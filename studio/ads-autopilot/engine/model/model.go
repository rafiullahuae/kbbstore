// Package model holds the types shared by the engine (LANE ENGINE) and the brain (LANE BRAIN), exactly as named in
// studio/ads-autopilot/CONTRACT.md. BRAIN may ADD fields; nobody renames one. Money in this package is always AED
// (float). Fils (AED minor units, what Meta's write API takes) exist only inside engine/executor and engine/meta.
package model

import (
	"context"
	"encoding/json"
	"time"
)

// ── the brain ──

// Brain is implemented by engine/brain (LANE BRAIN) and, until integration, by engine/brainstub. It never calls Meta:
// it returns Proposals, and only the engine's executor writes to Meta after its checks.
type Brain interface {
	Cycle(ctx context.Context, in CycleInput) (CycleOutput, error)    // the planner, run by the scheduler
	Research(ctx context.Context, in ResearchInput) (Research, error) // market/trend/competitor research (web search)
}

// Copywriter is optional: a Brain that also implements it serves POST /api/brain/copy.
type Copywriter interface {
	Copy(ctx context.Context, in CopyInput) (CopyOutput, error)
}

// BrainDeps is what the engine hands a brain when it builds it: a Claude client (never a Meta one) and a logger whose
// lines land in the audit log (secrets are redacted there).
type BrainDeps struct {
	LLM     LLM
	Log     func(kind, summary string, data any)
	DataDir string // the brain may keep its own notes under DataDir/brain/
}

type CycleInput struct {
	Now       time.Time
	Limits    Limits
	Account   Account
	Tree      []Campaign  // campaigns > adsets > ads, status, budgets (AED), learning state, review feedback
	Metrics   []MetricRow // per object per day, attribution 7d_click/1d_view, AED; last 2 days flagged "settling"
	Shop      ShopSnapshot
	Creatives []Creative // library incl. unused
	Research  Research   // latest notes
	History   []LogEntry // recent decisions + outcomes, so it learns
}

type CycleOutput struct {
	Plan      Plan
	Proposals []Proposal
	Report    string // the daily-summary text, AR+EN
}

// Proposal kinds (the executor's allow-list is exactly this set).
const (
	KindCreateCampaign = "create_campaign"
	KindCreateAdSet    = "create_adset"
	KindCreateAd       = "create_ad"
	KindPause          = "pause"
	KindResume         = "resume"
	KindSetBudget      = "set_budget"
	KindSetBid         = "set_bid"
	KindUploadCreative = "upload_creative"
	KindArchive        = "archive"
)

var ProposalKinds = []string{KindCreateCampaign, KindCreateAdSet, KindCreateAd, KindPause, KindResume, KindSetBudget, KindSetBid, KindUploadCreative, KindArchive}

type Proposal struct {
	ID         string         `json:"id"`
	Kind       string         `json:"kind"`   // create_campaign | create_adset | create_ad | pause | resume | set_budget | set_bid | upload_creative | archive
	Target     string         `json:"target"` // Meta object id, or a local temp id ("tmp:…") for new objects
	Params     map[string]any `json:"params"` // budgets in AED (float), never fils
	Why        string         `json:"why"`
	Confidence float64        `json:"confidence"`
	Evidence   []string       `json:"evidence"`
}

type Plan struct {
	UpdatedAt time.Time  `json:"updated_at"`
	Summary   string     `json:"summary"`
	Steps     []PlanStep `json:"steps"`
}

type PlanStep struct {
	When   string `json:"when"` // "now", "today", "2026-11-22", "after 30 purchases" …
	Title  string `json:"title"`
	Detail string `json:"detail,omitempty"`
}

type ResearchInput struct {
	Now      time.Time
	Shop     ShopSnapshot
	Previous Research
	Focus    string // optional: what the owner (or the planner) asked to look into
}

type Research struct {
	UpdatedAt      time.Time `json:"updated_at"`
	Trends         []Note    `json:"trends"`
	Competitors    []Note    `json:"competitors"`
	Angles         []Note    `json:"angles"`
	ArabicInsights []Note    `json:"arabic_insights"`
	Sources        []string  `json:"sources"`
}

type Note struct {
	Date   string `json:"date"` // YYYY-MM-DD
	Title  string `json:"title"`
	Body   string `json:"body"`
	Source string `json:"source,omitempty"`
}

type CopyInput struct {
	ProductIDs []string `json:"product_ids"`
	Langs      []string `json:"langs"` // "ar" first
	Angle      string   `json:"angle"`
	Shop       ShopSnapshot
	Research   Research
}

type CopyOutput struct {
	Variants []Creative `json:"variants"` // headline/primary_text/cta/lang filled; no media
}

// ── what the owner sets ──

const (
	AutopilotOff    = "off"
	AutopilotShadow = "shadow"
	AutopilotAuto   = "auto"
)

// Limits: the owner's hard limits (Settings → Limits). Numbers in AED. No daily or monthly limit = nothing launches.
// TargetCPAAED and KillMultiple are ENGINE additions: they size the Meta-side safety rule that pauses a non-buying
// ad set / ad while the Mac is closed (spend ≥ KillMultiple × TargetCPAAED with 0 purchases).
type Limits struct {
	DailyAED         float64  `json:"daily_aed"`
	MonthlyAED       float64  `json:"monthly_aed"`
	MaxBudgetStepPct float64  `json:"max_budget_step_pct"`
	CooldownHours    float64  `json:"cooldown_hours"`
	MaxActiveAds     int      `json:"max_active_ads"`
	Countries        []string `json:"countries"`
	Autopilot        string   `json:"autopilot"` // off | shadow | auto
	TargetCPAAED     float64  `json:"target_cpa_aed"`
	KillMultiple     float64  `json:"kill_multiple"`
}

func DefaultLimits() Limits {
	return Limits{MaxBudgetStepPct: 20, CooldownHours: 72, MaxActiveAds: 6, Countries: []string{"AE"}, Autopilot: AutopilotOff, KillMultiple: 3}
}

// Set: both money limits are there. Without them nothing launches and no budget goes up.
func (l Limits) Set() bool { return l.DailyAED > 0 && l.MonthlyAED > 0 }

// ── the Meta account, as the engine last read it (AED) ──

type Account struct {
	ID             string  `json:"id"` // act_…
	Name           string  `json:"name"`
	Currency       string  `json:"currency"`
	Timezone       string  `json:"timezone"`
	SpendCapAED    float64 `json:"spend_cap"`    // 0 = Meta has NO cap on the account
	AmountSpentAED float64 `json:"amount_spent"` // with respect to spend_cap (Meta's own definition)
	Status         int     `json:"account_status"`
	DisableReason  int     `json:"disable_reason,omitempty"`
	MinDailyAED    float64 `json:"min_daily_budget"`
	PageID         string  `json:"page_id,omitempty"`
	PixelID        string  `json:"pixel_id,omitempty"`
}

type Campaign struct {
	ID                string  `json:"id"`
	Name              string  `json:"name"`
	Objective         string  `json:"objective"`
	Status            string  `json:"status"`
	EffectiveStatus   string  `json:"effective_status"`
	DailyBudgetAED    float64 `json:"daily_budget"`
	LifetimeBudgetAED float64 `json:"lifetime_budget,omitempty"`
	BidStrategy       string  `json:"bid_strategy,omitempty"`
	AdvantageState    string  `json:"advantage_state,omitempty"`
	CreatedTime       string  `json:"created_time,omitempty"`
	UpdatedTime       string  `json:"updated_time,omitempty"`
	Managed           bool    `json:"managed"` // made by this app (name starts "KAA:")
	AdSets            []AdSet `json:"adsets"`
}

type AdSet struct {
	ID               string   `json:"id"`
	CampaignID       string   `json:"campaign_id"`
	Name             string   `json:"name"`
	Status           string   `json:"status"`
	EffectiveStatus  string   `json:"effective_status"`
	DailyBudgetAED   float64  `json:"daily_budget,omitempty"`
	OptimizationGoal string   `json:"optimization_goal,omitempty"`
	BidStrategy      string   `json:"bid_strategy,omitempty"`
	BidAmountAED     float64  `json:"bid_amount,omitempty"`
	Countries        []string `json:"countries,omitempty"`
	LearningStage    string   `json:"learning_stage,omitempty"` // LEARNING | SUCCESS | FAIL (learning limited) | ""
	Ads              []Ad     `json:"ads"`
}

type Ad struct {
	ID              string            `json:"id"`
	AdSetID         string            `json:"adset_id"`
	CampaignID      string            `json:"campaign_id"`
	Name            string            `json:"name"`
	Status          string            `json:"status"`
	EffectiveStatus string            `json:"effective_status"`
	CreativeID      string            `json:"creative_id,omitempty"`
	ReviewFeedback  map[string]string `json:"review_feedback,omitempty"`
	Issues          []string          `json:"issues,omitempty"`
}

// MetricRow: one object, one day (the ad account's own time zone). Attribution 7d_click + 1d_view. Settling = the
// last 2 days, whose conversions Meta is still attributing.
type MetricRow struct {
	Date             string  `json:"date"`
	Level            string  `json:"level"` // campaign | adset | ad
	ObjectID         string  `json:"object_id"`
	Name             string  `json:"name,omitempty"`
	SpendAED         float64 `json:"spend"`
	Impressions      int64   `json:"impressions"`
	Reach            int64   `json:"reach"`
	Clicks           int64   `json:"clicks"`      // link clicks
	Purchases        float64 `json:"purchases"`   // actions[purchase-type]
	PurchaseValueAED float64 `json:"revenue"`     // action_values[purchase-type]
	Frequency        float64 `json:"frequency"`   // as Meta reports it for the day
	Settling         bool    `json:"settling"`    // last 2 days
	ATC              float64 `json:"add_to_cart"` // actions[add_to_cart]
}

// ── the shop ──

type ShopSnapshot struct {
	FetchedAt    time.Time  `json:"fetched_at"`
	Products     []Product  `json:"products"`
	Orders       []OrderDay `json:"orders"`
	OrdersSource string     `json:"orders_source"` // "none" until the shop has an authenticated orders endpoint
}

type Product struct {
	ID        string   `json:"id"`
	Title     string   `json:"title"`
	Brand     string   `json:"brand,omitempty"`
	PriceAED  float64  `json:"price"`
	SaleAED   float64  `json:"sale_price,omitempty"`
	InStock   bool     `json:"in_stock"`
	MarginPct *float64 `json:"margin_pct,omitempty"` // only when the shop tells us
	Image     string   `json:"image,omitempty"`
	URL       string   `json:"url,omitempty"`
	Category  string   `json:"category,omitempty"`
}

type OrderDay struct {
	Date          string  `json:"date"`
	Orders        int     `json:"orders"`
	NetRevenueAED float64 `json:"net_revenue"`
	RefundsAED    float64 `json:"refunds"`
}

// ── creatives ──

type Creative struct {
	ID          string    `json:"id"`
	Lang        string    `json:"lang"`            // ar | en
	Format      string    `json:"format"`          // 1:1 | 4:5 | 9:16
	PNG         string    `json:"png,omitempty"`   // base64, only on POST; stored as a file, never echoed in lists
	Video       string    `json:"video,omitempty"` // base64, only on POST
	Headline    string    `json:"headline"`
	PrimaryText string    `json:"primary_text"`
	CTA         string    `json:"cta"`
	Link        string    `json:"link"`
	ProductIDs  []string  `json:"product_ids"`
	Angle       string    `json:"angle"`
	Source      string    `json:"source"` // brain | owner
	CreatedAt   time.Time `json:"created_at"`
	Media       string    `json:"media,omitempty"`      // "png" | "mp4" | "" (what the data folder holds)
	ImageHash   string    `json:"image_hash,omitempty"` // after upload_creative
	VideoID     string    `json:"video_id,omitempty"`
	MetaID      string    `json:"meta_creative_id,omitempty"`
	Used        bool      `json:"used"`
}

// ── the audit log ──

// LogEntry: one line of the append-only audit log (JSONL in the data folder). Kinds: snapshot, proposal, check,
// execution, meta, safety, cycle, alert, kill, config, claude, research, sync, error.
type LogEntry struct {
	Seq     int64           `json:"seq"`
	At      time.Time       `json:"at"`
	Kind    string          `json:"kind"`
	Summary string          `json:"summary"`
	Data    json.RawMessage `json:"data,omitempty"`
}

type Alert struct {
	At    time.Time `json:"at"`
	Level string    `json:"level"` // info | warn | error
	Text  string    `json:"text"`
}

// ── Claude, as the brain sees it (engine/claude implements LLM) ──

type LLM interface {
	Complete(ctx context.Context, req LLMRequest) (LLMResponse, error)
}

type LLMRequest struct {
	Purpose   string            // "plan" | "research" | "copy" … (for the usage log)
	Model     string            // "" = the configured model
	System    string            // stable text first: it is cached
	Messages  []LLMMessage      // role user|assistant; Content is a string or the API's content-block array
	Tools     []json.RawMessage // tool definitions as the Messages API takes them (strict client tools)
	WebSearch int               // > 0: add the server web_search tool with this many max_uses
	MaxTokens int               // 0 = 16000
	Effort    string            // "" = model default; low | medium | high | xhigh | max
}

type LLMMessage struct {
	Role    string `json:"role"`
	Content any    `json:"content"`
}

type LLMResponse struct {
	Model      string            `json:"model"`
	StopReason string            `json:"stop_reason"` // end_turn | tool_use | max_tokens | refusal | pause_turn
	Text       string            `json:"text"`        // all text blocks joined
	ToolUses   []ToolUse         `json:"tool_uses"`
	Content    []json.RawMessage `json:"content"` // the raw blocks, to append back on the next turn
	Citations  []string          `json:"citations,omitempty"`
	Usage      LLMUsage          `json:"usage"`
}

type ToolUse struct {
	ID    string          `json:"id"`
	Name  string          `json:"name"`
	Input json.RawMessage `json:"input"`
}

type LLMUsage struct {
	InputTokens      int     `json:"input_tokens"`
	OutputTokens     int     `json:"output_tokens"`
	CacheReadTokens  int     `json:"cache_read_input_tokens"`
	CacheWriteTokens int     `json:"cache_creation_input_tokens"`
	WebSearches      int     `json:"web_searches"`
	CostUSD          float64 `json:"cost_usd"`
}
