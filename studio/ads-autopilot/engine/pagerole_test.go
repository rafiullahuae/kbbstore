package main

import (
	"context"
	"strings"
	"testing"

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
	if !strings.Contains(pe, "K-Beauty Bliss") || !strings.Contains(pe, "My user token") {
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
