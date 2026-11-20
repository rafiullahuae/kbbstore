package brain

// learn.go — the self-learning loop (the owner, 20 Nov 2026: "i want this system to be self learning and improve
// itself day by day"). All of it is code and numbers; Claude only reads the lessons.
//
//  1. Every launch / pause / budget move the brain proposes is RECORDED with the prediction it made (expected CPA,
//     CTR), keyed by the creative it runs, its angle, format, language and products.
//  2. After the attribution window (7 days + 2 settling) each prediction is SCORED against the settled numbers of
//     the ads that ran it, and the outcome updates the account's PRIORS: a Gamma–Poisson-style posterior CPA per
//     cell (angle, format, language, product, angle×language×format), shrunk to the account's target.
//  3. The next cycle uses them: predictions come from the posteriors (so the error falls as data comes in), the
//     product ranking gets a learned boost, the rules plan gives ~80% of new concepts to winning patterns and keeps
//     ~20% for exploration, and the planner and copy briefs get a compact LESSONS section.
//  4. It back-tests its own stop threshold (the zero-purchase stop multiple) and the tiring frequency on the
//     account's ad history and moves them only inside the owner's bounds (Playbook "Let it tune its own rules":
//     off | within bounds (default) | ask me). Every change is logged with its evidence and is reversible.
//  5. A weekly "What I learned and changed" report (AR + EN) and a learning-health series (prediction error by week).

import (
	"fmt"
	"math"
	"sort"
	"strings"
	"time"

	"kaa/model"
)

type Pred struct {
	ID         string    `json:"id"`
	At         time.Time `json:"at"`
	Kind       string    `json:"kind"` // launch | pause | scale | cut
	Object     string    `json:"object"`
	CreativeID string    `json:"creative_id,omitempty"` // library id (launches)
	ProductIDs []string  `json:"product_ids,omitempty"`
	Angle      string    `json:"angle,omitempty"`
	Format     string    `json:"format,omitempty"`
	Lang       string    `json:"lang,omitempty"`
	ExpCPA     float64   `json:"expected_cpa"`
	Due        time.Time `json:"due"`
	Scored     bool      `json:"scored"`
	ScoredAt   time.Time `json:"scored_at,omitempty"`
	Spend      float64   `json:"spend,omitempty"`
	Purch      float64   `json:"purchases,omitempty"`
	ActCPA     float64   `json:"actual_cpa,omitempty"`
	Err        float64   `json:"abs_log_error,omitempty"` // |ln(expected / actual)|
	Outcome    string    `json:"outcome,omitempty"`       // win | loss | even
}

type Prior struct {
	Spend float64 `json:"spend"`
	Purch float64 `json:"purchases"`
	N     int     `json:"n"`
}

// Post: the posterior CPA of a cell, shrunk toward the prior CPA with k pseudo-purchases.
func (p Prior) Post(priorCPA float64) float64 {
	const k = 2
	return (p.Spend + k*priorCPA) / (p.Purch + k)
}

type Tuned struct {
	HardStopMult float64   `json:"hard_stop_mult,omitempty"`
	FatigueFreq  float64   `json:"fatigue_freq,omitempty"`
	At           time.Time `json:"at,omitempty"`
	Evidence     []string  `json:"evidence,omitempty"`
	Applied      bool      `json:"applied"` // false in "ask me": proposed only
}

type WeekHealth struct {
	Week    string  `json:"week"`
	Scored  int     `json:"scored"`
	MeanErr float64 `json:"mean_abs_log_error"`
	Hit     float64 `json:"within_30pct"` // share of predictions within ±30% of the outcome
}

type Lesson struct {
	Cell    string  `json:"cell"` // e.g. "angle=ingredient | lang=ar | format=carousel"
	Kind    string  `json:"kind"` // win | loss
	CPA     float64 `json:"cpa"`
	Target  float64 `json:"target"`
	N       int     `json:"ads"`
	Text    string  `json:"text"`
	TextAR  string  `json:"text_ar"`
	Dim     string  `json:"dim"`
	Value   string  `json:"value"`
	Purch   float64 `json:"purchases"`
	SpendAE float64 `json:"spend"`
}

type WeeklyReport struct {
	Week    string    `json:"week"`
	At      time.Time `json:"at"`
	EN      string    `json:"en"`
	AR      string    `json:"ar"`
	Lessons []Lesson  `json:"lessons"`
	Changes []string  `json:"changes"`
	Next    []string  `json:"next"`
}

type Ledger struct {
	Preds   []Pred           `json:"predictions"`
	Priors  map[string]Prior `json:"priors"`
	Tuned   Tuned            `json:"tuned"`
	Changes []string         `json:"changes"` // threshold changes, newest last, with evidence
	Reports []WeeklyReport   `json:"weekly"`
}

const scoreAfter = 9 * 24 * time.Hour // 7-day window + 2 settling days

func cellKeys(angle, format, lang string, products []string) []string {
	var ks []string
	norm := func(s string) string { return strings.ToLower(strings.TrimSpace(s)) }
	if a := norm(angle); a != "" {
		ks = append(ks, "angle="+a)
	}
	if f := norm(format); f != "" {
		ks = append(ks, "format="+f)
	}
	if l := norm(lang); l != "" {
		ks = append(ks, "lang="+l)
	}
	if angle != "" && lang != "" && format != "" {
		ks = append(ks, "combo="+norm(angle)+"|"+norm(lang)+"|"+norm(format))
	}
	for _, p := range products {
		ks = append(ks, "product="+p)
	}
	return ks
}

// Predict: the expected CPA of a new concept: the most specific cell with evidence, else the angle, else the
// language, else the account's target.
func (L *Ledger) Predict(angle, format, lang string, products []string, target float64) float64 {
	if L.Priors == nil {
		return target
	}
	order := []string{}
	ks := cellKeys(angle, format, lang, products)
	for _, k := range ks {
		if strings.HasPrefix(k, "combo=") {
			order = append([]string{k}, order...)
		}
	}
	for _, pre := range []string{"angle=", "product=", "format=", "lang="} {
		for _, k := range ks {
			if strings.HasPrefix(k, pre) {
				order = append(order, k)
			}
		}
	}
	for _, k := range order {
		if p, ok := L.Priors[k]; ok && p.N > 0 {
			return round2(p.Post(target))
		}
	}
	return target
}

// observe: one scored outcome into the priors.
func (L *Ledger) observe(p *Pred, spend, purch, target float64, now time.Time) {
	if L.Priors == nil {
		L.Priors = map[string]Prior{}
	}
	p.Scored, p.ScoredAt, p.Spend, p.Purch = true, now, round2(spend), purch
	p.ActCPA = round2((spend + 0.01) / math.Max(purch, 0.5)) // no purchase counts as half of one: a finite, honest CPA
	if p.ExpCPA > 0 && p.ActCPA > 0 {
		p.Err = round4(math.Abs(math.Log(p.ExpCPA / p.ActCPA)))
	}
	switch {
	case p.ActCPA <= 0.85*target:
		p.Outcome = "win"
	case p.ActCPA >= 1.3*target:
		p.Outcome = "loss"
	default:
		p.Outcome = "even"
	}
	if p.Kind != "launch" {
		return
	}
	for _, k := range cellKeys(p.Angle, p.Format, p.Lang, p.ProductIDs) {
		c := L.Priors[k]
		c.Spend, c.Purch, c.N = c.Spend+spend, c.Purch+purch, c.N+1
		L.Priors[k] = c
	}
}

// Record: the launches this cycle proposes, each with its prediction (pauses and budget moves are recorded too, so
// their outcome is checked).
func (L *Ledger) Record(in CycleInput, out CycleOutput) {
	lib := map[string]Creative{}
	for _, c := range in.Creatives {
		lib[c.ID] = c
	}
	have := map[string]bool{}
	for _, p := range L.Preds {
		have[p.ID] = true
	}
	tgt := out.Economics.TargetCPA
	for _, p := range out.Proposals {
		pr := Pred{ID: in.Account.ID + "|" + p.ID + "|" + in.Now.Format("060102"), At: in.Now, Object: p.Target, Due: in.Now.Add(scoreAfter)}
		switch p.Kind {
		case "create_ad":
			cr, _ := p.Params["creative_id"].(string)
			c, ok := lib[cr]
			if !ok {
				continue
			}
			pr.Kind, pr.CreativeID, pr.ProductIDs, pr.Angle, pr.Lang = "launch", c.ID, c.ProductIDs, c.Angle, c.Lang
			pr.Format, _ = p.Params["format"].(string)
			pr.ExpCPA = L.Predict(pr.Angle, pr.Format, pr.Lang, pr.ProductIDs, tgt)
		case "pause":
			pr.Kind, pr.ExpCPA = "pause", 1.3*tgt // a pause predicts the object would have kept missing the target
		case "set_budget":
			pr.Kind, pr.ExpCPA = map[bool]string{true: "scale", false: "cut"}[p.Params["direction"] == "up"], tgt
		default:
			continue
		}
		if !have[pr.ID] && tgt > 0 {
			L.Preds = append(L.Preds, pr)
		}
	}
	if len(L.Preds) > 2000 {
		L.Preds = L.Preds[len(L.Preds)-2000:]
	}
}

// Score: every due launch prediction against the settled numbers of the ads running its creative.
func (L *Ledger) Score(in CycleInput, target float64) int {
	byLib := map[string][]string{} // library creative id -> ad ids (the tree names Meta creative ids)
	libOf := map[string]string{}
	for _, c := range in.Creatives {
		libOf[c.ID] = c.ID
		if c.MetaID != "" {
			libOf[c.MetaID] = c.ID
		}
	}
	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				if id, ok := libOf[a.CreativeID]; ok {
					byLib[id] = append(byLib[id], a.ID)
				}
			}
		}
	}
	n := 0
	for i := range L.Preds {
		p := &L.Preds[i]
		if p.Scored || p.Kind != "launch" || in.Now.Before(p.Due) {
			continue
		}
		ads := map[string]bool{}
		for _, a := range byLib[p.CreativeID] {
			ads[a] = true
		}
		from, to := p.At.Format("2006-01-02"), p.Due.Format("2006-01-02")
		var spend, purch float64
		for _, r := range in.Metrics {
			if r.Level == "ad" && ads[r.ObjectID] && !r.Settling && r.Date >= from && r.Date <= to {
				spend += r.SpendAED
				purch += float64(r.Purchases)
			}
		}
		if spend <= 0 {
			if in.Now.Sub(p.Due) > 14*24*time.Hour { // never ran: closed without learning anything
				p.Scored, p.Outcome = true, "not_run"
			}
			continue
		}
		L.observe(p, spend, purch, target, in.Now)
		n++
	}
	return n
}

// Lessons: the cells that win or lose clearly (posterior CPA vs target, at least 2 ads).
func (L *Ledger) Lessons(target float64) []Lesson {
	var out []Lesson
	for k, p := range L.Priors {
		if p.N < 2 || strings.HasPrefix(k, "product=") {
			continue
		}
		post := p.Post(target)
		dim, val, _ := strings.Cut(k, "=")
		le := Lesson{Cell: k, CPA: round2(post), Target: round2(target), N: p.N, Dim: dim, Value: val, Purch: p.Purch, SpendAE: round2(p.Spend)}
		switch {
		case post <= 0.8*target:
			le.Kind = "win"
		case post >= 1.25*target:
			le.Kind = "loss"
		default:
			continue
		}
		word := map[string]string{"win": "wins", "loss": "loses"}[le.Kind]
		wordAR := map[string]string{"win": "يربح", "loss": "يخسر"}[le.Kind]
		le.Text = fmt.Sprintf("%s %s: CPA AED %.0f over %d ads (%.0f purchases, AED %.0f) vs target AED %.0f", strings.ReplaceAll(val, "|", " × "), word, post, p.N, p.Purch, p.Spend, target)
		le.TextAR = fmt.Sprintf("%s %s: تكلفة الطلب %.0f درهم عبر %d إعلانات مقابل المستهدف %.0f", strings.ReplaceAll(val, "|", " × "), wordAR, post, p.N, target)
		out = append(out, le)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Kind != out[j].Kind {
			return out[i].Kind == "win"
		}
		if out[i].Kind == "win" {
			return out[i].CPA < out[j].CPA
		}
		return out[i].CPA > out[j].CPA
	})
	return out
}

// ProductBoost: -1..1 per product from its own outcomes (the ranking's "learned" part).
func (L *Ledger) ProductBoost(target float64) map[string]float64 {
	m := map[string]float64{}
	for k, p := range L.Priors {
		if !strings.HasPrefix(k, "product=") || p.N == 0 || target <= 0 {
			continue
		}
		r := target / p.Post(target) // >1 = cheaper than target
		m[strings.TrimPrefix(k, "product=")] = math.Max(-1, math.Min(1, math.Log(r)*1.5))
	}
	return m
}

// Health: prediction error by ISO week of scoring (is it getting better?).
func (L *Ledger) Health() []WeekHealth {
	agg := map[string]*WeekHealth{}
	for _, p := range L.Preds {
		if !p.Scored || p.Kind != "launch" || p.Outcome == "not_run" {
			continue
		}
		y, w := p.ScoredAt.ISOWeek()
		k := fmt.Sprintf("%d-W%02d", y, w)
		h := agg[k]
		if h == nil {
			h = &WeekHealth{Week: k}
			agg[k] = h
		}
		h.MeanErr += p.Err
		if p.Err <= math.Log(1.3) {
			h.Hit++
		}
		h.Scored++
	}
	var out []WeekHealth
	for _, h := range agg {
		h.MeanErr = round4(h.MeanErr / float64(h.Scored))
		h.Hit = round2(h.Hit / float64(h.Scored))
		out = append(out, *h)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Week < out[j].Week })
	return out
}

// backtestStop: what each zero-purchase stop multiple would have saved (spend after the stop on ads that never
// bought) minus what it would have cost (purchases after the stop, valued at break-even CPA), on the account's own
// ad-level history. Returns the best multiple inside [lo, hi] and the evidence.
func backtestStop(rows []MetricRow, beCPA, lo, hi float64) (float64, []string, int) {
	byAd := map[string][]MetricRow{}
	for _, r := range rows {
		if r.Level == "ad" && !r.Settling {
			byAd[r.ObjectID] = append(byAd[r.ObjectID], r)
		}
	}
	if len(byAd) < 5 || beCPA <= 0 {
		return 0, nil, len(byAd)
	}
	for _, rs := range byAd {
		sort.Slice(rs, func(i, j int) bool { return rs[i].Date < rs[j].Date })
	}
	best, bestV := 0.0, math.Inf(-1)
	var ev []string
	for m := lo; m <= hi+1e-9; m += 0.5 {
		var saved, lost float64
		for _, rs := range byAd {
			var spend, purch float64
			stopped := false
			for _, r := range rs {
				if stopped {
					saved += r.SpendAED
					lost += float64(r.Purchases)
					continue
				}
				spend += r.SpendAED
				purch += float64(r.Purchases)
				if purch == 0 && spend >= m*beCPA {
					stopped = true
				}
			}
		}
		v := saved - lost*beCPA
		ev = append(ev, fmt.Sprintf("stop at %.1f× break-even: saves AED %.0f, loses %.0f purchases (net AED %.0f)", m, saved, lost, v))
		if v > bestV+1e-9 {
			best, bestV = m, v
		}
	}
	return best, ev, len(byAd)
}

// Tune: the self-tuning step. mode off: nothing; bounds: applied inside the owner's bounds; ask: proposed only.
// A change needs 5+ ads of history and moves at most one step (0.5) per week from the current value.
func (L *Ledger) Tune(in CycleInput, cur RulesConfig, econ Economics, mode string, b model.TuneBounds) []string {
	if mode == "off" {
		L.Tuned = Tuned{}
		return nil
	}
	if !L.Tuned.At.IsZero() && in.Now.Sub(L.Tuned.At) < 7*24*time.Hour {
		return nil
	}
	m, ev, nAds := backtestStop(in.Metrics, econ.BreakEvenCPA, b.HardStopMin, b.HardStopMax)
	if m == 0 {
		return nil
	}
	base := cur.HardStopMult
	if L.Tuned.HardStopMult > 0 && L.Tuned.Applied { // a proposal nobody applied is not the new base
		base = L.Tuned.HardStopMult
	}
	next := base + math.Max(-0.5, math.Min(0.5, m-base))
	next = math.Max(b.HardStopMin, math.Min(b.HardStopMax, next))
	if math.Abs(next-base) < 1e-9 {
		return nil
	}
	L.Tuned = Tuned{HardStopMult: next, At: in.Now, Evidence: append([]string{fmt.Sprintf("back-test on %d ads of this account", nAds)}, ev...), Applied: mode == "bounds"}
	ch := fmt.Sprintf("%s: stop a non-buying ad after %.1f× break-even CPA (was %.1f×), inside your bounds %.1f–%.1f×",
		in.Now.Format("2006-01-02"), next, base, b.HardStopMin, b.HardStopMax)
	if mode == "ask" {
		ch = "Proposed (waiting for you): " + ch
	}
	L.Changes = append(L.Changes, ch)
	if len(L.Changes) > 50 {
		L.Changes = L.Changes[len(L.Changes)-50:]
	}
	return []string{ch}
}

// Apply: the tuned thresholds into the rules (only when applied, only inside the bounds, never over a value the
// owner set himself on the Strategy screen).
func (L *Ledger) Apply(r RulesConfig, k model.Knobs, b model.TuneBounds) RulesConfig {
	if L.Tuned.Applied && L.Tuned.HardStopMult > 0 && k.HardStopMult == 0 {
		r.HardStopMult = math.Max(b.HardStopMin, math.Min(b.HardStopMax, L.Tuned.HardStopMult))
	}
	return r
}

// exploreAngles: ~80% of new concepts on winning angles, every 5th slot (≥ 20%) on an angle with no verdict yet.
func exploreAngles(lessons []Lesson, research []string, n int) []string {
	var win []string
	lost := map[string]bool{}
	for _, l := range lessons {
		if l.Dim == "angle" {
			if l.Kind == "win" {
				win = append(win, l.Value)
			} else {
				lost[l.Value] = true
			}
		}
	}
	var fresh []string
	known := map[string]bool{}
	for _, w := range win {
		known[w] = true
	}
	for _, a := range research {
		k := strings.ToLower(strings.TrimSpace(a))
		if !known[k] && !lost[k] {
			fresh = append(fresh, a)
		}
	}
	var out []string
	for i := 0; i < n; i++ {
		switch {
		case len(win) > 0 && (i%5 != 4 || len(fresh) == 0):
			out = append(out, win[i%len(win)])
		case len(fresh) > 0:
			out = append(out, fresh[i%len(fresh)])
		case len(research) > 0:
			out = append(out, research[i%len(research)])
		}
	}
	return out
}

// Weekly: the "What I learned and changed" report for this ISO week (built once a week, code only).
func (L *Ledger) Weekly(now time.Time, target float64) *WeeklyReport {
	y, w := now.ISOWeek()
	wk := fmt.Sprintf("%d-W%02d", y, w)
	if n := len(L.Reports); n > 0 && L.Reports[n-1].Week == wk {
		return nil
	}
	ls := L.Lessons(target)
	if len(ls) > 8 {
		ls = ls[:8]
	}
	var changes []string
	for _, c := range L.Changes {
		if strings.HasPrefix(c, now.AddDate(0, 0, -7).Format("2006-01-02")) || c >= now.AddDate(0, 0, -7).Format("2006-01-02") {
			changes = append(changes, c)
		}
	}
	h := L.Health()
	acc, accAR := "Not enough finished tests yet to judge its own predictions.", "لا توجد اختبارات مكتملة كافية بعد لتقييم توقعاته."
	if n := len(h); n > 0 {
		acc = fmt.Sprintf("Prediction error this week %.0f%% (%d tests scored, %.0f%% within ±30%%).", (math.Exp(h[n-1].MeanErr)-1)*100, h[n-1].Scored, h[n-1].Hit*100)
		accAR = fmt.Sprintf("خطأ التوقع هذا الأسبوع %.0f٪ (%d اختبارات، %.0f٪ ضمن ±٣٠٪).", (math.Exp(h[n-1].MeanErr)-1)*100, h[n-1].Scored, h[n-1].Hit*100)
		if n > 1 {
			d := (math.Exp(h[n-1].MeanErr) - math.Exp(h[n-2].MeanErr)) * 100
			acc += fmt.Sprintf(" %s %.0f points vs last week.", map[bool]string{true: "Better by", false: "Worse by"}[d < 0], math.Abs(d))
			accAR += fmt.Sprintf(" %s %.0f نقطة عن الأسبوع الماضي.", map[bool]string{true: "أفضل بـ", false: "أسوأ بـ"}[d < 0], math.Abs(d))
		}
	}
	var next []string
	var en, ar []string
	for _, l := range ls {
		en = append(en, "• "+l.Text)
		ar = append(ar, "• "+l.TextAR)
		if l.Kind == "win" && len(next) < 2 {
			next = append(next, "more concepts on "+strings.ReplaceAll(l.Value, "|", " × "))
		}
	}
	next = append(next, "keep 1 in 5 new concepts for an untested idea (exploration)")
	if len(en) == 0 {
		en, ar = []string{"• No clear winner or loser yet; tests continue."}, []string{"• لا يوجد رابح أو خاسر واضح بعد؛ تستمر الاختبارات."}
	}
	chEN := "No threshold changes."
	if len(changes) > 0 {
		chEN = strings.Join(changes, "\n")
	}
	r := WeeklyReport{Week: wk, At: now, Lessons: ls, Changes: changes, Next: next,
		EN: "What I learned (" + wk + "):\n" + strings.Join(en, "\n") + "\n\nWhat I changed:\n" + chEN + "\n\nNext week:\n• " + strings.Join(next, "\n• ") + "\n\n" + acc,
		AR: "ما تعلّمته (" + wk + "):\n" + strings.Join(ar, "\n") + "\n\nما غيّرته:\n" + map[bool]string{true: chEN, false: "لا تغييرات في الحدود."}[len(changes) > 0] +
			"\n\nالأسبوع القادم:\n• المزيد من الأفكار على الأنماط الرابحة\n• فكرة جديدة من كل خمس أفكار للاستكشاف\n\n" + accAR}
	L.Reports = append(L.Reports, r)
	if len(L.Reports) > 26 {
		L.Reports = L.Reports[len(L.Reports)-26:]
	}
	return &r
}

// lessonsBrief: the compact LESSONS section for the planner and the copy briefs (cost-aware: at most 8 lines).
func lessonsBrief(ls []Lesson) string {
	if len(ls) == 0 {
		return ""
	}
	var b strings.Builder
	b.WriteString("LESSONS FROM THIS ACCOUNT'S OWN RESULTS (use winning patterns for ~80% of new concepts, keep ~20% for new ideas; avoid losing patterns):\n")
	for i, l := range ls {
		if i >= 8 {
			break
		}
		b.WriteString("- " + l.Text + "\n")
	}
	return b.String()
}

// RecordLaunches: ads the ENGINE launched (the funnel build, the job queue, occasions) join the outcomes ledger too,
// each with its prediction, so the learning covers every ad the app runs, not only the planner's.
func (e *Engine) RecordLaunches(acct string, now time.Time, creatives []model.Creative, shop model.ShopSnapshot, props []model.Proposal, done map[string]bool) {
	lib := map[string]model.Creative{}
	for _, c := range creatives {
		lib[c.ID] = c
	}
	e.mu.Lock()
	defer e.mu.Unlock()
	var L Ledger
	e.load(acct, "ledger", &L)
	have := map[string]bool{}
	for _, p := range L.Preds {
		have[p.ID] = true
	}
	tgt := ComputeEconomics(e.B.Econ, shopFromModel(shop), now).TargetCPA
	n := 0
	for _, p := range props {
		if p.Kind != model.KindCreateAd || !done[p.ID] {
			continue
		}
		cid, _ := p.Params["creative_id"].(string)
		c, ok := lib[cid]
		id := acct + "|" + p.ID
		if !ok || have[id] {
			continue
		}
		angle := strings.TrimPrefix(strings.TrimPrefix(c.Angle, "starter: "), "occasion: ")
		pr := Pred{ID: id, At: now, Kind: "launch", Object: p.Target, CreativeID: c.ID, ProductIDs: c.ProductIDs, Angle: angle, Format: "single_image", Lang: c.Lang, Due: now.Add(scoreAfter)}
		pr.ExpCPA = L.Predict(pr.Angle, pr.Format, pr.Lang, pr.ProductIDs, tgt)
		L.Preds = append(L.Preds, pr)
		n++
	}
	if n > 0 {
		e.save(acct, "ledger", L)
	}
}
