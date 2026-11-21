package brain

// agency.go — "Our playbook": the low-budget media-buying rules big agencies run, as the app's default (the owner,
// 20 Nov 2026: "the system should be well trained about the high end meta campaigns and for best results / sales in
// low budget, which the big agencies do"). Each rule says what it does, why, where it is enforced, and whether it is
// Meta's own documentation ("meta") or practitioner practice ("practice", a heuristic, labelled as such).
// The same list is in the planner's system prompt (stable text: the cache prefix holds) and on Strategy → Our playbook.

import "strings"

type AgencyRule struct {
	ID       string `json:"id"`
	Rule     string `json:"rule"`
	RuleAR   string `json:"rule_ar"`
	Why      string `json:"why"`
	Source   string `json:"source"`   // meta | practice
	Ref      string `json:"ref"`      // where it was read (meta) or "practitioner heuristic"
	Enforced string `json:"enforced"` // code | planner | both
}

var AgencyPlaybook = []AgencyRule{
	{"consolidate", "Few campaigns and ad sets: one Advantage+ sales campaign for new customers and one catalog retargeting campaign. Number of prospecting ad sets = budget ÷ (target CPA × 50 ÷ 7), at least 1, at most 3.",
		"حملات ومجموعات قليلة: حملة مبيعات Advantage+ واحدة وحملة كتالوج واحدة لإعادة الاستهداف؛ عدد المجموعات = الميزانية ÷ (تكلفة الطلب المستهدفة × ٥٠ ÷ ٧).",
		"An ad set leaves Meta's learning phase after about 50 results in the week after its last significant edit; splitting a small budget keeps every ad set learning forever.",
		"meta", "facebook.com/business/help/112167992830700 (learning phase); Advantage+ sales structure: developers.facebook.com …/marketing-api/advantage-campaigns", "code"},
	{"volume", "More ads, not more campaigns: at the Active level (default) 8–12 live ads in the prospecting campaign, a test batch of 4–6 new concepts every Monday and Thursday, losers refilled the same day, winners given a new hook within 48 h. A 2nd prospecting campaign only when the budget gives it ~50 purchases a week (target CPA × 50 ÷ 7 per day) and that much is free under the daily limit; otherwise its concepts run as ads in the main campaign.",
		"إعلانات أكثر لا حملات أكثر: في المستوى النشِط ٨–١٢ إعلاناً نشطاً في حملة الجذب، دفعة اختبار من ٤–٦ أفكار كل اثنين وخميس، استبدال الخاسر في اليوم نفسه، وجملة جذب جديدة للرابح خلال ٤٨ ساعة. حملة ثانية فقط إذا كفت الميزانية لنحو ٥٠ طلباً أسبوعياً.",
		"More distinct creatives give Meta's delivery more to match people with, while every campaign still needs ~50 results a week to learn; more campaigns at a small budget would each stay in learning.",
		"practice", "practitioner heuristic (learning phase: facebook.com/business/help/112167992830700)", "code"},
	{"tiers", "Budget split by tier: under the small-budget line (default AED 150/day) ~80% prospecting / ~20% retargeting and existing customers handled by exclusion only; above it 70 / 20 / 10 with a capped existing-customer ad set.",
		"تقسيم الميزانية حسب الشريحة: أقل من ١٥٠ درهم يومياً ٨٠٪ لجذب عملاء جدد و٢٠٪ لإعادة الاستهداف؛ وفوقها ٧٠/٢٠/١٠.",
		"At small budgets every dirham must find new buyers; a separate existing-customer line only pays once there is budget to feed it.",
		"practice", "practitioner heuristic", "code"},
	{"existing", "Existing customers: excluded from prospecting (custom-audience exclusion, which Advantage+ audience never expands); at the growth tier a separate ad set for them with a daily spend cap inside the same campaign.",
		"العملاء الحاليون يُستبعدون من جذب العملاء الجدد؛ وفي الشريحة الأكبر لهم مجموعة مستقلة بسقف إنفاق يومي داخل الحملة نفسها.",
		"Meta's own way to replace the retired existing_customer_budget_percentage: two ad sets, one including the customers (not relaxed) with daily_spend_cap, one excluding them.",
		"meta", "developers.facebook.com …/marketing-api/advantage-campaigns (Replicate existing_customer_budget_percentage); …/advantage-audience (exclusions are never expanded)", "code"},
	{"retarget_freq", "Retargeting is scaled only while its 7-day frequency stays under ~3; above that it holds.",
		"لا تُزاد ميزانية إعادة الاستهداف إلا إذا بقي التكرار الأسبوعي أقل من ٣ تقريباً.",
		"A small warm audience saturates fast; more budget then buys the same people again at a higher cost.",
		"practice", "practitioner heuristic", "code"},
	{"creative_targeting", "Creative is the targeting: many genuinely different concepts per ad set (the Activity level sets how many: 8–12 live at Active); new concepts are tested inside the main campaign (flexible ads), not in a separate testing campaign; tired ads are replaced.",
		"الإعلان هو الاستهداف: أفكار كثيرة ومختلفة في كل مجموعة (مستوى النشاط يحدد العدد: ٨–١٢ في المستوى النشِط)؛ تُختبر الأفكار الجديدة داخل الحملة الرئيسية لا في حملة اختبار منفصلة.",
		"With broad audiences Meta's delivery chooses people from the creative; a separate test campaign at low budget never gets enough results to read.",
		"practice", "practitioner heuristic (flexible format: developers.facebook.com …/ad-creative/asset-feed-spec)", "both"},
	{"broad", "Broad + Advantage+ audience over interests: countries, age floor and gender as suggestions; no language restriction.",
		"جمهور واسع مع Advantage+ بدل الاهتمامات: الدول والحد الأدنى للعمر، والجنس كاقتراح؛ بدون تقييد اللغة.",
		"Meta treats location, minimum age, language and exclusions as hard limits and expands the rest; restricting language would cut real buyers.",
		"meta", "developers.facebook.com …/audiences/reference/targeting-expansion/advantage-audience", "code"},
	{"bidding", "Lowest cost (no cap) until an ad set has ~50 purchases a week; only then is a cost cap suggested (never applied without you).",
		"أقل تكلفة بدون سقف حتى ٥٠ طلباً أسبوعياً تقريباً؛ بعدها يُقترح سقف للتكلفة فقط.",
		"A cost cap on thin data starves delivery; lowest cost lets the system learn first.",
		"practice", "practitioner heuristic (bid strategies: developers.facebook.com …/bidding/overview/bid-strategy)", "code"},
	{"attribution", "Attribution 7-day click / 1-day view on every sales ad set; decisions only on settled days (the last 2 are still arriving).",
		"الإسناد ٧ أيام بعد النقر / يوم بعد المشاهدة؛ والقرارات على الأيام المكتملة فقط.",
		"The standard window Meta optimises and reports on; judging the last 2 days undercounts purchases.",
		"meta", "developers.facebook.com …/reference/ad-account/adsets (attribution_spec)", "code"},
	{"no_learning_edits", "Never edit an ad set in learning; scale at most 20% every 3 days; stop on statistics, not on a bad day.",
		"لا تعديل أثناء مرحلة التعلّم؛ زيادة ٢٠٪ كحد أقصى كل ٣ أيام؛ الإيقاف بالإحصاء لا بيوم سيئ.",
		"A significant edit restarts learning; big jumps reset delivery; one bad day is noise at these volumes.",
		"meta", "facebook.com/business/help/112167992830700 (significant edits restart learning); step size and cooldown: practitioner heuristic", "code"},
	{"reels_first", "Reels / Stories 9:16 first, Arabic hook in the first 2 seconds, UGC style, the price and offer clear; landing on the product page, never the home page.",
		"ريلز وستوريز ٩:١٦ أولاً، جملة جذب عربية في أول ثانيتين، أسلوب محتوى المستخدمين، السعر واضح، والرابط لصفحة المنتج.",
		"The cheapest attention for this audience is vertical video; a product-page landing removes a click before the purchase.",
		"practice", "practitioner heuristic", "both"},
	{"catalog_sets", "Catalog retargeting shows only in-stock products above your margin floor (product sets), people who viewed or carted in 14 days and did not buy.",
		"إعلانات الكتالوج تعرض المنتجات المتوفرة فوق حد الربح فقط، لمن شاهد أو أضاف للسلة خلال ١٤ يوماً ولم يشترِ.",
		"Dynamic ads with product_audience_specs reach exactly those people with exactly that product.",
		"meta", "developers.facebook.com …/marketing-api/advantage-catalog-ads/get-started (product_audience_specs); …/product-catalog/product_sets (filters)", "code"},
	{"benefit_first", "Benefit-first copy, Arabic first: hook in 2 s → the product's benefit for a skin type or routine step → the hero ingredient and the Korean-skincare angle → why buy now from K-Beauty Bliss (authentic, UAE delivery, the price) → a clear call to shop.",
		"نص يبدأ بالفائدة وبالعربية أولاً: جملة جذب في ثانيتين ← فائدة المنتج لنوع البشرة أو خطوة الروتين ← المكوّن الأساسي والأسلوب الكوري ← سبب الشراء الآن من K-Beauty Bliss (أصلي، توصيل داخل الإمارات، السعر) ← دعوة واضحة للشراء.",
		"Shoppers buy what the product does for them and why from this shop; benefits stay inside Balanced wording (helps / visibly / feel, never timed results or cures) and the linter checks every line.",
		"practice", "practitioner heuristic (the owner's direction, 20 Nov 2026)", "both"},
	{"seasonal", "Seasonal ramp: creatives ready 2 weeks before the date, budget raised gradually (≤ 20% steps) into it.",
		"المواسم: الإعلانات جاهزة قبل أسبوعين، والميزانية ترتفع تدريجياً.",
		"Peaks raise CPMs; creatives that already exited learning before the peak buy cheaper.",
		"practice", "practitioner heuristic", "both"},
}

// ownerRule: how the planner treats the owner's Playbook (stable text).
const ownerRule = `OWNER PLAYBOOK -- ALWAYS OBEYED, ABOVE EVERYTHING ELSE BELOW
- input owner_playbook.instructions_verbatim are the owner's own words (Arabic or English). Follow them in the plan, the proposals, the tests and every copy_request. If one conflicts with a default here, the owner wins unless it would break a hard limit or Meta policy; then say so in the report.
- The products you are given are ALREADY filtered by his Avoid list and his stock / margin floors: never name any other product id. owner_playbook.focus_pinned products must be considered in every batch.
- products are ranked fresh-first (trending now + new arrivals, weighted by his "prefer new & trending" setting) plus recent sales momentum, margin and stock, minus time already in ads; each carries its "why". Old evergreen items earn a slot only when their own numbers win.
- Use only his countries, age range, gender and ad languages. owner_playbook.notes_on_upcoming_jobs are his notes on queued jobs: honour them.`

// AgencyPrompt: the playbook as the planner reads it (stable, no numbers that change per cycle).
func AgencyPrompt() string {
	var b strings.Builder
	b.WriteString("OUR LOW-BUDGET PLAYBOOK (agency practice; the code enforces most of it -- plan inside it):\n")
	for _, r := range AgencyPlaybook {
		b.WriteString("- " + r.Rule + " (why: " + r.Why + ")\n")
	}
	b.WriteString("- Chat ads (Click to WhatsApp / Messenger / WhatsApp Status) are NOT offered by this shop: never propose them.\n")
	return b.String()
}
