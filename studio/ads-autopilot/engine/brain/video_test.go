package brain

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

func hasIssue(a VideoAnalysis, code, sev string) bool {
	for _, i := range a.Issues {
		if i.Code == code && (sev == "" || i.Severity == sev) {
			return true
		}
	}
	return false
}

// Local checks need no Claude: aspect, placements, duration, resolution.
func TestVideoLocalChecks(t *testing.T) {
	cases := []struct {
		w, h   int
		d      float64
		aspect string
		issue  string
	}{
		{1080, 1920, 15, "9:16", ""},
		{1080, 1350, 20, "4:5", ""},
		{1080, 1080, 45, "1:1", "long"},
		{1920, 1080, 20, "16:9", "aspect"},
		{540, 960, 75, "9:16", "too_long"},
	}
	for _, c := range cases {
		a, _, err := New("").AnalyzeVideo(context.Background(), VideoInput{ID: "v", Width: c.w, Height: c.h, DurationS: c.d, HasAudio: true}, ShopSnapshot{})
		if err != nil || a.Aspect != c.aspect || a.Model != "local-only" {
			t.Fatalf("%dx%d: %+v %v", c.w, c.h, a, err)
		}
		if c.issue != "" && !hasIssue(a, c.issue, "") {
			t.Fatalf("%dx%d %.0fs: want issue %s, got %+v", c.w, c.h, c.d, c.issue, a.Issues)
		}
	}
	a, _, _ := New("").AnalyzeVideo(context.Background(), VideoInput{ID: "v", Width: 540, Height: 960, DurationS: 12}, ShopSnapshot{})
	if !hasIssue(a, "low_resolution", "fix") || !hasIssue(a, "no_audio", "tip") {
		t.Fatalf("%+v", a.Issues)
	}
}

const visionGood = `{"hook":"Hand squeezes the COSRX gel onto fingertips in close-up","hook_strength":8,"language":"ar",
"on_screen_text":[{"text":"صباحكِ يبدأ بلطف","t":0.5,"y_top":0.40,"y_bottom":0.48},{"text":"COSRX","t":2,"y_top":0.92,"y_bottom":0.97}],
"captions":true,"products":[{"product_id":"3195","confidence":0.9,"first_seen_t":0.5},{"product_id":"999999","confidence":0.9,"first_seen_t":0}],
"brands_seen":["COSRX"],"angle":"routine","quality_issues":["slightly dark lighting"],"trims":[{"from_s":0,"to_s":1.2,"why":"slow start"},{"from_s":5,"to_s":99,"why":"past the end"}],
"suggestions":["Open on the gel texture"],"summary_en":"A clean routine clip.","summary_ar":"مقطع روتين أنيق.","negative_framing":false}`

// Vision answer merged with code checks; score computed in code.
// Mutation: drop the y_bottom > 0.65 safe-zone check -> safe_zone stays 10, red.
func TestVideoVisionMerge(t *testing.T) {
	f := &fakeClaude{}
	srv := f.server(t)
	defer srv.Close()
	f.planner = nil
	b := newTestBrain(srv.URL)
	in := VideoInput{ID: "v1", Name: "cosrx-morning.mp4", Width: 1080, Height: 1920, DurationS: 14, HasAudio: true,
		Frames: []VideoFrame{{T: 0, JPEG: "/9j/AAAA"}, {T: 1.5, JPEG: "data:image/jpeg;base64,/9j/BBBB"}}}
	vfake = visionGood
	a, u, err := b.AnalyzeVideo(context.Background(), in, shopFixture())
	if err != nil {
		t.Fatal(err)
	}
	if len(a.ProductsShown) != 1 || a.ProductsShown[0].ProductID != "3195" || a.FirstProductS != 0.5 {
		t.Fatalf("products %+v first %v", a.ProductsShown, a.FirstProductS)
	}
	if !hasIssue(a, "unsafe_text_zone", "fix") || a.ScoreParts["safe_zone"] != 0 {
		t.Fatalf("safe zone: %+v %v", a.Issues, a.ScoreParts)
	}
	if len(a.Trims) != 1 {
		t.Fatalf("trims %+v", a.Trims)
	}
	// hook 24 + aspect 15 + duration 15 + captions 10 + safe 0 + product 10 + arabic 10 = 84
	if a.Score != 84 {
		t.Fatalf("score %d parts %v", a.Score, a.ScoreParts)
	}
	if u.CostUSD <= 0 || a.Model != "claude-sonnet-5-5" {
		t.Fatal(u, a.Model)
	}
	// the request carried the frames as base64 JPEG images, prefix stripped
	content := f.reqs[0]["messages"].([]any)[0].(map[string]any)["content"].([]any)
	img := content[3].(map[string]any)["source"].(map[string]any)
	if img["media_type"] != "image/jpeg" || img["data"] != "/9j/BBBB" {
		t.Fatalf("image block %v", img)
	}
}

// On-screen "cures acne" and before/after framing block (balanced); relaxed
// downgrades before/after to a fix, the cure claim still blocks. Score capped at 30.
func TestVideoPolicy(t *testing.T) {
	var v visionAnswer
	_ = json.Unmarshal([]byte(strings.Replace(strings.Replace(visionGood, `"صباحكِ يبدأ بلطف"`, `"Cures acne fast"`, 1), `"angle":"routine"`, `"angle":"before_after_style"`, 1)), &v)
	in := VideoInput{Width: 1080, Height: 1920, DurationS: 14, HasAudio: true}
	a := localVideoChecks(in)
	finishVideo(&a, in, &v, shopFixture())
	if !hasIssue(a, "policy_medical_claim", "block") || !hasIssue(a, "negative_before_after", "block") || a.Score > 30 {
		t.Fatalf("%+v score %d", a.Issues, a.Score)
	}
	in.Wording = Relaxed
	a = localVideoChecks(in)
	finishVideo(&a, in, &v, shopFixture())
	if !hasIssue(a, "negative_before_after", "fix") || !hasIssue(a, "policy_medical_claim", "block") {
		t.Fatalf("relaxed %+v", a.Issues)
	}
}

// The planner may upload a runnable video, never a blocked or unknown one.
func TestPlannerVideoProposals(t *testing.T) {
	in := baseInput()
	in.Now = winnerInput().Now
	good := VideoAsset{ID: "v1", Path: "videos/v1.mp4", Analysis: &VideoAnalysis{Score: 80}}
	blocked := VideoAsset{ID: "v2", Analysis: &VideoAnalysis{Issues: []VideoIssue{{Code: "policy_medical_claim", Severity: "block"}}}}
	in.Videos = []VideoAsset{good, blocked}
	var a plannerAnswer
	_ = json.Unmarshal([]byte(`{"proposals":[
{"kind":"upload_creative","target":"new:video-v1","params":{"media":"video","video_id":"v1"},"why":"winner","confidence":0.8},
{"kind":"upload_creative","target":"new:video-v2","params":{"media":"video","video_id":"v2"},"why":"x","confidence":0.8},
{"kind":"create_ad","target":"new:ad-v1","params":{"adset_id":"s1","creative_id":"video:v1"},"why":"run it","confidence":0.8},
{"kind":"create_ad","target":"new:ad-v2","params":{"adset_id":"s1","creative_id":"video:v2"},"why":"run it","confidence":0.8}]}`), &a)
	b := New("")
	rr := Evaluate(in, tEcon, b.Rules)
	props, drop := b.validate(a, in, rr, knownObjects(in))
	if find(props, "upload_creative", "new:video-v1") == nil || find(props, "create_ad", "new:ad-v1") == nil {
		t.Fatalf("good video dropped: %v", drop)
	}
	if find(props, "upload_creative", "new:video-v2") != nil || find(props, "create_ad", "new:ad-v2") != nil || len(drop) != 2 {
		t.Fatalf("blocked video kept: %+v %v", props, drop)
	}
	if p := find(props, "upload_creative", "new:video-v1"); p.Params["path"] != "videos/v1.mp4" {
		t.Fatal(p.Params)
	}
}
