package shop

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"kaa/metamock"
)

const testKey = "kbbap_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"

// A stand-in for the shop: the public feed, and the API behind the key.
func fakeShop(t *testing.T, apiCalls *int32) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/feeds/meta-catalog.xml" {
			if r.Header.Get("Authorization") != "" {
				t.Error("the key was sent to the public feed")
			}
			w.Write([]byte(metamock.Feed))
			return
		}
		atomic.AddInt32(apiCalls, 1)
		if r.Header.Get("Authorization") != "Bearer "+testKey {
			w.WriteHeader(401)
			w.Write([]byte(`{"error":"unauthorized"}`))
			return
		}
		switch r.URL.Path {
		case "/autopilot-api/v1/orders/daily":
			if r.URL.Query().Get("from") != "2026-08-23" || r.URL.Query().Get("to") != "2026-11-20" {
				t.Errorf("range %s", r.URL.RawQuery)
			}
			w.Write([]byte(`{"ok":true,"days":[{"date":"2026-11-19","orders":3,"gross":400,"net":350.5,"refunded_on_orders":49.5},{"date":"2026-11-20","orders":0,"net":0,"refunded_on_orders":0}]}`))
		case "/autopilot-api/v1/products":
			if r.URL.Query().Get("after") == "0" {
				w.Write([]byte(`{"ok":true,"products":[{"id":"3195","margin_pct":41.5,"stock":42,"variants":[]}],"next_after":3195}`))
				return
			}
			w.Write([]byte(`{"ok":true,"products":[{"id":"9999","margin_pct":null,"variants":[]}],"next_after":null}`))
		case "/autopilot-api/v1/orders":
			if r.URL.Query().Get("from") != "2026-10-24" {
				t.Errorf("orders range %s", r.URL.RawQuery)
			}
			if r.URL.Query().Get("after") == "0" {
				w.Write([]byte(`{"ok":true,"orders":[{"day":"2026-11-18","is_sale":true,"items":[{"product_id":"3195","qty":2},{"product_id":null,"qty":1}]},{"day":"2026-11-01","is_sale":true,"items":[{"product_id":"3195","qty":1}]}],"next_after":7}`))
				return
			}
			w.Write([]byte(`{"ok":true,"orders":[{"day":"2026-11-19","is_sale":false,"items":[{"product_id":"3195","qty":9}]},{"day":"2026-11-20","is_sale":true,"items":[{"product_id":"4410","qty":3}]}],"next_after":null}`))
		default:
			w.WriteHeader(404)
		}
	}))
}

func testSource(srv *httptest.Server, key string) *APISource {
	s := New(srv.URL+"/feeds/meta-catalog.xml", func() string { return key })
	s.Base, s.anyBase = srv.URL+"/autopilot-api/v1", true
	s.Now = func() time.Time { return time.Date(2026, 11, 20, 8, 0, 0, 0, time.UTC) }
	return s
}

func TestAPISourceWithoutKeyIsTheFeed(t *testing.T) {
	var calls int32
	srv := fakeShop(t, &calls)
	defer srv.Close()
	s := testSource(srv, "")
	snap, err := s.Snapshot(context.Background())
	if err != nil || s.OrdersSource() != "none" || snap.OrdersSource != "none" || len(snap.Products) != 8 || calls != 0 {
		t.Fatalf("err %v, source %q/%q, %d products, %d api calls", err, s.OrdersSource(), snap.OrdersSource, len(snap.Products), calls)
	}
}

func TestAPISourceReadsNetRevenueAndMargins(t *testing.T) {
	var calls int32
	srv := fakeShop(t, &calls)
	defer srv.Close()
	s := testSource(srv, testKey)
	snap, err := s.Snapshot(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if s.OrdersSource() != "shop-api" || snap.OrdersSource != "shop-api" {
		t.Fatalf("source %q", snap.OrdersSource)
	}
	if len(snap.Orders) != 2 || snap.Orders[0].NetRevenueAED != 350.5 || snap.Orders[0].RefundsAED != 49.5 || snap.Orders[0].Orders != 3 {
		t.Fatalf("orders %+v", snap.Orders)
	}
	if snap.Products[0].ID != "3195" || snap.Products[0].MarginPct == nil || *snap.Products[0].MarginPct != 41.5 {
		t.Fatalf("margin %+v", snap.Products[0])
	}
	if snap.Products[1].MarginPct != nil {
		t.Fatal("a product the shop has no cost for got a margin")
	}
	// units sold: sale orders only (the cancelled one with 9 is not), split into the last 14 days and the 14 before;
	// stock from the API. MUTATION: drop the IsSale check → 3195 sells 11, red.
	if p := snap.Products[0]; p.Units14 != 2 || p.UnitsPrev14 != 1 || p.Stock == nil || *p.Stock != 42 || snap.Products[1].Units14 != 3 {
		t.Fatalf("momentum / stock: %+v %+v", snap.Products[0], snap.Products[1])
	}
	// daily + two product pages + two order pages (each cursor followed)
	if calls != 5 {
		t.Fatalf("%d api calls", calls)
	}
}

func TestAPISourceRefusedKeyKeepsProductsAndSaysWhy(t *testing.T) {
	var calls int32
	srv := fakeShop(t, &calls)
	defer srv.Close()
	s := testSource(srv, "kbbap_wrong")
	snap, err := s.Snapshot(context.Background())
	if !errors.Is(err, ErrUnauthorized) || snap.OrdersSource != "none" || len(snap.Products) != 8 || len(snap.Orders) != 0 {
		t.Fatalf("err %v, source %q, %d products", err, snap.OrdersSource, len(snap.Products))
	}
}

func TestAPISourceSendsTheKeyOnlyToTheShop(t *testing.T) {
	var calls int32
	srv := fakeShop(t, &calls)
	defer srv.Close()
	s := testSource(srv, testKey)
	s.anyBase = false // the real rule: only https://kbeautybliss.com/
	_, err := s.Snapshot(context.Background())
	if err == nil || !strings.Contains(err.Error(), "refusing") || calls != 0 {
		t.Fatalf("err %v, %d api calls", err, calls)
	}
	if New("", nil).Base != DefaultAPI || !strings.HasPrefix(DefaultAPI, apiPrefix) {
		t.Fatal("default API address")
	}
}
