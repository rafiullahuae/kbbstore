package main

// Tests for the engine gaps closed on 20 Nov 2026 (docs/BRAIN-INTEGRATION.md) and the first launch. Each says what the
// defect looked like and which mutation turns it red.

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"kaa/brain"
	"kaa/executor"
	"kaa/model"
)

func call(t *testing.T, s *server, method, path, acct string, body any) (int, map[string]any) {
	t.Helper()
	var rd *bytes.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	} else {
		rd = bytes.NewReader(nil)
	}
	r := httptest.NewRequest(method, path, rd)
	r.Host = "127.0.0.1:1"
	r.Header.Set("X-KAA", "t")
	if acct != "" {
		r.Header.Set("X-KAA-Account", acct)
	}
	w := httptest.NewRecorder()
	s.ServeHTTP(w, r)
	var out map[string]any
	_ = json.Unmarshal(w.Body.Bytes(), &out)
	return w.Code, out
}

func testServer(ac *Acct) *server {
	return &server{app: ac.app, token: "t", addr: "127.0.0.1:1", subs: map[chan []byte]bool{}}
}

// Approvals → Approve on ONE proposal runs that proposal and the not-yet-made objects it names, nothing else. Before,
// Approve re-ran a whole auto cycle (a fresh plan the owner never saw). MUTATION: make withDeps return only idx → the
// ad's ad set was never made, the create_ad is refused and this goes red.
func TestApproveOneProposal(t *testing.T) {
	ac, m := testApp(t)
	ac.settings.Limits.Autopilot = model.AutopilotShadow
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotShadow); err != nil {
		t.Fatal(err)
	}
	ps := ac.Pending()
	var ad, camp string
	for _, it := range ps.Items {
		if it.Status != "shadow" {
			t.Fatalf("practice proposal %s is %s", it.ID, it.Status)
		}
		switch it.Kind {
		case model.KindCreateAd:
			ad = it.ID
		case model.KindCreateCampaign:
			camp = it.ID
		}
	}
	if ad == "" || len(objStatus(m, "campaigns", "KAA:")) != 0 {
		t.Fatalf("practice wrote to Meta or proposed no ad: %+v", ps.Items)
	}
	s := testServer(ac)
	code, out := call(t, s, "POST", "/api/proposals/"+ad+"/approve", "", nil)
	if code != 200 || out["ran"].(float64) != 4 || out["counts"].(map[string]any)["done"].(float64) != 4 {
		t.Fatalf("approve: %d %v", code, out)
	}
	if len(objStatus(m, "campaigns", "KAA:")) != 1 || len(objStatus(m, "ads", "KAA:")) != 1 {
		t.Fatal("the approved ad (and the campaign / ad set it needs) were not made once")
	}
	for _, it := range ac.Pending().Items {
		if it.Status != "done" || it.By != "owner" {
			t.Fatalf("%s is %s by %s", it.ID, it.Status, it.By)
		}
	}
	if code, _ := call(t, s, "POST", "/api/proposals/"+camp+"/approve", "", nil); code != 409 {
		t.Fatalf("a done proposal approved again: %d", code)
	}
	if code, _ := call(t, s, "POST", "/api/proposals/nope/approve", "", nil); code != 404 {
		t.Fatalf("unknown proposal: %d", code)
	}
	if code, _ := call(t, s, "POST", "/api/proposals/"+ad+"/delete", "", nil); code != 404 {
		t.Fatalf("unknown verb: %d", code)
	}
}

// Not now marks one proposal rejected and writes nothing; an account with the autopilot off cannot approve.
// MUTATION: drop the Autopilot == off guard in Decide → the approval after the kill writes to Meta, red.
func TestRejectAndOffGuard(t *testing.T) {
	ac, m := testApp(t)
	ac.settings.Limits.Autopilot = model.AutopilotShadow
	ctx := context.Background()
	if _, err := ac.Cycle(ctx, model.AutopilotShadow); err != nil {
		t.Fatal(err)
	}
	s := testServer(ac)
	items := ac.Pending().Items
	n0 := len(m.Writes())
	if code, out := call(t, s, "POST", "/api/proposals/"+items[0].ID+"/reject", "", nil); code != 200 || out["status"] != "rejected" {
		t.Fatalf("reject %d %v", code, out)
	}
	if len(m.Writes()) != n0 || ac.Pending().Items[0].Status != "rejected" {
		t.Fatal("Not now wrote to Meta or did not stick")
	}
	ac.Kill(ctx)
	n1 := len(m.Writes())
	if code, out := call(t, s, "POST", "/api/proposals/"+items[len(items)-1].ID+"/approve", "", nil); code != 409 {
		t.Fatalf("approve with the autopilot off: %d %v", code, out)
	}
	if len(m.Writes()) != n1 {
		t.Fatal("an approval wrote to Meta after the kill switch")
	}
}

// GET /api/creatives/{id}/file serves the stored PNG of THIS account's creative only. Before there was no route and
// the library showed text tiles. MUTATION: look the id up in every account's library → the other account's
// request gets the picture and this goes red.
func TestCreativeFileScoped(t *testing.T) {
	ac, m := testApp(t)
	b := secondAccount(t, ac, m)
	if _, err := b.creatives.Add(model.Creative{ID: "cr-only-b", Lang: "en", Format: "1:1", PNG: tinyPNG, Headline: "x", PrimaryText: "y", Link: "https://kbeautybliss.com/", Source: "owner"}, time.Now()); err != nil {
		t.Fatal(err)
	}
	if _, err := ac.creatives.Add(model.Creative{ID: "cr-text", Lang: "en", Format: "1:1", Headline: "x", PrimaryText: "y", Link: "https://kbeautybliss.com/", Source: "owner"}, time.Now()); err != nil {
		t.Fatal(err)
	}
	s := testServer(ac)
	get := func(path, acct string) *httptest.ResponseRecorder {
		r := httptest.NewRequest("GET", path, nil)
		r.Host = "127.0.0.1:1"
		r.Header.Set("X-KAA", "t")
		if acct != "" {
			r.Header.Set("X-KAA-Account", acct)
		}
		w := httptest.NewRecorder()
		s.ServeHTTP(w, r)
		return w
	}
	w := get("/api/creatives/cr-ar-1/file", "")
	png, _ := base64.StdEncoding.DecodeString(tinyPNG)
	if w.Code != 200 || w.Header().Get("Content-Type") != "image/png" || !bytes.Equal(w.Body.Bytes(), png) {
		t.Fatalf("own picture: %d %s", w.Code, w.Header().Get("Content-Type"))
	}
	for _, c := range []struct{ path, acct string }{
		{"/api/creatives/cr-only-b/file", ac.id},   // another account's creative
		{"/api/creatives/cr-text/file", ""},        // no media
		{"/api/creatives/..%2fsettings/file", ""},  // traversal
		{"/api/creatives/../../settings/file", ""}, // traversal
		{"/api/creatives/CR-AR-1/file", ""},        // not an id
	} {
		if w := get(c.path, c.acct); w.Code == 200 {
			t.Fatalf("%s served %d bytes", c.path, w.Body.Len())
		}
	}
	if w := get("/api/creatives/cr-only-b/file", b.id); w.Code != 200 {
		t.Fatalf("the other account's own picture: %d", w.Code)
	}
}

// The owner's videos are read from <data>/videos only. MUTATION: drop the EvalSymlinks prefix check in videoFile →
// the symlink out of the folder is served and this goes red.
func TestVideoFileStaysInTheFolder(t *testing.T) {
	ac, _ := testApp(t)
	a := ac.app
	dir := filepath.Join(a.st.Dir, "videos")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(filepath.Join(dir, "v1.mp4"), []byte("\x00\x00\x00\x18ftypmp42 video"), 0o600)
	outside := filepath.Join(t.TempDir(), "secret.mp4")
	_ = os.WriteFile(outside, []byte("not yours"), 0o600)
	_ = os.Symlink(outside, filepath.Join(dir, "link.mp4"))
	_ = os.Mkdir(filepath.Join(dir, "d.mp4"), 0o700)
	f, n, err := a.videoFile("videos/v1.mp4")
	if err != nil || n != 18 {
		t.Fatalf("own video: %v %d", err, n)
	}
	f.Close()
	for _, p := range []string{"videos/link.mp4", "videos/../settings.json", "../videos/v1.mp4", "/etc/passwd", "videos/d.mp4", "videos/v1.exe", "accounts/x/v.mp4", "videos/missing.mp4"} {
		if f, _, err := a.videoFile(p); err == nil {
			f.Close()
			t.Fatalf("%s was opened", p)
		}
	}
}

// The ad-wording rule lives on /api/limits and the brain reads it from there. Before, /api/limits refused the field
// and the brain kept its own copy. MUTATION: make brain.Wording ignore Limits.Wording → red.
func TestWordingOnLimits(t *testing.T) {
	ac, _ := testApp(t)
	s := testServer(ac)
	if code, out := call(t, s, "PUT", "/api/limits", "", map[string]any{"wording": "strict"}); code != 200 || out["wording"] != "strict" {
		t.Fatalf("put wording: %d %v", code, out)
	}
	if code, _ := call(t, s, "PUT", "/api/limits", "", map[string]any{"wording": "anything goes"}); code != 400 {
		t.Fatalf("a bad wording rule was accepted: %d", code)
	}
	if ac.Settings().Limits.Wording != "strict" {
		t.Fatal("not stored")
	}
	if w := brain.Wording(ac.Settings().Limits, brain.AccountSettings{AdWording: brain.Relaxed}); w != brain.Strict {
		t.Fatalf("the brain read %q, not the limits' strict", w)
	}
	if w := brain.Wording(model.Limits{}, brain.AccountSettings{AdWording: brain.Relaxed}); w != brain.Relaxed {
		t.Fatalf("no wording on the limits: the brain's own setting is the fallback, got %q", w)
	}
}

// quietBrain plans nothing: the account's first launch must come from the engine's starter set.
type quietBrain struct{}

func (quietBrain) Cycle(context.Context, model.CycleInput) (model.CycleOutput, error) {
	return model.CycleOutput{Proposals: []model.Proposal{}}, nil
}
func (quietBrain) Research(context.Context, model.ResearchInput) (model.Research, error) {
	return model.Research{}, nil
}

// First launch does not wait for Monday: Auto + limits + nothing of the app's running → the first cycle launches the
// starter set (Arabic first, catalog retargeting with the catalog linked), every create PAUSED then activated; the
// next cycle launches nothing again. MUTATION: remove the starterPlan call in Cycle → nothing launches, red; drop
// the "app's campaigns are running" check → the second cycle launches a second set, red.
func TestStarterLaunchesAtOnce(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	out, err := ac.Cycle(ctx, model.AutopilotAuto)
	if err != nil {
		t.Fatal(err)
	}
	c := out["counts"].(map[string]int)
	camps := objStatus(m, "campaigns", "KAA:")
	ads := objStatus(m, "ads", "KAA:")
	if c["done"] == 0 || c["failed"]+c["refused"] != 0 || len(camps) != 2 || len(ads) < 3 {
		t.Fatalf("starter: counts %v, campaigns %v, ads %v (%v)", c, camps, ads, out["results"])
	}
	for id, st := range camps {
		if st != "ACTIVE" {
			t.Fatalf("campaign %s is %s", id, st)
		}
	}
	ar, en := 0, 0
	for _, o := range m.Objects("ads") {
		n, _ := o["name"].(string)
		ar += map[bool]int{true: 1}[strings.HasPrefix(n, "KAA: Prospecting AR")]
		en += map[bool]int{true: 1}[strings.HasPrefix(n, "KAA: Prospecting EN")]
	}
	if ar <= en || en != 1 {
		t.Fatalf("Arabic first: %d AR, %d EN", ar, en)
	}
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "/campaigns") && w.Status == 200 && (w.Form["status"] != "PAUSED" || w.Idem == "") {
			t.Fatalf("create not PAUSED / without a key: %+v", w)
		}
	}
	if ac.starter.State != "launched" {
		t.Fatalf("starter state %q", ac.starter.State)
	}
	n := len(m.Objects("campaigns"))
	ac.starter = StarterState{} // even without the 7-day memory, running campaigns alone hold the next starter back
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	if len(m.Objects("campaigns")) != n {
		t.Fatal("the next cycle launched a second starter set")
	}
}

// Practice mode shows exactly what the first launch WOULD be, writing nothing. MUTATION: pass cycleMode "auto" to
// env for a shadow cycle → objects are created, red.
func TestStarterPractice(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ac.settings.Limits.Autopilot = model.AutopilotShadow
	out, err := ac.Cycle(context.Background(), model.AutopilotAuto)
	if err != nil {
		t.Fatal(err)
	}
	if out["starter"] == nil || len(objStatus(m, "campaigns", "KAA:")) != 0 || ac.starter.State != "practice" {
		t.Fatalf("practice: %v, state %q", out["starter"], ac.starter.State)
	}
	for _, it := range ac.starter.Items {
		if it.Status != "shadow" {
			t.Fatalf("%s %s is %s (%v)", it.Kind, it.ID, it.Status, it.Result)
		}
	}
}

// No limits = nothing launches, starter included; and a budget too small to run says why on the dashboard.
// MUTATION: drop the Limits.Set() check in starterNeeded → a starter is planned without limits, red.
func TestStarterNeedsLimits(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ac.settings.Limits.DailyAED, ac.settings.Limits.MonthlyAED = 0, 0
	out, _ := ac.Cycle(context.Background(), model.AutopilotAuto)
	if out["starter"] != nil || len(objStatus(m, "campaigns", "KAA:")) != 0 || ac.starter.State != "" {
		t.Fatal("a starter set without limits")
	}
	// a daily limit too small for a campaign (70% of 12 = 8.4 < the 10 AED floor); the owner's own 50 AED/day is NOT
	// counted any more (22 Nov: his limits are for the autopilot's ads only)
	ac.settings.Limits.DailyAED, ac.settings.Limits.MonthlyAED = 12, 1000
	out, _ = ac.Cycle(context.Background(), model.AutopilotAuto)
	if out["starter"] != nil || ac.starter.State != "skipped" || !strings.Contains(ac.starter.Why, "no room") {
		t.Fatalf("tiny room: %v %+v", out["starter"], ac.starter)
	}
}

// Each guard of the first launch on its own: a running app campaign, app spend in the last 7 days, a starter launched
// less than 7 days ago, a planner that launches itself. MUTATION: drop any one of them in starterNeeded → red.
func TestStarterNeededGuards(t *testing.T) {
	ac, _ := testApp(t)
	env := ac.env(model.AutopilotAuto)
	now := env.Now
	running := model.CycleInput{Tree: []model.Campaign{{ID: "1", Managed: true, Status: "ACTIVE"}}}
	spent := model.CycleInput{Tree: []model.Campaign{{ID: "2", Managed: true, Status: "PAUSED"}},
		Metrics: []model.MetricRow{{Level: "campaign", ObjectID: "2", Date: now.In(ac.loc()).AddDate(0, 0, -3).Format("2006-01-02"), SpendAED: 40}}}
	idle := model.CycleInput{Tree: []model.Campaign{{ID: "3", Managed: true, Status: "PAUSED"}, {ID: "4", Status: "ACTIVE"}}}
	if ok, _ := ac.starterNeeded(running, nil, env); ok {
		t.Fatal("starter while an app campaign runs")
	}
	if ok, _ := ac.starterNeeded(spent, nil, env); ok {
		t.Fatal("starter while an app campaign spent this week")
	}
	if ok, _ := ac.starterNeeded(idle, []model.Proposal{{Kind: model.KindCreateCampaign}}, env); ok {
		t.Fatal("starter on top of the planner's own launch")
	}
	if ok, w := ac.starterNeeded(idle, nil, env); !ok {
		t.Fatalf("an idle account (only the owner's campaign running) did not start: %s", w)
	}
	ac.starter = StarterState{State: "launched", At: now.Add(-48 * time.Hour), AdsOn: true} // its ads were switched on (he may have paused them since)
	if ok, _ := ac.starterNeeded(idle, nil, env); ok {
		t.Fatal("a second starter 2 days after the first")
	}
	ac.starter.At = now.Add(-8 * 24 * time.Hour)
	if ok, _ := ac.starterNeeded(idle, nil, env); !ok {
		t.Fatal("an account idle for a week after its starter does not start again")
	}
}

// The fake Claude answers with scripted tool calls, so the REAL brain plans with Claude in -mock (before, every
// answer was plain text and the planner fell back to rules-only). MUTATION: answer "mock answer" text for the
// planner in metamock.claudeAnswer → model rules-only, red.
func TestMockClaudeDrivesTheRealBrain(t *testing.T) {
	ac, _ := testApp(t)
	a := ac.app
	eb := brain.New(model.BrainDeps{LLM: a.claude, DataDir: t.TempDir(), Log: func(string, string, any) {}})
	ctx := context.Background()
	res, err := eb.Research(ctx, model.ResearchInput{Now: time.Now(), Shop: a.shopSnap})
	if err != nil || len(res.Trends)+len(res.ArabicInsights)+len(res.Competitors) < 3 {
		t.Fatalf("research: %v %+v", err, res)
	}
	ac.mu.RLock()
	in := model.CycleInput{Now: time.Now(), Limits: ac.settings.Limits, Account: ac.accountRef(), Shop: a.shopSnap, Links: ac.settings.Links}
	ac.mu.RUnlock()
	out, err := eb.Cycle(ctx, in)
	if err != nil || !strings.Contains(out.Plan.Summary, "Arabic-first") {
		t.Fatalf("planner did not run: %v %q", err, out.Plan.Summary)
	}
	if ids := eb.(interface{ TrendingProductIDs() []string }).TrendingProductIDs(); len(ids) == 0 {
		t.Fatal("trending matched no shop product")
	}
}

// The owner, 22 Nov: "my campaigns budget should not calculate in the system, the budget which i set, that is only
// for the auto pilot system ads". His own campaign (50 AED/day, running) leaves the whole 55 AED/day to the app:
// the first launch is built (before, "no room": 55 − 50 left 5).
func TestOwnersBudgetsDoNotCount(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ac.settings.Limits.DailyAED, ac.settings.Limits.MonthlyAED = 55, 1000
	if err := ac.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}
	if own := executor.CommittedDailyOwn(ac.input().Tree); own != 50 { // metamock.Seed: "Owner – Always on", 50 AED/day
		t.Fatalf("the owner's own campaign: %v AED/day, want 50", own)
	}
	out, _ := ac.Cycle(context.Background(), model.AutopilotAuto)
	if out["starter"] == nil || len(objStatus(m, "campaigns", "KAA:")) == 0 {
		t.Fatalf("the owner's own budget still blocked the first launch: %+v", ac.starter)
	}
}

// The owner, 22 Nov: the first launch stopped part-way ("context deadline exceeded") after its campaign was made;
// it was recorded "launched", every later cycle skipped it for 7 days, and no ad ever reached Ads Manager. His
// account exactly: the app's campaign PAUSED, its ad set made, no ad switched on, AED 0 spent, record "launched".
//
//	1  starterNeeded → true (build again). MUTATION: drop the ActiveAds condition → false, red.
//	2  leftoverStarters → that campaign archived first (never two). Anything that spent or has a live ad: kept.
//	3  noteStarter: a run whose campaign was made but no ad switched on is "failed", not "launched".
func TestStoppedFirstLaunchIsBuiltAgain(t *testing.T) {
	ac, _ := testApp(t)
	now := time.Now()
	half := model.Campaign{ID: "c1", Name: "KAA: Prospecting · Advantage+ sales", Managed: true, Status: "PAUSED", EffectiveStatus: "PAUSED", DailyBudgetAED: 770,
		AdSets: []model.AdSet{{ID: "s1", CampaignID: "c1", Status: "ACTIVE", EffectiveStatus: "IN_PROCESS", Ads: []model.Ad{}}}}
	ran := model.Campaign{ID: "c2", Name: "KAA: Retargeting", Managed: true, Status: "PAUSED", EffectiveStatus: "PAUSED",
		AdSets: []model.AdSet{{ID: "s2", CampaignID: "c2", Status: "ACTIVE", Ads: []model.Ad{{ID: "a2", Status: "PAUSED"}}}}}
	in := model.CycleInput{Now: now, Tree: []model.Campaign{half, ran}, Metrics: []model.MetricRow{{Date: now.Format("2006-01-02"), Level: "campaign", ObjectID: "c2", SpendAED: 40}}}
	ac.mu.Lock()
	ac.starter = StarterState{State: "launched", At: now.Add(-time.Hour)}
	ac.mu.Unlock()
	env := ac.env(model.AutopilotAuto)
	env.Tree = in.Tree
	// c2 spent this week, so step 1 is checked on c1 alone
	in1 := in
	in1.Tree, in1.Metrics = []model.Campaign{half}, nil
	env.Tree = in1.Tree
	if ok, why := ac.starterNeeded(in1, nil, env); !ok {
		t.Fatalf("a launch with no live ad must be built again, got %q", why)
	}
	env.Tree = in.Tree
	lo := leftoverStarters(in, env, nil)
	if len(lo) != 1 || lo[0].Target != "c1" || lo[0].Kind != model.KindArchive {
		t.Fatalf("leftovers %+v, want only c1 archived (c2 spent: kept)", lo)
	}
	props := []model.Proposal{{ID: "starter-x-sales", Kind: model.KindCreateCampaign}, {ID: "starter-x-ad-1", Kind: model.KindCreateAd}}
	res := []executor.Result{{ProposalID: "starter-x-sales", Status: "done"}, {ProposalID: "starter-x-ad-1", Status: "failed", Error: "context deadline exceeded"}}
	ac.noteStarter(props, res, model.AutopilotAuto, "test")
	if ac.starter.State != "failed" {
		t.Fatalf("campaign made, no ad on: %q, want failed", ac.starter.State)
	}
	res[1] = executor.Result{ProposalID: "starter-x-ad-1", Status: "done", Steps: []string{"created PAUSED", "activated ad 9"}}
	ac.noteStarter(props, res, model.AutopilotAuto, "test")
	if ac.starter.State != "launched" {
		t.Fatalf("an ad switched on: %q, want launched", ac.starter.State)
	}
}
