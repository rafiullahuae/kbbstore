package brain

// Art direction for AI backgrounds: the brain writes the prompt, the owner can
// edit it before paying. Rules baked in:
//   - the set is EMPTY: no product, packaging, bottle, label, text or logo is
//     drawn (the real product photo is composited on top afterwards);
//   - room is left where the product will stand (centre-low on 1:1/4:5,
//     middle third on 9:16, which keeps the Reels/Stories UI bands clear);
//   - audience: Arab women in the UAE/GCC -- premium, feminine, calm; when a
//     person is wanted she is modestly styled (long sleeves, loose abaya or
//     hijab, or hands only), never a celebrity, never a real person's likeness;
//   - settings that read as home for the audience: marble vanities in Dubai
//     apartments, majlis daylight, desert-rose sand tones, Gulf morning light;
//   - K-beauty art direction: dewy, soft, clean, high-key, glass and water.

import (
	"fmt"
	"strings"
)

type SceneRequest struct {
	Family   string   `json:"family"`   // design family (hero, glow, ingredient, ...)
	Angle    string   `json:"angle"`    // the ad's angle
	Product  string   `json:"product"`  // product title (for category cues only)
	Category string   `json:"category"` // toner, serum, sunscreen, ...
	Bullets  []string `json:"bullets"`  // ingredients / benefits
	Palette  []string `json:"palette"`  // hex colours picked from the product photo
	Format   string   `json:"format"`   // 1:1 | 4:5 | 9:16
	Setting  string   `json:"setting"`  // studio | vanity | majlis | nature | water | auto
	People   string   `json:"people"`   // none | hands | modest
	Season   string   `json:"season"`   // ramadan | eid | summer | winter | national_day | ""
}

var familyMood = map[string]string{
	"hero":       "a minimal sculptural plinth of travertine and frosted glass on a seamless soft blush backdrop, gentle gradient light",
	"glow":       "dewy glass-skin mood: soft caustic light through water and glass, luminous pearl highlights, light leaks in rose and champagne",
	"ingredient": "the hero ingredient as fresh botanical still life, droplets on leaves, crystal-clear water splash frozen mid-air",
	"offer":      "bold yet elegant rose-pink colour block set with a glossy satin ribbon and gold accents, celebratory but premium",
	"routine":    "a calm morning bathroom vanity shelf in soft daylight, folded linen towel, ceramic tray, three empty spots in a row",
	"editorial":  "high-fashion beauty editorial set, deep rose silk drapery and polished gold, dramatic soft side light",
	"petals":     "rose and peony petals drifting over a pale pink silk surface, airy and romantic, shallow depth of field",
	"texture":    "a macro cream and gel texture swirl in pearly pink, glossy and tactile, studio macro lighting",
	"qa":         "a bright clean vanity corner with a round mirror and soft morning light, calm and trustworthy",
	"collection": "a long shelf-like marble ledge with soft shadows, a premium boutique display, evenly lit",
	"clean":      "a minimal sculptural plinth on a seamless soft blush backdrop, gentle gradient light",
}

var settingCue = map[string]string{
	"studio": "seamless studio set",
	"vanity": "a marble vanity in a bright modern Dubai apartment, morning light through sheer curtains, palm shadow",
	"majlis": "an elegant contemporary majlis interior with warm daylight, cream fabrics and brass details",
	"nature": "desert-rose sand dunes at golden hour with soft haze, warm and serene",
	"water":  "still turquoise water with gentle ripples and sunlit caustics, Gulf summer light",
}

var seasonCue = map[string]string{
	"ramadan":      "subtle Ramadan evening mood: a soft crescent glow and lantern light far in the background, elegant and restrained",
	"eid":          "Eid gifting mood: satin ribbon and soft gold sparkle, festive but refined",
	"summer":       "bright UAE summer light, cooling water freshness",
	"winter":       "soft cosy winter light, cashmere textures",
	"national_day": "a refined touch of UAE flag colours in the far background bokeh",
}

func productCue(r SceneRequest) string {
	t := strings.ToLower(r.Product + " " + r.Category + " " + r.Angle + " " + strings.Join(r.Bullets, " "))
	switch {
	case strings.Contains(t, "sun") || strings.Contains(t, "spf"):
		return "sunlit, airy, a cool aqua freshness"
	case strings.Contains(t, "heartleaf") || strings.Contains(t, "centella") || strings.Contains(t, "cica"):
		return "fresh green heartleaf and centella leaves with dew"
	case strings.Contains(t, "rice"):
		return "soft rice grains and milky light"
	case strings.Contains(t, "propolis") || strings.Contains(t, "honey"):
		return "golden honey light and a soft amber glow"
	case strings.Contains(t, "hyaluronic") || strings.Contains(t, "aqua") || strings.Contains(t, "water"):
		return "clear water ripples and floating droplets"
	case strings.Contains(t, "collagen") || strings.Contains(t, "night"):
		return "a calm night-time mood with moonlit satin"
	case strings.Contains(t, "lip"):
		return "glossy rosy satin"
	case strings.Contains(t, "plum") || strings.Contains(t, "fruit"):
		return "fresh green plums with dew"
	case strings.Contains(t, "snail") || strings.Contains(t, "mucin"):
		return "a clear glossy gel texture"
	}
	return "soft pastel freshness"
}

// ScenePrompt: the still background, product-free.
func ScenePrompt(r SceneRequest) string {
	fam := familyMood[r.Family]
	if fam == "" {
		fam = familyMood["hero"]
	}
	var b strings.Builder
	fmt.Fprintf(&b, "Premium K-beauty skincare advertising photograph, background plate only. %s, with %s.", capitalise(fam), productCue(r))
	if s := settingCue[r.Setting]; s != "" && r.Setting != "studio" {
		fmt.Fprintf(&b, " Set in %s.", s)
	}
	if s := seasonCue[r.Season]; s != "" {
		fmt.Fprintf(&b, " %s.", capitalise(s))
	}
	if len(r.Palette) > 0 {
		fmt.Fprintf(&b, " Colour palette harmonised with %s, plus kbeautybliss rose (#C6395F) and soft champagne gold.", strings.Join(limitStrings(r.Palette, 3), ", "))
	} else {
		b.WriteString(" Colour palette: blush pink, rose (#C6395F), cream and soft champagne gold.")
	}
	switch r.People {
	case "hands":
		b.WriteString(" Include only a woman's elegant hand with neat natural nails and a fine gold ring, entering from the edge, the sleeve of a flowing cream abaya; no face.")
	case "modest":
		b.WriteString(" A graceful Gulf Arab woman, modestly styled in a soft loose hijab and long-sleeved flowing clothing, softly out of focus at the side, serene and natural, no exaggerated makeup, not a celebrity.")
	default:
		b.WriteString(" No people.")
	}
	switch r.Format {
	case "9:16":
		b.WriteString(" Vertical 9:16 composition: keep the middle third open and uncluttered for a product to be placed later; keep the top 14% and bottom 20% calm and plain.")
	case "1:1":
		b.WriteString(" Square composition with a clear empty surface in the lower centre where a product will be placed later.")
	default:
		b.WriteString(" Portrait 4:5 composition with a clear empty surface in the lower centre where a product will be placed later.")
	}
	b.WriteString(" IMPORTANT: no product, no bottle, no jar, no tube, no packaging, no label, no text, no letters, no logo, no watermark. Photorealistic, soft high-key lighting, shallow depth of field, editorial luxury beauty quality.")
	return b.String()
}

// MotionPrompt: how the background plate should move (image-to-video).
func MotionPrompt(r SceneRequest) string {
	base := map[string]string{
		"glow":       "slow drifting caustic light and shimmering highlights",
		"ingredient": "leaves sway gently, droplets fall in slow motion",
		"petals":     "petals drift down slowly and settle",
		"texture":    "the cream swirl turns slowly with glossy highlights",
		"offer":      "the satin ribbon flows gently, soft gold sparkle",
		"editorial":  "silk drapery moves softly, light sweeps across",
	}[r.Family]
	if base == "" {
		base = "gentle light sweep and subtle parallax, soft water ripples"
	}
	return "Slow, elegant camera push-in, " + base + ". Calm luxury beauty mood, smooth motion, no cuts. The empty space stays empty: no product, no packaging, no text, no logo appears. " + ScenePrompt(r)
}

func capitalise(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}

func limitStrings(s []string, n int) []string {
	var out []string
	for _, v := range s {
		v = strings.TrimSpace(v)
		if len(v) == 7 && v[0] == '#' && len(out) < n {
			out = append(out, v)
		}
	}
	return out
}
