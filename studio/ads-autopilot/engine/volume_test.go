package main

// Tests for the activity level, the daily plan and close monitoring (the owner, 21 Nov 2026: "the system should create
// more campaigns / ad etc. ... plan super well on daily basis and monitor closely"). Each says the defect it guards
// and the mutation that turns it red.

import (
	"context"
	"strings"
	"testing"
	"time"

	"kaa/brain"
	"kaa/model"
)

func lvl(l string) model.Playbook {
	pb := model.DefaultPlaybook()
	pb.Knobs.ActivityLevel = l
	return pb.Normalize()
}

// Active (the default) targets 8–12 live ads with batches of 4–6 on Mon + Thu; Aggressive 12–20 on Mon/Wed/Fri;
// Steady keeps today's weekly batch. His Max active ads cuts the target and says so. MUTATION: drop the MaxActiveAds
// cap in volumeFor → the 6-ad limit case aims for 12, red.
func TestActivityLevelTargets(t *testing.T) {
	lim := model.Limits{DailyAED: 300, MonthlyAED: 9000, MaxActiveAds: 20}
	v := volumeFor(lvl(""), lim, 60, nil)
	if v.Level != "active" || v.LiveMin != 8 || v.LiveMax != 12 || v.BatchMin != 4 || v.BatchMax != 6 || strings.Join(v.BatchDays, ",") != "mon,thu" || v.IterateHours != 48 || !v.RefillSameDay {
		t.Fatalf("active %+v", v)
	}
	v = volumeFor(lvl("aggressive"), lim, 60, nil)
	if v.LiveMin != 12 || v.LiveMax != 20 || len(v.BatchDays) != 3 || v.MaxCampaigns != 3 {
		t.Fatalf("aggressive %+v", v)
	}
	if v = volumeFor(lvl("steady"), lim, 60, nil); v.LiveMax != 0 || len(v.BatchDays) != 1 || v.MaxCampaigns != 1 {
		t.Fatalf("steady %+v", v)
	}
	lim.MaxActiveAds = 6
	v = volumeFor(lvl("active"), lim, 60, nil)
	if !v.LimitCapped || v.LiveMax > 6 || !strings.Contains(v.Why, "Max active ads") {
		t.Fatalf("limit not respected: %+v", v)
	}
	if lvl("loud").Knobs.ActivityLevel != "" {
		t.Fatal("an unknown level was stored")
	}
}

// No extra campaign unless its budget feeds ~50 purchases a week: at AED 300/day and target CPA AED 60 a 2nd
// campaign needs AED 429/day and is NOT built (the UI says why with both numbers); at AED 2,000/day it is planned, a
// 3rd only on Aggressive. MUTATION: drop the "ProspectingAED < NeedAED" test → a starved 2nd campaign at 300/day, red.
func TestNoExtraCampaignWithoutLearningBudget(t *testing.T) {
	v := volumeFor(lvl("active"), model.Limits{DailyAED: 300, MonthlyAED: 9000}, 60, nil)
	if v.Campaigns[1].Status != "needs_budget" || v.NeedPerCampAED != 429 || !strings.Contains(v.Campaigns[1].Why, "AED 429/day") || !strings.Contains(v.Campaigns[1].Why, "you're at AED 210/day") {
		t.Fatalf("300/day: %+v", v.Campaigns)
	}
	v = volumeFor(lvl("active"), model.Limits{DailyAED: 2000, MonthlyAED: 60000}, 60, nil)
	if v.Campaigns[1].Status != "planned" || v.Campaigns[2].Status != "not_at_this_level" {
		t.Fatalf("2000/day active: %+v", v.Campaigns)
	}
	v = volumeFor(lvl("aggressive"), model.Limits{DailyAED: 1600, MonthlyAED: 48000}, 60, nil)
	if v.Campaigns[1].Status != "planned" || v.Campaigns[2].Status != "needs_budget" { // 3 × 429 = 1,287 > 70% of 1,600
		t.Fatalf("2000/day aggressive: %+v", v.Campaigns)
	}
	// in the job queue: nothing at 300/day
	ac, _ := testApp(t)
	ac.app.brain = quietBrain{}
	if _, err := ac.Cycle(context.Background(), model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	if j := ac.jobByID("vol-campaign-2"); j != nil {
		t.Fatalf("a starved campaign was planned: %+v", j)
	}
}

func mondayAt(h int) time.Time {
	d := time.Date(2026, 11, 23, h, 0, 0, 0, time.FixedZone("GST", 4*3600)) // Monday
	return d
}

// Active yields twice-weekly batches of 4–6 within the limits: on a Monday morning the queue holds Monday's and
// Thursday's batches; at 09:05 runDue launches Monday's and the account stays inside Max active ads; the next cycle
// on the same day launches no second batch. MUTATION: plan batches on "mon" only → no Thursday batch, red; drop the
// slot accounting (pending) in dueJobs → more live ads than the limit, red.
func TestActiveBatchesTwiceWeeklyWithinLimits(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	ac.app.now = func() time.Time { return mondayAt(6).AddDate(0, 0, -1) }
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil { // the first launch (Sunday)
		t.Fatal(err)
	}
	ac.settings.Limits.MaxActiveAds = 14
	ac.app.now = func() time.Time { return mondayAt(8) }
	ac.mu.Lock()
	ac.lastCycle = time.Time{}
	ac.mu.Unlock()
	js := ac.jobSet()
	in := ac.input()
	ac.planCadence(in, &js, ac.app.now())
	ac.saveJobs(js)
	mon, thu := 0, 0
	for _, j := range ac.jobSet().Jobs {
		switch {
		case strings.HasPrefix(j.ID, "vol-20261123-") && !strings.Contains(j.ID, "fill"):
			mon++
		case strings.HasPrefix(j.ID, "vol-20261126-"):
			thu++
		}
	}
	if mon < 4 || mon > 6 || thu < 4 || thu > 6 {
		t.Fatalf("batches: Monday %d, Thursday %d; jobs %v", mon, thu, jobIDs(ac))
	}
	live0, _ := liveProspecting(ac.input().Tree)
	ac.app.now = func() time.Time { return mondayAt(9).Add(5 * time.Minute) }
	ac.settings.Automated = true
	if _, err := ac.runDue(ctx); err != nil {
		t.Fatal(err)
	}
	if err := ac.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	live1, _ := liveProspecting(ac.input().Tree)
	all := 0
	for _, s := range objStatus(m, "ads", "KAA:") {
		if s == "ACTIVE" {
			all++
		}
	}
	t.Logf("Monday batch %d, Thursday batch %d; live prospecting ads %d → %d; all active %d (limit 14)", mon, thu, live0, live1, all)
	if live1 <= live0 || all > 14 {
		t.Fatalf("live prospecting ads %d → %d, active ads %d (limit 14); jobs %v", live0, live1, all, jobIDs(ac))
	}
	n := len(m.Writes())
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	for _, w := range m.Writes()[n:] {
		if strings.HasSuffix(w.Path, "/ads") && strings.Contains(w.Form["name"], "Prospecting") && live1 >= 8 {
			t.Fatalf("a second batch on the same day: %v", w.Form["name"])
		}
	}
}

func jobIDs(ac *Acct) []string {
	var out []string
	for _, j := range ac.jobSet().Jobs {
		out = append(out, j.ID+"="+j.Status+"("+j.Waiting+")")
	}
	return out
}

// A rejected ad is replaced: the hourly check queues a reworded version at once (new copy, never the rejected
// creative again) and the job pauses the rejected ad. MUTATION: drop the DISAPPROVED branch in watch → no job, red;
// make jobCreative return starterCreative for a reword → the same creative again, red.
func TestRejectedAdReplacedReworded(t *testing.T) {
	ac, _ := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	var adID, crID string
	ac.mu.Lock()
	for ci := range ac.tree {
		for si := range ac.tree[ci].AdSets {
			for ai := range ac.tree[ci].AdSets[si].Ads {
				a := &ac.tree[ci].AdSets[si].Ads[ai]
				if adID == "" && ac.tree[ci].Managed && ac.tree[ci].Objective == "OUTCOME_SALES" && a.Status == "ACTIVE" {
					a.EffectiveStatus, a.ReviewFeedback = "DISAPPROVED", map[string]string{"Personal Health": "negative self-perception"}
					adID, crID = a.ID, a.CreativeID
				}
			}
		}
	}
	ac.mu.Unlock()
	if adID == "" {
		t.Fatal("no live ad")
	}
	ac.work.Lock()
	w := ac.watch(ctx)
	ac.work.Unlock()
	j := ac.jobByID("reword-" + jobPID.ReplaceAllString(adID, "-"))
	if j == nil || j.Kind != "reword" || j.Target != adID || w.Rewords != 1 || !strings.Contains(j.CopyDirection, "negative self-perception") {
		t.Fatalf("reword job %+v (rewords %d)", j, w.Rewords)
	}
	ps, why := ac.materialise(ctx, ac.input(), *j, ac.env(model.AutopilotAuto))
	var pause, newCr string
	for _, p := range ps {
		if p.Kind == model.KindPause {
			pause = p.Target
		}
		if p.Kind == model.KindUploadCreative {
			newCr = p.Target
		}
	}
	old := ""
	for _, c := range ac.creatives.all() {
		if c.MetaID == crID || c.ID == crID {
			old = c.ID
		}
	}
	if why != "" || pause != adID || newCr == "" || newCr == old {
		t.Fatalf("materialised %q: pause %s new creative %s (rejected %s)", why, pause, newCr, old)
	}
	ac.work.Lock()
	ac.watch(ctx) // the next hour: not queued twice
	ac.work.Unlock()
	n := 0
	for _, x := range ac.jobSet().Jobs {
		if x.Kind == "reword" {
			n++
		}
	}
	if n != 1 {
		t.Fatalf("%d reword jobs", n)
	}
}

type dailyBrain struct {
	quietBrain
	asks []brain.DailyAsk
}

func (d *dailyBrain) CreationMode(string) string { return brain.CreationWithMe }
func (d *dailyBrain) RaiseDaily(_ string, _ time.Time, _ string, a []brain.DailyAsk) int {
	d.asks = append(d.asks, a...)
	return len(a)
}

// The daily plan is written once per day (first open, or from 07:00), with yesterday's numbers from the shop and the
// day's jobs, and raises a creation request for every ad today's jobs need that the library does not have.
// MUTATION: drop the "d.Date == today" check in maybeDailyPlan → a second plan, red; drop the libHas filter → asks
// for products the library already covers, red.
func TestDailyPlanOncePerDayAndCreationRequests(t *testing.T) {
	ac, _ := testApp(t)
	db := &dailyBrain{}
	ac.app.brain = db
	ctx := context.Background()
	ac.app.now = func() time.Time { return mondayAt(6).AddDate(0, 0, -1) }
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	ac.settings.Limits.MaxActiveAds = 14
	ac.app.now = func() time.Time { return mondayAt(6) }
	if ac.maybeDailyPlan(ctx, false) {
		t.Fatal("written before 07:00 while the app was already open")
	}
	if !ac.maybeDailyPlan(ctx, true) {
		t.Fatal("not written at the first open of the day")
	}
	ac.app.now = func() time.Time { return mondayAt(7).Add(30 * time.Minute) }
	if ac.maybeDailyPlan(ctx, false) {
		t.Fatal("written twice on one day")
	}
	d, _ := ac.dailyPlan()
	if d.Date != "2026-11-23" || d.Level != "active" || len(d.Today) < 4 || d.TextEN == "" || d.TextAR == "" || !strings.Contains(d.TextEN, "shop orders") {
		t.Fatalf("plan %+v", d)
	}
	batch := 0
	for _, l := range d.Today {
		if strings.Contains(l.Text, "09:00 · Test batch") {
			batch++
		}
	}
	if batch < 4 || len(db.asks) < batch || d.Create.Needed != len(db.asks) || d.Create.Mode != "with_me" {
		t.Fatalf("%d batch lines, %d asks, create %+v", batch, len(db.asks), d.Create)
	}
	// a product the library covers (an unused ad of his) is not asked for
	p := db.asks[0]
	if _, err := ac.creatives.Add(model.Creative{ID: "his-1", Lang: p.Lang, Format: "9:16", PNG: tinyPNG, Headline: "h", PrimaryText: "t", Link: "https://kbeautybliss.com/p/", ProductIDs: p.ProductIDs, Source: "owner"}, time.Now()); err != nil {
		t.Fatal(err)
	}
	db.asks = nil
	ac.app.now = func() time.Time { return mondayAt(7).AddDate(0, 0, 1) } // Tuesday 07:00
	if !ac.maybeDailyPlan(ctx, false) {
		t.Fatal("no plan the next morning at 07:00")
	}
	for _, a := range db.asks {
		if a.ProductIDs[0] == p.ProductIDs[0] && a.Lang == p.Lang {
			t.Fatalf("asked for a product the library covers: %+v", a)
		}
	}
}
