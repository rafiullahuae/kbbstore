package main

// acct.go — one ad account, automated on its own (the owner, 20 Nov 2026: "give multiple ad accounts to automate
// separately"). Its data folder is accounts/<act_id>/: settings (automated, limits, linked assets), the audit log,
// snapshots, metrics, plan, report, alerts, the executor's ledger (cooldowns, idempotency), the safety-net state,
// its creative library (image hashes are per ad account) and its history import.

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"kaa/executor"
	"kaa/meta"
	"kaa/model"
	"kaa/safety"
	"kaa/store"
)

type AcctSettings struct {
	Automated bool               `json:"automated"` // the owner switched this account on; off = read only
	Limits    model.Limits       `json:"limits"`
	Links     model.AccountLinks `json:"links"`
}

type linkCheck struct {
	OK    bool   `json:"ok"`
	Name  string `json:"name,omitempty"`
	Error string `json:"error,omitempty"`
}

type Acct struct {
	id  string
	app *App
	st  *store.Store

	mu        sync.RWMutex
	settings  AcctSettings
	account   model.Account
	tree      []model.Campaign
	metrics   map[string]model.MetricRow
	plan      model.Plan
	report    DailyReport
	alerts    []model.Alert
	lastSync  time.Time
	lastCycle time.Time
	nextSync  time.Time
	nextCycle time.Time
	cycleOn   bool
	stateJSON []byte
	linkState map[string]linkCheck
	backfill  BackfillState
	pending   PendingSet       // the last cycle's proposals and what became of each (Approvals)
	starter   StarterState     // the first launch (starter.go)
	pb        *model.Playbook  // the owner's Playbook (playbook.go); nil = the defaults
	audiences []model.Audience // custom audiences with Meta's sizes, read every sync
	jobs      JobSet           // the editable job queue (jobs.go)

	work        sync.Mutex  // sync / cycle / kill on this account: one at a time
	repriceMu   sync.Mutex  // the price-correction plan (reprice.go)
	manualCycle atomic.Bool // the running cycle is the owner's own "Run a cycle" (starter.go: Meta-setting wait)
	cycleCancel context.CancelFunc
	bfOn        bool

	ledger    *executor.Ledger
	exec      *executor.Executor
	safety    *safety.Net
	creatives *creativeLib
}

func openAcct(a *App, id string) (*Acct, error) {
	st, err := store.Open(filepath.Join(a.st.Dir, "accounts", id))
	if err != nil {
		return nil, err
	}
	for _, v := range a.secretValues() {
		st.AddSecret(v)
	}
	ac := &Acct{id: id, app: a, st: st, metrics: map[string]model.MetricRow{}, linkState: map[string]linkCheck{}}
	ac.settings.Limits = model.DefaultLimits()
	if err := st.Load("settings", &ac.settings); err != nil { // never silently back to the defaults (store keeps a copy)
		st.Append("alert", "This account's saved limits could not be read: "+err.Error()+". Check Settings → Limits before turning Auto on.", model.Alert{At: a.now().UTC(), Level: "warn", Text: err.Error()})
	}
	var snap struct {
		Account  model.Account     `json:"account"`
		Tree     []model.Campaign  `json:"tree"`
		Metrics  []model.MetricRow `json:"metrics"`
		LastSync time.Time         `json:"last_sync"`
	}
	_ = st.Load("snapshots/latest", &snap)
	ac.account, ac.tree, ac.lastSync = snap.Account, snap.Tree, snap.LastSync
	for _, r := range snap.Metrics {
		ac.metrics[mkey(r)] = r
	}
	_ = st.Load("plan", &ac.plan)
	_ = st.Load("report", &ac.report)
	_ = st.Load("alerts", &ac.alerts)
	_ = st.Load("history/state", &ac.backfill)
	_ = st.Load("proposals", &ac.pending)
	_ = st.Load("starter", &ac.starter)
	_ = st.Load("audiences", &ac.audiences)
	_ = st.Load("jobs", &ac.jobs)
	ac.loadPlaybook()
	var ls struct{ LastCycle time.Time }
	_ = st.Load("cycle", &ls)
	ac.lastCycle = ls.LastCycle
	ac.ledger = executor.NewLedger()
	_ = st.Load("ledger", ac.ledger)
	ac.creatives = newCreativeLib(st)
	return ac, nil
}

// secretValues: the saved keys, so every account's audit log redacts them too.
func (a *App) secretValues() []string {
	var out []string
	for _, n := range []string{"meta_token", "meta_app_secret", "anthropic_key", "shop_token"} {
		if v, _ := a.sec.Get(n); v != "" {
			out = append(out, v)
		}
	}
	return out
}

func mkey(r model.MetricRow) string { return r.Level + "|" + r.ObjectID + "|" + r.Date }

func (ac *Acct) wire() {
	a := ac.app
	a.mu.RLock()
	mc := a.meta
	a.mu.RUnlock()
	act := func() string { return ac.id }
	sn := &safety.Net{Meta: mc, Act: act, Now: a.now, Log: ac.logf, Persist: func(s *safety.State) { _ = ac.st.Save("safety", s) }}
	var ss safety.State
	_ = ac.st.Load("safety", &ss)
	sn.State = &ss
	ex := &executor.Executor{Meta: mc, Act: act, Ledger: ac.ledger, Creatives: ac.creatives, Log: ac.logf,
		Links:     func() model.AccountLinks { ac.mu.RLock(); defer ac.mu.RUnlock(); return ac.settings.Links },
		Persist:   func(l *executor.Ledger) { _ = ac.st.Save("ledger", l.Snapshot()) },
		VideoFile: a.videoFile,
		Safety: func(ctx context.Context) error { // after any write: re-read Meta, then size the net to what now runs
			if err := ac.Sync(ctx); err != nil {
				ac.alertOnce("Sync after changes failed: " + err.Error())
			}
			_, err := ac.ensureSafety(ctx)
			return err
		}}
	ac.mu.Lock()
	ac.safety, ac.exec = sn, ex
	ac.mu.Unlock()
}

func (ac *Acct) Automated() bool {
	ac.mu.RLock()
	defer ac.mu.RUnlock()
	return ac.settings.Automated
}

func (ac *Acct) Settings() AcctSettings {
	ac.mu.RLock()
	defer ac.mu.RUnlock()
	return ac.settings
}

// accountRef: the account as last read, with its id even before the first sync. The brain keys its own files by
// that id: a plan made before the first sync was stored under "" and the screens, reading after a sync, saw none.
// Caller holds ac.mu (read).
func (ac *Acct) accountRef() model.Account {
	acc := ac.account
	if acc.ID == "" {
		acc.ID = ac.id
	}
	return acc
}

func (ac *Acct) saveSettings() {
	ac.mu.RLock()
	s := ac.settings
	ac.mu.RUnlock()
	_ = ac.st.Save("settings", s)
}

func (ac *Acct) dirty() {
	ac.mu.Lock()
	ac.stateJSON = nil
	ac.mu.Unlock()
}

func (ac *Acct) publish(name string, v any) {
	if ac.app.publish != nil {
		ac.app.publish(name, map[string]any{"account": ac.id, "data": v})
	}
}

func (ac *Acct) logf(kind, summary string, data any) {
	e := ac.st.Append(kind, summary, data)
	if kind == "execution" || kind == "alert" || kind == "safety" || kind == "kill" {
		ac.publish("action."+kind, e)
	}
}

func (ac *Acct) alert(level, text string) {
	al := model.Alert{At: ac.app.now().UTC(), Level: level, Text: text}
	ac.mu.Lock()
	ac.alerts = append(ac.alerts, al)
	if len(ac.alerts) > 100 {
		ac.alerts = ac.alerts[len(ac.alerts)-100:]
	}
	alerts := append([]model.Alert(nil), ac.alerts...)
	ac.stateJSON = nil
	ac.mu.Unlock()
	_ = ac.st.Save("alerts", alerts)
	ac.st.Append("alert", text, al)
	ac.publish("alert", al)
}

// alertOnce: the same text is not repeated within 24 h.
func (ac *Acct) alertOnce(text string) {
	ac.mu.RLock()
	for _, al := range ac.alerts {
		if al.Text == text && ac.app.now().Sub(al.At) < 24*time.Hour {
			ac.mu.RUnlock()
			return
		}
	}
	ac.mu.RUnlock()
	ac.alert("warn", text)
}

func (ac *Acct) loc() *time.Location {
	ac.mu.RLock()
	tz := ac.account.Timezone
	ac.mu.RUnlock()
	if tz != "" {
		if l, err := time.LoadLocation(tz); err == nil {
			return l
		}
	}
	l, _ := time.LoadLocation("Asia/Dubai")
	return l
}

// checkLinks: a live read of every linked asset (Settings → Connections → Meta shows each one green or red).
func (ac *Acct) checkLinks(ctx context.Context) map[string]linkCheck {
	ac.app.mu.RLock()
	mc := ac.app.meta
	ac.app.mu.RUnlock()
	l := ac.Settings().Links
	out := map[string]linkCheck{}
	for _, c := range []struct{ key, id, fields string }{
		{"page", l.PageID, "id,name"}, {"instagram", l.InstagramUserID, "id,username"}, {"pixel", l.PixelID, "id,name,last_fired_time"}, {"catalog", l.CatalogID, "id,name,product_count"},
	} {
		if c.id == "" {
			continue
		}
		m, err := mc.CheckAsset(ctx, c.id, c.fields)
		if err != nil {
			out[c.key] = linkCheck{Error: err.Error()}
			continue
		}
		n, _ := m["name"].(string)
		if n == "" {
			n, _ = m["username"].(string)
		}
		out[c.key] = linkCheck{OK: true, Name: n}
	}
	if l.WhatsAppNumber != "" { // the number itself has no read the token can do; its format is checked
		out["whatsapp"] = linkCheck{OK: e164.MatchString(l.WhatsAppNumber), Name: l.WhatsAppNumber}
		if !out["whatsapp"].OK {
			out["whatsapp"] = linkCheck{Error: "not an international number like +9715XXXXXXXX"}
		}
	}
	ac.mu.Lock()
	ac.linkState, ac.stateJSON = out, nil
	ac.mu.Unlock()
	return out
}

// ── sync ──

// Sync pulls the account, the tree and Insights (catch-up since the last run, re-reading the 2 settling days).
func (ac *Acct) Sync(ctx context.Context) error {
	a := ac.app
	if !a.metaOK() {
		return errors.New("Meta is not connected")
	}
	a.mu.RLock()
	mc := a.meta
	a.mu.RUnlock()
	ac.mu.RLock()
	last := ac.lastSync
	ac.mu.RUnlock()
	acc, err := mc.Account(ctx, ac.id)
	if err != nil {
		return err
	}
	if acc.Currency != "AED" {
		return fmt.Errorf("%s is in %s; this app only works in AED", ac.id, acc.Currency)
	}
	loc := ac.loc()
	if l, e := time.LoadLocation(acc.Timezone); e == nil {
		loc = l
	}
	tree, err := mc.Tree(ctx, ac.id)
	if err != nil {
		return err
	}
	today := a.now().In(loc)
	since := today.AddDate(0, 0, -29)
	if !last.IsZero() {
		if s := last.In(loc).AddDate(0, 0, -2); s.After(since) { // the settling days are read again
			since = s
		}
	}
	sinceS, untilS := since.Format("2006-01-02"), today.Format("2006-01-02")
	var rows []model.MetricRow
	for _, lvl := range []string{"campaign", "adset", "ad"} {
		r, err := mc.Insights(ctx, ac.id, lvl, sinceS, untilS)
		if err != nil {
			return fmt.Errorf("insights (%s): %w", lvl, err)
		}
		rows = append(rows, r...)
	}
	settle := today.AddDate(0, 0, -1).Format("2006-01-02")
	keep := today.AddDate(0, 0, -90).Format("2006-01-02")
	ac.mu.Lock()
	acc.PageID, acc.PixelID = ac.settings.Links.PageID, ac.settings.Links.PixelID
	ac.account, ac.tree = acc, tree
	for _, r := range rows {
		ac.metrics[mkey(r)] = r
	}
	all := make([]model.MetricRow, 0, len(ac.metrics))
	for k, r := range ac.metrics {
		if r.Date < keep {
			delete(ac.metrics, k)
			continue
		}
		r.Settling = r.Date >= settle
		ac.metrics[k] = r
		all = append(all, r)
	}
	ac.lastSync = a.now()
	ac.stateJSON = nil
	snap := map[string]any{"account": acc, "tree": tree, "metrics": all, "last_sync": ac.lastSync}
	ac.mu.Unlock()
	_ = ac.st.Save("snapshots/latest", snap)
	_ = ac.st.Save("snapshots/"+today.Format("2006-01-02"), map[string]any{"account": acc, "tree": tree, "at": a.now().UTC()})
	ac.st.Append("snapshot", fmt.Sprintf("sync %s..%s: %d campaigns, %d metric rows; spend cap %.2f AED", sinceS, untilS, len(tree), len(rows), acc.SpendCapAED),
		map[string]any{"since": sinceS, "until": untilS, "campaigns": len(tree), "rows": len(rows), "account": acc})
	ac.publish("metrics.updated", map[string]any{"at": ac.lastSync, "rows": len(rows)})
	// the account's custom audiences with Meta's size estimates (the funnel waits for a big-enough one). Not fatal.
	if aus, err := mc.Audiences(ctx, ac.id); err == nil {
		ac.mu.Lock()
		ac.audiences, ac.stateJSON = aus, nil
		ac.mu.Unlock()
		_ = ac.st.Save("audiences", aus)
	}
	return nil
}

func (ac *Acct) metricRows() []model.MetricRow {
	ac.mu.RLock()
	defer ac.mu.RUnlock()
	out := make([]model.MetricRow, 0, len(ac.metrics))
	for _, r := range ac.metrics {
		out = append(out, r)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Date != out[j].Date {
			return out[i].Date < out[j].Date
		}
		return out[i].ObjectID < out[j].ObjectID
	})
	return out
}

// monthSpend: campaign-level spend this calendar month (account time zone), the whole account.
// todaySpendApp: what the app's own campaigns spent today (the Overview's "Today … / daily limit").
func (ac *Acct) todaySpendApp() float64 {
	day := ac.app.now().In(ac.loc()).Format("2006-01-02")
	ac.mu.RLock()
	defer ac.mu.RUnlock()
	ours := map[string]bool{}
	for _, c := range ac.tree {
		if c.Managed {
			ours[c.ID] = true
		}
	}
	s := 0.0
	for _, r := range ac.metrics {
		if r.Level == "campaign" && r.Date == day && (ours[r.ObjectID] || strings.HasPrefix(r.Name, meta.ManagedPrefix)) {
			s += r.SpendAED
		}
	}
	return s
}

func (ac *Acct) monthSpend() float64 {
	prefix := ac.app.now().In(ac.loc()).Format("2006-01")
	ac.mu.RLock()
	defer ac.mu.RUnlock()
	// the app's own campaigns only (22 Nov: his limits are for the autopilot's ads; his own spend is ignored)
	ours := map[string]bool{}
	for _, c := range ac.tree {
		if c.Managed {
			ours[c.ID] = true
		}
	}
	s := 0.0
	for _, r := range ac.metrics {
		if r.Level == "campaign" && strings.HasPrefix(r.Date, prefix) && (ours[r.ObjectID] || strings.HasPrefix(r.Name, meta.ManagedPrefix)) {
			s += r.SpendAED
		}
	}
	return s
}

func (ac *Acct) ensureSafety(ctx context.Context) (safety.Report, error) {
	if !ac.app.metaOK() {
		return safety.Report{}, errors.New("Meta is not connected")
	}
	ac.mu.RLock()
	never := ac.lastSync.IsZero()
	ac.mu.RUnlock()
	if never { // the cap needs this month's spend: read it first
		if err := ac.Sync(ctx); err != nil {
			return safety.Report{}, fmt.Errorf("cannot size the spending limit before a sync: %w", err)
		}
	}
	ac.mu.RLock()
	in := safety.Input{Limits: ac.settings.Limits, Tree: ac.tree,
		// his own campaigns' budgets for the rest of the month: added to the account limit, never to his limits
		OwnRemainingAED: executor.CommittedDailyOwn(ac.tree) * float64(executor.DaysLeftInMonth(ac.app.now().In(ac.loc())))}
	sn := ac.safety
	auto := ac.settings.Automated
	ac.mu.RUnlock()
	if !auto {
		return safety.Report{}, errors.New("this account is not automated by the app")
	}
	in.MonthSpendAED = ac.monthSpend()
	rep, err := sn.Ensure(ctx, in)
	for _, al := range rep.Alerts {
		ac.alertOnce(al)
	}
	if err != nil {
		ac.alert("error", "Meta safety net: "+err.Error())
	}
	ac.dirty()
	return rep, err
}

// ── the cycle ──

var errBusy = errors.New("a cycle is already running on this account")

// errUpdating: the autopilot is paused while an update installs (update_flow.go); it resumes by itself after
var errUpdating = errors.New("an update is being installed; the autopilot resumes by itself in a moment")

// others: the global-cap inputs: what the OTHER automated accounts commit.
func (ac *Acct) others() (committed, monthProjected float64) {
	for _, o := range ac.app.Accounts() {
		if o == ac || !o.Automated() {
			continue
		}
		o.mu.RLock()
		c := executor.CommittedDaily(o.tree)
		o.mu.RUnlock()
		committed += c
		monthProjected += o.monthSpend() + c*float64(executor.DaysLeftInMonth(ac.app.now().In(o.loc())))
	}
	return
}

// Cycle: sync → brain → executor → safety net → daily summary, for this account only. mode "shadow" never writes;
// "auto" writes only when this account's own setting is auto too and the account is automated.
func (ac *Acct) Cycle(parent context.Context, mode string) (map[string]any, error) {
	if ac.app.updating.Load() {
		return nil, errUpdating
	}
	if !ac.work.TryLock() {
		return nil, errBusy
	}
	defer ac.work.Unlock()
	a := ac.app
	ctx, cancel := context.WithTimeout(parent, 20*time.Minute)
	ac.mu.Lock()
	ac.cycleCancel, ac.cycleOn = cancel, true
	ac.mu.Unlock()
	defer func() {
		cancel()
		ac.mu.Lock()
		ac.cycleCancel, ac.cycleOn, ac.stateJSON = nil, false, nil
		ac.mu.Unlock()
	}()
	t0 := a.now()
	ac.publish("cycle.start", map[string]any{"at": t0, "mode": mode})
	ac.st.Append("cycle", "cycle start ("+mode+")", map[string]any{"mode": mode})
	out := map[string]any{"mode": mode, "account": ac.id}
	if err := ac.Sync(ctx); err != nil {
		ac.alertOnce("Sync with Meta failed: " + err.Error())
		out["sync_error"] = err.Error()
	} else if msg := ac.syncIdentity(ctx); msg != "" { // the Page / Instagram of his own ads (identity.go)
		out["identity_changed"] = msg
	}
	a.mu.RLock()
	shopSnap, research, learnings, br := a.shopSnap, a.research, a.learnings, a.brain
	a.mu.RUnlock()
	ac.mu.RLock()
	st := ac.settings
	in := model.CycleInput{Now: a.now(), Limits: st.Limits, Account: ac.accountRef(), Tree: cloneTree(ac.tree), Shop: shopSnap, Research: research, Links: st.Links, Learnings: learnings,
		Audiences: append([]model.Audience(nil), ac.audiences...)}
	ex := ac.exec
	ac.mu.RUnlock()
	if said := ac.stockAlerts(shopSnap); len(said) > 0 { // alerts only, never a pause (readiness.go)
		out["stock_alerts"] = len(said)
	}
	// the owner's Playbook, in code, before the brain or the funnel builder sees one product (playbook.go)
	fshop, pb, excluded := ac.filteredShop(shopSnap)
	in.Shop, in.Playbook, in.JobNotes = fshop, &pb, ac.jobNotes()
	if len(excluded) > 0 {
		out["playbook_excluded"] = len(excluded)
	}
	in.Metrics = ac.metricRows()
	in.Creatives = ac.creatives.all()
	in.History = ac.st.Recent(200, "execution", "check", "cycle", "kill", "alert", "safety")
	in.LongHistory, in.Creatives37 = ac.longHistory()
	co, err := br.Cycle(ctx, in)
	if err != nil {
		ac.alert("error", "The planner failed: "+err.Error()+" (nothing was changed)")
		out["error"] = err.Error()
		ac.finishCycle(t0, out)
		return out, err
	}
	ac.st.Append("cycle", fmt.Sprintf("plan: %d proposals", len(co.Proposals)), co)
	// a shadow cycle never writes, whatever the setting; an auto cycle writes only when the account is set to auto
	cycleMode := model.AutopilotShadow
	if mode == model.AutopilotAuto && st.Limits.Autopilot == model.AutopilotAuto {
		cycleMode = model.AutopilotAuto
	}
	env := ac.env(cycleMode)
	// the first launch does not wait for the weekly batch day (starter.go)
	sp, stWhy := ac.starterPlan(ctx, in, co.Proposals, env)
	if len(sp) > 0 {
		co.Proposals = append(dropCreates(co.Proposals), sp...)
		out["starter"] = map[string]any{"proposals": len(sp), "why": stWhy, "mode": env.Limits.Autopilot}
	} else {
		out["starter_why"] = stWhy // the Last cycle card says why the first launch did not run (runreport.go)
	}
	// the editable job queue (jobs.go): refreshed from this plan; due jobs become proposals now
	ac.refreshJobs(ctx, in, co.Plan, sp, stWhy, env)
	jobProps, ownerProps := ac.dueJobs(ctx, in, env, env.Limits.Autopilot)
	co.Proposals = append(co.Proposals, jobProps...)
	if j := ac.jobByID(jobFirstLaunch); j != nil && j.Status == "approved" && env.Limits.Autopilot == model.AutopilotShadow && len(sp) > 0 {
		var rest []model.Proposal // the owner approved the first launch on the Plan screen: it runs for real
		for _, p := range co.Proposals {
			if strings.HasPrefix(p.ID, "starter-") {
				ownerProps = append(ownerProps, p)
			} else {
				rest = append(rest, p)
			}
		}
		co.Proposals = rest
	}
	co.Proposals = holdRetargeting(co.Proposals, in, ac.loc())
	co.Proposals = oneEditPerAd(co.Proposals) // volume.go
	var results []executor.Result
	if len(co.Proposals) > 0 {
		results = ex.Run(ctx, env, co.Proposals)
	}
	if len(ownerProps) > 0 && st.Automated && st.Limits.Set() && st.Limits.Autopilot != model.AutopilotOff {
		results = append(results, ex.Run(ctx, ac.env(model.AutopilotAuto), ownerProps)...)
		co.Proposals = append(co.Proposals, ownerProps...)
	}
	if rl, ok := br.(interface {
		RecordLaunches(string, time.Time, []model.Creative, model.ShopSnapshot, []model.Proposal, map[string]bool)
	}); ok { // every ad the app launched joins the outcomes ledger (brain/learn.go)
		done := map[string]bool{}
		for _, r := range results {
			if r.Status == "done" {
				done[r.ProposalID] = true
			}
		}
		if len(done) > 0 {
			rl.RecordLaunches(ac.id, a.now(), ac.creatives.all(), in.Shop, co.Proposals, done)
		}
	}
	ac.keepProposals(t0, env.Limits.Autopilot, co.Proposals, results)
	ac.noteStarter(co.Proposals, results, env.Limits.Autopilot, stWhy)
	ac.noteJobs(results, env.Limits.Autopilot)
	counts := map[string]int{}
	for _, r := range results {
		counts[r.Status]++
	}
	out["proposals"], out["results"], out["counts"] = len(co.Proposals), results, counts
	if counts["done"] == 0 && st.Automated && st.Limits.Set() { // after writes the executor already re-synced and re-checked the net
		if _, err := ac.ensureSafety(ctx); err != nil {
			out["safety_error"] = err.Error()
		}
	}
	day := a.now().In(ac.loc()).Format("2006-01-02")
	ac.mu.Lock()
	co.Plan.UpdatedAt = a.now().UTC()
	ac.plan = co.Plan
	newDay := ac.report.Date != day
	if co.Report != "" {
		ac.report = DailyReport{Date: day, Text: co.Report, At: a.now().UTC()}
	}
	rep := ac.report
	ac.mu.Unlock()
	_ = ac.st.Save("plan", co.Plan)
	_ = ac.st.Save("report", rep)
	if newDay && co.Report != "" {
		ac.alert("info", "Daily summary for "+day+" is ready.")
	}
	out["_props"] = co.Proposals
	ac.finishCycle(t0, out)
	return out, nil
}

func (ac *Acct) finishCycle(t0 time.Time, out map[string]any) {
	now := ac.app.now()
	ac.mu.Lock()
	ac.lastCycle = now
	ac.stateJSON = nil
	ac.mu.Unlock()
	_ = ac.st.Save("cycle", map[string]any{"LastCycle": now})
	out["ms"] = now.Sub(t0).Milliseconds()
	props, _ := out["_props"].([]model.Proposal)
	delete(out, "_props")
	rr := ac.cycleReport(t0, out, props)
	ac.saveRunReport(rr) // Overview → Last cycle: every step (runreport.go)
	ac.alertMetaFixes(rr)
	ac.st.Append("cycle", "cycle end", out)
	ac.publish("cycle.end", out)
}

// cancellable: while the caller holds ac.work outside a cycle (a scheduled or warm-up sync), its cancel is where a
// cycle's would be, so an install (and Stop everything) can stop it instead of waiting; the returned func clears it.
func (ac *Acct) cancellable(cancel context.CancelFunc) func() {
	ac.mu.Lock()
	if ac.cycleCancel == nil {
		ac.cycleCancel = cancel
	}
	ac.mu.Unlock()
	return func() {
		ac.mu.Lock()
		ac.cycleCancel = nil
		ac.mu.Unlock()
	}
}

// Kill: this account's autopilot off, its running cycle cancelled, every ACTIVE campaign the app made in it paused.
// The owner's own campaigns are not touched; Meta's safety rules and the spend cap stay in place.
func (ac *Acct) Kill(ctx context.Context) map[string]any {
	a := ac.app
	ac.mu.Lock()
	if ac.cycleCancel != nil {
		ac.cycleCancel()
	}
	ac.settings.Limits.Autopilot = model.AutopilotOff
	ac.stateJSON = nil
	ac.mu.Unlock()
	ac.saveSettings()
	ac.work.Lock() // wait for the cancelled cycle to stop
	defer ac.work.Unlock()
	out := map[string]any{"account": ac.id, "autopilot": "off"}
	paused, failed := []string{}, []string{}
	if a.metaOK() {
		a.mu.RLock()
		mc := a.meta
		a.mu.RUnlock()
		if tree, err := mc.Tree(ctx, ac.id); err == nil {
			ac.mu.Lock()
			ac.tree = tree
			ac.mu.Unlock()
		} else {
			out["read_error"] = err.Error()
		}
		ac.mu.RLock()
		tree := cloneTree(ac.tree)
		ac.mu.RUnlock()
		for _, c := range tree {
			if !c.Managed || c.Status != "ACTIVE" {
				continue
			}
			if err := mc.Update(ctx, c.ID, map[string][]string{"status": {"PAUSED"}}); err != nil {
				failed = append(failed, c.ID+": "+err.Error())
			} else {
				paused = append(paused, c.ID)
				ac.ledger.NoteEdit(c.ID, a.now(), false, false)
			}
		}
		_ = ac.st.Save("ledger", ac.ledger.Snapshot())
	}
	out["paused"], out["failed"] = paused, failed
	ac.st.Append("kill", fmt.Sprintf("KILL: autopilot off, %d campaigns paused, %d failed", len(paused), len(failed)), out)
	ac.publish("action.kill", out)
	if len(failed) > 0 {
		ac.alert("error", "Kill switch: some campaigns could not be paused: "+strings.Join(failed, "; "))
	} else {
		ac.alert("warn", fmt.Sprintf("Kill switch used: autopilot off, %d of the app's campaigns paused.", len(paused)))
	}
	if a.metaOK() {
		_ = ac.Sync(ctx)
	}
	return out
}

// ── /api/state for this account, from memory ──

type kpi struct {
	Spend     float64 `json:"spend"`
	Purchases float64 `json:"purchases"`
	Revenue   float64 `json:"revenue"`
	CPA       float64 `json:"cpa"`
	ROAS      float64 `json:"roas"`
	CPC       float64 `json:"cpc"`
	CTR       float64 `json:"ctr"`
	CPM       float64 `json:"cpm"`
	Frequency float64 `json:"frequency"`
	Clicks    int64   `json:"clicks"`
	Impr      int64   `json:"impressions"`
}

func kpis(rows []model.MetricRow, from, level string) kpi {
	var k kpi
	var reach int64
	for _, r := range rows {
		if r.Level != level || r.Date < from {
			continue
		}
		k.Spend += r.SpendAED
		k.Purchases += r.Purchases
		k.Revenue += r.PurchaseValueAED
		k.Clicks += r.Clicks
		k.Impr += r.Impressions
		reach += r.Reach
	}
	div := func(a, b float64) float64 {
		if b == 0 {
			return 0
		}
		return math.Round(a/b*100) / 100
	}
	k.CPA, k.ROAS = div(k.Spend, k.Purchases), div(k.Revenue, k.Spend)
	k.CPC, k.CTR, k.CPM = div(k.Spend, float64(k.Clicks)), div(float64(k.Clicks)*100, float64(k.Impr)), div(k.Spend*1000, float64(k.Impr))
	k.Frequency = div(float64(k.Impr), float64(reach)) // summed daily reach: an upper-bound approximation
	k.Spend, k.Revenue = math.Round(k.Spend*100)/100, math.Round(k.Revenue*100)/100
	return k
}

// stateBytes: the dashboard's one call for this account. Built once per change, served from memory.
func (ac *Acct) stateBytes() []byte {
	ac.mu.RLock()
	if ac.stateJSON != nil {
		b := ac.stateJSON
		ac.mu.RUnlock()
		return b
	}
	ac.mu.RUnlock()
	a := ac.app
	rows := ac.metricRows()
	now := a.now().In(ac.loc())
	day := func(n int) string { return now.AddDate(0, 0, -n).Format("2006-01-02") }
	byObj := map[string][]model.MetricRow{}
	for _, r := range rows {
		if r.Date >= day(6) {
			byObj[r.Level+"|"+r.ObjectID] = append(byObj[r.Level+"|"+r.ObjectID], r)
		}
	}
	last := map[string]kpi{}
	for k, rs := range byObj {
		last[k] = kpis(rs, day(6), strings.SplitN(k, "|", 2)[0])
	}
	type adOut struct {
		model.Ad
		Last7 kpi `json:"last7"`
	}
	type setOut struct {
		model.AdSet
		Ads   []adOut `json:"ads"`
		Last7 kpi     `json:"last7"`
	}
	type campOut struct {
		model.Campaign
		AdSets []setOut `json:"adsets"`
		Last7  kpi      `json:"last7"`
	}
	a.mu.RLock()
	conns := map[string]bool{"meta": a.conns.Meta.OK, "claude": a.conns.Claude.OK, "shop": a.conns.Shop.OK}
	shopInfo := map[string]any{"products": len(a.shopSnap.Products), "orders_source": a.shopSnap.OrdersSource, "fetched_at": a.shopSnap.FetchedAt}
	researchAt := a.research.UpdatedAt
	var usage any
	if a.claude != nil {
		usage = a.claude.Total()
	}
	a.mu.RUnlock()
	rd := ac.readiness() // before the read lock: it takes its own
	ac.mu.RLock()
	camps := []campOut{}
	for _, c := range ac.tree {
		co := campOut{Campaign: c, Last7: last["campaign|"+c.ID], AdSets: []setOut{}}
		for _, s := range c.AdSets {
			so := setOut{AdSet: s, Last7: last["adset|"+s.ID], Ads: []adOut{}}
			for _, ad := range s.Ads {
				so.Ads = append(so.Ads, adOut{Ad: ad, Last7: last["ad|"+ad.ID]})
			}
			co.AdSets = append(co.AdSets, so)
		}
		camps = append(camps, co)
	}
	st := map[string]any{
		"at":              a.now().UTC(),
		"account_id":      ac.id,
		"automated":       ac.settings.Automated,
		"account":         ac.account,
		"links":           ac.settings.Links,
		"link_checks":     ac.linkState,
		"limits":          ac.settings.Limits,
		"limits_set":      ac.settings.Limits.Set(),
		"campaigns":       camps,
		"kpis":            map[string]kpi{"today": kpis(rows, day(0), "campaign"), "7d": kpis(rows, day(6), "campaign"), "30d": kpis(rows, day(29), "campaign")},
		"committed_daily": executor.CommittedDaily(ac.tree),
		"plan":            ac.plan,
		"report":          ac.report,
		"alerts":          ac.alerts,
		"last_sync":       ac.lastSync,
		"last_cycle":      ac.lastCycle,
		"next_sync":       ac.nextSync,
		"next_cycle":      ac.nextCycle,
		"cycle_running":   ac.cycleOn,
		"backfill":        ac.backfill,
		"connections":     conns,
		"shop":            shopInfo,
		"research_at":     researchAt,
		"claude_usage":    usage,
		"starter":         ac.starter,
		"readiness":       rd,
	}
	nWait := 0
	for _, it := range ac.pending.Items {
		if waiting(it.Status) {
			nWait++
		}
	}
	st["proposals_waiting"] = nWait
	if ac.safety != nil && ac.safety.State != nil {
		st["safety"] = ac.safety.State.LastReport
	}
	ac.mu.RUnlock()
	st["creatives"] = len(ac.creatives.all())
	st["month_spend"] = math.Round(ac.monthSpend()*100) / 100
	st["run_reports"] = ac.runReports()                              // Overview → Last cycle (runreport.go)
	st["today_spend_app"] = math.Round(ac.todaySpendApp()*100) / 100 // the daily limit is for the app's own ads (22 Nov)
	b, _ := json.Marshal(st)
	ac.mu.Lock()
	ac.stateJSON = b
	ac.mu.Unlock()
	return b
}

func cloneTree(t []model.Campaign) []model.Campaign {
	b, _ := json.Marshal(t)
	var out []model.Campaign
	_ = json.Unmarshal(b, &out)
	return out
}
