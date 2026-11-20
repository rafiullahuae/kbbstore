package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"kaa/store"
)

// A saved file the engine cannot read (a newer/older version wrote another shape, a damaged disk) was read as "never
// set": the engine started on defaults, said nothing, and its next Save wrote the defaults over the owner's settings.
// Now the unreadable file is kept as <name>.json.unreadable and Activity says so. MUTATION: `_ = st.Load("config", …)`
// back in newApp → no alert; drop the copy in store.Load → the owner's original bytes are gone after the Save.
func TestUnreadableSettingsAreKeptAndSaid(t *testing.T) {
	t.Setenv("KAA_SECRETS_FILE", "1")
	dir := t.TempDir()
	orig := []byte(`{"meta_app_id": "123", "selected": "act_1`) // cut short: this version cannot read it
	if err := os.WriteFile(filepath.Join(dir, "config.json"), orig, 0o600); err != nil {
		t.Fatal(err)
	}
	st, err := store.Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = st.Close() })
	a := newApp(st, time.Now, nil)
	said := false
	for _, e := range st.Recent(20, "alert") {
		said = said || strings.Contains(e.Summary, "config.json could not be read")
	}
	if !said {
		t.Fatal("an unreadable config.json was not said in Activity")
	}
	_ = a.st.Save("config", a.cfg) // the engine saves its config again later
	if b, _ := os.ReadFile(filepath.Join(dir, "config.json.unreadable")); string(b) != string(orig) {
		t.Fatalf("the owner's original settings are gone: %q", b)
	}
}
