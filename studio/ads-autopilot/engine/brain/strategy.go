package brain

// strategy.go — the Strategy screen's data ("How it decides", in plain words, with this account's live numbers) and
// the learning screen's data. Read-only: the owner changes things through the Playbook (engine /api/playbook).

import (
	"fmt"
	"math"
	"time"

	"kaa/model"
)

type ruleView struct {
	Key     string  `json:"key"`
	Label   string  `json:"label"`
	LabelAR string  `json:"label_ar"`
	Value   float64 `json:"value"`
	Default float64 `json:"default"`
	Unit    string  `json:"unit"`
	Why     string  `json:"why"`
	Knob    string  `json:"knob"`           // the Playbook knob that moves it ("" = fixed)
	Source  string  `json:"source"`         // default | yours | self-tuned
	Min     float64 `json:"min,omitempty"`  // the knob's own range
	Max     float64 `json:"max,omitempty"`  //
	Step    float64 `json:"step,omitempty"` //
}

func (e *Engine) strategy(acct string, in CycleInput) map[string]any {
	pb := model.DefaultPlaybook().Normalize()
	if in.Playbook != nil {
		pb = *in.Playbook
	}
	def, defE := DefaultRules(), DefaultEcon()
	r, ec := withKnobs(def, defE.WithInputs(pb.Econ), pb.Knobs)
	var L Ledger
	e.load(acct, "ledger", &L)
	r = L.Apply(r, pb.Knobs, pb.TuneBounds)
	econ := ComputeEconomics(ec, in.Shop, in.Now)
	src := func(knob float64, tuned bool) string {
		switch {
		case knob > 0:
			return "yours"
		case tuned:
			return "self-tuned"
		}
		return "default"
	}
	k := pb.Knobs
	rules := []ruleView{
		{"kill_p", "Stop a loser when the chance it beats break-even is at most", "إيقاف الإعلان الخاسر عندما يكون احتمال تجاوزه نقطة التعادل على الأكثر", r.KillP * 100, def.KillP * 100, "%",
			fmt.Sprintf("Judged only after it spent %.0f× the target CPA, on settled days, with Bayesian statistics — not on one bad day.", def.KillMinSpendMult), "kill_p", src(k.KillP, false), 5, 50, 5},
		{"hard_stop", "Stop an ad with no purchase after spending", "إيقاف إعلان بلا طلبات بعد إنفاق", r.HardStopMult, def.HardStopMult, "× break-even CPA",
			fmt.Sprintf("AED %.0f at today's break-even. The self-learning loop may move it inside your bounds (%.1f–%.1f×).", r.HardStopMult*econ.BreakEvenCPA, pb.TuneBounds.HardStopMin, pb.TuneBounds.HardStopMax), "hard_stop_mult", src(k.HardStopMult, L.Tuned.Applied && L.Tuned.HardStopMult > 0), 1.5, 10, 0.5},
		{"scale_p", "Scale a winner when the chance it beats the target is at least", "زيادة ميزانية الرابح عندما يكون احتمال تجاوزه الهدف على الأقل", r.ScaleP * 100, def.ScaleP * 100, "%",
			fmt.Sprintf("And at least %d settled purchases in the window; then +%.0f%% at most, every %.0f hours at most.", r.ScaleMinPurch, firstPos(in.Limits.MaxBudgetStepPct, def.DefaultStepPct), firstPos(in.Limits.CooldownHours, def.DefaultCooldownH)), "scale_p", src(k.ScaleP, false), 60, 99, 1},
		{"scale_min", "Purchases needed before scaling", "عدد الطلبات المطلوب قبل الزيادة", float64(r.ScaleMinPurch), float64(def.ScaleMinPurch), "purchases", "Fewer is noise at these volumes.", "scale_min_purchases", src(float64(k.ScaleMinPurch), false), 1, 100, 1},
		{"fatigue_freq", "An ad is tiring at a 7-day frequency of", "يُعتبر الإعلان مُرهقاً عند تكرار أسبوعي", r.FatigueFreq, def.FatigueFreq, "×",
			fmt.Sprintf("Or when CTR fell %.0f%% vs its first 14 days, or CPM/CPA rose %.0f%% (two signals needed). A tiring ad gets a fresh concept on the batch day.", r.FatigueCTRDrop*100, def.FatigueCostRise*100), "fatigue_frequency", src(k.FatigueFreq, false), 1, 10, 0.1},
		{"arabic", "Arabic share of new tests", "حصة العربية من الاختبارات الجديدة", r.ArabicShare * 100, def.ArabicShare * 100, "%", "Arabic first; it moves with what wins, never under 55% or over 85%.", "arabic_share_pct", src(k.ArabicSharePct, false), 55, 85, 5},
		{"batch", "New ads per weekly batch", "إعلانات جديدة في كل دفعة أسبوعية", float64(r.MaxNewAdsBatch), float64(def.MaxNewAdsBatch), "ads",
			"New ads go out together on the batch day, so Meta re-learns once a week, not every day.", "max_new_ads_batch", src(float64(k.MaxNewAdsBatch), false), 1, 12, 1},
		{"profit", "Profit kept per order (target CPA = break-even × (1 − this))", "الربح المحتفظ به لكل طلب", ec.ProfitShare * 100, defE.ProfitShare * 100, "%", "Higher = stricter target CPA, fewer but more profitable sales.", "profit_share_pct", src(k.ProfitSharePct, false), 5, 60, 5},
		{"small_budget", "Small-budget tier below", "شريحة الميزانية الصغيرة أقل من", k.SmallBudget(), model.DefSmallBudgetAED, "AED/day", "Under it: one prospecting campaign + catalog retargeting, ~80/20, existing customers by exclusion only.", "small_budget_aed", src(k.SmallBudgetAED, false), 30, 2000, 10},
		{"retarget_freq", "Retargeting is scaled only below a 7-day frequency of", "لا تُزاد إعادة الاستهداف إلا تحت تكرار أسبوعي", k.RetargetFreq(), model.DefRetargetMaxFreq, "×", "A warm audience is small; past it, more money buys the same people again.", "retarget_max_frequency", src(k.RetargetMaxFreq, false), 1.5, 8, 0.5},
		{"conv_week", "Purchases a week one ad set needs (learning phase)", "عدد الطلبات الأسبوعية لكل مجموعة إعلانية", float64(k.ConvWeek()), model.DefConversionsPerWeek, "purchases", "Meta: about 50 results in the week after the last significant edit. It sizes how many ad sets the budget can feed.", "conversions_per_week", src(float64(k.ConversionsPerWeek), false), 10, 200, 5},
		{"cost_cap", "Cost cap suggested from", "يُقترح سقف التكلفة من", float64(k.CostCapWeek()), model.DefCostCapMinConvWeek, "purchases / week", "Lowest cost until then: a cap on thin data starves delivery.", "cost_cap_min_conv_week", src(float64(k.CostCapMinConvWeek), false), 10, 500, 5},
	}
	for i := range rules {
		rules[i].Value = math.Round(rules[i].Value*100) / 100
		rules[i].Default = math.Round(rules[i].Default*100) / 100
	}
	budget := in.Limits.DailyAED
	sp := pb.SplitFor(budget)
	nSets := pb.AdSetsFor(budget*sp.Prospecting/100, econ.TargetCPA)
	funnel := []map[string]any{
		{"stage": "Prospecting", "stage_ar": "جذب عملاء جدد", "share": sp.Prospecting, "aed": round2(budget * sp.Prospecting / 100), "how": fmt.Sprintf("One Advantage+ sales campaign, %d ad set(s), 3–6 concepts each, recent buyers excluded.", nSets)},
		{"stage": "Retargeting", "stage_ar": "إعادة الاستهداف", "share": sp.Retargeting, "aed": round2(budget * sp.Retargeting / 100), "how": "Advantage+ catalog: viewed or carted in 14 days, not bought; in-stock, playbook-allowed products."},
		{"stage": "Existing customers", "stage_ar": "العملاء الحاليون", "share": sp.Existing, "aed": round2(budget * sp.Existing / 100), "how": map[bool]string{true: "By exclusion only at this budget (small tier).", false: "A capped ad set inside the prospecting campaign."}[pb.Tier(budget) == "small"]},
	}
	ranked := in.Ranked
	if len(ranked) > 40 {
		ranked = ranked[:40]
	}
	return map[string]any{
		"account": acct, "economics": econ, "orders_source": in.Shop.OrdersSource, "tier": pb.Tier(budget), "daily_aed": budget, "split": sp,
		"funnel": funnel, "rules": rules, "arabic_share": round2(r.ArabicShare), "seasons": Upcoming(in.Now, 365), "agency": AgencyPlaybook,
		"ranked": ranked, "freshness_pct": pb.FreshnessPct, "batch_day": r.BatchWeekday.String(), "self_tune": pb.SelfTune, "tuned": L.Tuned,
		"chat_ads": "not offered",
	}
}

func firstPos(xs ...float64) float64 {
	for _, x := range xs {
		if x > 0 {
			return x
		}
	}
	return 0
}

func (e *Engine) learning(acct string, in CycleInput) map[string]any {
	var L Ledger
	e.load(acct, "ledger", &L)
	econ := ComputeEconomics(e.B.Econ, in.Shop, in.Now)
	open, scored := 0, 0
	for _, p := range L.Preds {
		if p.Scored {
			scored++
		} else {
			open++
		}
	}
	var recent []Pred
	for i := len(L.Preds) - 1; i >= 0 && len(recent) < 20; i-- {
		recent = append(recent, L.Preds[i])
	}
	mode := "bounds"
	var bounds model.TuneBounds
	if in.Playbook != nil {
		mode, bounds = in.Playbook.SelfTune, in.Playbook.TuneBounds
	}
	reps := L.Reports
	if reps == nil {
		reps = []WeeklyReport{}
	}
	return map[string]any{"account": acct, "weekly": reps, "health": L.Health(), "lessons": L.Lessons(econ.TargetCPA), "tuned": L.Tuned, "changes": L.Changes,
		"open": open, "scored": scored, "recent": recent, "cells": len(L.Priors), "self_tune": mode, "bounds": bounds, "target_cpa": econ.TargetCPA, "at": time.Now()}
}
