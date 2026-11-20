package brain

// Local product cut-out models. The browser runs a real segmentation network
// (ONNX Runtime Web) on the shop's product photos; the engine only fetches the
// pieces ONCE on first use, checks every byte against a pinned SHA-256, keeps
// them in <data>/models/ and serves them same-origin. No key, no account, no
// photo ever leaves the Mac. Routes under /api/brain/x/seg/:
//
//	GET  seg/status            what is installed / downloading (sizes, licences)
//	POST seg/download {model}  fetch the runtime + that model in the background
//	GET  seg/file/{asset}      the verified bytes (runtime wasm or model)
//	GET  seg/mask?key=…        a cached alpha mask (PNG) the browser computed before
//	PUT  seg/mask?key=…        store one (PNG, <= 4 MB)
//
// Only permissive licences, chosen for commercial ads:
//   - ISNet general-use (DIS, Apache-2.0): runs on any Mac's CPU (WebAssembly).
//   - BiRefNet-lite (MIT), fp16: finest edges, needs WebGPU (fp32 runs out of
//     the 4 GB WebAssembly memory on CPU, measured).
//   - ONNX Runtime Web 1.31.1 (MIT): the WebAssembly engine both run on.
// RMBG-1.4/2.0 are NOT used (non-commercial licence).

import (
	"archive/tar"
	"compress/gzip"
	"crypto/sha256"
	"crypto/sha512"
	"encoding/hex"

	"fmt"
	"hash"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
)

// SegAsset is one downloadable file. Member set: the file is extracted from a
// .tgz (npm) whose own SHA-512 is TarSHA512.
type SegAsset struct {
	ID        string `json:"id"`
	Name      string `json:"name"`
	Licence   string `json:"licence"`
	URL       string `json:"-"`
	Member    string `json:"-"`
	TarSHA512 string `json:"-"`
	TarBytes  int64  `json:"-"`
	SHA256    string `json:"sha256"`
	Bytes     int64  `json:"bytes"`
}

// SegModel: a model and how the browser feeds it.
type SegModel struct {
	ID      string    `json:"id"`
	Name    string    `json:"name"`
	Licence string    `json:"licence"`
	Asset   string    `json:"asset"`
	Input   int       `json:"input"` // square input size
	Mean    []float64 `json:"mean"`
	Std     []float64 `json:"std"`
	Sigmoid bool      `json:"sigmoid"` // output is logits
	WebGPU  bool      `json:"webgpu"`  // needs WebGPU (too big for CPU WebAssembly)
	Note    string    `json:"note"`
}

const segRuntime = "ort-wasm"

// segBase lets tests point the downloads at a local server.
var segBase = map[string]string{
	"npm": "https://registry.npmjs.org",
	"hf":  "https://huggingface.co",
	"gh":  "https://github.com",
}

var segAssets = []SegAsset{
	{ID: segRuntime, Name: "ONNX Runtime Web 1.31.1 (WebAssembly + WebGPU)", Licence: "MIT",
		URL: "npm:/onnxruntime-web/-/onnxruntime-web-1.31.1.tgz", Member: "package/dist/ort-wasm-simd-threaded.asyncify.wasm",
		TarSHA512: "e46787f9b590f87c4cd74cfb7ab1a5b16aabcabc0c6d2be6ff5b6422c2934f8f20d1f235f555d5542e37e6de2ded5b0f75241e4bb366b993d7ad0ef793cd9447", TarBytes: 34586352,
		SHA256: "ca319e9539cd0f1e291cf56074760121eaf39650f5dbf84b6e52c07f4b2944ec", Bytes: 28747738},
	{ID: "isnet", Name: "ISNet general-use (DIS)", Licence: "Apache-2.0",
		URL:    "gh:/danielgatis/rembg/releases/download/v0.0.0/isnet-general-use.onnx",
		SHA256: "60920e99c45464f2ba57bee2ad08c919a52bbf852739e96947fbb4358c0d964a", Bytes: 178648008},
	{ID: "birefnet-lite-fp16", Name: "BiRefNet-lite fp16", Licence: "MIT",
		URL:    "hf:/onnx-community/BiRefNet_lite-ONNX/resolve/de15b22ba131738a16dff04aab8bdf8dc32e3ac1/onnx/model_fp16.onnx",
		SHA256: "d39b897ceb16ae654c1731f3dba0cf9b368d9cae74b5a57459b455cc8bfec402", Bytes: 114538221},
}

var segModels = []SegModel{
	{ID: "isnet", Name: "ISNet general-use", Licence: "Apache-2.0", Asset: "isnet", Input: 1024, Mean: []float64{0.5, 0.5, 0.5}, Std: []float64{1, 1, 1},
		Note: "Runs on any Mac (CPU). About 3-15 s per product photo, once; the mask is kept."},
	{ID: "birefnet-lite", Name: "BiRefNet-lite", Licence: "MIT", Asset: "birefnet-lite-fp16", Input: 1024, Mean: []float64{0.485, 0.456, 0.406}, Std: []float64{0.229, 0.224, 0.225}, Sigmoid: true, WebGPU: true,
		Note: "Finest edges (hair-thin caps, glass, droppers). Uses the Mac's GPU (WebGPU)."},
}

type segJob struct {
	Asset string  `json:"asset"`
	Done  int64   `json:"done"`
	Total int64   `json:"total"`
	Err   string  `json:"error,omitempty"`
	Phase string  `json:"phase"` // downloading | verifying | done | failed
	Pct   float64 `json:"pct"`
}

type segState struct {
	mu   sync.Mutex
	jobs map[string]*segJob
}

var segS = &segState{jobs: map[string]*segJob{}}

func segAsset(id string) *SegAsset {
	for i := range segAssets {
		if segAssets[i].ID == id {
			return &segAssets[i]
		}
	}
	return nil
}

func segModel(id string) *SegModel {
	for i := range segModels {
		if segModels[i].ID == id {
			return &segModels[i]
		}
	}
	return nil
}

func (e *Engine) modelsDir() string { return filepath.Join(e.deps.DataDir, "models") }

func (e *Engine) assetPath(a *SegAsset) string { return filepath.Join(e.modelsDir(), a.ID+".bin") }

// haveAsset: present with the pinned size. (The hash was checked when it was
// written; a size check per request keeps status cheap.)
func (e *Engine) haveAsset(a *SegAsset) bool {
	st, err := os.Stat(e.assetPath(a))
	return err == nil && st.Size() == a.Bytes
}

func resolveURL(u string) string {
	if i := strings.Index(u, ":/"); i > 0 {
		if b, ok := segBase[u[:i]]; ok {
			return b + u[i+1:]
		}
	}
	return u
}

// fetchAsset downloads, verifies and installs one asset. Never leaves a
// partial or unverified file under its final name.
func (e *Engine) fetchAsset(a *SegAsset, prog func(done, total int64)) error {
	if e.haveAsset(a) {
		return nil
	}
	_ = os.MkdirAll(e.modelsDir(), 0o700)
	c := &http.Client{Timeout: 30 * time.Minute}
	resp, err := c.Get(resolveURL(a.URL))
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("download %s: HTTP %d", a.ID, resp.StatusCode)
	}
	total := a.Bytes
	if a.Member != "" {
		total = a.TarBytes
	}
	part := e.assetPath(a) + ".part"
	defer os.Remove(part)
	cnt := &countReader{r: io.LimitReader(resp.Body, total+1), f: func(n int64) { prog(n, total) }}
	if a.Member == "" {
		h := sha256.New()
		if err := writeFile(part, io.TeeReader(cnt, h)); err != nil {
			return err
		}
		if cnt.n != a.Bytes || hex.EncodeToString(h.Sum(nil)) != a.SHA256 {
			return fmt.Errorf("%s: the download does not match its pinned SHA-256 (got %d bytes); not installed", a.ID, cnt.n)
		}
		return os.Rename(part, e.assetPath(a))
	}
	// npm tarball: verify the whole archive (SHA-512 = npm's integrity), then the member.
	tarPart := part + ".tgz"
	defer os.Remove(tarPart)
	th := sha512.New()
	if err := writeFile(tarPart, io.TeeReader(cnt, th)); err != nil {
		return err
	}
	if cnt.n != a.TarBytes || hex.EncodeToString(th.Sum(nil)) != a.TarSHA512 {
		return fmt.Errorf("%s: the archive does not match its pinned SHA-512; not installed", a.ID)
	}
	if err := extractMember(tarPart, a.Member, part, a.Bytes); err != nil {
		return err
	}
	if !fileHashIs(part, sha256.New(), a.SHA256) {
		return fmt.Errorf("%s: the extracted file does not match its pinned SHA-256; not installed", a.ID)
	}
	return os.Rename(part, e.assetPath(a))
}

type countReader struct {
	r io.Reader
	n int64
	f func(int64)
	t time.Time
}

func (c *countReader) Read(p []byte) (int, error) {
	n, err := c.r.Read(p)
	c.n += int64(n)
	if time.Since(c.t) > 200*time.Millisecond || err != nil {
		c.t = time.Now()
		c.f(c.n)
	}
	return n, err
}

func writeFile(p string, r io.Reader) error {
	f, err := os.OpenFile(p, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	_, err = io.Copy(f, r)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	return err
}

func extractMember(tgz, member, dst string, max int64) error {
	f, err := os.Open(tgz)
	if err != nil {
		return err
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		return err
	}
	tr := tar.NewReader(gz)
	for {
		h, err := tr.Next()
		if err != nil {
			return fmt.Errorf("%s not in the archive", member)
		}
		if h.Name == member && h.Typeflag == tar.TypeReg {
			return writeFile(dst, io.LimitReader(tr, max+1))
		}
	}
}

func fileHashIs(p string, h hash.Hash, want string) bool {
	f, err := os.Open(p)
	if err != nil {
		return false
	}
	defer f.Close()
	if _, err := io.Copy(h, f); err != nil {
		return false
	}
	return hex.EncodeToString(h.Sum(nil)) == want
}

// startSegDownload: runtime first, then the model's file. One job per asset.
func (e *Engine) startSegDownload(m *SegModel) {
	for _, id := range []string{segRuntime, m.Asset} {
		a := segAsset(id)
		segS.mu.Lock()
		j := segS.jobs[id]
		if e.haveAsset(a) || (j != nil && j.Phase == "downloading") {
			segS.mu.Unlock()
			continue
		}
		j = &segJob{Asset: id, Phase: "downloading", Total: a.Bytes}
		segS.jobs[id] = j
		segS.mu.Unlock()
		go func(a *SegAsset, j *segJob) {
			err := e.fetchAsset(a, func(d, t int64) {
				segS.mu.Lock()
				j.Done, j.Total = d, t
				if t > 0 {
					j.Pct = float64(d) / float64(t)
				}
				segS.mu.Unlock()
			})
			segS.mu.Lock()
			if err != nil {
				j.Phase, j.Err = "failed", err.Error()
			} else {
				j.Phase, j.Pct = "done", 1
			}
			segS.mu.Unlock()
			if err != nil {
				e.log("config", "cut-out model download failed: "+err.Error(), map[string]any{"asset": a.ID})
			} else {
				e.log("config", fmt.Sprintf("cut-out model installed: %s (%.0f MB, SHA-256 verified)", a.Name, float64(a.Bytes)/1e6), map[string]any{"asset": a.ID})
			}
		}(a, j)
	}
}

type segStatus struct {
	Runtime SegAsset          `json:"runtime"`
	Have    map[string]bool   `json:"have"`
	Models  []SegModel        `json:"models"`
	Assets  []SegAsset        `json:"assets"`
	Jobs    map[string]segJob `json:"jobs"`
}

func (e *Engine) segStatus() segStatus {
	st := segStatus{Runtime: *segAsset(segRuntime), Have: map[string]bool{}, Models: segModels, Assets: segAssets, Jobs: map[string]segJob{}}
	for i := range segAssets {
		st.Have[segAssets[i].ID] = e.haveAsset(&segAssets[i])
	}
	segS.mu.Lock()
	for k, v := range segS.jobs {
		st.Jobs[k] = *v
	}
	segS.mu.Unlock()
	return st
}

var maskKey = regexp.MustCompile(`^[a-f0-9]{16,64}$`)

const maxMask = 4 << 20

// serveSeg handles /api/brain/x/seg/*.
func (e *Engine) serveSeg(w http.ResponseWriter, r *http.Request, p string, js func(any), bad func(int, string)) {
	switch {
	case p == "seg/status":
		js(e.segStatus())
	case p == "seg/download" && r.Method == http.MethodPost:
		var b struct{ Model string }
		_ = decodeSmall(r, &b)
		m := segModel(b.Model)
		if m == nil {
			bad(400, "unknown model")
			return
		}
		e.startSegDownload(m)
		js(e.segStatus())
	case strings.HasPrefix(p, "seg/file/"):
		a := segAsset(strings.TrimPrefix(p, "seg/file/"))
		if a == nil || !e.haveAsset(a) {
			bad(404, "not installed")
			return
		}
		ct := "application/octet-stream"
		if a.ID == segRuntime {
			ct = "application/wasm"
		}
		w.Header().Set("content-type", ct)
		w.Header().Set("cache-control", "private, max-age=31536000, immutable")
		f, err := os.Open(e.assetPath(a))
		if err != nil {
			bad(500, "cannot read")
			return
		}
		defer f.Close()
		http.ServeContent(w, r, a.ID, time.Time{}, f)
	case p == "seg/mask":
		key := r.URL.Query().Get("key")
		if !maskKey.MatchString(key) {
			bad(400, "bad key")
			return
		}
		dir := filepath.Join(e.dir, "masks")
		f := filepath.Join(dir, key+".png")
		switch r.Method {
		case http.MethodGet:
			b, err := os.ReadFile(f)
			if err != nil {
				http.NotFound(w, r)
				return
			}
			w.Header().Set("content-type", "image/png")
			_, _ = w.Write(b)
		case http.MethodPut:
			b, err := io.ReadAll(io.LimitReader(r.Body, maxMask+1))
			if err != nil || len(b) > maxMask || len(b) < 8 || string(b[1:4]) != "PNG" {
				bad(400, "send a PNG mask under 4 MB")
				return
			}
			_ = os.MkdirAll(dir, 0o700)
			if err := os.WriteFile(f, b, 0o600); err != nil {
				bad(500, "cannot save")
				return
			}
			js(map[string]bool{"ok": true})
		default:
			bad(405, "method not allowed")
		}
	default:
		bad(404, "no such seg endpoint")
	}
}
