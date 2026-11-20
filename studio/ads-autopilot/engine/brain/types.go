// The brain's own working types. The engine's contract types live in
// engine/model; adapter.go converts model.CycleInput -> CycleInput and
// CycleOutput -> model.CycleOutput (plus the brain's extra outputs, served by
// the brain's own routes under /api/brain/x/). Money is AED everywhere here;
// fils exist only in the engine's executor.
package brain

import (
	"time"

	"kaa/model"
)

// Limits are the owner's hard limits (GET/PUT /api/limits). Zero daily or
// monthly AED means "no limit set", which means nothing launches.
type Limits struct {
	DailyAED         float64  `json:"daily_aed"`
	MonthlyAED       float64  `json:"monthly_aed"`
	MaxBudgetStepPct float64  `json:"max_budget_step_pct"` // default 20
	CooldownHours    float64  `json:"cooldown_hours"`      // default 72
	MaxActiveAds     int      `json:"max_active_ads"`
	Countries        []string `json:"countries"`
	Autopilot        string   `json:"autopilot"`  // off | shadow | auto
	AdWording        string   `json:"ad_wording"` // BRAIN addition: strict | balanced (default) | relaxed -- Settings > Limits > Ad wording rules
}

// Account is the Meta ad account as the engine read it.
type Account struct {
	ID          string  `json:"id"`
	Name        string  `json:"name"`
	Currency    string  `json:"currency"`
	Timezone    string  `json:"timezone"`
	SpendCapAED float64 `json:"spend_cap"`
}

// Campaign > AdSet > Ad, as the engine last read them from Meta.
type Campaign struct {
	ID             string    `json:"id"`
	Name           string    `json:"name"`
	Status         string    `json:"status"` // ACTIVE | PAUSED | ARCHIVED ...
	Objective      string    `json:"objective"`
	DailyBudgetAED float64   `json:"daily_budget_aed"` // campaign budget (Advantage+), 0 if ad-set budgets
	MadeByApp      bool      `json:"made_by_app"`
	CreatedAt      time.Time `json:"created_at"`
	AdSets         []AdSet   `json:"adsets"`
}

type AdSet struct {
	ID                  string    `json:"id"`
	CampaignID          string    `json:"campaign_id"`
	Name                string    `json:"name"`
	Status              string    `json:"status"`
	DailyBudgetAED      float64   `json:"daily_budget_aed"`
	BidAmountAED        float64   `json:"bid_amount_aed"`
	Countries           []string  `json:"countries"`
	Language            string    `json:"lang"`           // ar | en | "" (mixed)
	LearningStage       string    `json:"learning_stage"` // LEARNING | SUCCESS | FAIL (learning limited)
	LastSignificantEdit time.Time `json:"last_significant_edit"`
	LastBudgetChange    time.Time `json:"last_budget_change"`
	CreatedAt           time.Time `json:"created_at"`
	Ads                 []Ad      `json:"ads"`
}

type Ad struct {
	ID             string    `json:"id"`
	AdSetID        string    `json:"adset_id"`
	Name           string    `json:"name"`
	Status         string    `json:"status"`
	EffectiveState string    `json:"effective_status"` // ACTIVE, DISAPPROVED, WITH_ISSUES ...
	CreativeID     string    `json:"creative_id"`      // local library id
	Format         string    `json:"format"`           // BRAIN addition: AdFormats id the ad was made as
	Language       string    `json:"lang"`
	ReviewFeedback string    `json:"review_feedback"`
	CreatedAt      time.Time `json:"created_at"`
	LastChange     time.Time `json:"last_change"`
}

// MetricRow is one object on one day (attribution 7d_click/1d_view, AED).
// The engine flags the last 2 days "settling" (purchases still arriving).
type MetricRow struct {
	Date        string  `json:"date"`  // YYYY-MM-DD in the account timezone
	Level       string  `json:"level"` // campaign | adset | ad
	ObjectID    string  `json:"object_id"`
	SpendAED    float64 `json:"spend"`
	Impressions int64   `json:"impressions"`
	Reach       int64   `json:"reach"`
	Clicks      int64   `json:"clicks"` // link clicks
	Purchases   int64   `json:"purchases"`
	RevenueAED  float64 `json:"revenue"`
	Frequency   float64 `json:"frequency"`
	Settling    bool    `json:"settling"`
	Placement   string  `json:"placement,omitempty"` // BRAIN addition: set on placement-breakdown rows (reels, stories, feed...); totals rows leave it empty
}

// ShopSnapshot is what the shop told the engine (v0: the public feed only).
type ShopSnapshot struct {
	Products     []Product  `json:"products"`
	Orders       []OrderDay `json:"orders"`        // empty until the website ships the read-only orders endpoint
	OrdersSource string     `json:"orders_source"` // "none" until then
	FetchedAt    time.Time  `json:"fetched_at"`
}

type Product struct {
	ID          string   `json:"id"`
	Title       string   `json:"title"`
	Brand       string   `json:"brand"`
	Type        string   `json:"type"` // "Skincare > Face Cleansers"
	PriceAED    float64  `json:"price"`
	SalePrice   float64  `json:"sale_price"`
	InStock     bool     `json:"in_stock"`
	Stock       int      `json:"stock"`      // -1 unknown
	MarginPct   float64  `json:"margin_pct"` // 0 = unknown
	Image       string   `json:"image"`
	Images      []string `json:"images"`
	URL         string   `json:"url"`
	Description string   `json:"description"`
}

type OrderDay struct {
	Date       string  `json:"date"`
	Orders     int     `json:"orders"`
	NetRevenue float64 `json:"net_revenue"` // AED, incl. VAT, after discounts
	Refunds    float64 `json:"refunds"`
	NewCust    int     `json:"new_customers"`
}

// Creative is one library item (an AR or EN version of an ad).
type Creative struct {
	ID          string    `json:"id"`
	PairID      string    `json:"pair_id"` // AR and EN versions of one idea share it
	Lang        string    `json:"lang"`    // ar | en
	Format      string    `json:"format"`  // 1:1 | 4:5 | 9:16
	Headline    string    `json:"headline"`
	PrimaryText string    `json:"primary_text"`
	Description string    `json:"description"`
	CTA         string    `json:"cta"`
	Link        string    `json:"link"`
	ProductIDs  []string  `json:"product_ids"`
	Angle       string    `json:"angle"`
	Template    string    `json:"template"` // design family id
	Source      string    `json:"source"`   // brain | owner
	ImageURL    string    `json:"image_url"`
	CreatedAt   time.Time `json:"created_at"`
	LintOK      bool      `json:"lint_ok"`
	Approved    bool      `json:"approved,omitempty"` // the owner made it with Claude and said Run it
	MetaID      string    `json:"meta_creative_id,omitempty"`
}

// Research is the brain's market memory: dated notes with sources.
type Research struct {
	UpdatedAt time.Time      `json:"updated_at"`
	Notes     []ResearchNote `json:"notes"`
}

type ResearchNote struct {
	ID       string    `json:"id"`
	Topic    string    `json:"topic"` // trend | ingredient | competitor | arabic_audience | format | hook | season
	Title    string    `json:"title"`
	TitleAR  string    `json:"title_ar"`
	Body     string    `json:"body"`
	BodyAR   string    `json:"body_ar"`
	Angles   []string  `json:"angles"` // ad angles this suggests
	Sources  []Source  `json:"sources"`
	Date     time.Time `json:"date"`
	Strength float64   `json:"strength"` // 0..1 how well sourced
}

type Source struct {
	URL   string `json:"url"`
	Title string `json:"title"`
	Date  string `json:"date,omitempty"`
}

type ResearchInput struct {
	Now      time.Time    `json:"now"`
	Shop     ShopSnapshot `json:"shop"`
	Previous Research     `json:"previous"`
	Focus    []string     `json:"focus"` // optional topics to refresh
}

// LogEntry is one line of the engine's append-only audit log.
type LogEntry struct {
	ID      string         `json:"id"`
	Time    time.Time      `json:"time"`
	Kind    string         `json:"kind"` // proposal | check | execution | meta_response | brain.learning | cycle ...
	Object  string         `json:"object"`
	Summary string         `json:"summary"`
	Outcome string         `json:"outcome"` // executed | rejected | shadow | failed | win | loss ...
	Data    map[string]any `json:"data,omitempty"`
}

type CycleInput struct {
	Now                time.Time        `json:"now"`
	Limits             Limits           `json:"limits"`
	Account            Account          `json:"account"`
	Tree               []Campaign       `json:"tree"`
	Metrics            []MetricRow      `json:"metrics"`
	Shop               ShopSnapshot     `json:"shop"`
	Creatives          []Creative       `json:"creatives"`
	Research           Research         `json:"research"`
	History            []LogEntry       `json:"history"`
	Profile            *LearningProfile `json:"profile,omitempty"`             // BRAIN addition: this account's learned profile (brain builds it when nil)
	Portfolio          *LearningProfile `json:"portfolio,omitempty"`           // BRAIN addition: the cross-account profile
	Trending           *TrendingReport  `json:"trending,omitempty"`            // BRAIN: trending products matched to the shop
	PortfolioLearnings any              `json:"portfolio_learnings,omitempty"` // the engine cross-account aggregates
	Videos             []VideoAsset     `json:"videos"`                        // BRAIN addition: the owner's ad videos with analysis + results
	// the owner's Playbook (the shop above is already filtered by it), the ranked candidates and his job notes
	Playbook *model.Playbook       `json:"playbook,omitempty"`
	Ranked   []model.RankedProduct `json:"ranked,omitempty"`
	JobNotes []string              `json:"job_notes,omitempty"`
	Lessons  []Lesson              `json:"lessons,omitempty"` // learn.go: what wins / loses in this account
}

// CycleOutput: Plan, Proposals and Report are the contract's. Verdicts,
// Designs, CopySets and Learnings are BRAIN additions (fields added, none
// renamed): the UI shows Verdicts as "why each ad was kept/paused/scaled",
// renders Designs to PNG and POSTs them to /api/creatives, and the engine logs
// Learnings as kind "brain.learning" so the next cycle's History carries them.
type CycleOutput struct {
	Plan      Plan       `json:"plan"`
	Proposals []Proposal `json:"proposals"`
	Report    string     `json:"report"` // the daily summary, AR + EN

	Verdicts  []Verdict        `json:"verdicts"`
	Designs   []DesignSpec     `json:"designs"`
	CopySets  []CopySet        `json:"copy_sets"`
	Learnings []string         `json:"learnings"`
	Economics Economics        `json:"economics"`
	Model     string           `json:"model"` // which model planned ("rules-only" without Claude)
	Usage     Usage            `json:"usage"`
	Profile   *LearningProfile `json:"profile,omitempty"` // the profile this cycle planned with
	Dropped   []string         `json:"dropped"`           // planner proposals the brain refused, with the reason
	// CreationNeeded: copy requests left for the owner (creation mode "with me in Claude"), see creation.go
	CreationNeeded []CopyRequest `json:"creation_needed,omitempty"`
	BatchDate      string        `json:"batch_date,omitempty"` // the next weekly batch day (YYYY-MM-DD), for the job queue
}

type Proposal struct {
	ID         string         `json:"id"`
	Kind       string         `json:"kind"` // create_campaign | create_adset | create_ad | pause | resume | set_budget | set_bid | upload_creative | archive
	Target     string         `json:"target"`
	Params     map[string]any `json:"params"` // budgets in AED (float), never fils
	Why        string         `json:"why"`
	Confidence float64        `json:"confidence"`
	Evidence   []string       `json:"evidence"`
}

// Plan is what the autopilot intends: this week's tests, the budget split,
// seasonal pushes. Every field is shown on the Plan screen.
type Plan struct {
	Week        string       `json:"week"` // ISO week the plan is for, e.g. 2026-W47
	Summary     string       `json:"summary"`
	SummaryAR   string       `json:"summary_ar"`
	Tests       []PlanTest   `json:"tests"`
	Allocation  []Allocation `json:"allocation"`
	Seasonal    []SeasonPush `json:"seasonal"`
	Watch       []string     `json:"watch"`
	GeneratedAt time.Time    `json:"generated_at"`
}

type PlanTest struct {
	Name       string   `json:"name"`
	Hypothesis string   `json:"hypothesis"`
	Lang       string   `json:"lang"`
	Angle      string   `json:"angle"`
	ProductIDs []string `json:"product_ids"`
	Template   string   `json:"template"`
	BudgetAED  float64  `json:"budget_aed"` // per day
	Format     string   `json:"format"`     // BRAIN addition: an AdFormats id
	Audience   string   `json:"audience"`   // BRAIN addition: an AudiencePresets id
	Status     string   `json:"status"`     // planned | running | won | lost
}

type Allocation struct {
	Bucket   string  `json:"bucket"` // e.g. "Arabic prospecting", "English prospecting", "Creative tests", "Retargeting"
	SharePct float64 `json:"share_pct"`
	DailyAED float64 `json:"daily_aed"`
	Why      string  `json:"why"`
}

type SeasonPush struct {
	Name     string `json:"name"`
	NameAR   string `json:"name_ar"`
	Start    string `json:"start"`
	End      string `json:"end"`
	Action   string `json:"action"`
	Approx   bool   `json:"approx"`
	PrepFrom string `json:"prep_from"`
}

// Verdict is the rules layer's judgement of one object, with the numbers.
type Verdict struct {
	Object   string   `json:"object"`
	Level    string   `json:"level"`
	Verdict  string   `json:"verdict"` // keep | kill | scale | cut | watch | learning | fatigued | settling | no_data
	Why      string   `json:"why"`
	PWinTgt  float64  `json:"p_beats_target"`    // P(CPA < target CPA)
	PWinBE   float64  `json:"p_beats_breakeven"` // P(CPA < break-even CPA)
	SpendAED float64  `json:"spend"`
	Purch    int64    `json:"purchases"`
	CPA      float64  `json:"cpa"`
	ROAS     float64  `json:"roas"`
	CTR      float64  `json:"ctr"`
	Signals  []string `json:"signals,omitempty"`
}

type Usage struct {
	InputTokens      int     `json:"input_tokens"`
	OutputTokens     int     `json:"output_tokens"`
	CacheReadTokens  int     `json:"cache_read_tokens"`
	CacheWriteTokens int     `json:"cache_write_tokens"`
	WebSearches      int     `json:"web_searches"`
	CostUSD          float64 `json:"cost_usd"`
}

// VideoAsset is one of the owner's ad videos (BRAIN addition; engine/model
// adds it and CycleInput.Videos). The original file is copied once into
// "~/KBB Ads Autopilot Data/videos/" and referenced by Path; only small JPEG
// frames/thumbnails are kept beside it.
type VideoAsset struct {
	ID          string         `json:"id"`
	Name        string         `json:"name"` // the owner's file name
	Path        string         `json:"path"` // inside the data folder
	Bytes       int64          `json:"bytes"`
	Thumb       string         `json:"thumb"`         // small JPEG path/URL
	Status      string         `json:"status"`        // new | past | running | retired
	MetaVideoID string         `json:"meta_video_id"` // advideo id once uploaded / matched
	MetaAdIDs   []string       `json:"meta_ad_ids"`   // past ads matched by name/creative
	Results     *VideoResults  `json:"results"`       // from Meta (matched) or typed by the owner
	Analysis    *VideoAnalysis `json:"analysis"`
	AddedAt     time.Time      `json:"added_at"`
}

type VideoResults struct {
	Source      string  `json:"source"` // meta | owner
	SpendAED    float64 `json:"spend"`
	Impressions int64   `json:"impressions"`
	Clicks      int64   `json:"clicks"`
	Purchases   int64   `json:"purchases"`
	RevenueAED  float64 `json:"revenue"`
	ThruPlays   int64   `json:"thruplays"`
	Views3s     int64   `json:"views_3s"`
	Note        string  `json:"note"` // owner's own words ("did well in Ramadan")
}

// VideoAnalysis: the numbers and checks are computed in code from the
// browser's measurements; Claude (vision on a handful of frames) reports what
// it SEES (hook, text, products, language, angle). Score is code's.
type VideoAnalysis struct {
	DurationS     float64        `json:"duration_s"`
	Width         int            `json:"width"`
	Height        int            `json:"height"`
	Aspect        string         `json:"aspect"` // 9:16 | 4:5 | 1:1 | 16:9 | other
	Placements    []string       `json:"placements"`
	Hook          string         `json:"hook"`
	HookStrength  float64        `json:"hook_strength"` // 0..10, Claude
	Language      string         `json:"language"`      // ar | en | mixed | none (on-screen; audio not transcribed)
	OnScreenText  []string       `json:"on_screen_text"`
	Captions      bool           `json:"captions"`
	ProductsShown []VideoMatch   `json:"products_shown"`
	FirstProductS float64        `json:"first_product_s"` // seconds until a product is visible (-1 never)
	Angle         string         `json:"angle"`           // routine | ingredient | before_after_style | testimonial | offer | texture | unboxing | other
	Issues        []VideoIssue   `json:"issues"`
	Suggestions   []string       `json:"suggestions"`
	Trims         []VideoTrim    `json:"trims"`
	Score         int            `json:"score"` // 0..100
	ScoreParts    map[string]int `json:"score_parts"`
	Summary       string         `json:"summary"`
	SummaryAR     string         `json:"summary_ar"`
	Model         string         `json:"model"`
	AnalysedAt    time.Time      `json:"analysed_at"`
}

type VideoMatch struct {
	ProductID  string  `json:"product_id"`
	Title      string  `json:"title"`
	Confidence float64 `json:"confidence"`
}

type VideoIssue struct {
	Code     string `json:"code"`
	Severity string `json:"severity"` // block | fix | tip
	Detail   string `json:"detail"`
}

type VideoTrim struct {
	FromS float64 `json:"from_s"`
	ToS   float64 `json:"to_s"`
	Why   string  `json:"why"`
}

// DesignSpec asks the UI to render one ad image (canvas, in the app) and POST
// the PNG to /api/creatives with design_id = ID. Original art only: the
// shop's own product photos plus our templates.
type DesignSpec struct {
	ID         string   `json:"id"`
	PairID     string   `json:"pair_id"`
	Lang       string   `json:"lang"`     // ar | en (ar renders RTL)
	Template   string   `json:"template"` // hero | glow | ingredient | offer | routine | editorial | petals | texture | qa | collection
	Formats    []string `json:"formats"`  // 1:1 | 4:5 | 9:16
	ProductIDs []string `json:"product_ids"`
	Images     []string `json:"images"` // product photo URLs (the engine proxies them same-origin)
	Headline   string   `json:"headline"`
	Sub        string   `json:"sub"`
	Bullets    []string `json:"bullets"`
	Badge      string   `json:"badge"` // short badge text, never a % discount without an approved promo
	CTA        string   `json:"cta"`
	Price      string   `json:"price"`
	Angle      string   `json:"angle"`
	CopyID     string   `json:"copy_id"`
	Numerals   string   `json:"numerals,omitempty"` // ar only: "arab" (٠١٢) | "latn" (012); the price chip follows it
	Category   string   `json:"category,omitempty"` // product type, for art direction
	Scene      string   `json:"scene,omitempty"`    // the brain's art-direction prompt for an optional AI background
	// Create with Claude (create.go)
	Palette     []string `json:"palette,omitempty"`      // 2-4 hex colours Claude chose (empty = from the product photo)
	PrimaryText string   `json:"primary_text,omitempty"` // the ad text that runs with the picture
	Idea        int      `json:"idea,omitempty"`         // 1-based idea (variant) of its session
	Motion      bool     `json:"motion,omitempty"`       // also render a 9:16 motion version
	Photo       bool     `json:"photo,omitempty"`        // the owner's own photo replaces the product photo
}

// CopySet is one product/angle's copy in one language, linted.
type CopySet struct {
	ID          string     `json:"id"`
	PairID      string     `json:"pair_id"`
	Lang        string     `json:"lang"`
	ProductIDs  []string   `json:"product_ids"`
	Angle       string     `json:"angle"`
	Hooks       []string   `json:"hooks"`
	PrimaryText string     `json:"primary_text"`
	Headline    string     `json:"headline"`
	Description string     `json:"description"`
	CTA         string     `json:"cta"` // Meta call_to_action type, e.g. SHOP_NOW
	Lint        LintResult `json:"lint"`
	Rewritten   bool       `json:"rewritten"`
	VideoID     string     `json:"video_id,omitempty"` // copy written for one of the owner's videos
}

// Economics: unit economics the rules layer judged against this cycle.
type Economics struct {
	AOV             float64 `json:"aov"`
	AOVSource       string  `json:"aov_source"` // orders | feed_median | default
	MarginPct       float64 `json:"margin_pct"`
	MarginSource    string  `json:"margin_source"`
	Contribution    float64 `json:"contribution"`
	BreakEvenCPA    float64 `json:"breakeven_cpa"`
	BreakEvenROAS   float64 `json:"breakeven_roas"`
	TargetCPA       float64 `json:"target_cpa"`
	TargetROAS      float64 `json:"target_roas"`
	SeasonTolerance float64 `json:"season_tolerance"`
	Season          string  `json:"season"`
}

// LintResult is the policy linter's verdict on one piece of copy.
type LintResult struct {
	OK       bool        `json:"ok"`
	Blocked  bool        `json:"blocked"`
	Findings []LintIssue `json:"findings"`
}

type LintIssue struct {
	Rule     string `json:"rule"`
	Severity string `json:"severity"` // block | rewrite | warn
	Match    string `json:"match"`
	Field    string `json:"field"`
	Why      string `json:"why"`
	Fix      string `json:"fix,omitempty"`
}
