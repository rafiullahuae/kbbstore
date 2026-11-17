// KBB Studio engine · photo sources (Work Abroad, 16 Nov 2026): the Photos tab searches Pexels and Unsplash with the
// owner's OWN free API keys.
//
//   - the keys live in <studio>/settings/photo-sources.json (0600), written and read ONLY here. settings/ is not a read or
//     write root, so the app, a project, an export or a company file can never contain or serve them. The app only
//     learns whether a key is set and its last four characters.
//   - /net/get adds the key to requests for api.pexels.com ("Authorization: <key>") and api.unsplash.com
//     ("Authorization: Client-ID <key>", "Accept-Version: v1") -- never to any other host.
//   - the five photo hosts are on the /net allowlist, which still needs the app's per-item permission grant for every
//     exact URL (a search, a preview, a download), exactly as for the model downloads.
//   - -test-photo-base http://127.0.0.1:<port> (TESTS ONLY, never set by the launcher) sends photo-host requests to a local
//     mock server instead: https://api.pexels.com/v1/search?q -> <base>/api.pexels.com/v1/search?q
package main

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

var photoHosts = map[string]bool{"api.pexels.com": true, "images.pexels.com": true, "api.unsplash.com": true, "images.unsplash.com": true, "plus.unsplash.com": true}

// keySources: which API host each key belongs to
var keySources = map[string]string{"pexels": "api.pexels.com", "unsplash": "api.unsplash.com"}

// a key is printable ASCII without spaces or quotes (Pexels: 56 chars; Unsplash access key: 43) -- nothing that could
// break a header line
var keyRe = regexp.MustCompile(`^[A-Za-z0-9_\-]{16,128}$`)

func init() {
	for h := range photoHosts {
		netHosts[h] = true
	}
}

func (s *server) keyFile() string { return filepath.Join(s.settingsDir(), "photo-sources.json") }

func (s *server) photoKeys() map[string]string {
	out := map[string]string{}
	b, err := os.ReadFile(s.keyFile())
	if err != nil {
		return out
	}
	var m map[string]string
	if json.Unmarshal(b, &m) != nil {
		return out
	}
	for k, v := range m {
		if _, ok := keySources[k]; ok && keyRe.MatchString(v) {
			out[k] = v
		}
	}
	return out
}

func (s *server) savePhotoKey(source, key string) error {
	if _, ok := keySources[source]; !ok {
		return errors.New("source must be pexels or unsplash")
	}
	key = strings.TrimSpace(key)
	if key != "" && !keyRe.MatchString(key) {
		return errors.New("that does not look like an API key (letters, digits, - and _ only)")
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	m := s.photoKeys()
	if key == "" {
		delete(m, source)
	} else {
		m[source] = key
	}
	if err := os.MkdirAll(filepath.Dir(s.keyFile()), 0o700); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(m, "", "  ")
	tmp := s.keyFile() + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, s.keyFile())
}

// keyStatus: what the app may know -- set or not, and the last four characters
func (s *server) keyStatus() map[string]any {
	m := s.photoKeys()
	out := map[string]any{}
	for src := range keySources {
		k := m[src]
		st := map[string]any{"set": k != ""}
		if k != "" {
			st["hint"] = "…" + k[len(k)-4:]
		}
		out[src] = st
	}
	return out
}

// netRequest builds the outgoing request for a permitted URL: the photo APIs get their key; under -test-photo-base the
// photo hosts are sent to the local mock instead.
func (s *server) netRequest(u string) (*http.Request, error) {
	pu, err := url.Parse(u)
	if err != nil {
		return nil, err
	}
	target := u
	if s.photoBase != "" && photoHosts[pu.Hostname()] {
		target = strings.TrimSuffix(s.photoBase, "/") + "/" + pu.Hostname() + pu.EscapedPath()
		if pu.RawQuery != "" {
			target += "?" + pu.RawQuery
		}
	}
	req, err := http.NewRequest(http.MethodGet, target, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", appName+"/"+version)
	keys := s.photoKeys()
	switch pu.Hostname() {
	case "api.pexels.com":
		if k := keys["pexels"]; k != "" {
			req.Header.Set("Authorization", k)
		}
	case "api.unsplash.com":
		if k := keys["unsplash"]; k != "" {
			req.Header.Set("Authorization", "Client-ID "+k)
		}
		req.Header.Set("Accept-Version", "v1")
	}
	return req, nil
}

// /api/photo-keys: GET -> {pexels:{set,hint}, unsplash:{set,hint}}; POST {source, key} stores (an empty key removes it)
func (s *server) photoKeysAPI(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		writeJSON(w, s.keyStatus())
	case http.MethodPost:
		var b struct{ Source, Key string }
		if json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&b) != nil {
			jsonErr(w, 400, "bad request")
			return
		}
		if err := s.savePhotoKey(b.Source, b.Key); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		writeJSON(w, s.keyStatus())
	default:
		jsonErr(w, 405, "GET or POST")
	}
}
