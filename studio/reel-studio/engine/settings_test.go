package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const testClaudeKey = "sk-ant-api03-TESTKEY_abcdefghijklmnopqrstuvwxyz0123456789-WXYZ"

func grantClaude(t *testing.T, s *server) {
	t.Helper()
	b, _ := json.Marshal(map[string]string{"url": claudeURL, "scope": "exact", "what": "Smart brief"})
	if w := call(s, "POST", "/api/permission", string(b)); w.Code != 200 {
		t.Fatalf("grant claude: %d %s", w.Code, w.Body)
	}
}

// The key: 0600 under settings/, never readable/writable/served through the file API, status shows only set + last four;
// a bad key or model is refused; Disconnect removes the file AND the session's permission.
func TestClaudeKeyPrivate(t *testing.T) {
	s := testServer(t)
	s.inst = &installer{s: s, State: "idle"}
	if w := call(s, "POST", "/api/claude", `{"key":"`+testClaudeKey+`"}`); w.Code != 200 {
		t.Fatalf("save: %d %s", w.Code, w.Body)
	}
	st, err := os.Stat(s.claudeFile())
	if err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("claude.json %v %v", err, st)
	}
	if d, _ := os.Stat(s.settingsDir()); d.Mode().Perm() != 0o700 {
		t.Fatalf("settings/ perm %v", d.Mode().Perm())
	}
	w := call(s, "GET", "/api/claude", "")
	if strings.Contains(w.Body.String(), "TESTKEY") || !strings.Contains(w.Body.String(), `"hint":"…WXYZ"`) || !strings.Contains(w.Body.String(), `"model":"claude-sonnet-5-5"`) {
		t.Fatalf("status leaks or misses: %s", w.Body)
	}
	for _, p := range []string{"settings/claude.json", "settings/prefs.json"} {
		if _, err := s.resolve(p, readRoots); err == nil {
			t.Fatalf("%s readable", p)
		}
		if _, err := s.resolve(p, writeRoots); err == nil {
			t.Fatalf("%s writable", p)
		}
		if w := call(s, "GET", "/"+p, ""); w.Code == 200 {
			t.Fatalf("%s served", p)
		}
	}
	for _, bad := range []string{`{"key":"sk-ant-short"}`, `{"key":"not-a-key-abcdefghijklmnopqrstuvwxyz"}`, `{"key":"sk-ant-abc\r\nX-Evil: 1abcdefghijklmnopqrstuvwxyz"}`, `{"model":"gpt-4"}`, `{"model":"claude-x; rm"}`} {
		if w := call(s, "POST", "/api/claude", bad); w.Code != 400 {
			t.Errorf("%s: %d, want 400", bad, w.Code)
		}
	}
	if s.claudeConfig().Key != testClaudeKey {
		t.Fatal("a refused save changed the key")
	}
	if w := call(s, "POST", "/api/claude", `{"model":"claude-opus-5-5"}`); w.Code != 200 || s.claudeConfig().Model != "claude-opus-5-5" || s.claudeConfig().Key != testClaudeKey {
		t.Fatalf("model change: %d %s", w.Code, w.Body)
	}
	grantClaude(t, s)
	if w := call(s, "POST", "/api/claude", `{"disconnect":true}`); w.Code != 200 || strings.Contains(w.Body.String(), `"set":true`) {
		t.Fatalf("disconnect: %d %s", w.Code, w.Body)
	}
	if _, err := os.Stat(s.claudeFile()); err == nil || s.grants["exact "+claudeURL] {
		t.Fatal("disconnect left the key file or the permission behind")
	}
}

// The Claude URL can be granted only exactly, and only the engine's own calls use it: /net/get cannot reach it.
func TestClaudeNotANetHost(t *testing.T) {
	s := testServer(t)
	grantClaude(t, s)
	if _, ok := s.permitted(claudeURL); ok {
		t.Fatal("api.anthropic.com must not be reachable through /net")
	}
	if w := call(s, "GET", "/net/get?url="+url.QueryEscape(claudeURL), ""); w.Code != 403 {
		t.Fatalf("/net/get to Claude: %d", w.Code)
	}
	for _, bad := range []map[string]string{{"url": "https://api.anthropic.com/v1/files", "scope": "exact"}, {"url": claudeURL, "scope": "repo"}, {"url": "https://api.anthropic.com/", "scope": "exact"}} {
		b, _ := json.Marshal(bad)
		if w := call(s, "POST", "/api/permission", string(b)); w.Code == 200 {
			t.Errorf("grant %v accepted", bad)
		}
	}
}

// A run needs the key AND this session's permission; the request carries the key, the version, the fallback beta and
// ONLY the brief + catalogue names; answers, refusals and errors are mapped to plain messages; no redirect is followed.
func TestClaudeRun(t *testing.T) {
	var last struct {
		hdr  http.Header
		body map[string]any
	}
	mode := "ok"
	mock := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		last.hdr = r.Header.Clone()
		b, _ := io.ReadAll(r.Body)
		last.body = nil
		_ = json.Unmarshal(b, &last.body)
		w.Header().Set("Content-Type", "application/json")
		switch mode {
		case "401":
			w.WriteHeader(401)
			_, _ = w.Write([]byte(`{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key ` + testClaudeKey + `"}}`))
		case "refusal":
			_, _ = w.Write([]byte(`{"model":"claude-sonnet-5-5","stop_reason":"refusal","content":[]}`))
		case "redirect":
			http.Redirect(w, r, "https://evil.example.com/", http.StatusTemporaryRedirect)
		default:
			_, _ = w.Write([]byte(`{"model":"claude-sonnet-5-5","stop_reason":"end_turn","content":[{"type":"thinking","thinking":""},{"type":"text","text":"{\"kind\":\"offer\",\"products\":[\"anua\"]}"}]}`))
		}
	}))
	defer mock.Close()
	s := testServer(t)
	s.inst = &installer{s: s, State: "idle"}
	s.claudeBase = mock.URL
	brief := `{"task":"brief","brief":"National Day 30% off Anua","catalogue":["anua = Anua Heartleaf mist"],"company":"KBB","industry":"Beauty"}`
	if w := call(s, "POST", "/api/claude/run", brief); w.Code != 409 {
		t.Fatalf("no key: %d", w.Code)
	}
	_ = s.writePrivate(s.claudeFile(), claudeConf{Key: testClaudeKey})
	if w := call(s, "POST", "/api/claude/run", brief); w.Code != 403 || last.hdr != nil {
		t.Fatalf("no permission: %d (a request reached the mock: %v)", w.Code, last.hdr != nil)
	}
	grantClaude(t, s)
	w := call(s, "POST", "/api/claude/run", brief)
	if w.Code != 200 || !strings.Contains(w.Body.String(), `"products":["anua"]`) {
		t.Fatalf("run: %d %s", w.Code, w.Body)
	}
	if last.hdr.Get("x-api-key") != testClaudeKey || last.hdr.Get("anthropic-version") != claudeAPIVersion || last.hdr.Get("anthropic-beta") != "server-side-fallback-2026-07-01" {
		t.Fatalf("headers: %v", last.hdr)
	}
	if last.body["model"] != "claude-sonnet-5-5" || last.body["fallbacks"] != "default" || last.body["system"] == nil {
		t.Fatalf("body: %v", last.body)
	}
	if _, bad := last.body["temperature"]; bad {
		t.Fatal("temperature must not be sent on 2026-10-21")
	}
	msgs := last.body["messages"].([]any)
	content := msgs[0].(map[string]any)["content"].(string)
	if len(msgs) != 1 || !strings.Contains(content, "National Day 30% off Anua") || !strings.Contains(content, "anua = Anua Heartleaf mist") {
		t.Fatalf("user content: %q", content)
	}
	oc := last.body["output_config"].(map[string]any)
	if oc["format"].(map[string]any)["type"] != "json_schema" {
		t.Fatalf("output_config: %v", oc)
	}
	for m, want := range map[string]int{"401": 401, "refusal": 422, "redirect": 502} {
		mode = m
		w := call(s, "POST", "/api/claude/run", brief)
		if w.Code != want || strings.Contains(w.Body.String(), "TESTKEY") {
			t.Errorf("%s: %d %s", m, w.Code, w.Body)
		}
	}
	mode = "ok"
	if w := call(s, "POST", "/api/claude/run", `{"task":"shell","brief":"x"}`); w.Code != 400 {
		t.Fatalf("unknown task: %d", w.Code)
	}
	_ = call(s, "POST", "/api/claude/run", `{"task":"translate","lines":["Hello"],"from":"en","to":"ar"}`) // the translation hook's request shape
	if last.body["max_tokens"] == nil || !strings.Contains(last.body["messages"].([]any)[0].(map[string]any)["content"].(string), `["Hello"]`) {
		t.Fatalf("translate request: %v", last.body)
	}
}

// Preferences refuse anything that looks like a secret; /api/open stays inside the studio; models/remove only deletes
// manifest files.
func TestPrefsOpenRemove(t *testing.T) {
	s := testServer(t)
	s.inst = &installer{s: s, State: "idle"}
	s.noBrowser = true
	if w := call(s, "POST", "/api/prefs", `{"captionLang":"ar","targets":["ar","ur"]}`); w.Code != 200 {
		t.Fatalf("prefs: %d", w.Code)
	}
	if w := call(s, "GET", "/api/prefs", ""); !strings.Contains(strings.ReplaceAll(w.Body.String(), " ", ""), `"captionLang":"ar"`) {
		t.Fatalf("prefs read: %s", w.Body)
	}
	if w := call(s, "POST", "/api/prefs", `{"claudeApiKey":"sk-ant-x"}`); w.Code != 400 {
		t.Fatalf("secret in prefs: %d", w.Code)
	}
	for _, p := range []string{"../", "/etc", "settings", "workspaces/../settings"} {
		if w := call(s, "POST", "/api/open", `{"path":"`+p+`"}`); w.Code != 403 {
			t.Errorf("open %s: %d", p, w.Code)
		}
	}
	if w := call(s, "POST", "/api/open", `{"path":"workspaces/kbb/exports"}`); w.Code != 200 || strings.Contains(w.Body.String(), `"opened":true`) {
		t.Fatalf("open exports: %d %s", w.Code, w.Body)
	}
	man := `{"models":[{"id":"m1","repo":"o/r","files":[{"path":"a.onnx","size":3}]}]}`
	_ = os.WriteFile(filepath.Join(s.root, "models", "manifest.json"), []byte(man), 0o644)
	_ = os.MkdirAll(filepath.Join(s.root, "models", "o", "r"), 0o755)
	_ = os.WriteFile(filepath.Join(s.root, "models", "o", "r", "a.onnx"), []byte("abc"), 0o644)
	_ = os.WriteFile(filepath.Join(s.root, "models", "o", "r", "keep.txt"), []byte("x"), 0o644)
	if w := call(s, "POST", "/api/models/remove", `{"id":"m1"}`); w.Code != 200 {
		t.Fatalf("remove: %d %s", w.Code, w.Body)
	}
	if _, err := os.Stat(filepath.Join(s.root, "models", "o", "r", "a.onnx")); err == nil {
		t.Fatal("model file not removed")
	}
	if _, err := os.Stat(filepath.Join(s.root, "models", "o", "r", "keep.txt")); err != nil {
		t.Fatal("a file outside the manifest was removed")
	}
	if w := call(s, "POST", "/api/models/remove", `{"id":"nope"}`); w.Code != 400 {
		t.Fatalf("unknown model: %d", w.Code)
	}
	if w := call(s, "GET", "/api/folders", ""); w.Code != 200 || !strings.Contains(w.Body.String(), `"models"`) {
		t.Fatalf("folders: %d %s", w.Code, w.Body)
	}
}
