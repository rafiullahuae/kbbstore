// Package safety keeps Meta's own protections in place, so the ads stay safe while the Mac is closed (the owner's
// choice, 20 Nov 2026). Run on launch, after every executor batch that wrote anything, and every cycle.
//
// (a) The ACCOUNT spending limit (spend_cap). Meta's cap is cumulative (it stops all delivery when amount_spent
//
//	reaches it), not monthly, so the cap that means "no more than the owner's monthly limit this month" is
//	    target = amount_spent + max(0, monthly_limit − spent this month)
//	rounded DOWN to the fils. Rules:
//	  - no cap on the account (Meta reports 0, which means NONE) → set it to target
//	  - a cap above target → lowered to target
//	  - a cap below target → raised ONLY if it is the cap this app set last time (the month rolled over or the
//	    limit went up); a lower cap the owner set by hand is never raised
//	  - 0 is never written (0 removes the cap); neither is anything ≤ 0. Meta's write takes AED in standard units
//	    (META-FACTS §6), at most 10 changes a day: we allow ourselves 6
//	  - after a write the account is read again; if Meta reset amount_spent, the cap is recomputed and lowered
//
// (b) Automated Rules (adrules_library) on the app's own objects only (names starting "KAA:"), found and updated by
//
//	name, never duplicated (extras deleted, stale KAA: rules deleted):
//	  KAA: no-purchase ad set   SCHEDULE semi-hourly: ad set, LAST_7_DAYS, spent > k×target CPA, purchases < 1 → PAUSE
//	  KAA: no-purchase ad       the same for ads
//	  KAA: high-CPA ad set      SCHEDULE semi-hourly: LAST_7_DAYS, spent > k×CPA and cost per purchase > k×CPA → PAUSE
//	  KAA: daily limit          TRIGGER (real time, p99 ≈ 7.5 min): campaign, TODAY, spent > daily limit ÷ running
//	                            KAA campaigns → PAUSE
//	Money in rule filters is in the currency's base unit (fils). The no-purchase / high-CPA rules need a target
//	CPA in Settings → Limits; without one they are not made and an alert says so.
package safety

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"math"
	"net/url"
	"sort"
	"strings"
	"sync"
	"time"

	"kaa/meta"
	"kaa/model"
)

const (
	tolAED          = 1.0 // a cap within 1 AED of target is left alone (saves Meta's 10-a-day allowance)
	maxCapWritesDay = 6
)

type State struct {
	LastCapAED float64           `json:"last_cap_aed"` // the cap this app last wrote (0 = never)
	CapWrites  []time.Time       `json:"cap_writes"`
	RuleHashes map[string]string `json:"rule_hashes"` // rule name → hash of the specs this app last wrote
	RuleIDs    map[string]string `json:"rule_ids"`
	LastCheck  time.Time         `json:"last_check"`
	LastReport *Report           `json:"last_report,omitempty"`
}

type Net struct {
	Meta    *meta.Client
	Act     func() string
	Log     func(kind, summary string, data any)
	Persist func(*State)
	Now     func() time.Time

	mu    sync.Mutex
	State *State
}

type Input struct {
	Limits        model.Limits
	MonthSpendAED float64 // the APP's own campaigns' spend this month
	Tree          []model.Campaign
	// what the owner's own campaigns need for the rest of the month (their daily budgets × days left): added to the
	// account limit so it never stops his ads (22 Nov)
	OwnRemainingAED float64
}

type CapReport struct {
	BeforeAED float64 `json:"before"`
	TargetAED float64 `json:"target"`
	AfterAED  float64 `json:"after"`
	Action    string  `json:"action"` // set | lowered | raised | kept | skipped
	Reason    string  `json:"reason"`
}

type RuleReport struct {
	Name   string `json:"name"`
	ID     string `json:"id,omitempty"`
	Action string `json:"action"` // created | updated | kept | deleted | skipped
	Reason string `json:"reason,omitempty"`
}

type Report struct {
	At       time.Time    `json:"at"`
	SpendCap CapReport    `json:"spend_cap"`
	Rules    []RuleReport `json:"rules"`
	Alerts   []string     `json:"alerts,omitempty"`
}

func (n *Net) now() time.Time {
	if n.Now != nil {
		return n.Now()
	}
	return time.Now()
}

func (n *Net) log(kind, s string, d any) {
	if n.Log != nil {
		n.Log(kind, s, d)
	}
}

// CapTarget: what the account spending limit should be (AED, rounded down to the fils); ok=false when no positive
// cap can express it (nothing spent yet on the account and nothing left this month).
func CapTarget(amountSpentAED, monthlyAED, monthSpendAED float64) (float64, bool) {
	remaining := math.Max(0, monthlyAED-monthSpendAED)
	t := math.Floor((amountSpentAED+remaining)*100) / 100
	return t, t > 0
}

// DecideCap: the pure decision (table-tested). current 0 = Meta has no cap.
func DecideCap(currentAED, targetAED, lastOursAED float64) (action string, write float64) {
	switch {
	case !(targetAED > 0):
		return "skipped", 0
	case currentAED == 0:
		return "set", targetAED
	case currentAED > targetAED+tolAED:
		return "lowered", targetAED
	case currentAED < targetAED-tolAED:
		if lastOursAED > 0 && math.Abs(currentAED-lastOursAED) < 0.01 {
			return "raised", targetAED
		}
		return "kept", 0 // the owner's own lower cap: never raised
	}
	return "kept", 0
}

func (n *Net) capWritesToday(now time.Time) int {
	c := 0
	keep := n.State.CapWrites[:0]
	for _, t := range n.State.CapWrites {
		if now.Sub(t) < 24*time.Hour {
			keep = append(keep, t)
			c++
		}
	}
	n.State.CapWrites = keep
	return c
}

// Ensure brings the account cap and the KAA: rules to what the limits say. Idempotent: a second run writes nothing.
func (n *Net) Ensure(ctx context.Context, in Input) (Report, error) {
	n.mu.Lock()
	defer n.mu.Unlock()
	if n.State == nil {
		n.State = &State{}
	}
	if n.State.RuleHashes == nil {
		n.State.RuleHashes = map[string]string{}
	}
	if n.State.RuleIDs == nil {
		n.State.RuleIDs = map[string]string{}
	}
	now := n.now()
	rep := Report{At: now.UTC()}
	act := n.Act()
	if act == "" {
		return rep, fmt.Errorf("no ad account chosen")
	}
	defer func() {
		n.State.LastCheck = now
		r := rep
		n.State.LastReport = &r
		if n.Persist != nil {
			n.Persist(n.State)
		}
		n.log("safety", fmt.Sprintf("spend_cap %s; %d rules", rep.SpendCap.Action, len(rep.Rules)), rep)
	}()
	if !in.Limits.Set() {
		rep.SpendCap = CapReport{Action: "skipped", Reason: "no daily and monthly limit set (nothing launches)"}
		rep.Alerts = append(rep.Alerts, "Set a daily and a monthly limit: until then the app launches nothing and Meta's safety rules are not installed.")
		return rep, nil
	}

	// (a) spend_cap. The owner, 22 Nov: "if the system budget + my budget, the system update the monthly budget by
	// adding both budgets. but the daily budget is for the system ads only." So the account limit = what is spent +
	// what the autopilot may still spend this month (his monthly limit − its own spend) + what HIS campaigns need for
	// the rest of the month (their daily budgets × days left, OwnRemainingAED): it moves with his budgets, it never
	// stops his own ads, and the whole account still has a ceiling while the Mac is closed.
	acc, err := n.Meta.Account(ctx, act)
	if err != nil {
		return rep, fmt.Errorf("reading the account: %w", err)
	}
	target, ok := CapTarget(acc.AmountSpentAED+in.OwnRemainingAED, in.Limits.MonthlyAED, in.MonthSpendAED)
	cr := CapReport{BeforeAED: acc.SpendCapAED, TargetAED: target, AfterAED: acc.SpendCapAED}
	if !ok {
		cr.Action, cr.Reason = "skipped", "no positive cap expresses it (0 would REMOVE the cap)"
		rep.Alerts = append(rep.Alerts, "The monthly limit is used up and Meta's spending limit cannot be set below what was already spent: the app pauses its own campaigns instead.")
	} else {
		action, write := DecideCap(acc.SpendCapAED, target, n.State.LastCapAED)
		cr.Action = action
		if write > 0 {
			if n.capWritesToday(now) >= maxCapWritesDay {
				cr.Action, cr.Reason = "skipped", fmt.Sprintf("already changed %d times today (Meta allows 10)", maxCapWritesDay)
			} else if err := n.Meta.SetSpendCap(ctx, act, write); err != nil {
				cr.Action, cr.Reason = "failed", err.Error()
				rep.Alerts = append(rep.Alerts, "Could not set Meta's account spending limit: "+err.Error())
			} else {
				n.State.CapWrites = append(n.State.CapWrites, now)
				n.State.LastCapAED = write
				cr.AfterAED = write
				// read back: if Meta restarted amount_spent at the write, the cap above is too generous; recompute
				if acc2, err := n.Meta.Account(ctx, act); err == nil {
					if t2, ok2 := CapTarget(acc2.AmountSpentAED+in.OwnRemainingAED, in.Limits.MonthlyAED, in.MonthSpendAED); ok2 && acc2.SpendCapAED > t2+tolAED && n.capWritesToday(now) < maxCapWritesDay {
						if err := n.Meta.SetSpendCap(ctx, act, t2); err == nil {
							n.State.CapWrites = append(n.State.CapWrites, now)
							n.State.LastCapAED, cr.AfterAED = t2, t2
							cr.Reason = "lowered again after Meta reset amount_spent"
						}
					}
				}
			}
		} else if action == "kept" && acc.SpendCapAED < target-tolAED {
			cr.Reason = "the owner's own lower cap is kept (never raised by the app)"
		}
	}
	rep.SpendCap = cr

	// (b) rules
	desired, notes := DesiredRules(in.Limits, in.Tree)
	rep.Alerts = append(rep.Alerts, notes...)
	existing, err := n.Meta.Rules(ctx, act)
	if err != nil {
		return rep, fmt.Errorf("reading the automated rules: %w", err)
	}
	byName := map[string][]meta.Rule{}
	for _, r := range existing {
		if strings.HasPrefix(r.Name, meta.ManagedPrefix) {
			byName[r.Name] = append(byName[r.Name], r)
		}
	}
	want := map[string]bool{}
	for _, d := range desired {
		want[d.Name] = true
		h := d.Hash()
		have := byName[d.Name]
		for _, extra := range sliceFrom(have, 1) { // duplicates (a retried create): keep the first
			if err := n.Meta.Delete(ctx, extra.ID, nil); err == nil {
				rep.Rules = append(rep.Rules, RuleReport{Name: d.Name, ID: extra.ID, Action: "deleted", Reason: "duplicate"})
			}
		}
		form := d.Form()
		switch {
		case len(have) == 0:
			var r meta.IDResp
			if err := n.Meta.Post(ctx, act+"/adrules_library", form, "", &r); err != nil {
				rep.Rules = append(rep.Rules, RuleReport{Name: d.Name, Action: "failed", Reason: err.Error()})
				rep.Alerts = append(rep.Alerts, "Could not create the Meta rule "+d.Name+": "+err.Error())
				continue
			}
			n.State.RuleHashes[d.Name], n.State.RuleIDs[d.Name] = h, r.ID
			rep.Rules = append(rep.Rules, RuleReport{Name: d.Name, ID: r.ID, Action: "created"})
		case n.State.RuleHashes[d.Name] == h && have[0].Status == "ENABLED" && n.State.RuleIDs[d.Name] == have[0].ID:
			rep.Rules = append(rep.Rules, RuleReport{Name: d.Name, ID: have[0].ID, Action: "kept"})
		default:
			if err := n.Meta.Update(ctx, have[0].ID, form); err != nil {
				rep.Rules = append(rep.Rules, RuleReport{Name: d.Name, ID: have[0].ID, Action: "failed", Reason: err.Error()})
				continue
			}
			n.State.RuleHashes[d.Name], n.State.RuleIDs[d.Name] = h, have[0].ID
			rep.Rules = append(rep.Rules, RuleReport{Name: d.Name, ID: have[0].ID, Action: "updated"})
		}
	}
	names := make([]string, 0, len(byName))
	for name := range byName {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		if want[name] {
			continue
		}
		for _, r := range byName[name] { // a KAA: rule no longer wanted (e.g. the target CPA was cleared)
			if err := n.Meta.Delete(ctx, r.ID, nil); err == nil {
				rep.Rules = append(rep.Rules, RuleReport{Name: name, ID: r.ID, Action: "deleted", Reason: "no longer wanted"})
				delete(n.State.RuleHashes, name)
				delete(n.State.RuleIDs, name)
			}
		}
	}
	return rep, nil
}

func sliceFrom(r []meta.Rule, i int) []meta.Rule {
	if len(r) <= i {
		return nil
	}
	return r[i:]
}

// ── the rules ──

type Rule struct {
	Name       string
	Evaluation map[string]any
	Execution  map[string]any
	Schedule   map[string]any // nil for TRIGGER rules
}

func (r Rule) Form() url.Values {
	v := url.Values{"name": {r.Name}, "status": {"ENABLED"}, "evaluation_spec": {js(r.Evaluation)}, "execution_spec": {js(r.Execution)}}
	if r.Schedule != nil {
		v.Set("schedule_spec", js(r.Schedule))
	}
	return v
}

func (r Rule) Hash() string {
	h := sha256.Sum256([]byte(r.Name + js(r.Evaluation) + js(r.Execution) + js(r.Schedule)))
	return hex.EncodeToString(h[:12])
}

func js(v any) string {
	b, _ := json.Marshal(v) // map keys are sorted by encoding/json: stable hashes
	return string(b)
}

func f(field, op string, value any) map[string]any {
	return map[string]any{"field": field, "operator": op, "value": value}
}

func fils(aed float64) int64 { return int64(math.Round(aed * meta.MinorPerAED)) }

// RunningManaged: the app's campaigns that can spend now.
func RunningManaged(tree []model.Campaign) int {
	c := 0
	for _, x := range tree {
		if x.Managed && x.Status == "ACTIVE" {
			c++
		}
	}
	return c
}

// DesiredRules: the KAA: rules the limits call for. Pure (table-tested).
func DesiredRules(l model.Limits, tree []model.Campaign) ([]Rule, []string) {
	var out []Rule
	var notes []string
	pause := map[string]any{"execution_type": "PAUSE"}
	ours := f("campaign.name", "CONTAIN", meta.ManagedPrefix)
	if l.TargetCPAAED > 0 {
		k := l.KillMultiple
		if k <= 0 {
			k = 3
		}
		th := fils(k * l.TargetCPAAED)
		semi := map[string]any{"schedule_type": "SEMI_HOURLY"}
		for _, lvl := range []struct{ name, entity string }{{"KAA: no-purchase ad set", "ADSET"}, {"KAA: no-purchase ad", "AD"}} {
			out = append(out, Rule{Name: lvl.name, Schedule: semi, Execution: pause, Evaluation: map[string]any{
				"evaluation_type": "SCHEDULE",
				"filters": []any{f("entity_type", "EQUAL", lvl.entity), ours, f("time_preset", "EQUAL", "LAST_7_DAYS"),
					f("spent", "GREATER_THAN", th), f("offsite_conversion.fb_pixel_purchase", "LESS_THAN", 1)}}})
		}
		out = append(out, Rule{Name: "KAA: high-CPA ad set", Schedule: semi, Execution: pause, Evaluation: map[string]any{
			"evaluation_type": "SCHEDULE",
			"filters": []any{f("entity_type", "EQUAL", "ADSET"), ours, f("time_preset", "EQUAL", "LAST_7_DAYS"),
				f("spent", "GREATER_THAN", th), f("cost_per_purchase_fb", "GREATER_THAN", th)}}})
	} else {
		notes = append(notes, "No target CPA set (Settings → Limits): Meta's 'pause an ad set that spends without buying' rules are not installed.")
	}
	if l.DailyAED > 0 {
		nRun := RunningManaged(tree)
		if nRun < 1 {
			nRun = 1
		}
		per := fils(l.DailyAED / float64(nRun))
		// (no "monthly" TRIGGER rule: Meta's trigger rules take TODAY presets only, META-FACTS §7 — 0.6.5 added one
		// with THIS_MONTH and Meta can refuse it. The month is held by the account spending limit above and by the
		// app's own budget pacing.)
		out = append(out, Rule{Name: "KAA: daily limit", Execution: pause, Evaluation: map[string]any{
			"evaluation_type": "TRIGGER",
			"trigger":         map[string]any{"type": "STATS_CHANGE", "field": "spent", "operator": "GREATER_THAN", "value": per},
			"filters":         []any{f("entity_type", "EQUAL", "CAMPAIGN"), f("name", "CONTAIN", meta.ManagedPrefix), f("time_preset", "EQUAL", "TODAY")}}})
	}
	return out, notes
}
