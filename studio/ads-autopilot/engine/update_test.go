package main

// The updater (update.go, update_flow.go). Ported from studio/reel-studio/engine/update_test.go (the package checks)
// and extended for the owner's 20 Nov 2026 brief ("build me the patch upload system ... must be super reliable").
// Every test names the defect it catches and the MUTATION that turns it red.

import (
	"archive/zip"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
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

func pkgFiles(v string) map[string]string {
	return map[string]string{
		"app/index.html":                  `<html><script type="module" src="js/main.js"></script>v` + v + `</html>`,
		"app/js/main.js":                  "import { x } from './lib.js';\nmain " + v,
		"app/js/lib.js":                   "export const x = 1; // " + v,
		"app/css/app.css":                 "body{} /*" + v + "*/",
		"engine/kaa-mac-arm64":            "arm engine",
		"engine/kaa-mac-amd64":            "intel engine",
		"VERSION":                         v + "\n",
		"README.md":                       "readme",
		"Start KBB Ads Autopilot.command": "#!/bin/bash\necho start",
	}
}

func listOf(files map[string]string) map[string]updFile {
	m := map[string]updFile{}
	for k, b := range files {
		m[k] = updFile{Sha256: shaHex(b), Size: int64(len(b))}
	}
	return m
}

func updManifestFor(v string, files map[string]string) updManifestT {
	return updManifestT{Format: 1, App: appName, Version: v, Date: "2026-11-20", Notes: []string{"First", "Second"}, Files: listOf(files)}
}

func zipOf(t *testing.T, files map[string]string, man any, extra []zent) string {
	t.Helper()
	keys := make([]string, 0, len(files))
	for k := range files {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	ents := []zent{{name: appName + "/"}}
	for _, k := range keys {
		mode := os.FileMode(0o644)
		if updExecutable(k) {
			mode = 0o755
		}
		ents = append(ents, zent{name: appName + "/" + k, body: files[k], mode: mode})
	}
	mb, _ := json.Marshal(man)
	ents = append(ents, zent{name: appName + "/update.json", body: string(mb)})
	return writeZip(t, append(ents, extra...))
}

func buildPkg(t *testing.T, v string, tweak func(files map[string]string, man *updManifestT, extra *[]zent)) string {
	t.Helper()
	files := pkgFiles(v)
	man := updManifestFor(v, files)
	var extra []zent
	if tweak != nil {
		tweak(files, &man, &extra)
	}
	return zipOf(t, files, man, extra)
}

// buildPatch: from → to, where to = pkgFiles(to) with mod applied (body "" = the file is gone in "to")
func buildPatch(t *testing.T, from, to string, mod map[string]string, tweak func(carry map[string]string, man *updManifestT)) string {
	t.Helper()
	old, nw := pkgFiles(from), pkgFiles(to)
	for k, b := range mod {
		if b == "" {
			delete(nw, k)
		} else {
			nw[k] = b
		}
	}
	carry := map[string]string{}
	for k, b := range nw {
		if old[k] != b {
			carry[k] = b
		}
	}
	var removed []string
	for k := range old {
		if _, ok := nw[k]; !ok {
			removed = append(removed, k)
		}
	}
	sort.Strings(removed)
	man := updManifestT{Format: 2, Kind: "patch", App: appName, From: from, Version: to, Notes: []string{"patch"}, Files: listOf(carry), Removed: removed, Result: listOf(nw)}
	if tweak != nil {
		tweak(carry, &man)
	}
	return zipOf(t, carry, man, nil)
}

// installed: an app folder at version v, as tools/package-mac.sh lays it out (update.json included)
func installed(t *testing.T, v string) string {
	t.Helper()
	root := t.TempDir()
	files := pkgFiles(v)
	for k, b := range files {
		p := filepath.Join(root, filepath.FromSlash(k))
		_ = os.MkdirAll(filepath.Dir(p), 0o755)
		mode := os.FileMode(0o644)
		if updExecutable(k) {
			mode = 0o755
		}
		if err := os.WriteFile(p, []byte(b), mode); err != nil {
			t.Fatal(err)
		}
	}
	mb, _ := json.Marshal(updManifestFor(v, files))
	_ = os.WriteFile(filepath.Join(root, updManifest), mb, 0o644)
	return root
}

// treeSum: every file under dir (but .update), path + mode + sha256
func treeSum(t *testing.T, dir string) string {
	t.Helper()
	var out []string
	_ = filepath.WalkDir(dir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(dir, p)
		if rel == updDirName {
			return filepath.SkipDir
		}
		if d.IsDir() {
			out = append(out, rel+"/")
			return nil
		}
		b, _ := os.ReadFile(p)
		fi, _ := d.Info()
		out = append(out, fmt.Sprintf("%s %o %s", rel, fi.Mode().Perm(), shaHex(string(b))))
		return nil
	})
	return strings.Join(out, "\n")
}

// updServer: a server on an installed app folder whose "engine" answers -h like a Go engine does. The data folder
// is a separate temp folder (as main enforces).
func updServer(t *testing.T, root string) *server {
	t.Helper()
	exe := filepath.Join(t.TempDir(), "kaa")
	_ = os.WriteFile(exe, []byte("#!/bin/sh\necho '  -dir string'\necho '  -after-update'\n"), 0o755)
	return &server{root: root, data: t.TempDir(), exe: exe, noRestart: true, token: "t", subs: map[chan []byte]bool{}, healthGate: make(chan struct{})}
}

func TestCheckPackageAccepts(t *testing.T) {
	st := filepath.Join(t.TempDir(), "staging")
	c, err := checkPackage(buildPkg(t, "0.2.0", nil), st, "", "0.1.0", false)
	if err != nil {
		t.Fatal(err)
	}
	want := "README.md,Start KBB Ads Autopilot.command,VERSION,app,engine/kaa-mac-amd64,engine/kaa-mac-arm64,update.json"
	if c.man.Version != "0.2.0" || strings.Join(c.units, ",") != want {
		t.Fatalf("units %v", c.units)
	}
	for _, x := range []string{"engine/kaa-mac-arm64", "engine/kaa-mac-amd64", "Start KBB Ads Autopilot.command"} {
		if s, _ := os.Stat(filepath.Join(st, x)); s == nil || s.Mode().Perm() != 0o755 {
			t.Errorf("%s should keep its executable bit", x)
		}
	}
}

// MUTATION: let updUnit return segs[0] for any folder → "data folder" / "other engine" rows are accepted;
// drop the n != want.Size / sha check in unpackOne → "tampered" and "wrong hash in update.json" are accepted;
// drop updCleanName's ".." check → "zip-slip" is accepted.
func TestCheckPackageRefuses(t *testing.T) {
	cases := map[string]struct {
		tweak func(f map[string]string, m *updManifestT, x *[]zent)
		cur   string
		want  string
	}{
		"tampered file": {func(f map[string]string, _ *updManifestT, _ *[]zent) { f["app/index.html"] = "<html>evil</html>" }, "0.1.0", "changed or damaged"},
		"wrong hash in update.json": {func(_ map[string]string, m *updManifestT, _ *[]zent) {
			f := m.Files["app/js/lib.js"]
			f.Sha256 = strings.Repeat("0", 64)
			m.Files["app/js/lib.js"] = f
		}, "0.1.0", "fingerprint"},
		"zip-slip": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: appName + "/../../evil.sh", body: "x"})
		}, "0.1.0", "outside"},
		"absolute path": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: "/etc/evil", body: "x"})
		}, "0.1.0", "not allowed"},
		"hidden file": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["app/.hidden.js"] = "x"
			*m = updManifestFor(m.Version, f)
		}, "0.1.0", "may not touch"},
		"symlink": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: appName + "/app/link", body: "/etc/passwd", mode: os.ModeSymlink | 0o777})
		}, "0.1.0", "link"},
		"unlisted file": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: appName + "/app/extra.js", body: "x"})
		}, "0.1.0", "does not list"},
		"listed, missing": {func(f map[string]string, _ *updManifestT, _ *[]zent) { delete(f, "app/js/lib.js") }, "0.1.0", "incomplete"},
		"data folder": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["secrets.json"] = "{}"
			f["data/audit.jsonl"] = "{}"
			*m = updManifestFor(m.Version, f)
		}, "0.1.0", "may not touch"},
		"other engine": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["engine/run.sh"] = "x"
			*m = updManifestFor(m.Version, f)
		}, "0.1.0", "may not touch"},
		"engine source": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			f["engine/main.go"] = "x"
			*m = updManifestFor(m.Version, f)
		}, "0.1.0", "may not touch"},
		"missing engine": {func(f map[string]string, m *updManifestT, _ *[]zent) {
			delete(f, "engine/kaa-mac-amd64")
			*m = updManifestFor(m.Version, f)
		}, "0.1.0", "Intel"},
		"older":         {nil, "0.3.0", "older"},
		"same":          {nil, "0.2.0", "already installed"},
		"min version":   {func(_ map[string]string, m *updManifestT, _ *[]zent) { m.MinVersion = "0.1.5" }, "0.1.0", "needs version 0.1.5"},
		"other app":     {func(_ map[string]string, m *updManifestT, _ *[]zent) { m.App = "KBB Studio" }, "0.1.0", "not for"},
		"future format": {func(_ map[string]string, m *updManifestT, _ *[]zent) { m.Format = 3 }, "0.1.0", "newer kind of updater"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			st := filepath.Join(t.TempDir(), "staging")
			_, err := checkPackage(buildPkg(t, "0.2.0", c.tweak), st, "", c.cur, false)
			if err == nil || !strings.Contains(err.Error(), c.want) {
				t.Fatalf("want a refusal mentioning %q, got %v", c.want, err)
			}
			if _, e := os.Stat(st); e == nil {
				t.Fatal("a refused package left its staging folder behind")
			}
		})
	}
}

// A patch staged over the exact "from" version is byte-for-byte the new version: changed files new, removed files
// gone, the rest as it was. MUTATION: skip linkTree in checkPackage → the staged app/ lacks the unchanged files and
// verifyStaged refuses; skip the Removed loop → "app/js/lib.js is not part of the new version".
func TestPatchStagesExactResult(t *testing.T) {
	root := installed(t, "0.3.0")
	z := buildPatch(t, "0.3.0", "0.3.1", map[string]string{"app/js/lib.js": "", "app/js/new.js": "export const y = 2;", "app/js/main.js": "import { y } from './new.js';\nmain 0.3.1"}, nil)
	st := filepath.Join(t.TempDir(), "staging")
	c, err := checkPackage(z, st, root, "0.3.0", false)
	if err != nil {
		t.Fatal(err)
	}
	if !c.man.patch() || strings.Join(c.units, ",") != "VERSION,app,update.json" {
		t.Fatalf("a patch touching app/ and VERSION staged units %v", c.units)
	}
	if _, err := os.Stat(filepath.Join(st, "app/js/lib.js")); err == nil {
		t.Fatal("the removed file is still staged")
	}
	if b, _ := os.ReadFile(filepath.Join(st, "app/css/app.css")); string(b) != pkgFiles("0.3.1")["app/css/app.css"] && string(b) != pkgFiles("0.3.0")["app/css/app.css"] {
		t.Fatal("an unchanged file did not come through")
	}
	added, changed, removed, _, engine := updDiff(root, c)
	if added != 1 || removed != 1 || changed < 2 || engine {
		t.Fatalf("diff added %d changed %d removed %d engine %v", added, changed, removed, engine)
	}
	// the installed folder is untouched by staging (hard links are never written through)
	if treeSum(t, root) != treeSum(t, installed(t, "0.3.0")) {
		t.Fatal("staging a patch changed the installed app folder")
	}
}

// MUTATION: compare man.From with "" or drop the `current != man.From` check → a patch lays itself over the wrong
// version; drop the Removed path checks → a patch can delete outside the units an update owns.
func TestPatchRefuses(t *testing.T) {
	cases := map[string]struct {
		cur   string
		z     func(t *testing.T) string
		prep  func(root string)
		want  string
		code  string
		hasTo string
	}{
		"wrong from": {"0.2.9", func(t *testing.T) string {
			return buildPatch(t, "0.3.0", "0.3.1", map[string]string{"app/js/lib.js": "x"}, nil)
		}, nil,
			"updates version 0.3.0 only, and you have 0.2.9", "wrongfrom", "KBB Ads Autopilot v0.3.1.zip"},
		"changed install": {"0.3.0", func(t *testing.T) string {
			return buildPatch(t, "0.3.0", "0.3.1", map[string]string{"app/js/lib.js": "x"}, nil)
		},
			func(root string) {
				_ = os.WriteFile(filepath.Join(root, "app/js/added-by-hand.js"), []byte("x"), 0o644)
			}, "not exactly as it was installed", "wrongfrom", "v0.3.1.zip"},
		"removed traversal": {"0.3.0", func(t *testing.T) string {
			return buildPatch(t, "0.3.0", "0.3.1", map[string]string{"app/js/lib.js": "x"}, func(_ map[string]string, m *updManifestT) {
				m.Removed = []string{"../../KBB Ads Autopilot Data/secrets.json"}
			})
		}, nil, "may not touch", "outside", ""},
		"removes VERSION": {"0.3.0", func(t *testing.T) string {
			return buildPatch(t, "0.3.0", "0.3.1", nil, func(_ map[string]string, m *updManifestT) { m.Removed = []string{"VERSION"} })
		}, nil, "may not touch", "outside", ""},
		"result disagrees": {"0.3.0", func(t *testing.T) string {
			return buildPatch(t, "0.3.0", "0.3.1", map[string]string{"app/js/lib.js": "x"}, func(_ map[string]string, m *updManifestT) {
				f := m.Result["app/js/lib.js"]
				f.Size++
				m.Result["app/js/lib.js"] = f
			})
		}, nil, "damaged", "damaged", ""},
		"format 1 patch": {"0.3.0", func(t *testing.T) string {
			return buildPatch(t, "0.3.0", "0.3.1", map[string]string{"app/js/lib.js": "x"}, func(_ map[string]string, m *updManifestT) { m.Format = 1 })
		}, nil, "damaged", "damaged", ""},
		"older patch": {"0.3.0", func(t *testing.T) string { return buildPatch(t, "0.3.0", "0.2.0", nil, nil) }, nil, "damaged", "damaged", ""},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			root := installed(t, "0.3.0")
			if c.prep != nil {
				c.prep(root)
			}
			_, err := checkPackage(c.z(t), filepath.Join(t.TempDir(), "st"), root, c.cur, false)
			if err == nil || !strings.Contains(err.Error(), c.want) || refusalCode(err) != c.code || !strings.Contains(err.Error(), c.hasTo) {
				t.Fatalf("want %q (code %s), got %v (code %q)", c.want, c.code, err, refusalCode(err))
			}
		})
	}
}

// The whole install through the HTTP API: verify → stage → swap, the old version kept, history, the health marker.
// Then Roll back: byte-identical to before. MUTATION: in install(), skip verifyStaged → "staged file changed" is
// installed; drop the s.updBusy check → a second install starts while one runs.
func TestInstallAndRollBack(t *testing.T) {
	root := installed(t, "0.3.0")
	before := treeSum(t, root)
	s := updServer(t, root)
	pend, err := s.checkFile(buildPkg(t, "0.3.1", nil), "p.zip", 1)
	if err != nil {
		t.Fatal(err)
	}
	if pend.Kind != "full" || pend.Changed == 0 || len(pend.Checks) < 6 {
		t.Fatalf("pending %+v", pend)
	}
	s.updPend = pend
	lines := stream(t, s, "/api/update/install", map[string]string{"ID": pend.ID})
	if !strings.Contains(lines, `"state":"done","step":"verify"`) || !strings.Contains(lines, `"state":"done","step":"swap"`) || !strings.Contains(lines, `"ok":true`) {
		t.Fatalf("install stream: %s", lines)
	}
	if appVersionAt(root) != "0.3.1" || s.readPrevious() == nil || s.readPrevious().Version != "0.3.0" {
		t.Fatal("not installed, or the old version not kept")
	}
	if m := s.readMarker(); m == nil || m.To != "0.3.1" || !m.Auto {
		t.Fatalf("no health marker for the new engine: %+v", m)
	}
	_ = os.Remove(filepath.Join(s.updDir(), updMarkerName))
	lines = stream(t, s, "/api/update/rollback", nil)
	if !strings.Contains(lines, `"ok":true`) || treeSum(t, root) != before {
		t.Fatalf("roll back did not restore the folder byte for byte: %s", lines)
	}
	if h := s.readHistory(); len(h) != 2 || h[0].Kind != "rollback" || h[1].Kind != "update" {
		t.Fatalf("history %+v", h)
	}
	if m := s.readMarker(); m == nil || m.Auto {
		t.Fatal("a roll back must be health-checked but never roll itself back again")
	}
}

// A staged file changed on disk between "checked" and "Install" is caught by the verify step, nothing moves -- also
// when it was swapped for a LINK to a file with the very same bytes (hashing alone would follow it and pass).
// MUTATION: drop the IsRegular check in verifyStaged → "link" is installed.
func TestInstallVerifiesStagingAgain(t *testing.T) {
	for name, spoil := range map[string]func(p string){
		"changed": func(p string) { _ = os.WriteFile(p, []byte("swapped on disk"), 0o644) },
		"link": func(p string) {
			b, _ := os.ReadFile(p)
			other := filepath.Join(t.TempDir(), "same.js")
			_ = os.WriteFile(other, b, 0o644)
			_ = os.Remove(p)
			_ = os.Symlink(other, p)
		},
	} {
		t.Run(name, func(t *testing.T) {
			root := installed(t, "0.3.0")
			before := treeSum(t, root)
			s := updServer(t, root)
			pend, err := s.checkFile(buildPkg(t, "0.3.1", nil), "p.zip", 1)
			if err != nil {
				t.Fatal(err)
			}
			spoil(filepath.Join(pend.staging, "app/js/lib.js"))
			s.updPend = pend
			lines := stream(t, s, "/api/update/install", map[string]string{"ID": pend.ID})
			if !strings.Contains(lines, `"state":"fail","step":"verify"`) || !strings.Contains(lines, `"ok":false`) || treeSum(t, root) != before {
				t.Fatalf("a spoiled staged file was installed: %s", lines)
			}
			if s.updBusy {
				t.Fatal("still busy after a refused install")
			}
		})
	}
}

// A patch through the API, and a patch onto the wrong version refused with the full zip named.
func TestInstallPatch(t *testing.T) {
	root := installed(t, "0.3.0")
	s := updServer(t, root)
	pend, err := s.checkFile(buildPatch(t, "0.3.0", "0.3.1", map[string]string{"app/js/lib.js": "export const x = 2;"}, nil), "patch.zip", 1)
	if err != nil {
		t.Fatal(err)
	}
	s.updPend = pend
	if lines := stream(t, s, "/api/update/install", map[string]string{"ID": pend.ID}); !strings.Contains(lines, `"kind":"patch"`) {
		t.Fatal(lines)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "app/js/lib.js")); string(b) != "export const x = 2;" || appVersionAt(root) != "0.3.1" {
		t.Fatal("the patch did not land")
	}
	if b, _ := os.ReadFile(filepath.Join(root, "app/css/app.css")); string(b) != pkgFiles("0.3.1")["app/css/app.css"] {
		t.Fatal("an unchanged file did not survive the patch")
	}
	// the same patch again: now it is the wrong version
	if _, err := s.checkFile(buildPatch(t, "0.3.0", "0.3.1", map[string]string{"app/js/lib.js": "y"}, nil), "patch.zip", 1); err == nil || refusalCode(err) != "wrongfrom" {
		t.Fatalf("a patch for 0.3.0 was accepted on 0.3.1: %v", err)
	}
}

// A power cut in the middle of the swap: the next start puts everything back exactly, says so, and keeps no stale
// marker. MUTATION: restore the old unswap (move cur→src whenever cur exists) → the units the swap never reached are
// moved OUT of the app folder and the tree differs; remove the journal write → nothing is recovered.
func TestInterruptedSwapIsRecovered(t *testing.T) {
	for _, at := range []int{0, 1, 2, 4} {
		t.Run(fmt.Sprint("cut at unit ", at), func(t *testing.T) {
			root := installed(t, "0.3.0")
			before := treeSum(t, root)
			s := updServer(t, root)
			pend, err := s.checkFile(buildPkg(t, "0.3.1", nil), "p.zip", 1)
			if err != nil {
				t.Fatal(err)
			}
			updCrashAt = at
			defer func() { updCrashAt = -1 }()
			if _, err := s.apply(pend.staging, pend.units, "0.3.0", "0.3.1", "update"); err != errUpdCrash {
				t.Fatalf("the test's power cut did not happen: %v", err)
			}
			updCrashAt = -1
			s2 := updServer(t, root) // the next start
			s2.recoverInterrupted()
			if got := treeSum(t, root); got != before {
				t.Fatalf("not put back exactly:\n%s\n---\n%s", got, before)
			}
			if l := s2.readLast(); l == nil || l.Kind != "interrupted" || !strings.Contains(l.Reason, "0.3.0 was put back") {
				t.Fatalf("the page is not told: %+v", l)
			}
			if ents, _ := os.ReadDir(s2.updDir()); len(ents) != 1 { // last.json only
				t.Fatalf("left behind: %v", ents)
			}
		})
	}
}

// A power cut after the swap finished but before the backup was kept as "previous": it becomes "previous".
func TestFinishedSwapBackupIsKept(t *testing.T) {
	root := installed(t, "0.3.0")
	s := updServer(t, root)
	pend, _ := s.checkFile(buildPkg(t, "0.3.1", nil), "p.zip", 1)
	backup := filepath.Join(s.updDir(), "backup-cut")
	if err := swapUnits(root, pend.staging, backup, pend.units, "0.3.0", "0.3.1"); err != nil {
		t.Fatal(err)
	}
	info, _ := json.Marshal(updInfo{Version: "0.3.0", Units: pend.units})
	_ = os.WriteFile(filepath.Join(backup, "info.json"), info, 0o644)
	_ = os.Remove(filepath.Join(backup, "journal.json"))
	s.recoverInterrupted()
	if appVersionAt(root) != "0.3.1" || s.readPrevious() == nil || s.readPrevious().Version != "0.3.0" {
		t.Fatal("the finished install was lost or its backup thrown away")
	}
}

// The new engine checks itself; a failed health check rolls back BY ITSELF to the version before, records why, and
// the next engine (the old one) is health-checked too but never rolls again. MUTATION: drop `m.Auto &&` handling
// (never roll back) → VERSION stays 0.3.1; make healthCheck return nil → the broken app (no main.js) is kept.
func TestHealthFailureRollsBackByItself(t *testing.T) {
	root := installed(t, "0.3.0")
	before := treeSum(t, root)
	s := updServer(t, root)
	// 0.3.1 whose app does not load: its main.js imports a module the package does not have
	pend, err := s.checkFile(buildPkg(t, "0.3.1", func(f map[string]string, m *updManifestT, _ *[]zent) {
		f["app/js/main.js"] = "import { z } from './gone.js';"
		*m = updManifestFor("0.3.1", f)
	}), "p.zip", 1)
	if err != nil {
		t.Fatal(err)
	}
	if _, _, _, _, err := s.install(pend, func(string, string, map[string]any) {}); err != nil {
		t.Fatal(err)
	}
	// the engine that starts next
	runningAs(t, "0.3.1")
	s2 := updServer(t, root)
	srv := httptest.NewServer(s2)
	defer srv.Close()
	s2.addr = strings.TrimPrefix(srv.URL, "http://")
	s2.startHealth()
	<-s2.healthGate // noRestart: the test stops where the engine would exec
	if got := treeSum(t, root); got != before {
		t.Fatal("not rolled back to 0.3.0 byte for byte")
	}
	l := s2.readLast()
	if l == nil || l.Kind != "auto-rollback" || l.From != "0.3.1" || l.To != "0.3.0" || !strings.Contains(l.Reason, "gone.js is missing") {
		t.Fatalf("last: %+v", l)
	}
	if h := s2.readHistory(); len(h) < 2 || h[0].Kind != "auto-rollback" || !strings.Contains(h[0].Reason, "gone.js") {
		t.Fatalf("history %+v", h)
	}
	// the restored engine checks itself and does not roll again
	m := s2.readMarker()
	if m == nil || m.Auto || m.To != "0.3.0" {
		t.Fatalf("marker after the auto roll back: %+v", m)
	}
	runningAs(t, "0.3.0") // the engine exec'd after the roll back starts as the version put back
	s3 := updServer(t, root)
	srv3 := httptest.NewServer(s3)
	defer srv3.Close()
	s3.addr = strings.TrimPrefix(srv3.URL, "http://")
	s3.startHealth()
	<-s3.healthGate
	if s3.readMarker() != nil || s3.health == nil || s3.health.State != "ok" {
		t.Fatalf("the restored version did not pass its health check: %+v", s3.health)
	}
	if l := s3.readLast(); l.Kind != "auto-rollback" || !strings.Contains(l.Reason, "gone.js") {
		t.Fatalf("the auto-roll-back note was overwritten before the page could show it: %+v", l)
	}
}

// A healthy new engine: marker gone, autopilot gate open, "ok". A marker for a version no longer in place (the
// install was undone) is dropped at start, never acted on. MUTATION: drop the version guard in recoverInterrupted →
// the old version is "health-checked" as the new one and rolled back to an even older one.
func TestHealthOK(t *testing.T) {
	root := installed(t, "0.3.1")
	s := updServer(t, root)
	_ = s.writeMarker(updMarker{Kind: "update", From: "0.3.0", To: "0.3.1", Auto: true})
	runningAs(t, "0.3.1")
	srv := httptest.NewServer(s)
	defer srv.Close()
	s.addr = strings.TrimPrefix(srv.URL, "http://")
	s.startHealth()
	<-s.healthGate
	if s.readMarker() != nil || s.health.State != "ok" || appVersionAt(root) != "0.3.1" {
		t.Fatalf("health %+v", s.health)
	}
	_ = s.writeMarker(updMarker{Kind: "update", From: "0.3.1", To: "0.3.2", Auto: true})
	s.recoverInterrupted()
	if s.readMarker() != nil {
		t.Fatal("a marker for a version that is not in place survived the start")
	}
}

// Never two installs at once, in one process or across two (two windows on one app folder).
func TestOneInstallAtATime(t *testing.T) {
	root := installed(t, "0.3.0")
	s, s2 := updServer(t, root), updServer(t, root)
	unlock, err := s.lockInstall()
	if err != nil {
		t.Fatal(err)
	}
	if _, err := s2.lockInstall(); err == nil {
		t.Fatal("a second install got the lock")
	}
	unlock()
	if u, err := s2.lockInstall(); err != nil {
		t.Fatal("the lock was not released")
	} else {
		u()
	}
	s.updBusy = true
	if lines := stream(t, s, "/api/update/rollback", nil); !strings.Contains(lines, "already being installed") {
		t.Fatalf("install while busy: %s", lines)
	}
}

// The autopilot is paused while an update installs: a cycle refuses, and resumes after.
func TestAutopilotPausesDuringInstall(t *testing.T) {
	a := &App{accounts: map[string]*Acct{}}
	ac := &Acct{app: a, id: "act_100001"}
	a.accounts[ac.id] = ac
	a.updating.Store(true)
	if _, err := ac.Cycle(context.Background(), "auto"); !errors.Is(err, errUpdating) {
		t.Fatalf("a cycle ran during an install: %v", err)
	}
	s := &server{app: a}
	if !s.pauseAutopilot(0) {
		t.Fatal("pause with nothing running")
	}
	ac.work.Lock() // a cycle still running
	if s.pauseAutopilot(300 * 1e6) {
		t.Fatal("the install did not wait for the running cycle")
	}
	ac.work.Unlock()
	s.resumeAutopilot()
	if a.updating.Load() {
		t.Fatal("not resumed")
	}
}

// Resumable upload: a chunk that arrives cut short is not counted; the page asks where to go on and resumes there,
// not at zero; a damaged chunk is refused; a chunk at the wrong offset is told the right one; begin with the same
// name and size picks the upload up again. MUTATION: write the chunk before checking its sha → a damaged chunk is
// counted; accept any offset → a resent chunk is written twice and the package fails its fingerprint check.
func TestResumableUpload(t *testing.T) {
	root := installed(t, "0.3.0")
	s := updServer(t, root)
	z, _ := os.ReadFile(buildPkg(t, "0.3.1", nil))
	h := func(method, path string, body []byte, hdr map[string]string) (int, map[string]any) {
		r := httptest.NewRequest(method, path, bytes.NewReader(body))
		r.Host = s.addr
		r.Header.Set("X-KAA", "t")
		for k, v := range hdr {
			r.Header.Set(k, v)
		}
		w := httptest.NewRecorder()
		s.ServeHTTP(w, r)
		var out map[string]any
		_ = json.Unmarshal(w.Body.Bytes(), &out)
		return w.Code, out
	}
	s.addr = "127.0.0.1:1"
	jb := func(v any) []byte { b, _ := json.Marshal(v); return b }
	code, beg := h("POST", "/api/update/upload/begin", jb(map[string]any{"name": "KBB Ads Autopilot v0.3.1.zip", "size": len(z)}), nil)
	if code != 200 {
		t.Fatal(code, beg)
	}
	id := beg["id"].(string)
	half := len(z) / 2
	sum := func(b []byte) string { x := sha256.Sum256(b); return hex.EncodeToString(x[:]) }
	put := func(off int, b []byte, sha string) (int, map[string]any) {
		return h("PUT", fmt.Sprintf("/api/update/upload/chunk?id=%s&offset=%d", id, off), b, map[string]string{"X-Chunk-SHA256": sha})
	}
	if c, _ := put(0, z[:half], sum(z[:half])); c != 200 {
		t.Fatal("first chunk", c)
	}
	// the connection drops mid-chunk: the body ends before Content-Length
	r := httptest.NewRequest("PUT", fmt.Sprintf("/api/update/upload/chunk?id=%s&offset=%d", id, half), io.LimitReader(bytes.NewReader(z[half:]), 100))
	r.ContentLength = int64(len(z) - half)
	r.Host = s.addr
	r.Header.Set("X-KAA", "t")
	r.Header.Set("X-Chunk-SHA256", sum(z[half:]))
	w := httptest.NewRecorder()
	s.ServeHTTP(w, r)
	if w.Code == 200 {
		t.Fatal("a cut chunk was accepted")
	}
	if c, st := h("GET", "/api/update/upload/status?id="+id, nil, nil); c != 200 || int(st["received"].(float64)) != half {
		t.Fatalf("after the drop the engine should still have %d bytes: %v", half, st)
	}
	if c, out := put(0, z[:half], sum(z[:half])); c != 409 || int(out["received"].(float64)) != half {
		t.Fatalf("a chunk at the wrong offset: %d %v", c, out)
	}
	if c, _ := put(half, z[half:], sum([]byte("other"))); c != 422 {
		t.Fatal("a damaged chunk was accepted", c)
	}
	// the page reloads and chooses the same file: same upload, resumes at half
	if c, again := h("POST", "/api/update/upload/begin", jb(map[string]any{"name": "KBB Ads Autopilot v0.3.1.zip", "size": len(z), "resume": id}), nil); c != 200 || again["id"] != id || int(again["received"].(float64)) != half {
		t.Fatalf("resume: %v", again)
	}
	if c, _ := put(half, z[half:], sum(z[half:])); c != 200 {
		t.Fatal("last chunk", c)
	}
	code, pend := h("POST", "/api/update/upload/finish", jb(map[string]any{"id": id}), nil)
	if code != 200 || pend["to"] != "0.3.1" || pend["kind"] != "full" {
		t.Fatalf("finish: %d %v", code, pend)
	}
	if _, err := os.Stat(s.uploadDir(id)); err == nil {
		t.Fatal("the upload was kept after it was checked")
	}
}

// A refused package through the API leaves the app folder byte-identical, with no .update folder, and says why with
// a code the page words in Arabic.
func TestRefusedLeavesNothing(t *testing.T) {
	root := installed(t, "0.3.0")
	before := treeSum(t, root)
	s := updServer(t, root)
	s.addr = "127.0.0.1:1"
	z, _ := os.ReadFile(buildPkg(t, "0.2.0", nil))
	r := httptest.NewRequest("POST", "/api/update/upload", bytes.NewReader(z))
	r.Host = s.addr
	r.Header.Set("X-KAA", "t")
	w := httptest.NewRecorder()
	s.ServeHTTP(w, r)
	if w.Code != 422 || !strings.Contains(w.Body.String(), `"code":"older"`) {
		t.Fatalf("%d %s", w.Code, w.Body.String())
	}
	if _, err := os.Stat(s.updDir()); err == nil || treeSum(t, root) != before {
		t.Fatal("a refused package left something behind")
	}
}

// TestRealPackage: KAA_PKG=<the zip tools/package-mac.sh made> go test -run RealPackage — the updater accepts it.
func TestRealPackage(t *testing.T) {
	z := os.Getenv("KAA_PKG")
	if z == "" {
		t.Skip("set KAA_PKG to a built zip")
	}
	c, err := checkPackage(z, filepath.Join(t.TempDir(), "staging"), "", "0.0.1", false)
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("accepted %s: %d units %v, %d bytes", c.man.Version, len(c.units), c.units, c.total)
}

func TestVersionFromFile(t *testing.T) {
	d := t.TempDir()
	_ = os.WriteFile(filepath.Join(d, "VERSION"), []byte("1.2.3\n"), 0o644)
	if v := appVersionAt(d); v != "1.2.3" {
		t.Fatalf("%q", v)
	}
	_ = os.WriteFile(filepath.Join(d, "VERSION"), []byte("1.2.3; rm -rf /\n"), 0o644)
	if v := appVersionAt(d); v != "" {
		t.Fatalf("a bad VERSION read as %q", v)
	}
}

// runningAs: the engine's own version (main sets it from VERSION at start; a test binary is "dev")
func runningAs(t *testing.T, v string) {
	old := version
	version = v
	t.Cleanup(func() { version = old })
}

// An OLD engine still running after the folder was replaced (the owner, 20 Nov 2026: 0.2.0's engine served 0.3.0's
// app, whose cards then vanished) never passes as the new version: /api/info says what it started as, and the health
// check refuses it. MUTATION: drop the info.Version check in healthCheck → this passes as healthy.
func TestHealthRefusesAnOldEngine(t *testing.T) {
	root := installed(t, "0.3.1")
	s := updServer(t, root)
	runningAs(t, "0.3.0")
	srv := httptest.NewServer(s)
	defer srv.Close()
	s.addr = strings.TrimPrefix(srv.URL, "http://")
	err := s.healthCheck(context.Background(), updMarker{From: "0.3.0", To: "0.3.1"})
	if err == nil || !strings.Contains(err.Error(), `running is version "0.3.0"`) {
		t.Fatalf("an old engine passed the health check: %v", err)
	}
	r, _ := http.Get(srv.URL + "/api/info")
	var info map[string]any
	_ = json.NewDecoder(r.Body).Decode(&info)
	r.Body.Close()
	if info["version"] != "0.3.0" || info["folderVersion"] != "0.3.1" {
		t.Fatalf("/api/info does not tell the page the engine is stale: %v", info)
	}
}

// stream: POST path, the NDJSON lines as one string
func stream(t *testing.T, s *server, path string, body any) string {
	t.Helper()
	b, _ := json.Marshal(body)
	r := httptest.NewRequest(http.MethodPost, path, bytes.NewReader(b))
	if s.addr == "" {
		s.addr = "127.0.0.1:1"
	}
	r.Host = s.addr
	r.Header.Set("X-KAA", "t")
	w := httptest.NewRecorder()
	s.ServeHTTP(w, r)
	return w.Body.String()
}
