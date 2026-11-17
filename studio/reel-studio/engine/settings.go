// KBB Studio engine · settings (round 3, 16 Nov 2026): the Configure page's back end.
//
//   - Connect Claude: the owner's Anthropic API key lives in <studio>/settings/claude.json (0600), written and read ONLY
//     here. settings/ is never a read or write root, so the app, a project, an export, a company file or a log can never
//     contain it; the app only learns "set" and the last four characters.
//   - Claude calls are made BY THE ENGINE, to exactly one URL (https://api.anthropic.com/v1/messages), only after the
//     app's per-session permission for that URL (/api/permission, scope exact), and only for the tasks below: the engine
//     builds every request itself from a short, typed input, so nothing but the brief text and the catalogue names it
//     needs (or the caption lines to translate) ever leaves the Mac. No redirects are followed.
//     POST /api/claude/run {task:"test"}                                   -> {ok, model, ms}
//     POST /api/claude/run {task:"brief", brief, catalogue:[names], company, industry}
//     -> {ok, model, ms, result:{...spec}}
//     POST /api/claude/run {task:"translate", lines:[...], from, to, glossary:[...]}
//     -> {ok, model, ms, result:{lines:[...]}}
//     (the translation lane's "Best quality (Claude)" calls task "translate"; see app/settings/claude.js claudeRun()).
//   - GET/POST /api/claude: status {set, hint, model, models, connected, lastTest} / save {key?, model?} / {disconnect:true}
//   - GET/POST /api/prefs: small non-secret preferences (language defaults ...), settings/prefs.json, ≤ 16 KB
//   - GET /api/folders: where projects / exports / library / models live, with sizes; POST /api/open {path}: open one in
//     Finder (a folder inside the studio only)
//   - POST /api/models/remove {id}: delete one installed model's files (never while the installer runs)
//   - -test-claude-base http://127.0.0.1:<port> (TESTS ONLY, never set by the launcher) sends Claude calls to a local mock
//     (tools/d-claude-mock.cjs) instead of api.anthropic.com.
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"
)

const claudeURL = "https://api.anthropic.com/v1/messages"
const claudeAPIVersion = "2026-10-21"

// the choices the Configure page offers; the default is the current Sonnet (the owner: "Sonnet-class"), and any
// claude-* id he types is accepted
var claudeModels = []map[string]string{
	{"id": "claude-sonnet-5-5", "label": "Claude Sonnet 5.5 — fast, very good (default)"},
	{"id": "claude-opus-5-5", "label": "Claude Opus 5.5 — most capable, slower, ~2× the price"},
	{"id": "claude-haiku-5-5", "label": "Claude Haiku 5.5 — quickest and cheapest"},
}

const defaultClaudeModel = "claude-sonnet-5-5"

var (
	claudeKeyRe   = regexp.MustCompile(`^sk-ant-[A-Za-z0-9_\-]{20,200}$`)
	claudeModelRe = regexp.MustCompile(`^claude-[a-z0-9][a-z0-9.\-]{2,60}$`)
)

type claudeConf struct {
	Key       string `json:"key,omitempty"`
	Model     string `json:"model,omitempty"`
	Connected string `json:"connected,omitempty"` // when the key was saved
	LastTest  string `json:"lastTest,omitempty"`  // "ok <time>" or "fail <time> <reason>"
}

func (s *server) settingsDir() string { return filepath.Join(s.dataDir(), "settings") }
func (s *server) claudeFile() string  { return filepath.Join(s.settingsDir(), "claude.json") }
func (s *server) prefsFile() string   { return filepath.Join(s.settingsDir(), "prefs.json") }
func (s *server) claudeConfig() claudeConf {
	var c claudeConf
	b, err := os.ReadFile(s.claudeFile())
	if err == nil {
		_ = json.Unmarshal(b, &c)
	}
	if !claudeKeyRe.MatchString(c.Key) {
		c.Key = ""
	}
	if !claudeModelRe.MatchString(c.Model) {
		c.Model = defaultClaudeModel
	}
	return c
}

// writePrivate: settings/<file>, 0600 in a 0700 folder, atomic
func (s *server) writePrivate(p string, v any) error {
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(v, "", "  ")
	tmp := p + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, p)
}

func (s *server) claudeStatus() map[string]any {
	c := s.claudeConfig()
	st := map[string]any{"set": c.Key != "", "model": c.Model, "models": claudeModels, "connected": c.Connected, "lastTest": c.LastTest, "url": claudeURL}
	if c.Key != "" {
		st["hint"] = "…" + c.Key[len(c.Key)-4:]
	}
	return st
}

// settingsAPI handles the routes of this file; false = not ours
func (s *server) settingsAPI(w http.ResponseWriter, r *http.Request, p string) bool {
	switch p {
	case "/api/claude":
		switch r.Method {
		case http.MethodGet:
			writeJSON(w, s.claudeStatus())
		case http.MethodPost:
			var b struct {
				Key        *string `json:"key"`
				Model      *string `json:"model"`
				Disconnect bool    `json:"disconnect"`
			}
			if json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&b) != nil {
				jsonErr(w, 400, "bad request")
				return true
			}
			s.mu.Lock()
			defer s.mu.Unlock()
			if b.Disconnect {
				if err := os.Remove(s.claudeFile()); err != nil && !errors.Is(err, fs.ErrNotExist) {
					jsonErr(w, 500, "could not remove the key")
					return true
				}
				s.dropGrant("exact " + claudeURL)
				log.Printf("claude: disconnected (key removed)")
				writeJSON(w, s.claudeStatus())
				return true
			}
			c := s.claudeConfig()
			if b.Key != nil {
				k := strings.TrimSpace(*b.Key)
				if !claudeKeyRe.MatchString(k) {
					jsonErr(w, 400, "That does not look like a Claude API key. It starts with sk-ant- (copy it again from console.anthropic.com → API keys).")
					return true
				}
				c.Key, c.Connected, c.LastTest = k, time.Now().Format(time.RFC3339), ""
			}
			if b.Model != nil {
				m := strings.TrimSpace(*b.Model)
				if !claudeModelRe.MatchString(m) {
					jsonErr(w, 400, "The model must be a Claude model id, e.g. "+defaultClaudeModel)
					return true
				}
				c.Model = m
			}
			if err := s.writePrivate(s.claudeFile(), c); err != nil {
				jsonErr(w, 500, "could not save: "+err.Error())
				return true
			}
			log.Printf("claude: settings saved (key set: %v, model %s)", c.Key != "", c.Model)
			writeJSON(w, s.claudeStatus())
		default:
			jsonErr(w, 405, "GET or POST")
		}
		return true

	case "/api/claude/run":
		if r.Method != http.MethodPost {
			jsonErr(w, 405, "POST only")
			return true
		}
		s.claudeRun(w, r)
		return true

	case "/api/prefs":
		switch r.Method {
		case http.MethodGet:
			b, err := os.ReadFile(s.prefsFile())
			if err != nil || !json.Valid(b) {
				b = []byte("{}")
			}
			w.Header().Set("Content-Type", "application/json")
			w.Header().Set("Cache-Control", "no-store")
			_, _ = w.Write(b)
		case http.MethodPost:
			var m map[string]any
			body, _ := io.ReadAll(io.LimitReader(r.Body, 16<<10+1))
			if len(body) > 16<<10 || json.Unmarshal(body, &m) != nil || m == nil {
				jsonErr(w, 400, "preferences must be a JSON object under 16 KB")
				return true
			}
			for k := range m { // preferences are never secrets: a key-looking name is refused so nobody parks one here
				if regexp.MustCompile(`(?i)(api.?key|secret|token|password)`).MatchString(k) {
					jsonErr(w, 400, "secrets do not belong in preferences")
					return true
				}
			}
			s.mu.Lock()
			defer s.mu.Unlock()
			if r.URL.Query().Get("merge") == "1" {
				// ?merge=1 (app/core/prefs.js): change only the keys sent, one level deep (an object merges into the
				// object already there; null removes), so two screens saving different preferences never undo each other
				var cur map[string]any
				if b, err := os.ReadFile(s.prefsFile()); err != nil || json.Unmarshal(b, &cur) != nil || cur == nil {
					cur = map[string]any{}
				}
				for k, v := range m {
					old, oldObj := cur[k].(map[string]any)
					nv, newObj := v.(map[string]any)
					switch {
					case v == nil:
						delete(cur, k)
					case oldObj && newObj:
						for kk, vv := range nv {
							if vv == nil {
								delete(old, kk)
							} else {
								old[kk] = vv
							}
						}
					case newObj:
						for kk, vv := range nv {
							if vv == nil {
								delete(nv, kk)
							}
						}
						cur[k] = nv
					default:
						cur[k] = v
					}
				}
				if b, _ := json.Marshal(cur); len(b) > 16<<10 {
					jsonErr(w, 400, "preferences must stay under 16 KB")
					return true
				}
				m = cur
			}
			if err := s.writePrivate(s.prefsFile(), m); err != nil {
				jsonErr(w, 500, err.Error())
				return true
			}
			writeJSON(w, m)
		default:
			jsonErr(w, 405, "GET or POST")
		}
		return true

	case "/api/folders":
		out := []map[string]any{}
		for _, f := range []struct{ id, rel, label string }{
			{"workspaces", "workspaces", "Companies: projects, exports and designs"},
			{"library", "library", "Your library: your own music and saved people photos (the built-in music and sound effects ship with the app)"},
			{"brands", "brands", "Your brand kits: logos, colours, fonts"},
			{"models", "models", "AI models (downloaded once)"},
		} {
			n, size := dirSize(filepath.Join(s.dataDir(), f.rel))
			out = append(out, map[string]any{"id": f.id, "path": f.rel, "label": f.label, "files": n, "size": size})
		}
		writeJSON(w, map[string]any{"root": s.dataShow, "app": s.root, "separate": s.separateData(), "free": freeBytes(s.dataDir()), "folders": out})
		return true

	case "/api/open":
		var b struct{ Path string }
		if r.Method != http.MethodPost || json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&b) != nil {
			jsonErr(w, 400, "bad request")
			return true
		}
		abs, err := s.resolve(strings.TrimSuffix(b.Path, "/"), readRoots)
		if err != nil {
			jsonErr(w, 403, "path refused")
			return true
		}
		if st, err := os.Stat(abs); err != nil || !st.IsDir() {
			_ = os.MkdirAll(abs, 0o755) // a company folder that has nothing in it yet
			if st, err := os.Stat(abs); err != nil || !st.IsDir() {
				jsonErr(w, 404, "not a folder")
				return true
			}
		}
		opened := false
		if !s.noBrowser { // the test runs (-no-browser) never pop windows
			var cmd *exec.Cmd
			switch runtime.GOOS {
			case "darwin":
				cmd = exec.Command("open", abs)
			case "linux":
				cmd = exec.Command("xdg-open", abs)
			}
			if cmd != nil && cmd.Start() == nil {
				opened = true
			}
		}
		writeJSON(w, map[string]any{"ok": true, "opened": opened, "path": b.Path})
		return true

	case "/api/models/remove":
		var b struct{ ID string }
		if r.Method != http.MethodPost || json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&b) != nil {
			jsonErr(w, 400, "bad request")
			return true
		}
		if err := s.removeModel(b.ID); err != nil {
			jsonErr(w, 400, err.Error())
			return true
		}
		writeJSON(w, s.modelStatus())
		return true
	}
	return false
}

func dirSize(root string) (int, int64) {
	n, size := 0, int64(0)
	_ = filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if !d.IsDir() {
			if info, e := d.Info(); e == nil {
				n++
				size += info.Size()
			}
		}
		return nil
	})
	return n, size
}

func (s *server) dropGrant(k string) { delete(s.grants, k) } // caller holds s.mu

// removeModel: the files of one manifest model (and their .part leftovers); never a file the manifest does not list
func (s *server) removeModel(id string) error {
	s.inst.mu.Lock()
	busy := s.inst.State == "running"
	s.inst.mu.Unlock()
	if busy {
		return errors.New("A download is running. Pause it first.")
	}
	m, err := s.readManifest()
	if err != nil {
		return err
	}
	for _, md := range m.Models {
		if md.ID != id {
			continue
		}
		for _, f := range md.Files {
			dest, err := s.modelDest(md.Repo, f.Path)
			if err != nil {
				return errors.New("the model list has a bad entry")
			}
			_ = os.Remove(dest)
			_ = os.Remove(dest + ".part")
		}
		log.Printf("models: removed %s (%s)", md.ID, md.Repo)
		return nil
	}
	return errors.New("no such model")
}

// ── Claude ──

const briefSystem = `You read advertising briefs for a small company's design studio and return ONE JSON object that matches the schema.
Rules: use only the products, brands, countries and occupations from the catalogue lists the user message gives (exact ids);
never invent prices, discounts, dates, salaries, visa promises or guarantees that the brief does not state; if the brief does
not say something, leave the field empty. Copy must be short (headline at most 7 words, offer at most 8, CTA at most 4),
honest, and written for social media ads. Arabic copy must be natural Gulf-friendly Modern Standard Arabic, not a word-for-word
translation.`

var briefSchema = map[string]any{
	"type": "object", "additionalProperties": false,
	"required": []string{"kind", "occasion", "offer", "products", "brands", "countries", "visa", "intake", "occupations", "audience", "tone", "languages", "sizes", "cta", "copy"},
	"properties": map[string]any{
		"kind":        map[string]any{"type": "string", "enum": []string{"offer", "launch", "hiring", "study", "visa", "event", "brand", "generic"}},
		"occasion":    map[string]any{"type": "string"},
		"offer":       map[string]any{"type": "string"},
		"products":    map[string]any{"type": "array", "items": map[string]any{"type": "string"}},
		"brands":      map[string]any{"type": "array", "items": map[string]any{"type": "string"}},
		"countries":   map[string]any{"type": "array", "items": map[string]any{"type": "string"}},
		"visa":        map[string]any{"type": "string"},
		"intake":      map[string]any{"type": "string"},
		"occupations": map[string]any{"type": "array", "items": map[string]any{"type": "string"}},
		"audience":    map[string]any{"type": "string"},
		"tone":        map[string]any{"type": "string", "enum": []string{"luxury", "playful", "bold", "professional", "calm", "urgent", "warm"}},
		"languages":   map[string]any{"type": "array", "items": map[string]any{"type": "string", "enum": []string{"en", "ar"}}},
		"sizes":       map[string]any{"type": "array", "items": map[string]any{"type": "string", "enum": []string{"story", "post", "square"}}},
		"cta":         map[string]any{"type": "string"},
		"copy": map[string]any{"type": "array", "items": map[string]any{
			"type": "object", "additionalProperties": false, "required": []string{"lang", "headline", "offer", "cta"},
			"properties": map[string]any{"lang": map[string]any{"type": "string", "enum": []string{"en", "ar"}}, "headline": map[string]any{"type": "string"}, "offer": map[string]any{"type": "string"}, "cta": map[string]any{"type": "string"}},
		}},
	},
}

const translateSystem = `You translate short social-media caption lines. Keep every number, price, percentage, brand name, product
name and URL exactly as written; keep each glossary term as given; keep the meaning and the tone; one output line per input
line, in the same order; no notes.`

var translateSchema = map[string]any{
	"type": "object", "additionalProperties": false, "required": []string{"lines"},
	"properties": map[string]any{"lines": map[string]any{"type": "array", "items": map[string]any{"type": "string"}}},
}

var langRe = regexp.MustCompile(`^[a-z]{2,3}(-[A-Za-z]{2,4})?$`)

func clip(s string, n int) string {
	s = strings.TrimSpace(strings.ReplaceAll(s, "\x00", ""))
	if len([]rune(s)) > n {
		return string([]rune(s)[:n])
	}
	return s
}

func cleanList(in []string, maxN, maxLen int) []string {
	out := []string{}
	for _, x := range in {
		if x = clip(x, maxLen); x != "" && len(out) < maxN {
			out = append(out, x)
		}
	}
	return out
}

// claudeRun: build the request for one task from a typed input, send it, return the parsed JSON
func (s *server) claudeRun(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Task      string   `json:"task"`
		Brief     string   `json:"brief"`
		Catalogue []string `json:"catalogue"`
		Company   string   `json:"company"`
		Industry  string   `json:"industry"`
		Lines     []string `json:"lines"`
		From      string   `json:"from"`
		To        string   `json:"to"`
		Glossary  []string `json:"glossary"`
	}
	if json.NewDecoder(io.LimitReader(r.Body, 256<<10)).Decode(&in) != nil {
		jsonErr(w, 400, "bad request")
		return
	}
	c := s.claudeConfig()
	if c.Key == "" {
		jsonErr(w, 409, "Claude is not connected. Open ⚙ Configure → Connect Claude and paste your key.")
		return
	}
	s.mu.Lock()
	allowed := s.grants["exact "+claudeURL]
	s.mu.Unlock()
	if !allowed {
		jsonErr(w, 403, "not permitted: ask the owner first")
		return
	}
	var system, user string
	var schema map[string]any
	maxTokens := 4000
	switch in.Task {
	case "test":
		user, maxTokens = "Reply with the single word: ok", 256
	case "brief":
		brief := clip(in.Brief, 4000)
		if brief == "" {
			jsonErr(w, 400, "the brief is empty")
			return
		}
		system, schema = briefSystem, briefSchema
		user = "Company: " + clip(in.Company, 80) + " (" + clip(in.Industry, 80) + ")\nCatalogue (id = name): " + strings.Join(cleanList(in.Catalogue, 400, 120), "; ") + "\n\nBrief:\n" + brief
	case "translate":
		lines := cleanList(in.Lines, 400, 400)
		if len(lines) == 0 || !langRe.MatchString(in.From) || !langRe.MatchString(in.To) {
			jsonErr(w, 400, "translate needs lines and from/to language codes")
			return
		}
		system, schema = translateSystem, translateSchema
		lj, _ := json.Marshal(lines)
		user = "Translate from " + in.From + " to " + in.To + ".\nGlossary (keep as written): " + strings.Join(cleanList(in.Glossary, 200, 80), "; ") + "\nLines (JSON array):\n" + string(lj)
		maxTokens = 8000
	default:
		jsonErr(w, 400, "unknown task")
		return
	}

	body := map[string]any{"model": c.Model, "max_tokens": maxTokens, "messages": []map[string]any{{"role": "user", "content": user}}}
	oc := map[string]any{"effort": "low"}
	if schema != nil {
		oc["format"] = map[string]any{"type": "json_schema", "schema": schema}
	}
	body["output_config"] = oc
	if system != "" {
		body["system"] = system
	}
	beta := ""
	if c.Model == "claude-sonnet-5-5" || c.Model == "claude-opus-5-5" { // a refused request is re-run on a fallback model
		body["fallbacks"] = "default"
		beta = "server-side-fallback-2026-07-01"
	}
	t0 := time.Now()
	text, model, err := s.claudeSend(c.Key, beta, body)
	ms := time.Since(t0).Milliseconds()
	stamp := time.Now().Format("2006-01-02 15:04")
	if in.Task == "test" {
		s.mu.Lock()
		cc := s.claudeConfig()
		if err != nil {
			cc.LastTest = "fail " + stamp + " " + err.Error()
		} else {
			cc.LastTest = "ok " + stamp
		}
		_ = s.writePrivate(s.claudeFile(), cc)
		s.mu.Unlock()
	}
	log.Printf("claude: %s (%d chars out) -> %v in %d ms", in.Task, len(user), err == nil, ms)
	if err != nil {
		var ce claudeErr
		code := 502
		if errors.As(err, &ce) {
			code = ce.code
		}
		jsonErr(w, code, err.Error())
		return
	}
	out := map[string]any{"ok": true, "model": model, "ms": ms}
	if schema != nil {
		var res any
		if json.Unmarshal([]byte(text), &res) != nil {
			jsonErr(w, 502, "Claude's answer was not in the expected shape; the studio used its own reading instead.")
			return
		}
		out["result"] = res
	}
	writeJSON(w, out)
}

type claudeErr struct {
	code int
	msg  string
}

func (e claudeErr) Error() string { return e.msg }

// claudeSend: POST the Messages request (never follows a redirect), return the first text block and the model that answered
func (s *server) claudeSend(key, beta string, body map[string]any) (string, string, error) {
	b, _ := json.Marshal(body)
	target := claudeURL
	if s.claudeBase != "" {
		target = strings.TrimSuffix(s.claudeBase, "/") + "/v1/messages"
	}
	req, err := http.NewRequest(http.MethodPost, target, bytes.NewReader(b))
	if err != nil {
		return "", "", err
	}
	req.Header.Set("x-api-key", key)
	req.Header.Set("anthropic-version", claudeAPIVersion)
	req.Header.Set("content-type", "application/json")
	req.Header.Set("User-Agent", appName+"/"+version)
	if beta != "" {
		req.Header.Set("anthropic-beta", beta)
	}
	cl := &http.Client{Timeout: 120 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("redirect refused") }}
	resp, err := cl.Do(req)
	if err != nil {
		return "", "", claudeErr{502, "Could not reach Claude (api.anthropic.com). Check the internet connection."}
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 2<<20))
	if resp.StatusCode != 200 {
		var e struct {
			Error struct{ Type, Message string } `json:"error"`
		}
		_ = json.Unmarshal(raw, &e)
		msg := clip(strings.ReplaceAll(e.Error.Message, key, "…"), 300)
		switch resp.StatusCode {
		case 401:
			return "", "", claudeErr{401, "Claude refused the key (401). Paste it again from console.anthropic.com → API keys."}
		case 403:
			return "", "", claudeErr{403, "This key may not use that model or feature (403). " + msg}
		case 404:
			return "", "", claudeErr{404, "Claude does not know that model id (404). Pick another model in Connect Claude."}
		case 429:
			return "", "", claudeErr{429, "Claude is rate-limiting this key (429). Wait a minute and try again."}
		case 529, 503:
			return "", "", claudeErr{503, "Claude is busy right now. Try again in a moment."}
		}
		return "", "", claudeErr{502, fmt.Sprintf("Claude answered %d. %s", resp.StatusCode, msg)}
	}
	var m struct {
		Model      string                        `json:"model"`
		StopReason string                        `json:"stop_reason"`
		Content    []struct{ Type, Text string } `json:"content"`
	}
	if json.Unmarshal(raw, &m) != nil {
		return "", "", claudeErr{502, "Claude's answer could not be read."}
	}
	if m.StopReason == "refusal" {
		return "", "", claudeErr{422, "Claude declined this request. The studio's own reading is used instead."}
	}
	if m.StopReason == "max_tokens" {
		return "", "", claudeErr{502, "Claude's answer was cut off. Try a shorter brief."}
	}
	for _, c := range m.Content {
		if c.Type == "text" {
			return c.Text, m.Model, nil
		}
	}
	return "", m.Model, claudeErr{502, "Claude sent no text."}
}
