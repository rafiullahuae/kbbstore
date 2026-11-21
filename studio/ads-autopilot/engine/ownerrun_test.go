package main

import (
	"context"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// The owner, 22 Nov: the catch-up of 0.6.4–0.6.11 queued EVERY approved creative (six jobs, 71 ads) though he
// meant two routines. Now nothing approved long ago launches by itself, and those catch-up jobs are skipped; what
// he presses Run it on (after seeing it) runs. MUTATION: call ownerBacklog in refreshJobs again → red.
func TestOldApprovalsDoNotLaunchBySelf(t *testing.T) {
	ac, _ := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	c, err := ac.creatives.Add(model.Creative{ID: "cc-aaaaaaaa1ar-4x5", Lang: "ar", Format: "4:5", PNG: tinyPNG, Headline: "Routine", PrimaryText: "Korean skincare", Link: "https://kbeautybliss.com/p/", Source: "owner"}, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	c.Approved = true
	_ = ac.creatives.Put(c)
	js := ac.jobSet()
	js.Jobs = append(js.Jobs, Job{ID: "run-legacy", Kind: "owner_run", Status: "approved", When: time.Now().Add(-time.Hour), Creatives: []string{"cc-x"},
		Why: "You pressed Run it on these: they go live now (before this version Run it only marked them for the planner)."})
	ac.saveJobs(js)
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	for _, j := range ac.jobSet().Jobs {
		if j.Kind == "owner_run" && j.ID != "run-legacy" {
			t.Fatalf("an old approval was queued by itself: %+v", j)
		}
		if j.ID == "run-legacy" && j.Status != "skipped" {
			t.Fatalf("the catch-up job was not skipped: %s", j.Status)
		}
	}
	got, _, _ := ac.creatives.Get(c.ID)
	if got.MetaID != "" {
		t.Fatal("an old approval went to Meta by itself")
	}
	_ = strings.Contains
}
