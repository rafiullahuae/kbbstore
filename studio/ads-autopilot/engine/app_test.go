package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"kaa/metamock"
	"kaa/model"
	"kaa/store"
)

const tinyPNG = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC"

func testApp(t *testing.T) (*App, *metamock.Server) {
	t.Helper()
	t.Setenv("KAA_SECRETS_FILE", "1")
	st, err := store.Open(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = st.Close() })
	a := newApp(st, time.Now, nil)
	m, err := setupMock(a)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(m.Close)
	a.wire()
	ctx := context.Background()
	if c := a.connectAll(ctx); !c.Meta.OK || !c.Claude.OK || !c.Shop.OK {
		t.Fatalf("connections %+v", c)
	}
	l := a.limits
	l.DailyAED, l.MonthlyAED, l.TargetCPAAED, l.Autopilot, l.Countries = 300, 6000, 60, model.AutopilotAuto, []string{"AE"}
	a.limits = l
	if _, err := a.creatives.Add(model.Creative{ID: "cr-ar-1", Lang: "ar", Format: "4:5", PNG: tinyPNG, Headline: "بشرة", PrimaryText: "روتين", Link: "https://kbeautybliss.com/p/", Source: "owner"}, time.Now()); err != nil {
		t.Fatal(err)
	}
	return a, m
}

func objStatus(m *metamock.Server, kind, prefix string) map[string]string {
	out := map[string]string{}
	for _, o := range m.Objects(kind) {
		if n, _ := o["name"].(string); strings.HasPrefix(n, prefix) {
			out[o["id"].(string)] = o["status"].(string)
		}
	}
	return out
}

// launch → kill: the app's campaign is paused, the owner's is not touched, autopilot goes off, and the next cycle
// writes nothing. MUTATION: drop the "!c.Managed" skip in Kill → the owner's campaign is paused and this goes red.
func TestCycleThenKill(t *testing.T) {
	a, m := testApp(t)
	out, err := a.Cycle(context.Background(), model.AutopilotAuto)
	if err != nil || out["counts"].(map[string]int)["done"] != 4 {
		t.Fatalf("cycle %v %v", out, err)
	}
	ours := objStatus(m, "campaigns", "KAA:")
	if len(ours) != 1 {
		t.Fatalf("ours %v", ours)
	}
	for _, st := range ours {
		if st != "ACTIVE" {
			t.Fatalf("launched campaign is %s", st)
		}
	}
	if len(m.Rules()) != 4 {
		t.Fatalf("%d Meta rules after a launch, want 4", len(m.Rules()))
	}
	k := a.Kill(context.Background())
	if len(k["paused"].([]string)) != 1 {
		t.Fatalf("kill %v", k)
	}
	for _, st := range objStatus(m, "campaigns", "KAA:") {
		if st != "PAUSED" {
			t.Fatal("kill left the app's campaign running")
		}
	}
	for _, st := range objStatus(m, "campaigns", "Owner") {
		if st != "ACTIVE" {
			t.Fatal("kill touched the owner's own campaign")
		}
	}
	if a.limits.Autopilot != model.AutopilotOff {
		t.Fatal("autopilot still on")
	}
	n := len(m.Writes())
	out2, _ := a.Cycle(context.Background(), model.AutopilotAuto)
	if c := out2["counts"].(map[string]int); c["done"] != 0 {
		t.Fatalf("a cycle after kill did %v", c)
	}
	for _, w := range m.Writes()[n:] {
		if w.Path != "act_1000000001/adrules_library" && !strings.HasPrefix(w.Path, "act_") {
			t.Fatalf("after kill: write %s %v", w.Path, w.Form)
		}
	}
	if len(m.Rules()) != 4 {
		t.Fatal("the Meta-side rules must stay after a kill")
	}
}

// A shadow cycle with autopilot "auto" still writes no object to Meta.
func TestShadowCycle(t *testing.T) {
	a, m := testApp(t)
	out, err := a.Cycle(context.Background(), model.AutopilotShadow)
	if err != nil {
		t.Fatal(err)
	}
	if c := out["counts"].(map[string]int); c["done"] != 0 || c["shadow"] == 0 {
		t.Fatalf("counts %v", c)
	}
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "/campaigns") || strings.HasSuffix(w.Path, "/ads") || w.Form["status"] != "" && !strings.Contains(w.Path, "adrules") {
			t.Fatalf("shadow wrote %s", w.Path)
		}
	}
}

// Never two cycles at once.
func TestOneCycleAtATime(t *testing.T) {
	a, _ := testApp(t)
	a.work.Lock()
	_, err := a.Cycle(context.Background(), model.AutopilotAuto)
	a.work.Unlock()
	if err != errBusy {
		t.Fatalf("got %v, want busy", err)
	}
}

// /api/state answers from memory: < 20 ms even with 30 days × 3 levels of rows.
func TestStateIsFast(t *testing.T) {
	a, _ := testApp(t)
	if _, err := a.Cycle(context.Background(), model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	a.stateBytes()
	t0 := time.Now()
	for i := 0; i < 100; i++ {
		_ = a.stateBytes()
	}
	if per := time.Since(t0) / 100; per > 20*time.Millisecond {
		t.Fatalf("state %v per call", per)
	}
	a.mu.Lock()
	a.stateJSON = nil // rebuilt from scratch
	a.mu.Unlock()
	t1 := time.Now()
	b := a.stateBytes()
	if d := time.Since(t1); d > 20*time.Millisecond {
		t.Fatalf("cold state build %v", d)
	}
	var st map[string]any
	if json.Unmarshal(b, &st) != nil || st["kpis"] == nil || st["campaigns"] == nil {
		t.Fatal("state shape")
	}
	if strings.Contains(string(b), "EAAMOCK") || strings.Contains(string(b), "sk-ant-mock") {
		t.Fatal("a key reached /api/state")
	}
}

// The unverified-business path: a short-lived user token from Graph API Explorer is exchanged for a 60-day one, its
// expiry is shown, a warning comes 7 days ahead, and a lapsed token stops the app writing (Meta's rules stay).
func TestUserTokenPath(t *testing.T) {
	a, m := testApp(t)
	m.AddUserToken("EAASHORTLIVED000000000000001", time.Hour)
	s := &server{app: a, token: "t", addr: "127.0.0.1:1"}
	body := `{"auth":"user_token","app_id":"1234567890123","app_secret":"mockappsecret0000","token":"EAASHORTLIVED000000000000001"}`
	r := httptest.NewRequest("PUT", "/api/connections/meta", strings.NewReader(body))
	w := httptest.NewRecorder()
	s.putConnection(w, r, "meta")
	var c connMeta
	_ = json.Unmarshal(w.Body.Bytes(), &c)
	if !c.OK || c.Auth != AuthUserToken || c.TokenType != "USER" || c.ExpiresAt == nil || c.DaysLeft < 58 || c.DaysLeft > 60 {
		t.Fatalf("%d %s", w.Code, w.Body)
	}
	if tok, _ := a.sec.Get("meta_token"); !strings.HasPrefix(tok, "EAALONG") {
		t.Fatal("the long-lived token was not kept")
	}
	if strings.Contains(w.Body.String(), "EAALONG") || strings.Contains(w.Body.String(), "mockappsecret") {
		t.Fatal("a secret went back to the browser")
	}
	if days, warn := a.tokenStatus(time.Now().Add(54 * 24 * time.Hour)); warn == "" || days > 6 {
		t.Fatalf("no 7-day warning: %d %q", days, warn)
	}
	_, _ = a.ensureSafety(context.Background())
	rules := len(m.Rules())
	m.ExpireAll()
	c2 := a.testMeta(context.Background())
	if c2.OK || !strings.Contains(c2.Error, "token") {
		t.Fatalf("an expired token still connects: %+v", c2)
	}
	if len(m.Rules()) != rules || rules == 0 {
		t.Fatal("the Meta-side rules must outlive the token")
	}
	// a bad app secret: the exchange is refused with a plain message, nothing saved
	m.AddUserToken("EAASHORT2000000000000000002", time.Hour)
	w2 := httptest.NewRecorder()
	s.putConnection(w2, httptest.NewRequest("PUT", "/x", strings.NewReader(`{"auth":"user_token","app_secret":"wrongsecret00","token":"EAASHORT2000000000000000002"}`)), "meta")
	if w2.Code != 400 || !strings.Contains(w2.Body.String(), "60-day") {
		t.Fatalf("%d %s", w2.Code, w2.Body)
	}
}

// The HTTP guards: token, Host (DNS rebinding), Origin.
func TestServerGuards(t *testing.T) {
	a, _ := testApp(t)
	s := &server{app: a, token: "tok123", addr: "127.0.0.1:41927", subs: map[chan []byte]bool{}}
	do := func(method, path, host, origin, tok string) int {
		r := httptest.NewRequest(method, path, nil)
		r.Host = host
		if origin != "" {
			r.Header.Set("Origin", origin)
		}
		if tok != "" {
			r.Header.Set("X-KAA", tok)
		}
		w := httptest.NewRecorder()
		s.ServeHTTP(w, r)
		return w.Code
	}
	cases := []struct {
		name                      string
		method, path, host, o, tk string
		want                      int
	}{
		{"info open to the page", "GET", "/api/info", "127.0.0.1:41927", "", "", 200},
		{"state needs the token", "GET", "/api/state", "127.0.0.1:41927", "", "", 401},
		{"state with token", "GET", "/api/state", "127.0.0.1:41927", "", "tok123", 200},
		{"wrong token", "GET", "/api/state", "127.0.0.1:41927", "", "nope", 401},
		{"DNS rebinding host", "GET", "/api/info", "evil.example:41927", "", "", 403},
		{"cross-origin info", "GET", "/api/info", "127.0.0.1:41927", "https://evil.example", "", 403},
		{"cross-origin kill", "POST", "/api/kill", "127.0.0.1:41927", "https://evil.example", "tok123", 403},
		{"static traversal", "GET", "/app/../engine/main.go", "127.0.0.1:41927", "", "", 404},
	}
	for _, c := range cases {
		if got := do(c.method, c.path, c.host, c.o, c.tk); got != c.want {
			t.Errorf("%s: %d, want %d", c.name, got, c.want)
		}
	}
}

func TestSchedulerDue(t *testing.T) {
	now := time.Date(2026, 11, 20, 12, 0, 0, 0, time.UTC)
	h := time.Hour
	cases := []struct {
		name              string
		lastSync, lastCyc time.Time
		sync, cycle       bool
	}{
		{"first open: a cycle (it syncs first)", time.Time{}, time.Time{}, false, true},
		{"plan 7 h ago: a cycle", now.Add(-10 * time.Minute), now.Add(-7 * h), false, true},
		{"sync 61 min ago, plan 2 h ago: a sync", now.Add(-61 * time.Minute), now.Add(-2 * h), true, false},
		{"all fresh: nothing", now.Add(-5 * time.Minute), now.Add(-1 * h), false, false},
		{"woke after a night: a cycle", now.Add(-9 * h), now.Add(-9 * h), false, true},
	}
	for _, c := range cases {
		s, cy := due(now, c.lastSync, c.lastCyc, time.Hour, 6*h)
		if s != c.sync || cy != c.cycle {
			t.Errorf("%s: sync=%v cycle=%v", c.name, s, cy)
		}
	}
}

// Creatives: only the shop's own links, real PNG bytes, no echo of the media.
func TestCreativeValidation(t *testing.T) {
	a, _ := testApp(t)
	bad := []model.Creative{
		{ID: "x-1", Lang: "fr", Format: "1:1", PNG: tinyPNG, Headline: "h", PrimaryText: "p", Link: "https://kbeautybliss.com/", Source: "owner"},
		{ID: "x-2", Lang: "ar", Format: "1:1", PNG: tinyPNG, Headline: "h", PrimaryText: "p", Link: "https://evil.example/", Source: "owner"},
		{ID: "x-3", Lang: "ar", Format: "1:1", PNG: base64.StdEncoding.EncodeToString([]byte("GIF89a....")), Headline: "h", PrimaryText: "p", Link: "https://kbeautybliss.com/", Source: "owner"},
		{ID: "../x", Lang: "ar", Format: "1:1", Headline: "h", PrimaryText: "p", Link: "https://kbeautybliss.com/", Source: "owner"},
	}
	for _, c := range bad {
		if _, err := a.creatives.Add(c, time.Now()); err == nil {
			t.Errorf("accepted %s", c.ID)
		}
	}
	for _, c := range a.creatives.all() {
		if c.PNG != "" || c.Video != "" {
			t.Fatal("media in the list")
		}
	}
}

var _ = http.MethodGet
