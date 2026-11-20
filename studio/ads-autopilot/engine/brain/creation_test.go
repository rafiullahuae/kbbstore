package brain

import (
	"context"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// With the default creation mode the planner's copy requests become Creation requests (no API copy call), the
// owner is told once over SSE and by the Mac's notification, a second cycle does not duplicate the request, and
// Start opens Claude with the brief prefilled through the documented claude://claude.ai/new?q= link.
// Mutation: drop the dedupe in creationAfterCycle and the "one request" check is red.
func TestCreationRequestsNotifyOnceAndStart(t *testing.T) {
	dir := t.TempDir()
	stub := filepath.Join(dir, "notify.sh")
	log := filepath.Join(dir, "notified.txt")
	os.WriteFile(stub, []byte("#!/bin/sh\necho \"$1|$2\" >> "+log+"\n"), 0o700)
	t.Setenv("KAA_NOTIFY_CMD", stub)
	t.Setenv("KAA_OPEN_CMD", "true")
	var pushed []string
	e := &Engine{B: NewBrain(""), dir: dir, deps: model.BrainDeps{Publish: func(name string, v any) { pushed = append(pushed, name) }}}
	st := e.settings("act_1")
	if st.CreationMode != CreationWithMe || st.FallbackHours != 0 {
		t.Fatalf("defaults %+v: want with_me and the API fallback OFF", st)
	}
	in := CycleInput{Now: time.Date(2026, 11, 18, 10, 0, 0, 0, time.UTC), Shop: shopFixture()}
	out := CycleOutput{CreationNeeded: []CopyRequest{{ProductIDs: []string{"3195"}, Angle: "morning routine"}},
		Verdicts: []Verdict{{Level: "ad", Verdict: "fatigued"}, {Level: "ad", Verdict: "fatigued"}}}
	b := e.B.clone()
	b.DeferCopy = true
	e.creationAfterCycle(context.Background(), "act_1", b, in, &out, st)
	e.creationAfterCycle(context.Background(), "act_1", b, in, &out, st)
	reqs := e.creations("act_1")
	if len(reqs) != 1 || reqs[0].Status != "open" || reqs[0].Tiring != 2 || !strings.Contains(reqs[0].Title, "2 ads tiring") {
		t.Fatalf("requests %+v", reqs)
	}
	got, _ := os.ReadFile(log)
	if strings.Count(string(got), "New ads needed") != 1 || len(pushed) != 1 || pushed[0] != "creation" {
		t.Fatalf("notified %q pushed %v", got, pushed)
	}
	u := claudeURL(reqs[0].Brief)
	q, err := url.Parse(u)
	if err != nil || q.Scheme != "claude" || q.Host != "claude.ai" || q.Path != "/new" || !strings.Contains(q.Query().Get("q"), reqs[0].ID) {
		t.Fatalf("deep link %s", u)
	}
	r1, ok := e.markCreation("act_1", reqs[0].ID, "started", "")
	r2, _ := e.markCreation("act_1", reqs[0].ID, "started", "")
	if !ok || r1.Status != "started" || !r1.StartedAt.Equal(r2.StartedAt) {
		t.Fatalf("start not idempotent: %+v %+v", r1, r2)
	}
	e.markCreation("act_1", reqs[0].ID, "done", "s123")
	if c := e.creations("act_1")[0]; c.Status != "done" || c.SessionID != "s123" {
		t.Fatalf("done %+v", c)
	}
}

// The planner's ads that wait on deferred copy are not proposed (they would reference copy that was never written).
func TestDeferCopyDropsWaitingAds(t *testing.T) {
	f := &fakeClaude{planner: []string{goodPlan}, copyAns: []string{goodCopy}}
	srv := f.server(t)
	defer srv.Close()
	b := newTestBrain(srv.URL)
	b.DeferCopy = true
	out, err := b.Cycle(context.Background(), winnerInput())
	if err != nil {
		t.Fatal(err)
	}
	if len(out.CreationNeeded) == 0 || len(out.Designs) != 0 {
		t.Fatalf("creation needed %d designs %d", len(out.CreationNeeded), len(out.Designs))
	}
	for _, r := range f.reqs {
		if sys := r["system"].([]any)[0].(map[string]any)["text"].(string); strings.HasPrefix(sys, copySystem[:60]) {
			t.Fatal("copy was written through the API in with-me mode")
		}
	}
	for _, p := range out.Proposals {
		if id, _ := p.Params["creative_id"].(string); strings.HasPrefix(id, "copy:") {
			t.Fatalf("an ad waiting on deferred copy was proposed: %+v", p)
		}
	}
}

// Authored (Claude app, Max): no model call; a revision changes only the fields it carries.
func TestAuthorAndRevise(t *testing.T) {
	ideas := []CreateIdea{{ProductIDs: []string{"3195"}, Angle: "a", Template: "glow", Sets: []CreateSet{
		{Lang: "en", Headline: "A gentle start to the morning", PrimaryText: "Gentle daily step.", CTA: "SHOP_NOW"},
		{Lang: "ar", Headline: "صباحكِ يبدأ بلطف مع كوريا", PrimaryText: "خطوة يومية لطيفة.", CTA: "SHOP_NOW"}}}}
	s, err := Author(CreateRequest{Authored: ideas, Key: "k1"}, nil, shopFixture(), Balanced, false)
	if err != nil || s.Source != "claude_app" || len(s.Designs) != 2 || s.Designs[0].Lang != "ar" {
		t.Fatalf("%v %+v", err, s)
	}
	h := "Gentle mornings"
	hAR := "صباح لطيف"
	s2, err := Author(CreateRequest{Revise: &Revision{EN: &SetChange{Headline: &h}, AR: &SetChange{Headline: &hAR}}}, &s, shopFixture(), Balanced, false)
	if err != nil {
		t.Fatal(err)
	}
	if s2.Turns[1].Changed[0] != "headline" || len(s2.Turns[1].Changed) != 1 {
		t.Fatalf("changed %v", s2.Turns[1].Changed)
	}
	for i := range s.Designs {
		a, z := s.Designs[i], s2.Designs[i]
		if a.Headline == z.Headline {
			t.Fatal("headline unchanged")
		}
		a.Headline, z.Headline = "", ""
		if a.ID != z.ID || a.Template != z.Template || a.PrimaryText != z.PrimaryText || a.Price != z.Price {
			t.Fatalf("moved beyond the headline: %+v / %+v", a, z)
		}
	}
	if _, err := Author(CreateRequest{Authored: []CreateIdea{{ProductIDs: []string{"nope"}}}}, nil, shopFixture(), Balanced, false); err == nil {
		t.Fatal("an unknown product was accepted")
	}
}
