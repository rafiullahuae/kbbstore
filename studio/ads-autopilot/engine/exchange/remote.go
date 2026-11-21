package exchange

// Two ways to reach the exchange: GitHub's REST API (the Mac app: no git binary, a fine-grained token that reaches
// only the exchange repository) and a plain folder (the end-to-end self-test, where git moves the folder's files).
//
// GitHub, git data API (docs.github.com/rest/git): GET git/ref/heads/<b> -> commit -> tree?recursive=1 lists every
// file with its blob sha; GET git/blobs/<sha> reads one (any size, unlike the contents API's 1 MB); a write is ONE
// commit: POST git/blobs per file, POST git/trees {base_tree, tree:[{path, sha | sha:null to delete}]}, POST
// git/commits, PATCH git/refs/heads/<b> {sha, force:false}. A ref that moved meanwhile answers 422 (not a fast
// forward): ErrConflict, and the caller re-reads. An empty repository (no commit yet) gets its first commit through
// the contents API (git data refuses an empty repo).

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

var ErrConflict = errors.New("the exchange changed meanwhile")

type Entry struct {
	Path string
	SHA  string
	Size int64
}

type Snapshot struct {
	Head  string           // commit sha ("" for a folder)
	Files map[string]Entry // path -> entry
}

type Remote interface {
	Snapshot() (*Snapshot, error)
	Read(e Entry) ([]byte, error)
	// Commit: put / delete files as one change on top of the snapshot; ErrConflict when it is no longer the head.
	Commit(base *Snapshot, msg string, put map[string][]byte, del []string) error
}

// ── GitHub ──

type GitHub struct {
	API, Owner, Repo, Branch, Token string
	HTTP                            *http.Client
	Expires                         string // the token's expiry, from GitHub's response header (fine-grained tokens)
}

func NewGitHub(api, owner, repo, token string) *GitHub {
	if api == "" {
		api = "https://api.github.com"
	}
	return &GitHub{API: strings.TrimRight(api, "/"), Owner: owner, Repo: repo, Token: token, HTTP: &http.Client{Timeout: 60 * time.Second}}
}

type ghError struct {
	Code int
	Msg  string
}

func (e *ghError) Error() string { return fmt.Sprintf("GitHub %d: %s", e.Code, e.Msg) }

// HTTPStatus: the HTTP status of a GitHub error (0 otherwise).
func HTTPStatus(err error) int {
	var g *ghError
	if errors.As(err, &g) {
		return g.Code
	}
	return 0
}

func (g *GitHub) do(method, path string, in, out any) error {
	var body io.Reader
	if in != nil {
		b, _ := json.Marshal(in)
		body = bytes.NewReader(b)
	}
	u := g.API + path
	if !strings.HasPrefix(path, "/user") {
		u = g.API + "/repos/" + g.Owner + "/" + g.Repo + path
	}
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, method, u, body)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+g.Token)
	req.Header.Set("Accept", "application/vnd.github+json")
	req.Header.Set("X-GitHub-Api-Version", "2022-11-28")
	req.Header.Set("User-Agent", "kbb-ads-autopilot")
	if in != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	res, err := g.HTTP.Do(req)
	if err != nil {
		return fmt.Errorf("GitHub did not answer: %v", err)
	}
	defer res.Body.Close()
	if x := res.Header.Get("GitHub-Authentication-Token-Expiration"); x != "" {
		g.Expires = x
	}
	b, _ := io.ReadAll(io.LimitReader(res.Body, MaxFile*2))
	if res.StatusCode >= 300 {
		var m struct{ Message string }
		_ = json.Unmarshal(b, &m)
		if m.Message == "" {
			m.Message = http.StatusText(res.StatusCode)
		}
		return &ghError{res.StatusCode, m.Message}
	}
	if out != nil && len(b) > 0 {
		return json.Unmarshal(b, out)
	}
	return nil
}

func (g *GitHub) branch() (string, error) {
	if g.Branch != "" {
		return g.Branch, nil
	}
	var r struct {
		DefaultBranch string `json:"default_branch"`
	}
	if err := g.do("GET", "", nil, &r); err != nil {
		return "", err
	}
	g.Branch = r.DefaultBranch
	if g.Branch == "" {
		g.Branch = "main"
	}
	return g.Branch, nil
}

func (g *GitHub) Snapshot() (*Snapshot, error) {
	br, err := g.branch()
	if err != nil {
		return nil, err
	}
	var ref struct{ Object struct{ SHA string } }
	if err := g.do("GET", "/git/ref/heads/"+br, nil, &ref); err != nil {
		if c := HTTPStatus(err); c == 404 || c == 409 { // an empty repository: nothing in it yet
			return &Snapshot{Files: map[string]Entry{}}, nil
		}
		return nil, err
	}
	var cm struct{ Tree struct{ SHA string } }
	if err := g.do("GET", "/git/commits/"+ref.Object.SHA, nil, &cm); err != nil {
		return nil, err
	}
	var tr struct {
		Tree []struct {
			Path, Type, SHA string
			Size            int64
		}
		Truncated bool
	}
	if err := g.do("GET", "/git/trees/"+cm.Tree.SHA+"?recursive=1", nil, &tr); err != nil {
		return nil, err
	}
	s := &Snapshot{Head: ref.Object.SHA, Files: map[string]Entry{}}
	for _, t := range tr.Tree {
		if t.Type == "blob" {
			s.Files[t.Path] = Entry{Path: t.Path, SHA: t.SHA, Size: t.Size}
		}
	}
	return s, nil
}

func (g *GitHub) Read(e Entry) ([]byte, error) {
	var b struct{ Content, Encoding string }
	if err := g.do("GET", "/git/blobs/"+e.SHA, nil, &b); err != nil {
		return nil, err
	}
	if b.Encoding != "base64" {
		return []byte(b.Content), nil
	}
	return base64.StdEncoding.DecodeString(strings.ReplaceAll(b.Content, "\n", ""))
}

func (g *GitHub) Commit(base *Snapshot, msg string, put map[string][]byte, del []string) error {
	if len(put) == 0 && len(del) == 0 {
		return nil
	}
	br, err := g.branch()
	if err != nil {
		return err
	}
	if base.Head == "" { // the very first commit of an empty repository: the contents API makes it
		if err := g.do("PUT", "/contents/README.md", map[string]any{"message": "start the exchange", "content": base64.StdEncoding.EncodeToString([]byte("# KBB ads exchange\n\nJobs from KBB Ads Autopilot and the ads the cloud makes for them. No secrets here.\n")), "branch": br}, nil); err != nil && HTTPStatus(err) != 422 {
			return err
		}
		return ErrConflict // re-read: the caller snapshots again and commits on top
	}
	type item struct {
		Path string  `json:"path"`
		Mode string  `json:"mode"`
		Type string  `json:"type"`
		SHA  *string `json:"sha"`
	}
	var items []item
	for p, b := range put {
		var r struct{ SHA string }
		if err := g.do("POST", "/git/blobs", map[string]string{"content": base64.StdEncoding.EncodeToString(b), "encoding": "base64"}, &r); err != nil {
			return err
		}
		sha := r.SHA
		items = append(items, item{p, "100644", "blob", &sha})
	}
	for _, p := range del {
		if _, ok := base.Files[p]; ok {
			items = append(items, item{p, "100644", "blob", nil})
		}
	}
	var cm struct{ Tree struct{ SHA string } }
	if err := g.do("GET", "/git/commits/"+base.Head, nil, &cm); err != nil {
		return err
	}
	var tr struct{ SHA string }
	if err := g.do("POST", "/git/trees", map[string]any{"base_tree": cm.Tree.SHA, "tree": items}, &tr); err != nil {
		return err
	}
	var nc struct{ SHA string }
	if err := g.do("POST", "/git/commits", map[string]any{"message": msg, "tree": tr.SHA, "parents": []string{base.Head}}, &nc); err != nil {
		return err
	}
	if err := g.do("PATCH", "/git/refs/heads/"+br, map[string]any{"sha": nc.SHA, "force": false}, nil); err != nil {
		if c := HTTPStatus(err); c == 422 || c == 409 {
			return ErrConflict
		}
		return err
	}
	return nil
}

// Check: the key reaches this repository, can read and write its contents, and reaches no OTHER private repository
// with write access (the owner: "can read+write that repo only"). The write test is a blob nobody references
// (git garbage-collects it): nothing in the repository changes.
func (g *GitHub) Check() (map[string]any, error) {
	var r struct {
		Private       bool   `json:"private"`
		FullName      string `json:"full_name"`
		DefaultBranch string `json:"default_branch"`
	}
	if err := g.do("GET", "", nil, &r); err != nil {
		switch HTTPStatus(err) {
		case 401:
			return nil, errors.New("GitHub refused the key (401): it is wrong, expired or revoked — create a new one with the button")
		case 403, 404:
			return nil, fmt.Errorf("the key cannot see %s/%s: when creating it, choose Repository access → Only select repositories → %s", g.Owner, g.Repo, g.Repo)
		}
		return nil, err
	}
	if !r.Private {
		return nil, fmt.Errorf("%s is public: make it private first (Settings → General → Danger Zone → Change visibility); briefs carry your Playbook", r.FullName)
	}
	g.Branch = r.DefaultBranch
	if _, err := g.Snapshot(); err != nil {
		return nil, fmt.Errorf("the key cannot read the files (%v): give it Contents: Read and write", err)
	}
	if err := g.do("POST", "/git/blobs", map[string]string{"content": "a2Ji", "encoding": "base64"}, nil); err != nil {
		return nil, fmt.Errorf("the key can read but not write (%v): give it Contents: Read and write", err)
	}
	var others []string
	var list []struct {
		FullName    string `json:"full_name"`
		Private     bool   `json:"private"`
		Permissions struct {
			Push bool `json:"push"`
		} `json:"permissions"`
	}
	if err := g.do("GET", "/user/repos?per_page=100", nil, &list); err == nil {
		for _, x := range list {
			if x.Private && x.Permissions.Push && !strings.EqualFold(x.FullName, g.Owner+"/"+g.Repo) {
				others = append(others, x.FullName)
			}
		}
	}
	if len(others) > 0 {
		return nil, fmt.Errorf("this key also reaches %d other private repositories (%s): create one for %s only (Only select repositories)", len(others), strings.Join(others[:min(3, len(others))], ", "), g.Repo)
	}
	return map[string]any{"repo": r.FullName, "branch": g.Branch, "expires": g.Expires}, nil
}

// ── a plain folder (the self-test; git moves its files) ──

type Dir struct{ Root string }

func (d Dir) Snapshot() (*Snapshot, error) {
	s := &Snapshot{Files: map[string]Entry{}}
	err := filepath.Walk(d.Root, func(p string, fi os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(d.Root, p)
		rel = filepath.ToSlash(rel)
		if fi.IsDir() {
			if rel == ".git" {
				return filepath.SkipDir
			}
			return nil
		}
		s.Files[rel] = Entry{Path: rel, SHA: rel, Size: fi.Size()}
		return nil
	})
	return s, err
}

func (d Dir) Read(e Entry) ([]byte, error) {
	return os.ReadFile(filepath.Join(d.Root, filepath.FromSlash(e.Path)))
}

func (d Dir) Commit(_ *Snapshot, _ string, put map[string][]byte, del []string) error {
	for p, b := range put {
		f := filepath.Join(d.Root, filepath.FromSlash(p))
		if err := os.MkdirAll(filepath.Dir(f), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(f, b, 0o644); err != nil {
			return err
		}
	}
	for _, p := range del {
		_ = os.Remove(filepath.Join(d.Root, filepath.FromSlash(p)))
	}
	return nil
}
