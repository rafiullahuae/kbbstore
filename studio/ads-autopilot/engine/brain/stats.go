package brain

// Bayesian statistics for kill / keep / scale. Pure math, no I/O, so the
// thresholds can be tested exactly. The LLM never computes any of this.
//
// CPA: purchases ~ Poisson(lambda * spend), lambda = purchases per AED = 1/CPA.
// Prior lambda ~ Gamma(shape=a0, rate=a0*priorCPA) (mean 1/priorCPA, weight a0
// pseudo-purchases). Posterior Gamma(a0+k, a0*priorCPA + spend).
// P(CPA < T) = P(lambda > 1/T) = 1 - GammaCDF(1/T).
//
// CTR / CVR: Beta(1+successes, 1+failures) (uniform prior).

import "math"

// gammaP is the regularized lower incomplete gamma P(a, x).
func gammaP(a, x float64) float64 {
	if x <= 0 || a <= 0 {
		return 0
	}
	if x < a+1 {
		// series
		sum, del, ap := 1/a, 1/a, a
		for n := 0; n < 500; n++ {
			ap++
			del *= x / ap
			sum += del
			if math.Abs(del) < math.Abs(sum)*1e-14 {
				break
			}
		}
		lg, _ := math.Lgamma(a)
		return sum * math.Exp(-x+a*math.Log(x)-lg)
	}
	return 1 - gammaQcf(a, x)
}

// gammaQcf: upper incomplete gamma Q(a,x) by continued fraction (Lentz).
func gammaQcf(a, x float64) float64 {
	const tiny = 1e-300
	b := x + 1 - a
	c := 1 / tiny
	d := 1 / b
	h := d
	for i := 1; i < 500; i++ {
		an := -float64(i) * (float64(i) - a)
		b += 2
		d = an*d + b
		if math.Abs(d) < tiny {
			d = tiny
		}
		c = b + an/c
		if math.Abs(c) < tiny {
			c = tiny
		}
		d = 1 / d
		del := d * c
		h *= del
		if math.Abs(del-1) < 1e-14 {
			break
		}
	}
	lg, _ := math.Lgamma(a)
	return math.Exp(-x+a*math.Log(x)-lg) * h
}

// betaI is the regularized incomplete beta I_x(a, b).
func betaI(x, a, b float64) float64 {
	if x <= 0 {
		return 0
	}
	if x >= 1 {
		return 1
	}
	la, _ := math.Lgamma(a)
	lb, _ := math.Lgamma(b)
	lab, _ := math.Lgamma(a + b)
	front := math.Exp(lab - la - lb + a*math.Log(x) + b*math.Log(1-x))
	if x < (a+1)/(a+b+2) {
		return front * betacf(x, a, b) / a
	}
	return 1 - front*betacf(1-x, b, a)/b
}

func betacf(x, a, b float64) float64 {
	const tiny = 1e-300
	qab, qap, qam := a+b, a+1, a-1
	c, d := 1.0, 1-qab*x/qap
	if math.Abs(d) < tiny {
		d = tiny
	}
	d = 1 / d
	h := d
	for m := 1; m < 500; m++ {
		fm := float64(m)
		m2 := 2 * fm
		aa := fm * (b - fm) * x / ((qam + m2) * (a + m2))
		d = 1 + aa*d
		if math.Abs(d) < tiny {
			d = tiny
		}
		c = 1 + aa/c
		if math.Abs(c) < tiny {
			c = tiny
		}
		d = 1 / d
		h *= d * c
		aa = -(a + fm) * (qab + fm) * x / ((a + m2) * (qap + m2))
		d = 1 + aa*d
		if math.Abs(d) < tiny {
			d = tiny
		}
		c = 1 + aa/c
		if math.Abs(c) < tiny {
			c = tiny
		}
		d = 1 / d
		del := d * c
		h *= del
		if math.Abs(del-1) < 1e-14 {
			break
		}
	}
	return h
}

// CPAPosterior is the Gamma posterior on purchases-per-AED.
type CPAPosterior struct {
	Shape, Rate float64
}

// PriorWeight is the prior's pseudo-purchases. 2 makes "zero purchases after
// 2x target CPA" a kill at P = 5e^-4 = 0.09 and "zero after 1x" the 0.2
// boundary -- the practitioner 2x rule, backed by the posterior.
const PriorWeight = 2.0

// NewCPAPosterior builds the posterior for k purchases on spend AED, with a
// weak prior centred on priorCPA (the account's own CPA when known, else the
// target).
func NewCPAPosterior(k int64, spend, priorCPA float64) CPAPosterior {
	if priorCPA <= 0 {
		priorCPA = 100
	}
	return CPAPosterior{Shape: PriorWeight + float64(k), Rate: PriorWeight*priorCPA + math.Max(spend, 0)}
}

// PBelow is P(CPA < t).
func (p CPAPosterior) PBelow(t float64) float64 {
	if t <= 0 {
		return 0
	}
	return 1 - gammaP(p.Shape, p.Rate/t)
}

// MeanCPA is the posterior CPA estimate (rate/(shape-1) is E[1/lambda]).
func (p CPAPosterior) MeanCPA() float64 {
	if p.Shape <= 1 {
		return math.Inf(1)
	}
	return p.Rate / (p.Shape - 1)
}

// PRateBelow is P(rate < t) for a Beta(1+s, 1+f) posterior (CTR, CVR).
func PRateBelow(successes, trials int64, t float64) float64 {
	if trials < successes {
		trials = successes
	}
	return betaI(t, 1+float64(successes), 1+float64(trials-successes))
}
