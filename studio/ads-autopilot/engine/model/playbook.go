package model

// playbook.go — the owner's Playbook (his standing instructions, always obeyed) and the product ranking both the
// engine and the brain use. One rule in one place: the engine filters the shop with it BEFORE the brain (or the
// funnel builder) sees a single product, and the brain ranks and checks its own output with the same functions.
//
// The owner, 20 Nov 2026: "i want to include the latest and trending products, not the old like snail mucin etc.
// will i have any way to change the upcoming job or edit the prompt etc or plan?"

import (
	"fmt"
	"math"
	"sort"
	"strconv"
	"strings"
	"time"
)

// PBRule: one Focus (pin) or Avoid (exclude) line. Kind product | brand | category | keyword.
type PBRule struct {
	Kind  string `json:"kind"`
	Value string `json:"value"`
	Label string `json:"label,omitempty"` // what the owner saw when he picked it (a product title)
}

// Split: how the daily budget divides across the funnel, in percent (normalised to 100).
type Split struct {
	Prospecting float64 `json:"prospecting"`
	Retargeting float64 `json:"retargeting"`
	Existing    float64 `json:"existing"`
}

// Knobs: the decision thresholds the owner may move on the Strategy screen. 0 / "" = the built-in default.
type Knobs struct {
	KillP             float64 `json:"kill_p"`               // stop when P(CPA < break-even) <= this (default 0.20)
	ScaleP            float64 `json:"scale_p"`              // scale when P(CPA < target) >= this (default 0.80)
	ScaleMinPurch     int     `json:"scale_min_purchases"`  // and at least this many settled purchases (default 5)
	HardStopMult      float64 `json:"hard_stop_mult"`       // 0 purchases after this x break-even CPA -> stop (default 3)
	FatigueFreq       float64 `json:"fatigue_frequency"`    // 7-day frequency counted as tiring (default 2.5)
	FatigueCTRDropPct float64 `json:"fatigue_ctr_drop_pct"` // CTR drop vs its first 14 days counted as tiring (default 30)
	ArabicSharePct    float64 `json:"arabic_share_pct"`     // Arabic share of new tests (default 70, kept 55..85)
	ProfitSharePct    float64 `json:"profit_share_pct"`     // target CPA = break-even x (1 - this) (default 25)
	BatchDay          string  `json:"batch_day"`            // mon..sun, the weekly day new ads go out (default mon)
	MaxNewAdsBatch    int     `json:"max_new_ads_batch"`    // new ads per weekly batch (default 4)
	// the low-budget agency playbook's tier thresholds (Strategy → Our playbook)
	SmallBudgetAED     float64 `json:"small_budget_aed"`       // under this AED/day the account runs the small-budget structure (default 150)
	RetargetMaxFreq    float64 `json:"retarget_max_frequency"` // retargeting is scaled only while its 7-day frequency is below this (default 3)
	CostCapMinConvWeek int     `json:"cost_cap_min_conv_week"` // cost cap is suggested only from this many purchases a week (default 50)
	ConversionsPerWeek int     `json:"conversions_per_week"`   // what one ad set needs a week to exit learning (default 50, Meta)
}

// EconInputs: VAT is removed from revenue first (prices include it), then the payment fee on the order total by
// payment family, the delivery cost and the free gift. Defaults: the owner, 20 Nov 2026.
type EconInputs struct {
	VATPct      float64 `json:"vat_pct"`       // 5
	BNPLFeePct  float64 `json:"bnpl_fee_pct"`  // Tabby / Tamara 7
	CardFeePct  float64 `json:"card_fee_pct"`  // Stripe (cards, Apple Pay) 2.5
	CODFeePct   float64 `json:"cod_fee_pct"`   // Quiqup cash collection 1.5
	DeliveryAED float64 `json:"delivery_aed"`  // Quiqup 15 per order
	GiftCostAED float64 `json:"gift_cost_aed"` // the free gift per order (unknown yet: 0 until he adds it)
}

func DefaultEconInputs() EconInputs {
	return EconInputs{VATPct: 5, BNPLFeePct: 7, CardFeePct: 2.5, CODFeePct: 1.5, DeliveryAED: 15}
}

// TuneBounds: the range inside which the self-learning loop may move a threshold on its own.
type TuneBounds struct {
	HardStopMin float64 `json:"hard_stop_min"` // the "stop a non-buyer after N x break-even CPA" multiple (default 2..4)
	HardStopMax float64 `json:"hard_stop_max"`
	FatigueMin  float64 `json:"fatigue_freq_min"` // the tiring frequency (default 2..3.5)
	FatigueMax  float64 `json:"fatigue_freq_max"`
}

// Defaults of the knobs that have one (the rest are the brain's RulesConfig).
const (
	DefSmallBudgetAED     = 150
	DefRetargetMaxFreq    = 3
	DefCostCapMinConvWeek = 50
	DefConversionsPerWeek = 50
)

func (k Knobs) SmallBudget() float64  { return orF(k.SmallBudgetAED, DefSmallBudgetAED) }
func (k Knobs) RetargetFreq() float64 { return orF(k.RetargetMaxFreq, DefRetargetMaxFreq) }
func (k Knobs) CostCapWeek() int      { return orI(k.CostCapMinConvWeek, DefCostCapMinConvWeek) }
func (k Knobs) ConvWeek() int         { return orI(k.ConversionsPerWeek, DefConversionsPerWeek) }

func orF(x, d float64) float64 {
	if x > 0 {
		return x
	}
	return d
}
func orI(x, d int) int {
	if x > 0 {
		return x
	}
	return d
}

type Playbook struct {
	Instructions      string   `json:"instructions"` // free text, Arabic or English: sent to Claude verbatim, first, with priority
	Focus             []PBRule `json:"focus"`
	Avoid             []PBRule `json:"avoid"`
	FreshnessPct      float64  `json:"freshness_pct"`  // "Prefer new & trending" 0..100
	MinStock          int      `json:"min_stock"`      // products with fewer units in stock are never advertised (unknown stock passes)
	MinMarginPct      float64  `json:"min_margin_pct"` // products below this margin are never advertised (unknown margin passes)
	Countries         []string `json:"countries"`      // empty = the countries in Limits
	AgeMin            int      `json:"age_min"`
	AgeMax            int      `json:"age_max"`
	Gender            string   `json:"gender"`              // women | all
	Languages         []string `json:"languages"`           // which ad languages run: ar, en
	ExcludeBuyersDays int      `json:"exclude_buyers_days"` // prospecting leaves out people who bought in this many days (0 = no)
	Split             Split    `json:"split"`               // at or above the small-budget tier
	SmallSplit        Split    `json:"small_split"`         // under it (agency rule: ~80% prospecting / ~20% retargeting, no separate existing-customer spend)
	OffersAllowed     bool     `json:"offers_allowed"`      // the offers below may be used in ads (never a % or amount off: the linter blocks it)
	FreeGift          bool     `json:"free_gift"`           // "Free gift with every order" / «هدية مجانية مع كل طلب»
	CouponCode        string   `json:"coupon_code"`         // "Use code GLOW at checkout" / «استخدمي الكود GLOW»
	Gifts             []string `json:"gifts"`               // the gifts we give, by name (filled by the owner; never invented)
	PayLater          bool     `json:"pay_later"`           // "Pay in 4 with Tabby or Tamara" / «قسّميها على ٤ دفعات مع تابي أو تمارا»
	// Econ: the shop's real unit economics (Strategy → How it decides); the math is shown there
	Econ EconInputs `json:"econ"`
	// Occasions (Strategy → Occasions): nil = the defaults. Nothing seasonal goes live without his approval unless
	// AutoApproveOccasions (off by default).
	Occasions            []Occasion `json:"occasions,omitempty"`
	AutoApproveOccasions bool       `json:"auto_approve_occasions"`
	// OccasionSlots: how many products each occasion package carries (3–8, default 5; season.go). ProductSeasons:
	// what the owner says a product is for (product id → winter / summer / ramadan / gifting / sale); his word wins
	// over the rules (Strategy → Products).
	OccasionSlots  int                 `json:"occasion_slots"`
	ProductSeasons map[string][]string `json:"product_seasons,omitempty"`
	Knobs          Knobs               `json:"knobs"`
	// "Let it tune its own rules": off | bounds (default: it changes a threshold itself, only inside TuneBounds) | ask
	SelfTune   string     `json:"self_tune"`
	TuneBounds TuneBounds `json:"tune_bounds"`
	UpdatedAt  time.Time  `json:"updated_at"`
	Version    int        `json:"version"`
}

// DefaultPlaybook: what the owner asked for on 20 Nov ships ON (CLAUDE.md "what he asked for ships on"): new and
// trending first, old evergreens only when the data says they win. Everything is one click to change or reset.
func DefaultPlaybook() Playbook {
	return Playbook{
		Instructions: "Focus on the latest arrivals and the products trending now. Do not push old evergreen items (for example snail mucin) unless the shop's own sales show they are winning.\n" +
			"ركّز على أحدث المنتجات والأكثر رواجاً الآن. لا تروّج للمنتجات القديمة (مثل سنيل ميوسين) إلا إذا أثبتت مبيعات المتجر أنها تربح.",
		Focus: []PBRule{}, Avoid: []PBRule{}, FreshnessPct: 70, MinStock: 3, AgeMin: 18, AgeMax: 45, Gender: "women",
		Languages: []string{"ar", "en"}, SelfTune: "bounds", TuneBounds: TuneBounds{2, 4, 2, 3.5}, OffersAllowed: true, FreeGift: true, CouponCode: "GLOW", Gifts: []string{}, PayLater: true, Econ: DefaultEconInputs(), ExcludeBuyersDays: 180, Split: Split{Prospecting: 70, Retargeting: 20, Existing: 10}, SmallSplit: Split{Prospecting: 80, Retargeting: 20},
	}
}

var pbKinds = map[string]bool{"product": true, "brand": true, "category": true, "keyword": true}

func clampF(x, lo, hi float64) float64 {
	if math.IsNaN(x) || math.IsInf(x, 0) {
		return lo
	}
	return math.Max(lo, math.Min(hi, x))
}

func cleanText(s string, n int) string {
	s = strings.Map(func(r rune) rune {
		if r == '\n' || r == '\t' {
			return r
		}
		if r < 32 || r == 0x7f {
			return -1
		}
		return r
	}, s)
	if r := []rune(s); len(r) > n {
		s = string(r[:n])
	}
	return strings.TrimSpace(s)
}

func cleanRules(rs []PBRule) []PBRule {
	out := []PBRule{}
	seen := map[string]bool{}
	for _, r := range rs {
		r.Kind = strings.ToLower(strings.TrimSpace(r.Kind))
		r.Value = cleanText(r.Value, 80)
		r.Label = cleanText(r.Label, 120)
		k := r.Kind + "|" + strings.ToLower(r.Value)
		if !pbKinds[r.Kind] || r.Value == "" || seen[k] || len(out) >= 60 {
			continue
		}
		seen[k] = true
		out = append(out, r)
	}
	return out
}

// Normalize: every value back inside its range; a select keeps one of its own options or the default.
func (p Playbook) Normalize() Playbook {
	d := DefaultPlaybook()
	p.Instructions = cleanText(p.Instructions, 4000)
	p.Focus, p.Avoid = cleanRules(p.Focus), cleanRules(p.Avoid)
	p.FreshnessPct = clampF(p.FreshnessPct, 0, 100)
	if p.MinStock < 0 || p.MinStock > 1000 {
		p.MinStock = 0
	}
	p.MinMarginPct = clampF(p.MinMarginPct, 0, 90)
	var cs []string
	for _, c := range p.Countries {
		c = strings.ToUpper(strings.TrimSpace(c))
		if len(c) == 2 && c[0] >= 'A' && c[0] <= 'Z' && c[1] >= 'A' && c[1] <= 'Z' && len(cs) < 25 {
			cs = append(cs, c)
		}
	}
	p.Countries = cs
	if p.AgeMin < 18 || p.AgeMin > 65 {
		p.AgeMin = d.AgeMin
	}
	if p.AgeMax < p.AgeMin || p.AgeMax > 65 {
		p.AgeMax = 65
	}
	if p.Gender != "all" {
		p.Gender = "women"
	}
	var ls []string
	for _, l := range p.Languages {
		if (l == "ar" || l == "en") && !strings.Contains(strings.Join(ls, ","), l) {
			ls = append(ls, l)
		}
	}
	if len(ls) == 0 {
		ls = []string{"ar"}
	}
	p.Languages = ls
	if p.ExcludeBuyersDays < 0 || p.ExcludeBuyersDays > 180 {
		p.ExcludeBuyersDays = 180
	}
	p.Split, p.SmallSplit = normSplit(p.Split, d.Split), normSplit(p.SmallSplit, d.SmallSplit)
	k := p.Knobs
	k.KillP, k.ScaleP = clampF(k.KillP, 0, 0.5), clampF(k.ScaleP, 0, 0.99)
	if k.ScaleP != 0 && k.ScaleP < 0.6 {
		k.ScaleP = 0.6
	}
	if k.ScaleMinPurch < 0 || k.ScaleMinPurch > 100 {
		k.ScaleMinPurch = 0
	}
	k.HardStopMult = clampF(k.HardStopMult, 0, 10)
	if k.HardStopMult != 0 && k.HardStopMult < 1.5 {
		k.HardStopMult = 1.5
	}
	k.FatigueFreq = clampF(k.FatigueFreq, 0, 10)
	k.FatigueCTRDropPct = clampF(k.FatigueCTRDropPct, 0, 90)
	k.ArabicSharePct = clampF(k.ArabicSharePct, 0, 85)
	if k.ArabicSharePct != 0 && k.ArabicSharePct < 55 {
		k.ArabicSharePct = 55
	}
	k.ProfitSharePct = clampF(k.ProfitSharePct, 0, 60)
	if _, ok := Weekdays[k.BatchDay]; !ok {
		k.BatchDay = ""
	}
	if k.MaxNewAdsBatch < 0 || k.MaxNewAdsBatch > 12 {
		k.MaxNewAdsBatch = 0
	}
	k.SmallBudgetAED = clampF(k.SmallBudgetAED, 0, 100000)
	k.RetargetMaxFreq = clampF(k.RetargetMaxFreq, 0, 10)
	if k.CostCapMinConvWeek < 0 || k.CostCapMinConvWeek > 1000 {
		k.CostCapMinConvWeek = 0
	}
	if k.ConversionsPerWeek < 0 || k.ConversionsPerWeek > 500 {
		k.ConversionsPerWeek = 0
	}
	p.Knobs = k
	p.CouponCode = strings.ToUpper(strings.Map(func(r rune) rune {
		if r >= 'A' && r <= 'Z' || r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '-' || r == '_' {
			return r
		}
		return -1
	}, p.CouponCode))
	if len(p.CouponCode) > 24 {
		p.CouponCode = p.CouponCode[:24]
	}
	gs := []string{}
	for _, g := range p.Gifts {
		if g = cleanText(g, 80); g != "" && len(gs) < 20 {
			gs = append(gs, g)
		}
	}
	p.Gifts = gs
	p.Occasions = normOccasions(p.Occasions)
	p.OccasionSlots = p.Slots()
	p.ProductSeasons = normSeasons(p.ProductSeasons)
	e, de := p.Econ, DefaultEconInputs()
	if e == (EconInputs{}) { // a playbook saved before these existed
		e = de
	}
	e.VATPct, e.BNPLFeePct, e.CardFeePct, e.CODFeePct = clampF(e.VATPct, 0, 20), clampF(e.BNPLFeePct, 0, 20), clampF(e.CardFeePct, 0, 20), clampF(e.CODFeePct, 0, 20)
	e.DeliveryAED, e.GiftCostAED = clampF(e.DeliveryAED, 0, 500), clampF(e.GiftCostAED, 0, 500)
	p.Econ = e
	if p.SelfTune != "off" && p.SelfTune != "ask" {
		p.SelfTune = "bounds"
	}
	tb := p.TuneBounds
	tb.HardStopMin, tb.HardStopMax = clampF(tb.HardStopMin, 1.5, 10), clampF(tb.HardStopMax, 1.5, 10)
	tb.FatigueMin, tb.FatigueMax = clampF(tb.FatigueMin, 1, 10), clampF(tb.FatigueMax, 1, 10)
	if tb.HardStopMin > tb.HardStopMax || tb.HardStopMax == 1.5 && tb.HardStopMin == 1.5 {
		tb.HardStopMin, tb.HardStopMax = d.TuneBounds.HardStopMin, d.TuneBounds.HardStopMax
	}
	if tb.FatigueMin > tb.FatigueMax || tb.FatigueMax == 1 && tb.FatigueMin == 1 {
		tb.FatigueMin, tb.FatigueMax = d.TuneBounds.FatigueMin, d.TuneBounds.FatigueMax
	}
	p.TuneBounds = tb
	return p
}

func normSplit(s, def Split) Split {
	s.Prospecting, s.Retargeting, s.Existing = clampF(s.Prospecting, 0, 100), clampF(s.Retargeting, 0, 100), clampF(s.Existing, 0, 100)
	t := s.Prospecting + s.Retargeting + s.Existing
	if t <= 0 {
		return def
	}
	s.Prospecting, s.Retargeting, s.Existing = math.Round(s.Prospecting/t*1000)/10, math.Round(s.Retargeting/t*1000)/10, math.Round(s.Existing/t*1000)/10
	if s.Prospecting < 10 { // prospecting is what finds new customers; it never drops below 10%
		s.Prospecting = 10
	}
	return s
}

// Tier: "small" under the small-budget threshold (AED/day), else "growth". SplitFor: the split that applies.
func (p Playbook) Tier(dailyAED float64) string {
	if dailyAED < p.Knobs.SmallBudget() {
		return "small"
	}
	return "growth"
}
func (p Playbook) SplitFor(dailyAED float64) Split {
	if p.Tier(dailyAED) == "small" {
		return p.SmallSplit
	}
	return p.Split
}

// AdSetsFor: how many prospecting ad sets a budget can feed so each still exits learning: budget ÷ (target CPA ×
// conversions-per-week ÷ 7), at least 1, at most 3 (agency rule "consolidate"; Meta: ~50 optimisation events in the
// 7 days after the last significant edit).
func (p Playbook) AdSetsFor(prospectingAED, targetCPA float64) int {
	if targetCPA <= 0 {
		return 1
	}
	n := int(prospectingAED / (targetCPA * float64(p.Knobs.ConvWeek()) / 7))
	if n < 1 {
		n = 1
	}
	if n > 3 {
		n = 3
	}
	return n
}

var Weekdays = map[string]time.Weekday{"sun": time.Sunday, "mon": time.Monday, "tue": time.Tuesday, "wed": time.Wednesday, "thu": time.Thursday, "fri": time.Friday, "sat": time.Saturday}

// CountriesOr: the playbook's countries, else the limits'.
func (p Playbook) CountriesOr(lim []string) []string {
	if len(p.Countries) > 0 {
		return p.Countries
	}
	return lim
}

// ── matching and the hard filter ──

// Match: does this rule name this product? product = its feed id or its group (feed "123-45" is product 123);
// brand = the brand (or the title starts with it); category = the feed category contains it; keyword = every word
// of it is in the title, brand or category. All case-insensitive.
func (r PBRule) Match(p Product) bool {
	v := strings.ToLower(strings.TrimSpace(r.Value))
	if v == "" {
		return false
	}
	switch r.Kind {
	case "product":
		id := strings.ToLower(p.ID)
		return id == v || strings.HasPrefix(id, v+"-")
	case "brand":
		return strings.EqualFold(strings.TrimSpace(p.Brand), v) || strings.HasPrefix(strings.ToLower(p.Title), v+" ")
	case "category":
		return strings.Contains(strings.ToLower(p.Category), v)
	case "keyword": // every word of it, in any order ("snail mucin" names "Advanced Snail 96 Mucin Essence")
		hay := strings.ToLower(p.Title + " \x00 " + p.Brand + " \x00 " + p.Category)
		for _, w := range strings.Fields(v) {
			if !strings.Contains(hay, w) {
				return false
			}
		}
		return true
	}
	return false
}

func matchAny(rs []PBRule, p Product) (PBRule, bool) {
	for _, r := range rs {
		if r.Match(p) {
			return r, true
		}
	}
	return PBRule{}, false
}

// Pinned: the owner pinned it (Focus).
func (p Playbook) Pinned(pr Product) bool { _, ok := matchAny(p.Focus, pr); return ok }

// Excluded is one product the hard filter took out, with the owner-readable reason.
type Excluded struct {
	ID     string `json:"id"`
	Title  string `json:"title"`
	Reason string `json:"reason"`
}

// Filter: the shop as the planner may see it. Avoid always wins (also over a pin); below the stock or margin floor
// is out unless the owner pinned it; an unknown stock or margin passes (it is not evidence of anything).
func (p Playbook) Filter(s ShopSnapshot) (ShopSnapshot, []Excluded) {
	out := s
	out.Products = make([]Product, 0, len(s.Products))
	ex := []Excluded{}
	for _, pr := range s.Products {
		if r, ok := matchAny(p.Avoid, pr); ok {
			ex = append(ex, Excluded{pr.ID, pr.Title, fmt.Sprintf("you said avoid (%s: %s)", r.Kind, r.Value)})
			continue
		}
		if !p.Pinned(pr) {
			if p.MinStock > 0 && pr.Stock != nil && *pr.Stock < p.MinStock {
				ex = append(ex, Excluded{pr.ID, pr.Title, fmt.Sprintf("only %d in stock (your minimum is %d)", *pr.Stock, p.MinStock)})
				continue
			}
			if p.MinMarginPct > 0 && pr.MarginPct != nil && *pr.MarginPct < p.MinMarginPct {
				ex = append(ex, Excluded{pr.ID, pr.Title, fmt.Sprintf("margin %.0f%% (your minimum is %.0f%%)", *pr.MarginPct, p.MinMarginPct)})
				continue
			}
		}
		out.Products = append(out.Products, pr)
	}
	return out, ex
}

// Allowed: a product id the planner may use (it survived the filter).
func Allowed(s ShopSnapshot) map[string]bool {
	m := make(map[string]bool, len(s.Products))
	for _, p := range s.Products {
		m[p.ID] = true
	}
	return m
}

// ── ranking: fresh, not old ──

// RankedProduct: one product with its score and the plain-language why (Strategy screen, planner input).
type RankedProduct struct {
	ID       string             `json:"id"`
	Title    string             `json:"title"`
	Brand    string             `json:"brand,omitempty"`
	Category string             `json:"category,omitempty"`
	Image    string             `json:"image,omitempty"`
	URL      string             `json:"url,omitempty"`
	PriceAED float64            `json:"price"`
	Score    float64            `json:"score"` // 0..100 (+100 when pinned)
	Parts    map[string]float64 `json:"parts"` // trend, new, momentum, margin, stock, fatigue (each 0..1)
	Why      []string           `json:"why"`
	Pinned   bool               `json:"pinned,omitempty"`
	Trending bool               `json:"trending,omitempty"`
	AgeDays  int                `json:"age_days"` // since the engine first saw it in the feed; -1 = there before the app
	Units14  int                `json:"units_14d"`
	InStock  bool               `json:"in_stock"`
	Seasons  []string           `json:"seasons,omitempty"`       // what it is for (season.go), the owner's word or the rules'
	Yours    bool               `json:"seasons_yours,omitempty"` // the owner set Seasons himself (Strategy → Products)
}

// RankSignals: what the ranking reads besides the products. Trending = shop product ids trending now, best first.
// AdDays = days each product has been in the app's ads (fatigue / age in ads). Baseline = the day the engine first
// recorded the feed: a product first seen after it ARRIVED then; one already there is dated by its shop id (the shop
// numbers products in the order they were added).
type RankSignals struct {
	Now      time.Time
	Trending []string
	AdDays   map[string]int
	Fatigued map[string]bool
	Baseline string
	Boost    map[string]float64 // learned: -1..1 per product from the outcomes ledger (brain/learn.go)
}

func groupNum(id string) (int, bool) {
	if i := strings.IndexByte(id, '-'); i > 0 {
		id = id[:i]
	}
	n, err := strconv.Atoi(id)
	return n, err == nil
}

// Rank orders in-stock products with a photo: freshness (trending + new) weighted by the owner's slider, plus
// recent sales momentum, margin and stock, minus time already spent in ads. Pinned products always come first.
// An old evergreen only climbs on its own sales. Deterministic.
func Rank(prods []Product, pb Playbook, sig RankSignals) []RankedProduct {
	tr := map[string]int{}
	for i, id := range sig.Trending {
		if _, ok := tr[id]; !ok {
			tr[id] = i
		}
	}
	// id percentile among products present at the baseline (newest = 1)
	var nums []int
	for _, p := range prods {
		if n, ok := groupNum(p.ID); ok {
			nums = append(nums, n)
		}
	}
	sort.Ints(nums)
	pctOf := func(id string) float64 {
		n, ok := groupNum(id)
		if !ok || len(nums) < 2 {
			return 0.5
		}
		i := sort.SearchInts(nums, n)
		return float64(i) / float64(len(nums)-1)
	}
	F := pb.FreshnessPct / 100
	var out []RankedProduct
	for _, p := range prods {
		if !p.InStock || p.Image == "" || p.PriceAED <= 0 {
			continue
		}
		rp := RankedProduct{ID: p.ID, Title: p.Title, Brand: p.Brand, Category: p.Category, Image: p.Image, URL: p.URL, PriceAED: p.PriceAED,
			InStock: true, Units14: p.Units14, AgeDays: -1, Parts: map[string]float64{}}
		// trend
		trend := 0.0
		if r, ok := tr[p.ID]; ok {
			trend = math.Max(0.5, 1-float64(r)*0.05)
			rp.Trending = true
			rp.Why = append(rp.Why, fmt.Sprintf("trending now (#%d in the market research)", r+1))
		}
		// newness
		newS := 0.0
		if p.FirstSeen != "" && sig.Baseline != "" && p.FirstSeen > sig.Baseline {
			if t, err := time.Parse("2006-01-02", p.FirstSeen); err == nil {
				rp.AgeDays = int(sig.Now.Sub(t).Hours() / 24)
				newS = math.Exp(-float64(rp.AgeDays) / 45)
				rp.Why = append(rp.Why, fmt.Sprintf("new arrival: in the shop for %d days", rp.AgeDays))
			}
		} else {
			newS = pctOf(p.ID) * 0.8
			if newS >= 0.72 {
				rp.Why = append(rp.Why, "one of the newest 10% of the catalogue")
			} else if newS <= 0.24 {
				rp.Why = append(rp.Why, "an older catalogue item")
			}
		}
		// momentum: recent sales and their direction (shop orders)
		mom := 0.0
		if p.Units14 > 0 {
			vol := math.Min(1, float64(p.Units14)/12)
			grow := float64(p.Units14) / float64(p.Units14+p.UnitsPrev14)
			mom = 0.6*vol + 0.4*grow
			d := ""
			if p.UnitsPrev14 > 0 {
				d = fmt.Sprintf(" (%+.0f%% vs the 2 weeks before)", (float64(p.Units14)/float64(p.UnitsPrev14)-1)*100)
			}
			rp.Why = append(rp.Why, fmt.Sprintf("sold %d in the last 14 days%s", p.Units14, d))
		}
		// margin
		mg := 0.5
		if p.MarginPct != nil {
			mg = clampF(*p.MarginPct/60, 0, 1)
			rp.Why = append(rp.Why, fmt.Sprintf("margin %.0f%%", *p.MarginPct))
		}
		// stock
		st := 0.6
		if p.Stock != nil {
			st = clampF(float64(*p.Stock)/20, 0, 1)
			if *p.Stock < 5 {
				rp.Why = append(rp.Why, fmt.Sprintf("low stock (%d)", *p.Stock))
			}
		}
		// fatigue: time already spent in the app's ads
		fat := 0.0
		if d := sig.AdDays[p.ID]; d > 21 {
			fat = math.Min(1, float64(d-21)/30)
			rp.Why = append(rp.Why, fmt.Sprintf("in ads for %d days already", d))
		}
		if sig.Fatigued[p.ID] {
			fat = math.Max(fat, 0.7)
			rp.Why = append(rp.Why, "its ad is tiring (frequency / CTR)")
		}
		learned := clampF(sig.Boost[p.ID], -1, 1)
		if learned >= 0.2 {
			rp.Why = append(rp.Why, "won in this account's own ads before")
		} else if learned <= -0.2 {
			rp.Why = append(rp.Why, "lost in this account's own ads before")
		}
		score := F*(0.5*trend+0.5*newS) + (1-F)*0.5*mg + 0.35*mom + 0.15*st - 0.3*fat + 0.25*learned
		rp.Parts = map[string]float64{"trend": r3(trend), "new": r3(newS), "momentum": r3(mom), "margin": r3(mg), "stock": r3(st), "fatigue": r3(fat), "learned": r3(learned)}
		rp.Score = math.Round(clampF(score, 0, 2)*1000) / 10
		rp.Seasons, rp.Yours = pb.SeasonsFor(p)
		if pb.Pinned(p) {
			rp.Pinned = true
			rp.Score += 100
			rp.Why = append([]string{"pinned by you: always considered"}, rp.Why...)
		}
		out = append(out, rp)
	}
	sort.SliceStable(out, func(i, j int) bool {
		if out[i].Score != out[j].Score {
			return out[i].Score > out[j].Score
		}
		return out[i].ID > out[j].ID // a tie goes to the newer product
	})
	return out
}

func r3(x float64) float64 { return math.Round(x*1000) / 1000 }

// ── audiences (read from Meta every sync) ──

type Audience struct {
	ID            string `json:"id"`
	Name          string `json:"name"`
	Subtype       string `json:"subtype,omitempty"`
	Lower         int64  `json:"approximate_count_lower_bound"`
	Upper         int64  `json:"approximate_count_upper_bound"`
	DeliveryCode  int    `json:"delivery_code,omitempty"` // 200 = ready to use
	DeliveryText  string `json:"delivery_text,omitempty"`
	RetentionDays int    `json:"retention_days,omitempty"`
}

// OfferFacts: what the copy may say about offers (none when offers are off). Never a % or amount off.
func (p Playbook) OfferFacts() []string {
	if !p.OffersAllowed {
		return nil
	}
	var f []string
	if p.FreeGift {
		f = append(f, "Free gift with every order / هدية مجانية مع كل طلب")
		if len(p.Gifts) > 0 {
			f = append(f, "The gifts we give: "+strings.Join(p.Gifts, ", "))
		}
	}
	if p.CouponCode != "" {
		f = append(f, "Use code "+p.CouponCode+" at checkout / استخدمي الكود "+p.CouponCode)
	}
	if p.PayLater {
		f = append(f, "Pay in 4 with Tabby or Tamara / قسّميها على ٤ دفعات مع تابي أو تمارا")
	}
	return f
}
