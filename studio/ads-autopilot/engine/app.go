package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"sort"
	"strings"
	"sync"
	"time"
	_ "time/tzdata" // the account's time zone (Asia/Dubai) on any machine

	"kaa/claude"
	"kaa/executor"
	"kaa/meta"
	"kaa/model"
	"kaa/safety"
	"kaa/secrets"
	"kaa/shop"
	"kaa/store"
)

// Config: the non-secret settings (config.json in the data folder). Keys live in the Keychain (secrets).
type Config struct {
	MetaAccountID string `json:"meta_account_id"` // act_…
	MetaAppID     string `json:"meta_app_id"`
	PageID        string `json:"page_id"`
	PixelID       string `json:"pixel_id"`
	APIVersion    string `json:"api_version"`
	ClaudeModel   string `json:"claude_model"`
	ShopFeedURL   string `json:"shop_feed_url"`
	SyncMinutes   int    `json:"sync_minutes"`
	PlansPerDay   int    `json:"plans_per_day"`
}

type connMeta struct {
	OK      bool           `json:"ok"`
	Account *model.Account `json:"account,omitempty"`
	Error   string         `json:"error,omitempty"`
	Token   secrets.Info   `json:"token"`
	Secret  secrets.Info   `json:"app_secret"`
	Version string         `json:"api_version"`
	PageID  string         `json:"page_id"`
	PixelID string         `json:"pixel_id"`
	AppID   string         `json:"app_id"`
	Usage   meta.Usage     `json:"usage"`
}
type connClaude struct {
	OK    bool           `json:"ok"`
	Model string         `json:"model"`
	Error string         `json:"error,omitempty"`
	Key   secrets.Info   `json:"key"`
	Usage model.LLMUsage `json:"usage"`
}
type connShop struct {
	OK           bool         `json:"ok"`
	Products     int          `json:"products"`
	OrdersSource string       `json:"orders_source"`
	Error        string       `json:"error,omitempty"`
	FeedURL      string       `json:"feed_url"`
	Token        secrets.Info `json:"token"`
}
type Connections struct {
	Meta   connMeta   `json:"meta"`
	Claude connClaude `json:"claude"`
	Shop   connShop   `json:"shop"`
	Store  string     `json:"secret_store"`
}

type DailyReport struct {
	Date string    `json:"date"`
	Text string    `json:"text"`
	At   time.Time `json:"at"`
}

type App struct {
	st      *store.Store
	sec     secrets.Store
	now     func() time.Time
	publish func(name string, v any)

	mu        sync.RWMutex
	cfg       Config
	limits    model.Limits
	account   model.Account
	tree      []model.Campaign
	metrics   map[string]model.MetricRow
	shopSnap  model.ShopSnapshot
	research  model.Research
	plan      model.Plan
	report    DailyReport
	alerts    []model.Alert
	conns     Connections
	lastSync  time.Time
	lastCycle time.Time
	nextSync  time.Time
	nextCycle time.Time
	lastErr   string
	cycleOn   bool
	stateJSON []byte

	work        sync.Mutex // sync / cycle / kill: one at a time
	cycleCancel context.CancelFunc

	meta      *meta.Client
	claude    *claude.Client
	shopSrc   shop.ShopSource
	ledger    *executor.Ledger
	exec      *executor.Executor
	safety    *safety.Net
	brain     model.Brain
	creatives *creativeLib

	testMetaBase   string
	testClaudeBase string
	testFeedURL    string
}

func newApp(st *store.Store, now func() time.Time, publish func(string, any)) *App {
	a := &App{st: st, now: now, publish: publish, metrics: map[string]model.MetricRow{}}
	a.sec = secrets.New(st.Dir, st.AddSecret)
	a.limits = model.DefaultLimits()
	_ = st.Load("config", &a.cfg)
	_ = st.Load("limits", &a.limits)
	var snap struct {
		Account  model.Account     `json:"account"`
		Tree     []model.Campaign  `json:"tree"`
		Metrics  []model.MetricRow `json:"metrics"`
		LastSync time.Time         `json:"last_sync"`
	}
	_ = st.Load("snapshots/latest", &snap)
	a.account, a.tree, a.lastSync = snap.Account, snap.Tree, snap.LastSync
	for _, r := range snap.Metrics {
		a.metrics[mkey(r)] = r
	}
	_ = st.Load("plan", &a.plan)
	_ = st.Load("research", &a.research)
	_ = st.Load("report", &a.report)
	_ = st.Load("alerts", &a.alerts)
	var ls struct{ LastCycle time.Time }
	_ = st.Load("cycle", &ls)
	a.lastCycle = ls.LastCycle
	if a.cfg.APIVersion == "" {
		a.cfg.APIVersion = meta.DefaultVersion
	}
	if a.cfg.ClaudeModel == "" {
		a.cfg.ClaudeModel = claude.DefaultModel
	}
	if a.cfg.ShopFeedURL == "" {
		a.cfg.ShopFeedURL = shop.DefaultFeed
	}
	if a.cfg.SyncMinutes <= 0 {
		a.cfg.SyncMinutes = 60
	}
	if a.cfg.PlansPerDay <= 0 {
		a.cfg.PlansPerDay = 4
	}
	a.ledger = executor.NewLedger()
	_ = st.Load("ledger", a.ledger)
	a.creatives = newCreativeLib(st)
	return a
}

// wire builds the clients from the config (again after every Settings change).
func (a *App) wire() {
	a.mu.Lock()
	cfg := a.cfg
	a.mu.Unlock()
	mc := meta.New(func() string { v, _ := a.sec.Get("meta_token"); return v })
	mc.AppSecret = func() string { v, _ := a.sec.Get("meta_app_secret"); return v }
	mc.Version = cfg.APIVersion
	if a.testMetaBase != "" {
		mc.Base = a.testMetaBase
		mc.Sleep = func(ctx context.Context, d time.Duration) error { return nil } // checks do not wait out backoffs
	}
	mc.OnCall = func(c meta.Call) {
		if c.Method != "GET" || c.Err != nil {
			s := fmt.Sprintf("%s %s → %d", c.Method, c.Path, c.Status)
			if c.Err != nil {
				s += " " + c.Err.Error()
			}
			a.st.Append("meta", s, c)
		}
	}
	cc := claude.New(func() string { v, _ := a.sec.Get("anthropic_key"); return v })
	cc.Model = cfg.ClaudeModel
	if a.testClaudeBase != "" {
		cc.Base = a.testClaudeBase
	}
	cc.OnUsage = func(purpose, mdl string, u model.LLMUsage, ms int64, err error) {
		d := map[string]any{"purpose": purpose, "model": mdl, "usage": u, "ms": ms}
		if err != nil {
			d["error"] = err.Error()
		}
		a.st.Append("claude", fmt.Sprintf("%s %s: in %d (cache %d) out %d, $%.4f", purpose, mdl, u.InputTokens, u.CacheReadTokens, u.OutputTokens, u.CostUSD), d)
	}
	feed := cfg.ShopFeedURL
	if a.testFeedURL != "" {
		feed = a.testFeedURL
	}
	act := func() string { a.mu.RLock(); defer a.mu.RUnlock(); return a.cfg.MetaAccountID }
	sn := &safety.Net{Meta: mc, Act: act, Now: a.now, Log: a.logf,
		Persist: func(s *safety.State) { _ = a.st.Save("safety", s) }}
	var ss safety.State
	_ = a.st.Load("safety", &ss)
	sn.State = &ss
	ex := &executor.Executor{Meta: mc, Act: act, Ledger: a.ledger, Creatives: a.creatives, Log: a.logf,
		PageID:  func() string { a.mu.RLock(); defer a.mu.RUnlock(); return a.cfg.PageID },
		PixelID: func() string { a.mu.RLock(); defer a.mu.RUnlock(); return a.cfg.PixelID },
		Persist: func(l *executor.Ledger) { _ = a.st.Save("ledger", l.Snapshot()) },
		Safety: func(ctx context.Context) error { // after any write: re-read Meta, then size the net to what now runs
			if err := a.Sync(ctx); err != nil {
				a.alertOnce("Sync after changes failed: " + err.Error())
			}
			_, err := a.ensureSafety(ctx)
			return err
		}}
	a.mu.Lock()
	a.meta, a.claude, a.shopSrc, a.safety, a.exec = mc, cc, shop.NewFeed(feed), sn, ex
	if a.brain == nil {
		a.brain = newBrain(model.BrainDeps{LLM: cc, Log: a.logf, DataDir: a.st.Dir})
	}
	a.mu.Unlock()
}

func (a *App) logf(kind, summary string, data any) {
	e := a.st.Append(kind, summary, data)
	if a.publish != nil && (kind == "execution" || kind == "alert" || kind == "safety" || kind == "kill") {
		a.publish("action."+kind, e)
	}
}

func (a *App) alert(level, text string) {
	al := model.Alert{At: a.now().UTC(), Level: level, Text: text}
	a.mu.Lock()
	a.alerts = append(a.alerts, al)
	if len(a.alerts) > 100 {
		a.alerts = a.alerts[len(a.alerts)-100:]
	}
	alerts := append([]model.Alert(nil), a.alerts...)
	a.stateJSON = nil
	a.mu.Unlock()
	_ = a.st.Save("alerts", alerts)
	a.st.Append("alert", text, al)
	if a.publish != nil {
		a.publish("alert", al)
	}
}

func mkey(r model.MetricRow) string { return r.Level + "|" + r.ObjectID + "|" + r.Date }

func (a *App) loc() *time.Location {
	a.mu.RLock()
	tz := a.account.Timezone
	a.mu.RUnlock()
	if tz != "" {
		if l, err := time.LoadLocation(tz); err == nil {
			return l
		}
	}
	l, _ := time.LoadLocation("Asia/Dubai")
	return l
}

// ── connections ──

func (a *App) testMeta(ctx context.Context) connMeta {
	a.mu.RLock()
	cfg := a.cfg
	mc := a.meta
	a.mu.RUnlock()
	c := connMeta{Token: secrets.Describe(a.sec, "meta_token"), Secret: secrets.Describe(a.sec, "meta_app_secret"), Version: cfg.APIVersion, PageID: cfg.PageID, PixelID: cfg.PixelID, AppID: cfg.MetaAppID}
	switch {
	case !c.Token.Set:
		c.Error = "No Meta system-user token saved."
	case cfg.MetaAccountID == "":
		c.Error = "No ad account id (act_…) saved."
	default:
		acc, err := mc.Account(ctx, cfg.MetaAccountID)
		if err != nil {
			c.Error = err.Error()
			if me, ok := meta.AsError(err); ok && me.TokenInvalid() {
				c.Error = "Meta refused the token (expired or revoked): make a new system-user token. " + err.Error()
			}
		} else if acc.Currency != "AED" {
			c.Account, c.Error = &acc, "The ad account's currency is "+acc.Currency+"; this app only works in AED."
		} else {
			acc.PageID, acc.PixelID = cfg.PageID, cfg.PixelID
			c.OK, c.Account = true, &acc
			a.mu.Lock()
			a.account = acc
			a.mu.Unlock()
			if acc.Status != 1 {
				a.alert("error", fmt.Sprintf("Meta reports the ad account status as %d (not active, disable reason %d).", acc.Status, acc.DisableReason))
			}
		}
	}
	c.Usage = mc.Usage()
	return c
}

func (a *App) testClaude(ctx context.Context) connClaude {
	a.mu.RLock()
	cc := a.claude
	a.mu.RUnlock()
	c := connClaude{Model: cc.Model, Key: secrets.Describe(a.sec, "anthropic_key"), Usage: cc.Total()}
	if !c.Key.Set {
		c.Error = "No Anthropic API key saved."
		return c
	}
	if err := cc.Ping(ctx); err != nil {
		c.Error = err.Error()
		return c
	}
	c.OK = true
	return c
}

func (a *App) testShop(ctx context.Context) connShop {
	a.mu.RLock()
	src := a.shopSrc
	cfg := a.cfg
	a.mu.RUnlock()
	c := connShop{FeedURL: cfg.ShopFeedURL, OrdersSource: src.OrdersSource(), Token: secrets.Describe(a.sec, "shop_token")}
	snap, err := src.Snapshot(ctx)
	if err != nil {
		c.Error = err.Error()
		return c
	}
	c.OK, c.Products = true, len(snap.Products)
	a.mu.Lock()
	a.shopSnap = snap
	a.stateJSON = nil
	a.mu.Unlock()
	return c
}

// connectAll: on launch and after a Settings change. The three run side by side.
func (a *App) connectAll(ctx context.Context) Connections {
	var wg sync.WaitGroup
	var m connMeta
	var c connClaude
	var s connShop
	wg.Add(3)
	go func() { defer wg.Done(); m = a.testMeta(ctx) }()
	go func() { defer wg.Done(); c = a.testClaude(ctx) }()
	go func() { defer wg.Done(); s = a.testShop(ctx) }()
	wg.Wait()
	conns := Connections{Meta: m, Claude: c, Shop: s, Store: a.sec.Kind()}
	a.mu.Lock()
	a.conns = conns
	a.stateJSON = nil
	a.mu.Unlock()
	a.st.Append("config", fmt.Sprintf("connections: meta %v, claude %v, shop %v (%d products)", m.OK, c.OK, s.OK, s.Products), conns)
	if a.publish != nil {
		a.publish("connections", conns)
	}
	return conns
}

// ── sync ──

// Sync pulls the account, the tree and Insights (catch-up since the last run, re-reading the 2 settling days).
func (a *App) Sync(ctx context.Context) error {
	a.mu.RLock()
	act, mc, last := a.cfg.MetaAccountID, a.meta, a.lastSync
	ok := a.conns.Meta.OK
	a.mu.RUnlock()
	if act == "" || !ok {
		return errors.New("Meta is not connected")
	}
	acc, err := mc.Account(ctx, act)
	if err != nil {
		return err
	}
	loc := a.loc()
	if l, e := time.LoadLocation(acc.Timezone); e == nil {
		loc = l
	}
	tree, err := mc.Tree(ctx, act)
	if err != nil {
		return err
	}
	today := a.now().In(loc)
	since := today.AddDate(0, 0, -29)
	if !last.IsZero() {
		s := last.In(loc).AddDate(0, 0, -2) // the settling days are read again
		if s.After(since) {
			since = s
		}
	}
	sinceS, untilS := since.Format("2006-01-02"), today.Format("2006-01-02")
	var rows []model.MetricRow
	for _, lvl := range []string{"campaign", "adset", "ad"} {
		r, err := mc.Insights(ctx, act, lvl, sinceS, untilS)
		if err != nil {
			return fmt.Errorf("insights (%s): %w", lvl, err)
		}
		rows = append(rows, r...)
	}
	settle := today.AddDate(0, 0, -1).Format("2006-01-02")
	keep := today.AddDate(0, 0, -90).Format("2006-01-02")
	a.mu.Lock()
	acc.PageID, acc.PixelID = a.cfg.PageID, a.cfg.PixelID
	a.account, a.tree = acc, tree
	for _, r := range rows {
		a.metrics[mkey(r)] = r
	}
	all := make([]model.MetricRow, 0, len(a.metrics))
	for k, r := range a.metrics {
		if r.Date < keep {
			delete(a.metrics, k)
			continue
		}
		r.Settling = r.Date >= settle
		a.metrics[k] = r
		all = append(all, r)
	}
	a.lastSync = a.now()
	a.stateJSON = nil
	snap := map[string]any{"account": acc, "tree": tree, "metrics": all, "last_sync": a.lastSync}
	a.mu.Unlock()
	_ = a.st.Save("snapshots/latest", snap)
	_ = a.st.Save("snapshots/"+today.Format("2006-01-02"), map[string]any{"account": acc, "tree": tree, "at": a.now().UTC()})
	a.st.Append("snapshot", fmt.Sprintf("sync %s..%s: %d campaigns, %d metric rows; spend cap %.2f AED", sinceS, untilS, len(tree), len(rows), acc.SpendCapAED),
		map[string]any{"since": sinceS, "until": untilS, "campaigns": len(tree), "rows": len(rows), "account": acc})
	if a.publish != nil {
		a.publish("metrics.updated", map[string]any{"at": a.lastSync, "rows": len(rows)})
	}
	return nil
}

func (a *App) metricRows() []model.MetricRow {
	a.mu.RLock()
	defer a.mu.RUnlock()
	out := make([]model.MetricRow, 0, len(a.metrics))
	for _, r := range a.metrics {
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
func (a *App) monthSpend() float64 {
	now := a.now().In(a.loc())
	prefix := now.Format("2006-01")
	a.mu.RLock()
	defer a.mu.RUnlock()
	s := 0.0
	for _, r := range a.metrics {
		if r.Level == "campaign" && strings.HasPrefix(r.Date, prefix) {
			s += r.SpendAED
		}
	}
	return s
}

func (a *App) ensureSafety(ctx context.Context) (safety.Report, error) {
	a.mu.RLock()
	never := a.lastSync.IsZero()
	a.mu.RUnlock()
	if never { // the cap needs this month's spend: read it first
		if err := a.Sync(ctx); err != nil {
			return safety.Report{}, fmt.Errorf("cannot size the spending limit before a sync: %w", err)
		}
	}
	a.mu.RLock()
	in := safety.Input{Limits: a.limits, Tree: a.tree}
	sn := a.safety
	ok := a.conns.Meta.OK
	a.mu.RUnlock()
	if !ok {
		return safety.Report{}, errors.New("Meta is not connected")
	}
	in.MonthSpendAED = a.monthSpend()
	rep, err := sn.Ensure(ctx, in)
	for _, al := range rep.Alerts {
		a.alertOnce(al)
	}
	if err != nil {
		a.alert("error", "Meta safety net: "+err.Error())
	}
	a.mu.Lock()
	a.stateJSON = nil
	a.mu.Unlock()
	return rep, err
}

// alertOnce: the same text is not repeated within 24 h.
func (a *App) alertOnce(text string) {
	a.mu.RLock()
	for _, al := range a.alerts {
		if al.Text == text && a.now().Sub(al.At) < 24*time.Hour {
			a.mu.RUnlock()
			return
		}
	}
	a.mu.RUnlock()
	a.alert("warn", text)
}

// ── the cycle ──

var errBusy = errors.New("a cycle is already running")

// Cycle: sync → brain → executor → safety net → daily summary. mode "shadow" never writes; "auto" writes only when
// the owner's own setting is auto too.
func (a *App) Cycle(parent context.Context, mode string) (map[string]any, error) {
	if !a.work.TryLock() {
		return nil, errBusy
	}
	defer a.work.Unlock()
	ctx, cancel := context.WithTimeout(parent, 20*time.Minute)
	a.mu.Lock()
	a.cycleCancel, a.cycleOn = cancel, true
	a.mu.Unlock()
	defer func() {
		cancel()
		a.mu.Lock()
		a.cycleCancel, a.cycleOn, a.stateJSON = nil, false, nil
		a.mu.Unlock()
	}()
	t0 := a.now()
	if a.publish != nil {
		a.publish("cycle.start", map[string]any{"at": t0, "mode": mode})
	}
	a.st.Append("cycle", "cycle start ("+mode+")", map[string]any{"mode": mode})
	out := map[string]any{"mode": mode}
	if err := a.Sync(ctx); err != nil {
		a.alertOnce("Sync with Meta failed: " + err.Error())
		out["sync_error"] = err.Error()
	}
	a.mu.RLock()
	lim := a.limits
	in := model.CycleInput{Now: a.now(), Limits: lim, Account: a.account, Tree: cloneTree(a.tree), Shop: a.shopSnap, Research: a.research}
	br, ex := a.brain, a.exec
	a.mu.RUnlock()
	in.Metrics = a.metricRows()
	in.Creatives = a.creatives.all()
	in.History = a.st.Recent(200, "execution", "check", "cycle", "kill", "alert", "safety")
	effective := lim
	if mode != model.AutopilotAuto && effective.Autopilot == model.AutopilotAuto {
		effective.Autopilot = model.AutopilotShadow // a shadow cycle never writes, whatever the setting
	}
	co, err := br.Cycle(ctx, in)
	if err != nil {
		a.alert("error", "The planner failed: "+err.Error()+" (nothing was changed)")
		out["error"] = err.Error()
		a.finishCycle(t0, out)
		return out, err
	}
	a.st.Append("cycle", fmt.Sprintf("plan: %d proposals", len(co.Proposals)), co)
	env := executor.Env{Now: a.now(), Limits: effective, Tree: in.Tree, MonthSpendAED: a.monthSpend(), Ledger: a.ledger, Usage: a.meta.Usage(), Loc: a.loc()}
	if l, ok := br.(interface{ Lint(model.Proposal) []string }); ok {
		env.Lint = func(p model.Proposal) []string { return append(executor.BuiltinLint(p), l.Lint(p)...) }
	}
	var results []executor.Result
	if len(co.Proposals) > 0 {
		results = ex.Run(ctx, env, co.Proposals)
	}
	counts := map[string]int{}
	for _, r := range results {
		counts[r.Status]++
	}
	out["proposals"], out["results"], out["counts"] = len(co.Proposals), results, counts
	if counts["done"] == 0 && lim.Set() { // after writes the executor already re-synced and re-checked the net
		if _, err := a.ensureSafety(ctx); err != nil {
			out["safety_error"] = err.Error()
		}
	}
	day := a.now().In(a.loc()).Format("2006-01-02")
	a.mu.Lock()
	co.Plan.UpdatedAt = a.now().UTC()
	a.plan = co.Plan
	newDay := a.report.Date != day
	if co.Report != "" {
		a.report = DailyReport{Date: day, Text: co.Report, At: a.now().UTC()}
	}
	rep := a.report
	a.mu.Unlock()
	_ = a.st.Save("plan", co.Plan)
	_ = a.st.Save("report", rep)
	if newDay && co.Report != "" {
		a.alert("info", "Daily summary for "+day+" is ready.")
	}
	a.finishCycle(t0, out)
	return out, nil
}

func (a *App) finishCycle(t0 time.Time, out map[string]any) {
	a.mu.Lock()
	a.lastCycle = a.now()
	a.stateJSON = nil
	a.mu.Unlock()
	_ = a.st.Save("cycle", map[string]any{"LastCycle": a.lastCycle})
	out["ms"] = a.now().Sub(t0).Milliseconds()
	a.st.Append("cycle", "cycle end", out)
	if a.publish != nil {
		a.publish("cycle.end", out)
	}
}

// Kill: autopilot off, the running cycle cancelled, every ACTIVE campaign the app made paused. The owner's own
// campaigns are not touched. Meta's safety rules and spend cap stay in place.
func (a *App) Kill(ctx context.Context) map[string]any {
	a.mu.Lock()
	if a.cycleCancel != nil {
		a.cycleCancel()
	}
	a.limits.Autopilot = model.AutopilotOff
	lim := a.limits
	a.stateJSON = nil
	mc, act, ok := a.meta, a.cfg.MetaAccountID, a.conns.Meta.OK
	a.mu.Unlock()
	_ = a.st.Save("limits", lim)
	a.work.Lock() // wait for the cancelled cycle to stop
	defer a.work.Unlock()
	out := map[string]any{"autopilot": "off"}
	var paused, failed []string
	if ok && act != "" {
		if tree, err := mc.Tree(ctx, act); err == nil {
			a.mu.Lock()
			a.tree = tree
			a.mu.Unlock()
		} else {
			out["read_error"] = err.Error()
		}
		a.mu.RLock()
		tree := cloneTree(a.tree)
		a.mu.RUnlock()
		for _, c := range tree {
			if !c.Managed || c.Status != "ACTIVE" {
				continue
			}
			if err := mc.Update(ctx, c.ID, map[string][]string{"status": {"PAUSED"}}); err != nil {
				failed = append(failed, c.ID+": "+err.Error())
			} else {
				paused = append(paused, c.ID)
				a.ledger.NoteEdit(c.ID, a.now(), false, false)
			}
		}
		_ = a.st.Save("ledger", a.ledger.Snapshot())
	}
	out["paused"], out["failed"] = paused, failed
	a.st.Append("kill", fmt.Sprintf("KILL: autopilot off, %d campaigns paused, %d failed", len(paused), len(failed)), out)
	if a.publish != nil {
		a.publish("action.kill", out)
	}
	if len(failed) > 0 {
		a.alert("error", "Kill switch: some campaigns could not be paused: "+strings.Join(failed, "; "))
	} else {
		a.alert("warn", fmt.Sprintf("Kill switch used: autopilot off, %d of the app's campaigns paused.", len(paused)))
	}
	if ok {
		_ = a.SyncLocked(ctx)
	}
	return out
}

// SyncLocked: Sync for a caller that already holds work.
func (a *App) SyncLocked(ctx context.Context) error { return a.Sync(ctx) }

// ── /api/state, from memory ──

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

func kpis(rows []model.MetricRow, from string) kpi {
	var k kpi
	var reach int64
	for _, r := range rows {
		if r.Level != "campaign" || r.Date < from {
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

// stateBytes: the dashboard's one call. Built once per change and served from memory.
func (a *App) stateBytes() []byte {
	a.mu.RLock()
	if a.stateJSON != nil {
		b := a.stateJSON
		a.mu.RUnlock()
		return b
	}
	a.mu.RUnlock()
	rows := a.metricRows()
	now := a.now().In(a.loc())
	day := func(n int) string { return now.AddDate(0, 0, -n).Format("2006-01-02") }
	last := map[string]kpi{}
	byObj := map[string][]model.MetricRow{}
	for _, r := range rows {
		if r.Date >= day(6) {
			byObj[r.Level+"|"+r.ObjectID] = append(byObj[r.Level+"|"+r.ObjectID], r)
		}
	}
	for k, rs := range byObj {
		lvl := strings.SplitN(k, "|", 2)[0]
		for i := range rs {
			rs[i].Level = "campaign" // reuse kpis() over one object's rows
		}
		kp := kpis(rs, day(6))
		last[k] = kp
		_ = lvl
	}
	a.mu.RLock()
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
	camps := []campOut{}
	for _, c := range a.tree {
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
	nextCycle := a.nextCycle
	st := map[string]any{
		"at":              a.now().UTC(),
		"account":         a.account,
		"limits":          a.limits,
		"limits_set":      a.limits.Set(),
		"campaigns":       camps,
		"kpis":            map[string]kpi{"today": kpis(rows, day(0)), "7d": kpis(rows, day(6)), "30d": kpis(rows, day(29))},
		"committed_daily": executor.CommittedDaily(a.tree),
		"plan":            a.plan,
		"report":          a.report,
		"alerts":          a.alerts,
		"last_sync":       a.lastSync,
		"last_cycle":      a.lastCycle,
		"next_sync":       a.nextSync,
		"next_cycle":      nextCycle,
		"cycle_running":   a.cycleOn,
		"connections":     map[string]bool{"meta": a.conns.Meta.OK, "claude": a.conns.Claude.OK, "shop": a.conns.Shop.OK},
		"shop":            map[string]any{"products": len(a.shopSnap.Products), "orders_source": a.shopSnap.OrdersSource, "fetched_at": a.shopSnap.FetchedAt},
		"creatives":       len(a.creatives.all()),
		"research_at":     a.research.UpdatedAt,
	}
	if a.safety != nil && a.safety.State != nil {
		st["safety"] = a.safety.State.LastReport
	}
	if a.claude != nil {
		st["claude_usage"] = a.claude.Total()
	}
	a.mu.RUnlock()
	st["month_spend"] = math.Round(a.monthSpend()*100) / 100
	b, _ := json.Marshal(st)
	a.mu.Lock()
	a.stateJSON = b
	a.mu.Unlock()
	return b
}

func cloneTree(t []model.Campaign) []model.Campaign {
	b, _ := json.Marshal(t)
	var out []model.Campaign
	_ = json.Unmarshal(b, &out)
	return out
}
