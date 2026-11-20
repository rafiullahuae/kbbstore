package brain

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
	"time"
)

// Deps is what the engine hands the brain's HTTP handler. The engine owns
// storage, the scheduler and the executor; the brain only computes.
type Deps struct {
	// Input assembles the current CycleInput (tree, metrics, shop, library, research, history).
	Input func(ctx context.Context) (CycleInput, error)
	// Result receives every output ("plan" CycleOutput, "research" Research,
	// "copy" CopyResult) so the engine can persist/log it and push SSE events.
	Result func(kind string, v any)
}

// Handler serves POST /api/brain/plan | /api/brain/research | /api/brain/copy.
// Mount it at "/api/brain/" behind the engine's X-KAA token check. A plan
// here is computed and returned; whether anything executes is the
// executor's decision (mode off/shadow/auto), never the brain's.
func Handler(b *Brain, d Deps) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "POST only", http.StatusMethodNotAllowed)
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 10*time.Minute)
		defer cancel()
		in, err := d.Input(ctx)
		if err != nil {
			writeErr(w, http.StatusServiceUnavailable, err)
			return
		}
		var out any
		kind := strings.TrimPrefix(r.URL.Path[strings.LastIndex(r.URL.Path, "/"):], "/")
		switch kind {
		case "plan":
			out, err = b.Cycle(ctx, in)
		case "research":
			var body struct {
				Focus []string `json:"focus"`
			}
			_ = json.NewDecoder(http.MaxBytesReader(w, r.Body, 64<<10)).Decode(&body)
			out, err = b.Research(ctx, ResearchInput{Now: in.Now, Shop: in.Shop, Previous: in.Research, Focus: clean(body.Focus, 6)})
		case "copy":
			var req CopyRequest
			if e := json.NewDecoder(http.MaxBytesReader(w, r.Body, 64<<10)).Decode(&req); e != nil || len(req.ProductIDs) == 0 {
				writeErr(w, http.StatusBadRequest, errString("send {product_ids:[...], angle, template, brief}"))
				return
			}
			if len(req.ProductIDs) > 3 {
				req.ProductIDs = req.ProductIDs[:3]
			}
			req.Template = oneOf(req.Template, templateIDs, "")
			if req.Wording == "" {
				req.Wording = in.Limits.AdWording
			}
			out, err = b.Copy(ctx, req, in.Shop, in.Research)
		default:
			http.NotFound(w, r)
			return
		}
		if err != nil {
			writeErr(w, http.StatusBadGateway, err)
			return
		}
		if d.Result != nil {
			d.Result(kind, out)
		}
		w.Header().Set("content-type", "application/json")
		_ = json.NewEncoder(w).Encode(out)
	})
}

type errString string

func (e errString) Error() string { return string(e) }

func writeErr(w http.ResponseWriter, code int, err error) {
	w.Header().Set("content-type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(map[string]string{"error": err.Error()})
}
