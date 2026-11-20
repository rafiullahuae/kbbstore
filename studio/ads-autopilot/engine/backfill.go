package main

// backfill.go — the history import (the owner, 20 Nov 2026: "read the whole business portfolio … and train itself").
// On the first connect of an account: every campaign / ad set / ad ever (archived and deleted too) with what kind of
// creative each ad was, then Insights as far back as Meta keeps them (37 months; error 3018 beyond):
// campaign and ad-set level by day, ad level by month, one calendar month per request, oldest first. Paced by the
// Meta client (Limited tier: 60 points / 300 s), resumable (history/state.json keeps the next month per level), and
// reported over SSE as "backfill.progress". Afterwards the portfolio-wide learnings are recomputed from every
// account's history (learnings.json): spend, purchases, CPA and ROAS by ad format, by objective and by country.

import (
	"context"
	"fmt"
	"math"
	"regexp"
	"sort"
	"time"

	"kaa/model"
)

var e164 = regexp.MustCompile(`^\+[1-9][0-9]{7,14}$`)

const historyMonths = 37

type BackfillState struct {
	State     string            `json:"state"` // "" | running | done | error
	Objects   bool              `json:"objects_done"`
	Next      map[string]string `json:"next"` // level → next month (YYYY-MM) to read
	Done      int               `json:"done"` // months read (all levels)
	Total     int               `json:"total"`
	Error     string            `json:"error,omitempty"`
	StartedAt time.Time         `json:"started_at"`
	EndedAt   time.Time         `json:"ended_at,omitempty"`
	Rows      int               `json:"rows"`
}

var historyLevels = []struct{ level, increment string }{{"campaign", "1"}, {"adset", "1"}, {"ad", "monthly"}}

func (a *App) startBackfill(ac *Acct) {
	if a.noBackfill {
		return
	}
	ac.mu.Lock()
	if ac.bfOn || ac.backfill.State == "done" {
		ac.mu.Unlock()
		return
	}
	ac.bfOn = true
	ac.mu.Unlock()
	go func() {
		defer func() { ac.mu.Lock(); ac.bfOn = false; ac.mu.Unlock() }()
		ctx, cancel := context.WithTimeout(context.Background(), 6*time.Hour)
		defer cancel()
		if err := ac.Backfill(ctx); err != nil {
			ac.st.Append("error", "history import: "+err.Error(), nil)
		}
	}()
}

// Backfill runs (or resumes) the import. Safe to call again: it continues where it stopped.
func (ac *Acct) Backfill(ctx context.Context) error {
	a := ac.app
	if !a.metaOK() {
		return fmt.Errorf("Meta is not connected")
	}
	a.mu.RLock()
	mc := a.meta
	a.mu.RUnlock()
	now := a.now().In(ac.loc())
	first := time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, now.Location()).AddDate(0, -(historyMonths - 1), 0)
	ac.mu.Lock()
	bf := ac.backfill
	if bf.Next == nil {
		bf.Next = map[string]string{}
		for _, l := range historyLevels {
			bf.Next[l.level] = first.Format("2006-01")
		}
		bf.StartedAt = a.now().UTC()
	}
	bf.State, bf.Error, bf.Total = "running", "", historyMonths*len(historyLevels)
	ac.backfill = bf
	ac.mu.Unlock()
	save := func() {
		ac.mu.Lock()
		ac.backfill, ac.stateJSON = bf, nil
		ac.mu.Unlock()
		_ = ac.st.Save("history/state", bf)
		ac.publish("backfill.progress", bf)
	}
	fail := func(err error) error {
		bf.State, bf.Error = "error", err.Error()
		save()
		return err
	}
	save()
	if !bf.Objects {
		tree, crs, err := mc.AllObjects(ctx, ac.id)
		if err != nil {
			return fail(err)
		}
		_ = ac.st.Save("history/objects", map[string]any{"tree": tree, "creatives": crs})
		bf.Objects = true
		save()
	}
	end := now.Format("2006-01")
	for _, l := range historyLevels {
		var rows []model.MetricRow
		_ = ac.st.Load("history/"+l.level, &rows)
		for bf.Next[l.level] <= end {
			if ctx.Err() != nil {
				return fail(ctx.Err())
			}
			m, err := time.ParseInLocation("2006-01", bf.Next[l.level], now.Location())
			if err != nil {
				return fail(err)
			}
			until := m.AddDate(0, 1, -1)
			if until.After(now) {
				until = now
			}
			r, err := mc.InsightsRange(ctx, ac.id, l.level, m.Format("2006-01-02"), until.Format("2006-01-02"), l.increment)
			if err != nil {
				return fail(fmt.Errorf("%s %s: %w", l.level, bf.Next[l.level], err))
			}
			rows = append(rows, r...)
			bf.Rows += len(r)
			bf.Next[l.level] = m.AddDate(0, 1, 0).Format("2006-01")
			bf.Done++
			if bf.Done%6 == 0 || bf.Next[l.level] > end {
				_ = ac.st.Save("history/"+l.level, rows)
				save()
			}
		}
		_ = ac.st.Save("history/"+l.level, rows)
	}
	bf.State, bf.EndedAt, bf.Done = "done", a.now().UTC(), bf.Total
	save()
	ac.st.Append("snapshot", fmt.Sprintf("history import done: %d rows over %d months", bf.Rows, historyMonths), bf)
	a.recomputeLearnings()
	return nil
}

// longHistory: what the brain gets: campaign-level daily rows (all of them) and every ad's creative kind.
func (ac *Acct) longHistory() ([]model.MetricRow, []model.HistoryCreative) {
	var rows []model.MetricRow
	_ = ac.st.Load("history/campaign", &rows)
	var obj struct {
		Creatives []model.HistoryCreative `json:"creatives"`
	}
	_ = ac.st.Load("history/objects", &obj)
	return rows, obj.Creatives
}

// recomputeLearnings: by format (ad level × the ad's creative kind), objective and country, per account.
func (a *App) recomputeLearnings() {
	var out []model.Learning
	for _, ac := range a.Accounts() {
		var ads []model.MetricRow
		_ = ac.st.Load("history/ad", &ads)
		var obj struct {
			Tree      []model.Campaign        `json:"tree"`
			Creatives []model.HistoryCreative `json:"creatives"`
		}
		_ = ac.st.Load("history/objects", &obj)
		if len(ads) == 0 && len(obj.Tree) == 0 {
			continue
		}
		format := map[string]string{}
		for _, c := range obj.Creatives {
			format[c.AdID] = c.Format
		}
		objective, country := map[string]string{}, map[string]string{}
		for _, c := range obj.Tree {
			for _, s := range c.AdSets {
				for _, ad := range s.Ads {
					objective[ad.ID] = c.Objective
					if len(s.Countries) == 1 {
						country[ad.ID] = s.Countries[0]
					} else if len(s.Countries) > 1 {
						country[ad.ID] = "multi"
					}
				}
			}
		}
		type acc struct {
			l   model.Learning
			ads map[string]bool
		}
		agg := map[string]*acc{}
		add := func(dim, key string, r model.MetricRow) {
			if key == "" {
				return
			}
			k := dim + "|" + key
			x := agg[k]
			if x == nil {
				x = &acc{l: model.Learning{AccountID: ac.id, Dimension: dim, Key: key, From: r.Date, To: r.Date}, ads: map[string]bool{}}
				agg[k] = x
			}
			x.l.SpendAED += r.SpendAED
			x.l.Purchases += r.Purchases
			x.l.Revenue += r.PurchaseValueAED
			x.ads[r.ObjectID] = true
			if r.Date < x.l.From {
				x.l.From = r.Date
			}
			if r.Date > x.l.To {
				x.l.To = r.Date
			}
		}
		for _, r := range ads {
			add("format", format[r.ObjectID], r)
			add("objective", objective[r.ObjectID], r)
			add("country", country[r.ObjectID], r)
		}
		for _, x := range agg {
			l := x.l
			l.Ads = len(x.ads)
			if l.Purchases > 0 {
				l.CPA = math.Round(l.SpendAED/l.Purchases*100) / 100
			}
			if l.SpendAED > 0 {
				l.ROAS = math.Round(l.Revenue/l.SpendAED*100) / 100
			}
			l.SpendAED, l.Revenue = math.Round(l.SpendAED*100)/100, math.Round(l.Revenue*100)/100
			out = append(out, l)
		}
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].AccountID != out[j].AccountID {
			return out[i].AccountID < out[j].AccountID
		}
		if out[i].Dimension != out[j].Dimension {
			return out[i].Dimension < out[j].Dimension
		}
		return out[i].SpendAED > out[j].SpendAED
	})
	a.mu.Lock()
	a.learnings = out
	a.mu.Unlock()
	_ = a.st.Save("learnings", out)
	a.st.Append("research", fmt.Sprintf("portfolio learnings: %d aggregates", len(out)), nil)
}
