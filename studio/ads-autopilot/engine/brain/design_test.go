package brain

// Design pipeline: local cut-out model downloads, AI generators (cost first,
// budget enforced, keys never echoed, the product never drawn), art direction.

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"crypto/sha512"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

func testEngine(t *testing.T) *Engine {
	t.Setenv("KAA_SECRETS_FILE", "1")
	genS.mu.Lock()
	genS.sec, genS.jobs = nil, map[string]*genJob{}
	genS.mu.Unlock()
	return New(model.BrainDeps{LLM: &fakeLLM{}, DataDir: t.TempDir()}).(*Engine)
}

func do(t *testing.T, e *Engine, method, path string, body any) (int, map[string]any) {
	t.Helper()
	var rd io.Reader
	if b, ok := body.([]byte); ok {
		rd = bytes.NewReader(b)
	} else if body != nil {
		j, _ := json.Marshal(body)
		rd = bytes.NewReader(j)
	}
	r := httptest.NewRequest(method, "/api/brain/x/"+path, rd)
	w := httptest.NewRecorder()
	e.ServeBrain(w, r, "act_1", func() model.CycleInput { return model.CycleInput{} })
	out := map[string]any{}
	_ = json.Unmarshal(w.Body.Bytes(), &out)
	return w.Code, out
}

// Segmentation assets install only when every byte matches the pinned hash.
// Mutation: drop the SHA check in fetchAsset and "tampered" installs.
func TestSegDownloadVerifiesHashes(t *testing.T) {
	mdl := []byte("pretend onnx model bytes")
	wasm := []byte("\x00asm pretend runtime")
	var tgz bytes.Buffer
	gz := gzip.NewWriter(&tgz)
	tw := tar.NewWriter(gz)
	_ = tw.WriteHeader(&tar.Header{Name: "package/dist/ort-wasm-simd-threaded.asyncify.wasm", Mode: 0o644, Size: int64(len(wasm)), Typeflag: tar.TypeReg})
	_, _ = tw.Write(wasm)
	tw.Close()
	gz.Close()
	serveModel := mdl
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, ".tgz") {
			_, _ = w.Write(tgz.Bytes())
			return
		}
		_, _ = w.Write(serveModel)
	}))
	defer srv.Close()
	oldBase, oldAssets := segBase, segAssets
	defer func() { segBase, segAssets = oldBase, oldAssets }()
	segBase = map[string]string{"npm": srv.URL, "gh": srv.URL, "hf": srv.URL}
	h := sha256.Sum256(mdl)
	hw := sha256.Sum256(wasm)
	ht := sha512.Sum512(tgz.Bytes())
	segAssets = []SegAsset{
		{ID: segRuntime, URL: "npm:/x/-/ort.tgz", Member: "package/dist/ort-wasm-simd-threaded.asyncify.wasm", TarSHA512: hex.EncodeToString(ht[:]), TarBytes: int64(tgz.Len()), SHA256: hex.EncodeToString(hw[:]), Bytes: int64(len(wasm))},
		{ID: "isnet", URL: "gh:/m.onnx", SHA256: hex.EncodeToString(h[:]), Bytes: int64(len(mdl))},
	}
	e := testEngine(t)
	if err := e.fetchAsset(&segAssets[0], func(int64, int64) {}); err != nil {
		t.Fatalf("runtime: %v", err)
	}
	if err := e.fetchAsset(&segAssets[1], func(int64, int64) {}); err != nil {
		t.Fatalf("model: %v", err)
	}
	code, st := do(t, e, "GET", "seg/status", nil)
	if code != 200 || st["have"].(map[string]any)["isnet"] != true {
		t.Fatalf("status %d %v", code, st)
	}
	// served same-origin with the right type
	r := httptest.NewRequest("GET", "/api/brain/x/seg/file/ort-wasm", nil)
	w := httptest.NewRecorder()
	e.ServeBrain(w, r, "act_1", func() model.CycleInput { return model.CycleInput{} })
	if w.Code != 200 || w.Header().Get("content-type") != "application/wasm" || !bytes.Equal(w.Body.Bytes(), wasm) {
		t.Fatalf("file %d %q", w.Code, w.Header().Get("content-type"))
	}
	// a tampered download is refused and leaves nothing behind
	os.Remove(e.assetPath(&segAssets[1]))
	serveModel = []byte("pretend onnx model byteZ")
	if err := e.fetchAsset(&segAssets[1], func(int64, int64) {}); err == nil || !strings.Contains(err.Error(), "SHA-256") {
		t.Fatalf("tampered model installed: %v", err)
	}
	if e.haveAsset(&segAssets[1]) {
		t.Fatal("tampered file present under its final name")
	}
	if _, err := os.Stat(e.assetPath(&segAssets[1]) + ".part"); err == nil {
		t.Fatal("partial file left behind")
	}
}

func TestSegMaskCache(t *testing.T) {
	e := testEngine(t)
	png := append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{1}, 100)...)
	if c, _ := do(t, e, "PUT", "seg/mask?key=../../etc", png); c != 400 {
		t.Fatalf("path traversal key accepted: %d", c)
	}
	if c, _ := do(t, e, "PUT", "seg/mask?key=0123456789abcdef", []byte("not a png")); c != 400 {
		t.Fatalf("non-PNG accepted: %d", c)
	}
	if c, _ := do(t, e, "PUT", "seg/mask?key=0123456789abcdef", png); c != 200 {
		t.Fatalf("put %d", c)
	}
	r := httptest.NewRequest("GET", "/api/brain/x/seg/mask?key=0123456789abcdef", nil)
	w := httptest.NewRecorder()
	e.ServeBrain(w, r, "act_1", func() model.CycleInput { return model.CycleInput{} })
	if w.Code != 200 || !bytes.Equal(w.Body.Bytes(), png) {
		t.Fatalf("get %d", w.Code)
	}
}

// Every pinned asset is a permissive licence and has a full SHA-256.
func TestSegAssetsPinned(t *testing.T) {
	for _, a := range segAssets {
		if len(a.SHA256) != 64 || a.Bytes <= 0 || !(a.Licence == "MIT" || a.Licence == "Apache-2.0") {
			t.Fatalf("asset %s not pinned / not permissive: %+v", a.ID, a)
		}
		if strings.Contains(strings.ToLower(a.URL), "rmbg") {
			t.Fatalf("%s: RMBG is non-commercial", a.ID)
		}
	}
	for _, m := range segModels {
		if segAsset(m.Asset) == nil {
			t.Fatalf("model %s has no asset", m.ID)
		}
	}
}

// fake provider APIs: they check the request and answer like the real ones.
func fakeProviders(t *testing.T, png []byte, mp4 []byte, seen *[]string) *httptest.Server {
	var srv *httptest.Server
	srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		*seen = append(*seen, r.Method+" "+r.URL.Path+" "+string(b))
		switch {
		case r.URL.Path == "/v1/images/generations":
			if r.Header.Get("Authorization") != "Bearer sk-test-1234" {
				w.WriteHeader(401)
				_, _ = w.Write([]byte(`{"error":"bad key sk-test-1234"}`))
				return
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"data": []any{map[string]string{"b64_json": base64.StdEncoding.EncodeToString(png)}}})
		case strings.HasSuffix(r.URL.Path, ":predictLongRunning"):
			_, _ = w.Write([]byte(`{"name":"operations/op1"}`))
		case r.URL.Path == "/v1beta/operations/op1":
			_ = json.NewEncoder(w).Encode(map[string]any{"done": true, "response": map[string]any{"generateVideoResponse": map[string]any{"generatedSamples": []any{map[string]any{"video": map[string]string{"uri": srv.URL + "/files/v.mp4"}}}}}})
		case r.URL.Path == "/files/v.mp4":
			_, _ = w.Write(mp4)
		default:
			w.WriteHeader(404)
		}
	}))
	return srv
}

func TestGeneratorsCostFirstBudgetAndKeys(t *testing.T) {
	png := append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{7}, 200)...)
	mp4 := append([]byte{0, 0, 0, 0x18, 'f', 't', 'y', 'p', 'i', 's', 'o', 'm'}, bytes.Repeat([]byte{1}, 200)...)
	var seen []string
	srv := fakeProviders(t, png, mp4, &seen)
	defer srv.Close()
	old := genBase
	defer func() { genBase = old }()
	genBase = map[string]string{"openai": srv.URL, "gemini": srv.URL}
	oldPoll := genPoll
	genPoll = 10 * time.Millisecond
	defer func() { genPoll = oldPoll }()
	e := testEngine(t)

	// no key: estimate says why, generation refused (402), nothing charged
	_, est := do(t, e, "POST", "gen/estimate", map[string]any{"provider": "openai"})
	if est["ok"] != false || est["key_set"] != false || est["usd"].(float64) != 0.2 {
		t.Fatalf("estimate without key %v", est)
	}
	if c, _ := do(t, e, "POST", "gen/image", map[string]any{"provider": "openai", "prompt": "x", "confirm_usd": 1}); c != 402 {
		t.Fatalf("no-key generation: %d", c)
	}
	// key saved; the settings view never returns it
	c, st := do(t, e, "PUT", "gen/keys", map[string]any{"provider": "openai", "key": "sk-test-1234"})
	b, _ := json.Marshal(st)
	if c != 200 || strings.Contains(string(b), "sk-test-1234") || !strings.Contains(string(b), `"last4":"1234"`) {
		t.Fatalf("key view %d %s", c, b)
	}
	// unconfirmed price -> 409
	if c, out := do(t, e, "POST", "gen/image", map[string]any{"provider": "openai", "prompt": "x", "format": "4:5", "confirm_usd": 0.1}); c != 409 {
		t.Fatalf("unconfirmed: %d %v", c, out)
	}
	// confirmed -> image saved, served, charged
	c, f := do(t, e, "POST", "gen/image", map[string]any{"provider": "openai", "prompt": "empty set", "format": "4:5", "confirm_usd": 0.2})
	if c != 200 || f["mime"] != "image/png" || !strings.HasPrefix(f["url"].(string), "/api/brain/x/gen/file/g") {
		t.Fatalf("generate %d %v", c, f)
	}
	if !strings.Contains(seen[len(seen)-1], `"size":"1024x1536"`) {
		t.Fatalf("4:5 should ask for a portrait size: %s", seen[len(seen)-1])
	}
	r := httptest.NewRequest("GET", f["url"].(string), nil)
	w := httptest.NewRecorder()
	e.ServeBrain(w, r, "act_1", func() model.CycleInput { return model.CycleInput{} })
	if !bytes.Equal(w.Body.Bytes(), png) {
		t.Fatal("generated file not served back")
	}
	if s := e.spentThisMonth(time.Now()); s != 0.2 {
		t.Fatalf("spent %v", s)
	}
	// budget: 0.30 -> the second $0.20 image would pass it
	do(t, e, "PUT", "gen/settings", map[string]any{"budget_usd": 0.3})
	if c, out := do(t, e, "POST", "gen/image", map[string]any{"provider": "openai", "prompt": "x", "confirm_usd": 0.2}); c != 402 || !strings.Contains(out["error"].(string), "budget") {
		t.Fatalf("over budget allowed: %d %v", c, out)
	}
	// a provider error never echoes the key
	do(t, e, "PUT", "gen/settings", map[string]any{"budget_usd": 50})
	do(t, e, "PUT", "gen/keys", map[string]any{"provider": "openai", "key": "sk-wrong-9999"})
	_, out := do(t, e, "POST", "gen/image", map[string]any{"provider": "openai", "prompt": "x", "confirm_usd": 0.2})
	if strings.Contains(out["error"].(string), "sk-") && strings.Contains(out["error"].(string), "9999") {
		t.Fatalf("key leaked in error: %v", out)
	}
	if s := e.spentThisMonth(time.Now()); s != 0.2 {
		t.Fatalf("a refused call was charged: %v", s)
	}

	// video (Veo, long-running) from the generated still
	do(t, e, "PUT", "gen/keys", map[string]any{"provider": "veo", "key": "AIza-test-5678"})
	c, j := do(t, e, "POST", "gen/video", map[string]any{"provider": "veo", "prompt": "slow push-in", "format": "9:16", "seconds": 8, "image_id": f["id"], "confirm_usd": 1.2})
	if c != 200 || j["state"] != "running" {
		t.Fatalf("video start %d %v", c, j)
	}
	var job map[string]any
	for i := 0; i < 200; i++ {
		_, job = do(t, e, "GET", "gen/jobs/"+j["id"].(string), nil)
		if job["state"] != "running" {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if job["state"] != "done" || job["file"].(map[string]any)["mime"] != "video/mp4" {
		t.Fatalf("video job %v", job)
	}
	sent := strings.Join(seen, "\n")
	if !strings.Contains(sent, `"aspectRatio":"9:16"`) || !strings.Contains(sent, "bytesBase64Encoded") {
		t.Fatalf("veo request: %s", sent)
	}
	if s := e.spentThisMonth(time.Now()); s != 1.4 {
		t.Fatalf("spent after video %v", s)
	}
}

// The brain's prompts never ask for the product, always for empty space, and
// keep any person modest. Mutation: drop the "no product" sentence -> red.
func TestScenePromptRules(t *testing.T) {
	for _, fam := range templateIDs {
		for _, f := range []string{"1:1", "4:5", "9:16"} {
			for _, people := range []string{"none", "hands", "modest"} {
				p := ScenePrompt(SceneRequest{Family: fam, Product: "Anua Heartleaf 77% Soothing Toner", Format: f, People: people, Palette: []string{"#A8D5BA", "javascript:x", "#C6395F"}})
				for _, must := range []string{"no product", "no packaging", "no text", "no logo"} {
					if !strings.Contains(p, must) {
						t.Fatalf("%s/%s: prompt lacks %q: %s", fam, f, must, p)
					}
				}
				if strings.Contains(p, "javascript") {
					t.Fatal("palette entry not a hex colour leaked into the prompt")
				}
				if people == "modest" && !strings.Contains(p, "modestly styled") {
					t.Fatal("person not modestly styled")
				}
				if f == "9:16" && !strings.Contains(p, "top 14%") {
					t.Fatal("9:16 prompt ignores the Reels safe bands")
				}
			}
		}
	}
	if !strings.Contains(ScenePrompt(SceneRequest{Product: "Isntree Watery Sun Gel"}), "sunlit") {
		t.Fatal("product cue missing")
	}
}

func TestFamilyFor(t *testing.T) {
	cases := map[string]string{"gentle morning routine": "routine", "calming heartleaf ingredient": "ingredient", "glass-skin glow": "glow", "Eid gift": "offer", "summer-proof lightweight SPF texture": "texture"}
	for a, want := range cases {
		if got := familyFor(a, 1, 0); got != want {
			t.Fatalf("%q -> %s, want %s", a, got, want)
		}
	}
	if familyFor("anything", 2, 0) != "collection" || familyFor("x", 3, 0) != "routine" {
		t.Fatal("multi-product families")
	}
	seen := map[string]bool{}
	for h := uint32(0); h < 8; h++ {
		seen[familyFor("best K-beauty pick", 1, h)] = true
	}
	if len(seen) < 3 {
		t.Fatal("default angle always gets the same family")
	}
	for _, f := range []string{"hero", "glow", "ingredient", "offer", "routine", "editorial", "petals", "texture", "qa", "collection"} {
		if oneOf(f, templateIDs, "") == "" {
			t.Fatalf("%s not an allowed template", f)
		}
	}
}
