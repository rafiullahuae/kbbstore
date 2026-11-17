package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// a studio (app) folder and a separate data folder, as the start script runs it
func dataServer(t *testing.T) *server {
	t.Helper()
	s := testServer(t)
	d, _ := filepath.EvalSymlinks(t.TempDir())
	s.data = d
	s.inst = &installer{s: s, State: "idle"}
	return s
}

func put(t *testing.T, p, body string) {
	t.Helper()
	_ = os.MkdirAll(filepath.Dir(p), 0o755)
	if err := os.WriteFile(p, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}

func readAll(t *testing.T, p string) string {
	b, err := os.ReadFile(p)
	if err != nil {
		t.Fatalf("%s: %v", p, err)
	}
	return string(b)
}

// every kit in the repository's brands/ is named as built-in, so a migration never moves a shipped kit into the data
// folder (where it would hide the next update's copy)
func TestBuiltinBrandsListed(t *testing.T) {
	ents, err := os.ReadDir(filepath.Join("..", "brands"))
	if err != nil {
		t.Skip("no brands folder")
	}
	for _, e := range ents {
		if !strings.HasPrefix(e.Name(), ".") && !builtinBrands[e.Name()] {
			t.Errorf("brands/%s ships with the app but is not in builtinBrands (data.go)", e.Name())
		}
	}
}

// library/ and brands/: reads look in the data folder then the app folder, listings merge both, writes go to data;
// workspaces and models (not the manifest) live in the data folder only
func TestOverlay(t *testing.T) {
	s := dataServer(t)
	put(t, filepath.Join(s.root, "library", "music", "built.m4a"), "b")
	put(t, filepath.Join(s.data, "library", "music", "user", "mine.m4a"), "m")
	put(t, filepath.Join(s.root, "brands", "kbb", "kit.json"), `{"app":1}`)
	put(t, filepath.Join(s.data, "brands", "acme", "kit.json"), `{"own":1}`)
	put(t, filepath.Join(s.root, "models", "manifest.json"), `{"models":[]}`)

	w := call(s, "GET", "/api/list?path=library/music", "")
	if !strings.Contains(w.Body.String(), "built.m4a") || !strings.Contains(w.Body.String(), `"user"`) {
		t.Fatalf("library listing does not merge: %s", w.Body)
	}
	w = call(s, "GET", "/api/list?path=brands", "")
	if !strings.Contains(w.Body.String(), `"kbb"`) || !strings.Contains(w.Body.String(), `"acme"`) {
		t.Fatalf("brands listing does not merge: %s", w.Body)
	}
	for p, want := range map[string]string{"/library/music/built.m4a": "b", "/library/music/user/mine.m4a": "m", "/brands/acme/kit.json": `{"own":1}`, "/models/manifest.json": `{"models":[]}`} {
		if w := call(s, "GET", p, ""); w.Code != 200 || w.Body.String() != want {
			t.Errorf("GET %s: %d %q", p, w.Code, w.Body)
		}
	}
	if w := call(s, "PUT", "/api/write?path=library/people/nurse/a.jpg", "jpg"); w.Code != 200 {
		t.Fatalf("write: %d %s", w.Code, w.Body)
	}
	if readAll(t, filepath.Join(s.data, "library", "people", "nurse", "a.jpg")) != "jpg" {
		t.Fatal("a library write did not land in the data folder")
	}
	if _, err := os.Stat(filepath.Join(s.root, "library", "people")); err == nil {
		t.Fatal("a library write touched the app folder")
	}
	if w := call(s, "PUT", "/api/write?path=workspaces/acme/workspace.json", "{}"); w.Code != 200 {
		t.Fatalf("write ws: %d", w.Code)
	}
	if _, err := os.Stat(filepath.Join(s.data, "workspaces", "acme", "workspace.json")); err != nil {
		t.Fatal("workspaces are not in the data folder")
	}
	if w := call(s, "PUT", "/api/write?path=models/manifest.json", "{}"); w.Code != 403 {
		t.Fatalf("the model list must not be writable: %d", w.Code)
	}
	if w := call(s, "POST", "/api/delete?path=library/music/built.m4a", ""); w.Code != 403 {
		t.Fatalf("a built-in file must not be removable: %d", w.Code)
	}
	if w := call(s, "POST", "/api/delete?path=library/people/nurse", ""); w.Code != 200 {
		t.Fatalf("delete own: %d %s", w.Code, w.Body)
	}
	// a symlink out of the data folder is refused there too
	_ = os.Symlink("/etc", filepath.Join(s.data, "library", "out"))
	if w := call(s, "GET", "/library/out/hostname", ""); w.Code != 403 {
		t.Fatalf("symlink escape: %d", w.Code)
	}
	// settings stay unreachable through the file API
	put(t, filepath.Join(s.data, "settings", "claude.json"), "{}")
	if w := call(s, "GET", "/settings/claude.json", ""); w.Code == 200 {
		t.Fatal("settings served")
	}
}

func manifestFor(t *testing.T, s *server, files map[string]string) {
	t.Helper()
	m := manifest{Revision: "main"}
	md := mModel{ID: "m1", Repo: "o/r", Required: true}
	for p, body := range files {
		h := sha256.Sum256([]byte(body))
		md.Files = append(md.Files, mFile{Path: p, Size: int64(len(body)), Sha256: hex.EncodeToString(h[:])})
		md.Size += int64(len(body))
	}
	m.Models = []mModel{md}
	b, _ := json.Marshal(m)
	put(t, filepath.Join(s.root, "models", "manifest.json"), string(b))
}

// first run after the update: the owner's things in the app folder move to the data folder; built-in brands and
// library stay; nothing already in the data folder is overwritten; a wrong-size model file is not moved
func TestMigrateFromAppFolder(t *testing.T) {
	s := dataServer(t)
	manifestFor(t, s, map[string]string{"a.onnx": "AAAA", "b.onnx": "BBBBBB"})
	put(t, filepath.Join(s.root, "models", "o", "r", "a.onnx"), "AAAA")
	put(t, filepath.Join(s.root, "models", "o", "r", "b.onnx"), "BB") // half a file
	put(t, filepath.Join(s.root, "workspaces", "acme", "workspace.json"), `{"id":"acme"}`)
	put(t, filepath.Join(s.root, "workspaces", "kbb", "workspace.json"), `{"old":1}`)
	put(t, filepath.Join(s.data, "workspaces", "kbb", "workspace.json"), `{"new":1}`)
	put(t, filepath.Join(s.root, "settings", "claude.json"), `{"key":"x"}`)
	put(t, filepath.Join(s.root, "library", "music", "user", "song.m4a"), "song")
	put(t, filepath.Join(s.root, "library", "music", "built.m4a"), "b")
	put(t, filepath.Join(s.root, "brands", "kbb", "kit.json"), "{}")
	put(t, filepath.Join(s.root, "brands", "acme", "kit.json"), `{"acme":1}`)

	s.setupData("")

	if readAll(t, filepath.Join(s.data, "models", "o", "r", "a.onnx")) != "AAAA" {
		t.Fatal("model not moved")
	}
	if _, err := os.Stat(filepath.Join(s.data, "models", "o", "r", "b.onnx")); err == nil {
		t.Fatal("a half file was moved as if installed")
	}
	if st := s.modelStatus(); st["models"].([]map[string]any)[0]["installed"] != false {
		t.Fatal("a model with a missing file counts as installed")
	}
	if readAll(t, filepath.Join(s.data, "workspaces", "acme", "workspace.json")) != `{"id":"acme"}` {
		t.Fatal("workspace not moved")
	}
	if readAll(t, filepath.Join(s.data, "workspaces", "kbb", "workspace.json")) != `{"new":1}` {
		t.Fatal("newer data was overwritten")
	}
	if readAll(t, filepath.Join(s.data, "library", "music", "user", "song.m4a")) != "song" {
		t.Fatal("user music not moved")
	}
	if readAll(t, filepath.Join(s.data, "brands", "acme", "kit.json")) != `{"acme":1}` {
		t.Fatal("own brand not moved")
	}
	for _, p := range []string{"brands/kbb", "library/music/built.m4a", "models/manifest.json"} {
		if _, err := os.Stat(filepath.Join(s.root, p)); err != nil {
			t.Errorf("built-in %s moved away", p)
		}
		if _, err := os.Stat(filepath.Join(s.data, p)); err == nil {
			t.Errorf("built-in %s copied into the data folder", p)
		}
	}
	st, err := os.Stat(filepath.Join(s.data, "settings", "claude.json"))
	if err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("key file %v %v", err, st)
	}
	if d, _ := os.Stat(filepath.Join(s.data, "settings")); d.Mode().Perm() != 0o700 {
		t.Fatalf("settings folder %v", d.Mode().Perm())
	}
	if !strings.Contains(readAll(t, filepath.Join(s.data, "migration.log")), "moved workspaces/acme") {
		t.Fatal("migration.log does not say what moved")
	}
}

// an older KBB Studio folder in ~/Downloads: its models come over; a folder without the engine is not one
func TestImportFromOldStudio(t *testing.T) {
	s := dataServer(t)
	manifestFor(t, s, map[string]string{"a.onnx": "AAAA"})
	home := t.TempDir()
	old := filepath.Join(home, "Downloads", "KBB Studio")
	put(t, filepath.Join(old, "engine", "kbbstudio-mac-arm64"), "x")
	put(t, filepath.Join(old, "models", "manifest.json"), "{}")
	put(t, filepath.Join(old, "models", "o", "r", "a.onnx"), "AAAA")
	fake := filepath.Join(home, "Desktop", "Not studio")
	put(t, filepath.Join(fake, "models", "manifest.json"), "{}")
	put(t, filepath.Join(fake, "models", "o", "r", "a.onnx"), "AAAA")

	if got := s.oldStudios(home); len(got) != 1 || got[0] != old {
		t.Fatalf("old studios: %v", got)
	}
	s.setupData(home)
	if readAll(t, filepath.Join(s.data, "models", "o", "r", "a.onnx")) != "AAAA" || s.modelsMissing(false) {
		t.Fatal("models not imported from the old folder")
	}
}

// a copy across volumes is verified by sha256 before it counts
func TestImportModelFileVerifies(t *testing.T) {
	dir := t.TempDir()
	src := filepath.Join(dir, "src.onnx")
	put(t, src, "AAAA")
	h := sha256.Sum256([]byte("AAAB"))
	part := filepath.Join(dir, "dest.onnx.part")
	if err := copyFile(src, part); err != nil {
		t.Fatal(err)
	}
	if err := verifyFile(part, &iFile{Size: 4, sha256: hex.EncodeToString(h[:])}); err == nil {
		t.Fatal("a wrong sha256 passed")
	}
}

// ?merge=1 changes only the keys sent, one level deep; null removes
func TestPrefsMerge(t *testing.T) {
	s := dataServer(t)
	call(s, "POST", "/api/prefs", `{"captionLang":"en","ui":{"a":"1"}}`)
	if w := call(s, "POST", "/api/prefs?merge=1", `{"ui":{"b":"2","a":null}}`); w.Code != 200 {
		t.Fatalf("merge: %d %s", w.Code, w.Body)
	}
	var m map[string]any
	_ = json.Unmarshal([]byte(call(s, "GET", "/api/prefs", "").Body.String()), &m)
	ui, _ := m["ui"].(map[string]any)
	if m["captionLang"] != "en" || ui["b"] != "2" || ui["a"] != nil {
		t.Fatalf("merged prefs: %v", m)
	}
	if _, err := os.Stat(filepath.Join(s.data, "settings", "prefs.json")); err != nil {
		t.Fatal("prefs not in the data folder")
	}
}
