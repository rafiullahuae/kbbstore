package main

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"kaa/store"
)

// The connector speaks MCP over stdio: initialize echoes a protocol version it knows, tools/list carries the seven
// tools, a call with no engine running answers isError with "open the app" (never a crash), unknown methods are
// -32601, notifications get no answer. Mutation: answer notifications and the line count is wrong.
func TestMCPHandshakeAndNoEngine(t *testing.T) {
	in := strings.Join([]string{
		`{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}`,
		`{"jsonrpc":"2.0","method":"notifications/initialized"}`,
		`{"jsonrpc":"2.0","id":2,"method":"tools/list"}`,
		`{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_brief","arguments":{}}}`,
		`{"jsonrpc":"2.0","id":4,"method":"nope"}`,
	}, "\n") + "\n"
	var out bytes.Buffer
	runMCP(t.TempDir(), strings.NewReader(in), &out)
	lines := strings.Split(strings.TrimSpace(out.String()), "\n")
	byID := map[float64]map[string]any{}
	for _, l := range lines {
		var m map[string]any
		if err := json.Unmarshal([]byte(l), &m); err != nil {
			t.Fatalf("not JSON: %s", l)
		}
		if id, ok := m["id"].(float64); ok {
			byID[id] = m
		}
	}
	if len(lines) != 4 || len(byID) != 4 {
		t.Fatalf("want 4 answers, got %d: %s", len(lines), out.String())
	}
	if v := byID[1]["result"].(map[string]any)["protocolVersion"]; v != "2025-11-25" {
		t.Fatalf("protocol %v", v)
	}
	tools := byID[2]["result"].(map[string]any)["tools"].([]any)
	if len(tools) != 7 {
		t.Fatalf("%d tools", len(tools))
	}
	r3 := byID[3]["result"].(map[string]any)
	if r3["isError"] != true || !strings.Contains(r3["content"].([]any)[0].(map[string]any)["text"].(string), "not open") {
		t.Fatalf("no-engine answer %v", r3)
	}
	if byID[4]["error"].(map[string]any)["code"].(float64) != -32601 {
		t.Fatal("unknown method")
	}
}

func mustStore(t *testing.T) *store.Store {
	st, err := store.Open(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { st.Close() })
	return st
}

// Install connector keeps every other setting and server, backs the old file up, and refuses a broken file.
func TestMCPInstallMergesAndBacksUp(t *testing.T) {
	dir := t.TempDir()
	p := filepath.Join(dir, "Claude", "claude_desktop_config.json")
	t.Setenv("KAA_CLAUDE_CONFIG", p)
	os.MkdirAll(filepath.Dir(p), 0o700)
	os.WriteFile(p, []byte(`{"mcpServers":{"other":{"command":"x"}},"theme":"dark"}`), 0o600)
	s := &server{app: &App{st: mustStore(t)}, token: "t", addr: "127.0.0.1:1", subs: map[chan []byte]bool{}}
	if _, err := s.mcpInstall(); err != nil {
		t.Fatal(err)
	}
	var cfg map[string]any
	b, _ := os.ReadFile(p)
	json.Unmarshal(b, &cfg)
	ms := cfg["mcpServers"].(map[string]any)
	if cfg["theme"] != "dark" || ms["other"] == nil || ms[mcpName].(map[string]any)["args"].([]any)[0] != "-mcp" {
		t.Fatalf("merged %s", b)
	}
	baks, _ := filepath.Glob(p + ".bak-*")
	if len(baks) != 1 {
		t.Fatalf("backups %v", baks)
	}
	os.WriteFile(p, []byte(`{broken`), 0o600)
	if _, err := s.mcpInstall(); err == nil {
		t.Fatal("a broken config was overwritten")
	}
	if b, _ := os.ReadFile(p); string(b) != `{broken` {
		t.Fatal("the broken file was touched")
	}
}
