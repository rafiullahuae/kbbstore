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
	"encoding/json"
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
// benefitOf: a policy-safe benefit line from the product's own category / title words (no claims beyond them).
func benefitOf(p model.Product, lang string) string {
	t := strings.ToLower(p.Title + " " + p.Category)
	type b struct{ key, en, ar string }
	for _, x := range []b{
		{"sun", "Lightweight daily sun care with a comfortable, non-sticky finish", "حماية يومية خفيفة من الشمس بلمسة مريحة غير لزجة"},
		{"cleanser", "A gentle cleanse that leaves skin feeling fresh, not tight", "تنظيف لطيف يترك البشرة منتعشة دون شدّ"},
		{"toner", "A calming toner step for a fresh, balanced feel", "خطوة تونر مهدئة لإحساس منتعش ومتوازن"},
		{"serum", "A lightweight serum step for a dewy, glowing look", "سيروم خفيف لإطلالة ندية ومشرقة"},
		{"essence", "A lightweight essence step for a dewy, glowing look", "إسنس خفيف لإطلالة ندية ومشرقة"},
		{"cream", "Rich, comforting hydration that helps skin feel soft", "ترطيب غني ومريح يساعد البشرة على النعومة"},
		{"mask", "A soothing mask moment in your routine", "لحظة ماسك مهدئة في روتينك"},
		{"lip", "Soft, hydrated-looking lips", "شفاه تبدو ناعمة ومرطبة"},
		{"set", "A complete Korean routine in one set", "روتين كوري متكامل في مجموعة واحدة"},
	} {
		if strings.Contains(t, x.key) {
			if lang == "ar" {
				return x.ar
			}
			return x.en
		}
	}
	if lang == "ar" {
		return "عناية كورية لطيفة تعتمد على المكونات"
	}
	return "Gentle, ingredient-first Korean skincare"
}

// fallbackCopy: benefit-first template wording (benefit -> Korean angle -> reason to buy -> CTA) when Claude is not
// connected or creation waits for the owner: the product's benefit from its own category, the price, and only the
// offers the Playbook lists (free gift, the coupon code). No claims about skin.
func fallbackCopy(p model.Product, lang string, pb model.Playbook) (string, string) {
	brand := p.Brand
	if brand == "" {
		brand = "K-Beauty"
	}
	price := p.PriceAED
	if p.SaleAED > 0 && p.SaleAED < price {
		price = p.SaleAED
	}
	gift, code := pb.OffersAllowed && pb.FreeGift, ""
	if pb.OffersAllowed {
		code = pb.CouponCode
	}
	if lang == "ar" {
		t := fmt.Sprintf("%s. %s الأصلي من كوريا، بسعر %.0f درهم مع توصيل داخل الإمارات.", benefitOf(p, "ar"), brand, price)
		if gift {
			t += " هدية مجانية مع كل طلب."
		}
		if code != "" {
			t += " استخدمي الكود " + code + "."
		}
		return shortTitle(p), t + " تسوّقي الآن."
	}
	t := fmt.Sprintf("%s. Authentic %s from Korea, AED %.0f, delivered across the UAE.", benefitOf(p, "en"), brand, price)
	if gift {
		t += " Free gift with every order."
	}
	if code != "" {
		t += " Use code " + code + " at checkout."
	}
	return shortTitle(p), t + " Shop now."
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
	h, t := fallbackCopy(p, lang, ac.playbook())
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

// funnelCountries: the playbook's countries, but only those inside the owner's hard limits (the executor refuses
// any other); the limits' list when they do not overlap.
func funnelCountries(pb model.Playbook, lim []string) []any {
	allowed := map[string]bool{}
	for _, c := range lim {
		allowed[strings.ToUpper(c)] = true
	}
	var out []any
	for _, c := range pb.CountriesOr(lim) {
		if allowed[strings.ToUpper(c)] {
			out = append(out, strings.ToUpper(c))
		}
	}
	if len(out) == 0 {
		for _, c := range lim {
			out = append(out, c)
		}
	}
	return out
}

// targetCPA: the owner's target CPA, else the brain's (computed from real shop orders and cost prices), else 60.
func (ac *Acct) targetCPA(in model.CycleInput) float64 {
	if in.Limits.TargetCPAAED > 0 {
		return in.Limits.TargetCPAAED
	}
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	if t, ok := br.(interface {
		TargetCPAWith(model.ShopSnapshot, model.EconInputs) float64
	}); ok && in.Playbook != nil {
		if v := t.TargetCPAWith(in.Shop, in.Playbook.Econ); v > 0 {
			return v
		}
	}
	return 60
}

// ownerCopyBrief: the owner's playbook instructions, verbatim, on every copy request the engine makes.
func ownerCopyBrief(pb model.Playbook, extra string) string {
	var parts []string
	if strings.TrimSpace(pb.Instructions) != "" {
		parts = append(parts, "OWNER'S INSTRUCTIONS (always obey):\n"+pb.Instructions)
	}
	if true {
		if of := pb.OfferFacts(); len(of) > 0 {
			parts = append(parts, "OFFERS YOU MAY USE in the reason-to-buy step (exactly these, nothing else): "+strings.Join(of, "; ")+". Never a percentage or an amount off.")
		} else {
			parts = append(parts, "No offer wording at all (the owner switched offers off). Never a percentage or an amount off.")
		}
	}
	if strings.TrimSpace(extra) != "" {
		parts = append(parts, extra)
	}
	return strings.Join(parts, "\n\n")
}

// FunnelBudgets: the first build's daily budgets (AED) by the agency playbook's tier: prospecting campaign budget
// (incl. the existing-customer cap inside it), the existing-customer ad-set cap, catalog retargeting.
type FunnelBudgets struct {
	Tier                                  string
	Prospecting, ExistingCap, Retargeting float64
	Total                                 float64
}

func funnelBudgets(total float64, pb model.Playbook, dailyLimit, minDaily float64, catalog bool) FunnelBudgets {
	sp := pb.SplitFor(dailyLimit)
	fb := FunnelBudgets{Tier: pb.Tier(dailyLimit), Total: total}
	floor := math.Max(minDaily, 10)
	rt := 0.0
	if catalog && sp.Retargeting > 0 && total >= 2*floor+10 {
		rt = math.Max(floor5(total*sp.Retargeting/100), floor5(floor+4))
	}
	fb.Retargeting = rt
	fb.Prospecting = floor5(total - rt)
	if fb.Tier == "growth" && sp.Existing > 0 {
		fb.ExistingCap = math.Max(5, floor5(total*sp.Existing/100))
		if fb.ExistingCap >= fb.Prospecting {
			fb.ExistingCap = 0
		}
	}
	return fb
}

// starterPlan: the first build of the professional funnel (the owner, 20 Nov 2026: "proper things"), by the agency
// low-budget playbook (brain/agency.go) and the owner's Playbook:
//
//	Prospecting · Advantage+ sales (OUTCOME_SALES, campaign budget, lowest cost, Advantage+ audience + placements,
//	  7d-click/1d-view): new-customer ad set(s) excluding recent buyers — ONE ad set at small budgets (budget ÷
//	  (target CPA × 50/7)), AR + EN only when the budget feeds both; at the growth tier an existing-customer ad set
//	  with a daily spend cap inside the same campaign (Meta's replacement for existing_customer_budget_percentage)
//	Retargeting · Advantage+ catalog (PRODUCT_CATALOG_SALES): in-stock, playbook-allowed product set; people who
//	  viewed or carted in 14 days and did not buy (product_audience_specs)
//
// Every product is the ranked, playbook-filtered list (pins first, fresh-first). Everything goes through the
// executor with every check; in Practice it is logged as what it WOULD launch.
func (ac *Acct) starterPlan(ctx context.Context, in model.CycleInput, brainProps []model.Proposal, env executor.Env) ([]model.Proposal, string) {
	ok, why := ac.starterNeeded(in, brainProps, env)
	if !ok {
		return nil, why
	}
	if j := ac.jobByID(jobFirstLaunch); j != nil && j.Status == "skipped" {
		return nil, "you skipped the first launch on the Plan screen"
	}
	pb := model.DefaultPlaybook().Normalize()
	if in.Playbook != nil {
		pb = *in.Playbook
	}
	edit := ac.jobByID(jobFirstLaunch)
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
	if nCr > 5 { // five product slots (the owner, 21 Nov), never past max_active_ads
		nCr = 5
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
	total := sales + rt
	if edit != nil && edit.Edited && edit.BudgetAED > 0 && edit.BudgetAED < total { // the owner lowered it on the Plan screen
		total = edit.BudgetAED
	}
	fb := funnelBudgets(total, pb, lim.DailyAED, in.Account.MinDailyAED, catalog)
	if fb.Prospecting < math.Max(in.Account.MinDailyAED, 10) {
		return nil, ac.starterSkip(fmt.Sprintf("no room for a starter campaign: AED %.0f/day after your edit", total))
	}
	if fb.ExistingCap > 0 { // the existing-customer ad needs a slot of its own under max_active_ads
		if nCr > 1 {
			nCr--
		} else {
			fb.ExistingCap = 0
		}
	}
	langOn := map[string]bool{}
	for _, l := range pb.Languages {
		langOn[l] = true
	}
	nAR, nEN := nCr, 0
	if langOn["en"] && nCr >= 2 {
		nAR, nEN = nCr-1, 1
	}
	if !langOn["ar"] {
		nAR, nEN = 0, nCr
	}
	ac.app.mu.RLock()
	br, claudeOK := ac.app.brain, ac.app.conns.Claude.OK
	ac.app.mu.RUnlock()
	var trending []string
	if t, ok := br.(interface{ TrendingProductIDs() []string }); ok {
		trending = t.TrendingProductIDs()
	}
	var prods []model.Product
	byID := map[string]model.Product{}
	for _, p := range in.Shop.Products {
		byID[p.ID] = p
	}
	pick := func(ids []string) {
		for _, id := range ids {
			if p, ok := byID[id]; ok && p.InStock && executor.ShopURL(p.URL) && executor.ShopURL(p.Image) && len(prods) < nCr {
				dup := false
				for _, q := range prods {
					dup = dup || q.ID == p.ID
				}
				if !dup {
					prods = append(prods, p)
				}
			}
		}
	}
	if edit != nil && edit.Edited && (edit.ProductsGone || len(edit.ProductIDs) > 0 && len(allowedOnly(edit.ProductIDs, in.Shop)) == 0) {
		return nil, ac.starterSkip(msgJobAvoided) // his own picks were all avoided since: say so, never swap them quietly
	}
	if edit != nil && edit.Edited && len(edit.ProductIDs) > 0 { // the owner's own product picks first
		pick(edit.ProductIDs)
	}
	var ranked []string
	for _, r := range model.Rank(in.Shop.Products, pb, model.RankSignals{Now: env.Now, Trending: trending, Baseline: firstSeenBaseline(in.Shop)}) {
		if edit == nil || !contains(edit.NotFor, r.ID) { // ✕ on the Plan: not for the first launch (slots.go)
			ranked = append(ranked, r.ID)
		}
	}
	pick(ranked)
	if len(prods) == 0 {
		return nil, ac.starterSkip("no in-stock product with a photo in the shop feed (after your playbook)")
	}
	if nAR > len(prods) {
		nAR = len(prods)
	}
	brief := ownerCopyBrief(pb, "")
	if edit != nil && strings.TrimSpace(edit.CopyDirection+edit.Note) != "" {
		brief = ownerCopyBrief(pb, strings.TrimSpace("Copy direction for this job: "+edit.CopyDirection+"\n"+edit.Note))
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
		out, err := cw.Copy(cctx, model.CopyInput{ProductIDs: []string{p.ID}, Langs: []string{"ar", "en"}, Angle: "best-seller, authentic Korean skincare", Brief: brief, Shop: in.Shop, Research: in.Research})
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
	countries := funnelCountries(pb, lim.Countries)
	cc := strings.Join(anyToStrings(countries), "+")
	tCPA := ac.targetCPA(in)
	nSets := pb.AdSetsFor(fb.Prospecting-fb.ExistingCap, tCPA)
	split := nSets >= 2 && nAR > 0 && nEN > 0 // AR and EN ad sets only when the budget feeds both through learning
	ps := []model.Proposal{{ID: id("sales"), Kind: model.KindCreateCampaign, Target: "tmp:" + tag + "-sales",
		Params: map[string]any{"name": "Prospecting · Advantage+ sales", "daily_budget": fb.Prospecting, "type": "sales", "bid_strategy": "LOWEST_COST_WITHOUT_CAP"},
		Why:    fmt.Sprintf("The funnel's main campaign: Advantage+ sales finds new customers (AED %.0f/day, %s-budget tier, %.0f%% of the room under your limits). Lowest cost bidding until it has ~50 purchases a week.", fb.Prospecting, fb.Tier, starterShare*100), Confidence: 0.7,
		Evidence: []string{fmt.Sprintf("daily limit AED %.0f, committed AED %.0f, target CPA AED %.0f → %d ad set(s) can exit learning", lim.DailyAED, executor.CommittedDaily(env.Tree), tCPA, nSets)}}}
	var exclude []any
	if pb.ExcludeBuyersDays > 0 {
		ps = append(ps, model.Proposal{ID: id("buyers"), Kind: model.KindCreateAudience, Target: "tmp:" + tag + "-buyers",
			Params: map[string]any{"name": fmt.Sprintf("Buyers %dd", pb.ExcludeBuyersDays), "source": "website", "event": "Purchase", "retention_days": float64(pb.ExcludeBuyersDays)},
			Why:    fmt.Sprintf("Everyone who bought in the last %d days, so prospecting spends on new customers only (your playbook).", pb.ExcludeBuyersDays), Confidence: 0.8})
		exclude = []any{"tmp:" + tag + "-buyers"}
	}
	newSet := func(key, name string) model.Proposal {
		pr := map[string]any{"name": name, "campaign_id": "tmp:" + tag + "-sales", "countries": countries, "destination": "website",
			"advantage_audience": 1.0, "age_range": []any{float64(pb.AgeMin), float64(pb.AgeMax)}}
		if pb.Gender == "women" {
			pr["genders"] = []any{2.0}
		}
		if exclude != nil {
			pr["excluded_custom_audiences"] = exclude
		}
		return model.Proposal{ID: id(key), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-" + key, Params: pr, Confidence: 0.7,
			Why: "New customers: Advantage+ audience with your age and gender as suggestions, recent buyers excluded (an exclusion Meta never expands)."}
	}
	setAR, setEN := "tmp:"+tag+"-ar", "tmp:"+tag+"-ar"
	if split {
		ps = append(ps, newSet("ar", "Prospecting AR · new customers · "+cc), newSet("en", "Prospecting EN · new customers · "+cc))
		setEN = "tmp:" + tag + "-en"
	} else {
		label := map[bool]string{true: "AR+EN", false: strings.ToUpper(strings.Join(pb.Languages, "+"))}[nAR > 0 && nEN > 0]
		ps = append(ps, newSet("ar", "Prospecting "+label+" · new customers · "+cc))
	}
	addAd := func(n int, lang, set string, p model.Product) error {
		crID, err := ac.starterCreative(ctx, p, lang, copyOf)
		if err != nil {
			return err
		}
		cr, _, err := ac.creatives.Get(crID)
		if err != nil || strings.TrimSpace(cr.Headline+cr.PrimaryText) == "" { // never an ad with empty copy (QA)
			return fmt.Errorf("creative %s could not be read back: %v", crID, err)
		}
		ps = append(ps, model.Proposal{ID: id(fmt.Sprintf("cr-%d", n)), Kind: model.KindUploadCreative, Target: crID,
			Params: map[string]any{"creative_id": crID, "format": "image", "headline": cr.Headline, "primary_text": cr.PrimaryText},
			Why:    fmt.Sprintf("%s ad for %s (ranked #%d: %s).", strings.ToUpper(lang), shortTitle(p), indexOf(ranked, p.ID)+1, map[bool]string{true: "trending, in stock", false: "in stock"}[contains(trending, p.ID)]), Confidence: 0.65},
			model.Proposal{ID: id(fmt.Sprintf("ad-%d", n)), Kind: model.KindCreateAd, Target: fmt.Sprintf("tmp:%s-ad%d", tag, n),
				Params: map[string]any{"name": fmt.Sprintf("Prospecting %s · %s", strings.ToUpper(lang), shortTitle(p)), "adset_id": set, "creative_id": crID},
				Why:    "A distinct concept inside the main campaign (creative is the targeting), switched on after its campaign and ad set.", Confidence: 0.65})
		return nil
	}
	n := 0
	for _, p := range prods[:nAR] {
		n++
		if err := addAd(n, "ar", setAR, p); err != nil {
			return nil, ac.starterSkip("starter creative: " + err.Error())
		}
	}
	for i := 0; i < nEN && i < len(prods); i++ {
		n++
		if err := addAd(n, "en", setEN, prods[i]); err != nil {
			return nil, ac.starterSkip("starter creative: " + err.Error())
		}
	}
	if fb.ExistingCap > 0 { // growth tier: existing customers inside the same campaign, capped (META-FACTS §2b)
		ps = append(ps,
			model.Proposal{ID: id("past"), Kind: model.KindCreateAudience, Target: "tmp:" + tag + "-past",
				Params: map[string]any{"name": "Past buyers 30–180d", "source": "website", "event": "Purchase", "retention_days": 180.0, "exclude_event": "Purchase", "exclude_days": 30.0},
				Why:    "Customers who bought 30 to 180 days ago: due for a refill or the next routine step.", Confidence: 0.7},
			model.Proposal{ID: id("existing"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-existing",
				Params: map[string]any{"name": "Prospecting · existing customers (capped)", "campaign_id": "tmp:" + tag + "-sales", "countries": countries, "destination": "website",
					"advantage_audience": 0.0, "custom_audiences": []any{"tmp:" + tag + "-past"}, "strict_audiences": 1.0, "spend_cap": fb.ExistingCap},
				Why: fmt.Sprintf("Existing customers get their own ad set capped at AED %.0f/day (%.0f%% split) — Meta's own way to cap existing-customer spend in Advantage+ sales.", fb.ExistingCap, pb.SplitFor(lim.DailyAED).Existing), Confidence: 0.65})
		p := prods[len(prods)-1]
		n++
		if err := addAd(n, map[bool]string{true: "ar", false: "en"}[langOn["ar"]], "tmp:"+tag+"-existing", p); err != nil {
			return nil, ac.starterSkip("starter creative: " + err.Error())
		}
	}
	if catalog && fb.Retargeting > 0 {
		var allowed []string
		for _, p := range in.Shop.Products {
			if p.InStock {
				allowed = append(allowed, p.ID)
			}
		}
		filter := `{"availability":{"eq":"in stock"}}`
		if len(allowed) > 0 {
			b, _ := json.Marshal(map[string]any{"and": []any{map[string]any{"availability": map[string]string{"eq": "in stock"}}, map[string]any{"retailer_id": map[string]any{"is_any": allowed}}}})
			filter = string(b)
		}
		h, t := "{{product.name}}", "منتجات كورية أصلية رأيتِها على K-Beauty Bliss — ما زالت متوفرة. توصيل داخل الإمارات."
		if !langOn["ar"] {
			t = "Authentic Korean skincare you looked at on K-Beauty Bliss, still in stock. Delivery across the UAE."
		}
		ps = append(ps,
			model.Proposal{ID: id("productset"), Kind: model.KindCreateProductSet, Target: "tmp:" + tag + "-ps",
				Params: map[string]any{"name": "Retargeting · in stock, playbook-allowed", "filter": filter}, Why: "Only in-stock products your playbook allows (avoid list and stock / margin floors) can appear.", Confidence: 0.75},
			model.Proposal{ID: id("catalog"), Kind: model.KindCreateCampaign, Target: "tmp:" + tag + "-cat",
				Params: map[string]any{"name": "Retargeting · Advantage+ catalog", "daily_budget": fb.Retargeting, "type": "catalog", "bid_strategy": "LOWEST_COST_WITHOUT_CAP"},
				Why:    fmt.Sprintf("Retargeting with the exact product each person looked at (AED %.0f/day, %.0f%% split).", fb.Retargeting, pb.SplitFor(lim.DailyAED).Retargeting), Confidence: 0.7},
			model.Proposal{ID: id("adset-cat"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-catset",
				Params: map[string]any{"name": "Retargeting · viewed or carted 14d, not bought", "campaign_id": "tmp:" + tag + "-cat", "countries": countries, "product_set_id": "tmp:" + tag + "-ps",
					"advantage_audience": 0.0, "retarget": map[string]any{"product_set_id": "tmp:" + tag + "-ps", "days": 14.0, "events": []any{"ViewContent", "AddToCart"}, "exclude_purchase_days": 14.0}},
				Why: "Dynamic retargeting: viewed or added to cart in the last 14 days and did not buy.", Confidence: 0.75},
			model.Proposal{ID: id("cr-cat"), Kind: model.KindUploadCreative, Target: "tmp:" + tag + "-catcr",
				Params: map[string]any{"format": "catalog", "product_set_id": "tmp:" + tag + "-ps", "template": map[string]any{"headline": h, "primary_text": t, "link": "https://kbeautybliss.com/"}, "primary_text": t},
				Why:    "Advantage+ catalog ad: Meta shows the product each person viewed and links to its page.", Confidence: 0.7},
			model.Proposal{ID: id("ad-cat"), Kind: model.KindCreateAd, Target: "tmp:" + tag + "-catad",
				Params: map[string]any{"name": "Retargeting AR · catalog", "adset_id": "tmp:" + tag + "-catset", "creative_id": "tmp:" + tag + "-catcr"}, Why: "The retargeting ad.", Confidence: 0.7})
	}
	return ps, fmt.Sprintf("funnel build (%s tier): %d Arabic + %d English ads in %d prospecting ad set(s), AED %.0f/day prospecting%s%s", fb.Tier, nAR, nEN,
		map[bool]int{true: 2, false: 1}[split], fb.Prospecting,
		map[bool]string{true: fmt.Sprintf(" (existing customers capped at AED %.0f)", fb.ExistingCap), false: ""}[fb.ExistingCap > 0],
		map[bool]string{true: fmt.Sprintf(" + AED %.0f/day catalog retargeting", fb.Retargeting), false: ""}[catalog && fb.Retargeting > 0])
}

func indexOf(xs []string, s string) int {
	for i, x := range xs {
		if x == s {
			return i
		}
	}
	return -1
}

func anyToStrings(xs []any) []string {
	var o []string
	for _, x := range xs {
		o = append(o, fmt.Sprint(x))
	}
	return o
}

// firstSeenBaseline: the first day the engine recorded the feed.
func firstSeenBaseline(s model.ShopSnapshot) string {
	b := ""
	for _, p := range s.Products {
		if p.FirstSeen != "" && (b == "" || p.FirstSeen < b) {
			b = p.FirstSeen
		}
	}
	return b
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
	ac.st.Append("starter", "the first launch is waiting: "+why, s) // Activity shows every skip
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
