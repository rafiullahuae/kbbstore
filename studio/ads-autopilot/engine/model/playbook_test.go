package model

import (
	"testing"
	"time"
)

func ip(n int) *int { return &n }

// Fresh, not old: with the default freshness, a new arrival and a trending product rank above an old evergreen that
// does not sell; the same evergreen climbs when its own sales win; a pin is always first. MUTATION: drop the 0.35×
// momentum term in Rank → the selling evergreen stays at the bottom, red.
func TestRankFreshFirstButDataWins(t *testing.T) {
	now := time.Date(2026, 11, 20, 0, 0, 0, 0, time.UTC)
	ps := []Product{
		{ID: "1000", Title: "COSRX Snail Mucin Essence", PriceAED: 89, InStock: true, Image: "x", FirstSeen: "2026-01-01"},
		{ID: "8000", Title: "Medicube PDRN Serum", PriceAED: 129, InStock: true, Image: "x", FirstSeen: "2026-11-10"},
		{ID: "5000", Title: "Anua Toner", PriceAED: 99, InStock: true, Image: "x", FirstSeen: "2026-01-01"},
		{ID: "3000", Title: "Round Lab Cleanser", PriceAED: 59, InStock: true, Image: "x", FirstSeen: "2026-01-01"},
	}
	pb := DefaultPlaybook().Normalize()
	r := Rank(ps, pb, RankSignals{Now: now, Trending: []string{"5000"}, Baseline: "2026-01-01"})
	if r[len(r)-1].ID != "1000" || (r[0].ID != "8000" && r[0].ID != "5000") || r[0].AgeDays < 0 && r[0].ID == "8000" {
		t.Fatalf("fresh-first: %v", order(r))
	}
	ps[0].Units14, ps[0].UnitsPrev14 = 30, 10 // the evergreen sells: it earns its slot on data
	r = Rank(ps, pb, RankSignals{Now: now, Trending: []string{"5000"}, Baseline: "2026-01-01"})
	if pos(r, "1000") > 1 {
		t.Fatalf("a winning evergreen stayed down: %v", order(r))
	}
	pb.Focus = []PBRule{{Kind: "product", Value: "3000"}}
	if r = Rank(ps, pb, RankSignals{Now: now, Baseline: "2026-01-01"}); r[0].ID != "3000" || !r[0].Pinned {
		t.Fatalf("pin not first: %v", order(r))
	}
}

func order(r []RankedProduct) []string {
	var o []string
	for _, x := range r {
		o = append(o, x.ID)
	}
	return o
}
func pos(r []RankedProduct, id string) int {
	for i, x := range r {
		if x.ID == id {
			return i
		}
	}
	return -1
}

// Avoid wins over a pin; floors apply unless pinned; unknown stock or margin passes. MUTATION: check Pinned before
// Avoid in Filter → an avoided pin passes, red.
func TestFilter(t *testing.T) {
	m := 10.0
	s := ShopSnapshot{Products: []Product{
		{ID: "1", Title: "COSRX Advanced Snail 96 Mucin Essence", Brand: "COSRX"},
		{ID: "2", Title: "Low stock serum", Stock: ip(1)},
		{ID: "3", Title: "Thin margin cream", MarginPct: &m},
		{ID: "4", Title: "Unknown stock toner"},
		{ID: "5", Title: "Pinned low stock", Stock: ip(1)},
	}}
	pb := DefaultPlaybook()
	pb.Avoid = []PBRule{{Kind: "keyword", Value: "snail mucin"}}
	pb.Focus = []PBRule{{Kind: "product", Value: "1"}, {Kind: "product", Value: "5"}}
	pb.MinStock, pb.MinMarginPct = 3, 20
	f, ex := pb.Filter(s)
	got := map[string]bool{}
	for _, p := range f.Products {
		got[p.ID] = true
	}
	if got["1"] || got["2"] || got["3"] || !got["4"] || !got["5"] || len(ex) != 3 {
		t.Fatalf("kept %v, excluded %v", got, ex)
	}
}

// Hijri dates resolve (estimated): Ramadan 1448 starts around 8 Feb 2027 and Eid al-Fitr around 10 Mar 2027; Black
// Friday 2026 is 27 Nov. MUTATION: drop the (3+11y)/30 leap term → Ramadan is off by days, red.
func TestOccasionDates(t *testing.T) {
	near := func(got time.Time, y, m, d, tol int) bool {
		want := time.Date(y, time.Month(m), d, 0, 0, 0, 0, time.UTC)
		diff := got.Sub(want).Hours() / 24
		return diff >= -float64(tol) && diff <= float64(tol)
	}
	if r := HijriToGregorian(1448, 9, 1); !near(r, 2027, 2, 8, 2) {
		t.Fatalf("Ramadan 1448 → %s", r.Format("2006-01-02"))
	}
	if e := HijriToGregorian(1448, 10, 1); !near(e, 2027, 3, 10, 2) {
		t.Fatalf("Eid al-Fitr 1448 → %s", e.Format("2006-01-02"))
	}
	if bf := blackFriday(2026); bf.Format("2006-01-02") != "2026-11-27" {
		t.Fatalf("Black Friday 2026 → %s", bf.Format("2006-01-02"))
	}
	pb := DefaultPlaybook().Normalize()
	up := pb.UpcomingOccasions(time.Date(2026, 11, 12, 0, 0, 0, 0, time.UTC), 40, []string{"AE"})
	found, saudi := false, false
	for _, i := range up {
		found = found || i.Occasion == "national-day" && i.Start == "2026-12-02" && i.PrepFrom == "2026-11-18"
		saudi = saudi || i.Occasion == "saudi-national-day"
	}
	if !found || saudi {
		t.Fatalf("upcoming for AE: %+v", up)
	}
}

// Offers: free gift + the coupon code + pay in 4 (Tabby / Tamara) by default; never a % (the linter enforces it); off = nothing.
func TestOfferFacts(t *testing.T) {
	pb := DefaultPlaybook().Normalize()
	if f := pb.OfferFacts(); len(f) != 3 || pb.CouponCode != "GLOW" {
		t.Fatalf("%v", f)
	}
	pb.CouponCode = "glow 20%!"
	if pb = pb.Normalize(); pb.CouponCode != "GLOW20" {
		t.Fatalf("code %q", pb.CouponCode)
	}
	pb.OffersAllowed = false
	if len(pb.OfferFacts()) != 0 {
		t.Fatal("offers off still lists offers")
	}
}
