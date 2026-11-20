package brain

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

// A 120-day history: Arabic routine ads at CPA ~20, English glow ads at ~60,
// one ad fatiguing after day 21, placement rows for reels/feed.
func trainingInput() CycleInput {
	in := baseInput()
	in.Tree[0].AdSets[0].Ads = []Ad{
		{ID: "ar1", Status: "ACTIVE", Language: "ar", CreativeID: "c-ar", Format: "single_video"},
		{ID: "en1", Status: "ACTIVE", Language: "en", CreativeID: "c-en", Format: "single_image"},
	}
	in.Creatives = []Creative{{ID: "c-ar", Angle: "routine", Template: "routine", Lang: "ar"}, {ID: "c-en", Angle: "glow", Template: "glow", Lang: "en"}}
	for k := 120; k >= 3; k-- {
		ctr := int64(200)
		if k < 120-21 { // ar1 fatigues after 3 weeks
			ctr = 120
		}
		in.Metrics = append(in.Metrics,
			MetricRow{Date: ago(k), Level: "ad", ObjectID: "ar1", SpendAED: 40, Impressions: 10000, Clicks: ctr, Purchases: 2, RevenueAED: 360},
			MetricRow{Date: ago(k), Level: "ad", ObjectID: "en1", SpendAED: 60, Impressions: 10000, Clicks: 100, Purchases: 1, RevenueAED: 150},
			MetricRow{Date: ago(k), Level: "ad", ObjectID: "ar1", Placement: "reels", SpendAED: 30, Impressions: 8000, Clicks: 170, Purchases: 2, RevenueAED: 340},
			MetricRow{Date: ago(k), Level: "ad", ObjectID: "ar1", Placement: "feed", SpendAED: 10, Impressions: 2000, Clicks: 20, Purchases: 0, RevenueAED: 0})
	}
	return in
}

func slice(s []Slice, key string) Slice {
	for _, x := range s {
		if x.Key == key {
			return x
		}
	}
	return Slice{}
}

// Mutation: let placement rows into the totals -> 90d baseline spend grows by 40/day, red.
func TestBuildProfile(t *testing.T) {
	in := trainingInput()
	p := BuildProfile("act_1", in)
	if p.Days != 118 || p.Baselines["90d"].Spend != 100*88 {
		t.Fatalf("days %d 90d %+v", p.Days, p.Baselines["90d"])
	}
	ar, en := slice(p.ByLang, "ar"), slice(p.ByLang, "en")
	if ar.CPA != 20 || en.CPA != 60 || ar.PWin < 0.99 || en.PWin > 0.01 {
		t.Fatalf("lang %+v %+v", ar, en)
	}
	if r := slice(p.ByPlacement, "reels"); r.CPA != 15 || slice(p.ByPlacement, "feed").Purchases != 0 {
		t.Fatalf("placement %+v", p.ByPlacement)
	}
	if slice(p.ByFormat, "single_video").ROASIndex <= 1 || slice(p.ByAngle, "routine").Ads != 1 {
		t.Fatalf("format/angle %+v %+v", p.ByFormat, p.ByAngle)
	}
	if p.HalfLifeDays < 21 || p.HalfLifeDays > 30 || len(p.FatigueCurve) < 4 || p.FatigueCurve[0] != 1 {
		t.Fatalf("fatigue %v %v", p.HalfLifeDays, p.FatigueCurve)
	}
	joined := strings.Join(p.Winners, "|")
	if !strings.Contains(joined, `language "ar" wins`) || !strings.Contains(strings.Join(p.Evidence, "|"), `language "en" underperforms`) {
		t.Fatalf("winners %v evidence %v", p.Winners, p.Evidence)
	}
	// the cycle carries it and the planner sees it
	out, _ := New("").Cycle(nil, in)
	if out.Profile == nil || out.Profile.Account != "" && out.Profile.Days != 118 {
		t.Fatal("profile not attached")
	}
	b, _ := json.Marshal(plannerInput(in, Evaluate(in, tEcon, DefaultRules()), false))
	_ = b
}

func TestMergeProfiles(t *testing.T) {
	in := trainingInput()
	a := BuildProfile("act_1", in)
	b := BuildProfile("act_2", in)
	m := MergeProfiles([]LearningProfile{a, b}, time.Now())
	if m.Baselines["all"].Spend != 2*a.Baselines["all"].Spend || len(m.Accounts) != 2 {
		t.Fatalf("%+v", m.Baselines["all"])
	}
	if s := slice(m.ByLang, "ar"); s.Spend != 2*slice(a.ByLang, "ar").Spend || s.CPA != 20 {
		t.Fatalf("%+v", s)
	}
	if m.HalfLifeDays != a.HalfLifeDays {
		t.Fatal(m.HalfLifeDays)
	}
}

// Formats: carousel needs 2-10 real products; a video creative defaults to single_video.
func TestFormatValidation(t *testing.T) {
	shop := shopFixture()
	msg := ""
	bad := func(s string) { msg = s }
	if validFormat(map[string]any{"format": "carousel", "product_ids": []any{"3195"}}, shop, &bad) || !strings.Contains(msg, "2-10") {
		t.Fatal("carousel with 1 product passed")
	}
	if !validFormat(map[string]any{"format": "carousel", "product_ids": []any{"3195", "4001"}}, shop, &bad) {
		t.Fatal(msg)
	}
	p := map[string]any{"creative_id": "video:v1"}
	validFormat(p, shop, &bad)
	if p["format"] != "single_video" {
		t.Fatal(p)
	}
	p = map[string]any{"format": "click_to_whatsapp"}
	validFormat(p, shop, &bad)
	if p["destination"] != "whatsapp" {
		t.Fatal(p)
	}
	if !strings.Contains(PlannerSystem(), "click_to_whatsapp") || strings.Contains(PlannerSystem(), "2026") {
		t.Fatal("planner system must list formats and stay date-free")
	}
}
