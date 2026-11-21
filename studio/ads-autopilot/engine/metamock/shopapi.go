package metamock

// shopapi.go — a fake of the website's read-only shop API (/autopilot-api/v1/*) for the checks, so -mock has units
// sold per product (the occasion slots rank on them). Opt-in (KAA_MOCK_SHOP_API=1 with -mock): the other checks keep
// the feed-only shop they were written against.

import (
	"encoding/json"
	"net/http"
	"strconv"
	"strings"
	"time"
)

const ShopKey = "kbbshop-mock-0000000000"

// MockSales: units sold per product id over the last 90 days (the mock's best sellers).
var MockSales = map[string]int{"8130": 36, "8170": 30, "8150": 20, "2210": 14, "3195": 12, "4410": 9, "8101": 5, "8144": 4, "7301": 3}

var mockShopIDs = []string{"3195", "4410", "2210", "6120", "7301", "8015", "8022", "8101", "8130", "8144", "8150", "8162", "8170", "8178", "8185"}

func (a *Aux) shopAPI(w http.ResponseWriter, r *http.Request) bool {
	if !strings.HasPrefix(r.URL.Path, "/autopilot-api/v1/") {
		return false
	}
	w.Header().Set("Content-Type", "application/json")
	if r.Header.Get("Authorization") != "Bearer "+ShopKey {
		w.WriteHeader(401)
		_, _ = w.Write([]byte(`{"error":"unauthorized"}`))
		return true
	}
	now := time.Now()
	if a.Now != nil {
		now = a.Now()
	}
	today := now.In(time.FixedZone("GST", 4*3600))
	day := func(back int) string { return today.AddDate(0, 0, -back).Format("2006-01-02") }
	enc := json.NewEncoder(w)
	switch strings.TrimPrefix(r.URL.Path, "/autopilot-api/v1") {
	case "/orders/daily":
		var days []map[string]any
		for i := 89; i >= 0; i-- {
			days = append(days, map[string]any{"date": day(i), "orders": 12, "net": 1800.0, "refunded_on_orders": 0})
		}
		_ = enc.Encode(map[string]any{"ok": true, "days": days})
	case "/products":
		var ps []map[string]any
		for _, id := range mockShopIDs {
			ps = append(ps, map[string]any{"id": id, "margin_pct": 45.0, "stock": 40, "variants": []any{}})
		}
		_ = enc.Encode(map[string]any{"ok": true, "products": ps, "next_after": nil})
	case "/orders":
		var os []map[string]any
		if after, _ := strconv.Atoi(r.URL.Query().Get("after")); after == 0 {
			for _, id := range mockShopIDs { // one order per unit, spread over the 90 days
				u := MockSales[id]
				for k := 0; k < u; k++ {
					os = append(os, map[string]any{"day": day(k * 89 / u), "is_sale": true, "payment_family": "card",
						"items": []map[string]any{{"product_id": id, "qty": 1}}})
				}
			}
		}
		_ = enc.Encode(map[string]any{"ok": true, "orders": os, "next_after": nil})
	default:
		w.WriteHeader(404)
		_, _ = w.Write([]byte(`{"error":"not found"}`))
	}
	return true
}
