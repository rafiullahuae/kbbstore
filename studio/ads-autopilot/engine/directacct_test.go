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

// The owner, 28 Nov 2026, after 0.6.25: "Meta says: context canceled" -- the portfolio walk had used up the request's
// time before the direct read began. The read now has its own time. Mutation: pass ctx straight to mc.Account and
// this is red.
func TestTheDirectReadSurvivesASpentRequest(t *testing.T) {
	ac, m := testApp(t)
	a := ac.app
	m.HideAccounts = true
	p, err := a.meta.Portfolio(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	spent, cancel := context.WithCancel(context.Background())
	cancel()
	a.addDirectAccounts(spent, a.meta, &p)
	for _, r := range p.AdAccounts {
		if r.ID == m.Account && r.Status == 1 && r.Currency != "" {
			return
		}
	}
	t.Fatalf("not read with a spent context: %+v", p.AdAccounts)
}
