package main

// api.go — the HTTP API (docs/ENGINE-API.md lists every endpoint for the UI lane).
//
// Account scoping: every account-scoped endpoint acts on the ad account named by the X-KAA-Account header, or the
// ?account= query parameter (the SSE stream and links use it), or else the selected account (POST
// /api/accounts/select). SSE events carry {"account": "act_…" | "", "data": …}.

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"

	"kaa/model"
	"kaa/secrets"
)

const maxBody = 1 << 20

var (
	actRe = regexp.MustCompile(`^act_[0-9]{5,20}$`)
	idRe  = regexp.MustCompile(`^[0-9]{5,25}$`)
	verRe = regexp.MustCompile(`^v[0-9]{2}\.0$`)
)

func readJSON(r *http.Request, v any, limit int64) error {
	dec := json.NewDecoder(io.LimitReader(r.Body, limit))
	dec.DisallowUnknownFields()
	if err := dec.Decode(v); err != nil {
		return fmt.Errorf("bad JSON: %v", err)
	}
	return nil
}

// acct: the account a request is about.
func (s *server) acct(w http.ResponseWriter, r *http.Request) *Acct {
	id := r.Header.Get("X-KAA-Account")
	if id == "" {
		id = r.URL.Query().Get("account")
	}
	if id == "" {
		id = s.app.Selected()
	}
	if id == "" {
		jsonErr(w, 409, "no ad account yet: connect Meta first (Settings → Connections → Meta)")
		return nil
	}
	ac, err := s.app.Acct(id)
	if err != nil {
		jsonErr(w, 404, err.Error())
		return nil
	}
	return ac
}

func (s *server) api(w http.ResponseWriter, r *http.Request, p string) {
	a := s.app
	switch {
	case p == "/api/events":
		s.events(w, r)
	case p == "/api/stop" && r.Method == http.MethodPost:
		writeJSON(w, map[string]bool{"ok": true})
		go func() { time.Sleep(200 * time.Millisecond); s.stop() }()

	// ── shared ──
	case p == "/api/connections" && r.Method == http.MethodGet:
		a.mu.RLock()
		c := a.conns
		a.mu.RUnlock()
		writeJSON(w, c)
	case p == "/api/connections/retest" && r.Method == http.MethodPost: // "Try again": read the keys and test again
		ctx, cancel := context.WithTimeout(r.Context(), 2*time.Minute)
		defer cancel()
		writeJSON(w, a.connectAll(ctx))
	case strings.HasPrefix(p, "/api/connections/") && r.Method == http.MethodPut:
		s.putConnection(w, r, strings.TrimPrefix(p, "/api/connections/"))
	case p == "/api/accounts" && r.Method == http.MethodGet:
		writeJSON(w, s.accountsView())
	case p == "/api/accounts/select" && r.Method == http.MethodPost:
		var in struct {
			Account string `json:"account"`
		}
		if err := readJSON(r, &in, maxBody); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		ac, err := a.Acct(in.Account)
		if err != nil {
			jsonErr(w, 404, err.Error())
			return
		}
		a.mu.Lock()
		a.cfg.Selected = ac.id
		cfg := a.cfg
		a.mu.Unlock()
		_ = a.st.Save("config", cfg)
		a.startBackfill(ac)
		ac.mu.RLock()
		never := ac.lastSync.IsZero()
		ac.mu.RUnlock()
		if never && a.metaOK() { // an account never read yet shows its campaigns within seconds of the switch
			go func() {
				if !ac.work.TryLock() {
					return
				}
				defer ac.work.Unlock()
				ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
				defer cancel()
				if err := ac.Sync(ctx); err != nil {
					ac.alertOnce("Sync with Meta failed: " + err.Error())
				}
			}()
		}
		writeJSON(w, map[string]string{"selected": ac.id})
	case strings.HasPrefix(p, "/api/accounts/") && r.Method == http.MethodPut:
		s.putAccount(w, r, strings.TrimPrefix(p, "/api/accounts/"))
	case p == "/api/limits/global" && r.Method == http.MethodGet:
		a.mu.RLock()
		g := map[string]float64{"daily_aed": a.cfg.GlobalDailyAED, "monthly_aed": a.cfg.GlobalMonthlyAED}
		a.mu.RUnlock()
		writeJSON(w, g)
	case p == "/api/limits/global" && r.Method == http.MethodPut:
		var in struct {
			Daily   float64 `json:"daily_aed"`
			Monthly float64 `json:"monthly_aed"`
		}
		if err := readJSON(r, &in, maxBody); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		if !finite(in.Daily, in.Monthly) || in.Daily > 100000 || in.Monthly > 3000000 || in.Daily > 0 && in.Monthly > 0 && in.Monthly < in.Daily {
			jsonErr(w, 400, "all-accounts limits: AED, 0 = none, monthly ≥ daily")
			return
		}
		a.mu.Lock()
		a.cfg.GlobalDailyAED, a.cfg.GlobalMonthlyAED = in.Daily, in.Monthly
		cfg := a.cfg
		a.mu.Unlock()
		_ = a.st.Save("config", cfg)
		a.st.Append("config", fmt.Sprintf("all-accounts limits: %.0f AED/day, %.0f AED/month", in.Daily, in.Monthly), in)
		writeJSON(w, in)
	case p == "/api/settings" && r.Method == http.MethodGet:
		a.mu.RLock()
		c := a.cfg
		a.mu.RUnlock()
		writeJSON(w, map[string]any{"sync_minutes": c.SyncMinutes, "plans_per_day": c.PlansPerDay, "claude_model": c.ClaudeModel, "api_version": c.APIVersion})
	case p == "/api/settings" && r.Method == http.MethodPut:
		var in struct {
			SyncMinutes int `json:"sync_minutes"`
			PlansPerDay int `json:"plans_per_day"`
		}
		if err := readJSON(r, &in, maxBody); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		if in.SyncMinutes < 15 || in.SyncMinutes > 1440 || in.PlansPerDay < 1 || in.PlansPerDay > 24 {
			jsonErr(w, 400, "sync_minutes 15–1440, plans_per_day 1–24")
			return
		}
		a.mu.Lock()
		a.cfg.SyncMinutes, a.cfg.PlansPerDay = in.SyncMinutes, in.PlansPerDay
		c := a.cfg
		a.mu.Unlock()
		_ = a.st.Save("config", c)
		a.st.Append("config", fmt.Sprintf("schedule: sync every %d min, plan %d×/day", c.SyncMinutes, c.PlansPerDay), in)
		writeJSON(w, in)
	case p == "/api/research" && r.Method == http.MethodGet:
		a.mu.RLock()
		rs := a.research
		a.mu.RUnlock()
		writeJSON(w, rs)
	case p == "/api/learnings" && r.Method == http.MethodGet:
		a.mu.RLock()
		l := a.learnings
		a.mu.RUnlock()
		if l == nil {
			l = []model.Learning{}
		}
		writeJSON(w, l)
	case p == "/api/brain/research" && r.Method == http.MethodPost:
		s.brainResearch(w, r)
	case p == "/api/kill" && r.Method == http.MethodPost:
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		id := r.Header.Get("X-KAA-Account")
		if id == "" {
			id = r.URL.Query().Get("account")
		}
		if id == "" || id == "all" { // the big red button: every account
			var outs []map[string]any
			for _, ac := range a.Accounts() {
				outs = append(outs, ac.Kill(ctx))
			}
			writeJSON(w, map[string]any{"accounts": outs})
			return
		}
		ac, err := a.Acct(id)
		if err != nil {
			jsonErr(w, 404, err.Error())
			return
		}
		writeJSON(w, ac.Kill(ctx))
	case strings.HasPrefix(p, "/api/test/") && mockURL != "":
		s.testAPI(w, r, p)

	// ── per account ──
	case p == "/api/state" && r.Method == http.MethodGet:
		if ac := s.acct(w, r); ac != nil {
			w.Header().Set("Content-Type", "application/json")
			w.Header().Set("Cache-Control", "no-store")
			_, _ = w.Write(ac.stateBytes())
		}
	case p == "/api/limits" && r.Method == http.MethodGet:
		if ac := s.acct(w, r); ac != nil {
			writeJSON(w, ac.Settings().Limits)
		}
	case p == "/api/limits" && r.Method == http.MethodPut:
		if ac := s.acct(w, r); ac != nil {
			s.putLimits(w, r, ac)
		}
	case p == "/api/cycle" && r.Method == http.MethodPost:
		ac := s.acct(w, r)
		if ac == nil {
			return
		}
		var in struct {
			Mode string `json:"mode"`
		}
		_ = readJSON(r, &in, maxBody)
		if in.Mode != model.AutopilotAuto {
			in.Mode = model.AutopilotShadow
		}
		out, err := ac.Cycle(context.Background(), in.Mode)
		if errors.Is(err, errBusy) || errors.Is(err, errUpdating) {
			jsonErr(w, 409, err.Error())
			return
		}
		if err != nil {
			w.WriteHeader(502)
		}
		writeJSON(w, out)
	case p == "/api/sync" && r.Method == http.MethodPost:
		ac := s.acct(w, r)
		if ac == nil {
			return
		}
		if !ac.work.TryLock() {
			jsonErr(w, 409, errBusy.Error())
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 10*time.Minute)
		err := ac.Sync(ctx)
		cancel()
		ac.work.Unlock()
		if err != nil {
			jsonErr(w, 502, err.Error())
			return
		}
		writeJSON(w, map[string]any{"ok": true, "account": ac.id, "at": a.now()})
	case p == "/api/safety" && r.Method == http.MethodPost:
		if ac := s.acct(w, r); ac != nil {
			rep, err := ac.ensureSafety(r.Context())
			if err != nil {
				jsonErr(w, 502, err.Error())
				return
			}
			writeJSON(w, rep)
		}
	case p == "/api/backfill":
		ac := s.acct(w, r)
		if ac == nil {
			return
		}
		if r.Method == http.MethodPost {
			ac.mu.Lock()
			if ac.backfill.State == "error" || ac.backfill.State == "done" && r.URL.Query().Get("again") == "1" {
				if ac.backfill.State == "done" {
					ac.backfill = BackfillState{}
				}
				ac.backfill.State = ""
			}
			ac.mu.Unlock()
			a.startBackfill(ac)
		}
		ac.mu.RLock()
		bf := ac.backfill
		ac.mu.RUnlock()
		writeJSON(w, bf)
	case p == "/api/log" && r.Method == http.MethodGet:
		after, _ := strconv.ParseInt(r.URL.Query().Get("after"), 10, 64)
		limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
		kinds := strings.Split(r.URL.Query().Get("kind"), ",")
		if r.URL.Query().Get("scope") == "app" { // the shared log: connections, Claude usage, research
			writeJSON(w, map[string]any{"entries": a.st.Log(after, limit, kinds...), "last": a.st.LastSeq()})
			return
		}
		if ac := s.acct(w, r); ac != nil {
			writeJSON(w, map[string]any{"account": ac.id, "entries": ac.st.Log(after, limit, kinds...), "last": ac.st.LastSeq()})
		}
	case p == "/api/creatives" && r.Method == http.MethodGet:
		if ac := s.acct(w, r); ac != nil {
			writeJSON(w, ac.creatives.all())
		}
	case p == "/api/creatives" && r.Method == http.MethodPost:
		ac := s.acct(w, r)
		if ac == nil {
			return
		}
		var cr model.Creative
		if err := readJSON(r, &cr, 96<<20); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		out, err := ac.creatives.Add(cr, a.now())
		if err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		ac.st.Append("config", "creative added: "+out.ID+" ("+out.Lang+" "+out.Format+", "+out.Source+")", out)
		ac.dirty()
		writeJSON(w, out)
	case strings.HasPrefix(p, "/api/creatives/") && strings.HasSuffix(p, "/file") && (r.Method == http.MethodGet || r.Method == http.MethodHead):
		s.creativeFile(w, r, strings.TrimSuffix(strings.TrimPrefix(p, "/api/creatives/"), "/file"))
	case p == "/api/playbook" || p == "/api/jobs" || strings.HasPrefix(p, "/api/jobs/") || p == "/api/funnel": // media-buying lane (playbook.go, jobs.go, funnel.go)
		if ac := s.acct(w, r); ac != nil {
			switch {
			case p == "/api/playbook":
				s.playbookAPI(w, r, ac)
			case p == "/api/funnel":
				s.funnelAPI(w, r, ac)
			default:
				s.jobsAPI(w, r, ac, strings.TrimPrefix(strings.TrimPrefix(p, "/api/jobs"), "/"))
			}
		}
	case p == "/api/proposals" || strings.HasPrefix(p, "/api/proposals/"):
		s.proposalsAPI(w, r, p)
	case p == "/api/launch" && r.Method == http.MethodPost: // Create with Claude → Run it / Launch now (create_wire.go)
		s.launchAPI(w, r)
	case (p == "/api/render" || strings.HasPrefix(p, "/api/render/")) && r.Method == http.MethodPost:
		s.renderAPI(w, r, p)
	case p == "/api/mcp" || strings.HasPrefix(p, "/api/mcp/"):
		s.mcpAPI(w, r, p)
	case (p == "/api/brain/plan" || p == "/api/brain/copy") && r.Method == http.MethodPost:
		if ac := s.acct(w, r); ac != nil {
			s.brainAPI(w, r, strings.TrimPrefix(p, "/api/brain/"), ac)
		}
	default:
		if s.brainExtra(w, r, p) { // LANE BRAIN's routes (brain_wire.go)
			return
		}
		if !s.updateAPI(w, r, p) {
			jsonErr(w, 404, "no such endpoint")
		}
	}
}

type accountView struct {
	model.AdAccountRef
	LimitsSet bool                 `json:"limits_set"`
	Autopilot string               `json:"autopilot"`
	Links     model.AccountLinks   `json:"links"`
	Checks    map[string]linkCheck `json:"link_checks,omitempty"`
	Backfill  BackfillState        `json:"backfill"`
	Selected  bool                 `json:"selected"`
}

// accountsView: GET /api/accounts.
func (s *server) accountsView() map[string]any {
	a := s.app
	sel := a.Selected()
	a.mu.RLock()
	p := a.portfolio
	global := map[string]float64{"daily_aed": a.cfg.GlobalDailyAED, "monthly_aed": a.cfg.GlobalMonthlyAED}
	a.mu.RUnlock()
	seen := map[string]bool{}
	var list []accountView
	add := func(ref model.AdAccountRef) {
		v := accountView{AdAccountRef: ref, Selected: ref.ID == sel, Autopilot: model.AutopilotOff}
		a.mu.RLock()
		ac := a.accounts[ref.ID]
		a.mu.RUnlock()
		if ac != nil {
			ac.mu.RLock()
			v.Automated, v.LimitsSet, v.Autopilot, v.Links, v.Checks, v.Backfill = ac.settings.Automated, ac.settings.Limits.Set(), ac.settings.Limits.Autopilot, ac.settings.Links, ac.linkState, ac.backfill
			if v.Name == "" {
				v.Name = ac.account.Name
			}
			ac.mu.RUnlock()
		}
		seen[ref.ID] = true
		list = append(list, v)
	}
	for _, ref := range p.AdAccounts {
		add(ref)
	}
	for _, ac := range a.Accounts() { // accounts with data the token no longer lists
		if !seen[ac.id] {
			add(model.AdAccountRef{ID: ac.id, Relation: "not visible to the token"})
		}
	}
	if list == nil {
		list = []accountView{}
	}
	return map[string]any{"selected": sel, "accounts": list, "businesses": p.Businesses, "read_at": p.ReadAt, "global_limits": global}
}

// putAccount: PUT /api/accounts/{act} {automated?, links?} — switch an account on/off and link its assets (each
// link is checked live and the answer says which are green).
func (s *server) putAccount(w http.ResponseWriter, r *http.Request, id string) {
	ac, err := s.app.Acct(id)
	if err != nil {
		jsonErr(w, 404, err.Error())
		return
	}
	var in struct {
		Automated *bool               `json:"automated"`
		Links     *model.AccountLinks `json:"links"`
	}
	if err := readJSON(r, &in, maxBody); err != nil {
		jsonErr(w, 400, err.Error())
		return
	}
	if l := in.Links; l != nil {
		for _, v := range []string{l.PageID, l.InstagramUserID, l.PixelID, l.CatalogID} {
			if v != "" && !idRe.MatchString(v) {
				jsonErr(w, 400, fmt.Sprintf("%q is not a Meta id (digits)", v))
				return
			}
		}
		if l.WhatsAppNumber != "" && !e164.MatchString(l.WhatsAppNumber) {
			jsonErr(w, 400, "whatsapp_number: an international number like +9715XXXXXXXX")
			return
		}
	}
	ac.mu.Lock()
	if in.Automated != nil {
		ac.settings.Automated = *in.Automated
	}
	if in.Links != nil {
		ac.settings.Links = *in.Links
	}
	set := ac.settings
	ac.stateJSON = nil
	ac.mu.Unlock()
	ac.saveSettings()
	ac.st.Append("config", fmt.Sprintf("account settings: automated %v", set.Automated), set)
	ctx, cancel := context.WithTimeout(r.Context(), time.Minute)
	defer cancel()
	checks := map[string]linkCheck{}
	if s.app.metaOK() {
		checks = ac.checkLinks(ctx)
	}
	s.app.startBackfill(ac)
	writeJSON(w, map[string]any{"account": ac.id, "automated": set.Automated, "links": set.Links, "link_checks": checks})
}

// putConnection saves ids and keys (keys go to the Keychain only; the answer shows {set,last4}) and tests at once.
func (s *server) putConnection(w http.ResponseWriter, r *http.Request, which string) {
	a := s.app
	ctx, cancel := context.WithTimeout(r.Context(), 2*time.Minute)
	defer cancel()
	switch which {
	case "meta":
		var in struct {
			Token      string `json:"token"`
			AppID      string `json:"app_id"`
			AppSecret  string `json:"app_secret"`
			APIVersion string `json:"api_version"`
			Auth       string `json:"auth"` // system_user (default) | user_token
			// one-account shortcut (the first build's form): links these to that account and selects it
			AccountID string `json:"account_id"`
			PageID    string `json:"page_id"`
			PixelID   string `json:"pixel_id"`
		}
		if err := readJSON(r, &in, maxBody); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		if in.AccountID != "" && !strings.HasPrefix(in.AccountID, "act_") {
			in.AccountID = "act_" + in.AccountID
		}
		for _, chk := range []struct {
			v  string
			re *regexp.Regexp
			n  string
		}{{in.AccountID, actRe, "account_id (act_…)"}, {in.AppID, idRe, "app_id"}, {in.PageID, idRe, "page_id"}, {in.PixelID, idRe, "pixel_id"}, {in.APIVersion, verRe, "api_version (v27.0)"}} {
			if chk.v != "" && !chk.re.MatchString(chk.v) {
				jsonErr(w, 400, chk.n+" does not look right")
				return
			}
		}
		if in.Auth != "" && in.Auth != AuthSystemUser && in.Auth != AuthUserToken {
			jsonErr(w, 400, `auth must be "system_user" or "user_token"`)
			return
		}
		for _, k := range [][2]string{{"meta_token", in.Token}, {"meta_app_secret", in.AppSecret}} {
			if k[1] != "" {
				if err := secrets.CheckFormat(k[0], strings.TrimSpace(k[1])); err != nil {
					jsonErr(w, 400, err.Error())
					return
				}
			}
		}
		if in.AppSecret != "" {
			if err := a.sec.Set("meta_app_secret", strings.TrimSpace(in.AppSecret)); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
		}
		a.mu.Lock()
		if in.Auth != "" {
			a.cfg.MetaAuth = in.Auth
		}
		if in.AppID != "" {
			a.cfg.MetaAppID = in.AppID
		}
		if in.APIVersion != "" {
			a.cfg.APIVersion = in.APIVersion
		}
		auth := a.cfg.MetaAuth
		a.mu.Unlock()
		a.wire()
		if in.Token != "" {
			if err := secrets.ValidValue(strings.TrimSpace(in.Token)); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
			if err := a.saveMetaToken(ctx, auth, in.Token); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
			a.mu.Lock()
			a.cfg.TokenExpires, a.cfg.TokenType, a.cfg.TokenScopes = time.Time{}, "", nil
			a.mu.Unlock()
		}
		a.mu.RLock()
		cfg := a.cfg
		a.mu.RUnlock()
		_ = a.st.Save("config", cfg)
		a.st.Append("config", "Meta connection saved", map[string]any{"auth": cfg.MetaAuth, "app": cfg.MetaAppID, "version": cfg.APIVersion, "token": secrets.Describe(a.sec, "meta_token")})
		for _, ac := range a.Accounts() {
			for _, v := range a.secretValues() {
				ac.st.AddSecret(v)
			}
		}
		c := a.testMeta(ctx)
		a.mu.Lock()
		a.conns.Meta = c
		a.mu.Unlock()
		a.dirtyAll()
		if c.OK && in.AccountID != "" {
			if ac, err := a.Acct(in.AccountID); err == nil {
				ac.mu.Lock()
				if in.PageID != "" {
					ac.settings.Links.PageID = in.PageID
				}
				if in.PixelID != "" {
					ac.settings.Links.PixelID = in.PixelID
				}
				ac.mu.Unlock()
				ac.saveSettings()
				a.mu.Lock()
				a.cfg.Selected = ac.id
				cfg = a.cfg
				a.mu.Unlock()
				_ = a.st.Save("config", cfg)
			}
		}
		writeJSON(w, c)
	case "claude":
		var in struct {
			APIKey string `json:"api_key"`
			Model  string `json:"model"`
		}
		if err := readJSON(r, &in, maxBody); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		if in.Model != "" && !regexp.MustCompile(`^claude-[a-z0-9-]{3,40}$`).MatchString(in.Model) {
			jsonErr(w, 400, "model does not look like a Claude model id")
			return
		}
		if in.APIKey != "" {
			if err := secrets.CheckFormat("anthropic_key", strings.TrimSpace(in.APIKey)); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
			if err := a.sec.Set("anthropic_key", strings.TrimSpace(in.APIKey)); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
		}
		a.mu.Lock()
		if in.Model != "" {
			a.cfg.ClaudeModel = in.Model
		}
		cfg := a.cfg
		a.mu.Unlock()
		_ = a.st.Save("config", cfg)
		a.st.Append("config", "Claude connection saved", map[string]any{"model": cfg.ClaudeModel, "key": secrets.Describe(a.sec, "anthropic_key")})
		a.wire()
		c := a.testClaude(ctx)
		a.mu.Lock()
		a.conns.Claude = c
		a.mu.Unlock()
		a.dirtyAll()
		writeJSON(w, c)
	case "shop":
		var in struct {
			FeedURL string `json:"feed_url"`
			Token   string `json:"token"`
		}
		if err := readJSON(r, &in, maxBody); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		if in.FeedURL != "" && !strings.HasPrefix(in.FeedURL, "https://kbeautybliss.com/") {
			jsonErr(w, 400, "feed_url must be on https://kbeautybliss.com/")
			return
		}
		if in.Token != "" {
			if err := secrets.CheckFormat("shop_token", strings.TrimSpace(in.Token)); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
			if err := a.sec.Set("shop_token", strings.TrimSpace(in.Token)); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
		}
		a.mu.Lock()
		if in.FeedURL != "" {
			a.cfg.ShopFeedURL = in.FeedURL
		}
		cfg := a.cfg
		a.mu.Unlock()
		_ = a.st.Save("config", cfg)
		a.st.Append("config", "shop connection saved", map[string]any{"feed": cfg.ShopFeedURL})
		a.wire()
		c := a.testShop(ctx)
		a.mu.Lock()
		a.conns.Shop = c
		a.mu.Unlock()
		writeJSON(w, c)
	default:
		jsonErr(w, 404, "meta, claude or shop")
	}
}

func finite(xs ...float64) bool {
	for _, x := range xs {
		if math.IsNaN(x) || math.IsInf(x, 0) || x < 0 {
			return false
		}
	}
	return true
}

var countryRe = regexp.MustCompile(`^[A-Z]{2}$`)

func validLimits(l model.Limits) error {
	if !finite(l.DailyAED, l.MonthlyAED, l.MaxBudgetStepPct, l.CooldownHours, l.TargetCPAAED, l.KillMultiple) {
		return errors.New("numbers must be 0 or more")
	}
	if l.DailyAED > 100000 || l.MonthlyAED > 3000000 {
		return errors.New("that is above the app's sanity ceiling (AED 100,000 a day, 3,000,000 a month)")
	}
	if l.DailyAED > 0 && l.MonthlyAED > 0 && l.MonthlyAED < l.DailyAED {
		return errors.New("the monthly limit is below the daily limit")
	}
	if l.MaxBudgetStepPct < 1 || l.MaxBudgetStepPct > 50 {
		return errors.New("max_budget_step_pct must be 1–50")
	}
	if l.CooldownHours > 720 {
		return errors.New("cooldown_hours must be 0–720")
	}
	if l.MaxActiveAds < 1 || l.MaxActiveAds > 50 {
		return errors.New("max_active_ads must be 1–50")
	}
	if len(l.Countries) == 0 || len(l.Countries) > 30 {
		return errors.New("1–30 countries")
	}
	for _, c := range l.Countries {
		if !countryRe.MatchString(c) {
			return fmt.Errorf("country %q is not a 2-letter code like AE", c)
		}
	}
	if l.Autopilot != model.AutopilotOff && l.Autopilot != model.AutopilotShadow && l.Autopilot != model.AutopilotAuto {
		return errors.New(`autopilot must be "off", "shadow" or "auto"`)
	}
	if l.Wording != "" && l.Wording != "strict" && l.Wording != "balanced" && l.Wording != "relaxed" {
		return errors.New(`wording must be "strict", "balanced" or "relaxed"`)
	}
	if l.KillMultiple != 0 && (l.KillMultiple < 1 || l.KillMultiple > 10) {
		return errors.New("kill_multiple must be 1–10")
	}
	return nil
}

func (s *server) putLimits(w http.ResponseWriter, r *http.Request, ac *Acct) {
	l := ac.Settings().Limits
	if err := readJSON(r, &l, maxBody); err != nil { // fields not sent keep their value
		jsonErr(w, 400, err.Error())
		return
	}
	for i := range l.Countries {
		l.Countries[i] = strings.ToUpper(strings.TrimSpace(l.Countries[i]))
	}
	if l.KillMultiple == 0 {
		l.KillMultiple = 3
	}
	if err := validLimits(l); err != nil {
		jsonErr(w, 400, err.Error())
		return
	}
	ac.mu.Lock()
	old := ac.settings.Limits
	ac.settings.Limits, ac.stateJSON = l, nil
	auto := ac.settings.Automated
	ac.mu.Unlock()
	ac.saveSettings()
	ac.st.Append("config", fmt.Sprintf("limits: %.0f AED/day, %.0f AED/month, autopilot %s", l.DailyAED, l.MonthlyAED, l.Autopilot), map[string]any{"before": old, "after": l})
	if s.app.metaOK() && auto && l.Set() {
		go func() { // the Meta-side net follows the new limits at once
			ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
			defer cancel()
			_, _ = ac.ensureSafety(ctx)
		}()
	}
	writeJSON(w, l)
}

func (s *server) brainResearch(w http.ResponseWriter, r *http.Request) {
	a := s.app
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Minute)
	defer cancel()
	var q struct {
		Focus string `json:"focus"`
	}
	_ = readJSON(r, &q, maxBody)
	a.mu.RLock()
	br, shopSnap, prev := a.brain, a.shopSnap, a.research
	a.mu.RUnlock()
	res, err := br.Research(ctx, model.ResearchInput{Now: a.now(), Shop: shopSnap, Previous: prev, Focus: q.Focus})
	if err != nil {
		jsonErr(w, 502, err.Error())
		return
	}
	a.mu.Lock()
	a.research = res
	a.mu.Unlock()
	a.dirtyAll()
	_ = a.st.Save("research", res)
	a.st.Append("research", "research updated", map[string]any{"trends": len(res.Trends), "angles": len(res.Angles), "sources": len(res.Sources)})
	s.publish("research.updated", map[string]any{"account": "", "data": map[string]any{"at": res.UpdatedAt}})
	writeJSON(w, res)
}

func (s *server) brainAPI(w http.ResponseWriter, r *http.Request, what string, ac *Acct) {
	a := s.app
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Minute)
	defer cancel()
	a.mu.RLock()
	br, shopSnap, research, learnings := a.brain, a.shopSnap, a.research, a.learnings
	a.mu.RUnlock()
	ac.mu.RLock()
	in := model.CycleInput{Now: a.now(), Limits: ac.settings.Limits, Account: ac.accountRef(), Tree: cloneTree(ac.tree), Shop: shopSnap, Research: research, Links: ac.settings.Links, Learnings: learnings}
	ac.mu.RUnlock()
	switch what {
	case "plan": // a preview: the brain plans, nothing is executed
		in.Metrics, in.Creatives = ac.metricRows(), ac.creatives.all()
		in.History = ac.st.Recent(200, "execution", "check", "cycle", "kill", "alert", "safety")
		in.LongHistory, in.Creatives37 = ac.longHistory()
		out, err := br.Cycle(ctx, in)
		if err != nil {
			jsonErr(w, 502, err.Error())
			return
		}
		ac.st.Append("proposal", fmt.Sprintf("plan preview: %d proposals (not executed)", len(out.Proposals)), out)
		writeJSON(w, out)
	case "copy":
		cw, ok := br.(model.Copywriter)
		if !ok {
			jsonErr(w, 501, "this brain does not write copy")
			return
		}
		var q model.CopyInput
		body, _ := io.ReadAll(io.LimitReader(r.Body, maxBody))
		if err := json.Unmarshal(bytes.TrimSpace(body), &q); err != nil && len(bytes.TrimSpace(body)) > 0 {
			jsonErr(w, 400, "bad JSON")
			return
		}
		q.Shop, q.Research = in.Shop, in.Research
		out, err := cw.Copy(ctx, q)
		if err != nil {
			jsonErr(w, 502, err.Error())
			return
		}
		writeJSON(w, out)
	}
}

// testAPI: only with -mock. Lets tools/engine-check drive the fake Meta (inject errors, read its writes).
func (s *server) testAPI(w http.ResponseWriter, r *http.Request, p string) {
	var target string
	switch p {
	case "/api/test/meta-inject":
		target = mockURL + "/__mock/inject"
	case "/api/test/meta-writes":
		target = mockURL + "/__mock/writes"
	case "/api/test/meta-tag": // {"id", "tag": WINNER|LOSER}: seeded results for one Meta object
		target = mockURL + "/__mock/tag"
	case "/api/test/claude-calls": // the fake Claude's scripted answers, by kind
		target = mockClaudeURL + "/__mock/claude-calls"
	case "/api/test/clock": // {"advance_hours": n}: move the engine's and the fake Meta's clock forward
		var in struct {
			AdvanceHours float64 `json:"advance_hours"`
		}
		if err := readJSON(r, &in, 4096); err != nil || in.AdvanceHours < 0 || in.AdvanceHours > 24*400 {
			jsonErr(w, 400, "advance_hours 0–9600")
			return
		}
		mockOffset.Add(int64(in.AdvanceHours * float64(time.Hour)))
		s.app.dirtyAll()
		writeJSON(w, map[string]any{"now": s.app.now().UTC()})
		return
	default:
		jsonErr(w, 404, "no such test endpoint")
		return
	}
	req, _ := http.NewRequestWithContext(r.Context(), r.Method, target, r.Body)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		jsonErr(w, 502, err.Error())
		return
	}
	defer resp.Body.Close()
	w.Header().Set("Content-Type", "application/json")
	_, _ = io.Copy(w, resp.Body)
}
