package shop

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
)

// The super-sale page lists 32 products and continues on ?page=2 (the live shop, 22 Nov): every page is read.
func TestPageProductsFollowsEveryPage(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("page") == "2" {
			fmt.Fprint(w, `<div data-product_id="9001"></div><div data-product_id="3216"></div>`)
			return
		}
		fmt.Fprint(w, `<div data-product_id="3216"></div><div data-product_id="3965"></div><a rel="next" href="https://kbeautybliss.com/super-sale?page=2" aria-label="Next page">2</a>`)
	}))
	defer srv.Close()
	hc := srv.Client()
	hc.Transport.(*http.Transport).DialTLSContext = nil
	base := srv.Client().Transport.(*http.Transport)
	hc.Transport = rewrite{base, srv.URL}
	ids, err := PageProducts(context.Background(), hc, "https://kbeautybliss.com/super-sale/")
	if err != nil || fmt.Sprint(ids) != "[3216 3965 9001]" {
		t.Fatalf("%v %v", ids, err)
	}
	if _, err := PageProducts(context.Background(), hc, "https://example.com/x"); err == nil {
		t.Fatal("a page outside the shop was read")
	}
}

type rewrite struct {
	rt   *http.Transport
	base string
}

func (r rewrite) RoundTrip(q *http.Request) (*http.Response, error) {
	u := *q.URL
	t, _ := q.URL.Parse(r.base)
	u.Scheme, u.Host = t.Scheme, t.Host
	q2 := q.Clone(q.Context())
	q2.URL = &u
	q2.Host = ""
	return r.rt.RoundTrip(q2)
}
