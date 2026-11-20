package main

import (
	"context"
	"strings"
	"testing"
	"time"

	"kaa/model"
	"kaa/secrets"
	"kaa/store"
)

// realKeychain: stands in for the owner's macOS Keychain -- what is in it, what was written to it, and whether macOS
// is refusing reads right now.
type realKeychain struct {
	items  map[string]string
	writes []string
	refuse bool
}

func (k *realKeychain) Get(n string) (string, error) {
	if k.refuse {
		return "", &secrets.KeyError{Name: n, Denied: true}
	}
	return k.items[n], nil
}
func (k *realKeychain) Set(n, v string) error {
	k.writes = append(k.writes, n)
	k.items[n] = v
	return nil
}
func (k *realKeychain) Delete(n string) error { delete(k.items, n); return nil }
func (k *realKeychain) Kind() string          { return "keychain" }

// A -mock run (every check script runs one) on the owner's Mac wrote its throwaway EAAMOCK… token and sk-ant-mock key
// into the macOS Keychain: the item is service + name, shared by every data folder, so a temp --data did not protect
// him and his real keys were overwritten with no trace. MUTATION: drop the NewFile swap in setupMock → red.
func TestMockNeverWritesTheOwnersKeychain(t *testing.T) {
	t.Setenv("KAA_SECRETS_FILE", "")
	st, err := store.Open(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = st.Close() })
	a := newApp(st, time.Now, nil)
	kc := &realKeychain{items: map[string]string{"meta_token": "EAAowner-real-token", "anthropic_key": "sk-ant-owner-real"}}
	a.sec = kc
	m, err := setupMock(a)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(m.Close)
	if len(kc.writes) != 0 || kc.items["meta_token"] != "EAAowner-real-token" {
		t.Fatalf("-mock wrote %v into the owner's Keychain: %v", kc.writes, kc.items)
	}
	if a.sec.Kind() != "file" {
		t.Fatalf("-mock keeps its keys in %s", a.sec.Kind())
	}
}

// After a restart macOS refused the Keychain read (prompt not answered, locked): every Meta call said "no Meta token
// saved (Settings → Connections → Meta)" and every Claude call "no Anthropic key saved" -- the owner's saved keys
// looked gone in Activity and in every failed cycle. A key that could not be read says so. MUTATION: drop TokenErr /
// KeyErr wiring in App.wire → red.
func TestUnreadableKeyIsNotNoKeySaved(t *testing.T) {
	t.Setenv("KAA_SECRETS_FILE", "1")
	st, err := store.Open(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = st.Close() })
	a := newApp(st, time.Now, nil)
	a.sec = &realKeychain{items: map[string]string{"meta_token": "EAAx", "anthropic_key": "sk-ant-x", "shop_token": "kbbap_x"}, refuse: true}
	a.testFeedURL = "http://127.0.0.1:1/feed.xml"
	a.wire()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	_, merr := a.meta.Portfolio(ctx)
	_, cerr := a.claude.Complete(ctx, model.LLMRequest{})
	for what, err := range map[string]error{"Meta": merr, "Claude": cerr} {
		if err == nil || strings.Contains(err.Error(), "saved (Settings") || !strings.Contains(err.Error(), "could not be read") || !strings.Contains(err.Error(), "Always Allow") {
			t.Errorf("%s with an unreadable key: %v", what, err)
		}
	}
}
