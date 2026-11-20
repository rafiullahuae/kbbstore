package brain

import (
	"math"
	"testing"
	"time"

	"kaa/model"
)

// The owner's unit economics (20 Nov 2026): VAT off first, then the fee by payment family on the order total
// (Tabby/Tamara 7%, cards 2.5%, cash 1.5%), Quiqup AED 15, the gift cost. AOV 210 incl. VAT, margin 50%, mix 50% BNPL /
// 50% card: ex-VAT 200, gross 100, fees 4.75% of 210 = 9.975, delivery 15, refunds 3% of 200 = 6 → contribution 69.03.
// MUTATION: take the fee on the ex-VAT revenue → 68.5, red.
func TestEconomicsOwnerMath(t *testing.T) {
	m := 50.0
	var orders []OrderDay
	for i := 0; i < 20; i++ {
		orders = append(orders, OrderDay{Date: "2026-11-1" + string(rune('0'+i%9)), Orders: 1, NetRevenue: 210})
	}
	shop := ShopSnapshot{Orders: orders, PaymentMix: map[string]float64{"bnpl": 0.5, "card": 0.5}, Orders28: 400,
		Products: []Product{{ID: "1", PriceAED: 100, InStock: true, MarginPct: m}}}
	e := ComputeEconomics(DefaultEcon().WithInputs(model.DefaultEconInputs()), shop, time.Date(2026, 11, 20, 0, 0, 0, 0, time.UTC))
	if math.Abs(e.Contribution-69.03) > 0.02 || e.MixSource != "orders" || e.OrdersPerWeek != 100 || len(e.Math) != 6 {
		t.Fatalf("contribution %.2f (want 69.03), mix %s, orders/week %.0f, %d math lines", e.Contribution, e.MixSource, e.OrdersPerWeek, len(e.Math))
	}
	in := model.DefaultEconInputs()
	in.GiftCostAED = 10
	if g := ComputeEconomics(DefaultEcon().WithInputs(in), shop, time.Date(2026, 11, 20, 0, 0, 0, 0, time.UTC)); math.Abs(g.Contribution-59.03) > 0.02 {
		t.Fatalf("a AED 10 gift: contribution %.2f, want 59.03", g.Contribution)
	}
}
