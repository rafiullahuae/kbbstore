package brain

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

// vfake is the vision answer the fake returns for video analysis.
var vfake string

// fakeClaude answers by which system prompt it sees. It records every request.
type fakeClaude struct {
	mu       sync.Mutex
	reqs     []map[string]any
	headers  []http.Header
	planner  []string // successive planner answers (JSON text)
	copyAns  []string
	research []string // raw content arrays (JSON) for the web-search call
	notes    string
	status   int
}

func (f *fakeClaude) server(t *testing.T) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var m map[string]any
		_ = json.Unmarshal(body, &m)
		f.mu.Lock()
		f.reqs = append(f.reqs, m)
		f.headers = append(f.headers, r.Header.Clone())
		f.mu.Unlock()
		if f.status != 0 {
			w.WriteHeader(f.status)
			w.Write([]byte(`{"type":"error","error":{"type":"api_error","message":"boom"}}`))
			return
		}
		sys := m["system"].([]any)[0].(map[string]any)["text"].(string)
		text := func(s string) string {
			b, _ := json.Marshal(map[string]any{"id": "msg", "model": m["model"], "stop_reason": "end_turn",
				"content": []any{map[string]any{"type": "text", "text": s}},
				"usage":   map[string]any{"input_tokens": 1000, "output_tokens": 500, "cache_read_input_tokens": 3000}})
			return string(b)
		}
		pop := func(q *[]string) string {
			f.mu.Lock()
			defer f.mu.Unlock()
			s := (*q)[0]
			if len(*q) > 1 {
				*q = (*q)[1:]
			}
			return s
		}
		switch {
		case strings.HasPrefix(sys, plannerSystem[:60]):
			w.Write([]byte(text(pop(&f.planner))))
		case strings.HasPrefix(sys, copySystem[:60]):
			w.Write([]byte(text(pop(&f.copyAns))))
		case strings.HasPrefix(sys, researchSystem[:60]):
			c := pop(&f.research)
			w.Write([]byte(`{"id":"r","stop_reason":"end_turn","content":` + c + `,"usage":{"input_tokens":5000,"output_tokens":900,"server_tool_use":{"web_search_requests":3}}}`))
		case strings.HasPrefix(sys, videoSystem[:60]):
			w.Write([]byte(text(vfake)))
		case strings.HasPrefix(sys, normalizeSystem[:60]):
			w.Write([]byte(text(f.notes)))
		default:
			t.Errorf("unknown system prompt %q", sys[:40])
		}
	}))
}

func shopFixture() ShopSnapshot {
	return ShopSnapshot{OrdersSource: "none", Products: []Product{
		{ID: "3195", Title: "COSRX - Low pH Good Morning Gel Cleanser - 150ml", Brand: "COSRX", PriceAED: 70, InStock: true, Image: "https://kbeautybliss.com/a.webp", Description: "tea tree oil and natural BHA"},
		{ID: "4001", Title: "Beauty of Joseon - Relief Sun: Rice + Probiotics SPF50+", Brand: "Beauty of Joseon", PriceAED: 85, InStock: true, Image: "https://kbeautybliss.com/b.webp"},
	}}
}

const goodPlan = `{"summary_en":"Arabic tests first.","summary_ar":"الاختبارات العربية أولاً.",
"tests":[{"name":"AR cleanser morning ritual","hypothesis":"Routine hook beats ingredient hook","lang":"ar","angle":"morning routine","product_ids":["3195"],"template":"routine","budget_aed":40}],
"allocation":[{"bucket":"Arabic prospecting","share_pct":56,"why":"owner"},{"bucket":"English prospecting","share_pct":24,"why":"expats"},{"bucket":"Creative tests","share_pct":20,"why":"diversity"}],
"watch":["CPM rising into White Friday"],
"proposals":[
 {"kind":"pause","target":"a1","params":{"daily_budget_aed":null,"bid_amount_aed":null,"name":null,"adset_id":null,"campaign_id":null,"creative_id":null,"lang":null,"countries":null,"objective":null},"why":"pause the winner","confidence":0.6,"evidence":[]},
 {"kind":"pause","target":"zzz","params":{},"why":"unknown","confidence":0.6,"evidence":[]},
 {"kind":"set_budget","target":"c1","params":{"daily_budget_aed":1000},"why":"too big","confidence":0.6,"evidence":[]},
 {"kind":"create_ad","target":"new:ar-routine","params":{"adset_id":"s1","creative_id":"copy:0"},"why":"fill the Arabic slot","confidence":0.7,"evidence":["slot ar=3"]}
],
"copy_requests":[{"product_ids":["3195"],"angle":"morning routine","template":"routine","brief":"gentle low-pH morning cleanse"}],
"report_en":"Quiet day; Arabic routine test queued.","report_ar":"يوم هادئ؛ تمت جدولة اختبار روتين بالعربية.","learnings":["Arabic routine hooks out-click ingredient hooks"]}`

const badCopy = `{"sets":[
{"lang":"ar","hooks":["صباحك يبدأ بلطف"],"primary_text":"غسول جل لطيف للبشرة المعرّضة للحبوب.","headline":"صباح منعش","description":"COSRX الأصلي","cta":"SHOP_NOW","sub":"روتين الصباح","bullets":["درجة حموضة منخفضة"],"badge":"COSRX"},
{"lang":"en","hooks":["Struggling with breakouts?"],"primary_text":"Struggling with breakouts? Try this.","headline":"Morning reset","description":"Authentic COSRX","cta":"SHOP_NOW","sub":"Morning ritual","bullets":["Low pH"],"badge":"COSRX"}]}`

const goodCopy = `{"sets":[
{"lang":"ar","hooks":["صباحك يبدأ بلطف"],"primary_text":"غسول جل لطيف للبشرة المعرّضة للحبوب.","headline":"صباح منعش","description":"COSRX الأصلي","cta":"SHOP_NOW","sub":"روتين الصباح","bullets":["درجة حموضة منخفضة"],"badge":"COSRX"},
{"lang":"en","hooks":["The gentlest start to your morning"],"primary_text":"A low-pH gel cleanser made for blemish-prone skin.","headline":"Morning reset","description":"Authentic COSRX","cta":"SHOP_NOW","sub":"Morning ritual","bullets":["Low pH"],"badge":"COSRX"}]}`

func winnerInput() CycleInput {
	in := baseInput()
	in.Now = time.Date(2026, 11, 23, 8, 0, 0, 0, time.UTC) // Monday: batch day
	in.Shop = shopFixture()
	in.Metrics = rows("ad", "a1", 12, 3, 300, 20, 50000, 800, 1.3)
	for i := range in.Metrics {
		in.Metrics[i].Date = in.Now.AddDate(0, 0, -(12 - i)).Format("2006-01-02")
		in.Metrics[i].Settling = false
	}
	return in
}

func newTestBrain(url string) *Brain {
	b := New("sk-test")
	b.Claude.BaseURL = url
	b.Claude.Backoff = time.Millisecond
	return b
}

// The whole cycle against a fake Claude: request shape, validation, copy
// lint-and-regenerate, designs, report.
func TestCycleWithPlanner(t *testing.T) {
	f := &fakeClaude{planner: []string{goodPlan}, copyAns: []string{badCopy, goodCopy}}
	srv := f.server(t)
	defer srv.Close()
	b := newTestBrain(srv.URL)
	out, err := b.Cycle(context.Background(), winnerInput())
	if err != nil {
		t.Fatal(err)
	}
	// request shape (verified against platform.claude.com, 20 Nov 2026)
	h, r0 := f.headers[0], f.reqs[0]
	if h.Get("anthropic-version") != "2026-10-21" || h.Get("x-api-key") != "sk-test" || h.Get("anthropic-beta") != FallbackBeta {
		t.Fatalf("headers %v", h)
	}
	if r0["model"] != "claude-sonnet-5-5" || r0["fallbacks"] != "default" {
		t.Fatalf("model/fallbacks %v %v", r0["model"], r0["fallbacks"])
	}
	for _, k := range []string{"temperature", "top_p", "top_k"} {
		if _, ok := r0[k]; ok {
			t.Fatalf("%s sent (400 on 2026-10-21)", k)
		}
	}
	oc := r0["output_config"].(map[string]any)
	if oc["format"].(map[string]any)["type"] != "json_schema" || oc["effort"] != "medium" {
		t.Fatalf("output_config %v", oc)
	}
	cc := r0["system"].([]any)[0].(map[string]any)["cache_control"].(map[string]any)
	if cc["type"] != "ephemeral" || cc["ttl"] != "1h" {
		t.Fatalf("cache_control %v", cc)
	}
	// the stable prefix must not carry per-cycle data
	if strings.Contains(r0["system"].([]any)[0].(map[string]any)["text"].(string), "2026") {
		t.Fatal("system prompt carries a date: cache would break every cycle")
	}
	// validation: winner pause, unknown id and over-step budget dropped; create_ad kept
	if find(out.Proposals, "pause", "a1") != nil || find(out.Proposals, "pause", "zzz") != nil || find(out.Proposals, "set_budget", "c1") != nil {
		t.Fatalf("invalid proposals survived: %+v", out.Proposals)
	}
	ca := find(out.Proposals, "create_ad", "new:ar-routine")
	if ca == nil || ca.Params["creative_pair_id"] == nil {
		t.Fatalf("create_ad missing/unlinked: %+v", out.Proposals)
	}
	if len(out.Dropped) != 3 {
		t.Fatalf("dropped %v", out.Dropped)
	}
	// copy: the EN set was blocked first time, regenerated, both langs out
	if len(out.CopySets) != 2 || len(out.Designs) != 2 || out.Designs[0].Lang != "ar" || out.Designs[0].Template != "routine" {
		t.Fatalf("copy %+v designs %+v", out.CopySets, out.Designs)
	}
	for _, c := range out.CopySets {
		if !c.Lint.OK && c.Lint.Blocked {
			t.Fatal("blocked copy returned")
		}
	}
	// planner, planner repair (3 of 4 proposals invalid), copy, copy-regenerate
	if len(f.reqs) != 4 {
		t.Fatalf("%d requests", len(f.reqs))
	}
	last := f.reqs[3]["messages"].([]any)
	if !strings.Contains(last[len(last)-1].(map[string]any)["content"].(string), "Struggling with") {
		t.Fatal("regeneration did not carry the linter findings")
	}
	// plan + report
	if out.Model != "claude-sonnet-5-5" || len(out.Plan.Tests) != 1 || out.Plan.Allocation[0].DailyAED != 280 {
		t.Fatalf("plan %+v", out.Plan)
	}
	if !strings.Contains(out.Report, "\nAR\n") || !strings.Contains(out.Report, "يوم هادئ") || out.Usage.CostUSD <= 0 {
		t.Fatalf("report %q usage %+v", out.Report, out.Usage)
	}
}

// Claude down: the rules layer still pauses the loser and the report says so.
// Mutation: return the planner error from Cycle -> red.
func TestPlannerDownStillProtects(t *testing.T) {
	f := &fakeClaude{status: 500}
	srv := f.server(t)
	defer srv.Close()
	in := baseInput()
	in.Metrics = rows("ad", "a1", 9, 3, 2.5*45, 0, 20000, 240, 1.2)
	b := newTestBrain(srv.URL)
	b.Econ = DefaultEcon()
	out, err := b.Cycle(context.Background(), in)
	if err != nil {
		t.Fatal(err)
	}
	if out.Model != "rules-only" || len(out.Dropped) == 0 || !strings.Contains(out.Report, "not available") {
		t.Fatalf("model %s dropped %v", out.Model, out.Dropped)
	}
	if len(f.reqs) != 3 {
		t.Fatalf("want 3 attempts, got %d", len(f.reqs))
	}
}

// Mostly-invalid answers get ONE repair round carrying the reasons.
func TestRepairRound(t *testing.T) {
	bad := `{"summary_en":"","summary_ar":"","tests":[],"allocation":[],"watch":[],"proposals":[{"kind":"pause","target":"nope","params":{},"why":"x","confidence":1,"evidence":[]}],"copy_requests":[],"report_en":"","report_ar":"","learnings":[]}`
	good := `{"summary_en":"ok","summary_ar":"تمام","tests":[],"allocation":[],"watch":[],"proposals":[],"copy_requests":[],"report_en":"fixed","report_ar":"تم","learnings":[]}`
	f := &fakeClaude{planner: []string{bad, good}}
	srv := f.server(t)
	defer srv.Close()
	out, _ := newTestBrain(srv.URL).Cycle(context.Background(), winnerInput())
	if len(f.reqs) != 2 {
		t.Fatalf("%d requests", len(f.reqs))
	}
	msgs := f.reqs[1]["messages"].([]any)
	if len(msgs) != 3 || !strings.Contains(msgs[2].(map[string]any)["content"].(string), "unknown object id") {
		t.Fatalf("repair turn %v", msgs)
	}
	if !strings.Contains(out.Report, "fixed") {
		t.Fatal(out.Report)
	}
}

// No key: rules-only, no network.
func TestNoKeyRulesOnly(t *testing.T) {
	b := New("")
	out, err := b.Cycle(context.Background(), winnerInput())
	if err != nil || out.Model != "rules-only" || out.Plan.Week == "" || len(out.Plan.Allocation) != 3 {
		t.Fatalf("%v %+v", err, out.Plan)
	}
	if out.Plan.Allocation[0].Bucket != "Arabic prospecting" || out.Plan.Allocation[0].SharePct != 56 {
		t.Fatalf("arabic allocation %+v", out.Plan.Allocation[0])
	}
}

// Research: notes citing a URL the search never returned are dropped.
// Mutation: skip the source check -> the unsourced note survives, red.
func TestResearchKeepsOnlySourcedNotes(t *testing.T) {
	content := `[{"type":"server_tool_use","id":"s1","name":"web_search","input":{"query":"k-beauty trends uae"}},
{"type":"web_search_tool_result","tool_use_id":"s1","content":[{"type":"web_search_result","url":"https://www.example-news.ae/kbeauty-2026","title":"K-beauty in the UAE","encrypted_content":"x"}]},
{"type":"text","text":"Rice and PDRN are rising.","citations":[{"type":"web_search_result_location","url":"https://www.example-news.ae/kbeauty-2026","title":"K-beauty in the UAE","cited_text":"rice"}]}]`
	notes := `{"notes":[
{"topic":"ingredient","title":"Rice and PDRN rising","title_ar":"الأرز وPDRN","body":"b","body_ar":"ب","angles":["rice glow ritual","Cures acne fast"],"source_urls":["https://example-news.ae/kbeauty-2026/"]},
{"topic":"trend","title":"Invented trend","title_ar":"x","body":"b","body_ar":"ب","angles":["x"],"source_urls":["https://never-searched.com/x"]}]}`
	f := &fakeClaude{research: []string{content}, notes: notes}
	srv := f.server(t)
	defer srv.Close()
	b := newTestBrain(srv.URL)
	r, err := b.Research(context.Background(), ResearchInput{Now: tNow, Shop: shopFixture()})
	if err != nil {
		t.Fatal(err)
	}
	if len(r.Notes) != 1 || r.Notes[0].Title != "Rice and PDRN rising" {
		t.Fatalf("notes %+v", r.Notes)
	}
	if len(r.Notes[0].Angles) != 1 || r.Notes[0].Angles[0] != "rice glow ritual" {
		t.Fatalf("angles must pass the linter: %v", r.Notes[0].Angles)
	}
	tool := f.reqs[0]["tools"].([]any)[0].(map[string]any)
	if tool["type"] != "web_search_20260209" || tool["user_location"].(map[string]any)["country"] != "AE" {
		t.Fatalf("tool %v", tool)
	}
	if f.reqs[1]["model"] != "claude-haiku-5-5" {
		t.Fatal("normalising should use the small model")
	}
	if u := b.LastResearchUsage(); u.WebSearches != 3 || u.CostUSD <= 0.03 {
		t.Fatalf("usage %+v", u)
	}
}

func TestHandler(t *testing.T) {
	b := New("")
	var got string
	h := Handler(b, Deps{Input: func(context.Context) (CycleInput, error) { return winnerInput(), nil }, Result: func(k string, v any) { got = k }})
	srv := httptest.NewServer(h)
	defer srv.Close()
	resp, err := http.Post(srv.URL+"/api/brain/plan", "application/json", nil)
	if err != nil || resp.StatusCode != 200 || got != "plan" {
		t.Fatalf("%v %v %q", err, resp.StatusCode, got)
	}
	resp, _ = http.Post(srv.URL+"/api/brain/copy", "application/json", strings.NewReader(`{}`))
	if resp.StatusCode != 400 {
		t.Fatal(resp.StatusCode)
	}
	resp, _ = http.Get(srv.URL + "/api/brain/plan")
	if resp.StatusCode != 405 {
		t.Fatal(resp.StatusCode)
	}
}

// New ads are batched weekly: a planner create_ad on a Thursday is refused
// (every new ad is a significant edit that restarts learning).
func TestNewAdsOnlyOnBatchDay(t *testing.T) {
	var a plannerAnswer
	_ = json.Unmarshal([]byte(goodPlan), &a)
	b := New("")
	for _, day := range []time.Time{tNow, time.Date(2026, 11, 23, 8, 0, 0, 0, time.UTC)} {
		in := baseInput()
		in.Now = day
		rr := Evaluate(in, tEcon, b.Rules)
		props, _ := b.validate(a, in, rr, knownObjects(in))
		got := find(props, "create_ad", "new:ar-routine") != nil
		if got != (day.Weekday() == time.Monday) {
			t.Fatalf("%s: create_ad kept=%v", day.Weekday(), got)
		}
	}
}
