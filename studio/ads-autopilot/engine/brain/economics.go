package brain

import (
	"fmt"
	"math"
	"sort"
	"time"

	"kaa/model"
)

// EconConfig holds unit-economics assumptions. Every default is a labelled
// assumption until the shop's own data replaces it (orders endpoint, the
// owner's margins in Settings). Defaults are deliberately conservative.
type EconConfig struct {
	DefaultAOV       float64 // AED incl. VAT, used until orders exist and the feed gives nothing
	ItemsPerOrder    float64 // feed median price x this = AOV estimate when orders are unknown
	MarginPct        float64 // gross margin on ex-VAT price, used where a product has none
	VATPct           float64 // UAE 5%; shop prices include it
	ShippingCost     float64 // AED the shop pays per order (net of what the shopper pays)
	PaymentFeePct    float64 // weighted card/Tabby/Tamara/COD fee on the VAT-incl. total
	PaymentFeeFixed  float64 // AED per order
	RefundPct        float64 // expected refunds/returns as share of ex-VAT revenue
	ProfitShare      float64 // share of contribution kept as profit: target CPA = break-even x (1-this)
	PixelIncludesVAT bool    // Meta purchase value includes VAT (the shop's pixel sends the order total)
	// the owner's real fees (20 Nov 2026): by payment family on the order total; free gift per order
	BNPLFeePct, CardFeePct, CODFeePct float64
	GiftCost                          float64
}

// WithInputs: the owner's economics from his Playbook (Strategy → How it decides).
func (c EconConfig) WithInputs(in model.EconInputs) EconConfig {
	c.VATPct, c.BNPLFeePct, c.CardFeePct, c.CODFeePct = in.VATPct/100, in.BNPLFeePct/100, in.CardFeePct/100, in.CODFeePct/100
	c.ShippingCost, c.GiftCost, c.PaymentFeeFixed = in.DeliveryAED, in.GiftCostAED, 0
	return c
}

func DefaultEcon() EconConfig {
	return EconConfig{
		DefaultAOV:      180,
		ItemsPerOrder:   1.6,
		MarginPct:       0.45,
		VATPct:          0.05,
		ShippingCost:    15, // Quiqup per order (the owner)
		PaymentFeePct:   0.035,
		PaymentFeeFixed: 0,
		BNPLFeePct:      0.07, CardFeePct: 0.025, CODFeePct: 0.015,
		RefundPct:        0.03,
		ProfitShare:      0.25,
		PixelIncludesVAT: true,
	}
}

// ComputeEconomics derives break-even and target CPA/ROAS.
//
//	contribution = AOV_exVAT*margin - shipping - fee%*AOV - fixed fee - refund%*AOV_exVAT
//	break-even CPA = contribution;  break-even ROAS = AOV / break-even CPA
//	target CPA = break-even CPA * (1 - profit share) * season tolerance
//
// The season tolerance (>1 in peak windows) loosens the target, never the
// break-even.
func ComputeEconomics(cfg EconConfig, shop ShopSnapshot, now time.Time) Economics {
	e := Economics{AOV: cfg.DefaultAOV, AOVSource: "default", MarginPct: cfg.MarginPct, MarginSource: "default"}
	// AOV from real orders, trailing 60 days, when the shop provides them.
	var rev float64
	var n int
	cut := now.AddDate(0, 0, -60).Format("2006-01-02")
	for _, o := range shop.Orders {
		if o.Date >= cut {
			rev += o.NetRevenue - o.Refunds
			n += o.Orders
		}
	}
	if n >= 10 {
		e.AOV, e.AOVSource = rev/float64(n), "orders"
	} else if med := medianPrice(shop.Products); med > 0 {
		e.AOV, e.AOVSource = med*cfg.ItemsPerOrder, "feed_median"
	}
	// Margin: price-weighted mean of known product margins.
	var mw, w float64
	for _, p := range shop.Products {
		if p.MarginPct > 0 && p.InStock {
			mw += p.MarginPct / 100 * p.PriceAED
			w += p.PriceAED
		}
	}
	if w > 0 {
		e.MarginPct, e.MarginSource = mw/w, "products"
	}
	// fees by payment family on the order total: the shop's real mix (28 days of sale orders), else an assumption
	mix := shop.PaymentMix
	e.MixSource = "orders"
	if len(mix) == 0 {
		mix, e.MixSource = map[string]float64{"card": 0.5, "bnpl": 0.3, "cod": 0.2}, "assumed"
	}
	fee := cfg.PaymentFeePct
	if cfg.BNPLFeePct+cfg.CardFeePct+cfg.CODFeePct > 0 {
		fee = mix["bnpl"]*cfg.BNPLFeePct + mix["card"]*cfg.CardFeePct + mix["cod"]*cfg.CODFeePct + mix["other"]*cfg.CardFeePct
	}
	e.PaymentMix, e.FeePct = mix, round4(fee)
	exVAT := e.AOV / (1 + cfg.VATPct) // VAT comes off first: shop prices include it
	e.ExVAT, e.GrossProfit = round2(exVAT), round2(exVAT*e.MarginPct)
	e.Fees, e.Delivery, e.GiftCost, e.Refunds = round2(fee*e.AOV), cfg.ShippingCost, cfg.GiftCost, round2(cfg.RefundPct*exVAT)
	e.Contribution = exVAT*e.MarginPct - cfg.ShippingCost - cfg.GiftCost - fee*e.AOV - cfg.PaymentFeeFixed - cfg.RefundPct*exVAT
	if shop.Orders28 > 0 {
		e.OrdersPerWeek = round2(float64(shop.Orders28) / 4)
	}
	if e.Contribution < 1 {
		e.Contribution = 1 // a product line that loses money per order still needs a finite target
	}
	e.BreakEvenCPA = round2(e.Contribution)
	value := e.AOV
	if !cfg.PixelIncludesVAT {
		value = exVAT
	}
	e.BreakEvenROAS = round2(value / e.BreakEvenCPA)
	s := SeasonAt(now)
	e.SeasonTolerance, e.Season = 1, ""
	if s != nil {
		e.SeasonTolerance, e.Season = s.CPATolerance, s.Name
	}
	e.TargetCPA = round2(e.BreakEvenCPA * (1 - cfg.ProfitShare) * e.SeasonTolerance)
	if e.TargetCPA > e.BreakEvenCPA {
		e.TargetCPA = e.BreakEvenCPA
	}
	e.TargetROAS = round2(value / e.TargetCPA)
	e.AOV = round2(e.AOV)
	e.Contribution = round2(e.Contribution)
	e.Math = []string{
		fmt.Sprintf("Average order AED %.2f (%s) − VAT %.0f%% = AED %.2f", e.AOV, e.AOVSource, cfg.VATPct*100, e.ExVAT),
		fmt.Sprintf("× margin %.0f%% (%s) = gross profit AED %.2f", e.MarginPct*100, e.MarginSource, e.GrossProfit),
		fmt.Sprintf("− payment fees %.2f%% of AED %.2f (Tabby/Tamara %.1f%%, cards %.1f%%, cash %.1f%%; mix %s) = AED %.2f", e.FeePct*100, e.AOV, cfg.BNPLFeePct*100, cfg.CardFeePct*100, cfg.CODFeePct*100, e.MixSource, e.Fees),
		fmt.Sprintf("− delivery AED %.2f − free gift AED %.2f − refunds %.0f%% AED %.2f", e.Delivery, e.GiftCost, cfg.RefundPct*100, e.Refunds),
		fmt.Sprintf("= contribution AED %.2f per order = break-even cost per order (ROAS %.2f)", e.Contribution, e.BreakEvenROAS),
		fmt.Sprintf("× (1 − profit kept %.0f%%) = target cost per order AED %.2f (ROAS %.2f)", cfg.ProfitShare*100, e.TargetCPA, e.TargetROAS),
	}
	return e
}

func medianPrice(ps []Product) float64 {
	var v []float64
	for _, p := range ps {
		price := p.PriceAED
		if p.SalePrice > 0 && p.SalePrice < price {
			price = p.SalePrice
		}
		if p.InStock && price > 0 {
			v = append(v, price)
		}
	}
	if len(v) == 0 {
		return 0
	}
	sort.Float64s(v)
	if len(v)%2 == 1 {
		return v[len(v)/2]
	}
	return (v[len(v)/2-1] + v[len(v)/2]) / 2
}

func round2(f float64) float64 { return math.Round(f*100) / 100 }

var _ = model.DefaultEconInputs
