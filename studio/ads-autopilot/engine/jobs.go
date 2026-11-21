package main

// jobs.go — the Plan you can edit: the job queue (the owner, 20 Nov 2026: "will i have any way to change the
// upcoming job or edit the prompt etc or plan?").
//
// Every upcoming piece of work is a Job with a time, a reason, a budget, products, an audience, a format and the
// brief Claude gets: the first launch (the funnel build), the weekly creative tests and refreshes of tiring ads (the
// planner's plan), seasonal pushes, and the funnel pieces still missing (retargeting, existing customers,
// lookalikes). The owner can Approve, Edit (products, budget, language, format, copy direction, his own note), Skip,
// move it earlier or later, read the exact brief, and ask Claude to replan with a note.
//
//	Auto:     a job runs at its time unless skipped (approved or not).
//	Practice: a due job waits; its proposals are logged as what it WOULD do. Approve runs it now, for real, through
//	          every executor check (as Approvals does for one proposal).
//
// Jobs are materialised into proposals only when due, from the account as it is then (so an edit always lands), and
// every proposal still passes the executor's checks (limits, PAUSED→ACTIVE, idempotency keys, linter).

import (
	"context"
	"errors"
	"fmt"
	"math"
	"net/http"
	"regexp"
	"sort"
	"strings"
	"time"

	"kaa/executor"
	"kaa/model"
)

const jobFirstLaunch = "first-launch"

type JobLog struct {
	At   time.Time `json:"at"`
	Text string    `json:"text"`
}

type Job struct {
	ID            string    `json:"id"`
	Kind          string    `json:"kind"` // build_funnel | test | refresh | seasonal | funnel_retargeting | funnel_existing | funnel_lookalike
	Title         string    `json:"title"`
	TitleAR       string    `json:"title_ar"`
	Why           string    `json:"why"`
	When          time.Time `json:"when"`
	Status        string    `json:"status"` // planned | approved | skipped | waiting | done | failed
	Waiting       string    `json:"waiting,omitempty"`
	BudgetAED     float64   `json:"budget_aed,omitempty"`
	ProductIDs    []string  `json:"product_ids"`
	Lang          string    `json:"lang,omitempty"`
	Format        string    `json:"format,omitempty"` // image | carousel
	Audience      string    `json:"audience,omitempty"`
	CopyDirection string    `json:"copy_direction,omitempty"`
	Note          string    `json:"note,omitempty"`
	Target        string    `json:"target,omitempty"` // refresh: the tiring ad; seasonal: the campaign it raises
	Edited        bool      `json:"edited,omitempty"`
	ProductsGone  bool      `json:"products_gone,omitempty"` // every product it had was avoided: it waits for the owner's pick (picker.go)
	Source        string    `json:"source"`                  // funnel | planner | season
	ProposalIDs   []string  `json:"proposal_ids,omitempty"`
	Result        string    `json:"result,omitempty"`
	// occasions (occasions.go): the prepared ads, the ones the owner rejected, the dates, the reminders sent
	Creatives []string                `json:"creatives,omitempty"`
	Rejected  []string                `json:"rejected,omitempty"`
	Occasion  *model.OccasionInstance `json:"occasion,omitempty"`
	Reminded  []int                   `json:"reminded,omitempty"`
	Log       []JobLog                `json:"log,omitempty"`
	UpdatedAt time.Time               `json:"updated_at"`
}

type JobSet struct {
	Jobs       []Job  `json:"jobs"`
	ReplanNote string `json:"replan_note,omitempty"`
}

func (j *Job) logf(at time.Time, f string, a ...any) {
	j.Log = append(j.Log, JobLog{at.UTC(), fmt.Sprintf(f, a...)})
	if len(j.Log) > 30 {
		j.Log = j.Log[len(j.Log)-30:]
	}
	j.UpdatedAt = at.UTC()
}

func (ac *Acct) jobSet() JobSet {
	ac.mu.RLock()
	defer ac.mu.RUnlock()
	js := ac.jobs
	js.Jobs = append([]Job(nil), ac.jobs.Jobs...)
	return js
}

func (ac *Acct) saveJobs(js JobSet) {
	sort.SliceStable(js.Jobs, func(i, k int) bool { return js.Jobs[i].When.Before(js.Jobs[k].When) })
	if len(js.Jobs) > 200 { // keep the queue + the recent history
		js.Jobs = js.Jobs[len(js.Jobs)-200:]
	}
	ac.mu.Lock()
	ac.jobs, ac.stateJSON = js, nil
	ac.mu.Unlock()
	_ = ac.st.Save("jobs", js)
}

func (ac *Acct) jobByID(id string) *Job {
	for _, j := range ac.jobSet().Jobs {
		if j.ID == id {
			j := j
			return &j
		}
	}
	return nil
}

// jobNotes: the owner's notes on queued jobs and his replan note, for the planner.
func (ac *Acct) jobNotes() []string {
	js := ac.jobSet()
	var out []string
	if js.ReplanNote != "" {
		out = append(out, "Replan: "+js.ReplanNote)
	}
	for _, j := range js.Jobs {
		if open(j.Status) && strings.TrimSpace(j.Note+j.CopyDirection) != "" {
			out = append(out, fmt.Sprintf("%s (%s): %s", j.Title, j.When.Format("2006-01-02"), strings.TrimSpace(j.CopyDirection+" "+j.Note)))
		}
	}
	return out
}

func open(status string) bool {
	return status == "planned" || status == "approved" || status == "waiting"
}

// upsert: a job the plan (re)proposes. What the owner decided (status, edits, his time) is kept.
func upsert(js *JobSet, j Job, now time.Time) {
	for i := range js.Jobs {
		o := &js.Jobs[i]
		if o.ID != j.ID {
			continue
		}
		if !open(o.Status) { // done, failed or skipped: the owner's / the run's word stands
			return
		}
		if o.Edited || o.Status == "approved" { // keep his edits and his approval; only the waiting state moves
			switch {
			case j.Status == "waiting":
				o.Waiting = j.Waiting
				if o.Status != "approved" {
					o.Status = "waiting"
				}
			case o.Status == "waiting":
				o.Status, o.Waiting = "planned", ""
			}
			if len(j.ProposalIDs) > 0 {
				o.ProposalIDs = j.ProposalIDs
			}
			return
		}
		keepLog, when := o.Log, o.When
		*o = j
		o.Log = keepLog
		if o.Kind == "build_funnel" && !when.IsZero() {
			o.When = when
		}
		return
	}
	j.logf(now, "planned: %s", j.Why)
	js.Jobs = append(js.Jobs, j)
}

// refreshJobs: the queue from this cycle's plan, the first launch and the funnel's missing pieces.
func (ac *Acct) refreshJobs(ctx context.Context, in model.CycleInput, plan model.Plan, starter []model.Proposal, stWhy string, env executor.Env) {
	now := env.Now
	loc := ac.loc()
	js := ac.jobSet()
	pb := model.DefaultPlaybook().Normalize()
	if in.Playbook != nil {
		pb = *in.Playbook
	}
	// first launch
	if len(starter) > 0 {
		var ids, prods []string
		budget := 0.0
		for _, p := range starter {
			ids = append(ids, p.ID)
			if p.Kind == model.KindCreateCampaign {
				b, _ := p.Params["daily_budget"].(float64)
				budget += b
			}
			if p.Kind == model.KindUploadCreative {
				if c, _, err := ac.creatives.Get(fmt.Sprint(p.Params["creative_id"])); err == nil {
					prods = append(prods, c.ProductIDs...)
				}
			}
		}
		upsert(&js, Job{ID: jobFirstLaunch, Kind: "build_funnel", Title: "First launch: build the funnel", TitleAR: "الإطلاق الأول: بناء مسار المبيعات",
			Why: stWhy, When: now, Status: "planned", BudgetAED: budget, ProductIDs: uniq(prods), Lang: strings.Join(pb.Languages, "+"), Format: "image",
			Audience: "Advantage+ audience, recent buyers excluded", Source: "funnel", ProposalIDs: ids, UpdatedAt: now.UTC()}, now)
	}
	// the planner's tests, refreshes and seasonal pushes
	weekTests := map[string]bool{}
	for i, s := range plan.Steps {
		at := atLocal(s.Date, 9, loc, now)
		switch s.Kind {
		case "test":
			id := fmt.Sprintf("test-%s-%d", strings.ReplaceAll(s.Date, "-", ""), i)
			weekTests[id] = true
			f := "image"
			if s.Format == "carousel" {
				f = "carousel"
			}
			upsert(&js, Job{ID: id, Kind: "test", Title: s.Title, TitleAR: "اختبار إعلان جديد", Why: firstNonEmpty(s.Why, s.Detail), When: at, Status: "planned",
				ProductIDs: allowedOnly(s.ProductIDs, in.Shop), Lang: firstNonEmpty(s.Lang, "ar"), Format: f, CopyDirection: s.Angle,
				Audience: "inside the prospecting ad set (Advantage+ audience)", Source: "planner", UpdatedAt: now.UTC()}, now)
		case "refresh":
			upsert(&js, Job{ID: "refresh-" + s.Target, Kind: "refresh", Title: "Refresh a tiring ad", TitleAR: "تجديد إعلان مُرهَق", Why: s.Why, When: at, Status: "planned",
				Target: s.Target, Lang: "ar", Format: "image", Source: "planner", ProductIDs: []string{}, UpdatedAt: now.UTC()}, now)
		}
	}
	// a planned, unedited test that this plan no longer has goes away (the planner changed its mind)
	var keep []Job
	for _, j := range js.Jobs {
		if j.Kind == "test" && j.Status == "planned" && !j.Edited && !weekTests[j.ID] && j.When.After(now) && len(weekTests) > 0 {
			continue
		}
		keep = append(keep, j)
	}
	js.Jobs = keep
	// occasions: packages prepared at their prep date, waiting for approval (occasions.go)
	ac.refreshOccasions(ctx, in, &js, now)
	// the funnel's missing pieces (only once the main campaign exists)
	f := findFunnel(in.Tree, in.Audiences)
	if f.prosp != nil {
		sp := pb.SplitFor(in.Limits.DailyAED)
		l := in.Links
		if f.catalog == nil && sp.Retargeting > 0 {
			j := Job{ID: "funnel-retargeting", Kind: "funnel_retargeting", Title: "Add catalog retargeting", TitleAR: "إضافة إعادة الاستهداف بالكتالوج",
				Why: "Viewed or carted in 14 days and did not buy: the highest-intent people, shown their own product.", When: now, Status: "planned",
				BudgetAED: math.Max(10, floor5(in.Limits.DailyAED*sp.Retargeting/100*starterShare)), Source: "funnel", ProductIDs: []string{}, Lang: "ar", Format: "catalog", UpdatedAt: now.UTC()}
			if l.CatalogID == "" || l.PixelID == "" {
				j.Status, j.Waiting = "waiting", "link the product catalog and the pixel (Settings → Accounts)"
			}
			upsert(&js, j, now)
		}
		if f.existing == nil && pb.Tier(in.Limits.DailyAED) == "growth" && sp.Existing > 0 {
			upsert(&js, Job{ID: "funnel-existing", Kind: "funnel_existing", Title: "Add the existing-customer ad set", TitleAR: "إضافة مجموعة العملاء الحاليين",
				Why: "Growth tier: past buyers (30–180 days) get their own capped ad set inside the prospecting campaign.", When: now, Status: "planned",
				BudgetAED: math.Max(5, floor5(f.prosp.DailyBudgetAED*sp.Existing/math.Max(1, sp.Prospecting+sp.Existing))), Source: "funnel", ProductIDs: []string{}, Lang: "ar", Format: "image", UpdatedAt: now.UTC()}, now)
		}
		if f.lookalike == nil {
			j := Job{ID: "funnel-lookalike", Kind: "funnel_lookalike", Title: "Add lookalikes of buyers (1% + 3%)", TitleAR: "إضافة جمهور مشابه للمشترين",
				Why: "People like the shop's buyers, as suggestions in a new prospecting ad set (the running ones are not edited).", When: now, Status: "planned",
				Source: "funnel", ProductIDs: []string{}, Lang: "ar", Format: "image", UpdatedAt: now.UTC()}
			switch {
			case f.buyers == nil:
				j.Status, j.Waiting = "waiting", "needs the buyers audience"
			case f.buyers.Lower < 100:
				j.Status, j.Waiting = "waiting", fmt.Sprintf("needs 100+ buyers (Meta's minimum); about %d now", f.buyers.Lower)
			}
			upsert(&js, j, now)
		}
	}
	ac.saveJobs(js)
}

func uniq(xs []string) []string {
	seen := map[string]bool{}
	out := []string{}
	for _, x := range xs {
		if !seen[x] {
			seen[x] = true
			out = append(out, x)
		}
	}
	return out
}

func allowedOnly(ids []string, s model.ShopSnapshot) []string {
	ok := model.Allowed(s)
	out := []string{}
	for _, id := range ids {
		if ok[id] {
			out = append(out, id)
		}
	}
	return out
}

// atLocal: a YYYY-MM-DD at hour h in the account's time zone (now when empty or past).
func atLocal(day string, h int, loc *time.Location, now time.Time) time.Time {
	t, err := time.ParseInLocation("2006-01-02", day, loc)
	if err != nil {
		return now
	}
	t = t.Add(time.Duration(h) * time.Hour)
	if t.Before(now) {
		return now
	}
	return t
}

// ── materialise: a due job → proposals ──

var jobPID = regexp.MustCompile(`[^A-Za-z0-9_-]+`)

func (ac *Acct) prospectingSet(in model.CycleInput, lang string) string {
	f := findFunnel(in.Tree, in.Audiences)
	best := ""
	for _, s := range f.newSets {
		if s.Status == "ARCHIVED" || s.Status == "DELETED" {
			continue
		}
		u := " " + s.Name + " "
		if strings.Contains(u, " "+strings.ToUpper(lang)+" ") || strings.Contains(u, "AR+EN") {
			return s.ID
		}
		if best == "" {
			best = s.ID
		}
	}
	return best
}

// nextProducts: the job's own products (allowed ones), else the best-ranked ones not already in an app ad.
func (ac *Acct) nextProducts(in model.CycleInput, j Job, n int) []model.Product {
	by := map[string]model.Product{}
	for _, p := range in.Shop.Products {
		by[p.ID] = p
	}
	var out []model.Product
	for _, id := range j.ProductIDs {
		if p, ok := by[id]; ok && p.InStock && executor.ShopURL(p.Image) && executor.ShopURL(p.URL) && len(out) < n {
			out = append(out, p)
		}
	}
	if len(out) > 0 {
		return out
	}
	used := map[string]bool{}
	lib := map[string]model.Creative{}
	for _, c := range in.Creatives {
		lib[c.ID] = c
		lib[c.MetaID] = c
	}
	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				if a.Status == "ACTIVE" {
					for _, id := range lib[a.CreativeID].ProductIDs {
						used[id] = true
					}
				}
			}
		}
	}
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	var tr []string
	if t, ok := br.(interface{ TrendingProductIDs() []string }); ok {
		tr = t.TrendingProductIDs()
	}
	for _, r := range model.Rank(in.Shop.Products, *in.Playbook, model.RankSignals{Now: in.Now, Trending: tr, Baseline: firstSeenBaseline(in.Shop)}) {
		if p, ok := by[r.ID]; ok && !used[r.ID] && executor.ShopURL(p.URL) && executor.ShopURL(p.Image) && len(out) < n {
			out = append(out, p)
		}
	}
	return out
}

// materialise: the proposals of one due job, or why it has to wait.
func (ac *Acct) materialise(ctx context.Context, in model.CycleInput, j Job, env executor.Env) ([]model.Proposal, string) {
	pb := *in.Playbook
	pid := func(s string) string { return "job-" + jobPID.ReplaceAllString(j.ID, "-") + "-" + s }
	tag := "j" + fmt.Sprintf("%x", hash32(j.ID))[:6]
	countries := funnelCountries(pb, env.Limits.Countries)
	ac.app.mu.RLock()
	br, claudeOK := ac.app.brain, ac.app.conns.Claude.OK
	ac.app.mu.RUnlock()
	brief := ownerCopyBrief(pb, strings.TrimSpace(strings.TrimSpace("Copy direction for this job: "+j.CopyDirection)+"\n"+j.Note))
	copyCache := map[string]map[string][2]string{}
	copyOf := func(p model.Product) map[string][2]string {
		if m, ok := copyCache[p.ID]; ok {
			return m
		}
		m := map[string][2]string{}
		copyCache[p.ID] = m
		cw, ok := br.(model.Copywriter)
		if !ok || !claudeOK {
			return m
		}
		cctx, cancel := context.WithTimeout(ctx, 3*time.Minute)
		defer cancel()
		out, err := cw.Copy(cctx, model.CopyInput{ProductIDs: []string{p.ID}, Langs: []string{"ar", "en"}, Angle: firstNonEmpty(j.CopyDirection, "authentic Korean skincare"), Brief: brief, Shop: in.Shop, Research: in.Research})
		if err != nil {
			return m
		}
		for _, v := range out.Variants {
			if _, have := m[v.Lang]; !have && v.Headline != "" && v.PrimaryText != "" {
				m[v.Lang] = [2]string{v.Headline, v.PrimaryText}
			}
		}
		return m
	}
	lang := firstNonEmpty(j.Lang, "ar")
	if lang != "ar" && lang != "en" {
		lang = "ar"
	}
	adsInto := func(set string, prods []model.Product) ([]model.Proposal, string) {
		var ps []model.Proposal
		if j.Format == "carousel" && len(prods) >= 2 {
			var cards []any
			var first model.Creative
			for i, p := range prods {
				crID, err := ac.starterCreative(ctx, p, lang, copyOf)
				if err != nil {
					return nil, err.Error()
				}
				c, _, err := ac.creatives.Get(crID)
				if err != nil || strings.TrimSpace(c.Headline+c.PrimaryText) == "" {
					return nil, fmt.Sprintf("creative %s could not be read back: %v", crID, err)
				}
				if i == 0 {
					first = c
				}
				cards = append(cards, map[string]any{"creative_id": crID, "headline": c.Headline, "link": p.URL})
			}
			ps = append(ps, model.Proposal{ID: pid("carousel"), Kind: model.KindUploadCreative, Target: "tmp:" + tag + "-car",
				Params: map[string]any{"format": "carousel", "cards": cards, "primary_text": first.PrimaryText, "headline": first.Headline}, Why: j.Why, Confidence: 0.6},
				model.Proposal{ID: pid("ad"), Kind: model.KindCreateAd, Target: "tmp:" + tag + "-ad",
					Params: map[string]any{"name": fmt.Sprintf("Prospecting %s · carousel · %s", strings.ToUpper(lang), shortTitle(prods[0])), "adset_id": set, "creative_id": "tmp:" + tag + "-car"}, Why: j.Why, Confidence: 0.6})
			return ps, ""
		}
		for i, p := range prods {
			crID, err := ac.starterCreative(ctx, p, lang, copyOf)
			if err != nil {
				return nil, err.Error()
			}
			c, _, err := ac.creatives.Get(crID)
			if err != nil || strings.TrimSpace(c.Headline+c.PrimaryText) == "" {
				return nil, fmt.Sprintf("creative %s could not be read back: %v", crID, err)
			}
			ps = append(ps, model.Proposal{ID: pid(fmt.Sprintf("cr%d", i)), Kind: model.KindUploadCreative, Target: crID,
				Params: map[string]any{"creative_id": crID, "format": "image", "headline": c.Headline, "primary_text": c.PrimaryText}, Why: j.Why, Confidence: 0.6},
				model.Proposal{ID: pid(fmt.Sprintf("ad%d", i)), Kind: model.KindCreateAd, Target: fmt.Sprintf("tmp:%s-ad%d", tag, i),
					Params: map[string]any{"name": fmt.Sprintf("Prospecting %s · %s", strings.ToUpper(lang), shortTitle(p)), "adset_id": set, "creative_id": crID}, Why: j.Why, Confidence: 0.6})
		}
		return ps, ""
	}
	// the owner's (or an occasion's) own products all avoided since: it says so instead of quietly changing them
	if j.ProductsGone || (len(j.ProductIDs) > 0 && (j.Edited || j.Kind == "occasion") && len(allowedOnly(j.ProductIDs, in.Shop)) == 0) {
		return nil, msgJobAvoided
	}
	f := findFunnel(in.Tree, in.Audiences)
	switch j.Kind {
	case "test", "refresh", "seasonal":
		set := ac.prospectingSet(in, lang)
		if set == "" {
			return nil, "needs the prospecting campaign first (the first launch)"
		}
		n := 1
		if j.Format == "carousel" {
			n = 4
		}
		prods := ac.nextProducts(in, j, n)
		if len(prods) == 0 {
			return nil, "no in-stock product your playbook allows"
		}
		ps, why := adsInto(set, prods)
		if why != "" {
			return nil, why
		}
		if j.Kind == "refresh" && j.Target != "" {
			ps = append(ps, model.Proposal{ID: pid("pause"), Kind: model.KindPause, Target: j.Target, Params: map[string]any{}, Why: "Replaced by a fresh concept (it was tiring).", Confidence: 0.7})
		}
		if j.Kind == "seasonal" && f.prosp != nil {
			step := env.Limits.MaxBudgetStepPct
			if step <= 0 {
				step = 20
			}
			nb := math.Round(f.prosp.DailyBudgetAED*(1+step/100)*100) / 100
			if j.BudgetAED > 0 && j.BudgetAED < nb {
				nb = j.BudgetAED
			}
			ps = append(ps, model.Proposal{ID: pid("budget"), Kind: model.KindSetBudget, Target: f.prosp.ID, Params: map[string]any{"daily_budget": nb},
				Why: fmt.Sprintf("Seasonal ramp: one step up (≤ %.0f%%) before the peak.", step), Confidence: 0.6})
		}
		return ps, ""
	case "occasion":
		return ac.occasionProposals(in, j, env.Limits.MaxBudgetStepPct, pid)
	case "funnel_retargeting":
		if in.Links.CatalogID == "" || in.Links.PixelID == "" {
			return nil, "link the product catalog and the pixel first"
		}
		var allowed []string
		for _, p := range in.Shop.Products {
			if p.InStock {
				allowed = append(allowed, p.ID)
			}
		}
		filter := `{"availability":{"eq":"in stock"}}`
		if len(allowed) > 0 {
			filter = fmt.Sprintf(`{"and":[{"availability":{"eq":"in stock"}},{"retailer_id":{"is_any":["%s"]}}]}`, strings.Join(allowed, `","`))
		}
		t := "منتجات كورية أصلية رأيتِها على K-Beauty Bliss — ما زالت متوفرة. توصيل داخل الإمارات."
		b := j.BudgetAED
		if b <= 0 {
			b = 10
		}
		return []model.Proposal{
			{ID: pid("ps"), Kind: model.KindCreateProductSet, Target: "tmp:" + tag + "-ps", Params: map[string]any{"name": "Retargeting · in stock, playbook-allowed", "filter": filter}, Why: j.Why, Confidence: 0.75},
			{ID: pid("camp"), Kind: model.KindCreateCampaign, Target: "tmp:" + tag + "-cat", Params: map[string]any{"name": "Retargeting · Advantage+ catalog", "daily_budget": b, "type": "catalog", "bid_strategy": "LOWEST_COST_WITHOUT_CAP"}, Why: j.Why, Confidence: 0.7},
			{ID: pid("set"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-set", Params: map[string]any{"name": "Retargeting · viewed or carted 14d, not bought", "campaign_id": "tmp:" + tag + "-cat", "countries": countries,
				"product_set_id": "tmp:" + tag + "-ps", "advantage_audience": 0.0, "retarget": map[string]any{"product_set_id": "tmp:" + tag + "-ps", "days": 14.0, "events": []any{"ViewContent", "AddToCart"}, "exclude_purchase_days": 14.0}}, Why: j.Why, Confidence: 0.7},
			{ID: pid("cr"), Kind: model.KindUploadCreative, Target: "tmp:" + tag + "-cr", Params: map[string]any{"format": "catalog", "product_set_id": "tmp:" + tag + "-ps",
				"template": map[string]any{"headline": "{{product.name}}", "primary_text": t, "link": "https://kbeautybliss.com/"}, "primary_text": t}, Why: j.Why, Confidence: 0.7},
			{ID: pid("ad"), Kind: model.KindCreateAd, Target: "tmp:" + tag + "-ad", Params: map[string]any{"name": "Retargeting AR · catalog", "adset_id": "tmp:" + tag + "-set", "creative_id": "tmp:" + tag + "-cr"}, Why: j.Why, Confidence: 0.7},
		}, ""
	case "funnel_existing":
		if f.prosp == nil || f.prosp.DailyBudgetAED <= 0 {
			return nil, "needs the prospecting campaign first"
		}
		capAED := math.Min(j.BudgetAED, f.prosp.DailyBudgetAED*0.5)
		if capAED <= 0 {
			capAED = 5
		}
		prods := ac.nextProducts(in, j, 1)
		if len(prods) == 0 {
			return nil, "no in-stock product your playbook allows"
		}
		ps := []model.Proposal{
			{ID: pid("aud"), Kind: model.KindCreateAudience, Target: "tmp:" + tag + "-past", Params: map[string]any{"name": "Past buyers 30–180d", "source": "website", "event": "Purchase", "retention_days": 180.0, "exclude_event": "Purchase", "exclude_days": 30.0}, Why: j.Why, Confidence: 0.7},
			{ID: pid("set"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-set", Params: map[string]any{"name": "Prospecting · existing customers (capped)", "campaign_id": f.prosp.ID, "countries": countries, "destination": "website",
				"advantage_audience": 0.0, "custom_audiences": []any{"tmp:" + tag + "-past"}, "strict_audiences": 1.0, "spend_cap": math.Round(capAED*100) / 100}, Why: j.Why, Confidence: 0.65},
		}
		more, why := adsInto("tmp:"+tag+"-set", prods)
		if why != "" {
			return nil, why
		}
		return append(ps, more...), ""
	case "funnel_lookalike":
		if f.prosp == nil {
			return nil, "needs the prospecting campaign first"
		}
		if f.buyers == nil || f.buyers.Lower < 100 {
			return nil, "needs 100+ buyers in the buyers audience"
		}
		prods := ac.nextProducts(in, j, 2)
		if len(prods) == 0 {
			return nil, "no in-stock product your playbook allows"
		}
		pr := map[string]any{"name": "Prospecting · lookalike suggestions", "campaign_id": f.prosp.ID, "countries": countries, "destination": "website",
			"advantage_audience": 1.0, "custom_audiences": []any{"tmp:" + tag + "-lal1", "tmp:" + tag + "-lal3"}, "age_range": []any{float64(pb.AgeMin), float64(pb.AgeMax)}}
		if pb.Gender == "women" {
			pr["genders"] = []any{2.0}
		}
		if pb.ExcludeBuyersDays > 0 {
			pr["excluded_custom_audiences"] = []any{f.buyers.ID}
		}
		ps := []model.Proposal{
			{ID: pid("lal1"), Kind: model.KindCreateLookalike, Target: "tmp:" + tag + "-lal1", Params: map[string]any{"name": "Lookalike 1% of buyers", "origin_audience_id": f.buyers.ID, "ratio": 0.01}, Why: j.Why, Confidence: 0.7},
			{ID: pid("lal3"), Kind: model.KindCreateLookalike, Target: "tmp:" + tag + "-lal3", Params: map[string]any{"name": "Lookalike 3% of buyers", "origin_audience_id": f.buyers.ID, "ratio": 0.03}, Why: j.Why, Confidence: 0.7},
			{ID: pid("set"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-set", Params: pr, Why: j.Why, Confidence: 0.65},
		}
		more, why := adsInto("tmp:"+tag+"-set", prods)
		if why != "" {
			return nil, why
		}
		return append(ps, more...), ""
	}
	return nil, "nothing to do"
}

func hash32(s string) uint32 {
	var h uint32 = 2166136261
	for i := 0; i < len(s); i++ {
		h ^= uint32(s[i])
		h *= 16777619
	}
	return h
}

// dueJobs: the proposals of the jobs due now. mode is the cycle's (auto | shadow). In Auto every due planned or
// approved job runs; in Practice a due job is logged as what it WOULD do, and an approved one runs for real (owner).
func (ac *Acct) dueJobs(ctx context.Context, in model.CycleInput, env executor.Env, mode string) (cycle, owner []model.Proposal) {
	js := ac.jobSet()
	now := env.Now
	for i := range js.Jobs {
		j := &js.Jobs[i]
		if j.ID == jobFirstLaunch || (j.Status != "planned" && j.Status != "approved") || j.When.After(now) {
			continue
		}
		if j.Kind == "occasion" && j.Status != "approved" && !in.Playbook.AutoApproveOccasions {
			j.Waiting = "waits for your approval (Approvals → Occasions)"
			continue
		}
		ps, why := ac.materialise(ctx, in, *j, env)
		if why == "" {
			if n := countKind(ps, model.KindCreateAd); n > 0 && env.Limits.MaxActiveAds > 0 && executor.ActiveAds(env.Tree)+n > env.Limits.MaxActiveAds {
				why = fmt.Sprintf("no free ad slot: %d ads active, your maximum is %d (Settings → Limits)", executor.ActiveAds(env.Tree), env.Limits.MaxActiveAds)
			}
		}
		if why != "" {
			if j.Waiting != why {
				j.logf(now, "waiting: %s", why)
			}
			j.Waiting = why
			continue
		}
		j.Waiting = ""
		j.ProposalIDs = nil
		for _, p := range ps {
			j.ProposalIDs = append(j.ProposalIDs, p.ID)
		}
		if mode == model.AutopilotAuto || j.Status != "approved" {
			cycle = append(cycle, ps...)
		} else {
			owner = append(owner, ps...)
		}
	}
	ac.saveJobs(js)
	return
}

// noteJobs: what became of each job's proposals.
func (ac *Acct) noteJobs(results []executor.Result, mode string) {
	by := map[string]executor.Result{}
	for _, r := range results {
		by[r.ProposalID] = r
	}
	js := ac.jobSet()
	now := ac.app.now()
	changed := false
	for i := range js.Jobs {
		j := &js.Jobs[i]
		if !open(j.Status) || len(j.ProposalIDs) == 0 {
			continue
		}
		cnt := map[string]int{}
		var errs []string
		seen := 0
		for _, id := range j.ProposalIDs {
			r, ok := by[id]
			if !ok {
				continue
			}
			seen++
			cnt[r.Status]++
			if r.Status == "failed" || r.Status == "refused" {
				m := r.Error
				if m == "" && len(r.Decision.Reasons) > 0 {
					m = r.Decision.Reasons[0]
				}
				errs = append(errs, m)
			}
		}
		if seen == 0 {
			continue
		}
		changed = true
		switch {
		case cnt["done"]+cnt["duplicate"] == seen:
			j.Status, j.Result = "done", fmt.Sprintf("ran: %d steps done", seen)
		case cnt["shadow"] == seen:
			j.Result = fmt.Sprintf("Practice: would run %d steps (approve to run it now)", seen)
		default:
			j.Status, j.Result = "failed", strings.Join(uniq(errs), "; ")
		}
		j.logf(now, "%s", j.Result)
	}
	if changed {
		ac.saveJobs(js)
	}
}

// ── the owner's controls ──

type jobEdit struct {
	When          *time.Time `json:"when"`
	MoveHours     float64    `json:"move_hours"`
	BudgetAED     *float64   `json:"budget_aed"`
	ProductIDs    []string   `json:"product_ids"`
	Lang          *string    `json:"lang"`
	Format        *string    `json:"format"`
	CopyDirection *string    `json:"copy_direction"`
	Note          *string    `json:"note"`
}

var jobIDRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,80}$`)

func (s *server) jobsAPI(w http.ResponseWriter, r *http.Request, ac *Acct, rest string) {
	now := s.app.now()
	if rest == "" {
		if r.Method != http.MethodGet {
			jsonErr(w, 405, "GET")
			return
		}
		js := ac.jobSet()
		if js.Jobs == nil {
			js.Jobs = []Job{}
		}
		st := ac.Settings()
		writeJSON(w, map[string]any{"account": ac.id, "jobs": js.Jobs, "titles": ac.titlesOf(js.Jobs), "replan_note": js.ReplanNote, "mode": st.Limits.Autopilot, "now": now})
		return
	}
	if rest == "replan" && r.Method == http.MethodPost {
		var in struct {
			Note string `json:"note"`
		}
		if err := readJSON(r, &in, 16<<10); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		js := ac.jobSet()
		js.ReplanNote = cleanNote(in.Note)
		ac.saveJobs(js)
		ac.st.Append("config", "owner asked Claude to replan: "+js.ReplanNote, nil)
		out, err := ac.Cycle(r.Context(), model.AutopilotShadow) // a plan-only cycle: nothing is written to Meta
		if err != nil {
			code := 502
			if errors.Is(err, errBusy) || errors.Is(err, errUpdating) {
				code = 409
			}
			jsonErr(w, code, err.Error())
			return
		}
		writeJSON(w, map[string]any{"account": ac.id, "replanned": true, "proposals": out["proposals"], "jobs": ac.jobSet().Jobs})
		return
	}
	parts := strings.Split(rest, "/")
	id := parts[0]
	if !jobIDRe.MatchString(id) {
		jsonErr(w, 404, "no such job")
		return
	}
	js := ac.jobSet()
	idx := -1
	for i := range js.Jobs {
		if js.Jobs[i].ID == id {
			idx = i
		}
	}
	if idx < 0 {
		jsonErr(w, 404, "no such job")
		return
	}
	j := &js.Jobs[idx]
	verb := ""
	if len(parts) == 2 {
		verb = parts[1]
	}
	switch {
	case (verb == "reject-ad" || verb == "restore-ad") && r.Method == http.MethodPost:
		var b struct {
			CreativeID string `json:"creative_id"`
		}
		if err := readJSON(r, &b, 4096); err != nil || !contains(j.Creatives, b.CreativeID) {
			jsonErr(w, 400, "send {creative_id} of one of this job's ads")
			return
		}
		var keep []string
		for _, x := range j.Rejected {
			if x != b.CreativeID {
				keep = append(keep, x)
			}
		}
		if verb == "reject-ad" {
			keep = append(keep, b.CreativeID)
			j.logf(now, "you rejected the ad %s", b.CreativeID)
		} else {
			j.logf(now, "you put the ad %s back", b.CreativeID)
		}
		j.Rejected = keep
	case verb == "brief" && r.Method == http.MethodGet:
		writeJSON(w, ac.jobBrief(*j))
		return
	case verb == "" && r.Method == http.MethodPut:
		if !open(j.Status) {
			jsonErr(w, 409, "this job already ran ("+j.Status+")")
			return
		}
		var e jobEdit
		if err := readJSON(r, &e, 64<<10); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		if err := ac.applyEdit(j, e, now); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
	case r.Method == http.MethodPost && (verb == "skip" || verb == "unskip" || verb == "approve"):
		switch verb {
		case "skip":
			if !open(j.Status) {
				jsonErr(w, 409, "this job already ran ("+j.Status+")")
				return
			}
			j.Status = "skipped"
			j.logf(now, "you skipped it: nothing of it will run")
		case "unskip":
			if j.Status != "skipped" {
				jsonErr(w, 409, "not skipped")
				return
			}
			j.Status = "planned"
			j.logf(now, "you put it back on the plan")
		case "approve":
			if !open(j.Status) {
				jsonErr(w, 409, "this job already ran ("+j.Status+")")
				return
			}
			j.Status = "approved"
			j.logf(now, "you approved it")
		}
	default:
		jsonErr(w, 404, "PUT /api/jobs/{id}, POST /api/jobs/{id}/approve|skip|unskip, GET /api/jobs/{id}/brief, POST /api/jobs/replan")
		return
	}
	jj := *j
	ac.saveJobs(js)
	ac.st.Append("config", fmt.Sprintf("job %s: %s", jj.ID, jj.Log[len(jj.Log)-1].Text), jj)
	out := map[string]any{"account": ac.id, "job": jj}
	if verb == "" && jj.ID == jobFirstLaunch && len(jj.ProductIDs) > 0 { // the waiting first launch follows at once (picker.go)
		out["rebuilt"] = ac.starterToProducts(r.Context(), jj.ProductIDs)
	}
	// Approve on a job that is due runs it now (both modes), through every executor check
	if verb == "approve" && !jj.When.After(now) {
		res, code, err := ac.runJobNow(r.Context(), jj.ID)
		if err != nil {
			out["run_error"], out["run_code"] = err.Error(), code
		} else {
			out["run"] = res
		}
		out["job"] = ac.jobByID(jj.ID)
	}
	writeJSON(w, out)
}

func cleanNote(s string) string {
	s = strings.TrimSpace(s)
	if r := []rune(s); len(r) > 1000 {
		s = string(r[:1000])
	}
	return strings.Map(func(r rune) rune {
		if r < 32 && r != '\n' {
			return -1
		}
		return r
	}, s)
}

func (ac *Acct) applyEdit(j *Job, e jobEdit, now time.Time) error {
	var what []string
	if e.When != nil {
		if e.When.Before(now.Add(-time.Minute)) || e.When.After(now.AddDate(0, 3, 0)) {
			return errors.New("when: from now to 3 months ahead")
		}
		j.When = e.When.UTC()
		what = append(what, "time → "+j.When.In(ac.loc()).Format("Mon 2 Jan 15:04"))
	}
	if e.MoveHours != 0 {
		t := j.When.Add(time.Duration(e.MoveHours * float64(time.Hour)))
		if t.Before(now) {
			t = now
		}
		if t.After(now.AddDate(0, 3, 0)) {
			return errors.New("not more than 3 months ahead")
		}
		j.When = t.UTC()
		what = append(what, "time → "+j.When.In(ac.loc()).Format("Mon 2 Jan 15:04"))
	}
	if e.BudgetAED != nil {
		b := *e.BudgetAED
		lim := ac.Settings().Limits
		if math.IsNaN(b) || b < 0 || (lim.DailyAED > 0 && b > lim.DailyAED) {
			return fmt.Errorf("budget: 0 to your daily limit (AED %.0f)", lim.DailyAED)
		}
		j.BudgetAED = math.Round(b*100) / 100
		what = append(what, fmt.Sprintf("budget → AED %.0f/day", j.BudgetAED))
	}
	if e.ProductIDs != nil {
		a := ac.app
		a.mu.RLock()
		shop := a.shopSnap
		a.mu.RUnlock()
		fs, _, _ := ac.filteredShop(shop)
		ok := model.Allowed(fs)
		if len(e.ProductIDs) > 10 {
			return errors.New("at most 10 products")
		}
		for _, id := range e.ProductIDs {
			if !ok[id] {
				return fmt.Errorf("product %s is not allowed by your playbook (or not in the shop)", id)
			}
		}
		j.ProductIDs = uniq(e.ProductIDs)
		j.ProductsGone = false
		if j.Waiting == msgJobAvoided {
			j.Waiting = ""
		}
		if j.Kind == "occasion" && len(j.ProductIDs) > 0 { // its prepared ads follow the products (picker.go)
			ctx, cancel := context.WithTimeout(context.Background(), 3*time.Minute)
			ac.occasionToProducts(ctx, j)
			cancel()
		}
		what = append(what, fmt.Sprintf("products → %s", strings.Join(j.ProductIDs, ", ")))
	}
	if e.Lang != nil {
		if *e.Lang != "ar" && *e.Lang != "en" {
			return errors.New("lang: ar or en")
		}
		j.Lang = *e.Lang
		what = append(what, "language → "+j.Lang)
	}
	if e.Format != nil {
		if *e.Format != "image" && *e.Format != "carousel" {
			return errors.New("format: image or carousel")
		}
		j.Format = *e.Format
		what = append(what, "format → "+j.Format)
	}
	if e.CopyDirection != nil {
		j.CopyDirection = cleanNote(*e.CopyDirection)
		what = append(what, "copy direction → "+j.CopyDirection)
	}
	if e.Note != nil {
		j.Note = cleanNote(*e.Note)
		what = append(what, "your note → "+j.Note)
	}
	if len(what) == 0 {
		return errors.New("nothing to change")
	}
	j.Edited = true
	j.logf(now, "you edited it: %s", strings.Join(what, "; "))
	return nil
}

// runJobNow: the owner's Approve on a due job: materialise and run it through the executor now (as Approvals does
// for one proposal), whatever the mode, with every check.
func (ac *Acct) runJobNow(ctx context.Context, id string) (map[string]any, int, error) {
	st := ac.Settings()
	switch {
	case !st.Automated:
		return nil, 409, errors.New("this account is not automated by the app (Portfolio → switch it on first)")
	case !st.Limits.Set():
		return nil, 409, errors.New("set the daily and monthly limits first: nothing launches without them")
	case st.Limits.Autopilot == model.AutopilotOff:
		return nil, 409, errors.New("the autopilot is off for this account (Practice or Auto runs approvals)")
	}
	if id == jobFirstLaunch { // the first launch is the waiting starter set: run it as Approvals would
		ps := ac.Pending()
		for _, it := range ps.Items {
			if strings.HasPrefix(it.ID, "starter-") && it.Kind == model.KindCreateAd && waiting(it.Status) {
				out, code, err := ac.Decide(ctx, it.ID, true)
				if err != nil {
					return nil, code, err
				}
				for _, it2 := range ac.Pending().Items {
					if strings.HasPrefix(it2.ID, "starter-") && it2.Kind == model.KindCreateAd && waiting(it2.Status) {
						if _, _, err := ac.Decide(ctx, it2.ID, true); err != nil {
							break
						}
					}
				}
				var res []executor.Result
				for _, it2 := range ac.Pending().Items {
					if strings.HasPrefix(it2.ID, "starter-") && it2.Result != nil {
						res = append(res, *it2.Result)
					}
				}
				ac.noteJobs(res, model.AutopilotAuto)
				return out, code, nil
			}
		}
		return nil, 409, errors.New("the first launch has nothing waiting (run a cycle first)")
	}
	if !ac.work.TryLock() {
		return nil, 409, errBusy
	}
	defer ac.work.Unlock()
	if err := ac.Sync(ctx); err != nil {
		return nil, 502, fmt.Errorf("cannot read the account before acting: %w", err)
	}
	in := ac.input()
	env := ac.env(model.AutopilotAuto)
	j := ac.jobByID(id)
	if j == nil {
		return nil, 404, errors.New("no such job")
	}
	ps, why := ac.materialise(ctx, in, *j, env)
	if why != "" {
		js := ac.jobSet()
		for i := range js.Jobs {
			if js.Jobs[i].ID == id {
				js.Jobs[i].Waiting = why
				js.Jobs[i].logf(ac.app.now(), "waiting: %s", why)
			}
		}
		ac.saveJobs(js)
		return nil, 409, errors.New("it has to wait: " + why)
	}
	js := ac.jobSet()
	for i := range js.Jobs {
		if js.Jobs[i].ID == id {
			js.Jobs[i].ProposalIDs = nil
			for _, p := range ps {
				js.Jobs[i].ProposalIDs = append(js.Jobs[i].ProposalIDs, p.ID)
			}
		}
	}
	ac.saveJobs(js)
	ac.mu.RLock()
	ex := ac.exec
	ac.mu.RUnlock()
	res := ex.Run(ctx, env, ps)
	ac.noteJobs(res, model.AutopilotAuto)
	ac.st.Append("approval", fmt.Sprintf("owner ran job %s: %d steps", id, len(res)), res)
	ac.dirty()
	counts := map[string]int{}
	for _, r := range res {
		counts[r.Status]++
	}
	return map[string]any{"ran": len(res), "counts": counts, "results": res}, 200, nil
}

// jobBrief: exactly what Claude gets for this job, read-only, plus where the owner's words go.
func (ac *Acct) jobBrief(j Job) map[string]any {
	in := ac.input()
	pb := *in.Playbook
	prods := ac.nextProducts(in, j, map[bool]int{true: 4, false: 1}[j.Format == "carousel"])
	var ids []string
	var titles []string
	for _, p := range prods {
		ids = append(ids, p.ID)
		titles = append(titles, p.Title)
	}
	brief := ownerCopyBrief(pb, strings.TrimSpace(strings.TrimSpace("Copy direction for this job: "+j.CopyDirection)+"\n"+j.Note))
	out := map[string]any{"job": j.ID, "products": titles, "owner_brief": brief}
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	if b, ok := br.(interface {
		CopyPrompt(model.CopyInput) (string, string)
	}); ok {
		sys, user := b.CopyPrompt(model.CopyInput{ProductIDs: ids, Langs: []string{"ar", "en"}, Angle: firstNonEmpty(j.CopyDirection, "authentic Korean skincare"), Brief: brief, Shop: in.Shop})
		out["copy_system"], out["copy_user"] = sys, user
	}
	if b, ok := br.(interface{ PlannerPrompt() string }); ok {
		out["planner_system"] = b.PlannerPrompt()
	}
	out["planner_owner_block"] = map[string]any{"instructions_verbatim": pb.Instructions, "notes_on_upcoming_jobs": ac.jobNotes()}
	return out
}

// holdRetargeting: the agency rule "scale retargeting only while its 7-day frequency is under ~3", in code: a budget
// raise on the app's catalog campaign is dropped (logged as held) when the campaign's frequency is at the line.
func holdRetargeting(ps []model.Proposal, in model.CycleInput, loc *time.Location) []model.Proposal {
	f := findFunnel(in.Tree, in.Audiences)
	if f.catalog == nil || in.Playbook == nil {
		return ps
	}
	from := in.Now.In(loc).AddDate(0, 0, -6).Format("2006-01-02")
	_, _, freq := purchases7(in.Metrics, f.catalog.ID, from)
	if freq < in.Playbook.Knobs.RetargetFreq() {
		return ps
	}
	var out []model.Proposal
	for _, p := range ps {
		if p.Kind == model.KindSetBudget && p.Target == f.catalog.ID {
			if b, ok := p.Params["daily_budget"].(float64); ok && b > f.catalog.DailyBudgetAED {
				continue
			}
		}
		out = append(out, p)
	}
	return out
}

func countKind(ps []model.Proposal, kind string) int {
	n := 0
	for _, p := range ps {
		if p.Kind == kind {
			n++
		}
	}
	return n
}
