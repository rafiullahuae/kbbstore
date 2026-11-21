package brain

// daily.go — the morning's creation requests (engine/dailyplan.go). The owner, 21 Nov 2026: "the system should plan
// super well on daily basis". When the day's plan has new ads to launch and the library has no ad of his for their
// products, the requests are raised EARLY (with the daily plan, before the jobs' times) in "With me in Claude" mode,
// with ONE Mac notification for the day: "Today's ads: N to create — Start creating". In "Automatic (API)" mode
// nothing is raised: the jobs write their own copy at their time.

import (
	"fmt"
	"strings"
	"time"
)

// DailyAsk: one ad the day's plan needs and the library does not have.
type DailyAsk struct {
	JobID      string
	ProductIDs []string
	Product    string // its short title
	Lang       string // ar | en
	Format     string // 9:16 | 4:5 | 1:1 | carousel
	Angle      string
	When       time.Time
}

// CreationMode: with_me | api, as the owner set it (Settings → Creation).
func (e *Engine) CreationMode(acct string) string { return e.settings(acct).CreationMode }

// RaiseDaily: the day's creation requests (deduplicated against the open ones by product + angle) and one
// notification for the day. Returns how many requests are open for the day's asks (new + already open).
func (e *Engine) RaiseDaily(acct string, now time.Time, day string, asks []DailyAsk) int {
	if len(asks) == 0 || e.CreationMode(acct) != CreationWithMe {
		return 0
	}
	e.mu.Lock()
	reqs := e.creations(acct)
	open := map[string]bool{}
	for _, r := range reqs {
		if openReq(r.Status) {
			open[strings.Join(r.ProductIDs, ",")+"|"+strings.ToLower(r.Angle)] = true
		}
	}
	n, fresh := 0, 0
	for _, a := range asks {
		key := strings.Join(a.ProductIDs, ",") + "|" + strings.ToLower(a.Angle)
		n++
		if open[key] {
			continue
		}
		open[key] = true
		f, fAR := "ad", "إعلان"
		if a.Format == "9:16" {
			f, fAR = "Reels / Stories ad (9:16)", "إعلان ريلز / ستوريز (٩:١٦)"
		} else if a.Format == "carousel" {
			f, fAR = "carousel", "إعلان دوّار"
		} else if a.Format != "" {
			f, fAR = a.Format+" ad", "إعلان "+a.Format
		}
		r := CreationRequest{ID: fmt.Sprintf("cq%x", hash32(fmt.Sprint(day, a.JobID, a.ProductIDs, a.Angle))), At: now, Status: "open", Count: 1, Lang: a.Lang,
			Format: f, ProductIDs: a.ProductIDs, Product: a.Product, Angle: a.Angle}
		r.Title = fmt.Sprintf("Today %s: %s %s for %s — %s", a.When.Format("15:04"), strings.ToUpper(a.Lang), f, a.Product, a.Angle)
		r.TitleAR = fmt.Sprintf("اليوم %s: %s لـ %s — %s", a.When.Format("15:04"), fAR, a.Product, a.Angle)
		r.Brief = creationBrief(r, fmt.Sprintf("Today's plan, job %s at %s. Language %s first. Format %s. If it is not ready by then, the job runs with a template ad from the shop photo.", a.JobID, a.When.Format("15:04"), strings.ToUpper(a.Lang), f))
		reqs = append([]CreationRequest{r}, reqs...)
		fresh++
	}
	e.saveCreations(acct, reqs)
	sent := map[string]bool{}
	e.load(acct, "creation_daily", &sent)
	first := !sent[day]
	if first {
		sent[day] = true
		e.save(acct, "creation_daily", sent)
	}
	e.mu.Unlock()
	if first {
		e.notify(acct, Notice{Kind: "needed", Title: fmt.Sprintf("Today's ads: %d to create", n),
			Body: fmt.Sprintf("Today's ads: %d to create — Start creating", n), BodyAR: fmt.Sprintf("إعلانات اليوم: %d للإنشاء — ابدئي الإنشاء", n)})
	} else if fresh > 0 {
		e.push(acct)
	}
	return n
}
