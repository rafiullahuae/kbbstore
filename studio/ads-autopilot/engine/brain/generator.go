package brain

// AI generation providers (the owner: "i need the high end technologies, i
// don't need any limitations, get the models if any u want to").
//
// What they make: BACKGROUND SCENES ONLY -- lifestyle sets, light, water,
// petals, GCC interiors, and short moving versions of them. The REAL product
// photo is always cut out and composited on top in the browser; no model ever
// draws the packaging (no fake or misleading product depiction, and the label
// stays legible and true). Every prompt the brain writes says "no product, no
// packaging, no text, no logos" and leaves room where the product will sit.
//
// Keys: Settings -> Connections -> Generators, stored through the engine's
// secrets package (macOS Keychain, service "KBB Ads Autopilot"; names
// gen_<provider>). The browser only ever sees {set,last4}. Cost is shown before
// anything is generated (POST gen/estimate), every generation must carry the
// cost the owner confirmed, and a monthly budget (USD) is enforced here, in the
// engine, against an append-only ledger. With no key everything still works:
// the procedural designs need none.
//
// Routes under /api/brain/x/gen/:
//
//	GET  gen/settings                 providers (+ key set/last4, price, terms), budget, spent this month
//	PUT  gen/settings                 {budget_usd, image, video, models{provider:model}}
//	PUT  gen/keys                     {provider, key}  ("" deletes)
//	POST gen/prompt                   {spec, format, setting, people} -> the brain's art-direction prompt
//	POST gen/estimate                 {kind, provider, format, seconds, n} -> {usd, spent, budget, ok}
//	POST gen/image                    {provider, prompt, format, confirm_usd} -> {id, url, usd}
//	POST gen/video                    {provider, prompt, format, seconds, image_id, confirm_usd} -> job
//	GET  gen/jobs/{id}                a video job's state
//	GET  gen/file/{id}                a generated file (png/jpeg/webp/mp4)
//	GET  gen/files                    recent generations (newest first)

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"kaa/secrets"
)

// GenProvider: one API. Prices are list prices in USD looked up on 20 Nov 2026
// (see PriceNote); the owner can see them before every run.
type GenProvider struct {
	ID        string  `json:"id"`
	Name      string  `json:"name"`
	Kind      string  `json:"kind"` // image | video
	Model     string  `json:"model"`
	PriceUSD  float64 `json:"price_usd"`
	Unit      string  `json:"unit"` // image | second
	PriceNote string  `json:"price_note"`
	Terms     string  `json:"terms"`
	KeyURL    string  `json:"key_url"`
	Secret    string  `json:"-"`
}

var genProviders = []GenProvider{
	{ID: "openai", Name: "OpenAI GPT Image 1.5", Kind: "image", Model: "gpt-image-1.5", PriceUSD: 0.20, Unit: "image", Secret: "gen_openai",
		PriceNote: "high quality 1024x1536: $0.20/image (square ~$0.13); OpenAI model page, Nov 2026",
		Terms:     "OpenAI terms: you own the output; commercial use allowed.", KeyURL: "https://platform.openai.com/api-keys"},
	{ID: "gemini", Name: "Google Gemini 3 Pro Image (Nano Banana Pro)", Kind: "image", Model: "gemini-3-pro-image-preview", PriceUSD: 0.134, Unit: "image", Secret: "gen_google",
		PriceNote: "~$0.134/image at 1K-2K (token-billed); ai.google.dev pricing, Nov 2026",
		Terms:     "Gemini API terms: Google claims no ownership of generated content; paid tier, commercial use allowed. Images carry SynthID.", KeyURL: "https://aistudio.google.com/apikey"},
	{ID: "fal-flux", Name: "FLUX 1.1 [pro] ultra (Black Forest Labs, via fal)", Kind: "image", Model: "fal-ai/flux-pro/v1.1-ultra", PriceUSD: 0.06, Unit: "image", Secret: "gen_fal",
		PriceNote: "$0.06/image; fal.ai pricing, Nov 2026",
		Terms:     "fal + BFL API terms: outputs may be used commercially.", KeyURL: "https://fal.ai/dashboard/keys"},
	{ID: "ideogram", Name: "Ideogram 3.0", Kind: "image", Model: "ideogram-v3", PriceUSD: 0.09, Unit: "image", Secret: "gen_ideogram",
		PriceNote: "~$0.09/image (Quality); third-party listings, Nov 2026 -- check ideogram.ai/api",
		Terms:     "Ideogram API terms: paid outputs usable commercially.", KeyURL: "https://ideogram.ai/manage-api"},
	{ID: "veo", Name: "Google Veo 3.1 Fast", Kind: "video", Model: "veo-3.1-fast-generate-preview", PriceUSD: 0.15, Unit: "second", Secret: "gen_google",
		PriceNote: "$0.10-0.15/s (720p/1080p, with audio); ai.google.dev pricing as reported Nov 2026",
		Terms:     "Gemini API terms (as above); videos carry SynthID.", KeyURL: "https://aistudio.google.com/apikey"},
	{ID: "runway", Name: "Runway Gen-4 Turbo", Kind: "video", Model: "gen4_turbo", PriceUSD: 0.05, Unit: "second", Secret: "gen_runway",
		PriceNote: "5 credits/s at $0.01/credit = $0.05/s; Runway API pricing page, Nov 2026",
		Terms:     "Runway API terms: you own outputs; commercial use allowed.", KeyURL: "https://dev.runwayml.com/"},
	{ID: "fal-kling", Name: "Kling 2.6 Pro (via fal)", Kind: "video", Model: "fal-ai/kling-video/v2.6/pro/image-to-video", PriceUSD: 0.07, Unit: "second", Secret: "gen_fal",
		PriceNote: "$0.07/s audio off; fal.ai model page, Nov 2026",
		Terms:     "fal + Kling terms: outputs may be used commercially.", KeyURL: "https://fal.ai/dashboard/keys"},
	{ID: "luma", Name: "Luma Ray 2", Kind: "video", Model: "ray-2", PriceUSD: 0.35, Unit: "second", Secret: "gen_luma",
		PriceNote: "~$0.35/s at 720p; listings disagree, check lumalabs.ai/api",
		Terms:     "Luma API terms: commercial use of API outputs allowed.", KeyURL: "https://lumalabs.ai/dream-machine/api/keys"},
}

// genBase: provider hosts (tests point them at httptest servers).
var genBase = map[string]string{
	"openai":   "https://api.openai.com",
	"gemini":   "https://generativelanguage.googleapis.com",
	"fal":      "https://fal.run",
	"falq":     "https://queue.fal.run",
	"ideogram": "https://api.ideogram.ai",
	"runway":   "https://api.dev.runwayml.com",
	"luma":     "https://api.lumalabs.ai",
}

// genPoll: how often a video job is polled (tests shorten it).
var genPoll = 5 * time.Second

func init() {
	seen := map[string]bool{}
	for _, n := range secrets.Names {
		seen[n] = true
	}
	for _, p := range genProviders {
		if !seen[p.Secret] {
			secrets.Names = append(secrets.Names, p.Secret)
			seen[p.Secret] = true
		}
	}
}

func genProvider(id string) *GenProvider {
	for i := range genProviders {
		if genProviders[i].ID == id {
			return &genProviders[i]
		}
	}
	return nil
}

// GenSettings: the owner's choices. Budget 0 = generation off.
type GenSettings struct {
	BudgetUSD float64           `json:"budget_usd"`
	Image     string            `json:"image"`
	Video     string            `json:"video"`
	Models    map[string]string `json:"models,omitempty"` // provider -> model id override (newer model names)
}

type genLedger struct {
	At       time.Time `json:"at"`
	Provider string    `json:"provider"`
	Kind     string    `json:"kind"`
	USD      float64   `json:"usd"`
	ID       string    `json:"id"`
	OK       bool      `json:"ok"`
	Note     string    `json:"note,omitempty"`
}

type genFile struct {
	ID       string    `json:"id"`
	Kind     string    `json:"kind"`
	Provider string    `json:"provider"`
	Model    string    `json:"model"`
	Format   string    `json:"format"`
	Prompt   string    `json:"prompt"`
	USD      float64   `json:"usd"`
	Mime     string    `json:"mime"`
	URL      string    `json:"url"`
	At       time.Time `json:"at"`
	Seconds  float64   `json:"seconds,omitempty"`
}

type genJob struct {
	ID    string   `json:"id"`
	State string   `json:"state"` // running | done | failed
	Error string   `json:"error,omitempty"`
	File  *genFile `json:"file,omitempty"`
	USD   float64  `json:"usd"`
}

type genState struct {
	mu   sync.Mutex
	sec  secrets.Store
	jobs map[string]*genJob
}

var genS = &genState{jobs: map[string]*genJob{}}

func (e *Engine) genDir() string { return filepath.Join(e.dir, "gen") }

// genSecrets: the engine's secrets package. On a Mac that is the Keychain
// (one item per name, shared with the engine); elsewhere a 0600 file of the
// brain's own, so two in-process file stores never overwrite each other.
func (e *Engine) genSecrets() secrets.Store {
	genS.mu.Lock()
	defer genS.mu.Unlock()
	if genS.sec == nil {
		genS.sec = secrets.New(e.dir, e.deps.Redact) // every key loaded or saved is redacted from the audit logs (QA)
	}
	return genS.sec
}

func (e *Engine) genSettings() GenSettings {
	s := GenSettings{BudgetUSD: 25, Image: "openai", Video: "veo"}
	e.load("", "gen_settings", &s)
	return s
}

func (e *Engine) genKey(p *GenProvider) string {
	v, _ := e.genSecrets().Get(p.Secret)
	return v
}

func (e *Engine) genModel(p *GenProvider) string {
	if m := e.genSettings().Models[p.ID]; m != "" {
		return m
	}
	return p.Model
}

// spentThisMonth: sum of charged ledger rows in the current calendar month.
func (e *Engine) spentThisMonth(now time.Time) float64 {
	b, err := os.ReadFile(filepath.Join(e.genDir(), "ledger.jsonl"))
	if err != nil {
		return 0
	}
	sum := 0.0
	for _, ln := range bytes.Split(b, []byte("\n")) {
		var r genLedger
		if json.Unmarshal(ln, &r) == nil && r.At.Year() == now.Year() && r.At.Month() == now.Month() {
			sum += r.USD
		}
	}
	return round2(sum)
}

func (e *Engine) charge(r genLedger) {
	_ = os.MkdirAll(e.genDir(), 0o700)
	f, err := os.OpenFile(filepath.Join(e.genDir(), "ledger.jsonl"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		return
	}
	b, _ := json.Marshal(r)
	_, _ = f.Write(append(b, '\n'))
	f.Close()
}

// Estimate: the price of one run, in USD, before it happens.
func estimateUSD(p *GenProvider, seconds float64, n int) float64 {
	if n < 1 {
		n = 1
	}
	if p.Unit == "second" {
		return round2(p.PriceUSD * clampSeconds(p, seconds) * float64(n))
	}
	return round2(p.PriceUSD * float64(n))
}

// clampSeconds: the lengths each video API actually offers.
func clampSeconds(p *GenProvider, s float64) float64 {
	switch p.ID {
	case "veo":
		if s <= 4 {
			return 4
		}
		if s <= 6 {
			return 6
		}
		return 8
	case "runway", "fal-kling":
		if s <= 5 {
			return 5
		}
		return 10
	case "luma":
		if s <= 5 {
			return 5
		}
		return 9
	}
	return s
}

type genEstimate struct {
	USD      float64 `json:"usd"`
	Spent    float64 `json:"spent_usd"`
	Budget   float64 `json:"budget_usd"`
	Left     float64 `json:"left_usd"`
	OK       bool    `json:"ok"`
	KeySet   bool    `json:"key_set"`
	Why      string  `json:"why,omitempty"`
	Seconds  float64 `json:"seconds,omitempty"`
	Provider string  `json:"provider"`
	Model    string  `json:"model"`
	Note     string  `json:"price_note"`
}

func (e *Engine) estimate(p *GenProvider, seconds float64, n int, now time.Time) genEstimate {
	s := e.genSettings()
	est := genEstimate{USD: estimateUSD(p, seconds, n), Spent: e.spentThisMonth(now), Budget: s.BudgetUSD, Provider: p.ID, Model: e.genModel(p), Note: p.PriceNote}
	if p.Unit == "second" {
		est.Seconds = clampSeconds(p, seconds)
	}
	est.Left = round2(s.BudgetUSD - est.Spent)
	est.KeySet = e.genKey(p) != ""
	switch {
	case !est.KeySet:
		est.Why = "No key for " + p.Name + " yet (Settings -> Connections -> Generators)."
	case s.BudgetUSD <= 0:
		est.Why = "The monthly generation budget is 0 (generation is off)."
	case est.Spent+est.USD > s.BudgetUSD+1e-9:
		est.Why = fmt.Sprintf("This would pass the monthly generation budget ($%.2f spent of $%.2f).", est.Spent, s.BudgetUSD)
	default:
		est.OK = true
	}
	return est
}

func decodeSmall(r *http.Request, v any) error {
	return json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(v)
}

var genID = regexp.MustCompile(`^g[0-9a-f]{6,24}$`)

func (e *Engine) serveGen(w http.ResponseWriter, r *http.Request, p string, js func(any), bad func(int, string)) {
	now := time.Now()
	switch {
	case p == "gen/settings" && r.Method == http.MethodGet:
		js(e.genSettingsView(now))
	case p == "gen/settings" && r.Method == http.MethodPut:
		var in GenSettings
		if decodeSmall(r, &in) != nil {
			bad(400, "bad JSON")
			return
		}
		s := e.genSettings()
		if in.BudgetUSD >= 0 && in.BudgetUSD <= 10000 {
			s.BudgetUSD = round2(in.BudgetUSD)
		}
		if q := genProvider(in.Image); q != nil && q.Kind == "image" {
			s.Image = q.ID
		}
		if q := genProvider(in.Video); q != nil && q.Kind == "video" {
			s.Video = q.ID
		}
		if in.Models != nil {
			s.Models = map[string]string{}
			for k, v := range in.Models {
				if genProvider(k) != nil && regexp.MustCompile(`^[A-Za-z0-9._/-]{2,80}$`).MatchString(v) {
					s.Models[k] = v
				}
			}
		}
		e.save("", "gen_settings", s)
		e.log("config", fmt.Sprintf("generators: monthly budget $%.2f, image %s, video %s", s.BudgetUSD, s.Image, s.Video), nil)
		js(e.genSettingsView(now))
	case p == "gen/keys" && r.Method == http.MethodPut:
		var in struct{ Provider, Key string }
		if decodeSmall(r, &in) != nil {
			bad(400, "bad JSON")
			return
		}
		q := genProvider(in.Provider)
		if q == nil {
			bad(400, "unknown provider")
			return
		}
		in.Key = strings.TrimSpace(in.Key)
		var err error
		if in.Key == "" {
			err = e.genSecrets().Delete(q.Secret)
		} else if err = secrets.ValidValue(in.Key); err == nil {
			err = e.genSecrets().Set(q.Secret, in.Key)
		}
		if err != nil {
			bad(400, err.Error())
			return
		}
		e.log("config", "generator key saved for "+q.Name, map[string]any{"key": secrets.Describe(e.genSecrets(), q.Secret)})
		js(e.genSettingsView(now))
	case p == "gen/one-key" && r.Method == http.MethodPut:
		// "AI pictures & video": ONE Google Gemini API key gives both the images (Gemini image) and the video (Veo);
		// saving it makes them the providers, so the owner never picks between models ("" removes it).
		var in struct{ Key string }
		if decodeSmall(r, &in) != nil {
			bad(400, "bad JSON")
			return
		}
		in.Key = strings.TrimSpace(in.Key)
		var err error
		if in.Key == "" {
			err = e.genSecrets().Delete("gen_google")
		} else if err = secrets.ValidValue(in.Key); err == nil {
			err = e.genSecrets().Set("gen_google", in.Key)
		}
		if err != nil {
			bad(400, err.Error())
			return
		}
		if in.Key != "" {
			s := e.genSettings()
			s.Image, s.Video = "gemini", "veo"
			e.save("", "gen_settings", s)
		}
		e.log("config", "AI pictures & video key (Google Gemini) "+map[bool]string{true: "saved", false: "removed"}[in.Key != ""], map[string]any{"key": secrets.Describe(e.genSecrets(), "gen_google")})
		js(e.genSettingsView(now))
	case p == "gen/prompt" && r.Method == http.MethodPost:
		var in SceneRequest
		if decodeSmall(r, &in) != nil {
			bad(400, "bad JSON")
			return
		}
		js(map[string]string{"prompt": ScenePrompt(in), "motion": MotionPrompt(in)})
	case p == "gen/estimate" && r.Method == http.MethodPost:
		var in struct {
			Provider string  `json:"provider"`
			Seconds  float64 `json:"seconds"`
			N        int     `json:"n"`
		}
		_ = decodeSmall(r, &in)
		q := genProvider(in.Provider)
		if q == nil {
			bad(400, "unknown provider")
			return
		}
		js(e.estimate(q, in.Seconds, in.N, now))
	case p == "gen/image" && r.Method == http.MethodPost:
		var in genRun
		if decodeSmall(r, &in) != nil {
			bad(400, "bad JSON")
			return
		}
		q := genProvider(in.Provider)
		if q == nil || q.Kind != "image" {
			bad(400, "unknown image provider")
			return
		}
		est, ok := e.admit(q, &in, now, bad)
		if !ok {
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 4*time.Minute)
		defer cancel()
		f, err := e.genImage(ctx, q, in)
		e.settle(q, "image", est.USD, f, err)
		if err != nil {
			bad(502, err.Error())
			return
		}
		js(f)
	case p == "gen/video" && r.Method == http.MethodPost:
		var in genRun
		if decodeSmall(r, &in) != nil {
			bad(400, "bad JSON")
			return
		}
		q := genProvider(in.Provider)
		if q == nil || q.Kind != "video" {
			bad(400, "unknown video provider")
			return
		}
		est, ok := e.admit(q, &in, now, bad)
		if !ok {
			return
		}
		var start []byte
		if in.ImageID != "" {
			b, _, err := e.genRead(in.ImageID)
			if err != nil {
				bad(400, "no such start image")
				return
			}
			start = b
		}
		j := &genJob{ID: newGenID(), State: "running", USD: est.USD}
		genS.mu.Lock()
		genS.jobs[j.ID] = j
		genS.mu.Unlock()
		go func() {
			ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
			defer cancel()
			f, err := e.genVideo(ctx, q, in, start)
			e.settle(q, "video", est.USD, f, err)
			genS.mu.Lock()
			if err != nil {
				j.State, j.Error = "failed", err.Error()
			} else {
				j.State, j.File = "done", f
			}
			genS.mu.Unlock()
		}()
		js(j)
	case strings.HasPrefix(p, "gen/jobs/"):
		genS.mu.Lock()
		j := genS.jobs[strings.TrimPrefix(p, "gen/jobs/")]
		var c genJob
		if j != nil {
			c = *j
		}
		genS.mu.Unlock()
		if j == nil {
			bad(404, "no such job")
			return
		}
		js(c)
	case p == "gen/files":
		js(e.genIndex())
	case strings.HasPrefix(p, "gen/file/"):
		b, mime, err := e.genRead(strings.TrimPrefix(p, "gen/file/"))
		if err != nil {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("content-type", mime)
		w.Header().Set("cache-control", "private, max-age=31536000, immutable")
		http.ServeContent(w, r, "", time.Time{}, bytes.NewReader(b))
	default:
		bad(404, "no such generator endpoint")
	}
}

type genRun struct {
	Provider   string  `json:"provider"`
	Prompt     string  `json:"prompt"`
	Format     string  `json:"format"` // 1:1 | 4:5 | 9:16
	Seconds    float64 `json:"seconds"`
	ImageID    string  `json:"image_id"`
	ConfirmUSD float64 `json:"confirm_usd"`
}

// admit: key present, budget left, and the owner confirmed at least this price.
func (e *Engine) admit(q *GenProvider, in *genRun, now time.Time, bad func(int, string)) (genEstimate, bool) {
	in.Prompt = strings.TrimSpace(in.Prompt)
	if in.Prompt == "" || len(in.Prompt) > 4000 {
		bad(400, "send a prompt (the brain writes one: POST gen/prompt)")
		return genEstimate{}, false
	}
	in.Format = oneOf(in.Format, []string{"1:1", "4:5", "9:16"}, "4:5")
	est := e.estimate(q, in.Seconds, 1, now)
	if !est.OK {
		bad(402, est.Why)
		return est, false
	}
	if in.ConfirmUSD+1e-9 < est.USD {
		bad(409, fmt.Sprintf("confirm the price first: this run costs $%.2f", est.USD))
		return est, false
	}
	return est, true
}

func (e *Engine) settle(q *GenProvider, kind string, usd float64, f *genFile, err error) {
	row := genLedger{At: time.Now(), Provider: q.ID, Kind: kind, USD: usd, OK: err == nil}
	if f != nil {
		row.ID = f.ID
	}
	if err != nil {
		row.USD, row.Note = 0, truncate(err.Error(), 200)
		// a provider that failed after accepting may still bill: count it
		if strings.Contains(err.Error(), "after acceptance") {
			row.USD = usd
		}
	}
	e.charge(row)
	if err != nil {
		e.log("config", fmt.Sprintf("generation failed (%s): %s", q.Name, truncate(err.Error(), 160)), nil)
		return
	}
	e.log("config", fmt.Sprintf("generated a %s with %s ($%.2f)", kind, q.Name, usd), map[string]any{"id": f.ID})
}

func truncate(s string, n int) string {
	if len(s) > n {
		return s[:n] + "…"
	}
	return s
}

func newGenID() string { return fmt.Sprintf("g%x", time.Now().UnixNano()) }

func (e *Engine) genSettingsView(now time.Time) map[string]any {
	s := e.genSettings()
	type pv struct {
		GenProvider
		Key   secrets.Info `json:"key"`
		Model string       `json:"model"`
	}
	var ps []pv
	for i := range genProviders {
		q := genProviders[i]
		ps = append(ps, pv{GenProvider: q, Key: secrets.Describe(e.genSecrets(), q.Secret), Model: e.genModel(&q)})
	}
	img, vid := genProvider("gemini"), genProvider("veo")
	one := map[string]any{"name": "Google Gemini API key", "key": secrets.Describe(e.genSecrets(), "gen_google"), "active": s.Image == "gemini" && s.Video == "veo",
		"image_model": e.genModel(img), "video_model": e.genModel(vid), "image_usd": img.PriceUSD, "video_usd_per_s": vid.PriceUSD,
		"key_url": img.KeyURL, "doc_url": "https://ai.google.dev/gemini-api/docs/pricing", "terms": img.Terms}
	return map[string]any{"providers": ps, "budget_usd": s.BudgetUSD, "spent_usd": e.spentThisMonth(now), "image": s.Image, "video": s.Video, "month": now.Format("2006-01"), "one_key": one}
}

// ---------- files ----------

func (e *Engine) genSave(f *genFile, b []byte) error {
	ext := map[string]string{"image/png": ".png", "image/jpeg": ".jpg", "image/webp": ".webp", "video/mp4": ".mp4"}[f.Mime]
	if ext == "" {
		return fmt.Errorf("the provider returned %s, not an image/video", f.Mime)
	}
	_ = os.MkdirAll(e.genDir(), 0o700)
	if err := os.WriteFile(filepath.Join(e.genDir(), f.ID+ext), b, 0o600); err != nil {
		return err
	}
	f.URL = "/api/brain/x/gen/file/" + f.ID
	idx := e.genIndex()
	idx = append([]genFile{*f}, idx...)
	if len(idx) > 500 {
		idx = idx[:500]
	}
	e.save("", "gen_index", idx)
	return nil
}

func (e *Engine) genIndex() []genFile {
	idx := []genFile{}
	e.load("", "gen_index", &idx)
	return idx
}

func (e *Engine) genRead(id string) ([]byte, string, error) {
	if !genID.MatchString(id) {
		return nil, "", fmt.Errorf("bad id")
	}
	for _, ext := range []string{".png", ".jpg", ".webp", ".mp4"} {
		if b, err := os.ReadFile(filepath.Join(e.genDir(), id+ext)); err == nil {
			return b, map[string]string{".png": "image/png", ".jpg": "image/jpeg", ".webp": "image/webp", ".mp4": "video/mp4"}[ext], nil
		}
	}
	return nil, "", os.ErrNotExist
}

// ---------- provider calls ----------

var genHTTP = &http.Client{Timeout: 5 * time.Minute}

// call: JSON (or multipart) request -> decoded JSON; errors carry the
// provider's own message with the key scrubbed out.
func genCall(ctx context.Context, method, u string, hdr map[string]string, body io.Reader, ct string, out any, key string) error {
	req, err := http.NewRequestWithContext(ctx, method, u, body)
	if err != nil {
		return err
	}
	if ct != "" {
		req.Header.Set("content-type", ct)
	}
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	resp, err := genHTTP.Do(req)
	if err != nil {
		return scrub(err.Error(), key)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
	if resp.StatusCode >= 300 {
		return scrub(fmt.Sprintf("HTTP %d: %s", resp.StatusCode, truncate(string(b), 400)), key)
	}
	if out == nil {
		return nil
	}
	if err := json.Unmarshal(b, out); err != nil {
		return fmt.Errorf("unreadable answer: %s", truncate(string(b), 200))
	}
	return nil
}

func scrub(s, key string) error {
	if key != "" {
		s = strings.ReplaceAll(s, key, "[key]")
	}
	return fmt.Errorf("%s", s)
}

// fetchMedia: download a result URL (https only, size-capped).
func fetchMedia(ctx context.Context, u string, hdr map[string]string, max int64) ([]byte, string, error) {
	pu, err := url.Parse(u)
	if err != nil || (pu.Scheme != "https" && !strings.HasPrefix(u, "http://127.0.0.1")) {
		return nil, "", fmt.Errorf("refusing a non-https result URL")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	resp, err := genHTTP.Do(req)
	if err != nil {
		return nil, "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return nil, "", fmt.Errorf("result download: HTTP %d", resp.StatusCode)
	}
	b, err := io.ReadAll(io.LimitReader(resp.Body, max+1))
	if err != nil || int64(len(b)) > max {
		return nil, "", fmt.Errorf("result too large or cut off")
	}
	return b, sniff(b), nil
}

func sniff(b []byte) string {
	ct := http.DetectContentType(b)
	if len(b) > 12 && string(b[4:8]) == "ftyp" {
		return "video/mp4"
	}
	if i := strings.Index(ct, ";"); i > 0 {
		ct = ct[:i]
	}
	return ct
}

func sizeFor(provider, format string) string {
	switch provider {
	case "openai":
		return map[string]string{"1:1": "1024x1024", "4:5": "1024x1536", "9:16": "1024x1536"}[format]
	case "ideogram":
		return map[string]string{"1:1": "1x1", "4:5": "4x5", "9:16": "9x16"}[format]
	case "fal-flux":
		return map[string]string{"1:1": "1:1", "4:5": "3:4", "9:16": "9:16"}[format]
	case "runway":
		return map[string]string{"1:1": "960:960", "4:5": "832:1104", "9:16": "720:1280"}[format]
	case "veo", "luma", "fal-kling":
		if format == "1:1" && provider == "luma" {
			return "1:1"
		}
		if format == "9:16" || format == "4:5" {
			return "9:16"
		}
		return "16:9"
	}
	return format
}

func (e *Engine) genImage(ctx context.Context, q *GenProvider, in genRun) (*genFile, error) {
	key := e.genKey(q)
	model := e.genModel(q)
	f := &genFile{ID: newGenID(), Kind: "image", Provider: q.ID, Model: model, Format: in.Format, Prompt: in.Prompt, USD: estimateUSD(q, 0, 1), At: time.Now()}
	var img []byte
	switch q.ID {
	case "openai":
		var out struct {
			Data []struct {
				B64 string `json:"b64_json"`
			} `json:"data"`
		}
		body, _ := json.Marshal(map[string]any{"model": model, "prompt": in.Prompt, "size": sizeFor("openai", in.Format), "quality": "high", "n": 1})
		if err := genCall(ctx, "POST", genBase["openai"]+"/v1/images/generations", map[string]string{"Authorization": "Bearer " + key}, bytes.NewReader(body), "application/json", &out, key); err != nil {
			return nil, err
		}
		if len(out.Data) == 0 {
			return nil, fmt.Errorf("OpenAI returned no image")
		}
		img, _ = base64.StdEncoding.DecodeString(out.Data[0].B64)
	case "gemini":
		var out struct {
			Candidates []struct {
				Content struct {
					Parts []struct {
						Inline *struct {
							Mime string `json:"mimeType"`
							Data string `json:"data"`
						} `json:"inlineData"`
					} `json:"parts"`
				} `json:"content"`
			} `json:"candidates"`
		}
		body, _ := json.Marshal(map[string]any{"contents": []any{map[string]any{"parts": []any{map[string]string{"text": in.Prompt}}}},
			"generationConfig": map[string]any{"responseModalities": []string{"IMAGE"}, "imageConfig": map[string]string{"aspectRatio": in.Format}}})
		if err := genCall(ctx, "POST", genBase["gemini"]+"/v1beta/models/"+url.PathEscape(model)+":generateContent", map[string]string{"x-goog-api-key": key}, bytes.NewReader(body), "application/json", &out, key); err != nil {
			return nil, err
		}
		for _, c := range out.Candidates {
			for _, p := range c.Content.Parts {
				if p.Inline != nil && img == nil {
					img, _ = base64.StdEncoding.DecodeString(p.Inline.Data)
				}
			}
		}
	case "fal-flux":
		var out struct {
			Images []struct {
				URL string `json:"url"`
			} `json:"images"`
		}
		body, _ := json.Marshal(map[string]any{"prompt": in.Prompt, "aspect_ratio": sizeFor("fal-flux", in.Format), "num_images": 1, "output_format": "png", "safety_tolerance": "2", "enable_safety_checker": true})
		if err := genCall(ctx, "POST", genBase["fal"]+"/"+model, map[string]string{"Authorization": "Key " + key}, bytes.NewReader(body), "application/json", &out, key); err != nil {
			return nil, err
		}
		if len(out.Images) == 0 {
			return nil, fmt.Errorf("fal returned no image")
		}
		b, _, err := fetchMedia(ctx, out.Images[0].URL, nil, 30<<20)
		if err != nil {
			return nil, fmt.Errorf("after acceptance: %v", err)
		}
		img = b
	case "ideogram":
		var buf bytes.Buffer
		mw := multipart.NewWriter(&buf)
		_ = mw.WriteField("prompt", in.Prompt)
		_ = mw.WriteField("aspect_ratio", sizeFor("ideogram", in.Format))
		_ = mw.WriteField("rendering_speed", "QUALITY")
		_ = mw.WriteField("magic_prompt", "OFF")
		_ = mw.WriteField("style_type", "REALISTIC")
		mw.Close()
		var out struct {
			Data []struct {
				URL string `json:"url"`
			} `json:"data"`
		}
		if err := genCall(ctx, "POST", genBase["ideogram"]+"/v1/ideogram-v3/generate", map[string]string{"Api-Key": key}, &buf, mw.FormDataContentType(), &out, key); err != nil {
			return nil, err
		}
		if len(out.Data) == 0 {
			return nil, fmt.Errorf("Ideogram returned no image")
		}
		b, _, err := fetchMedia(ctx, out.Data[0].URL, nil, 30<<20)
		if err != nil {
			return nil, fmt.Errorf("after acceptance: %v", err)
		}
		img = b
	default:
		return nil, fmt.Errorf("not an image provider")
	}
	if len(img) == 0 {
		return nil, fmt.Errorf("after acceptance: %s returned no image data", q.Name)
	}
	f.Mime = sniff(img)
	if err := e.genSave(f, img); err != nil {
		return nil, fmt.Errorf("after acceptance: %v", err)
	}
	return f, nil
}

func dataURI(b []byte) string {
	return "data:" + sniff(b) + ";base64," + base64.StdEncoding.EncodeToString(b)
}

func (e *Engine) genVideo(ctx context.Context, q *GenProvider, in genRun, start []byte) (*genFile, error) {
	key := e.genKey(q)
	model := e.genModel(q)
	secs := clampSeconds(q, in.Seconds)
	f := &genFile{ID: newGenID(), Kind: "video", Provider: q.ID, Model: model, Format: in.Format, Prompt: in.Prompt, USD: estimateUSD(q, secs, 1), At: time.Now(), Seconds: secs}
	var vid []byte
	poll := func(get func() (done bool, err error)) error {
		for {
			done, err := get()
			if err != nil || done {
				return err
			}
			select {
			case <-ctx.Done():
				return fmt.Errorf("after acceptance: timed out waiting for the video")
			case <-time.After(genPoll):
			}
		}
	}
	switch q.ID {
	case "veo":
		inst := map[string]any{"prompt": in.Prompt}
		if start != nil {
			inst["image"] = map[string]string{"bytesBase64Encoded": base64.StdEncoding.EncodeToString(start), "mimeType": sniff(start)}
		}
		body, _ := json.Marshal(map[string]any{"instances": []any{inst}, "parameters": map[string]any{"aspectRatio": sizeFor("veo", in.Format), "durationSeconds": int(secs), "personGeneration": "allow_adult"}})
		var op struct {
			Name string `json:"name"`
		}
		h := map[string]string{"x-goog-api-key": key}
		if err := genCall(ctx, "POST", genBase["gemini"]+"/v1beta/models/"+url.PathEscape(model)+":predictLongRunning", h, bytes.NewReader(body), "application/json", &op, key); err != nil {
			return nil, err
		}
		var uri string
		err := poll(func() (bool, error) {
			var st struct {
				Done     bool                      `json:"done"`
				Error    *struct{ Message string } `json:"error"`
				Response struct {
					G struct {
						Samples []struct {
							Video struct {
								URI string `json:"uri"`
							} `json:"video"`
						} `json:"generatedSamples"`
					} `json:"generateVideoResponse"`
				} `json:"response"`
			}
			if err := genCall(ctx, "GET", genBase["gemini"]+"/v1beta/"+op.Name, h, nil, "", &st, key); err != nil {
				return false, fmt.Errorf("after acceptance: %v", err)
			}
			if st.Error != nil {
				return false, fmt.Errorf("Veo: %s", st.Error.Message)
			}
			if st.Done && len(st.Response.G.Samples) > 0 {
				uri = st.Response.G.Samples[0].Video.URI
			}
			return st.Done, nil
		})
		if err != nil {
			return nil, err
		}
		if uri == "" {
			return nil, fmt.Errorf("Veo finished without a video (the safety filter may have refused the prompt)")
		}
		b, _, err := fetchMedia(ctx, uri, h, 300<<20)
		if err != nil {
			return nil, fmt.Errorf("after acceptance: %v", err)
		}
		vid = b
	case "runway":
		if start == nil {
			return nil, fmt.Errorf("Runway animates a still: make the background image first")
		}
		h := map[string]string{"Authorization": "Bearer " + key, "X-Runway-Version": "2024-11-06"}
		body, _ := json.Marshal(map[string]any{"model": model, "promptImage": dataURI(start), "promptText": truncate(in.Prompt, 990), "ratio": sizeFor("runway", in.Format), "duration": int(secs)})
		var task struct {
			ID string `json:"id"`
		}
		if err := genCall(ctx, "POST", genBase["runway"]+"/v1/image_to_video", h, bytes.NewReader(body), "application/json", &task, key); err != nil {
			return nil, err
		}
		var out string
		err := poll(func() (bool, error) {
			var st struct {
				Status  string   `json:"status"`
				Output  []string `json:"output"`
				Failure string   `json:"failure"`
			}
			if err := genCall(ctx, "GET", genBase["runway"]+"/v1/tasks/"+url.PathEscape(task.ID), h, nil, "", &st, key); err != nil {
				return false, fmt.Errorf("after acceptance: %v", err)
			}
			switch st.Status {
			case "SUCCEEDED":
				if len(st.Output) > 0 {
					out = st.Output[0]
				}
				return true, nil
			case "FAILED", "CANCELLED":
				return false, fmt.Errorf("Runway: %s %s", st.Status, st.Failure)
			}
			return false, nil
		})
		if err != nil {
			return nil, err
		}
		b, _, err := fetchMedia(ctx, out, nil, 300<<20)
		if err != nil {
			return nil, fmt.Errorf("after acceptance: %v", err)
		}
		vid = b
	case "fal-kling":
		if start == nil {
			return nil, fmt.Errorf("Kling animates a still: make the background image first")
		}
		h := map[string]string{"Authorization": "Key " + key}
		body, _ := json.Marshal(map[string]any{"prompt": in.Prompt, "image_url": dataURI(start), "duration": fmt.Sprint(int(secs)), "generate_audio": false, "negative_prompt": "product, bottle, packaging, text, logo, watermark, distorted hands"})
		var sub struct {
			StatusURL   string `json:"status_url"`
			ResponseURL string `json:"response_url"`
		}
		if err := genCall(ctx, "POST", genBase["falq"]+"/"+model, h, bytes.NewReader(body), "application/json", &sub, key); err != nil {
			return nil, err
		}
		err := poll(func() (bool, error) {
			var st struct{ Status string }
			if err := genCall(ctx, "GET", sub.StatusURL, h, nil, "", &st, key); err != nil {
				return false, fmt.Errorf("after acceptance: %v", err)
			}
			if st.Status == "ERROR" || st.Status == "FAILED" {
				return false, fmt.Errorf("after acceptance: Kling failed")
			}
			return st.Status == "COMPLETED", nil
		})
		if err != nil {
			return nil, err
		}
		var res struct {
			Video struct {
				URL string `json:"url"`
			} `json:"video"`
		}
		if err := genCall(ctx, "GET", sub.ResponseURL, h, nil, "", &res, key); err != nil {
			return nil, fmt.Errorf("after acceptance: %v", err)
		}
		b, _, err := fetchMedia(ctx, res.Video.URL, nil, 300<<20)
		if err != nil {
			return nil, fmt.Errorf("after acceptance: %v", err)
		}
		vid = b
	case "luma":
		h := map[string]string{"Authorization": "Bearer " + key}
		body, _ := json.Marshal(map[string]any{"prompt": in.Prompt, "model": model, "aspect_ratio": sizeFor("luma", in.Format), "duration": fmt.Sprintf("%ds", int(secs)), "resolution": "720p"})
		var g struct {
			ID string `json:"id"`
		}
		if err := genCall(ctx, "POST", genBase["luma"]+"/dream-machine/v1/generations", h, bytes.NewReader(body), "application/json", &g, key); err != nil {
			return nil, err
		}
		var out string
		err := poll(func() (bool, error) {
			var st struct {
				State   string `json:"state"`
				Failure string `json:"failure_reason"`
				Assets  struct {
					Video string `json:"video"`
				} `json:"assets"`
			}
			if err := genCall(ctx, "GET", genBase["luma"]+"/dream-machine/v1/generations/"+url.PathEscape(g.ID), h, nil, "", &st, key); err != nil {
				return false, fmt.Errorf("after acceptance: %v", err)
			}
			if st.State == "failed" {
				return false, fmt.Errorf("Luma: %s", st.Failure)
			}
			out = st.Assets.Video
			return st.State == "completed", nil
		})
		if err != nil {
			return nil, err
		}
		b, _, err := fetchMedia(ctx, out, nil, 300<<20)
		if err != nil {
			return nil, fmt.Errorf("after acceptance: %v", err)
		}
		vid = b
	default:
		return nil, fmt.Errorf("not a video provider")
	}
	f.Mime = sniff(vid)
	if f.Mime != "video/mp4" {
		return nil, fmt.Errorf("after acceptance: %s returned %s, not an MP4", q.Name, f.Mime)
	}
	if err := e.genSave(f, vid); err != nil {
		return nil, fmt.Errorf("after acceptance: %v", err)
	}
	return f, nil
}
