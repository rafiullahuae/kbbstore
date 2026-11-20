// Package metamock is a fake Graph / Marketing API for every check in this app (both lanes): no check ever touches
// the real Meta. It serves the endpoints the engine uses, at /<version>/…, with realistic shapes:
//
//	GET  act_X                         account (spend_cap / amount_spent in fils as numeric strings)
//	GET  act_X/campaigns|adsets|ads    cursor paging (limit / after)
//	GET  act_X/insights                deterministic daily rows per object (hash of id + date), actions/action_values
//	POST act_X/campaigns|adsets|ads|adcreatives   honours X-Client-Idempotent-Key like Meta (same key + same body →
//	                                   the first object; same key + other body → error 100/1349016)
//	POST act_X/adimages|advideos       image hash / video id
//	POST <id>                          status, daily_budget, bid_amount; act_X: spend_cap (STANDARD units, 0 = remove)
//	GET/POST act_X/adrules_library, POST/DELETE <rule id>
//
// Meta's own limits are enforced: 4 budget changes per ad set per hour (613/1487632), 10 spend-cap changes per day
// (17/1885172). Errors can be injected (rate limit 613 / 80004, token 190, …) and an ad whose copy contains "REJECT"
// is DISAPPROVED with review feedback. Every write is recorded for the checks (Writes).
package metamock

import (
	"crypto/sha256"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

type obj map[string]any

type Inject struct {
	Method    string `json:"method"`  // "" = any
	Path      string `json:"path"`    // substring of the path after the version
	Code      int    `json:"code"`    // Meta error code
	Subcode   int    `json:"subcode"` // error_subcode
	Status    int    `json:"status"`  // HTTP status (default 400)
	Count     int    `json:"count"`   // how many requests it hits (default 1)
	Message   string `json:"message"`
	Transient bool   `json:"is_transient"`
	// AfterWrite: Meta did the write, then the answer was lost (a 5xx / dropped connection after the object exists)
	AfterWrite bool `json:"after_write"`
}

type Write struct {
	At     time.Time         `json:"at"`
	Method string            `json:"method"`
	Path   string            `json:"path"`
	Form   map[string]string `json:"form"`
	Idem   string            `json:"idem,omitempty"`
	Status int               `json:"status"`
}

type Server struct {
	Token   string
	Account string // act_123
	Version string
	Now     func() time.Time

	mu        sync.Mutex
	nextID    int64
	account   obj
	campaigns []obj
	adsets    []obj
	ads       []obj
	creatives map[string]obj
	accounts  map[string]obj // every ad account the token sees (multi-account); account = accounts[Account]
	cur       string         // the ad account named in the request path
	assets    map[string]obj // pages, Instagram accounts, pixels, catalogs, WhatsApp accounts, product sets, videos
	BizID     string
	// user tokens (the unverified-business path): token → expiry. AppID/AppSecret are the owner's own app.
	userTokens map[string]time.Time
	AppID      string
	AppSecret  string
	rules      []obj
	tags       map[string]string // seeded results by object id (WINNER / LOSER), as if in its name
	audiences  []obj
	idem       map[string]idemRec
	budgetTS   map[string][]time.Time
	capTS      []time.Time
	injects    []Inject
	writes     []Write
	calls      int
	srv        *http.Server
	URL        string
}

type idemRec struct {
	body string
	id   string
}

func New(token, account string) *Server {
	s := &Server{Token: token, Account: account, Version: "v27.0", Now: time.Now, nextID: 120000000000,
		creatives: map[string]obj{}, idem: map[string]idemRec{}, budgetTS: map[string][]time.Time{}}
	s.account = obj{"id": account, "name": "K-Beauty Bliss UAE", "currency": "AED", "timezone_name": "Asia/Dubai",
		"spend_cap": "0", "amount_spent": "1234500", "account_status": 1, "disable_reason": 0, "min_daily_budget": 367}
	s.accounts = map[string]obj{account: s.account}
	s.BizID = "900000000001"
	s.assets = map[string]obj{
		"200000000001": {"id": "200000000001", "name": "K-Beauty Bliss", "_kind": "page"},
		"500000000001": {"id": "500000000001", "username": "kbeautybliss", "_kind": "instagram"},
		"300000000001": {"id": "300000000001", "name": "kbeautybliss.com pixel", "_kind": "pixel", "last_fired_time": "2026-11-20T09:00:00+0000"},
		"400000000001": {"id": "400000000001", "name": "KBB catalog", "_kind": "catalog", "product_count": 902},
		"600000000001": {"id": "600000000001", "name": "KBB WhatsApp", "_kind": "whatsapp"},
	}
	return s
}

// Seed adds an owner campaign the app must never touch, with one ad set and ad.
func (s *Server) Seed() {
	s.mu.Lock()
	defer s.mu.Unlock()
	c := s.id()
	s.campaigns = append(s.campaigns, obj{"id": c, "name": "Owner – Always on", "objective": "OUTCOME_SALES", "status": "ACTIVE", "effective_status": "ACTIVE",
		"daily_budget": "5000", "bid_strategy": "LOWEST_COST_WITHOUT_CAP", "created_time": "2026-10-01T10:00:00+0400", "updated_time": "2026-10-01T10:00:00+0400",
		"advantage_state_info": obj{"advantage_state": "ADVANTAGE_PLUS_SALES"}})
	a := s.id()
	s.adsets = append(s.adsets, obj{"id": a, "campaign_id": c, "name": "Owner – UAE", "status": "ACTIVE", "effective_status": "ACTIVE",
		"optimization_goal": "OFFSITE_CONVERSIONS", "targeting": obj{"geo_locations": obj{"countries": []string{"AE"}}}})
	s.ads = append(s.ads, obj{"id": s.id(), "adset_id": a, "campaign_id": c, "name": "Owner – ad 1", "status": "ACTIVE", "effective_status": "ACTIVE", "creative": obj{"id": s.id()}})
}

func (s *Server) id() string { s.nextID++; return strconv.FormatInt(s.nextID, 10) }

// AddAccount: a second (third…) ad account in the same business, for the multi-account checks.
func (s *Server) AddAccount(id, name string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.accounts[id] = obj{"id": id, "name": name, "currency": "AED", "timezone_name": "Asia/Dubai", "spend_cap": "0", "amount_spent": "50000", "account_status": 1, "min_daily_budget": 367}
}

// SeedFor: an owner campaign (one ad set, one ad) in another ad account, so each account shows its own data.
func (s *Server) SeedFor(act, name string, dailyFils int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	c := s.id()
	s.campaigns = append(s.campaigns, obj{"id": c, "_act": act, "name": name, "objective": "OUTCOME_SALES", "status": "ACTIVE", "effective_status": "ACTIVE",
		"daily_budget": strconv.Itoa(dailyFils), "bid_strategy": "LOWEST_COST_WITHOUT_CAP", "created_time": "2026-09-15T10:00:00+0400", "updated_time": "2026-09-15T10:00:00+0400"})
	a := s.id()
	s.adsets = append(s.adsets, obj{"id": a, "_act": act, "campaign_id": c, "name": name + " – set", "status": "ACTIVE", "effective_status": "ACTIVE",
		"optimization_goal": "OFFSITE_CONVERSIONS", "targeting": obj{"geo_locations": obj{"countries": []string{"AE"}}}})
	s.ads = append(s.ads, obj{"id": s.id(), "_act": act, "adset_id": a, "campaign_id": c, "name": name + " – ad", "status": "ACTIVE", "effective_status": "ACTIVE", "creative": obj{"id": s.id()}})
}

// AccountObj: a copy of one account's fields.
func (s *Server) AccountObj(id string) map[string]any {
	s.mu.Lock()
	defer s.mu.Unlock()
	return copyObj(s.accounts[id])
}

func (s *Server) actOf(o obj) string {
	if a, ok := o["_act"].(string); ok {
		return a
	}
	return s.Account
}

func (s *Server) mine(src []obj) []obj {
	var out []obj
	for _, o := range src {
		if s.actOf(o) == s.cur {
			out = append(out, o)
		}
	}
	return out
}

// Start listens on 127.0.0.1:0 and serves until Close.
func (s *Server) Start() (string, error) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return "", err
	}
	s.srv = &http.Server{Handler: s, ReadHeaderTimeout: 10 * time.Second}
	go func() { _ = s.srv.Serve(ln) }()
	s.URL = "http://" + ln.Addr().String()
	return s.URL, nil
}

func (s *Server) Close() {
	if s.srv != nil {
		_ = s.srv.Close()
	}
}

func (s *Server) InjectError(in Inject) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if in.Count <= 0 {
		in.Count = 1
	}
	if in.Status == 0 {
		in.Status = 400
	}
	s.injects = append(s.injects, in)
}

func (s *Server) Writes() []Write {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]Write(nil), s.writes...)
}

func (s *Server) Calls() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.calls
}

// SetAccount changes account fields (tests: amount_spent, spend_cap in fils strings).
func (s *Server) SetAccount(k string, v any) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.account[k] = v
}

func (s *Server) AccountField(k string) any {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.account[k]
}

// Rules returns the stored rules (copies).
func (s *Server) Rules() []map[string]any {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []map[string]any
	for _, r := range s.rules {
		out = append(out, copyObj(r))
	}
	return out
}

// Objects: every campaign / ad set / ad (copies), for the checks.
func (s *Server) Objects(kind string) []map[string]any {
	s.mu.Lock()
	defer s.mu.Unlock()
	src := map[string][]obj{"campaigns": s.campaigns, "adsets": s.adsets, "ads": s.ads}[kind]
	var out []map[string]any
	for _, o := range src {
		out = append(out, copyObj(o))
	}
	return out
}

func copyObj(o obj) map[string]any {
	b, _ := json.Marshal(o)
	var m map[string]any
	_ = json.Unmarshal(b, &m)
	return m
}

func (s *Server) fail(w http.ResponseWriter, status, code, sub int, msg string, transient bool) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	e := obj{"message": msg, "type": "OAuthException", "code": code, "fbtrace_id": "MOCK" + strconv.Itoa(s.calls)}
	if sub != 0 {
		e["error_subcode"] = sub
	}
	if transient {
		e["is_transient"] = true
	}
	_ = json.NewEncoder(w).Encode(obj{"error": e})
}

func (s *Server) ok(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.calls++
	if r.URL.Path == "/__mock/inject" && r.Method == http.MethodPost {
		var in Inject
		_ = json.NewDecoder(r.Body).Decode(&in)
		if in.Count <= 0 {
			in.Count = 1
		}
		if in.Status == 0 {
			in.Status = 400
		}
		s.injects = append(s.injects, in)
		s.ok(w, obj{"ok": true})
		return
	}
	if strings.HasPrefix(r.URL.Path, "/video-ads-upload/") { // the resumable upload host (rupload.facebook.com)
		if r.Header.Get("Authorization") != "OAuth "+s.Token && !strings.HasPrefix(r.Header.Get("Authorization"), "OAuth EAALONG") {
			s.fail(w, 401, 190, 0, "Invalid OAuth access token.", false)
			return
		}
		vid := r.URL.Path[strings.LastIndex(r.URL.Path, "/")+1:]
		v := s.assets[vid]
		body, _ := io.ReadAll(r.Body)
		if v == nil || r.Header.Get("file_size") == "" {
			s.fail(w, 400, 100, 0, "unknown upload", false)
			return
		}
		off, _ := strconv.Atoi(r.Header.Get("offset"))
		v["_bytes"] = off + len(body)
		v["_file_size"] = r.Header.Get("file_size")
		s.ok(w, obj{"success": true})
		return
	}
	if r.URL.Path == "/__mock/writes" {
		s.ok(w, s.writes)
		return
	}
	if r.URL.Path == "/__mock/tag" && r.Method == http.MethodPost { // seeded results: {"id": "<object id>", "tag": "WINNER"|"LOSER"|""}
		var in struct{ ID, Tag string }
		_ = json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&in)
		if in.Tag != "WINNER" && in.Tag != "LOSER" && in.Tag != "" {
			s.fail(w, 400, 100, 0, "tag: WINNER, LOSER or empty", false)
			return
		}
		if s.tags == nil {
			s.tags = map[string]string{}
		}
		s.tags[in.ID] = in.Tag
		s.ok(w, obj{"ok": true})
		return
	}
	prefix := "/" + s.Version + "/"
	if !strings.HasPrefix(r.URL.Path, prefix) {
		s.fail(w, 400, 2635, 0, "Unsupported version: use "+s.Version, false)
		return
	}
	path := strings.Trim(strings.TrimPrefix(r.URL.Path, prefix), "/")
	_ = r.ParseMultipartForm(64 << 20)
	_ = r.ParseForm()
	form := r.Form
	usage := fmt.Sprintf(`{"%s":[{"type":"ads_management","call_count":%d,"total_cputime":3,"total_time":4,"estimated_time_to_regain_access":0,"ads_api_access_tier":"development_access"}]}`,
		strings.TrimPrefix(s.Account, "act_"), s.calls%60)
	w.Header().Set("X-Business-Use-Case-Usage", usage)
	if strings.HasSuffix(path, "/insights") {
		w.Header().Set("X-FB-Ads-Insights-Throttle", `{"app_id_util_pct":2.5,"acc_id_util_pct":1.0,"ads_api_access_tier":"development_access"}`)
	}

	switch path {
	case "oauth/access_token":
		s.exchange(w, form)
		return
	case "debug_token":
		s.debugToken(w, form)
		return
	}
	if tok := form.Get("access_token"); tok != s.Token {
		exp, ok := s.userTokens[tok]
		if !ok || !s.Now().Before(exp) {
			s.fail(w, 400, 190, 463, "Error validating access token: Session has expired.", false)
			return
		}
	}
	for i := range s.injects {
		in := &s.injects[i]
		if in.Count > 0 && (in.Method == "" || in.Method == r.Method) && strings.Contains(path, in.Path) {
			in.Count--
			msg := in.Message
			if msg == "" {
				msg = "Injected error"
			}
			if r.Method != http.MethodGet {
				s.recordWrite(r, path, form, in.Status)
			}
			if in.AfterWrite {
				s.route(httptest.NewRecorder(), r, path, form)
			}
			s.fail(w, in.Status, in.Code, in.Subcode, msg, in.Transient)
			return
		}
	}
	if r.Method != http.MethodGet {
		defer s.recordWrite(r, path, form, 200)
	}
	s.route(w, r, path, form)
}

func (s *Server) route(w http.ResponseWriter, r *http.Request, path string, form url.Values) {
	segs := strings.Split(path, "/")
	if s.accounts[segs[0]] != nil {
		s.cur = segs[0]
	}
	if s.portfolio(w, r, segs, form) {
		return
	}
	switch {
	case r.Method == http.MethodGet && len(segs) == 1 && s.accounts[segs[0]] != nil:
		s.ok(w, pickFields(s.accounts[segs[0]], form.Get("fields")))
	case r.Method == http.MethodGet && len(segs) == 2 && s.accounts[segs[0]] != nil:
		switch segs[1] {
		case "campaigns":
			s.list(w, form, s.mine(s.campaigns))
		case "adsets":
			s.learningTick()
			s.list(w, form, s.mine(s.adsets))
		case "ads":
			s.reviewTick()
			s.list(w, form, s.mine(s.ads))
		case "adrules_library":
			s.list(w, form, s.mine(s.rules))
		case "customaudiences":
			s.list(w, form, s.mine(s.audiences))
		case "insights":
			s.insights(w, form)
		default:
			s.fail(w, 400, 100, 0, "Unknown edge "+segs[1], false)
		}
	case r.Method == http.MethodPost && len(segs) == 2 && s.accounts[segs[0]] != nil:
		s.create(w, r, segs[1], form)
	case r.Method == http.MethodPost && len(segs) == 1:
		s.update(w, segs[0], form)
	case r.Method == http.MethodDelete && len(segs) == 1:
		for i, ru := range s.rules {
			if ru["id"] == segs[0] {
				s.rules = append(s.rules[:i], s.rules[i+1:]...)
				s.ok(w, obj{"success": true})
				return
			}
		}
		s.fail(w, 400, 100, 33, "Object does not exist", false)
	case r.Method == http.MethodGet && len(segs) == 1:
		if o := s.findAny(segs[0]); o != nil {
			s.ok(w, pickFields(o, form.Get("fields")))
			return
		}
		s.fail(w, 400, 100, 33, "Object does not exist", false)
	default:
		s.fail(w, 400, 100, 0, "Unsupported request", false)
	}
}

func (s *Server) recordWrite(r *http.Request, path string, form url.Values, status int) {
	m := map[string]string{}
	for k, v := range form {
		if k == "access_token" || k == "appsecret_proof" {
			continue
		}
		if k == "bytes" || k == "source" {
			m[k] = fmt.Sprintf("<%d bytes>", len(v[0]))
			continue
		}
		m[k] = v[0]
	}
	s.writes = append(s.writes, Write{At: s.Now(), Method: r.Method, Path: path, Form: m, Idem: r.Header.Get("X-Client-Idempotent-Key"), Status: status})
}

func pickFields(o obj, fields string) obj {
	if fields == "" {
		return o
	}
	out := obj{"id": o["id"]}
	for _, f := range strings.Split(fields, ",") {
		f = strings.TrimSpace(f)
		if i := strings.Index(f, "{"); i > 0 {
			f = f[:i]
		}
		if v, ok := o[f]; ok {
			out[f] = v
		}
	}
	return out
}

func (s *Server) list(w http.ResponseWriter, form url.Values, src []obj) {
	limit, _ := strconv.Atoi(form.Get("limit"))
	if limit <= 0 || limit > 500 {
		limit = 25
	}
	start := 0
	if a := form.Get("after"); a != "" {
		start, _ = strconv.Atoi(a)
	}
	var rows []obj
	for _, o := range src {
		if o["status"] == "DELETED" {
			continue
		}
		rows = append(rows, pickFields(o, form.Get("fields")))
	}
	end := start + limit
	if end > len(rows) {
		end = len(rows)
	}
	if start > len(rows) {
		start = len(rows)
	}
	page := rows[start:end]
	if page == nil {
		page = []obj{}
	}
	resp := obj{"data": page}
	if end < len(rows) {
		resp["paging"] = obj{"cursors": obj{"before": strconv.Itoa(start), "after": strconv.Itoa(end)}, "next": "https://graph.facebook.com/next?after=" + strconv.Itoa(end)}
	}
	s.ok(w, resp)
}

func (s *Server) findAny(id string) obj {
	if a, ok := s.assets[id]; ok {
		return a
	}
	for _, l := range [][]obj{s.campaigns, s.adsets, s.ads, s.rules, s.audiences} {
		for _, o := range l {
			if o["id"] == id {
				return o
			}
		}
	}
	if c, ok := s.creatives[id]; ok {
		return c
	}
	return nil
}

func (s *Server) parent(list []obj, id string) obj {
	for _, o := range list {
		if o["id"] == id {
			return o
		}
	}
	return nil
}

func isInt(v string) bool {
	n, err := strconv.ParseInt(v, 10, 64)
	return err == nil && n > 0
}

func (s *Server) create(w http.ResponseWriter, r *http.Request, edge string, form url.Values) {
	key := r.Header.Get("X-Client-Idempotent-Key")
	body := canonForm(form)
	if key != "" {
		if rec, ok := s.idem[s.cur+"|"+key]; ok {
			if rec.body != body {
				s.fail(w, 400, 100, 1349016, "Invalid parameter", false)
				return
			}
			s.ok(w, obj{"id": rec.id})
			return
		}
	}
	now := s.Now().Format("2006-01-02T15:04:05-0700")
	var id string
	switch edge {
	case "campaigns":
		if form.Get("objective") == "" || form.Get("special_ad_categories") == "" || form.Get("name") == "" {
			s.fail(w, 400, 100, 0, "Invalid parameter: objective, name and special_ad_categories are required", false)
			return
		}
		if st := form.Get("status"); st != "ACTIVE" && st != "PAUSED" {
			s.fail(w, 400, 100, 0, "Only ACTIVE and PAUSED are valid during creation", false)
			return
		}
		if b := form.Get("daily_budget"); b != "" && !isInt(b) {
			s.fail(w, 400, 100, 0, "daily_budget must be an integer in the account currency's minor unit", false)
			return
		}
		id = s.id()
		s.campaigns = append(s.campaigns, obj{"id": id, "name": form.Get("name"), "objective": form.Get("objective"), "status": form.Get("status"),
			"effective_status": form.Get("status"), "daily_budget": form.Get("daily_budget"), "bid_strategy": form.Get("bid_strategy"), "created_time": now, "updated_time": now,
			"advantage_state_info": obj{"advantage_state": "DISABLED"}})
	case "adsets":
		c := s.parent(s.campaigns, form.Get("campaign_id"))
		if c == nil {
			s.fail(w, 400, 100, 0, "Invalid campaign_id", false)
			return
		}
		var tg struct {
			Geo struct {
				Countries []string `json:"countries"`
			} `json:"geo_locations"`
		}
		if json.Unmarshal([]byte(form.Get("targeting")), &tg) != nil || len(tg.Geo.Countries) == 0 {
			s.fail(w, 400, 100, 1487079, "targeting: countries is required", false)
			return
		}
		if form.Get("optimization_goal") == "OFFSITE_CONVERSIONS" && !strings.Contains(form.Get("promoted_object"), "pixel_id") && !strings.Contains(form.Get("promoted_object"), "product_set_id") { // catalog ad sets promote a product set (META-FACTS §14)
			s.fail(w, 400, 100, 1815437, "promoted_object with pixel_id is required", false)
			return
		}
		if form.Get("daily_budget") != "" && c["daily_budget"] != "" && c["daily_budget"] != nil {
			s.fail(w, 400, 100, 1885621, "You can either set budget at the campaign level or at the adset level, not both", false)
			return
		}
		id = s.id()
		var tgt any
		_ = json.Unmarshal([]byte(form.Get("targeting")), &tgt)
		s.adsets = append(s.adsets, obj{"id": id, "campaign_id": c["id"], "name": form.Get("name"), "status": form.Get("status"), "effective_status": form.Get("status"),
			"daily_budget": form.Get("daily_budget"), "optimization_goal": form.Get("optimization_goal"), "bid_amount": form.Get("bid_amount"), "targeting": tgt,
			"learning_stage_info": obj{"status": "LEARNING"}})
		if strings.Contains(form.Get("targeting"), `"advantage_audience":1`) {
			c["advantage_state_info"] = obj{"advantage_state": "ADVANTAGE_PLUS_SALES"}
		}
	case "adcreatives":
		if !strings.Contains(form.Get("object_story_spec"), "page_id") {
			s.fail(w, 400, 100, 0, "object_story_spec.page_id is required", false)
			return
		}
		id = s.id()
		s.creatives[id] = obj{"id": id, "name": form.Get("name"), "object_story_spec": form.Get("object_story_spec")}
	case "ads":
		as := s.parent(s.adsets, form.Get("adset_id"))
		if as == nil {
			s.fail(w, 400, 100, 0, "Invalid adset_id", false)
			return
		}
		var cr struct {
			ID string `json:"creative_id"`
		}
		_ = json.Unmarshal([]byte(form.Get("creative")), &cr)
		cobj, ok := s.creatives[cr.ID]
		if !ok {
			s.fail(w, 400, 100, 1772103, "Invalid creative id", false)
			return
		}
		id = s.id()
		ad := obj{"id": id, "adset_id": as["id"], "campaign_id": as["campaign_id"], "name": form.Get("name"), "status": form.Get("status"),
			"effective_status": "PENDING_REVIEW", "creative": s.creativeView(cr.ID, cobj), "_reviewAt": s.Now().Add(time.Second).Unix()}
		if strings.Contains(fmt.Sprint(cobj["object_story_spec"]), "REJECT") {
			ad["_reject"] = true
		}
		s.ads = append(s.ads, ad)
	case "adimages":
		if form.Get("bytes") == "" {
			s.fail(w, 400, 100, 0, "bytes is required", false)
			return
		}
		h := sha256.Sum256([]byte(form.Get("bytes")))
		hash := fmt.Sprintf("%x", h[:16])
		s.ok(w, obj{"images": obj{form.Get("name"): obj{"hash": hash, "url": "https://scontent.example/" + hash}}})
		return
	case "advideos":
		if form.Get("title") == "" {
			s.fail(w, 400, 100, 0, "source is required", false)
			return
		}
		id = s.id()
	case "customaudiences":
		if form.Get("name") == "" {
			s.fail(w, 400, 100, 0, "name is required", false)
			return
		}
		if form.Get("subtype") == "LOOKALIKE" {
			if strings.Contains(form.Get("lookalike_spec"), "country") || strings.Contains(form.Get("lookalike_spec"), "location_spec") {
				s.fail(w, 400, 100, 0, "lookalike_spec.country is no longer accepted in v27.0", false)
				return
			}
			if s.findAny(form.Get("origin_audience_id")) == nil {
				s.fail(w, 400, 100, 0, "origin_audience_id: no such audience", false)
				return
			}
		} else if !json.Valid([]byte(form.Get("rule"))) || !strings.Contains(form.Get("rule"), "event_sources") {
			s.fail(w, 400, 100, 0, "rule is required", false)
			return
		}
		id = s.id()
		// size estimates the way Meta reports them (approximate_count_lower/upper_bound, delivery_status code 200):
		// buyers ~1,500 (enough to seed a lookalike), site visitors ~12,000, lookalikes ~2M; "TINY" in the name = 40
		lo := int64(12000)
		switch {
		case strings.Contains(form.Get("name"), "TINY"):
			lo = 40
		case form.Get("subtype") == "LOOKALIKE":
			lo = 2000000
		case strings.Contains(form.Get("rule"), `"Purchase"`):
			lo = 1500
		}
		s.audiences = append(s.audiences, obj{"id": id, "name": form.Get("name"), "subtype": form.Get("subtype"), "rule": form.Get("rule"),
			"approximate_count_lower_bound": lo, "approximate_count_upper_bound": lo + lo/10, "delivery_status": obj{"code": 200, "description": "This audience is ready for use."}})
	case "video_ads":
		switch form.Get("upload_phase") {
		case "start":
			vid := s.id()
			s.assets[vid] = obj{"id": vid, "_kind": "video", "status": obj{"video_status": "processing", "uploading_phase": obj{"status": "not_started", "bytes_transferred": 0}}}
			s.ok(w, obj{"video_id": vid, "upload_url": "https://rupload.facebook.com/video-ads-upload/" + s.Version + "/" + vid})
		case "finish":
			v := s.assets[form.Get("video_id")]
			if v == nil || v["_bytes"] == nil {
				s.fail(w, 400, 100, 0, "video_id: nothing uploaded", false)
				return
			}
			v["status"] = obj{"video_status": "ready", "uploading_phase": obj{"status": "complete", "bytes_transferred": v["_bytes"]}}
			s.ok(w, obj{"success": true})
		default:
			s.fail(w, 400, 100, 0, "upload_phase must be start or finish", false)
		}
		return
	case "adrules_library":
		if form.Get("name") == "" || form.Get("evaluation_spec") == "" || form.Get("execution_spec") == "" {
			s.fail(w, 400, 100, 0, "name, evaluation_spec and execution_spec are required", false)
			return
		}
		id = s.id()
		rule := obj{"id": id, "name": form.Get("name"), "status": "ENABLED"}
		for _, k := range []string{"evaluation_spec", "execution_spec", "schedule_spec"} {
			if v := form.Get(k); v != "" {
				var x any
				if json.Unmarshal([]byte(v), &x) != nil {
					s.fail(w, 400, 100, 0, k+" is not JSON", false)
					return
				}
				rule[k] = x
			}
		}
		s.rules = append(s.rules, rule)
	default:
		s.fail(w, 400, 100, 0, "Unknown edge "+edge, false)
		return
	}
	if o := s.findAny(id); o != nil {
		o["_act"] = s.cur
	}
	if key != "" {
		s.idem[s.cur+"|"+key] = idemRec{body: body, id: id}
	}
	s.ok(w, obj{"id": id})
}

func canonForm(f url.Values) string {
	keys := make([]string, 0, len(f))
	for k := range f {
		if k == "access_token" || k == "appsecret_proof" {
			continue
		}
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var b strings.Builder
	for _, k := range keys {
		b.WriteString(k + "=" + strings.Join(f[k], ",") + "&")
	}
	return b.String()
}

func (s *Server) update(w http.ResponseWriter, id string, form url.Values) {
	now := s.Now()
	if acct := s.accounts[id]; acct != nil {
		if v := form.Get("spend_cap"); v != "" || form.Get("spend_cap_action") != "" {
			keep := s.capTS[:0]
			for _, t := range s.capTS {
				if now.Sub(t) < 24*time.Hour {
					keep = append(keep, t)
				}
			}
			s.capTS = keep
			if len(s.capTS) >= 10 {
				s.fail(w, 400, 17, 1885172, "You can only change your account spending limit 10 times per day. Please wait to make more changes.", false)
				return
			}
			s.capTS = append(s.capTS, now)
			if form.Get("spend_cap_action") == "delete" {
				acct["spend_cap"] = "0"
			} else if form.Get("spend_cap_action") == "reset" {
				acct["amount_spent"] = "0"
			}
			if v != "" {
				f, err := strconv.ParseFloat(v, 64)
				if err != nil || f < 0 {
					s.fail(w, 400, 100, 0, "spend_cap must be a number", false)
					return
				}
				// standard denomination in, minor units stored (as Meta reads it back); 0 = NO cap
				acct["spend_cap"] = strconv.FormatInt(int64(f*100+0.5), 10)
			}
			s.ok(w, obj{"success": true})
			return
		}
		s.fail(w, 400, 100, 0, "Unsupported account update", false)
		return
	}
	for _, ru := range s.rules {
		if ru["id"] == id {
			for _, k := range []string{"evaluation_spec", "execution_spec", "schedule_spec"} {
				if v := form.Get(k); v != "" {
					var x any
					_ = json.Unmarshal([]byte(v), &x)
					ru[k] = x
				}
			}
			if v := form.Get("name"); v != "" {
				ru["name"] = v
			}
			if v := form.Get("status"); v != "" {
				ru["status"] = v
			}
			s.ok(w, obj{"success": true})
			return
		}
	}
	o := s.findAny(id)
	if o == nil {
		s.fail(w, 400, 100, 33, "Unsupported post request. Object with ID '"+id+"' does not exist", false)
		return
	}
	if v := form.Get("daily_budget"); v != "" {
		if !isInt(v) {
			s.fail(w, 400, 100, 0, "daily_budget must be an integer in minor units", false)
			return
		}
		keep := s.budgetTS[id][:0]
		for _, t := range s.budgetTS[id] {
			if now.Sub(t) < time.Hour {
				keep = append(keep, t)
			}
		}
		if len(keep) >= 4 {
			s.budgetTS[id] = keep
			s.fail(w, 400, 613, 1487632, "You can only change your ad set budget 4 times per hour. Please wait to make more changes.", false)
			return
		}
		s.budgetTS[id] = append(keep, now)
		o["daily_budget"] = v
	}
	if v := form.Get("bid_amount"); v != "" {
		o["bid_amount"] = v
	}
	if v := form.Get("status"); v != "" {
		o["status"] = v
		if o["effective_status"] != "DISAPPROVED" && o["effective_status"] != "PENDING_REVIEW" {
			o["effective_status"] = v
		}
		// children follow a paused parent, as on Meta
		for _, set := range s.adsets {
			if set["campaign_id"] == id && v == "PAUSED" && set["status"] == "ACTIVE" {
				set["effective_status"] = "CAMPAIGN_PAUSED"
			} else if set["campaign_id"] == id && v == "ACTIVE" && set["status"] == "ACTIVE" {
				set["effective_status"] = "ACTIVE"
			}
		}
	}
	o["updated_time"] = now.Format("2006-01-02T15:04:05-0700")
	s.ok(w, obj{"success": true})
}

// reviewTick: ads in review are decided after a second: approved (ACTIVE or PAUSED per their status) or, when the copy
// says REJECT, DISAPPROVED with feedback.
func (s *Server) reviewTick() {
	now := s.Now().Unix()
	for _, a := range s.ads {
		at, ok := a["_reviewAt"].(int64)
		if !ok || a["effective_status"] != "PENDING_REVIEW" || now < at {
			continue
		}
		if a["_reject"] == true {
			a["effective_status"] = "DISAPPROVED"
			a["ad_review_feedback"] = obj{"global": obj{"Personal Health": "Ads must not imply or assert negative self-perception to promote health or beauty products."}}
		} else {
			a["effective_status"] = a["status"]
		}
	}
}

// learningTick: an ad set leaves Meta's learning phase a week after its campaign was made (the mock has no
// conversion counting; a week is Meta's own window).
func (s *Server) learningTick() {
	for _, a := range s.adsets {
		li, _ := a["learning_stage_info"].(obj)
		if li == nil || li["status"] != "LEARNING" {
			continue
		}
		if c := s.parent(s.campaigns, fmt.Sprint(a["campaign_id"])); c != nil {
			if t, err := time.Parse("2006-01-02T15:04:05-0700", fmt.Sprint(c["created_time"])); err == nil && s.Now().Sub(t) >= 7*24*time.Hour {
				a["learning_stage_info"] = obj{"status": "SUCCESS"}
			}
		}
	}
}

// insights: deterministic numbers per object per day. Spend ~ its budget share; an object whose name contains
// "LOSER" spends and never buys; "WINNER" buys at a low CPA.
func (s *Server) insights(w http.ResponseWriter, form url.Values) {
	level := form.Get("level")
	var tr struct{ Since, Until string }
	_ = json.Unmarshal([]byte(form.Get("time_range")), &tr)
	since, err1 := time.Parse("2006-01-02", tr.Since)
	until, err2 := time.Parse("2006-01-02", tr.Until)
	if err1 != nil || err2 != nil || until.Before(since) {
		s.fail(w, 400, 100, 0, "time_range is invalid", false)
		return
	}
	var src []obj
	switch level {
	case "campaign":
		src = s.mine(s.campaigns)
	case "adset":
		src = s.mine(s.adsets)
	case "ad":
		src = s.mine(s.ads)
	default:
		s.fail(w, 400, 100, 0, "level is invalid", false)
		return
	}
	var rows []obj
	for d := since; !d.After(until); d = d.AddDate(0, 0, 1) {
		day := d.Format("2006-01-02")
		for _, o := range src {
			created, _ := o["created_time"].(string)
			if created == "" { // ad sets and ads: no results before their campaign existed
				if c := s.parent(s.campaigns, fmt.Sprint(o["campaign_id"])); c != nil {
					created, _ = c["created_time"].(string)
				}
			}
			if len(created) >= 10 && created[:10] > day {
				continue
			}
			id := fmt.Sprint(o["id"])
			h := sha256.Sum256([]byte(id + day))
			r := binary.BigEndian.Uint64(h[:8])
			name := s.nameChain(o)
			spend := 20 + float64(r%8000)/100 // 20–100 AED
			impr := int64(spend * 45)
			clicks := impr / 90
			purchases := float64(r % 4)
			if strings.Contains(name, "WINNER") {
				purchases = 3 + float64(r%3)
			}
			if strings.Contains(name, "LOSER") { // the more specific verdict: a losing ad inside a winning campaign loses
				purchases = 0
				spend += 60
			}
			value := purchases * 140
			row := obj{"date_start": day, "date_stop": day, "spend": fmt.Sprintf("%.2f", spend), "impressions": strconv.FormatInt(impr, 10),
				"reach": strconv.FormatInt(impr*7/10, 10), "frequency": "1.42", "inline_link_clicks": strconv.FormatInt(clicks, 10)}
			if purchases > 0 {
				row["actions"] = []obj{{"action_type": "omni_purchase", "value": fmt.Sprint(purchases)}, {"action_type": "purchase", "value": fmt.Sprint(purchases)},
					{"action_type": "offsite_conversion.fb_pixel_purchase", "value": fmt.Sprint(purchases)}, {"action_type": "add_to_cart", "value": fmt.Sprint(purchases * 3)}}
				row["action_values"] = []obj{{"action_type": "omni_purchase", "value": fmt.Sprintf("%.2f", value)}, {"action_type": "purchase", "value": fmt.Sprintf("%.2f", value)}}
			}
			switch level {
			case "campaign":
				row["campaign_id"], row["campaign_name"] = id, o["name"]
			case "adset":
				row["adset_id"], row["adset_name"], row["campaign_id"] = id, o["name"], o["campaign_id"]
			case "ad":
				row["ad_id"], row["ad_name"], row["adset_id"], row["campaign_id"] = id, o["name"], o["adset_id"], o["campaign_id"]
			}
			rows = append(rows, row)
		}
	}
	s.list(w, url.Values{"limit": {form.Get("limit")}, "after": {form.Get("after")}}, rows)
}

func (s *Server) nameChain(o obj) string {
	n := fmt.Sprint(o["name"]) + " " + s.tags[fmt.Sprint(o["id"])]
	if a := s.parent(s.adsets, fmt.Sprint(o["adset_id"])); a != nil {
		n += " " + fmt.Sprint(a["name"]) + " " + s.tags[fmt.Sprint(a["id"])]
	}
	if c := s.parent(s.campaigns, fmt.Sprint(o["campaign_id"])); c != nil {
		n += " " + s.tags[fmt.Sprint(c["id"])]
		n += " " + fmt.Sprint(c["name"])
	}
	return n
}

// AddUserToken: a short-lived user token as Graph API Explorer hands it out (valid for `life`).
func (s *Server) AddUserToken(tok string, life time.Duration) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.userTokens == nil {
		s.userTokens = map[string]time.Time{}
	}
	s.userTokens[tok] = s.Now().Add(life)
}

// ExpireAll: every user token lapses (the 60 days ran out).
func (s *Server) ExpireAll() {
	s.mu.Lock()
	defer s.mu.Unlock()
	for k := range s.userTokens {
		s.userTokens[k] = s.Now().Add(-time.Second)
	}
}

func (s *Server) exchange(w http.ResponseWriter, form url.Values) {
	if form.Get("grant_type") != "fb_exchange_token" || form.Get("client_id") != s.AppID || form.Get("client_secret") != s.AppSecret || s.AppID == "" {
		s.fail(w, 400, 101, 0, "Error validating application. Invalid application ID or secret.", false)
		return
	}
	exp, ok := s.userTokens[form.Get("fb_exchange_token")]
	if !ok || !s.Now().Before(exp) {
		s.fail(w, 400, 190, 463, "Error validating access token: Session has expired.", false)
		return
	}
	long := "EAALONG" + s.id() + "0000000000000000"
	if s.userTokens == nil {
		s.userTokens = map[string]time.Time{}
	}
	s.userTokens[long] = s.Now().Add(60 * 24 * time.Hour)
	s.ok(w, obj{"access_token": long, "token_type": "bearer", "expires_in": 5183944})
}

func (s *Server) debugToken(w http.ResponseWriter, form url.Values) {
	as, in := form.Get("access_token"), form.Get("input_token")
	_, asUser := s.userTokens[as]
	if as != s.AppID+"|"+s.AppSecret && as != in && as != s.Token && !asUser {
		s.fail(w, 400, 190, 0, "Invalid OAuth access token.", false)
		return
	}
	d := obj{"app_id": s.AppID, "scopes": []string{"ads_management", "ads_read", "business_management"}}
	if in == s.Token {
		d["type"], d["is_valid"], d["expires_at"] = "SYSTEM_USER", true, 0
	} else if exp, ok := s.userTokens[in]; ok {
		d["type"], d["is_valid"], d["expires_at"], d["user_id"] = "USER", s.Now().Before(exp), exp.Unix(), "777"
	} else {
		d["is_valid"] = false
	}
	s.ok(w, obj{"data": d})
}

// portfolio: me/businesses, the business edges, me/adaccounts, and product_sets on the catalog.
func (s *Server) portfolio(w http.ResponseWriter, r *http.Request, segs []string, form url.Values) bool {
	list := func(rows []obj) {
		s.list(w, form, rows)
	}
	kind := func(k string) []obj {
		var o []obj
		for _, a := range s.assets {
			if a["_kind"] == k {
				o = append(o, a)
			}
		}
		return o
	}
	accs := func() []obj {
		var o []obj
		ids := make([]string, 0, len(s.accounts))
		for id := range s.accounts {
			ids = append(ids, id)
		}
		sort.Strings(ids)
		for _, id := range ids {
			o = append(o, s.accounts[id])
		}
		return o
	}
	if r.Method == http.MethodGet && len(segs) == 2 && segs[0] == "me" {
		switch segs[1] {
		case "businesses":
			list([]obj{{"id": s.BizID, "name": "K-Beauty Bliss"}})
		case "adaccounts":
			list(accs())
		default:
			return false
		}
		return true
	}
	if r.Method == http.MethodGet && len(segs) == 2 && segs[0] == s.BizID {
		switch segs[1] {
		case "owned_ad_accounts":
			list(accs())
		case "client_ad_accounts":
			list([]obj{})
		case "owned_pages":
			list(kind("page"))
		case "owned_instagram_accounts":
			list(kind("instagram"))
		case "owned_pixels":
			list(kind("pixel"))
		case "owned_product_catalogs":
			list(kind("catalog"))
		case "owned_whatsapp_business_accounts":
			list(kind("whatsapp"))
		default:
			return false
		}
		return true
	}
	if r.Method == http.MethodPost && len(segs) == 2 && segs[1] == "product_sets" {
		if c := s.assets[segs[0]]; c == nil || c["_kind"] != "catalog" {
			s.fail(w, 400, 100, 33, "no such catalog", false)
			return true
		}
		if f := form.Get("filter"); f != "" && !json.Valid([]byte(f)) {
			s.fail(w, 400, 100, 0, "filter is not JSON", false)
			return true
		}
		id := s.id()
		s.assets[id] = obj{"id": id, "name": form.Get("name"), "_kind": "product_set", "filter": form.Get("filter")}
		s.ok(w, obj{"id": id})
		return true
	}
	return false
}
