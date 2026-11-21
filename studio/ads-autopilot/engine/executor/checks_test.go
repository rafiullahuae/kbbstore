package executor

import (
	"math"
	"strings"
	"testing"
	"time"

	"kaa/meta"
	"kaa/model"
)

var t0 = time.Date(2026, 11, 20, 12, 0, 0, 0, time.UTC) // 11 days left in November, today included

func lim() model.Limits {
	l := model.DefaultLimits()
	l.DailyAED, l.MonthlyAED, l.Autopilot, l.TargetCPAAED, l.Countries, l.MaxActiveAds = 300, 6000, model.AutopilotAuto, 60, []string{"AE", "SA"}, 3
	return l
}

// tree: one owner campaign (50 AED/day, running, not ours) and one of ours (100 AED/day campaign budget, running)
// with one ad set and two ads (one active).
func tree() []model.Campaign {
	return []model.Campaign{
		{ID: "100", Name: "Owner", Status: "ACTIVE", EffectiveStatus: "ACTIVE", DailyBudgetAED: 50, AdSets: []model.AdSet{{ID: "101", CampaignID: "100", Status: "ACTIVE", Ads: []model.Ad{{ID: "102", Status: "ACTIVE"}}}}},
		{ID: "200", Name: "KAA: Sales", Managed: true, Status: "ACTIVE", EffectiveStatus: "ACTIVE", DailyBudgetAED: 100, AdSets: []model.AdSet{{ID: "201", CampaignID: "200", Status: "ACTIVE", BidAmountAED: 40,
			Ads: []model.Ad{{ID: "202", Status: "ACTIVE"}, {ID: "203", Status: "PAUSED"}}}}},
		{ID: "300", Name: "KAA: Paused", Managed: true, Status: "PAUSED", EffectiveStatus: "PAUSED", AdSets: []model.AdSet{{ID: "301", CampaignID: "300", Status: "ACTIVE", DailyBudgetAED: 80, Ads: []model.Ad{}}}},
	}
}

func env() Env {
	return Env{Now: t0, Limits: lim(), Tree: tree(), MonthSpendAED: 1000, Ledger: NewLedger(), Links: testLinks}
}

// MUTATION: change math.Round to math.Floor in Fils → the 12.345 row (1234.5 → 1235) goes red.
func TestFils(t *testing.T) {
	cases := []struct {
		aed  float64
		want int64
		bad  bool
	}{
		{100, 10000, false}, {12.345, 1235, false}, {0.01, 1, false}, {0.004, 0, true}, {0, 0, true}, {-5, 0, true},
		{math.NaN(), 0, true}, {math.Inf(1), 0, true}, {100000, 10000000, false}, {100000.01, 0, true}, {367.29, 36729, false},
	}
	for _, c := range cases {
		got, err := Fils(c.aed)
		if (err != nil) != c.bad || got != c.want {
			t.Errorf("Fils(%v) = %d, %v; want %d bad=%v", c.aed, got, err, c.want, c.bad)
		}
	}
}

func TestCommittedDaily(t *testing.T) {
	// ours 100 only: the owner's own 50/day is not his limits' business (22 Nov: "ignore my ads budget and spend");
	// the paused campaign's ad set (80) does not count. MUTATION: drop the !c.Managed skip → 150, red.
	if got := CommittedDaily(tree()); got != 100 {
		t.Fatalf("committed = %v, want 100 (the app's own campaigns only)", got)
	}
	if got := CommittedDailyOwn(tree()); got != 50 {
		t.Fatalf("own committed = %v, want 50", got)
	}
	if got := ActiveAds(tree()); got != 1 {
		t.Fatalf("active ads = %d, want 1 (the owner's own active ad is not counted)", got)
	}
}

func prop(kind, target string, params map[string]any) model.Proposal {
	return model.Proposal{ID: "p1", Kind: kind, Target: target, Params: params}
}

// The table every guardrail answers to. Each row's comment is its mutation note.
func TestCheckTable(t *testing.T) {
	cases := []struct {
		name  string
		p     model.Proposal
		mod   func(*Env)
		ok    bool
		stage string
		has   string
	}{
		{"unknown kind refused", prop("delete_account", "200", nil), nil, false, "kind", "not something"},
		{"autopilot off refuses all", prop(model.KindPause, "201", nil), func(e *Env) { e.Limits.Autopilot = model.AutopilotOff }, false, "limits", "off"},
		{"no limits: nothing launches", prop(model.KindCreateCampaign, "tmp:c", map[string]any{"daily_budget": 10.0}), func(e *Env) { e.Limits.DailyAED = 0 }, false, "limits", "no daily and monthly"},
		{"no limits: a pause still goes", prop(model.KindPause, "201", nil), func(e *Env) { e.Limits.DailyAED = 0 }, true, "ok", ""},
		{"owner's campaign never touched", prop(model.KindSetBudget, "100", map[string]any{"daily_budget": 55.0}), nil, false, "limits", "did not make"},
		{"owner's campaign never paused", prop(model.KindPause, "101", nil), nil, false, "limits", "did not make"},
		// daily cap with what OURS already runs (the owner's 50/day is not counted, 22 Nov): 100 + 200 = 300 ok;
		// +201 = 301 refused.
		// MUTATION: drop "committed+" in the daily check → the 151 row goes green and the test red.
		{"daily cap counts running budgets (fits)", prop(model.KindCreateCampaign, "tmp:c", map[string]any{"daily_budget": 200.0}), func(e *Env) { e.MonthSpendAED = 0 }, true, "ok", ""},
		{"daily cap counts running budgets (over)", prop(model.KindCreateCampaign, "tmp:c", map[string]any{"daily_budget": 201.0}), func(e *Env) { e.MonthSpendAED = 0 }, false, "limits", "daily limit"},
		// monthly (ours only): 1000 spent + (100+100) × 11 days = 3200 ≤ 6000; with 3900 spent → 6100 > 6000
		// MUTATION: use committed instead of committed×DaysLeftInMonth → the over row goes green.
		{"monthly projection fits", prop(model.KindCreateCampaign, "tmp:c", map[string]any{"daily_budget": 100.0}), nil, true, "ok", ""},
		{"monthly projection over", prop(model.KindCreateCampaign, "tmp:c", map[string]any{"daily_budget": 100.0}), func(e *Env) { e.MonthSpendAED = 3900 }, false, "limits", "monthly limit"},
		// step %: 100 → 120 is +20% (ok), → 121 is +21% (refused), → 79 is -21% (refused)
		// MUTATION: compare pct > step*2 → the 121 row goes green.
		{"budget step at the limit", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 120.0}), nil, true, "ok", ""},
		{"budget step over the limit", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 121.0}), nil, false, "limits", "step"},
		{"budget step down over the limit", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 79.0}), nil, false, "limits", "step"},
		{"bid step over the limit", prop(model.KindSetBid, "201", map[string]any{"bid_amount": 50.0}), nil, false, "limits", "step"},
		{"bid step fits", prop(model.KindSetBid, "201", map[string]any{"bid_amount": 46.0}), nil, true, "ok", ""},
		// a budget raise inside the step but over the daily cap: 150 + (118-100) = 168 fine; with daily 160 → refused
		{"budget raise over the daily cap", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 118.0}), func(e *Env) { e.Limits.DailyAED = 110 }, false, "limits", "daily limit"},
		// the owner's own campaign (50/day) is NOT counted (22 Nov): 118 fits a 160 limit
		{"the owner's budget is not counted", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 118.0}), func(e *Env) { e.Limits.DailyAED = 160 }, true, "ok", ""},
		// cooldown: changed 71 h ago → refused; 73 h ago → ok. Pause is exempt.
		// MUTATION: skip the cooldown block → the 71h row goes green.
		{"cooldown blocks a change", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 110.0}), func(e *Env) { e.Ledger.NoteEdit("200", t0.Add(-71*time.Hour), true, true) }, false, "limits", "cooldown"},
		{"cooldown over", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 110.0}), func(e *Env) { e.Ledger.NoteEdit("200", t0.Add(-73*time.Hour), true, true) }, true, "ok", ""},
		{"pause ignores cooldown", prop(model.KindPause, "200", nil), func(e *Env) { e.Ledger.NoteEdit("200", t0.Add(-1*time.Hour), true, true) }, true, "ok", ""},
		// max active ads: 2 active (owner's + ours) of 3 → one more ok, ActivatedNow=1 → refused
		{"max active ads fits", prop(model.KindResume, "203", nil), nil, true, "ok", ""},
		{"max active ads reached", prop(model.KindResume, "203", nil), func(e *Env) { e.ActivatedNow = 2 }, false, "limits", "ads are active"},
		{"create ad when full", prop(model.KindCreateAd, "tmp:a", map[string]any{"adset_id": "201", "creative_id": "x"}), func(e *Env) { e.Limits.MaxActiveAds = 1 }, false, "limits", "ads are active"},
		// the owner's own active ad is NOT counted (22 Nov): one of ours active, max 2 → one more fits
		{"the owner's ads are not counted", prop(model.KindCreateAd, "tmp:a", map[string]any{"adset_id": "201", "creative_id": "x"}), func(e *Env) { e.Limits.MaxActiveAds = 2 }, true, "ok", ""},
		// countries
		{"country outside the list", prop(model.KindCreateAdSet, "tmp:s", map[string]any{"campaign_id": "200", "countries": []any{"AE", "KW"}}), nil, false, "limits", "country KW"},
		{"countries inside the list", prop(model.KindCreateAdSet, "tmp:s", map[string]any{"campaign_id": "200", "countries": []any{"AE", "SA"}}), nil, true, "ok", ""},
		{"adset under the owner's campaign", prop(model.KindCreateAdSet, "tmp:s", map[string]any{"campaign_id": "100", "countries": []any{"AE"}}), nil, false, "limits", "did not make"},
		// resume of a paused campaign commits its ad set budget (80): 150+80=230 ok; daily 200 → refused
		{"resume commits its budget", prop(model.KindResume, "300", nil), func(e *Env) { e.Limits.DailyAED = 170 }, false, "limits", "daily limit"},
		// Meta's write rate: edited 10 s ago → refused (30 s rule); 4 budget changes this hour → refused
		// MUTATION: change 30*time.Second to 5*time.Second → the 10s row goes green.
		{"30 s between edits", prop(model.KindPause, "202", nil), func(e *Env) { e.Ledger.NoteEdit("202", t0.Add(-10*time.Second), false, false) }, false, "rate", "30 s"},
		{"4 budget changes an hour", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 101.0}), func(e *Env) {
			e.Limits.CooldownHours = 0.001
			for i := 1; i <= 4; i++ {
				e.Ledger.NoteEdit("200", t0.Add(-time.Duration(i*10)*time.Minute), true, false)
			}
		}, false, "rate", "4 budget changes"},
		{"BUC usage high", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 101.0}), func(e *Env) { e.Usage = meta.Usage{At: t0.Add(-time.Minute), MaxPct: 95} }, false, "rate", "95%"},
		{"BUC usage high still lets a pause through", prop(model.KindPause, "202", nil), func(e *Env) { e.Usage = meta.Usage{At: t0.Add(-time.Minute), MaxPct: 95} }, true, "ok", ""},
		// linter
		{"linter refuses a medical claim", prop(model.KindUploadCreative, "cr1", map[string]any{"headline": "Cures acne in 3 days"}), nil, false, "lint", "cure"},
		{"linter refuses an Arabic claim", prop(model.KindCreateAd, "tmp:a", map[string]any{"adset_id": "201", "primary_text": "نتائج مضمونة"}), func(e *Env) { e.Limits.MaxActiveAds = 9 }, false, "lint", "نتائج"},
		// shadow: passes every check, still not executed. MUTATION: drop the shadow block → stage "ok".
		{"shadow mode logs only", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 110.0}), func(e *Env) { e.Limits.Autopilot = model.AutopilotShadow }, false, "shadow", "shadow"},
		{"bad money refused", prop(model.KindSetBudget, "200", map[string]any{"daily_budget": math.NaN()}), nil, false, "limits", "not a number"},
		{"missing object", prop(model.KindPause, "999", nil), nil, false, "limits", "not in the account"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			e := env()
			if c.mod != nil {
				c.mod(&e)
			}
			d := Check(c.p, e)
			if d.OK != c.ok || d.Stage != c.stage || c.has != "" && !strings.Contains(strings.Join(d.Reasons, " "), c.has) {
				t.Fatalf("got ok=%v stage=%s reasons=%v; want ok=%v stage=%s containing %q", d.OK, d.Stage, d.Reasons, c.ok, c.stage, c.has)
			}
		})
	}
}

// MUTATION: drop p.ID or the params from Key → TestKeyStable's "different params" assertion goes red.
func TestKeyStable(t *testing.T) {
	a := prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 110.0, "x": "y"})
	b := prop(model.KindSetBudget, "200", map[string]any{"x": "y", "daily_budget": 110.0})
	c := prop(model.KindSetBudget, "200", map[string]any{"daily_budget": 111.0, "x": "y"})
	if Key(a) != Key(b) {
		t.Fatal("same proposal, different key")
	}
	if Key(a) == Key(c) {
		t.Fatal("different params, same key")
	}
	if len(Key(a)) > 128 || strings.ContainsAny(Key(a), " :/") {
		t.Fatal("key is not a valid Meta idempotency key")
	}
}

func TestDaysLeft(t *testing.T) {
	if d := DaysLeftInMonth(t0); d != 11 {
		t.Fatalf("days left on 20 Nov = %d, want 11", d)
	}
	if d := DaysLeftInMonth(time.Date(2027, 2, 28, 1, 0, 0, 0, time.UTC)); d != 1 {
		t.Fatalf("days left on 28 Feb 2027 = %d, want 1", d)
	}
}

var testLinks = model.AccountLinks{PageID: "200000000001", PixelID: "300000000001", CatalogID: "400000000001", InstagramUserID: "500000000001", WhatsAppNumber: "+971500000000"}
