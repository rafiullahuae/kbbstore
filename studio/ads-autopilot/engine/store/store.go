// Package store is the data folder (~/KBB Ads Autopilot Data): small JSON state files written atomically, and the
// append-only audit log (audit.jsonl) of every snapshot, proposal, check, execution and Meta response.
//
// Secrets never reach the disk through here: every log line is redacted before it is written -- the values the
// secrets store registered (exact match), anything shaped like a Meta token (EAA…) or an Anthropic key (sk-ant-…),
// and the value of any JSON key named like a credential (access_token, token, secret, api_key, password,
// appsecret_proof, x-api-key, authorization).
package store

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"kaa/model"
)

const (
	logFile  = "audit.jsonl"
	tailKeep = 4000 // log lines kept in memory for /api/log and the brain's history
)

type Store struct {
	Dir     string
	mu      sync.Mutex
	f       *os.File
	seq     int64
	tail    []model.LogEntry
	secrets []string
	now     func() time.Time
}

// Open makes the data folder (0700) and its parts and opens the audit log for appending.
func Open(dir string) (*Store, error) {
	for _, d := range []string{dir, filepath.Join(dir, "creatives"), filepath.Join(dir, "snapshots"), filepath.Join(dir, "brain")} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			return nil, err
		}
	}
	s := &Store{Dir: dir, now: time.Now}
	if err := s.loadTail(); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(filepath.Join(dir, logFile), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		return nil, err
	}
	s.f = f
	return s, nil
}

func (s *Store) Close() error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.f != nil {
		return s.f.Close()
	}
	return nil
}

func (s *Store) loadTail() error {
	f, err := os.Open(filepath.Join(s.Dir, logFile))
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 1<<20), 16<<20)
	for sc.Scan() {
		var e model.LogEntry
		if json.Unmarshal(sc.Bytes(), &e) != nil {
			continue // a half-written last line after a power cut is skipped, never fatal
		}
		if e.Seq > s.seq {
			s.seq = e.Seq
		}
		s.tail = append(s.tail, e)
		if len(s.tail) > tailKeep*2 {
			s.tail = append([]model.LogEntry(nil), s.tail[len(s.tail)-tailKeep:]...)
		}
	}
	if len(s.tail) > tailKeep {
		s.tail = append([]model.LogEntry(nil), s.tail[len(s.tail)-tailKeep:]...)
	}
	return nil
}

// AddSecret registers a value that must never appear in the log (called by the secrets store on every load/save).
func (s *Store) AddSecret(v string) {
	if len(v) < 6 {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, x := range s.secrets {
		if x == v {
			return
		}
	}
	s.secrets = append(s.secrets, v)
}

var (
	reMetaTok  = regexp.MustCompile(`EAA[A-Za-z0-9]{20,}`)
	reAntKey   = regexp.MustCompile(`sk-ant-[A-Za-z0-9_\-]{8,}`)
	reCredJSON = regexp.MustCompile(`(?i)"(access_token|token|secret|app_secret|api_key|apikey|password|appsecret_proof|x-api-key|authorization)"\s*:\s*"[^"]*"`)
	reCredForm = regexp.MustCompile(`(?i)(access_token|appsecret_proof|api_key)=[^&\s"]+`)
)

// Redact applies every rule above to b.
func (s *Store) Redact(b []byte) []byte {
	s.mu.Lock()
	secs := append([]string(nil), s.secrets...)
	s.mu.Unlock()
	return redactWith(b, secs)
}

func redactWith(b []byte, secs []string) []byte {
	for _, v := range secs {
		b = bytes.ReplaceAll(b, []byte(v), []byte("[redacted]"))
	}
	b = reMetaTok.ReplaceAll(b, []byte("[redacted]"))
	b = reAntKey.ReplaceAll(b, []byte("[redacted]"))
	b = reCredJSON.ReplaceAll(b, []byte(`"$1":"[redacted]"`))
	b = reCredForm.ReplaceAll(b, []byte(`$1=[redacted]`))
	return b
}

// Append writes one audit line (redacted) and returns it.
func (s *Store) Append(kind, summary string, data any) model.LogEntry {
	var raw json.RawMessage
	if data != nil {
		b, err := json.Marshal(data)
		if err != nil {
			b, _ = json.Marshal(map[string]string{"unmarshalable": err.Error()})
		}
		raw = b
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if raw != nil {
		raw = redactWith(raw, s.secrets)
	}
	summary = string(redactWith([]byte(summary), s.secrets))
	s.seq++
	e := model.LogEntry{Seq: s.seq, At: s.now().UTC(), Kind: kind, Summary: summary, Data: raw}
	line, _ := json.Marshal(e)
	if s.f != nil {
		_, _ = s.f.Write(append(line, '\n'))
	}
	s.tail = append(s.tail, e)
	if len(s.tail) > tailKeep*2 {
		s.tail = append([]model.LogEntry(nil), s.tail[len(s.tail)-tailKeep:]...)
	}
	return e
}

// Log returns entries with Seq > after (oldest first), at most limit, optionally only these kinds. Served from memory.
func (s *Store) Log(after int64, limit int, kinds ...string) []model.LogEntry {
	if limit <= 0 || limit > 2000 {
		limit = 500
	}
	want := map[string]bool{}
	for _, k := range kinds {
		if k != "" {
			want[k] = true
		}
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	out := []model.LogEntry{}
	for _, e := range s.tail {
		if e.Seq <= after || len(want) > 0 && !want[e.Kind] {
			continue
		}
		out = append(out, e)
		if len(out) >= limit {
			break
		}
	}
	return out
}

// Recent: the last n entries of these kinds (newest last).
func (s *Store) Recent(n int, kinds ...string) []model.LogEntry {
	want := map[string]bool{}
	for _, k := range kinds {
		want[k] = true
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []model.LogEntry
	for i := len(s.tail) - 1; i >= 0 && len(out) < n; i-- {
		if len(want) == 0 || want[s.tail[i].Kind] {
			out = append(out, s.tail[i])
		}
	}
	for i, j := 0, len(out)-1; i < j; i, j = i+1, j-1 {
		out[i], out[j] = out[j], out[i]
	}
	return out
}

func (s *Store) LastSeq() int64 {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.seq
}

// ── JSON state files ──

var nameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_\-/]{0,80}$`)

func (s *Store) path(name string) (string, error) {
	if !nameRe.MatchString(name) || strings.Contains(name, "..") {
		return "", fmt.Errorf("bad state name %q", name)
	}
	return filepath.Join(s.Dir, name+".json"), nil
}

// Load reads <name>.json into v. A missing file leaves v as it is and returns nil.
func (s *Store) Load(name string, v any) error {
	p, err := s.path(name)
	if err != nil {
		return err
	}
	b, err := os.ReadFile(p)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	return json.Unmarshal(b, v)
}

// Save writes <name>.json atomically (temp file + rename), 0600.
func (s *Store) Save(name string, v any) error {
	p, err := s.path(name)
	if err != nil {
		return err
	}
	b, err := json.MarshalIndent(v, "", " ")
	if err != nil {
		return err
	}
	return WriteAtomic(p, b, 0o600)
}

func WriteAtomic(p string, b []byte, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(p), ".tmp-*")
	if err != nil {
		return err
	}
	_, werr := tmp.Write(b)
	serr := tmp.Sync()
	cerr := tmp.Close()
	if werr != nil || serr != nil || cerr != nil {
		_ = os.Remove(tmp.Name())
		return fmt.Errorf("write %s: %v %v %v", p, werr, serr, cerr)
	}
	_ = os.Chmod(tmp.Name(), mode)
	if err := os.Rename(tmp.Name(), p); err != nil {
		_ = os.Remove(tmp.Name())
		return err
	}
	return nil
}
