package main

import (
	"testing"

	"kaa/secrets"
)

// The owner, 21 Nov (three times): after an update Settings → Connections showed empty key fields, "Not connected"
// and 0 ad accounts — the cached test result is blank until the first test of the new engine finishes — which read
// exactly as "the keys vanished", so he pasted them again. Before the first test the page now gets the SAVED keys,
// read live, marked checking. MUTATION: return a.conns unchanged from ConnectionsView and this test fails.
func TestConnectionsBeforeTheFirstTestShowTheSavedKeys(t *testing.T) {
	a := &App{sec: secrets.NewFile(t.TempDir(), nil)}
	a.cfg.ShopFeedURL = "https://kbeautybliss.com/feeds/meta-catalog.xml"
	for n, v := range map[string]string{"meta_token": "EAAowner1234", "anthropic_key": "sk-ant-api03-x8QAA", "shop_token": "kbbap_YZ0M"} {
		if err := a.sec.Set(n, v); err != nil {
			t.Fatal(err)
		}
	}
	a.firstTest.Store(true) // no live test in a unit test
	c := a.ConnectionsView()
	if !c.Checking || c.Checked {
		t.Fatalf("not marked checking: %+v", c)
	}
	if !c.Meta.Token.Set || c.Meta.Token.Last4 != "1234" || !c.Claude.Key.Set || !c.Shop.Token.Set || c.Shop.FeedURL == "" {
		t.Fatalf("saved keys not shown before the first test: meta %+v claude %+v shop %+v feed %q", c.Meta.Token, c.Claude.Key, c.Shop.Token, c.Shop.FeedURL)
	}
	a.conns = Connections{Checked: true}
	if c := a.ConnectionsView(); c.Checking {
		t.Fatal("after the first test the tested result is served")
	}
}
