package main

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io/fs"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

type zent struct {
	name, body string
	mode       os.FileMode
}

func writeZip(t *testing.T, ents []zent) string {
	t.Helper()
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	for _, e := range ents {
		hd := &zip.FileHeader{Name: e.name, Method: zip.Deflate}
		m := e.mode
		if m == 0 {
			m = 0o644
		}
		hd.SetMode(m)
		w, err := zw.CreateHeader(hd)
		if err != nil {
			t.Fatal(err)
		}
		_, _ = w.Write([]byte(e.body))
	}
	if err := zw.Close(); err != nil {
		t.Fatal(err)
	}
	p := filepath.Join(t.TempDir(), "pkg.zip")
	if err := os.WriteFile(p, buf.Bytes(), 0o644); err != nil {
		t.Fatal(err)
	}
	return p
}

func shaHex(s string) string { h := sha256.Sum256([]byte(s)); return hex.EncodeToString(h[:]) }

func configJS(v string) string {
	return "export const APP_NAME = 'KBB Studio';\nexport const APP_VERSION = '" + v + "'; // x\n"
}

// pkgFiles: a minimal real package's files for version v
func pkgFiles(v string) map[string]string {
	return map[string]string{
		"app/index.html":                     "<html><head></head>v" + v + "</html>",
		"app/core/config.js":                 configJS(v),
		"vendor/lib.js":                      "lib " + v,
		"brands/kbb/kit.json":                `{"v":"` + v + `"}`,
		"library/music/a.txt":                "music " + v,
		"engine/kbbstudio-mac-arm64":         "arm engine " + v,
		"engine/kbbstudio-mac-amd64":         "intel engine " + v,
		"models/manifest.json":               `{"models":[]}`,
		"README.md":                          "readme " + v,
		"Start KBB Studio.command":           "#!/bin/bash\necho " + v,
		"GUIDE - keys and what to send.html": "<p>guide</p>",
	}
}

func updManifestFor(v string, files map[string]string) updManifestT {
	m := updManifestT{Format: 1, App: appName, Version: v, Date: "2026-11-19", Notes: []string{"Updates from Configure", "Move a reel"}, Files: map[string]updFile{}}
	for k, b := range files {
		m.Files[k] = updFile{Sha256: shaHex(b), Size: int64(len(b))}
	}
	return m
}

// buildPkg: the zip as package-mac.sh makes it ("KBB Studio/..."), with tweak applied to the manifest and the entries
func buildPkg(t *testing.T, v string, tweak func(files map[string]string, man *updManifestT, extra *[]zent)) string {
	t.Helper()
	files := pkgFiles(v)
	man := updManifestFor(v, files)
	var extra []zent
	if tweak != nil {
		tweak(files, &man, &extra)
	}
	var ents []zent
	keys := make([]string, 0, len(files))
	for k := range files {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	ents = append(ents, zent{name: "KBB Studio/"})
	for _, k := range keys {
		mode := os.FileMode(0o644)
		if updExecutable(k) {
			mode = 0o755
		}
		ents = append(ents, zent{name: "KBB Studio/" + k, body: files[k], mode: mode})
	}
	mb, _ := json.Marshal(man)
	ents = append(ents, zent{name: "KBB Studio/update.json", body: string(mb)})
	ents = append(ents, extra...)
	return writeZip(t, ents)
}

// an installed app folder at version v, with a separate data folder holding the owner's things
func updServer(t *testing.T, v string) *server {
	t.Helper()
	s := dataServer(t)
	for k, b := range pkgFiles(v) {
		put(t, filepath.Join(s.root, filepath.FromSlash(k)), b)
	}
	_ = os.Chmod(filepath.Join(s.root, "engine", "kbbstudio-mac-arm64"), 0o755)
	put(t, filepath.Join(s.root, "engine", "kbbstudio-linux-amd64"), "dev engine, not in packages")
	put(t, filepath.Join(s.data, "workspaces", "kbb", "workspace.json"), `{"id":"kbb"}`)
	put(t, filepath.Join(s.data, "settings", "claude.json"), `{"key":"secret"}`)
	exe := filepath.Join(t.TempDir(), "fake-engine")
	if err := os.WriteFile(exe, []byte("#!/bin/sh\necho '  -dir string'\necho '  -after-update'\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	s.exe, s.noRestart = exe, true
	return s
}

// treeSum: every file (path, mode, sha256) under dir, .update left out
func treeSum(t *testing.T, dir string) string {
	t.Helper()
	var out []string
	_ = filepath.WalkDir(dir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		rel, _ := filepath.Rel(dir, p)
		if rel == updDirName || strings.HasPrefix(rel, updDirName+string(os.PathSeparator)) {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		if d.Type().IsRegular() {
			b, _ := os.ReadFile(p)
			st, _ := d.Info()
			out = append(out, rel+" "+st.Mode().String()+" "+shaHex(string(b)))
		} else if d.IsDir() {
			out = append(out, rel+"/")
		}
		return nil
	})
	return strings.Join(out, "\n")
}

func upload(t *testing.T, s *server, zipPath string) *httptest.ResponseRecorder {
	t.Helper()
	b, _ := os.ReadFile(zipPath)
	return call(s, "POST", "/api/update/upload", string(b))
}

func TestCheckPackageAccepts(t *testing.T) {
	st := filepath.Join(t.TempDir(), "staging")
	man, units, total, err := checkPackage(buildPkg(t, "1.3.0", nil), st, "1.2.1", false)
	if err != nil {
		t.Fatal(err)
	}
	if man.Version != "1.3.0" || total == 0 || len(man.Notes) != 2 {
		t.Fatalf("manifest %+v total %d", man, total)
	}
	want := "GUIDE - keys and what to send.html,README.md,Start KBB Studio.command,app,brands,engine/kbbstudio-mac-amd64,engine/kbbstudio-mac-arm64,library,models/manifest.json,update.json,vendor"
	if strings.Join(units, ",") != want {
		t.Fatalf("units %v", units)
	}
	for _, x := range []string{"engine/kbbstudio-mac-arm64", "engine/kbbstudio-mac-amd64", "Start KBB Studio.command"} {
		if st, _ := os.Stat(filepath.Join(st, x)); st == nil || st.Mode().Perm() != 0o755 {
			t.Errorf("%s should keep its executable bit", x)
		}
	}
	if st, _ := os.Stat(filepath.Join(st, "app/index.html")); st.Mode().Perm() != 0o644 {
		t.Error("app files are not executable")
	}
	// re-zipped on a Mac: __MACOSX and .DS_Store are skipped, not refused
	p := buildPkg(t, "1.3.0", func(_ map[string]string, _ *updManifestT, x *[]zent) {
		*x = append(*x, zent{name: "__MACOSX/KBB Studio/._README.md", body: "x"}, zent{name: "KBB Studio/app/.DS_Store", body: "x"})
	})
	if _, _, _, err := checkPackage(p, filepath.Join(t.TempDir(), "s2"), "1.2.1", false); err != nil {
		t.Fatalf("Mac junk should be skipped: %v", err)
	}
}

func TestCheckPackageRefuses(t *testing.T) {
	cases := map[string]struct {
		tweak func(f map[string]string, m *updManifestT, x *[]zent)
		cur   string
		want  string
	}{
		"tampered file": {func(f map[string]string, _ *updManifestT, _ *[]zent) { f["app/index.html"] = "<html>evil</html>" }, "1.2.1", "changed or damaged"},
		"same size tamper": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["vendor/lib.js"] = strings.Repeat("x", len(f["vendor/lib.js"]))
		}, "1.2.1", "fingerprint"},
		"zip-slip": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: "KBB Studio/../../evil.sh", body: "x"})
		}, "1.2.1", "outside"},
		"absolute path": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: "/etc/evil", body: "x"})
		}, "1.2.1", "not allowed"},
		"backslash": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: "KBB Studio\\..\\evil", body: "x"})
		}, "1.2.1", "not allowed"},
		"symlink": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: "KBB Studio/app/link", body: "/etc/passwd", mode: os.ModeSymlink | 0o777})
		}, "1.2.1", "link"},
		"unlisted file": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: "KBB Studio/app/extra.js", body: "x"})
		}, "1.2.1", "does not list"},
		"listed missing": {func(_ map[string]string, m *updManifestT, _ *[]zent) {
			m.Files["app/gone.js"] = updFile{Sha256: shaHex("x"), Size: 1}
		}, "1.2.1", "missing"},
		"data folder": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["workspaces/kbb/workspace.json"] = "{}"
			*m = updManifestFor(m.Version, f)
		}, "1.2.1", "may not touch"},
		"settings": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["settings/claude.json"] = "{}"
			*m = updManifestFor(m.Version, f)
		}, "1.2.1", "may not touch"},
		"hidden": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["app/.htaccess"] = "x"
			*m = updManifestFor(m.Version, f)
		}, "1.2.1", "may not touch"},
		"other engine": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["engine/run.sh"] = "x"
			*m = updManifestFor(m.Version, f)
		}, "1.2.1", "may not touch"},
		"model weights": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["models/x/model.onnx"] = "x"
			*m = updManifestFor(m.Version, f)
		}, "1.2.1", "may not touch"},
		"missing engine": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			delete(f, "engine/kbbstudio-mac-amd64")
			*m = updManifestFor(m.Version, f)
		}, "1.2.1", "Intel"},
		"missing arm": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			delete(f, "engine/kbbstudio-mac-arm64")
			*m = updManifestFor(m.Version, f)
		}, "1.2.1", "Apple-silicon"},
		"older":       {nil, "1.4.0", "older"},
		"same":        {nil, "1.3.0", "already installed"},
		"min version": {func(_ map[string]string, m *updManifestT, _ *[]zent) { m.MinVersion = "1.2.5" }, "1.2.1", "needs version 1.2.5"},
		"other app":   {func(_ map[string]string, m *updManifestT, _ *[]zent) { m.App = "Other" }, "1.2.1", "not for"},
		"bad version": {func(_ map[string]string, m *updManifestT, _ *[]zent) { m.Version = "1.3.0; rm -rf" }, "1.2.1", "which version"},
		"format 2":    {func(_ map[string]string, m *updManifestT, _ *[]zent) { m.Format = 2 }, "1.2.1", "newer kind"},
		"case twins": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: "KBB Studio/APP/index.html", body: "x"})
		}, "1.2.1", "twice"},
		"two folders": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: "Other/app/x.js", body: "x"})
		}, "1.2.1", "update information"},
		"bad sha": {func(_ map[string]string, m *updManifestT, _ *[]zent) {
			m.Files["README.md"] = updFile{Sha256: "zz", Size: 1}
		}, "1.2.1", "damaged"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			st := filepath.Join(t.TempDir(), "staging")
			_, _, _, err := checkPackage(buildPkg(t, "1.3.0", c.tweak), st, c.cur, false)
			if err == nil || !strings.Contains(err.Error(), c.want) {
				t.Fatalf("want a refusal mentioning %q, got %v", c.want, err)
			}
			if _, e := os.Stat(st); e == nil {
				t.Error("staging left behind after a refusal")
			}
		})
	}
	// no update.json at all (a 1.2.1 zip from before the updater)
	z := writeZip(t, []zent{{name: "KBB Studio/app/index.html", body: "x"}})
	if _, _, _, err := checkPackage(z, filepath.Join(t.TempDir(), "s"), "1.2.1", false); err == nil || !strings.Contains(err.Error(), "no update information") {
		t.Fatalf("got %v", err)
	}
	p := filepath.Join(t.TempDir(), "x.zip")
	_ = os.WriteFile(p, []byte("not a zip"), 0o644)
	if _, _, _, err := checkPackage(p, filepath.Join(t.TempDir(), "s"), "1.2.1", false); err == nil || !strings.Contains(err.Error(), "not a zip") {
		t.Fatalf("got %v", err)
	}
}

func TestUpdateInstallAndRollBack(t *testing.T) {
	s := updServer(t, "1.2.1")
	before, data := treeSum(t, s.root), treeSum(t, s.data)

	// a refused package leaves the folder byte-identical, .update included
	r := upload(t, s, buildPkg(t, "1.3.0", func(f map[string]string, _ *updManifestT, _ *[]zent) { f["README.md"] = "evil" }))
	if r.Code != 422 || !strings.Contains(r.Body.String(), "changed or damaged") {
		t.Fatalf("tampered: %d %s", r.Code, r.Body)
	}
	if treeSum(t, s.root) != before {
		t.Fatal("a refused package changed the app folder")
	}
	if _, err := os.Stat(s.updDir()); err == nil {
		t.Fatal(".update left behind after a refusal")
	}

	r = upload(t, s, buildPkg(t, "1.3.0", nil))
	if r.Code != 200 {
		t.Fatalf("upload: %d %s", r.Code, r.Body)
	}
	var pend updPending
	_ = json.Unmarshal(r.Body.Bytes(), &pend)
	if pend.From != "1.2.1" || pend.To != "1.3.0" || len(pend.Notes) != 2 || pend.Size == 0 {
		t.Fatalf("review %+v", pend)
	}
	if treeSum(t, s.root) != before {
		t.Fatal("checking a package changed the app folder")
	}
	if r := call(s, "POST", "/api/update/install", `{"id":"wrong"}`); r.Code != 409 {
		t.Fatalf("install with a stale id: %d", r.Code)
	}
	r = call(s, "POST", "/api/update/install", `{"id":"`+pend.ID+`"}`)
	if r.Code != 200 || !strings.Contains(r.Body.String(), `"to":"1.3.0"`) {
		t.Fatalf("install: %d %s", r.Code, r.Body)
	}
	if v := appVersionAt(s.root); v != "1.3.0" {
		t.Fatalf("version after install: %s", v)
	}
	if readAll(t, filepath.Join(s.root, "engine", "kbbstudio-linux-amd64")) != "dev engine, not in packages" {
		t.Error("a file the package does not own was touched")
	}
	if st, _ := os.Stat(filepath.Join(s.root, "engine", "kbbstudio-mac-arm64")); st.Mode().Perm() != 0o755 {
		t.Error("the engine lost its executable bit")
	}
	if treeSum(t, s.data) != data {
		t.Fatal("the data folder changed")
	}
	st := s.updateStatus()
	if st["previous"] == nil || st["previous"].(map[string]any)["version"] != "1.2.1" {
		t.Fatalf("previous: %v", st["previous"])
	}
	if h := s.readHistory(); len(h) != 1 || h[0].Kind != "update" || h[0].From != "1.2.1" || h[0].To != "1.3.0" {
		t.Fatalf("history %+v", h)
	}
	// the same package again: refused (already installed)
	if r := upload(t, s, buildPkg(t, "1.3.0", nil)); r.Code != 422 || !strings.Contains(r.Body.String(), "already installed") {
		t.Fatalf("reinstall: %d %s", r.Code, r.Body)
	}

	// roll back: the folder is exactly as before, data untouched; and roll forward again
	if r := call(s, "POST", "/api/update/rollback", `{}`); r.Code != 200 || !strings.Contains(r.Body.String(), `"to":"1.2.1"`) {
		t.Fatalf("rollback: %d %s", r.Code, r.Body)
	}
	after := strings.Split(treeSum(t, s.root), "\n")
	var noManifest []string
	for _, l := range after {
		if !strings.HasPrefix(l, "update.json ") {
			noManifest = append(noManifest, l)
		}
	}
	if strings.Join(noManifest, "\n") != before {
		t.Fatalf("roll back did not restore the folder:\n%s\n---\n%s", strings.Join(noManifest, "\n"), before)
	}
	if treeSum(t, s.data) != data {
		t.Fatal("the data folder changed")
	}
	if p := s.readPrevious(); p == nil || p.Version != "1.3.0" {
		t.Fatalf("after a roll back the newer version is kept: %+v", p)
	}
	if r := call(s, "POST", "/api/update/rollback", `{}`); r.Code != 200 || appVersionAt(s.root) != "1.3.0" {
		t.Fatalf("roll forward: %d %s", r.Code, r.Body)
	}
	if h := s.readHistory(); len(h) != 3 || h[0].Kind != "rollback" {
		t.Fatalf("history %+v", h)
	}
}

// the swap fails halfway: every unit already moved is put back, the folder is byte-identical
func TestUpdateSwapFailsMidway(t *testing.T) {
	s := updServer(t, "1.2.1")
	before := treeSum(t, s.root)
	r := upload(t, s, buildPkg(t, "1.3.0", nil))
	var pend updPending
	_ = json.Unmarshal(r.Body.Bytes(), &pend)
	updFailAt = 4
	defer func() { updFailAt = -1 }()
	r = call(s, "POST", "/api/update/install", `{"id":"`+pend.ID+`"}`)
	if r.Code != 500 || !strings.Contains(r.Body.String(), "nothing was changed") {
		t.Fatalf("%d %s", r.Code, r.Body)
	}
	if treeSum(t, s.root) != before {
		t.Fatal("a failed swap left the folder changed")
	}
	if s.readPrevious() != nil {
		t.Fatal("a failed swap left a backup to roll back to")
	}
}

// the new engine does not run on this Mac: put back, nothing changed
func TestUpdateNewEngineFails(t *testing.T) {
	s := updServer(t, "1.2.1")
	before := treeSum(t, s.root)
	r := upload(t, s, buildPkg(t, "1.3.0", nil))
	var pend updPending
	_ = json.Unmarshal(r.Body.Bytes(), &pend)
	s.exe = "/bin/false"
	r = call(s, "POST", "/api/update/install", `{"id":"`+pend.ID+`"}`)
	if r.Code != 500 || !strings.Contains(r.Body.String(), "did not start") {
		t.Fatalf("%d %s", r.Code, r.Body)
	}
	if treeSum(t, s.root) != before {
		t.Fatal("the folder changed although the new engine did not run")
	}
}

// power lost midway through a swap: the next start puts the old version back
func TestUpdateRecoverInterrupted(t *testing.T) {
	s := updServer(t, "1.2.1")
	before := treeSum(t, s.root)
	d := s.updDir()
	st := filepath.Join(d, "staging-x")
	_, units, _, err := checkPackage(buildPkg(t, "1.3.0", nil), st, "1.2.1", false)
	if err != nil {
		t.Fatal(err)
	}
	if err := swapUnits(s.root, st, filepath.Join(d, "backup-x"), units[:5]); err != nil {
		t.Fatal(err)
	}
	if treeSum(t, s.root) == before {
		t.Fatal("test setup: nothing was swapped")
	}
	s.recoverInterrupted()
	if treeSum(t, s.root) != before {
		t.Fatal("an interrupted swap was not put back")
	}
	if ents, _ := os.ReadDir(d); len(ents) != 0 {
		t.Fatalf("leftovers in .update: %v", ents)
	}
}

// updating needs the data outside the app folder, and the API is behind the token like the rest
func TestUpdateGuards(t *testing.T) {
	s := updServer(t, "1.2.1")
	s.data = ""
	if r := upload(t, s, buildPkg(t, "1.3.0", nil)); r.Code != 409 {
		t.Fatalf("data inside the app folder: %d", r.Code)
	}
	s = updServer(t, "1.2.1")
	req := httptest.NewRequest("POST", "/api/update/upload", strings.NewReader("x"))
	req.Host = s.addr
	w := httptest.NewRecorder()
	s.ServeHTTP(w, req)
	if w.Code != 401 {
		t.Fatalf("no token: %d", w.Code)
	}
	if r := call(s, "POST", "/api/update/rollback", `{}`); r.Code != 409 {
		t.Fatalf("roll back with no backup: %d", r.Code)
	}
	if cmpVersion("1.10.0", "1.9.3") != 1 || cmpVersion("1.2", "1.2.0") != 0 || cmpVersion("1.2.1", "1.3") != -1 {
		t.Fatal("cmpVersion")
	}
}
