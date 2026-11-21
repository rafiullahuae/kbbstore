package main

// create_wire.go — the engine side of "Create with Claude" (brain/create.go) and the Claude-app connector (mcp.go):
//
//	POST /api/launch          {key, creative_ids[], launch, daily_aed?} — Run it: the creatives become owner-approved for
//	                          the next cycle; launch:true also makes ONE small test campaign + ad set(s) + ads as owner
//	                          proposals and runs each ad through the existing single-proposal approve path (every
//	                          executor check). Idempotent: the same creatives/key never make a second campaign.
//	POST /api/render          {session_id, op: preview|save} — asks the open app tab to render (SSE render.request) and
//	                          streams NDJSON back: {"step":"queued"} {"step":"progress","done","total"} {"step":"done",…}.
//	                          Jobs are kept until done: a tab that reconnects is sent every unfinished job again, and the
//	                          same (session, op, designs) is one job, so a retried call never renders twice.
//	POST /api/render/{id}     the tab: {progress:{done,total}} or {result:{previews[], creative_ids[], refused, error}}
//	GET  /api/mcp             the connector's status (installed, connected, last call)
//	POST /api/mcp/install     writes the Claude desktop config entry (merged, the old file backed up)
//	POST /api/mcp/seen        the connector process says hello / names its tool (status "connected")

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"
	"sync"
	"time"

	"kaa/executor"
	"kaa/model"
	"kaa/store"
)

// ── Run it / Launch now ──

type launchReq struct {
	Key         string   `json:"key"`
	CreativeIDs []string `json:"creative_ids"`
	Launch      bool     `json:"launch"`
	DailyAED    float64  `json:"daily_aed"`
	Name        string   `json:"name"`
}

func (s *server) launchAPI(w http.ResponseWriter, r *http.Request) {
	ac := s.acct(w, r)
	if ac == nil {
		return
	}
	var in launchReq
	if err := readJSON(r, &in, 64<<10); err != nil || len(in.CreativeIDs) == 0 || len(in.CreativeIDs) > 12 {
		jsonErr(w, 400, "send {creative_ids:[1-12 library ids], launch}")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 10*time.Minute)
	defer cancel()
	out, code, err := ac.launch(ctx, in)
	if err != nil {
		jsonErr(w, code, err.Error())
		return
	}
	writeJSON(w, out)
}

var launchMu sync.Mutex // one launch at a time per engine: a double click waits, then finds its own proposals

func (ac *Acct) launch(ctx context.Context, in launchReq) (map[string]any, int, error) {
	launchMu.Lock()
	defer launchMu.Unlock()
	lib := map[string]model.Creative{}
	for _, c := range ac.creatives.all() {
		lib[c.ID] = c
	}
	ids := append([]string(nil), in.CreativeIDs...)
	sort.Strings(ids)
	var crs []model.Creative
	for _, id := range ids {
		c, ok := lib[id]
		if !ok {
			return nil, 404, fmt.Errorf("no creative %q in this account's library (save it first)", id)
		}
		crs = append(crs, c)
	}
	approved := 0
	for _, c := range crs { // owner-approved: the next cycle's planner puts them in its tests first
		if !c.Approved {
			c.Approved = true
			if err := ac.creatives.Put(c); err != nil {
				return nil, 500, err
			}
			approved++
		}
	}
	if approved > 0 {
		ac.st.Append("approval", fmt.Sprintf("owner: %d creative(s) made with Claude approved for the autopilot", approved), ids)
		ac.dirty()
	}
	out := map[string]any{"account": ac.id, "approved": ids}
	if !in.Launch {
		// Run it: a real job that puts exactly these ads live (ownerrun.go), shown on Plan → Upcoming jobs
		st := ac.Settings()
		jid := ac.queueOwnerRun(ids, "You pressed Run it in Create with Claude.")
		out["job"] = jid
		switch {
		case jid == "":
			out["note"] = "already on Meta"
		case st.Automated && st.Limits.Set() && st.Limits.Autopilot != model.AutopilotOff:
			// he approved them by pressing Run it: they run now in Auto and in Practice (as approved jobs always do)
			ac.mu.RLock()
			active := executor.ActiveAds(ac.tree)
			ac.mu.RUnlock()
			max := st.Limits.MaxActiveAds
			if max <= 0 {
				max = 6 // the executor's default (checks.go maxAds)
			}
			if free := max - active; free < len(ids) {
				// said NOW, not discovered later: the ads wait for a free slot (the autopilot never pauses a
				// running ad to make room for new ones that have no results yet)
				out["waiting"] = true
				out["note"] = fmt.Sprintf("no free ad slot: %d ads are active and your maximum is %d. Raise Settings → Limits → Max active ads to %d and they go live at once (they are queued on Plan → Upcoming jobs)", active, max, active+len(ids))
				out["raise_to"] = active + len(ids)
			} else {
				out["note"], out["starting"] = "going live now, inside your limits: Plan → Upcoming jobs shows each step", true
			}
			ac.runOwnerJobNow(jid)
		case !st.Automated:
			out["note"] = "queued, but this account is not automated by the app (Portfolio → switch it on)"
		case !st.Limits.Set():
			out["note"] = "queued, but nothing launches until the daily and monthly limits are set (Settings → Limits)"
		default:
			out["note"] = "queued, but the autopilot is Off (top bar → Practice or Auto)"
		}
		return out, 200, nil
	}
	st := ac.Settings()
	switch {
	case !st.Automated:
		return nil, 409, errors.New("this account is not automated by the app (Portfolio → switch it on first)")
	case !st.Limits.Set():
		return nil, 409, errors.New("set the daily and monthly limits first: nothing launches without them")
	case st.Limits.Autopilot == model.AutopilotOff:
		return nil, 409, errors.New("the autopilot is off for this account (Practice or Auto runs a launch)")
	}
	h := sha256.Sum256([]byte(strings.Join(ids, ",")))
	tag := "cc" + hex.EncodeToString(h[:])[:8]
	pid := func(s string) string { return "claude-" + tag + "-" + s }
	ps := ac.Pending()
	have := map[string]bool{}
	for _, it := range ps.Items {
		have[it.ID] = true
	}
	ac.mu.RLock()
	tree := cloneTree(ac.tree)
	ac.mu.RUnlock()
	if !have[pid("campaign")] {
		// a test fits the free ad slots: feed pictures first, Arabic first; the rest stay approved for the cycle
		if lim := st.Limits.MaxActiveAds; lim > 0 {
			free := lim - executor.ActiveAds(tree)
			if free <= 0 {
				return nil, 409, fmt.Errorf("no free ad slot (%d ads active, your limit is %d): they stay approved and the autopilot rotates them in, or raise max active ads in Settings → Limits", lim-free, lim)
			}
			rank := func(c model.Creative) int {
				r := 0
				if c.Format != "4:5" {
					r += 2
				}
				if c.Lang != "ar" {
					r++
				}
				return r
			}
			sort.SliceStable(crs, func(i, j int) bool { return rank(crs[i]) < rank(crs[j]) })
			if len(crs) > free {
				out["left_for_cycle"] = len(crs) - free
				crs = crs[:free]
			}
		}
		room := st.Limits.DailyAED - executor.CommittedDaily(tree)
		budget := in.DailyAED
		if budget <= 0 {
			budget = st.Limits.DailyAED * 0.15
			if budget < 10 {
				budget = 10
			}
		}
		if budget > room {
			budget = room
		}
		budget = float64(int(budget))
		if budget < 5 {
			return nil, 409, fmt.Errorf("no room under your daily limit (AED %.0f/day, AED %.0f already committed): raise it or pause something first", st.Limits.DailyAED, st.Limits.DailyAED-room)
		}
		countries := make([]any, 0, len(st.Limits.Countries))
		for _, c := range st.Limits.Countries {
			countries = append(countries, c)
		}
		name := strings.TrimSpace(in.Name)
		if name == "" || len(name) > 60 {
			name = "Made with Claude"
		}
		day := ac.app.now().In(ac.loc()).Format("Jan 2")
		items := []model.Proposal{{ID: pid("campaign"), Kind: model.KindCreateCampaign, Target: "tmp:" + tag + "-c",
			Params: map[string]any{"name": name + " · test " + day, "daily_budget": budget, "type": "sales"},
			Why:    fmt.Sprintf("Owner's launch: a small test of the ads he made with Claude (AED %.0f/day, inside his limits).", budget), Confidence: 0.6}}
		langs := map[string]bool{}
		for _, c := range crs {
			langs[c.Lang] = true
		}
		for _, l := range []string{"ar", "en"} {
			if !langs[l] {
				continue
			}
			p := map[string]any{"name": name + " · " + strings.ToUpper(l), "campaign_id": "tmp:" + tag + "-c", "countries": countries, "destination": "website", "advantage_audience": 1.0}
			if l == "ar" {
				p["genders"], p["age_range"] = []any{2.0}, []any{18.0, 45.0}
			}
			items = append(items, model.Proposal{ID: pid("adset-" + l), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-" + l, Params: p,
				Why: map[string]string{"ar": "Arabic first: Arab women in the GCC.", "en": "English residents and bilingual shoppers."}[l], Confidence: 0.6})
		}
		for i, c := range crs {
			format := "image"
			if c.Media == "mp4" {
				format = "video"
			}
			items = append(items,
				model.Proposal{ID: pid(fmt.Sprintf("cr-%d", i+1)), Kind: model.KindUploadCreative, Target: c.ID,
					Params: map[string]any{"creative_id": c.ID, "format": format, "headline": c.Headline, "primary_text": c.PrimaryText},
					Why:    "Made with Claude by the owner (" + strings.ToUpper(c.Lang) + " " + c.Format + ").", Confidence: 0.6},
				model.Proposal{ID: pid(fmt.Sprintf("ad-%d", i+1)), Kind: model.KindCreateAd, Target: fmt.Sprintf("tmp:%s-ad%d", tag, i+1),
					Params: map[string]any{"name": fmt.Sprintf("%s · %s %s", name, strings.ToUpper(c.Lang), c.Format), "adset_id": "tmp:" + tag + "-" + c.Lang, "creative_id": c.ID},
					Why:    "The owner's ad, switched on after its campaign and ad set (each a checked step).", Confidence: 0.6})
		}
		for _, p := range items {
			ps.Items = append(ps.Items, PendingItem{Proposal: p, Status: "shadow", By: "owner"})
		}
		if ps.Mode == "" {
			ps.Mode = "shadow"
		}
		ac.savePending(ps)
		ac.st.Append("proposal", fmt.Sprintf("owner launch (made with Claude): %d proposals, AED %.0f/day", len(items), budget), items)
	}
	// every ad through the existing approve path (it pulls in its campaign, ad set and creative)
	var results []any
	counts := map[string]int{}
	var adIDs []string
	for _, it := range ac.Pending().Items {
		if strings.HasPrefix(it.ID, pid("ad-")) {
			adIDs = append(adIDs, it.ID)
		}
	}
	for _, id := range adIDs {
		res, _, err := ac.Decide(ctx, id, true)
		if errors.Is(err, errNotWaiting) { // already decided (a retried launch): report what happened
			for _, it := range ac.Pending().Items {
				if strings.HasPrefix(it.ID, "claude-"+tag+"-") {
					counts[it.Status]++
				}
			}
			out["again"] = true
			break
		}
		if err != nil {
			return nil, 409, err
		}
		for k, v := range res["counts"].(map[string]int) {
			counts[k] += v
		}
		results = append(results, res["results"])
	}
	out["counts"], out["results"], out["tag"] = counts, results, tag
	var props []string
	for _, it := range ac.Pending().Items {
		if strings.HasPrefix(it.ID, "claude-"+tag+"-") {
			props = append(props, it.ID)
		}
	}
	out["proposals"] = props
	return out, 200, nil
}

// ── the render broker: the app tab renders, the engine waits ──

type renderJob struct {
	ID       string          `json:"id"`
	Account  string          `json:"account"`
	Session  string          `json:"session_id"`
	Op       string          `json:"op"` // preview | save
	Designs  []string        `json:"design_ids,omitempty"`
	At       time.Time       `json:"at"`
	Done     int             `json:"done"`
	Total    int             `json:"total"`
	Result   json.RawMessage `json:"result,omitempty"`
	finished chan struct{}
	progress chan struct{}
}

var renders = struct {
	sync.Mutex
	m map[string]*renderJob
}{m: map[string]*renderJob{}}

func renderKey(acct, session, op string, designs []string) string {
	d := append([]string(nil), designs...)
	sort.Strings(d)
	h := sha256.Sum256([]byte(acct + "|" + session + "|" + op + "|" + strings.Join(d, ",")))
	return "r" + hex.EncodeToString(h[:])[:16]
}

func (s *server) tabs() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return len(s.subs)
}

// resendRenders: a tab (re)connected — every unfinished job goes to it again (requests are kept until done).
func (s *server) resendRenders(ch chan []byte) {
	renders.Lock()
	defer renders.Unlock()
	for _, j := range renders.m {
		if j.Result == nil {
			b, _ := json.Marshal(map[string]any{"account": j.Account, "data": j})
			select {
			case ch <- []byte("event: render.request\ndata: " + string(b) + "\n\n"):
			default:
			}
		}
	}
}

const errOpenApp = "Open KBB Ads Autopilot (the app's tab draws the pictures), then try again."

func (s *server) renderAPI(w http.ResponseWriter, r *http.Request, p string) {
	ac := s.acct(w, r)
	if ac == nil {
		return
	}
	if p != "/api/render" { // the tab answers
		id := strings.TrimPrefix(p, "/api/render/")
		var in struct {
			Progress *struct{ Done, Total int } `json:"progress"`
			Result   json.RawMessage            `json:"result"`
		}
		if err := readJSON(r, &in, 32<<20); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		renders.Lock()
		j := renders.m[id]
		if j == nil || j.Account != ac.id {
			renders.Unlock()
			jsonErr(w, 404, "no such render job")
			return
		}
		if in.Progress != nil && j.Result == nil {
			j.Done, j.Total = in.Progress.Done, in.Progress.Total
			select {
			case j.progress <- struct{}{}:
			default:
			}
		}
		if len(in.Result) > 0 && j.Result == nil {
			j.Result = in.Result
			close(j.finished)
			var e struct{ Error string }
			if json.Unmarshal(in.Result, &e) == nil && e.Error != "" {
				delete(renders.m, id) // a failure is answered once and forgotten: the next call draws again
			}
		}
		renders.Unlock()
		writeJSON(w, map[string]bool{"ok": true})
		return
	}
	var in struct {
		Session string   `json:"session_id"`
		Op      string   `json:"op"`
		Designs []string `json:"design_ids"`
		Rev     int      `json:"rev"` // the session's turn count: an edited session is drawn again, a retry is not
		WaitS   int      `json:"wait_s"`
	}
	if err := readJSON(r, &in, 64<<10); err != nil || in.Session == "" || (in.Op != "preview" && in.Op != "save") {
		jsonErr(w, 400, "send {session_id, op: preview|save}")
		return
	}
	key := renderKey(ac.id, in.Session+"#"+fmt.Sprint(in.Rev), in.Op, in.Designs)
	renders.Lock()
	j := renders.m[key]
	if j != nil && j.Result != nil && in.Op == "preview" && time.Since(j.At) > 10*time.Minute {
		delete(renders.m, key) // stale preview: draw again (a save is kept: never posted twice)
		j = nil
	}
	fresh := j == nil
	if fresh {
		j = &renderJob{ID: key, Account: ac.id, Session: in.Session, Op: in.Op, Designs: in.Designs, At: time.Now().UTC(), finished: make(chan struct{}), progress: make(chan struct{}, 1)}
		renders.m[key] = j
	}
	renders.Unlock()
	w.Header().Set("Content-Type", "application/x-ndjson")
	w.Header().Set("Cache-Control", "no-store")
	fl, _ := w.(http.Flusher)
	line := func(v any) {
		b, _ := json.Marshal(v)
		_, _ = w.Write(append(b, '\n'))
		if fl != nil {
			fl.Flush()
		}
	}
	select {
	case <-j.finished:
		line(map[string]any{"step": "done", "job": j.ID, "result": j.Result, "again": true})
		return
	default:
	}
	if s.tabs() == 0 {
		line(map[string]any{"step": "error", "job": j.ID, "error": errOpenApp, "open_app": true})
		return
	}
	if fresh {
		s.publish("render.request", map[string]any{"account": ac.id, "data": j})
	}
	line(map[string]any{"step": "queued", "job": j.ID})
	wait := time.Duration(in.WaitS) * time.Second
	if wait <= 0 || wait > 5*time.Minute {
		wait = 3 * time.Minute
	}
	deadline := time.NewTimer(wait)
	defer deadline.Stop()
	for {
		select {
		case <-j.finished:
			line(map[string]any{"step": "done", "job": j.ID, "result": j.Result})
			return
		case <-j.progress:
			renders.Lock()
			d, t := j.Done, j.Total
			renders.Unlock()
			line(map[string]any{"step": "progress", "job": j.ID, "done": d, "total": t})
		case <-deadline.C:
			msg := "The app has not finished drawing yet; call again with the same request (it keeps going)."
			if s.tabs() == 0 {
				msg = errOpenApp
			}
			line(map[string]any{"step": "error", "job": j.ID, "error": msg, "open_app": s.tabs() == 0})
			return
		case <-r.Context().Done():
			return
		}
	}
}

// ── the Claude desktop connector ──

const mcpName = "kbb-ads-autopilot"

// claudeConfigPath: Claude desktop's config on a Mac (KAA_CLAUDE_CONFIG in tests).
func claudeConfigPath() string {
	if p := os.Getenv("KAA_CLAUDE_CONFIG"); p != "" {
		return p
	}
	home, _ := os.UserHomeDir()
	if runtime.GOOS == "windows" {
		return filepath.Join(os.Getenv("APPDATA"), "Claude", "claude_desktop_config.json")
	}
	return filepath.Join(home, "Library", "Application Support", "Claude", "claude_desktop_config.json")
}

var mcpSeen = struct {
	sync.Mutex
	at   time.Time
	tool string
	n    int
}{}

func (s *server) mcpEntry() map[string]any {
	exe := s.exe
	if exe == "" {
		exe, _ = os.Executable()
	}
	return map[string]any{"command": exe, "args": []any{"-mcp", "-data", s.data}}
}

func (s *server) mcpStatus() map[string]any {
	p := claudeConfigPath()
	installed, same := false, false
	if b, err := os.ReadFile(p); err == nil {
		var cfg map[string]any
		if json.Unmarshal(b, &cfg) == nil {
			if m, ok := cfg["mcpServers"].(map[string]any); ok {
				if e, ok := m[mcpName].(map[string]any); ok {
					installed = true
					same = fmt.Sprint(e["command"]) == fmt.Sprint(s.mcpEntry()["command"])
				}
			}
		}
	}
	mcpSeen.Lock()
	at, tool, n := mcpSeen.at, mcpSeen.tool, mcpSeen.n
	mcpSeen.Unlock()
	connected := !at.IsZero() && time.Since(at) < 15*time.Minute
	out := map[string]any{"config_path": p, "installed": installed, "current": same, "connected": connected, "calls": n, "last_tool": tool,
		"entry":  map[string]any{"mcpServers": map[string]any{mcpName: s.mcpEntry()}},
		"try":    "Use the KBB Ads Autopilot connector: call get_brief, then make 2 Arabic + English ads for the product it says needs them most, and show me the previews.",
		"try_ar": "استخدم موصل KBB Ads Autopilot: نادِ get_brief ثم اصنع إعلانين بالعربية والإنجليزية للمنتج الأكثر حاجة، وأرني المعاينات."}
	if !at.IsZero() {
		out["last_seen"] = at
	}
	return out
}

// mcpInstall: merge our entry into Claude desktop's config; the old file is kept next to it first.
func (s *server) mcpInstall() (map[string]any, error) {
	p := claudeConfigPath()
	cfg := map[string]any{}
	backup := ""
	if b, err := os.ReadFile(p); err == nil && len(strings.TrimSpace(string(b))) > 0 {
		if err := json.Unmarshal(b, &cfg); err != nil {
			return nil, fmt.Errorf("Claude's config file is not valid JSON (%v); it was left untouched — fix it or add the entry by hand", err)
		}
		backup = p + ".bak-" + time.Now().Format("20060102-150405")
		if err := os.WriteFile(backup, b, 0o600); err != nil {
			return nil, err
		}
	} else if err != nil && !os.IsNotExist(err) {
		return nil, err
	}
	servers, _ := cfg["mcpServers"].(map[string]any)
	if servers == nil {
		servers = map[string]any{}
	}
	servers[mcpName] = s.mcpEntry()
	cfg["mcpServers"] = servers
	b, _ := json.MarshalIndent(cfg, "", "  ")
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return nil, err
	}
	if err := store.WriteAtomic(p, append(b, '\n'), 0o600); err != nil {
		return nil, err
	}
	s.app.st.Append("config", "Claude desktop connector installed in "+p, map[string]any{"backup": backup})
	out := s.mcpStatus()
	out["backup"] = backup
	out["restart"] = "Quit Claude completely (⌘Q) and open it again; the connector appears under the tools button."
	return out, nil
}

func (s *server) mcpAPI(w http.ResponseWriter, r *http.Request, p string) {
	switch {
	case p == "/api/mcp" && r.Method == http.MethodGet:
		writeJSON(w, s.mcpStatus())
	case p == "/api/mcp/install" && r.Method == http.MethodPost:
		out, err := s.mcpInstall()
		if err != nil {
			jsonErr(w, 500, err.Error())
			return
		}
		writeJSON(w, out)
	case p == "/api/mcp/seen" && r.Method == http.MethodPost:
		var in struct{ Tool string }
		_ = readJSON(r, &in, 4096)
		mcpSeen.Lock()
		first := mcpSeen.at.IsZero() || time.Since(mcpSeen.at) > 15*time.Minute
		mcpSeen.at, mcpSeen.n = time.Now(), mcpSeen.n+1
		if in.Tool != "" && len(in.Tool) < 40 {
			mcpSeen.tool = in.Tool
		}
		mcpSeen.Unlock()
		if c := s.app.ccRunner(); c != nil && in.Tool != "" { // an auto-run's live line on its card
			c.toolSeen(in.Tool)
		}
		if first {
			s.publish("connections", map[string]any{"account": "", "data": map[string]any{"mcp": true}})
		}
		writeJSON(w, map[string]bool{"ok": true})
	default:
		jsonErr(w, 404, "no such endpoint")
	}
}

// writeEngineFile: where the connector finds the running engine (data folder, 0600; removed on a clean stop).
func (s *server) writeEngineFile() {
	b, _ := json.Marshal(map[string]any{"addr": s.addr, "pid": os.Getpid(), "version": version})
	_ = store.WriteAtomic(filepath.Join(s.data, "engine.json"), b, 0o600)
}
