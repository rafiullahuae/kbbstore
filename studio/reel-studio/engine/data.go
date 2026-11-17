// KBB Studio engine · the owner's data lives OUTSIDE the app folder (17 Nov 2026).
//
// The owner: "whenever i replaced the files, it asked me again these all the download, why is that? why the system is
// not saving data". Every update is a new zip that replaces the KBB Studio folder, and until now that folder also held
// everything he owns: 6 GB of models, his companies and projects, his keys, his music and photos, his brand kits.
//
//   - -data <folder> (the start script passes ~/KBB Studio Data) holds the owner's roots: models/ (not the manifest,
//     which ships with the app), workspaces/, settings/ (0700, keys 0600), brands/ and library/ (his own files).
//     app/, vendor/, models/manifest.json and the BUILT-IN library and brand kits stay in the app folder.
//   - library/ and brands/ are an overlay: a read looks in the data folder first, then in the app folder; a listing
//     merges both (the data folder's entry wins on a name clash); writes and deletes go to the data folder only.
//   - On start, whatever the data folder lacks is MOVED there from the app folder (a rename on the same volume; across
//     volumes a copy that is verified before the original is removed). Nothing that already exists in the data folder
//     is ever overwritten. Model files are moved by the manifest only, and only when they have the manifest's size
//     (and, when copied, its sha256 / git blob sha1), so a half-copied file is never counted as installed.
//   - When models are still missing after that, older KBB Studio folders are looked for (one level deep: beside the app
//     folder, ~/Downloads, ~/Desktop, ~/Documents, ~/Applications, ~/.Trash) and their models are brought over the same
//     way. A KBB Studio folder is one with engine/kbbstudio-mac-* and models/manifest.json.
//   - Everything moved is logged to the Terminal window and to <data>/migration.log.
//   - Without -data (the test harnesses) the data folder IS the app folder, exactly as before.
package main

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"
)

// the brand kits that ship with the app; any other entry in an app folder's brands/ is the owner's own kit
// (TestBuiltinBrandsListed fails when a kit is added to brands/ without being named here)
var builtinBrands = map[string]bool{"kbb": true, "planet-careers": true, "kit.schema.json": true}

// the parts of library/ the app writes (the owner's music, his saved stock photos); the rest is built-in
var userLibrary = []string{"library/music/user", "library/people"}

func (s *server) dataDir() string {
	if s.data == "" {
		return s.root
	}
	return s.data
}

func (s *server) separateData() bool { return s.data != "" && s.data != s.root }

// bases: where the files of a (validated) relative path live, in lookup order
func (s *server) bases(rel string) []string {
	seg0, _, _ := strings.Cut(rel, "/")
	switch seg0 {
	case "app", "vendor":
		return []string{s.root}
	case "models":
		if rel == "models/manifest.json" {
			return []string{s.root}
		}
		return []string{s.dataDir()}
	case "library", "brands":
		if s.separateData() {
			return []string{s.data, s.root}
		}
		return []string{s.root}
	default: // workspaces
		return []string{s.dataDir()}
	}
}

// moved logs one line to the Terminal and to <data>/migration.log (lg is nil when that file cannot be opened)
func (s *server) moved(lg io.Writer, format string, a ...any) {
	msg := fmt.Sprintf(format, a...)
	log.Print("data: " + msg)
	if lg != nil {
		fmt.Fprintf(lg, "%s %s\n", time.Now().Format("2006-01-02 15:04:05"), msg)
	}
}

// setupData creates the data folder and moves into it what it lacks. Called once at start, before serving.
func (s *server) setupData(home string) {
	for _, d := range []string{"workspaces", "models", "library", "brands"} {
		_ = os.MkdirAll(filepath.Join(s.dataDir(), d), 0o755)
	}
	_ = os.MkdirAll(filepath.Join(s.dataDir(), "settings"), 0o700)
	defer s.privateSettings()
	if !s.separateData() {
		return
	}
	f, err := os.OpenFile(filepath.Join(s.data, "migration.log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	var lg io.Writer
	if err == nil {
		defer f.Close()
		lg = f
	}
	// 1. the owner's things still inside this app folder (the first run after this update, or a new zip unpacked over
	//    an old folder)
	for _, rel := range append([]string{"workspaces", "settings"}, userLibrary...) {
		s.moveTree(lg, filepath.Join(s.root, filepath.FromSlash(rel)), filepath.Join(s.data, filepath.FromSlash(rel)), rel)
	}
	if ents, err := os.ReadDir(filepath.Join(s.root, "brands")); err == nil {
		for _, e := range ents {
			if !builtinBrands[e.Name()] && !strings.HasPrefix(e.Name(), ".") {
				s.moveTree(lg, filepath.Join(s.root, "brands", e.Name()), filepath.Join(s.data, "brands", e.Name()), "brands/"+e.Name())
			}
		}
	}
	s.importModels(lg, s.root, "this folder")
	// 2. models still missing: older KBB Studio folders the owner may still have. Looked for whenever a NEEDED model is
	//    missing, and once (then remembered in .looked-for-old-folders) for the optional ones he may never want, so
	//    an ordinary start does not read Downloads / Desktop / Documents (macOS asks before a Terminal app may)
	marker := filepath.Join(s.data, ".looked-for-old-folders")
	_, seen := os.Stat(marker)
	if s.modelsMissing(true) || seen != nil && s.modelsMissing(false) {
		for _, old := range s.oldStudios(home) {
			s.importModels(lg, old, old)
			if !s.modelsMissing(false) {
				break
			}
		}
		_ = os.WriteFile(marker, []byte(time.Now().Format(time.RFC3339)+"\n"), 0o644)
	}
}

// privateSettings: settings/ 0700 and every file in it 0600 (keys moved from an old folder keep no wider mode)
func (s *server) privateSettings() {
	d := filepath.Join(s.dataDir(), "settings")
	_ = os.Chmod(d, 0o700)
	ents, _ := os.ReadDir(d)
	for _, e := range ents {
		if e.Type().IsRegular() {
			_ = os.Chmod(filepath.Join(d, e.Name()), 0o600)
		}
	}
}

// moveTree moves src to dst without ever overwriting: a missing dst is a rename (or a verified copy across volumes);
// two folders are merged entry by entry; a file that exists on both sides stays where it is in the data folder.
// Symlinks and hidden files (.DS_Store, .part-* temporaries) are left behind.
func (s *server) moveTree(lg io.Writer, src, dst, label string) {
	st, err := os.Lstat(src)
	if err != nil || st.Mode()&fs.ModeSymlink != 0 {
		return
	}
	dt, err := os.Lstat(dst)
	if errors.Is(err, fs.ErrNotExist) {
		if st.IsDir() {
			if n, _ := dirSize(src); n == 0 {
				return // an empty folder the old engine made: nothing to bring
			}
		}
		if err := moveOrCopy(src, dst); err != nil {
			s.moved(lg, "could not move %s: %v (left where it is)", label, err)
			return
		}
		s.moved(lg, "moved %s from %s to %s", label, filepath.Dir(src), filepath.Dir(dst))
		return
	}
	if err != nil || !st.IsDir() || !dt.IsDir() {
		return // the data folder has its own (newer) copy: never overwritten
	}
	ents, _ := os.ReadDir(src)
	for _, e := range ents {
		if strings.HasPrefix(e.Name(), ".") {
			continue
		}
		s.moveTree(lg, filepath.Join(src, e.Name()), filepath.Join(dst, e.Name()), label+"/"+e.Name())
	}
}

// moveOrCopy: rename when both are on the same volume; otherwise copy to <dst>.moving, check every file's size, rename
// into place and only then remove the original
func moveOrCopy(src, dst string) error {
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return err
	}
	err := os.Rename(src, dst)
	if err == nil {
		return nil
	}
	var le *os.LinkError
	if !errors.As(err, &le) || !errors.Is(le.Err, syscall.EXDEV) {
		return err
	}
	tmp := dst + ".moving"
	_ = os.RemoveAll(tmp)
	if err := copyTree(src, tmp); err != nil {
		_ = os.RemoveAll(tmp)
		return err
	}
	if err := os.Rename(tmp, dst); err != nil {
		_ = os.RemoveAll(tmp)
		return err
	}
	return os.RemoveAll(src)
}

func copyTree(src, dst string) error {
	return filepath.WalkDir(src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(src, p)
		out := filepath.Join(dst, rel)
		switch {
		case d.IsDir():
			info, _ := d.Info()
			mode := fs.FileMode(0o755)
			if info != nil {
				mode = info.Mode().Perm()
			}
			return os.MkdirAll(out, mode)
		case d.Type().IsRegular():
			return copyFile(p, out)
		}
		return nil // symlinks and devices are not copied
	})
}

// copyFile copies and checks the size; the mode (0600 keys) is kept
func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	st, err := in.Stat()
	if err != nil {
		return err
	}
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, st.Mode().Perm())
	if err != nil {
		return err
	}
	n, err := io.Copy(out, in)
	if e := out.Sync(); err == nil {
		err = e
	}
	if e := out.Close(); err == nil {
		err = e
	}
	if err == nil && n != st.Size() {
		err = fmt.Errorf("%s: copied %d of %d bytes", filepath.Base(src), n, st.Size())
	}
	return err
}

// modelsMissing: a manifest file (of a needed model, when requiredOnly) the data folder does not have at its full size
func (s *server) modelsMissing(requiredOnly bool) bool {
	m, err := s.readManifest()
	if err != nil {
		return false
	}
	for _, md := range m.Models {
		if requiredOnly && !md.Required {
			continue
		}
		for _, f := range md.Files {
			if dest, err := s.modelDest(md.Repo, f.Path); err == nil && fileSize(dest) != f.Size {
				return true
			}
		}
	}
	return false
}

// importModels brings every manifest file from <from>/models into the data folder when the data folder lacks it and
// <from> has it at the manifest's size. Same volume: rename. Otherwise: copy to <dest>.part, verify (size, then sha256
// or git blob sha1 when the manifest has one), rename, remove the original. An unfinished download (<file>.part) is
// brought along too, so the installer resumes it instead of starting over.
func (s *server) importModels(lg io.Writer, from, label string) {
	m, err := s.readManifest()
	if err != nil {
		return
	}
	src := filepath.Join(from, "models")
	if real, err := filepath.EvalSymlinks(src); err != nil || real == filepath.Join(s.dataDir(), "models") {
		return
	}
	n, bytes := 0, int64(0)
	for _, md := range m.Models {
		for _, f := range md.Files {
			dest, err := s.modelDest(md.Repo, f.Path)
			if err != nil || fileSize(dest) == f.Size {
				continue
			}
			fp := filepath.Join(src, filepath.FromSlash(md.Repo), filepath.FromSlash(f.Path))
			if !regularSize(fp, f.Size) {
				// an unfinished download from that folder: carry it over only when this side has nothing at all
				if part := fp + ".part"; regularSize(part, -1) && fileSize(dest) == 0 && fileSize(dest+".part") == 0 {
					if _, err := os.Lstat(dest + ".part"); errors.Is(err, fs.ErrNotExist) {
						if os.MkdirAll(filepath.Dir(dest), 0o755) == nil && os.Rename(part, dest+".part") == nil {
							s.moved(lg, "moved an unfinished download of %s/%s from %s (it resumes from here)", md.Repo, f.Path, label)
						}
					}
				}
				continue
			}
			if err := importModelFile(fp, dest, f, md.Repo); err != nil {
				s.moved(lg, "could not bring %s/%s from %s: %v", md.Repo, f.Path, label, err)
				continue
			}
			n++
			bytes += f.Size
		}
	}
	if n > 0 {
		s.moved(lg, "models: brought %d files (%s) from %s into %s", n, human(bytes), label, filepath.Join(s.dataDir(), "models"))
	}
}

func regularSize(p string, size int64) bool {
	st, err := os.Lstat(p)
	return err == nil && st.Mode().IsRegular() && (size < 0 || st.Size() == size)
}

func importModelFile(from, dest string, f mFile, repo string) error {
	if err := os.MkdirAll(filepath.Dir(dest), 0o755); err != nil {
		return err
	}
	if _, err := os.Lstat(dest); err == nil {
		_ = os.Remove(dest) // here at the wrong size: a damaged copy, the verified one replaces it
	}
	err := os.Rename(from, dest)
	if err == nil {
		if fileSize(dest) != f.Size { // cannot happen for a rename; checked anyway
			_ = os.Remove(dest)
			return errCorrupt
		}
		return nil
	}
	var le *os.LinkError
	if !errors.As(err, &le) || !errors.Is(le.Err, syscall.EXDEV) {
		return err
	}
	part := dest + ".part"
	if err := copyFile(from, part); err != nil {
		_ = os.Remove(part)
		return err
	}
	if err := verifyFile(part, &iFile{Size: f.Size, sha256: strings.ToLower(f.Sha256), gsha1: strings.ToLower(f.GitBlobSha1), repo: repo}); err != nil {
		_ = os.Remove(part)
		return err
	}
	if err := os.Rename(part, dest); err != nil {
		return err
	}
	_ = os.Remove(from)
	return nil
}

// oldStudios: older KBB Studio folders one level deep in the usual places, newest first (never this app folder)
func (s *server) oldStudios(home string) []string {
	places := []string{filepath.Dir(s.root)}
	if home != "" {
		for _, d := range []string{"Downloads", "Desktop", "Documents", "Applications", ".Trash"} {
			places = append(places, filepath.Join(home, d))
		}
	}
	seen := map[string]bool{s.root: true, s.dataDir(): true}
	type cand struct {
		p string
		t time.Time
	}
	var out []cand
	for _, pl := range places {
		ents, err := os.ReadDir(pl) // ~/.Trash needs Full Disk Access on a Mac: unreadable is simply skipped
		if err != nil {
			continue
		}
		for _, e := range ents {
			if !e.IsDir() { // a symlink is not followed
				continue
			}
			p := filepath.Join(pl, e.Name())
			real, err := filepath.EvalSymlinks(p)
			if err != nil || seen[real] {
				continue
			}
			seen[real] = true
			mst, err := os.Stat(filepath.Join(p, "models", "manifest.json"))
			if err != nil || !mst.Mode().IsRegular() {
				continue
			}
			if eng, _ := filepath.Glob(filepath.Join(p, "engine", "kbbstudio-mac-*")); len(eng) == 0 {
				continue
			}
			info, _ := os.Stat(filepath.Join(p, "models"))
			c := cand{p: p}
			if info != nil {
				c.t = info.ModTime()
			}
			out = append(out, c)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].t.After(out[j].t) })
	paths := make([]string, len(out))
	for i, c := range out {
		paths[i] = c.p
	}
	return paths
}

// displayPath: the data folder as the owner would type it (~/KBB Studio Data)
func displayPath(p, home string) string {
	if real, err := filepath.EvalSymlinks(home); err == nil && home != "" {
		home = real
	}
	if home != "" && (p == home || strings.HasPrefix(p, home+string(os.PathSeparator))) {
		return "~" + strings.TrimPrefix(p, home)
	}
	return p
}
