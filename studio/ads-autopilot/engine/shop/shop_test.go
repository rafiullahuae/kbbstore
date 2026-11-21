package shop

import (
	"strings"
	"testing"

	"kaa/metamock"
)

func TestParseFeed(t *testing.T) {
	s, err := Parse(strings.NewReader(metamock.Feed))
	if err != nil {
		t.Fatal(err)
	}
	if len(s.Products) != 16 || s.OrdersSource != "none" {
		t.Fatalf("%d products, orders %q", len(s.Products), s.OrdersSource)
	}
	p := s.Products[0]
	if p.ID != "3195" || p.PriceAED != 70 || p.SaleAED != 59 || !p.InStock || p.Brand != "COSRX" {
		t.Fatalf("%+v", p)
	}
	if s.Products[2].InStock {
		t.Fatal("out_of_stock read as in stock")
	}
	// what each product is, from the title, category AND the feed description (the Arabic line of 8130 says rich +
	// dry skin). MUTATION: drop the description from ClassifyProduct → Illiyoon loses "hydration", red.
	tags := map[string]string{}
	for _, p := range s.Products {
		tags[p.ID] = strings.Join(p.Tags, ",")
	}
	for id, want := range map[string][]string{"8130": {"barrier", "cream", "rich", "hydration"}, "8170": {"spf", "light", "hydration"}, "8144": {"hydration", "body", "barrier"}, "8150": {"lip", "mask", "hydration"}, "8101": {"eye", "cream", "brightening"}} {
		for _, w := range want {
			if !strings.Contains(","+tags[id]+",", ","+w+",") {
				t.Errorf("%s: tags %q, want %s", id, tags[id], w)
			}
		}
	}
}

func TestParsePrice(t *testing.T) {
	for in, want := range map[string]float64{"70.00 AED": 70, "59 AED": 59, "12.5": 12.5, "10.00 USD": 0, "": 0, "abc AED": 0, "-3 AED": 0} {
		if got := ParsePrice(in); got != want {
			t.Errorf("ParsePrice(%q) = %v want %v", in, got, want)
		}
	}
}
