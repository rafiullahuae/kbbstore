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
// gives itself the role when Meta allows it, and otherwise sends NOTHING new and says exactly what to switch on.

// MUTATION: drop the assigned_users POST in pageRole → no creative is made, red.
func TestAppGivesItselfThePageRole(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	m.AddPage("200000000001", "K-Beauty Bliss", true) // the owner's Page: no Advertiser role for the app
	if _, err := ac.Cycle(context.Background(), model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	assigned, made := false, 0
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "200000000001/assigned_users") && strings.Contains(w.Form["tasks"], "ADVERTISE") {
			assigned = true
		}
		if strings.HasSuffix(w.Path, "/adcreatives") && w.Status == 200 {
			made++
		}
	}
	if !assigned || made == 0 {
		t.Fatalf("assigned %v, creatives made %d: the app should give itself the role and then make the ads", assigned, made)
	}
}

// MUTATION: drop the withoutNew filter in Cycle → campaigns / ad sets are made and the creatives fail, red.
func TestNoPageRoleSendsNothingNew(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	m.AddPage("200000000001", "K-Beauty Bliss", true)
	m.SetAssignRefused(true)
	out, err := ac.Cycle(context.Background(), model.AutopilotAuto)
	if err != nil {
		t.Fatal(err)
	}
	pe, _ := out["page_error"].(string)
	if !strings.Contains(pe, "K-Beauty Bliss") || !strings.Contains(pe, "Assign assets") {
		t.Fatalf("page_error %q: it must name the Page and the fix", pe)
	}
	for _, w := range m.Writes() {
		for _, e := range []string{"/campaigns", "/adsets", "/adcreatives", "/ads"} {
			if strings.HasSuffix(w.Path, e) {
				t.Fatalf("something new was sent although the Page refuses the app: %s", w.Path)
			}
		}
	}
}
