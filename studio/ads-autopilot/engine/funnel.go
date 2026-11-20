package main

// funnel.go — the professional funnel per ad account, as the owner sees it on Campaigns and Audiences (GET
// /api/funnel): each piece (prospecting, existing customers, catalog retargeting, lookalikes) with its status, the
// Meta objects that make it, every setting and WHY it was chosen, and the custom audiences with Meta's sizes.
// The pieces that are still missing become jobs on the Plan screen (jobs.go). Chat ads are not part of it (the
// owner, 20 Nov 2026: "we don't offer this things").

import (
	"fmt"
	"net/http"
	"strings"

	"kaa/model"
)

type FunnelObj struct {
	ID       string  `json:"id"`
	Name     string  `json:"name"`
	Level    string  `json:"level"`
	Status   string  `json:"status"`
	Budget   float64 `json:"daily_budget,omitempty"`
	Learning string  `json:"learning_stage,omitempty"`
}

type Setting struct {
	Key   string `json:"key"`
	Value string `json:"value"`
	Why   string `json:"why"`
	Meta  string `json:"meta,omitempty"` // the Meta field it is
}

type FunnelPiece struct {
	Key      string      `json:"key"` // prospecting | existing | retargeting | lookalike
	Name     string      `json:"name"`
	NameAR   string      `json:"name_ar"`
	Purpose  string      `json:"purpose"`
	Status   string      `json:"status"` // live | paused | missing | waiting | off
	Why      string      `json:"why"`
	Objects  []FunnelObj `json:"objects"`
	Settings []Setting   `json:"settings"`
	Suggest  []string    `json:"suggestions,omitempty"`
}

type AudienceView struct {
	model.Audience
	Purpose string   `json:"purpose"`
	UsedBy  []string `json:"used_by"`
	Ready   bool     `json:"ready"`
}

// funnelObjects: the app's own campaigns and ad sets, by role (from their KAA: names).
type funnelObjs struct {
	prosp, catalog  *model.Campaign
	newSets         []model.AdSet
	existing        *model.AdSet
	lookalike       *model.AdSet
	catSets         []model.AdSet
	buyers, past    *model.Audience
	lals            []model.Audience
	pTotal, pActive int
}

func findFunnel(tree []model.Campaign, aus []model.Audience) funnelObjs {
	var f funnelObjs
	for i := range tree {
		c := &tree[i]
		if !c.Managed || c.Status == "ARCHIVED" || c.Status == "DELETED" {
			continue
		}
		switch {
		case c.Objective == "PRODUCT_CATALOG_SALES" && f.catalog == nil:
			f.catalog = c
			f.catSets = c.AdSets
		case c.Objective == "OUTCOME_SALES" && f.prosp == nil && !strings.Contains(c.Name, "Click-to"):
			f.prosp = c
			for j := range c.AdSets {
				s := c.AdSets[j]
				switch {
				case strings.Contains(s.Name, "existing customers"):
					f.existing = &c.AdSets[j]
				case strings.Contains(s.Name, "lookalike"):
					f.lookalike = &c.AdSets[j]
				default:
					f.newSets = append(f.newSets, s)
				}
			}
		}
	}
	for i := range aus {
		a := &aus[i]
		switch {
		case !strings.HasPrefix(a.Name, "KAA:"):
		case a.Subtype == "LOOKALIKE":
			f.lals = append(f.lals, *a)
		case strings.Contains(a.Name, "Past buyers"):
			f.past = a
		case strings.Contains(a.Name, "Buyers"):
			f.buyers = a
		}
	}
	return f
}

func objOf(c *model.Campaign) FunnelObj {
	return FunnelObj{ID: c.ID, Name: c.Name, Level: "campaign", Status: firstNonEmpty(c.EffectiveStatus, c.Status), Budget: c.DailyBudgetAED}
}
func setObj(s model.AdSet) FunnelObj {
	return FunnelObj{ID: s.ID, Name: s.Name, Level: "adset", Status: firstNonEmpty(s.EffectiveStatus, s.Status), Budget: s.DailyBudgetAED, Learning: s.LearningStage}
}

func firstNonEmpty(xs ...string) string {
	for _, x := range xs {
		if x != "" {
			return x
		}
	}
	return ""
}

func liveOr(c *model.Campaign) string {
	if c == nil {
		return "missing"
	}
	if c.Status == "ACTIVE" {
		return "live"
	}
	return "paused"
}

// purchases7: settled-ish purchases of an object in the last 7 days (for the cost-cap suggestion).
func purchases7(rows []model.MetricRow, id, from string) (float64, float64, float64) {
	var p, s, imp, reach float64
	for _, r := range rows {
		if r.ObjectID == id && r.Date >= from {
			p += r.Purchases
			s += r.SpendAED
			imp += float64(r.Impressions)
			reach += float64(r.Reach)
		}
	}
	f := 0.0
	if reach > 0 {
		f = imp / reach
	}
	return p, s, f
}

// Funnel: the whole picture for the screens.
func (ac *Acct) Funnel(in model.CycleInput, pb model.Playbook) map[string]any {
	f := findFunnel(in.Tree, in.Audiences)
	l := in.Links
	k := pb.Knobs
	sp := pb.SplitFor(in.Limits.DailyAED)
	tier := pb.Tier(in.Limits.DailyAED)
	from := ac.app.now().In(ac.loc()).AddDate(0, 0, -6).Format("2006-01-02")
	attribution := Setting{"Attribution", "7-day click, 1-day view", "Meta's standard sales window; decisions use settled days only.", "attribution_spec"}
	common := []Setting{
		{"Optimisation", "Purchase (website pixel)", "The goal is sales, so Meta optimises for the shop's purchase event.", "optimization_goal=OFFSITE_CONVERSIONS, promoted_object.custom_event_type=PURCHASE"},
		attribution,
		{"Placements", "Advantage+ placements (all)", "The cheapest inventory wins; Reels and Stories get 9:16 creatives.", "no placement targeting"},
		{"Schedule", "Always on, daily budget", "Meta documents dayparting only with lifetime budgets; daily budgets keep spend smooth.", "daily_budget"},
	}
	countries := strings.Join(anyToStrings(funnelCountries(pb, in.Limits.Countries)), ", ")
	gender := map[string]string{"women": "women (suggestion)", "all": "everyone"}[pb.Gender]
	tCPA := ac.targetCPA(in)
	var pieces []FunnelPiece

	// prospecting
	pp := FunnelPiece{Key: "prospecting", Name: "Prospecting · Advantage+ sales", NameAR: "جذب عملاء جدد · مبيعات Advantage+", Status: liveOr(f.prosp),
		Purpose: "Finds new customers with the ranked, fresh-first products.", Objects: []FunnelObj{}}
	nSets := pb.AdSetsFor(in.Limits.DailyAED*sp.Prospecting/100, tCPA)
	pp.Settings = append([]Setting{
		{"Objective", "Sales (Advantage+ sales campaign)", "Meta's recommended structure for shops: campaign budget + Advantage+ audience + placements.", "objective=OUTCOME_SALES"},
		{"Bid strategy", "Lowest cost (no cap)", fmt.Sprintf("A cap on thin data starves delivery; a cost cap is suggested only from %d purchases a week.", k.CostCapWeek()), "bid_strategy=LOWEST_COST_WITHOUT_CAP"},
		{"Budget", fmt.Sprintf("Campaign budget, %.0f%% of the daily limit (%s tier)", sp.Prospecting+sp.Existing, tier), "One budget Meta moves to the best ad set every hour.", "campaign daily_budget"},
		{"Ad sets", fmt.Sprintf("%d (budget ÷ (target CPA AED %.0f × %d ÷ 7))", nSets, tCPA, k.ConvWeek()), "Each ad set needs about 50 purchases a week to leave the learning phase (Meta); fewer, fuller ad sets learn.", ""},
		{"Audience", fmt.Sprintf("Advantage+ audience in %s; age %d–%d and %s as suggestions", countries, pb.AgeMin, pb.AgeMax, gender), "Meta keeps countries, minimum age, language and exclusions as hard limits and expands the rest — broad beats interests.", "targeting_automation.advantage_audience=1, age_range, genders"},
		{"Excluded", map[bool]string{true: fmt.Sprintf("people who bought in the last %d days", pb.ExcludeBuyersDays), false: "nobody (your playbook)"}[pb.ExcludeBuyersDays > 0], "Prospecting money goes to new customers; exclusions are never expanded by Advantage+ audience.", "excluded_custom_audiences"},
		{"Languages", strings.ToUpper(strings.Join(pb.Languages, " + ")) + " ads, no language targeting", "Language targeting is a hard limit in Advantage+ audience; the ad's language does the work.", ""},
	}, common...)
	if f.prosp != nil {
		pp.Objects = append(pp.Objects, objOf(f.prosp))
		for _, s := range f.newSets {
			pp.Objects = append(pp.Objects, setObj(s))
			if p, _, _ := purchases7(in.Metrics, s.ID, from); p >= float64(k.CostCapWeek()) {
				pp.Suggest = append(pp.Suggest, fmt.Sprintf("%s had %.0f purchases in 7 days: a cost cap near AED %.0f could now hold the CPA (not applied without you).", s.Name, p, tCPA))
			}
			if s.LearningStage == "FAIL" {
				pp.Suggest = append(pp.Suggest, s.Name+" is learning-limited: consolidating ad sets or raising the budget would feed it.")
			}
		}
		pp.Why = fmt.Sprintf("Built by the app (%s).", f.prosp.Name)
	} else {
		pp.Why = "Not built yet: the first launch builds it (Plan → First launch)."
	}
	pieces = append(pieces, pp)

	// existing customers
	ep := FunnelPiece{Key: "existing", Name: "Existing customers (capped)", NameAR: "العملاء الحاليون (بسقف)", Purpose: "Refill and next-step products for people who bought 30–180 days ago.", Objects: []FunnelObj{}}
	ep.Settings = []Setting{
		{"Where", "An ad set inside the prospecting campaign", "Meta's replacement for the retired existing-customer budget %: one ad set includes them, the other excludes them.", "custom_audiences + targeting_relaxation_types.custom_audience=0"},
		{"Budget", fmt.Sprintf("Capped at %.0f%% of the daily limit", sp.Existing), "Keeps returning buyers from eating the budget meant for new ones.", "daily_spend_cap"},
		attribution,
	}
	switch {
	case f.existing != nil:
		ep.Status, ep.Why = map[bool]string{true: "live", false: "paused"}[f.existing.Status == "ACTIVE"], "Built by the app."
		ep.Objects = append(ep.Objects, setObj(*f.existing))
	case tier == "small" || sp.Existing <= 0:
		ep.Status, ep.Why = "off", fmt.Sprintf("Small-budget tier (under AED %.0f/day): existing customers are handled by the exclusion only (agency rule: every dirham finds new buyers first).", k.SmallBudget())
	default:
		ep.Status, ep.Why = "missing", "Growth tier: the Plan screen has a job to add it."
	}
	pieces = append(pieces, ep)

	// retargeting
	rp := FunnelPiece{Key: "retargeting", Name: "Retargeting · Advantage+ catalog", NameAR: "إعادة الاستهداف · كتالوج Advantage+", Status: liveOr(f.catalog),
		Purpose: "Shows each visitor the exact product they viewed or carted (14 days) and did not buy.", Objects: []FunnelObj{}}
	rp.Settings = []Setting{
		{"Objective", "Catalog sales", "Advantage+ catalog ads pick the product per person from the catalog.", "objective=PRODUCT_CATALOG_SALES, promoted_object.product_catalog_id"},
		{"Products", "In stock and allowed by your playbook", "Never shows something sold out, avoided or under your stock / margin floor.", "product_sets filter {availability, retailer_id is_any}"},
		{"Audience", "Viewed or added to cart in 14 days, not bought in 14 days", "The highest-intent people, with their own product.", "targeting.product_audience_specs"},
		{"Budget", fmt.Sprintf("%.0f%% of the daily limit; scaled only while 7-day frequency < %.1f", sp.Retargeting, k.RetargetFreq()), "A warm audience is small; above that frequency more money buys the same people again.", "campaign daily_budget"},
		{"Bid strategy", "Lowest cost (no cap)", "As prospecting.", "bid_strategy=LOWEST_COST_WITHOUT_CAP"},
		attribution,
	}
	switch {
	case f.catalog != nil:
		rp.Objects = append(rp.Objects, objOf(f.catalog))
		for _, s := range f.catSets {
			rp.Objects = append(rp.Objects, setObj(s))
		}
		if _, _, fr := purchases7(in.Metrics, f.catalog.ID, from); fr >= k.RetargetFreq() {
			rp.Suggest = append(rp.Suggest, fmt.Sprintf("7-day frequency %.1f: budget held (no scaling above %.1f).", fr, k.RetargetFreq()))
		}
		rp.Why = "Built by the app."
	case l.CatalogID == "" || l.PixelID == "":
		rp.Status, rp.Why = "waiting", "Link the product catalog and the pixel (Settings → Accounts) to switch it on."
	default:
		rp.Why = "Missing: the Plan screen has a job to build it."
	}
	pieces = append(pieces, rp)

	// lookalikes
	lp := FunnelPiece{Key: "lookalike", Name: "Lookalikes of buyers (1% and 3%)", NameAR: "جمهور مشابه للمشترين (١٪ و٣٪)", Purpose: "Suggestions inside Advantage+ audience: people like the shop's buyers.", Objects: []FunnelObj{}}
	lp.Settings = []Setting{
		{"Seed", "Buyers audience (pixel purchases)", "Meta needs at least 100 people in the source audience.", "subtype=LOOKALIKE, origin_audience_id"},
		{"Size", "1% and 3%", "1% is closest to the buyers; 3% gives Meta room at higher budgets.", "lookalike_spec.ratio=0.01 / 0.03"},
		{"Use", "As suggestions in a new prospecting ad set (Advantage+ audience on)", "The running ad sets are not edited: an edit restarts learning.", "custom_audiences with advantage_audience=1"},
	}
	switch {
	case f.lookalike != nil:
		lp.Status, lp.Why = map[bool]string{true: "live", false: "paused"}[f.lookalike.Status == "ACTIVE"], "Built by the app."
		lp.Objects = append(lp.Objects, setObj(*f.lookalike))
	case len(f.lals) > 0:
		lp.Status, lp.Why = "missing", "The lookalikes exist; the job adds the ad set that uses them."
	case f.buyers == nil:
		lp.Status, lp.Why = "waiting", "Needs the buyers audience (made by the first launch)."
	case f.buyers.Lower < 100:
		lp.Status, lp.Why = "waiting", fmt.Sprintf("Needs 100+ buyers in the audience (Meta's minimum); about %d now.", f.buyers.Lower)
	default:
		lp.Status, lp.Why = "missing", fmt.Sprintf("%d+ buyers: the Plan screen has a job to build it.", f.buyers.Lower)
	}
	pieces = append(pieces, lp)

	// audiences with purpose and use
	used := map[string][]string{}
	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			for _, a := range in.Audiences {
				if strings.Contains(s.Name, "new customers") && strings.Contains(a.Name, "Buyers") && !strings.Contains(a.Name, "Past") {
					used[a.ID] = append(used[a.ID], s.Name+" (excluded)")
				}
				if strings.Contains(s.Name, "existing customers") && strings.Contains(a.Name, "Past buyers") {
					used[a.ID] = append(used[a.ID], s.Name)
				}
				if strings.Contains(s.Name, "lookalike") && a.Subtype == "LOOKALIKE" {
					used[a.ID] = append(used[a.ID], s.Name+" (suggestion)")
				}
			}
		}
	}
	aus := []AudienceView{}
	for _, a := range in.Audiences {
		v := AudienceView{Audience: a, UsedBy: used[a.ID], Ready: a.DeliveryCode == 200 || a.DeliveryCode == 0}
		if v.UsedBy == nil {
			v.UsedBy = []string{}
		}
		switch {
		case !strings.HasPrefix(a.Name, "KAA:"):
			v.Purpose = "Yours (made outside the app; never changed)"
		case a.Subtype == "LOOKALIKE":
			v.Purpose = "Lookalike of buyers: a suggestion for new prospecting ad sets"
		case strings.Contains(a.Name, "Past buyers"):
			v.Purpose = "Existing customers 30–180 days: the capped ad set"
		case strings.Contains(a.Name, "Buyers"):
			v.Purpose = "Recent buyers: excluded from prospecting; seeds the lookalikes"
		default:
			v.Purpose = "Website audience"
		}
		aus = append(aus, v)
	}
	return map[string]any{"account": ac.id, "tier": tier, "small_budget_aed": k.SmallBudget(), "split": sp, "target_cpa": tCPA, "pieces": pieces, "audiences": aus,
		"chat_ads": "not offered (your decision)"}
}

func (s *server) funnelAPI(w http.ResponseWriter, r *http.Request, ac *Acct) {
	if r.Method != http.MethodGet {
		jsonErr(w, 405, "GET")
		return
	}
	in := ac.input()
	writeJSON(w, ac.Funnel(in, *in.Playbook))
}

// input: the account's cycle input as the screens need it (filtered shop, playbook, audiences), no Meta call.
func (ac *Acct) input() model.CycleInput {
	a := ac.app
	a.mu.RLock()
	shopSnap, research := a.shopSnap, a.research
	a.mu.RUnlock()
	ac.mu.RLock()
	in := model.CycleInput{Now: a.now(), Limits: ac.settings.Limits, Account: ac.accountRef(), Tree: cloneTree(ac.tree), Research: research, Links: ac.settings.Links,
		Audiences: append([]model.Audience(nil), ac.audiences...)}
	ac.mu.RUnlock()
	fshop, pb, _ := ac.filteredShop(shopSnap)
	in.Shop, in.Playbook = fshop, &pb
	in.Metrics, in.Creatives = ac.metricRows(), ac.creatives.all()
	return in
}
