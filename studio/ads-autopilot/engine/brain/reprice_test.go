package brain

import "testing"

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
