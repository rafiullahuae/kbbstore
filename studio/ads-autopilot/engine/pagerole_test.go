package main

import (
	"context"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// The owner, 22 Nov: his Run it failed half-way with "Meta error 10/3858749: Application does not have permission
// for this action (To create posts for Page …, contact an admin to get permission for Advertiser role or higher)"
// after campaigns and ad sets were already made. Now the app checks the Page role before it sends anything new,
// never changes the Page's roles (it sits in another business portfolio), sends NOTHING new and says how to
// connect with his own login, which already advertises for the Page.

// MUTATION: drop the withoutNew filter in Cycle → campaigns / ad sets are made and the creatives fail, red.
func TestNoPageRoleSendsNothingNew(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	m.AddPage("200000000001", "K-Beauty Bliss", true)
	out, err := ac.Cycle(context.Background(), model.AutopilotAuto)
	if err != nil {
		t.Fatal(err)
	}
	pe, _ := out["page_error"].(string)
	if !strings.Contains(pe, "K-Beauty Bliss") || !strings.Contains(pe, "My own Facebook login") {
		t.Fatalf("page_error %q: it must name the Page and the fix", pe)
	}
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "/assigned_users") { // the owner: never touch the Page's roles
			t.Fatalf("the app changed the Page's roles: %s", w.Path)
		}
		for _, e := range []string{"/campaigns", "/adsets", "/adcreatives", "/ads"} {
			if strings.HasSuffix(w.Path, e) {
				t.Fatalf("something new was sent although the Page refuses the app: %s", w.Path)
			}
		}
	}
}

// The owner, 23 Nov: Run a cycle sat on "Reading Meta" for ever while Overview said "Meta is not connected". A cycle
// (and its preview) now stops at once with the connection's own reason, and the API answers it.
// MUTATION: drop the metaOK check in CycleOpts → it reads Meta (writes appear / no error), red.
func TestCycleStopsAtOnceWhenMetaIsNotConnected(t *testing.T) {
	ac, _ := testApp(t)
	a := ac.app
	a.mu.Lock()
	a.conns.Meta.OK, a.conns.Meta.Error = false, "The App secret is not the one of the Meta app this token was made with"
	a.mu.Unlock()
	t0 := time.Now()
	_, err := ac.CycleOpts(context.Background(), model.AutopilotAuto, cycleOpts{Manual: true, Preview: true})
	if err == nil || !strings.Contains(err.Error(), "not connected") || !strings.Contains(err.Error(), "App secret") || time.Since(t0) > 2*time.Second {
		t.Fatalf("a cycle with Meta not connected: %v after %s", err, time.Since(t0))
	}
}
