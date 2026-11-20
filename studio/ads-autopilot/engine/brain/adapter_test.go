package brain

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// fakeLLM answers through model.LLM the way engine/claude does: strict client
// tools come back as ToolUses.
type fakeLLM struct{ reqs []model.LLMRequest }

func (f *fakeLLM) Complete(_ context.Context, r model.LLMRequest) (model.LLMResponse, error) {
	f.reqs = append(f.reqs, r)
	switch {
	case strings.HasPrefix(r.System, plannerSystem[:60]):
		in := json.RawMessage(`{"summary_en":"s","summary_ar":"ع","tests":[],"allocation":[],"watch":[],"proposals":[
{"kind":"create_adset","target":"new:ar-women","params":{"campaign_id":"c1","name":"KAA: AR","audience":"women_gcc_ar","countries":["AE"]},"why":"Arabic set","confidence":0.7,"evidence":[]}],
"copy_requests":[],"report_en":"ok","report_ar":"تمام","learnings":[]}`)
		blk, _ := json.Marshal(map[string]any{"type": "tool_use", "id": "tu1", "name": "respond", "input": in})
		return model.LLMResponse{StopReason: "tool_use", ToolUses: []model.ToolUse{{ID: "tu1", Name: "respond", Input: in}}, Content: []json.RawMessage{blk}}, nil
	case strings.HasPrefix(r.System, trendingSystem[:60]):
		blk := json.RawMessage(`{"type":"web_search_tool_result","tool_use_id":"s","content":[{"type":"web_search_result","url":"https://www.oliveyoung.com/best","title":"Olive Young best"}]}`)
		txt := json.RawMessage(`{"type":"text","text":"1. Anua Heartleaf 77 Soothing Toner rising","citations":[{"type":"web_search_result_location","url":"https://www.oliveyoung.com/best","title":"Olive Young best"}]}`)
		return model.LLMResponse{StopReason: "end_turn", Content: []json.RawMessage{blk, txt}, Usage: model.LLMUsage{WebSearches: 2}}, nil
	case strings.HasPrefix(r.System, normalizeSystem[:60]):
		in := json.RawMessage(`{"items":[
{"rank":1,"name":"Heartleaf 77% Soothing Toner","brand":"Anua","kind":"product","momentum":"rising","region":"gcc","why":"TikTok","why_ar":"تيك توك","source_urls":["https://www.oliveyoung.com/best"]},
{"rank":2,"name":"PDRN Pink Peptide Serum","brand":"Medicube","kind":"product","momentum":"hot","region":"global","why":"viral","why_ar":"منتشر","source_urls":["https://www.oliveyoung.com/best"]},
{"rank":3,"name":"Invented Cream","brand":"X","kind":"product","momentum":"new","region":"global","why":"?","why_ar":"؟","source_urls":["https://not-searched.example/x"]}]}`)
		return model.LLMResponse{StopReason: "tool_use", ToolUses: []model.ToolUse{{ID: "tu2", Name: "respond", Input: in}}}, nil
	}
	return model.LLMResponse{StopReason: "end_turn", Text: ""}, nil
}

func modelInput() model.CycleInput {
	return model.CycleInput{Now: time.Date(2026, 11, 23, 8, 0, 0, 0, time.UTC), Account: model.Account{ID: "act_1", Name: "KBB"},
		Limits: model.Limits{DailyAED: 300, MonthlyAED: 9000, MaxBudgetStepPct: 20, CooldownHours: 72, MaxActiveAds: 6, Countries: []string{"AE"}, Autopilot: "shadow"},
		Tree: []model.Campaign{{ID: "c1", Name: "KAA: Sales", Status: "ACTIVE", Managed: true, AdSets: []model.AdSet{{ID: "s1", CampaignID: "c1", Name: "KAA: AR women", Status: "ACTIVE", DailyBudgetAED: 100,
			Ads: []model.Ad{{ID: "a1", AdSetID: "s1", Name: "AR · routine", Status: "ACTIVE"}}}}}},
		Shop:    model.ShopSnapshot{Products: []model.Product{{ID: "3214", Title: "Anua - Heartleaf 77% Soothing Toner 250ml", Brand: "Anua", PriceAED: 105, InStock: true, Image: "https://kbeautybliss.com/a.webp"}}},
		Metrics: []model.MetricRow{{Date: "2026-11-15", Level: "ad", ObjectID: "a1", SpendAED: 300, Impressions: 20000, Clicks: 200, Purchases: 0}},
	}
}

// The engine's model types in, the engine's executor names out; Claude via model.LLM.
func TestEngineAdapter(t *testing.T) {
	llm := &fakeLLM{}
	e := New(model.BrainDeps{LLM: llm, DataDir: t.TempDir()}).(*Engine)
	out, err := e.Cycle(context.Background(), modelInput())
	if err != nil {
		t.Fatal(err)
	}
	var pause, adset *model.Proposal
	for i, p := range out.Proposals {
		if p.Kind == "pause" && p.Target == "a1" {
			pause = &out.Proposals[i]
		}
		if p.Kind == "create_adset" {
			adset = &out.Proposals[i]
		}
	}
	if pause == nil {
		t.Fatalf("rules pause missing: %+v", out.Proposals)
	}
	if adset == nil || adset.Target != "tmp:ar-women" || adset.Params["advantage_audience"] != 1 || adset.Params["destination"] != "website" {
		t.Fatalf("adset %+v", adset)
	}
	if g, _ := json.Marshal(adset.Params["genders"]); string(g) != "[2]" {
		t.Fatalf("genders %s", g)
	}
	// planner request: strict respond tool, no output_config, cached system, purpose
	var plan model.LLMRequest
	for _, r := range llm.reqs {
		if r.Purpose == "plan" {
			plan = r
		}
	}
	if len(plan.Tools) != 1 || !strings.Contains(string(plan.Tools[0]), `"strict":true`) || !strings.Contains(plan.System, "respond tool") {
		t.Fatalf("plan request %+v", plan.Tools)
	}
	// trending ran (stale) and matched the shop: Anua sold, Medicube to consider, invented dropped
	tr := e.trending()
	if len(tr.Items) != 2 || len(tr.Selling) != 1 || tr.Selling[0] != "3214" || len(tr.ConsiderStocking) != 1 || tr.ConsiderStocking[0].Brand != "Medicube" {
		t.Fatalf("trending %+v", tr)
	}
	// the UI routes
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		e.ServeBrain(w, r, "act_1", modelInput)
	}))
	defer srv.Close()
	var c CycleOutput
	getJSON(t, srv.URL+"/api/brain/x/cycle", &c)
	if len(c.Verdicts) == 0 || c.Profile == nil {
		t.Fatalf("cycle extras %+v", c)
	}
	req, _ := http.NewRequest("PUT", srv.URL+"/api/brain/x/settings", strings.NewReader(`{"ad_wording":"strict"}`))
	if resp, err := http.DefaultClient.Do(req); err != nil || resp.StatusCode != 200 || e.settings("act_1").AdWording != Strict {
		t.Fatal("settings not saved")
	}
	var trj TrendingReport
	getJSON(t, srv.URL+"/api/brain/x/trending", &trj)
	if len(trj.Selling) != 1 {
		t.Fatalf("%+v", trj)
	}
}

func getJSON(t *testing.T, u string, v any) {
	t.Helper()
	resp, err := http.Get(u)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("%s: %v %v", u, err, resp)
	}
	_ = json.NewDecoder(resp.Body).Decode(v)
}

// Catalogue match: brand + distinctive name tokens; ingredient by mention.
// Mutation: lower the 0.6 name coverage to 0 -> "Heartleaf Pore Control Cleansing Oil" matches the toner item, red.
func TestMatchTrending(t *testing.T) {
	shop := ShopSnapshot{Products: []Product{
		{ID: "1", Title: "Anua - Heartleaf Pore Control Cleansing Oil 200ml", Brand: "Anua", InStock: true},
		{ID: "2", Title: "Beauty of Joseon - Glow Serum: Propolis + Niacinamide", Brand: "Beauty of Joseon", InStock: false},
	}}
	rep := TrendingReport{Items: []TrendingItem{
		{Rank: 1, Name: "Heartleaf 77% Soothing Toner", Brand: "Anua", Kind: "product"},
		{Rank: 2, Name: "Glow Serum Propolis Niacinamide", Brand: "Beauty of Joseon", Kind: "product"},
		{Rank: 3, Name: "Niacinamide", Kind: "ingredient"},
	}}
	MatchTrending(&rep, shop)
	if rep.Items[0].InShop || !rep.Items[1].InShop || rep.Items[1].InStock || !rep.Items[2].InShop {
		t.Fatalf("%+v", rep.Items)
	}
	if len(rep.Selling) != 0 || len(rep.ConsiderStocking) != 1 {
		t.Fatalf("selling %v consider %+v", rep.Selling, rep.ConsiderStocking)
	}
}
