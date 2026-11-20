// update.go — installing a new KBB Ads Autopilot from a zip, from inside the app (Settings → Updates). Ported from
// KBB Studio's updater (studio/reel-studio/engine/update.go), copied rather than imported so the two apps never share
// code or a release. The owner, 20 Nov 2026: "build me the patch upload system in the auto pilot ads system too. must
// be super reliable." update_flow.go holds the moving parts (resumable upload, the install job, the health check
// and automatic roll back); this file holds the package checks, staging, the swap and the crash recovery.
//
// Two kinds of package, both made by the tools in tools/ and both carrying update.json beside the files:
//
//	full  (tools/package-mac.sh, "KBB Ads Autopilot v1.3.0.zip")  — format 1, every file of the version
//	{ "format": 1, "app": "KBB Ads Autopilot", "version": "1.3.0", "minVersion": "", "date": "…", "notes": […],
//	  "files": { "app/index.html": {"sha256": "…", "size": 1234}, … }, "signature": null }
//
//	patch (tools/package-patch.sh, "KBB Ads Autopilot patch 1.2.0→1.3.0.zip") — format 2, only what changed
//	{ "format": 2, "kind": "patch", "from": "1.2.0", "version": "1.3.0", …, "files": {only the changed / added files},
//	  "removed": ["app/js/old.js"], "result": {EVERY file of 1.3.0 with its sha256 and size} }
//
// A patch applies ONLY onto exactly its "from" version. It is staged by copying (hard-linking) each part of the app
// folder it touches, laying the changed files over it and deleting the removed ones; then every file of every staged
// part is compared with "result" — the staged folder is byte-for-byte the new version, or nothing happens. An engine
// from before patches (format 1 only) refuses a patch with "newer kind of updater", never half-reads it.
//
// What is checked, all of it before the app folder is touched (nothing inside the zip is ever run while checking):
//   - every name: no absolute path, no '..', no backslash, no hidden part (bar the __MACOSX / .DS_Store junk a Mac
//     adds, which is skipped), no two names that differ only in case (a Mac disk does not tell them apart)
//   - only regular files and folders: a symlink, device or encrypted entry refuses the package
//   - only the parts of the app folder an update owns (updUnit): app/, docs/, the two Mac engines, VERSION and plain
//     top-level files (README, the launcher). Never anything else -- and never the data folder, which is not inside
//     the app folder at all (main refuses to start otherwise)
//   - update.json present, for this app, a newer version than the running one (an older one only through Roll back),
//     minVersion satisfied, every file listed and every listed file there, each with its exact size and sha256
//     (counted while it is unpacked, never taken from the zip's own header)
//   - caps on the upload, the unpacked total and the number of files (a zip bomb stops at the cap); free disk space
//
// Signatures: there is no safe place for a private key yet, so the bar is integrity (every file's sha256) plus the
// owner choosing the file himself. "signature" is reserved in update.json; this engine ignores it.
//
// The swap: unit by unit, the current copy is renamed into .update/backup-* and the staged one into place (same
// disk, so every move is a rename). A journal written (and fsynced) first lists the units, so a failure midway is put
// back at once and a power cut midway is put back on the next start (recoverInterrupted). The backup becomes
// .update/previous (one level: "Roll back"). The data folder (~/KBB Ads Autopilot Data) and the Keychain are never
// touched by any of this.
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
	"io/fs"
	"log"
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
	updDirName   = ".update" // inside the app folder: staging, backup, previous, history, uploads
	updMaxUpload = 1 << 30   // the zip itself (today's package is ~15 MB)
	updMaxTotal  = 3 << 30   // all files unpacked
	updMaxFiles  = 40000     // entries in the zip
	updMaxNotes  = 40        // lines of release notes kept
	updManifest  = "update.json"
	updSpare     = 200 << 20 // free space kept on top of what an install writes
)

var (
	updVersionRe = regexp.MustCompile(`^\d{1,4}(\.\d{1,4}){1,3}$`)
	updTopFileRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 ._()-]{0,90}\.(md|html|command|txt)$`)
	updShaRe     = regexp.MustCompile(`^[0-9a-f]{64}$`)
	updEngines   = []string{"engine/kaa-mac-arm64", "engine/kaa-mac-amd64"}
	// test hooks (Go tests only): fail the swap at this unit index and undo; or stop dead there (a power cut)
	updFailAt  = -1
	updCrashAt = -1
)

type updFile struct {
	Sha256 string `json:"sha256"`
	Size   int64  `json:"size"`
}

type updManifestT struct {
	Format     int                `json:"format"`
	App        string             `json:"app"`
	Kind       string             `json:"kind,omitempty"` // "" / "full" | "patch"
	From       string             `json:"from,omitempty"` // patch: the exact version it applies to
	Version    string             `json:"version"`
	MinVersion string             `json:"minVersion,omitempty"`
	Date       string             `json:"date,omitempty"`
	Notes      []string           `json:"notes"`
	Files      map[string]updFile `json:"files"`
	Removed    []string           `json:"removed,omitempty"` // patch: files the new version no longer has
	Result     map[string]updFile `json:"result,omitempty"`  // patch: every file of the new version
	Signature  json.RawMessage    `json:"signature,omitempty"`
}

func (m *updManifestT) patch() bool { return m.Kind == "patch" }

// expected: every file the staged units must hold afterwards (a full package: its files; a patch: its result)
func (m *updManifestT) expected() map[string]updFile {
	if m.patch() {
		return m.Result
	}
	return m.Files
}

// updCheck: one line of "Checks passed" on the review card (the page words it, EN / AR)
type updCheck struct {
	ID string `json:"id"`
	N  int64  `json:"n,omitempty"`
	V  string `json:"v,omitempty"`
}

// a checked, staged package waiting for the owner's "Install"
type updPending struct {
	ID       string     `json:"id"`
	Kind     string     `json:"kind"` // full | patch
	Name     string     `json:"name,omitempty"`
	From     string     `json:"from"`
	To       string     `json:"to"`
	Date     string     `json:"date,omitempty"`
	Notes    []string   `json:"notes"`
	Size     int64      `json:"size"`  // the zip
	Total    int64      `json:"total"` // unpacked
	Files    int        `json:"files"`
	Added    int        `json:"added"`
	Changed  int        `json:"changed"`
	Removed  int        `json:"removed"`
	Writes   int64      `json:"writes"` // bytes of added + changed files
	Engine   bool       `json:"engine"` // the engine itself changes
	Checks   []updCheck `json:"checks"`
	staging  string
	units    []string
	expected map[string]updFile
}

type updHistory struct {
	At     string `json:"at"`
	Kind   string `json:"kind"` // update | patch | rollback | auto-rollback
	From   string `json:"from"`
	To     string `json:"to"`
	Reason string `json:"reason,omitempty"`
}

// backup/info.json: which version the backup holds and which units the swap moved
type updInfo struct {
	Version string   `json:"version"`
	At      string   `json:"at"`
	Units   []string `json:"units"`
}

// backup/journal.json: written (and fsynced) before the first move
type updJournal struct {
	Units []string `json:"units"`
	Src   string   `json:"src"`
	From  string   `json:"from,omitempty"`
	To    string   `json:"to,omitempty"`
}

type updRefusal struct{ msg, code string }

func (e updRefusal) Error() string         { return e.msg }
func refuse(format string, a ...any) error { return updRefusal{msg: fmt.Sprintf(format, a...)} }

// refuseC: a refusal the page can word in Arabic too (code), English text as the engine says it
func refuseC(code, format string, a ...any) error {
	return updRefusal{msg: fmt.Sprintf(format, a...), code: code}
}

func refusalCode(err error) string {
	var r updRefusal
	if errors.As(err, &r) {
		return r.code
	}
	return ""
}

func (s *server) updDir() string { return filepath.Join(s.root, updDirName) }

// appVersionAt: the version of the app folder as it is now (its VERSION file)
func appVersionAt(root string) string {
	b, err := os.ReadFile(filepath.Join(root, "VERSION"))
	if err != nil {
		return ""
	}
	v := strings.TrimSpace(string(b))
	if !updVersionRe.MatchString(v) {
		return ""
	}
	return v
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
		return "", refuseC("outside", "A file in the package has a name that is not allowed (%q)", clip(name, 80))
	}
	for _, r := range name {
		if r < 0x20 || r == 0x7f {
			return "", refuseC("outside", "A file in the package has a name that is not allowed (%q)", clip(name, 80))
		}
	}
	segs := strings.Split(strings.TrimSuffix(name, "/"), "/")
	for _, seg := range segs {
		if seg == "" || seg == "." || seg == ".." {
			return "", refuseC("outside", "A file in the package points outside its folder (%q)", clip(name, 80))
		}
	}
	return name, nil
}

// updJunk: what a Mac adds when it re-zips or browses a folder; skipped, never installed
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
		if rel == updManifest || rel == "VERSION" || updTopFileRe.MatchString(rel) {
			return rel
		}
		return ""
	}
	switch segs[0] {
	case "app", "docs":
		return segs[0]
	case "engine":
		for _, e := range updEngines {
			if rel == e {
				return rel
			}
		}
	}
	return ""
}

// updExecutable: the files that keep the executable bit (by path, never by what the zip says)
func updExecutable(rel string) bool {
	return strings.HasPrefix(rel, "engine/kaa-mac-") || !strings.Contains(rel, "/") && strings.HasSuffix(rel, ".command")
}

// updValidUnit: a unit name read back from our own info.json / journal is still one an update owns
func updValidUnit(u string) bool {
	if _, err := updCleanName(u); err != nil {
		return false
	}
	return updUnit(u) == u || updUnit(u+"/x") == u
}

// updChecked: what checkPackage found and staged
type updChecked struct {
	man      *updManifestT
	manBytes []byte
	units    []string
	total    int64 // the package's files, unpacked
}

// checkPackage reads the zip at zpath, checks everything, and stages the result into staging (which it creates):
// a full package is unpacked there; a patch is laid over a copy of the parts of root it touches. current is the
// version installed (root's VERSION; "" in tests that do not care). On any refusal staging is removed and the error
// says why in plain words.
func checkPackage(zpath, staging, root, current string, rollbackOK bool) (*updChecked, error) {
	zr, err := zip.OpenReader(zpath)
	if err != nil {
		return nil, refuseC("notzip", "This is not a zip file, or it is damaged. Use the “KBB Ads Autopilot v….zip” file exactly as you received it.")
	}
	defer zr.Close()
	if len(zr.File) > updMaxFiles {
		return nil, refuse("The package has too many files (%d).", len(zr.File))
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
			return nil, err
		}
		if f.Flags&0x1 != 0 {
			return nil, refuse("The package is password-protected; an update never is.")
		}
		m := f.Mode()
		if m&os.ModeSymlink != 0 {
			return nil, refuseC("outside", "The package contains a link (%q); an update never does.", clip(name, 80))
		}
		if m&(os.ModeDevice|os.ModeNamedPipe|os.ModeSocket|os.ModeCharDevice|os.ModeIrregular) != 0 {
			return nil, refuse("The package contains something that is not a file (%q).", clip(name, 80))
		}
		if updJunk(name) {
			continue
		}
		key := strings.ToLower(strings.TrimSuffix(name, "/"))
		if prev, dup := lower[key]; dup {
			return nil, refuse("The package has the same file twice (%q and %q).", clip(prev, 60), clip(name, 60))
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
		return nil, refuseC("notupdate", "This zip is not a KBB Ads Autopilot update: it has no update information inside.")
	}
	files := map[string]*zip.File{}
	var manFile *zip.File
	for _, e := range ents {
		rel := strings.TrimPrefix(e.rel, prefix)
		if prefix != "" && !strings.HasPrefix(e.rel, prefix) {
			if strings.TrimSuffix(e.rel, "/") == top { // the top folder's own entry
				continue
			}
			return nil, refuseC("outside", "The package has files outside its KBB Ads Autopilot folder (%q).", clip(e.rel, 80))
		}
		if rel == "" || strings.HasSuffix(rel, "/") || e.f.Mode().IsDir() {
			continue // a folder: made as needed
		}
		if rel == updManifest {
			manFile = e.f
			continue
		}
		if updUnit(rel) == "" {
			return nil, refuseC("outside", "The package wants to write %q, which an update may not touch.", clip(rel, 80))
		}
		files[rel] = e.f
	}
	// 2. the manifest
	if manFile == nil {
		return nil, refuseC("notupdate", "This zip is not a KBB Ads Autopilot update: it has no update information inside.")
	}
	rc, err := manFile.Open()
	if err != nil {
		return nil, refuseC("damaged", "The update information inside the package is damaged.")
	}
	manBytes, err := io.ReadAll(io.LimitReader(rc, 16<<20+1))
	rc.Close()
	if err != nil || len(manBytes) > 16<<20 {
		return nil, refuseC("damaged", "The update information inside the package is damaged.")
	}
	var man updManifestT
	if err := json.Unmarshal(manBytes, &man); err != nil {
		return nil, refuseC("damaged", "The update information inside the package is damaged.")
	}
	switch {
	case man.Format == 1 && (man.Kind == "" || man.Kind == "full"):
	case man.Format == 2 && man.Kind == "patch":
	case man.Format > 2:
		return nil, refuse("This package is for a newer kind of updater (format %d). Install the full “KBB Ads Autopilot v….zip” by replacing the folder, once.", man.Format)
	default:
		return nil, refuseC("damaged", "The update information inside the package is damaged.")
	}
	if man.App != appName {
		return nil, refuse("This package is not for %s.", appName)
	}
	if !updVersionRe.MatchString(man.Version) {
		return nil, refuseC("damaged", "The package does not say which version it is.")
	}
	if man.patch() {
		if !updVersionRe.MatchString(man.From) || cmpVersion(man.Version, man.From) <= 0 {
			return nil, refuseC("damaged", "The update information inside the package is damaged.")
		}
		if root == "" || current != man.From {
			return nil, refuseC("wrongfrom", "This patch updates version %s only, and you have %s. Use the full “KBB Ads Autopilot v%s.zip” instead.", man.From, orUnknown(current), man.Version)
		}
	}
	if current != "" && !rollbackOK {
		switch c := cmpVersion(man.Version, current); {
		case c == 0:
			return nil, refuseC("same", "Version %s is already installed.", man.Version)
		case c < 0:
			return nil, refuseC("older", "This package is version %s, older than the one you have (%s). To go back, use “Roll back”.", man.Version, current)
		}
	}
	if man.MinVersion != "" {
		if !updVersionRe.MatchString(man.MinVersion) {
			return nil, refuseC("damaged", "The update information inside the package is damaged.")
		}
		if current != "" && cmpVersion(current, man.MinVersion) < 0 {
			return nil, refuse("Version %s needs version %s or later first. Install it by replacing the folder, once.", man.Version, man.MinVersion)
		}
	}
	if len(man.Files) > updMaxFiles || (len(man.Files) == 0 && !man.patch()) {
		return nil, refuseC("damaged", "The update information inside the package is damaged.")
	}
	checkList := func(m map[string]updFile) (int64, error) {
		var t int64
		for rel, f := range m {
			if _, err := updCleanName(rel); err != nil || strings.HasSuffix(rel, "/") || rel == updManifest || updUnit(rel) == "" {
				return 0, refuseC("outside", "The package lists %q, which an update may not touch.", clip(rel, 80))
			}
			if !updShaRe.MatchString(f.Sha256) || f.Size < 0 || f.Size > updMaxTotal {
				return 0, refuseC("damaged", "The update information inside the package is damaged (%q).", clip(rel, 80))
			}
			t += f.Size
		}
		return t, nil
	}
	total, err := checkList(man.Files)
	if err != nil {
		return nil, err
	}
	for rel := range man.Files {
		if _, ok := files[rel]; !ok {
			return nil, refuseC("incomplete", "The package is incomplete: %q is missing. Download it again.", clip(rel, 80))
		}
	}
	if total > updMaxTotal {
		return nil, refuse("The package is too big to be a KBB Ads Autopilot update.")
	}
	for rel := range files {
		if _, ok := man.Files[rel]; !ok {
			return nil, refuseC("tampered", "The package has a file it does not list (%q): it may have been changed.", clip(rel, 80))
		}
	}
	// what the version must hold, whole: both engines and the app (a full package carries them; a patch lists them)
	must := man.expected()
	if man.patch() {
		if len(man.Result) == 0 || len(man.Result) > updMaxFiles {
			return nil, refuseC("damaged", "The update information inside the package is damaged.")
		}
		rt, err := checkList(man.Result)
		if err != nil {
			return nil, err
		}
		if rt > updMaxTotal {
			return nil, refuse("The package is too big to be a KBB Ads Autopilot update.")
		}
		for rel, f := range man.Files {
			if man.Result[rel] != f {
				return nil, refuseC("damaged", "The update information inside the package is damaged (%q).", clip(rel, 80))
			}
		}
		if len(man.Removed) > updMaxFiles {
			return nil, refuseC("damaged", "The update information inside the package is damaged.")
		}
		for _, rel := range man.Removed {
			if _, err := updCleanName(rel); err != nil || strings.HasSuffix(rel, "/") || rel == updManifest || rel == "VERSION" || updUnit(rel) == "" {
				return nil, refuseC("outside", "The patch wants to remove %q, which an update may not touch.", clip(rel, 80))
			}
			if _, keep := man.Result[rel]; keep {
				return nil, refuseC("damaged", "The update information inside the package is damaged (%q).", clip(rel, 80))
			}
		}
		if f, ok := man.Result["VERSION"]; !ok || man.Files["VERSION"] != f {
			return nil, refuseC("damaged", "The patch does not carry its version.")
		}
	}
	for _, e := range updEngines {
		if _, ok := must[e]; !ok {
			return nil, refuseC("incomplete", "The package is missing the engine for %s Macs.", map[bool]string{true: "Apple-silicon", false: "Intel"}[strings.HasSuffix(e, "arm64")])
		}
	}
	if _, ok := must["app/index.html"]; !ok {
		return nil, refuseC("incomplete", "The package is incomplete: the app itself is missing.")
	}
	need := total + updSpare
	if man.patch() {
		need = 0
		for rel, f := range man.Result { // conservative: as if nothing could be hard-linked
			if unitIn(updUnit(rel), &man) {
				need += f.Size
			}
		}
		need += updSpare
	}
	if fb := freeBytes(filepath.Dir(staging)); fb >= 0 && fb < need {
		return nil, refuseC("space", "Not enough free space on this Mac to install it (needs %s).", human(need))
	}
	// 3. stage, counting and hashing every byte as it is written
	if err := os.MkdirAll(staging, 0o755); err != nil {
		return nil, err
	}
	fail := func(err error) (*updChecked, error) {
		_ = os.RemoveAll(staging)
		return nil, err
	}
	units := map[string]bool{updManifest: true}
	for rel := range man.Files {
		units[updUnit(rel)] = true
	}
	for _, rel := range man.Removed {
		units[updUnit(rel)] = true
	}
	ul := make([]string, 0, len(units))
	for u := range units {
		ul = append(ul, u)
	}
	sort.Strings(ul)
	if man.patch() { // the parts it touches, as they are now (hard links: nothing is copied, nothing is written in place)
		for _, u := range ul {
			if _, carried := man.Files[u]; carried || u == updManifest {
				continue // a single file the patch brings whole
			}
			src := filepath.Join(root, filepath.FromSlash(u))
			if st, err := os.Lstat(src); err == nil {
				if err := linkTree(src, filepath.Join(staging, filepath.FromSlash(u)), st); err != nil {
					return fail(err)
				}
			}
		}
	}
	rels := make([]string, 0, len(files))
	for rel := range files {
		rels = append(rels, rel)
	}
	sort.Strings(rels)
	for _, rel := range rels {
		dest := filepath.Join(staging, filepath.FromSlash(rel))
		_ = os.Remove(dest) // a patch: the linked old copy goes (the link only, never the installed file)
		if err := unpackOne(files[rel], dest, man.Files[rel], updExecutable(rel)); err != nil {
			if _, ok := err.(updRefusal); ok {
				return fail(refuseC("tampered", "This package was changed or damaged: %s (%s). Download it again.", err.Error(), clip(rel, 80)))
			}
			return fail(err)
		}
	}
	for _, rel := range man.Removed {
		p := filepath.Join(staging, filepath.FromSlash(rel))
		_ = os.Remove(p)
		for d := filepath.Dir(p); len(d) > len(staging); d = filepath.Dir(d) {
			if os.Remove(d) != nil { // only an empty folder goes
				break
			}
		}
	}
	// 4. the staged parts are exactly the new version: every file there, nothing else, every byte as listed
	if err := verifyStaged(staging, ul, must); err != nil {
		if man.patch() {
			if r, ok := err.(updRefusal); ok {
				return fail(refuseC("wrongfrom", "Your copy of version %s is not exactly as it was installed (%s), so this patch cannot be laid over it. Use the full “KBB Ads Autopilot v%s.zip” instead.", man.From, r.msg, man.Version))
			}
		}
		return fail(err)
	}
	if err := os.WriteFile(filepath.Join(staging, updManifest), manBytes, 0o644); err != nil {
		return fail(err)
	}
	return &updChecked{man: &man, manBytes: manBytes, units: ul, total: total}, nil
}

func orUnknown(v string) string {
	if v == "" {
		return "an unknown version"
	}
	return v
}

// unitIn: the patch touches this unit
func unitIn(u string, man *updManifestT) bool {
	for rel := range man.Files {
		if updUnit(rel) == u {
			return true
		}
	}
	for _, rel := range man.Removed {
		if updUnit(rel) == u {
			return true
		}
	}
	return false
}

// verifyStaged: every regular file under dir/<unit> (units bar update.json) is in want with its exact size and sha256,
// and every file of want that belongs to one of those units is there. Junk a Mac adds (.DS_Store) is removed.
func verifyStaged(dir string, units []string, want map[string]updFile) error {
	in := map[string]bool{}
	for _, u := range units {
		if u != updManifest {
			in[u] = true
		}
	}
	seen := map[string]bool{}
	for u := range in {
		base := filepath.Join(dir, filepath.FromSlash(u))
		if _, err := os.Lstat(base); errors.Is(err, fs.ErrNotExist) {
			continue
		}
		err := filepath.WalkDir(base, func(p string, d fs.DirEntry, err error) error {
			if err != nil {
				return err
			}
			rel, _ := filepath.Rel(dir, p)
			rel = filepath.ToSlash(rel)
			if d.IsDir() {
				return nil
			}
			if updJunk(rel) {
				return os.Remove(p)
			}
			if !d.Type().IsRegular() {
				return refuse("%q is not a plain file", clip(rel, 80))
			}
			f, ok := want[rel]
			if !ok {
				return refuse("%q is not part of the new version", clip(rel, 80))
			}
			if err := hashMatches(p, f); err != nil {
				return refuse("%q %s", clip(rel, 80), err.Error())
			}
			seen[rel] = true
			return nil
		})
		if err != nil {
			return err
		}
	}
	for rel := range want {
		if in[updUnit(rel)] && !seen[rel] {
			return refuse("%q is missing", clip(rel, 80))
		}
	}
	return nil
}

func hashMatches(p string, want updFile) error {
	f, err := os.Open(p)
	if err != nil {
		return refuse("cannot be read")
	}
	defer f.Close()
	h := sha256.New()
	n, err := io.Copy(h, io.LimitReader(f, want.Size+1))
	if err != nil {
		return refuse("cannot be read")
	}
	if n != want.Size || hex.EncodeToString(h.Sum(nil)) != want.Sha256 {
		return refuse("does not match its fingerprint")
	}
	return nil
}

// linkTree: dst becomes a copy of src made of hard links (a plain copy where a link is not possible). A link inside
// the installed app folder refuses: an update never made one.
func linkTree(src, dst string, st fs.FileInfo) error {
	if st.Mode().IsRegular() {
		if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
			return err
		}
		return linkOrCopy(src, dst, st.Mode().Perm())
	}
	return filepath.WalkDir(src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(src, p)
		t := filepath.Join(dst, rel)
		switch {
		case d.IsDir():
			return os.MkdirAll(t, 0o755)
		case d.Type().IsRegular():
			fi, err := d.Info()
			if err != nil {
				return err
			}
			return linkOrCopy(p, t, fi.Mode().Perm())
		default:
			return refuseC("wrongfrom", "Your app folder has something that is not a plain file (%q).", clip(rel, 80))
		}
	})
}

func linkOrCopy(src, dst string, perm os.FileMode) error {
	if os.Link(src, dst) == nil {
		return nil
	}
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(dst, os.O_WRONLY|os.O_CREATE|os.O_EXCL, perm)
	if err != nil {
		return err
	}
	_, err = io.Copy(out, in)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	return err
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
	if err == nil {
		err = out.Sync()
	}
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

// updDiff: what the package changes in root, for the review card. Hashes only files whose size is the same.
func updDiff(root string, c *updChecked) (added, changed, removed int, writes int64, engine bool) {
	man := c.man
	for rel, f := range man.Files {
		st, err := os.Stat(filepath.Join(root, filepath.FromSlash(rel)))
		switch {
		case err != nil:
			added++
			writes += f.Size
		case st.Size() != f.Size || hashMatches(filepath.Join(root, filepath.FromSlash(rel)), f) != nil:
			changed++
			writes += f.Size
			if strings.HasPrefix(rel, "engine/") {
				engine = true
			}
		}
	}
	if man.patch() {
		for _, rel := range man.Removed {
			if _, err := os.Lstat(filepath.Join(root, filepath.FromSlash(rel))); err == nil {
				removed++
			}
		}
		return
	}
	for _, u := range c.units {
		if u == updManifest {
			continue
		}
		_ = filepath.WalkDir(filepath.Join(root, filepath.FromSlash(u)), func(p string, d fs.DirEntry, err error) error {
			if err != nil || d.IsDir() {
				return nil
			}
			rel, _ := filepath.Rel(root, p)
			rel = filepath.ToSlash(rel)
			if _, ok := man.Files[rel]; !ok && !updJunk(rel) {
				removed++
			}
			return nil
		})
	}
	return
}

// ── the swap ──

type updMove struct{ from, to string }

// swapUnits: for each unit, root/<u> → backup/<u> (when it exists) and src/<u> → root/<u> (when it exists). A journal
// in backup lists the units first, on disk before the first move. On any failure every move made is undone, last
// first.
func swapUnits(root, src, backup string, units []string, from, to string) error {
	if err := os.MkdirAll(backup, 0o755); err != nil {
		return err
	}
	j, _ := json.Marshal(updJournal{Units: units, Src: filepath.Base(src), From: from, To: to})
	if err := writeFileSync(filepath.Join(backup, "journal.json"), j); err != nil {
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
		if i == updCrashAt {
			return errUpdCrash // the test's power cut: nothing is undone, the journal stays
		}
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
	syncDir(root)
	syncDir(backup)
	return nil
}

var errUpdCrash = errors.New("test: power cut")

func undoMoves(done []updMove) error {
	var first error
	for i := len(done) - 1; i >= 0; i-- {
		if err := os.Rename(done[i].to, done[i].from); err != nil && first == nil {
			first = err
		}
	}
	return first
}

// unswap: undo a swapUnits that finished, or stopped anywhere in the middle (a power cut). Per unit:
//   - the old copy is in backup: the swap reached it. Whatever is in its place now is the new one (back to src),
//     and the old one returns.
//   - no old copy, the new one no longer in src but in place: the unit was ADDED by the swap; it goes back to src.
//   - otherwise the swap never reached this unit: it is left exactly as it is.
func unswap(root, src, backup string, units []string) error {
	var first error
	keep := func(err error) {
		if err != nil && first == nil {
			first = err
		}
	}
	for i := len(units) - 1; i >= 0; i-- {
		u := filepath.FromSlash(units[i])
		cur, nw, bk := filepath.Join(root, u), filepath.Join(src, u), filepath.Join(backup, u)
		_, bkErr := os.Lstat(bk)
		_, nwErr := os.Lstat(nw)
		_, curErr := os.Lstat(cur)
		switch {
		case bkErr == nil:
			if curErr == nil {
				_ = os.MkdirAll(filepath.Dir(nw), 0o755)
				keep(os.Rename(cur, nw))
			}
			_ = os.MkdirAll(filepath.Dir(cur), 0o755)
			keep(os.Rename(bk, cur))
		case nwErr != nil && curErr == nil:
			_ = os.MkdirAll(filepath.Dir(nw), 0o755)
			keep(os.Rename(cur, nw))
		}
	}
	syncDir(root)
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

// recoverInterrupted: runs at every start, before anything is served.
//   - a swap that never finished (journal still there: the Mac lost power midway) is put back exactly;
//   - a swap that finished but whose backup was not yet kept as "previous" becomes "previous";
//   - a health check waiting for a version that is no longer in place is dropped;
//   - stale staging / trash / undo folders go, and uploads older than a day (younger ones can be resumed).
func (s *server) recoverInterrupted() {
	d := s.updDir()
	ents, err := os.ReadDir(d)
	if err != nil {
		return
	}
	for _, e := range ents { // sorted: backup-* comes before staging-*, so an undone swap's files return first
		p := filepath.Join(d, e.Name())
		switch {
		case strings.HasPrefix(e.Name(), "backup-"):
			var j updJournal
			if b, err := os.ReadFile(filepath.Join(p, "journal.json")); err == nil {
				if json.Unmarshal(b, &j) == nil && (strings.HasPrefix(j.Src, "staging-") || j.Src == "previous") && j.Src == filepath.Base(j.Src) {
					ok := true
					for _, u := range j.Units {
						ok = ok && updValidUnit(u)
					}
					if ok {
						if err := unswap(s.root, filepath.Join(d, j.Src), p, j.Units); err != nil {
							log.Printf("update: an interrupted update could not be fully put back: %v", err)
							continue // keep it for a person to look at
						}
						now := appVersionAt(s.root)
						log.Printf("update: an update that was interrupted has been undone; you are on %s", now)
						s.writeLast(updLast{Kind: "interrupted", From: j.From, To: j.To, OK: false, Reason: fmt.Sprintf("The Mac stopped while version %s was being installed. Nothing was lost: version %s was put back exactly as it was.", orUnknown(j.To), orUnknown(now))})
					}
				}
				_ = os.RemoveAll(p)
				continue
			}
			if _, err := os.Stat(filepath.Join(p, "info.json")); err == nil { // finished; only "keep as previous" was cut
				prev := filepath.Join(d, "previous")
				_ = os.RemoveAll(prev)
				if os.Rename(p, prev) == nil {
					continue
				}
			}
			_ = os.RemoveAll(p)
		case strings.HasPrefix(e.Name(), "upload-"):
			if fi, err := e.Info(); err == nil && time.Since(fi.ModTime()) < 24*time.Hour {
				continue
			}
			_ = os.RemoveAll(p)
		case strings.HasPrefix(e.Name(), "staging-"), strings.HasPrefix(e.Name(), "trash-"), strings.HasPrefix(e.Name(), "undo-"), strings.HasPrefix(e.Name(), ".part-"):
			_ = os.RemoveAll(p)
		}
	}
	if m := s.readMarker(); m != nil && appVersionAt(s.root) != m.To {
		_ = os.Remove(filepath.Join(d, updMarkerName))
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

func (s *server) addHistory(kind, from, to, reason string) {
	h := append([]updHistory{{At: time.Now().Format(time.RFC3339), Kind: kind, From: from, To: to, Reason: reason}}, s.readHistory()...)
	if len(h) > 30 {
		h = h[:30]
	}
	b, _ := json.MarshalIndent(h, "", " ")
	_ = writeFileSync(filepath.Join(s.updDir(), "history.json"), b)
}

// apply: swap src into the app folder, test the engine, leave the health-check marker for the engine that starts
// next, keep the backup as "previous", record it. kind = update | patch | rollback | auto-rollback. Returns the
// usage text of the engine that will be exec'd.
func (s *server) apply(src string, units []string, from, to, kind string) (string, error) {
	d := s.updDir()
	backup := filepath.Join(d, "backup-"+randHex(4))
	if err := swapUnits(s.root, src, backup, units, from, to); err != nil {
		if err == errUpdCrash {
			return "", err
		}
		_ = os.RemoveAll(backup)
		return "", fmt.Errorf("could not replace the files (%v); nothing was changed", err)
	}
	usage, err := selfTest(s.exe)
	if err == nil {
		if _, e := os.Stat(filepath.Join(s.root, "app", "index.html")); e != nil {
			err = errors.New("the app is missing after the swap")
		} else if v := appVersionAt(s.root); v != to {
			err = fmt.Errorf("the app folder says version %q after the swap, not %s", v, to)
		}
	}
	if err != nil {
		if uerr := unswap(s.root, src, backup, units); uerr != nil {
			return "", fmt.Errorf("%v, and putting the old version back failed (%v): close KBB Ads Autopilot and start it again", err, uerr)
		}
		_ = os.RemoveAll(backup)
		return "", fmt.Errorf("%v; nothing was changed", err)
	}
	touchUnits(s.root, units)
	// from here the new version is in place: the next engine to start checks its health (and, for an update, rolls
	// back by itself when that fails). Written before the journal goes, so a power cut in between is still undone.
	_ = s.writeMarker(updMarker{Kind: kind, From: from, To: to, At: time.Now().Format(time.RFC3339), Auto: kind == "update" || kind == "patch"})
	info, _ := json.MarshalIndent(updInfo{Version: from, At: time.Now().Format(time.RFC3339), Units: units}, "", " ")
	_ = writeFileSync(filepath.Join(backup, "info.json"), info)
	_ = writeFileSync(filepath.Join(backup, "units.txt"), []byte(strings.Join(units, "\n")+"\n")) // the launcher's way back
	_ = os.Remove(filepath.Join(backup, "journal.json"))
	syncDir(backup)
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
	syncDir(d)
	reason := ""
	if kind == "auto-rollback" {
		if l := s.readLast(); l != nil {
			reason = l.Reason
		}
	}
	s.addHistory(kind, from, to, reason)
	return usage, nil
}

// ── small file helpers ──

// writeFileSync: the file is complete on disk (fsynced, renamed into place, folder synced) or not there at all
func writeFileSync(abs string, b []byte) error {
	if err := os.MkdirAll(filepath.Dir(abs), 0o755); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(abs), ".part-*")
	if err != nil {
		return err
	}
	_, err = tmp.Write(b)
	if err == nil {
		err = tmp.Sync()
	}
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(tmp.Name())
		return err
	}
	if err := os.Rename(tmp.Name(), abs); err != nil {
		os.Remove(tmp.Name())
		return err
	}
	syncDir(filepath.Dir(abs))
	return nil
}

// syncDir: renames inside dir are on disk (best effort: not every file system allows it)
func syncDir(dir string) {
	if f, err := os.Open(dir); err == nil {
		_ = f.Sync()
		f.Close()
	}
}

func writeAtomic(abs string, body io.Reader) error {
	b, err := io.ReadAll(body)
	if err != nil {
		return err
	}
	return writeFileSync(abs, b)
}

func human(n int64) string {
	switch {
	case n >= 1e9:
		return fmt.Sprintf("%.1f GB", float64(n)/1e9)
	case n >= 1e6:
		return fmt.Sprintf("%.0f MB", float64(n)/1e6)
	default:
		return fmt.Sprintf("%d KB", max(1, n/1000))
	}
}

func clip(s string, n int) string {
	s = strings.TrimSpace(strings.ReplaceAll(s, "\x00", ""))
	if len([]rune(s)) > n {
		return string([]rune(s)[:n])
	}
	return s
}

func freeBytes(dir string) int64 {
	var st syscall.Statfs_t
	if err := syscall.Statfs(dir, &st); err != nil {
		return -1
	}
	return int64(st.Bavail) * int64(st.Bsize)
}

// separateData: the data folder is always outside the app folder here (main refuses anything else).
func (s *server) separateData() bool { return true }
