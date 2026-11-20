package brain

// playbook.go — the owner's Playbook inside the brain. The engine already filtered the shop with it (Avoid never
// reaches here); the brain (1) moves its thresholds by the owner's knobs, (2) ranks products fresh-first, (3) puts
// his free-text instructions FIRST in the planner and copy prompts, verbatim, and (4) checks the planner's answer
// against the structured rules before anything leaves the brain.

import (
	"fmt"
	"strings"
	"time"

	"kaa/model"
)

// withKnobs: the rules and economics the owner's Strategy knobs make (0 / "" = the default).
func withKnobs(r RulesConfig, e EconConfig, k model.Knobs) (RulesConfig, EconConfig) {
	if k.KillP > 0 {
		r.KillP = k.KillP
	}
	if k.ScaleP > 0 {
		r.ScaleP = k.ScaleP
	}
	if k.ScaleMinPurch > 0 {
		r.ScaleMinPurch = int64(k.ScaleMinPurch)
	}
	if k.HardStopMult > 0 {
		r.HardStopMult = k.HardStopMult
	}
	if k.FatigueFreq > 0 {
		r.FatigueFreq = k.FatigueFreq
	}
	if k.FatigueCTRDropPct > 0 {
		r.FatigueCTRDrop = k.FatigueCTRDropPct / 100
	}
	if k.ArabicSharePct > 0 {
		r.ArabicShare = k.ArabicSharePct / 100
	}
	if wd, ok := model.Weekdays[k.BatchDay]; ok {
		r.BatchWeekday = wd
	}
	if k.MaxNewAdsBatch > 0 {
		r.MaxNewAdsBatch = k.MaxNewAdsBatch
	}
	if k.ProfitSharePct > 0 {
		e.ProfitShare = k.ProfitSharePct / 100
	}
	return r, e
}

// languageSlots: an ad language the owner switched off gets no new-ad slot (its slots go to the other language).
func languageSlots(rr *RulesResult, langs []string) {
	on := map[string]bool{}
	for _, l := range langs {
		on[l] = true
	}
	if len(on) == 0 || (on["ar"] && on["en"]) {
		return
	}
	for _, l := range []string{"ar", "en"} {
		if !on[l] {
			other := map[string]string{"ar": "en", "en": "ar"}[l]
			rr.NewAdSlots[other] += rr.NewAdSlots[l]
			rr.NewAdSlots[l] = 0
		}
	}
}

// adDays: days each product has been in the app's live ads (from the library creative each ad uses).
func adDays(in CycleInput) (map[string]int, map[string]bool) {
	lib := map[string]Creative{}
	for _, c := range in.Creatives {
		lib[c.ID] = c
	}
	days, fat := map[string]int{}, map[string]bool{}
	for _, c := range in.Tree {
		if !c.MadeByApp {
			continue
		}
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				cr, ok := lib[a.CreativeID]
				if !ok || a.Status != "ACTIVE" || cr.CreatedAt.IsZero() {
					continue
				}
				d := int(in.Now.Sub(cr.CreatedAt).Hours() / 24)
				for _, id := range cr.ProductIDs {
					if d > days[id] {
						days[id] = d
					}
				}
			}
		}
	}
	return days, fat
}

// RankFor: the ranked candidates for this cycle (the shop is already filtered by the engine).
func RankFor(in CycleInput, mshop model.ShopSnapshot, pb model.Playbook, baseline string, verdicts []Verdict) []model.RankedProduct {
	var tr []string
	if in.Trending != nil {
		tr = in.Trending.Selling
	}
	days, fat := adDays(in)
	if len(verdicts) > 0 { // a fatigued ad marks its products
		lib := map[string]Creative{}
		for _, c := range in.Creatives {
			lib[c.ID] = c
		}
		adCr := map[string]string{}
		for _, c := range in.Tree {
			for _, s := range c.AdSets {
				for _, a := range s.Ads {
					adCr[a.ID] = a.CreativeID
				}
			}
		}
		for _, v := range verdicts {
			if v.Verdict == "fatigued" {
				for _, id := range lib[adCr[v.Object]].ProductIDs {
					fat[id] = true
				}
			}
		}
	}
	return model.Rank(mshop.Products, pb, model.RankSignals{Now: in.Now, Trending: tr, AdDays: days, Fatigued: fat, Baseline: baseline})
}

// ownerBlock: the planner's first input field. The instructions are passed verbatim.
func ownerBlock(pb *model.Playbook, notes []string) map[string]any {
	if pb == nil {
		return nil
	}
	rules := func(rs []model.PBRule) []string {
		var o []string
		for _, r := range rs {
			o = append(o, r.Kind+": "+firstNonEmpty(r.Label, r.Value))
		}
		return o
	}
	return map[string]any{
		"instructions_verbatim":  pb.Instructions,
		"focus_pinned":           rules(pb.Focus),
		"avoid_already_removed":  rules(pb.Avoid),
		"prefer_new_trending":    fmt.Sprintf("%.0f%%", pb.FreshnessPct),
		"min_stock":              pb.MinStock,
		"min_margin_pct":         pb.MinMarginPct,
		"countries":              pb.Countries,
		"age_range":              []int{pb.AgeMin, pb.AgeMax},
		"gender":                 pb.Gender,
		"ad_languages":           pb.Languages,
		"exclude_buyers_days":    pb.ExcludeBuyersDays,
		"budget_split_pct":       pb.Split,
		"small_budget_split_pct": pb.SmallSplit,
		"offers_allowed":         pb.OffersAllowed,
		"notes_on_upcoming_jobs": notes,
	}
}

// OwnerBrief: the owner's instructions as they go into every copy / creation request (verbatim, first).
func OwnerBrief(pb *model.Playbook, extra string) string {
	var parts []string
	if pb != nil && strings.TrimSpace(pb.Instructions) != "" {
		parts = append(parts, "OWNER'S INSTRUCTIONS (always obey):\n"+pb.Instructions)
	}
	if pb != nil && !pb.OffersAllowed {
		parts = append(parts, "No discount or sale wording (the owner has not allowed offers).")
	}
	if s := strings.TrimSpace(extra); s != "" {
		parts = append(parts, s)
	}
	return strings.Join(parts, "\n\n")
}

// checkPlaybook: the planner's proposals against the structured rules. A product outside the filtered shop, a
// country outside his list, a language he switched off or a chat ad is dropped with the reason; a new ad set gets
// his age range and gender.
func checkPlaybook(ps []Proposal, in CycleInput) ([]Proposal, []string) {
	pb := in.Playbook
	if pb == nil {
		return ps, nil
	}
	allowed := map[string]bool{}
	for _, p := range in.Shop.Products {
		allowed[p.ID] = true
	}
	lib := map[string]Creative{}
	for _, c := range in.Creatives {
		lib[c.ID] = c
	}
	cs := map[string]bool{}
	for _, c := range pb.CountriesOr(in.Limits.Countries) {
		cs[strings.ToUpper(c)] = true
	}
	langOn := map[string]bool{}
	for _, l := range pb.Languages {
		langOn[l] = true
	}
	var out []Proposal
	var drop []string
	for _, p := range ps {
		why := ""
		ids := map[string]bool{}
		collectIDs(p.Params["product_ids"], ids)
		if cr, ok := p.Params["creative_id"].(string); ok {
			if c, ok := lib[cr]; ok {
				for _, id := range c.ProductIDs {
					ids[id] = true
				}
				if c.Lang != "" && len(langOn) > 0 && !langOn[c.Lang] && strings.HasPrefix(p.Kind, "create_") {
					why = "ad language " + c.Lang + " is switched off in your playbook"
				}
			}
		}
		for id := range ids {
			if !allowed[id] {
				why = "product " + id + " is not allowed by your playbook (avoided, or under your stock / margin floor)"
			}
		}
		if p.Kind == "create_adset" {
			for _, c := range anyStrings(p.Params["countries"]) {
				if len(cs) > 0 && !cs[strings.ToUpper(c)] {
					why = "country " + c + " is outside your playbook"
				}
			}
			if d, _ := p.Params["destination"].(string); d != "" && d != "website" {
				why = "chat ads are not offered"
			}
			if why == "" {
				p.Params["age_range"] = []any{float64(pb.AgeMin), float64(pb.AgeMax)}
				if pb.Gender == "women" {
					p.Params["genders"] = []any{2.0}
				} else {
					delete(p.Params, "genders")
				}
			}
		}
		if why != "" {
			drop = append(drop, p.ID+" "+p.Kind+": "+why)
			continue
		}
		out = append(out, p)
	}
	return out, drop
}

func collectIDs(v any, into map[string]bool) {
	for _, s := range anyStrings(v) {
		into[s] = true
	}
}

func anyStrings(v any) []string {
	switch x := v.(type) {
	case []string:
		return x
	case []any:
		var o []string
		for _, e := range x {
			o = append(o, fmt.Sprint(e))
		}
		return o
	}
	return nil
}

// feedBaseline: the first day the engine recorded the feed (the oldest first_seen).
func feedBaseline(s model.ShopSnapshot) string {
	b := ""
	for _, p := range s.Products {
		if p.FirstSeen != "" && (b == "" || p.FirstSeen < b) {
			b = p.FirstSeen
		}
	}
	return b
}

var _ = time.Now
