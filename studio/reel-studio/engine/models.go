package main

// The model installer: everything in models/manifest.json downloaded after ONE "Allow", into models/<repo>/<path>.
//
// Bulletproof by construction:
//   - free disk space is checked first (with a margin) and a shortage is said plainly
//   - every file downloads to <dest>.part and RESUMES with an HTTP Range request, across retries and app restarts
//   - network errors, 5xx, 429 and stalls (no bytes for 30 s) retry automatically with backoff; only after that
//     does the app show a Retry button
//   - every file is verified (size + sha256 for model files, git blob sha1 for small text files) and re-fetched on a
//     mismatch; only a verified file is renamed .part -> final, so the app never sees half a model
//   - 3 files at a time, overall progress, speed and time left, pause/resume
//   - only through the /net allowlist and the app's grant for that repo (redirects to the HF CDNs allowed)

import (
	"context"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"hash"
	"io"
	"math/rand"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"
)

type mFile struct {
	Path        string `json:"path"`
	Size        int64  `json:"size"`
	Sha256      string `json:"sha256,omitempty"`
	GitBlobSha1 string `json:"gitBlobSha1,omitempty"`
}
type mModel struct {
	ID       string  `json:"id"`
	Repo     string  `json:"repo"`
	Purpose  string  `json:"purpose"`
	Owner    string  `json:"owner"`
	Required bool    `json:"required"`
	Size     int64   `json:"size"`
	Files    []mFile `json:"files"`
	Group    string  `json:"group,omitempty"`   // e.g. "Image AI" (the Configure page groups by it)
	Licence  string  `json:"licence,omitempty"` // shown beside the model
}
type manifest struct {
	Revision string   `json:"revision"`
	Models   []mModel `json:"models"`
}

type iFile struct {
	Model    string `json:"model"`
	Path     string `json:"path"`
	Size     int64  `json:"size"`
	Done     int64  `json:"done"`
	State    string `json:"state"` // queued | downloading | retrying | verifying | done | error
	Attempts int    `json:"attempts"`
	Message  string `json:"message,omitempty"`
	repo     string
	sha256   string
	gsha1    string
	dest     string
	url      string
	canon    string
}

type installer struct {
	s       *server
	mu      sync.Mutex
	State   string   `json:"state"` // idle | running | paused | done | stalled | nospace
	Message string   `json:"message"`
	IDs     []string `json:"ids"`
	Files   []*iFile `json:"files"`
	Total   int64    `json:"total"`
	Done    int64    `json:"done"`
	Speed   float64  `json:"speed"` // bytes/s
	ETA     float64  `json:"eta"`   // s
	cancel  context.CancelFunc
	runID   int
}

const (
	maxAttempts  = 8
	stallTimeout = 30 * time.Second
	parallel     = 3
)

var errCorrupt = errors.New("the file arrived damaged")

type fatalErr struct{ msg string }

func (e fatalErr) Error() string { return e.msg }

func (s *server) readManifest() (*manifest, error) {
	b, err := os.ReadFile(filepath.Join(s.root, "models", "manifest.json"))
	if err != nil {
		return nil, fmt.Errorf("the model list (models/manifest.json) is missing")
	}
	var m manifest
	if err := json.Unmarshal(b, &m); err != nil {
		return nil, fmt.Errorf("the model list (models/manifest.json) is damaged")
	}
	if m.Revision == "" {
		m.Revision = "main"
	}
	return &m, nil
}

// safe file locations for a manifest entry, or an error for anything odd (no '..', no absolute paths)
func (s *server) modelDest(repo, p string) (string, error) {
	if !hfRepo.MatchString("https://huggingface.co/" + repo + "/") {
		return "", errRefused
	}
	return s.resolve("models/"+repo+"/"+p, map[string]bool{"models": true})
}

func freeBytes(dir string) int64 {
	var st syscall.Statfs_t
	if err := syscall.Statfs(dir, &st); err != nil {
		return -1
	}
	return int64(st.Bavail) * int64(st.Bsize)
}

func fileSize(p string) int64 {
	st, err := os.Stat(p)
	if err != nil {
		return 0
	}
	return st.Size()
}

// status: every model with installed/partial bytes, totals and free disk
func (s *server) modelStatus() map[string]any {
	m, err := s.readManifest()
	out := map[string]any{"free": freeBytes(filepath.Join(s.dataDir(), "models"))}
	if err != nil {
		out["error"] = err.Error()
		out["models"] = []any{}
		return out
	}
	list := []map[string]any{}
	for _, md := range m.Models {
		have, part := int64(0), int64(0)
		installed := len(md.Files) > 0
		for _, f := range md.Files {
			dest, err := s.modelDest(md.Repo, f.Path)
			if err != nil {
				installed = false
				continue
			}
			if fileSize(dest) == f.Size {
				have += f.Size
			} else {
				installed = false
				part += fileSize(dest + ".part")
			}
		}
		list = append(list, map[string]any{"id": md.ID, "repo": md.Repo, "purpose": md.Purpose, "owner": md.Owner, "required": md.Required,
			"size": md.Size, "files": len(md.Files), "installed": installed, "have": have, "partial": part, "group": md.Group, "licence": md.Licence})
	}
	out["models"] = list
	s.inst.mu.Lock()
	out["installer"] = s.inst.snapshot()
	s.inst.mu.Unlock()
	return out
}

func (in *installer) snapshot() map[string]any {
	files := make([]iFile, len(in.Files))
	for i, f := range in.Files {
		files[i] = *f
	}
	return map[string]any{"state": in.State, "message": in.Message, "ids": in.IDs, "total": in.Total, "done": in.Done, "speed": in.Speed, "eta": in.ETA, "files": files}
}

// start (or resume) installing the given model ids
func (s *server) installModels(ids []string) error {
	in := s.inst
	m, err := s.readManifest()
	if err != nil {
		return err
	}
	want := map[string]bool{}
	for _, id := range ids {
		want[id] = true
	}
	var files []*iFile
	for _, md := range m.Models {
		if !want[md.ID] {
			continue
		}
		canonRepo := "https://huggingface.co/" + md.Repo + "/"
		if _, ok := s.permitted(canonRepo + "resolve/" + m.Revision + "/x"); !ok {
			return fatalErr{"Please press Allow first (" + md.Repo + " was not allowed)."}
		}
		for _, f := range md.Files {
			dest, err := s.modelDest(md.Repo, f.Path)
			if err != nil || strings.Contains(f.Path, "..") || f.Size <= 0 {
				return fatalErr{"The model list has a bad entry: " + md.ID + " " + f.Path}
			}
			canon := canonRepo + "resolve/" + m.Revision + "/" + escapePath(f.Path)
			u := canon
			if s.testBase != "" { // test-only: a local server stands in for huggingface.co
				u = strings.TrimSuffix(s.testBase, "/") + "/" + md.Repo + "/resolve/" + m.Revision + "/" + escapePath(f.Path)
			}
			fi := &iFile{Model: md.ID, Path: md.Repo + "/" + f.Path, Size: f.Size, State: "queued", repo: md.Repo, sha256: strings.ToLower(f.Sha256), gsha1: strings.ToLower(f.GitBlobSha1), dest: dest, url: u, canon: canon}
			if fileSize(dest) == f.Size {
				fi.State, fi.Done = "done", f.Size
			} else {
				if _, err := os.Stat(dest); err == nil {
					_ = os.Remove(dest) // wrong size: a damaged copy, fetch again
				}
				fi.Done = min(fileSize(dest+".part"), f.Size)
			}
			files = append(files, fi)
		}
	}
	if len(files) == 0 {
		return fatalErr{"Nothing to download."}
	}

	in.mu.Lock()
	if in.cancel != nil {
		in.cancel()
	}
	in.IDs, in.Files = ids, files
	in.Total, in.Done = 0, 0
	need := int64(0)
	for _, f := range files {
		in.Total += f.Size
		in.Done += f.Done
		if f.State != "done" {
			need += f.Size - f.Done
		}
	}
	free := freeBytes(filepath.Join(s.dataDir(), "models"))
	margin := max(int64(300<<20), need/20)
	if free >= 0 && free < need+margin {
		in.State = "nospace"
		in.Message = fmt.Sprintf("Not enough free space on this Mac: the models need %s and %s is free. Please free up %s (empty the Bin, remove old videos) and press Retry.",
			human(need+margin), human(free), human(need+margin-free))
		in.mu.Unlock()
		s.publish("models", s.modelStatus())
		return nil
	}
	ctx, cancel := context.WithCancel(context.Background())
	in.cancel = cancel
	in.State, in.Message = "running", "Downloading…"
	in.runID++
	run := in.runID
	in.mu.Unlock()

	go s.runInstall(ctx, run)
	return nil
}

func escapePath(p string) string {
	parts := strings.Split(p, "/")
	for i, x := range parts {
		parts[i] = strings.ReplaceAll(x, " ", "%20")
	}
	return strings.Join(parts, "/")
}

func human(n int64) string {
	switch {
	case n >= 1e9:
		return fmt.Sprintf("%.1f GB", float64(n)/1e9)
	case n >= 1e6:
		return fmt.Sprintf("%.0f MB", float64(n)/1e6)
	default:
		return fmt.Sprintf("%d KB", max(1, n/1000))
	}
}

func (s *server) pauseModels() {
	in := s.inst
	in.mu.Lock()
	if in.cancel != nil && in.State == "running" {
		in.cancel()
		in.cancel = nil
		in.State, in.Message = "paused", "Paused. Press Resume to carry on where it stopped."
		for _, f := range in.Files {
			if f.State != "done" {
				f.State = "queued"
			}
		}
	}
	in.mu.Unlock()
	s.publish("models", s.modelStatus())
}

func (s *server) runInstall(ctx context.Context, run int) {
	in := s.inst
	queue := make(chan *iFile, len(in.Files))
	in.mu.Lock()
	for _, f := range in.Files {
		if f.State != "done" {
			queue <- f
		}
	}
	in.mu.Unlock()
	close(queue)

	// progress ticker: speed (smoothed), time left, events
	tickCtx, stopTick := context.WithCancel(ctx)
	go func() {
		t := time.NewTicker(400 * time.Millisecond)
		defer t.Stop()
		last, lastAt := int64(-1), time.Now()
		for {
			select {
			case <-tickCtx.Done():
				return
			case now := <-t.C:
				in.mu.Lock()
				done := int64(0)
				for _, f := range in.Files {
					done += f.Done
				}
				in.Done = done
				if last >= 0 {
					inst := float64(done-last) / now.Sub(lastAt).Seconds()
					if inst < 0 {
						inst = 0
					}
					in.Speed = in.Speed*0.8 + inst*0.2
					if in.Speed > 1 {
						in.ETA = float64(in.Total-done) / in.Speed
					}
				}
				last, lastAt = done, now
				in.mu.Unlock()
				s.publish("models", s.modelStatus())
			}
		}
	}()

	var wg sync.WaitGroup
	var firstErr error
	var errMu sync.Mutex
	for w := 0; w < parallel; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for f := range queue {
				if ctx.Err() != nil {
					return
				}
				if err := s.fetchFile(ctx, f); err != nil && ctx.Err() == nil {
					errMu.Lock()
					if firstErr == nil {
						firstErr = err
					}
					errMu.Unlock()
				}
			}
		}()
	}
	wg.Wait()
	stopTick()

	in.mu.Lock()
	if in.runID == run && ctx.Err() == nil {
		allDone := true
		for _, f := range in.Files {
			if f.State != "done" {
				allDone = false
			}
		}
		in.Done = 0
		for _, f := range in.Files {
			in.Done += f.Done
		}
		switch {
		case allDone:
			in.State, in.Message, in.ETA = "done", "All set — every model is installed and checked.", 0
		default:
			in.State = "stalled"
			in.Message = friendly(firstErr) + " Everything downloaded so far is kept. Press Retry to carry on."
		}
		in.cancel = nil
	}
	in.mu.Unlock()
	s.publish("models", s.modelStatus())
}

func friendly(err error) string {
	var fe fatalErr
	switch {
	case err == nil:
		return "The download stopped."
	case errors.As(err, &fe):
		return fe.msg
	case errors.Is(err, errCorrupt):
		return "A file kept arriving damaged."
	default:
		return "The internet connection is not working right now."
	}
}

func (in *installer) set(f *iFile, fn func()) {
	in.mu.Lock()
	fn()
	in.mu.Unlock()
}

// fetchFile: download (resuming), verify, rename; retries with backoff.
func (s *server) fetchFile(ctx context.Context, f *iFile) error {
	in := s.inst
	part := f.dest + ".part"
	var last error
	for attempt := 1; attempt <= maxAttempts; attempt++ {
		in.set(f, func() { f.State, f.Attempts, f.Message = "downloading", attempt, "" })
		err := s.fetchOnce(ctx, f, part)
		if err == nil {
			in.set(f, func() { f.State = "verifying" })
			err = verifyFile(part, f)
			if err == nil {
				if err = os.Rename(part, f.dest); err == nil {
					in.set(f, func() { f.State, f.Done, f.Message = "done", f.Size, "" })
					return nil
				}
			} else {
				_ = os.Remove(part) // damaged: start this file again from zero
				in.set(f, func() { f.Done = 0 })
			}
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		last = err
		var fe fatalErr
		if errors.As(err, &fe) {
			break
		}
		wait := time.Duration(1<<min(attempt-1, 5)) * time.Second // 1 2 4 8 16 32 s
		wait += time.Duration(rand.Intn(700)) * time.Millisecond
		msg := "Connection dropped — trying again"
		if errors.Is(err, errCorrupt) {
			msg = "File arrived damaged — fetching it again"
		}
		in.set(f, func() { f.State, f.Message = "retrying", fmt.Sprintf("%s in %d s", msg, int(wait.Seconds())) })
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(wait):
		}
	}
	in.set(f, func() { f.State, f.Message = "error", friendly(last) })
	return last
}

func (s *server) fetchOnce(ctx context.Context, f *iFile, part string) error {
	if err := os.MkdirAll(filepath.Dir(part), 0o755); err != nil {
		return fatalErr{"Cannot write into the models folder."}
	}
	have := fileSize(part)
	if have > f.Size {
		_ = os.Truncate(part, 0)
		have = 0
	}
	s.inst.set(f, func() { f.Done = have })
	if have == f.Size {
		return nil // complete from an earlier run: verify it
	}
	rctx, cancel := context.WithCancel(ctx)
	defer cancel()
	req, _ := http.NewRequestWithContext(rctx, http.MethodGet, f.url, nil)
	if have > 0 {
		req.Header.Set("Range", fmt.Sprintf("bytes=%d-", have))
	}
	req.Header.Set("User-Agent", "KBB-Studio/"+version)
	// the response header must arrive within stallTimeout too
	hdrT := time.AfterFunc(stallTimeout, cancel)
	resp, err := s.client.Do(req)
	hdrT.Stop()
	if err != nil {
		if strings.Contains(err.Error(), "not on the allowlist") {
			return fatalErr{"The download server sent the studio to an address it does not allow. Nothing was downloaded from there."}
		}
		return err
	}
	defer resp.Body.Close()
	flags := os.O_CREATE | os.O_WRONLY
	switch {
	case resp.StatusCode == http.StatusPartialContent && have > 0:
		flags |= os.O_APPEND
	case resp.StatusCode == http.StatusOK:
		flags |= os.O_TRUNC // the server sent the whole file: start over
		have = 0
	case resp.StatusCode == http.StatusRequestedRangeNotSatisfiable:
		_ = os.Truncate(part, 0)
		return errors.New("range not satisfiable")
	case resp.StatusCode == 429 || resp.StatusCode == 408 || resp.StatusCode >= 500:
		return fmt.Errorf("server busy (%d)", resp.StatusCode)
	case resp.StatusCode == 401 || resp.StatusCode == 403 || resp.StatusCode == 404:
		return fatalErr{fmt.Sprintf("The file %s is not available on the download server any more.", path.Base(f.Path))}
	default:
		return fmt.Errorf("unexpected answer %d", resp.StatusCode)
	}
	out, err := os.OpenFile(part, flags, 0o644)
	if err != nil {
		return fatalErr{"Cannot write into the models folder."}
	}
	n := have
	buf := make([]byte, 256<<10)
	idle := time.AfterFunc(stallTimeout, cancel)
	defer idle.Stop()
	var werr error
	for {
		k, rerr := resp.Body.Read(buf)
		if k > 0 {
			idle.Reset(stallTimeout)
			if n+int64(k) > f.Size {
				k = int(f.Size - n) // never write past the expected size
			}
			if _, werr = out.Write(buf[:k]); werr != nil {
				break
			}
			n += int64(k)
			cur := n
			s.inst.set(f, func() { f.Done = cur })
			if n >= f.Size {
				break
			}
		}
		if rerr != nil {
			if rerr != io.EOF {
				werr = rerr
			}
			break
		}
	}
	if err := out.Sync(); werr == nil {
		werr = err
	}
	if err := out.Close(); werr == nil {
		werr = err
	}
	if werr != nil {
		var pe *os.PathError
		if errors.As(werr, &pe) && strings.Contains(strings.ToLower(pe.Err.Error()), "no space") {
			return fatalErr{"The Mac ran out of free space while downloading. Free some space and press Retry."}
		}
		return werr
	}
	if n < f.Size {
		return errors.New("connection closed early")
	}
	return nil
}

func verifyFile(p string, f *iFile) error {
	if fileSize(p) != f.Size {
		return errCorrupt
	}
	var h hash.Hash
	want := ""
	switch {
	case f.sha256 != "":
		h, want = sha256.New(), f.sha256
	case f.gsha1 != "":
		h, want = sha1.New(), f.gsha1
		fmt.Fprintf(h, "blob %d\x00", f.Size)
	default:
		return nil // size-only entry
	}
	fh, err := os.Open(p)
	if err != nil {
		return err
	}
	defer fh.Close()
	if _, err := io.Copy(h, fh); err != nil {
		return err
	}
	if hex.EncodeToString(h.Sum(nil)) != want {
		return errCorrupt
	}
	return nil
}
