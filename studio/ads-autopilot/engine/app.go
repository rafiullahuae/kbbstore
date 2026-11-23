package main

// app.go — what is shared by every ad account: the keys, the Meta / Claude / shop clients, the portfolio the token can
// see, the shop feed, the market research, the brain, the global audit log. Everything per ad account lives in
// acct.go (accounts/<act_id>/ in the data folder).

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
	_ "time/tzdata" // the account's time zone (Asia/Dubai) on any machine

	"kaa/claude"
	"kaa/meta"
	"kaa/model"
	"kaa/secrets"
	"kaa/shop"
	"kaa/store"
)

// Config: the non-secret settings shared by all accounts (config.json). Keys live in the Keychain (secrets).
type Config struct {
	MetaAppID   string `json:"meta_app_id"`
	APIVersion  string `json:"api_version"`
	ClaudeModel string `json:"claude_model"`
	ShopFeedURL string `json:"shop_feed_url"`
	SyncMinutes int    `json:"sync_minutes"`
	PlansPerDay int    `json:"plans_per_day"`
	Selected    string `json:"selected_account"` // the account the screens show when the page names none
	// the optional caps across ALL automated accounts (0 = none); each account's own limits always apply
	GlobalDailyAED   float64 `json:"global_daily_aed"`
	GlobalMonthlyAED float64 `json:"global_monthly_aed"`
	// token.go: how Meta is connected, and what /debug_token last said about the token
	MetaAuth     string    `json:"meta_auth"` // system_user | user_token
	TokenType    string    `json:"token_type,omitempty"`
	TokenExpires time.Time `json:"token_expires_at,omitempty"`
	TokenScopes  []string  `json:"token_scopes,omitempty"`
	TokenValid   bool      `json:"token_valid,omitempty"`
	// before multi-account (read once, moved into accounts/<act>/)
	LegacyAccountID string `json:"meta_account_id,omitempty"`
	LegacyPageID    string `json:"page_id,omitempty"`
	LegacyPixelID   string `json:"pixel_id,omitempty"`
}

type connMeta struct {
	OK        bool         `json:"ok"`
	Error     string       `json:"error,omitempty"`
	Token     secrets.Info `json:"token"`
	Secret    secrets.Info `json:"app_secret"`
	Version   string       `json:"api_version"`
	AppID     string       `json:"app_id"`
	Usage     meta.Usage   `json:"usage"`
	Auth      string       `json:"auth"` // system_user | user_token
	TokenType string       `json:"token_type,omitempty"`
	ExpiresAt *time.Time   `json:"token_expires_at,omitempty"` // nil = does not expire by time
	DaysLeft  int          `json:"token_days_left"`            // -1 = no expiry
	Scopes    []string     `json:"token_scopes,omitempty"`
	Warning   string       `json:"warning,omitempty"`
	Tier      string       `json:"access_tier,omitempty"`
	Accounts  int          `json:"ad_accounts"` // how many ad accounts the token sees (GET /api/accounts lists them)
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
	// Checked: the three were tested since this engine started. Until then the page shows the saved keys and
	// "Checking…", never empty cards (the owner, 21 Nov: after every update the cards read empty and "Not connected"
	// until the first test finished, which looked exactly like the keys had vanished).
	Checked  bool `json:"checked"`
	Checking bool `json:"checking,omitempty"`
}

type DailyReport struct {
	Date string    `json:"date"`
	Text string    `json:"text"`
	At   time.Time `json:"at"`
}

type App struct {
	pagesFromFeed atomic.Bool  // -mock / tests: every product of the fake feed counts as on the source pages
	st            *store.Store // global: config, connections, portfolio, research, learnings
	sec           secrets.Store
	now           func() time.Time
	publish       func(name string, v any)

	mu        sync.RWMutex
	cfg       Config
	conns     Connections
	portfolio model.Portfolio
	learnings []model.Learning
	shopSnap  model.ShopSnapshot
	research  model.Research
	accounts  map[string]*Acct

	meta    *meta.Client
	claude  *claude.Client
	shopSrc shop.ShopSource
	brain   model.Brain

	testMetaBase   string
	testClaudeBase string
	testFeedURL    string
	testShopBase   string      // -mock with KAA_MOCK_SHOP_API=1: the fake shop API (metamock/shopapi.go)
	noBackfill     bool        // Go tests that do not want the history import running in the background
	warm           warmer      // the refresh on start / wake / page return (warmup.go)
	cc             *ccRunner   // Claude Code on this Mac: auto-creation on the Max plan (claudecode.go)
	firstTest      atomic.Bool // ConnectionsView started a first test (connectAll may also run from the warm-up)
	updating       atomic.Bool // an update is being installed / checked: no sync or cycle starts (update_flow.go)
	stopMu         sync.Mutex
	roleOK         sync.Map      // account → pageRoleOK: the Page role check passed (pagerole.go), trusted 6 hours
	stopCh         chan struct{} // closed when an install stops the running work (stoppable, stopWork)
}

// stoppable: ctx that ends when an update stops the running work (update_flow.go pauseAutopilot). The owner, 22 Nov:
// an install stuck behind his Run it jobs ("no ANY patch is accepting") -- they held the account for minutes and
// nothing could stop them. Every long piece of account work (a job run at once, an approval) runs under this.
func (a *App) stoppable(parent context.Context) (context.Context, context.CancelFunc) {
	a.stopMu.Lock()
	if a.stopCh == nil {
		a.stopCh = make(chan struct{})
	}
	ch := a.stopCh
	a.stopMu.Unlock()
	ctx, cancel := context.WithCancel(parent)
	go func() {
		select {
		case <-ch:
			cancel()
		case <-ctx.Done():
		}
	}()
	return ctx, cancel
}

// stopWork: every stoppable ctx ends now; later ones start fresh (resumeWork).
func (a *App) stopWork() {
	a.stopMu.Lock()
	if a.stopCh == nil {
		a.stopCh = make(chan struct{})
	}
	select {
	case <-a.stopCh:
	default:
		close(a.stopCh)
	}
	a.stopMu.Unlock()
}

func (a *App) resumeWork() {
	a.stopMu.Lock()
	a.stopCh = make(chan struct{})
	a.stopMu.Unlock()
}

func newApp(st *store.Store, now func() time.Time, publish func(string, any)) *App {
	a := &App{st: st, now: now, publish: publish, accounts: map[string]*Acct{}}
	if testing.Testing() { // the engine's own tests never read the real shop's pages
		a.pagesFromFeed.Store(true)
	}
	a.sec = secrets.New(st.Dir, st.AddSecret)
	// a saved file this version cannot read is said in Activity (store.Load keeps a copy), never read as "never set"
	for _, x := range []struct {
		n string
		v any
	}{{"config", &a.cfg}, {"portfolio", &a.portfolio}, {"learnings", &a.learnings}, {"research", &a.research}} {
		if err := st.Load(x.n, x.v); err != nil {
			st.Append("alert", "Saved settings could not be read: "+err.Error()+". Check Settings; nothing on Meta was changed.", model.Alert{At: now().UTC(), Level: "warn", Text: err.Error()})
		}
	}
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
	// every account the app has data for
	if ents, err := os.ReadDir(filepath.Join(st.Dir, "accounts")); err == nil {
		for _, e := range ents {
			if e.IsDir() && actRe.MatchString(e.Name()) {
				if ac, err := openAcct(a, e.Name()); err == nil {
					a.accounts[e.Name()] = ac
				}
			}
		}
	}
	// the single-account config of the first build becomes accounts/<act>/
	if id := a.cfg.LegacyAccountID; id != "" && a.accounts[id] == nil {
		if ac, err := openAcct(a, id); err == nil {
			ac.settings.Links.PageID, ac.settings.Links.PixelID, ac.settings.Automated = a.cfg.LegacyPageID, a.cfg.LegacyPixelID, true
			_ = st.Load("limits", &ac.settings.Limits)
			ac.saveSettings()
			a.accounts[id] = ac
			if a.cfg.Selected == "" {
				a.cfg.Selected = id
			}
		}
	}
	return a
}

// wire builds the shared clients from the config (again after every Settings change) and re-points every account.
func (a *App) wire() {
	a.mu.Lock()
	cfg := a.cfg
	a.mu.Unlock()
	// each client reads its key on every request and, when the Keychain refused the read, says so instead of "no key
	// saved" (secrets.Key)
	mk := secrets.NewKey(a.sec, "meta_token")
	mc := meta.New(mk.Value)
	mc.TokenErr = mk.Err
	mc.AppSecret = func() string { v, _ := a.sec.Get("meta_app_secret"); return v }
	mc.Version = cfg.APIVersion
	if a.testMetaBase != "" {
		mc.Base = a.testMetaBase
		mc.Sleep = func(ctx context.Context, d time.Duration) error { return nil } // checks do not wait out backoffs
		meta.RuploadBase = a.testMetaBase + "/video-ads-upload"
	}
	mc.OnWait = func(d time.Duration, tier string) { // the owner, 23 Nov: "loading n loading, nothing happening"
		for _, ac := range a.Accounts() {
			if ac.liveSnap().Running {
				ac.liveStep("pace", "Waiting for Meta's request limit", "warn", fmt.Sprintf("about %d s, then it continues by itself: Meta lets this app make only a few requests every 5 minutes (access tier %q; Standard access raises it)", int(d.Seconds())+1, firstNonEmpty(tier, "not reported yet")))
			}
		}
	}
	mc.OnCall = func(c meta.Call) {
		if c.Method != "GET" || c.Err != nil {
			s := fmt.Sprintf("%s %s → %d", c.Method, c.Path, c.Status)
			if c.Err != nil {
				s += " " + c.Err.Error()
			}
			if ac := a.acctForPath(c.Path); ac != nil {
				ac.st.Append("meta", s, c)
			} else {
				a.st.Append("meta", s, c)
			}
		}
	}
	ck := secrets.NewKey(a.sec, "anthropic_key")
	cc := claude.New(ck.Value)
	cc.KeyErr = ck.Err
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
	a.mu.Lock()
	sk := secrets.NewKey(a.sec, "shop_token")
	src := shop.New(feed, sk.Value)
	src.TokenErr = sk.Err
	if a.testShopBase != "" {
		src.TestBase(a.testShopBase)
	}
	a.meta, a.claude, a.shopSrc = mc, cc, src
	if a.brain == nil {
		a.brain = newBrain(model.BrainDeps{LLM: cc, Log: func(k, s string, d any) { a.st.Append(k, s, d) }, DataDir: a.st.Dir, Publish: a.publish, Redact: a.redact})
	}
	accs := a.accountList()
	a.mu.Unlock()
	for _, ac := range accs {
		ac.wire()
	}
}

// acctForPath: the account a Meta call was about (act_… at the start of its path).
func (a *App) acctForPath(p string) *Acct {
	for i := 0; i < len(p); i++ {
		if p[i] == '/' {
			p = p[:i]
			break
		}
	}
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.accounts[p]
}

func (a *App) accountList() []*Acct { // caller holds mu (read or write)
	out := make([]*Acct, 0, len(a.accounts))
	for _, ac := range a.accounts {
		out = append(out, ac)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].id < out[j].id })
	return out
}

func (a *App) Accounts() []*Acct {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.accountList()
}

// Acct returns the account, opening its data folder the first time it is named (it must be one the token sees, or
// one the app already has data for).
func (a *App) Acct(id string) (*Acct, error) {
	if !actRe.MatchString(id) {
		return nil, fmt.Errorf("%q is not an ad account id (act_…)", id)
	}
	a.mu.RLock()
	ac := a.accounts[id]
	known := false
	for _, r := range a.portfolio.AdAccounts {
		known = known || r.ID == id
	}
	a.mu.RUnlock()
	if ac != nil {
		return ac, nil
	}
	if !known {
		return nil, fmt.Errorf("%s is not an ad account this Meta token can see", id)
	}
	ac, err := openAcct(a, id)
	if err != nil {
		return nil, err
	}
	a.mu.Lock()
	if have := a.accounts[id]; have != nil {
		a.mu.Unlock()
		return have, nil
	}
	a.accounts[id] = ac
	a.mu.Unlock()
	ac.wire()
	return ac, nil
}

// Selected: the account the page means when it names none.
func (a *App) Selected() string {
	a.mu.RLock()
	defer a.mu.RUnlock()
	if a.cfg.Selected != "" {
		return a.cfg.Selected
	}
	if len(a.portfolio.AdAccounts) > 0 {
		return a.portfolio.AdAccounts[0].ID
	}
	return ""
}

func (a *App) logf(kind, summary string, data any) {
	e := a.st.Append(kind, summary, data)
	if a.publish != nil && (kind == "alert" || kind == "kill") {
		a.publish("action."+kind, map[string]any{"account": "", "data": e})
	}
}

// alertGlobal: an alert that is about the app, not one account (the token, Claude, the shop).
func (a *App) alertGlobal(text string) {
	for _, al := range a.st.Recent(50, "alert") {
		if al.Summary == text && a.now().Sub(al.At) < 24*time.Hour {
			return
		}
	}
	al := model.Alert{At: a.now().UTC(), Level: "warn", Text: text}
	a.st.Append("alert", text, al)
	if a.publish != nil {
		a.publish("alert", map[string]any{"account": "", "data": al})
	}
}

// alertOnce kept for token.go: global.
func (a *App) alertOnce(text string) { a.alertGlobal(text) }

// ── connections ──

func (a *App) testMeta(ctx context.Context) connMeta {
	a.mu.RLock()
	cfg := a.cfg
	mc := a.meta
	a.mu.RUnlock()
	c := connMeta{Token: secrets.Describe(a.sec, "meta_token"), Secret: secrets.Describe(a.sec, "meta_app_secret"), Version: cfg.APIVersion, AppID: cfg.MetaAppID, Auth: cfg.MetaAuth}
	if c.Auth == "" {
		c.Auth = AuthSystemUser
	}
	if !c.Token.Set {
		c.Error = "No Meta token saved (Settings → Connections → Meta; the setup guide shows both ways)."
		if c.Token.Error != "" {
			c.Error = "The Meta token could not be read: " + c.Token.Error
		}
		return c
	}
	_ = a.inspectToken(ctx) // best effort: type, expiry, scopes
	a.mu.RLock()
	cfg = a.cfg
	a.mu.RUnlock()
	c.TokenType, c.Scopes = cfg.TokenType, cfg.TokenScopes
	if !cfg.TokenExpires.IsZero() {
		t := cfg.TokenExpires
		c.ExpiresAt = &t
	}
	c.DaysLeft, c.Warning = a.tokenStatus(a.now())
	p, err := mc.Portfolio(ctx)
	if err != nil {
		c.Error = err.Error()
		if me, ok := meta.AsError(err); ok && me.TokenInvalid() {
			c.Error = "Meta refused the token (expired or revoked): paste a new one. Meta's own rules and spending limits keep protecting the ads meanwhile. " + err.Error()
		} else if strings.Contains(strings.ToLower(err.Error()), "appsecret_proof") {
			// the owner, 23 Nov: the App ID / secret of one Meta app with a token made for another
			c.Error = "The App secret is not the one of the Meta app this token was made with, so Meta refuses every request. Use the App ID and App secret of the SAME app you picked in Graph API Explorer (developers.facebook.com → that app → App settings → Basic), or make the token with this app. " + err.Error()
		}
	} else {
		a.mu.Lock()
		for i := range p.AdAccounts {
			if ac := a.accounts[p.AdAccounts[i].ID]; ac != nil {
				p.AdAccounts[i].Automated = ac.Automated()
			}
		}
		a.portfolio = p
		a.mu.Unlock()
		_ = a.st.Save("portfolio", p)
		c.OK, c.Accounts = true, len(p.AdAccounts)
		if len(p.AdAccounts) == 0 {
			c.OK, c.Error = false, "The token sees no ad account. Give the system user (or your own user) access to the ad account in Business Settings."
		}
	}
	c.Usage = mc.Usage()
	c.Tier = c.Usage.Tier
	return c
}

func (a *App) testClaude(ctx context.Context) connClaude {
	a.mu.RLock()
	cc := a.claude
	a.mu.RUnlock()
	c := connClaude{Model: cc.Model, Key: secrets.Describe(a.sec, "anthropic_key"), Usage: cc.Total()}
	if !c.Key.Set {
		c.Error = "No Anthropic API key saved."
		if c.Key.Error != "" {
			c.Error = "The Claude key could not be read: " + c.Key.Error
		}
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
	if c.Token.Error != "" {
		c.Error = "The shop connection key could not be read: " + c.Token.Error
		return c
	}
	snap, err := src.Snapshot(ctx)
	if err != nil {
		c.Error = err.Error()
		return c
	}
	c.OK, c.Products = true, len(snap.Products)
	a.stampFirstSeen(&snap)
	a.mu.Lock()
	a.shopSnap = snap
	a.mu.Unlock()
	a.refreshSourcePages(ctx) // the products on his source pages (sourcepages.go), read with the shop
	a.dirtyAll()
	return c
}

func (a *App) dirtyAll() {
	for _, ac := range a.Accounts() {
		ac.dirty()
	}
}

// ConnectionsView: what GET /api/connections answers. Before the first test of this run it is the saved keys and
// settings read live (never blank), marked checking, and the first test is started if nothing has started it.
func (a *App) ConnectionsView() Connections {
	a.mu.RLock()
	c, cfg := a.conns, a.cfg
	a.mu.RUnlock()
	if c.Checked {
		return c
	}
	c.Checking = true
	c.Store = a.sec.Kind()
	c.Meta.Token = secrets.Describe(a.sec, "meta_token")
	c.Meta.Secret = secrets.Describe(a.sec, "meta_app_secret")
	c.Meta.AppID = cfg.MetaAppID
	c.Claude.Key = secrets.Describe(a.sec, "anthropic_key")
	c.Shop.Token = secrets.Describe(a.sec, "shop_token")
	c.Shop.FeedURL = cfg.ShopFeedURL
	if a.firstTest.CompareAndSwap(false, true) {
		go func() {
			ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
			defer cancel()
			a.connectAll(ctx)
		}()
	}
	return c
}

// connectAll: on launch and after a Settings change. The three run side by side; then every account the app knows
// is checked (its links) and the history import starts for any account that has none yet.
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
	conns := Connections{Meta: m, Claude: c, Shop: s, Store: a.sec.Kind(), Checked: true}
	a.mu.Lock()
	a.conns = conns
	if a.cfg.Selected == "" && len(a.portfolio.AdAccounts) > 0 {
		a.cfg.Selected = a.portfolio.AdAccounts[0].ID
		_ = a.st.Save("config", a.cfg)
	}
	a.mu.Unlock()
	a.dirtyAll()
	a.st.Append("config", fmt.Sprintf("connections: meta %v (%d ad accounts), claude %v, shop %v (%d products)", m.OK, m.Accounts, c.OK, s.OK, s.Products), conns)
	if a.publish != nil {
		a.publish("connections", map[string]any{"account": "", "data": conns})
	}
	if m.OK {
		for _, ac := range a.Accounts() {
			if ac.Automated() {
				ac.checkLinks(ctx)
			}
			a.startBackfill(ac)
		}
		if sel := a.Selected(); sel != "" {
			if ac, err := a.Acct(sel); err == nil {
				a.startBackfill(ac)
			}
		}
	}
	return conns
}

func (a *App) metaOK() bool {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.conns.Meta.OK
}

// redact: a secret the brain holds (generator keys) joins the redaction list of the app log and of every account log.
func (a *App) redact(v string) {
	if len(v) < 6 {
		return
	}
	a.st.AddSecret(v)
	for _, ac := range a.Accounts() {
		ac.st.AddSecret(v)
	}
}
