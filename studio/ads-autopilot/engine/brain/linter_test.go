package brain

import (
	"strings"
	"testing"
)

func rulesOf(r LintResult) string {
	var s []string
	for _, f := range r.Findings {
		s = append(s, f.Rule+":"+f.Severity)
	}
	return strings.Join(s, ",")
}

func hasRule(r LintResult, rule string) bool {
	for _, f := range r.Findings {
		if f.Rule == rule {
			return true
		}
	}
	return false
}

// Each case is a line an ad copywriter (or an LLM) plausibly writes. Meta's
// own examples are marked META. Mutation notes in tools/brain-mutate.sh.
func TestLinterBlocksEnglish(t *testing.T) {
	cases := []struct{ text, rule string }{
		{"Struggling with breakouts? Meet the gel that helps.", "personal_attribute_question"},
		{"Your acne doesn't stand a chance.", "personal_attribute_health"},
		{"Do you have dark spots?", "personal_attribute_health"},
		{"Ready to upgrade your skin to look younger?", "personal_attribute_age"}, // META example
		{"Hide those ugly pores today", "inferiority"},
		{"Cures acne in days", "medical_claim"},
		{"Guaranteed glass skin", "unrealistic_outcome"},
		{"Results in 7 days", "unrealistic_outcome"},
		{"See the before and after", "before_after"},
		{"Whitening cream for a fairer you", "whitening"},
		{"★★★★★ loved by 10,000 customers", "fake_review"},
		{"\"It changed my skin forever\" - Sarah", "fake_review"},
		{"Helps with psoriasis flare-ups", "disease_claim"},
	}
	for _, c := range cases {
		r := Lint(CopyFields{PrimaryText: c.text}, LintOptions{Lang: "en"})
		if !hasRule(r, c.rule) || r.OK {
			t.Errorf("%q: want %s, got [%s]", c.text, c.rule, rulesOf(r))
		}
	}
}

func TestLinterPassesGoodEnglish(t *testing.T) {
	good := []string{
		"Our new lotion and creams fight wrinkles like never before!", // META allowed example
		"A low-pH gel cleanser made for blemish-prone skin. Gentle enough for every morning.",
		"Snail mucin essence for a dewy, glass-skin look. Authentic K-beauty, delivered in the UAE.",
		"Meet the cult Korean sunscreen with a weightless finish.",
		"Your new favourite step: a calming centella toner.", // "your" without an attribute is fine (META)
	}
	for _, g := range good {
		if r := Lint(CopyFields{PrimaryText: g, Headline: "Glow, the K-beauty way", CTA: "SHOP_NOW"}, LintOptions{Lang: "en"}); !r.OK {
			t.Errorf("%q flagged: [%s]", g, rulesOf(r))
		}
	}
}

func TestLinterBlocksArabic(t *testing.T) {
	cases := []struct{ text, rule string }{
		{"هل تعانين من حب الشباب؟ جربي هذا الغسول", "personal_attribute_question"},
		{"ودّعي حبوب بشرتكِ مع هذا السيروم", "personal_attribute_health"},
		{"تجاعيدك ستختفي", "personal_attribute_health"},
		{"كريم يعالج الكلف والتصبغات", "medical_claim"},
		{"نتائج مضمونة من أول استخدام", "unrealistic_outcome"},
		{"بشرة صافية خلال ٧ أيام", "unrealistic_outcome"},
		{"شاهدي الفرق قبل وبعد", "before_after"},
		{"كريم تبييض للوجه", "whitening"},
		{"سيروم لتبييضها بسرعة", "whitening"}, // prefix + pronoun suffix
		{"تبدين أصغر بعشر سنوات", "personal_attribute_age"},
	}
	for _, c := range cases {
		r := Lint(CopyFields{PrimaryText: c.text}, LintOptions{Lang: "ar"})
		if !hasRule(r, c.rule) || r.OK {
			t.Errorf("%q: want %s, got [%s]", c.text, c.rule, rulesOf(r))
		}
	}
}

// Words that merely CONTAIN a banned stem must pass: محبوب (beloved) holds
// حبوب (pimples); طبيعي (natural) holds طبي (medical); دائماً (always) holds دائم.
// Mutation: switch arMatch back to strings.Contains -> red.
func TestLinterPassesGoodArabic(t *testing.T) {
	good := []string{
		"غسول جل لطيف مناسب للبشرة المعرّضة للحبوب، بدرجة حموضة منخفضة.",
		"اختيار محبوب من الجمال الكوري، بمكونات طبيعية.",
		"سيروم الحلزون لإطلالة ندية ومشرقة دائماً.",
		"روتينك الصباحي في ثلاث خطوات بسيطة مع COSRX.",
		"إشراقة ونضارة بلمسة كورية أصلية.",
	}
	for _, g := range good {
		if r := Lint(CopyFields{PrimaryText: g, Headline: "توهّج على الطريقة الكورية", CTA: "SHOP_NOW"}, LintOptions{Lang: "ar"}); !r.OK {
			t.Errorf("%q flagged: [%s]", g, rulesOf(r))
		}
	}
}

func TestDiscountAllowedWithApprovedPromo(t *testing.T) {
	r := Lint(CopyFields{PrimaryText: "Weekend sale on all COSRX"}, LintOptions{PromoApproved: true})
	if r.Blocked || !hasRule(r, "discount_disclosure") {
		t.Fatalf("[%s]", rulesOf(r))
	}
}

func TestArabicVersionMustBeArabic(t *testing.T) {
	r := Lint(CopyFields{PrimaryText: "Glow like never before with this serum", Headline: "Shop now"}, LintOptions{Lang: "ar"})
	if !hasRule(r, "arabic_language") {
		t.Fatalf("[%s]", rulesOf(r))
	}
}

// Fix rewrites what it safely can; what it cannot stays blocked.
func TestFix(t *testing.T) {
	f, r, ch := Fix(CopyFields{PrimaryText: "Whitening serum for your dark spots", CTA: "GET_OFFER"}, LintOptions{})
	if !ch || r.Blocked || f.CTA != "SHOP_NOW" || !strings.Contains(f.PrimaryText, "brightening") {
		t.Fatalf("%+v [%s]", f, rulesOf(r))
	}
	_, r, _ = Fix(CopyFields{PrimaryText: "Do you have eczema? See the before and after."}, LintOptions{})
	if !r.Blocked {
		t.Fatal("unfixable copy must stay blocked")
	}
	f, r, _ = Fix(CopyFields{PrimaryText: "ودّعي حبوب بشرتك مع كريم تبييض"}, LintOptions{Lang: "ar"})
	if r.Blocked {
		t.Fatalf("%q [%s]", f.PrimaryText, rulesOf(r))
	}
}

// BALANCED (the default, owner 20 Nov 2026: "acne etc ad we run, but make the
// rules half way"): acne/blemish/pore/dark-spot products are advertised with
// product- and skin-type framing. These must pass in balanced mode.
// Mutation: map personal_attribute_context to block in balanced -> red.
func TestBalancedAllowsAcneTopics(t *testing.T) {
	good := []string{
		"BHA cleanser for oily, blemish-prone skin.",
		"Helps calm the look of blemishes, every day.",
		"For acne-prone skin: a gentle low-pH gel cleanser.",
		"Your new favourite cleanser for acne-prone skin.",
		"Pore-refining pads that help skin look smoother.",
		"Niacinamide serum for the look of dark spots.",
		"Made for your acne-prone skin type.",
	}
	for _, g := range good {
		if r := Lint(CopyFields{PrimaryText: g}, LintOptions{Lang: "en"}); r.Blocked || !r.OK {
			t.Errorf("balanced flagged %q: [%s]", g, rulesOf(r))
		}
	}
	goodAR := []string{
		"غسول لطيف للبشرة المعرّضة لحب الشباب.",
		"سيروم يساعد على تهدئة مظهر البثور.",
		"روتينك اليومي للبشرة المعرّضة للحبوب.", // second person + condition, not asserted: a warning only
		"مناسب للبشرة الدهنية والمسام الواضحة.",
	}
	for _, g := range goodAR {
		if r := Lint(CopyFields{PrimaryText: g}, LintOptions{Lang: "ar"}); r.Blocked || !r.OK {
			t.Errorf("balanced flagged %q: [%s]", g, rulesOf(r))
		}
	}
	// ...and the same lines that only carry a context warning are blocked in STRICT
	if r := Lint(CopyFields{PrimaryText: "Your new favourite cleanser for acne-prone skin."}, LintOptions{Strictness: Strict}); !r.Blocked {
		t.Fatalf("strict should block you+condition: [%s]", rulesOf(r))
	}
}

// Balanced still blocks what Meta rejects most reliably.
func TestBalancedStillBlocksAssertions(t *testing.T) {
	for _, b := range []string{"Do you have acne?", "Say goodbye to your acne.", "You've got breakouts? Not anymore.", "Cures acne", "Clear skin in 3 days", "Treats eczema fast"} {
		if r := Lint(CopyFields{PrimaryText: b}, LintOptions{}); !r.Blocked {
			t.Errorf("balanced passed %q: [%s]", b, rulesOf(r))
		}
	}
	for _, b := range []string{"هل تعانين من حب الشباب؟", "بشرتك المليئة بالحبوب تستحق الأفضل", "كريم يعالج حب الشباب"} {
		if r := Lint(CopyFields{PrimaryText: b}, LintOptions{Lang: "ar"}); !r.Blocked {
			t.Errorf("balanced passed %q: [%s]", b, rulesOf(r))
		}
	}
}

// STRICT blocks the softer patterns too; RELAXED still blocks cure claims.
func TestStrictAndRelaxed(t *testing.T) {
	strict := []struct{ text, rule string }{
		{"Dermatologist-prescribed formula", "medical_wording"},
		{"Big sale this weekend only", "discount_unapproved"}, // a % or amount off blocks in every mode (TestOffersOnlyCouponAndFreeGift)
		{"تنزيلات على كل المنتجات", "discount_unapproved"},
		{"Hide those flaws", "negative_wording"},
	}
	for _, c := range strict {
		r := Lint(CopyFields{PrimaryText: c.text}, LintOptions{Strictness: Strict})
		if !hasRule(r, c.rule) || !r.Blocked {
			t.Errorf("strict %q: want %s blocked, got [%s]", c.text, c.rule, rulesOf(r))
		}
		if r := Lint(CopyFields{PrimaryText: c.text}, LintOptions{}); r.Blocked {
			t.Errorf("balanced should only warn on %q: [%s]", c.text, rulesOf(r))
		}
	}
	if r := Lint(CopyFields{PrimaryText: "See the before and after"}, LintOptions{Strictness: Relaxed}); r.Blocked {
		t.Fatalf("relaxed: before/after is a warning [%s]", rulesOf(r))
	}
	if r := Lint(CopyFields{PrimaryText: "Cures acne"}, LintOptions{Strictness: Relaxed}); !r.Blocked {
		t.Fatal("cure claims block in every mode")
	}
}

// Benefit-first copy (the owner, 20 Nov 2026) stays inside Balanced rules: the benefit, the ingredient, the Korean
// angle and the reason to buy pass; a timed result, a cure or a guarantee dressed as a benefit is blocked.
// MUTATION: drop the unrealistic_outcome rule in Lint → "Calms redness in 3 days" passes, red.
func TestBenefitFirstCopyStaysBalanced(t *testing.T) {
	good := []string{
		"Weightless hydration in one step. Heartleaf 77% helps calm the look of redness for sensitive skin. Authentic from Korea, delivered across the UAE. AED 99.",
		"Your glass-skin finish starts here: a gentle low-pH gel cleanser for oily, blemish-prone skin, ingredient-first the Korean way. Shop now.",
		"Rice and niacinamide for a visibly brighter, dewy glow. Layer it after your toner. Authentic K-beauty, AED 85.",
	}
	for _, g := range good {
		if r := Lint(CopyFields{PrimaryText: g, Headline: "Visibly calmer skin, the K-beauty way", CTA: "SHOP_NOW"}, LintOptions{Lang: "en", Strictness: Balanced}); !r.OK {
			t.Errorf("benefit copy %q flagged: [%s]", g, rulesOf(r))
		}
	}
	goodAR := []string{
		"ترطيب خفيف بخطوة واحدة. مستخلص الهارتليف يساعد على تهدئة مظهر الاحمرار للبشرة الحساسة. أصلي من كوريا مع توصيل داخل الإمارات.",
		"إشراقة ندية على الطريقة الكورية: الأرز والنياسيناميد لبشرة تبدو أكثر نضارة. تسوّقي الآن.",
	}
	for _, g := range goodAR {
		if r := Lint(CopyFields{PrimaryText: g, Headline: "نضارة كورية أصلية", CTA: "SHOP_NOW"}, LintOptions{Lang: "ar", Strictness: Balanced}); !r.OK {
			t.Errorf("Arabic benefit copy %q flagged: [%s]", g, rulesOf(r))
		}
	}
	bad := []struct{ text, rule string }{
		{"Calms redness in 3 days with heartleaf.", "unrealistic_outcome"},
		{"Centella that cures acne for good.", "medical_claim"},
		{"Guaranteed glass skin from the first use.", "unrealistic_outcome"},
	}
	for _, c := range bad {
		r := Lint(CopyFields{PrimaryText: c.text}, LintOptions{Lang: "en", Strictness: Balanced})
		if !hasRule(r, c.rule) || r.OK {
			t.Errorf("%q: want %s, got [%s]", c.text, c.rule, rulesOf(r))
		}
	}
}

// The owner's offer rules (20 Nov 2026): no percentage or amount discount in any wording mode, in English or Arabic;
// the coupon code (GLOW) and the free gift are the only promo wording, and they pass. A product name with a % in it
// ("Heartleaf 77% Toner") is not a discount. MUTATION: set discount_amount to "warn" for relaxed → red.
func TestOffersOnlyCouponAndFreeGift(t *testing.T) {
	for _, mode := range []string{Strict, Balanced, Relaxed} {
		for _, c := range []struct{ text, lang string }{
			{"Get 20% off all COSRX today", "en"}, {"Save AED 30 on your routine", "en"}, {"Half price serums this week", "en"},
			{"Sale up to 50% on sunscreen", "en"}, {"AED 20 off with every set", "en"},
			{"خصم ٢٠٪ على كل المنتجات", "ar"}, {"50% خصم على السيروم", "ar"}, {"تخفيض على مجموعة الشتاء", "ar"}, {"نصف السعر لفترة محدودة", "ar"},
		} {
			r := Lint(CopyFields{PrimaryText: c.text}, LintOptions{Lang: c.lang, Strictness: mode, PromoApproved: true, Coupon: "GLOW"})
			if !hasRule(r, "discount_amount") || r.OK {
				t.Errorf("%s %q: want discount_amount, got [%s]", mode, c.text, rulesOf(r))
			}
		}
	}
	for _, c := range []struct{ text, lang string }{
		{"Free gift with every order. Use code GLOW at checkout.", "en"},
		{"Heartleaf 77% Toner for a calm, dewy look. Use coupon GLOW.", "en"},
		{"هدية مجانية مع كل طلب. استخدمي الكود GLOW عند الدفع.", "ar"},
	} {
		if r := Lint(CopyFields{PrimaryText: c.text, Headline: map[string]string{"en": "Your K-beauty glow", "ar": "توهّج كوري"}[c.lang], CTA: "SHOP_NOW"}, LintOptions{Lang: c.lang, Strictness: Balanced, Coupon: "GLOW"}); !r.OK || hasRule(r, "discount_amount") {
			t.Errorf("%q flagged: [%s]", c.text, rulesOf(r))
		}
	}
}

// The owner (20 Nov 2026): "pay in 4 with Tabby / Tamara" is allowed; gift tiers are not ("a better gift above AED X").
// MUTATION: drop the gift_tier rule → the tier lines pass, red.
func TestPayLaterYesGiftTiersNo(t *testing.T) {
	for _, c := range []struct{ text, lang string }{{"Pay in 4 with Tabby or Tamara. Free gift with every order.", "en"}, {"قسّميها على ٤ دفعات مع تابي أو تمارا. هدية مجانية مع كل طلب.", "ar"}} {
		if r := Lint(CopyFields{PrimaryText: c.text, Headline: map[string]string{"en": "Your K-beauty glow", "ar": "توهّج كوري"}[c.lang], CTA: "SHOP_NOW"}, LintOptions{Lang: c.lang, Strictness: Balanced}); !r.OK {
			t.Errorf("%q flagged: [%s]", c.text, rulesOf(r))
		}
	}
	for _, c := range []struct{ text, lang string }{{"Get a better gift on orders above AED 200", "en"}, {"Spend AED 300 and get a bigger gift", "en"}, {"هدية أكبر للطلبات فوق ٢٠٠ درهم", "ar"}} {
		if r := Lint(CopyFields{PrimaryText: c.text}, LintOptions{Lang: c.lang, Strictness: Relaxed}); !hasRule(r, "gift_tier") || r.OK {
			t.Errorf("%q: want gift_tier, got [%s]", c.text, rulesOf(r))
		}
	}
}
