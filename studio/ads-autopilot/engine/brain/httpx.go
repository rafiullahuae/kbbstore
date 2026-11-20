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
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
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
			s := e.settings(acct) // fields not sent keep their value
			if json.NewDecoder(io.LimitReader(r.Body, 4096)).Decode(&s) != nil {
				bad(400, "bad JSON")
				return
			}
			if s.AdWording != Strict && s.AdWording != Relaxed {
				s.AdWording = Balanced
			}
			normCreation(&s)
			e.save(acct, "settings", s)
			e.log("config", fmt.Sprintf("ad wording rules: %s, promotion approved: %v", s.AdWording, s.PromoApproved), s)
		}
		js(e.settings(acct))
	case p == "img":
		e.serveImg(w, r)
	case strings.HasPrefix(p, "seg/"): // local cut-out models (seg.go)
		e.serveSeg(w, r, p, js, bad)
	case strings.HasPrefix(p, "gen/"): // AI background generators (generator.go)
		e.serveGen(w, r, p, js, bad)
	case p == "create" || strings.HasPrefix(p, "create/"): // Create with Claude (create.go)
		mi := input()
		e.serveCreate(w, r, p, acct, func() CycleInput { return e.FromModel(mi) }, Wording(mi.Limits, e.settings(acct)), bad, js)
	case p == "creation" || strings.HasPrefix(p, "creation/") || p == "brief" || p == "products": // creation.go
		e.serveCreation(w, r, p, acct, func() CycleInput { return e.FromModel(input()) }, js, bad)
	case p == "designs":
		arch := []DesignSpec{}
		e.load(acct, "designs_archive", &arch)
		js(arch)
	case p == "copy" && r.Method == http.MethodPost: // AR+EN copy + design specs (the UI renders them)
		var req CopyRequest
		if json.NewDecoder(io.LimitReader(r.Body, 64<<10)).Decode(&req) != nil || len(req.ProductIDs) == 0 {
			bad(400, "send {product_ids:[...], angle, template}")
			return
		}
		if len(req.ProductIDs) > 3 {
			req.ProductIDs = req.ProductIDs[:3]
		}
		req.Template = oneOf(req.Template, templateIDs, "")
		req.Wording = Wording(input().Limits, e.settings(acct))
		res, err := e.B.Copy(ctx, req, shopFromModel(input().Shop), e.richResearch())
		if err != nil {
			bad(502, err.Error())
			return
		}
		arch := []DesignSpec{}
		e.load(acct, "designs_archive", &arch)
		e.save(acct, "designs_archive", append(arch, res.Designs...))
		js(res)
	case p == "strategy": // Strategy screen: how it decides, with this account's live numbers
		js(e.strategy(acct, e.FromModel(input())))
	case p == "learning" && r.Method == http.MethodGet: // What it learned: weekly report, health, lessons, tuning
		js(e.learning(acct, e.FromModel(input())))
	case p == "learning/reset-tuning" && r.Method == http.MethodPost: // undo the self-tuned thresholds
		var L Ledger
		e.load(acct, "ledger", &L)
		was := L.Tuned
		L.Tuned = Tuned{}
		L.Changes = append(L.Changes, time.Now().Format("2006-01-02")+": you reset the self-tuned thresholds to your own / the defaults")
		e.save(acct, "ledger", L)
		e.log("learning", "owner reset the self-tuned thresholds", was)
		js(e.learning(acct, e.FromModel(input())))
	case p == "learning/apply-proposal" && r.Method == http.MethodPost: // "ask me" mode: the owner applies the proposal
		var L Ledger
		e.load(acct, "ledger", &L)
		if L.Tuned.HardStopMult <= 0 || L.Tuned.Applied {
			bad(409, "no proposal is waiting")
			return
		}
		L.Tuned.Applied = true
		L.Changes = append(L.Changes, time.Now().Format("2006-01-02")+fmt.Sprintf(": you applied the proposal: stop after %.1f× break-even CPA", L.Tuned.HardStopMult))
		e.save(acct, "ledger", L)
		e.log("learning", "owner applied the self-tuning proposal", L.Tuned)
		js(e.learning(acct, e.FromModel(input())))
	case p == "insights":
		js(Insights(e.FromModel(input())))
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
			in.ID, in.Name, in.Wording = v.ID, v.Name, Wording(input().Limits, e.settings(acct))
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

// Insights: what the overview charts need that the engine state does not
// carry: 30 daily points (spend, revenue, purchases, CPC, CPM, CTR as a
// fraction) and the Arabic/English split over 30 settled days.
func Insights(in CycleInput) map[string]any {
	from := in.Now.AddDate(0, 0, -30).Format("2006-01-02")
	days := map[string]*Agg{}
	var order []string
	lang := map[string]string{}
	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				lang[a.ID] = firstNonEmpty(a.Language, s.Language)
			}
		}
	}
	split := map[string]*Agg{"ar": {}, "en": {}}
	for _, r := range in.Metrics {
		if r.Date < from || r.Placement != "" {
			continue
		}
		if r.Level == "campaign" {
			if days[r.Date] == nil {
				days[r.Date] = &Agg{}
				order = append(order, r.Date)
			}
			days[r.Date].add(r)
		}
		if r.Level == "ad" {
			if g := split[lang[r.ObjectID]]; g != nil {
				g.add(r)
			}
		}
	}
	sortStrings(order)
	series := []map[string]any{}
	for _, d := range order {
		a := days[d]
		cpc := 0.0
		if a.Clicks > 0 {
			cpc = a.Spend / float64(a.Clicks)
		}
		series = append(series, map[string]any{"date": d, "spend": round2(a.Spend), "revenue": round2(a.Revenue), "purchases": a.Purch, "cpc": round2(cpc), "cpm": round2(a.CPM()), "ctr": round4(a.CTR())})
	}
	sp := map[string]any{}
	for k, a := range split {
		sp[k] = map[string]any{"spend": round2(a.Spend), "purchases": a.Purch, "revenue": round2(a.Revenue), "cpa": round2(a.CPA()), "roas": round2(a.ROAS()), "ctr": round4(a.CTR())}
	}
	return map[string]any{"series": series, "split": sp}
}

// serveImg: same-origin copies of the shop's product photos so the canvas that
// draws ads stays untainted and can export PNG. kbeautybliss.com over https
// only, images only, <= 8 MB, cached under DataDir/brain/img.
func (e *Engine) serveImg(w http.ResponseWriter, r *http.Request) {
	raw := r.URL.Query().Get("u")
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "https" || !(u.Hostname() == "kbeautybliss.com" || strings.HasSuffix(u.Hostname(), ".kbeautybliss.com")) {
		writeErr(w, 400, errString("only https://kbeautybliss.com images"))
		return
	}
	sum := sha256.Sum256([]byte(raw))
	dir := filepath.Join(e.dir, "img")
	_ = os.MkdirAll(dir, 0o700)
	f := filepath.Join(dir, hex.EncodeToString(sum[:12]))
	b, err := os.ReadFile(f)
	ct := ""
	if err == nil {
		ct = http.DetectContentType(b)
	} else {
		c := &http.Client{Timeout: 20 * time.Second}
		resp, err := c.Get(u.String())
		if err != nil {
			writeErr(w, 502, err)
			return
		}
		defer resp.Body.Close()
		b, _ = io.ReadAll(io.LimitReader(resp.Body, 8<<20))
		ct = http.DetectContentType(b)
		if resp.StatusCode != 200 || !strings.HasPrefix(ct, "image/") {
			writeErr(w, 502, errString("not an image"))
			return
		}
		_ = os.WriteFile(f, b, 0o600)
	}
	w.Header().Set("content-type", ct)
	w.Header().Set("cache-control", "max-age=86400")
	_, _ = w.Write(b)
}
