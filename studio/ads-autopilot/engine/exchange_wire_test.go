package main

import (
	"bytes"
	"context"
	"encoding/json"
	"image"
	"image/color"
	"image/png"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
	"time"

	"kaa/brain"
	"kaa/exchange"
	"kaa/model"
)

const ghTok = "github_pat_11TESTTESTTEST0123456789abcdefghijklmnopqrstuvwxyz"

// cloudApp: the mock app with the REAL brain (creation requests live there) and a fake GitHub holding the exchange.
func cloudApp(t *testing.T) (*Acct, *brain.Engine, *exchange.FakeGitHub, *server) {
	t.Helper()
	ac, _ := testApp(t)
	a := ac.app
	eb := brain.New(model.BrainDeps{LLM: a.claude, DataDir: t.TempDir(), Log: func(string, string, any) {}}).(*brain.Engine)
	a.mu.Lock()
	a.brain = eb
	a.mu.Unlock()
	f := exchange.NewFakeGitHub(ghTok)
	t.Cleanup(f.Close)
	old := githubAPI
	githubAPI = f.URL
	t.Cleanup(func() { githubAPI = old })
	f.Put(map[string][]byte{"README.md": []byte("exchange\n")})
	return ac, eb, f, &server{app: a}
}

func xcall(t *testing.T, s *server, method, path, body string) (int, map[string]any) {
	t.Helper()
	w := httptest.NewRecorder()
	r := httptest.NewRequest(method, path, strings.NewReader(body))
	s.exchangeAPI(w, r, path)
	var m map[string]any
	_ = json.Unmarshal(w.Body.Bytes(), &m)
	return w.Code, m
}

func connect(t *testing.T, s *server) {
	t.Helper()
	if code, m := xcall(t, s, "PUT", "/api/exchange", `{"owner":"rafiullahuae","repo":"kbb-ad-exchange","token":"`+ghTok+`"}`); code != 200 || m["connected"] != true {
		t.Fatalf("connect: %d %v", code, m)
	}
}

func stockProduct(t *testing.T, a *App) model.Product {
	for _, p := range a.shopSnap.Products {
		if p.InStock && p.Image != "" {
			return p
		}
	}
	t.Fatal("no in-stock product with a photo in the mock shop")
	return model.Product{}
}

func goodPNG(w, h int) []byte {
	im := image.NewRGBA(image.Rect(0, 0, w, h))
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			im.Set(x, y, color.RGBA{uint8(x * 255 / w), uint8(y * 255 / h), 120, 255})
		}
	}
	var b bytes.Buffer
	_ = png.Encode(&b, im)
	return b.Bytes()
}

func quality(ok bool, share float64) exchange.Quality {
	var q exchange.Quality
	q.OK, q.Score = ok, 88
	q.Checks.ProductShare, q.Checks.MinContrast, q.Checks.TextDensity = share, 6.2, 0.12
	q.Checks.Arabic = &struct {
		OK bool `json:"ok"`
	}{true}
	return q
}

// The whole round trip on a fake GitHub: a request is pushed once as a job (brief: catalogue facts, no key, no
// customer data), two workers race for it and only one gets it, the results come back and every render is re-checked
// on the Mac: the linter refuses the English set (a cure claim), the gate's numbers refuse a 4:5 whose product is too
// small, a 9:16 PNG of the wrong size is refused, the Arabic 1:1 and the MP4 are kept, once. MUTATIONS: drop the
// CloudSetProblems call in importJob → the English render is imported, red; drop CheckQuality → 4:5 imported, red;
// skip the Imported check in exchangeRun → second sync imports again (ids already exist, but feedback/counts move), red.
func TestCloudExchangeRoundTrip(t *testing.T) {
	ac, eb, f, s := cloudApp(t)
	a := ac.app
	connect(t, s)
	p := stockProduct(t, a)
	req := eb.AddCreationRequest(ac.id, ac.brainInput(), []string{p.ID}, "glass skin glow")
	if _, err := a.exchangeSync(context.Background(), "test"); err != nil {
		t.Fatal(err)
	}
	files := f.Files()
	dir := exchange.JobDir(ac.id, req.ID)
	var b exchange.Brief
	if err := json.Unmarshal(files[dir+"/brief.json"], &b); err != nil || len(b.Products) != 1 || b.Products[0].ID != p.ID || b.Products[0].Photo == "" || len(b.Linter.Rules) == 0 || len(b.DesignFamilies) != 10 || b.BrandColours["rose"] == "" {
		t.Fatalf("brief %v %+v", err, b)
	}
	if !strings.Contains(string(files[dir+"/status.json"]), `"queued"`) {
		t.Fatal("status not queued")
	}
	// no secrets, no customer data, anywhere in the exchange
	for path, c := range files {
		low := strings.ToLower(string(c))
		if strings.Contains(string(c), ghTok) || regexp.MustCompile(`[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}`).MatchString(low) || regexp.MustCompile(`\+?971\d{7,}`).MatchString(low) || strings.Contains(low, "order_id") || strings.Contains(low, "customer") || strings.Contains(low, "sk-ant-") || strings.Contains(low, "eaa") && strings.Contains(low, "token") {
			t.Fatalf("%s carries something it must not:\n%s", path, c)
		}
	}
	for _, h := range f.Bodies {
		if strings.Contains(h, ghTok) {
			t.Fatal("the key was sent in a body")
		}
	}
	// idempotent: a second sync pushes nothing
	n := len(f.Bodies)
	if sum, _ := a.exchangeSync(context.Background(), "test"); sum["pushed"] != 0 {
		t.Fatalf("pushed again: %v", sum)
	}
	for _, body := range f.Bodies[n:] {
		if strings.Contains(body, "brief") {
			t.Fatal("brief re-sent")
		}
	}
	// two workers race: one claim wins
	g1 := exchange.NewGitHub(f.URL, "rafiullahuae", "kbb-ad-exchange", ghTok)
	g2 := exchange.NewGitHub(f.URL, "rafiullahuae", "kbb-ad-exchange", ghTok)
	ok1, err1 := exchange.ClaimJob(g1, ac.id, req.ID, "cloud-a", time.Now())
	ok2, err2 := exchange.ClaimJob(g2, ac.id, req.ID, "cloud-b", time.Now())
	if err1 != nil || err2 != nil || !ok1 || ok2 {
		t.Fatalf("claims %v %v %v %v", ok1, err1, ok2, err2)
	}
	a.exchangeSync(context.Background(), "test")
	if r := findReq(eb, ac.id, req.ID); r.Cloud != "working" || r.CloudBy != "cloud-a" {
		t.Fatalf("cloud state %q by %q", r.Cloud, r.CloudBy)
	}
	// the cloud's results
	rd := exchange.ResultDir(ac.id, req.ID)
	ads := exchange.Ads{JobID: req.ID, Worker: "cloud-a", Ideas: []exchange.Idea{{ProductIDs: []string{p.ID}, Angle: "glass skin glow", Template: "glow", Formats: []string{"1:1", "4:5", "9:16"}, Motion: true,
		Sets: []exchange.Set{
			{Lang: "ar", Headline: "توهّج زجاجي كل صباح", PrimaryText: "قوام خفيف يمنح بشرتكِ إشراقة.\nمن كوريا إلى الإمارات.", Sub: "خلاصة الأرز", CTA: "SHOP_NOW"},
			{Lang: "en", Headline: "Cures acne in 3 days", PrimaryText: "This serum cures acne and heals your skin for good.", Sub: "Rice extract", CTA: "SHOP_NOW"},
		}}},
		Renders: []exchange.Render{
			{Idea: 1, Lang: "ar", Format: "1:1", Kind: "png", File: "i1-ar-1x1.png", Family: "glow", Quality: quality(true, 0.52)},
			{Idea: 1, Lang: "ar", Format: "4:5", Kind: "png", File: "i1-ar-4x5.png", Family: "glow", Quality: quality(true, 0.2)},
			{Idea: 1, Lang: "ar", Format: "9:16", Kind: "png", File: "i1-ar-9x16.png", Family: "glow", Quality: quality(true, 0.5)},
			{Idea: 1, Lang: "ar", Format: "9:16", Kind: "mp4", File: "i1-ar-motion.mp4", Family: "glow", Quality: quality(true, 0.5)},
			{Idea: 1, Lang: "en", Format: "1:1", Kind: "png", File: "i1-en-1x1.png", Family: "glow", Quality: quality(true, 0.52)},
		}}
	mp4 := append([]byte{0, 0, 0, 24, 'f', 't', 'y', 'p', 'i', 's', 'o', 'm'}, bytes.Repeat([]byte{1}, 4000)...)
	f.Put(map[string][]byte{
		rd + "/ads.json": exchange.MustJSON(ads), rd + "/i1-ar-1x1.png": goodPNG(1080, 1080), rd + "/i1-ar-4x5.png": goodPNG(1080, 1350),
		rd + "/i1-ar-9x16.png": goodPNG(1080, 1080), rd + "/i1-ar-motion.mp4": mp4, rd + "/i1-en-1x1.png": goodPNG(1080, 1080),
		rd + "/done.json": exchange.MustJSON(exchange.Done{JobID: req.ID, Worker: "cloud-a", Plan: "Claude Max", At: time.Now()}),
	})
	sum, err := a.exchangeSync(context.Background(), "test")
	if err != nil || sum["imported"] != 2 || sum["refused"] != 3 {
		t.Fatalf("import %v %v", sum, err)
	}
	var cloud []model.Creative
	for _, c := range ac.creatives.all() {
		if c.Origin == "cloud_max" {
			cloud = append(cloud, c)
		}
	}
	if len(cloud) != 2 || cloud[0].Lang != "ar" || cloud[1].Lang != "ar" {
		t.Fatalf("library %+v", cloud)
	}
	r := findReq(eb, ac.id, req.ID)
	if r.Status != "done" || r.Cloud != "imported" || r.CloudN != 2 || r.SessionID == "" {
		t.Fatalf("request %+v", r)
	}
	if s := eb.SessionByRequest(ac.id, req.ID); s == nil || s.Source != "cloud_max" || len(s.Ideas) != 1 || len(s.Ideas[0].Sets) != 1 || len(s.Designs) != 1 {
		t.Fatalf("session %+v", s)
	}
	fbb := f.Files()[exchange.FeedDir(ac.id, req.ID)+"/import.json"]
	var fb exchange.Feedback
	_ = json.Unmarshal(fbb, &fb)
	why := map[string]string{}
	for _, x := range fb.Rejected {
		why[x.File] = x.Why
	}
	if len(fb.Imported) != 2 || !strings.Contains(why["i1-en-1x1.png"], "wording") || !strings.Contains(why["i1-ar-4x5.png"], "product only 20%") || !strings.Contains(why["i1-ar-9x16.png"], "1080x1080") {
		t.Fatalf("feedback %s", fbb)
	}
	if len(ac.st.Recent(50, "alert")) == 0 || !strings.Contains(ac.st.Recent(50, "alert")[0].Summary, "cloud ad refused") {
		t.Fatal("refusal not in Activity")
	}
	// idempotent import; the owner removes one ad → the feedback says so
	if sum, _ := a.exchangeSync(context.Background(), "test"); sum["imported"] != 0 {
		t.Fatalf("imported twice: %v", sum)
	}
	if n := len(ac.creatives.all()); n != 3 { // the test app's own creative + 2
		t.Fatalf("%d creatives", n)
	}
	if c := a.exchangeView()["counts"].(map[string]int); c["finished"] != 1 {
		t.Fatalf("counts %v", c)
	}
}

func findReq(eb *brain.Engine, acct, id string) brain.CreationRequest {
	for _, r := range eb.CreationRequests(acct) {
		if r.ID == id {
			return r
		}
	}
	return brain.CreationRequest{}
}

// He makes it himself first (here: dismissed; done-by-a-local-session takes the same branch): the job is withdrawn,
// and results that still arrive are not imported. MUTATION: drop the withdraw case → status stays queued, red.
func TestCloudWithdrawnWhenMadeLocally(t *testing.T) {
	ac, eb, f, s := cloudApp(t)
	a := ac.app
	connect(t, s)
	p := stockProduct(t, a)
	req := eb.AddCreationRequest(ac.id, ac.brainInput(), []string{p.ID}, "hydration")
	a.exchangeSync(context.Background(), "test")
	w := httptest.NewRecorder()
	eb.ServeBrain(w, httptest.NewRequest("POST", "/api/brain/x/creation/"+req.ID+"/dismiss", nil), ac.id, ac.brainInput)
	if w.Code != 200 {
		t.Fatalf("dismiss %d %s", w.Code, w.Body)
	}
	if sum, _ := a.exchangeSync(context.Background(), "test"); sum["withdrawn"] != 1 {
		t.Fatalf("not withdrawn: %v", sum)
	}
	if !strings.Contains(string(f.Files()[exchange.JobDir(ac.id, req.ID)+"/status.json"]), "withdrawn") {
		t.Fatal("status")
	}
	if ok, _ := exchange.ClaimJob(exchange.NewGitHub(f.URL, "rafiullahuae", "kbb-ad-exchange", ghTok), ac.id, req.ID, "w", time.Now()); ok {
		t.Fatal("a withdrawn job was claimed")
	}
	rd := exchange.ResultDir(ac.id, req.ID)
	f.Put(map[string][]byte{rd + "/ads.json": []byte(`{"ideas":[]}`), rd + "/done.json": []byte(`{"worker":"late"}`)})
	if sum, _ := a.exchangeSync(context.Background(), "test"); sum["imported"] != 0 || sum["withdrawn"] != 0 {
		t.Fatalf("late results: %v", sum)
	}
	if !strings.Contains(string(f.Files()[exchange.FeedDir(ac.id, req.ID)+"/import.json"]), "closed here first") {
		t.Fatal("no ignored feedback")
	}
}

// The key: refused before it is kept when it reaches other private repositories, when the repo is public or when it
// is not a GitHub key; kept ONLY in the secrets store; sent only in the Authorization header. MUTATION: save the key
// before Check → the refused key is in secrets.json, red.
func TestCloudKeyOnlyInSecrets(t *testing.T) {
	ac, _, f, s := cloudApp(t)
	a := ac.app
	if code, m := xcall(t, s, "PUT", "/api/exchange", `{"owner":"rafiullahuae","repo":"kbb-ad-exchange","token":"sk-ant-api03-xxxxxxxxxxxxxxxxxxxxxxxxxxxx"}`); code != 400 || !strings.Contains(m["error"].(string), "github_pat_") {
		t.Fatalf("a Claude key was accepted: %d %v", code, m)
	}
	f.OtherRepos = []string{"rafiullahuae/kbbstore"}
	if code, m := xcall(t, s, "PUT", "/api/exchange", `{"owner":"rafiullahuae","repo":"kbb-ad-exchange","token":"`+ghTok+`"}`); code != 400 || !strings.Contains(m["error"].(string), "other private") {
		t.Fatalf("a key for every repo was accepted: %d %v", code, m)
	}
	f.OtherRepos, f.Public = nil, true
	if code, _ := xcall(t, s, "PUT", "/api/exchange", `{"owner":"rafiullahuae","repo":"kbb-ad-exchange","token":"`+ghTok+`"}`); code != 400 {
		t.Fatal("a public repo was accepted")
	}
	if v, _ := a.sec.Get("github_token"); v != "" {
		t.Fatal("a refused key was kept")
	}
	f.Public = false
	connect(t, s)
	time.Sleep(200 * time.Millisecond) // the sync the connect started
	a.xs().run.Lock()
	a.xs().run.Unlock()
	if v, _ := a.sec.Get("github_token"); v != ghTok {
		t.Fatal("not saved")
	}
	_, m := xcall(t, s, "GET", "/api/exchange", "")
	b, _ := json.Marshal(m)
	if strings.Contains(string(b), ghTok) || !strings.Contains(m["token_url"].(string), "contents=write") || !strings.Contains(m["token_url"].(string), "target_name=rafiullahuae") {
		t.Fatalf("view %s", b)
	}
	_ = filepath.Walk(a.st.Dir, func(p string, fi os.FileInfo, err error) error {
		if err != nil || fi.IsDir() || filepath.Base(p) == "secrets.json" {
			return nil
		}
		if c, _ := os.ReadFile(p); bytes.Contains(c, []byte(ghTok)) {
			t.Fatalf("the key is in %s", p)
		}
		return nil
	})
	for i, h := range f.Headers {
		if h != "" && h != "Bearer "+ghTok {
			t.Fatalf("header %d: %q", i, h)
		}
	}
	if code, _ := xcall(t, s, "DELETE", "/api/exchange", ""); code != 200 {
		t.Fatal("delete")
	}
	if v, _ := a.sec.Get("github_token"); v != "" {
		t.Fatal("not removed")
	}
	_ = http.StatusOK
}
