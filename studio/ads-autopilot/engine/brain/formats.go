package brain

// Every Meta ad type the brain can plan. Existence of each was checked on
// developers.facebook.com/docs/marketing-api (navigation + guides, read live
// 20 Nov 2026): Advantage+ campaigns (sales), Video & Carousel Ads, Instant
// Experiences, Collection Ads, Advantage+ Catalog Ads (incl. Localized
// Catalogs -- Arabic titles), Click to Messenger, Click to WhatsApp, Ads in
// WhatsApp Status, Partnership Ads (Instagram/Facebook), Placement Asset
// Customization, Dynamic Creative (asset_feed_spec), Flexible Ad Format,
// Reels Ads, Advantage+ Creative (degrees_of_freedom_spec), Advantage+
// Audience, Lookalike / Advantage Lookalike, Website/Engagement Custom
// Audiences. The ENGINE executor builds the Graph calls; the brain only names
// the format and its inputs. Support status per format is in
// docs/BRAIN-INTEGRATION.md.

type AdFormat struct {
	ID         string   `json:"id"`
	Name       string   `json:"name"`
	NameAR     string   `json:"name_ar"`
	Meta       string   `json:"meta"`       // the Marketing API object/fields the executor uses
	Needs      []string `json:"needs"`      // inputs the proposal must carry
	Placements []string `json:"placements"` // best placements for it
	When       string   `json:"when"`       // when the brain picks it
}

var AdFormats = []AdFormat{
	{"single_image", "Single image", "صورة واحدة", "adcreative object_story_spec.link_data{image_hash}", []string{"creative_id"}, []string{"feed", "explore"}, "A clear product shot with one message; fastest to test."},
	{"single_video", "Single video", "فيديو واحد", "advideo upload + object_story_spec.video_data{video_id}", []string{"video_id"}, []string{"reels", "stories", "feed"}, "The owner's videos and motion ads; best hook rates on Reels."},
	{"reels_stories", "Reels & Stories (9:16)", "ريلز وستوريز", "video_data + placement asset customization (asset_feed_spec customization rules) for 9:16", []string{"video_id|creative_id(9:16)"}, []string{"reels", "stories"}, "Default for this audience: women 18-45 in the GCC watch Reels/Stories most; lower CPM."},
	{"carousel", "Carousel (multi-product)", "دوّار متعدد المنتجات", "object_story_spec.link_data.child_attachments[2..10]", []string{"product_ids(2-10)"}, []string{"feed", "explore"}, "Routines (cleanser -> toner -> serum), bundles, 'shop the look'."},
	{"collection", "Collection / Instant Experience", "مجموعة / تجربة فورية", "Instant Experience canvas + collection template (catalog product_set)", []string{"product_set_id|product_ids", "cover creative_id|video_id"}, []string{"feed", "stories"}, "Browse-to-buy on mobile for a theme (Ramadan glow, summer SPF)."},
	{"catalog", "Advantage+ catalog ads", "إعلانات الكتالوج Advantage+", "product catalog + product_set_id, template_data; Localized Catalogs for Arabic", []string{"catalog_id", "product_set_id"}, []string{"feed", "reels", "stories"}, "Retargeting viewers/carts with the exact product, and broad prospecting across 900 SKUs."},
	{"flexible", "Flexible format / Dynamic Creative", "تنسيق مرن / إبداع ديناميكي", "asset_feed_spec (images, videos, bodies, titles; ad_formats AUTOMATIC_FORMAT)", []string{"creative_ids(2+)"}, []string{"all"}, "Let Meta mix 2-10 assets and texts per person once single assets have proven themselves."},
	{"partnership", "Partnership (creator) ads", "إعلانات الشراكة مع صانعات المحتوى", "Partnership Ads: boost creator media (partnership ad code) or new creative with sponsor identity", []string{"partnership_code|creator_ig_id"}, []string{"reels", "feed", "stories"}, "When the owner has a creator's permission/code; social proof without fake reviews."},
}

// NotOffered: chat ads the owner does not run (the owner, 20 Nov 2026: "we don't offer this things"). The executor
// keeps the capability; the planner never sees these formats and validate drops any proposal that names one.
var NotOffered = map[string]bool{"click_to_whatsapp": true, "click_to_messenger": true, "whatsapp_status": true}

var formatIDs = func() []string {
	var s []string
	for _, f := range AdFormats {
		s = append(s, f.ID)
	}
	return s
}()

// AudiencePreset: who an ad set targets. Advantage+ audience treats
// "suggestions" as hints Meta may go beyond; the countries and age floor are
// hard controls.
type AudiencePreset struct {
	ID          string   `json:"id"`
	Name        string   `json:"name"`
	Meta        string   `json:"meta"`
	Suggestions []string `json:"suggestions"`
	When        string   `json:"when"`
}

var AudiencePresets = []AudiencePreset{
	{"women_gcc_ar", "Arabic-speaking women 18-45, GCC (Advantage+ audience)", "targeting_automation.advantage_audience=1; geo = limits.countries; age_min 18; suggestions: genders [2], age 18-45, locales Arabic, interests skincare / K-beauty / cosmetics",
		[]string{"female", "18-45", "Arabic language", "AE, SA, KW, QA, BH, OM (owner's list)", "interests: skin care, Korean beauty, cosmetics"}, "Default prospecting for Arabic creative."},
	{"women_uae_en", "English-speaking women 18-45, UAE (Advantage+ audience)", "advantage_audience=1; geo AE; suggestions female 18-45, English", []string{"female", "18-45", "English", "AE"}, "English creative for residents/expats."},
	{"broad", "Broad (Advantage+ audience, no suggestions)", "advantage_audience=1; geo + age floor only", nil, "Once creative is proven: let Andromeda find people from the creative itself."},
	{"retarget_viewers", "Retarget: product viewers (pixel, 14 days)", "website custom audience: ViewContent 14d, exclude Purchase 14d", nil, "Catalog ads with the exact product viewed."},
	{"retarget_cart", "Retarget: cart abandoners (pixel, 7 days)", "website custom audience: AddToCart/InitiateCheckout 7d, exclude Purchase 7d", nil, "Highest intent; small budget, catalog or carousel."},
	{"retarget_engagers", "Retarget: video viewers & IG/FB engagers (30 days)", "engagement custom audiences: 50% video views, IG profile engagers", nil, "Second touch for people who watched the videos."},
	{"lookalike_buyers", "Lookalike / Advantage lookalike of buyers", "lookalike 1-3% of Purchase 180d (country = each GCC market)", nil, "Seed once the pixel has 100+ purchasers."},
	{"existing_customers", "Existing customers (separate ad set)", "customer-list custom audience; the other ad sets exclude it", nil, "Meta's own guidance for new Advantage+ sales: one ad set for existing, one excluding them."},
}

var audienceIDs = func() []string {
	var s []string
	for _, a := range AudiencePresets {
		s = append(s, a.ID)
	}
	return s
}()

var placementIDs = []string{"reels", "stories", "feed", "explore", "advantage_placements"}

var destinationIDs = []string{"website"}

// CostStrategy is the fixed playbook for low CPC/CPM with women in the GCC;
// the planner reads it, the UI shows it on the Plan screen.
var CostStrategy = []string{
	"Creative-first targeting: broad Advantage+ audience with suggestions (female, 18-45, Arabic, GCC, skincare interests); Meta's Andromeda finds the audience from the creative, so the lever is many genuinely different creatives, not narrow interests.",
	"Placements: Reels and Stories first (9:16) -- the cheapest attention for this audience; keep Advantage+ placements on so feed fills in, with 9:16 and 4:5 assets supplied for each slot (placement asset customization).",
	"Frequency: prospecting ads refresh before 7-day frequency passes ~2.5; retargeting capped near 3 per 7 days; fatigue rules pause and replace.",
	"Cost: watch CPM (attention cost), CTR (hook), CPC (both) every cycle; a creative with CTR credibly below the account's is cut early before it wastes budget.",
	"Bidding: highest volume with the owner's daily cap while weekly purchases are low; cost cap only once purchases are stable.",
}
