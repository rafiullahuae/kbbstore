package main

// starter.go — the first launch does not wait for Monday (the owner, 20 Nov 2026).
//
// The brain's weekly batch puts new ads up on its batch day only. An account the owner has just switched to Auto,
// with limits set and none of the app's campaigns running (and none spending in the last 7 days), launches a starter
// set in its very first cycle instead:
//
//   - 1 Advantage+ sales campaign (OUTCOME_SALES, campaign budget, Advantage+ audience + placements), Arabic first:
//     an Arabic ad set (women, the owner's countries) with up to 3 Arabic ads and an English ad set with 1, each ad a
//     different best-selling / trending in-stock product (the brain's trending list first)
//   - catalog retargeting when a catalog and a pixel are linked: an in-stock product set, a 30-day site-visitor
//     audience and an Advantage+ catalog campaign showing them what they looked at
//
// Budgets are 70% of the room under the daily limit (and under the monthly limit spread over the days left), so the
// winners can be scaled inside the limits afterwards; 70/30 sales / retargeting. Everything goes through the executor
// with every check (PAUSED creates, checked activation, idempotency keys). In Practice mode the same proposals are
// logged as "shadow" and kept in Approvals: exactly what it WOULD launch. After that, the weekly batch rule applies.

import (
	"context"
	"fmt"
	"math"
	"regexp"
	"sort"
	"strings"
	"time"

	"kaa/executor"
	"kaa/model"
)

type StarterState struct {
	State string        `json:"state"` // "" | practice | launched | failed | skipped
	At    time.Time     `json:"at"`
	Why   string        `json:"why,omitempty"`
	Items []PendingItem `json:"proposals,omitempty"`
}

const starterShare = 0.7 // of the room under the limits; the rest stays free for scaling winners

var pidSafe = regexp.MustCompile(`[^a-z0-9]+`)

// dropCreates: in a starter cycle the starter is the launch; the brain's own new objects wait for its batch day.
func dropCreates(ps []model.Proposal) []model.Proposal {
	var out []model.Proposal
	for _, p := range ps {
		if strings.HasPrefix(p.Kind, "create_") || p.Kind == model.KindUploadCreative {
			continue
		}
		out = append(out, p)
	}
	return out
}

// starterNeeded: whether this cycle launches the starter set, and why not when it does not.
func (ac *Acct) starterNeeded(in model.CycleInput, brainProps []model.Proposal, env executor.Env) (bool, string) {
	lim := env.Limits
	if lim.Autopilot != model.AutopilotAuto && lim.Autopilot != model.AutopilotShadow {
		return false, "autopilot off"
	}
	if !lim.Set() {
		return false, "no limits"
	}
	for _, p := range brainProps {
		if p.Kind == model.KindCreateCampaign {
			return false, "the planner launches a campaign itself"
		}
	}
	managed := map[string]bool{}
	for _, c := range in.Tree {
		if c.Managed {
			managed[c.ID] = true
			if c.Status == "ACTIVE" && (c.EffectiveStatus == "" || c.EffectiveStatus == "ACTIVE") {
				return false, "the app's campaigns are running"
			}
		}
	}
	weekAgo := env.Now.In(ac.loc()).AddDate(0, 0, -6).Format("2006-01-02")
	for _, r := range in.Metrics {
		if r.Level == "campaign" && managed[r.ObjectID] && r.Date >= weekAgo && r.SpendAED > 0 {
			return false, "the app's campaigns spent in the last 7 days"
		}
	}
	ac.mu.RLock()
	prev := ac.starter
	ac.mu.RUnlock()
	if prev.State == "launched" && env.Now.Sub(prev.At) < 7*24*time.Hour {
		return false, "a starter set was launched less than 7 days ago"
	}
	return true, ""
}

// starterProducts: in-stock products with a shop photo and page, trending first, then those with a ready design,
// then the ones on offer; at most n.
func starterProducts(shop model.ShopSnapshot, trending []string, lib []model.Creative, n int) []model.Product {
	rank := map[string]int{}
	for i, id := range trending {
		if _, ok := rank[id]; !ok {
			rank[id] = i
		}
	}
	designed := map[string]bool{}
	for _, c := range lib {
		if c.Media == "png" && !c.Used {
			for _, id := range c.ProductIDs {
				designed[id] = true
			}
		}
	}
	type cand struct {
		p     model.Product
		score float64
		i     int
	}
	var cs []cand
	for i, p := range shop.Products {
		if !p.InStock || !executor.ShopURL(p.URL) || !executor.ShopURL(p.Image) {
			continue
		}
		s := 0.0
		if r, ok := rank[p.ID]; ok {
			s += 1000 - float64(r)
		}
		if designed[p.ID] {
			s += 100
		}
		if p.SaleAED > 0 && p.SaleAED < p.PriceAED {
			s += 10
		}
		cs = append(cs, cand{p, s, i})
	}
	sort.SliceStable(cs, func(a, b int) bool {
		if cs[a].score != cs[b].score {
			return cs[a].score > cs[b].score
		}
		return cs[a].i < cs[b].i
	})
	var out []model.Product
	for _, c := range cs {
		if len(out) == n {
			break
		}
		out = append(out, c.p)
	}
	return out
}

// floor5: budgets in whole multiples of AED 5, so a later +20% step lands on exact fils.
func floor5(x float64) float64 { return math.Floor(x/5) * 5 }

// starterBudgets: (sales, retargeting) AED/day, or a reason there is no room.
func starterBudgets(env executor.Env, minDaily float64, retarget bool) (float64, float64, string) {
	lim := env.Limits
	committed := executor.CommittedDaily(env.Tree)
	room := lim.DailyAED - committed
	now := env.Now
	if env.Loc != nil {
		now = now.In(env.Loc)
	}
	if days := float64(executor.DaysLeftInMonth(now)); days > 0 {
		room = math.Min(room, (lim.MonthlyAED-env.MonthSpendAED)/days-committed)
	}
	if env.GlobalDailyAED > 0 {
		room = math.Min(room, env.GlobalDailyAED-env.OtherCommitted-committed)
	}
	total := room * starterShare
	floor := math.Max(minDaily, 10)
	rt := 0.0
	if retarget && total >= 2*floor+10 {
		rt = math.Max(floor5(total*0.3), floor5(floor+4))
	}
	sales := floor5(total - rt)
	if sales < floor {
		return 0, 0, fmt.Sprintf("no room for a starter campaign: AED %.2f/day is free under the limits (AED %.0f already committed)", math.Max(room, 0), committed)
	}
	return sales, rt, ""
}

func shortTitle(p model.Product) string {
	t := p.Title
	if i := strings.LastIndex(t, " - "); i > 20 { // "Brand - Name - 150ml" → "Brand - Name"
		t = t[:i]
	}
	if len([]rune(t)) > 60 {
		t = string([]rune(t)[:60])
	}
	return strings.TrimSpace(t)
}

// fallbackCopy: plain, policy-safe wording (no claims about skin) when Claude is not connected.
func fallbackCopy(p model.Product, lang string) (string, string) {
	brand := p.Brand
	if brand == "" {
		brand = "K-Beauty"
	}
	price := p.PriceAED
	if p.SaleAED > 0 && p.SaleAED < price {
		price = p.SaleAED
	}
	if lang == "ar" {
		return shortTitle(p), fmt.Sprintf("%s الأصلي من كوريا، متوفر الآن في K-Beauty Bliss بسعر %.0f درهم. توصيل داخل الإمارات.", brand, price)
	}
	return shortTitle(p), fmt.Sprintf("Authentic %s from Korea, in stock now at K-Beauty Bliss for AED %.0f. Delivery across the UAE.", brand, price)
}

// starterCreative: an unused designed creative of the product in that language, else a library entry made from the
// shop's own photo (link_data.picture) with the brain's copy (or the plain fallback).
func (ac *Acct) starterCreative(ctx context.Context, p model.Product, lang string, copyOf func(model.Product) map[string][2]string) (string, error) {
	for _, c := range ac.creatives.all() {
		if c.Media == "png" && !c.Used && c.Lang == lang && strings.HasPrefix(c.Link, "https://kbeautybliss.com/") {
			for _, id := range c.ProductIDs {
				if id == p.ID {
					return c.ID, nil
				}
			}
		}
	}
	id := "st-" + strings.Trim(pidSafe.ReplaceAllString(strings.ToLower(p.ID), "-"), "-") + "-" + lang
	if len(id) > 64 {
		id = id[:64]
	}
	for _, c := range ac.creatives.all() {
		if c.ID == id {
			return id, nil
		}
	}
	h, t := fallbackCopy(p, lang)
	if cp, ok := copyOf(p)[lang]; ok && cp[0] != "" && cp[1] != "" {
		h, t = cp[0], cp[1]
	}
	cr := model.Creative{ID: id, Lang: lang, Format: "1:1", Headline: h, PrimaryText: t, CTA: "SHOP_NOW", Link: p.URL, ProductIDs: []string{p.ID},
		Angle: "starter: best-seller / trending", Source: "brain", ImageURL: p.Image, Starter: true}
	if _, err := ac.creatives.Add(cr, ac.app.now()); err != nil {
		return "", err
	}
	return id, nil
}

// starterPlan: the starter proposals for this cycle, or none (with why, logged once per cycle).
func (ac *Acct) starterPlan(ctx context.Context, in model.CycleInput, brainProps []model.Proposal, env executor.Env) ([]model.Proposal, string) {
	ok, why := ac.starterNeeded(in, brainProps, env)
	if !ok {
		return nil, why
	}
	lim, links := env.Limits, env.Links
	free := lim.MaxActiveAds
	if free <= 0 {
		free = 6
	}
	free -= executor.ActiveAds(env.Tree)
	catalog := links.CatalogID != "" && links.PixelID != ""
	if catalog {
		free--
	}
	nCr := free
	if nCr > 4 {
		nCr = 4
	}
	if nCr < 1 {
		return nil, ac.starterSkip(fmt.Sprintf("no free ad slot under max_active_ads (%d)", lim.MaxActiveAds))
	}
	if links.PageID == "" {
		return nil, ac.starterSkip("no Facebook Page linked: an ad needs one")
	}
	if links.PixelID == "" {
		return nil, ac.starterSkip("no pixel linked: a sales campaign optimises for the shop's purchases")
	}
	sales, rt, why := starterBudgets(env, in.Account.MinDailyAED, catalog)
	if why != "" {
		return nil, ac.starterSkip(why)
	}
	nAR := nCr
	if nCr >= 2 {
		nAR = nCr - 1
	}
	nEN := nCr - nAR
	ac.app.mu.RLock()
	br, claudeOK := ac.app.brain, ac.app.conns.Claude.OK
	ac.app.mu.RUnlock()
	var trending []string
	if t, ok := br.(interface{ TrendingProductIDs() []string }); ok {
		trending = t.TrendingProductIDs()
	}
	prods := starterProducts(in.Shop, trending, in.Creatives, nAR)
	if len(prods) == 0 {
		return nil, ac.starterSkip("no in-stock product with a photo in the shop feed")
	}
	if len(prods) < nAR {
		nAR = len(prods)
	}
	cache := map[string]map[string][2]string{}
	copyOf := func(p model.Product) map[string][2]string { // the brain's Arabic + English copy, one call per product
		if m, ok := cache[p.ID]; ok {
			return m
		}
		m := map[string][2]string{}
		cache[p.ID] = m
		cw, ok := br.(model.Copywriter)
		if !ok || !claudeOK {
			return m
		}
		cctx, cancel := context.WithTimeout(ctx, 3*time.Minute)
		defer cancel()
		out, err := cw.Copy(cctx, model.CopyInput{ProductIDs: []string{p.ID}, Langs: []string{"ar", "en"}, Angle: "best-seller, authentic Korean skincare", Shop: in.Shop, Research: in.Research})
		if err != nil {
			ac.st.Append("starter", "starter copy from Claude failed, plain wording used: "+err.Error(), nil)
			return m
		}
		for _, v := range out.Variants {
			if _, have := m[v.Lang]; !have && v.Headline != "" && v.PrimaryText != "" {
				m[v.Lang] = [2]string{v.Headline, v.PrimaryText}
			}
		}
		return m
	}
	day := env.Now.In(ac.loc()).Format("20060102")
	tag := "st" + day[4:]
	id := func(s string) string { return "starter-" + day + "-" + s }
	countries := make([]any, 0, len(lim.Countries))
	for _, c := range lim.Countries {
		countries = append(countries, c)
	}
	women := func(name string) map[string]any {
		return map[string]any{"name": name, "campaign_id": "tmp:" + tag + "-sales", "countries": countries, "destination": "website",
			"advantage_audience": 1.0, "genders": []any{2.0}, "age_range": []any{18.0, 45.0}}
	}
	ps := []model.Proposal{{ID: id("sales"), Kind: model.KindCreateCampaign, Target: "tmp:" + tag + "-sales",
		Params: map[string]any{"name": "Starter · Advantage+ sales", "daily_budget": sales, "type": "sales"},
		Why:    fmt.Sprintf("First launch: nothing of the app's is running, so it starts now instead of waiting for the weekly batch day (AED %.0f/day, %.0f%% of the room under your limits).", sales, starterShare*100), Confidence: 0.7,
		Evidence: []string{fmt.Sprintf("daily limit AED %.0f, committed AED %.0f", lim.DailyAED, executor.CommittedDaily(env.Tree))}}}
	ps = append(ps, model.Proposal{ID: id("adset-ar"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-ar", Params: women("Starter AR · women " + strings.Join(lim.Countries, "+")),
		Why: "Arabic first: most of the shop's customers are Arab women in the GCC.", Confidence: 0.7})
	addAd := func(n int, lang, set string, p model.Product) error {
		crID, err := ac.starterCreative(ctx, p, lang, copyOf)
		if err != nil {
			return err
		}
		cr, _, _ := ac.creatives.Get(crID)
		ps = append(ps, model.Proposal{ID: id(fmt.Sprintf("cr-%d", n)), Kind: model.KindUploadCreative, Target: crID,
			Params: map[string]any{"creative_id": crID, "format": "image", "headline": cr.Headline, "primary_text": cr.PrimaryText},
			Why:    fmt.Sprintf("%s ad for %s (in stock%s).", strings.ToUpper(lang), shortTitle(p), map[bool]string{true: ", trending", false: ""}[contains(trending, p.ID)]), Confidence: 0.65},
			model.Proposal{ID: id(fmt.Sprintf("ad-%d", n)), Kind: model.KindCreateAd, Target: fmt.Sprintf("tmp:%s-ad%d", tag, n),
				Params: map[string]any{"name": fmt.Sprintf("Starter %s · %s", strings.ToUpper(lang), shortTitle(p)), "adset_id": set, "creative_id": crID},
				Why:    "Starter ad, switched on after its campaign and ad set (each a checked step).", Confidence: 0.65})
		return nil
	}
	n := 0
	for _, p := range prods[:nAR] {
		n++
		if err := addAd(n, "ar", "tmp:"+tag+"-ar", p); err != nil {
			return nil, ac.starterSkip("starter creative: " + err.Error())
		}
	}
	if nEN > 0 {
		en := women("Starter EN · " + strings.Join(lim.Countries, "+"))
		delete(en, "genders")
		delete(en, "age_range")
		ps = append(ps, model.Proposal{ID: id("adset-en"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-en", Params: en,
			Why: "One English test for the expat and bilingual shoppers.", Confidence: 0.6})
		n++
		if err := addAd(n, "en", "tmp:"+tag+"-en", prods[0]); err != nil {
			return nil, ac.starterSkip("starter creative: " + err.Error())
		}
	}
	if catalog && rt > 0 {
		h, t := "{{product.name}}", "منتجات كورية أصلية رأيتِها على K-Beauty Bliss — ما زالت متوفرة. توصيل داخل الإمارات."
		ps = append(ps,
			model.Proposal{ID: id("productset"), Kind: model.KindCreateProductSet, Target: "tmp:" + tag + "-ps",
				Params: map[string]any{"name": "Starter · in stock", "filter": `{"availability":{"eq":"in stock"}}`}, Why: "Catalog retargeting shows only what is in stock.", Confidence: 0.7},
			model.Proposal{ID: id("visitors"), Kind: model.KindCreateAudience, Target: "tmp:" + tag + "-visitors",
				Params: map[string]any{"name": "Starter · site visitors 30d", "source": "website", "event": "ViewContent", "retention_days": 30.0}, Why: "People who looked at a product in the last 30 days.", Confidence: 0.7},
			model.Proposal{ID: id("catalog"), Kind: model.KindCreateCampaign, Target: "tmp:" + tag + "-cat",
				Params: map[string]any{"name": "Starter · catalog retargeting", "daily_budget": rt, "type": "catalog"}, Why: fmt.Sprintf("Catalog linked: retarget site visitors with the products they viewed (AED %.0f/day).", rt), Confidence: 0.7},
			model.Proposal{ID: id("adset-cat"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-catset",
				Params: map[string]any{"name": "Starter · viewed, not bought", "campaign_id": "tmp:" + tag + "-cat", "countries": countries, "product_set_id": "tmp:" + tag + "-ps",
					"custom_audiences": []any{"tmp:" + tag + "-visitors"}, "advantage_audience": 0.0}, Why: "Only the site's recent visitors.", Confidence: 0.7},
			model.Proposal{ID: id("cr-cat"), Kind: model.KindUploadCreative, Target: "tmp:" + tag + "-catcr",
				Params: map[string]any{"format": "catalog", "product_set_id": "tmp:" + tag + "-ps", "template": map[string]any{"headline": h, "primary_text": t, "link": "https://kbeautybliss.com/"}, "primary_text": t},
				Why:    "Advantage+ catalog ad: Meta picks the product each visitor viewed.", Confidence: 0.7},
			model.Proposal{ID: id("ad-cat"), Kind: model.KindCreateAd, Target: "tmp:" + tag + "-catad",
				Params: map[string]any{"name": "Starter AR · catalog", "adset_id": "tmp:" + tag + "-catset", "creative_id": "tmp:" + tag + "-catcr"}, Why: "The retargeting ad.", Confidence: 0.7})
	}
	return ps, fmt.Sprintf("starter set: %d Arabic + %d English ads, AED %.0f/day sales%s", nAR, nEN, sales, map[bool]string{true: fmt.Sprintf(" + AED %.0f/day catalog retargeting", rt), false: ""}[catalog && rt > 0])
}

func contains(xs []string, s string) bool {
	for _, x := range xs {
		if x == s {
			return true
		}
	}
	return false
}

// starterSkip: an account that should have started but cannot says why (on the dashboard, once a day).
func (ac *Acct) starterSkip(why string) string {
	ac.mu.Lock()
	ac.starter = StarterState{State: "skipped", At: ac.app.now().UTC(), Why: why}
	s := ac.starter
	ac.stateJSON = nil
	ac.mu.Unlock()
	_ = ac.st.Save("starter", s)
	ac.alertOnce("The first launch is waiting: " + why)
	return why
}

// noteStarter records what became of the starter proposals of this cycle.
func (ac *Acct) noteStarter(props []model.Proposal, results []executor.Result, mode, why string) {
	byID := map[string]executor.Result{}
	for _, r := range results {
		byID[r.ProposalID] = r
	}
	s := StarterState{At: ac.app.now().UTC(), Why: why, State: "practice"}
	launched, some := false, false
	for _, p := range props {
		if !strings.HasPrefix(p.ID, "starter-") {
			continue
		}
		some = true
		it := PendingItem{Proposal: p, Status: "refused"}
		if r, ok := byID[p.ID]; ok {
			r := r
			it.Status, it.Result = r.Status, &r
		}
		if p.Kind == model.KindCreateCampaign && (it.Status == "done" || it.Status == "duplicate") {
			launched = true
		}
		s.Items = append(s.Items, it)
	}
	if !some {
		return
	}
	if mode == model.AutopilotAuto {
		s.State = map[bool]string{true: "launched", false: "failed"}[launched]
	}
	ac.mu.Lock()
	ac.starter, ac.stateJSON = s, nil
	ac.mu.Unlock()
	_ = ac.st.Save("starter", s)
	ac.st.Append("starter", fmt.Sprintf("starter (%s): %s", s.State, why), s)
}
