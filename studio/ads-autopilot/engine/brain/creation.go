package brain

// Creation requests -- the owner (Nov 2026): ad CREATION runs on his Claude Max plan, with him present; the
// autopilot's unattended work stays on the API. "i don't need to involve credits, keep this OFF by default."
//
// Creation mode "with_me" (the default): when a cycle decides new ads are needed (the planner's copy requests:
// fatigue, free test slots, a season, a trending product) the brain does NOT write them through the API. It keeps
// a Creation request (accounts/<act>/creation.json, kept until done or dismissed), pushes it to the app over SSE at
// once (banner on Overview + Approvals) and raises a macOS notification. "Start" opens the Claude desktop app with
// the brief prefilled (claude://claude.ai/new?q=..., documented at support.claude.com/en/articles/14729294) and the
// brief on the clipboard; Claude then drives the app through the local MCP connector (engine/mcp.go).
// One step ahead: when the weekly batch day is less than 3 hours away, "creation due in ~N h" is sent once.
//
// "api" mode: the old behaviour (the planner's copy is written through the API: credits).
// FallbackHours (0 = off, the default): a request not started within that many hours is written through the API.
//
// Routes (httpx.go, /api/brain/x/):
//
//	GET  creation                {mode, fallback_hours, requests[], notifications[]}
//	POST creation/{id}/start     -> {brief, url}; opens Claude (Mac) and copies the brief
//	POST creation/{id}/dismiss
//	GET  brief                   what is needed now (the MCP get_brief tool)
//	GET  products                the in-stock catalogue (the MCP list_products tool)

import (
	"context"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"
)

const (
	CreationWithMe = "with_me"
	CreationAPI    = "api"
)

func normCreation(s *AccountSettings) {
	if s.CreationMode != CreationAPI {
		s.CreationMode = CreationWithMe
	}
	if s.FallbackHours < 0 || s.FallbackHours > 168 {
		s.FallbackHours = 0
	}
}

// CreationRequest: ads the autopilot needs and the owner makes (with Claude).
type CreationRequest struct {
	ID         string    `json:"id"`
	At         time.Time `json:"at"`
	Status     string    `json:"status"` // open | started | done | auto | dismissed
	Count      int       `json:"count"`
	Lang       string    `json:"lang"`   // ar | en | both
	Format     string    `json:"format"` // reels | image | carousel ...
	ProductIDs []string  `json:"product_ids"`
	Product    string    `json:"product"`
	Angle      string    `json:"angle"`
	Template   string    `json:"template,omitempty"`
	VideoID    string    `json:"video_id,omitempty"`
	Tiring     int       `json:"tiring"` // ads the rules call fatigued this cycle
	Title      string    `json:"title"`
	TitleAR    string    `json:"title_ar"`
	Brief      string    `json:"brief"`
	StartedAt  time.Time `json:"started_at,omitempty"`
	DoneAt     time.Time `json:"done_at,omitempty"`
	SessionID  string    `json:"session_id,omitempty"`
}

type Notice struct {
	At     time.Time `json:"at"`
	Kind   string    `json:"kind"` // needed | due_soon | auto
	Title  string    `json:"title"`
	Body   string    `json:"body"`
	BodyAR string    `json:"body_ar"`
	Req    string    `json:"request,omitempty"`
	Shown  bool      `json:"shown"` // the OS notification ran
}

func (e *Engine) creations(acct string) []CreationRequest {
	var r []CreationRequest
	e.load(acct, "creation", &r)
	return r
}

func (e *Engine) saveCreations(acct string, r []CreationRequest) {
	if len(r) > 100 {
		r = r[:100]
	}
	e.save(acct, "creation", r)
}

func openReq(s string) bool { return s == "open" || s == "started" }

// approvedFirst: the owner's approved creatives lead the library the planner sees (it is cut at 60).
func approvedFirst(cs []Creative) []Creative {
	out := make([]Creative, 0, len(cs))
	for _, c := range cs {
		if c.Approved {
			out = append(out, c)
		}
	}
	for _, c := range cs {
		if !c.Approved {
			out = append(out, c)
		}
	}
	return out
}

// creationAfterCycle: deferred copy -> requests (deduplicated), the "due soon" note, and the opt-in API fallback.
func (e *Engine) creationAfterCycle(ctx context.Context, acct string, b *Brain, in CycleInput, out *CycleOutput, st AccountSettings) {
	e.mu.Lock()
	reqs := e.creations(acct)
	e.mu.Unlock()
	tiring := 0
	for _, v := range out.Verdicts {
		if v.Level == "ad" && v.Verdict == "fatigued" {
			tiring++
		}
	}
	var fresh []CreationRequest
	for _, cr := range out.CreationNeeded {
		key := strings.Join(cr.ProductIDs, ",") + "|" + strings.ToLower(cr.Angle)
		dup := false
		for _, r := range reqs {
			if openReq(r.Status) && strings.Join(r.ProductIDs, ",")+"|"+strings.ToLower(r.Angle) == key {
				dup = true
			}
		}
		if dup {
			continue
		}
		r := newCreationRequest(cr, in, tiring)
		reqs = append([]CreationRequest{r}, reqs...)
		fresh = append(fresh, r)
	}
	// the opt-in fallback: written through the API (credits) only when the owner turned it on
	if st.FallbackHours > 0 && b.hasClaude() {
		for i := range reqs {
			r := &reqs[i]
			if r.Status != "open" || in.Now.Sub(r.At) < time.Duration(st.FallbackHours)*time.Hour {
				continue
			}
			cr := CopyRequest{ProductIDs: r.ProductIDs, Angle: r.Angle, Template: r.Template, Brief: r.Brief, PairID: "p" + r.ID, Wording: in.Limits.AdWording}
			sets, designs, usage, err := b.copyFor(ctx, cr, in.Shop, in.Research)
			addUsageTotals(&out.Usage, usage)
			if err != nil {
				out.Dropped = append(out.Dropped, "creation fallback "+r.ID+": "+err.Error())
				continue
			}
			out.CopySets = append(out.CopySets, sets...)
			out.Designs = append(out.Designs, designs...)
			r.Status, r.DoneAt = "auto", in.Now
			e.notify(acct, Notice{Kind: "auto", Title: "Created automatically (API)", Req: r.ID,
				Body:   fmt.Sprintf("You had not started \"%s\" within %d h, so the autopilot wrote it through the API (credits).", r.Title, st.FallbackHours),
				BodyAR: fmt.Sprintf("لم تبدئي «%s» خلال %d ساعة، فكتبه الطيار الآلي عبر الواجهة البرمجية (برصيد).", r.TitleAR, st.FallbackHours)})
		}
	}
	e.mu.Lock()
	// merge with what may have changed meanwhile (a Start pressed during the cycle wins)
	cur := e.creations(acct)
	byID := map[string]CreationRequest{}
	for _, r := range cur {
		byID[r.ID] = r
	}
	for i := range reqs {
		if c, ok := byID[reqs[i].ID]; ok && c.Status != "open" && reqs[i].Status == "open" {
			reqs[i] = c
		}
	}
	e.saveCreations(acct, reqs)
	e.mu.Unlock()
	for _, r := range fresh {
		e.notify(acct, Notice{Kind: "needed", Title: "New ads needed", Req: r.ID, Body: r.Title + ". Start creating.", BodyAR: r.TitleAR + ". ابدئي الإنشاء."})
	}
	// one step ahead: the weekly batch opens soon
	if st.CreationMode == CreationWithMe {
		cfg := b.Rules
		if cfg.BatchWeekday == 0 && cfg.MaxNewAdsBatch == 0 {
			cfg = DefaultRules()
		}
		now := in.Now
		next := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, now.Location())
		for next.Weekday() != cfg.BatchWeekday || !next.After(now) {
			next = next.AddDate(0, 0, 1)
		}
		if h := next.Sub(now).Hours(); h > 0 && h <= 3 {
			day := next.Format("2006-01-02")
			sent := map[string]bool{}
			e.load(acct, "creation_ahead", &sent)
			if !sent[day] {
				sent[day] = true
				e.save(acct, "creation_ahead", sent)
				n := fmt.Sprintf("%.0f", h+0.49)
				e.notify(acct, Notice{Kind: "due_soon", Title: "Creation due in ~" + n + " h",
					Body: "New test slots open in about " + n + " hours. Have a few minutes with Claude?", BodyAR: "تُفتح خانات اختبار جديدة خلال نحو " + n + " ساعات. هل لديكِ دقائق مع Claude؟"})
			}
		}
	}
}

func newCreationRequest(cr CopyRequest, in CycleInput, tiring int) CreationRequest {
	prods := productsByID(in.Shop, cr.ProductIDs)
	name := strings.Join(cr.ProductIDs, ", ")
	if len(prods) > 0 {
		name = shortName(prods[0].Title)
	}
	format, formatAR := "ads", "إعلانات"
	if cr.VideoID != "" || strings.Contains(strings.ToLower(cr.Brief+cr.Angle), "reel") {
		format, formatAR = "Reels", "ريلز"
	}
	r := CreationRequest{ID: fmt.Sprintf("cq%x", hash32(fmt.Sprint(in.Now.UnixNano(), cr.ProductIDs, cr.Angle))), At: in.Now, Status: "open", Count: 2, Lang: "both",
		Format: format, ProductIDs: cr.ProductIDs, Product: name, Angle: cr.Angle, Template: cr.Template, VideoID: cr.VideoID, Tiring: tiring}
	why, whyAR := "", ""
	if tiring > 0 {
		why, whyAR = fmt.Sprintf(" (%d ads tiring)", tiring), fmt.Sprintf(" (%d إعلانات بدأت تتعب)", tiring)
	}
	r.Title = fmt.Sprintf("Arabic + English %s needed for %s — %s%s", format, name, cr.Angle, why)
	r.TitleAR = fmt.Sprintf("مطلوب %s بالعربية والإنجليزية لـ %s — %s%s", formatAR, name, cr.Angle, whyAR)
	r.Brief = creationBrief(r, cr.Brief)
	return r
}

func shortName(t string) string {
	parts := strings.Split(t, " - ")
	if len(parts) >= 2 {
		return strings.TrimSpace(parts[0] + " " + parts[1])
	}
	return t
}

// creationBrief: the text pasted in Claude (or prefilled through the deep link).
func creationBrief(r CreationRequest, extra string) string {
	var b strings.Builder
	fmt.Fprintf(&b, "Use the KBB Ads Autopilot connector. Creation request %s:\n", r.ID)
	fmt.Fprintf(&b, "Make %d ad ideas for %s (product ids %s), angle: %s. Arabic first, then English. Formats 1:1, 4:5 and 9:16", r.Count, r.Product, strings.Join(r.ProductIDs, ", "), r.Angle)
	if r.Format == "Reels" {
		b.WriteString(", with a motion version for Reels")
	}
	b.WriteString(".\n")
	if extra != "" {
		fmt.Fprintf(&b, "Brief: %s\n", truncate(extra, 300))
	}
	b.WriteString("Steps: call get_brief, then create_ad_set with request_id " + r.ID + " and show me the previews. I will tell you what to change (revise). When I say so: save_to_library, then run_it.")
	return b.String()
}

// ---------- notifications: SSE at once + the Mac's own notification ----------

// notifyCmd: tests point KAA_NOTIFY_CMD at a stub (called with title, body); on a Mac osascript shows it.
func notifyOS(title, body string) bool {
	if c := os.Getenv("KAA_NOTIFY_CMD"); c != "" {
		return exec.Command(c, title, body).Run() == nil
	}
	if runtime.GOOS != "darwin" {
		return false
	}
	q := func(s string) string { return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`).Replace(s) + `"` }
	return exec.Command("osascript", "-e", "display notification "+q(body)+" with title "+q("KBB Ads Autopilot")+" subtitle "+q(title)).Run() == nil
}

func (e *Engine) notify(acct string, n Notice) {
	n.At = time.Now().UTC()
	n.Shown = notifyOS(n.Title, n.Body)
	e.mu.Lock()
	var ns []Notice
	e.load(acct, "notices", &ns)
	ns = append([]Notice{n}, ns...)
	if len(ns) > 40 {
		ns = ns[:40]
	}
	e.save(acct, "notices", ns)
	e.mu.Unlock()
	e.push(acct)
	e.log("alert", "creation: "+n.Title+" — "+n.Body, n)
}

// push: the creation state to every open tab, at once (no polling).
func (e *Engine) push(acct string) {
	if e.deps.Publish != nil {
		e.deps.Publish("creation", map[string]any{"account": acct, "data": e.creationView(acct)})
	}
}

func (e *Engine) creationView(acct string) map[string]any {
	st := e.settings(acct)
	e.mu.Lock()
	reqs := e.creations(acct)
	var ns []Notice
	e.load(acct, "notices", &ns)
	e.mu.Unlock()
	if reqs == nil {
		reqs = []CreationRequest{}
	}
	if ns == nil {
		ns = []Notice{}
	}
	return map[string]any{"mode": st.CreationMode, "fallback_hours": st.FallbackHours, "requests": reqs, "notifications": ns}
}

// claudeURL: the Claude desktop app's documented new-chat link with the prompt prefilled (q is ~14,000 chars max).
func claudeURL(brief string) string {
	if len(brief) > 12000 {
		brief = brief[:12000]
	}
	return "claude://claude.ai/new?q=" + url.QueryEscape(brief)
}

// openClaude: on a Mac, the brief goes on the clipboard and Claude opens with it prefilled. Tests: KAA_OPEN_CMD.
func openClaude(u, brief string) bool {
	if c := os.Getenv("KAA_OPEN_CMD"); c != "" {
		return exec.Command(c, u).Run() == nil
	}
	if runtime.GOOS != "darwin" {
		return false
	}
	pb := exec.Command("pbcopy")
	pb.Stdin = strings.NewReader(brief)
	_ = pb.Run()
	if exec.Command("open", u).Run() == nil {
		return true
	}
	return exec.Command("open", "-a", "Claude").Run() == nil
}

// markCreation: a request moves (start / dismiss / done by a create session). Idempotent.
func (e *Engine) markCreation(acct, id, status, session string) (CreationRequest, bool) {
	e.mu.Lock()
	reqs := e.creations(acct)
	var out CreationRequest
	ok := false
	for i := range reqs {
		if reqs[i].ID != id {
			continue
		}
		r := &reqs[i]
		ok = true
		switch status {
		case "started":
			if r.Status == "open" {
				r.Status, r.StartedAt = "started", time.Now().UTC()
			}
		case "done":
			if openReq(r.Status) {
				r.Status, r.DoneAt = "done", time.Now().UTC()
			}
			if session != "" {
				r.SessionID = session
			}
		case "dismissed":
			if openReq(r.Status) {
				r.Status = "dismissed"
			}
		}
		out = *r
	}
	if ok {
		e.saveCreations(acct, reqs)
	}
	e.mu.Unlock()
	if ok {
		e.push(acct)
	}
	return out, ok
}

func (e *Engine) serveCreation(w http.ResponseWriter, r *http.Request, p, acct string, in func() CycleInput, js func(any), bad func(int, string)) {
	switch {
	case p == "creation" && r.Method == http.MethodGet:
		js(e.creationView(acct))
	case strings.HasPrefix(p, "creation/") && r.Method == http.MethodPost:
		parts := strings.Split(strings.TrimPrefix(p, "creation/"), "/")
		if len(parts) != 2 {
			bad(404, "no such route")
			return
		}
		switch parts[1] {
		case "start":
			cr, ok := e.markCreation(acct, parts[0], "started", "")
			if !ok {
				bad(404, "no such creation request")
				return
			}
			u := claudeURL(cr.Brief)
			opened := openClaude(u, cr.Brief)
			js(map[string]any{"request": cr, "brief": cr.Brief, "url": u, "opened": opened})
		case "dismiss":
			cr, ok := e.markCreation(acct, parts[0], "dismissed", "")
			if !ok {
				bad(404, "no such creation request")
				return
			}
			js(cr)
		default:
			bad(404, "no such route")
		}
	case p == "brief":
		js(e.brief(acct, in()))
	case p == "products":
		var out []map[string]any
		for _, x := range in().Shop.Products {
			if x.InStock && x.Image != "" {
				out = append(out, map[string]any{"id": x.ID, "title": x.Title, "brand": x.Brand, "type": x.Type, "price_aed": x.PriceAED})
			}
		}
		js(out)
	default:
		bad(404, "no such route")
	}
}

// brief: what is needed now, for Claude (the MCP get_brief tool) -- small, factual, Arabic first.
func (e *Engine) brief(acct string, in CycleInput) map[string]any {
	var last CycleOutput
	e.load(acct, "last_cycle", &last)
	var fatigued []map[string]any
	for _, v := range last.Verdicts {
		if v.Level == "ad" && (v.Verdict == "fatigued" || v.Verdict == "kill" || v.Verdict == "cut") && len(fatigued) < 10 {
			fatigued = append(fatigued, map[string]any{"ad": v.Object, "verdict": v.Verdict, "why": v.Why})
		}
	}
	tr := e.trending()
	var top []map[string]any
	idx := map[string]Product{}
	for _, p := range in.Shop.Products {
		idx[p.ID] = p
	}
	for _, id := range tr.Selling {
		if p, ok := idx[id]; ok && p.InStock && len(top) < 8 {
			top = append(top, map[string]any{"id": p.ID, "title": p.Title, "price_aed": p.PriceAED, "trending": true})
		}
	}
	for _, p := range in.Shop.Products {
		if len(top) >= 8 {
			break
		}
		if p.InStock && p.Image != "" && !contains(tr.Selling, p.ID) {
			top = append(top, map[string]any{"id": p.ID, "title": p.Title, "price_aed": p.PriceAED})
		}
	}
	rr := e.richResearch()
	var notes []map[string]any
	for _, n := range rr.Notes {
		if len(notes) < 8 {
			notes = append(notes, map[string]any{"topic": n.Topic, "title": n.Title, "angles": n.Angles})
		}
	}
	var open []map[string]any
	for _, r := range e.creations(acct) {
		if openReq(r.Status) {
			open = append(open, map[string]any{"id": r.ID, "title": r.Title, "product_ids": r.ProductIDs, "angle": r.Angle, "count": r.Count})
		}
	}
	st := e.settings(acct)
	return map[string]any{
		"account": acct, "open_requests": open, "products_to_push": top, "tiring_ads": fatigued, "research": notes,
		"plan_summary": last.Plan.Summary,
		"brand_rules": []string{
			"Arabic first: every idea has an Arabic set (Modern Standard Arabic, warm and Gulf-friendly, feminine address) and an English set; Arabic is written, not translated.",
			"Policy (wording: " + firstNonEmpty(in.Limits.AdWording, st.AdWording) + "): no cure/treat/علاج claims, no before/after, no condition-shaming questions, no whitening/تبييض, no '#1', no discounts unless a promotion is approved (" + fmt.Sprint(st.PromoApproved) + ").",
			"Headline <= 40 characters; 2-4 short lines of primary text; CTA SHOP_NOW unless asked.",
			"Design families: hero glow ingredient offer routine editorial petals texture qa collection. Formats 1:1, 4:5, 9:16 (+ motion for Reels).",
			"The real product photo is always used; an AI background prompt describes only the set (no product, no text, no logos, no faces).",
		},
		"how": "create_ad_set renders previews in the app; revise changes only the fields you send; save_to_library runs the quality gate; run_it hands the saved creatives to the autopilot (limits and Practice/Auto apply).",
	}
}
