package executor

// formats.go — every ad type the executor can make (the owner, 20 Nov 2026: "Claude must be able to run every ad type
// Meta offers"), each from an allow-listed proposal kind with validated params. Facts: docs/META-FACTS.md §9, §14-§16.
//
//	image / video / carousel / flexible (asset_feed_spec) / catalog (template_data + product_set_id) creatives,
//	collection only from an Instant Experience the owner made in Ads Manager (canvas_id),
//	click-to-WhatsApp / Messenger / Instagram Direct (ad set destination_type + creative CTA),
//	website and engagement custom audiences, lookalikes, product sets,
//	Advantage+ audience with suggestions, placements, Advantage+ creative enhancements (allow-listed).

import (
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"

	"kaa/model"
)

var (
	destinations = map[string]struct{ destType, goal, cta, appDest string }{
		"website":          {"", "OFFSITE_CONVERSIONS", "SHOP_NOW", ""},
		"whatsapp":         {"WHATSAPP", "CONVERSATIONS", "WHATSAPP_MESSAGE", "WHATSAPP"},
		"messenger":        {"MESSENGER", "CONVERSATIONS", "MESSAGE_PAGE", "MESSENGER"},
		"instagram_direct": {"INSTAGRAM_DIRECT", "CONVERSATIONS", "INSTAGRAM_MESSAGE", "INSTAGRAM_DIRECT"},
	}
	publisherPlatforms = set("facebook", "instagram", "messenger", "audience_network", "threads")
	facebookPositions  = set("feed", "right_hand_column", "marketplace", "video_feeds", "story", "search", "instream_video", "facebook_reels", "facebook_reels_overlay", "profile_feed", "notification")
	instagramPositions = set("stream", "story", "explore", "explore_home", "reels", "profile_feed", "ig_search", "profile_reels")
	// Advantage+ creative enhancements (degrees_of_freedom_spec.creative_features_spec). The app may OPT_IN only to the
	// ones that keep his own words and pictures; the generative / translating ones are OPT_OUT only (a Gulf shopper
	// must never see a machine translation or an invented background under his brand). product_extensions (v27's
	// "product showcase" default) is sent OPT_OUT unless the brain opts in.
	enhanceOptIn   = set("adapt_to_placement", "enhance_cta", "image_brightness_and_contrast", "image_templates", "image_touchups", "image_uncrop", "inline_comment", "media_type_automation", "reveal_details_over_time", "text_optimizations", "video_auto_crop", "video_uncrop", "description_automation", "image_animation", "product_extensions")
	enhanceOptOut  = set("add_text_overlay", "creative_stickers", "image_background_gen", "image_text_translation", "text_translation", "translate_voiceover", "pac_relaxation", "video_filtering")
	audienceEvents = map[string]bool{"PageView": true, "ViewContent": true, "AddToCart": true, "InitiateCheckout": true, "Purchase": true}
	ctaRe          = regexp.MustCompile(`^[A-Z_]{2,40}$`)
	idRe           = regexp.MustCompile(`^(tmp:[A-Za-z0-9_-]{1,40}|[0-9]{5,25})$`)
)

func set(xs ...string) map[string]bool {
	m := map[string]bool{}
	for _, x := range xs {
		m[x] = true
	}
	return m
}

func anyList(p map[string]any, k string) []any {
	v, _ := p[k].([]any)
	return v
}

func ids(p map[string]any, k string) ([]string, error) {
	var out []string
	for _, x := range strs(p, k) {
		if !idRe.MatchString(x) {
			return nil, fmt.Errorf("%s: %q is not an id", k, x)
		}
		out = append(out, x)
	}
	return out, nil
}

// ValidateParams: the shape of a proposal's params, per kind. Run by Check before any limit (stage "params").
func ValidateParams(p model.Proposal, links model.AccountLinks) error {
	pr := p.Params
	switch p.Kind {
	case model.KindCreateCampaign:
		switch str(pr, "type") {
		case "", "sales":
		case "catalog":
			if links.CatalogID == "" {
				return errors.New("a catalog campaign needs the product catalog linked (Settings → Connections → Meta)")
			}
		default:
			return fmt.Errorf("campaign type %q: sales or catalog", str(pr, "type"))
		}
	case model.KindCreateAdSet:
		d := str(pr, "destination")
		if d == "" {
			d = "website"
		}
		if _, ok := destinations[d]; !ok {
			return fmt.Errorf("destination %q: website, whatsapp, messenger or instagram_direct", d)
		}
		if links.PageID == "" {
			return errors.New("no Facebook Page linked")
		}
		if d == "website" && links.PixelID == "" && str(pr, "product_set_id") == "" {
			return errors.New("a website (sales) ad set needs the pixel / dataset linked")
		}
		if d == "whatsapp" && links.WhatsAppNumber == "" {
			return errors.New("click-to-WhatsApp needs a WhatsApp number linked to the Page (Settings → Connections → Meta)")
		}
		if d == "instagram_direct" && links.InstagramUserID == "" {
			return errors.New("click-to-Instagram-Direct needs the Instagram account linked")
		}
		if v, ok := num(pr, "advantage_audience"); ok && v != 0 && v != 1 {
			return errors.New("advantage_audience is 0 or 1")
		}
		adv := true
		if v, ok := num(pr, "advantage_audience"); ok && v == 0 {
			adv = false
		}
		if a, ok := num(pr, "age_min"); ok && (a < 18 || a > 65 || adv && a > 25) {
			return errors.New("age_min: 18–65, and 18–25 with Advantage+ audience (Meta's rule)")
		}
		if ar := anyList(pr, "age_range"); ar != nil {
			if len(ar) != 2 {
				return errors.New("age_range is [min, max]")
			}
			lo, ok1 := ar[0].(float64)
			hi, ok2 := ar[1].(float64)
			if !ok1 || !ok2 || lo < 18 || hi > 65 || lo > hi {
				return errors.New("age_range: 18 ≤ min ≤ max ≤ 65")
			}
		}
		for _, g := range anyList(pr, "genders") {
			if f, ok := g.(float64); !ok || f != 1 && f != 2 {
				return errors.New("genders: 1 (men) and/or 2 (women)")
			}
		}
		for _, l := range anyList(pr, "locales") {
			if f, ok := l.(float64); !ok || f <= 0 || f > 10000 {
				return errors.New("locales: Meta locale ids (numbers)")
			}
		}
		for _, k := range []string{"custom_audiences", "excluded_custom_audiences"} {
			if _, err := ids(pr, k); err != nil {
				return err
			}
		}
		if ps := str(pr, "product_set_id"); ps != "" && !idRe.MatchString(ps) {
			return errors.New("product_set_id is not an id")
		}
		if pl, ok := pr["placements"].(map[string]any); ok {
			for k, allowed := range map[string]map[string]bool{"publisher_platforms": publisherPlatforms, "facebook_positions": facebookPositions, "instagram_positions": instagramPositions} {
				for _, x := range strs(pl, k) {
					if !allowed[x] {
						return fmt.Errorf("placements.%s: %q is not one of Meta's", k, x)
					}
				}
			}
			for k := range pl {
				if k != "publisher_platforms" && k != "facebook_positions" && k != "instagram_positions" {
					return fmt.Errorf("placements.%s is not supported", k)
				}
			}
		} else if pr["placements"] != nil {
			return errors.New("placements is an object")
		}
		if pr["frequency_cap"] != nil {
			return errors.New("frequency_cap: Meta takes frequency_control_specs only on REACH / THRUPLAY ad sets; a sales ad set cannot have one (META-FACTS §16)")
		}
	case model.KindUploadCreative:
		f := str(pr, "format")
		if f == "" {
			f = "image"
		}
		if links.PageID == "" {
			return errors.New("no Facebook Page linked")
		}
		switch f {
		case "image", "video":
		case "carousel":
			cards := anyList(pr, "cards")
			if len(cards) < 2 || len(cards) > 10 {
				return errors.New("a carousel has 2–10 cards")
			}
			for i, c := range cards {
				m, ok := c.(map[string]any)
				if !ok || str(m, "creative_id") == "" {
					return fmt.Errorf("card %d needs creative_id (a picture or video from the library)", i+1)
				}
			}
		case "flexible":
			n := len(strs(pr, "creative_ids"))
			if n < 1 || n > 10 || len(strs(pr, "texts")) < 1 || len(strs(pr, "texts")) > 5 || len(strs(pr, "headlines")) > 5 {
				return errors.New("flexible: 1–10 creative_ids, 1–5 texts, up to 5 headlines")
			}
		case "catalog":
			if links.CatalogID == "" {
				return errors.New("catalog ads need the product catalog linked")
			}
			t, ok := pr["template"].(map[string]any)
			if !ok || str(t, "primary_text") == "" || !strings.HasPrefix(str(t, "link"), "https://kbeautybliss.com/") {
				return errors.New("catalog: template {headline, primary_text, link on kbeautybliss.com} and product_set_id")
			}
			if !idRe.MatchString(str(pr, "product_set_id")) {
				return errors.New("catalog: product_set_id")
			}
		case "collection":
			if !regexp.MustCompile(`^[0-9]{5,25}$`).MatchString(str(pr, "canvas_id")) {
				return errors.New("collection ads need canvas_id: an Instant Experience made in Ads Manager (the API cannot build one safely for us)")
			}
		default:
			return fmt.Errorf("format %q: image, video, carousel, flexible, catalog or collection", f)
		}
		d := str(pr, "destination")
		if d != "" {
			if _, ok := destinations[d]; !ok {
				return fmt.Errorf("destination %q", d)
			}
		}
		if en, ok := pr["enhancements"].(map[string]any); ok {
			for k, v := range en {
				s, _ := v.(string)
				if s != "OPT_IN" && s != "OPT_OUT" {
					return fmt.Errorf("enhancements.%s: OPT_IN or OPT_OUT", k)
				}
				if !enhanceOptIn[k] && !enhanceOptOut[k] {
					return fmt.Errorf("enhancement %q is not one the app knows", k)
				}
				if s == "OPT_IN" && enhanceOptOut[k] {
					return fmt.Errorf("enhancement %q is never switched on (it would rewrite or translate the owner's ad)", k)
				}
			}
		}
		if c := str(pr, "cta"); c != "" && !ctaRe.MatchString(c) {
			return errors.New("cta must be a Meta call-to-action type")
		}
	case model.KindCreateAudience:
		if str(pr, "name") == "" {
			return errors.New("an audience needs a name")
		}
		days, _ := num(pr, "retention_days")
		switch str(pr, "source") {
		case "website":
			if links.PixelID == "" {
				return errors.New("website audiences need the pixel linked")
			}
			if !audienceEvents[str(pr, "event")] {
				return errors.New("event: PageView, ViewContent, AddToCart, InitiateCheckout or Purchase")
			}
			if days < 1 || days > 180 {
				return errors.New("retention_days 1–180 for a website audience")
			}
		case "page", "instagram":
			if str(pr, "source") == "page" && links.PageID == "" || str(pr, "source") == "instagram" && links.InstagramUserID == "" {
				return errors.New("link the Page / Instagram account first")
			}
			if days < 1 || days > 365 {
				return errors.New("retention_days 1–365 for an engagement audience")
			}
		default:
			return errors.New("source: website, page or instagram")
		}
	case model.KindCreateLookalike:
		r, _ := num(pr, "ratio")
		if r < 0.01 || r > 0.10 || str(pr, "name") == "" || !idRe.MatchString(str(pr, "origin_audience_id")) {
			return errors.New("lookalike: name, origin_audience_id and ratio 0.01–0.10")
		}
	case model.KindCreateProductSet:
		if links.CatalogID == "" || str(pr, "name") == "" {
			return errors.New("a product set needs the catalog linked and a name")
		}
		if f := str(pr, "filter"); f != "" && !json.Valid([]byte(f)) {
			return errors.New("filter must be JSON")
		}
	}
	return nil
}

// adSetForm adds the destination, targeting and placements of a create_adset to form fields.
func adSetTargeting(pr map[string]any, countries []string) (map[string]any, error) {
	t := map[string]any{"geo_locations": map[string]any{"countries": countries}}
	adv := 1
	if v, ok := num(pr, "advantage_audience"); ok && v == 0 {
		adv = 0
	}
	t["targeting_automation"] = map[string]any{"advantage_audience": adv}
	if a, ok := num(pr, "age_min"); ok {
		t["age_min"] = int(a)
	}
	if ar := anyList(pr, "age_range"); len(ar) == 2 {
		t["age_range"] = []int{int(ar[0].(float64)), int(ar[1].(float64))} // a suggestion under Advantage+ audience
	}
	if g := anyList(pr, "genders"); len(g) > 0 {
		var gs []int
		for _, x := range g {
			gs = append(gs, int(x.(float64)))
		}
		t["genders"] = gs
	}
	if l := anyList(pr, "locales"); len(l) > 0 {
		var ls []int
		for _, x := range l {
			ls = append(ls, int(x.(float64)))
		}
		t["locales"] = ls
	}
	for _, k := range []string{"custom_audiences", "excluded_custom_audiences"} {
		if xs := strs(pr, k); len(xs) > 0 {
			var o []map[string]string
			for _, x := range xs {
				o = append(o, map[string]string{"id": x})
			}
			t[k] = o
		}
	}
	if pl, ok := pr["placements"].(map[string]any); ok {
		for _, k := range []string{"publisher_platforms", "facebook_positions", "instagram_positions"} {
			if xs := strs(pl, k); len(xs) > 0 {
				t[k] = xs
			}
		}
	}
	return t, nil
}

// enhancementsSpec: degrees_of_freedom_spec with the brain's allow-listed choices and product_extensions OPT_OUT
// unless asked.
func enhancementsSpec(pr map[string]any) map[string]any {
	feats := map[string]any{"product_extensions": map[string]string{"enroll_status": "OPT_OUT"}}
	if en, ok := pr["enhancements"].(map[string]any); ok {
		for k, v := range en {
			feats[k] = map[string]any{"enroll_status": v}
		}
	}
	return map[string]any{"creative_features_spec": feats}
}

// texts: every piece of copy on a proposal, for the linter.
func texts(p model.Proposal) string {
	pr := p.Params
	parts := []string{str(pr, "headline"), str(pr, "primary_text")}
	parts = append(parts, strs(pr, "texts")...)
	parts = append(parts, strs(pr, "headlines")...)
	for _, c := range anyList(pr, "cards") {
		if m, ok := c.(map[string]any); ok {
			parts = append(parts, str(m, "headline"))
		}
	}
	if t, ok := pr["template"].(map[string]any); ok {
		parts = append(parts, str(t, "headline"), str(t, "primary_text"))
	}
	return strings.Join(parts, " ")
}
