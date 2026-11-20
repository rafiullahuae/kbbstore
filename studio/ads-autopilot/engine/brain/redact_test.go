package brain

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"kaa/model"
)

func writeBroken(p, s string) error { return os.WriteFile(p, []byte(s), 0o600) }

func hasUnreadable(p string) bool {
	m, _ := filepath.Glob(p + ".unreadable-*")
	return len(m) == 1
}

// The generator keys join the audit-log redaction list like the engine's own (QA, 20 Nov 2026): a provider error
// that echoes a key never reaches log.jsonl in clear. MUTATION: pass nil to secrets.New in genSecrets → red.
func TestGeneratorKeysAreRedacted(t *testing.T) {
	t.Setenv("KAA_SECRETS_FILE", "1")
	genS.mu.Lock()
	genS.sec, genS.jobs = nil, map[string]*genJob{}
	genS.mu.Unlock()
	var got []string
	e := New(model.BrainDeps{LLM: &fakeLLM{}, DataDir: t.TempDir(), Redact: func(v string) { got = append(got, v) }}).(*Engine)
	if c, _ := do(t, e, "PUT", "gen/keys", map[string]any{"provider": "veo", "key": "AIza-redact-me-1234"}); c != 200 {
		t.Fatalf("save key: %d", c)
	}
	if !strings.Contains(strings.Join(got, "|"), "AIza-redact-me-1234") {
		t.Fatalf("the key was not handed to the redaction list: %v", got)
	}
}

// An unreadable brain file is kept aside and said, never read as "never set" and overwritten silently (QA).
// MUTATION: return false without the rename in load → the .unreadable copy is missing, red.
func TestUnreadableBrainFileIsKept(t *testing.T) {
	var logs []string
	dir := t.TempDir()
	e := New(model.BrainDeps{LLM: &fakeLLM{}, DataDir: dir, Log: func(k, s string, d any) { logs = append(logs, k+": "+s) }}).(*Engine)
	e.save("act_1", "settings", AccountSettings{AdWording: Strict})
	p := e.path("act_1", "settings")
	if err := writeBroken(p, "{not json"); err != nil {
		t.Fatal(err)
	}
	var s AccountSettings
	if e.load("act_1", "settings", &s) {
		t.Fatal("a broken file loaded")
	}
	if !strings.Contains(strings.Join(logs, "|"), "kept aside as settings.json.unreadable-") || !hasUnreadable(p) {
		t.Fatalf("no copy kept / no alert: %v", logs)
	}
}
