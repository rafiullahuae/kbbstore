package main

// dailyplan.go — the Daily Plan, every morning (the owner, 21 Nov 2026: "the system should plan super well on daily
// basis"). Written once per local day for each automated account: at the first open of the day, or at 07:00 if the
// app is open then. Arabic + English, on Overview and Plan:
//
//	yesterday   spend (Meta), orders and net revenue from the SHOP (every channel), CPA and ROAS on those
//	changed     what the autopilot did since the last plan, with its reasons (the audit log)
//	today       the jobs with their times (batches, refills, iterations, campaigns, refreshes), and the hourly checks
//	watching    new ads in their first 72 h, weak signals, delivery problems, pacing
//	create      the ads today's jobs need that the library does not have: in "With me in Claude" mode they are raised
//	            as creation requests at once, with ONE Mac notification "Today's ads: N to create — Start creating"
//
// Jobs then run through the day at their times (scheduler.go → runDue). GET /api/today; POST /api/today/plan writes
// it again now; POST /api/today/check runs the hourly check now.

import (
	"context"
	"fmt"
	"net/http"
	"sort"
	"strings"
	"time"

	"kaa/brain"
)

type DayLine struct {
	At   time.Time `json:"at,omitempty"`
	Text string    `json:"text"`
	AR   string    `json:"ar"`
	Job  string    `json:"job,omitempty"`
}

type DailyPlan struct {
	Date      string    `json:"date"`
	At        time.Time `json:"at"`
	Trigger   string    `json:"trigger"` // open | morning | owner
	Level     string    `json:"level"`
	Yesterday struct {
		Date        string  `json:"date"`
		SpendAED    float64 `json:"spend"`
		ShopOrders  int     `json:"shop_orders"`
		ShopRevenue float64 `json:"shop_revenue"`
		MetaPurch   float64 `json:"meta_purchases"`
		CPA         float64 `json:"cpa"`
		ROAS        float64 `json:"roas"`
		Source      string  `json:"source"`
	} `json:"yesterday"`
	Changed  []DayLine `json:"changed"`
	Today    []DayLine `json:"today"`
	Watching []DayLine `json:"watching"`
	Create   struct {
		Needed int    `json:"needed"`
		Raised int    `json:"raised"`
		Mode   string `json:"mode"`
		Note   string `json:"note"`
		NoteAR string `json:"note_ar"`
	} `json:"create"`
	Live   int    `json:"live"`
	Target string `json:"target"`
	TextEN string `json:"text_en"`
	TextAR string `json:"text_ar"`
}

func (ac *Acct) dailyPlan() (DailyPlan, bool) {
	var d DailyPlan
	err := ac.st.Load("dailyplan", &d)
	return d, err == nil && d.Date != ""
}

// maybeDailyPlan: once per local day — at the first open (opened=true) or from 07:00. Returns true when written.
func (ac *Acct) maybeDailyPlan(ctx context.Context, opened bool) bool {
	now := ac.app.now().In(ac.loc())
	if d, ok := ac.dailyPlan(); ok && d.Date == now.Format("2006-01-02") {
		return false
	}
	if !opened && now.Hour() < 7 {
		return false
	}
	if !ac.work.TryLock() { // a cycle is running: the next tick tries again
		return false
	}
	defer ac.work.Unlock()
	trig := "morning"
	if opened {
		trig = "open"
	}
	ac.makeDailyPlan(ctx, trig)
	return true
}

// makeDailyPlan: the caller holds ac.work.
func (ac *Acct) makeDailyPlan(ctx context.Context, trigger string) DailyPlan {
	a := ac.app
	now := a.now()
	loc := ac.loc()
	lt := now.In(loc)
	in := ac.input()
	js := ac.jobSet()
	ac.planCadence(in, &js, now) // today's jobs exist before the plan is written
	ac.saveJobs(js)
	pb := *in.Playbook
	v := volumeFor(pb, in.Limits, ac.targetCPA(in), in.Tree)
	var d DailyPlan
	d.Date, d.At, d.Trigger, d.Level, d.Live = lt.Format("2006-01-02"), now.UTC(), trigger, v.Level, v.Live
	if v.LiveMax > 0 {
		d.Target = fmt.Sprintf("%d live prospecting ads (target %d–%d)", v.Live, v.LiveMin, v.LiveMax)
	} else {
		d.Target = fmt.Sprintf("%d live prospecting ads (Steady: the planner decides)", v.Live)
	}
	// yesterday: Meta spend, SHOP orders (every channel)
	y := lt.AddDate(0, 0, -1).Format("2006-01-02")
	d.Yesterday.Date, d.Yesterday.Source = y, "spend from Meta; orders and revenue from the shop (every channel)"
	for _, r := range in.Metrics {
		if r.Level == "campaign" && r.Date == y {
			d.Yesterday.SpendAED += r.SpendAED
			d.Yesterday.MetaPurch += r.Purchases
		}
	}
	for _, o := range in.Shop.Orders {
		if o.Date == y {
			d.Yesterday.ShopOrders += o.Orders
			d.Yesterday.ShopRevenue += o.NetRevenueAED
		}
	}
	d.Yesterday.SpendAED, d.Yesterday.ShopRevenue = round2(d.Yesterday.SpendAED), round2(d.Yesterday.ShopRevenue)
	if d.Yesterday.ShopOrders > 0 {
		d.Yesterday.CPA = round2(d.Yesterday.SpendAED / float64(d.Yesterday.ShopOrders))
	}
	if d.Yesterday.SpendAED > 0 {
		d.Yesterday.ROAS = round2(d.Yesterday.ShopRevenue / d.Yesterday.SpendAED)
	}
	// what it changed since the last plan (or 24 h)
	since := now.Add(-24 * time.Hour)
	if prev, ok := ac.dailyPlan(); ok && prev.At.After(since) {
		since = prev.At
	}
	for _, e := range ac.st.Recent(400, "execution", "kill", "volume", "safety") {
		if e.At.Before(since) || strings.HasPrefix(e.Summary, "jobs at their time") {
			continue
		}
		d.Changed = append(d.Changed, DayLine{At: e.At, Text: truncateRunes(e.Summary, 220), AR: truncateRunes(e.Summary, 220)})
	}
	if len(d.Changed) > 12 {
		d.Changed = d.Changed[len(d.Changed)-12:]
	}
	// today's jobs, with their times
	end := time.Date(lt.Year(), lt.Month(), lt.Day(), 23, 59, 59, 0, loc)
	var asks []brain.DailyAsk
	libHas := map[string]bool{} // product|lang the library already has an unused ad for (not a template)
	for _, c := range in.Creatives {
		if !c.Used && !c.Starter && c.Media != "" {
			for _, p := range c.ProductIDs {
				libHas[p+"|"+c.Lang] = true
			}
		}
	}
	titles := map[string]string{}
	for _, p := range in.Shop.Products {
		titles[p.ID] = shortTitle(p)
	}
	jobsToday := 0
	for _, j := range js.Jobs {
		if !open(j.Status) || j.When.After(end) {
			continue
		}
		jobsToday++
		at := j.When
		if at.Before(now) {
			at = now
		}
		line := DayLine{At: at, Job: j.ID, Text: fmt.Sprintf("%s · %s", at.In(loc).Format("15:04"), j.Title), AR: fmt.Sprintf("%s · %s", at.In(loc).Format("15:04"), firstNonEmpty(j.TitleAR, j.Title))}
		if j.Waiting != "" {
			line.Text += " (waiting: " + j.Waiting + ")"
			line.AR += " (ينتظر: " + j.Waiting + ")"
		}
		d.Today = append(d.Today, line)
		if (volKind(j.Kind) || j.Kind == "test" || j.Kind == "refresh") && len(j.ProductIDs) > 0 {
			ask := brain.DailyAsk{JobID: j.ID, ProductIDs: j.ProductIDs[:1], Product: titles[j.ProductIDs[0]], Lang: firstNonEmpty(j.Lang, "ar"), Angle: firstNonEmpty(j.CopyDirection, "benefit-first"), When: at.In(loc)}
			if j.Format == "carousel" {
				ask.Format, ask.ProductIDs = "carousel", j.ProductIDs
			} else if i := strings.Index(j.Title, "· 9:16"); i >= 0 {
				ask.Format = "9:16"
			} else if strings.Contains(j.Title, "· 4:5") {
				ask.Format = "4:5"
			} else {
				ask.Format = "1:1"
			}
			if !libHas[ask.ProductIDs[0]+"|"+ask.Lang] {
				asks = append(asks, ask)
			}
		}
	}
	syncEvery, _ := a.intervals()
	d.Today = append(d.Today, DayLine{At: now.Add(syncEvery), Text: fmt.Sprintf("Every %s while the app is open: sync with Meta, the rules, and the new ads' early signals (next %s)", syncEvery, now.Add(syncEvery).In(loc).Format("15:04")),
		AR: fmt.Sprintf("كل %s والتطبيق مفتوح: مزامنة مع Meta والقواعد وإشارات الإعلانات الجديدة (التالية %s)", syncEvery, now.Add(syncEvery).In(loc).Format("15:04"))})
	sort.SliceStable(d.Today, func(i, k int) bool { return d.Today[i].At.Before(d.Today[k].At) })
	// what it is watching
	w := ac.watchState()
	nNew := 0
	for _, it := range w.Items {
		if it.New {
			nNew++
			if it.Early.Verdict == "weak" {
				d.Watching = append(d.Watching, DayLine{Text: it.Name + ": " + it.Early.Why, AR: it.Name + ": " + it.Early.WhyAR})
			}
		}
	}
	if nNew > 0 {
		d.Watching = append([]DayLine{{Text: fmt.Sprintf("%d new ad(s) in their first 72 h, checked every hour against the account's medians", nNew), AR: fmt.Sprintf("%d إعلانات جديدة في أول ٧٢ ساعة، تُفحص كل ساعة مقابل متوسطات الحساب", nNew)}}, d.Watching...)
	}
	for _, p := range w.Problems {
		d.Watching = append(d.Watching, DayLine{Text: p.Text, AR: p.TextAR})
	}
	if w.Pacing.State != "" {
		d.Watching = append(d.Watching, DayLine{Text: "Pacing: " + w.Pacing.Why, AR: "وتيرة الإنفاق: " + w.Pacing.WhyAR})
	}
	for _, s := range v.Campaigns {
		if s.N >= 2 && s.Status == "needs_budget" {
			d.Watching = append(d.Watching, DayLine{Text: s.Why, AR: s.WhyAR})
			break
		}
	}
	// the ads to create today
	a.mu.RLock()
	br := a.brain
	a.mu.RUnlock()
	d.Create.Needed = len(asks)
	if rd, ok := br.(interface {
		CreationMode(string) string
		RaiseDaily(string, time.Time, string, []brain.DailyAsk) int
	}); ok && rd.CreationMode(ac.id) == brain.CreationWithMe {
		d.Create.Mode = "with_me"
		d.Create.Raised = rd.RaiseDaily(ac.id, now, d.Date, asks)
		if d.Create.Needed > 0 {
			d.Create.Note = fmt.Sprintf("Today's ads: %d to create with Claude (Create → Start creating). A job whose ad is not ready at its time runs with a template ad from the shop photo.", d.Create.Needed)
			d.Create.NoteAR = fmt.Sprintf("إعلانات اليوم: %d للإنشاء مع Claude (إنشاء ← ابدئي الإنشاء). إن لم يجهز الإعلان في وقته تعمل المهمة بإعلان قالب من صورة المتجر.", d.Create.Needed)
		}
	} else {
		d.Create.Mode = "api"
		if d.Create.Needed > 0 {
			d.Create.Note = fmt.Sprintf("%d ads are written automatically at their time (Automatic mode).", d.Create.Needed)
			d.Create.NoteAR = fmt.Sprintf("%d إعلانات تُكتب تلقائياً في وقتها (الوضع التلقائي).", d.Create.Needed)
		}
	}
	if d.Create.Needed == 0 {
		d.Create.Note, d.Create.NoteAR = "Nothing to create today: the library covers today's jobs.", "لا شيء للإنشاء اليوم: المكتبة تغطي مهام اليوم."
	}
	// the written plan
	var en, ar strings.Builder
	yy := d.Yesterday
	fmt.Fprintf(&en, "Daily plan · %s (%s level)\n", d.Date, v.Level)
	fmt.Fprintf(&ar, "خطة اليوم · %s (المستوى: %s)\n", d.Date, map[string]string{"steady": "هادئ", "active": "نشِط", "aggressive": "جريء"}[v.Level])
	fmt.Fprintf(&en, "Yesterday: AED %.0f spent, %d shop orders (AED %.0f)", yy.SpendAED, yy.ShopOrders, yy.ShopRevenue)
	fmt.Fprintf(&ar, "أمس: إنفاق %.0f درهم، %d طلبات من المتجر (%.0f درهم)", yy.SpendAED, yy.ShopOrders, yy.ShopRevenue)
	if yy.ShopOrders > 0 {
		fmt.Fprintf(&en, ", CPA AED %.0f", yy.CPA)
		fmt.Fprintf(&ar, "، تكلفة الطلب %.0f درهم", yy.CPA)
	}
	if yy.SpendAED > 0 {
		fmt.Fprintf(&en, ", ROAS %.2f", yy.ROAS)
		fmt.Fprintf(&ar, "، العائد على الإنفاق %.2f", yy.ROAS)
	}
	en.WriteString(" (orders: shop, every channel).\n")
	ar.WriteString(" (الطلبات من المتجر، كل القنوات).\n")
	fmt.Fprintf(&en, "Now: %s. Today: %d job(s); %s\n", d.Target, jobsToday, d.Create.Note)
	fmt.Fprintf(&ar, "الآن: %d إعلاناً نشطاً. اليوم: %d مهام؛ %s\n", v.Live, jobsToday, d.Create.NoteAR)
	d.TextEN, d.TextAR = strings.TrimSpace(en.String()), strings.TrimSpace(ar.String())
	if d.Changed == nil {
		d.Changed = []DayLine{}
	}
	if d.Watching == nil {
		d.Watching = []DayLine{}
	}
	_ = ac.st.Save("dailyplan", d)
	ac.st.Append("plan", fmt.Sprintf("daily plan for %s (%s): %d job(s) today, %d ad(s) to create, %s", d.Date, trigger, jobsToday, d.Create.Needed, d.Target), map[string]any{"date": d.Date, "jobs": jobsToday, "create": d.Create.Needed})
	ac.publish("dailyplan", map[string]any{"account": ac.id, "date": d.Date})
	return d
}

// ── API ──

func (s *server) todayAPI(w http.ResponseWriter, r *http.Request, ac *Acct, rest string) {
	switch {
	case rest == "" && r.Method == http.MethodGet:
	case rest == "plan" && r.Method == http.MethodPost:
		if !ac.work.TryLock() {
			jsonErr(w, 409, errBusy.Error())
			return
		}
		ac.makeDailyPlan(r.Context(), "owner")
		ac.work.Unlock()
	case rest == "check" && r.Method == http.MethodPost:
		if !ac.work.TryLock() {
			jsonErr(w, 409, errBusy.Error())
			return
		}
		if s.app.metaOK() {
			if err := ac.Sync(r.Context()); err != nil {
				ac.work.Unlock()
				jsonErr(w, 502, "cannot read the account: "+err.Error())
				return
			}
		}
		ac.watch(r.Context())
		ac.work.Unlock()
	default:
		jsonErr(w, 404, "GET /api/today, POST /api/today/plan, POST /api/today/check")
		return
	}
	in := ac.input()
	v := volumeFor(*in.Playbook, in.Limits, ac.targetCPA(in), in.Tree)
	out := map[string]any{"account": ac.id, "watch": ac.watchState(), "volume": v, "now": s.app.now()}
	if d, ok := ac.dailyPlan(); ok {
		out["daily_plan"] = d
	}
	writeJSON(w, out)
}
