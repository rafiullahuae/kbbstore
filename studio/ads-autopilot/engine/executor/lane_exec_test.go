package executor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"kaa/model"
)

// upload_creative {format: video, video_path}: the owner's video goes to Meta straight from the data folder (once;
// the second ad on it reuses the Meta video id), with the cover frame the analysis saved. Before, a video had to be
// posted as base64 into the library. MUTATION: drop the ledger cache in ownerVideo → the second proposal uploads
// the file again and this goes red.
func TestOwnerVideoByPath(t *testing.T) {
	m, x, mc := rig(t)
	dir := t.TempDir()
	vid := append([]byte("\x00\x00\x00\x18ftypmp42"), make([]byte, 300000)...)
	_ = os.MkdirAll(filepath.Join(dir, "videos"), 0o700)
	_ = os.WriteFile(filepath.Join(dir, "videos", "v1.mp4"), vid, 0o600)
	_ = os.WriteFile(filepath.Join(dir, "videos", "v1.jpg"), []byte("\xff\xd8\xff\xe0 jpeg"), 0o600)
	x.VideoFile = func(rel string) (ReaderAtCloser, int64, error) {
		f, err := os.Open(filepath.Join(dir, filepath.FromSlash(rel)))
		if err != nil {
			return nil, 0, err
		}
		st, _ := f.Stat()
		return f, st.Size(), nil
	}
	env := liveEnv(t, mc)
	props := []model.Proposal{
		{ID: "v-a", Kind: model.KindUploadCreative, Target: "tmp:video-v1", Params: map[string]any{"format": "video", "video_path": "videos/v1.mp4", "primary_text": "روتين الصباح", "headline": "صباح منعش"}},
		{ID: "v-b", Kind: model.KindUploadCreative, Target: "tmp:video-v1b", Params: map[string]any{"format": "video", "video_path": "videos/v1.mp4", "primary_text": "Morning routine", "headline": "Morning"}},
	}
	res := x.Run(context.Background(), env, props)
	for _, r := range res {
		if r.Status != "done" {
			t.Fatalf("%s: %s %s %v", r.ProposalID, r.Status, r.Error, r.Decision)
		}
	}
	starts, uploaded := 0, 0
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "/video_ads") && w.Form["upload_phase"] == "start" {
			starts++
		}
		if strings.HasSuffix(w.Path, "/adcreatives") && strings.Contains(w.Form["object_story_spec"], `"video_id"`) && strings.Contains(w.Form["object_story_spec"], `"image_hash"`) {
			uploaded++
		}
	}
	if starts != 1 || uploaded != 2 {
		t.Fatalf("video uploads %d (want 1), video creatives with a cover %d (want 2)", starts, uploaded)
	}
	if x.Ledger.Resolve("tmp:video-v1") == "tmp:video-v1" {
		t.Fatal("the creative's tmp id was not mapped for create_ad")
	}
}

// A video_path outside videos/ never reaches the file system. MUTATION: drop the VideoPathRe check in ValidateParams
// → the proposal passes the checks, red.
func TestVideoPathRefused(t *testing.T) {
	_, x, mc := rig(t)
	opened := false
	x.VideoFile = func(string) (ReaderAtCloser, int64, error) { opened = true; return nil, 0, os.ErrNotExist }
	for _, p := range []string{"../secrets.json", "videos/../../x.mp4", "/etc/passwd", "videos/a b.mp4", "videos/x.exe"} {
		res := x.Run(context.Background(), liveEnv(t, mc), []model.Proposal{{ID: "v", Kind: model.KindUploadCreative, Target: "tmp:v",
			Params: map[string]any{"format": "video", "video_path": p, "primary_text": "x"}}})
		if res[0].Status != "refused" || opened {
			t.Fatalf("%s: %s (opened %v)", p, res[0].Status, opened)
		}
	}
}

// Activations in one batch were counted twice (the tree already shows the ad ACTIVE, and ActivatedNow counted it
// again): a launch of 4 ads with 1 running and max_active_ads 6 refused the 4th ad ("7 ads are active"). MUTATION:
// put env.ActivatedNow++ back in afterStatus → the 4th ad is refused, red.
func TestBatchActivationsCountedOnce(t *testing.T) {
	_, x, mc := rig(t)
	mem := x.Creatives.(*memCreatives)
	props := []model.Proposal{
		{ID: "a", Kind: model.KindCreateCampaign, Target: "tmp:c1", Params: map[string]any{"name": "Sales", "daily_budget": 120.0}},
		{ID: "b", Kind: model.KindCreateAdSet, Target: "tmp:s1", Params: map[string]any{"campaign_id": "tmp:c1", "countries": []any{"AE"}}},
	}
	for i, id := range []string{"cr-1", "cr-2", "cr-3", "cr-4"} {
		mem.m[id] = model.Creative{ID: id, Lang: "ar", Format: "1:1", Media: "png", Headline: "بشرة", PrimaryText: "روتين", Link: "https://kbeautybliss.com/p/"}
		props = append(props,
			model.Proposal{ID: "u" + id, Kind: model.KindUploadCreative, Target: id, Params: map[string]any{"creative_id": id}},
			model.Proposal{ID: "d" + id, Kind: model.KindCreateAd, Target: "tmp:a" + string(rune('1'+i)), Params: map[string]any{"adset_id": "tmp:s1", "creative_id": id}})
	}
	env := liveEnv(t, mc)
	env.Limits.MaxActiveAds = 5 // the owner's 1 + these 4
	for _, r := range x.Run(context.Background(), env, props) {
		if r.Status != "done" {
			t.Fatalf("%s %s: %s %v", r.Kind, r.ProposalID, r.Status, r.Decision.Reasons)
		}
	}
}
