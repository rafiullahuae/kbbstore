package brain

// Cloud creation, the brain's half (the engine's half is engine/exchange_wire.go): a creation request raised in
// "With me in Claude (Max)" mode is ALSO a job for the scheduled cloud Claude Code task, so ads get made while the
// owner's Mac is closed. Here: the job's brief (catalogue facts, the Playbook, the linter's rules, lessons — never a
// key, a customer or an order), and the import of what comes back as an authored Create-with-Claude session
// (Source "cloud_max"), the request marked done with a "Made in the cloud on your Max plan" notice.

import (
	"fmt"
	"regexp"
	"strings"
	"time"

	"kaa/exchange"
	"kaa/model"
)

// CreationChanged: told when requests appear or close (the engine's exchange sync pushes / withdraws at once).
var CreationChanged func(acct string)

// CreationRequests: the account's requests, newest first (a copy).
func (e *Engine) CreationRequests(acct string) []CreationRequest {
	e.mu.Lock()
	defer e.mu.Unlock()
	return e.creations(acct)
}

// SetCloud: a request's cloud state moves (no-op for an unknown id). The open tabs see it at once.
func (e *Engine) SetCloud(acct, id, state, by string) {
	e.mu.Lock()
	reqs := e.creations(acct)
	ch := false
	for i := range reqs {
		if reqs[i].ID == id && (reqs[i].Cloud != state || (by != "" && reqs[i].CloudBy != by)) {
			reqs[i].Cloud, reqs[i].CloudAt = state, time.Now().UTC()
			if by != "" {
				reqs[i].CloudBy = by
			}
			ch = true
		}
	}
	if ch {
		e.saveCreations(acct, reqs)
	}
	e.mu.Unlock()
	if ch {
		e.push(acct)
	}
}

// AddCreationRequest: a request as a cycle would raise it (the -mock self-test and tests use it).
func (e *Engine) AddCreationRequest(acct string, min model.CycleInput, productIDs []string, angle string) CreationRequest {
	in := e.FromModel(min)
	r := newCreationRequest(CopyRequest{ProductIDs: productIDs, Angle: angle}, in, 0)
	e.mu.Lock()
	reqs := append([]CreationRequest{r}, e.creations(acct)...)
	e.saveCreations(acct, reqs)
	e.mu.Unlock()
	e.push(acct)
	return r
}

// CloudWording: the account's linter mode and whether a promotion is approved (what an import is checked with).
func (e *Engine) CloudWording(acct string, l model.Limits) (string, bool) {
	st := e.settings(acct)
	return Wording(l, st), st.PromoApproved
}

var (
	tagRe   = regexp.MustCompile(`<[^>]*>`)
	ingreds = []string{"centella", "cica", "niacinamide", "rice", "snail", "heartleaf", "propolis", "hyaluronic", "ceramide", "retinol", "vitamin c", "aha", "bha", "pha", "green tea", "mugwort", "ginseng", "collagen", "peptide", "panthenol", "tea tree", "aloe", "honey", "madecassoside", "salicylic", "birch", "artemisia", "houttuynia", "probiotic", "squalane", "spf"}
)

func cloudProduct(p Product, trending bool) exchange.Product {
	low := strings.ToLower(p.Title + " " + p.Type)
	var found []string
	for _, k := range ingreds {
		if strings.Contains(low, k) {
			found = append(found, k)
		}
	}
	facts := "Category: " + p.Type
	if len(found) > 0 {
		facts += ". Named in the title: " + strings.Join(found, ", ")
	}
	if d := strings.TrimSpace(tagRe.ReplaceAllString(p.Description, " ")); d != "" {
		facts += ". " + truncate(d, 900)
	}
	out := exchange.Product{ID: p.ID, Title: p.Title, Brand: p.Brand, Category: p.Type, PriceAED: p.PriceAED, SaleAED: p.SalePrice,
		PriceEN: fmt.Sprintf("AED %.0f", p.PriceAED), PriceAR: fmt.Sprintf("%.0f درهم", p.PriceAED), Facts: facts, Photo: p.Image, URL: p.URL, Trending: trending}
	for _, u := range p.Images {
		if len(out.Photos) < 3 && u != p.Image {
			out.Photos = append(out.Photos, u)
		}
	}
	return out
}

// BrandColours: app/js/creative/art.js BRAND.
var BrandColours = map[string]string{"rose": "#C6395F", "roseDeep": "#8E2445", "plum": "#4A1830", "blush": "#FCE8EE", "petal": "#F6C9D6", "cream": "#FFF8F4", "gold": "#C9A15B", "goldLight": "#EBD3A2", "ink": "#2B1F26", "ink2": "#5F4E57", "white": "#FFFFFF"}

const cloudOutput = `Write results/<account>/<job_id>/ads.json as {"job_id", "worker", "ideas": [IDEA...]} where IDEA is
{"product_ids": [ids from products/more_products], "angle", "hook", "template": one of design_families, "palette": [] or 2-4 hex colours,
 "show_price": bool, "formats": the brief's formats, "motion": bool (true on ONE idea when the brief asks for motion), "scene": "",
 "sets": [{"lang": "ar"|"en", "headline" (<=40 chars, the benefit), "primary_text" (2-4 short lines), "sub" (the hero ingredient, <=40),
 "bullets": [2-3 short benefit lines], "badge" (brand or a short claim, never a % or amount off), "cta": "SHOP_NOW", "hooks": [2 first-2-second hooks]}]}.
One set per language in langs, Arabic first and written (not translated). Then run: node cloud/worker.mjs render (it draws every format with the
app's own renderer, runs the quality gate and fills "renders"), then node cloud/worker.mjs finish.`

// CloudBrief: the job for one request. Only catalogue facts, the owner's own instructions and aggregates.
func (e *Engine) CloudBrief(acct string, min model.CycleInput, r CreationRequest) exchange.Brief {
	in := e.FromModel(min)
	bm := e.brief(acct, in)
	st := e.settings(acct)
	trend := map[string]bool{}
	for _, id := range e.trending().Selling {
		trend[id] = true
	}
	b := exchange.Brief{Version: 1, JobID: r.ID, Account: acct, At: time.Now().UTC(), Title: r.Title, TitleAR: r.TitleAR, Count: max(1, r.Count),
		Langs: []string{"ar", "en"}, Formats: []string{"1:1", "4:5", "9:16"}, Motion: r.Format == "Reels", Angle: r.Angle, Extra: r.Brief,
		DesignFamilies: []string{"hero", "glow", "ingredient", "offer", "routine", "editorial", "petals", "texture", "qa", "collection"},
		BrandColours:   BrandColours, Output: cloudOutput, Offers: []string{}}
	if r.Lang == "ar" || r.Lang == "en" {
		b.Langs = []string{r.Lang}
	}
	have := map[string]bool{}
	for _, p := range productsByID(in.Shop, r.ProductIDs) {
		b.Products = append(b.Products, cloudProduct(p, trend[p.ID]))
		have[p.ID] = true
	}
	if top, ok := bm["products_to_push"].([]map[string]any); ok {
		for _, t := range top {
			id, _ := t["id"].(string)
			for _, p := range productsByID(in.Shop, []string{id}) {
				if !have[p.ID] && p.InStock && p.Image != "" && len(b.MoreProducts) < 6 {
					b.MoreProducts = append(b.MoreProducts, cloudProduct(p, trend[p.ID]))
					have[p.ID] = true
				}
			}
		}
	}
	if in.Playbook != nil {
		b.OwnerInstructions = in.Playbook.Instructions
		b.Offers = append(b.Offers, in.Playbook.OfferFacts()...)
	}
	b.Linter = exchange.Linter{Mode: in.Limits.AdWording, PromoApproved: st.PromoApproved}
	if rules, ok := bm["brand_rules"].([]string); ok {
		b.Linter.Rules = rules
	}
	if l, ok := bm["lessons"].(string); ok {
		b.Lessons = l
	}
	if notes, ok := bm["research"].([]map[string]any); ok {
		for _, n := range notes {
			b.Research = append(b.Research, fmt.Sprintf("%v: %v — angles %v", n["topic"], n["title"], n["angles"]))
		}
	}
	for _, s := range e.sessions(acct) { // what the owner kept running before: winning patterns, not numbers per customer
		if s.Outcome != nil && s.Outcome.Verdict == "won" && len(s.Ideas) > 0 && len(b.Winning) < 5 {
			x := s.Ideas[0]
			b.Winning = append(b.Winning, fmt.Sprintf("%s design, angle %q, hook %q (ROAS %.1f)", x.Template, x.Angle, x.Hook, s.Outcome.ROAS))
		}
	}
	return b
}

// CloudSetProblems: why a set's copy cannot ship as drawn. A blocked line, and also a line the linter would REWRITE:
// the picture already carries the old words, so a rewrite here would ship text that differs from the image.
func CloudSetProblems(s CreateSet, wording string, promo bool) []string {
	o := LintOptions{PromoApproved: promo, Lang: s.Lang, Strictness: wording}
	a, d := setFields(s)
	var out []string
	for _, lr := range []LintResult{Lint(a, o), Lint(d, o)} {
		for _, f := range lr.Findings {
			if f.Severity == "block" || f.Severity == "rewrite" {
				out = append(out, fmt.Sprintf("%s %s %q: %s", s.Lang, f.Field, f.Match, f.Why))
			}
		}
	}
	return out
}

// ImportCloud: the ideas that passed the Mac's checks become an authored session (Creatives → Made with Claude),
// the request is done, and the owner is told "Made in the cloud on your Max plan".
func (e *Engine) ImportCloud(acct, reqID, by string, ideas []CreateIdea, saved []string, min model.CycleInput) (CreateSession, error) {
	if len(ideas) == 0 {
		return CreateSession{}, fmt.Errorf("nothing passed the checks")
	}
	if s := e.SessionByRequest(acct, reqID); s != nil && s.Source == "cloud_max" {
		return *s, nil // imported before (idempotent)
	}
	shop := e.FromModel(min).Shop
	now := time.Now().UTC()
	s := CreateSession{ID: newSessionID(), At: now, Updated: now, Prompt: "made in the cloud", Source: "cloud_max", RequestID: reqID, Key: "cloud-" + reqID,
		Ideas: ideas, Saved: saved, Turns: []CreateTurn{{At: now, Text: "made in the cloud on your Max plan (" + by + ")", Kind: "prompt"}}}
	s.Designs = designsFor(s.ID, ideas, shop, false)
	e.putSession(acct, s)
	title, titleAR := "", ""
	e.mu.Lock()
	reqs := e.creations(acct)
	for i := range reqs {
		if reqs[i].ID == reqID {
			r := &reqs[i]
			if openReq(r.Status) {
				r.Status, r.DoneAt = "done", now
			}
			r.SessionID, r.Cloud, r.CloudAt, r.CloudBy, r.CloudN = s.ID, "imported", now, by, len(saved)
			title, titleAR = r.Title, r.TitleAR
		}
	}
	e.saveCreations(acct, reqs)
	e.mu.Unlock()
	if title == "" {
		title, titleAR = "New ads", "إعلانات جديدة"
	}
	e.notify(acct, Notice{Kind: "cloud_done", Title: "Made in the cloud on your Max plan", Req: reqID,
		Body:   fmt.Sprintf("%d ads ready for \"%s\": Creatives → Made with Claude.", len(saved), title),
		BodyAR: fmt.Sprintf("%d إعلانات جاهزة لـ «%s»: الإعلانات ← صُنعت مع Claude.", len(saved), titleAR)})
	return s, nil
}

// SessionByRequest: the session that answered a request (nil when none).
func (e *Engine) SessionByRequest(acct, reqID string) *CreateSession {
	e.mu.Lock()
	defer e.mu.Unlock()
	for _, s := range e.sessions(acct) {
		if s.RequestID == reqID {
			c := s
			return &c
		}
	}
	return nil
}
