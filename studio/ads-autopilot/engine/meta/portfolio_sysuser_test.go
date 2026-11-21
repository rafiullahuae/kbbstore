package meta

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// The owner, 21 Nov: "no business in the portfolio owns this ad account ... but i can't see any pages". His token is a
// SYSTEM USER's: me/businesses gives a system user nothing, so every account had no business and no Page or pixel to
// pick. Discovery now falls back to the account's own `business` field and the assets the account can use.
// MUTATION: delete the fallback block in Portfolio() and this test fails (no business, no page, no pixel).
func TestPortfolioForASystemUserToken(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		p := r.URL.Path
		w.Header().Set("Content-Type", "application/json")
		switch {
		case strings.HasSuffix(p, "/me/businesses"):
			_, _ = w.Write([]byte(`{"data":[]}`))
		case strings.HasSuffix(p, "/me/adaccounts"):
			_, _ = w.Write([]byte(`{"data":[{"id":"act_1","name":"KBB23","currency":"AED","account_status":1}]}`))
		case strings.HasSuffix(p, "/me/accounts"):
			_, _ = w.Write([]byte(`{"data":[{"id":"pg_assigned","name":"Assigned Page"}]}`))
		case strings.HasSuffix(p, "/act_1") && strings.Contains(r.URL.RawQuery, "business"):
			_, _ = w.Write([]byte(`{"id":"act_1","business":{"id":"biz_9","name":"Imagine Design Center"}}`))
		case strings.HasSuffix(p, "/biz_9/owned_pages"), strings.HasSuffix(p, "/biz_9/owned_instagram_accounts"),
			strings.HasSuffix(p, "/biz_9/owned_product_catalogs"):
			_, _ = w.Write([]byte(`{"data":[]}`))
		case strings.HasSuffix(p, "/biz_9/owned_pixels"):
			w.WriteHeader(403)
			_, _ = w.Write([]byte(`{"error":{"message":"(#200) Permissions error","code":200}}`))
		case strings.HasSuffix(p, "/act_1/promote_pages"):
			_, _ = w.Write([]byte(`{"data":[{"id":"pg_1","name":"K-Beauty Bliss"}]}`))
		case strings.HasSuffix(p, "/act_1/adspixels"):
			_, _ = w.Write([]byte(`{"data":[{"id":"px_1","name":"KBB Pixel"}]}`))
		case strings.HasSuffix(p, "/act_1/instagram_accounts"):
			_, _ = w.Write([]byte(`{"data":[{"id":"ig_1","username":"kbeautybliss"}]}`))
		default:
			_, _ = w.Write([]byte(`{"data":[]}`))
		}
	}))
	defer srv.Close()
	p, err := client(srv.URL).Portfolio(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(p.AdAccounts) != 1 || p.AdAccounts[0].BusinessID != "biz_9" {
		t.Fatalf("account not tied to its business: %+v", p.AdAccounts)
	}
	if len(p.Businesses) != 1 {
		t.Fatalf("businesses: %+v", p.Businesses)
	}
	b := p.Businesses[0]
	has := func(xs []string, id string) bool {
		for _, x := range xs {
			if x == id {
				return true
			}
		}
		return false
	}
	var pages, pixels []string
	for _, x := range b.Pages {
		pages = append(pages, x.ID)
	}
	for _, x := range b.Pixels {
		pixels = append(pixels, x.ID)
	}
	if !has(b.AdAccounts, "act_1") || !has(pages, "pg_1") || !has(pages, "pg_assigned") || !has(pixels, "px_1") || len(b.Instagram) != 1 {
		t.Fatalf("assets missing: pages %v pixels %v ig %v", pages, pixels, b.Instagram)
	}
	if !strings.Contains(b.Error, "owned_pixels") {
		t.Fatalf("a refused edge must be noted, got %q", b.Error)
	}
}
