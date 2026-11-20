package main

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

func readJSON(r *http.Request, v any, limit int64) error {
	dec := json.NewDecoder(io.LimitReader(r.Body, limit))
	dec.DisallowUnknownFields()
	if err := dec.Decode(v); err != nil {
		return fmt.Errorf("bad JSON: %v", err)
	}
	return nil
}

func (s *server) api(w http.ResponseWriter, r *http.Request, p string) {
	a := s.app
	switch {
	case p == "/api/events":
		s.events(w, r)
	case p == "/api/stop" && r.Method == http.MethodPost:
		writeJSON(w, map[string]bool{"ok": true})
		go func() { time.Sleep(200 * time.Millisecond); s.stop() }()
	case p == "/api/state" && r.Method == http.MethodGet:
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		_, _ = w.Write(a.stateBytes())
	case p == "/api/connections" && r.Method == http.MethodGet:
		a.mu.RLock()
		c := a.conns
		a.mu.RUnlock()
		writeJSON(w, c)
	case strings.HasPrefix(p, "/api/connections/") && r.Method == http.MethodPut:
		s.putConnection(w, r, strings.TrimPrefix(p, "/api/connections/"))
	case p == "/api/limits" && r.Method == http.MethodGet:
		a.mu.RLock()
		l := a.limits
		a.mu.RUnlock()
		writeJSON(w, l)
	case p == "/api/limits" && r.Method == http.MethodPut:
		s.putLimits(w, r)
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
	case p == "/api/cycle" && r.Method == http.MethodPost:
		var in struct {
			Mode string `json:"mode"`
		}
		_ = readJSON(r, &in, maxBody)
		if in.Mode != model.AutopilotAuto {
			in.Mode = model.AutopilotShadow
		}
		out, err := a.Cycle(context.Background(), in.Mode)
		if errors.Is(err, errBusy) {
			jsonErr(w, 409, err.Error())
			return
		}
		if err != nil {
			w.WriteHeader(502)
		}
		writeJSON(w, out)
	case p == "/api/sync" && r.Method == http.MethodPost:
		if !a.work.TryLock() {
			jsonErr(w, 409, errBusy.Error())
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 10*time.Minute)
		err := a.Sync(ctx)
		cancel()
		a.work.Unlock()
		if err != nil {
			jsonErr(w, 502, err.Error())
			return
		}
		writeJSON(w, map[string]any{"ok": true, "at": a.now()})
	case p == "/api/safety" && r.Method == http.MethodPost:
		rep, err := a.ensureSafety(r.Context())
		if err != nil {
			jsonErr(w, 502, err.Error())
			return
		}
		writeJSON(w, rep)
	case p == "/api/log" && r.Method == http.MethodGet:
		after, _ := strconv.ParseInt(r.URL.Query().Get("after"), 10, 64)
		limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
		writeJSON(w, map[string]any{"entries": a.st.Log(after, limit, strings.Split(r.URL.Query().Get("kind"), ",")...), "last": a.st.LastSeq()})
	case p == "/api/creatives" && r.Method == http.MethodGet:
		writeJSON(w, a.creatives.all())
	case p == "/api/creatives" && r.Method == http.MethodPost:
		var cr model.Creative
		if err := readJSON(r, &cr, 96<<20); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		out, err := a.creatives.Add(cr, a.now())
		if err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		a.st.Append("config", "creative added: "+out.ID+" ("+out.Lang+" "+out.Format+", "+out.Source+")", out)
		a.mu.Lock()
		a.stateJSON = nil
		a.mu.Unlock()
		writeJSON(w, out)
	case p == "/api/research" && r.Method == http.MethodGet:
		a.mu.RLock()
		rs := a.research
		a.mu.RUnlock()
		writeJSON(w, rs)
	case strings.HasPrefix(p, "/api/brain/") && r.Method == http.MethodPost:
		s.brainAPI(w, r, strings.TrimPrefix(p, "/api/brain/"))
	case p == "/api/kill" && r.Method == http.MethodPost:
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		writeJSON(w, a.Kill(ctx))
	case strings.HasPrefix(p, "/api/test/") && mockURL != "":
		s.testAPI(w, r, p)
	default:
		if !s.updateAPI(w, r, p) {
			jsonErr(w, 404, "no such endpoint")
		}
	}
}

var (
	actRe = regexp.MustCompile(`^act_[0-9]{5,20}$`)
	idRe  = regexp.MustCompile(`^[0-9]{5,25}$`)
	verRe = regexp.MustCompile(`^v[0-9]{2}\.0$`)
)

// putConnection saves ids and keys (keys go to the Keychain only; the answer shows {set,last4}) and tests at once.
func (s *server) putConnection(w http.ResponseWriter, r *http.Request, which string) {
	a := s.app
	ctx, cancel := context.WithTimeout(r.Context(), time.Minute)
	defer cancel()
	switch which {
	case "meta":
		var in struct {
			Token      string `json:"token"`
			AppID      string `json:"app_id"`
			AppSecret  string `json:"app_secret"`
			AccountID  string `json:"account_id"`
			PageID     string `json:"page_id"`
			PixelID    string `json:"pixel_id"`
			APIVersion string `json:"api_version"`
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
		for name, v := range map[string]string{"meta_token": in.Token, "meta_app_secret": in.AppSecret} {
			if v != "" {
				if err := a.sec.Set(name, strings.TrimSpace(v)); err != nil {
					jsonErr(w, 400, err.Error())
					return
				}
			}
		}
		a.mu.Lock()
		for _, kv := range []struct {
			dst *string
			v   string
		}{{&a.cfg.MetaAccountID, in.AccountID}, {&a.cfg.MetaAppID, in.AppID}, {&a.cfg.PageID, in.PageID}, {&a.cfg.PixelID, in.PixelID}, {&a.cfg.APIVersion, in.APIVersion}} {
			if kv.v != "" {
				*kv.dst = kv.v
			}
		}
		cfg := a.cfg
		a.mu.Unlock()
		_ = a.st.Save("config", cfg)
		a.st.Append("config", "Meta connection saved", map[string]any{"account": cfg.MetaAccountID, "page": cfg.PageID, "pixel": cfg.PixelID, "version": cfg.APIVersion, "token": secrets.Describe(a.sec, "meta_token")})
		a.wire()
		c := a.testMeta(ctx)
		a.mu.Lock()
		a.conns.Meta, a.stateJSON = c, nil
		a.mu.Unlock()
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
		a.conns.Claude, a.stateJSON = c, nil
		a.mu.Unlock()
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
		a.conns.Shop, a.stateJSON = c, nil
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
	if l.KillMultiple != 0 && (l.KillMultiple < 1 || l.KillMultiple > 10) {
		return errors.New("kill_multiple must be 1–10")
	}
	return nil
}

func (s *server) putLimits(w http.ResponseWriter, r *http.Request) {
	a := s.app
	a.mu.RLock()
	l := a.limits
	a.mu.RUnlock()
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
	a.mu.Lock()
	old := a.limits
	a.limits, a.stateJSON = l, nil
	ok := a.conns.Meta.OK
	a.mu.Unlock()
	_ = a.st.Save("limits", l)
	a.st.Append("config", fmt.Sprintf("limits: %.0f AED/day, %.0f AED/month, autopilot %s", l.DailyAED, l.MonthlyAED, l.Autopilot), map[string]any{"before": old, "after": l})
	if ok && l.Set() {
		go func() { // the Meta-side net follows the new limits at once
			ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
			defer cancel()
			_, _ = a.ensureSafety(ctx)
		}()
	}
	writeJSON(w, l)
}

func (s *server) brainAPI(w http.ResponseWriter, r *http.Request, what string) {
	a := s.app
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Minute)
	defer cancel()
	a.mu.RLock()
	br := a.brain
	in := model.CycleInput{Now: a.now(), Limits: a.limits, Account: a.account, Tree: cloneTree(a.tree), Shop: a.shopSnap, Research: a.research}
	a.mu.RUnlock()
	switch what {
	case "plan": // a preview: the brain plans, nothing is executed
		in.Metrics, in.Creatives = a.metricRows(), a.creatives.all()
		in.History = a.st.Recent(200, "execution", "check", "cycle", "kill", "alert", "safety")
		out, err := br.Cycle(ctx, in)
		if err != nil {
			jsonErr(w, 502, err.Error())
			return
		}
		a.st.Append("proposal", fmt.Sprintf("plan preview: %d proposals (not executed)", len(out.Proposals)), out)
		writeJSON(w, out)
	case "research":
		var q struct {
			Focus string `json:"focus"`
		}
		_ = readJSON(r, &q, maxBody)
		res, err := br.Research(ctx, model.ResearchInput{Now: a.now(), Shop: in.Shop, Previous: in.Research, Focus: q.Focus})
		if err != nil {
			jsonErr(w, 502, err.Error())
			return
		}
		a.mu.Lock()
		a.research, a.stateJSON = res, nil
		a.mu.Unlock()
		_ = a.st.Save("research", res)
		a.st.Append("research", "research updated", map[string]any{"trends": len(res.Trends), "angles": len(res.Angles), "sources": len(res.Sources)})
		s.publish("research.updated", map[string]any{"at": res.UpdatedAt})
		writeJSON(w, res)
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
	default:
		jsonErr(w, 404, "plan, research or copy")
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
