package brain

import (
	"testing"

	"kaa/model"
)

// The owner, 21 Nov: COSRX is AED 70 with a sale price of AED 59 and the ads printed AED 70. Change Selling back to
// the regular price (or PriceLabel to %.0f of PriceAED) and this is red.
func TestSellingPriceIsTheSalePrice(t *testing.T) {
	p := Product{PriceAED: 70, SalePrice: 59}
	if p.Selling() != 59 {
		t.Fatalf("selling = %v, want the sale price 59", p.Selling())
	}
	if (Product{PriceAED: 70}).Selling() != 70 || (Product{PriceAED: 70, SalePrice: 80}).Selling() != 70 {
		t.Fatal("no sale (or a sale above the price) must keep the regular price")
	}
	if PriceLabel("en", 59) != "AED 59" || PriceLabel("ar", 59) != "59 درهم" || PriceLabel("en", 49.5) != "AED 49.50" {
		t.Fatalf("labels: %q %q %q", PriceLabel("en", 59), PriceLabel("ar", 59), PriceLabel("en", 49.5))
	}
	d := designFor(CopySet{ID: "x", Lang: "en", ProductIDs: []string{"1"}}, copyExtra{}, []Product{{ID: "1", PriceAED: 70, SalePrice: 59, InStock: true, Image: "https://kbeautybliss.com/a.jpg"}}, "hero")
	if d.Price != "AED 59" {
		t.Fatalf("design price %q, want AED 59", d.Price)
	}
}

// fixPrice changes the price chip and nothing else; a design that shows no price stays without one.
func TestFixPriceOnlyThePrice(t *testing.T) {
	sell := map[string]float64{"1": 59}
	d := DesignSpec{Lang: "ar", ProductIDs: []string{"1"}, Price: "70 درهم", Headline: "h"}
	if old, ok := fixPrice(&d, sell); !ok || old != "70 درهم" || d.Price != "59 درهم" || d.Headline != "h" {
		t.Fatalf("got %q %v %+v", old, ok, d)
	}
	if _, ok := fixPrice(&d, sell); ok {
		t.Fatal("a second pass must find nothing")
	}
	n := DesignSpec{Lang: "en", ProductIDs: []string{"1"}}
	if _, ok := fixPrice(&n, sell); ok || n.Price != "" {
		t.Fatal("a design without a price must stay without one")
	}
}

// The owner, 21 Nov, after 0.6.1: "i pressed the correct prices button, but still i can see the wrong prices".
// A picture is listed by what it SHOWS against today's price, whether or not its design changed in this call:
//   - recorded price (Creative.Price) different from today's   -> redrawn
//   - from before prices were recorded, product on sale now     -> redrawn (the old designer printed the regular price)
//   - redrawn by 0.6.1 / recorded and right                     -> left alone
//   - a one-photo routine (the Anua set) not yet drawn once      -> redrawn; a 3-product routine is not
//
// Make printedWrong compare against the design's change in this call (0.6.1) and the first two cases are red.
func TestStaleByWhatThePictureShows(t *testing.T) {
	pr := shopPrices{sell: map[string]float64{"1": 49}, regular: map[string]float64{"1": 70}}
	d := DesignSpec{Lang: "ar", ProductIDs: []string{"1"}, Price: PriceLabel("ar", 49)} // already corrected earlier
	cases := []struct {
		name  string
		c     model.Creative
		wrong bool
	}{
		{"recorded old price", model.Creative{Price: "70 درهم"}, true},
		{"legacy, product on sale", model.Creative{}, true},
		{"redrawn by 0.6.1", model.Creative{RepricedAt: "2026-11-21T10:00:00Z"}, false},
		{"recorded and right", model.Creative{Price: "49 درهم"}, false},
	}
	for _, k := range cases {
		if _, w := printedWrong(k.c, d, pr); w != k.wrong {
			t.Errorf("%s: wrong=%v, want %v", k.name, w, k.wrong)
		}
	}
	if _, w := printedWrong(model.Creative{}, DesignSpec{Lang: "en", ProductIDs: []string{"2"}, Price: "AED 30"}, shopPrices{regular: map[string]float64{"2": 30}}); w {
		t.Error("legacy, not on sale: it already shows the right price")
	}
	if _, w := printedWrong(model.Creative{Price: PriceNone}, DesignSpec{ProductIDs: []string{"1"}}, pr); w {
		t.Error("no price on the design and none on the picture")
	}
	if !repeats(DesignSpec{Template: "routine", Images: []string{"a.jpg"}}) || repeats(DesignSpec{Template: "routine", Images: []string{"a", "b", "c"}}) || !repeats(DesignSpec{Template: "collection", Images: []string{"a", "a"}}) {
		t.Error("repeats: a one-photo routine or a photo listed twice repeats; three different products do not")
	}
}

// A saved box picture names its design in its id; a headline edited after saving still finds it.
func TestMatchByDesignIDTail(t *testing.T) {
	ds := []DesignSpec{{ID: "c-s18ea1-1-ar", Lang: "ar", Headline: "new words"}, {ID: "c-s18ea1-2-ar", Lang: "ar", Headline: "other"}}
	c := model.Creative{ID: "cc-0a1b2c3d" + alnumTail(ds[0].ID, 6) + "-4x5", Lang: "ar", Headline: "old words", Format: "4:5", Media: "png"}
	if i := matchDesign(c, ds, false); i != 0 {
		t.Fatalf("matched %d, want 0", i)
	}
}
