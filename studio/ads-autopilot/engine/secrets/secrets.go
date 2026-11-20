// Package secrets keeps the owner's keys: the macOS Keychain on a Mac (service "KBB Ads Autopilot", one generic
// password per name, written through `security -i` on stdin so a key never appears in a process list), a 0600 JSON
// file in the data folder anywhere else (the Linux test runs). The browser only ever sees {set, last4, error}.
//
// 20 Nov 2026, the owner: after he replaced the app folder and started the new version, "all the apis vanished" --
// every key showed as not set. Whatever macOS answered (a Keychain prompt dismissed or not answered within the old
// 15 s, "user interaction is not allowed", a locked keychain), the engine read ANY failure of `security` that was not
// "could not be found" as an error, and every caller -- Describe, the connection tests -- turned that error into
// "not set". Nothing about the keys lives in the app folder (the Keychain item is service + name; the index below is
// in the DATA folder), so a replaced folder, an update, a roll back or a restart cannot lose them; what they lost was
// the READ. Now:
//   - a read that fails is never "not set": Describe carries the reason (KeyError), and macOS refusing access says
//     what to do ("click Always Allow, then Try again"); only `security`'s own "not found" (exit 44) is "not set"
//   - a failed read is not cached: Try again asks macOS again; a read waits 60 s (the owner may be answering a prompt)
//   - keys-index.json in the data folder (names and dates, never values) remembers which keys were saved, so a key
//     that is gone from the Keychain says so ("saved on …, no longer in the Keychain") instead of looking never set
//   - CheckFormat refuses a key pasted into the wrong field before it is stored (a Meta token in the Claude field)
package secrets

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"sync"
	"time"
)

const Service = "KBB Ads Autopilot"

// Names the engine stores. Anything else is refused.
var Names = []string{"meta_token", "meta_app_secret", "anthropic_key", "shop_token"}

type Store interface {
	Get(name string) (string, error) // "" + nil when not set
	Set(name, value string) error
	Delete(name string) error
	Kind() string // "keychain" | "file"
}

type Info struct {
	Set   bool   `json:"set"`
	Last4 string `json:"last4,omitempty"`
	Error string `json:"error,omitempty"` // the key could not be read: why, in plain words (never shown as "not set")
}

// KeyError: a saved key that could not be read.
type KeyError struct {
	Name    string
	Denied  bool   // macOS did not let the app read it (a prompt dismissed / not answered, interaction not allowed)
	Missing string // the index says it was saved on this date, the Keychain no longer has it
	Detail  string
}

func (e *KeyError) Error() string {
	switch {
	case e.Denied:
		return "macOS didn't let the app read its saved keys. Click “Always Allow” when macOS asks (the password is your Mac login password), then Try again."
	case e.Missing != "":
		return fmt.Sprintf("This key was saved on %s but is no longer in the macOS Keychain (it was removed there). Paste it again.", e.Missing)
	default:
		return "The macOS Keychain could not be read (" + e.Detail + "). Try again; if it keeps failing, open Keychain Access and unlock the login keychain."
	}
}

// Describe: what the UI may know about a secret. A key that could not be read says why; it is never "not set".
func Describe(s Store, name string) Info {
	v, err := s.Get(name)
	if err != nil {
		return Info{Error: err.Error()}
	}
	if v == "" {
		return Info{}
	}
	l := v
	if len(l) > 4 {
		l = l[len(l)-4:]
	}
	return Info{Set: true, Last4: l}
}

func validName(n string) bool {
	for _, x := range Names {
		if x == n {
			return true
		}
	}
	return false
}

// ValidValue: printable ASCII without quotes, backslashes or spaces (every real key is), at most 4 KB. It is also what
// makes the `security -i` command line safe to build.
func ValidValue(v string) error {
	if v == "" || len(v) > 4096 {
		return errors.New("the key is empty or too long")
	}
	for _, r := range v {
		if r <= 0x20 || r >= 0x7f || r == '"' || r == '\\' || r == '\'' {
			return errors.New("the key has a character keys do not have (space, quote or backslash): paste it again")
		}
	}
	return nil
}

// CheckFormat: the key looks like the key the field is for. A key pasted into the wrong field (the owner put his Meta
// token into the Claude field) is refused before it is stored, saying what it looks like instead.
func CheckFormat(name, v string) error {
	what := func(v string) string {
		switch {
		case strings.HasPrefix(v, "EAA"):
			return "a Meta token (EAA…)"
		case strings.HasPrefix(v, "sk-ant-"):
			return "a Claude key (sk-ant-…)"
		case strings.HasPrefix(v, "kbbap_"):
			return "a shop connection key (kbbap_…)"
		case strings.HasPrefix(v, "AIza"):
			return "a Google key (AIza…)"
		}
		return ""
	}
	want := map[string][2]string{
		"meta_token":    {"EAA", "Meta tokens start with EAA"},
		"anthropic_key": {"sk-ant-", "Claude keys start with sk-ant-"},
		"shop_token":    {"kbbap_", "Shop connection keys start with kbbap_ (kbeautybliss.com admin → Ads Autopilot → API keys)"},
	}
	if w, ok := want[name]; ok {
		if strings.HasPrefix(v, w[0]) {
			return nil
		}
		if x := what(v); x != "" {
			return fmt.Errorf("This looks like %s, not the key for this field. %s.", x, w[1])
		}
		return fmt.Errorf("This does not look like the right key. %s.", w[1])
	}
	if name == "meta_app_secret" {
		if x := what(v); x != "" {
			return fmt.Errorf("This looks like %s, not the Meta app secret (32 letters and digits from the Meta app's Basic settings).", x)
		}
	}
	return nil
}

// securityPath / goos: the Keychain tool and the platform (the tests put a fake macOS `security` here).
var (
	securityPath = "/usr/bin/security"
	goos         = runtime.GOOS
)

// New picks the Keychain on macOS, the file elsewhere. onValue is told every value loaded or saved (the audit log
// registers it for redaction). dataDir is the DATA folder: nothing here ever looks at the app folder, its path, the
// engine binary or VERSION, so replacing the app folder, an update and a roll back all read the same keys.
//
// Why a NEW engine binary is never locked out (Apple, `man security`, add-generic-password: "-T appPath Specify an
// application which may access this item", "-A Allow any application to access this item without warning (insecure,
// not recommended!)"; SecKeychainItemCreateFromContent with no initial access trusts only the calling application):
// the item is created WITHOUT -A and WITHOUT -T, so the one application macOS trusts with it is the process that made
// it -- /usr/bin/security, Apple-signed, the same path on every Mac and every version of this app. Every read goes
// through that same /usr/bin/security, so the reader matches the creator whatever engine binary started it (a new
// build has a new hash; it never touches the Keychain itself). -T with the engine's path would pin THIS build's hash
// and prompt after every update; -A would let any app read the keys silently. TestKeysSurviveFolderReplacement pins
// both flags absent.
func New(dataDir string, onValue func(string)) Store {
	if goos == "darwin" && os.Getenv("KAA_SECRETS_FILE") == "" {
		if _, err := os.Stat(securityPath); err == nil {
			return &keychain{cache: map[string]string{}, on: onValue, index: filepath.Join(dataDir, "keys-index.json"), run: run}
		}
	}
	return NewFile(dataDir, onValue)
}

// NewFile: the 0600 file store in dataDir, whatever the platform (-mock uses it, so a test run on a Mac never writes
// its throwaway keys over the owner's real ones in the Keychain: the Keychain item is service + name, shared by every
// data folder).
func NewFile(dataDir string, onValue func(string)) Store {
	return &fileStore{path: filepath.Join(dataDir, "secrets.json"), on: onValue}
}

// Key: one key's value for a client that asks on every request, remembering why the last read failed, so the client
// says "could not be read" instead of "no key saved" (when macOS refused the read after a restart, every Meta call
// said "no Meta token saved" although the token was in the Keychain).
type Key struct {
	s    Store
	name string
	mu   sync.Mutex
	err  error
}

func NewKey(s Store, name string) *Key { return &Key{s: s, name: name} }

// Value: the key, or "" (not set, or not readable: Err says which).
func (k *Key) Value() string {
	v, err := k.s.Get(k.name)
	k.mu.Lock()
	k.err = err
	k.mu.Unlock()
	return v
}

// Err: why the last Value() was "" (nil: plainly not set, or read fine).
func (k *Key) Err() error {
	k.mu.Lock()
	defer k.mu.Unlock()
	return k.err
}

// ── macOS Keychain ──

type keychain struct {
	mu    sync.Mutex
	cache map[string]string
	on    func(string)
	index string                                             // data folder: which keys were saved, when (never a value)
	run   func(stdin string, args ...string) (string, error) // /usr/bin/security (tests: a fake)
}

// keychain failures `security` reports when macOS did not let it read: the owner dismissed or did not answer the
// prompt, interaction is not allowed (no GUI session), the keychain is locked, or our wait ran out
var deniedRe = regexp.MustCompile(`(?i)user canceled|interaction is not allowed|not allowed|authori[sz]ation|denied|locked|exit status (25|36|45|51|128)\b|signal: killed|deadline exceeded`)

func (k *keychain) readIndex() map[string]string {
	m := map[string]string{}
	if b, err := os.ReadFile(k.index); err == nil {
		_ = json.Unmarshal(b, &m)
	}
	return m
}

func (k *keychain) writeIndex(name, at string) {
	if k.index == "" {
		return
	}
	m := k.readIndex()
	if m[name] == at && at != "" {
		return
	}
	if at == "" {
		delete(m, name)
	} else {
		m[name] = at
	}
	b, _ := json.MarshalIndent(m, "", " ")
	tmp := k.index + ".tmp"
	if os.WriteFile(tmp, b, 0o600) == nil {
		_ = os.Rename(tmp, k.index)
	}
}

func (k *keychain) Kind() string { return "keychain" }

func run(stdin string, args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second) // macOS may be asking the owner meanwhile
	defer cancel()
	cmd := exec.CommandContext(ctx, securityPath, args...)
	if stdin != "" {
		cmd.Stdin = strings.NewReader(stdin)
	}
	var out, errb bytes.Buffer
	cmd.Stdout, cmd.Stderr = &out, &errb
	err := cmd.Run()
	if err != nil {
		return "", fmt.Errorf("%v: %s", err, strings.TrimSpace(errb.String()))
	}
	return out.String(), nil
}

func (k *keychain) Get(name string) (string, error) {
	if !validName(name) {
		return "", fmt.Errorf("unknown secret %q", name)
	}
	k.mu.Lock()
	defer k.mu.Unlock()
	if v, ok := k.cache[name]; ok {
		return v, nil
	}
	out, err := k.run("", "find-generic-password", "-s", Service, "-a", name, "-w")
	if err != nil {
		if strings.Contains(err.Error(), "could not be found") || strings.Contains(err.Error(), "exit status 44") {
			if at := k.readIndex()[name]; at != "" { // saved once, gone now: say so (not cached: Try again re-reads)
				return "", &KeyError{Name: name, Missing: at}
			}
			k.cache[name] = ""
			return "", nil
		}
		// anything else is NOT "not set", and is not cached: the next read asks macOS again
		return "", &KeyError{Name: name, Denied: deniedRe.MatchString(err.Error()), Detail: clipErr(err.Error())}
	}
	v := strings.TrimRight(out, "\r\n")
	k.cache[name] = v
	if v != "" && k.readIndex()[name] == "" { // keys saved before the index existed join it on first read
		k.writeIndex(name, time.Now().Format("2 Jan 2006"))
	}
	if k.on != nil && v != "" {
		k.on(v)
	}
	return v, nil
}

func (k *keychain) Set(name, value string) error {
	if !validName(name) {
		return fmt.Errorf("unknown secret %q", name)
	}
	if err := ValidValue(value); err != nil {
		return err
	}
	// `security -i` reads commands from stdin: the key goes through a pipe, never argv. -U updates in place.
	cmd := fmt.Sprintf("add-generic-password -U -s \"%s\" -a \"%s\" -l \"%s\" -w \"%s\"\n", Service, name, Service, value)
	if _, err := k.run(cmd, "-i"); err != nil {
		if deniedRe.MatchString(err.Error()) {
			return fmt.Errorf("macOS didn't let the app save the key in the Keychain. Click “Always Allow” when macOS asks, then save again.")
		}
		return fmt.Errorf("Keychain: %v", clipErr(err.Error()))
	}
	// read it back: `security -i` can end with exit 0 when the command inside it failed, and a save that did not
	// happen must not say "saved" (the key would look gone at the next start)
	if out, err := k.run("", "find-generic-password", "-s", Service, "-a", name, "-w"); err != nil || strings.TrimRight(out, "\r\n") != value {
		why := "it was not there when read back"
		if err != nil {
			why = clipErr(err.Error())
		}
		return fmt.Errorf("The key did not reach the macOS Keychain (%s), so it was not saved. Try again; if it keeps failing, open Keychain Access and unlock the login keychain.", why)
	}
	k.mu.Lock()
	k.cache[name] = value
	k.writeIndex(name, time.Now().Format("2 Jan 2006"))
	k.mu.Unlock()
	if k.on != nil {
		k.on(value)
	}
	return nil
}

func (k *keychain) Delete(name string) error {
	if !validName(name) {
		return fmt.Errorf("unknown secret %q", name)
	}
	_, err := k.run("", "delete-generic-password", "-s", Service, "-a", name)
	if err != nil && !strings.Contains(err.Error(), "could not be found") {
		return err
	}
	k.mu.Lock()
	k.cache[name] = ""
	k.writeIndex(name, "")
	k.mu.Unlock()
	return nil
}

// ── 0600 file (Linux, tests) ──

type fileStore struct {
	mu   sync.Mutex
	path string
	m    map[string]string
	on   func(string)
}

func (f *fileStore) Kind() string { return "file" }

func (f *fileStore) load() error {
	if f.m != nil {
		return nil
	}
	f.m = map[string]string{}
	b, err := os.ReadFile(f.path)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	if err := json.Unmarshal(b, &f.m); err != nil {
		return err
	}
	for _, v := range f.m {
		if f.on != nil && v != "" {
			f.on(v)
		}
	}
	return nil
}

func (f *fileStore) save() error {
	b, _ := json.MarshalIndent(f.m, "", " ")
	tmp := f.path + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	_ = os.Chmod(tmp, 0o600)
	return os.Rename(tmp, f.path)
}

func (f *fileStore) Get(name string) (string, error) {
	if !validName(name) {
		return "", fmt.Errorf("unknown secret %q", name)
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	if err := f.load(); err != nil {
		return "", err
	}
	return f.m[name], nil
}

func (f *fileStore) Set(name, value string) error {
	if !validName(name) {
		return fmt.Errorf("unknown secret %q", name)
	}
	if err := ValidValue(value); err != nil {
		return err
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	if err := f.load(); err != nil {
		return err
	}
	f.m[name] = value
	if f.on != nil {
		f.on(value)
	}
	return f.save()
}

func (f *fileStore) Delete(name string) error {
	if !validName(name) {
		return fmt.Errorf("unknown secret %q", name)
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	if err := f.load(); err != nil {
		return err
	}
	delete(f.m, name)
	return f.save()
}

func clipErr(s string) string {
	s = strings.TrimSpace(s)
	if len(s) > 200 {
		s = s[:200]
	}
	return s
}
