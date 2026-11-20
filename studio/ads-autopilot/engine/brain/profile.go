package brain

// "Training": the owner wants the system to read the whole business
// portfolio and learn from it. Training here is statistics over the
// back-filled history (up to 37 months of daily Insights per account), done in
// code: baselines, what wins by language / angle / template / format /
// placement / weekday / season, fatigue curves and creative half-life. The
// planner reads the profile (and the portfolio-wide one) as evidence; the
// rules use its baselines as priors. Profiles are rebuilt after every sync
// (BuildProfile is linear in the number of metric rows).

import (
	"fmt"
	"math"
	"sort"
	"time"
)

type LearningProfile struct {
	Account      string              `json:"account"`
	Accounts     []string            `json:"accounts,omitempty"` // portfolio profile: which accounts it merges
	BuiltAt      time.Time           `json:"built_at"`
	FirstDay     string              `json:"first_day"`
	LastDay      string              `json:"last_day"`
	Days         int                 `json:"days"`
	Baselines    map[string]Baseline `json:"baselines"` // "30d" | "90d" | "365d" | "all"
	ByLang       []Slice             `json:"by_lang"`
	ByAngle      []Slice             `json:"by_angle"`
	ByTemplate   []Slice             `json:"by_template"`
	ByFormat     []Slice             `json:"by_format"`
	ByPlacement  []Slice             `json:"by_placement"`
	ByWeekday    []Slice             `json:"by_weekday"`
	BySeason     []Slice             `json:"by_season"`
	HalfLifeDays float64             `json:"creative_half_life_days"` // median days until an ad's CTR falls 30% below its first week
	LifespanDays float64             `json:"creative_lifespan_days"`  // median days an ad kept spending
	FatigueCurve []float64           `json:"fatigue_curve"`           // mean CTR index by week of ad age (week 1 = 1.0)
	Winners      []string            `json:"winners"`                 // plain-language findings with evidence
	Evidence     []string            `json:"evidence"`
}

type Baseline struct {
	Spend     float64 `json:"spend"`
	Purchases int64   `json:"purchases"`
	Revenue   float64 `json:"revenue"`
	CPA       float64 `json:"cpa"`
	ROAS      float64 `json:"roas"`
	CPC       float64 `json:"cpc"`
	CPM       float64 `json:"cpm"`
	CTR       float64 `json:"ctr"`
}

// Slice: one cut of the history, with its index vs the account baseline.
type Slice struct {
	Key       string  `json:"key"`
	Spend     float64 `json:"spend"`
	Purchases int64   `json:"purchases"`
	CPA       float64 `json:"cpa"`
	ROAS      float64 `json:"roas"`
	CTR       float64 `json:"ctr"`
	CPM       float64 `json:"cpm"`
	ROASIndex float64 `json:"roas_index"` // ROAS / baseline ROAS
	PWin      float64 `json:"p_beats_baseline"`
	Ads       int     `json:"ads,omitempty"`
}

func baselineOf(a Agg) Baseline {
	b := Baseline{Spend: round2(a.Spend), Purchases: a.Purch, Revenue: round2(a.Revenue), CPA: round2(a.CPA()), ROAS: round2(a.ROAS()), CPM: round2(a.CPM()), CTR: round4(a.CTR())}
	if a.Clicks > 0 {
		b.CPC = round2(a.Spend / float64(a.Clicks))
	}
	return b
}

// BuildProfile learns from one account's full history in `in`.
func BuildProfile(account string, in CycleInput) LearningProfile {
	p := LearningProfile{Account: account, BuiltAt: in.Now, Baselines: map[string]Baseline{}}
	now := in.Now
	cut := func(d int) string { return now.AddDate(0, 0, -d).Format("2006-01-02") }
	var w30, w90, w365, all Agg
	adLang, adSet, adCreative, adFormat := map[string]string{}, map[string]string{}, map[string]string{}, map[string]string{}
	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				adLang[a.ID] = firstNonEmpty(a.Language, s.Language)
				adSet[a.ID] = s.ID
				adCreative[a.ID] = a.CreativeID
				adFormat[a.ID] = firstNonEmpty(a.Format, "single_image")
			}
		}
	}
	cr := map[string]Creative{}
	for _, c := range in.Creatives {
		cr[c.ID] = c
	}
	slice := map[string]map[string]*Agg{"lang": {}, "angle": {}, "template": {}, "format": {}, "placement": {}, "weekday": {}, "season": {}}
	ads := map[string]map[string]map[string]bool{"angle": {}, "template": {}, "format": {}}
	addTo := func(dim, key string, r MetricRow, ad string) {
		if key == "" {
			return
		}
		if slice[dim][key] == nil {
			slice[dim][key] = &Agg{}
		}
		slice[dim][key].add(r)
		if m, ok := ads[dim]; ok {
			if m[key] == nil {
				m[key] = map[string]bool{}
			}
			m[key][ad] = true
		}
	}
	byAd := map[string][]MetricRow{}
	for _, r := range in.Metrics {
		if r.Level != "ad" || r.Settling {
			continue
		}
		if r.Placement != "" {
			addTo("placement", r.Placement, r, r.ObjectID)
			continue
		}
		if p.FirstDay == "" || r.Date < p.FirstDay {
			p.FirstDay = r.Date
		}
		if r.Date > p.LastDay {
			p.LastDay = r.Date
		}
		all.add(r)
		if r.Date >= cut(365) {
			w365.add(r)
		}
		if r.Date >= cut(90) {
			w90.add(r)
		}
		if r.Date >= cut(30) {
			w30.add(r)
		}
		byAd[r.ObjectID] = append(byAd[r.ObjectID], r)
		addTo("lang", adLang[r.ObjectID], r, r.ObjectID)
		if c, ok := cr[adCreative[r.ObjectID]]; ok {
			addTo("angle", c.Angle, r, r.ObjectID)
			addTo("template", c.Template, r, r.ObjectID)
		}
		addTo("format", adFormat[r.ObjectID], r, r.ObjectID)
		if t, err := time.Parse("2006-01-02", r.Date); err == nil {
			addTo("weekday", t.Weekday().String()[:3], r, r.ObjectID)
			if s := SeasonAt(t); s != nil {
				addTo("season", s.Name, r, r.ObjectID)
			} else {
				addTo("season", "no season", r, r.ObjectID)
			}
		}
	}
	// videos with results feed the angle/format cuts too
	for _, v := range in.Videos {
		if v.Results == nil || v.Results.SpendAED <= 0 || v.Analysis == nil {
			continue
		}
		r := MetricRow{SpendAED: v.Results.SpendAED, Impressions: v.Results.Impressions, Clicks: v.Results.Clicks, Purchases: v.Results.Purchases, RevenueAED: v.Results.RevenueAED}
		addTo("angle", v.Analysis.Angle, r, "video:"+v.ID)
		addTo("format", "single_video", r, "video:"+v.ID)
	}
	if p.FirstDay != "" {
		a, _ := time.Parse("2006-01-02", p.FirstDay)
		b, _ := time.Parse("2006-01-02", p.LastDay)
		p.Days = int(b.Sub(a).Hours()/24) + 1
	}
	p.Baselines["30d"], p.Baselines["90d"], p.Baselines["365d"], p.Baselines["all"] = baselineOf(w30), baselineOf(w90), baselineOf(w365), baselineOf(all)
	base := all
	prior := base.CPA()
	if base.Purch < 10 {
		prior = 0
	}
	mk := func(dim string) []Slice {
		var out []Slice
		for k, a := range slice[dim] {
			s := Slice{Key: k, Spend: round2(a.Spend), Purchases: a.Purch, CPA: round2(a.CPA()), ROAS: round2(a.ROAS()), CTR: round4(a.CTR()), CPM: round2(a.CPM())}
			if base.ROAS() > 0 {
				s.ROASIndex = round2(a.ROAS() / base.ROAS())
			}
			if prior > 0 {
				s.PWin = round2(NewCPAPosterior(a.Purch, a.Spend, prior).PBelow(prior))
			}
			if m, ok := ads[dim]; ok {
				s.Ads = len(m[k])
			}
			out = append(out, s)
		}
		sort.Slice(out, func(i, j int) bool {
			if out[i].Spend != out[j].Spend {
				return out[i].Spend > out[j].Spend
			}
			return out[i].Key < out[j].Key
		})
		return out
	}
	p.ByLang, p.ByAngle, p.ByTemplate, p.ByFormat = mk("lang"), mk("angle"), mk("template"), mk("format")
	p.ByPlacement, p.ByWeekday, p.BySeason = mk("placement"), mk("weekday"), mk("season")
	p.HalfLifeDays, p.LifespanDays, p.FatigueCurve = fatigueStats(byAd)

	// Plain findings, each with its evidence. Only credible ones (spend >= 2x
	// baseline CPA and P >= 0.8 or <= 0.2 against the account's own CPA).
	minSpend := 2 * math.Max(prior, 1)
	for _, dim := range []struct {
		name string
		s    []Slice
	}{{"language", p.ByLang}, {"angle", p.ByAngle}, {"design", p.ByTemplate}, {"format", p.ByFormat}, {"placement", p.ByPlacement}, {"season", p.BySeason}} {
		for _, s := range dim.s {
			if s.Spend < minSpend || prior == 0 {
				continue
			}
			switch {
			case s.PWin >= 0.8:
				p.Winners = append(p.Winners, fmt.Sprintf("%s %q wins: CPA AED %.0f vs account %.0f (%s likely better), ROAS index %.2f, on AED %.0f.", dim.name, s.Key, s.CPA, prior, pct(s.PWin), s.ROASIndex, s.Spend))
			case s.PWin <= 0.2:
				p.Evidence = append(p.Evidence, fmt.Sprintf("%s %q underperforms: CPA AED %.0f vs account %.0f (%s likely better), on AED %.0f.", dim.name, s.Key, s.CPA, prior, pct(s.PWin), s.Spend))
			}
		}
	}
	if p.HalfLifeDays > 0 {
		p.Evidence = append(p.Evidence, fmt.Sprintf("Creative half-life: CTR typically falls 30%% below its first week after %.0f days; ads keep spending a median %.0f days.", p.HalfLifeDays, p.LifespanDays))
	}
	return p
}

// fatigueStats: per ad with >= 14 days, the day its 7-day CTR first drops to
// <= 70% of its first-7-day CTR; plus lifespan and a weekly CTR index curve.
func fatigueStats(byAd map[string][]MetricRow) (half, life float64, curve []float64) {
	var halves, lives []float64
	sums, counts := make([]float64, 12), make([]float64, 12)
	for _, rows := range byAd {
		sort.Slice(rows, func(i, j int) bool { return rows[i].Date < rows[j].Date })
		if len(rows) > 0 {
			lives = append(lives, float64(len(rows)))
		}
		if len(rows) < 14 {
			continue
		}
		var first Agg
		for _, r := range rows[:7] {
			first.add(r)
		}
		if first.CTR() == 0 {
			continue
		}
		for wk := 0; wk*7+7 <= len(rows) && wk < 12; wk++ {
			var w Agg
			for _, r := range rows[wk*7 : wk*7+7] {
				w.add(r)
			}
			sums[wk] += w.CTR() / first.CTR()
			counts[wk]++
		}
		for d := 14; d <= len(rows); d++ {
			var w Agg
			for _, r := range rows[d-7 : d] {
				w.add(r)
			}
			if w.CTR() <= 0.7*first.CTR() {
				halves = append(halves, float64(d))
				break
			}
		}
	}
	for i := range sums {
		if counts[i] == 0 {
			break
		}
		curve = append(curve, round2(sums[i]/counts[i]))
	}
	return median(halves), median(lives), curve
}

func median(v []float64) float64 {
	if len(v) == 0 {
		return 0
	}
	sort.Float64s(v)
	if len(v)%2 == 1 {
		return v[len(v)/2]
	}
	return (v[len(v)/2-1] + v[len(v)/2]) / 2
}

// MergeProfiles builds the portfolio profile: baselines summed, slices
// summed by key across accounts (indices recomputed), half-life spend-weighted.
func MergeProfiles(ps []LearningProfile, now time.Time) LearningProfile {
	out := LearningProfile{Account: "portfolio", BuiltAt: now, Baselines: map[string]Baseline{}}
	for _, win := range []string{"30d", "90d", "365d", "all"} {
		var a Agg
		for _, p := range ps {
			b := p.Baselines[win]
			a.Spend += b.Spend
			a.Purch += b.Purchases
			a.Revenue += b.Revenue
			if b.CTR > 0 && b.CPM > 0 {
				impr := b.Spend / b.CPM * 1000
				a.Impr += int64(impr)
				a.Clicks += int64(impr * b.CTR)
			}
		}
		out.Baselines[win] = baselineOf(a)
	}
	base := out.Baselines["all"]
	merge := func(get func(LearningProfile) []Slice) []Slice {
		m := map[string]*Slice{}
		var keys []string
		for _, p := range ps {
			for _, s := range get(p) {
				x := m[s.Key]
				if x == nil {
					x = &Slice{Key: s.Key}
					m[s.Key] = x
					keys = append(keys, s.Key)
				}
				x.Spend += s.Spend
				x.Purchases += s.Purchases
				x.Ads += s.Ads
				x.ROAS += s.ROAS * s.Spend // weighted, normalised below
			}
		}
		sort.Strings(keys)
		var res []Slice
		for _, k := range keys {
			x := *m[k]
			if x.Spend > 0 {
				x.ROAS = round2(x.ROAS / x.Spend)
			}
			if x.Purchases > 0 {
				x.CPA = round2(x.Spend / float64(x.Purchases))
			}
			if base.ROAS > 0 {
				x.ROASIndex = round2(x.ROAS / base.ROAS)
			}
			if base.CPA > 0 {
				x.PWin = round2(NewCPAPosterior(x.Purchases, x.Spend, base.CPA).PBelow(base.CPA))
			}
			x.Spend = round2(x.Spend)
			res = append(res, x)
		}
		sort.SliceStable(res, func(i, j int) bool { return res[i].Spend > res[j].Spend })
		return res
	}
	out.ByLang = merge(func(p LearningProfile) []Slice { return p.ByLang })
	out.ByAngle = merge(func(p LearningProfile) []Slice { return p.ByAngle })
	out.ByTemplate = merge(func(p LearningProfile) []Slice { return p.ByTemplate })
	out.ByFormat = merge(func(p LearningProfile) []Slice { return p.ByFormat })
	out.ByPlacement = merge(func(p LearningProfile) []Slice { return p.ByPlacement })
	out.BySeason = merge(func(p LearningProfile) []Slice { return p.BySeason })
	var hw, w float64
	for _, p := range ps {
		out.Accounts = append(out.Accounts, p.Account)
		if p.HalfLifeDays > 0 {
			hw += p.HalfLifeDays * p.Baselines["all"].Spend
			w += p.Baselines["all"].Spend
		}
		for _, x := range p.Winners {
			out.Winners = append(out.Winners, p.Account+": "+x)
		}
	}
	if w > 0 {
		out.HalfLifeDays = round2(hw / w)
	}
	return out
}
