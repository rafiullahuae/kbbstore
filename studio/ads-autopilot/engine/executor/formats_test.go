package executor

import (
	"context"
	"strings"
	"testing"

	"kaa/metamock"
	"kaa/model"
)

type mediaCreatives struct{ m map[string]model.Creative }

func (c *mediaCreatives) Get(id string) (model.Creative, []byte, error) {
	cr, ok := c.m[id]
	if !ok {
		return cr, nil, context.Canceled
	}
	if cr.Media == "mp4" {
		return cr, []byte("\x00\x00\x00\x18ftypmp42fakevideo"), nil
	}
	return cr, []byte("\x89PNG\r\n\x1a\nfake"), nil
}
func (c *mediaCreatives) Put(cr model.Creative) error { c.m[cr.ID] = cr; return nil }

func formatsRig(t *testing.T) (*metamock.Server, *Executor, Env) {
	m, x, mc := rig(t)
	lib := &mediaCreatives{m: map[string]model.Creative{}}
	for _, id := range []string{"img-1", "img-2", "img-3"} {
		lib.m[id] = model.Creative{ID: id, Lang: "ar", Media: "png", Headline: "h " + id, PrimaryText: "روتين", Link: "https://kbeautybliss.com/p/" + id}
	}
	lib.m["vid-1"] = model.Creative{ID: "vid-1", Lang: "ar", Media: "mp4", Headline: "v", PrimaryText: "فيديو", Link: "https://kbeautybliss.com/p/v"}
	x.Creatives = lib
	e := liveEnv(t, mc)
	e.Limits.MaxActiveAds = 20
	return m, x, e
}

func form(m *metamock.Server, suffix string) []map[string]string {
	var out []map[string]string
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, suffix) && w.Status == 200 {
			out = append(out, w.Form)
		}
	}
	return out
}

// Every ad type end to end against the mock: each is checked, made PAUSED, and the Meta form carries the verified
// fields. MUTATION: drop child_attachments from the carousel builder → the carousel assertion goes red.
func TestEveryAdType(t *testing.T) {
	m, x, e := formatsRig(t)
	ctx := context.Background()
	props := []model.Proposal{
		{ID: "c", Kind: model.KindCreateCampaign, Target: "tmp:c", Params: map[string]any{"name": "All formats", "daily_budget": 100.0}},
		{ID: "wa", Kind: model.KindCreateAdSet, Target: "tmp:wa", Params: map[string]any{"campaign_id": "tmp:c", "countries": []any{"AE"}, "destination": "whatsapp",
			"age_min": 21.0, "age_range": []any{25.0, 44.0}, "genders": []any{2.0}, "locales": []any{28.0}, "placements": map[string]any{"publisher_platforms": []any{"instagram"}, "instagram_positions": []any{"reels", "story"}}}},
		{ID: "aud", Kind: model.KindCreateAudience, Target: "tmp:atc", Params: map[string]any{"name": "Add to cart 30d", "source": "website", "event": "AddToCart", "retention_days": 30.0}},
		{ID: "lal", Kind: model.KindCreateLookalike, Target: "tmp:lal", Params: map[string]any{"name": "LAL 1%", "origin_audience_id": "tmp:atc", "ratio": 0.01}},
		{ID: "rt", Kind: model.KindCreateAdSet, Target: "tmp:rt", Params: map[string]any{"campaign_id": "tmp:c", "countries": []any{"AE"}, "custom_audiences": []any{"tmp:atc"}, "advantage_audience": 0.0}},
		{ID: "car", Kind: model.KindUploadCreative, Target: "tmp:car", Params: map[string]any{"format": "carousel", "cards": []any{map[string]any{"creative_id": "img-1"}, map[string]any{"creative_id": "img-2", "headline": "كريم"}}}},
		{ID: "flex", Kind: model.KindUploadCreative, Target: "tmp:flex", Params: map[string]any{"format": "flexible", "creative_ids": []any{"img-1", "img-3", "vid-1"}, "texts": []any{"نص ١", "نص ٢"}, "headlines": []any{"عنوان"},
			"enhancements": map[string]any{"image_touchups": "OPT_IN", "text_translation": "OPT_OUT"}}},
		{ID: "vid", Kind: model.KindUploadCreative, Target: "tmp:vid", Params: map[string]any{"format": "video", "creative_id": "vid-1", "thumbnail_creative_id": "img-1", "destination": "whatsapp"}},
		{ID: "ps", Kind: model.KindCreateProductSet, Target: "tmp:ps", Params: map[string]any{"name": "COSRX", "filter": `{"brand":{"eq":"COSRX"}}`}},
		{ID: "cat", Kind: model.KindUploadCreative, Target: "tmp:cat", Params: map[string]any{"format": "catalog", "product_set_id": "tmp:ps", "template": map[string]any{"primary_text": "{{product.name}}", "link": "https://kbeautybliss.com/"}}},
		{ID: "ad1", Kind: model.KindCreateAd, Target: "tmp:ad1", Params: map[string]any{"adset_id": "tmp:wa", "creative_id": "tmp:vid"}},
		{ID: "ad2", Kind: model.KindCreateAd, Target: "tmp:ad2", Params: map[string]any{"adset_id": "tmp:rt", "creative_id": "tmp:car"}},
	}
	res := x.Run(ctx, e, props)
	for _, r := range res {
		if r.Status != "done" {
			t.Fatalf("%s %s: %s %v %s", r.ProposalID, r.Kind, r.Status, r.Decision.Reasons, r.Error)
		}
	}
	sets := form(m, "/adsets")
	if sets[0]["destination_type"] != "WHATSAPP" || sets[0]["optimization_goal"] != "CONVERSATIONS" || !strings.Contains(sets[0]["promoted_object"], `"whatsapp_phone_number":"+971500000000"`) {
		t.Fatalf("CTWA ad set %v", sets[0])
	}
	if !strings.Contains(sets[0]["targeting"], `"instagram_positions":["reels","story"]`) || !strings.Contains(sets[0]["targeting"], `"age_range":[25,44]`) || !strings.Contains(sets[0]["targeting"], `"locales":[28]`) {
		t.Fatalf("targeting %s", sets[0]["targeting"])
	}
	if !strings.Contains(sets[1]["targeting"], `"custom_audiences":[{"id":"`) || !strings.Contains(sets[1]["targeting"], `"advantage_audience":0`) {
		t.Fatalf("retargeting ad set %s", sets[1]["targeting"])
	}
	auds := form(m, "/customaudiences")
	if len(auds) != 2 || !strings.Contains(auds[0]["rule"], `"value":"AddToCart"`) || auds[1]["subtype"] != "LOOKALIKE" || strings.Contains(auds[1]["lookalike_spec"], "country") {
		t.Fatalf("audiences %v", auds)
	}
	crs := form(m, "/adcreatives")
	byName := map[string]map[string]string{}
	for _, c := range crs {
		byName[strings.Fields(c["name"])[1]] = c
	}
	if !strings.Contains(byName["carousel"]["object_story_spec"], "child_attachments") {
		t.Fatal("carousel without child_attachments")
	}
	if !strings.Contains(byName["flexible"]["asset_feed_spec"], `"bodies"`) || !strings.Contains(byName["flexible"]["degrees_of_freedom_spec"], `"image_touchups":{"enroll_status":"OPT_IN"}`) {
		t.Fatalf("flexible %v", byName["flexible"])
	}
	if !strings.Contains(byName["flexible"]["degrees_of_freedom_spec"], `"product_extensions":{"enroll_status":"OPT_OUT"}`) {
		t.Fatal("v27 product showcase not opted out")
	}
	if !strings.Contains(byName["video"]["object_story_spec"], `"app_destination":"WHATSAPP"`) || !strings.Contains(byName["video"]["object_story_spec"], `"video_id"`) {
		t.Fatalf("CTWA video %v", byName["video"]["object_story_spec"])
	}
	if !strings.Contains(byName["catalog"]["object_story_spec"], "template_data") || byName["catalog"]["product_set_id"] == "" {
		t.Fatalf("catalog creative %v", byName["catalog"])
	}
	if byName["video"]["instagram_user_id"] != "500000000001" {
		t.Fatal("instagram_user_id not sent")
	}
	// the video went through the resumable upload: start, bytes to rupload, finish
	var phases []string
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "/video_ads") {
			phases = append(phases, w.Form["upload_phase"])
		}
	}
	if strings.Join(phases, ",") != "start,finish" {
		t.Fatalf("video upload phases %v", phases)
	}
}

// Refusals with a reason the brain can read. MUTATION: drop the OPT_IN-on-optOut check → the translation row passes.
func TestAdTypeRefusals(t *testing.T) {
	_, _, e := formatsRig(t)
	noWA := e
	noWA.Links.WhatsAppNumber = ""
	cases := []struct {
		name string
		p    model.Proposal
		env  Env
		has  string
	}{
		{"translation never switched on", prop(model.KindUploadCreative, "tmp:x", map[string]any{"format": "image", "creative_id": "img-1", "enhancements": map[string]any{"text_translation": "OPT_IN"}}), e, "never switched on"},
		{"unknown enhancement", prop(model.KindUploadCreative, "tmp:x", map[string]any{"enhancements": map[string]any{"make_it_viral": "OPT_IN"}}), e, "not one the app knows"},
		{"collection without an Instant Experience", prop(model.KindUploadCreative, "tmp:x", map[string]any{"format": "collection", "creative_id": "img-1"}), e, "canvas_id"},
		{"carousel of one", prop(model.KindUploadCreative, "tmp:x", map[string]any{"format": "carousel", "cards": []any{map[string]any{"creative_id": "img-1"}}}), e, "2–10 cards"},
		{"frequency cap on a sales ad set", prop(model.KindCreateAdSet, "tmp:s", map[string]any{"campaign_id": "200", "countries": []any{"AE"}, "frequency_cap": map[string]any{"max": 2.0, "days": 7.0}}), e, "REACH"},
		{"WhatsApp without a number", prop(model.KindCreateAdSet, "tmp:s", map[string]any{"campaign_id": "200", "countries": []any{"AE"}, "destination": "whatsapp"}), noWA, "WhatsApp number"},
		{"age_min over 25 with Advantage+ audience", prop(model.KindCreateAdSet, "tmp:s", map[string]any{"campaign_id": "200", "countries": []any{"AE"}, "age_min": 30.0}), e, "18–25"},
		{"bad placement", prop(model.KindCreateAdSet, "tmp:s", map[string]any{"campaign_id": "200", "countries": []any{"AE"}, "placements": map[string]any{"instagram_positions": []any{"billboard"}}}), e, "billboard"},
		{"lookalike too wide", prop(model.KindCreateLookalike, "tmp:l", map[string]any{"name": "x", "origin_audience_id": "123456", "ratio": 0.2}), e, "0.01–0.10"},
		{"website audience 400 days", prop(model.KindCreateAudience, "tmp:a", map[string]any{"name": "x", "source": "website", "event": "Purchase", "retention_days": 400.0}), e, "1–180"},
		{"claim in a carousel card", prop(model.KindUploadCreative, "tmp:x", map[string]any{"format": "carousel", "cards": []any{map[string]any{"creative_id": "img-1", "headline": "Cures acne"}, map[string]any{"creative_id": "img-2"}}}), e, "cure"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			d := Check(c.p, c.env)
			if d.OK || !strings.Contains(strings.Join(d.Reasons, " "), c.has) {
				t.Fatalf("%v %v, want a refusal with %q", d.Stage, d.Reasons, c.has)
			}
		})
	}
}

// The all-accounts cap: this account fits its own limits, the portfolio does not.
func TestGlobalCap(t *testing.T) {
	e := env()
	e.MonthSpendAED = 0
	p := prop(model.KindCreateCampaign, "tmp:c", map[string]any{"daily_budget": 100.0})
	if d := Check(p, e); !d.OK {
		t.Fatalf("%v", d)
	}
	e.GlobalDailyAED, e.OtherCommitted = 400, 200 // 200 elsewhere + 150 here + 100 new = 450 > 400
	if d := Check(p, e); d.OK || !strings.Contains(d.Reasons[0], "all accounts") {
		t.Fatalf("%v", d)
	}
}
