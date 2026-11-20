package brain

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"sync"
	"testing"
)

// createFake answers the create schema with successive scripted answers and records the requests.
type createFake struct {
	mu   sync.Mutex
	ans  []string
	reqs []string
}

func (f *createFake) server() *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		f.mu.Lock()
		f.reqs = append(f.reqs, string(b))
		a := f.ans[0]
		if len(f.ans) > 1 {
			f.ans = f.ans[1:]
		}
		f.mu.Unlock()
		out, _ := json.Marshal(map[string]any{"id": "m", "stop_reason": "end_turn", "content": []any{map[string]any{"type": "text", "text": a}},
			"usage": map[string]any{"input_tokens": 100, "output_tokens": 50}})
		w.Write(out)
	}))
}

func ideaJSON(pid, headAR, headEN, palette string, scene string, arText string) string {
	return `{"product_ids":["` + pid + `"],"angle":"gentle morning cleanse","hook":"h","template":"glow","palette":[` + palette + `],"show_price":false,
"formats":["1:1","4:5","9:16"],"motion":true,"scene":"` + scene + `","sets":[
{"lang":"en","headline":"` + headEN + `","primary_text":"A gentle daily step from Korea.","sub":"Morning","bullets":["Low pH"],"badge":"COSRX","cta":"SHOP_NOW","hooks":["x"]},
{"lang":"ar","headline":"` + headAR + `","primary_text":"` + arText + `","sub":"الصباح","bullets":["حموضة منخفضة"],"badge":"COSRX","cta":"SHOP_NOW","hooks":["س"]}]}`
}

// The owner's follow-up "make the headline shorter" must move the headlines and NOTHING else, even when
// Claude's answer also drifts another field (here: the palette). Mutation: make mergeIdeas copy n wholesale and
// the palette check is red.
func TestCreateEditChangesOnlyWhatWasAsked(t *testing.T) {
	first := `{"said":"ok","changed":[],"ideas":[` + ideaJSON("3195", "صباحكِ يبدأ بلطف مع كوريا", "The gentlest start to your morning", "", "a sunlit marble shelf", "خطوة يومية لطيفة من كوريا.") + `]}`
	edit := `{"said":"shorter","changed":["headline"],"ideas":[` + ideaJSON("3195", "صباح لطيف", "Gentle mornings", `"#FF00AA"`, "", "خطوة يومية لطيفة من كوريا.") + `]}`
	f := &createFake{ans: []string{first, edit}}
	srv := f.server()
	defer srv.Close()
	b := newTestBrain(srv.URL)
	s1, err := b.Create(context.Background(), CreateRequest{Prompt: "ad for the cleanser"}, nil, shopFixture(), Research{}, nil, false, Balanced, nil)
	if err != nil {
		t.Fatal(err)
	}
	if len(s1.Designs) != 2 || s1.Designs[0].Lang != "ar" || s1.Designs[1].Lang != "en" {
		t.Fatalf("want AR then EN designs, got %+v", s1.Designs)
	}
	if s1.Ideas[0].Scene != "" {
		t.Fatal("a scene prompt was kept without the AI pictures key")
	}
	if s1.Designs[0].Price != "" {
		t.Fatal("show_price false still printed the price")
	}
	if !strings.Contains(f.reqs[0], `\"scene_generator_available\":false`) {
		t.Fatal("Claude was not told the scene generator is unavailable")
	}
	s2, err := b.Create(context.Background(), CreateRequest{SessionID: s1.ID, Edit: "make the headline shorter"}, &s1, shopFixture(), Research{}, nil, false, Balanced, nil)
	if err != nil {
		t.Fatal(err)
	}
	if s2.ID != s1.ID || len(s2.Turns) != 2 || !reflect.DeepEqual(s2.Turns[1].Changed, []string{"headline"}) {
		t.Fatalf("session %s turns %+v", s2.ID, s2.Turns)
	}
	if len(s2.Ideas[0].Palette) != 0 {
		t.Fatalf("palette changed on a headline-only edit: %v", s2.Ideas[0].Palette)
	}
	for i := range s1.Designs {
		a, z := s1.Designs[i], s2.Designs[i]
		if a.ID != z.ID || a.Headline == z.Headline {
			t.Fatalf("design %d: ids %s/%s headline %q -> %q", i, a.ID, z.ID, a.Headline, z.Headline)
		}
		a.Headline, z.Headline = "", ""
		if !reflect.DeepEqual(a, z) {
			t.Fatalf("design %d moved beyond the headline:\n%+v\n%+v", i, a, z)
		}
	}
	if !strings.Contains(f.reqs[1], "Follow-up") || !strings.Contains(f.reqs[1], "make the headline shorter") {
		t.Fatal("the follow-up did not carry the instruction")
	}
}

// Claude's copy goes through the policy linter: a blocked Arabic line is regenerated once, and a set still
// blocked never comes back. Unknown products are dropped. Mutation: skip fixIdeas and the "علاج" check is red.
func TestCreateLintsAndKeepsCatalogueOnly(t *testing.T) {
	bad := `{"said":"x","changed":[],"ideas":[` + ideaJSON("3195", "علاج فوري لبشرتكِ", "Morning", "", "", "علاج لبشرتكِ.") + `,` + ideaJSON("99999", "x", "y", "", "", "z") + `]}`
	f := &createFake{ans: []string{bad, bad}}
	srv := f.server()
	defer srv.Close()
	b := newTestBrain(srv.URL)
	s, err := b.Create(context.Background(), CreateRequest{Prompt: "cleanser"}, nil, shopFixture(), Research{}, nil, true, Balanced, nil)
	if err != nil {
		t.Fatal(err)
	}
	if len(f.reqs) != 2 || !strings.Contains(f.reqs[1], "policy linter blocked") {
		t.Fatalf("want one regeneration round with the findings, got %d calls", len(f.reqs))
	}
	if len(s.Ideas) != 1 {
		t.Fatalf("the unknown product survived: %+v", s.Ideas)
	}
	for _, d := range s.Designs {
		if strings.Contains(d.Headline+d.PrimaryText, "علاج") {
			t.Fatalf("blocked copy shipped: %q", d.Headline)
		}
	}
}

// Outcomes: a session whose creative ran with ROAS >= 2 is a winner, and winners lead what Claude is shown.
func TestCreateOutcomesFeedTheNextAsk(t *testing.T) {
	ss := []CreateSession{
		{ID: "s1", Ran: []string{"cr-a"}, Ideas: []CreateIdea{{Angle: "loser", Sets: []CreateSet{{Headline: "L"}}}}},
		{ID: "s2", Ran: []string{"cr-b"}, Ideas: []CreateIdea{{Angle: "winner", Sets: []CreateSet{{Headline: "W"}}}}},
		{ID: "s3"},
	}
	in := CycleInput{Tree: []Campaign{{AdSets: []AdSet{{Ads: []Ad{{ID: "ad1", CreativeID: "cr-a"}, {ID: "ad2", CreativeID: "cr-b"}}}}}},
		Metrics: []MetricRow{{Level: "ad", ObjectID: "ad1", SpendAED: 80}, {Level: "ad", ObjectID: "ad2", SpendAED: 50, Purchases: 2, RevenueAED: 200}, {Level: "ad", ObjectID: "ad2", SpendAED: 999, Settling: true}}}
	outcomes(ss, in)
	if ss[0].Outcome.Verdict != "lost" || ss[1].Outcome.Verdict != "won" || ss[1].Outcome.ROAS != 4 || ss[2].Outcome.Verdict != "not_run" {
		t.Fatalf("%+v %+v %+v", ss[0].Outcome, ss[1].Outcome, ss[2].Outcome)
	}
	p := pastForPrompt(ss)
	if len(p) != 2 || p[0]["angle"] != "winner" {
		t.Fatalf("past %v", p)
	}
}
