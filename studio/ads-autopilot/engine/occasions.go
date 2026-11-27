package main

// occasions.go — the Occasions layer (the owner, 20 Nov 2026: "the system must sense the upcoming UAE occasions ...
// and prepare the ads in advance and give me for approval").
//
// At an occasion's prep date (its lead time before the start) the engine builds the occasion package as a job of
// kind "occasion": products that fit (the occasion's keywords on the ranked, playbook-filtered list), the creatives
// prepared NOW in Arabic and English (Claude's copy with the occasion brief, or the benefit-first template), a budget
// ramp (one step up, ≤ the owner's step %, 3 days before the start), the dates and the audience. In "With me in
// Claude" creation mode it also raises a creation request with the occasion brief. The package waits in Approvals →
// Occasions: NOTHING seasonal goes live without his approval, in Auto too, unless he ticked "auto-approve occasions".
// He is notified at prep time and reminded 7 and 3 days before the start while it is not approved.

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"time"

	"kaa/executor"
	"kaa/model"
)

// occasionCreative: a library creative for one product, language and occasion (its own id, its own copy).
func (ac *Acct) occasionCreative(p model.Product, lang, occ string, copyOf func(model.Product) map[string][2]string) (string, error) {
	id := "oc-" + strings.Trim(pidSafe.ReplaceAllString(strings.ToLower(occ+"-"+p.ID), "-"), "-") + "-" + lang
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
		Angle: "occasion: " + occ, Source: "brain", ImageURL: p.Image}
	if _, err := ac.creatives.Add(cr, ac.app.now()); err != nil {
		return "", err
	}
	return id, nil
}

// occasionProducts: the occasion's top n products (slots.go: fit to its season × units sold, new, trending; pinned
// first; the shop is already Playbook-filtered), leaving out the ones in skip, each usable for an ad.
func occasionProducts(in model.CycleInput, o model.Occasion, n int, trending []string, skip map[string]bool) []model.Product {
	by := map[string]model.Product{}
	for _, p := range in.Shop.Products {
		by[p.ID] = p
	}
	var out []model.Product
	for _, r := range model.RankForOccasion(in.Shop.Products, *in.Playbook, o, model.RankSignals{Now: in.Now, Trending: trending, Baseline: firstSeenBaseline(in.Shop)}) {
		if p := by[r.ID]; len(out) < n && !skip[r.ID] && usable(p) {
			out = append(out, p)
		}
	}
	return out
}

// refreshOccasions: occasion jobs whose prep date has come (lead time before the start), prepared once.
func (ac *Acct) refreshOccasions(ctx context.Context, in model.CycleInput, js *JobSet, now time.Time) {
	if in.Playbook == nil {
		return
	}
	pb := *in.Playbook
	byID := map[string]model.Occasion{}
	for _, o := range pb.OccasionsOf() {
		byID[o.ID] = o
	}
	ac.app.mu.RLock()
	br, claudeOK := ac.app.brain, ac.app.conns.Claude.OK
	ac.app.mu.RUnlock()
	today := now.In(ac.loc()).Format("2006-01-02")
	for _, inst := range pb.UpcomingOccasions(now, 70, in.Limits.Countries) {
		o := byID[inst.Occasion]
		if today < inst.PrepFrom || today > inst.End || strings.Contains(o.Angle, "no sales push") || strings.Contains(o.Angle, "none (low-key)") {
			continue
		}
		id := "occ-" + inst.ID
		exists := false
		for _, j := range js.Jobs {
			exists = exists || j.ID == id
		}
		if exists {
			continue
		}
		start, _ := time.ParseInLocation("2006-01-02", inst.Start, ac.loc())
		when := start.AddDate(0, 0, -3).Add(9 * time.Hour)
		if when.Before(now) {
			when = now
		}
		prods := occasionProducts(in, o, pb.Slots(), ac.trendingIDs(), nil)
		brief := fmt.Sprintf("Occasion: %s (%s, %s to %s%s). Angle: %s. Tone: %s.", inst.Name, inst.NameAR, inst.Start, inst.End, map[bool]string{true: ", date estimated", false: ""}[inst.Estimated], o.Angle, o.Tone)
		if o.Offer != "" {
			brief += " The owner's offer for it: " + o.Offer + "."
		}
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
			out, err := cw.Copy(cctx, model.CopyInput{ProductIDs: []string{p.ID}, Langs: []string{"ar", "en"}, Angle: o.Angle, Brief: ownerCopyBrief(pb, brief), Shop: in.Shop, Research: in.Research})
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
		var crs, pids []string
		for _, p := range prods {
			pids = append(pids, p.ID)
			for _, lang := range pb.Languages {
				if cid, err := ac.occasionCreative(p, lang, o.ID, copyOf); err == nil {
					crs = append(crs, cid)
				}
			}
		}
		days := int(start.Sub(now).Hours()/24 + 0.5)
		title, titleAR := fmt.Sprintf("%s in %d days — ads ready for your approval", inst.Name, days), fmt.Sprintf("%s بعد %d يوماً — الإعلانات جاهزة لموافقتك", inst.NameAR, days)
		if days <= 0 {
			title, titleAR = fmt.Sprintf("%s (on now, until %s) — ads ready for your approval", inst.Name, inst.End), fmt.Sprintf("%s (جارٍ حتى %s) — الإعلانات جاهزة لموافقتك", inst.NameAR, inst.End)
		}
		j := Job{ID: id, Kind: "occasion", Title: title, TitleAR: titleAR,
			Why: brief, When: when, Status: "planned", ProductIDs: pids, Lang: strings.Join(pb.Languages, "+"), Format: "image", CopyDirection: o.Angle,
			Audience: map[bool]string{true: "its own new campaign with its own budget (Advantage+ audience, no bid cap)", false: "the prospecting campaign (Advantage+ audience), budget one step up from 3 days before"}[ownCampaign(&pb, "occasion")], Source: "occasion", Target: inst.ID, Creatives: crs, Occasion: &inst, Slots: pb.Slots(), UpdatedAt: now.UTC()}
		j.logf(now, "prepared %d ads for %s (%s → %s%s); waiting for your approval", len(crs), inst.Name, inst.Start, inst.End, map[bool]string{true: ", estimated date", false: ""}[inst.Estimated])
		js.Jobs = append(js.Jobs, j)
		ac.notifyOwnerRef(j.ID, j.Title, fmt.Sprintf("%s starts %s. Open Approvals → Occasions.", inst.Name, inst.Start), fmt.Sprintf("%s يبدأ %s. افتحي الموافقات ← المناسبات.", inst.NameAR, inst.Start))
		if c, ok := br.(interface {
			OccasionCreation(acct, title, titleAR, brief string, productIDs []string) string
		}); ok {
			if rid := c.OccasionCreation(ac.id, inst.Name+" ads", inst.NameAR, ownerCopyBrief(pb, brief), pids); rid != "" {
				j.logf(now, "creation request %s raised (With me in Claude)", rid)
			}
		}
		ac.st.Append("occasion", fmt.Sprintf("%s: package prepared (%d ads), waiting for approval", inst.Name, len(crs)), j)
	}
	// reminders 7 and 3 days before the start while not approved
	for i := range js.Jobs {
		j := &js.Jobs[i]
		if j.Kind != "occasion" || j.Status != "planned" || j.Occasion == nil {
			continue
		}
		start, _ := time.ParseInLocation("2006-01-02", j.Occasion.Start, ac.loc())
		left := int(start.Sub(now).Hours()/24 + 0.5)
		for _, d := range []int{7, 3} {
			if left <= d && left > 0 && !containsInt(j.Reminded, d) {
				j.Reminded = append(j.Reminded, d)
				j.logf(now, "reminder: %d days left, not approved yet", left)
				ac.notifyOwnerRef(j.ID, fmt.Sprintf("%s in %d days — not approved yet · %s بعد %d أيام — لم تتم الموافقة بعد", j.Occasion.Name, left, j.Occasion.NameAR, left), "Approvals → Occasions", "الموافقات ← المناسبات")
			}
		}
	}
}

func containsInt(xs []int, x int) bool {
	for _, v := range xs {
		if v == x {
			return true
		}
	}
	return false
}

// notifyOwner: the Mac notification (through the brain's notifier) + an Overview alert.
func (ac *Acct) notifyOwner(title, body, bodyAR string) { ac.notifyOwnerRef("", title, body, bodyAR) }

// notifyOwnerRef: the same, tied to an occasion package: approving it takes the notice and the alert away
// (the owner, 21 Nov: "X in N days — not approved yet" stayed after he approved it).
func (ac *Acct) notifyOwnerRef(ref, title, body, bodyAR string) {
	ac.alert("info", title+" — "+body)
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	if n, ok := br.(interface {
		NotifyRef(acct, ref, title, body, bodyAR string)
	}); ok && ref != "" {
		n.NotifyRef(ac.id, ref, title, body, bodyAR)
	} else if n, ok := br.(interface {
		Notify(acct, title, body, bodyAR string)
	}); ok {
		n.Notify(ac.id, title, body, bodyAR)
	}
}

// occasionSettled: the package was approved -- its "ready for your approval" / "not approved yet" notices and
// Overview alerts go at once. Returns how many went.
func (ac *Acct) occasionSettled(j Job) int {
	if j.Occasion == nil {
		return 0
	}
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	k := 0
	if c, ok := br.(interface{ ClearNotices(acct, ref string) int }); ok {
		k += c.ClearNotices(ac.id, j.ID)
	}
	name := j.Occasion.Name
	ac.mu.Lock()
	kept := ac.alerts[:0:0]
	for _, al := range ac.alerts {
		if name != "" && strings.HasPrefix(al.Text, name) && (strings.Contains(al.Text, "not approved yet") || strings.Contains(al.Text, "ready for your approval")) {
			k++
			continue
		}
		kept = append(kept, al)
	}
	ch := len(kept) != len(ac.alerts)
	if ch {
		ac.alerts = kept
		ac.stateJSON = nil
	}
	alerts := append([]model.Alert(nil), ac.alerts...)
	ac.mu.Unlock()
	if ch {
		_ = ac.st.Save("alerts", alerts)
		ac.publish("alerts", alerts)
	}
	return k
}

// occasionProposals: the approved package → ads (rejected ads left out) in its own campaign, or (the old way) into
// the prospecting ad set + the ramp.
// Since 0.6.23 the package is a campaign of its own with its own budget (runcampaign.go), unless he chose the old way.
func (ac *Acct) occasionProposals(in model.CycleInput, j Job, env executor.Env, pid func(string) string) ([]model.Proposal, string) {
	stepPct := env.Limits.MaxBudgetStepPct
	f := findFunnel(in.Tree, in.Audiences)
	own := ownCampaign(in.Playbook, j.Kind)
	var budget float64
	if own {
		b, why := ac.runBudget(in, j, env)
		if why != "" {
			return nil, why
		}
		budget = b
	} else if f.prosp == nil {
		return nil, "needs the prospecting campaign first (the first launch)"
	}
	var langs []string
	seenL := map[string]bool{}
	rej := map[string]bool{}
	for _, r := range j.Rejected {
		rej[r] = true
	}
	var ps []model.Proposal
	for i, cid := range j.Creatives {
		if rej[cid] {
			continue
		}
		c, _, err := ac.creatives.Get(cid)
		if err != nil {
			continue
		}
		l := strings.ToLower(c.Lang)
		if l != "en" {
			l = "ar"
		}
		set := "{set:" + l + "}"
		if !own {
			if set = ac.prospectingSet(in, c.Lang); set == "" {
				return nil, "needs the prospecting ad set first"
			}
		} else if !seenL[l] {
			seenL[l] = true
			langs = append(langs, l)
		}
		ps = append(ps, model.Proposal{ID: pid(fmt.Sprintf("cr%d", i)), Kind: model.KindUploadCreative, Target: cid,
			Params: map[string]any{"creative_id": cid, "format": "image", "headline": c.Headline, "primary_text": c.PrimaryText}, Why: j.Title, Confidence: 0.65},
			model.Proposal{ID: pid(fmt.Sprintf("ad%d", i)), Kind: model.KindCreateAd, Target: fmt.Sprintf("tmp:j%x-oc%d", hash32(j.ID)&0xffffff, i),
				Params: map[string]any{"name": fmt.Sprintf("Occasion %s · %s", strings.ToUpper(c.Lang), shortTitleOf(c)), "adset_id": set, "creative_id": cid}, Why: j.Title, Confidence: 0.65})
	}
	if len(ps) == 0 {
		return nil, "every ad of the package was rejected"
	}
	if own { // its own campaign carries its own budget: no ramp of an earlier campaign
		camp, sets := ac.runCampaign(in, j, env, pid, langs, budget, nil)
		for i := range ps {
			if s, ok := ps[i].Params["adset_id"].(string); ok && strings.HasPrefix(s, "{set:") {
				ps[i].Params["adset_id"] = sets[strings.TrimSuffix(strings.TrimPrefix(s, "{set:"), "}")]
			}
		}
		return append(camp, ps...), ""
	}
	if stepPct <= 0 {
		stepPct = 20
	}
	nb := f.prosp.DailyBudgetAED * (1 + stepPct/100)
	if j.BudgetAED > 0 && j.BudgetAED < nb {
		nb = j.BudgetAED
	}
	ps = append(ps, model.Proposal{ID: pid("ramp"), Kind: model.KindSetBudget, Target: f.prosp.ID, Params: map[string]any{"daily_budget": float64(int(nb*100)) / 100},
		Why: fmt.Sprintf("Occasion ramp: one step up (≤ %.0f%%) 3 days before the start.", stepPct), Confidence: 0.6})
	return ps, ""
}

func shortTitleOf(c model.Creative) string {
	t := c.Headline
	if r := []rune(t); len(r) > 40 {
		t = string(r[:40])
	}
	return t
}

// occasionsAPI: GET /api/occasions — the calendar for the next 400 days with the account's packages.
func (s *server) occasionsAPI(w http.ResponseWriter, r *http.Request, ac *Acct) {
	if r.Method != http.MethodGet {
		jsonErr(w, 405, "GET")
		return
	}
	ac.attachRegen() // designs saved for a Regenerate join their package before it is shown (regen.go)
	pb := ac.playbook()
	st := ac.Settings()
	now := s.app.now()
	var pkgs []Job
	for _, j := range ac.jobSet().Jobs {
		if j.Kind == "occasion" {
			pkgs = append(pkgs, j)
		}
	}
	if pkgs == nil {
		pkgs = []Job{}
	}
	lib := map[string]model.Creative{}
	for _, c := range ac.creatives.all() {
		lib[c.ID] = c
	}
	ads := map[string][]model.Creative{}
	for _, j := range pkgs {
		for _, cid := range j.Creatives {
			if c, ok := lib[cid]; ok {
				ads[j.ID] = append(ads[j.ID], c)
			}
		}
	}
	all := pb
	all.Occasions = pb.OccasionsOf()
	upcoming := []model.OccasionInstance{}
	for _, o := range all.Occasions {
		if o.Applies(st.Limits.Countries) {
			upcoming = append(upcoming, o.Instances(now, 400)...)
		}
	}
	picks := map[string][]model.SlotPick{}
	if len(pkgs) > 0 {
		s.app.mu.RLock()
		shop := s.app.shopSnap
		s.app.mu.RUnlock()
		fs, fpb, _ := ac.filteredShop(shop) // only what the chips need (not the whole cycle input)
		in, tr := model.CycleInput{Now: now, Shop: fs, Playbook: &fpb}, ac.trendingIDs()
		for _, j := range pkgs {
			if open(j.Status) {
				picks[j.ID] = ac.slotPicks(in, j, tr)
			}
		}
	}
	writeJSON(w, map[string]any{"account": ac.id, "occasions": all.Occasions, "upcoming": upcoming, "packages": pkgs, "ads": ads, "titles": ac.titlesOf(pkgs),
		"picks": picks, "slots": pb.Slots(),
		"auto_approve": pb.AutoApproveOccasions, "countries": st.Limits.Countries, "defaults": model.DefaultOccasions()})
}

// Refresh: what the warm-up re-evaluates when no cycle is due (QA, 20 Nov 2026): the occasion packages whose prep
// date came while the Mac was closed, their reminders, and the brain's "creation due soon". Nothing is planned or
// written to Meta.
func (ac *Acct) Refresh(ctx context.Context) {
	if !ac.work.TryLock() { // a cycle is running: it re-evaluates all of this itself
		return
	}
	defer ac.work.Unlock()
	in := ac.input()
	js := ac.jobSet()
	ac.refreshOccasions(ctx, in, &js, ac.app.now())
	ac.saveJobs(js)
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	if r, ok := br.(interface {
		Refresh(context.Context, string, time.Time)
	}); ok {
		r.Refresh(ctx, ac.id, ac.app.now())
	}
}
