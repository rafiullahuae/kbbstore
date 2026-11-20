package brain

import (
	"fmt"
	"math"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// A simulated 30 days where one angle truly wins (ingredient: CPA ~AED 30; routine: ~AED 90; target 60). Every day
// two concepts launch with the brain's own prediction; each is scored 9 days later. By the end the brain (1) puts
// ~80% of new concepts on the winning angle and keeps ≥ 1 in 5 for exploration, (2) predicts better (the error of
// the last week is well under the first's), (3) ranks the winning angle's product up. MUTATION: skip the priors
// update in observe → the error never falls and the angles do not shift, red.
func TestLearningShiftsTowardTheWinner(t *testing.T) {
	var L Ledger
	start := time.Date(2026, 10, 1, 9, 0, 0, 0, time.UTC)
	target := 60.0
	trueCPA := map[string]float64{"ingredient": 30, "routine": 90}
	prod := map[string]string{"ingredient": "p-ing", "routine": "p-rou"}
	for d := 0; d < 40; d++ {
		now := start.AddDate(0, 0, d)
		if d < 30 {
			for _, a := range []string{"ingredient", "routine"} {
				p := Pred{ID: fmt.Sprintf("%d-%s", d, a), At: now, Kind: "launch", Angle: a, Format: "single_image", Lang: "ar", ProductIDs: []string{prod[a]}, Due: now.Add(scoreAfter)}
				p.ExpCPA = L.Predict(p.Angle, p.Format, p.Lang, p.ProductIDs, target)
				L.Preds = append(L.Preds, p)
			}
		}
		for i := range L.Preds { // outcomes arrive after the window; a little deterministic noise
			p := &L.Preds[i]
			if !p.Scored && !now.Before(p.Due) {
				noise := 1 + 0.1*math.Sin(float64(i))
				purch := 6.0
				L.observe(p, trueCPA[p.Angle]*purch*noise, purch, target, now)
			}
		}
	}
	h := L.Health()
	if len(h) < 3 || h[len(h)-1].MeanErr >= h[0].MeanErr*0.5 {
		t.Fatalf("prediction error did not fall: %+v", h)
	}
	ls := L.Lessons(target)
	if len(ls) == 0 || ls[0].Kind != "win" || !strings.Contains(ls[0].Value, "ingredient") {
		t.Fatalf("lessons %+v", ls)
	}
	angles := exploreAngles(ls, []string{"routine", "texture & feel", "ingredient"}, 10)
	ing, explore := 0, 0
	for _, a := range angles {
		switch a {
		case "ingredient":
			ing++
		case "texture & feel":
			explore++
		case "routine":
			t.Fatalf("a losing angle got a slot: %v", angles)
		}
	}
	if ing < 7 || explore < 2 {
		t.Fatalf("allocation %v (want ≥ 7 on the winner and ≥ 2 exploring)", angles)
	}
	b := L.ProductBoost(target)
	if b["p-ing"] <= 0.3 || b["p-rou"] >= -0.3 {
		t.Fatalf("product boost %v", b)
	}
	rep := L.Weekly(start.AddDate(0, 0, 40), target)
	if rep == nil || !strings.Contains(rep.EN, "ingredient") || rep.AR == "" {
		t.Fatalf("weekly report %+v", rep)
	}
}

// Self-tuning stays inside the owner's bounds, moves one step at a time, and "ask me" never applies by itself.
// MUTATION: drop the clamp to b.HardStopMax in Tune → the multiple leaves the bounds, red.
func TestSelfTuningBounds(t *testing.T) {
	now := time.Date(2026, 11, 20, 9, 0, 0, 0, time.UTC)
	var rows []MetricRow
	// 8 ads that never buy and keep spending: stopping earlier saves money, so the back-test wants a LOW multiple
	for a := 0; a < 8; a++ {
		for d := 0; d < 20; d++ {
			rows = append(rows, MetricRow{Date: now.AddDate(0, 0, -30+d).Format("2006-01-02"), Level: "ad", ObjectID: fmt.Sprint("ad", a), SpendAED: 25})
		}
	}
	in := CycleInput{Now: now, Metrics: rows}
	econ := Economics{BreakEvenCPA: 50}
	b := model.TuneBounds{HardStopMin: 2.5, HardStopMax: 4}
	for _, mode := range []string{"bounds", "ask"} {
		var L Ledger
		r := DefaultRules()
		for w := 0; w < 6; w++ {
			in.Now = now.AddDate(0, 0, 7*w)
			L.Tune(in, r, econ, mode, b)
			if L.Tuned.HardStopMult != 0 && (L.Tuned.HardStopMult < b.HardStopMin || L.Tuned.HardStopMult > b.HardStopMax) {
				t.Fatalf("%s: tuned %.2f outside the bounds", mode, L.Tuned.HardStopMult)
			}
			r2 := L.Apply(r, model.Knobs{}, b)
			if r2.HardStopMult < b.HardStopMin-1e-9 || r2.HardStopMult > b.HardStopMax+1e-9 {
				t.Fatalf("%s: multiple %.2f outside the bounds", mode, r2.HardStopMult)
			}
			if mode == "ask" && r2.HardStopMult != r.HardStopMult {
				t.Fatalf("ask mode applied %.2f by itself", r2.HardStopMult)
			}
		}
		if mode == "bounds" && (L.Apply(r, model.Knobs{}, b).HardStopMult != 2.5 || len(L.Changes) == 0) {
			t.Fatalf("bounds mode did not tune down to its floor: %+v %v", L.Tuned, L.Changes)
		}
		if mode == "ask" && (L.Tuned.Applied || len(L.Changes) == 0 || !strings.HasPrefix(L.Changes[0], "Proposed")) {
			t.Fatalf("ask mode: %+v %v", L.Tuned, L.Changes)
		}
		// the owner's own value always wins over the tuned one
		if v := L.Apply(r, model.Knobs{HardStopMult: 3.5}, b).HardStopMult; v != r.HardStopMult {
			t.Fatalf("tuning overrode the owner's knob: %.2f", v)
		}
	}
	// bounds the owner tightened BELOW the current value: the first step lands inside them, not half way
	var tight Ledger
	tb := model.TuneBounds{HardStopMin: 1.5, HardStopMax: 2}
	tight.Tune(in, DefaultRules(), econ, "bounds", tb)
	if v := tight.Tuned.HardStopMult; v < 1.5 || v > 2 {
		t.Fatalf("tightened bounds: tuned %.2f, want 1.5–2", v)
	}
	var off Ledger
	off.Tuned = Tuned{HardStopMult: 2, Applied: true}
	off.Tune(in, DefaultRules(), econ, "off", b)
	if off.Tuned.HardStopMult != 0 {
		t.Fatal("off mode kept a tuned value")
	}
}
