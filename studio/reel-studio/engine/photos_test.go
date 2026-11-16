package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func testServer(t *testing.T) *server {
	t.Helper()
	root := t.TempDir()
	for _, d := range []string{"app", "library", "workspaces", "models", "brands", "vendor"} {
		_ = os.MkdirAll(filepath.Join(root, d), 0o755)
	}
	real, _ := filepath.EvalSymlinks(root)
	s := &server{root: real, token: "tok", addr: "127.0.0.1:9", grants: map[string]bool{}, subs: map[chan []byte]bool{}, jobs: map[string]*job{}}
	s.client = &http.Client{CheckRedirect: s.checkRedirect}
	return s
}

func call(s *server, method, path, body string) *httptest.ResponseRecorder {
	r := httptest.NewRequest(method, path, strings.NewReader(body))
	r.Host = s.addr
	r.Header.Set("X-RS-Token", s.token)
	w := httptest.NewRecorder()
	s.ServeHTTP(w, r)
	return w
}

func grant(t *testing.T, s *server, u string) {
	t.Helper()
	b, _ := json.Marshal(map[string]string{"url": u, "scope": "exact", "what": "test"})
	if w := call(s, "POST", "/api/permission", string(b)); w.Code != 200 {
		t.Fatalf("grant %s: %d %s", u, w.Code, w.Body)
	}
}

// The five photo hosts are on the allowlist -- and nothing that merely looks like them.
func TestPhotoHostsAllowlisted(t *testing.T) {
	for _, h := range []string{"api.pexels.com", "images.pexels.com", "api.unsplash.com", "images.unsplash.com", "plus.unsplash.com"} {
		if !netHosts[h] || !redirectOK(h) {
			t.Errorf("%s should be allowed", h)
		}
	}
	for _, h := range []string{"pexels.com", "www.pexels.com", "images.pexels.com.evil.com", "evilunsplash.com", "unsplash.com", "api.unsplash.com:8443", "x@api.unsplash.com"} {
		if netHosts[h] || redirectOK(h) {
			t.Errorf("%s should be refused", h)
		}
	}
}

// Every photo URL needs the app's grant for that exact URL; the grant is refused for anything off the allowlist.
func TestPhotoNeedsExactGrant(t *testing.T) {
	s := testServer(t)
	u := "https://api.pexels.com/v1/search?query=welder&per_page=24"
	if _, ok := s.permitted(u); ok {
		t.Fatal("permitted before any grant")
	}
	if w := call(s, "GET", "/net/get?url="+url.QueryEscape(u), ""); w.Code != 403 {
		t.Fatalf("/net/get without a grant: %d", w.Code)
	}
	grant(t, s, u)
	if _, ok := s.permitted(u); !ok {
		t.Fatal("not permitted after the grant")
	}
	for _, other := range []string{"https://api.pexels.com/v1/search?query=nurse&per_page=24", "http://api.pexels.com/v1/search?query=welder&per_page=24", "https://api.pexels.com:444/v1/search?query=welder&per_page=24", "https://images.pexels.com/photos/1/a.jpeg"} {
		if _, ok := s.permitted(other); ok {
			t.Errorf("%s permitted by a grant for another URL", other)
		}
	}
	for _, bad := range []string{"https://evil.com/x", "https://u:p@api.pexels.com/v1/search", "http://api.unsplash.com/search/photos", "https://api.unsplash.com:8443/x"} {
		b, _ := json.Marshal(map[string]string{"url": bad, "scope": "exact"})
		if w := call(s, "POST", "/api/permission", string(b)); w.Code != 403 {
			t.Errorf("grant for %s: %d, want 403", bad, w.Code)
		}
	}
	b, _ := json.Marshal(map[string]string{"url": "https://api.pexels.com/", "scope": "repo"})
	if w := call(s, "POST", "/api/permission", string(b)); w.Code != 403 {
		t.Errorf("a repo-wide grant on a photo host must be refused, got %d", w.Code)
	}
}

// Keys: stored 0600 under settings/ (never a served or writable root); the app only ever sees "set" and the last four.
func TestPhotoKeysStoredPrivately(t *testing.T) {
	s := testServer(t)
	key := "PexelsTestKey_abcdefghijklmnopqrstuvwxyz0123456789ABCD"
	if w := call(s, "POST", "/api/photo-keys", `{"source":"pexels","key":"`+key+`"}`); w.Code != 200 {
		t.Fatalf("save: %d %s", w.Code, w.Body)
	}
	st, err := os.Stat(s.keyFile())
	if err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("key file %v perm %v", err, st)
	}
	w := call(s, "GET", "/api/photo-keys", "")
	if strings.Contains(w.Body.String(), key) || !strings.Contains(w.Body.String(), `"hint":"…ABCD"`) || !strings.Contains(w.Body.String(), `"set":true`) {
		t.Fatalf("status leaks or misses: %s", w.Body)
	}
	if _, err := s.resolve("settings/photo-sources.json", readRoots); err == nil {
		t.Fatal("settings/ must not be readable through the file API")
	}
	if _, err := s.resolve("settings/photo-sources.json", writeRoots); err == nil {
		t.Fatal("settings/ must not be writable through the file API")
	}
	if w := call(s, "GET", "/settings/photo-sources.json", ""); w.Code == 200 || strings.Contains(w.Body.String(), key) {
		t.Fatalf("the key file is served: %d", w.Code)
	}
	for _, bad := range []string{`{"source":"pexels","key":"has space in it 1234567"}`, `{"source":"pexels","key":"short"}`, `{"source":"pexels","key":"abc\r\nX-Evil: 1234567890"}`, `{"source":"flickr","key":"abcdefghijklmnopqrstuvwxyz"}`} {
		if w := call(s, "POST", "/api/photo-keys", bad); w.Code != 400 {
			t.Errorf("%s: %d, want 400", bad, w.Code)
		}
	}
	if s.photoKeys()["pexels"] != key {
		t.Fatal("a refused save changed the stored key")
	}
	if w := call(s, "POST", "/api/photo-keys", `{"source":"pexels","key":""}`); w.Code != 200 || s.photoKeys()["pexels"] != "" {
		t.Fatal("an empty key should remove it")
	}
}

// /net/get adds the key for the API host it belongs to, and to no other host; the response passes through.
func TestKeyInjection(t *testing.T) {
	type seen struct{ path, auth, ver string }
	var got []seen
	mock := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = append(got, seen{r.URL.RequestURI(), r.Header.Get("Authorization"), r.Header.Get("Accept-Version")})
		if strings.Contains(r.URL.Path, "/redirect-out") {
			http.Redirect(w, r, "https://evil.example.com/steal", http.StatusFound)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true}`))
	}))
	defer mock.Close()
	s := testServer(t)
	s.photoBase = mock.URL
	pk, uk := "PexelsKey_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGH", "UnsplashAccess-Key_0123456789abcdefghijk"
	if s.savePhotoKey("pexels", pk) != nil || s.savePhotoKey("unsplash", uk) != nil {
		t.Fatal("save")
	}
	urls := []string{"https://api.pexels.com/v1/search?query=nurse", "https://api.unsplash.com/search/photos?query=nurse", "https://images.pexels.com/photos/1/p.jpeg?w=400", "https://images.unsplash.com/photo-1?w=400"}
	for _, u := range urls {
		grant(t, s, u)
		w := call(s, "GET", "/net/get?url="+url.QueryEscape(u), "")
		if w.Code != 200 || w.Body.String() != `{"ok":true}` {
			t.Fatalf("%s: %d %s", u, w.Code, w.Body)
		}
	}
	want := []seen{{"/api.pexels.com/v1/search?query=nurse", pk, ""}, {"/api.unsplash.com/search/photos?query=nurse", "Client-ID " + uk, "v1"}, {"/images.pexels.com/photos/1/p.jpeg?w=400", "", ""}, {"/images.unsplash.com/photo-1?w=400", "", ""}}
	for i := range want {
		if i >= len(got) || got[i] != want[i] {
			t.Fatalf("request %d: got %+v want %+v", i, got, want[i])
		}
	}
	// a redirect off the allowlist is refused (and Go never carries the key across hosts)
	u := "https://api.pexels.com/redirect-out"
	grant(t, s, u)
	if w := call(s, "GET", "/net/get?url="+url.QueryEscape(u), ""); w.Code != 502 || !strings.Contains(w.Body.String(), "refused") {
		t.Fatalf("redirect to evil.example.com: %d %s", w.Code, w.Body)
	}
}
