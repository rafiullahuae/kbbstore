package meta

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// The owner, 28 Nov 2026: KBB24 showed "NOT VISIBLE TO YOUR META TOKEN (Meta says: context deadline exceeded)". The
// Test had walked his businesses (me/..., business edges) and filled the ONE pacing budget the client kept, so the
// direct read of act_1120714123764221 waited in the queue past its 20 s and never reached Meta. Meta scores PER AD
// ACCOUNT, so the pacing does too. Mutation: make paceKey return "" for every path and this test is red (the read
// waits for the window).
func TestAnAccountReadIsNotQueuedBehindThePortfolioWalk(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"id":"act_24","name":"KBB24","currency":"AED","account_status":1}`))
	}))
	defer srv.Close()
	c := New(func() string { return "EAATOKEN" })
	c.Base = srv.URL
	waited := 0
	c.OnWait = func(time.Duration, string) { waited++ }
	for i := 0; i < 48; i++ { // the walk: the whole Limited budget (60 × 80%) on non-account reads
		var out map[string]any
		if err := c.Get(context.Background(), "me/businesses", nil, &out); err != nil {
			t.Fatal(err)
		}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	acc, err := c.Account(ctx, "act_24")
	if err != nil || acc.Name != "KBB24" || acc.Currency != "AED" || waited != 0 {
		t.Fatalf("account read queued behind the walk: %+v %v waited=%d", acc, err, waited)
	}
	// and one account's own score still paces that account
	if paceKey("act_24/campaigns") != "act_24" || paceKey("/act_24") != "act_24" || paceKey("me/adaccounts") != "" || paceKey("1234567") != "" {
		t.Fatal("paceKey")
	}
}
