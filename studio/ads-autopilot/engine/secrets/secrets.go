// Package secrets keeps the owner's keys: the macOS Keychain on a Mac (service "KBB Ads Autopilot", one generic
// password per name, written through `security -i` on stdin so a key never appears in a process list), a 0600 JSON
// file in the data folder anywhere else (the Linux test runs). The browser only ever sees {set, last4}.
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
}

// Describe: what the UI may know about a secret.
func Describe(s Store, name string) Info {
	v, err := s.Get(name)
	if err != nil || v == "" {
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

// New picks the Keychain on macOS, the file elsewhere. onValue is told every value loaded or saved (the audit log
// registers it for redaction).
func New(dataDir string, onValue func(string)) Store {
	if runtime.GOOS == "darwin" && os.Getenv("KAA_SECRETS_FILE") == "" {
		if _, err := os.Stat("/usr/bin/security"); err == nil {
			return &keychain{cache: map[string]string{}, on: onValue}
		}
	}
	return &fileStore{path: filepath.Join(dataDir, "secrets.json"), on: onValue}
}

// ── macOS Keychain ──

type keychain struct {
	mu    sync.Mutex
	cache map[string]string
	on    func(string)
}

func (k *keychain) Kind() string { return "keychain" }

func run(stdin string, args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "/usr/bin/security", args...)
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
	out, err := run("", "find-generic-password", "-s", Service, "-a", name, "-w")
	if err != nil {
		if strings.Contains(err.Error(), "could not be found") || strings.Contains(err.Error(), "exit status 44") {
			k.cache[name] = ""
			return "", nil
		}
		return "", err
	}
	v := strings.TrimRight(out, "\r\n")
	k.cache[name] = v
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
	if _, err := run(cmd, "-i"); err != nil {
		return fmt.Errorf("Keychain: %v", err)
	}
	k.mu.Lock()
	k.cache[name] = value
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
	_, err := run("", "delete-generic-password", "-s", Service, "-a", name)
	if err != nil && !strings.Contains(err.Error(), "could not be found") {
		return err
	}
	k.mu.Lock()
	k.cache[name] = ""
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
