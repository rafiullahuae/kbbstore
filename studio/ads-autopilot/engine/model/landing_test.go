package model

import "testing"

// The owner, 22 Nov: every ad goes to https://kbeautybliss.com/super-sale/, an Arabic ad to the Arabic page
// (the live shop's hreflang="ar": /ar/super-sale/); a page he sets follows the same rule; "product" keeps each ad's
// own product page, in its language.
func TestLandingFollowsTheAdLanguage(t *testing.T) {
	var l AccountLinks
	cases := []struct {
		links           AccountLinks
		lang, own, want string
	}{
		{l, "en", "https://kbeautybliss.com/p/x", "https://kbeautybliss.com/super-sale/"},
		{l, "ar", "https://kbeautybliss.com/p/x", "https://kbeautybliss.com/ar/super-sale/"},
		{AccountLinks{LandingURL: "https://kbeautybliss.com/winter/"}, "ar", "", "https://kbeautybliss.com/ar/winter/"},
		{AccountLinks{LandingURL: "https://kbeautybliss.com/ar/winter/"}, "en", "", "https://kbeautybliss.com/winter/"},
		{AccountLinks{LandingURL: "product"}, "ar", "https://kbeautybliss.com/product/cosrx/", "https://kbeautybliss.com/ar/product/cosrx/"},
		{AccountLinks{LandingURL: "product"}, "en", "https://kbeautybliss.com/product/cosrx/", "https://kbeautybliss.com/product/cosrx/"},
		{l, "mixed", "", "https://kbeautybliss.com/super-sale/"}, // Arabic + English together: the English page
		{AccountLinks{LandingURL: "https://kbeautybliss.com/ar/winter/"}, "", "", "https://kbeautybliss.com/winter/"},
	}
	for _, c := range cases {
		if got := c.links.Landing(c.lang, c.own); got != c.want {
			t.Errorf("%+v %s: %s, want %s", c.links, c.lang, got, c.want)
		}
	}
}
