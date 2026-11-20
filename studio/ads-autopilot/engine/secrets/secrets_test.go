package secrets

import (
	"os"
	"path/filepath"
	"testing"
)

func TestFileStore(t *testing.T) {
	dir := t.TempDir()
	var seen []string
	s := &fileStore{path: filepath.Join(dir, "secrets.json"), on: func(v string) { seen = append(seen, v) }}
	if err := s.Set("meta_token", "EAAabc123456789"); err != nil {
		t.Fatal(err)
	}
	if err := s.Set("password", "x123456"); err == nil {
		t.Fatal("unknown name accepted")
	}
	if err := s.Set("anthropic_key", `sk-ant-"; rm -rf /`); err == nil {
		t.Fatal("a value with quotes/spaces accepted (it would be unsafe on the security -i command line)")
	}
	st, _ := os.Stat(filepath.Join(dir, "secrets.json"))
	if st.Mode().Perm() != 0o600 {
		t.Fatalf("mode %v", st.Mode().Perm())
	}
	if d := Describe(s, "meta_token"); !d.Set || d.Last4 != "6789" {
		t.Fatalf("%+v", d)
	}
	if d := Describe(s, "shop_token"); d.Set {
		t.Fatal("unset shown as set")
	}
	if len(seen) != 1 {
		t.Fatal("the audit log was not told the new secret (redaction)")
	}
	s2 := &fileStore{path: filepath.Join(dir, "secrets.json")}
	if v, _ := s2.Get("meta_token"); v != "EAAabc123456789" {
		t.Fatal("not persisted")
	}
	_ = s2.Delete("meta_token")
	if v, _ := s2.Get("meta_token"); v != "" {
		t.Fatal("not deleted")
	}
}
