package main

import "testing"

// The owner's Approvals, 21 Nov: Dr.Melaxin sells at AED 108 (regular 138) and its occasion ad said "AED 138" /
// "بسعر ١٣٨ درهم". Only a price next to AED / درهم that equals the regular price moves; other numbers stay.
func TestFixTextPrice(t *testing.T) {
	cases := [][2]string{
		{"Authentic Korean skincare, AED 138. Pay in 4 with Tabby.", "Authentic Korean skincare, AED 108. Pay in 4 with Tabby."},
		{"بسعر 138 درهم مع توصيل", "بسعر 108 درهم مع توصيل"},
		{"وبسعر ١٣٨ درهم. قسّميها", "وبسعر ١٠٨ درهم. قسّميها"},
		{"only 138 AED today", "only 108 AED today"},
		{"AED 138.00 delivered", "AED 108 delivered"},
		{"1380 ml, AED 1385, 138 pairs", "1380 ml, AED 1385, 138 pairs"}, // not the price
	}
	for _, c := range cases {
		if got := fixTextPrice(c[0], 138, 108); got != c[1] {
			t.Errorf("%q -> %q, want %q", c[0], got, c[1])
		}
	}
	if fixTextPrice("AED 138", 138, 138) != "AED 138" {
		t.Error("no sale: nothing changes")
	}
}
