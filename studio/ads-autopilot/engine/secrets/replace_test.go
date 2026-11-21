package secrets

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// fakeMacSecurity: a stand-in for /usr/bin/security that answers the way macOS does. Items live in $KC (the login
// keychain: outside every app folder). $KC/mode picks macOS's answer to a READ:
//
//	ok      the item, or exit 44 "could not be found"
//	128     the owner dismissed the prompt ("User canceled the operation.")
//	36      no GUI session ("User interaction is not allowed.")
//	51      the keychain is locked / its password was not given
//	silent  (writes only) `security -i` exits 0 although the add inside it failed
//
// Each add records the flags it was given, so the test can pin that no item is tied to one engine binary.
const fakeMacSecurity = `#!/bin/bash
KC="${KC:?}"
mode="$(cat "$KC/mode" 2>/dev/null || echo ok)"
item() { printf '%s' "$KC/item-$(printf '%s|%s' "$1" "$2" | od -An -tx1 | tr -d ' \n')"; }
read_item() {
  case "$mode" in
    128) echo "security: SecKeychainSearchCopyNext: User canceled the operation." >&2; exit 128 ;;
    36)  echo "security: SecKeychainSearchCopyNext: User interaction is not allowed." >&2; exit 36 ;;
    51)  echo "security: SecKeychainSearchCopyNext: The user name or passphrase you entered is not correct." >&2; exit 51 ;;
  esac
  local f; f="$(item "$1" "$2")"
  if [ ! -f "$f" ]; then echo "security: SecKeychainSearchCopyNext: The specified item could not be found in the keychain." >&2; exit 44; fi
  cat "$f"; echo
}
add_item() { # add-generic-password -U -s S -a A -l L -w W
  shift; local s a w flags=""
  while [ $# -gt 0 ]; do
    case "$1" in -s) s="$2"; shift ;; -a) a="$2"; shift ;; -w) w="$2"; shift ;; -l) shift ;; -U) ;; *) flags="$flags $1" ;; esac
    shift
  done
  echo "add$flags" >> "$KC/adds.log"
  [ "$mode" = silent ] && { echo "add-generic-password: returned 51" >&2; return 0; }
  printf '%s' "$w" > "$(item "$s" "$a")"
}
case "$1" in
  find-generic-password) read_item "$3" "$5" ;;
  delete-generic-password) rm -f "$(item "$3" "$5")" ;;
  -i) while IFS= read -r line; do eval "set -- $line"; add_item "$@"; done ;;
  *) exit 2 ;;
esac
`

// The owner, 20 Nov 2026: "the keys should not vanished upon replacing files etc." Every way the app folder changes
// -- he drags a freshly downloaded folder over the old one, Settings → Updates installs a version and restarts, the
// start script rolls a version back -- the next start must read the same keys, and whatever macOS answers it must
// never show a saved key as "not set". The stub is macOS's own `security` behaviour; each "start" is a fresh store
// (empty cache) over the same data folder, with a different app folder and engine binary.
//
// MUTATION: put keys-index.json in the app folder (New(appDir…)) → "vanished from the Keychain" after replacement is
// red; add -T <engine> or -A to the add command → the flags pin is red; drop the read-back in Set → "silent" is red.
func TestKeysSurviveFolderReplacement(t *testing.T) {
	if _, err := exec.LookPath("bash"); err != nil {
		t.Skip("no bash")
	}
	root := t.TempDir()
	kc, data := filepath.Join(root, "Library", "Keychains"), filepath.Join(root, "KBB Ads Autopilot Data")
	for _, d := range []string{kc, data} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	sec := filepath.Join(root, "bin", "security")
	_ = os.MkdirAll(filepath.Dir(sec), 0o755)
	if err := os.WriteFile(sec, []byte(fakeMacSecurity), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("KC", kc)
	t.Setenv("KAA_SECRETS_FILE", "")
	oldPath, oldOS := securityPath, goos
	securityPath, goos = sec, "darwin"
	t.Cleanup(func() { securityPath, goos = oldPath, oldOS })
	mode := func(m string) { _ = os.WriteFile(filepath.Join(kc, "mode"), []byte(m), 0o600) }

	// an app folder: VERSION + an engine binary whose bytes (hash) differ per build
	appDir := filepath.Join(root, "Downloads", "KBB Ads Autopilot")
	makeApp := func(version string) {
		_ = os.RemoveAll(appDir)
		_ = os.MkdirAll(filepath.Join(appDir, "engine"), 0o755)
		_ = os.WriteFile(filepath.Join(appDir, "VERSION"), []byte(version), 0o644)
		_ = os.WriteFile(filepath.Join(appDir, "engine", "kaa-mac-arm64"), []byte("engine build "+version), 0o755)
	}
	start := func() Store {
		s := New(data, nil)
		if s.Kind() != "keychain" {
			t.Fatalf("the fake Mac did not get the Keychain store: %s", s.Kind())
		}
		return s
	}
	keys := map[string]string{"meta_token": "EAAmeta00001111", "anthropic_key": "sk-ant-api03-k2222", "shop_token": "kbbap_shop3333", "meta_app_secret": "0123456789abcdef0123456789ab4444"}

	makeApp("0.3.1")
	mode("ok")
	s := start()
	for n, v := range keys {
		if err := s.Set(n, v); err != nil {
			t.Fatal(err)
		}
	}
	// nothing tied to one engine build: no -T (trusts a binary's hash) and no -A (any app could read the keys)
	adds, _ := os.ReadFile(filepath.Join(kc, "adds.log"))
	if strings.Count(string(adds), "add\n") != len(keys) {
		t.Fatalf("the Keychain items were added with extra flags (-T pins one engine build, -A opens them to every app):\n%s", adds)
	}
	// nothing about the keys landed in the app folder
	_ = filepath.Walk(appDir, func(p string, fi os.FileInfo, err error) error {
		if err == nil && !fi.IsDir() && !strings.HasPrefix(fi.Name(), "kaa-") && fi.Name() != "VERSION" {
			t.Errorf("a key file in the app folder: %s", p)
		}
		return nil
	})

	check := func(when string) {
		t.Helper()
		for _, m := range []string{"ok", "128", "36", "51"} {
			mode(m)
			s := start()
			for n, v := range keys {
				d := Describe(s, n)
				switch {
				case m == "ok" && (!d.Set || d.Last4 != v[len(v)-4:]):
					t.Errorf("%s, macOS ok: %s = %+v", when, n, d)
				case m != "ok" && (!d.Set || d.Last4 != v[len(v)-4:]):
					// 21 Nov: the keys "vanished" on his Mac after updates; whatever the Keychain answers, the backup
					// in the data folder keeps every key readable
					t.Errorf("%s, macOS %s: the Keychain refused and the backup did not answer: %s = %+v", when, m, n, d)
				}
			}
			mode("ok") // he answers macOS, then Try again: the same store reads the key
			if d := Describe(s, "meta_token"); !d.Set {
				t.Errorf("%s, after macOS %s then Try again: %+v", when, m, d)
			}
		}
	}
	check("first start")

	makeApp("0.3.2") // he replaces the app folder with a freshly downloaded one, and starts it
	check("after replacing the app folder")

	makeApp("0.3.3") // Settings → Updates installs a newer version and the engine restarts into it
	check("after an in-app update")

	makeApp("0.3.2") // the start script puts the version before back
	check("after a roll back")

	// a key he removed in Keychain Access says so, it does not look never set
	// a key gone from the Keychain comes back from the backup, and the Keychain is repaired from it
	item := filepath.Join(kc, "item-"+hexOf(Service+"|shop_token"))
	_ = os.Remove(item)
	if d := Describe(start(), "shop_token"); !d.Set || d.Last4 != "3333" {
		t.Fatalf("a key removed from the Keychain did not come back from the backup: %+v", d)
	}
	if _, err := os.Stat(item); err != nil {
		t.Fatalf("the Keychain was not repaired from the backup: %v", err)
	}
	// both gone: it says so, it never looks never set
	_ = os.Remove(item)
	_ = os.Remove(filepath.Join(data, "keys-backup.json"))
	if d := Describe(start(), "shop_token"); d.Set || !strings.Contains(d.Error, "no longer in the macOS Keychain") {
		t.Fatalf("a key removed from the Keychain and the backup: %+v", d)
	}
	// the Keychain refuses and the backup is gone: the refusal is said, with what to do
	mode("128")
	if d := Describe(start(), "meta_token"); d.Set || !strings.Contains(d.Error, "Always Allow") {
		t.Fatalf("a refused read with no backup: %+v", d)
	}

	// `security -i` exiting 0 although the add failed: the save must not say "saved"
	mode("silent")
	// a save that never reached the Keychain is still kept in the backup, and reads back
	s2 := start()
	if err := s2.Set("shop_token", "kbbap_new5555"); err != nil {
		t.Fatalf("the backup did not take the key the Keychain refused: %v", err)
	}
	if v, _ := s2.Get("shop_token"); v != "kbbap_new5555" {
		t.Fatalf("a key kept only in the backup reads back as %q", v)
	}
	// the Keychain store on its own still refuses to say "saved" (the read-back in keychain.Set)
	if err := s2.(*mirror).kc.Set("shop_token", "kbbap_new6666"); err == nil || !strings.Contains(err.Error(), "did not reach the macOS Keychain") {
		t.Fatalf("a save that never reached the Keychain answered %v", err)
	}
}

func hexOf(s string) string {
	const h = "0123456789abcdef"
	b := make([]byte, 0, len(s)*2)
	for i := 0; i < len(s); i++ {
		b = append(b, h[s[i]>>4], h[s[i]&15])
	}
	return string(b)
}
