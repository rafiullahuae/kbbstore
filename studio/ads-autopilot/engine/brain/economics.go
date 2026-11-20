package brain

import (
	"math"
	"sort"
	"time"
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
}

func DefaultEcon() EconConfig {
	return EconConfig{
		DefaultAOV:       180,
		ItemsPerOrder:    1.6,
		MarginPct:        0.45,
		VATPct:           0.05,
		ShippingCost:     12,
		PaymentFeePct:    0.035,
		PaymentFeeFixed:  1,
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
	exVAT := e.AOV / (1 + cfg.VATPct)
	e.Contribution = exVAT*e.MarginPct - cfg.ShippingCost - cfg.PaymentFeePct*e.AOV - cfg.PaymentFeeFixed - cfg.RefundPct*exVAT
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
