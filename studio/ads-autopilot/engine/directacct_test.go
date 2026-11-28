package main

import (
	"context"
	"testing"
)

// The owner, 28 Nov 2026: KBB24 was active, assigned to him and readable with his token in Graph API Explorer
// (account_status 1, AED), yet the app listed it red as "DISABLED" with no currency, because Meta's account lists left
// it out. An account the app knows that the lists miss is now read directly. Mutation: drop addDirectAccounts() from
// testMeta and this is red (no currency, status 0).
func TestAnAccountTheListsMissIsReadDirectly(t *testing.T) {
	ac, m := testApp(t)
	a := ac.app
	m.HideAccounts = true
	c := a.testMeta(context.Background())
	if !c.OK {
		t.Fatalf("meta not ok after the lists dropped the account: %+v", c)
	}
	var found bool
	for _, r := range a.portfolio.AdAccounts {
		if r.ID == m.Account {
			found = true
			if r.Currency == "" || r.Status != 1 || r.Relation != "direct" {
				t.Fatalf("read directly but %+v", r)
			}
		}
	}
	if !found {
		t.Fatalf("%s missing from the portfolio: %+v", m.Account, a.portfolio.AdAccounts)
	}
	s := &server{app: a}
	v := s.accountsView()
	for _, x := range v["accounts"].([]accountView) {
		if x.ID == m.Account && (x.Currency == "" || x.Status != 1) {
			t.Fatalf("the accounts list still shows it without its status: %+v", x)
		}
	}
}
