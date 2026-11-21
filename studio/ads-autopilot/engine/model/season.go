package model

// season.go — what each product is FOR, and the five products an occasion package gets (the owner, 21 Nov 2026,
// looking at a Winter package with one eye cream in it: "if the system is planning winter campaigns, then the system
// must know about the products what are for winter, and always give me 5 products from the most selling / latest
// ones, and if i remove any product, it should come in next suggested products").
//
// Code rules only (instant, no Claude call): every product is tagged from its title, category and feed description
// with English + Arabic keywords — routine step (cleanser, toner, serum, eye, cream, balm, mask, spf, lip, body, set),
// benefit (hydration, barrier, soothing, brightening, anti-ageing, oil-control) and texture (rich vs light). Each
// occasion maps to a season (winter, summer, ramadan, gifting, sale) whose profile weighs those tags. The owner can
// say what a product is for himself (Playbook.ProductSeasons, Strategy → Products): his word wins over the rules.
//
// Score = fit × (0.35 + 0.65 × units sold in 90 days, normalised) + newness + trending + momentum − low stock − low
// margin; pinned (Playbook Focus) first. Avoid / out of stock / under the stock or margin floor never get here: the
// shop is Playbook.Filter-ed before.

import (
	"math"
	"sort"
	"strings"
)

// Seasons: what the owner can say a product is for (Strategy → Products), in this order.
var Seasons = []string{"winter", "summer", "ramadan", "gifting", "sale"}

type tagRule struct {
	tag string
	en  []string // word prefixes ("hydrat" names hydrating, hydration) or phrases with a space
	ar  []string // substrings
}

var tagRules = []tagRule{
	{"cleanser", []string{"cleans", "foam", "wash", "micellar"}, []string{"منظف", "غسول", "رغوة"}},
	{"toner", []string{"toner", "toning", "mist"}, []string{"تونر"}},
	{"serum", []string{"serum", "essence", "ampoule", "ampule"}, []string{"سيروم", "مصل", "أمبول", "إيسنس"}},
	{"eye", []string{"eye"}, []string{"العين", "للعين"}},
	{"cream", []string{"cream", "moisturi", "lotion", "emulsion"}, []string{"كريم", "مرطب"}},
	{"balm", []string{"balm", "butter", "ointment"}, []string{"بلسم", "بلم", "زبدة"}},
	{"mask", []string{"mask", "sleeping pack", "pad"}, []string{"ماسك", "قناع"}},
	{"spf", []string{"spf", "sunscreen", "sun=", "suncream", "sunblock", "uv="}, []string{"واقي", "الشمس", "شمس"}},
	{"lip", []string{"lip"}, []string{"شفاه", "شفايف", "الشفاه"}},
	{"body", []string{"body", "hand cream", "hand=", "hands="}, []string{"الجسم", "جسم", "اليدين"}},
	{"set", []string{"set=", "sets=", "kit=", "duo=", "trio=", "gift", "bundle", "mini="}, []string{"مجموعة", "هدية", "طقم"}},
	{"hydration", []string{"hydrat", "hyaluron", "moistur", "aqua", "water=", "dewy", "plump"}, []string{"ترطيب", "مرطب", "هيالورون", "رطوبة"}},
	{"barrier", []string{"barrier", "ceramide", "cica", "panthenol", "repair", "squalane"}, []string{"حاجز", "سيراميد", "سيكا", "إصلاح"}},
	{"soothing", []string{"sooth", "calm", "centella", "heartleaf", "aloe", "mugwort", "sensitive"}, []string{"مهدئ", "تهدئة", "حساسة"}},
	{"brightening", []string{"bright", "glow", "vitamin c", "niacinamide", "dark spot", "tone up", "tone-up", "radian", "rice", "melasma", "whiten"}, []string{"تفتيح", "إشراق", "نضارة", "توهج", "التصبغ", "البقع"}},
	{"anti-ageing", []string{"retinol", "retinal", "peptide", "collagen", "wrinkle", "firming", "anti-aging", "anti-ageing", "pdrn", "bakuchiol", "lifting"}, []string{"تجاعيد", "ريتينول", "ببتيد", "كولاجين"}},
	{"oil-control", []string{"oil control", "oil-control", "sebum", "pore", "bha", "salicylic", "acne", "blemish", "matte", "mattif", "tea tree"}, []string{"مسام", "الدهون", "دهني", "حب الشباب"}},
	{"rich", []string{"rich", "intens", "nourish", "butter", "balm", "ointment", "night", "sleeping"}, []string{"غني", "مغذي", "ليلي"}},
	{"light", []string{"gel", "light", "watery", "fluid", "fresh", "cooling", "water cream", "airy"}, []string{"خفيف", "منعش"}},
}

// TagLabels: the owner-readable name of each tag (EN, AR), for the fit reason on a chip.
var TagLabels = map[string][2]string{
	"cleanser": {"cleanser", "منظف"}, "toner": {"toner", "تونر"}, "serum": {"serum", "سيروم"}, "eye": {"eye care", "العين"},
	"cream": {"cream", "كريم"}, "balm": {"balm", "بلسم"}, "mask": {"mask", "ماسك"}, "spf": {"SPF", "واقي شمس"}, "lip": {"lip care", "الشفاه"},
	"body": {"body", "الجسم"}, "set": {"gift set", "مجموعة هدايا"}, "hydration": {"hydration", "ترطيب"}, "barrier": {"barrier", "حاجز البشرة"},
	"soothing": {"soothing", "تهدئة"}, "brightening": {"glow", "إشراق"}, "anti-ageing": {"anti-ageing", "مضاد للتجاعيد"},
	"oil-control": {"oil control", "تحكم بالدهون"}, "rich": {"rich texture", "قوام غني"}, "light": {"light texture", "قوام خفيف"},
	"winter": {"winter", "الشتاء"}, "summer": {"summer", "الصيف"}, "ramadan": {"Ramadan", "رمضان"}, "gifting": {"gifting", "الهدايا"}, "sale": {"sale days", "أيام التخفيضات"},
}

func words(s string) []string {
	return strings.FieldsFunc(strings.ToLower(s), func(r rune) bool {
		return !(r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r > 127)
	})
}

// ClassifyProduct: the product's tags from its title, category and description (EN + AR keywords), sorted.
func ClassifyProduct(title, category, desc string) []string {
	hay := strings.ToLower(title + " | " + category + " | " + desc)
	ws := words(hay)
	flat := " " + strings.Join(ws, " ") + " "
	out := []string{}
	for _, r := range tagRules {
		hit := false
		for _, k := range r.en {
			if strings.Contains(k, " ") || strings.Contains(k, "-") {
				hit = hit || strings.Contains(flat, " "+strings.ReplaceAll(k, "-", " ")) || strings.Contains(hay, k)
				continue
			}
			exact := strings.HasSuffix(k, "=")
			k = strings.TrimSuffix(k, "=")
			for _, w := range ws {
				if w == k || !exact && strings.HasPrefix(w, k) {
					hit = true
					break
				}
			}
			if hit {
				break
			}
		}
		for _, k := range r.ar {
			hit = hit || strings.Contains(hay, k)
		}
		if hit {
			out = append(out, r.tag)
		}
	}
	sort.Strings(out)
	return out
}

// TagsOf: the feed-time tags, or (a snapshot saved before tags existed) the rules on the title and category now.
func TagsOf(p Product) []string {
	if p.Tags != nil {
		return p.Tags
	}
	return ClassifyProduct(p.Title, p.Category, "")
}

func has(tags []string, t string) bool {
	for _, x := range tags {
		if x == t {
			return true
		}
	}
	return false
}

// AutoSeasons: what the rules say a product is for.
func AutoSeasons(tags []string) []string {
	h := func(ts ...string) bool {
		for _, t := range ts {
			if has(tags, t) {
				return true
			}
		}
		return false
	}
	var out []string
	summerish := h("spf", "oil-control") || h("light") && !h("rich", "balm")
	if h("hydration", "barrier", "rich", "balm", "lip", "mask") && !h("spf") && !(h("oil-control") && !h("hydration", "barrier")) {
		out = append(out, "winter")
	}
	if summerish {
		out = append(out, "summer")
	}
	if h("hydration", "lip", "barrier", "brightening", "soothing") {
		out = append(out, "ramadan")
	}
	if h("set", "brightening", "anti-ageing") {
		out = append(out, "gifting")
	}
	return append(out, "sale")
}

// seasonProfile: how much each tag fits a season (negative = works against it). "sale" has none: there the best
// sellers and the new arrivals decide.
var seasonProfile = map[string]map[string]float64{
	"winter":  {"hydration": 1, "barrier": 1, "rich": 0.9, "balm": 0.9, "cream": 0.8, "lip": 0.7, "mask": 0.6, "soothing": 0.4, "eye": 0.3, "body": 0.3, "anti-ageing": 0.2, "spf": -0.7, "oil-control": -0.8, "light": -0.4, "cleanser": -0.2},
	"summer":  {"spf": 1.2, "oil-control": 0.9, "light": 0.8, "soothing": 0.4, "cleanser": 0.3, "toner": 0.3, "rich": -0.6, "balm": -0.6},
	"ramadan": {"hydration": 0.9, "brightening": 0.8, "lip": 0.6, "barrier": 0.6, "set": 0.6, "soothing": 0.4, "cream": 0.4},
	"gifting": {"set": 1.2, "brightening": 0.6, "anti-ageing": 0.4, "hydration": 0.3, "lip": 0.3, "mask": 0.3, "eye": 0.2},
	"sale":    {},
}

// seasonOf: the season an occasion is planned on (the defaults by id; the owner's own by its words).
var seasonOfID = map[string]string{
	"winter": "winter", "dsf": "winter", "summer": "summer", "dss": "summer", "back-to-school": "summer", "ramadan": "ramadan",
	"white-friday": "sale", "cyber-monday": "sale", "singles-day": "sale",
}

func OccasionSeason(o Occasion) string {
	if s, ok := seasonOfID[o.ID]; ok {
		return s
	}
	if strings.HasPrefix(o.ID, "eid-") || strings.Contains(o.ID, "national-day") || strings.Contains(o.ID, "founding") {
		return "gifting"
	}
	t := ClassifyProduct(o.Name+" "+o.Angle+" "+strings.Join(o.Products, " "), "", "")
	l := strings.ToLower(o.Name + " " + o.Angle)
	switch {
	case strings.Contains(l, "winter") || strings.Contains(l, "شتاء") || has(t, "barrier") && has(t, "hydration"):
		return "winter"
	case strings.Contains(l, "summer") || strings.Contains(l, "صيف") || has(t, "spf"):
		return "summer"
	case strings.Contains(l, "ramadan") || strings.Contains(l, "رمضان"):
		return "ramadan"
	case strings.Contains(l, "sale") || strings.Contains(l, "friday") || strings.Contains(l, "تخفيض"):
		return "sale"
	}
	return "gifting"
}

// Fit: how well these tags fit the season, 0..1, and the matched tags, best first.
func Fit(tags []string, season string) (float64, []string) {
	prof := seasonProfile[season]
	if len(prof) == 0 {
		return 0.6, nil
	}
	sum := 0.0
	var pos []string
	for _, t := range tags {
		w := prof[t]
		sum += w
		if w > 0 {
			pos = append(pos, t)
		}
	}
	sort.SliceStable(pos, func(i, j int) bool { return prof[pos[i]] > prof[pos[j]] })
	return clampF(sum/1.8, 0, 1), pos
}

// SlotPick: one product as an occasion package (or a job's slot) shows it.
type SlotPick struct {
	ID       string   `json:"id"`
	Title    string   `json:"title"`
	Brand    string   `json:"brand,omitempty"`
	Image    string   `json:"image,omitempty"`
	URL      string   `json:"url,omitempty"`
	PriceAED float64  `json:"price"`
	Score    float64  `json:"score"`
	Fit      float64  `json:"fit"`
	Tags     []string `json:"tags"`
	Seasons  []string `json:"seasons"`
	Reason   string   `json:"reason"`    // "rich texture · barrier · winter"
	ReasonAR string   `json:"reason_ar"` // the same in Arabic
	Units30  int      `json:"units_30d"`
	Units90  int      `json:"units_90d"`
	New      bool     `json:"new,omitempty"`
	Trending bool     `json:"trending,omitempty"`
	Pinned   bool     `json:"pinned,omitempty"`
	Yours    bool     `json:"seasons_yours,omitempty"` // the owner set what it is for
}

// SeasonsFor: the owner's word for this product (Strategy → Products), else the rules'.
func (p Playbook) SeasonsFor(pr Product) ([]string, bool) {
	if s, ok := p.ProductSeasons[pr.ID]; ok {
		return s, true
	}
	if i := strings.IndexByte(pr.ID, '-'); i > 0 { // a variant line follows its product
		if s, ok := p.ProductSeasons[pr.ID[:i]]; ok {
			return s, true
		}
	}
	return AutoSeasons(TagsOf(pr)), false
}

func sold(p Product) int {
	u := p.Units14 + p.UnitsPrev14
	if p.Units90 > u {
		u = p.Units90
	}
	return u
}

// RankForOccasion: the shop (ALREADY filtered by the Playbook) ranked for this occasion, best first.
func RankForOccasion(prods []Product, pb Playbook, o Occasion, sig RankSignals) []SlotPick {
	season := OccasionSeason(o)
	by := make(map[string]Product, len(prods))
	maxSold := 0
	for _, p := range prods {
		by[p.ID] = p
		if s := sold(p); s > maxSold {
			maxSold = s
		}
	}
	var out []SlotPick
	for _, r := range Rank(prods, pb, sig) {
		p := by[r.ID]
		tags := TagsOf(p)
		seasons, yours := pb.SeasonsFor(p)
		fit, matched := Fit(tags, season)
		if yours { // his word wins: for this season = a full fit, not for it = almost none
			if has(seasons, season) || season == "sale" {
				fit = math.Max(fit, 0.9)
			} else {
				fit = 0.05
			}
		}
		salesN := 0.0
		if maxSold > 0 {
			salesN = math.Log1p(float64(sold(p))) / math.Log1p(float64(maxSold))
		}
		pen := 0.0
		if p.Stock != nil && pb.MinStock > 0 && *p.Stock < pb.MinStock*2 {
			pen += 0.1
		}
		if p.MarginPct != nil && *p.MarginPct < math.Max(pb.MinMarginPct+10, 25) {
			pen += 0.1
		}
		newS := r.Parts["new"]
		isNew := (r.AgeDays >= 0 && r.AgeDays <= 45) || newS >= 0.72
		score := fit*(0.35+0.65*salesN) + 0.2*newS + 0.15*r.Parts["trend"] + 0.1*r.Parts["momentum"] - pen
		if season == "sale" { // White Friday & co: best sellers and new arrivals
			score = 0.6*salesN + 0.3*newS + 0.15*r.Parts["trend"] + 0.1*r.Parts["momentum"] - pen
		}
		sp := SlotPick{ID: p.ID, Title: p.Title, Brand: p.Brand, Image: p.Image, URL: p.URL, PriceAED: p.PriceAED, Fit: r3(fit), Tags: tags, Seasons: seasons, Yours: yours,
			Units30: p.Units30, Units90: sold(p), New: isNew, Trending: r.Trending, Pinned: r.Pinned}
		if sp.Units30 == 0 {
			sp.Units30 = p.Units14
		}
		if r.Pinned {
			score += 100
		}
		sp.Score = math.Round(score*1000) / 10
		var en, ar []string
		for i, t := range matched {
			if i == 2 {
				break
			}
			en, ar = append(en, TagLabels[t][0]), append(ar, TagLabels[t][1])
		}
		if yours && has(seasons, season) {
			en, ar = append(en, "you said"), append(ar, "حسب اختيارك")
		}
		if fit >= 0.3 || season == "sale" {
			en, ar = append(en, TagLabels[season][0]), append(ar, TagLabels[season][1])
		} else {
			en, ar = append(en, "best match left"), append(ar, "أفضل المتاح")
		}
		if salesN >= 0.5 && sold(p) > 0 {
			en, ar = append(en, "best seller"), append(ar, "الأكثر مبيعاً")
		}
		sp.Reason, sp.ReasonAR = strings.Join(en, " · "), strings.Join(ar, " · ")
		out = append(out, sp)
	}
	sort.SliceStable(out, func(i, j int) bool {
		if out[i].Score != out[j].Score {
			return out[i].Score > out[j].Score
		}
		return out[i].ID > out[j].ID
	})
	return out
}

// Slots: how many products an occasion package carries (Strategy → Occasions, 3–8, default 5).
func (p Playbook) Slots() int {
	if p.OccasionSlots < 3 || p.OccasionSlots > 8 {
		return 5
	}
	return p.OccasionSlots
}

func normSeasons(m map[string][]string) map[string][]string {
	if len(m) == 0 {
		return nil
	}
	ok := map[string]bool{}
	for _, s := range Seasons {
		ok[s] = true
	}
	out := map[string][]string{}
	for id, ss := range m {
		id = strings.TrimSpace(id)
		if id == "" || len(id) > 64 || len(out) >= 3000 {
			continue
		}
		seen := map[string]bool{}
		v := []string{}
		for _, s := range ss {
			if ok[s] && !seen[s] {
				seen[s] = true
				v = append(v, s)
			}
		}
		out[id] = v
	}
	return out
}
