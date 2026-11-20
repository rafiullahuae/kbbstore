package brain

import (
	"fmt"
	"math"
	"sort"
	"strings"
	"time"
)

// RulesConfig: every threshold the rules layer uses, in one place. Labels
// say where each number comes from (research_notes/.../optimisation_decision_logic.md).
type RulesConfig struct {
	SettlingDays     int     // exclude today + this many days before it (attribution still arriving) -- 2
	WindowDays       int     // evaluation window length, ending before the settling days -- 7
	KillMinSpendMult float64 // judge an ad only after spend >= this x target CPA -- 2 (practitioner "2x")
	KillP            float64 // kill when P(CPA < break-even) <= this -- 0.2
	ScaleP           float64 // scale when P(CPA < target) >= this -- 0.8
	ScaleMinPurch    int64   // and at least this many settled purchases in the window -- 5
	HardStopMult     float64 // zero purchases after this x break-even CPA, settling included -> stop -- 3
	CutMinSpendMult  float64 // cut an ad set's budget when 7d spend >= this x target and P(CPA<BE) <= KillP -- 3
	HookMinImpr      int64   // weak-hook kill needs this many impressions -- 3000
	HookCTRFrac      float64 // ... and CTR credibly below this fraction of the account CTR -- 0.6
	HookP            float64 // ... with this probability -- 0.9
	LearningResults  int64   // Meta: ~50 results in the week after the last significant edit
	LearningDays     int     // the week
	FatigueCTRDrop   float64 // 7d CTR <= (1-this) x first-14d CTR -- 0.30
	FatigueCostRise  float64 // 7d CPM or CPA >= (1+this) x first-14d -- 0.25
	FatigueFreq      float64 // 7d frequency >= this -- 2.5
	FatigueMinSignal int     // signals needed -- 2
	DefaultStepPct   float64 // budget step if the owner set none -- 20
	DefaultCooldownH float64 // per-object cooldown if the owner set none -- 72
	TestSharePct     float64 // share of daily budget reserved for creative tests -- 20
	ArabicShare      float64 // default Arabic share of tests and prospecting budget -- 0.70 (owner: Arabic first)
	ArabicMin        float64 // never below -- 0.55
	ArabicMax        float64 // never above -- 0.85
	BatchWeekday     time.Weekday
	MaxNewAdsBatch   int // new ads per weekly batch -- 4
	MaxChanges       int // proposals per cycle -- 12
}

func DefaultRules() RulesConfig {
	return RulesConfig{
		SettlingDays: 2, WindowDays: 7, KillMinSpendMult: 2, KillP: 0.2, ScaleP: 0.8, ScaleMinPurch: 5,
		HardStopMult: 3, CutMinSpendMult: 3, HookMinImpr: 3000, HookCTRFrac: 0.6, HookP: 0.9,
		LearningResults: 50, LearningDays: 7, FatigueCTRDrop: 0.30, FatigueCostRise: 0.25, FatigueFreq: 2.5,
		FatigueMinSignal: 2, DefaultStepPct: 20, DefaultCooldownH: 72, TestSharePct: 20,
		ArabicShare: 0.70, ArabicMin: 0.55, ArabicMax: 0.85, BatchWeekday: time.Monday, MaxNewAdsBatch: 4, MaxChanges: 12,
	}
}

// Agg is summed metrics.
type Agg struct {
	Spend, Revenue      float64
	Impr, Clicks, Purch int64
	FreqMax             float64
	Days                int
}

func (a Agg) CPA() float64 {
	if a.Purch == 0 {
		return 0
	}
	return a.Spend / float64(a.Purch)
}
func (a Agg) ROAS() float64 {
	if a.Spend == 0 {
		return 0
	}
	return a.Revenue / a.Spend
}
func (a Agg) CTR() float64 {
	if a.Impr == 0 {
		return 0
	}
	return float64(a.Clicks) / float64(a.Impr)
}
func (a Agg) CPM() float64 {
	if a.Impr == 0 {
		return 0
	}
	return a.Spend / float64(a.Impr) * 1000
}

func (a *Agg) add(r MetricRow) {
	a.Spend += r.SpendAED
	a.Revenue += r.RevenueAED
	a.Impr += r.Impressions
	a.Clicks += r.Clicks
	a.Purch += r.Purchases
	if r.Frequency > a.FreqMax {
		a.FreqMax = r.Frequency
	}
	a.Days++
}

// RulesResult is the deterministic half of a cycle.
type RulesResult struct {
	Econ        Economics
	Verdicts    []Verdict
	Proposals   []Proposal
	Alerts      []string
	AccountCTR  float64
	AccountCPA  float64
	ArabicShare float64
	NewAdSlots  map[string]int // lang -> slots this batch (0 off batch day)
	BatchDay    bool
	LimitsSet   bool
	Window      [2]string
	Lang        map[string]Agg // settled 30d by language
	Learning    []LearningRow
}

// Evaluate runs every rule. It is pure: same input, same output.
func Evaluate(in CycleInput, econ Economics, cfg RulesConfig) RulesResult {
	now := in.Now
	today := now.Format("2006-01-02")
	winEnd := now.AddDate(0, 0, -(cfg.SettlingDays + 1)).Format("2006-01-02")
	winStart := now.AddDate(0, 0, -(cfg.SettlingDays + cfg.WindowDays)).Format("2006-01-02")
	d30 := now.AddDate(0, 0, -(cfg.SettlingDays + 30)).Format("2006-01-02")
	res := RulesResult{Econ: econ, Window: [2]string{winStart, winEnd}, NewAdSlots: map[string]int{}, Lang: map[string]Agg{}}
	res.LimitsSet = in.Limits.DailyAED > 0 && in.Limits.MonthlyAED > 0

	settled := func(r MetricRow) bool { return !r.Settling && r.Date <= winEnd && r.Date < today }
	win := map[string]*Agg{}  // settled 7d window
	life := map[string]*Agg{} // settled lifetime
	all := map[string]*Agg{}  // everything incl. settling
	byObjDay := map[string][]MetricRow{}
	var acct Agg
	get := func(m map[string]*Agg, id string) *Agg {
		if m[id] == nil {
			m[id] = &Agg{}
		}
		return m[id]
	}
	for _, r := range in.Metrics {
		get(all, r.ObjectID).add(r)
		if !settled(r) {
			continue
		}
		get(life, r.ObjectID).add(r)
		byObjDay[r.ObjectID] = append(byObjDay[r.ObjectID], r)
		if r.Date >= winStart {
			get(win, r.ObjectID).add(r)
		}
		if r.Level == "ad" && r.Date >= d30 {
			acct.add(r)
		}
	}
	res.AccountCTR = acct.CTR()
	prior := econ.TargetCPA
	if acct.Purch >= 10 {
		prior = acct.CPA()
		res.AccountCPA = prior
	}
	z := func(p *Agg) Agg {
		if p == nil {
			return Agg{}
		}
		return *p
	}
	step := in.Limits.MaxBudgetStepPct
	if step <= 0 || step > cfg.DefaultStepPct*2 {
		step = cfg.DefaultStepPct
	}
	cool := time.Duration(nz(in.Limits.CooldownHours, cfg.DefaultCooldownH) * float64(time.Hour))
	res.BatchDay = now.Weekday() == cfg.BatchWeekday

	// Daily budget already committed (active campaign + ad-set budgets).
	var committed float64
	activeAds := 0
	for _, c := range in.Tree {
		if c.Status != "ACTIVE" {
			continue
		}
		committed += c.DailyBudgetAED
		for _, s := range c.AdSets {
			if s.Status == "ACTIVE" {
				committed += s.DailyBudgetAED
				for _, a := range s.Ads {
					if a.Status == "ACTIVE" {
						activeAds++
					}
				}
			}
		}
	}
	headroom := in.Limits.DailyAED - committed

	lastChange := map[string]time.Time{}
	for _, h := range in.History {
		if (h.Kind == "execution" || h.Kind == "action.executed") && h.Object != "" && h.Time.After(lastChange[h.Object]) {
			lastChange[h.Object] = h.Time
		}
	}
	coolOK := func(id string, t ...time.Time) bool {
		last := lastChange[id]
		for _, x := range t {
			if x.After(last) {
				last = x
			}
		}
		return last.IsZero() || now.Sub(last) >= cool
	}

	var props []Proposal
	pid := 0
	propose := func(kind, target string, params map[string]any, why string, conf float64, ev ...string) {
		pid++
		props = append(props, Proposal{ID: fmt.Sprintf("r%s-%d", now.Format("0102"), pid), Kind: kind, Target: target,
			Params: params, Why: why, Confidence: round2(conf), Evidence: ev})
	}
	verdict := func(v Verdict) { res.Verdicts = append(res.Verdicts, v) }
	killed := 0
	adLang := map[string]string{}

	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			inLearning := adsetLearning(s, z(win[s.ID]), now, cfg)
			for _, a := range s.Ads {
				lang := firstNonEmpty(a.Language, s.Language)
				adLang[a.ID] = lang
				if a.Status != "ACTIVE" {
					continue
				}
				v := judgeAd(a, z(life[a.ID]), z(all[a.ID]), byObjDay[a.ID], now, econ, prior, res.AccountCTR, inLearning, cfg)
				verdict(v)
				switch v.Verdict {
				case "kill":
					killed++
					propose("pause", a.ID, map[string]any{"level": "ad"}, v.Why, 1-v.PWinBE, v.Signals...)
				case "fatigued":
					if v.PWinBE < 0.5 {
						killed++
						propose("pause", a.ID, map[string]any{"level": "ad", "reason": "fatigue"}, v.Why, 0.7, v.Signals...)
					}
				}
			}
			if s.Status != "ACTIVE" || s.DailyBudgetAED <= 0 {
				continue
			}
			v := judgeBudget("adset", s.ID, z(win[s.ID]), econ, prior, cfg)
			if inLearning && v.Verdict != "cut" {
				v.Verdict, v.Why = "learning", fmt.Sprintf("In Meta's learning phase (%d results this week, needs ~%d). No edits until it exits or a week passes.", z(win[s.ID]).Purch, cfg.LearningResults)
			}
			verdict(v)
			budgetMove(&res, v, s.ID, "adset", s.DailyBudgetAED, step, coolOK(s.ID, s.LastBudgetChange), &headroom, propose)
		}
		if c.Status == "ACTIVE" && c.DailyBudgetAED > 0 {
			v := judgeBudget("campaign", c.ID, z(win[c.ID]), econ, prior, cfg)
			learning := false
			for _, s := range c.AdSets {
				if s.Status == "ACTIVE" && adsetLearning(s, z(win[s.ID]), now, cfg) {
					learning = true
				}
			}
			if learning && v.Verdict != "cut" {
				v.Verdict, v.Why = "learning", "An ad set in this campaign is still learning; the campaign budget stays put."
			}
			verdict(v)
			budgetMove(&res, v, c.ID, "campaign", c.DailyBudgetAED, step, coolOK(c.ID), &headroom, propose)
		}
	}

	// Language performance (settled 30d) and the Arabic share.
	for id, rows := range byObjDay {
		lang := adLang[id]
		if lang == "" {
			continue
		}
		for _, r := range rows {
			if r.Level == "ad" && r.Date >= d30 {
				g := res.Lang[lang]
				g.add(r)
				res.Lang[lang] = g
			}
		}
	}
	res.ArabicShare = arabicShare(res.Lang["ar"], res.Lang["en"], prior, cfg)

	// Weekly creative batch: new test slots, Arabic first.
	if res.BatchDay && res.LimitsSet {
		free := in.Limits.MaxActiveAds - activeAds + killed
		if in.Limits.MaxActiveAds <= 0 {
			free = cfg.MaxNewAdsBatch
		}
		if free > cfg.MaxNewAdsBatch {
			free = cfg.MaxNewAdsBatch
		}
		if free > 0 {
			ar := int(math.Round(float64(free) * res.ArabicShare))
			if ar < 1 {
				ar = 1
			}
			if ar > free {
				ar = free
			}
			res.NewAdSlots["ar"], res.NewAdSlots["en"] = ar, free-ar
		}
	}
	if !res.LimitsSet {
		res.Alerts = append(res.Alerts, "No daily/monthly limit set: the autopilot will not launch or raise anything. Set Limits in Settings.")
		// keep pauses and cuts only: they lower spend
		var keep []Proposal
		for _, p := range props {
			if p.Kind == "pause" || (p.Kind == "set_budget" && p.Params["direction"] == "down") {
				keep = append(keep, p)
			}
		}
		props = keep
	}
	if headroom < 0 && res.LimitsSet {
		res.Alerts = append(res.Alerts, fmt.Sprintf("Active budgets (AED %.0f/day) are above the daily limit (AED %.0f).", committed, in.Limits.DailyAED))
	}
	// Most important first: pauses, then cuts, then scales; cap the count.
	rank := map[string]int{"pause": 0, "set_budget": 1}
	sort.SliceStable(props, func(i, j int) bool { return rank[props[i].Kind] < rank[props[j].Kind] })
	if len(props) > cfg.MaxChanges {
		props = props[:cfg.MaxChanges]
	}
	res.Proposals = props
	res.Learning = learningTable(in, life, econ, prior)
	return res
}

func budgetMove(res *RulesResult, v Verdict, id, level string, cur, step float64, cooled bool, headroom *float64,
	propose func(string, string, map[string]any, string, float64, ...string)) {
	switch v.Verdict {
	case "scale":
		if !cooled {
			res.Verdicts[len(res.Verdicts)-1].Why += " (scale waits: cooldown)"
			return
		}
		nb := round2(cur * (1 + step/100))
		if nb-cur > *headroom {
			nb = round2(cur + math.Max(*headroom, 0))
		}
		if nb <= cur {
			res.Verdicts[len(res.Verdicts)-1].Why += " (scale waits: no room under the daily limit)"
			return
		}
		*headroom -= nb - cur
		propose("set_budget", id, map[string]any{"level": level, "daily_budget_aed": nb, "from_aed": cur, "direction": "up"},
			v.Why, v.PWinTgt, fmt.Sprintf("P(CPA<target)=%.2f", v.PWinTgt))
	case "cut":
		if !cooled {
			res.Verdicts[len(res.Verdicts)-1].Why += " (cut waits: cooldown)"
			return
		}
		nb := round2(cur * (1 - step/100))
		propose("set_budget", id, map[string]any{"level": level, "daily_budget_aed": nb, "from_aed": cur, "direction": "down"},
			v.Why, 1-v.PWinBE, fmt.Sprintf("P(CPA<break-even)=%.2f", v.PWinBE))
	}
}

func adsetLearning(s AdSet, w Agg, now time.Time, cfg RulesConfig) bool {
	if w.Purch >= cfg.LearningResults {
		return false
	}
	if strings.EqualFold(s.LearningStage, "LEARNING") {
		return true
	}
	edit := s.LastSignificantEdit
	if edit.IsZero() {
		edit = s.CreatedAt
	}
	return !edit.IsZero() && now.Sub(edit) < time.Duration(cfg.LearningDays)*24*time.Hour && !strings.EqualFold(s.LearningStage, "SUCCESS")
}

func judgeAd(a Ad, life, all Agg, days []MetricRow, now time.Time, e Economics, prior, acctCTR float64, learning bool, cfg RulesConfig) Verdict {
	post := NewCPAPosterior(life.Purch, life.Spend, prior)
	v := Verdict{Object: a.ID, Level: "ad", SpendAED: round2(life.Spend), Purch: life.Purch, CPA: round2(life.CPA()),
		ROAS: round2(life.ROAS()), CTR: round4(life.CTR()),
		PWinTgt: round2(post.PBelow(e.TargetCPA)), PWinBE: round2(post.PBelow(e.BreakEvenCPA))}
	// Hard stop: real money, nothing back, even counting the settling days.
	if all.Purch == 0 && all.Spend >= cfg.HardStopMult*e.BreakEvenCPA {
		v.Verdict = "kill"
		v.Why = fmt.Sprintf("Spent AED %.0f (%.1fx break-even CPA) with no purchase at all. Stopping it.", all.Spend, all.Spend/e.BreakEvenCPA)
		v.Signals = []string{"hard_stop"}
		return v
	}
	if life.Spend < 0.5*e.TargetCPA {
		v.Verdict, v.Why = "no_data", fmt.Sprintf("Only AED %.0f of settled spend; too early to judge.", life.Spend)
		if all.Spend > life.Spend {
			v.Verdict, v.Why = "settling", fmt.Sprintf("AED %.0f spent, mostly in the last %d days -- purchases are still being attributed.", all.Spend, cfg.SettlingDays)
		}
		return v
	}
	if life.Spend >= cfg.KillMinSpendMult*e.TargetCPA && v.PWinBE <= cfg.KillP {
		v.Verdict = "kill"
		v.Why = fmt.Sprintf("AED %.0f spent, %d purchases: only %s chance its CPA beats break-even (AED %.0f).", life.Spend, life.Purch, pct(v.PWinBE), e.BreakEvenCPA)
		v.Signals = []string{"posterior_kill"}
		return v
	}
	if life.Purch == 0 && life.Impr >= cfg.HookMinImpr && life.Spend >= e.TargetCPA && acctCTR > 0 &&
		PRateBelow(life.Clicks, life.Impr, cfg.HookCTRFrac*acctCTR) >= cfg.HookP {
		v.Verdict = "kill"
		v.Why = fmt.Sprintf("Weak hook: CTR %.2f%% vs account %.2f%% over %d impressions, no purchase.", life.CTR()*100, acctCTR*100, life.Impr)
		v.Signals = []string{"weak_hook"}
		return v
	}
	if sig := fatigueSignals(days, now, cfg); len(sig) >= cfg.FatigueMinSignal {
		v.Verdict, v.Signals = "fatigued", sig
		v.Why = "Creative fatigue: " + strings.Join(sig, ", ") + ". Queue a fresh creative for this slot."
		return v
	}
	if learning && now.Sub(a.CreatedAt) < 72*time.Hour {
		v.Verdict, v.Why = "learning", "New ad in a learning ad set; give it 3 days."
		return v
	}
	if v.PWinTgt >= cfg.ScaleP && life.Purch >= 3 {
		v.Verdict = "keep"
		v.Why = fmt.Sprintf("Winner: %d purchases at AED %.0f CPA, %s likely to beat target AED %.0f.", life.Purch, life.CPA(), pct(v.PWinTgt), e.TargetCPA)
		v.Signals = []string{"winner"}
		return v
	}
	v.Verdict = "watch"
	v.Why = fmt.Sprintf("%d purchases on AED %.0f; %s likely to beat break-even -- keep collecting data.", life.Purch, life.Spend, pct(v.PWinBE))
	return v
}

func judgeBudget(level, id string, w Agg, e Economics, prior float64, cfg RulesConfig) Verdict {
	post := NewCPAPosterior(w.Purch, w.Spend, prior)
	v := Verdict{Object: id, Level: level, SpendAED: round2(w.Spend), Purch: w.Purch, CPA: round2(w.CPA()), ROAS: round2(w.ROAS()),
		CTR: round4(w.CTR()), PWinTgt: round2(post.PBelow(e.TargetCPA)), PWinBE: round2(post.PBelow(e.BreakEvenCPA))}
	switch {
	case w.Spend >= cfg.CutMinSpendMult*e.TargetCPA && v.PWinBE <= cfg.KillP:
		v.Verdict = "cut"
		v.Why = fmt.Sprintf("Settled 7 days: AED %.0f, %d purchases; %s chance of beating break-even. Budget down one step.", w.Spend, w.Purch, pct(v.PWinBE))
	case v.PWinTgt >= cfg.ScaleP && w.Purch >= cfg.ScaleMinPurch && w.CPA() <= e.TargetCPA:
		v.Verdict = "scale"
		v.Why = fmt.Sprintf("Settled 7 days: CPA AED %.0f vs target %.0f, %s likely to keep beating it. Budget up one step.", w.CPA(), e.TargetCPA, pct(v.PWinTgt))
	default:
		v.Verdict = "keep"
		v.Why = fmt.Sprintf("Settled 7 days: AED %.0f, %d purchases. No budget change.", w.Spend, w.Purch)
	}
	return v
}

// fatigueSignals compares an ad's last 7 settled days with its first 14.
func fatigueSignals(days []MetricRow, now time.Time, cfg RulesConfig) []string {
	if len(days) < 14 {
		return nil
	}
	sort.Slice(days, func(i, j int) bool { return days[i].Date < days[j].Date })
	var first, last Agg
	for i, r := range days {
		if i < 14 {
			first.add(r)
		}
		if i >= len(days)-7 {
			last.add(r)
		}
	}
	if len(days) < 21 { // need a recent week that does not overlap the first fortnight
		return nil
	}
	var s []string
	if first.CTR() > 0 && last.CTR() <= (1-cfg.FatigueCTRDrop)*first.CTR() {
		s = append(s, fmt.Sprintf("CTR down %.0f%%", (1-last.CTR()/first.CTR())*100))
	}
	costUp := false
	if first.CPM() > 0 && last.CPM() >= (1+cfg.FatigueCostRise)*first.CPM() {
		costUp = true
	}
	if first.CPA() > 0 && last.CPA() >= (1+cfg.FatigueCostRise)*first.CPA() {
		costUp = true
	}
	if costUp {
		s = append(s, "CPM/CPA rising")
	}
	// frequency: the max daily 7-day frequency Meta reports for the last week
	if last.FreqMax >= cfg.FatigueFreq {
		s = append(s, fmt.Sprintf("frequency %.1f", last.FreqMax))
	}
	return s
}

// arabicShare: Arabic gets the bulk (owner), moved by evidence within bounds.
func arabicShare(ar, en Agg, prior float64, cfg RulesConfig) float64 {
	share := cfg.ArabicShare
	if ar.Purch >= 10 && en.Purch >= 10 {
		arCPA := NewCPAPosterior(ar.Purch, ar.Spend, prior).MeanCPA()
		enCPA := NewCPAPosterior(en.Purch, en.Spend, prior).MeanCPA()
		switch {
		case arCPA <= 0.9*enCPA:
			share = cfg.ArabicShare + 0.10
		case arCPA >= 1.3*enCPA:
			share = cfg.ArabicMin
		}
	}
	return math.Min(cfg.ArabicMax, math.Max(cfg.ArabicMin, share))
}

// LearningRow: what has worked, by angle/language/template -- the planner's memory.
type LearningRow struct {
	Angle    string  `json:"angle"`
	Lang     string  `json:"lang"`
	Template string  `json:"template"`
	Ads      int     `json:"ads"`
	Spend    float64 `json:"spend"`
	Purch    int64   `json:"purchases"`
	CPA      float64 `json:"cpa"`
	CTR      float64 `json:"ctr"`
	PWin     float64 `json:"p_beats_target"`
}

func learningTable(in CycleInput, life map[string]*Agg, e Economics, prior float64) []LearningRow {
	cr := map[string]Creative{}
	for _, c := range in.Creatives {
		cr[c.ID] = c
	}
	type key struct{ a, l, t string }
	agg := map[key]*Agg{}
	ads := map[key]int{}
	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				m := life[a.ID]
				if m == nil {
					continue
				}
				k := key{"unknown", firstNonEmpty(a.Language, s.Language), ""}
				if x, ok := cr[a.CreativeID]; ok {
					k = key{firstNonEmpty(x.Angle, "unknown"), firstNonEmpty(x.Lang, k.l), x.Template}
				}
				if agg[k] == nil {
					agg[k] = &Agg{}
				}
				g := agg[k]
				g.Spend += m.Spend
				g.Revenue += m.Revenue
				g.Impr += m.Impr
				g.Clicks += m.Clicks
				g.Purch += m.Purch
				ads[k]++
			}
		}
	}
	var out []LearningRow
	for k, g := range agg {
		out = append(out, LearningRow{Angle: k.a, Lang: k.l, Template: k.t, Ads: ads[k], Spend: round2(g.Spend), Purch: g.Purch,
			CPA: round2(g.CPA()), CTR: round4(g.CTR()), PWin: round2(NewCPAPosterior(g.Purch, g.Spend, prior).PBelow(e.TargetCPA))})
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].PWin != out[j].PWin {
			return out[i].PWin > out[j].PWin
		}
		return out[i].Angle+out[i].Lang < out[j].Angle+out[j].Lang
	})
	return out
}

func nz(v, d float64) float64 {
	if v > 0 {
		return v
	}
	return d
}
func round4(f float64) float64 { return math.Round(f*10000) / 10000 }
func firstNonEmpty(s ...string) string {
	for _, x := range s {
		if x != "" {
			return x
		}
	}
	return ""
}

// pct prints a probability for the owner: "<1%", "37%", ">99%".
func pct(p float64) string {
	switch {
	case p < 0.01:
		return "<1%"
	case p > 0.99:
		return ">99%"
	}
	return fmt.Sprintf("%.0f%%", p*100)
}
