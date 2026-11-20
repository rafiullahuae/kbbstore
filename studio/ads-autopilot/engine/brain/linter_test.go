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
		{"Dermatologist-prescribed formula", "medical_claim"},
		{"Guaranteed glass skin", "unrealistic_outcome"},
		{"Results in 7 days", "unrealistic_outcome"},
		{"See the before and after", "before_after"},
		{"Whitening cream for a fairer you", "whitening"},
		{"50% off this weekend only", "discount_unapproved"},
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
		{"خصم ٥٠٪ على كل المنتجات", "discount_unapproved"},
		{"الأفضل في الإمارات", "exclusivity_claim"},
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
	r := Lint(CopyFields{PrimaryText: "20% off all COSRX this weekend"}, LintOptions{PromoApproved: true})
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
