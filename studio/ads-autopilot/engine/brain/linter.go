package brain

// Policy linter, in code (not the LLM). Every rule cites what it enforces:
//
//   META-PA  Meta Ad Standards "Privacy Violations and Personal Attributes"
//            (transparency.meta.com, read live 20 Nov 2026): ads must not assert
//            or imply personal attributes incl. "physical or mental health
//            (including medical conditions)" and age; "you/your" only WITHOUT a
//            personal attribute. Meta's own examples: OK "Our new lotion and
//            creams fight wrinkles like never before!"; NOT OK "Ready to upgrade
//            your skin to look younger?", "Do you have diabetes?".
//   META-HW  Meta "Health and Wellness" (changelog Jul 23, 2026, read live):
//            cosmetic ads can't contain "statements of inferiority about
//            physical appearance"; no "skin whitening or bleaching products that
//            cause permanent skin color change"; no claims to cure/heal/eliminate
//            the listed incurable diseases (diabetes, herpes, thyroid, psoriasis,
//            cancer ...); no clickbait "promises of specific outcomes within a
//            set timeframe without disclaimers"; before/after only for 18+.
//   UAE-MC   UAE Media Council standards: no false, misleading, exaggerated or
//            exclusivity claims; health/therapeutic claims need prior approval
//            (Emirates Drug Establishment since 29 Dec 2025).
//   UAE-CP   UAE consumer protection / Dubai DET: discounts need authorisation
//            and must show original price, discounted price and %.
//
// Severities: block = never ships as written; rewrite = the safe phrasing is
// applied in code (Fix) or the copy is regenerated with the finding as
// feedback; warn = shown to the owner, does not stop the ad.

import (
	"regexp"
	"strings"
	"unicode"
)

type LintOptions struct {
	PromoApproved bool // an authorised promotion exists (then discount wording only warns)
	Lang          string
}

var (
	enYou = regexp.MustCompile(`(?i)\b(you|your|you're|youre|yours|yourself|ur)\b`)

	// health/skin conditions -- attributes when tied to "you/your"
	enCondition = regexp.MustCompile(`(?i)\b(acne|pimples?|breakouts?|blemish(es)?|eczema|psoriasis|rosacea|dermatitis|melasma|hyperpigmentation|dark spots?|pigmentation|wrinkles?|fine lines|sagging|ageing skin|aging skin|scars?|scarring|dark circles|large pores|enlarged pores|redness|irritat(ed|ion)|sensitive skin|dry skin|oily skin|dull skin|hair loss|cellulite|stretch marks)\b`)
	enAge       = regexp.MustCompile(`(?i)\b(look(ing)? younger|your age|younger[- ]looking you|over (30|35|40|45|50)\b|in your (20|30|40|50)s|turning (30|40|50)|aging you|age(ing)? gracefully)`)
	enSuffer    = regexp.MustCompile(`(?i)\b(struggl(e|es|ing) with|suffer(s|ing)? (from|with)|dealing with|tired of|sick of|fed up with|embarrassed (by|of|about)|insecure about|hate (your|their)|ashamed of|self-conscious about|do you have|have you got)\b`)
	enInferior  = regexp.MustCompile(`(?i)\b(ugly|uglier|embarrassing|gross|disgusting|hideous|unattractive|flaws?|flawed|imperfections?|bad skin|problem skin|hide (your|those|the)|fix your (face|skin)|ruin(ing|s)? your|nobody wants)\b`)
	enMedical   = regexp.MustCompile(`(?i)\b(cures?|cured|curing|heals?|healing|treats?|treatment for|therapeutic|medical[- ]grade|prescription|eliminates? (acne|eczema|psoriasis|rosacea|wrinkles|scars|spots)|gets? rid of (acne|eczema|psoriasis|rosacea|scars)|anti[- ]inflammatory|antibacterial|antifungal|repairs? dna|clinically proven|dermatologist[- ](approved|recommended|prescribed))\b`)
	enDisease   = regexp.MustCompile(`(?i)\b(diabetes|herpes|thyroid|psoriasis|ebola|cancer|autism|alzheimer'?s|parkinson'?s|als|hiv)\b`)
	enOutcome   = regexp.MustCompile(`(?i)\b(guarantee(d|s)?|100% (results|effective|guaranteed)|instant(ly)? (results|clear|glow|transformation)|overnight (results|transformation|miracle)|permanent(ly)?|forever|miracle|magic(al)? (cream|serum|results)|results in \d+ (days?|hours?|weeks?)|in (just )?\d+ (days?|hours?) (you|your|skin|results)|never (again|age))\b`)
	enBeforeAft = regexp.MustCompile(`(?i)\b(before\s*(and|&|/|-)\s*after|before-after)\b`)
	enWhitening = regexp.MustCompile(`(?i)\b(whiten(ing|s)?|bleach(ing)?|skin lighten(ing|er)?|lighten (your|the) skin|fairer skin|fair skin|lighter skin tone)\b`)
	enDiscount  = regexp.MustCompile(`(?i)(\d+\s?%\s?(off|discount)|\bsale\b|\bdiscount(s|ed)?\b|\bclearance\b|\bwas (aed|dhs?)\s?\d|\bcoupon\b|\bpromo code\b|\bbogo\b|buy one get one)`)
	enExclusive = regexp.MustCompile(`(?i)(#\s?1\b|\bnumber one\b|\bno\.? ?1\b|\bthe best (in|skincare|serum|cream)\b|\bbest in (the )?(uae|dubai|gcc|world)\b|\bthe only\b|\bunlike other (brands|shops|stores)\b|\bcheapest\b)`)
	enFakeRev   = regexp.MustCompile(`(★|⭐){3,}|(?i)\b(rated|rating of) [45](\.\d)? stars?\b|(?i)["“][^"”]{8,}["”]\s*[-–—]\s*[A-Z][a-z]+`)
	enSPF       = regexp.MustCompile(`(?i)\bspf\s?\d+`)

	ctaAllowed = map[string]bool{"SHOP_NOW": true, "LEARN_MORE": true, "ORDER_NOW": true, "BUY_NOW": true, "SEE_MORE": true, "GET_OFFER": true}
)

// Arabic terms are matched on normalized text (no diacritics/tatweel, one alef).
var (
	arCondition = []string{"حب الشباب", "حبوب", "الحبوب", "البثور", "بثور", "الاكزيما", "اكزيما", "الصدفيه", "الصدفية", "الورديه", "الوردية", "الكلف", "التصبغات", "تصبغات", "البقع الداكنه", "البقع الداكنة", "بقع داكنه", "بقع داكنة", "التجاعيد", "تجاعيد", "الخطوط الدقيقه", "الخطوط الدقيقة", "الشيخوخه", "الشيخوخة", "الترهل", "ترهل", "الهالات السوداء", "هالات", "الندوب", "ندوب", "المسام الواسعه", "المسام الواسعة", "الاحمرار", "احمرار", "تهيج", "البشره الدهنيه", "البشرة الدهنية", "البشره الجافه", "البشرة الجافة", "تساقط الشعر"}
	arYouWords  = []string{"انت", "انتي", "تعانين", "تعاني", "لديك", "عندك", "هل تعانين", "مشكلتك", "مشاكلك"}
	arYouExempt = map[string]bool{"ذلك": true, "لذلك": true, "كذلك": true, "تلك": true, "هنالك": true, "مسك": true, "المسك": true, "سمك": true, "بذلك": true, "مبارك": true, "ملك": true, "شوك": true, "فلك": true, "ديك": true, "اشترك": true, "شارك": true, "بنك": true, "دليلك": false}
	arSuffer    = []string{"تعانين من", "تعاني من", "هل تعانين", "هل لديك", "هل عندك", "تعبتي من", "تعبت من", "مللتي من", "مللت من", "محرجه من", "محرجة من", "تخجلين من", "تكرهين"}
	arAge       = []string{"تبدين اصغر", "تبدو اصغر", "اصغر سنا", "عمرك", "سنك", "في الاربعين", "في الثلاثين", "بعد الاربعين", "بعد الثلاثين"}
	arInferior  = []string{"قبيحه", "قبيحة", "قبيح", "محرج", "محرجه", "محرجة", "بشعه", "بشعة", "مقزز", "عيوب بشرتك", "عيوب", "اخفي", "اخفاء", "مشكله بشرتك", "مشكلة بشرتك", "بشره سيئه", "بشرة سيئة"}
	arMedical   = []string{"يعالج", "تعالج", "علاج", "يشفي", "تشفي", "شفاء", "يقضي على", "تقضي على", "دواء", "طبي", "علاجي", "مضاد للالتهاب", "مضاد للبكتيريا", "مثبت سريريا", "موصوف من", "بوصفه طبيه", "بوصفة طبية"}
	arDisease   = []string{"السكري", "الهربس", "الغده الدرقيه", "الغدة الدرقية", "الصدفيه", "الصدفية", "السرطان", "التوحد", "الزهايمر", "باركنسون", "الايدز"}
	arOutcome   = []string{"مضمون", "مضمونه", "مضمونة", "نتائج فوريه", "نتائج فورية", "فورا", "بين ليله وضحاها", "بين ليلة وضحاها", "دائم", "دائمه", "دائمة", "نهائيا", "للابد", "معجزه", "معجزة", "سحري", "سحريه", "سحرية", "خلال ايام", "خلال اسبوع"}
	arBeforeAft = []string{"قبل وبعد", "قبل و بعد", "قبل/بعد"}
	arWhitening = []string{"تبييض", "مبيض", "تفتيح البشره", "تفتيح البشرة", "بشره افتح", "بشرة افتح", "بشره بيضاء", "بشرة بيضاء"}
	arDiscount  = []string{"خصم", "تخفيض", "تخفيضات", "تنزيلات", "اوكازيون", "كوبون", "كود خصم", "اشتري واحد"}
	arExclusive = []string{"رقم 1", "رقم ١", "الافضل في الامارات", "الافضل في دبي", "الافضل في الخليج", "الوحيد", "الوحيده", "الوحيدة", "ارخص", "الارخص"}
	arTimeframe = regexp.MustCompile(`(خلال|في)\s*([0-9٠-٩]+)\s*(يوم|ايام|ساعه|ساعة|ساعات|اسابيع|اسبوع)`)
	arPercent   = regexp.MustCompile(`[0-9٠-٩]+\s*[%٪]`)
)

// Fields of one piece of copy, keyed for findings.
type CopyFields struct {
	PrimaryText string
	Headline    string
	Description string
	CTA         string
	Hooks       []string
}

func (c CopyFields) each(f func(field, text string)) {
	f("primary_text", c.PrimaryText)
	f("headline", c.Headline)
	f("description", c.Description)
	for _, h := range c.Hooks {
		f("hook", h)
	}
}

// Lint checks one copy set. Arabic and English rules both run on every
// field (Arabic copy often carries English brand names and vice versa).
func Lint(c CopyFields, o LintOptions) LintResult {
	var out []LintIssue
	add := func(rule, sev, field, match, why, fix string) {
		out = append(out, LintIssue{Rule: rule, Severity: sev, Field: field, Match: match, Why: why, Fix: fix})
	}
	c.each(func(field, text string) {
		if strings.TrimSpace(text) == "" {
			return
		}
		for _, sent := range splitSentences(text) {
			// --- English ---
			if enCondition.MatchString(sent) && enYou.MatchString(sent) {
				add("personal_attribute_health", "block", field, enCondition.FindString(sent),
					"META-PA: 'you/your' tied to a skin/health condition implies the viewer has it.",
					"Describe the product instead: 'Made for blemish-prone skin' / 'A gentle gel for skin that breaks out'.")
			}
			if m := enSuffer.FindString(sent); m != "" && (enCondition.MatchString(sent) || enYou.MatchString(sent)) {
				add("personal_attribute_question", "block", field, m, "META-PA: asks about or implies the viewer's condition.", "Lead with the product benefit, not the viewer's problem.")
			}
			if m := enAge.FindString(sent); m != "" {
				add("personal_attribute_age", "block", field, m, "META-PA: Meta's own example 'Ready to upgrade your skin to look younger?' is not allowed.", "Say what the product does: 'Firming peptide cream for a smoother look'.")
			}
		}
		if m := enInferior.FindString(text); m != "" {
			add("inferiority", "block", field, m, "META-HW: statements of inferiority about appearance or hygiene.", "Use positive, aspirational wording.")
		}
		if m := enDisease.FindString(text); m != "" {
			add("disease_claim", "block", field, m, "META-HW: incurable/terminal disease named in a cosmetic ad.", "Remove it.")
		}
		if m := enMedical.FindString(text); m != "" {
			add("medical_claim", "block", field, m, "UAE-MC / META-HW: therapeutic claims need EDE approval; cosmetics care, they do not treat.", "'cares for', 'soothes the look of', 'helps skin feel'.")
		}
		if m := enOutcome.FindString(text); m != "" {
			add("unrealistic_outcome", "block", field, m, "META-HW clickbait / UAE-MC exaggeration: guaranteed, permanent or timed results.", "Drop the promise; describe texture, ingredient, routine.")
		}
		if m := enBeforeAft.FindString(text); m != "" {
			add("before_after", "block", field, m, "META-HW: before/after transformations are restricted (18+ only); we never show them.", "Show the product and the routine instead.")
		}
		if m := enWhitening.FindString(text); m != "" {
			add("whitening", "block", field, m, "META-HW: skin whitening/bleaching claims.", "'brightening', 'radiance', 'glow' describe the look without whitening.")
		}
		discount(&out, field, text, o, enDiscount.FindString(text), "")
		if m := enExclusive.FindString(text); m != "" {
			add("exclusivity_claim", "rewrite", field, m, "UAE-MC: exaggerated or exclusivity claims and comparisons with others.", "'Loved K-beauty pick', 'Authentic Korean skincare'.")
		}
		if m := enFakeRev.FindString(text); m != "" {
			add("fake_review", "block", field, m, "Star ratings or quoted shoppers we cannot show are fabricated reviews.", "Use product facts or a Q&A card.")
		}
		if m := enSPF.FindString(text); m != "" {
			add("spf_claim", "warn", field, m, "SPF numbers must match the product label exactly.", "")
		}
		// --- Arabic (normalized) ---
		n := normalizeAR(text)
		if !hasArabic(n) {
			return
		}
		for _, sent := range splitSentences(n) {
			cond := arMatch(sent, arCondition)
			if cond != "" && arSecondPerson(sent) {
				add("personal_attribute_health", "block", field, cond, "META-PA: second person (ـكِ / أنتِ) tied to a skin condition.",
					"صِفي المنتج: «مناسب للبشرة المعرّضة للحبوب» بدل «حبوب بشرتكِ».")
			}
			if m := arMatch(sent, arSuffer); m != "" {
				add("personal_attribute_question", "block", field, m, "META-PA: «هل تعانين...» implies the viewer's condition.", "ابدئي بفائدة المنتج.")
			}
			if m := arMatch(sent, arAge); m != "" {
				add("personal_attribute_age", "block", field, m, "META-PA: age attribute.", "اذكري ما يفعله المنتج.")
			}
		}
		if m := arMatch(n, arInferior); m != "" {
			add("inferiority", "block", field, m, "META-HW: negative wording about appearance.", "استخدمي لغة إيجابية.")
		}
		if m := arMatch(n, arDisease); m != "" {
			add("disease_claim", "block", field, m, "META-HW: disease named in a cosmetic ad.", "")
		}
		if m := arMatch(n, arMedical); m != "" {
			add("medical_claim", "block", field, m, "UAE-MC: therapeutic claim (يعالج/علاج/يشفي) needs EDE approval.", "«يهدّئ مظهر»، «يمنح إحساساً بـ».")
		}
		if m := arMatch(n, arOutcome); m != "" {
			add("unrealistic_outcome", "block", field, m, "META-HW / UAE-MC: guaranteed, permanent or instant results.", "")
		} else if m := arTimeframe.FindString(n); m != "" {
			add("unrealistic_outcome", "block", field, m, "META-HW: results promised within a set timeframe.", "")
		}
		if m := arMatch(n, arBeforeAft); m != "" {
			add("before_after", "block", field, m, "META-HW: before/after.", "")
		}
		if m := arMatch(n, arWhitening); m != "" {
			add("whitening", "block", field, m, "META-HW: whitening/bleaching.", "«إشراقة»، «نضارة»، «توهّج».")
		}
		m := arMatch(n, arDiscount)
		if m == "" {
			m = arPercent.FindString(n)
		}
		discount(&out, field, n, o, m, "ar")
		if m := arMatch(n, arExclusive); m != "" {
			add("exclusivity_claim", "rewrite", field, m, "UAE-MC: exclusivity / superlative claim.", "«اختيار محبوب من الجمال الكوري».")
		}
	})
	// Field-level checks.
	if c.CTA != "" && !ctaAllowed[c.CTA] {
		add("cta", "rewrite", "cta", c.CTA, "Not a Meta call_to_action type we use.", "SHOP_NOW")
	}
	if c.CTA == "GET_OFFER" && !o.PromoApproved {
		add("cta_offer", "rewrite", "cta", c.CTA, "UAE-CP: 'Get offer' implies a promotion we have no authorisation for.", "SHOP_NOW")
	}
	if o.Lang == "ar" {
		for _, f := range []struct{ name, text string }{{"primary_text", c.PrimaryText}, {"headline", c.Headline}} {
			if f.text != "" && arScriptShare(f.text) < 0.6 {
				add("arabic_language", "rewrite", f.name, "", "Arabic version must be written in Arabic (UAE ads: MSA or Emirati dialect); brand names may stay Latin.", "")
			}
		}
	}
	if r := []rune(c.Headline); len(r) > 40 {
		add("length_headline", "warn", "headline", "", "Headlines over 40 characters get cut in the feed.", "")
	}
	if r := []rune(c.Description); len(r) > 30 {
		add("length_description", "warn", "description", "", "Descriptions over 30 characters often do not show.", "")
	}
	res := LintResult{OK: true, Findings: out}
	for _, f := range out {
		if f.Severity == "block" || f.Severity == "rewrite" {
			res.OK = false
		}
		if f.Severity == "block" {
			res.Blocked = true
		}
	}
	return res
}

func discount(out *[]LintIssue, field, text string, o LintOptions, m, lang string) {
	if m == "" {
		return
	}
	if o.PromoApproved {
		*out = append(*out, LintIssue{Rule: "discount_disclosure", Severity: "warn", Field: field, Match: m,
			Why: "UAE-CP: show original price, discounted price and the % together; keep the DET permit reference.", Fix: ""})
		return
	}
	*out = append(*out, LintIssue{Rule: "discount_unapproved", Severity: "block", Field: field, Match: m,
		Why: "UAE-CP / Dubai DET: discount or sale wording needs an authorised promotion. None is set in the app.",
		Fix: "Talk about value instead: 'Free delivery over AED 150' only if the shop really offers it."})
}

// safe deterministic rewrites: (pattern, replacement). Applied by Fix; the
// copy is linted again afterwards and anything still blocked is dropped or
// regenerated -- never shipped.
var enFixes = []struct {
	re  *regexp.Regexp
	rep string
}{
	{regexp.MustCompile(`(?i)\b(your|ur) (acne|breakouts|blemishes)\b`), "blemish-prone skin"},
	{regexp.MustCompile(`(?i)\b(your|ur) (wrinkles|fine lines)\b`), "the look of fine lines"},
	{regexp.MustCompile(`(?i)\b(your|ur) dark spots\b`), "the look of dark spots"},
	{regexp.MustCompile(`(?i)\b(your|ur) (dry|oily|sensitive|dull) skin\b`), "$2 skin"},
	{regexp.MustCompile(`(?i)\bwhitening\b`), "brightening"},
	{regexp.MustCompile(`(?i)\bclinically proven\b`), "carefully formulated"},
	{regexp.MustCompile(`(?i)\bheals?\b`), "soothes"},
	{regexp.MustCompile(`(?i)\bcures?\b`), "cares for"},
	{regexp.MustCompile(`(?i)\bguaranteed\b`), ""},
	{regexp.MustCompile(`(?i)\bmiracle\b`), "favourite"},
	{regexp.MustCompile(`(?i)\b(#\s?1|number one)\b`), "much-loved"},
}

var arFixes = [][2]string{
	{"حبوب بشرتك", "البشرة المعرضة للحبوب"},
	{"تجاعيدك", "مظهر الخطوط الدقيقة"},
	{"بشرتك الجافة", "البشرة الجافة"},
	{"بشرتك الدهنية", "البشرة الدهنية"},
	{"تبييض", "إشراقة"},
	{"يعالج", "يعتني بـ"},
	{"علاج", "عناية"},
	{"مضمون", ""},
	{"معجزة", "مفضّل"},
	{"الأفضل في الإمارات", "المحبوب في الإمارات"},
}

// Fix applies the safe rewrites and re-lints. Rewritten reports whether any
// text changed.
func Fix(c CopyFields, o LintOptions) (CopyFields, LintResult, bool) {
	changed := false
	apply := func(s string) string {
		orig := s
		for _, f := range enFixes {
			s = f.re.ReplaceAllString(s, f.rep)
		}
		for _, f := range arFixes {
			s = strings.ReplaceAll(s, f[0], f[1])
		}
		s = strings.Join(strings.Fields(s), " ")
		if s != strings.Join(strings.Fields(orig), " ") {
			changed = true
		}
		return s
	}
	c.PrimaryText, c.Headline, c.Description = apply(c.PrimaryText), apply(c.Headline), apply(c.Description)
	for i := range c.Hooks {
		c.Hooks[i] = apply(c.Hooks[i])
	}
	if !ctaAllowed[c.CTA] || (c.CTA == "GET_OFFER" && !o.PromoApproved) {
		c.CTA, changed = "SHOP_NOW", true
	}
	return c, Lint(c, o), changed
}

// --- Arabic helpers ---

func normalizeAR(s string) string {
	var b strings.Builder
	for _, r := range s {
		switch {
		case r >= 0x064B && r <= 0x065F, r == 0x0670, r == 0x0640: // harakat, superscript alef, tatweel
			continue
		case r == 'أ' || r == 'إ' || r == 'آ' || r == 'ٱ':
			b.WriteRune('ا')
		case r == 'ى':
			b.WriteRune('ي')
		default:
			b.WriteRune(r)
		}
	}
	return b.String()
}

func hasArabic(s string) bool {
	for _, r := range s {
		if unicode.Is(unicode.Arabic, r) {
			return true
		}
	}
	return false
}

// arScriptShare: share of letters that are Arabic script.
func arScriptShare(s string) float64 {
	var ar, all float64
	for _, r := range s {
		if unicode.IsLetter(r) {
			all++
			if unicode.Is(unicode.Arabic, r) {
				ar++
			}
		}
	}
	if all == 0 {
		return 0
	}
	return ar / all
}

func arSecondPerson(sent string) bool {
	if arMatch(sent, arYouWords) != "" {
		return true
	}
	for _, w := range strings.FieldsFunc(sent, func(r rune) bool { return !unicode.IsLetter(r) }) {
		if arYouExempt[w] {
			continue
		}
		if len([]rune(w)) >= 4 && (strings.HasSuffix(w, "ك") || strings.HasSuffix(w, "كي")) {
			return true
		}
	}
	return false
}

// arMatch finds a term as a WORD, not a substring: "طبي" (medical) must not
// match "طبيعي" (natural), "حبوب" (pimples) must not match "محبوب" (beloved).
// A token matches a single-word term after dropping one clitic prefix
// (و ف ب ل ال وال بال فال لل) and one pronoun suffix (ك كي كم ها ه هم ي نا).
// Multi-word terms match on space-bounded token runs. Terms are normalized.
func arMatch(s string, terms []string) string {
	toks := strings.FieldsFunc(s, func(r rune) bool {
		return !unicode.IsLetter(r) && !unicode.IsDigit(r) && r != '%' && r != '٪' && r != '/'
	})
	joined := " " + strings.Join(toks, " ") + " "
	for _, t := range terms {
		t = normalizeAR(t)
		if strings.Contains(t, " ") || strings.Contains(t, "/") {
			if strings.Contains(joined, " "+t+" ") || strings.Contains(joined, " ال"+t+" ") || strings.Contains(joined, " و"+t+" ") {
				return t
			}
			continue
		}
		bare := strings.TrimPrefix(t, "ال")
		for _, w := range toks {
			for _, c := range arForms(w) {
				if c == t || c == bare {
					return t
				}
			}
		}
	}
	return ""
}

var (
	arPrefixes = []string{"وال", "بال", "فال", "لل", "ال", "و", "ف", "ب", "ل"}
	arSuffixes = []string{"كي", "كم", "هم", "ها", "نا", "ك", "ه"}
)

// arForms: the word, without one pronoun suffix, without one clitic prefix,
// and without both (each stem kept at >= 3 letters).
func arForms(w string) []string {
	out := []string{w}
	strip := func(w string) []string {
		var r []string
		for _, x := range arSuffixes {
			if strings.HasSuffix(w, x) && len([]rune(w))-len([]rune(x)) >= 3 {
				r = append(r, strings.TrimSuffix(w, x))
			}
		}
		return r
	}
	out = append(out, strip(w)...)
	for _, p := range arPrefixes {
		if strings.HasPrefix(w, p) && len([]rune(w))-len([]rune(p)) >= 3 {
			b := strings.TrimPrefix(w, p)
			out = append(out, b)
			out = append(out, strip(b)...)
		}
	}
	return out
}

var sentSplit = regexp.MustCompile(`[.!?؟؛\n]+`)

func splitSentences(s string) []string {
	parts := sentSplit.Split(s, -1)
	out := parts[:0]
	for _, p := range parts {
		if strings.TrimSpace(p) != "" {
			out = append(out, p)
		}
	}
	return out
}
