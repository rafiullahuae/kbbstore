package executor

import (
	"context"
	"fmt"
	"strings"
	"testing"
	"time"

	"kaa/meta"
	"kaa/metamock"
	"kaa/model"
)

type memCreatives struct{ m map[string]model.Creative }

func (c *memCreatives) Get(id string) (model.Creative, []byte, error) {
	cr, ok := c.m[id]
	if !ok {
		return cr, nil, fmt.Errorf("no %s", id)
	}
	return cr, []byte("\x89PNG\r\n\x1a\nfake"), nil
}
func (c *memCreatives) Put(cr model.Creative) error { c.m[cr.ID] = cr; return nil }

func rig(t *testing.T) (*metamock.Server, *Executor, *meta.Client) {
	t.Helper()
	m := metamock.New("EAATESTTOKEN00000000000000", "act_1000000001")
	m.Seed()
	u, err := m.Start()
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(m.Close)
	mc := meta.New(func() string { return m.Token })
	mc.Base = u
	meta.RuploadBase = u + "/video-ads-upload"
	mc.Sleep = func(context.Context, time.Duration) error { return nil }
	x := &Executor{Meta: mc, Act: func() string { return m.Account }, Links: func() model.AccountLinks { return testLinks },
		Ledger: NewLedger(), Creatives: &memCreatives{m: map[string]model.Creative{"cr-ar-1": {ID: "cr-ar-1", Lang: "ar", Format: "4:5", Media: "png", Headline: "بشرة", PrimaryText: "روتين", Link: "https://kbeautybliss.com/p/"}}}}
	return m, x, mc
}

func launchBatch() []model.Proposal {
	return []model.Proposal{
		{ID: "a", Kind: model.KindCreateCampaign, Target: "tmp:c1", Params: map[string]any{"name": "Sales", "daily_budget": 120.5}},
		{ID: "b", Kind: model.KindCreateAdSet, Target: "tmp:s1", Params: map[string]any{"campaign_id": "tmp:c1", "countries": []any{"AE"}}},
		{ID: "c", Kind: model.KindUploadCreative, Target: "cr-ar-1", Params: map[string]any{"creative_id": "cr-ar-1"}},
		{ID: "d", Kind: model.KindCreateAd, Target: "tmp:a1", Params: map[string]any{"adset_id": "tmp:s1", "creative_id": "cr-ar-1"}},
	}
}

func liveEnv(t *testing.T, mc *meta.Client) Env {
	tr, err := mc.Tree(context.Background(), "act_1000000001")
	if err != nil {
		t.Fatal(err)
	}
	e := env()
	e.Tree, e.MonthSpendAED = tr, 0
	return e
}

// The launch path: PAUSED creates (fils in the form), then PAUSED→ACTIVE as separate updates, campaign first.
// MUTATION: create with status ACTIVE in apply() → the "created PAUSED" assertion goes red.
func TestLaunchCreatesPausedThenActivates(t *testing.T) {
	m, x, mc := rig(t)
	res := x.Run(context.Background(), liveEnv(t, mc), launchBatch())
	for _, r := range res {
		if r.Status != "done" {
			t.Fatalf("%s: %s %v %s", r.Kind, r.Status, r.Decision.Reasons, r.Error)
		}
	}
	var creates, activations []metamock.Write
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "/campaigns") || strings.HasSuffix(w.Path, "/adsets") || strings.HasSuffix(w.Path, "/ads") {
			creates = append(creates, w)
			if w.Form["status"] != "PAUSED" {
				t.Fatalf("created %s with status %s", w.Path, w.Form["status"])
			}
			if w.Idem == "" {
				t.Fatalf("create %s sent without an idempotency key", w.Path)
			}
		}
		if w.Form["status"] == "ACTIVE" {
			activations = append(activations, w)
		}
	}
	if len(creates) != 3 || len(activations) != 3 {
		t.Fatalf("creates %d activations %d, want 3 and 3", len(creates), len(activations))
	}
	if creates[0].Form["daily_budget"] != "12050" {
		t.Fatalf("120.5 AED went out as %q, want 12050 fils", creates[0].Form["daily_budget"])
	}
	if creates[0].Form["objective"] != "OUTCOME_SALES" || !strings.HasPrefix(creates[0].Form["name"], "KAA: ") {
		t.Fatalf("campaign form %v", creates[0].Form)
	}
	if !strings.Contains(creates[1].Form["targeting"], `"advantage_audience":1`) || !strings.Contains(creates[1].Form["promoted_object"], "PURCHASE") {
		t.Fatalf("ad set form %v", creates[1].Form)
	}
}

// A retried batch (the cycle ran twice, the app restarted mid-way) does nothing twice.
// MUTATION: skip the Ledger.Lookup in one() → the second run creates a second campaign.
func TestReplayIsIdempotent(t *testing.T) {
	m, x, mc := rig(t)
	x.Run(context.Background(), liveEnv(t, mc), launchBatch())
	n := len(m.Writes())
	res := x.Run(context.Background(), liveEnv(t, mc), launchBatch())
	for _, r := range res {
		if r.Status != "duplicate" {
			t.Fatalf("replay of %s: %s", r.Kind, r.Status)
		}
	}
	if len(m.Writes()) != n {
		t.Fatalf("replay wrote %d more times", len(m.Writes())-n)
	}
	if c := len(m.Objects("campaigns")); c != 2 {
		t.Fatalf("%d campaigns, want 2 (owner's + one)", c)
	}
}

// Meta drops the connection after making the campaign: the client retries with the SAME key and Meta answers with the
// first campaign. MUTATION: drop the X-Client-Idempotent-Key header in client.do → two campaigns.
func TestNetworkRetryNeverDoubleCreates(t *testing.T) {
	m, x, mc := rig(t)
	m.InjectError(metamock.Inject{Method: "POST", Path: "/campaigns", Code: 1, Status: 500, Transient: true, AfterWrite: true, Message: "An unknown error occurred"})
	// the first attempt MADE the campaign and lost the answer; the retry carries the same key and gets that campaign
	res := x.Run(context.Background(), liveEnv(t, mc), launchBatch()[:1])
	if res[0].Status != "done" {
		t.Fatalf("%+v", res[0])
	}
	var keys []string
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "/campaigns") {
			keys = append(keys, w.Idem)
		}
	}
	if len(keys) != 2 || keys[0] != keys[1] {
		t.Fatalf("attempts %v: want 2 with the same key", keys)
	}
	if c := len(m.Objects("campaigns")); c != 2 {
		t.Fatalf("%d campaigns, want 2", c)
	}
}

// Shadow mode writes nothing at all. MUTATION: remove the shadow stage in Check → writes appear.
func TestShadowWritesNothing(t *testing.T) {
	m, x, mc := rig(t)
	e := liveEnv(t, mc)
	e.Limits.Autopilot = model.AutopilotShadow
	res := x.Run(context.Background(), e, launchBatch())
	for _, r := range res {
		if r.Status != "shadow" && r.Status != "refused" {
			t.Fatalf("%s: %s", r.Kind, r.Status)
		}
	}
	if len(m.Writes()) != 0 {
		t.Fatalf("shadow mode wrote %d times", len(m.Writes()))
	}
}

// The activation step is checked on its own: a full ad count leaves the new ad PAUSED.
func TestActivationIsChecked(t *testing.T) {
	m, x, mc := rig(t)
	e := liveEnv(t, mc)
	e.Limits.MaxActiveAds = 2 // the owner's ad is active: one slot left, then the create_ad check needs +1 → 2 ok
	res := x.Run(context.Background(), e, launchBatch())
	if res[3].Status != "done" {
		t.Fatalf("%+v", res[3])
	}
	e2 := liveEnv(t, mc)
	e2.Limits.MaxActiveAds = 2
	more := []model.Proposal{{ID: "z", Kind: model.KindCreateAd, Target: "tmp:a2", Params: map[string]any{"adset_id": res[1].MetaID, "creative_id": "cr-ar-1"}}}
	r := x.Run(context.Background(), e2, more)
	if r[0].Status != "refused" {
		t.Fatalf("a third active ad was allowed: %+v", r[0])
	}
	_ = m
}
