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
//            no "statements of inferiority about physical appearance"; no "skin
//            whitening or bleaching products that cause permanent skin color
//            change"; no claims to cure/heal/eliminate the listed incurable
//            diseases; no clickbait "promises of specific outcomes within a set
//            timeframe"; before/after only for 18+.
//   UAE-MC   UAE Media Council: no false, misleading, exaggerated or exclusivity
//            claims; health/therapeutic claims need prior approval (EDE).
//   UAE-CP   UAE consumer protection / Dubai DET: discounts need authorisation
//            and must show original price, discounted price and %.
//
// STRICTNESS (Settings -> Limits -> Ad wording rules). The owner, 20 Nov 2026:
// "acne etc ad we run, but make the rules half way. it's okay." So acne,
// blemish, pore and dark-spot products ARE advertised, and the default is
// BALANCED: product/skin-type framing ("for acne-prone skin", "helps calm the
// look of blemishes") passes; only what Meta rejects most reliably blocks --
// second-person attribute assertions ("Do you have acne?", "your acne",
// «هل تعانين من حب الشباب؟», «بشرتك المليئة بالحبوب»), cure/medical claims,
// guaranteed/timed outcomes, before/after, fake reviews. Everything else is a
// logged warning. STRICT blocks every pattern; RELAXED also softens the
// before/after and strong-negative rules to warnings (cure claims, attribute
// assertions, timed promises and fake reviews block in every mode).
//
// Severities: block = never ships as written; rewrite = the safe phrasing is
// applied in code (Fix), else the copy is regenerated with the finding as
// feedback; warn = logged and shown, does not stop the ad.

import (
	"regexp"
	"strings"
	"unicode"
)

const (
	Strict   = "strict"
	Balanced = "balanced"
	Relaxed  = "relaxed"
)

type LintOptions struct {
	PromoApproved bool   // an authorised promotion exists (then discount wording only warns)
	Coupon        string // the owner's coupon code (Playbook → Offers, default GLOW): naming it is allowed
	Lang          string // ar | en: the version being linted
	Strictness    string // strict | balanced (default) | relaxed
}

// severity per rule: [strict, balanced, relaxed]
var ruleSeverity = map[string][3]string{
	"personal_attribute_health":   {"block", "block", "block"}, // your acne / you have acne / حبوبك
	"personal_attribute_context":  {"block", "warn", "warn"},   // "you" and a condition in one sentence, not asserted
	"personal_attribute_question": {"block", "block", "block"}, // "Struggling with breakouts?", «هل تعانين من...»
	"personal_attribute_soft":     {"block", "warn", "warn"},   // "Tired of long routines? You..." (no condition)
	"personal_attribute_age":      {"block", "block", "block"}, // "your skin ... look younger", «تبدين أصغر»
	"age_reference":               {"block", "warn", "warn"},   // "look younger" without "you"
	"inferiority":                 {"block", "block", "warn"},  // ugly, disgusting, hideous, قبيحة
	"negative_wording":            {"block", "warn", "warn"},   // flaws, imperfections, hide, عيوب
	"medical_claim":               {"block", "block", "block"}, // cures, heals, treats, eliminates acne, يعالج
	"medical_wording":             {"block", "warn", "warn"},   // anti-inflammatory, clinically proven, dermatologist-approved
	"disease_claim":               {"block", "block", "block"},
	"unrealistic_outcome":         {"block", "block", "block"}, // guaranteed, instant, results in 7 days, مضمون
	"hype_wording":                {"block", "warn", "warn"},   // permanent, forever, miracle, magic
	"before_after":                {"block", "block", "warn"},
	"whitening":                   {"block", "rewrite", "rewrite"},
	"discount_unapproved":         {"block", "warn", "warn"},
	"discount_amount":             {"block", "block", "block"}, // the owner: never a % or amount off, only the coupon code + free gift
	"exclusivity_claim":           {"rewrite", "warn", "warn"},
	"fake_review":                 {"block", "block", "block"},
	"spf_claim":                   {"warn", "warn", "warn"},
}

func sevFor(rule, mode string) string {
	s, ok := ruleSeverity[rule]
	if !ok {
		return "warn"
	}
	switch mode {
	case Strict:
		return s[0]
	case Relaxed:
		return s[2]
	}
	return s[1]
}

var (
	enYou = regexp.MustCompile(`(?i)\b(you|your|you're|youre|yours|yourself|ur)\b`)

	// skin/health conditions (attributes when ASSERTED about the viewer)
	condWords   = `acne|pimples?|breakouts?|blemish(?:es)?|eczema|psoriasis|rosacea|dermatitis|melasma|hyperpigmentation|dark spots?|pigmentation|wrinkles?|fine lines|sagging|ageing skin|aging skin|scars?|scarring|dark circles|large pores|enlarged pores|pores|redness|irritat(?:ed|ion)|hair loss|cellulite|stretch marks`
	enCondition = regexp.MustCompile(`(?i)\b(` + condWords + `)\b`)
	// assertion: "your acne", "your stubborn dark spots", "you have acne", "you've got breakouts", "your acne-prone skin" (ok -> handled by -prone check)
	enAssert = regexp.MustCompile(`(?i)\b(?:(?:your|ur)\s+(?:[a-z]+\s+){0,2}?(?:` + condWords + `)\b|you(?:'ve| have|'re| are)?\s+(?:got\s+|have\s+|suffering from\s+|dealing with\s+)(?:[a-z]+\s+){0,2}?(?:` + condWords + `)\b)`)
	enProne  = regexp.MustCompile(`(?i)^(?:-?\s*)(prone|type)\b`)

	enAgeYou      = regexp.MustCompile(`(?i)\b(your age|younger[- ]looking you|in your (20|30|40|50)s|are you (over )?(30|40|50)|you look older|aging you)\b`)
	enAge         = regexp.MustCompile(`(?i)\b(look(ing)? younger|over (30|35|40|45|50)\b|turning (30|40|50))`)
	enSuffer      = regexp.MustCompile(`(?i)\b(struggl(e|es|ing) with|suffer(s|ing)? (from|with)|dealing with|tired of|sick of|fed up with|embarrassed (by|of|about)|insecure about|hate (your|their)|ashamed of|self-conscious about|do you have|have you got)\b`)
	enInferior    = regexp.MustCompile(`(?i)\b(ugly|uglier|disgusting|hideous|gross|nobody wants)\b`)
	enNegative    = regexp.MustCompile(`(?i)\b(embarrassing|unattractive|flaws?|flawed|imperfections?|bad skin|problem skin|hide (your|those|the)|fix your (face|skin)|ruin(ing|s)? your)\b`)
	enMedical     = regexp.MustCompile(`(?i)\b(cures?|cured|curing|heals?|healing|treats?|treatment for|medical[- ]grade|prescription|eliminates? (acne|eczema|psoriasis|rosacea|wrinkles|scars|spots|breakouts|pimples)|gets? rid of (acne|eczema|psoriasis|rosacea|scars|breakouts|pimples))\b`)
	enMedWord     = regexp.MustCompile(`(?i)\b(therapeutic|anti[- ]inflammatory|antibacterial|antifungal|repairs? dna|clinically proven|dermatologist[- ](approved|recommended|prescribed))\b`)
	enDisease     = regexp.MustCompile(`(?i)\b(diabetes|herpes|thyroid|psoriasis|ebola|cancer|autism|alzheimer'?s|parkinson'?s|hiv)\b`)
	enBenefitTime = regexp.MustCompile(`(?i)\b(calm|clear|fade|reduc|brighten|smooth|firm|transform|repair|heal|lighten|tighten|shrink|plump|glow|hydrat|soothe|even)\w*\b[^.!?\n]{0,40}?\b(in|within) (just |only )?\d+ (days?|hours?|weeks?|nights?)\b`)
	enOutcome     = regexp.MustCompile(`(?i)\b(guarantee(d|s)?|100% (results|effective|guaranteed)|instant(ly)? (results|clear|glow|transformation)|overnight (results|transformation|miracle)|results in \d+ (days?|hours?|weeks?)|(clear|perfect|flawless) skin in \d+ (days?|hours?|weeks?)|in (just )?\d+ (days?|hours?) (you|your|skin|results))\b`)
	enHype        = regexp.MustCompile(`(?i)\b(permanent(ly)?|forever|miracle|magic(al)? (cream|serum|results)|never (again|age))\b`)
	enBeforeAft   = regexp.MustCompile(`(?i)\b(before\s*(and|&|/|-)\s*after|before-after)\b`)
	enWhitening   = regexp.MustCompile(`(?i)\b(whiten(ing|s)?|bleach(ing)?|skin lighten(ing|er)?|lighten (your|the) skin|fairer skin|fair skin|fairer you|lighter skin tone)\b`)
	// any percentage or amount off, in every mode (the owner, 20 Nov 2026); a product name like "Heartleaf 77% Toner"
	// is not a discount, so a % only counts next to off / discount / save / sale
	enDiscountAmt = regexp.MustCompile(`(?i)(\d+(\.\d+)?\s?%\s?(off|discount|saving|cheaper|less)|\b(save|discount|off|sale)\s(of\s)?(up to\s)?\d+\s?%|\bhalf[- ]price\b|\bsave\s(up to\s)?(aed|dhs?)\s?\d|\b(aed|dhs?)\s?\d+(\.\d+)?\s?off\b|\d+\s?(aed|dhs?)\s?off\b)`)
	enDiscount    = regexp.MustCompile(`(?i)(\d+\s?%\s?(off|discount)|\bsale\b|\bdiscount(s|ed)?\b|\bclearance\b|\bwas (aed|dhs?)\s?\d|\bcoupon\b|\bpromo code\b|\bbogo\b|buy one get one)`)
	enExclusive   = regexp.MustCompile(`(?i)(#\s?1\b|\bnumber one\b|\bno\.? ?1\b|\bthe best (in|skincare|serum|cream)\b|\bbest in (the )?(uae|dubai|gcc|world)\b|\bthe only\b|\bunlike other (brands|shops|stores)\b|\bcheapest\b)`)
	enFakeRev     = regexp.MustCompile(`(★|⭐){3,}|(?i)\b(rated|rating of) [45](\.\d)? stars?\b|(?i)["“][^"”]{8,}["”]\s*[-–—]\s*[A-Z][a-z]+`)
	enSPF         = regexp.MustCompile(`(?i)\bspf\s?\d+`)

	ctaAllowed = map[string]bool{"SHOP_NOW": true, "LEARN_MORE": true, "ORDER_NOW": true, "BUY_NOW": true, "SEE_MORE": true, "GET_OFFER": true}
)

// Arabic terms are matched as words (arMatch) on normalized text.
var (
	arCondition   = []string{"حب الشباب", "حبوب", "البثور", "بثور", "الاكزيما", "اكزيما", "الصدفيه", "الصدفية", "الورديه", "الوردية", "الكلف", "التصبغات", "تصبغات", "البقع الداكنه", "البقع الداكنة", "بقع داكنه", "بقع داكنة", "التجاعيد", "تجاعيد", "الخطوط الدقيقه", "الخطوط الدقيقة", "الشيخوخه", "الشيخوخة", "الترهل", "ترهل", "الهالات السوداء", "هالات", "الندوب", "ندوب", "المسام الواسعه", "المسام الواسعة", "مسام", "الاحمرار", "احمرار", "تهيج", "تساقط الشعر"}
	arYouWords    = []string{"انت", "انتي", "تعانين", "تعاني", "لديك", "عندك", "مشكلتك", "مشاكلك"}
	arYouExempt   = map[string]bool{"ذلك": true, "لذلك": true, "كذلك": true, "تلك": true, "هنالك": true, "مسك": true, "المسك": true, "سمك": true, "بذلك": true, "مبارك": true, "ملك": true, "شوك": true, "فلك": true, "ديك": true, "اشترك": true, "شارك": true, "بنك": true}
	arSuffer      = []string{"تعانين من", "تعاني من", "هل تعانين", "هل لديك", "هل عندك", "تعبتي من", "تعبت من", "مللتي من", "مللت من", "محرجه من", "محرجة من", "تخجلين من", "تكرهين"}
	arAgeYou      = []string{"تبدين اصغر", "عمرك", "سنك"}
	arAge         = []string{"تبدو اصغر", "اصغر سنا", "في الاربعين", "في الثلاثين", "بعد الاربعين", "بعد الثلاثين"}
	arInferior    = []string{"قبيحه", "قبيحة", "قبيح", "بشعه", "بشعة", "مقزز", "مقززه", "مقززة"}
	arNegative    = []string{"محرج", "محرجه", "محرجة", "عيوب بشرتك", "عيوب", "اخفي", "اخفاء", "مشكله بشرتك", "مشكلة بشرتك", "بشره سيئه", "بشرة سيئة"}
	arMedical     = []string{"يعالج", "تعالج", "علاج", "يشفي", "تشفي", "شفاء", "يقضي على", "تقضي على", "دواء", "علاجي", "بوصفه طبيه", "بوصفة طبية"}
	arMedWord     = []string{"طبي", "مضاد للالتهاب", "مضاد للبكتيريا", "مثبت سريريا", "موصوف من"}
	arDisease     = []string{"السكري", "الهربس", "الغده الدرقيه", "الغدة الدرقية", "الصدفيه", "الصدفية", "السرطان", "التوحد", "الزهايمر", "باركنسون", "الايدز"}
	arOutcome     = []string{"مضمون", "مضمونه", "مضمونة", "نتائج فوريه", "نتائج فورية", "فورا", "بين ليله وضحاها", "بين ليلة وضحاها", "خلال ايام", "خلال اسبوع"}
	arHype        = []string{"دائم", "دائمه", "دائمة", "نهائيا", "للابد", "معجزه", "معجزة", "سحري", "سحريه", "سحرية"}
	arBeforeAft   = []string{"قبل وبعد", "قبل و بعد", "قبل/بعد"}
	arWhitening   = []string{"تبييض", "مبيض", "تفتيح البشره", "تفتيح البشرة", "بشره افتح", "بشرة افتح", "بشره بيضاء", "بشرة بيضاء"}
	arDiscountAmt = []string{"خصم", "تخفيض", "تخفيضات", "نصف السعر", "وفري", "توفير"}
	arPercentOff  = regexp.MustCompile(`[0-9٠-٩]+\s?[٪%]\s?(خصم|تخفيض|اقل|أقل)`)
	arDiscount    = []string{"خصم", "تخفيض", "تخفيضات", "تنزيلات", "اوكازيون", "كوبون", "كود خصم", "اشتري واحد"}
	arExclusive   = []string{"رقم 1", "رقم ١", "الافضل في الامارات", "الافضل في دبي", "الافضل في الخليج", "الوحيد", "الوحيده", "الوحيدة", "ارخص", "الارخص"}
	arTimeframe   = regexp.MustCompile(`(خلال|في)\s*([0-9٠-٩]+)\s*(يوم|ايام|ساعه|ساعة|ساعات|اسابيع|اسبوع)`)
	arPercent     = regexp.MustCompile(`[0-9٠-٩]+\s*[%٪]`)
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
	mode := o.Strictness
	if mode != Strict && mode != Relaxed {
		mode = Balanced
	}
	var out []LintIssue
	add := func(rule, field, match, why, fix string) {
		out = append(out, LintIssue{Rule: rule, Severity: sevFor(rule, mode), Field: field, Match: match, Why: why, Fix: fix})
	}
	c.each(func(field, text string) {
		if strings.TrimSpace(text) == "" {
			return
		}
		for _, sent := range splitSentences(text) {
			// --- English ---
			asserted := false
			for _, loc := range enAssert.FindAllStringIndex(sent, -1) {
				if !enProne.MatchString(sent[loc[1]:]) { // "your acne-prone skin" is a skin type, not an assertion
					add("personal_attribute_health", field, sent[loc[0]:loc[1]],
						"META-PA: asserts the viewer has a skin/health condition ('your acne', 'you have breakouts').",
						"Frame the product: 'for acne-prone skin', 'helps calm the look of blemishes'.")
					asserted = true
					break
				}
			}
			cond := enCondition.FindString(sent)
			if m := enSuffer.FindString(sent); m != "" && (cond != "" || enYou.MatchString(sent)) {
				if cond != "" {
					add("personal_attribute_question", field, m, "META-PA: asks about or implies the viewer's condition.", "Lead with the product benefit, not the viewer's problem.")
				} else {
					add("personal_attribute_soft", field, m, "META-PA risk: problem-question aimed at the viewer.", "Lead with the benefit.")
				}
				asserted = true
			}
			if !asserted && cond != "" && enYou.MatchString(sent) {
				add("personal_attribute_context", field, cond, "'you/your' and a condition in one sentence can read as implying the viewer has it.", "Keep 'you' away from the condition: 'for acne-prone skin'.")
			}
			if m := enAgeYou.FindString(sent); m != "" || (enAge.MatchString(sent) && enYou.MatchString(sent)) {
				if m == "" {
					m = enAge.FindString(sent)
				}
				add("personal_attribute_age", field, m, "META-PA: Meta's own example 'Ready to upgrade your skin to look younger?' is not allowed.", "Say what the product does: 'Firming peptide cream for a smoother look'.")
			} else if m := enAge.FindString(sent); m != "" {
				add("age_reference", field, m, "Age framing; fine without 'you', but keep it about the product.", "")
			}
		}
		if m := enInferior.FindString(text); m != "" {
			add("inferiority", field, m, "META-HW: statements of inferiority about appearance or hygiene.", "Use positive, aspirational wording.")
		}
		if m := enNegative.FindString(text); m != "" {
			add("negative_wording", field, m, "META-HW risk: negative framing of appearance.", "Prefer positive wording.")
		}
		if m := enDisease.FindString(text); m != "" {
			add("disease_claim", field, m, "META-HW: incurable/terminal disease named in a cosmetic ad.", "Remove it.")
		}
		if m := enMedical.FindString(text); m != "" {
			add("medical_claim", field, m, "UAE-MC / META-HW: cure/treat claims need EDE approval; cosmetics care, they do not treat.", "'helps calm the look of', 'cares for', 'soothes'.")
		}
		if m := enMedWord.FindString(text); m != "" {
			add("medical_wording", field, m, "UAE-MC: clinical/medical wording needs evidence or approval.", "'carefully formulated', 'gentle'.")
		}
		if m := enOutcome.FindString(text); m != "" {
			add("unrealistic_outcome", field, m, "META-HW clickbait / UAE-MC: guaranteed, instant or timed results.", "Describe texture, ingredient, routine instead.")
		} else if m := enBenefitTime.FindString(text); m != "" { // a benefit with a deadline ("calms redness in 3 days"); delivery times stay fine
			add("unrealistic_outcome", field, m, "META-HW: a skin result promised within a set timeframe.", "'helps calm the look of redness' (no timeframe).")
		}
		if m := enHype.FindString(text); m != "" {
			add("hype_wording", field, m, "UAE-MC: exaggerated wording.", "")
		}
		if m := enBeforeAft.FindString(text); m != "" {
			add("before_after", field, m, "META-HW: before/after transformations are restricted (18+ only).", "Show the product and the routine instead.")
		}
		if m := enWhitening.FindString(text); m != "" {
			add("whitening", field, m, "META-HW: skin whitening/bleaching claims.", "'brightening', 'radiance', 'glow'.")
		}
		if m := enDiscountAmt.FindString(text); m != "" {
			add("discount_amount", field, m, "The owner: no percentage or amount discounts in ads; only the coupon code and the free gift.", "'Use code "+firstNonEmpty(o.Coupon, "GLOW")+" at checkout' / 'Free gift with every order'.")
		} else if m := enDiscount.FindString(text); !(couponOK(text, o.Coupon) && (strings.EqualFold(m, "coupon") || strings.EqualFold(m, "promo code"))) {
			discount(&out, field, o, mode, m)
		}
		if m := enExclusive.FindString(text); m != "" {
			add("exclusivity_claim", field, m, "UAE-MC: exaggerated or exclusivity claims.", "'Loved K-beauty pick', 'Authentic Korean skincare'.")
		}
		if m := enFakeRev.FindString(text); m != "" {
			add("fake_review", field, m, "Star ratings or quoted shoppers we cannot show are fabricated reviews.", "Use product facts or a Q&A card.")
		}
		if m := enSPF.FindString(text); m != "" {
			add("spf_claim", field, m, "SPF numbers must match the product label exactly.", "")
		}
		// --- Arabic (normalized) ---
		n := normalizeAR(text)
		if !hasArabic(n) {
			return
		}
		for _, sent := range splitSentences(n) {
			asserted := false
			if m := arAsserted(sent); m != "" {
				add("personal_attribute_health", field, m, "META-PA: asserts the viewer's condition («حبوبكِ»، «بشرتكِ المليئة بالحبوب»).",
					"صِفي المنتج: «مناسب للبشرة المعرّضة للحبوب».")
				asserted = true
			}
			if m := arMatch(sent, arSuffer); m != "" {
				if arMatch(sent, arCondition) != "" {
					add("personal_attribute_question", field, m, "META-PA: «هل تعانين...» implies the viewer's condition.", "ابدئي بفائدة المنتج.")
				} else {
					add("personal_attribute_soft", field, m, "META-PA risk: problem-question aimed at the viewer.", "ابدئي بفائدة المنتج.")
				}
				asserted = true
			}
			if cond := arMatch(sent, arCondition); !asserted && cond != "" && arSecondPerson(sent) {
				add("personal_attribute_context", field, cond, "Second person and a condition in one sentence can read as implying the viewer has it.", "«للبشرة المعرّضة للحبوب».")
			}
			if m := arMatch(sent, arAgeYou); m != "" {
				add("personal_attribute_age", field, m, "META-PA: age attribute aimed at the viewer.", "اذكري ما يفعله المنتج.")
			} else if m := arMatch(sent, arAge); m != "" {
				add("age_reference", field, m, "Age framing.", "")
			}
		}
		if m := arMatch(n, arInferior); m != "" {
			add("inferiority", field, m, "META-HW: insulting wording about appearance.", "استخدمي لغة إيجابية.")
		}
		if m := arMatch(n, arNegative); m != "" {
			add("negative_wording", field, m, "META-HW risk: negative framing.", "استخدمي لغة إيجابية.")
		}
		if m := arMatch(n, arDisease); m != "" {
			add("disease_claim", field, m, "META-HW: disease named in a cosmetic ad.", "")
		}
		if m := arMatch(n, arMedical); m != "" {
			add("medical_claim", field, m, "UAE-MC: therapeutic claim (يعالج/علاج/يشفي) needs EDE approval.", "«يهدّئ مظهر»، «يعتني بـ».")
		}
		if m := arMatch(n, arMedWord); m != "" {
			add("medical_wording", field, m, "UAE-MC: medical/clinical wording.", "")
		}
		if m := arMatch(n, arOutcome); m != "" {
			add("unrealistic_outcome", field, m, "META-HW / UAE-MC: guaranteed or instant results.", "")
		} else if m := arTimeframe.FindString(n); m != "" {
			add("unrealistic_outcome", field, m, "META-HW: results promised within a set timeframe.", "")
		}
		if m := arMatch(n, arHype); m != "" {
			add("hype_wording", field, m, "UAE-MC: exaggerated wording.", "")
		}
		if m := arMatch(n, arBeforeAft); m != "" {
			add("before_after", field, m, "META-HW: before/after.", "")
		}
		if m := arMatch(n, arWhitening); m != "" {
			add("whitening", field, m, "META-HW: whitening/bleaching.", "«إشراقة»، «نضارة»، «توهّج».")
		}
		if m := arMatch(n, arDiscountAmt); m != "" || arPercentOff.MatchString(n) {
			if m == "" {
				m = arPercentOff.FindString(n)
			}
			add("discount_amount", field, m, "The owner: no percentage or amount discounts in ads; only the coupon code and the free gift.", "«استخدمي الكود "+firstNonEmpty(o.Coupon, "GLOW")+"» / «هدية مجانية مع كل طلب».")
		} else {
			m := arMatch(n, arDiscount)
			if m == "" {
				m = arPercent.FindString(n)
			}
			if !(couponOK(n, o.Coupon) && (m == "كوبون" || m == "كود خصم")) {
				discount(&out, field, o, mode, m)
			}
		}
		if m := arMatch(n, arExclusive); m != "" {
			add("exclusivity_claim", field, m, "UAE-MC: exclusivity / superlative claim.", "«اختيار محبوب من الجمال الكوري».")
		}
	})
	// Field-level checks.
	if c.CTA != "" && !ctaAllowed[c.CTA] {
		out = append(out, LintIssue{Rule: "cta", Severity: "rewrite", Field: "cta", Match: c.CTA, Why: "Not a Meta call_to_action type we use.", Fix: "SHOP_NOW"})
	}
	if c.CTA == "GET_OFFER" && !o.PromoApproved {
		out = append(out, LintIssue{Rule: "cta_offer", Severity: "rewrite", Field: "cta", Match: c.CTA, Why: "UAE-CP: 'Get offer' implies a promotion we have no authorisation for.", Fix: "SHOP_NOW"})
	}
	if o.Lang == "ar" {
		for _, f := range []struct{ name, text string }{{"primary_text", c.PrimaryText}, {"headline", c.Headline}} {
			if f.text != "" && arScriptShare(f.text) < 0.6 {
				out = append(out, LintIssue{Rule: "arabic_language", Severity: "rewrite", Field: f.name, Why: "Arabic version must be written in Arabic (UAE ads: MSA or Emirati dialect); brand names may stay Latin."})
			}
		}
	}
	if r := []rune(c.Headline); len(r) > 40 {
		out = append(out, LintIssue{Rule: "length_headline", Severity: "warn", Field: "headline", Why: "Headlines over 40 characters get cut in the feed."})
	}
	if r := []rune(c.Description); len(r) > 30 {
		out = append(out, LintIssue{Rule: "length_description", Severity: "warn", Field: "description", Why: "Descriptions over 30 characters often do not show."})
	}
	return lintVerdict(out)
}

func lintVerdict(out []LintIssue) LintResult {
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

// arAsserted: a condition word carrying the second-person suffix (حبوبك،
// تجاعيدك), or بشرتك / وجهك within three words of a condition word
// (بشرتك المليئة بالحبوب). «للبشرة المعرضة للحبوب» has neither.
func arAsserted(sent string) string {
	toks := strings.FieldsFunc(sent, func(r rune) bool { return !unicode.IsLetter(r) })
	for i, w := range toks {
		if arYouExempt[w] {
			continue
		}
		if strings.HasSuffix(w, "ك") || strings.HasSuffix(w, "كي") {
			base := strings.TrimSuffix(strings.TrimSuffix(w, "ي"), "ك")
			if arMatch(base, arCondition) != "" {
				return w
			}
			if b := arForms(base); len(b) > 0 && (strings.HasSuffix(base, "بشرت") || strings.HasSuffix(base, "وجه") || strings.HasSuffix(base, "مسام")) {
				lo, hi := i-3, i+4
				if lo < 0 {
					lo = 0
				}
				if hi > len(toks) {
					hi = len(toks)
				}
				if m := arMatch(strings.Join(toks[lo:i], " ")+" "+strings.Join(toks[i+1:hi], " "), arCondition); m != "" {
					return w + " … " + m
				}
			}
		}
	}
	return ""
}

func discount(out *[]LintIssue, field string, o LintOptions, mode, m string) {
	if m == "" {
		return
	}
	if o.PromoApproved {
		*out = append(*out, LintIssue{Rule: "discount_disclosure", Severity: "warn", Field: field, Match: m,
			Why: "UAE-CP: show original price, discounted price and the % together; keep the DET permit reference."})
		return
	}
	*out = append(*out, LintIssue{Rule: "discount_unapproved", Severity: sevFor("discount_unapproved", mode), Field: field, Match: m,
		Why: "UAE-CP / Dubai DET: discount or sale wording needs an authorised promotion. None is set in the app.",
		Fix: "Talk about value instead, or set the promotion as approved in Settings."})
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
	{regexp.MustCompile(`(?i)\bfairer (you|skin)\b`), "radiant-looking skin"},
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
	r := Lint(c, o)
	// whatever a safe rewrite could not fix is not shippable: promote it
	for i := range r.Findings {
		if r.Findings[i].Severity == "rewrite" {
			r.Findings[i].Severity = "block"
		}
	}
	return c, lintVerdict(r.Findings), changed
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

// couponOK: the text names the owner's own coupon code (the only promo-code wording allowed).
func couponOK(text, code string) bool {
	if code == "" {
		code = "GLOW"
	}
	return strings.Contains(strings.ToUpper(text), strings.ToUpper(code))
}
