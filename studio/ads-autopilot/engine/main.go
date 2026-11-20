// KBB Ads Autopilot engine: the owner's local server for running kbeautybliss.com's Meta ads.
//
//   - listens on 127.0.0.1 only, on a FIXED port (41927; a random free one only when that is busy), so the page keeps
//     one origin, and opens the default browser (Chrome when present on a Mac)
//   - per-launch token: the page reads it from /api/info (same-origin only) and sends it as X-KAA on every call
//   - data in ~/KBB Ads Autopilot Data (outside the app folder: an update never touches it); keys in the Keychain
//   - stops itself when the last browser tab closes (the /api/events SSE stream is the heartbeat) or on /api/stop
//   - on open: connects to Meta, Claude and the shop, catches up, then cycles while open (scheduler.go)
//
// Go standard library only (CGO_ENABLED=0): no third-party module anywhere in engine/.
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
	"os"
	"os/exec"
	"os/signal"
	"path"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"kaa/metamock"
	"kaa/store"
)

const appName = "KBB Ads Autopilot"

// version is stamped from the VERSION file by build.sh (-ldflags "-X main.version=…"); the app folder's VERSION file
// is the one place the version lives.
var version = "dev"

// apiLevel goes up whenever the app needs an endpoint an older engine lacks; the page refuses to run on an older one.
// 1 (20 Nov 2026): the first engine.
const apiLevel = 1

const preferredPort = 41927

type server struct {
	root      string // the app folder (holds app/, engine/, VERSION)
	data      string
	token     string
	addr      string
	app       *App
	mu        sync.Mutex
	clients   int
	subs      map[chan []byte]bool
	idleT     *time.Timer
	idle      time.Duration
	shutdown  chan struct{}
	stopOnce  sync.Once
	exe       string
	noBrowser bool
	noRestart bool
	updMu     sync.Mutex
	updPend   *updPending
	updBusy   bool
	sched     *scheduler
}

func main() {
	exe, _ := os.Executable()
	home, _ := os.UserHomeDir()
	dir := flag.String("dir", filepath.Dir(filepath.Dir(exe)), "the app folder (holds app/, engine/, VERSION)")
	dataDir := flag.String("data", filepath.Join(home, "KBB Ads Autopilot Data"), "the owner's data folder, outside the app folder")
	port := flag.Int("port", preferredPort, "port (a random free one when busy; 0 = random)")
	noBrowser := flag.Bool("no-browser", false, "do not open a browser")
	idle := flag.Duration("idle", 20*time.Second, "stop this long after the last tab closes")
	afterUpdate := flag.Bool("after-update", false, "set by the engine itself when it restarts into a new version")
	mock := flag.Bool("mock", false, "TESTS ONLY: run against an in-process fake Meta, fake Claude and fake shop feed (never the real ones)")
	noSched := flag.Bool("no-scheduler", false, "TESTS ONLY: no automatic sync / cycles")
	flag.Parse()

	root, err := filepath.Abs(*dir)
	if err != nil {
		log.Fatal(err)
	}
	if r, err := filepath.EvalSymlinks(root); err == nil {
		root = r
	}
	data, _ := filepath.Abs(*dataDir)
	if err := os.MkdirAll(data, 0o700); err != nil {
		log.Fatalf("data folder %s: %v", data, err)
	}
	if r, err := filepath.EvalSymlinks(data); err == nil {
		data = r
	}
	if data == root || strings.HasPrefix(data, root+string(os.PathSeparator)) {
		log.Fatalf("the data folder %s is inside the app folder, where an update would replace it", data)
	}
	s := &server{root: root, data: data, token: randHex(16), subs: map[chan []byte]bool{}, idle: *idle, shutdown: make(chan struct{}), noBrowser: *noBrowser}
	if p, err := filepath.EvalSymlinks(exe); err == nil && exe != "" {
		s.exe = p
	}
	s.recoverInterrupted()
	if v := appVersionAt(root); v != "" && version == "dev" {
		version = v
	}

	st, err := store.Open(data)
	if err != nil {
		log.Fatalf("data folder: %v", err)
	}
	s.app = newApp(st, time.Now, s.publish)
	if *mock {
		if err := setupMock(s.app); err != nil {
			log.Fatal(err)
		}
	}
	s.app.wire()

	ln, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(*port))
	if err != nil && *port != 0 {
		log.Printf("port %d is busy (another %s still open?): using a free one for this session", *port, appName)
		ln, err = net.Listen("tcp", "127.0.0.1:0")
	}
	if err != nil {
		log.Fatalf("listen: %v", err)
	}
	s.addr = ln.Addr().String()
	s.idleT = time.AfterFunc(5*time.Minute, s.stop) // nobody connected within 5 minutes: stop
	srv := &http.Server{Handler: s, ReadHeaderTimeout: 20 * time.Second}
	go func() {
		if err := srv.Serve(ln); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatal(err)
		}
	}()
	u := "http://" + s.addr + "/app/"
	fmt.Printf("%s %s running at %s\n", appName, version, u)
	fmt.Printf("Your settings, history and creatives are kept in %s (keys in the %s).\n", data, map[string]string{"keychain": "macOS Keychain", "file": "data folder, 0600"}[s.app.sec.Kind()])
	fmt.Println("Keep this window open while it works. Close the browser tab to stop it; Meta's own rules keep the ads safe while it is closed.")
	if !*noBrowser && !*afterUpdate {
		openBrowser(u)
	}
	s.sched = newScheduler(s.app)
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		s.app.connectAll(ctx)
		if !*noSched {
			s.sched.start(s.shutdown)
		}
	}()

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
	_ = st.Close()
	fmt.Printf("%s stopped.\n", appName)
}

// setupMock: the -mock flag. Fake Meta + fake Claude + fake feed in this process, a throwaway token, a seeded owner
// campaign the app must never touch. The data folder is whatever -data says (the checks use a temp folder).
func setupMock(a *App) error {
	m := metamock.New("EAAMOCKTOKEN0000000000000000", "act_1000000001")
	m.Seed()
	mu, err := m.Start()
	if err != nil {
		return err
	}
	aux := &metamock.Aux{Key: "sk-ant-mock-0000000000"}
	au, err := aux.Start()
	if err != nil {
		return err
	}
	a.testMetaBase, a.testClaudeBase, a.testFeedURL = mu, au, au+"/feed.xml"
	_ = a.sec.Set("meta_token", m.Token)
	_ = a.sec.Set("anthropic_key", aux.Key)
	a.cfg.MetaAccountID, a.cfg.PageID, a.cfg.PixelID = m.Account, "200000000001", "300000000001"
	_ = a.st.Save("config", a.cfg)
	log.Printf("TEST MODE: Meta → %s, Claude + feed → %s (never the real services)", mu, au)
	mockURL = mu
	return nil
}

var mockURL string

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
	default:
		cmd = exec.Command("xdg-open", u)
	}
	_ = cmd.Start()
}

// ── routing ──

func (s *server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	h := w.Header()
	h.Set("X-Content-Type-Options", "nosniff")
	h.Set("Referrer-Policy", "no-referrer")
	h.Set("X-Frame-Options", "DENY")
	h.Set("Cross-Origin-Opener-Policy", "same-origin")
	h.Set("Cross-Origin-Resource-Policy", "same-origin")
	// DNS-rebinding guard: only our own address may be named
	if r.Host != s.addr && r.Host != strings.Replace(s.addr, "127.0.0.1", "localhost", 1) {
		jsonErr(w, http.StatusForbidden, "wrong host")
		return
	}
	// a page on another site may not drive this server, even with a guessed token
	if o := r.Header.Get("Origin"); o != "" && o != "http://"+r.Host {
		jsonErr(w, http.StatusForbidden, "cross-origin request refused")
		return
	}
	if sf := r.Header.Get("Sec-Fetch-Site"); sf == "cross-site" || sf == "same-site" {
		jsonErr(w, http.StatusForbidden, "cross-site request refused")
		return
	}
	p := r.URL.Path
	switch {
	case p == "/" || p == "/app":
		http.Redirect(w, r, "/app/", http.StatusFound)
	case p == "/api/info":
		writeJSON(w, map[string]any{"app": appName, "version": version, "apiLevel": apiLevel, "token": s.token, "dataDir": s.data})
	case strings.HasPrefix(p, "/api/"):
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
	t := r.Header.Get("X-KAA")
	if t == "" && r.URL.Path == "/api/events" {
		t = r.URL.Query().Get("t")
	}
	return subtle.ConstantTimeCompare([]byte(t), []byte(s.token)) == 1
}

func jsonErr(w http.ResponseWriter, code int, msg string) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(map[string]string{"error": msg})
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	_ = json.NewEncoder(w).Encode(v)
}

const placeholder = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KBB Ads Autopilot</title>
<style>:root{color-scheme:light dark}body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:48px 16px;background:Canvas;color:CanvasText}main{max-width:640px;margin:auto}</style></head>
<body><main><h1>KBB Ads Autopilot</h1><p>The engine is running. The app's screens (app/index.html) are not in this folder yet.</p></main></body></html>`

// static serves app/ (no listing, no dotfiles, no traversal) with a strict Content-Security-Policy.
func (s *server) static(w http.ResponseWriter, r *http.Request, rel string) {
	w.Header().Set("Content-Security-Policy", "default-src 'self'; img-src 'self' data: blob: https:; media-src 'self' blob: data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'")
	clean := path.Clean("/" + rel)
	if !strings.HasPrefix(clean, "/app") {
		jsonErr(w, 404, "not found")
		return
	}
	if strings.HasSuffix(rel, "/") || clean == "/app" {
		clean += "/index.html"
	}
	for _, seg := range strings.Split(clean, "/") {
		if strings.HasPrefix(seg, ".") {
			jsonErr(w, 404, "not found")
			return
		}
	}
	abs := filepath.Join(s.root, filepath.FromSlash(clean))
	real, err := filepath.EvalSymlinks(abs)
	if err != nil {
		if clean == "/app/index.html" {
			w.Header().Set("Content-Type", "text/html; charset=utf-8")
			_, _ = io.WriteString(w, placeholder)
			return
		}
		jsonErr(w, 404, "not found")
		return
	}
	appRoot, _ := filepath.EvalSymlinks(filepath.Join(s.root, "app"))
	if appRoot == "" || !strings.HasPrefix(real, appRoot+string(os.PathSeparator)) {
		jsonErr(w, 404, "not found")
		return
	}
	st, err := os.Stat(real)
	if err != nil || st.IsDir() {
		jsonErr(w, 404, "not found")
		return
	}
	if ct := mime.TypeByExtension(filepath.Ext(real)); ct != "" {
		w.Header().Set("Content-Type", ct)
	}
	if strings.HasSuffix(real, ".js") || strings.HasSuffix(real, ".mjs") {
		w.Header().Set("Content-Type", "text/javascript; charset=utf-8")
	}
	w.Header().Set("Cache-Control", "no-cache")
	f, err := os.Open(real)
	if err != nil {
		jsonErr(w, 404, "not found")
		return
	}
	defer f.Close()
	http.ServeContent(w, r, "", st.ModTime(), f)
}

// ── events: live pushes + heartbeat. The engine stops `idle` after the last stream closes. ──

func (s *server) publish(name string, v any) {
	b, _ := json.Marshal(v)
	msg := []byte("event: " + name + "\ndata: " + string(b) + "\n\n")
	s.mu.Lock()
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
	ch := make(chan []byte, 64)
	s.mu.Lock()
	s.subs[ch] = true
	s.clients++
	s.idleT.Stop()
	s.mu.Unlock()
	defer func() {
		s.mu.Lock()
		delete(s.subs, ch)
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
			_, _ = io.WriteString(w, "event: heartbeat\ndata: {}\n\n")
			fl.Flush()
		}
	}
}
