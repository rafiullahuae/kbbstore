package brain

import (
	"strings"
	"testing"
	"time"
)

// Fixture: Thursday 19 Nov 2026 (no season, not batch day, not payday).
var tNow = time.Date(2026, 11, 19, 8, 0, 0, 0, time.UTC)

func ago(k int) string { return tNow.AddDate(0, 0, -k).Format("2006-01-02") }

// econ with round numbers: break-even CPA 60, target CPA 45.
var tEcon = Economics{AOV: 200, BreakEvenCPA: 60, TargetCPA: 45, BreakEvenROAS: 3.33, TargetROAS: 4.44}

func baseInput() CycleInput {
	return CycleInput{
		Now:    tNow,
		Limits: Limits{DailyAED: 500, MonthlyAED: 12000, MaxBudgetStepPct: 20, CooldownHours: 72, MaxActiveAds: 10, Countries: []string{"AE"}, Autopilot: "auto"},
		Tree: []Campaign{{ID: "c1", Name: "KBB Sales", Status: "ACTIVE", MadeByApp: true, AdSets: []AdSet{
			{ID: "s1", CampaignID: "c1", Status: "ACTIVE", DailyBudgetAED: 200, Language: "ar", LearningStage: "SUCCESS",
				CreatedAt: tNow.AddDate(0, 0, -40), LastSignificantEdit: tNow.AddDate(0, 0, -20),
				Ads: []Ad{{ID: "a1", Status: "ACTIVE", Language: "ar", CreatedAt: tNow.AddDate(0, 0, -30)}}},
		}}},
	}
}

// rows spreads spend/purchases evenly over days [from..to] ago.
func rows(level, id string, from, to int, spend float64, purch int64, impr, clicks int64, freq float64) []MetricRow {
	n := from - to + 1
	var out []MetricRow
	for k := from; k >= to; k-- {
		p := purch / int64(n)
		if int64(from-k) < purch%int64(n) {
			p++
		}
		out = append(out, MetricRow{Date: ago(k), Level: level, ObjectID: id, SpendAED: spend / float64(n), Purchases: p,
			RevenueAED: float64(p) * 200, Impressions: impr / int64(n), Clicks: clicks / int64(n), Frequency: freq, Settling: k <= 2})
	}
	return out
}

func find(ps []Proposal, kind, target string) *Proposal {
	for i := range ps {
		if ps[i].Kind == kind && ps[i].Target == target {
			return &ps[i]
		}
	}
	return nil
}

func verdictOf(r RulesResult, id string) Verdict {
	for _, v := range r.Verdicts {
		if v.Object == id {
			return v
		}
	}
	return Verdict{}
}

// A zero-purchase ad that spent 2.5x target CPA on SETTLED days is paused.
// Mutation: raise KillP to 0.05 -> no pause, red.
func TestKillZeroPurchaseAfter2xTarget(t *testing.T) {
	in := baseInput()
	in.Metrics = rows("ad", "a1", 9, 3, 2.5*45, 0, 20000, 240, 1.2)
	r := Evaluate(in, tEcon, DefaultRules())
	if p := find(r.Proposals, "pause", "a1"); p == nil {
		t.Fatalf("expected pause, verdict %+v", verdictOf(r, "a1"))
	}
}

// The same spend but all of it in the settling days (today-2..today) is NOT
// judged: purchases are still being attributed.
// Mutation: drop the !r.Settling / date filter in settled() -> paused, red.
func TestSettlingDaysAreNotJudged(t *testing.T) {
	in := baseInput()
	in.Metrics = rows("ad", "a1", 2, 0, 2.5*45, 0, 20000, 240, 1.2)
	r := Evaluate(in, tEcon, DefaultRules())
	if find(r.Proposals, "pause", "a1") != nil {
		t.Fatal("paused an ad on settling data")
	}
	if v := verdictOf(r, "a1"); v.Verdict != "settling" {
		t.Fatalf("verdict %q, want settling", v.Verdict)
	}
}

// ...unless it is a runaway: 3x BREAK-EVEN with nothing back, settling included.
func TestHardStop(t *testing.T) {
	in := baseInput()
	in.Metrics = rows("ad", "a1", 2, 0, 3.1*60, 0, 30000, 300, 1.2)
	r := Evaluate(in, tEcon, DefaultRules())
	if find(r.Proposals, "pause", "a1") == nil || !contains(verdictOf(r, "a1").Signals, "hard_stop") {
		t.Fatalf("no hard stop: %+v", verdictOf(r, "a1"))
	}
}

// A winning ad set (settled 7d CPA 30 vs target 45, 14 purchases) scales by
// exactly the owner's step (20%: 200 -> 240).
// Mutation: use step 30 in budgetMove -> 260, red.
func TestScaleWinnerByStep(t *testing.T) {
	in := baseInput()
	in.Metrics = append(rows("adset", "s1", 9, 3, 420, 14, 60000, 900, 1.5), rows("ad", "a1", 9, 3, 420, 14, 60000, 900, 1.5)...)
	r := Evaluate(in, tEcon, DefaultRules())
	p := find(r.Proposals, "set_budget", "s1")
	if p == nil {
		t.Fatalf("no scale; verdict %+v", verdictOf(r, "s1"))
	}
	if p.Params["daily_budget_aed"] != 240.0 || p.Params["direction"] != "up" {
		t.Fatalf("params %+v", p.Params)
	}
}

// Headroom: with daily limit 220 and 200 committed, the step is capped at +20.
func TestScaleCappedByDailyLimit(t *testing.T) {
	in := baseInput()
	in.Limits.DailyAED = 220
	in.Metrics = rows("adset", "s1", 9, 3, 420, 14, 60000, 900, 1.5)
	r := Evaluate(in, tEcon, DefaultRules())
	p := find(r.Proposals, "set_budget", "s1")
	if p == nil || p.Params["daily_budget_aed"] != 220.0 {
		t.Fatalf("got %+v", p)
	}
}

// Cooldown: a budget change 24h ago blocks the next one (72h).
// Mutation: make coolOK always true -> red.
func TestCooldownBlocksScale(t *testing.T) {
	in := baseInput()
	in.Tree[0].AdSets[0].LastBudgetChange = tNow.Add(-24 * time.Hour)
	in.Metrics = rows("adset", "s1", 9, 3, 420, 14, 60000, 900, 1.5)
	r := Evaluate(in, tEcon, DefaultRules())
	if find(r.Proposals, "set_budget", "s1") != nil {
		t.Fatal("scaled inside cooldown")
	}
	if !strings.Contains(verdictOf(r, "s1").Why, "cooldown") {
		t.Fatal("verdict does not say why")
	}
}

// Learning: an ad set edited 3 days ago (LEARNING, 14 results < 50) is not touched.
func TestLearningAdSetUntouched(t *testing.T) {
	in := baseInput()
	in.Tree[0].AdSets[0].LearningStage = "LEARNING"
	in.Tree[0].AdSets[0].LastSignificantEdit = tNow.AddDate(0, 0, -3)
	in.Metrics = rows("adset", "s1", 9, 3, 420, 14, 60000, 900, 1.5)
	r := Evaluate(in, tEcon, DefaultRules())
	if find(r.Proposals, "set_budget", "s1") != nil || verdictOf(r, "s1").Verdict != "learning" {
		t.Fatalf("touched a learning ad set: %+v", verdictOf(r, "s1"))
	}
}

// A losing ad set (7d: 300 AED, 1 purchase) gets cut one step (200 -> 160).
func TestCutLoser(t *testing.T) {
	in := baseInput()
	in.Metrics = rows("adset", "s1", 9, 3, 300, 1, 40000, 500, 1.5)
	r := Evaluate(in, tEcon, DefaultRules())
	p := find(r.Proposals, "set_budget", "s1")
	if p == nil || p.Params["daily_budget_aed"] != 160.0 || p.Params["direction"] != "down" {
		t.Fatalf("got %+v / %+v", p, verdictOf(r, "s1"))
	}
}

// Weak hook: 0 purchases, 1x target spent, CTR 0.3% vs account 1.5%.
func TestWeakHookKill(t *testing.T) {
	in := baseInput()
	in.Tree[0].AdSets[0].Ads = append(in.Tree[0].AdSets[0].Ads, Ad{ID: "a2", Status: "ACTIVE", Language: "ar", CreatedAt: tNow.AddDate(0, 0, -20)})
	in.Metrics = append(rows("ad", "a1", 20, 3, 900, 30, 200000, 3000, 1.2), rows("ad", "a2", 9, 3, 50, 0, 10000, 30, 1.2)...)
	r := Evaluate(in, tEcon, DefaultRules())
	if v := verdictOf(r, "a2"); v.Verdict != "kill" || !contains(v.Signals, "weak_hook") {
		t.Fatalf("a2 %+v", v)
	}
}

// Fatigue: first 14 days CTR 1.5%, last 7 days 0.8% and frequency 3.1.
// Mutation: FatigueMinSignal 3 -> not fatigued (CPM unchanged), red.
func TestFatigue(t *testing.T) {
	in := baseInput()
	var m []MetricRow
	for k := 24; k >= 3; k-- {
		ctrClicks, freq := int64(30), 1.4
		if k <= 9 {
			ctrClicks, freq = 16, 3.1
		}
		m = append(m, MetricRow{Date: ago(k), Level: "ad", ObjectID: "a1", SpendAED: 40, Impressions: 2000, Clicks: ctrClicks, Purchases: 1, RevenueAED: 200, Frequency: freq})
	}
	in.Metrics = m
	r := Evaluate(in, tEcon, DefaultRules())
	if v := verdictOf(r, "a1"); v.Verdict != "fatigued" || len(v.Signals) < 2 {
		t.Fatalf("a1 %+v", v)
	}
}

// No limit set: nothing that adds spend survives; pauses do.
func TestNoLimitsNoLaunch(t *testing.T) {
	in := baseInput()
	in.Limits.DailyAED = 0
	in.Metrics = append(rows("adset", "s1", 9, 3, 420, 14, 60000, 900, 1.5), rows("ad", "a1", 9, 3, 2.5*45, 0, 20000, 240, 1.2)...)
	r := Evaluate(in, tEcon, DefaultRules())
	if find(r.Proposals, "set_budget", "s1") != nil {
		t.Fatal("scaled without limits")
	}
	if len(r.Alerts) == 0 || r.LimitsSet {
		t.Fatal("no alert")
	}
}

// Arabic share: default 70%; Arabic clearly cheaper -> 80%; clearly dearer -> floor 55%.
func TestArabicShare(t *testing.T) {
	cfg := DefaultRules()
	if s := arabicShare(Agg{}, Agg{}, 45, cfg); s != 0.70 {
		t.Fatal(s)
	}
	if s := arabicShare(Agg{Spend: 600, Purch: 20}, Agg{Spend: 900, Purch: 20}, 45, cfg); s < 0.799 || s > 0.801 {
		t.Fatal(s)
	}
	if s := arabicShare(Agg{Spend: 1400, Purch: 20}, Agg{Spend: 800, Purch: 20}, 45, cfg); s != 0.55 {
		t.Fatal(s)
	}
}

// Batch day (Monday): 10 max ads, 1 active -> 4 slots (batch cap), 3 Arabic.
func TestBatchSlotsArabicFirst(t *testing.T) {
	in := baseInput()
	in.Now = time.Date(2026, 11, 23, 8, 0, 0, 0, time.UTC) // Monday
	r := Evaluate(in, tEcon, DefaultRules())
	if !r.BatchDay || r.NewAdSlots["ar"] != 3 || r.NewAdSlots["en"] != 1 {
		t.Fatalf("slots %+v batch %v", r.NewAdSlots, r.BatchDay)
	}
	in.Now = tNow
	if r := Evaluate(in, tEcon, DefaultRules()); len(r.NewAdSlots) != 0 {
		t.Fatal("slots off batch day")
	}
}

// The owner's own campaigns get verdicts but never proposals.
// Mutation: drop the managed check in Evaluate -> a pause is proposed, red.
func TestOwnersCampaignsUntouched(t *testing.T) {
	in := baseInput()
	in.Tree[0].MadeByApp = false
	in.Metrics = rows("ad", "a1", 9, 3, 2.5*45, 0, 20000, 240, 1.2)
	r := Evaluate(in, tEcon, DefaultRules())
	if len(r.Proposals) != 0 || verdictOf(r, "a1").Verdict != "kill" {
		t.Fatalf("%+v / %+v", r.Proposals, verdictOf(r, "a1"))
	}
}
