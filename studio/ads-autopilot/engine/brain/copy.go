package brain

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
)

// CopyRequest asks for one idea as an AR + EN pair (POST /api/brain/copy, or
// a planner copy_request).
type CopyRequest struct {
	ProductIDs []string    `json:"product_ids"`
	Angle      string      `json:"angle"`
	Template   string      `json:"template"`
	Brief      string      `json:"brief"`
	PairID     string      `json:"pair_id,omitempty"`
	Wording    string      `json:"ad_wording,omitempty"` // linter strictness; the engine passes limits.ad_wording
	VideoID    string      `json:"video_id,omitempty"`   // write copy for this video (products from its analysis)
	Video      *VideoAsset `json:"-"`
}

// CopyResult is what POST /api/brain/copy returns.
type CopyResult struct {
	Sets    []CopySet    `json:"copy_sets"`
	Designs []DesignSpec `json:"designs"`
	Usage   Usage        `json:"usage"`
}

func (b *Brain) Copy(ctx context.Context, req CopyRequest, shop ShopSnapshot, r Research) (CopyResult, error) {
	if req.PairID == "" {
		req.PairID = fmt.Sprintf("p%x", hash32(strings.Join(req.ProductIDs, ",")+req.Angle+req.Brief))
	}
	sets, designs, u, err := b.copyFor(ctx, req, shop, r)
	return CopyResult{Sets: sets, Designs: designs, Usage: u}, err
}

type copyAnswer struct {
	Sets []struct {
		Lang        string   `json:"lang"`
		Hooks       []string `json:"hooks"`
		PrimaryText string   `json:"primary_text"`
		Headline    string   `json:"headline"`
		Description string   `json:"description"`
		CTA         string   `json:"cta"`
		Sub         string   `json:"sub"`
		Bullets     []string `json:"bullets"`
		Badge       string   `json:"badge"`
	} `json:"sets"`
}

// copyFor: one Claude call writes the AR and EN sets; the linter checks
// them; safe rewrites are applied in code; anything still blocked is
// regenerated ONCE with the findings as feedback, and dropped if it fails
// again. Nothing blocked is ever returned.
func (b *Brain) copyFor(ctx context.Context, cr CopyRequest, shop ShopSnapshot, r Research) ([]CopySet, []DesignSpec, Usage, error) {
	var u Usage
	if !b.hasClaude() {
		return nil, nil, u, ErrNoKey
	}
	if cr.Video != nil && cr.Video.Analysis != nil && len(cr.ProductIDs) == 0 {
		for _, m := range cr.Video.Analysis.ProductsShown {
			cr.ProductIDs = append(cr.ProductIDs, m.ProductID)
		}
	}
	prods := productsByID(shop, cr.ProductIDs)
	if len(prods) == 0 {
		return nil, nil, u, fmt.Errorf("no in-stock product with a photo among %v", cr.ProductIDs)
	}
	ask := b.copyAsk(cr, prods, r)
	req := Request{Purpose: "copy", Model: b.CopyModel, MaxTokens: 8000, System: CachedSystem(copySystem),
		Messages: []Message{{Role: "user", Content: "Write the ad copy.\n" + string(ask)}},
		Output:   JSONOutput(copySchema(), "medium")}
	opts := func(lang string) LintOptions {
		return LintOptions{PromoApproved: b.PromoApproved, Lang: lang, Strictness: cr.Wording}
	}

	var final []CopySet
	var extra = map[string]copyExtra{}
	for round := 0; round < 2; round++ {
		resp, err := b.Claude.Send(ctx, req)
		AddUsage(&u, b.CopyModel, resp)
		if err != nil {
			return nil, nil, u, err
		}
		if resp.StopReason == "refusal" || resp.StopReason == "max_tokens" {
			return nil, nil, u, fmt.Errorf("copy model stopped: %s", resp.StopReason)
		}
		var a copyAnswer
		if err := json.Unmarshal([]byte(strings.TrimSpace(resp.Text())), &a); err != nil {
			return nil, nil, u, fmt.Errorf("copy JSON: %v", err)
		}
		var blocked []string
		final = final[:0]
		for _, s := range a.Sets {
			if s.Lang != "ar" && s.Lang != "en" {
				continue
			}
			f := CopyFields{PrimaryText: s.PrimaryText, Headline: s.Headline, Description: s.Description, CTA: s.CTA, Hooks: clean(s.Hooks, 5)}
			lr := Lint(f, opts(s.Lang))
			rew := false
			if !lr.OK {
				f, lr, rew = Fix(f, opts(s.Lang))
			}
			// design text is linted too (it is printed on the image)
			dl := Lint(CopyFields{Headline: s.Sub, Description: s.Badge, Hooks: s.Bullets}, opts(s.Lang))
			if lr.Blocked || dl.Blocked {
				for _, x := range append(lr.Findings, dl.Findings...) {
					if x.Severity == "block" {
						blocked = append(blocked, fmt.Sprintf("[%s %s] %q: %s", s.Lang, x.Field, x.Match, x.Why))
					}
				}
				continue
			}
			id := fmt.Sprintf("%s-%s", cr.PairID, s.Lang)
			final = append(final, CopySet{ID: id, PairID: cr.PairID, Lang: s.Lang, ProductIDs: cr.ProductIDs, Angle: cr.Angle,
				Hooks: f.Hooks, PrimaryText: f.PrimaryText, Headline: f.Headline, Description: f.Description, CTA: f.CTA, Lint: lr, Rewritten: rew, VideoID: cr.VideoID})
			extra[id] = copyExtra{sub: s.Sub, bullets: clean(s.Bullets, 3), badge: s.Badge}
		}
		if len(blocked) == 0 && hasBothLangs(final) {
			break
		}
		if round == 0 {
			var prev []any
			for _, x := range resp.Raw {
				prev = append(prev, x)
			}
			req.Messages = append(req.Messages, Message{Role: "assistant", Content: prev}, Message{Role: "user",
				Content: "The policy linter blocked these lines; rewrite BOTH sets so none of them recurs:\n" + strings.Join(blocked, "\n")})
		}
	}
	if len(final) == 0 {
		return nil, nil, u, fmt.Errorf("every set was blocked by the policy linter")
	}
	var designs []DesignSpec
	for _, s := range final {
		if cr.VideoID != "" {
			break // the video is the creative; no image to design
		}
		x := extra[s.ID]
		designs = append(designs, designFor(s, x, prods, cr.Template))
	}
	return append([]CopySet{}, final...), designs, u, nil
}

type copyExtra struct {
	sub, badge string
	bullets    []string
}

func hasBothLangs(s []CopySet) bool {
	ar, en := false, false
	for _, x := range s {
		ar = ar || x.Lang == "ar"
		en = en || x.Lang == "en"
	}
	return ar && en
}

func productsByID(shop ShopSnapshot, ids []string) []Product {
	idx := map[string]Product{}
	for _, p := range shop.Products {
		idx[p.ID] = p
	}
	var out []Product
	for _, id := range ids {
		if p, ok := idx[id]; ok && p.InStock && p.Image != "" {
			out = append(out, p)
		}
	}
	return out
}

// designFor maps a linted copy set to a render spec. Template: the request's,
// else one that suits the angle.
func designFor(s CopySet, x copyExtra, prods []Product, tmpl string) DesignSpec {
	if oneOf(tmpl, templateIDs, "") == "" {
		tmpl = familyFor(s.Angle, len(prods), hash32(s.PairID+strings.Join(s.ProductIDs, ",")))
	}
	var imgs []string
	for _, p := range prods {
		imgs = append(imgs, p.Image)
	}
	price := ""
	if len(prods) > 0 {
		if s.Lang == "ar" {
			price = fmt.Sprintf("%.0f درهم", prods[0].PriceAED)
		} else {
			price = fmt.Sprintf("AED %.0f", prods[0].PriceAED)
		}
	}
	badge := x.badge
	if badge == "" && len(prods) > 0 {
		badge = prods[0].Brand
	}
	cat := ""
	if len(prods) > 0 {
		cat = prods[0].Type
	}
	d := DesignSpec{ID: "d-" + s.ID, PairID: s.PairID, Lang: s.Lang, Template: tmpl, Formats: []string{"1:1", "4:5", "9:16"},
		ProductIDs: s.ProductIDs, Images: imgs, Headline: s.Headline, Sub: x.sub, Bullets: x.bullets, Badge: badge,
		CTA: s.CTA, Price: price, Angle: s.Angle, CopyID: s.ID, Category: cat}
	if s.Lang == "ar" {
		d.Numerals = "arab" // Gulf ads read naturally with Arabic-Indic digits; the owner can switch per design
	}
	title := ""
	if len(prods) > 0 {
		title = prods[0].Title
	}
	d.Scene = ScenePrompt(SceneRequest{Family: tmpl, Angle: s.Angle, Product: title, Category: cat, Bullets: x.bullets, Format: "4:5", People: "none"})
	return d
}

// familyFor picks a design family from the angle; two products or more make a
// routine/collection; otherwise a stable per-pair choice among the hero
// families so a batch does not all look alike.
func familyFor(angle string, nProducts int, h uint32) string {
	a := strings.ToLower(angle)
	has := func(w ...string) bool {
		for _, x := range w {
			if strings.Contains(a, x) {
				return true
			}
		}
		return false
	}
	switch {
	case has("routine", "step") || nProducts >= 3:
		return "routine"
	case nProducts == 2 || has("bundle", "set", "collection", "carousel"):
		return "collection"
	case has("ingredient", "centella", "niacinamide", "rice", "snail", "heartleaf", "propolis", "hyaluronic", "ceramide"):
		return "ingredient"
	case has("glow", "dew", "glass"):
		return "glow"
	case has("gift", "value", "price", "offer", "eid"):
		return "offer"
	case has("texture", "feel", "gel", "cream"):
		return "texture"
	case has("question", "faq", "why", "how"):
		return "qa"
	case has("romantic", "rose", "petal", "valentine", "mother"):
		return "petals"
	case has("luxury", "editorial", "premium", "bestseller", "best seller"):
		return "editorial"
	}
	return []string{"hero", "editorial", "glow", "petals"}[h%4]
}

func hash32(s string) uint32 {
	var h uint32 = 2166136261
	for i := 0; i < len(s); i++ {
		h ^= uint32(s[i])
		h *= 16777619
	}
	return h
}

// videoBrief: what the copywriter needs to know about a video (no frames).
func videoBrief(v *VideoAsset) any {
	if v == nil || v.Analysis == nil {
		return nil
	}
	a := v.Analysis
	return map[string]any{"name": v.Name, "angle": a.Angle, "hook": a.Hook, "on_screen_text": a.OnScreenText, "language": a.Language,
		"aspect": a.Aspect, "duration_s": a.DurationS, "note": "Write the ad text that runs WITH this video (primary text, headline); the first line should match the video's hook."}
}

// copyAsk: the user turn of a copy request, exactly as sent (the Plan screen's "Show the brief" reads it too).
func (b *Brain) copyAsk(cr CopyRequest, prods []Product, r Research) []byte {
	var pinfo []map[string]any
	for _, p := range prods {
		desc := p.Description
		if len([]rune(desc)) > 700 {
			desc = string([]rune(desc)[:700])
		}
		pinfo = append(pinfo, map[string]any{"id": p.ID, "title": p.Title, "brand": p.Brand, "type": p.Type, "price_aed": p.PriceAED, "description": desc})
	}
	var angles []string
	for _, n := range r.Notes {
		if len(angles) < 8 {
			angles = append(angles, n.Title)
		}
	}
	facts := []string{"authentic Korean skincare and beauty", "delivery across the UAE", "prices in AED as given per product"}
	ask, _ := json.Marshal(map[string]any{"structure": "hook -> benefit -> proof/ingredient -> reason to buy (shop_facts only) -> CTA", "shop_facts": facts, "owner_brief_first": cr.Brief != "", "products": pinfo, "angle": cr.Angle, "brief": cr.Brief, "template": cr.Template,
		"promotion_approved": b.PromoApproved, "wording_rules": firstNonEmpty(cr.Wording, Balanced), "recent_research": angles, "want": "two sets: lang ar first, then lang en; same idea", "video": videoBrief(cr.Video)})
	return ask
}
