// update.go — installing a new KBB Studio from its zip, from inside the app (the owner, 19 Nov 2026: "give me function
// to upload patch directly in the studio setting, to make updates onwards"). Configure → Updates is the page.
//
// The package is the zip tools/package-mac.sh makes ("KBB Studio/..." inside) with update.json beside the files:
//
//	{ "format": 1, "app": "KBB Studio", "version": "1.3.0", "minVersion": "", "date": "2026-11-19",
//	  "notes": ["one line per change"], "files": { "app/index.html": {"sha256": "…", "size": 1234}, … },
//	  "signature": null }
//
// What is checked, all of it before the app folder is touched (nothing inside the zip is ever run while checking):
//   - every name: no absolute path, no '..', no backslash, no hidden part (bar the __MACOSX / .DS_Store junk a Mac
//     adds, which is skipped), no two names that differ only in case (a Mac disk does not tell them apart)
//   - only regular files and folders: a symlink, device or encrypted entry refuses the package
//   - only the parts of the app folder an update owns (updUnit): app/, vendor/, brands/, library/, the two Mac engines,
//     models/manifest.json and plain top-level files (README, the launcher, the guide). Never workspaces/, settings/,
//     models themselves, adstudio/ -- and never the data folder, which is not inside the app folder at all
//   - update.json present, for this app, a newer version than the running one (an older one only through Roll back),
//     both Mac engines listed, every file listed and every listed file there, each with its exact size and sha256
//     (counted while it is unpacked, never taken from the zip's own header)
//   - caps on the upload, the unpacked total and the number of files (a zip bomb stops at the cap)
//
// Signatures: there is no safe place for a private key yet, so the bar is integrity (every file's sha256) plus the
// owner choosing the file himself. "signature" is reserved in update.json: a later engine can require it, sign the
// manifest bytes without that field, and refuse a package without one; this one ignores it.
//
// Installing: the files are unpacked into .update/staging-* inside the app folder (same disk, so every move below is
// a rename). Then, unit by unit, the current copy is renamed into .update/backup-* and the new one into place; a
// journal in the backup lists the units, so a failure midway is put back at once, and a crash midway (power cut) is
// put back on the next start (recoverInterrupted). The new engine is then asked for its usage (-h): if it does not
// run on this Mac, everything is put back and nothing changed. Then the backup becomes .update/previous (one level:
// "Roll back"), and the engine re-executes itself (syscall.Exec, same arguments, same port) so the Terminal window
// keeps running it and the page reloads into the new version.
//
// If the new engine still fails to start, "Start KBB Studio.command" sees it exit at once and offers to put the
// previous version back (shell only: it works whatever version the backup is).
//
// Quarantine: Go cannot clear com.apple.quarantine, and does not need to: files the engine writes are not marked
// (only the browser's download, the zip itself, is). The launcher still runs xattr -d on its engine at every start.
package main

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
	"log"
	"net/http"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const (
	updDirName   = ".update" // inside the app folder: staging, backup, previous, history
	updMaxUpload = 1 << 30   // the zip itself (today's package is ~110 MB)
	updMaxTotal  = 3 << 30   // all files unpacked
	updMaxFiles  = 40000     // entries in the zip
	updMaxNotes  = 40        // lines of release notes kept
	updManifest  = "update.json"
)

var (
	updVersionRe = regexp.MustCompile(`^\d{1,4}(\.\d{1,4}){1,3}$`)
	updTopFileRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 ._()-]{0,90}\.(md|html|command|txt)$`)
	updShaRe     = regexp.MustCompile(`^[0-9a-f]{64}$`)
	appVersionRe = regexp.MustCompile(`(?m)^export const APP_VERSION = '([^']*)'`)
	updEngines   = []string{"engine/kbbstudio-mac-arm64", "engine/kbbstudio-mac-amd64"}
	// test hook: fail the swap at this unit index (Go tests only)
	updFailAt = -1
)

type updFile struct {
	Sha256 string `json:"sha256"`
	Size   int64  `json:"size"`
}

type updManifestT struct {
	Format     int                `json:"format"`
	App        string             `json:"app"`
	Version    string             `json:"version"`
	MinVersion string             `json:"minVersion,omitempty"`
	Date       string             `json:"date,omitempty"`
	Notes      []string           `json:"notes"`
	Files      map[string]updFile `json:"files"`
	Signature  json.RawMessage    `json:"signature,omitempty"` // reserved (see the top of this file)
}

// a checked, unpacked package waiting for the owner's "Install"
type updPending struct {
	ID      string   `json:"id"`
	From    string   `json:"from"`
	To      string   `json:"to"`
	Date    string   `json:"date,omitempty"`
	Notes   []string `json:"notes"`
	Size    int64    `json:"size"`  // the zip
	Total   int64    `json:"total"` // unpacked
	Files   int      `json:"files"`
	staging string
	units   []string
}

type updHistory struct {
	At   string `json:"at"`
	Kind string `json:"kind"` // update | rollback
	From string `json:"from"`
	To   string `json:"to"`
}

// backup/info.json: which version the backup holds and which units the swap moved
type updInfo struct {
	Version string   `json:"version"`
	At      string   `json:"at"`
	Units   []string `json:"units"`
}

type updRefusal struct{ msg string }

func (e updRefusal) Error() string         { return e.msg }
func refuse(format string, a ...any) error { return updRefusal{fmt.Sprintf(format, a...)} }

func (s *server) updDir() string { return filepath.Join(s.root, updDirName) }

// appVersion: the version of the app folder as it is now (app/core/config.js APP_VERSION, as package-mac.sh reads it)
func appVersionAt(root string) string {
	b, err := os.ReadFile(filepath.Join(root, "app", "core", "config.js"))
	if err != nil {
		return ""
	}
	if m := appVersionRe.FindSubmatch(b); m != nil {
		return string(m[1])
	}
	return ""
}

// cmpVersion: -1, 0, 1 by number per part ("1.10.0" > "1.9.3"); missing parts are 0
func cmpVersion(a, b string) int {
	pa, pb := strings.Split(a, "."), strings.Split(b, ".")
	for i := 0; i < len(pa) || i < len(pb); i++ {
		var x, y int
		if i < len(pa) {
			x, _ = strconv.Atoi(pa[i])
		}
		if i < len(pb) {
			y, _ = strconv.Atoi(pb[i])
		}
		if x != y {
			if x < y {
				return -1
			}
			return 1
		}
	}
	return 0
}

// updCleanName: a zip entry name as a clean relative path, or an error. Folders end in "/".
func updCleanName(name string) (string, error) {
	if name == "" || len(name) > 400 || strings.ContainsAny(name, "\\\x00:") || strings.HasPrefix(name, "/") {
		return "", refuse("A file in the package has a name that is not allowed (%q)", clip(name, 80))
	}
	for _, r := range name {
		if r < 0x20 || r == 0x7f {
			return "", refuse("A file in the package has a name that is not allowed (%q)", clip(name, 80))
		}
	}
	segs := strings.Split(strings.TrimSuffix(name, "/"), "/")
	for _, seg := range segs {
		if seg == "" || seg == "." || seg == ".." {
			return "", refuse("A file in the package points outside its folder (%q)", clip(name, 80))
		}
	}
	return name, nil
}

// updJunk: what a Mac adds when it re-zips a folder; skipped, never installed
func updJunk(rel string) bool {
	return rel == "__MACOSX" || strings.HasPrefix(rel, "__MACOSX/") || path.Base(strings.TrimSuffix(rel, "/")) == ".DS_Store"
}

// updUnit: which part of the app folder a (clean, top-folder-stripped) file belongs to -- the thing that is swapped
// as one -- or "" when an update may not write there.
func updUnit(rel string) string {
	segs := strings.Split(rel, "/")
	for _, seg := range segs {
		if strings.HasPrefix(seg, ".") {
			return ""
		}
	}
	if len(segs) == 1 {
		if rel == updManifest || updTopFileRe.MatchString(rel) {
			return rel
		}
		return ""
	}
	switch segs[0] {
	case "app", "vendor", "brands", "library":
		return segs[0]
	case "engine":
		for _, e := range updEngines {
			if rel == e {
				return rel
			}
		}
	case "models":
		if rel == "models/manifest.json" {
			return rel
		}
	}
	return ""
}

// updExecutable: the files that keep the executable bit (by path, never by what the zip says)
func updExecutable(rel string) bool {
	return strings.HasPrefix(rel, "engine/kbbstudio-mac-") || !strings.Contains(rel, "/") && strings.HasSuffix(rel, ".command")
}

// updValidUnit: a unit name read back from our own info.json is still one an update owns
func updValidUnit(u string) bool {
	if _, err := updCleanName(u); err != nil {
		return false
	}
	return updUnit(u) == u || updUnit(u+"/x") == u
}

// checkPackage reads the zip at zpath, checks everything, and unpacks it into staging (which it creates). On any
// refusal staging is removed and the error says why in plain words.
func checkPackage(zpath, staging, current string, rollbackOK bool) (*updManifestT, []string, int64, error) {
	zr, err := zip.OpenReader(zpath)
	if err != nil {
		return nil, nil, 0, refuse("This is not a zip file, or it is damaged. Use the “KBB Studio v….zip” file exactly as you received it.")
	}
	defer zr.Close()
	if len(zr.File) > updMaxFiles {
		return nil, nil, 0, refuse("The package has too many files (%d).", len(zr.File))
	}
	// 1. names, kinds, the common top folder
	type ent struct {
		f   *zip.File
		rel string
	}
	var ents []ent
	lower := map[string]string{}
	top := ""
	topSame := true
	for _, f := range zr.File {
		name, err := updCleanName(f.Name)
		if err != nil {
			return nil, nil, 0, err
		}
		if f.Flags&0x1 != 0 {
			return nil, nil, 0, refuse("The package is password-protected; an update never is.")
		}
		m := f.Mode()
		if m&os.ModeSymlink != 0 {
			return nil, nil, 0, refuse("The package contains a link (%q); an update never does.", clip(name, 80))
		}
		if m&(os.ModeDevice|os.ModeNamedPipe|os.ModeSocket|os.ModeCharDevice|os.ModeIrregular) != 0 {
			return nil, nil, 0, refuse("The package contains something that is not a file (%q).", clip(name, 80))
		}
		if updJunk(name) {
			continue
		}
		key := strings.ToLower(strings.TrimSuffix(name, "/"))
		if prev, dup := lower[key]; dup {
			return nil, nil, 0, refuse("The package has the same file twice (%q and %q).", clip(prev, 60), clip(name, 60))
		}
		lower[key] = name
		first, _, _ := strings.Cut(name, "/")
		if top == "" {
			top = first
		} else if first != top {
			topSame = false
		}
		ents = append(ents, ent{f, name})
	}
	prefix := ""
	if topSame && top != "" && lower[strings.ToLower(top+"/"+updManifest)] != "" {
		prefix = top + "/"
	} else if lower[updManifest] == "" {
		return nil, nil, 0, refuse("This zip is not a KBB Studio update: it has no update information inside. Only packages made for KBB Studio 1.3 and later can be installed from here.")
	}
	files := map[string]*zip.File{}
	var manFile *zip.File
	for _, e := range ents {
		rel := strings.TrimPrefix(e.rel, prefix)
		if prefix != "" && !strings.HasPrefix(e.rel, prefix) {
			if strings.TrimSuffix(e.rel, "/") == top { // the top folder's own entry
				continue
			}
			return nil, nil, 0, refuse("The package has files outside its KBB Studio folder (%q).", clip(e.rel, 80))
		}
		if rel == "" || strings.HasSuffix(rel, "/") || e.f.Mode().IsDir() {
			continue // a folder: made as needed
		}
		if rel == updManifest {
			manFile = e.f
			continue
		}
		if updUnit(rel) == "" {
			return nil, nil, 0, refuse("The package wants to write %q, which an update may not touch.", clip(rel, 80))
		}
		files[rel] = e.f
	}
	// 2. the manifest
	if manFile == nil {
		return nil, nil, 0, refuse("This zip is not a KBB Studio update: it has no update information inside.")
	}
	rc, err := manFile.Open()
	if err != nil {
		return nil, nil, 0, refuse("The update information inside the package is damaged.")
	}
	manBytes, err := io.ReadAll(io.LimitReader(rc, 16<<20+1))
	rc.Close()
	if err != nil || len(manBytes) > 16<<20 {
		return nil, nil, 0, refuse("The update information inside the package is damaged.")
	}
	var man updManifestT
	if err := json.Unmarshal(manBytes, &man); err != nil {
		return nil, nil, 0, refuse("The update information inside the package is damaged.")
	}
	if man.Format != 1 {
		return nil, nil, 0, refuse("This package is for a newer kind of updater (format %d). Install it by replacing the folder, once.", man.Format)
	}
	if man.App != appName {
		return nil, nil, 0, refuse("This package is not for %s.", appName)
	}
	if !updVersionRe.MatchString(man.Version) {
		return nil, nil, 0, refuse("The package does not say which version it is.")
	}
	if current != "" && !rollbackOK {
		switch c := cmpVersion(man.Version, current); {
		case c == 0:
			return nil, nil, 0, refuse("Version %s is already installed.", man.Version)
		case c < 0:
			return nil, nil, 0, refuse("This package is version %s, older than the one you have (%s). To go back, use “Roll back”.", man.Version, current)
		}
	}
	if man.MinVersion != "" {
		if !updVersionRe.MatchString(man.MinVersion) {
			return nil, nil, 0, refuse("The update information inside the package is damaged.")
		}
		if current != "" && cmpVersion(current, man.MinVersion) < 0 {
			return nil, nil, 0, refuse("Version %s needs version %s or later first. Install it by replacing the folder, once.", man.Version, man.MinVersion)
		}
	}
	if len(man.Files) == 0 || len(man.Files) > updMaxFiles {
		return nil, nil, 0, refuse("The update information inside the package is damaged.")
	}
	var total int64
	for rel, f := range man.Files {
		if _, err := updCleanName(rel); err != nil || strings.HasSuffix(rel, "/") || rel == updManifest || updUnit(rel) == "" {
			return nil, nil, 0, refuse("The package lists %q, which an update may not touch.", clip(rel, 80))
		}
		if !updShaRe.MatchString(f.Sha256) || f.Size < 0 || f.Size > updMaxTotal {
			return nil, nil, 0, refuse("The update information inside the package is damaged (%q).", clip(rel, 80))
		}
		total += f.Size
		if _, ok := files[rel]; !ok {
			return nil, nil, 0, refuse("The package is incomplete: %q is missing. Download it again.", clip(rel, 80))
		}
	}
	if total > updMaxTotal {
		return nil, nil, 0, refuse("The package is too big to be a KBB Studio update.")
	}
	for rel := range files {
		if _, ok := man.Files[rel]; !ok {
			return nil, nil, 0, refuse("The package has a file it does not list (%q): it may have been changed.", clip(rel, 80))
		}
	}
	for _, e := range updEngines {
		if _, ok := man.Files[e]; !ok {
			return nil, nil, 0, refuse("The package is missing the engine for %s Macs.", map[bool]string{true: "Apple-silicon", false: "Intel"}[strings.HasSuffix(e, "arm64")])
		}
	}
	if _, ok := man.Files["app/index.html"]; !ok {
		return nil, nil, 0, refuse("The package is incomplete: the app itself is missing.")
	}
	if fb := freeBytes(filepath.Dir(staging)); fb >= 0 && fb < total+(200<<20) {
		return nil, nil, 0, refuse("Not enough free space on this Mac to install it (needs %s).", human(total+(200<<20)))
	}
	// 3. unpack into staging, counting and hashing every byte as it is written
	if err := os.MkdirAll(staging, 0o755); err != nil {
		return nil, nil, 0, err
	}
	fail := func(err error) (*updManifestT, []string, int64, error) {
		_ = os.RemoveAll(staging)
		return nil, nil, 0, err
	}
	rels := make([]string, 0, len(files))
	for rel := range files {
		rels = append(rels, rel)
	}
	sort.Strings(rels)
	units := map[string]bool{updManifest: true}
	for _, rel := range rels {
		want := man.Files[rel]
		if err := unpackOne(files[rel], filepath.Join(staging, filepath.FromSlash(rel)), want, updExecutable(rel)); err != nil {
			if _, ok := err.(updRefusal); ok {
				return fail(refuse("This package was changed or damaged: %s (%s). Download it again.", err.Error(), clip(rel, 80)))
			}
			return fail(err)
		}
		units[updUnit(rel)] = true
	}
	if err := os.WriteFile(filepath.Join(staging, updManifest), manBytes, 0o644); err != nil {
		return fail(err)
	}
	out := make([]string, 0, len(units))
	for u := range units {
		out = append(out, u)
	}
	sort.Strings(out)
	return &man, out, total, nil
}

func unpackOne(f *zip.File, dest string, want updFile, executable bool) error {
	rc, err := f.Open()
	if err != nil {
		return refuse("a file cannot be read")
	}
	defer rc.Close()
	if err := os.MkdirAll(filepath.Dir(dest), 0o755); err != nil {
		return err
	}
	mode := os.FileMode(0o644)
	if executable {
		mode = 0o755
	}
	out, err := os.OpenFile(dest, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return err
	}
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(out, h), io.LimitReader(rc, want.Size+1))
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		if errors.Is(err, zip.ErrChecksum) || errors.Is(err, zip.ErrFormat) {
			return refuse("a file is damaged")
		}
		return err
	}
	if n != want.Size {
		return refuse("a file is not the size it should be")
	}
	if hex.EncodeToString(h.Sum(nil)) != want.Sha256 {
		return refuse("a file does not match its fingerprint")
	}
	return os.Chmod(dest, mode) // the umask must not take the engine's x bit
}

// ── the swap ──

type updMove struct{ from, to string }

// swapUnits: for each unit, root/<u> → backup/<u> (when it exists) and src/<u> → root/<u> (when it exists). A journal
// in backup lists the units first. On any failure every move made is undone, last first.
func swapUnits(root, src, backup string, units []string) error {
	if err := os.MkdirAll(backup, 0o755); err != nil {
		return err
	}
	j, _ := json.Marshal(map[string]any{"units": units, "src": filepath.Base(src)})
	if err := os.WriteFile(filepath.Join(backup, "journal.json"), j, 0o644); err != nil {
		return err
	}
	var done []updMove
	mv := func(a, b string) error {
		if err := os.MkdirAll(filepath.Dir(b), 0o755); err != nil {
			return err
		}
		if err := os.Rename(a, b); err != nil {
			return err
		}
		done = append(done, updMove{a, b})
		return nil
	}
	var err error
	for i, u := range units {
		if i == updFailAt {
			err = errors.New("test: failing midway")
			break
		}
		cur, nw, bk := filepath.Join(root, filepath.FromSlash(u)), filepath.Join(src, filepath.FromSlash(u)), filepath.Join(backup, filepath.FromSlash(u))
		if _, e := os.Lstat(cur); e == nil {
			if err = mv(cur, bk); err != nil {
				break
			}
		}
		if _, e := os.Lstat(nw); e == nil {
			if err = mv(nw, cur); err != nil {
				break
			}
		}
	}
	if err != nil {
		if uerr := undoMoves(done); uerr != nil {
			log.Printf("update: putting the old files back failed too: %v", uerr)
		}
		_ = os.Remove(filepath.Join(backup, "journal.json"))
		return err
	}
	return nil
}

func undoMoves(done []updMove) error {
	var first error
	for i := len(done) - 1; i >= 0; i-- {
		if err := os.Rename(done[i].to, done[i].from); err != nil && first == nil {
			first = err
		}
	}
	return first
}

// unswap: the exact reverse of a finished swapUnits (used when the new engine does not run)
func unswap(root, src, backup string, units []string) error {
	var first error
	for i := len(units) - 1; i >= 0; i-- {
		u := filepath.FromSlash(units[i])
		cur, nw, bk := filepath.Join(root, u), filepath.Join(src, u), filepath.Join(backup, u)
		if _, e := os.Lstat(cur); e == nil {
			_ = os.MkdirAll(filepath.Dir(nw), 0o755)
			if err := os.Rename(cur, nw); err != nil && first == nil {
				first = err
			}
		}
		if _, e := os.Lstat(bk); e == nil {
			_ = os.MkdirAll(filepath.Dir(cur), 0o755)
			if err := os.Rename(bk, cur); err != nil && first == nil {
				first = err
			}
		}
	}
	return first
}

// touchUnits: every file now in place gets "now" as its time, so the browser, which asks "changed since?", fetches
// the files a Roll back put back (they are OLDER than the ones it holds) instead of keeping the newer ones
func touchUnits(root string, units []string) {
	now := time.Now()
	for _, u := range units {
		_ = filepath.WalkDir(filepath.Join(root, filepath.FromSlash(u)), func(p string, d os.DirEntry, err error) error {
			if err == nil && d.Type().IsRegular() {
				_ = os.Chtimes(p, now, now)
			}
			return nil
		})
	}
}

// recoverInterrupted: a swap that never finished (the Mac lost power midway) is put back on the next start, before
// anything is served. Stale staging folders and uploads are removed.
func (s *server) recoverInterrupted() {
	d := s.updDir()
	ents, err := os.ReadDir(d)
	if err != nil {
		return
	}
	for _, e := range ents {
		p := filepath.Join(d, e.Name())
		switch {
		case strings.HasPrefix(e.Name(), "backup-"):
			var j struct {
				Units []string
				Src   string
			}
			b, err := os.ReadFile(filepath.Join(p, "journal.json"))
			if err == nil && json.Unmarshal(b, &j) == nil && (strings.HasPrefix(j.Src, "staging-") || j.Src == "previous") {
				ok := true
				for _, u := range j.Units {
					ok = ok && updValidUnit(u)
				}
				if ok {
					if err := unswap(s.root, filepath.Join(d, j.Src), p, j.Units); err != nil {
						log.Printf("update: an interrupted update could not be fully put back: %v", err)
						continue // keep it for a person to look at
					}
					log.Printf("update: an update that was interrupted has been undone; you are on %s", appVersionAt(s.root))
				}
			}
			_ = os.RemoveAll(p)
		case strings.HasPrefix(e.Name(), "staging-"), strings.HasPrefix(e.Name(), "upload-"), strings.HasPrefix(e.Name(), "trash-"), strings.HasPrefix(e.Name(), "undo-"):
			_ = os.RemoveAll(p)
		}
	}
}

// selfTest: run the engine that will be exec'd with -h (Go's flag package prints the usage and exits 0). Proves the
// binary loads and runs on this Mac -- any version answers this, the ones before the updater too. Returns the usage.
func selfTest(exe string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	out, err := exec.CommandContext(ctx, exe, "-h").CombinedOutput()
	if err != nil || !bytes.Contains(out, []byte("-dir")) {
		return "", fmt.Errorf("the new engine did not start on this Mac (%v)", err)
	}
	return string(out), nil
}

func (s *server) readPrevious() *updInfo {
	b, err := os.ReadFile(filepath.Join(s.updDir(), "previous", "info.json"))
	if err != nil {
		return nil
	}
	var in updInfo
	if json.Unmarshal(b, &in) != nil || !updVersionRe.MatchString(in.Version) {
		return nil
	}
	return &in
}

func (s *server) readHistory() []updHistory {
	var h []updHistory
	if b, err := os.ReadFile(filepath.Join(s.updDir(), "history.json")); err == nil {
		_ = json.Unmarshal(b, &h)
	}
	if h == nil {
		h = []updHistory{}
	}
	return h
}

func (s *server) addHistory(kind, from, to string) {
	h := append([]updHistory{{At: time.Now().Format(time.RFC3339), Kind: kind, From: from, To: to}}, s.readHistory()...)
	if len(h) > 20 {
		h = h[:20]
	}
	b, _ := json.MarshalIndent(h, "", " ")
	_ = writeAtomic(filepath.Join(s.updDir(), "history.json"), bytes.NewReader(b))
}

// apply: swap src into the app folder, test the engine, keep the backup as "previous", record it. kind = update |
// rollback. Returns the usage text of the engine that will be exec'd.
func (s *server) apply(src string, units []string, from, to, kind string) (string, error) {
	d := s.updDir()
	backup := filepath.Join(d, "backup-"+randHex(4))
	if err := swapUnits(s.root, src, backup, units); err != nil {
		_ = os.RemoveAll(backup)
		return "", fmt.Errorf("could not replace the files (%v); nothing was changed", err)
	}
	usage, err := selfTest(s.exe)
	if err == nil {
		if _, e := os.Stat(filepath.Join(s.root, "app", "index.html")); e != nil {
			err = errors.New("the app is missing after the swap")
		}
	}
	if err != nil {
		if uerr := unswap(s.root, src, backup, units); uerr != nil {
			return "", fmt.Errorf("%v, and putting the old version back failed (%v): close KBB Studio and start it again", err, uerr)
		}
		_ = os.RemoveAll(backup)
		return "", fmt.Errorf("%v; nothing was changed", err)
	}
	touchUnits(s.root, units)
	info, _ := json.MarshalIndent(updInfo{Version: from, At: time.Now().Format(time.RFC3339), Units: units}, "", " ")
	_ = os.WriteFile(filepath.Join(backup, "info.json"), info, 0o644)
	_ = os.WriteFile(filepath.Join(backup, "units.txt"), []byte(strings.Join(units, "\n")+"\n"), 0o644) // the launcher's way back
	_ = os.Remove(filepath.Join(backup, "journal.json"))
	prev := filepath.Join(d, "previous")
	if _, e := os.Lstat(prev); e == nil {
		trash := filepath.Join(d, "trash-"+randHex(4))
		if os.Rename(prev, trash) == nil {
			defer os.RemoveAll(trash)
		}
	}
	if err := os.Rename(backup, prev); err != nil {
		log.Printf("update: keeping the backup failed: %v", err)
	}
	s.addHistory(kind, from, to)
	return usage, nil
}

// restart: replace this process with the engine now in place, same arguments, same port, no new browser tab
func (s *server) restart(usage, to string) {
	time.Sleep(400 * time.Millisecond) // let the answer reach the page
	args := []string{s.exe}
	skip := false
	for _, a := range os.Args[1:] {
		if skip {
			skip = false
			continue
		}
		n := strings.TrimLeft(a, "-")
		if n == "port" {
			skip = true
			continue
		}
		if strings.HasPrefix(n, "port=") || n == "after-update" || strings.HasPrefix(n, "after-update=") {
			continue
		}
		args = append(args, a)
	}
	if _, p, err := netSplitPort(s.addr); err == nil {
		args = append(args, "-port="+p)
	}
	if strings.Contains(usage, "after-update") { // an engine from before the updater does not know the flag
		args = append(args, "-after-update")
	}
	fmt.Printf("\n%s: installed %s, restarting…\n", appName, to)
	if err := syscall.Exec(s.exe, args, os.Environ()); err != nil {
		log.Printf("update: could not restart (%v). Close this window and double-click “Start %s” again.", err, appName)
		s.updMu.Lock()
		s.updBusy = false
		s.updMu.Unlock()
	}
}

func netSplitPort(addr string) (string, string, error) {
	i := strings.LastIndex(addr, ":")
	if i < 0 {
		return "", "", errors.New("no port")
	}
	return addr[:i], addr[i+1:], nil
}

// ── the API ──

func (s *server) updateStatus() map[string]any {
	st := map[string]any{"version": appVersionAt(s.root), "engine": version, "history": s.readHistory(), "canUpdate": s.separateData() && s.exe != ""}
	if !s.separateData() {
		st["why"] = "Your files are kept inside the KBB Studio folder, so updating from here could replace them. Start KBB Studio with its “Start KBB Studio” file."
	}
	if p := s.readPrevious(); p != nil {
		st["previous"] = map[string]any{"version": p.Version, "at": p.At}
	}
	s.updMu.Lock()
	if s.updPend != nil {
		st["pending"] = s.updPend
	}
	st["busy"] = s.updBusy
	s.updMu.Unlock()
	return st
}

func (s *server) dropPending() { // caller holds updMu
	if s.updPend != nil {
		_ = os.RemoveAll(s.updPend.staging)
		s.updPend = nil
	}
}

func (s *server) updateAPI(w http.ResponseWriter, r *http.Request, p string) bool {
	switch p {
	case "/api/update":
		writeJSON(w, s.updateStatus())

	case "/api/update/upload":
		if r.Method != http.MethodPost {
			jsonErr(w, 405, "POST only")
			return true
		}
		if !s.separateData() || s.exe == "" {
			jsonErr(w, 409, "Updating from here needs KBB Studio started with its “Start KBB Studio” file.")
			return true
		}
		if r.ContentLength > updMaxUpload {
			jsonErr(w, 413, "That file is too big to be a KBB Studio update.")
			return true
		}
		s.updMu.Lock()
		if s.updBusy {
			s.updMu.Unlock()
			jsonErr(w, 409, "An update is already being installed.")
			return true
		}
		s.updBusy = true
		s.dropPending()
		s.updMu.Unlock()
		defer func() { s.updMu.Lock(); s.updBusy = false; s.updMu.Unlock() }()
		pend, err := s.receive(w, r)
		if err != nil {
			s.tidyUpdDir() // the upload is gone by now: an empty .update goes too
			code := 500
			if _, ok := err.(updRefusal); ok {
				code = 422
			}
			log.Printf("update: package refused: %v", err)
			jsonErr(w, code, err.Error())
			return true
		}
		s.updMu.Lock()
		s.updPend = pend
		s.updMu.Unlock()
		log.Printf("update: package %s checked (%d files), waiting for Install", pend.To, pend.Files)
		writeJSON(w, pend)

	case "/api/update/cancel":
		if r.Method != http.MethodPost {
			jsonErr(w, 405, "POST only")
			return true
		}
		s.updMu.Lock()
		if !s.updBusy {
			s.dropPending()
		}
		s.updMu.Unlock()
		s.tidyUpdDir()
		writeJSON(w, map[string]any{"ok": true})

	case "/api/update/install", "/api/update/rollback":
		var b struct{ ID string }
		if r.Method != http.MethodPost {
			jsonErr(w, 405, "POST only")
			return true
		}
		_ = json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&b)
		if !s.separateData() || s.exe == "" {
			jsonErr(w, 409, "Updating from here needs KBB Studio started with its “Start KBB Studio” file.")
			return true
		}
		s.inst.mu.Lock()
		dl := s.inst.State == "running"
		s.inst.mu.Unlock()
		if dl {
			jsonErr(w, 409, "Models are downloading. Pause them (or let them finish) first.")
			return true
		}
		s.updMu.Lock()
		if s.updBusy {
			s.updMu.Unlock()
			jsonErr(w, 409, "An update is already being installed.")
			return true
		}
		from := appVersionAt(s.root)
		var src, to, kind string
		var units []string
		if p == "/api/update/install" {
			if s.updPend == nil || s.updPend.ID != b.ID {
				s.updMu.Unlock()
				jsonErr(w, 409, "Choose the update file again.")
				return true
			}
			src, to, units, kind = s.updPend.staging, s.updPend.To, s.updPend.units, "update"
		} else {
			prev := s.readPrevious()
			if prev == nil {
				s.updMu.Unlock()
				jsonErr(w, 409, "There is no earlier version to go back to.")
				return true
			}
			src, to, units, kind = filepath.Join(s.updDir(), "previous"), prev.Version, prev.Units, "rollback"
			for _, u := range units {
				if !updValidUnit(u) {
					s.updMu.Unlock()
					jsonErr(w, 409, "The kept copy of the earlier version is damaged.")
					return true
				}
			}
			for _, need := range append([]string{"app/index.html"}, updEngines...) {
				if st, err := os.Stat(filepath.Join(src, filepath.FromSlash(need))); err != nil || !st.Mode().IsRegular() {
					s.updMu.Unlock()
					jsonErr(w, 409, "The kept copy of the earlier version is incomplete, so it cannot be put back.")
					return true
				}
			}
			s.dropPending()
		}
		s.updBusy = true
		s.updMu.Unlock()
		usage, err := s.apply(src, units, from, to, kind)
		s.updMu.Lock()
		if kind == "update" {
			s.dropPending()
		}
		if err != nil {
			s.updBusy = false
			s.updMu.Unlock()
			s.tidyUpdDir()
			log.Printf("update: %s to %s failed: %v", kind, to, err)
			jsonErr(w, 500, "Not installed: "+err.Error())
			return true
		}
		s.updMu.Unlock() // stays busy until the restart replaces this process
		s.tidyUpdDir()
		log.Printf("update: %s %s → %s done", kind, from, to)
		writeJSON(w, map[string]any{"ok": true, "from": from, "to": to, "kind": kind, "restarting": true})
		if fl, ok := w.(http.Flusher); ok {
			fl.Flush()
		}
		if s.noRestart {
			s.updMu.Lock()
			s.updBusy = false
			s.updMu.Unlock()
			return true
		}
		go s.restart(usage, to)

	default:
		return false
	}
	return true
}

// receive: the upload into .update/upload-*.zip, then checkPackage into .update/staging-*
func (s *server) receive(w http.ResponseWriter, r *http.Request) (*updPending, error) {
	d := s.updDir()
	if err := os.MkdirAll(d, 0o755); err != nil {
		return nil, err
	}
	zf, err := os.CreateTemp(d, "upload-*.zip")
	if err != nil {
		return nil, err
	}
	defer func() { zf.Close(); os.Remove(zf.Name()) }()
	n, err := io.Copy(zf, http.MaxBytesReader(w, r.Body, updMaxUpload))
	if err != nil {
		var mbe *http.MaxBytesError
		if errors.As(err, &mbe) {
			return nil, refuse("That file is too big to be a KBB Studio update.")
		}
		return nil, fmt.Errorf("the file did not arrive completely (%v)", err)
	}
	if err := zf.Close(); err != nil {
		return nil, err
	}
	id := randHex(6)
	staging := filepath.Join(d, "staging-"+id)
	man, units, total, err := checkPackage(zf.Name(), staging, appVersionAt(s.root), false)
	if err != nil {
		_ = os.RemoveAll(staging)
		return nil, err
	}
	notes := []string{}
	for _, l := range man.Notes {
		if l = strings.TrimSpace(clip(l, 300)); l != "" && len(notes) < updMaxNotes {
			notes = append(notes, l)
		}
	}
	return &updPending{ID: id, From: appVersionAt(s.root), To: man.Version, Date: clip(man.Date, 20), Notes: notes, Size: n, Total: total, Files: len(man.Files), staging: staging, units: units}, nil
}

// tidyUpdDir: .update removed again when nothing is kept in it (a refused package leaves the app folder as it was)
func (s *server) tidyUpdDir() { _ = os.Remove(s.updDir()) }
