package store

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Nothing secret reaches the audit log. MUTATION: delete the reCredJSON rule → the "access_token" row leaks.
func TestRedaction(t *testing.T) {
	dir := t.TempDir()
	s, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	s.AddSecret("my-shop-token-123456")
	s.Append("meta", "POST act_1/campaigns access_token=EAAB1234567890abcdefghijklmnop", map[string]any{
		"access_token": "anything", "form": "x=1&appsecret_proof=deadbeef&y=2", "note": "key sk-ant-api03-abcdefghijklmnop",
		"nested": map[string]string{"api_key": "zzz", "shop": "my-shop-token-123456"}, "ok": "keep me",
	})
	_ = s.Close()
	b, _ := os.ReadFile(filepath.Join(dir, "audit.jsonl"))
	for _, leak := range []string{"EAAB1234567890", "anything", "deadbeef", "sk-ant-api03", `"zzz"`, "my-shop-token-123456"} {
		if strings.Contains(string(b), leak) {
			t.Errorf("leaked %q in %s", leak, b)
		}
	}
	if !strings.Contains(string(b), "keep me") {
		t.Error("redaction ate normal data")
	}
	if st, _ := os.Stat(filepath.Join(dir, "audit.jsonl")); st.Mode().Perm() != 0o600 {
		t.Errorf("audit log mode %v", st.Mode().Perm())
	}
}

func TestLogSurvivesRestartAndTornLine(t *testing.T) {
	dir := t.TempDir()
	s, _ := Open(dir)
	for i := 0; i < 5; i++ {
		s.Append("cycle", "x", nil)
	}
	_ = s.Close()
	f, _ := os.OpenFile(filepath.Join(dir, "audit.jsonl"), os.O_APPEND|os.O_WRONLY, 0)
	_, _ = f.WriteString(`{"seq":6,"at":"2026`) // power cut mid-line
	_ = f.Close()
	s2, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	e := s2.Append("cycle", "after", nil)
	if e.Seq != 6 {
		t.Fatalf("seq after restart %d, want 6", e.Seq)
	}
	if got := s2.Log(3, 100); len(got) != 3 || got[0].Seq != 4 {
		t.Fatalf("Log(after=3) = %d entries", len(got))
	}
	if got := s2.Log(0, 100, "nope"); len(got) != 0 {
		t.Fatal("kind filter")
	}
}

func TestStateNames(t *testing.T) {
	s, _ := Open(t.TempDir())
	if err := s.Save("../escape", 1); err == nil {
		t.Fatal("a state name escaped the data folder")
	}
	if err := s.Save("plan", map[string]int{"a": 1}); err != nil {
		t.Fatal(err)
	}
	var v map[string]int
	if err := s.Load("plan", &v); err != nil || v["a"] != 1 {
		t.Fatal("round trip")
	}
}
