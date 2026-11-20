package brain

// The brain's own routes, mounted by engine/brain_wire.go under /api/brain/x/
// behind the engine's token / origin / account checks:
//
//	GET  cycle            the last full cycle for the account: verdicts, designs to render, copy sets, profile, rich plan
//	POST design-done      {id} -- the UI rendered and POSTed that design's PNGs
//	GET/PUT settings      {ad_wording: strict|balanced|relaxed, promo_approved}
//	GET  profile          the account's learned profile (built from its full history now)
//	GET  research         the rich research (AR + EN, angles, sources)
//	GET/POST trending     trending products matched to the shop (POST = refresh now)
//	GET  videos           the owner's videos with analysis
//	POST videos           raw upload (X-Filename, X-Status: new|past) -> copied once into <data>/videos/
//	POST videos/{id}/analyse  {duration_s, width, height, has_audio, frames[{t,jpeg}], results?, notes?}
//	GET  videos/{id}/thumb
//	PUT  videos/{id}      {status?, results?}

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"kaa/model"
)

const maxVideo = 2 << 30 // 2 GB

func (e *Engine) ServeBrain(w http.ResponseWriter, r *http.Request, acct string, input func() model.CycleInput) {
	p := strings.TrimPrefix(r.URL.Path, "/api/brain/x/")
	js := func(v any) { w.Header().Set("content-type", "application/json"); _ = json.NewEncoder(w).Encode(v) }
	bad := func(code int, msg string) { writeErr(w, code, errString(msg)) }
	ctx, cancel := context.WithTimeout(r.Context(), 10*time.Minute)
	defer cancel()
	switch {
	case p == "cycle" && r.Method == http.MethodGet:
		var c CycleOutput
		e.load(acct, "last_cycle", &c)
		done := map[string]bool{}
		e.load(acct, "designs_done", &done)
		var pend []DesignSpec
		for _, d := range c.Designs {
			if !done[d.ID] {
				pend = append(pend, d)
			}
		}
		c.Designs = pend
		js(c)
	case p == "design-done" && r.Method == http.MethodPost:
		var b struct{ ID string }
		_ = json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&b)
		done := map[string]bool{}
		e.load(acct, "designs_done", &done)
		done[b.ID] = true
		e.save(acct, "designs_done", done)
		js(map[string]bool{"ok": true})
	case p == "settings":
		if r.Method == http.MethodPut {
			var s AccountSettings
			if json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&s) != nil {
				bad(400, "bad JSON")
				return
			}
			if s.AdWording != Strict && s.AdWording != Relaxed {
				s.AdWording = Balanced
			}
			e.save(acct, "settings", s)
			e.log("config", fmt.Sprintf("ad wording rules: %s, promotion approved: %v", s.AdWording, s.PromoApproved), s)
		}
		js(e.settings(acct))
	case p == "profile":
		in := e.FromModel(input())
		js(BuildProfile(acct, in))
	case p == "research":
		js(e.richResearch())
	case p == "trending":
		if r.Method == http.MethodPost {
			in := input()
			tr, err := e.B.Trending(ctx, shopFromModel(in.Shop), time.Now())
			if err != nil {
				bad(502, err.Error())
				return
			}
			e.save("", "trending", tr)
		}
		tr := e.trending()
		if in := input(); len(in.Shop.Products) > 0 && !tr.UpdatedAt.IsZero() {
			MatchTrending(&tr, shopFromModel(in.Shop)) // stock changes since the search
		}
		js(tr)
	case p == "videos" && r.Method == http.MethodGet:
		js(e.videos(acct))
	case p == "videos" && r.Method == http.MethodPost:
		name := filepath.Base(strings.TrimSpace(urlDecode(r.Header.Get("X-Filename"))))
		ext := strings.ToLower(filepath.Ext(name))
		if !map[string]bool{".mp4": true, ".mov": true, ".m4v": true, ".webm": true}[ext] {
			bad(400, "send an .mp4, .mov, .m4v or .webm file")
			return
		}
		id := fmt.Sprintf("v%x", time.Now().UnixNano())
		dir := filepath.Join(e.deps.DataDir, "videos")
		_ = os.MkdirAll(dir, 0o700)
		dst := filepath.Join(dir, id+ext)
		f, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_EXCL, 0o600)
		if err != nil {
			bad(500, err.Error())
			return
		}
		n, err := io.Copy(f, io.LimitReader(r.Body, maxVideo))
		f.Close()
		if err != nil || n == 0 {
			os.Remove(dst)
			bad(400, "upload failed")
			return
		}
		status := "new"
		if r.Header.Get("X-Status") == "past" {
			status = "past"
		}
		v := VideoAsset{ID: id, Name: name, Path: filepath.Join("videos", id+ext), Bytes: n, Status: status, AddedAt: time.Now()}
		// past videos: match the account's ads by name (stem in the ad name)
		stem := strings.ToLower(strings.TrimSuffix(name, ext))
		if len(stem) > 3 {
			for _, c := range input().Tree {
				for _, s := range c.AdSets {
					for _, a := range s.Ads {
						if strings.Contains(strings.ToLower(a.Name), stem) {
							v.MetaAdIDs = append(v.MetaAdIDs, a.ID)
						}
					}
				}
			}
		}
		e.mu.Lock()
		vs := append([]VideoAsset{v}, e.videos(acct)...)
		e.save(acct, "videos", vs)
		e.mu.Unlock()
		e.log("config", fmt.Sprintf("video added: %s (%.1f MB)", name, float64(n)/1048576), map[string]any{"id": id})
		js(v)
	case strings.HasPrefix(p, "videos/"):
		parts := strings.Split(strings.TrimPrefix(p, "videos/"), "/")
		id := safeName(parts[0])
		e.mu.Lock()
		vs := e.videos(acct)
		e.mu.Unlock()
		idx := -1
		for i := range vs {
			if vs[i].ID == id {
				idx = i
			}
		}
		if idx < 0 {
			bad(404, "no such video")
			return
		}
		v := &vs[idx]
		thumb := filepath.Join(e.deps.DataDir, "videos", id+".jpg")
		switch {
		case len(parts) == 2 && parts[1] == "thumb":
			b, err := os.ReadFile(thumb)
			if err != nil {
				http.NotFound(w, r)
				return
			}
			w.Header().Set("content-type", "image/jpeg")
			_, _ = w.Write(b)
			return
		case len(parts) == 2 && parts[1] == "analyse" && r.Method == http.MethodPost:
			var in VideoInput
			if json.NewDecoder(io.LimitReader(r.Body, 12<<20)).Decode(&in) != nil {
				bad(400, "bad JSON")
				return
			}
			in.ID, in.Name, in.Wording = v.ID, v.Name, e.settings(acct).AdWording
			if len(in.Frames) > 0 {
				if b, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(in.Frames[0].JPEG, "data:image/jpeg;base64,")); err == nil && len(b) < 512<<10 {
					_ = os.WriteFile(thumb, b, 0o600)
					v.Thumb = "/api/brain/x/videos/" + id + "/thumb"
				}
			}
			if in.Results != nil {
				in.Results.Source = "owner"
				v.Results = in.Results
			} else {
				in.Results = v.Results
			}
			a, u, err := e.B.AnalyzeVideo(ctx, in, shopFromModel(input().Shop))
			if err != nil {
				a.Model = "local-only (" + err.Error() + ")"
			}
			v.Analysis = &a
			e.log("claude", fmt.Sprintf("video analysed: %s -- score %d, %s, %.1fs ($%.3f)", v.Name, a.Score, a.Aspect, a.DurationS, u.CostUSD), map[string]any{"id": id})
		case len(parts) == 1 && r.Method == http.MethodPut:
			var b struct {
				Status  string        `json:"status"`
				Results *VideoResults `json:"results"`
			}
			_ = json.NewDecoder(io.LimitReader(r.Body, 8192)).Decode(&b)
			if oneOf(b.Status, []string{"new", "past", "running", "retired"}, "") != "" {
				v.Status = b.Status
			}
			if b.Results != nil {
				b.Results.Source = "owner"
				v.Results = b.Results
			}
		default:
			bad(405, "method not allowed")
			return
		}
		e.mu.Lock()
		cur := e.videos(acct)
		for i := range cur {
			if cur[i].ID == v.ID {
				cur[i] = *v
			}
		}
		e.save(acct, "videos", cur)
		e.mu.Unlock()
		js(v)
	default:
		bad(404, "no such brain endpoint")
	}
}

func (e *Engine) videos(acct string) []VideoAsset {
	vs := []VideoAsset{}
	e.load(acct, "videos", &vs)
	return vs
}

func urlDecode(s string) string {
	out := strings.Builder{}
	for i := 0; i < len(s); i++ {
		if s[i] == '%' && i+2 < len(s) {
			var b byte
			if _, err := fmt.Sscanf(s[i+1:i+3], "%02x", &b); err == nil {
				out.WriteByte(b)
				i += 2
				continue
			}
		}
		out.WriteByte(s[i])
	}
	return out.String()
}
