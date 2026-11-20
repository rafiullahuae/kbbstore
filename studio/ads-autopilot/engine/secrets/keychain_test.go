package secrets

import (
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// fakeSecurity: /usr/bin/security as the tests want it -- a store, plus a forced answer for reads
type fakeSecurity struct {
	items   map[string]string
	readErr error
}

func (f *fakeSecurity) run(stdin string, args ...string) (string, error) {
	switch args[0] {
	case "find-generic-password":
		if f.readErr != nil {
			return "", f.readErr
		}
		v, ok := f.items[args[4]]
		if !ok {
			return "", errors.New("exit status 44: security: SecKeychainSearchCopyNext: The specified item could not be found in the keychain.")
		}
		return v + "\n", nil
	case "-i":
		m := regexp.MustCompile(`-a "([^"]+)" -l "[^"]+" -w "([^"]+)"`).FindStringSubmatch(stdin)
		f.items[m[1]] = m[2]
	case "delete-generic-password":
		delete(f.items, args[4])
	}
	return "", nil
}

// The owner, 20 Nov 2026: after replacing the app folder every key showed "not set". A Keychain read that macOS
// refuses (prompt dismissed / not answered, interaction not allowed, locked) or that fails for any other reason is
// NEVER "not set": Describe says why, the failure is not cached (Try again reads again), and the keys come back the
// moment macOS answers. MUTATION: return "", nil for every error in keychain.Get (the old behaviour) → red;
// cache the failed read → "after Try again" is red.
func TestKeychainReadErrorIsNotNotSet(t *testing.T) {
	dir := t.TempDir()
	fs := &fakeSecurity{items: map[string]string{}}
	k := &keychain{cache: map[string]string{}, index: filepath.Join(dir, "keys-index.json"), run: fs.run}
	if err := k.Set("meta_token", "EAAabc123456789"); err != nil {
		t.Fatal(err)
	}
	for name, e := range map[string]string{
		"prompt dismissed": "exit status 128: security: SecKeychainSearchCopyNext: User canceled the operation.",
		"no GUI":           "exit status 36: security: User interaction is not allowed.",
		"not answered":     "signal: killed: ",
		"other":            "exit status 1: security: something unexpected",
	} {
		t.Run(name, func(t *testing.T) {
			k2 := &keychain{cache: map[string]string{}, index: k.index, run: fs.run} // a fresh engine start
			fs.readErr = errors.New(e)
			d := Describe(k2, "meta_token")
			if d.Set || d.Error == "" {
				t.Fatalf("a failed read shown as %+v", d)
			}
			if name != "other" && !strings.Contains(d.Error, "Always Allow") {
				t.Fatalf("macOS refusing is not explained: %q", d.Error)
			}
			fs.readErr = nil // he clicks Always Allow, then Try again
			if d := Describe(k2, "meta_token"); !d.Set || d.Last4 != "6789" {
				t.Fatalf("after Try again: %+v", d)
			}
		})
	}
	// a key saved once and since removed from the Keychain says so; one never saved is plainly not set
	delete(fs.items, "meta_token")
	k3 := &keychain{cache: map[string]string{}, index: k.index, run: fs.run}
	if d := Describe(k3, "meta_token"); d.Set || !strings.Contains(d.Error, "no longer in the macOS Keychain") {
		t.Fatalf("a vanished key: %+v", d)
	}
	if d := Describe(k3, "shop_token"); d.Set || d.Error != "" {
		t.Fatalf("a never-saved key: %+v", d)
	}
	// nothing about the keys lives in the app folder: the index is in the data folder and holds no value
	b, _ := os.ReadFile(k.index)
	if strings.Contains(string(b), "EAAabc") || !strings.Contains(string(b), "meta_token") {
		t.Fatalf("index: %s", b)
	}
}

// A key pasted into the wrong field is refused before it is stored (the owner put his Meta token into the Claude
// field). MUTATION: return nil from CheckFormat → red.
func TestCheckFormat(t *testing.T) {
	bad := map[[2]string]string{
		{"anthropic_key", "EAAabc123456789"}:     "Meta token",
		{"meta_token", "sk-ant-api03-abcdef"}:    "Claude key",
		{"shop_token", "EAAabc123456789"}:        "Meta token",
		{"anthropic_key", "hello123"}:            "start with sk-ant-",
		{"meta_app_secret", "sk-ant-api03-abcd"}: "Claude key",
	}
	for k, want := range bad {
		if err := CheckFormat(k[0], k[1]); err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%s=%s: %v", k[0], k[1], err)
		}
	}
	for k, v := range map[string]string{"anthropic_key": "sk-ant-api03-x", "meta_token": "EAAx", "shop_token": "kbbap_x", "meta_app_secret": "0123456789abcdef0123456789abcdef"} {
		if err := CheckFormat(k, v); err != nil {
			t.Errorf("%s refused: %v", k, err)
		}
	}
}
