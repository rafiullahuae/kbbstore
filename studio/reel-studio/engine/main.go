// KBB Studio engine: a tiny local server for the studio folder.
//
//   - listens on 127.0.0.1 only, on a random free port, and opens the default browser (Chrome when present on a Mac)
//   - serves app/, vendor/, models/, library/, brands/, workspaces/ with the COOP/COEP headers multithreaded WASM needs
//   - a JSON file API that only reads/writes inside the studio folder (path traversal and symlink escapes refused)
//   - /net/* is the ONLY way out to the internet: refused unless the app has sent a permission grant for that exact
//     item this session, and only to an allowlist of hosts
//   - stops itself when the last browser tab closes (the /api/events stream is the heartbeat) or on /api/stop
package main

import (
	"context"
	"crypto/rand"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"mime"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

const appName = "KBB Studio" // keep in step with app/core/config.js APP_NAME
const version = "0.1.0"

var (
	readRoots  = map[string]bool{"app": true, "vendor": true, "models": true, "library": true, "brands": true, "workspaces": true}
	writeRoots = map[string]bool{"workspaces": true, "library": true, "brands": true, "models": true}
	netHosts   = map[string]bool{"huggingface.co": true, "cdn-lfs.huggingface.co": true, "cas-bridge.xethub.hf.co": true}
	hfRepo     = regexp.MustCompile(`^https://huggingface\.co/[A-Za-z0-9][\w.-]*/[A-Za-z0-9][\w.-]*/$`)
)

type server struct {
	root      string // real path of the studio folder
	token     string
	addr      string
	mu        sync.Mutex
	grants    map[string]bool // "exact <url>" or "repo <prefix>"
	clients   int
	subs      map[chan []byte]bool
	idleT     *time.Timer
	idle      time.Duration
	jobs      map[string]*job
	shutdown  chan struct{}
	stopOnce  sync.Once
	client    *http.Client
	inst      *installer
	testBase  string // test-only stand-in for huggingface.co (the check scripts' local server); never set by the launcher
	photoBase string // test-only stand-in for the photo hosts (tools/stock-mock.cjs); never set by the launcher
}

type job struct {
	ID    string `json:"id"`
	URL   string `json:"url"`
	Dest  string `json:"dest"`
	Done  int64  `json:"done"`
	Total int64  `json:"total"`
	State string `json:"state"`
	Error string `json:"error,omitempty"`
}

func main() {
	exe, _ := os.Executable()
	defDir := filepath.Dir(filepath.Dir(exe))
	dir := flag.String("dir", defDir, "the studio folder (holds app/, vendor/, workspaces/ ...)")
	port := flag.Int("port", 0, "port (0 = a random free port)")
	noBrowser := flag.Bool("no-browser", false, "do not open a browser")
	idle := flag.Duration("idle", 20*time.Second, "stop this long after the last tab closes")
	testBase := flag.String("test-hf-base", "", "TESTS ONLY: download models from this http://127.0.0.1:<port> server instead of huggingface.co")
	photoBase := flag.String("test-photo-base", "", "TESTS ONLY: send Pexels / Unsplash requests to this http://127.0.0.1:<port> mock server")
	flag.Parse()

	real, err := filepath.EvalSymlinks(*dir)
	if err != nil {
		log.Fatalf("studio folder: %v", err)
	}
	real, _ = filepath.Abs(real)
	if st, err := os.Stat(filepath.Join(real, "app", "index.html")); err != nil || st.IsDir() {
		log.Fatalf("%s: no app/index.html here — run me from the %s folder", real, appName)
	}
	for _, d := range []string{"workspaces", "models", "library", "brands"} {
		_ = os.MkdirAll(filepath.Join(real, d), 0o755)
	}

	ln, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(*port))
	if err != nil {
		log.Fatalf("listen: %v", err)
	}
	s := &server{
		root: real, token: randHex(16), addr: ln.Addr().String(),
		grants: map[string]bool{}, subs: map[chan []byte]bool{}, jobs: map[string]*job{},
		idle: *idle, shutdown: make(chan struct{}),
	}
	s.inst = &installer{s: s, State: "idle"}
	if *testBase != "" {
		if !strings.HasPrefix(*testBase, "http://127.0.0.1:") {
			log.Fatal("-test-hf-base must be http://127.0.0.1:<port>")
		}
		s.testBase = *testBase
	}
	if *photoBase != "" {
		if !strings.HasPrefix(*photoBase, "http://127.0.0.1:") {
			log.Fatal("-test-photo-base must be http://127.0.0.1:<port>")
		}
		s.photoBase = *photoBase
		log.Printf("TEST MODE: photo searches go to %s, not to Pexels / Unsplash", s.photoBase)
	}
	s.client = &http.Client{
		Timeout:       0,
		CheckRedirect: s.checkRedirect,
	}
	// nobody connected within 5 minutes (browser never opened?): stop
	s.idleT = time.AfterFunc(5*time.Minute, s.stop)

	srv := &http.Server{Handler: s, ReadHeaderTimeout: 20 * time.Second}
	go func() {
		if err := srv.Serve(ln); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatal(err)
		}
	}()
	u := "http://" + s.addr + "/app/"
	fmt.Printf("%s %s running at %s\n", appName, version, u)
	fmt.Println("Keep this window open while you work. Close the browser tab (or press Stop in the app) to finish.")
	if !*noBrowser {
		openBrowser(u)
	}

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, os.Interrupt, syscall.SIGTERM)
	select {
	case <-sig:
	case <-s.shutdown:
	}
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	s.mu.Lock()
	for ch := range s.subs {
		close(ch)
		delete(s.subs, ch)
	}
	s.mu.Unlock()
	_ = srv.Shutdown(ctx)
	fmt.Printf("%s stopped.\n", appName)
}

// redirectOK: Hugging Face hands file downloads to its own CDNs (cdn-lfs.huggingface.co, cas-bridge.xethub.hf.co and,
// since 2026, <region>.aws.cdn.hf.co such as us.aws.cdn.hf.co). A request must START at an allowlisted host; its
// redirects may only go to those hosts or to subdomains of HF's own hf.co / huggingface.co, by DNS name (never an
// IP literal) and on the default https port.
// checkRedirect: every redirect /net follows must stay on the allowlist (https, default port, redirectOK); the test-only
// local servers are the one exception. (Go itself drops the Authorization header when a redirect changes host.)
func (s *server) checkRedirect(req *http.Request, via []*http.Request) error {
	if len(via) > 10 {
		return errors.New("too many redirects")
	}
	if s.testBase != "" && strings.HasPrefix(req.URL.String(), s.testBase) || s.photoBase != "" && strings.HasPrefix(req.URL.String(), s.photoBase) {
		return nil
	}
	if req.URL.Scheme != "https" || req.URL.Port() != "" || !redirectOK(req.URL.Hostname()) {
		log.Printf("download: redirect to %s refused (not on the allowlist)", req.URL.Hostname())
		return fmt.Errorf("redirect to %s refused (not on the allowlist)", req.URL.Hostname())
	}
	log.Printf("download: redirect to %s", req.URL.Hostname())
	return nil
}

func redirectOK(host string) bool {
	if netHosts[host] {
		return true
	}
	if net.ParseIP(host) != nil || strings.ContainsAny(host, ":@") {
		return false
	}
	return strings.HasSuffix(host, ".hf.co") || strings.HasSuffix(host, ".huggingface.co")
}

func (s *server) stop() { s.stopOnce.Do(func() { close(s.shutdown) }) }

func randHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b)
}

func openBrowser(u string) {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "darwin":
		if _, err := os.Stat("/Applications/Google Chrome.app"); err == nil {
			cmd = exec.Command("open", "-a", "Google Chrome", u)
		} else {
			cmd = exec.Command("open", u)
		}
	case "windows":
		cmd = exec.Command("rundll32", "url.dll,FileProtocolHandler", u)
	default:
		cmd = exec.Command("xdg-open", u)
	}
	_ = cmd.Start()
}

// ── routing ──

func (s *server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	h := w.Header()
	h.Set("Cross-Origin-Opener-Policy", "same-origin")
	h.Set("Cross-Origin-Embedder-Policy", "require-corp")
	h.Set("Cross-Origin-Resource-Policy", "same-origin")
	h.Set("X-Content-Type-Options", "nosniff")
	h.Set("Referrer-Policy", "no-referrer")

	// DNS-rebinding guard: only our own address may be named
	host := r.Host
	if host != s.addr && host != strings.Replace(s.addr, "127.0.0.1", "localhost", 1) {
		jsonErr(w, http.StatusForbidden, "wrong host")
		return
	}
	p := r.URL.Path
	switch {
	case p == "/" || p == "/app":
		http.Redirect(w, r, "/app/", http.StatusFound)
	case strings.HasPrefix(p, "/api/") || strings.HasPrefix(p, "/net/"):
		if !s.authed(r) {
			jsonErr(w, http.StatusUnauthorized, "missing or wrong token")
			return
		}
		s.api(w, r, p)
	case r.Method == http.MethodGet || r.Method == http.MethodHead:
		s.static(w, r, strings.TrimPrefix(p, "/"))
	default:
		jsonErr(w, http.StatusMethodNotAllowed, "method not allowed")
	}
}

func (s *server) authed(r *http.Request) bool {
	t := r.Header.Get("X-RS-Token")
	if t == "" && r.URL.Path == "/api/events" {
		t = r.URL.Query().Get("t")
	}
	return subtle.ConstantTimeCompare([]byte(t), []byte(s.token)) == 1
}

func jsonErr(w http.ResponseWriter, code int, msg string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(map[string]string{"error": msg})
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	_ = json.NewEncoder(w).Encode(v)
}

var errRefused = errors.New("path refused")

// resolve maps a folder-relative path to an absolute one inside the studio folder, or refuses it.
// Refused: empty, absolute, backslashes, NUL, any '..' or hidden segment, a first segment outside roots,
// and anything whose real path (symlinks followed) leaves the studio folder.
func (s *server) resolve(rel string, roots map[string]bool) (string, error) {
	if rel == "" || strings.ContainsAny(rel, "\\\x00") || strings.HasPrefix(rel, "/") {
		return "", errRefused
	}
	segs := strings.Split(rel, "/")
	for i, seg := range segs {
		if seg == "" && i == len(segs)-1 {
			continue // trailing slash
		}
		if seg == "" || seg == "." || seg == ".." || strings.HasPrefix(seg, ".") {
			return "", errRefused
		}
	}
	if !roots[segs[0]] {
		return "", errRefused
	}
	abs := filepath.Join(s.root, filepath.FromSlash(path.Clean(rel)))
	// follow symlinks on the longest existing prefix and make sure we are still inside the folder
	probe := abs
	for {
		if real, err := filepath.EvalSymlinks(probe); err == nil {
			if real != s.root && !strings.HasPrefix(real, s.root+string(os.PathSeparator)) {
				return "", errRefused
			}
			break
		}
		parent := filepath.Dir(probe)
		if parent == probe {
			return "", errRefused
		}
		probe = parent
	}
	return abs, nil
}

func (s *server) static(w http.ResponseWriter, r *http.Request, rel string) {
	if rel == "app/" {
		rel = "app/index.html"
	}
	abs, err := s.resolve(rel, readRoots)
	if err != nil {
		jsonErr(w, http.StatusForbidden, "not allowed")
		return
	}
	f, err := os.Open(abs)
	if err != nil {
		jsonErr(w, http.StatusNotFound, "not found")
		return
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil || st.IsDir() {
		if err == nil && st.IsDir() {
			if _, e := os.Stat(filepath.Join(abs, "index.html")); e == nil {
				http.Redirect(w, r, "/"+strings.TrimSuffix(rel, "/")+"/index.html", http.StatusFound)
				return
			}
		}
		jsonErr(w, http.StatusNotFound, "not found")
		return
	}
	ext := strings.ToLower(filepath.Ext(abs))
	switch ext {
	case ".mjs", ".js":
		w.Header().Set("Content-Type", "text/javascript; charset=utf-8")
	case ".wasm":
		w.Header().Set("Content-Type", "application/wasm")
	case ".onnx", ".bin", ".task", ".tflite":
		w.Header().Set("Content-Type", "application/octet-stream")
	case ".json":
		w.Header().Set("Content-Type", "application/json")
	default:
		if t := mime.TypeByExtension(ext); t != "" {
			w.Header().Set("Content-Type", t)
		}
	}
	w.Header().Set("Cache-Control", "no-cache")
	// HTML pages of the app carry the session token so their scripts can call the API
	if ext == ".html" && strings.HasPrefix(rel, "app/") {
		b, err := io.ReadAll(f)
		if err != nil {
			jsonErr(w, 500, "read failed")
			return
		}
		meta := `<meta name="rs-token" content="` + s.token + `">`
		html := string(b)
		if i := strings.Index(strings.ToLower(html), "<head>"); i >= 0 {
			html = html[:i+6] + meta + html[i+6:]
		} else {
			html = meta + html
		}
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		w.Header().Set("Cache-Control", "no-store")
		_, _ = io.WriteString(w, html)
		return
	}
	http.ServeContent(w, r, st.Name(), st.ModTime(), f)
}

func (s *server) api(w http.ResponseWriter, r *http.Request, p string) {
	q := r.URL.Query()
	switch p {
	case "/api/info":
		writeJSON(w, map[string]any{"app": appName, "version": version, "os": runtime.GOOS, "arch": runtime.GOARCH, "netHosts": keys(netHosts)})

	case "/api/list":
		abs, err := s.resolve(strings.TrimSuffix(q.Get("path"), "/"), readRoots)
		if err != nil {
			jsonErr(w, 403, "path refused")
			return
		}
		ents, err := os.ReadDir(abs)
		if err != nil {
			writeJSON(w, []any{}) // a folder that isn't there yet is simply empty (no error noise in the browser)
			return
		}
		out := []map[string]any{}
		for _, e := range ents {
			if strings.HasPrefix(e.Name(), ".") {
				continue
			}
			info, err := e.Info()
			if err != nil {
				continue
			}
			out = append(out, map[string]any{"name": e.Name(), "dir": e.IsDir(), "size": info.Size(), "mtime": info.ModTime().UnixMilli()})
		}
		sort.Slice(out, func(i, j int) bool { return out[i]["name"].(string) < out[j]["name"].(string) })
		writeJSON(w, out)

	case "/api/stat":
		abs, err := s.resolve(q.Get("path"), readRoots)
		if err != nil {
			jsonErr(w, 403, "path refused")
			return
		}
		st, err := os.Stat(abs)
		if err != nil {
			writeJSON(w, map[string]any{"exists": false})
			return
		}
		writeJSON(w, map[string]any{"exists": true, "size": st.Size(), "dir": st.IsDir(), "mtime": st.ModTime().UnixMilli()})

	case "/api/write":
		if r.Method != http.MethodPut && r.Method != http.MethodPost {
			jsonErr(w, 405, "PUT only")
			return
		}
		rel := q.Get("path")
		abs, err := s.resolve(rel, writeRoots)
		if err != nil || strings.HasSuffix(rel, "/") {
			jsonErr(w, 403, "path refused")
			return
		}
		if err := writeAtomic(abs, http.MaxBytesReader(w, r.Body, 16<<30)); err != nil {
			jsonErr(w, 500, "write failed: "+err.Error())
			return
		}
		writeJSON(w, map[string]any{"ok": true, "path": rel})

	case "/api/delete":
		if r.Method != http.MethodPost {
			jsonErr(w, 405, "POST only")
			return
		}
		rel := strings.TrimSuffix(q.Get("path"), "/")
		abs, err := s.resolve(rel, map[string]bool{"workspaces": true, "library": true})
		// never a whole root or a whole workspace: only things inside them
		if err != nil || strings.Count(rel, "/") < 2 && strings.HasPrefix(rel, "workspaces/") || !strings.Contains(rel, "/") {
			jsonErr(w, 403, "path refused")
			return
		}
		if err := os.RemoveAll(abs); err != nil {
			jsonErr(w, 500, err.Error())
			return
		}
		writeJSON(w, map[string]any{"ok": true})

	case "/api/permission":
		var g struct{ URL, Scope, What string }
		if r.Method != http.MethodPost || json.NewDecoder(io.LimitReader(r.Body, 8192)).Decode(&g) != nil {
			jsonErr(w, 400, "bad request")
			return
		}
		u, err := url.Parse(g.URL)
		if err != nil || u.Scheme != "https" || !netHosts[u.Hostname()] || u.User != nil || u.Port() != "" {
			jsonErr(w, 403, "that host is not on the allowlist")
			return
		}
		switch g.Scope {
		case "exact":
		case "repo":
			if !hfRepo.MatchString(g.URL) {
				jsonErr(w, 403, "repo grants must be https://huggingface.co/<owner>/<repo>/")
				return
			}
		default:
			jsonErr(w, 400, "scope must be exact or repo")
			return
		}
		s.mu.Lock()
		s.grants[g.Scope+" "+g.URL] = true
		s.mu.Unlock()
		log.Printf("permission granted this session: %s %s (%s)", g.Scope, g.URL, g.What)
		writeJSON(w, map[string]any{"ok": true})

	case "/net/get":
		u, ok := s.permitted(q.Get("url"))
		if !ok {
			jsonErr(w, 403, "not permitted: ask the owner first")
			return
		}
		req, err := s.netRequest(u) // the photo APIs get the owner's key here (photos.go); nothing else does
		if err != nil {
			jsonErr(w, 400, "bad url")
			return
		}
		resp, err := s.client.Do(req)
		if err != nil {
			jsonErr(w, 502, err.Error())
			return
		}
		defer resp.Body.Close()
		if ct := resp.Header.Get("Content-Type"); ct != "" {
			w.Header().Set("Content-Type", ct)
		}
		w.WriteHeader(resp.StatusCode)
		_, _ = io.Copy(w, io.LimitReader(resp.Body, 64<<20))

	case "/net/download":
		var d struct{ URL, Dest string }
		if r.Method != http.MethodPost || json.NewDecoder(io.LimitReader(r.Body, 8192)).Decode(&d) != nil {
			jsonErr(w, 400, "bad request")
			return
		}
		u, ok := s.permitted(d.URL)
		if !ok {
			jsonErr(w, 403, "not permitted: ask the owner first")
			return
		}
		if !strings.HasPrefix(d.Dest, "models/") {
			jsonErr(w, 403, "downloads go to models/ only")
			return
		}
		abs, err := s.resolve(d.Dest, map[string]bool{"models": true})
		if err != nil {
			jsonErr(w, 403, "path refused")
			return
		}
		j := &job{ID: randHex(6), URL: u, Dest: d.Dest, State: "started"}
		s.mu.Lock()
		s.jobs[j.ID] = j
		s.mu.Unlock()
		go s.download(j, abs)
		writeJSON(w, j)

	case "/api/photo-keys":
		s.photoKeysAPI(w, r)

	case "/api/models":
		writeJSON(w, s.modelStatus())

	case "/api/models/install", "/api/models/resume":
		var b struct{ IDs []string }
		if r.Method != http.MethodPost {
			jsonErr(w, 405, "POST only")
			return
		}
		_ = json.NewDecoder(io.LimitReader(r.Body, 65536)).Decode(&b)
		if len(b.IDs) == 0 {
			s.inst.mu.Lock()
			b.IDs = append([]string(nil), s.inst.IDs...)
			s.inst.mu.Unlock()
		}
		if err := s.installModels(b.IDs); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		writeJSON(w, s.modelStatus())

	case "/api/models/pause":
		if r.Method != http.MethodPost {
			jsonErr(w, 405, "POST only")
			return
		}
		s.pauseModels()
		writeJSON(w, s.modelStatus())

	case "/api/events":
		s.events(w, r)

	case "/api/stop":
		writeJSON(w, map[string]any{"ok": true})
		go func() { time.Sleep(200 * time.Millisecond); s.stop() }()

	default:
		jsonErr(w, 404, "no such endpoint")
	}
}

func keys(m map[string]bool) []string {
	out := []string{}
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// permitted: the URL is https to an allowlisted host AND the app has a grant for it (exact, or its HF repo).
func (s *server) permitted(raw string) (string, bool) {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "https" || !netHosts[u.Hostname()] || u.User != nil || u.Port() != "" {
		return "", false
	}
	clean := u.String()
	if strings.Contains(u.Path, "..") {
		return "", false
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.grants["exact "+clean] {
		return clean, true
	}
	for g := range s.grants {
		if strings.HasPrefix(g, "repo ") && strings.HasPrefix(clean, strings.TrimPrefix(g, "repo ")) {
			return clean, true
		}
	}
	return "", false
}

func writeAtomic(abs string, body io.Reader) error {
	if err := os.MkdirAll(filepath.Dir(abs), 0o755); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(abs), ".part-*")
	if err != nil {
		return err
	}
	_, err = io.Copy(tmp, body)
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(tmp.Name())
		return err
	}
	return os.Rename(tmp.Name(), abs)
}

func (s *server) download(j *job, abs string) {
	fail := func(err error) {
		s.mu.Lock()
		j.State, j.Error = "error", err.Error()
		s.mu.Unlock()
		s.publish("progress", j)
	}
	resp, err := s.client.Get(j.URL)
	if err != nil {
		fail(err)
		return
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		fail(fmt.Errorf("HTTP %d", resp.StatusCode))
		return
	}
	j.Total = resp.ContentLength
	pr := &progressReader{r: resp.Body, every: 250 * time.Millisecond, fn: func(n int64) {
		s.mu.Lock()
		j.Done, j.State = n, "running"
		s.mu.Unlock()
		s.publish("progress", j)
	}}
	if err := writeAtomic(abs, pr); err != nil {
		fail(err)
		return
	}
	s.mu.Lock()
	j.Done, j.State = pr.n, "done"
	s.mu.Unlock()
	s.publish("progress", j)
}

type progressReader struct {
	r     io.Reader
	n     int64
	last  time.Time
	every time.Duration
	fn    func(int64)
}

func (p *progressReader) Read(b []byte) (int, error) {
	n, err := p.r.Read(b)
	p.n += int64(n)
	if time.Since(p.last) > p.every {
		p.last = time.Now()
		p.fn(p.n)
	}
	return n, err
}

// ── events: progress + heartbeat. The engine stops `idle` after the last stream closes. ──

func (s *server) publish(name string, v any) {
	s.mu.Lock()
	b, _ := json.Marshal(v)
	msg := []byte("event: " + name + "\ndata: " + string(b) + "\n\n")
	for ch := range s.subs {
		select {
		case ch <- msg:
		default:
		}
	}
	s.mu.Unlock()
}

func (s *server) events(w http.ResponseWriter, r *http.Request) {
	fl, ok := w.(http.Flusher)
	if !ok {
		jsonErr(w, 500, "no streaming")
		return
	}
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	ch := make(chan []byte, 32)
	s.mu.Lock()
	s.subs[ch] = true
	s.clients++
	s.idleT.Stop()
	s.mu.Unlock()
	defer func() {
		s.mu.Lock()
		if s.subs[ch] {
			delete(s.subs, ch)
		}
		s.clients--
		if s.clients <= 0 {
			s.idleT = time.AfterFunc(s.idle, s.stop)
		}
		s.mu.Unlock()
	}()
	_, _ = io.WriteString(w, "retry: 2000\nevent: hello\ndata: {}\n\n")
	fl.Flush()
	tick := time.NewTicker(15 * time.Second)
	defer tick.Stop()
	for {
		select {
		case <-r.Context().Done():
			return
		case <-s.shutdown:
			return
		case msg, ok := <-ch:
			if !ok {
				return
			}
			_, _ = w.Write(msg)
			fl.Flush()
		case <-tick.C:
			_, _ = io.WriteString(w, ": ping\n\n")
			fl.Flush()
		}
	}
}
