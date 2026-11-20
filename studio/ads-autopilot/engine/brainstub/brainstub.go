// Package brainstub is a rules-only stand-in for LANE BRAIN's engine/brain, so the engine runs and is tested on its
// own. It never calls Claude or Meta. The integrator swaps it out in engine/brain_wire.go.
//
// Rules: launch one Advantage+ sales campaign when none of the app's exists (limits set, a creative in the library);
// pause an app ad set that spent ≥ k × target CPA in 7 days without a purchase; raise a winner's campaign budget 15%
// when its settled 7-day CPA beats the target by 15% with ≥ 5 purchases.
package brainstub

import (
	"context"
	"fmt"
	"math"
	"sort"
	"strings"
	"time"

	"kaa/model"
)

type Stub struct{}

func New(model.BrainDeps) model.Brain { return &Stub{} }

func sum7(rows []model.MetricRow, level, id string, now time.Time, settledOnly bool) (spend, purch float64) {
	from := now.AddDate(0, 0, -7).Format("2006-01-02")
	for _, r := range rows {
		if r.Level == level && r.ObjectID == id && r.Date >= from && (!settledOnly || !r.Settling) {
			spend += r.SpendAED
			purch += r.Purchases
		}
	}
	return
}

func (s *Stub) Cycle(ctx context.Context, in model.CycleInput) (model.CycleOutput, error) {
	var props []model.Proposal
	n := 0
	id := func() string { n++; return fmt.Sprintf("stub-%s-%d", in.Now.UTC().Format("20060102T15"), n) }
	lim := in.Limits
	k := lim.KillMultiple
	if k <= 0 {
		k = 3
	}
	managed := 0
	for _, c := range in.Tree {
		if !c.Managed || c.Status == "ARCHIVED" {
			continue
		}
		managed++
		for _, s := range c.AdSets {
			if s.Status != "ACTIVE" || lim.TargetCPAAED <= 0 {
				continue
			}
			sp, pu := sum7(in.Metrics, "adset", s.ID, in.Now, false)
			if pu == 0 && sp >= k*lim.TargetCPAAED {
				props = append(props, model.Proposal{ID: id(), Kind: model.KindPause, Target: s.ID, Confidence: 0.8,
					Why:      fmt.Sprintf("spent %.0f AED in 7 days with no purchase (≥ %.0f× the %.0f AED target CPA)", sp, k, lim.TargetCPAAED),
					Evidence: []string{fmt.Sprintf("adset %s 7d spend %.2f, purchases 0", s.ID, sp)}})
			}
		}
		if c.Status == "ACTIVE" && c.DailyBudgetAED > 0 && lim.TargetCPAAED > 0 {
			sp, pu := sum7(in.Metrics, "campaign", c.ID, in.Now, true)
			if pu >= 5 && sp/pu <= 0.85*lim.TargetCPAAED {
				nb := math.Round(c.DailyBudgetAED*1.15*100) / 100
				props = append(props, model.Proposal{ID: id(), Kind: model.KindSetBudget, Target: c.ID, Params: map[string]any{"daily_budget": nb}, Confidence: 0.6,
					Why:      fmt.Sprintf("settled 7-day CPA %.0f AED beats the %.0f target by ≥15%% on %.0f purchases: +15%%", sp/pu, lim.TargetCPAAED, pu),
					Evidence: []string{fmt.Sprintf("campaign %s spend %.2f purchases %.0f", c.ID, sp, pu)}})
			}
		}
	}
	if managed == 0 && lim.Set() {
		var crs []model.Creative
		for _, c := range in.Creatives {
			if c.Media != "" {
				crs = append(crs, c)
			}
		}
		sort.SliceStable(crs, func(i, j int) bool { return crs[i].Lang == "ar" && crs[j].Lang != "ar" }) // Arabic first
		if len(crs) > 0 {
			budget := math.Floor(lim.DailyAED*0.8*100) / 100
			props = append(props,
				model.Proposal{ID: id(), Kind: model.KindCreateCampaign, Target: "tmp:c1", Params: map[string]any{"name": "Sales – Advantage+", "daily_budget": budget}, Confidence: 0.7,
					Why: "no campaign of the app's is running: one Advantage+ sales campaign (campaign budget, Advantage+ audience, automatic placements)"},
				model.Proposal{ID: id(), Kind: model.KindCreateAdSet, Target: "tmp:s1", Params: map[string]any{"name": "Prospecting", "campaign_id": "tmp:c1", "countries": toAny(lim.Countries)}, Confidence: 0.7,
					Why: "one prospecting ad set, so it can reach ~50 purchases a week"})
			maxAds := lim.MaxActiveAds
			if maxAds <= 0 {
				maxAds = 6
			}
			for i, c := range crs {
				if i >= 3 || i >= maxAds {
					break
				}
				props = append(props,
					model.Proposal{ID: id(), Kind: model.KindUploadCreative, Target: c.ID, Params: map[string]any{"creative_id": c.ID, "headline": c.Headline, "primary_text": c.PrimaryText}, Why: "upload " + c.Lang + " creative"},
					model.Proposal{ID: id(), Kind: model.KindCreateAd, Target: fmt.Sprintf("tmp:a%d", i+1), Params: map[string]any{"name": c.Lang + " " + c.Angle, "adset_id": "tmp:s1", "creative_id": c.ID, "headline": c.Headline, "primary_text": c.PrimaryText}, Why: "test " + c.Lang + " angle " + c.Angle})
			}
		}
	}
	plan := model.Plan{UpdatedAt: in.Now, Summary: fmt.Sprintf("Rules-only stand-in: %d proposals. The real planner (LANE BRAIN) replaces this.", len(props)),
		Steps: []model.PlanStep{{When: "now", Title: "Hold the plan", Detail: "pause losers, scale settled winners in ≤20% steps, launch one Advantage+ sales campaign if none"}}}
	var kinds []string
	for _, p := range props {
		kinds = append(kinds, p.Kind)
	}
	report := fmt.Sprintf("ملخص اليوم: %d اقتراحات (%s).\nToday: %d proposals (%s).", len(props), strings.Join(kinds, "، "), len(props), strings.Join(kinds, ", "))
	return model.CycleOutput{Plan: plan, Proposals: props, Report: report}, nil
}

func toAny(s []string) []any {
	out := make([]any, len(s))
	for i, x := range s {
		out[i] = x
	}
	return out
}

func (s *Stub) Research(ctx context.Context, in model.ResearchInput) (model.Research, error) {
	d := in.Now.Format("2006-01-02")
	return model.Research{UpdatedAt: in.Now,
		Trends:         []model.Note{{Date: d, Title: "Stand-in research", Body: "The real research (web search through Claude) comes with LANE BRAIN."}},
		Competitors:    []model.Note{},
		Angles:         []model.Note{{Date: d, Title: "Glass skin in UAE heat", Body: "Lightweight hydration + SPF for daily Gulf sun (placeholder angle)."}},
		ArabicInsights: []model.Note{{Date: d, Title: "Arabic first", Body: "Gulf-friendly MSA; avoid medical claims."}},
		Sources:        []string{}}, nil
}

func (s *Stub) Copy(ctx context.Context, in model.CopyInput) (model.CopyOutput, error) {
	var out model.CopyOutput
	for _, pid := range in.ProductIDs {
		title := pid
		link := ""
		for _, p := range in.Shop.Products {
			if p.ID == pid {
				title, link = p.Title, p.URL
			}
		}
		out.Variants = append(out.Variants,
			model.Creative{Lang: "ar", Headline: "بشرة مرطّبة كل يوم", PrimaryText: "روتين كوري خفيف يناسب حرّ الإمارات — " + title, CTA: "SHOP_NOW", Link: link, ProductIDs: []string{pid}, Angle: in.Angle, Source: "brain"},
			model.Creative{Lang: "en", Headline: "Hydrated skin, every day", PrimaryText: "A light K-beauty step made for UAE heat — " + title, CTA: "SHOP_NOW", Link: link, ProductIDs: []string{pid}, Angle: in.Angle, Source: "brain"})
	}
	return out, nil
}
