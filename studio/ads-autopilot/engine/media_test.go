package main

// The media-buying lane (20 Nov 2026): the owner's Playbook in code, the professional funnel on the wire, the
// editable job queue, occasions behind his approval, and the readiness that says why nothing waits. Each test says
// the defect it would catch and the mutation that turns it red.

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// spyBrain records what the planner was shown.
type spyBrain struct {
	quietBrain
	seen *model.CycleInput
}

func (b spyBrain) Cycle(_ context.Context, in model.CycleInput) (model.CycleOutput, error) {
	*b.seen = in
	return model.CycleOutput{Proposals: []model.Proposal{}}, nil
}

func ids(ps []model.Product) map[string]bool {
	m := map[string]bool{}
	for _, p := range ps {
		m[p.ID] = true
	}
	return m
}

// Avoid never reaches the planner or the first launch; a pin is always considered. Before, the planner saw the whole
// feed ("not the old like snail mucin"). MUTATION: hand the brain shopSnap instead of fshop in Acct.Cycle → red.
func TestPlaybookAvoidNeverReachesThePlanner(t *testing.T) {
	ac, m := testApp(t)
	var seen model.CycleInput
	ac.app.brain = spyBrain{seen: &seen}
	pb := model.DefaultPlaybook()
	pb.Avoid = []model.PBRule{{Kind: "keyword", Value: "snail mucin"}, {Kind: "brand", Value: "Medicube"}}
	pb.Focus = []model.PBRule{{Kind: "product", Value: "3195"}}
	pb.Version = 1
	ac.pb = &pb
	if _, err := ac.Cycle(context.Background(), model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	got := ids(seen.Shop.Products)
	if got["2210"] || got["8015"] || !got["3195"] || seen.Playbook == nil || seen.Playbook.Instructions == "" {
		t.Fatalf("planner saw %v (playbook %v)", got, seen.Playbook != nil)
	}
	for _, o := range m.Objects("adcreatives") { // nothing launched names an avoided product
		spec, _ := json.Marshal(o)
		if strings.Contains(string(spec), "snail") || strings.Contains(string(spec), "medicube") {
			t.Fatalf("an avoided product was launched: %s", spec)
		}
	}
	// the pin is advertised in the first launch
	found := false
	for _, c := range ac.creatives.all() {
		for _, id := range c.ProductIDs {
			found = found || id == "3195"
		}
	}
	if !found {
		t.Fatal("the pinned product was not in the first launch")
	}
}

// The funnel as Meta receives it: Advantage+ sales with lowest cost, new-customer ad set excluding buyers with
// 7-day click / 1-day view, catalog retargeting with product_audience_specs and an in-stock + allowed product set,
// every create PAUSED with an idempotency key. MUTATION: drop the attribution_spec line in createAdSet → red.
func TestFunnelOnTheWire(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	if _, err := ac.Cycle(context.Background(), model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	var sales, cat, newSet, catSet, ps map[string]string
	for _, w := range m.Writes() {
		if w.Status != 200 {
			continue
		}
		switch {
		case strings.HasSuffix(w.Path, "/campaigns") && w.Form["objective"] == "OUTCOME_SALES":
			sales = w.Form
		case strings.HasSuffix(w.Path, "/campaigns") && w.Form["objective"] == "PRODUCT_CATALOG_SALES":
			cat = w.Form
		case strings.HasSuffix(w.Path, "/adsets") && strings.Contains(w.Form["name"], "new customers"):
			newSet = w.Form
		case strings.HasSuffix(w.Path, "/adsets") && strings.Contains(w.Form["name"], "Retargeting"):
			catSet = w.Form
		case strings.HasSuffix(w.Path, "/product_sets"):
			ps = w.Form
		}
		if (strings.HasSuffix(w.Path, "/campaigns") || strings.HasSuffix(w.Path, "/adsets") || strings.HasSuffix(w.Path, "/ads")) && (w.Form["status"] != "PAUSED" || w.Idem == "") {
			t.Fatalf("create not PAUSED / no key: %s %v", w.Path, w.Form)
		}
	}
	if sales == nil || sales["bid_strategy"] != "LOWEST_COST_WITHOUT_CAP" || sales["daily_budget"] == "" || sales["special_ad_categories"] != "[]" {
		t.Fatalf("prospecting campaign %v", sales)
	}
	if newSet == nil || !strings.Contains(newSet["attribution_spec"], `"CLICK_THROUGH"`) || !strings.Contains(newSet["attribution_spec"], `"window_days":7`) ||
		!strings.Contains(newSet["targeting"], `"excluded_custom_audiences"`) || !strings.Contains(newSet["targeting"], `"advantage_audience":1`) ||
		!strings.Contains(newSet["promoted_object"], "PURCHASE") || newSet["optimization_goal"] != "OFFSITE_CONVERSIONS" {
		t.Fatalf("new-customer ad set %v", newSet)
	}
	if cat == nil || catSet == nil || !strings.Contains(catSet["targeting"], `"product_audience_specs"`) || !strings.Contains(catSet["targeting"], `"Purchase"`) ||
		!strings.Contains(catSet["targeting"], `"retention_seconds":1209600`) {
		t.Fatalf("catalog retargeting %v / %v", cat, catSet)
	}
	if ps == nil || !strings.Contains(ps["filter"], `"is_any"`) || !strings.Contains(ps["filter"], "in stock") || strings.Contains(ps["filter"], `"5120"`) {
		t.Fatalf("product set %v (5120 is out of stock)", ps)
	}
	// the funnel view says the same, with the why of each setting
	in := ac.input()
	f := ac.Funnel(in, *in.Playbook)
	pieces := f["pieces"].([]FunnelPiece)
	if pieces[0].Status != "live" || pieces[2].Status != "live" || len(pieces[0].Settings) < 8 || pieces[0].Settings[0].Why == "" {
		t.Fatalf("funnel view %+v", pieces)
	}
}

// The small-budget tier (agency rule): under AED 150/day one prospecting ad set, no existing-customer ad set.
// MUTATION: make Tier always "growth" → an existing-customer ad set appears, red.
func TestSmallBudgetConsolidates(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ac.settings.Limits.DailyAED = 120
	if _, err := ac.Cycle(context.Background(), model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	n, existing := 0, 0
	for _, o := range m.Objects("adsets") {
		nm, _ := o["name"].(string)
		if strings.Contains(nm, "new customers") {
			n++
		}
		if strings.Contains(nm, "existing customers") {
			existing++
		}
	}
	if n != 1 || existing != 0 {
		t.Fatalf("small tier: %d prospecting ad sets, %d existing-customer ad sets", n, existing)
	}
}

func jobOf(ac *Acct, kind string) *Job {
	for _, j := range ac.jobSet().Jobs {
		if j.Kind == kind {
			j := j
			return &j
		}
	}
	return nil
}

// Job edits land in what runs; Skip means nothing runs. A test job edited to a carousel of two chosen products runs
// as exactly that; a skipped one writes nothing. MUTATION: ignore j.ProductIDs in nextProducts → red.
func TestJobEditAndSkip(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil { // the funnel exists
		t.Fatal(err)
	}
	ac.settings.Limits.MaxActiveAds = 20
	now := ac.app.now()
	js := ac.jobSet()
	js.Jobs = append(js.Jobs,
		Job{ID: "test-a", Kind: "test", Title: "AR test", When: now.Add(time.Hour), Status: "planned", Lang: "ar", Format: "image", ProductIDs: []string{}, Source: "planner"},
		Job{ID: "test-b", Kind: "test", Title: "AR test 2", When: now.Add(-time.Minute), Status: "planned", Lang: "ar", Format: "image", ProductIDs: []string{}, Source: "planner"}) // due now: only the skip holds it
	ac.saveJobs(js)
	s := testServer(ac)
	code, out := call(t, s, "PUT", "/api/jobs/test-a", ac.id, map[string]any{"product_ids": []string{"6120", "7301"}, "format": "carousel", "copy_direction": "hydration for Ramadan nights", "move_hours": -2})
	if code != 200 {
		t.Fatalf("edit: %d %v", code, out)
	}
	if code, _ := call(t, s, "PUT", "/api/jobs/test-a", ac.id, map[string]any{"product_ids": []string{"2210-avoid-me", "nope"}}); code != 400 {
		t.Fatal("a product outside the shop was accepted")
	}
	if code, _ := call(t, s, "POST", "/api/jobs/test-b/skip", ac.id, map[string]any{}); code != 200 {
		t.Fatal("skip")
	}
	n0 := len(m.Writes())
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	var carousel string
	for _, w := range m.Writes()[n0:] {
		if strings.HasSuffix(w.Path, "/adcreatives") && strings.Contains(w.Form["object_story_spec"], "child_attachments") {
			carousel = w.Form["object_story_spec"]
		}
	}
	if !strings.Contains(carousel, "torriden-dive-in") || !strings.Contains(carousel, "anua-foam") {
		t.Fatalf("the edited job did not run as edited: %q", carousel)
	}
	a, b := ac.jobByID("test-a"), ac.jobByID("test-b")
	if a.Status != "done" || b.Status != "skipped" || len(b.ProposalIDs) != 0 {
		t.Fatalf("a %s (%s), b %s", a.Status, a.Result, b.Status)
	}
	if br := ac.jobBrief(*a); !strings.Contains(br["owner_brief"].(string), "hydration for Ramadan nights") || !strings.Contains(br["owner_brief"].(string), "OWNER'S INSTRUCTIONS") {
		t.Fatalf("brief %v", br["owner_brief"])
	}
}

// Occasions: 20 days before UAE National Day the package is prepared (ads in AR + EN, dates, ramp) and waits; in Auto
// nothing of it goes live until he approves; Approve runs it. MUTATION: drop the approval gate in dueJobs → red.
func TestOccasionWaitsForApproval(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	ac.settings.Limits.MaxActiveAds = 20
	nd := time.Date(ac.app.now().Year(), 12, 2, 10, 0, 0, 0, time.UTC)
	if nd.Before(ac.app.now()) {
		nd = nd.AddDate(1, 0, 0)
	}
	ac.app.now = func() time.Time { return nd.AddDate(0, 0, -2) } // inside the 3-day ramp: the job is due
	n0 := len(m.Writes())
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	j := jobOf(ac, "occasion")
	if j == nil || j.Occasion == nil || len(j.Creatives) < 2 || j.Status != "planned" {
		t.Fatalf("occasion package %+v", j)
	}
	for _, w := range m.Writes()[n0:] {
		if strings.Contains(w.Form["name"], "Occasion") {
			t.Fatalf("an occasion ad went live without approval: %v", w.Form)
		}
	}
	s := testServer(ac)
	call(t, s, "POST", "/api/jobs/"+j.ID+"/reject-ad", ac.id, map[string]any{"creative_id": j.Creatives[0]})
	code, out := call(t, s, "POST", "/api/jobs/"+j.ID+"/approve", ac.id, map[string]any{})
	if code != 200 || out["run"] == nil {
		t.Fatalf("approve: %d %v", code, out)
	}
	live := 0
	for _, o := range m.Objects("ads") {
		if strings.Contains(o["name"].(string), "Occasion") {
			live++
		}
	}
	if live != len(j.Creatives)-1 {
		t.Fatalf("%d occasion ads live, want %d (one rejected)", live, len(j.Creatives)-1)
	}
}

// A product that sells out while its ad runs is an ALERT, never an automatic pause (the owner: "if it's generating
// good results it should continue"). MUTATION: pause the ad in stockAlerts → red.
func TestSoldOutAlertsNeverPauses(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	ac.app.mu.Lock()
	for i := range ac.app.shopSnap.Products {
		ac.app.shopSnap.Products[i].InStock = false
	}
	ac.app.mu.Unlock()
	n0 := len(m.Writes())
	out, err := ac.Cycle(ctx, model.AutopilotAuto)
	if err != nil {
		t.Fatal(err)
	}
	for _, w := range m.Writes()[n0:] {
		if w.Form["status"] == "PAUSED" && !strings.HasSuffix(w.Path, "/campaigns") && !strings.HasSuffix(w.Path, "/adsets") && !strings.HasSuffix(w.Path, "/ads") {
			t.Fatalf("a sold-out product paused something: %s %v", w.Path, w.Form)
		}
	}
	if n, _ := out["stock_alerts"].(int); n < 1 {
		t.Fatalf("no sold-out alert: %v", out["stock_alerts"])
	}
}

// The warm-up's Refresh (no cycle due) still prepares an occasion whose prep date came while the Mac was closed (QA).
// MUTATION: drop the refreshOccasions call in Acct.Refresh → red.
func TestRefreshPreparesOccasionsWithoutACycle(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	nd := time.Date(ac.app.now().Year(), 12, 2, 10, 0, 0, 0, time.UTC)
	if nd.Before(ac.app.now()) {
		nd = nd.AddDate(1, 0, 0)
	}
	ac.app.now = func() time.Time { return nd.AddDate(0, 0, -10) }
	n0 := len(m.Writes())
	ac.Refresh(context.Background())
	found := false
	for _, j := range ac.jobSet().Jobs {
		found = found || j.Kind == "occasion" && j.Occasion != nil && j.Occasion.Occasion == "national-day"
	}
	if !found || len(m.Writes()) != n0 {
		t.Fatalf("refresh: National Day package %v, Meta writes %d", found, len(m.Writes())-n0)
	}
}

// A fresh account with nothing linked says why Approvals is empty, with the fix; Auto-link fills the links from the
// portfolio. MUTATION: drop the links blocker → red.
func TestReadinessSaysWhy(t *testing.T) {
	ac, _ := testApp(t)
	ac.settings.Links = model.AccountLinks{}
	rd := ac.readiness()
	ok := false
	for _, b := range rd {
		ok = ok || b.Key == "links" && b.Fix == "autolink"
	}
	if !ok {
		t.Fatalf("readiness %+v", rd)
	}
	var st map[string]any
	_ = json.Unmarshal(ac.stateBytes(), &st)
	if st["readiness"] == nil {
		t.Fatal("state has no readiness")
	}
}
