// Package executor is the ONLY code that writes to Meta on the brain's behalf. Claude proposes, the executor decides:
// every Proposal goes through Check (pure, table-tested) and only then through Execute.
//
// Check order (CONTRACT.md): kind allow-listed → the owner's limits (daily / monthly caps including what running
// budgets already commit, ± max_budget_step_pct, cooldown per object, max_active_ads, countries) → Meta's write
// rates (≤ 4 budget changes per object per hour, ≥ 30 s between edits of one object, the BUC usage headers) → the
// copy linter → shadow mode logs only.
//
// AED → fils happens here and only here (Fils). New objects are created PAUSED and set ACTIVE as a separate checked
// step. The app only ever writes to objects it made ("KAA: …" names, or a tmp: id it created in this run): the owner's
// own campaigns are read, never touched.
package executor

import (
	"errors"
	"fmt"
	"math"
	"sort"
	"strings"
	"time"

	"kaa/meta"
	"kaa/model"
)

// MaxFils: no single budget the app writes may exceed AED 100,000 a day, whatever the limits say (a typo guard).
const MaxFils = 100_000 * meta.MinorPerAED

// Fils converts AED to Meta's minor unit for AED (offset 100: META-FACTS §3). fils = round(aed × 100). Refuses NaN,
// infinities, negatives, zero and anything above MaxFils.
func Fils(aed float64) (int64, error) {
	if math.IsNaN(aed) || math.IsInf(aed, 0) {
		return 0, errors.New("the amount is not a number")
	}
	if aed <= 0 {
		return 0, fmt.Errorf("the amount must be above 0 (got %.2f AED)", aed)
	}
	f := math.Round(aed * meta.MinorPerAED)
	if f < 1 {
		return 0, fmt.Errorf("%.4f AED is below 1 fils", aed)
	}
	if f > MaxFils {
		return 0, fmt.Errorf("%.2f AED is above the AED %d sanity ceiling", aed, MaxFils/meta.MinorPerAED)
	}
	return int64(f), nil
}

// Env: everything Check looks at. Built fresh from the engine's state before each batch.
type Env struct {
	Now           time.Time
	Limits        model.Limits
	Tree          []model.Campaign
	MonthSpendAED float64 // this calendar month so far (account time zone), from Insights
	Ledger        *Ledger
	Usage         meta.Usage
	Lint          func(p model.Proposal) []string // the copy / policy linter (BRAIN's, or the built-in one)
	Loc           *time.Location                  // account time zone (nil = UTC)
	ActivatedNow  int                             // ads set ACTIVE earlier in this same batch
	CommitDelta   float64                         // daily budget committed earlier in this same batch (AED)
	Links         model.AccountLinks              // the account's Page / Instagram / pixel / catalog / WhatsApp
	// the optional global caps across ALL the owner's automated accounts (Settings → Limits → All accounts)
	GlobalDailyAED      float64
	GlobalMonthlyAED    float64
	OtherCommitted      float64 // daily budgets running in the OTHER automated accounts
	OtherMonthProjected float64 // the other accounts' month: spent + running budgets to month end
}

type Decision struct {
	OK      bool     `json:"ok"`
	Stage   string   `json:"stage"` // kind | limits | rate | lint | shadow | ok
	Reasons []string `json:"reasons,omitempty"`
}

func refuse(stage string, f string, a ...any) Decision {
	return Decision{OK: false, Stage: stage, Reasons: []string{fmt.Sprintf(f, a...)}}
}

func allowed(kind string) bool {
	for _, k := range model.ProposalKinds {
		if k == kind {
			return true
		}
	}
	return false
}

// ── reading the tree ──

type node struct {
	level    string // campaign | adset | ad
	campaign *model.Campaign
	adset    *model.AdSet
	ad       *model.Ad
}

func find(tree []model.Campaign, id string) *node {
	for i := range tree {
		c := &tree[i]
		if c.ID == id {
			return &node{level: "campaign", campaign: c}
		}
		for j := range c.AdSets {
			s := &c.AdSets[j]
			if s.ID == id {
				return &node{level: "adset", campaign: c, adset: s}
			}
			for k := range s.Ads {
				if s.Ads[k].ID == id {
					return &node{level: "ad", campaign: c, adset: s, ad: &s.Ads[k]}
				}
			}
		}
	}
	return nil
}

func running(status, effective string) bool {
	return status == "ACTIVE" && (effective == "" || effective == "ACTIVE" || effective == "IN_PROCESS" || effective == "PENDING_REVIEW" || effective == "WITH_ISSUES" || effective == "PREAPPROVED")
}

// CommittedDaily: the daily budget the account is committed to right now, in AED: every running campaign's budget
// (campaign budget) or the sum of its running ad sets' budgets (ad set budgets). ALL campaigns, the owner's too: his
// limits are the account's ceiling. Lifetime budgets count in the monthly projection, not here.
//
// ONLY the app's own campaigns ("KAA:"), the owner's rule of 22 Nov: "my campaigns budget should not calculate in
// the system, the budget which i set, that is only for the auto pilot system ads ... ignore my ads budget and spend".
// His own campaigns run outside his limits and are never counted against them.
func CommittedDaily(tree []model.Campaign) float64 {
	sum := 0.0
	for _, c := range tree {
		if !c.Managed || !running(c.Status, c.EffectiveStatus) {
			continue
		}
		if c.DailyBudgetAED > 0 {
			sum += c.DailyBudgetAED
			continue
		}
		for _, s := range c.AdSets {
			if running(s.Status, s.EffectiveStatus) {
				sum += s.DailyBudgetAED
			}
		}
	}
	return sum
}

// CommittedDailyOwn: the daily budgets of the OWNER's own running campaigns (not "KAA:"). Never counted against
// his limits; only added to Meta's account spending limit so that limit never stops his ads (safety, 22 Nov).
func CommittedDailyOwn(tree []model.Campaign) float64 {
	sum := 0.0
	for _, c := range tree {
		if c.Managed || !running(c.Status, c.EffectiveStatus) {
			continue
		}
		if c.DailyBudgetAED > 0 {
			sum += c.DailyBudgetAED
			continue
		}
		for _, s := range c.AdSets {
			if running(s.Status, s.EffectiveStatus) {
				sum += s.DailyBudgetAED
			}
		}
	}
	return sum
}

func committedLifetime(tree []model.Campaign) float64 {
	sum := 0.0
	for _, c := range tree {
		if c.Managed && running(c.Status, c.EffectiveStatus) {
			sum += c.LifetimeBudgetAED
		}
	}
	return sum
}

// ActiveAds counts the APP's ads that are ACTIVE (or in review to go active) in running ad sets and campaigns
// (Max active ads is for the autopilot's own ads; the owner's own ads are not counted, 22 Nov).
func ActiveAds(tree []model.Campaign) int {
	n := 0
	for _, c := range tree {
		if !c.Managed {
			continue
		}
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				if a.Status == "ACTIVE" && running(s.Status, "") && running(c.Status, "") {
					n++
				}
			}
		}
	}
	return n
}

// DaysLeftInMonth: today included.
func DaysLeftInMonth(now time.Time) int {
	first := time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, now.Location())
	days := first.AddDate(0, 1, -1).Day()
	return days - now.Day() + 1
}

// ProjectedMonth: what the month will cost if every running budget spends its full daily amount until the month ends.
func ProjectedMonth(env Env, committed float64) float64 {
	now := env.Now
	if env.Loc != nil {
		now = now.In(env.Loc)
	}
	return env.MonthSpendAED + committed*float64(DaysLeftInMonth(now)) + committedLifetime(env.Tree)
}

func num(p map[string]any, k string) (float64, bool) {
	switch v := p[k].(type) {
	case float64:
		return v, true
	case int:
		return float64(v), true
	case int64:
		return float64(v), true
	}
	return 0, false
}

func strs(p map[string]any, k string) []string {
	switch v := p[k].(type) {
	case []string:
		return v
	case []any:
		var out []string
		for _, x := range v {
			if s, ok := x.(string); ok {
				out = append(out, s)
			}
		}
		return out
	}
	return nil
}

func str(p map[string]any, k string) string {
	s, _ := p[k].(string)
	return s
}

// budgetOf: the daily budget (AED) an object commits when it runs: a campaign's own budget, or an ad set's budget
// under a campaign without one.
func budgetOf(n *node) float64 {
	switch n.level {
	case "campaign":
		if n.campaign.DailyBudgetAED > 0 {
			return n.campaign.DailyBudgetAED
		}
		sum := 0.0
		for _, s := range n.campaign.AdSets {
			if s.Status == "ACTIVE" {
				sum += s.DailyBudgetAED
			}
		}
		return sum
	case "adset":
		if n.campaign.DailyBudgetAED > 0 {
			return 0 // the campaign's budget is already counted
		}
		return n.adset.DailyBudgetAED
	}
	return 0
}

func isRunningNode(n *node) bool {
	switch n.level {
	case "campaign":
		return running(n.campaign.Status, n.campaign.EffectiveStatus)
	case "adset":
		return running(n.campaign.Status, "") && running(n.adset.Status, n.adset.EffectiveStatus)
	case "ad":
		return running(n.campaign.Status, "") && running(n.adset.Status, "") && running(n.ad.Status, n.ad.EffectiveStatus)
	}
	return false
}

// Check runs every check, in the contract's order, and returns the first refusal (or OK). It never calls Meta.
func Check(p model.Proposal, env Env) Decision {
	// 1. kind allow-listed
	if !allowed(p.Kind) {
		return refuse("kind", "%q is not something the executor does", p.Kind)
	}
	if p.Params == nil {
		p.Params = map[string]any{}
	}
	lim := env.Limits
	if lim.Autopilot != model.AutopilotShadow && lim.Autopilot != model.AutopilotAuto {
		return refuse("limits", "the autopilot is off")
	}
	if err := ValidateParams(p, env.Links); err != nil {
		return refuse("params", "%v", err)
	}
	reducing := p.Kind == model.KindPause || p.Kind == model.KindArchive
	spends := p.Kind == model.KindCreateCampaign || p.Kind == model.KindCreateAdSet || p.Kind == model.KindCreateAd ||
		p.Kind == model.KindResume || p.Kind == model.KindSetBudget || p.Kind == model.KindSetBid
	if spends && !lim.Set() {
		return refuse("limits", "no daily and monthly limit is set: nothing launches and no budget changes until the owner sets them")
	}

	// target: an existing object must be one the app made
	var n *node
	makes := p.Kind == model.KindCreateCampaign || p.Kind == model.KindUploadCreative || p.Kind == model.KindCreateAudience || p.Kind == model.KindCreateLookalike || p.Kind == model.KindCreateProductSet
	if !strings.HasPrefix(p.Target, "tmp:") && !makes {
		n = find(env.Tree, p.Target)
		if n == nil && (p.Kind != model.KindCreateAdSet && p.Kind != model.KindCreateAd) {
			return refuse("limits", "%s is not in the account as last read", p.Target)
		}
		if n != nil && !n.campaign.Managed && !reducing {
			return refuse("limits", "%s belongs to a campaign the app did not make; the app never changes the owner's own campaigns", p.Target)
		}
		if n != nil && !n.campaign.Managed && reducing {
			return refuse("limits", "%s belongs to a campaign the app did not make; pausing it is the owner's call", p.Target)
		}
	}

	// a new ad set / ad goes under a parent the app made (or one it is making in this run)
	for _, pk := range []string{"campaign_id", "adset_id"} {
		if parent := str(p.Params, pk); parent != "" && !strings.HasPrefix(parent, "tmp:") {
			pn := find(env.Tree, parent)
			if pn == nil {
				return refuse("limits", "%s %s is not in the account as last read", pk, parent)
			}
			if !pn.campaign.Managed {
				return refuse("limits", "%s %s belongs to a campaign the app did not make", pk, parent)
			}
		}
	}

	committed := CommittedDaily(env.Tree) + env.CommitDelta
	addDaily := 0.0
	switch p.Kind {
	case model.KindCreateCampaign:
		b, ok := num(p.Params, "daily_budget")
		if !ok || b <= 0 {
			return refuse("limits", "a new campaign needs a daily_budget in AED")
		}
		if _, err := Fils(b); err != nil {
			return refuse("limits", "daily_budget: %v", err)
		}
		// created PAUSED: it commits nothing until activation, which is checked again then; checked now anyway so
		// a campaign that could never be switched on is not made at all
		addDaily = b
	case model.KindCreateAdSet:
		cs := strs(p.Params, "countries")
		if len(cs) == 0 {
			return refuse("limits", "a new ad set needs its countries")
		}
		allowedC := map[string]bool{}
		for _, c := range lim.Countries {
			allowedC[strings.ToUpper(c)] = true
		}
		for _, c := range cs {
			if !allowedC[strings.ToUpper(c)] {
				return refuse("limits", "country %s is not in the owner's list %v", c, lim.Countries)
			}
		}
		if b, ok := num(p.Params, "daily_budget"); ok {
			if _, err := Fils(b); err != nil {
				return refuse("limits", "daily_budget: %v", err)
			}
			addDaily = b
		}
	case model.KindSetBudget, model.KindSetBid:
		field := "daily_budget"
		if p.Kind == model.KindSetBid {
			field = "bid_amount"
		}
		nv, ok := num(p.Params, field)
		if !ok {
			return refuse("limits", "%s needs %s in AED", p.Kind, field)
		}
		if _, err := Fils(nv); err != nil {
			return refuse("limits", "%s: %v", field, err)
		}
		if n == nil {
			return refuse("limits", "%s is not in the account as last read", p.Target)
		}
		old := 0.0
		switch {
		case p.Kind == model.KindSetBid && n.level == "adset":
			old = n.adset.BidAmountAED
		case p.Kind == model.KindSetBudget && n.level == "campaign":
			old = n.campaign.DailyBudgetAED
		case p.Kind == model.KindSetBudget && n.level == "adset":
			old = n.adset.DailyBudgetAED
		default:
			return refuse("limits", "%s does not apply to a %s", p.Kind, n.level)
		}
		if old <= 0 {
			return refuse("limits", "%s has no %s to change by a step", p.Target, field)
		}
		step := lim.MaxBudgetStepPct
		if step <= 0 {
			step = 20
		}
		if pct := math.Abs(nv-old) / old * 100; pct > step+1e-9 {
			return refuse("limits", "%s %.2f → %.2f AED is a %.1f%% step; the limit is ±%.0f%%", field, old, nv, pct, step)
		}
		if p.Kind == model.KindSetBudget && isRunningNode(n) {
			addDaily = nv - old
		}
	case model.KindResume:
		if n == nil {
			return refuse("limits", "%s is not in the account as last read", p.Target)
		}
		if !isRunningNode(n) {
			addDaily = budgetOf(n)
		}
		if n.level == "ad" && n.ad.Status != "ACTIVE" {
			if ActiveAds(env.Tree)+env.ActivatedNow+1 > maxAds(lim) {
				return refuse("limits", "%d ads are active; the limit is %d", ActiveAds(env.Tree)+env.ActivatedNow, maxAds(lim))
			}
		}
	case model.KindCreateAd:
		if ActiveAds(env.Tree)+env.ActivatedNow+1 > maxAds(lim) {
			return refuse("limits", "%d ads are active; the limit is %d (a new ad could never be switched on)", ActiveAds(env.Tree)+env.ActivatedNow, maxAds(lim))
		}
	}
	if addDaily > 0 {
		if committed+addDaily > lim.DailyAED+1e-9 {
			return refuse("limits", "running budgets would reach %.2f AED a day (%.2f already committed + %.2f); the daily limit is %.2f", committed+addDaily, committed, addDaily, lim.DailyAED)
		}
		if proj := ProjectedMonth(env, committed+addDaily); proj > lim.MonthlyAED+1e-9 {
			return refuse("limits", "the month would reach %.2f AED (%.2f spent + running budgets to month end); the monthly limit is %.2f", proj, env.MonthSpendAED, lim.MonthlyAED)
		}
		if env.GlobalDailyAED > 0 && env.OtherCommitted+committed+addDaily > env.GlobalDailyAED+1e-9 {
			return refuse("limits", "all accounts together would run %.2f AED a day; the all-accounts daily limit is %.2f", env.OtherCommitted+committed+addDaily, env.GlobalDailyAED)
		}
		if env.GlobalMonthlyAED > 0 {
			if proj := env.OtherMonthProjected + ProjectedMonth(env, committed+addDaily); proj > env.GlobalMonthlyAED+1e-9 {
				return refuse("limits", "all accounts together would reach %.2f AED this month; the all-accounts monthly limit is %.2f", proj, env.GlobalMonthlyAED)
			}
		}
	}

	// cooldown: no second significant change to one object within cooldown_hours (pause and archive are exempt:
	// stopping spend is never delayed)
	if !reducing && n != nil && env.Ledger != nil && (p.Kind == model.KindSetBudget || p.Kind == model.KindSetBid || p.Kind == model.KindResume) {
		cd := lim.CooldownHours
		if cd <= 0 {
			cd = 72
		}
		if last := env.Ledger.LastChange(p.Target); !last.IsZero() && env.Now.Sub(last) < time.Duration(cd*float64(time.Hour)) {
			return refuse("limits", "%s was changed %s ago; the cooldown is %.0f h", p.Target, env.Now.Sub(last).Round(time.Minute), cd)
		}
	}

	// 3. Meta's write rate
	if n != nil && env.Ledger != nil {
		if last := env.Ledger.LastEdit(p.Target); !last.IsZero() && env.Now.Sub(last) < 30*time.Second {
			return refuse("rate", "%s was edited %s ago; Meta allows one edit per object every 30 s", p.Target, env.Now.Sub(last).Round(time.Second))
		}
		if p.Kind == model.KindSetBudget && env.Ledger.BudgetChangesWithin(p.Target, env.Now, time.Hour) >= 4 {
			return refuse("rate", "%s had 4 budget changes in the last hour (Meta's limit)", p.Target)
		}
	}
	if !reducing && !env.Usage.At.IsZero() && env.Now.Sub(env.Usage.At) < 2*time.Hour {
		if env.Usage.MaxPct >= 90 {
			return refuse("rate", "Meta reports %.0f%% of the hourly call allowance used; waiting", env.Usage.MaxPct)
		}
		if env.Usage.RegainMin > 0 && env.Now.Sub(env.Usage.At) < time.Duration(env.Usage.RegainMin)*time.Minute {
			return refuse("rate", "Meta throttled the account; access back in about %d min", env.Usage.RegainMin)
		}
	}

	// 4. copy linter
	if p.Kind == model.KindUploadCreative || p.Kind == model.KindCreateAd || p.Kind == model.KindCreateAudience || p.Kind == model.KindCreateProductSet {
		lint := env.Lint
		if lint == nil {
			lint = BuiltinLint
		}
		if probs := lint(p); len(probs) > 0 {
			sort.Strings(probs)
			return Decision{OK: false, Stage: "lint", Reasons: probs}
		}
	}

	// 5. shadow: everything above passed, nothing is written
	if lim.Autopilot == model.AutopilotShadow {
		return Decision{OK: false, Stage: "shadow", Reasons: []string{"shadow mode: passed every check, logged only"}}
	}
	return Decision{OK: true, Stage: "ok"}
}

func maxAds(l model.Limits) int {
	if l.MaxActiveAds <= 0 {
		return 6
	}
	return l.MaxActiveAds
}

// BuiltinLint: a floor under the brain's own policy linter (LANE BRAIN's is the real one). Copy is checked when it is
// on the proposal (headline / primary_text), and claims Meta's health & beauty rules and UAE practice forbid are
// refused outright.
var bannedClaims = []string{"cure", "cures", "guaranteed results", "100% guaranteed", "miracle", "permanent results", "medical grade", "doctor approved", "lose weight", "before and after", "يعالج", "مضمون 100", "نتائج مضمونة", "معجزة"}

func BuiltinLint(p model.Proposal) []string {
	var probs []string
	text := strings.ToLower(texts(p))
	for _, b := range bannedClaims {
		if strings.Contains(text, strings.ToLower(b)) {
			probs = append(probs, fmt.Sprintf("copy makes a claim Meta refuses for beauty ads: %q", b))
		}
	}
	if len(str(p.Params, "headline")) > 255 {
		probs = append(probs, "headline is longer than 255 characters")
	}
	return probs
}
