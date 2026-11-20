package brain

// "Create with Claude" -- the owner: "if i want to create ad in my system but
// through claude, will it be there or not? ... the creation part i need to
// make it super easy, not from 100s different apps / models".
//
// ONE box. The owner types what he wants (Arabic or English) or taps chips,
// optionally attaching his own photo or video. ONE structured Claude call
// (strict `respond` tool, model.LLM) picks the products, angle, hook, design
// family, formats, Arabic + English copy and -- only when the one "AI pictures
// & video" key is set -- one scene prompt. The copy goes through the policy
// linter (the account's strict / balanced / relaxed rules) exactly like the
// planner's copy; the app renders every size locally and runs the quality
// gate. Nothing here talks to Meta.
//
// A follow-up ("make the headline shorter", "more pink", "swap to the toner")
// is a second call on the same session. Claude answers with the whole set plus
// `changed` -- the fields it touched -- and ONLY those fields are taken from the
// new answer (mergeIdea); everything else stays byte-identical, so the app
// re-renders only the designs whose spec moved.
//
// Routes (mounted by httpx.go under /api/brain/x/):
//
//	POST create              {prompt, product_ids, occasion, formats, langs, video_id, photo, session_id?, edit?}
//	                         -> NDJSON: {"step":"think"} {"step":"lint"} {"step":"done","session":{...}} | {"step":"error","error":"..."}
//	GET  create              the account's sessions, newest first, each with its outcome (ran / won)
//	PUT  create/{id}         {used:[design ids], saved:[creative ids], ran:[creative ids], proposals:[ids]} -- what the owner did

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"
)

// CreateRequest: what the box sends.
type CreateRequest struct {
	Prompt     string   `json:"prompt"`
	ProductIDs []string `json:"product_ids"` // the product chip (optional)
	Occasion   string   `json:"occasion"`    // chip: ramadan | eid | white_friday | national_day | mothers_day | summer | everyday
	Formats    []string `json:"formats"`     // chip: 1:1 | 4:5 | 9:16 (empty = Claude decides)
	Langs      []string `json:"langs"`       // chip: ar | en (empty = both)
	VideoID    string   `json:"video_id"`    // the owner's attached video (uploaded through videos)
	Photo      bool     `json:"photo"`       // the owner attached his own photo (kept in the app; designs use it)
	SessionID  string   `json:"session_id"`  // follow-up on this session
	Edit       string   `json:"edit"`        // the follow-up instruction
	Target     int      `json:"target"`      // follow-up on one idea (1-based); 0 = all
	// Key: the caller's idempotency key. The same key twice gives the SAME session (a retried tool call, a double
	// click, a dropped connection), never a second creation; a second call while the first runs waits for it.
	Key string `json:"key"`
	// RequestID: the Creation request this answers (marked done when the session exists).
	RequestID string `json:"request_id"`
	// Authored: the ideas written by Claude in the owner's own Claude app (Max plan, through the MCP connector).
	// The engine makes NO model call for them: they go through the same catalogue check, linter and designs.
	Authored []CreateIdea `json:"authored"`
	// Revise: an authored follow-up -- only the fields present change (MCP revise).
	Revise *Revision `json:"revise"`
}

// Revision: the MCP revise tool. Fields left out (nil) do not change; per-language copy goes in AR / EN.
type Revision struct {
	Idea       int        `json:"idea"` // 1-based; 0 = every idea
	ProductIDs []string   `json:"product_ids"`
	Angle      *string    `json:"angle"`
	Hook       *string    `json:"hook"`
	Template   *string    `json:"template"`
	Palette    []string   `json:"palette"`
	ShowPrice  *bool      `json:"show_price"`
	Formats    []string   `json:"formats"`
	Motion     *bool      `json:"motion"`
	Scene      *string    `json:"scene"`
	AR         *SetChange `json:"ar"`
	EN         *SetChange `json:"en"`
}

type SetChange struct {
	Headline    *string  `json:"headline"`
	PrimaryText *string  `json:"primary_text"`
	Sub         *string  `json:"sub"`
	Bullets     []string `json:"bullets"`
	Badge       *string  `json:"badge"`
	CTA         *string  `json:"cta"`
}

// apply: the revision as a new idea list + the changed field names (the same merge as a Claude follow-up).
func (rv Revision) apply(old []CreateIdea) ([]CreateIdea, []string) {
	nw := make([]CreateIdea, len(old))
	var ch []string
	mark := func(f string) {
		if !contains(ch, f) {
			ch = append(ch, f)
		}
	}
	for i, o := range old {
		n := o
		n.Sets = append([]CreateSet(nil), o.Sets...)
		if rv.ProductIDs != nil {
			n.ProductIDs = rv.ProductIDs
			mark("products")
		}
		if rv.Angle != nil {
			n.Angle = *rv.Angle
			mark("angle")
		}
		if rv.Hook != nil {
			n.Hook = *rv.Hook
			mark("hook")
		}
		if rv.Template != nil {
			n.Template = *rv.Template
			mark("template")
		}
		if rv.Palette != nil {
			n.Palette = rv.Palette
			mark("palette")
		}
		if rv.ShowPrice != nil {
			n.ShowPrice = *rv.ShowPrice
			mark("show_price")
		}
		if rv.Formats != nil {
			n.Formats = rv.Formats
			mark("formats")
		}
		if rv.Motion != nil {
			n.Motion = *rv.Motion
			mark("motion")
		}
		if rv.Scene != nil {
			n.Scene = *rv.Scene
			mark("scene")
		}
		for k := range n.Sets {
			c := rv.EN
			if n.Sets[k].Lang == "ar" {
				c = rv.AR
			}
			if c == nil {
				continue
			}
			s := &n.Sets[k]
			if c.Headline != nil {
				s.Headline = *c.Headline
				mark("headline")
			}
			if c.PrimaryText != nil {
				s.PrimaryText = *c.PrimaryText
				mark("primary_text")
			}
			if c.Sub != nil {
				s.Sub = *c.Sub
				mark("sub")
			}
			if c.Bullets != nil {
				s.Bullets = c.Bullets
				mark("bullets")
			}
			if c.Badge != nil {
				s.Badge = *c.Badge
				mark("badge")
			}
			if c.CTA != nil {
				s.CTA = *c.CTA
				mark("cta")
			}
		}
		nw[i] = n
	}
	return nw, ch
}

// Author: an authored turn (Claude in the owner's app wrote it; no model call here). prev nil = a new session.
func Author(req CreateRequest, prev *CreateSession, shop ShopSnapshot, wording string, promo bool) (CreateSession, error) {
	s := CreateSession{ID: newSessionID(), At: time.Now().UTC(), Prompt: req.Prompt, Photo: req.Photo, Source: "claude_app", Key: req.Key, RequestID: req.RequestID}
	turn := CreateTurn{At: time.Now().UTC(), Text: firstNonEmpty(req.Prompt, "made in the Claude app"), Kind: "prompt"}
	var ideas []CreateIdea
	if prev == nil {
		if len(req.Authored) == 0 {
			return s, fmt.Errorf("send at least one idea")
		}
		ideas = normIdeas(req.Authored, shop, req, true)
	} else {
		if req.Revise == nil {
			return s, fmt.Errorf("send what to change")
		}
		s = *prev
		nw, ch := req.Revise.apply(prev.Ideas)
		nw = normIdeas(nw, shop, CreateRequest{}, true)
		if len(nw) != len(prev.Ideas) {
			return s, fmt.Errorf("that change leaves an idea without an in-stock product with a photo")
		}
		ideas = mergeIdeas(prev.Ideas, nw, ch, req.Revise.Idea)
		turn = CreateTurn{At: time.Now().UTC(), Text: firstNonEmpty(req.Edit, "revised in the Claude app"), Kind: "edit", Changed: ch}
	}
	ideas = fixIdeas(ideas, wording, promo)
	if len(ideas) == 0 {
		return s, fmt.Errorf("no idea passed: each needs a product id from list_products and copy the policy linter accepts")
	}
	s.Ideas = ideas
	s.Designs = designsFor(s.ID, ideas, shop, s.Photo)
	s.Turns = append(s.Turns, turn)
	s.Updated = time.Now().UTC()
	return s, nil
}

// CreateSet: one language of one idea.
type CreateSet struct {
	Lang        string   `json:"lang"`
	Headline    string   `json:"headline"`
	PrimaryText string   `json:"primary_text"`
	Sub         string   `json:"sub"`
	Bullets     []string `json:"bullets"`
	Badge       string   `json:"badge"`
	CTA         string   `json:"cta"`
	Hooks       []string `json:"hooks"`
	Rewritten   bool     `json:"rewritten,omitempty"`
}

// CreateIdea: one ad idea (a variant), every format and language of it.
type CreateIdea struct {
	ProductIDs []string    `json:"product_ids"`
	Angle      string      `json:"angle"`
	Hook       string      `json:"hook"`
	Template   string      `json:"template"`
	Palette    []string    `json:"palette"`
	ShowPrice  bool        `json:"show_price"`
	Formats    []string    `json:"formats"`
	Motion     bool        `json:"motion"`
	Scene      string      `json:"scene"`
	Sets       []CreateSet `json:"sets"`
}

type CreateTurn struct {
	At      time.Time `json:"at"`
	Text    string    `json:"text"`
	Kind    string    `json:"kind"` // prompt | edit
	Changed []string  `json:"changed,omitempty"`
	Said    string    `json:"said,omitempty"` // Claude's one line back
}

// CreateSession: one conversation in the box, saved for Creatives -> Made with Claude.
type CreateSession struct {
	ID        string       `json:"id"`
	At        time.Time    `json:"at"`
	Updated   time.Time    `json:"updated"`
	Prompt    string       `json:"prompt"`
	VideoID   string       `json:"video_id,omitempty"`
	Photo     bool         `json:"photo,omitempty"`
	Turns     []CreateTurn `json:"turns"`
	Ideas     []CreateIdea `json:"ideas"`
	Designs   []DesignSpec `json:"designs"`
	SceneKey  bool         `json:"scene_key"` // the AI pictures & video key was set when it ran
	Used      []string     `json:"used,omitempty"`
	Saved     []string     `json:"saved,omitempty"`     // library creative ids
	Ran       []string     `json:"ran,omitempty"`       // creative ids sent to the autopilot / launched
	Proposals []string     `json:"proposals,omitempty"` // proposal ids it launched
	Outcome   *Outcome     `json:"outcome,omitempty"`   // filled on GET from the account's metrics
	Usage     Usage        `json:"usage"`
	Source    string       `json:"source"`               // box (this app, API credits) | claude_app (the owner's Claude, Max)
	Key       string       `json:"key,omitempty"`        // the idempotency key of its first turn
	Keys      []string     `json:"keys,omitempty"`       // every turn's key (a retried edit is not applied twice)
	RequestID string       `json:"request_id,omitempty"` // the Creation request it answered
}

// Outcome: how the ads made in a session did (settled days only).
type Outcome struct {
	Verdict   string  `json:"verdict"` // not_run | running | won | lost
	SpendAED  float64 `json:"spend"`
	Purchases int64   `json:"purchases"`
	ROAS      float64 `json:"roas"`
}

// Fields an edit may change (the schema's `changed` enum).
var createFields = []string{"products", "angle", "hook", "template", "palette", "show_price", "formats", "motion", "scene",
	"headline", "primary_text", "sub", "bullets", "badge", "cta", "langs", "ideas"}

var createFormats = []string{"1:1", "4:5", "9:16"}

func createSchema() map[string]any {
	set := obj(map[string]any{
		"lang": enum("ar", "en"), "headline": str(), "primary_text": str(), "sub": str(), "bullets": arr(str()),
		"badge": str(), "cta": enum("SHOP_NOW", "LEARN_MORE", "ORDER_NOW", "BUY_NOW"), "hooks": arr(str()),
	})
	idea := obj(map[string]any{
		"product_ids": arr(str()), "angle": str(), "hook": str(), "template": enum(templateIDs...),
		"palette": arr(str()), "show_price": map[string]any{"type": "boolean"}, "formats": arr(enum(createFormats...)),
		"motion": map[string]any{"type": "boolean"}, "scene": str(), "sets": arr(set),
	})
	return obj(map[string]any{"said": str(), "ideas": arr(idea), "changed": arr(enum(createFields...))})
}

const createSystem = copySystem + `

YOU ALSO ART-DIRECT. The owner describes an ad in one sentence (Arabic or English) and you decide everything else, so he never picks between tools:
- products: ids from the catalogue you are given ONLY (match names, brands, skin needs, "the snail essence", "sunscreen for oily skin"); 1 product unless he asks for a routine/bundle (max 3).
- ideas: as many as he asks for ("3 ads" = 3 ideas with DIFFERENT angles/hooks/design families), otherwise 1.
- each idea: angle, the scroll-stopping hook, a design family (hero glow ingredient offer routine editorial petals texture qa collection), a palette of 2-4 hex colours that suit the product and occasion (empty to take the product photo's colours), show_price, formats (1:1, 4:5, 9:16; all three unless he says otherwise), motion true for Reels/Stories/video asks, and the sets: one per language asked (Arabic first; both unless he says one).
- scene: ONLY when the request says a scene generator is available, one sentence describing a background set (light, surfaces, props, mood) with no product, no packaging, no text, no logos, no faces; otherwise "".
- occasions: Ramadan/Eid are about care, gifting and family, never fasting claims; White Friday only mentions a sale if a promotion is approved.
- his own photo or video: design around it; write copy that matches its hook.
- said: one short line back to him in his language ("Done: 3 Arabic + English ads for ...").
FOLLOW-UPS: you get the current set and his instruction. Change ONLY what he asked ("shorter headline" changes headlines only; "more pink" the palette only; "show the price" show_price only; "swap to the toner" products and the copy that names the product). Return the whole set and list in "changed" exactly the fields you changed.`

// Create runs one turn of the box. prev is the session to edit (nil for a new one).
func (b *Brain) Create(ctx context.Context, req CreateRequest, prev *CreateSession, shop ShopSnapshot, r Research, video *VideoAsset, sceneKey bool, wording string, past []map[string]any) (CreateSession, error) {
	if !b.hasClaude() {
		return CreateSession{}, ErrNoKey
	}
	var cat []map[string]any
	for _, p := range shop.Products {
		if !p.InStock || p.Image == "" {
			continue
		}
		if len(cat) >= 200 {
			break
		}
		cat = append(cat, map[string]any{"id": p.ID, "title": p.Title, "brand": p.Brand, "type": p.Type, "price_aed": p.PriceAED})
	}
	var angles []string
	for _, n := range r.Notes {
		if len(angles) < 8 {
			angles = append(angles, n.Title)
		}
	}
	ask := map[string]any{"catalogue": cat, "promotion_approved": b.PromoApproved, "wording_rules": firstNonEmpty(wording, Balanced),
		"scene_generator_available": sceneKey, "recent_research": angles, "past_creations": past}
	var msg string
	if prev == nil {
		ask["request"] = map[string]any{"text": truncate(req.Prompt, 1200), "product_ids": clean(req.ProductIDs, 3), "occasion": req.Occasion,
			"formats": clean(req.Formats, 3), "langs": clean(req.Langs, 2), "own_photo": req.Photo, "own_video": videoBrief(video)}
		msg = "Make the ads.\n"
	} else {
		ask["current"] = prev.Ideas
		ask["instruction"] = truncate(req.Edit, 600)
		if req.Target > 0 {
			ask["only_idea"] = req.Target
		}
		msg = "Follow-up: change only what the instruction asks.\n"
	}
	js, _ := json.Marshal(ask)
	cr := Request{Purpose: "create", Model: b.CopyModel, MaxTokens: 8000, System: CachedSystem(createSystem),
		Messages: []Message{{Role: "user", Content: msg + string(js)}}, Output: JSONOutput(createSchema(), "medium")}
	var u Usage
	var ans struct {
		Said    string       `json:"said"`
		Ideas   []CreateIdea `json:"ideas"`
		Changed []string     `json:"changed"`
	}
	for round := 0; round < 2; round++ {
		resp, err := b.Claude.Send(ctx, cr)
		AddUsage(&u, b.CopyModel, resp)
		if err != nil {
			return CreateSession{}, err
		}
		if resp.StopReason == "refusal" || resp.StopReason == "max_tokens" {
			return CreateSession{}, fmt.Errorf("Claude stopped: %s", resp.StopReason)
		}
		if err := json.Unmarshal([]byte(strings.TrimSpace(resp.Text())), &ans); err != nil {
			return CreateSession{}, fmt.Errorf("create JSON: %v", err)
		}
		blocked := lintIdeas(ans.Ideas, wording, b.PromoApproved)
		if len(blocked) == 0 || round == 1 {
			break
		}
		var raw []any
		for _, x := range resp.Raw {
			raw = append(raw, x)
		}
		cr.Messages = append(cr.Messages, Message{Role: "assistant", Content: raw}, Message{Role: "user",
			Content: "The policy linter blocked these lines; rewrite them so none recurs (same fields, same changed list):\n" + strings.Join(blocked, "\n")})
	}

	s := CreateSession{ID: newSessionID(), At: time.Now().UTC(), Prompt: req.Prompt, VideoID: req.VideoID, Photo: req.Photo, SceneKey: sceneKey, Source: "box", Key: req.Key, RequestID: req.RequestID}
	turn := CreateTurn{At: time.Now().UTC(), Text: req.Prompt, Kind: "prompt", Said: truncate(ans.Said, 300)}
	ideas := normIdeas(ans.Ideas, shop, req, sceneKey)
	if prev != nil {
		s = *prev
		turn.Text, turn.Kind = req.Edit, "edit"
		ch := clean(ans.Changed, len(createFields))
		var keep []string
		for _, c := range ch {
			if oneOf(c, createFields, "") != "" {
				keep = append(keep, c)
			}
		}
		turn.Changed = keep
		ideas = mergeIdeas(prev.Ideas, ideas, keep, req.Target)
	}
	// the linter has the last word: blocked lines are rewritten safely or the language is dropped
	ideas = fixIdeas(ideas, wording, b.PromoApproved)
	if len(ideas) == 0 {
		return CreateSession{}, fmt.Errorf("Claude found no in-stock product with a photo for this request; name a product or tap one")
	}
	s.Ideas = ideas
	s.Designs = designsFor(s.ID, ideas, shop, s.Photo)
	s.Turns = append(s.Turns, turn)
	s.Updated = time.Now().UTC()
	addUsageTotals(&s.Usage, u)
	return s, nil
}

// normIdeas: keep only catalogue products, known families/formats/colours, both languages unless asked.
func normIdeas(in []CreateIdea, shop ShopSnapshot, req CreateRequest, sceneKey bool) []CreateIdea {
	hex := regexp.MustCompile(`^#[0-9A-Fa-f]{6}$`)
	var out []CreateIdea
	for _, x := range in {
		if len(out) >= 5 {
			break
		}
		var ids []string
		for _, p := range productsByID(shop, clean(x.ProductIDs, 3)) {
			ids = append(ids, p.ID)
		}
		if len(ids) == 0 && len(req.ProductIDs) > 0 {
			for _, p := range productsByID(shop, clean(req.ProductIDs, 3)) {
				ids = append(ids, p.ID)
			}
		}
		if len(ids) == 0 {
			continue
		}
		x.ProductIDs = ids
		x.Template = oneOf(x.Template, templateIDs, familyFor(x.Angle, len(ids), hash32(x.Angle+strings.Join(ids, ","))))
		var pal []string
		for _, c := range x.Palette {
			if hex.MatchString(c) && len(pal) < 4 {
				pal = append(pal, strings.ToUpper(c))
			}
		}
		x.Palette = pal
		var fs []string
		for _, f := range createFormats {
			if contains(x.Formats, f) {
				fs = append(fs, f)
			}
		}
		if len(fs) == 0 {
			fs = append(fs, createFormats...)
		}
		x.Formats = fs
		if !sceneKey {
			x.Scene = ""
		}
		x.Scene = truncate(x.Scene, 600)
		x.Angle, x.Hook = truncate(x.Angle, 120), truncate(x.Hook, 200)
		var sets []CreateSet
		for _, l := range []string{"ar", "en"} { // Arabic first, always in this order
			for _, st := range x.Sets {
				if st.Lang == l {
					st.Bullets, st.Hooks = clean(st.Bullets, 3), clean(st.Hooks, 5)
					st.CTA = oneOf(st.CTA, []string{"SHOP_NOW", "LEARN_MORE", "ORDER_NOW", "BUY_NOW"}, "SHOP_NOW")
					sets = append(sets, st)
					break
				}
			}
		}
		x.Sets = sets
		out = append(out, x)
	}
	return out
}

// mergeIdeas: an edit takes ONLY the changed fields from Claude's answer.
func mergeIdeas(old, nw []CreateIdea, changed []string, target int) []CreateIdea {
	has := func(f string) bool { return contains(changed, f) }
	if has("ideas") && len(nw) > 0 { // he asked for a different number of ideas
		return nw
	}
	out := make([]CreateIdea, len(old))
	for i := range old {
		o := old[i]
		o.Sets = append([]CreateSet(nil), old[i].Sets...)
		out[i] = o
		if i >= len(nw) || (target > 0 && target != i+1) {
			continue
		}
		n := nw[i]
		if has("products") {
			o.ProductIDs = n.ProductIDs
		}
		if has("angle") {
			o.Angle = n.Angle
		}
		if has("hook") {
			o.Hook = n.Hook
		}
		if has("template") {
			o.Template = n.Template
		}
		if has("palette") {
			o.Palette = n.Palette
		}
		if has("show_price") {
			o.ShowPrice = n.ShowPrice
		}
		if has("formats") {
			o.Formats = n.Formats
		}
		if has("motion") {
			o.Motion = n.Motion
		}
		if has("scene") {
			o.Scene = n.Scene
		}
		if has("langs") {
			o.Sets = n.Sets
		} else {
			for k := range o.Sets {
				for _, ns := range n.Sets {
					if ns.Lang != o.Sets[k].Lang {
						continue
					}
					s := &o.Sets[k]
					if has("headline") {
						s.Headline = ns.Headline
					}
					if has("primary_text") {
						s.PrimaryText, s.Hooks = ns.PrimaryText, ns.Hooks
					}
					if has("sub") {
						s.Sub = ns.Sub
					}
					if has("bullets") {
						s.Bullets = ns.Bullets
					}
					if has("badge") {
						s.Badge = ns.Badge
					}
					if has("cta") {
						s.CTA = ns.CTA
					}
				}
			}
		}
		out[i] = o
	}
	return out
}

func setFields(s CreateSet) (CopyFields, CopyFields) {
	return CopyFields{PrimaryText: s.PrimaryText, Headline: s.Headline, CTA: s.CTA, Hooks: s.Hooks},
		CopyFields{Headline: s.Sub, Description: s.Badge, Hooks: s.Bullets}
}

// lintIdeas: the blocked lines, for the one regeneration round.
func lintIdeas(ideas []CreateIdea, wording string, promo bool) []string {
	var out []string
	for i, x := range ideas {
		for _, s := range x.Sets {
			o := LintOptions{PromoApproved: promo, Lang: s.Lang, Strictness: wording}
			a, d := setFields(s)
			for _, lr := range []LintResult{Lint(a, o), Lint(d, o)} {
				for _, f := range lr.Findings {
					if f.Severity == "block" {
						out = append(out, fmt.Sprintf("[idea %d %s %s] %q: %s", i+1, s.Lang, f.Field, f.Match, f.Why))
					}
				}
			}
		}
	}
	return out
}

// fixIdeas: safe rewrites in code; a set still blocked is dropped (never shown, never shipped).
func fixIdeas(ideas []CreateIdea, wording string, promo bool) []CreateIdea {
	var out []CreateIdea
	for _, x := range ideas {
		var sets []CreateSet
		for _, s := range x.Sets {
			o := LintOptions{PromoApproved: promo, Lang: s.Lang, Strictness: wording}
			a, d := setFields(s)
			lr := Lint(a, o)
			if !lr.OK {
				var rew bool
				a, lr, rew = Fix(a, o)
				s.Rewritten = s.Rewritten || rew
				s.PrimaryText, s.Headline, s.CTA, s.Hooks = a.PrimaryText, a.Headline, a.CTA, a.Hooks
			}
			if lr.Blocked || Lint(d, o).Blocked {
				continue
			}
			sets = append(sets, s)
		}
		if len(sets) > 0 {
			x.Sets = sets
			out = append(out, x)
		}
	}
	return out
}

// designsFor: one DesignSpec per idea x language (the app renders every format of it).
// IDs are stable per session/idea/lang so an edit replaces, never duplicates.
func designsFor(sid string, ideas []CreateIdea, shop ShopSnapshot, photo bool) []DesignSpec {
	var out []DesignSpec
	for i, x := range ideas {
		prods := productsByID(shop, x.ProductIDs)
		for _, s := range x.Sets {
			cs := CopySet{ID: fmt.Sprintf("%s-%d-%s", sid, i+1, s.Lang), PairID: fmt.Sprintf("%s-%d", sid, i+1), Lang: s.Lang, ProductIDs: x.ProductIDs,
				Angle: x.Angle, Headline: s.Headline, PrimaryText: s.PrimaryText, CTA: s.CTA, Hooks: s.Hooks}
			d := designFor(cs, copyExtra{sub: s.Sub, bullets: s.Bullets, badge: s.Badge}, prods, x.Template)
			d.ID = "c-" + cs.ID
			d.Formats = append([]string(nil), x.Formats...)
			d.Palette = x.Palette
			d.PrimaryText = s.PrimaryText
			d.Idea = i + 1
			d.Motion = x.Motion
			d.Photo = photo
			if !x.ShowPrice {
				d.Price = ""
			}
			if x.Scene != "" {
				d.Scene = x.Scene
			}
			out = append(out, d)
		}
	}
	return out
}

// ---------- persistence + outcomes ----------

const maxSessions = 80

func (e *Engine) sessions(acct string) []CreateSession {
	var s []CreateSession
	e.load(acct, "create_sessions", &s)
	return s
}

func (e *Engine) putSession(acct string, s CreateSession) {
	e.mu.Lock()
	defer e.mu.Unlock()
	all := e.sessions(acct)
	found := false
	for i := range all {
		if all[i].ID == s.ID {
			all[i], found = s, true
		}
	}
	if !found {
		all = append([]CreateSession{s}, all...)
	}
	if len(all) > maxSessions {
		all = all[:maxSessions]
	}
	e.save(acct, "create_sessions", all)
}

// outcomes: settled results of the ads whose creative came out of each session.
func outcomes(ss []CreateSession, in CycleInput) {
	adsBy := map[string][]string{} // creative id -> ad ids
	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				adsBy[a.CreativeID] = append(adsBy[a.CreativeID], a.ID)
			}
		}
	}
	agg := map[string]*Agg{}
	for _, m := range in.Metrics {
		if m.Level != "ad" || m.Settling || m.Placement != "" {
			continue
		}
		g := agg[m.ObjectID]
		if g == nil {
			g = &Agg{}
			agg[m.ObjectID] = g
		}
		g.Spend += m.SpendAED
		g.Revenue += m.RevenueAED
		g.Purch += m.Purchases
	}
	for i := range ss {
		o := &Outcome{Verdict: "not_run"}
		seen := map[string]bool{}
		for _, cid := range append(append([]string{}, ss[i].Ran...), ss[i].Saved...) {
			for _, ad := range adsBy[cid] {
				if seen[ad] {
					continue
				}
				seen[ad] = true
				o.Verdict = "running"
				if g := agg[ad]; g != nil {
					o.SpendAED += g.Spend
					o.Purchases += g.Purch
					o.ROAS += g.Revenue
				}
			}
		}
		if o.SpendAED > 0 {
			o.ROAS = round2(o.ROAS / o.SpendAED)
			switch {
			case o.Purchases > 0 && o.ROAS >= 2:
				o.Verdict = "won"
			case o.SpendAED >= 60 && o.Purchases == 0:
				o.Verdict = "lost"
			}
		} else {
			o.ROAS = 0
		}
		o.SpendAED = round2(o.SpendAED)
		ss[i].Outcome = o
	}
}

// pastForPrompt: what the brain learned from earlier sessions -- winners first.
func pastForPrompt(ss []CreateSession) []map[string]any {
	rank := map[string]int{"won": 0, "lost": 1, "running": 2, "not_run": 3}
	cp := append([]CreateSession(nil), ss...)
	sort.SliceStable(cp, func(i, j int) bool {
		vi, vj := "not_run", "not_run"
		if cp[i].Outcome != nil {
			vi = cp[i].Outcome.Verdict
		}
		if cp[j].Outcome != nil {
			vj = cp[j].Outcome.Verdict
		}
		return rank[vi] < rank[vj]
	})
	var out []map[string]any
	for _, s := range cp {
		if len(out) >= 8 || s.Outcome == nil || s.Outcome.Verdict == "not_run" {
			continue
		}
		for _, x := range s.Ideas {
			h := ""
			if len(x.Sets) > 0 {
				h = x.Sets[0].Headline
			}
			out = append(out, map[string]any{"result": s.Outcome.Verdict, "roas": s.Outcome.ROAS, "angle": x.Angle, "hook": x.Hook, "template": x.Template, "headline": h})
			break
		}
	}
	return out
}

var sessionIDRe = regexp.MustCompile(`^s[0-9a-f]{6,24}$`)

// serveCreate: the routes above. input() is the account's CycleInput.
func (e *Engine) serveCreate(w http.ResponseWriter, r *http.Request, p, acct string, input func() CycleInput, wording string, bad func(int, string), js func(any)) {
	switch {
	case p == "create" && r.Method == http.MethodGet:
		ss := e.sessions(acct)
		outcomes(ss, input())
		if ss == nil {
			ss = []CreateSession{}
		}
		js(ss)
	case p == "create" && r.Method == http.MethodPost:
		var req CreateRequest
		if json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&req) != nil {
			bad(400, "bad JSON")
			return
		}
		req.Prompt, req.Edit = strings.TrimSpace(req.Prompt), strings.TrimSpace(req.Edit)
		if len(req.Key) > 80 {
			req.Key = req.Key[:80]
		}
		w.Header().Set("Content-Type", "application/x-ndjson")
		w.Header().Set("Cache-Control", "no-store")
		fl, _ := w.(http.Flusher)
		step := func(v map[string]any) {
			b, _ := json.Marshal(v)
			_, _ = w.Write(append(b, '\n'))
			if fl != nil {
				fl.Flush()
			}
		}
		// idempotency: the same key gives the same session; a second call while the first runs waits for it
		var release func()
		if req.Key != "" {
			k := acct + "|" + req.Key
			for {
				if s := e.byKey(acct, req.Key); s != nil {
					step(map[string]any{"step": "done", "session": s, "again": true})
					return
				}
				ch, first := keyLock(k)
				if first {
					release = func() { keyUnlock(k, ch) }
					break
				}
				step(map[string]any{"step": "wait"})
				select {
				case <-ch:
				case <-r.Context().Done():
					return
				}
			}
			defer release()
		}
		s, err := e.createTurn(r.Context(), req, acct, input, wording, step)
		if err != nil {
			step(map[string]any{"step": "error", "error": err.Error()})
			return
		}
		step(map[string]any{"step": "done", "session": s})
	case strings.HasPrefix(p, "create/") && r.Method == http.MethodPut:
		id := strings.TrimPrefix(p, "create/")
		if !sessionIDRe.MatchString(id) {
			bad(404, "no such session")
			return
		}
		var in struct {
			Used, Saved, Ran, Proposals []string
		}
		if decodeSmall(r, &in) != nil {
			bad(400, "bad JSON")
			return
		}
		var cur *CreateSession
		for _, s := range e.sessions(acct) {
			if s.ID == id {
				s := s
				cur = &s
			}
		}
		if cur == nil {
			bad(404, "no such session")
			return
		}
		add := func(to []string, v []string) []string {
			for _, x := range v {
				if len(x) <= 120 && !contains(to, x) && len(to) < 200 {
					to = append(to, x)
				}
			}
			return to
		}
		cur.Used, cur.Saved, cur.Ran, cur.Proposals = add(cur.Used, in.Used), add(cur.Saved, in.Saved), add(cur.Ran, in.Ran), add(cur.Proposals, in.Proposals)
		cur.Updated = time.Now().UTC()
		e.putSession(acct, *cur)
		js(cur)
	default:
		bad(404, "no such create route")
	}
}

func newSessionID() string { return fmt.Sprintf("s%x", time.Now().UnixNano()) }

// ---------- one turn, idempotent ----------

var keys = struct {
	sync.Mutex
	m map[string]chan struct{}
}{m: map[string]chan struct{}{}}

// keyLock: the first caller of a key gets it; later ones get a channel closed when it is released.
func keyLock(k string) (chan struct{}, bool) {
	keys.Lock()
	defer keys.Unlock()
	if ch, ok := keys.m[k]; ok {
		return ch, false
	}
	ch := make(chan struct{})
	keys.m[k] = ch
	return ch, true
}

func keyUnlock(k string, ch chan struct{}) {
	keys.Lock()
	delete(keys.m, k)
	keys.Unlock()
	close(ch)
}

// byKey: the session a key already made (its first turn, or any later turn).
func (e *Engine) byKey(acct, key string) *CreateSession {
	for _, s := range e.sessions(acct) {
		if s.Key == key || contains(s.Keys, key) {
			s := s
			return &s
		}
	}
	return nil
}

func (e *Engine) session(acct, id string) *CreateSession {
	for _, s := range e.sessions(acct) {
		if s.ID == id {
			s := s
			return &s
		}
	}
	return nil
}

// createTurn: a box turn (one Claude call through the API) or an authored one (Claude in the owner's app wrote it).
func (e *Engine) createTurn(ctx context.Context, req CreateRequest, acct string, input func() CycleInput, wording string, step func(map[string]any)) (CreateSession, error) {
	var prev *CreateSession
	if req.SessionID != "" {
		if prev = e.session(acct, req.SessionID); prev == nil {
			return CreateSession{}, fmt.Errorf("no such session %s", req.SessionID)
		}
	}
	in := input()
	var s CreateSession
	var err error
	if len(req.Authored) > 0 || req.Revise != nil {
		s, err = Author(req, prev, in.Shop, wording, e.B.PromoApproved)
	} else {
		if prev != nil && req.Edit == "" {
			return CreateSession{}, fmt.Errorf("say what to change")
		}
		if prev == nil && req.Prompt == "" && len(req.ProductIDs) == 0 && req.VideoID == "" {
			return CreateSession{}, fmt.Errorf("say what you want, or tap a product")
		}
		var video *VideoAsset
		if req.VideoID != "" {
			for _, v := range e.videos(acct) {
				if v.ID == req.VideoID {
					v := v
					video = &v
				}
			}
			if video == nil {
				return CreateSession{}, fmt.Errorf("no such video")
			}
		}
		sceneKey := false // one key, one provider: the scene line is asked for only when it can be used
		if q := genProvider(e.genSettings().Image); q != nil {
			sceneKey = e.genKey(q) != ""
		}
		step(map[string]any{"step": "think"})
		ss := e.sessions(acct)
		outcomes(ss, in)
		cctx, cancel := context.WithTimeout(ctx, 5*time.Minute)
		defer cancel()
		s, err = e.B.Create(cctx, req, prev, in.Shop, e.richResearch(), video, sceneKey, wording, pastForPrompt(ss))
	}
	if err != nil {
		return CreateSession{}, err
	}
	step(map[string]any{"step": "lint"})
	if req.Key != "" && prev != nil {
		s.Keys = append(s.Keys, req.Key)
	}
	e.putSession(acct, s)
	if s.RequestID != "" {
		e.markCreation(acct, s.RequestID, "done", s.ID)
	}
	e.log("creative", fmt.Sprintf("Create with Claude (%s): %d idea(s), %d design(s) — %s", s.Source, len(s.Ideas), len(s.Designs), truncate(firstNonEmpty(req.Edit, req.Prompt), 80)), map[string]any{"session": s.ID})
	return s, nil
}
