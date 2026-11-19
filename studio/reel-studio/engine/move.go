// move.go — a reel from one company to another (the owner, 19 Nov 2026: "allow me facility to move the project from
// one company to another"). Home → Recent reels → ⇄ on a reel.
//
//	POST /api/projects/move {"from":"kbb","to":"planet-careers","id":"p…","copy":false}
//
// The reel's folder (workspaces/<from>/projects/<id>: project.json + media/) is renamed to
// workspaces/<to>/projects/<id> inside the data folder -- one rename, so its media go with it. project.json then gets
// its new company (workspace) and every path that named the old folder is rewritten. A file the reel used from the old
// company's own folders (its logo, a clip in its library) is copied into the reel's media/ and the path pointed there:
// the reel keeps its own look and no longer depends on the old company. Its "brand" (the kit it was made in) is kept
// on purpose: moving does not restyle it.
//
// Refused: an id or company that is not a plain name, the same company, a target company that does not exist, a reel
// that is not there, and a reel with the same id already in the target (never overwritten). A copy ("copy": true)
// leaves the original where it is. After a move, a save from a tab that still has the reel open is refused (409), so
// it cannot bring back a half reel in the old company.
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strings"
)

var (
	wsIDRe   = regexp.MustCompile(`^[a-z0-9]+(-[a-z0-9]+)*$`)
	projIDRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$`)
)

const moveMaxCopy = 2 << 30 // the old company's files a reel may bring along

func (s *server) movedTo(rel string) string {
	s.movedMu.Lock()
	defer s.movedMu.Unlock()
	for k, v := range s.movedReels {
		if rel == k || strings.HasPrefix(rel, k+"/") {
			return v
		}
	}
	return ""
}

func (s *server) companyName(ws string) string {
	var w struct{ Name, FullName string }
	if p, err := s.resolveWrite("workspaces/"+ws+"/workspace.json", writeRoots); err == nil {
		if b, err := os.ReadFile(p); err == nil && json.Unmarshal(b, &w) == nil && w.Name != "" {
			return clip(w.Name, 60)
		}
	}
	return ws
}

type moveReq struct {
	From, To, ID string
	Copy         bool
}

type moveResult struct {
	OK      bool   `json:"ok"`
	ID      string `json:"id"`
	To      string `json:"to"`
	ToName  string `json:"toName"`
	Path    string `json:"path"`
	Brought int    `json:"brought"` // files of the old company copied into the reel
	Copy    bool   `json:"copy"`
}

func (s *server) moveAPI(w http.ResponseWriter, r *http.Request, p string) bool {
	if p != "/api/projects/move" {
		return false
	}
	var q moveReq
	if r.Method != http.MethodPost || json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&q) != nil {
		jsonErr(w, 400, "bad request")
		return true
	}
	res, code, err := s.moveProject(q)
	if err != nil {
		jsonErr(w, code, err.Error())
		return true
	}
	writeJSON(w, res)
	return true
}

func (s *server) moveProject(q moveReq) (*moveResult, int, error) {
	if !wsIDRe.MatchString(q.From) || !wsIDRe.MatchString(q.To) || len(q.From) > 64 || len(q.To) > 64 || !projIDRe.MatchString(q.ID) {
		return nil, 400, errors.New("That is not a reel or a company of this studio.")
	}
	if q.From == q.To {
		return nil, 400, errors.New("The reel is already in that company.")
	}
	srcRel := "workspaces/" + q.From + "/projects/" + q.ID
	dstRel := "workspaces/" + q.To + "/projects/" + q.ID
	src, err1 := s.resolveWrite(srcRel, writeRoots)
	dst, err2 := s.resolveWrite(dstRel, writeRoots)
	wsFile, err3 := s.resolveWrite("workspaces/"+q.To+"/workspace.json", writeRoots)
	if err1 != nil || err2 != nil || err3 != nil {
		return nil, 403, errors.New("path refused")
	}
	if st, err := os.Stat(wsFile); err != nil || !st.Mode().IsRegular() {
		return nil, 404, errors.New("There is no such company.")
	}
	toName := s.companyName(q.To)
	if st, err := os.Lstat(src); err != nil || !st.IsDir() {
		return nil, 404, errors.New("That reel is not there any more.")
	}
	raw, err := os.ReadFile(filepath.Join(src, "project.json"))
	if err != nil {
		return nil, 404, errors.New("That reel is not there any more.")
	}
	if _, err := os.Lstat(dst); err == nil {
		return nil, 409, errors.New(toName + " already has a reel with the same id (" + q.ID + "), so it was not moved. Nothing was changed.")
	}
	var proj map[string]any
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	if dec.Decode(&proj) != nil {
		return nil, 422, errors.New("The reel's file is damaged, so it was not moved.")
	}
	if id, _ := proj["id"].(string); id != "" && id != q.ID {
		return nil, 422, errors.New("The reel's file does not match its folder, so it was not moved.")
	}

	// the paths: the reel's own folder → the new folder; the old company's other files → copied into the reel
	oldPre, newPre := srcRel+"/", dstRel+"/"
	coPre := "workspaces/" + q.From + "/"
	type bring struct{ from, rel string }
	var brings []bring
	seen := map[string]string{}
	var size int64
	var walkErr error
	var walk func(v any) any
	walk = func(v any) any {
		switch x := v.(type) {
		case map[string]any:
			for k, e := range x {
				x[k] = walk(e)
			}
		case []any:
			for i, e := range x {
				x[i] = walk(e)
			}
		case string:
			lead := ""
			s2 := x
			if strings.HasPrefix(s2, "/workspaces/") {
				lead, s2 = "/", s2[1:]
			}
			switch {
			case s2 == srcRel:
				return lead + dstRel
			case strings.HasPrefix(s2, oldPre):
				return lead + newPre + strings.TrimPrefix(s2, oldPre)
			case strings.HasPrefix(s2, coPre) && !strings.ContainsAny(s2, "?#"):
				if to, ok := seen[s2]; ok {
					return lead + to
				}
				abs, err := s.resolve(s2, writeRoots)
				if err != nil {
					return x
				}
				st, err := os.Stat(abs)
				if err != nil || !st.Mode().IsRegular() {
					return x // not a file (a folder, or gone): left as it is
				}
				size += st.Size()
				if size > moveMaxCopy {
					walkErr = errors.New("The reel uses too many of the old company's files to bring along.")
					return x
				}
				name := strings.ReplaceAll(strings.TrimPrefix(s2, coPre), "/", "_")
				rel := "media/from-" + q.From + "/" + name
				brings = append(brings, bring{abs, rel})
				seen[s2] = newPre + rel
				return lead + newPre + rel
			}
		}
		return v
	}
	walk(proj)
	if walkErr != nil {
		return nil, 422, walkErr
	}
	proj["workspace"] = q.To
	out, err := json.MarshalIndent(proj, "", " ")
	if err != nil {
		return nil, 500, err
	}

	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return nil, 500, err
	}
	undo := func() {}
	if q.Copy {
		if err := copyTree(src, dst); err != nil {
			_ = os.RemoveAll(dst)
			return nil, 500, errors.New("Could not copy the reel: " + err.Error())
		}
		undo = func() { _ = os.RemoveAll(dst) }
	} else {
		if err := os.Rename(src, dst); err != nil {
			return nil, 500, errors.New("Could not move the reel: " + err.Error())
		}
		undo = func() {
			_ = os.RemoveAll(filepath.Join(dst, "media", "from-"+q.From))
			if err := os.Rename(dst, src); err != nil {
				log.Printf("move: putting %s back failed: %v", srcRel, err)
			}
		}
	}
	for _, b := range brings {
		to := filepath.Join(dst, filepath.FromSlash(b.rel))
		_ = os.MkdirAll(filepath.Dir(to), 0o755)
		if err := copyFile(b.from, to); err != nil {
			undo()
			return nil, 500, errors.New("Could not bring a file along (" + path.Base(b.rel) + "): " + err.Error())
		}
	}
	if err := writeAtomic(filepath.Join(dst, "project.json"), bytes.NewReader(out)); err != nil {
		undo()
		return nil, 500, errors.New("Could not save the moved reel: " + err.Error())
	}
	s.movedMu.Lock()
	if s.movedReels == nil {
		s.movedReels = map[string]string{}
	}
	delete(s.movedReels, dstRel) // moved back here: saves to it are fine again
	if !q.Copy {
		s.movedReels[srcRel] = toName
	}
	s.movedMu.Unlock()
	verb := "moved"
	if q.Copy {
		verb = "copied"
	}
	log.Printf("reel %s %s from %s to %s (%d files brought along)", q.ID, verb, q.From, q.To, len(brings))
	return &moveResult{OK: true, ID: q.ID, To: q.To, ToName: toName, Path: dstRel, Brought: len(brings), Copy: q.Copy}, 200, nil
}
