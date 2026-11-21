package main

import (
	"context"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// The owner, 21 Nov: "I pressed Run on two routines ... in the ad account i can not see any those ads". Run it
// (0.6.3 and before) only set Creative.Approved and told the planner; nothing put them live. Creatives left in that
// state get a run job at the next cycle and go live in the prospecting ad set. Remove ownerBacklog from refreshJobs
// and this is red (0 ads with those creatives).
func TestOldRunItApprovalsGoLive(t *testing.T) {
	ac, _ := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil { // the first launch: the prospecting campaign
		t.Fatal(err)
	}
	var ids []string
	for _, x := range []struct{ id, lang string }{{"cc-aaaaaaaa1ar-4x5", "ar"}, {"cc-aaaaaaaa1en-4x5", "en"}} {
		c, err := ac.creatives.Add(model.Creative{ID: x.id, Lang: x.lang, Format: "4:5", PNG: tinyPNG, Headline: "Routine " + x.lang, PrimaryText: "Korean skincare", Link: "https://kbeautybliss.com/p/", Source: "owner"}, time.Now())
		if err != nil {
			t.Fatal(err)
		}
		c.Approved = true // what the old Run it left behind
		if err := ac.creatives.Put(c); err != nil {
			t.Fatal(err)
		}
		ids = append(ids, c.ID)
	}
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	// with every slot taken the job WAITS and says why (never pauses a running ad for them)
	for _, j := range ac.jobSet().Jobs {
		if j.Kind == "owner_run" && (j.Status != "approved" || !strings.Contains(j.Waiting, "no free ad slot")) {
			t.Fatalf("a full account: the run job must wait and say so: %s / %q", j.Status, j.Waiting)
		}
	}
	l := ac.settings.Limits
	l.MaxActiveAds = 20 // he raises Max active ads
	ac.settings.Limits = l
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	live := liveMeta(ac.input().Tree)
	n := 0
	for _, id := range ids {
		c, _, _ := ac.creatives.Get(id)
		if c.MetaID != "" && live[c.MetaID] {
			n++
		}
	}
	var job *Job
	for _, j := range ac.jobSet().Jobs {
		if j.Kind == "owner_run" {
			j := j
			job = &j
		}
	}
	if n != 2 || job == nil || job.Status != "done" {
		t.Fatalf("%d of 2 approved creatives live; run job %+v", n, job)
	}
	// a second cycle: nothing new
	before := len(liveMeta(ac.input().Tree))
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	if after := len(liveMeta(ac.input().Tree)); after != before {
		t.Fatalf("a second cycle changed the live creatives %d -> %d", before, after)
	}
}
