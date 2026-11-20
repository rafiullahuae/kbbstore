package brain

import (
	"math"
	"testing"
	"time"
)

func near(t *testing.T, name string, got, want, tol float64) {
	t.Helper()
	if math.Abs(got-want) > tol {
		t.Fatalf("%s = %.6f, want %.6f (±%g)", name, got, want, tol)
	}
}

// The incomplete gamma against closed forms: P(1,x)=1-e^-x, P(2,x)=1-e^-x(1+x).
// Mutation: drop the "1 -" in gammaP's continued-fraction branch -> red.
func TestGammaPClosedForms(t *testing.T) {
	for _, x := range []float64{0.1, 0.5, 1, 2, 3, 5, 9} {
		near(t, "P(1,x)", gammaP(1, x), 1-math.Exp(-x), 1e-10)
		near(t, "P(2,x)", gammaP(2, x), 1-math.Exp(-x)*(1+x), 1e-10)
	}
	// P(a,x) == P(Poisson(x) >= a) for integer a: P(5,3) = 1 - sum_{k<5} e^-3 3^k/k!
	sum := 0.0
	f := 1.0
	for k := 0; k < 5; k++ {
		if k > 0 {
			f *= float64(k)
		}
		sum += math.Exp(-3) * math.Pow(3, float64(k)) / f
	}
	near(t, "P(5,3)", gammaP(5, 3), 1-sum, 1e-10)
}

// I_x(1,1)=x; I_x(a,1)=x^a; symmetric I_0.5(a,a)=0.5.
// Mutation: swap a and b in betacf's call for the x >= (a+1)/(a+b+2) branch -> red.
func TestBetaI(t *testing.T) {
	for _, x := range []float64{0.05, 0.3, 0.7, 0.95} {
		near(t, "I(1,1)", betaI(x, 1, 1), x, 1e-10)
		near(t, "I(3,1)", betaI(x, 3, 1), math.Pow(x, 3), 1e-10)
	}
	near(t, "I_0.5(7,7)", betaI(0.5, 7, 7), 0.5, 1e-10)
}

// The decision thresholds, as the owner would read them (target CPA T):
//   - 0 purchases after 1x T: P(CPA<T) = e^-3*4 = 0.199 -- exactly the kill line
//   - 0 purchases after 2x T: 5e^-4 = 0.092 -- kill
//   - 1 purchase after 2x T: 0.238 -- not yet
//
// Mutation: change PriorWeight to 1 -> 0 at 1x becomes e^-2=0.135 and the
// first assertion is red (the rule would kill after spending one CPA).
func TestCPAPosteriorThresholds(t *testing.T) {
	T := 50.0
	near(t, "0@1x", NewCPAPosterior(0, T, T).PBelow(T), 4*math.Exp(-3), 1e-9)
	near(t, "0@2x", NewCPAPosterior(0, 2*T, T).PBelow(T), 5*math.Exp(-4), 1e-9)
	near(t, "1@2x", NewCPAPosterior(1, 2*T, T).PBelow(T), math.Exp(-4)*(1+4+8), 1e-9)
	// a clear winner: 12 purchases at CPA 0.6T over 7.2T spend
	if p := NewCPAPosterior(12, 7.2*T, T).PBelow(T); p < 0.9 {
		t.Fatalf("winner P = %.3f, want > 0.9", p)
	}
	near(t, "mean", NewCPAPosterior(8, 400, 50).MeanCPA(), (2*50+400)/9.0, 1e-9)
}

// CTR posterior: 10 clicks in 3000 impressions (0.33%) vs a 1.2% account CTR:
// P(CTR < 0.72%) must be ~1; 40 clicks (1.33%) must be ~0.
func TestRatePosterior(t *testing.T) {
	if p := PRateBelow(10, 3000, 0.0072); p < 0.99 {
		t.Fatalf("weak CTR P=%.4f", p)
	}
	if p := PRateBelow(40, 3000, 0.0072); p > 0.01 {
		t.Fatalf("good CTR P=%.4f", p)
	}
}

func TestEconomics(t *testing.T) {
	now := time.Date(2026, 11, 20, 9, 0, 0, 0, time.UTC)
	shop := ShopSnapshot{Products: []Product{{ID: "1", PriceAED: 100, InStock: true}, {ID: "2", PriceAED: 60, InStock: true}, {ID: "3", PriceAED: 80, InStock: true}}}
	e := ComputeEconomics(DefaultEcon(), shop, now)
	// AOV = median 80 x 1.6 = 128; exVAT 121.90; contribution = 121.90*.45 - 12 - .035*128 - 1 - .03*121.90 = 33.70
	near(t, "AOV", e.AOV, 128, 0.01)
	if e.AOVSource != "feed_median" {
		t.Fatal(e.AOVSource)
	}
	near(t, "BE CPA", e.BreakEvenCPA, 33.70, 0.02)
	near(t, "BE ROAS", e.BreakEvenROAS, 128/33.70, 0.02)
	// 20 Nov 2026 is inside White Friday (tolerance 1.15): target = 33.70*.75*1.15
	if e.Season != "White Friday" {
		t.Fatalf("season %q", e.Season)
	}
	near(t, "target CPA", e.TargetCPA, 33.70*0.75*1.15, 0.02)
	// Orders replace the feed estimate once there are 10+.
	shop.Orders = []OrderDay{{Date: "2026-11-10", Orders: 12, NetRevenue: 2400}}
	if e := ComputeEconomics(DefaultEcon(), shop, now); e.AOVSource != "orders" || e.AOV != 200 {
		t.Fatalf("orders AOV %v %v", e.AOV, e.AOVSource)
	}
}

func TestSeasons(t *testing.T) {
	d := func(s string) time.Time { x, _ := time.Parse("2006-01-02", s); return x }
	if s := SeasonAt(d("2026-12-02")); s == nil || s.Name != "White Friday" && s.Name != "UAE National Day" {
		t.Fatalf("2 Dec: %+v", s)
	}
	if s := SeasonAt(d("2027-02-20")); s == nil || s.Name != "Ramadan" {
		t.Fatalf("Ramadan: %+v", s)
	}
	if s := SeasonAt(d("2027-04-15")); s != nil {
		t.Fatalf("quiet April has no season, got %s", s.Name)
	}
	up := Upcoming(d("2027-01-20"), 30)
	if len(up) == 0 || up[0].Name != "Pre-Ramadan stock-up" || up[0].PrepFrom != "2027-01-15" {
		t.Fatalf("upcoming %+v", up)
	}
	if !IsPayday(d("2026-11-27")) || IsPayday(d("2026-11-15")) {
		t.Fatal("payday window")
	}
}
