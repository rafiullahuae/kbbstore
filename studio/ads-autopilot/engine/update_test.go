package main

// Ported from studio/reel-studio/engine/update_test.go (the package checks), adapted to this app's units.

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
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
		"app/index.html":                  "<html>v" + v + "</html>",
		"app/main.js":                     "main " + v,
		"engine/kaa-mac-arm64":            "arm engine " + v,
		"engine/kaa-mac-amd64":            "intel engine " + v,
		"VERSION":                         v + "\n",
		"README.md":                       "readme " + v,
		"Start KBB Ads Autopilot.command": "#!/bin/bash\necho " + v,
	}
}

func updManifestFor(v string, files map[string]string) updManifestT {
	m := updManifestT{Format: 1, App: appName, Version: v, Date: "2026-11-20", Notes: []string{"First", "Second"}, Files: map[string]updFile{}}
	for k, b := range files {
		m.Files[k] = updFile{Sha256: shaHex(b), Size: int64(len(b))}
	}
	return m
}

func buildPkg(t *testing.T, v string, tweak func(files map[string]string, man *updManifestT, extra *[]zent)) string {
	t.Helper()
	files := pkgFiles(v)
	man := updManifestFor(v, files)
	var extra []zent
	if tweak != nil {
		tweak(files, &man, &extra)
	}
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
	ents = append(ents, extra...)
	return writeZip(t, ents)
}

func TestCheckPackageAccepts(t *testing.T) {
	st := filepath.Join(t.TempDir(), "staging")
	man, units, _, err := checkPackage(buildPkg(t, "0.2.0", nil), st, "0.1.0", false)
	if err != nil {
		t.Fatal(err)
	}
	want := "README.md,Start KBB Ads Autopilot.command,VERSION,app,engine/kaa-mac-amd64,engine/kaa-mac-arm64,update.json"
	if man.Version != "0.2.0" || strings.Join(units, ",") != want {
		t.Fatalf("units %v", units)
	}
	for _, x := range []string{"engine/kaa-mac-arm64", "engine/kaa-mac-amd64", "Start KBB Ads Autopilot.command"} {
		if s, _ := os.Stat(filepath.Join(st, x)); s == nil || s.Mode().Perm() != 0o755 {
			t.Errorf("%s should keep its executable bit", x)
		}
	}
}

// MUTATION: let updUnit return segs[0] for any folder → "data folder" / "other engine" rows are accepted.
func TestCheckPackageRefuses(t *testing.T) {
	cases := map[string]struct {
		tweak func(f map[string]string, m *updManifestT, x *[]zent)
		cur   string
		want  string
	}{
		"tampered file": {func(f map[string]string, _ *updManifestT, _ *[]zent) { f["app/index.html"] = "<html>evil</html>" }, "0.1.0", "changed or damaged"},
		"zip-slip": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: appName + "/../../evil.sh", body: "x"})
		}, "0.1.0", "outside"},
		"symlink": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: appName + "/app/link", body: "/etc/passwd", mode: os.ModeSymlink | 0o777})
		}, "0.1.0", "link"},
		"unlisted file": {func(_ map[string]string, _ *updManifestT, x *[]zent) {
			*x = append(*x, zent{name: appName + "/app/extra.js", body: "x"})
		}, "0.1.0", "does not list"},
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
		"older":     {nil, "0.3.0", "older"},
		"same":      {nil, "0.2.0", "already installed"},
		"other app": {func(_ map[string]string, m *updManifestT, _ *[]zent) { m.App = "KBB Studio" }, "0.1.0", "not for"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			st := filepath.Join(t.TempDir(), "staging")
			_, _, _, err := checkPackage(buildPkg(t, "0.2.0", c.tweak), st, c.cur, false)
			if err == nil || !strings.Contains(err.Error(), c.want) {
				t.Fatalf("want a refusal mentioning %q, got %v", c.want, err)
			}
		})
	}
}

// TestRealPackage: KAA_PKG=<the zip tools/package-mac.sh made> go test -run RealPackage — the updater accepts it.
func TestRealPackage(t *testing.T) {
	z := os.Getenv("KAA_PKG")
	if z == "" {
		t.Skip("set KAA_PKG to a built zip")
	}
	man, units, total, err := checkPackage(z, filepath.Join(t.TempDir(), "staging"), "0.0.1", false)
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("accepted %s: %d units %v, %d bytes", man.Version, len(units), units, total)
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
