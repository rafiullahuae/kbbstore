// update_flow.go — the moving parts of Settings → Updates (update.go holds the checks, staging and the swap):
//
//   - a resumable upload: begin → chunks (each one checked against its own sha256, written, fsynced, counted) →
//     finish. A dropped connection resumes at the byte the engine has, never from zero; an upload survives a reload
//     of the page and a restart of the engine (kept a day).
//   - the install job, streamed to the page as one JSON line per step: verify (every staged file re-hashed) → stage
//     (the autopilot pauses and running cycles finish; disk space; one install at a time, across processes) → swap
//     (journaled, atomic per part, the new engine must answer) → restart (the engine re-executes itself, same window,
//     same port).
//   - the health check the NEW engine runs on itself before its autopilot resumes: the app folder says the new
//     version, its own API answers, and the app it serves loads -- index.html and every script and module it imports.
//     When that fails, or does not pass within updHealthWindow, an update rolls back BY ITSELF to the version before
//     and restarts into it; the page says so. If the new engine cannot even start, "Start KBB Ads Autopilot.command"
//     sees the marker and rolls back the same way.
package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const (
	updMarkerName   = "health.json" // .update/health.json: the next engine to start must check itself
	updLastName     = "last.json"   // .update/last.json: how the last install ended (shown on the page)
	updChunk        = 8 << 20       // one upload chunk
	updHealthWindow = 45 * time.Second
	updIdleWait     = 2 * time.Minute // how long an install waits for running cycles to finish
)

var (
	updIDRe = regexp.MustCompile(`^[0-9a-f]{12}$`)
	// test hook: the health check fails with this reason (Go tests only; never set by a flag or the environment)
	updHealthFail = ""
)

type updMarker struct {
	Kind  string `json:"kind"`
	From  string `json:"from"`
	To    string `json:"to"`
	At    string `json:"at"`
	Auto  bool   `json:"auto"` // roll back by itself when the health check fails
	Tries int    `json:"tries"`
}

type updLast struct {
	Kind   string `json:"kind"` // update | patch | rollback | auto-rollback | interrupted
	From   string `json:"from"`
	To     string `json:"to"`
	OK     bool   `json:"ok"`
	Reason string `json:"reason,omitempty"`
	At     string `json:"at"`
	Seen   bool   `json:"seen"`
}

type updUpload struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Size     int64  `json:"size"`
	Received int64  `json:"received"`
	At       string `json:"at"`
}

// updHealth: what the engine knows about its own health after an update (state: checking | ok | failed)
type updHealth struct {
	State  string `json:"state"`
	From   string `json:"from"`
	To     string `json:"to"`
	Kind   string `json:"kind"`
	Reason string `json:"reason,omitempty"`
}

func (s *server) readMarker() *updMarker {
	b, err := os.ReadFile(filepath.Join(s.updDir(), updMarkerName))
	if err != nil {
		return nil
	}
	var m updMarker
	if json.Unmarshal(b, &m) != nil || !updVersionRe.MatchString(m.To) {
		return nil
	}
	return &m
}

func (s *server) writeMarker(m updMarker) error {
	b, _ := json.MarshalIndent(m, "", " ")
	return writeFileSync(filepath.Join(s.updDir(), updMarkerName), b)
}

func (s *server) readLast() *updLast {
	b, err := os.ReadFile(filepath.Join(s.updDir(), updLastName))
	if err != nil {
		return nil
	}
	var l updLast
	if json.Unmarshal(b, &l) != nil || l.Kind == "" {
		return nil
	}
	return &l
}

func (s *server) writeLast(l updLast) {
	if l.At == "" {
		l.At = time.Now().Format(time.RFC3339)
	}
	b, _ := json.MarshalIndent(l, "", " ")
	_ = writeFileSync(filepath.Join(s.updDir(), updLastName), b)
}

// ── one install at a time, across processes too (two windows on one app folder) ──

func (s *server) lockInstall() (func(), error) {
	if err := os.MkdirAll(s.updDir(), 0o755); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(filepath.Join(s.updDir(), "lock"), os.O_CREATE|os.O_RDWR, 0o644)
	if err != nil {
		return nil, err
	}
	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		f.Close()
		return nil, refuse("Another KBB Ads Autopilot window is installing an update right now.")
	}
	return func() { _ = syscall.Flock(int(f.Fd()), syscall.LOCK_UN); f.Close() }, nil // exec closes it too (CLOEXEC)
}

// ── the autopilot pauses while an install runs ──

// pauseAutopilot: no new sync or cycle starts (scheduler and Cycle look at app.updating), then every running one
// is waited for. Returns false when one is still running after wait.
func (s *server) pauseAutopilot(wait time.Duration) bool {
	if s.app == nil {
		return true
	}
	s.app.updating.Store(true)
	deadline := time.Now().Add(wait)
	// The owner, 21 Nov: an install sat on "Pause the autopilot" while a cycle (up to 20 minutes, with the history
	// import of 8 accounts) held the account. A running cycle is cancelled after a short grace, the way Stop
	// everything does it: every Meta write is idempotent and the next cycle picks up where this one stopped.
	grace := time.Now().Add(10 * time.Second)
	cancelled := false
	for _, ac := range s.app.Accounts() {
		for !ac.work.TryLock() {
			if !cancelled && time.Now().After(grace) {
				s.app.stopWork() // a Run it / Approve / sync running at once stops too (app.go stoppable)
				for _, x := range s.app.Accounts() {
					x.mu.Lock()
					if x.cycleCancel != nil {
						x.cycleCancel()
					}
					x.mu.Unlock()
				}
				cancelled = true
			}
			if time.Now().After(deadline) {
				return false
			}
			time.Sleep(200 * time.Millisecond)
		}
		ac.work.Unlock()
	}
	return true
}

func (s *server) resumeAutopilot() {
	if s.app != nil {
		s.app.updating.Store(false)
		s.app.resumeWork()
	}
}

// ── the install job ──

type stepFn func(step, state string, extra map[string]any)

// install: verify → stage → swap; the caller restarts. pend == nil: Roll back to "previous".
func (s *server) install(pend *updPending, step stepFn) (from, to, kind, usage string, err error) {
	from = appVersionAt(s.root)
	var src string
	var units []string
	var expected map[string]updFile
	if pend != nil {
		src, to, units, kind, expected = pend.staging, pend.To, pend.units, "update", pend.expected
		if pend.Kind == "patch" {
			kind = "patch"
		}
		if pend.From != from {
			return from, to, kind, "", refuse("The app changed since the file was checked (%s, not %s). Choose the update file again.", from, pend.From)
		}
	} else {
		prev := s.readPrevious()
		if prev == nil {
			return from, "", "rollback", "", refuse("There is no earlier version to go back to.")
		}
		src, to, units, kind = filepath.Join(s.updDir(), "previous"), prev.Version, prev.Units, "rollback"
		for _, u := range units {
			if !updValidUnit(u) {
				return from, to, kind, "", refuse("The kept copy of the earlier version is damaged.")
			}
		}
		expected = previousExpected(src)
	}
	// 1. verify: every staged file again, byte for byte, right before anything moves
	step("verify", "run", nil)
	if expected != nil {
		if err := verifyStaged(src, units, expected); err != nil {
			step("verify", "fail", map[string]any{"error": err.Error()})
			return from, to, kind, "", refuse("The prepared files changed on disk since they were checked (%v). Nothing was changed.", err)
		}
	}
	for _, need := range []string{"app/index.html", "VERSION"} {
		if !unitHas(units, need) {
			continue
		}
		if st, err := os.Stat(filepath.Join(src, need)); err != nil || !st.Mode().IsRegular() {
			step("verify", "fail", nil)
			return from, to, kind, "", refuse("The prepared copy is incomplete (%s), so it cannot be put in. Nothing was changed.", need)
		}
	}
	step("verify", "done", nil)
	// 2. stage: one install at a time, the autopilot paused, room on disk
	step("stage", "run", nil)
	unlock, err := s.lockInstall()
	if err != nil {
		step("stage", "fail", nil)
		return from, to, kind, "", err
	}
	defer unlock()
	if !s.pauseAutopilot(updIdleWait) {
		s.resumeAutopilot()
		step("stage", "fail", nil)
		return from, to, kind, "", refuse("A cycle is still running. Nothing was changed; try again in a few minutes.")
	}
	if fb := freeBytes(s.updDir()); fb >= 0 && fb < 50<<20 {
		s.resumeAutopilot()
		step("stage", "fail", nil)
		return from, to, kind, "", refuseC("space", "Not enough free space on this Mac to install it.")
	}
	step("stage", "done", map[string]any{"paused": true})
	// 3. swap
	step("swap", "run", nil)
	usage, err = s.apply(src, units, from, to, kind)
	if err != nil {
		s.resumeAutopilot()
		step("swap", "fail", nil)
		return from, to, kind, "", err
	}
	step("swap", "done", nil)
	return from, to, kind, usage, nil
}

func unitHas(units []string, rel string) bool {
	for _, u := range units {
		if u == rel || updUnit(rel) == u {
			return true
		}
	}
	return false
}

// previousExpected: the kept version's own manifest (it is one of the units an update swaps), as the files that
// version had -- so a Roll back also checks every byte before it moves anything. nil: no manifest kept (an install
// made by hand), then only presence is checked.
func previousExpected(prev string) map[string]updFile {
	b, err := os.ReadFile(filepath.Join(prev, updManifest))
	if err != nil {
		return nil
	}
	var m updManifestT
	if json.Unmarshal(b, &m) != nil {
		return nil
	}
	return m.expected()
}

// restart: replace this process with the engine now in place, same arguments, same port, no new browser tab
func (s *server) restart(usage, to string) {
	time.Sleep(400 * time.Millisecond) // let the answer reach the page
	args := []string{s.exe}
	skip := false
	for _, a := range os.Args[1:] {
		if skip {
			skip = false
			continue
		}
		n := strings.TrimLeft(a, "-")
		if n == "port" {
			skip = true
			continue
		}
		if strings.HasPrefix(n, "port=") || n == "after-update" || strings.HasPrefix(n, "after-update=") {
			continue
		}
		args = append(args, a)
	}
	if _, p, err := netSplitPort(s.addr); err == nil {
		args = append(args, "-port="+p)
	}
	if strings.Contains(usage, "after-update") { // an engine from before the updater does not know the flag
		args = append(args, "-after-update")
	}
	fmt.Printf("\n%s: installed %s, restarting…\n", appName, to)
	if err := syscall.Exec(s.exe, args, os.Environ()); err != nil {
		log.Printf("update: could not restart (%v). Close this window and double-click “Start %s” again.", err, appName)
		s.updMu.Lock()
		s.updBusy = false
		s.updMu.Unlock()
	}
}

func netSplitPort(addr string) (string, string, error) {
	i := strings.LastIndex(addr, ":")
	if i < 0 {
		return "", "", errors.New("no port")
	}
	return addr[:i], addr[i+1:], nil
}

// ── the health check (the engine that just started after an update) ──

// startHealth: called once the listener is up. Without a marker there is nothing to check: the gate opens at once
// and the autopilot runs as usual. With one, the autopilot waits until this engine has proved itself.
func (s *server) startHealth() {
	m := s.readMarker()
	if m == nil {
		close(s.healthGate)
		return
	}
	m.Tries++
	_ = s.writeMarker(*m)
	if s.app != nil {
		s.app.updating.Store(true)
	}
	s.setHealth(&updHealth{State: "checking", From: m.From, To: m.To, Kind: m.Kind})
	go s.runHealth(*m)
}

func (s *server) setHealth(h *updHealth) {
	s.updMu.Lock()
	s.health = h
	s.updMu.Unlock()
	if h != nil {
		s.publish("update", h)
	}
}

func (s *server) runHealth(m updMarker) {
	ctx, cancel := context.WithTimeout(context.Background(), updHealthWindow)
	defer cancel()
	done := make(chan error, 1)
	go func() {
		if m.Tries > 2 { // started twice already without ever passing: do not loop
			done <- fmt.Errorf("version %s did not start properly twice", m.To)
			return
		}
		done <- s.healthCheck(ctx, m)
	}()
	var err error
	select {
	case err = <-done:
	case <-ctx.Done():
		err = fmt.Errorf("version %s did not answer its health check within %d seconds", m.To, int(updHealthWindow/time.Second))
	}
	if err == nil {
		_ = os.Remove(filepath.Join(s.updDir(), updMarkerName))
		if m.Kind != "auto-rollback" { // the version put back by itself is fine: the note saying WHY stays for the page
			s.writeLast(updLast{Kind: m.Kind, From: m.From, To: m.To, OK: true})
		}
		log.Printf("update: version %s is healthy; the autopilot resumes", m.To)
		fmt.Printf("Updated: %s %s is running and healthy.\n", appName, m.To)
		s.setHealth(&updHealth{State: "ok", From: m.From, To: m.To, Kind: m.Kind})
		s.resumeAutopilot()
		close(s.healthGate)
		return
	}
	log.Printf("update: the health check of %s failed: %v", m.To, err)
	reason := "The health check failed: " + err.Error() + "."
	prev := s.readPrevious()
	if m.Auto && prev != nil && prev.Version == m.From {
		s.writeLast(updLast{Kind: "auto-rollback", From: m.To, To: m.From, OK: false, Reason: reason})
		s.setHealth(&updHealth{State: "failed", From: m.From, To: m.To, Kind: m.Kind, Reason: reason})
		unlock, lerr := s.lockInstall()
		if lerr == nil {
			usage, aerr := s.apply(filepath.Join(s.updDir(), "previous"), prev.Units, m.To, m.From, "auto-rollback")
			unlock()
			if aerr == nil {
				fmt.Printf("%s: version %s failed its health check; version %s was put back.\n", appName, m.To, m.From)
				if s.noRestart { // Go tests: this is where the engine would become the old one
					close(s.healthGate)
					return
				}
				s.restart(usage, m.From)
				return
			}
			lerr = aerr
		}
		reason += " Putting version " + m.From + " back failed too (" + lerr.Error() + "): close this window and double-click “Start KBB Ads Autopilot” again."
	}
	_ = os.Remove(filepath.Join(s.updDir(), updMarkerName))
	s.writeLast(updLast{Kind: m.Kind, From: m.From, To: m.To, OK: false, Reason: reason})
	s.setHealth(&updHealth{State: "failed", From: m.From, To: m.To, Kind: m.Kind, Reason: reason})
	s.resumeAutopilot()
	close(s.healthGate)
}

var (
	reAsset  = regexp.MustCompile(`(?:src|href)="([^"#?:]+\.(?:js|css))"`)
	reImport = regexp.MustCompile(`(?:\bfrom\s*|\bimport\s*\(?\s*)['"](\.{1,2}/[^'"\s]+\.js)['"]`)
)

// healthCheck: the app folder says the new version; this engine's own API answers; the app loads -- index.html,
// every script and stylesheet it names, and every module those import, all served by this engine.
func (s *server) healthCheck(ctx context.Context, m updMarker) error {
	if updHealthFail != "" {
		return errors.New(updHealthFail)
	}
	if v := appVersionAt(s.root); v != m.To {
		return fmt.Errorf("the app folder says version %q, not %s", v, m.To)
	}
	cl := &http.Client{Timeout: 10 * time.Second}
	base := "http://" + s.addr
	get := func(p string) ([]byte, error) {
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, base+p, nil)
		r, err := cl.Do(req)
		if err != nil {
			return nil, err
		}
		defer r.Body.Close()
		b, err := io.ReadAll(io.LimitReader(r.Body, 8<<20))
		if r.StatusCode != 200 {
			return nil, fmt.Errorf("%s answered %d", p, r.StatusCode)
		}
		return b, err
	}
	b, err := get("/api/info")
	if err != nil {
		return fmt.Errorf("the engine's own API did not answer (%v)", err)
	}
	var info struct {
		App      string `json:"app"`
		APILevel int    `json:"apiLevel"`
		Version  string `json:"version"`
	}
	if json.Unmarshal(b, &info) != nil || info.App != appName || info.APILevel < 1 {
		return errors.New("the engine's own API answered something else")
	}
	if info.Version != m.To { // an engine that started as another version would serve the new app without its API
		return fmt.Errorf("the engine running is version %q, not %s", info.Version, m.To)
	}
	idx, err := get("/app/index.html")
	if err != nil || !bytes.Contains(idx, []byte("js/main.js")) {
		return errors.New("the app's page (index.html) does not load")
	}
	queue := []string{}
	for _, mm := range reAsset.FindAllSubmatch(idx, -1) {
		queue = append(queue, "/app/"+strings.TrimPrefix(string(mm[1]), "./"))
	}
	seen := map[string]bool{}
	for len(queue) > 0 && len(seen) < 400 {
		p := queue[0]
		queue = queue[1:]
		if seen[p] {
			continue
		}
		seen[p] = true
		body, err := get(p)
		if err != nil {
			return fmt.Errorf("the app does not load: %s is missing", strings.TrimPrefix(p, "/app/"))
		}
		if strings.HasSuffix(p, ".js") {
			for _, mm := range reImport.FindAllSubmatch(body, -1) {
				queue = append(queue, cleanURLPath(p, string(mm[1])))
			}
		}
	}
	return nil
}

func cleanURLPath(from, rel string) string {
	dir := from[:strings.LastIndex(from, "/")+1]
	return pathClean(dir + rel)
}

func pathClean(p string) string {
	segs := []string{}
	for _, s := range strings.Split(p, "/") {
		switch s {
		case "", ".":
		case "..":
			if len(segs) > 0 {
				segs = segs[:len(segs)-1]
			}
		default:
			segs = append(segs, s)
		}
	}
	return "/" + strings.Join(segs, "/")
}

// ── resumable upload ──

func (s *server) uploadDir(id string) string { return filepath.Join(s.updDir(), "upload-"+id) }

func (s *server) readUpload(id string) *updUpload {
	if !updIDRe.MatchString(id) {
		return nil
	}
	b, err := os.ReadFile(filepath.Join(s.uploadDir(id), "meta.json"))
	if err != nil {
		return nil
	}
	var u updUpload
	if json.Unmarshal(b, &u) != nil || u.ID != id {
		return nil
	}
	if st, err := os.Stat(filepath.Join(s.uploadDir(id), "data.part")); err != nil || st.Size() < u.Received {
		return nil
	}
	return &u
}

func (s *server) saveUpload(u *updUpload) error {
	u.At = time.Now().Format(time.RFC3339)
	b, _ := json.Marshal(u)
	return writeFileSync(filepath.Join(s.uploadDir(u.ID), "meta.json"), b)
}

// latestUpload: an unfinished upload the page can offer to resume (after a reload)
func (s *server) latestUpload() *updUpload {
	ents, _ := os.ReadDir(s.updDir())
	var best *updUpload
	for _, e := range ents {
		if id, ok := strings.CutPrefix(e.Name(), "upload-"); ok {
			if u := s.readUpload(id); u != nil && u.Received < u.Size && (best == nil || u.At > best.At) {
				best = u
			}
		}
	}
	return best
}

var reUpName = regexp.MustCompile(`[^A-Za-z0-9 ._()→>\-]`)

// beginUpload: a new upload (or the same one again: same name and size → its id and how much arrived)
func (s *server) beginUpload(name string, size int64, resume string) (*updUpload, error) {
	if size <= 0 {
		return nil, refuse("That file is empty.")
	}
	if size > updMaxUpload {
		return nil, refuse("That file is too big to be a KBB Ads Autopilot update.")
	}
	name = clip(reUpName.ReplaceAllString(name, "_"), 120)
	if u := s.readUpload(resume); u != nil && u.Name == name && u.Size == size {
		return u, nil
	}
	if err := os.MkdirAll(s.updDir(), 0o755); err != nil {
		return nil, err
	}
	if fb := freeBytes(s.updDir()); fb >= 0 && fb < 2*size+updSpare {
		return nil, refuseC("space", "Not enough free space on this Mac for this file (needs %s).", human(2*size+updSpare))
	}
	// one upload at a time: older unfinished ones go
	ents, _ := os.ReadDir(s.updDir())
	for _, e := range ents {
		if strings.HasPrefix(e.Name(), "upload-") {
			_ = os.RemoveAll(filepath.Join(s.updDir(), e.Name()))
		}
	}
	u := &updUpload{ID: randHex(6), Name: name, Size: size}
	if err := os.MkdirAll(s.uploadDir(u.ID), 0o755); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(filepath.Join(s.uploadDir(u.ID), "data.part"), os.O_CREATE|os.O_WRONLY|os.O_EXCL, 0o644)
	if err != nil {
		return nil, err
	}
	f.Close()
	return u, s.saveUpload(u)
}

// putChunk: bytes at offset. Accepted only at exactly the byte the engine has (else 409 with that byte, and the page
// continues from there), only whole (every byte the request announced), and only when they hash to X-Chunk-SHA256.
func (s *server) putChunk(r *http.Request, id string, offset int64) (*updUpload, int, error) {
	s.upMu.Lock()
	defer s.upMu.Unlock()
	u := s.readUpload(id)
	if u == nil {
		return nil, 404, refuse("That upload is no longer here. Choose the file again.")
	}
	if offset != u.Received {
		return u, 409, fmt.Errorf("the engine has %d bytes, not %d", u.Received, offset)
	}
	if r.ContentLength <= 0 || r.ContentLength > updChunk || offset+r.ContentLength > u.Size {
		return u, 400, errors.New("wrong chunk size")
	}
	buf := make([]byte, r.ContentLength)
	if _, err := io.ReadFull(r.Body, buf); err != nil {
		return u, 400, errors.New("the chunk did not arrive whole")
	}
	sum := sha256.Sum256(buf)
	if want := strings.ToLower(r.Header.Get("X-Chunk-SHA256")); want == "" || want != hex.EncodeToString(sum[:]) {
		return u, 422, errors.New("the chunk arrived damaged")
	}
	f, err := os.OpenFile(filepath.Join(s.uploadDir(id), "data.part"), os.O_WRONLY, 0o644)
	if err != nil {
		return u, 500, err
	}
	_, err = f.WriteAt(buf, offset)
	if err == nil {
		err = f.Truncate(offset + int64(len(buf)))
	}
	if err == nil {
		err = f.Sync()
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return u, 500, err
	}
	u.Received = offset + int64(len(buf))
	if err := s.saveUpload(u); err != nil {
		return u, 500, err
	}
	return u, 200, nil
}

// checkFile: checkPackage into a new staging folder, and the review card's numbers
func (s *server) checkFile(zpath, name string, size int64) (*updPending, error) {
	id := randHex(6)
	staging := filepath.Join(s.updDir(), "staging-"+id)
	cur := appVersionAt(s.root)
	c, err := checkPackage(zpath, staging, s.root, cur, false)
	if err != nil {
		_ = os.RemoveAll(staging)
		return nil, err
	}
	man := c.man
	notes := []string{}
	for _, l := range man.Notes {
		if l = strings.TrimSpace(clip(l, 300)); l != "" && len(notes) < updMaxNotes {
			notes = append(notes, l)
		}
	}
	added, changed, removed, writes, engine := updDiff(s.root, c)
	kind := "full"
	checks := []updCheck{{ID: "app"}, {ID: "hashes", N: int64(len(man.Files))}, {ID: "listed"}, {ID: "paths"}, {ID: "newer", V: cur}}
	if man.patch() {
		kind = "patch"
		checks = append(checks, updCheck{ID: "from", V: man.From}, updCheck{ID: "result", N: int64(len(man.Result))})
	} else if man.MinVersion != "" {
		checks = append(checks, updCheck{ID: "min", V: man.MinVersion})
	}
	checks = append(checks, updCheck{ID: "size", N: c.total}, updCheck{ID: "space"}, updCheck{ID: "signature"})
	return &updPending{ID: id, Kind: kind, Name: name, From: cur, To: man.Version, Date: clip(man.Date, 20), Notes: notes, Size: size,
		Total: c.total, Files: len(man.Files), Added: added, Changed: changed, Removed: removed, Writes: writes, Engine: engine,
		Checks: checks, staging: staging, units: c.units, expected: man.expected()}, nil
}

// ── the API ──

func (s *server) updateStatus() map[string]any {
	st := map[string]any{"version": appVersionAt(s.root), "engine": version, "apiLevel": apiLevel, "history": s.readHistory(),
		"canUpdate": s.separateData() && s.exe != "", "dataDir": s.data}
	if p := s.readPrevious(); p != nil {
		st["previous"] = map[string]any{"version": p.Version, "at": p.At}
	}
	if l := s.readLast(); l != nil {
		st["last"] = l
	}
	if u := s.latestUpload(); u != nil {
		st["upload"] = u
	}
	s.updMu.Lock()
	if s.updPend != nil {
		st["pending"] = s.updPend
	}
	st["busy"] = s.updBusy
	if s.health != nil {
		st["health"] = s.health
	}
	s.updMu.Unlock()
	st["paused"] = s.app != nil && s.app.updating.Load()
	return st
}

func (s *server) dropPending() { // caller holds updMu
	if s.updPend != nil {
		_ = os.RemoveAll(s.updPend.staging)
		s.updPend = nil
	}
}

// startCheck: busy while a package is checked (an install cannot start meanwhile)
func (s *server) startCheck() error {
	s.updMu.Lock()
	defer s.updMu.Unlock()
	if s.updBusy {
		return errUpdBusy
	}
	if s.health != nil && s.health.State == "checking" {
		return errUpdBusy
	}
	s.updBusy = true
	s.dropPending()
	return nil
}

var errUpdBusy = errors.New("An update is already being installed.")

func (s *server) endCheck(p *updPending) {
	s.updMu.Lock()
	s.updBusy = false
	if p != nil {
		s.updPend = p
	}
	s.updMu.Unlock()
}

func (s *server) updErr(w http.ResponseWriter, err error) {
	code := 500
	if errors.Is(err, errUpdBusy) {
		code = 409
	} else if _, ok := err.(updRefusal); ok {
		code = 422
	}
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(map[string]string{"error": err.Error(), "code": refusalCode(err)})
}

func (s *server) updateAPI(w http.ResponseWriter, r *http.Request, p string) bool {
	if !strings.HasPrefix(p, "/api/update") {
		return false
	}
	if p != "/api/update" && r.Method != http.MethodPost && !(p == "/api/update/upload/chunk" && r.Method == http.MethodPut) && p != "/api/update/upload/status" {
		jsonErr(w, 405, "POST only")
		return true
	}
	if p != "/api/update" && p != "/api/update/ack" && p != "/api/update/cancel" && (!s.separateData() || s.exe == "") {
		jsonErr(w, 409, "Updating from here needs KBB Ads Autopilot started with its “Start KBB Ads Autopilot” file.")
		return true
	}
	switch p {
	case "/api/update":
		writeJSON(w, s.updateStatus())

	case "/api/update/upload": // the whole zip in one request (kept for scripts; the page sends chunks)
		if r.ContentLength > updMaxUpload {
			jsonErr(w, 413, "That file is too big to be a KBB Ads Autopilot update.")
			return true
		}
		if err := s.startCheck(); err != nil {
			s.updErr(w, err)
			return true
		}
		pend, err := s.receive(w, r)
		s.endCheck(pend)
		if err != nil {
			s.tidyUpdDir()
			log.Printf("update: package refused: %v", err)
			s.updErr(w, err)
			return true
		}
		log.Printf("update: package %s checked (%d files), waiting for Install", pend.To, pend.Files)
		writeJSON(w, pend)

	case "/api/update/upload/begin":
		var in struct {
			Name   string `json:"name"`
			Size   int64  `json:"size"`
			Resume string `json:"resume"`
		}
		if err := readJSON(r, &in, 4096); err != nil {
			jsonErr(w, 400, err.Error())
			return true
		}
		s.updMu.Lock()
		busy := s.updBusy
		s.updMu.Unlock()
		if busy {
			s.updErr(w, errUpdBusy)
			return true
		}
		s.upMu.Lock()
		u, err := s.beginUpload(in.Name, in.Size, in.Resume)
		s.upMu.Unlock()
		if err != nil {
			s.updErr(w, err)
			return true
		}
		writeJSON(w, map[string]any{"id": u.ID, "received": u.Received, "size": u.Size, "chunk": updChunk})

	case "/api/update/upload/status":
		u := s.readUpload(r.URL.Query().Get("id"))
		if u == nil {
			jsonErr(w, 404, "That upload is no longer here. Choose the file again.")
			return true
		}
		writeJSON(w, u)

	case "/api/update/upload/chunk":
		off, err := strconv.ParseInt(r.URL.Query().Get("offset"), 10, 64)
		if err != nil || off < 0 {
			jsonErr(w, 400, "bad offset")
			return true
		}
		u, code, err := s.putChunk(r, r.URL.Query().Get("id"), off)
		if err != nil {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(code)
			out := map[string]any{"error": err.Error()}
			if u != nil {
				out["received"] = u.Received
			}
			_ = json.NewEncoder(w).Encode(out)
			return true
		}
		writeJSON(w, map[string]any{"received": u.Received, "size": u.Size})

	case "/api/update/upload/finish":
		var in struct {
			ID string `json:"id"`
		}
		_ = readJSON(r, &in, 4096)
		u := s.readUpload(in.ID)
		if u == nil {
			jsonErr(w, 404, "That upload is no longer here. Choose the file again.")
			return true
		}
		if u.Received != u.Size {
			jsonErr(w, 409, fmt.Sprintf("Only %d of %d bytes arrived.", u.Received, u.Size))
			return true
		}
		if err := s.startCheck(); err != nil {
			s.updErr(w, err)
			return true
		}
		pend, err := s.checkFile(filepath.Join(s.uploadDir(u.ID), "data.part"), u.Name, u.Size)
		_ = os.RemoveAll(s.uploadDir(u.ID))
		s.endCheck(pend)
		if err != nil {
			s.tidyUpdDir()
			log.Printf("update: package refused: %v", err)
			s.updErr(w, err)
			return true
		}
		log.Printf("update: %s package %s checked (%d files), waiting for Install", pend.Kind, pend.To, pend.Files)
		writeJSON(w, pend)

	case "/api/update/cancel":
		var in struct {
			Upload string `json:"upload"`
		}
		_ = json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&in)
		s.updMu.Lock()
		if !s.updBusy {
			s.dropPending()
		}
		s.updMu.Unlock()
		if updIDRe.MatchString(in.Upload) {
			s.upMu.Lock()
			_ = os.RemoveAll(s.uploadDir(in.Upload))
			s.upMu.Unlock()
		}
		s.tidyUpdDir()
		writeJSON(w, map[string]any{"ok": true})

	case "/api/update/ack":
		if l := s.readLast(); l != nil && !l.Seen {
			l.Seen = true
			s.writeLast(*l)
		}
		writeJSON(w, map[string]any{"ok": true})

	case "/api/update/install", "/api/update/rollback":
		var b struct{ ID string }
		_ = json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&b)
		s.updMu.Lock()
		if s.updBusy || (s.health != nil && s.health.State == "checking") {
			s.updMu.Unlock()
			s.updErr(w, errUpdBusy)
			return true
		}
		var pend *updPending
		if p == "/api/update/install" {
			if s.updPend == nil || s.updPend.ID != b.ID {
				s.updMu.Unlock()
				jsonErr(w, 409, "Choose the update file again.")
				return true
			}
			pend = s.updPend
		} else {
			s.dropPending()
		}
		s.updBusy = true
		s.updMu.Unlock()
		// one JSON line per step, as it happens
		w.Header().Set("Content-Type", "application/x-ndjson")
		w.Header().Set("Cache-Control", "no-store")
		fl, _ := w.(http.Flusher)
		line := func(v any) {
			b, _ := json.Marshal(v)
			_, _ = w.Write(append(b, '\n'))
			if fl != nil {
				fl.Flush()
			}
		}
		from, to, kind, usage, err := s.install(pend, func(step, state string, extra map[string]any) {
			v := map[string]any{"step": step, "state": state}
			for k, x := range extra {
				v[k] = x
			}
			line(v)
		})
		s.updMu.Lock()
		if pend != nil {
			s.dropPending()
		}
		if err != nil {
			s.updBusy = false
			s.updMu.Unlock()
			s.tidyUpdDir()
			log.Printf("update: %s to %s failed: %v", kind, to, err)
			msg := err.Error()
			if !strings.HasPrefix(msg, "Not installed") {
				msg = "Not installed: " + msg
			}
			line(map[string]any{"ok": false, "error": msg, "code": refusalCode(err), "from": from, "to": to})
			return true
		}
		s.updMu.Unlock() // stays busy until the restart replaces this process
		log.Printf("update: %s %s → %s done; restarting", kind, from, to)
		line(map[string]any{"step": "restart", "state": "run"})
		line(map[string]any{"ok": true, "from": from, "to": to, "kind": kind, "restarting": true})
		if s.noRestart {
			s.updMu.Lock()
			s.updBusy = false
			s.updMu.Unlock()
			return true
		}
		go s.restart(usage, to)

	default:
		jsonErr(w, 404, "no such endpoint")
	}
	return true
}

// receive: the one-request upload into .update/upload-*.zip, then checkFile
func (s *server) receive(w http.ResponseWriter, r *http.Request) (*updPending, error) {
	d := s.updDir()
	if err := os.MkdirAll(d, 0o755); err != nil {
		return nil, err
	}
	zf, err := os.CreateTemp(d, "upload-*.zip")
	if err != nil {
		return nil, err
	}
	defer func() { zf.Close(); os.Remove(zf.Name()) }()
	n, err := io.Copy(zf, http.MaxBytesReader(w, r.Body, updMaxUpload))
	if err != nil {
		var mbe *http.MaxBytesError
		if errors.As(err, &mbe) {
			return nil, refuse("That file is too big to be a KBB Ads Autopilot update.")
		}
		return nil, fmt.Errorf("the file did not arrive completely (%v)", err)
	}
	if err := zf.Close(); err != nil {
		return nil, err
	}
	return s.checkFile(zf.Name(), "", n)
}

// tidyUpdDir: .update removed again when nothing is kept in it (a refused package leaves the app folder as it was)
func (s *server) tidyUpdDir() {
	ents, err := os.ReadDir(s.updDir())
	if err != nil || len(ents) > 1 || len(ents) == 1 && ents[0].Name() != "lock" {
		return
	}
	_ = os.Remove(filepath.Join(s.updDir(), "lock"))
	_ = os.Remove(s.updDir())
}
