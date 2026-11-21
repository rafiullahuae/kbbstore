package exchange

// FakeGitHub: an in-memory GitHub (the git data API subset Remote uses), for the Go tests. Never used by the app.

import (
	"crypto/sha1"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
)

type FakeGitHub struct {
	*httptest.Server
	Token, Owner, Repo string
	Public             bool
	OtherRepos         []string // more private repos the key can push to
	mu                 sync.Mutex
	blobs              map[string][]byte
	trees              map[string]map[string]string
	commits            map[string][2]string // sha -> {tree, parent}
	ref                string
	Bodies             []string // every request body (tests look for leaks)
	Headers            []string // every Authorization header
	BeforeRef          func()   // runs before a ref update (a test moves the ref under a commit)
}

func NewFakeGitHub(token string) *FakeGitHub {
	f := &FakeGitHub{Token: token, Owner: DefaultOwner, Repo: DefaultRepo, blobs: map[string][]byte{}, trees: map[string]map[string]string{}, commits: map[string][2]string{}}
	f.Server = httptest.NewServer(http.HandlerFunc(f.serve))
	return f
}

func hashOf(b []byte) string { h := sha1.Sum(b); return hex.EncodeToString(h[:]) }

// Files: the current tree, path -> content.
func (f *FakeGitHub) Files() map[string][]byte {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := map[string][]byte{}
	if f.ref == "" {
		return out
	}
	for p, s := range f.trees[f.commits[f.ref][0]] {
		out[p] = f.blobs[s]
	}
	return out
}

// Put: a commit from outside (the cloud worker, in tests).
func (f *FakeGitHub) Put(files map[string][]byte, del ...string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	t := map[string]string{}
	if f.ref != "" {
		for p, s := range f.trees[f.commits[f.ref][0]] {
			t[p] = s
		}
	}
	for p, b := range files {
		s := hashOf(b)
		f.blobs[s] = b
		t[p] = s
	}
	for _, p := range del {
		delete(t, p)
	}
	f.commitLocked(t)
}

func (f *FakeGitHub) commitLocked(t map[string]string) string {
	tb, _ := json.Marshal(t)
	ts := hashOf(tb)
	f.trees[ts] = t
	cs := hashOf([]byte(ts + f.ref + fmt.Sprint(len(f.commits))))
	f.commits[cs] = [2]string{ts, f.ref}
	f.ref = cs
	return cs
}

func (f *FakeGitHub) serve(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	f.mu.Lock()
	defer f.mu.Unlock()
	f.Bodies = append(f.Bodies, string(body))
	f.Headers = append(f.Headers, r.Header.Get("Authorization"))
	js := func(code int, v any) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	if r.Header.Get("Authorization") != "Bearer "+f.Token {
		js(401, map[string]string{"message": "Bad credentials"})
		return
	}
	w.Header().Set("GitHub-Authentication-Token-Expiration", "2027-11-21 10:00:00 UTC")
	p := r.URL.Path
	if p == "/user/repos" {
		list := []map[string]any{{"full_name": f.Owner + "/" + f.Repo, "private": !f.Public, "permissions": map[string]bool{"push": true}}}
		for _, o := range f.OtherRepos {
			list = append(list, map[string]any{"full_name": o, "private": true, "permissions": map[string]bool{"push": true}})
		}
		js(200, list)
		return
	}
	pre := "/repos/" + f.Owner + "/" + f.Repo
	if !strings.HasPrefix(p, pre) {
		js(404, map[string]string{"message": "Not Found"})
		return
	}
	p = strings.TrimPrefix(p, pre)
	var in map[string]any
	_ = json.Unmarshal(body, &in)
	switch {
	case p == "" && r.Method == "GET":
		js(200, map[string]any{"full_name": f.Owner + "/" + f.Repo, "private": !f.Public, "default_branch": "main"})
	case p == "/git/ref/heads/main" && r.Method == "GET":
		if f.ref == "" {
			js(409, map[string]string{"message": "Git Repository is empty."})
			return
		}
		js(200, map[string]any{"object": map[string]string{"sha": f.ref}})
	case strings.HasPrefix(p, "/git/commits/") && r.Method == "GET":
		c, ok := f.commits[strings.TrimPrefix(p, "/git/commits/")]
		if !ok {
			js(404, map[string]string{"message": "Not Found"})
			return
		}
		js(200, map[string]any{"tree": map[string]string{"sha": c[0]}})
	case strings.HasPrefix(p, "/git/trees/") && r.Method == "GET":
		t := f.trees[strings.TrimPrefix(p, "/git/trees/")]
		var out []map[string]any
		for path, s := range t {
			out = append(out, map[string]any{"path": path, "type": "blob", "sha": s, "size": len(f.blobs[s])})
		}
		js(200, map[string]any{"tree": out})
	case strings.HasPrefix(p, "/git/blobs/") && r.Method == "GET":
		b, ok := f.blobs[strings.TrimPrefix(p, "/git/blobs/")]
		if !ok {
			js(404, map[string]string{"message": "Not Found"})
			return
		}
		js(200, map[string]string{"content": base64.StdEncoding.EncodeToString(b), "encoding": "base64"})
	case p == "/git/blobs" && r.Method == "POST":
		b, _ := base64.StdEncoding.DecodeString(fmt.Sprint(in["content"]))
		s := hashOf(b)
		f.blobs[s] = b
		js(201, map[string]string{"sha": s})
	case p == "/git/trees" && r.Method == "POST":
		t := map[string]string{}
		for k, v := range f.trees[fmt.Sprint(in["base_tree"])] {
			t[k] = v
		}
		items, _ := in["tree"].([]any)
		for _, it := range items {
			m := it.(map[string]any)
			if s, ok := m["sha"].(string); ok {
				t[m["path"].(string)] = s
			} else {
				delete(t, m["path"].(string))
			}
		}
		tb, _ := json.Marshal(t)
		ts := hashOf(tb)
		f.trees[ts] = t
		js(201, map[string]string{"sha": ts})
	case p == "/git/commits" && r.Method == "POST":
		ps, _ := in["parents"].([]any)
		parent := ""
		if len(ps) > 0 {
			parent = fmt.Sprint(ps[0])
		}
		cs := hashOf([]byte(fmt.Sprint(in["tree"], parent, len(f.commits))))
		f.commits[cs] = [2]string{fmt.Sprint(in["tree"]), parent}
		js(201, map[string]string{"sha": cs})
	case p == "/git/refs/heads/main" && r.Method == "PATCH":
		if f.BeforeRef != nil {
			fn := f.BeforeRef
			f.BeforeRef = nil
			f.mu.Unlock()
			fn()
			f.mu.Lock()
		}
		sha := fmt.Sprint(in["sha"])
		if f.commits[sha][1] != f.ref {
			js(422, map[string]string{"message": "Update is not a fast forward"})
			return
		}
		f.ref = sha
		js(200, map[string]any{"object": map[string]string{"sha": sha}})
	case p == "/contents/README.md" && r.Method == "PUT":
		if f.ref != "" {
			js(422, map[string]string{"message": "sha wasn't supplied"})
			return
		}
		b, _ := base64.StdEncoding.DecodeString(fmt.Sprint(in["content"]))
		s := hashOf(b)
		f.blobs[s] = b
		f.commitLocked(map[string]string{"README.md": s})
		js(201, map[string]any{})
	default:
		js(404, map[string]string{"message": "Not Found"})
	}
}
