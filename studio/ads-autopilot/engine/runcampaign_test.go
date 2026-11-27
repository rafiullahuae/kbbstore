package main

import (
	"context"
	"fmt"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// The owner, 26 Nov: "if new campaigns / routine run, it should be as seperate compaign, not inside the previous
// campaign. I mean each set should have it's own main campaign." Until 0.6.23 every Run it put its ads into the
// first campaign's prospecting ad set, so on Meta one campaign kept growing. Now two Run it presses build two NEW
// campaigns ("KAA: Run · …"), each with its own ad set(s), lowest cost with no bid cap, and the ads of each run are
// only in its own campaign; the campaigns that were there before get no new ad. And the control still works:
// "Put new ads into the main campaign instead" brings the old way back.
// MUTATION: make ownCampaign return false → the ads land in the first campaign, red.
func TestEachRunItBuildsItsOwnCampaign(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil { // the first launch: the funnel's campaigns
		t.Fatal(err)
	}
	ac.settings.Limits.MaxActiveAds = 40
	before := map[string]bool{}
	for _, c := range m.Objects("campaigns") {
		before[fmt.Sprint(c["id"])] = true
	}
	run := func(n int) string {
		var ids []string
		for _, l := range []string{"ar", "en"} {
			c, err := ac.creatives.Add(model.Creative{ID: fmt.Sprintf("cc-run%d%s-4x5", n, l), Lang: l, Format: "4:5", PNG: tinyPNG, Headline: fmt.Sprintf("Glow %d %s", n, l),
				PrimaryText: "Korean skincare, express delivery across the UAE", Link: "https://kbeautybliss.com/p/", Source: "owner"}, time.Now())
			if err != nil {
				t.Fatal(err)
			}
			ids = append(ids, c.ID)
		}
		id := ac.queueOwnerRun(ids, "You pressed Run it.")
		if id == "" {
			t.Fatal("no Run it job")
		}
		if _, code, err := ac.runJobNow(ctx, id); err != nil || code >= 400 {
			t.Fatalf("run %d: %d %v", n, code, err)
		}
		return id
	}
	run(1)
	run(2)

	camps := map[string]map[string]any{}
	for _, c := range m.Objects("campaigns") {
		camps[fmt.Sprint(c["id"])] = c
	}
	var runs []string
	for id, c := range camps {
		if !before[id] && strings.Contains(fmt.Sprint(c["name"]), "Run · ") {
			runs = append(runs, id)
			if bs := fmt.Sprint(c["bid_strategy"]); bs != "LOWEST_COST_WITHOUT_CAP" {
				t.Fatalf("a run campaign has a bid cap: %s", bs)
			}
		}
	}
	if len(runs) != 2 {
		t.Fatalf("want 2 new run campaigns, got %d: %v", len(runs), runs)
	}
	setsOf := map[string]int{}
	setCamp := map[string]string{}
	for _, s := range m.Objects("adsets") {
		setCamp[fmt.Sprint(s["id"])] = fmt.Sprint(s["campaign_id"])
		setsOf[fmt.Sprint(s["campaign_id"])]++
	}
	for _, id := range runs {
		if setsOf[id] == 0 {
			t.Fatalf("run campaign %s has no ad set of its own", id)
		}
	}
	adsIn := map[string][]string{}
	for _, a := range m.Objects("ads") {
		if strings.Contains(fmt.Sprint(a["name"]), "Made with Claude") {
			c := setCamp[fmt.Sprint(a["adset_id"])]
			if before[c] {
				t.Fatalf("a Run it ad went into an earlier campaign (%s): %v", c, a["name"])
			}
			adsIn[c] = append(adsIn[c], fmt.Sprint(a["name"]))
		}
	}
	if len(adsIn) != 2 || len(adsIn[runs[0]]) != 2 || len(adsIn[runs[1]]) != 2 {
		t.Fatalf("each run's 2 ads should be in its own campaign: %v", adsIn)
	}
	for _, names := range adsIn { // run 1's ads never share a campaign with run 2's
		if strings.Contains(strings.Join(names, "|"), "Glow 1") == strings.Contains(strings.Join(names, "|"), "Glow 2") {
			t.Fatalf("two runs share one campaign: %v", names)
		}
	}

	// a run's campaign never becomes "the prospecting campaign" (Meta lists the newest first)
	if f := findFunnel(ac.input().Tree, nil); f.prosp == nil || !before[f.prosp.ID] || len(f.runs) != 2 {
		t.Fatalf("the funnel's main campaign moved to a run campaign: %+v runs %d", f.prosp, len(f.runs))
	}

	// the old way, one tick away: the next run goes into the main campaign, no new campaign
	pb := *ac.input().Playbook
	pb.ShareCampaign = true
	if err := ac.savePlaybook(pb, "test"); err != nil {
		t.Fatal(err)
	}
	n0 := len(m.Objects("campaigns"))
	run(3)
	if n := len(m.Objects("campaigns")); n != n0 {
		t.Fatalf("with \"into the main campaign\" on, a new campaign was made (%d → %d)", n0, n)
	}
	in3 := 0
	for _, a := range m.Objects("ads") {
		if strings.Contains(fmt.Sprint(a["name"]), "Made with Claude") && before[setCamp[fmt.Sprint(a["adset_id"])]] {
			in3++
		}
	}
	if in3 != 2 {
		t.Fatalf("with the old way on, run 3's 2 ads should be in the main campaign, got %d", in3)
	}
}
