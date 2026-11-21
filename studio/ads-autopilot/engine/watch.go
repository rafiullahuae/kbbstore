package main

// watch.go — close monitoring (the owner, 21 Nov 2026: "monitor closely the running / new campaigns"). After every
// sync while the app is open (hourly by default) and after every cycle:
//
//   - each live ad: its age, status and early signal; ads in their first 72 h are read every hour against the
//     account's own medians (CTR, CPC, add-to-cart per click) and stopped early ONLY on strong evidence
//     (brain.Early: CTR credibly under half the median at 95% AND a second independent signal) — the slot is
//     refilled the same day (volume.go);
//   - spend pacing: today's spend against the committed daily budgets for the hours gone;
//   - delivery problems: ad rejected (a reworded version is queued at once and goes through the linter; the rejected
//     ad is paused), learning limited (a budget / structure fix is proposed, never applied without him), no delivery,
//     frequency high, CPM spike — each an alert, once a day.
//
// Practice only logs what it WOULD stop. The panel is Overview → Watching now (GET /api/today).

import (
	"context"
	"fmt"
	"math"
	"sort"
	"strings"
	"sync"
	"time"

	"kaa/brain"
	"kaa/executor"
	"kaa/model"
)

type WatchItem struct {
	AdID      string             `json:"ad_id"`
	Name      string             `json:"name"`
	Campaign  string             `json:"campaign"`
	Status    string             `json:"status"`
	AgeHours  float64            `json:"age_hours"`
	New       bool               `json:"new"`
	Impr      int64              `json:"impressions"`
	Clicks    int64              `json:"clicks"`
	CTR       float64            `json:"ctr"`
	CPC       float64            `json:"cpc"`
	ATC       float64            `json:"add_to_cart"`
	Purch     float64            `json:"purchases"`
	SpendAED  float64            `json:"spend"`
	Early     brain.EarlyVerdict `json:"early"`
	NextCheck time.Time          `json:"next_check"`
}

type WatchProblem struct {
	Kind   string `json:"kind"` // rejected | learning_limited | no_delivery | frequency | cpm_spike | pacing
	Object string `json:"object"`
	Name   string `json:"name"`
	Text   string `json:"text"`
	TextAR string `json:"text_ar"`
	Fix    string `json:"fix,omitempty"`
}

type Pacing struct {
	SpentAED    float64 `json:"spent_aed"`
	BudgetAED   float64 `json:"budget_aed"`
	ExpectedAED float64 `json:"expected_aed"`
	State       string  `json:"state"` // on_track | behind | ahead | early | no_budget
	Why         string  `json:"why"`
	WhyAR       string  `json:"why_ar"`
}

type WatchState struct {
	CheckedAt time.Time            `json:"checked_at"`
	NextAt    time.Time            `json:"next_at"`
	Items     []WatchItem          `json:"items"`
	Problems  []WatchProblem       `json:"problems"`
	Pacing    Pacing               `json:"pacing"`
	Medians   map[string]float64   `json:"medians"`
	FirstSeen map[string]time.Time `json:"first_seen"`
	Acted     map[string]string    `json:"acted"` // ad id -> what was done (early stop / reword), once
	Checks    int                  `json:"checks"`
	EarlyKill int                  `json:"early_kills"`
	Rewords   int                  `json:"rewords"`
}

var watchMu sync.Mutex

func (ac *Acct) watchState() WatchState {
	watchMu.Lock()
	defer watchMu.Unlock()
	var w WatchState
	_ = ac.st.Load("watch", &w)
	if w.FirstSeen == nil {
		w.FirstSeen = map[string]time.Time{}
	}
	if w.Acted == nil {
		w.Acted = map[string]string{}
	}
	if w.Items == nil {
		w.Items = []WatchItem{}
	}
	if w.Problems == nil {
		w.Problems = []WatchProblem{}
	}
	return w
}

func (ac *Acct) saveWatch(w WatchState) {
	watchMu.Lock()
	defer watchMu.Unlock()
	_ = ac.st.Save("watch", w)
}

func median(xs []float64) float64 {
	if len(xs) == 0 {
		return 0
	}
	sort.Float64s(xs)
	m := len(xs) / 2
	if len(xs)%2 == 0 {
		return (xs[m-1] + xs[m]) / 2
	}
	return xs[m]
}

// watch: one close-monitoring pass. The caller holds ac.work (after a sync or a cycle).
func (ac *Acct) watch(ctx context.Context) WatchState {
	a := ac.app
	now := a.now()
	loc := ac.loc()
	st := ac.Settings()
	in := ac.input()
	w := ac.watchState()
	syncEvery, _ := a.intervals()
	w.CheckedAt, w.NextAt = now.UTC(), now.Add(syncEvery).UTC()
	w.Checks++
	tCPA := ac.targetCPA(in)
	today := now.In(loc).Format("2006-01-02")
	type agg struct {
		spend, purch, atc float64
		impr, clicks      int64
		reach             int64
		first, last       string
	}
	ads := map[string]*agg{}
	from14 := now.In(loc).AddDate(0, 0, -14).Format("2006-01-02")
	camp := map[string]map[string]*agg{} // campaign level by day (pacing, CPM)
	setFreq := map[string]*agg{}
	from7 := now.In(loc).AddDate(0, 0, -6).Format("2006-01-02")
	for _, r := range in.Metrics {
		switch r.Level {
		case "ad":
			g := ads[r.ObjectID]
			if g == nil {
				g = &agg{first: r.Date}
				ads[r.ObjectID] = g
			}
			if r.Date < g.first {
				g.first = r.Date
			}
			if r.Date > g.last {
				g.last = r.Date
			}
			g.spend += r.SpendAED
			g.purch += r.Purchases
			g.atc += r.ATC
			g.impr += r.Impressions
			g.clicks += r.Clicks
		case "campaign":
			if camp[r.Date] == nil {
				camp[r.Date] = map[string]*agg{}
			}
			camp[r.Date][r.ObjectID] = &agg{spend: r.SpendAED, impr: r.Impressions}
		case "adset":
			if r.Date >= from7 {
				g := setFreq[r.ObjectID]
				if g == nil {
					g = &agg{}
					setFreq[r.ObjectID] = g
				}
				g.impr += r.Impressions
				g.reach += r.Reach
			}
		}
	}
	// the account's medians over its ads with enough impressions (last 14 days)
	var ctrs, cpcs, atcs []float64
	for _, g := range ads {
		if g.impr >= 500 && g.last >= from14 { // ads that ran in the last 14 days, with enough impressions
			ctrs = append(ctrs, float64(g.clicks)/float64(g.impr))
			if g.clicks > 0 {
				cpcs = append(cpcs, g.spend/float64(g.clicks))
				atcs = append(atcs, g.atc/float64(g.clicks))
			}
		}
	}
	w.Medians = map[string]float64{"ctr": round4(median(ctrs)), "cpc": round2(median(cpcs)), "atc_per_click": round4(median(atcs)), "ads": float64(len(ctrs))}
	lib := map[string]model.Creative{}
	for _, c := range in.Creatives {
		lib[c.ID], lib[c.MetaID] = c, c
	}
	mode := model.AutopilotShadow
	if st.Limits.Autopilot == model.AutopilotAuto {
		mode = model.AutopilotAuto
	}
	var problems []WatchProblem
	var items []WatchItem
	var kills []model.Proposal
	js := ac.jobSet()
	jobsChanged := false
	for _, c := range in.Tree {
		if !c.Managed {
			continue
		}
		for _, s := range c.AdSets {
			// learning limited: a fix is proposed (consolidate / budget), never applied without him
			if s.Status == "ACTIVE" && strings.EqualFold(s.LearningStage, "FAIL") {
				need := 0.0
				if tCPA > 0 {
					need = math.Ceil(tCPA * float64(in.Playbook.Knobs.ConvWeek()) / 7)
				}
				problems = append(problems, WatchProblem{Kind: "learning_limited", Object: s.ID, Name: s.Name,
					Text:   fmt.Sprintf("%s is learning-limited (Meta): it gets fewer than ~%d purchases a week. Proposed fix: keep new ads in one prospecting ad set, or give it about AED %.0f/day (needs your OK; nothing changed).", s.Name, in.Playbook.Knobs.ConvWeek(), need),
					TextAR: fmt.Sprintf("%s محدودة التعلّم: أقل من %d طلباً أسبوعياً. المقترح: جمع الإعلانات الجديدة في مجموعة واحدة أو ميزانية نحو %.0f درهم يومياً (بموافقتك؛ لم يتغيّر شيء).", s.Name, in.Playbook.Knobs.ConvWeek(), need),
					Fix:    "proposed: consolidate or raise the budget (waits for you)"})
			}
			if g := setFreq[s.ID]; g != nil && g.reach > 0 && s.Status == "ACTIVE" && c.Objective == "OUTCOME_SALES" {
				if fr := float64(g.impr) / float64(g.reach); fr >= 3.5 {
					problems = append(problems, WatchProblem{Kind: "frequency", Object: s.ID, Name: s.Name,
						Text:   fmt.Sprintf("%s: 7-day frequency %.1f — the same people see it too often; the next batch brings fresh concepts.", s.Name, fr),
						TextAR: fmt.Sprintf("%s: التكرار الأسبوعي %.1f — الأشخاص أنفسهم يرونه كثيراً؛ الدفعة القادمة تأتي بأفكار جديدة.", s.Name, fr)})
				}
			}
			for _, ad := range s.Ads {
				if _, ok := w.FirstSeen[ad.ID]; !ok {
					seen := now
					if g := ads[ad.ID]; g != nil && g.first != "" { // seen in the metrics before: as old as its first day
						if t, err := time.ParseInLocation("2006-01-02", g.first, loc); err == nil {
							seen = t
						}
					}
					w.FirstSeen[ad.ID] = seen.UTC()
				}
				eff := firstNonEmpty(ad.EffectiveStatus, ad.Status)
				// rejected: a reworded version now (through the copywriter and the linter); the rejected ad is paused
				if eff == "DISAPPROVED" && ad.Status != "DELETED" && w.Acted[ad.ID] == "" {
					fb := []string{}
					for k, v := range ad.ReviewFeedback {
						fb = append(fb, k+": "+v)
					}
					sort.Strings(fb)
					cr := lib[ad.CreativeID]
					j := Job{ID: "reword-" + jobPID.ReplaceAllString(ad.ID, "-"), Kind: "reword", Title: "Replace a rejected ad (reworded)", TitleAR: "استبدال إعلان مرفوض بصياغة جديدة",
						Why: "Meta rejected " + ad.Name + ": " + firstNonEmpty(strings.Join(fb, "; "), "no reason given") + ". A reworded version replaces it.", When: now, Status: "planned",
						ProductIDs: allowedOnly(cr.ProductIDs, in.Shop), Lang: firstNonEmpty(cr.Lang, "ar"), Format: "image", Target: ad.ID, Source: "watch",
						CopyDirection: "Reworded within Meta's advertising policies (the rejected ad said: " + truncateRunes(cr.Headline+" — "+cr.PrimaryText, 160) + "; Meta's reason: " + firstNonEmpty(strings.Join(fb, "; "), "not given") + "). No negative self-perception, no before/after, no timed results; benefit-first, Balanced wording.",
						UpdatedAt:     now.UTC()}
					upsert(&js, j, now)
					jobsChanged = true
					w.Acted[ad.ID] = "reword"
					w.Rewords++
					problems = append(problems, WatchProblem{Kind: "rejected", Object: ad.ID, Name: ad.Name, Text: "Meta rejected " + ad.Name + " (" + firstNonEmpty(strings.Join(fb, "; "), "no reason given") + "): a reworded version is queued now and checked by the linter; the rejected ad is paused.",
						TextAR: "رفضت Meta الإعلان " + ad.Name + ": صياغة جديدة في الطابور الآن وتمرّ على مدقّق النصوص؛ ويُوقف الإعلان المرفوض.", Fix: "reworded version queued (Plan → Upcoming jobs)"})
					continue
				}
				if ad.Status != "ACTIVE" || s.Status != "ACTIVE" || c.Status != "ACTIVE" {
					continue
				}
				g := ads[ad.ID]
				if g == nil {
					g = &agg{}
				}
				age := now.Sub(w.FirstSeen[ad.ID]).Hours()
				it := WatchItem{AdID: ad.ID, Name: ad.Name, Campaign: c.Name, Status: eff, AgeHours: math.Round(age*10) / 10, New: age < brain.EarlyWindowH,
					Impr: g.impr, Clicks: g.clicks, ATC: g.atc, Purch: g.purch, SpendAED: round2(g.spend), NextCheck: w.NextAt}
				if g.impr > 0 {
					it.CTR = round4(float64(g.clicks) / float64(g.impr))
				}
				if g.clicks > 0 {
					it.CPC = round2(g.spend / float64(g.clicks))
				}
				it.Early = brain.Early(brain.EarlyStats{AgeHours: age, Impr: g.impr, Clicks: g.clicks, ATC: g.atc, Purch: g.purch, SpendAED: g.spend,
					MedCTR: w.Medians["ctr"], MedCPC: w.Medians["cpc"], MedATCPerClick: w.Medians["atc_per_click"], TargetCPA: tCPA})
				if !it.New && it.Early.Verdict == "kill" { // past 72 h the rules layer judges it on purchases (brain/rules.go)
					it.Early.Verdict, it.Early.Why, it.Early.WhyAR = "weak", "Past its first 72 h: judged by the daily rules on purchases. "+it.Early.Why, "تجاوز أول ٧٢ ساعة: تحكم عليه القواعد اليومية بالطلبات."
				}
				if it.New && it.Early.Verdict == "kill" && w.Acted[ad.ID] == "" {
					kills = append(kills, model.Proposal{ID: "watch-early-" + jobPID.ReplaceAllString(ad.ID, "-"), Kind: model.KindPause, Target: ad.ID,
						Params: map[string]any{"level": "ad", "reason": "early"}, Why: it.Early.Why, Confidence: it.Early.PLow, Evidence: it.Early.Signals})
				}
				if eff == "ACTIVE" && age >= 24 && g.impr == 0 {
					problems = append(problems, WatchProblem{Kind: "no_delivery", Object: ad.ID, Name: ad.Name,
						Text:   fmt.Sprintf("%s has had no impressions in %.0f h. Usually Meta prefers the other ads in its ad set; it is rotated out at the next batch if it stays at zero.", ad.Name, age),
						TextAR: fmt.Sprintf("%s بلا ظهور منذ %.0f ساعة؛ يُستبدل في الدفعة القادمة إن بقي كذلك.", ad.Name, age)})
				}
				items = append(items, it)
			}
		}
	}
	// pacing: today's spend (as Meta reports it at this sync) against the committed daily budgets
	budget := executor.CommittedDaily(in.Tree)
	spent := 0.0
	for _, g := range camp[today] {
		spent += g.spend
	}
	lt := now.In(loc)
	frac := (float64(lt.Hour()) + float64(lt.Minute())/60) / 24
	pc := Pacing{SpentAED: round2(spent), BudgetAED: round2(budget), ExpectedAED: round2(budget * frac)}
	switch {
	case budget <= 0:
		pc.State, pc.Why, pc.WhyAR = "no_budget", "No running budget yet.", "لا توجد ميزانية تعمل بعد."
	case lt.Hour() < 4:
		pc.State, pc.Why, pc.WhyAR = "early", "Too early in the day to judge pacing.", "الوقت مبكر للحكم على وتيرة الإنفاق."
	case spent < 0.5*pc.ExpectedAED:
		pc.State = "behind"
		pc.Why = fmt.Sprintf("Behind: AED %.0f spent of ~AED %.0f expected by now (daily budgets AED %.0f). Usually ads in learning or in review; watched hourly.", spent, pc.ExpectedAED, budget)
		pc.WhyAR = fmt.Sprintf("أبطأ من المتوقع: أُنفق %.0f من نحو %.0f درهم حتى الآن (الميزانيات اليومية %.0f).", spent, pc.ExpectedAED, budget)
		problems = append(problems, WatchProblem{Kind: "pacing", Name: "Spend pacing", Text: pc.Why, TextAR: pc.WhyAR})
	case spent > 1.5*pc.ExpectedAED && spent > 0.25*budget:
		pc.State = "ahead"
		pc.Why = fmt.Sprintf("Ahead: AED %.0f spent, ~AED %.0f expected by now. Meta may spend up to 75%% over a daily budget on one day and evens it out in the week; your limits and Meta's spend cap still hold.", spent, pc.ExpectedAED)
		pc.WhyAR = fmt.Sprintf("أسرع من المتوقع: أُنفق %.0f من نحو %.0f درهم متوقعة. حدودك وسقف Meta ما زالت سارية.", spent, pc.ExpectedAED)
	default:
		pc.State = "on_track"
		pc.Why = fmt.Sprintf("On track: AED %.0f spent, ~AED %.0f expected by now of AED %.0f/day.", spent, pc.ExpectedAED, budget)
		pc.WhyAR = fmt.Sprintf("ضمن المسار: أُنفق %.0f من نحو %.0f درهم متوقعة من %.0f يومياً.", spent, pc.ExpectedAED, budget)
	}
	w.Pacing = pc
	// CPM spike: yesterday's account CPM against the 7 days before it
	y := lt.AddDate(0, 0, -1).Format("2006-01-02")
	cpmOf := func(d string) float64 {
		var s float64
		var i int64
		for _, g := range camp[d] {
			s += g.spend
			i += g.impr
		}
		if i == 0 {
			return 0
		}
		return s / float64(i) * 1000
	}
	var prev []float64
	for k := 2; k <= 8; k++ {
		if c := cpmOf(lt.AddDate(0, 0, -k).Format("2006-01-02")); c > 0 {
			prev = append(prev, c)
		}
	}
	if cy, mp := cpmOf(y), median(prev); len(prev) >= 4 && mp > 0 && cy >= 1.5*mp {
		problems = append(problems, WatchProblem{Kind: "cpm_spike", Name: "CPM", Text: fmt.Sprintf("CPM spiked yesterday: AED %.1f vs AED %.1f median of the week before. Budgets are held (no raises) until it settles.", cy, mp),
			TextAR: fmt.Sprintf("ارتفعت تكلفة الألف ظهور أمس: %.1f درهم مقابل %.1f. لا زيادة في الميزانيات حتى تهدأ.", cy, mp)})
	}
	// early stops: through the executor (Practice logs what it WOULD do)
	if len(kills) > 0 {
		env := ac.env(mode)
		ac.mu.RLock()
		ex := ac.exec
		ac.mu.RUnlock()
		res := ex.Run(ctx, env, kills)
		ac.addPending(kills, res)
		for i, r := range res {
			ad := kills[i].Target
			if r.Status == "done" {
				w.Acted[ad], w.EarlyKill = "early_stop", w.EarlyKill+1
				ac.st.Append("kill", "early stop (first 72 h, strong evidence): "+ad+" — "+kills[i].Why, map[string]any{"target": ad, "outcome": "paused"})
			} else {
				ac.st.Append("watch", fmt.Sprintf("early stop of %s: %s (%s)", ad, r.Status, kills[i].Why), map[string]any{"target": ad, "outcome": r.Status})
				if r.Status == "shadow" {
					w.Acted[ad] = "would_stop"
				}
			}
		}
		// the stopped ads' slots: refilled today (volume.go)
		in2 := ac.input()
		ac.planCadence(in2, &js, now)
		jobsChanged = true
	}
	if jobsChanged {
		ac.saveJobs(js)
	}
	sort.SliceStable(items, func(i, k int) bool {
		if items[i].New != items[k].New {
			return items[i].New
		}
		return items[i].AgeHours < items[k].AgeHours
	})
	if items == nil {
		items = []WatchItem{}
	}
	if problems == nil {
		problems = []WatchProblem{}
	}
	w.Items, w.Problems = items, problems
	ac.saveWatch(w)
	for _, p := range problems {
		ac.alertOnce(p.Text)
	}
	nNew, weak := 0, 0
	for _, it := range items {
		if it.New {
			nNew++
		}
		if it.Early.Verdict == "weak" {
			weak++
		}
	}
	ac.st.Append("watch", fmt.Sprintf("hourly check: %d live ads (%d new, %d weak signals), pacing %s, %d problem(s), %d early stop(s)", len(items), nNew, weak, pc.State, len(problems), len(kills)),
		map[string]any{"live": len(items), "new": nNew, "problems": len(problems)})
	ac.publish("watch", map[string]any{"account": ac.id, "checked_at": w.CheckedAt})
	return w
}

func round2(x float64) float64 { return math.Round(x*100) / 100 }
func round4(x float64) float64 { return math.Round(x*10000) / 10000 }

func truncateRunes(s string, n int) string {
	r := []rune(s)
	if len(r) > n {
		return string(r[:n]) + "…"
	}
	return s
}
