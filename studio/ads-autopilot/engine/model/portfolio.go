package model

import "time"

// ── Account scoping (the owner, 20 Nov 2026: "read the whole business portfolio including all ad accounts … switch
// from one ad account to another, and give multiple ad accounts to automate separately") ──
//
// Everything the brain gets in one CycleInput belongs to ONE ad account (CycleInput.Account.ID): its tree, metrics,
// limits, links, creatives, history. Proposals of that cycle act on that account only. Learnings are the one
// portfolio-wide input (what won across all of the owner's accounts).

// AccountLinks: the assets an ad account advertises with. Which ad types need which link:
//   - every ad: PageID (the Facebook Page the ad runs as)
//   - sales optimisation and website audiences: PixelID (the dataset)
//   - Instagram identity / click-to-Instagram-Direct / IG engagement audiences: InstagramUserID
//   - Advantage+ catalog ads: CatalogID
//   - click-to-WhatsApp: WhatsAppNumber linked to the Page (Page settings → WhatsApp)
type AccountLinks struct {
	PageID          string `json:"page_id,omitempty"`
	InstagramUserID string `json:"instagram_user_id,omitempty"`
	PixelID         string `json:"pixel_id,omitempty"`
	CatalogID       string `json:"catalog_id,omitempty"`
	WhatsAppNumber  string `json:"whatsapp_number,omitempty"` // E.164, e.g. +9715XXXXXXXX
}

// Portfolio: what the token can see (Settings → Connections → Meta).
type Portfolio struct {
	ReadAt     time.Time      `json:"read_at"`
	Businesses []Business     `json:"businesses"`
	AdAccounts []AdAccountRef `json:"ad_accounts"` // every account the token can use, owned + client + personal
}

type Business struct {
	ID         string     `json:"id"`
	Name       string     `json:"name"`
	Pages      []AssetRef `json:"pages"`
	Instagram  []AssetRef `json:"instagram_accounts"`
	Pixels     []AssetRef `json:"pixels"`
	Catalogs   []AssetRef `json:"catalogs"`
	WhatsApp   []AssetRef `json:"whatsapp_accounts"`
	AdAccounts []string   `json:"ad_accounts"`
	Error      string     `json:"error,omitempty"` // an edge the token may not read (e.g. without business_management)
}

type AssetRef struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type AdAccountRef struct {
	ID         string `json:"id"` // act_…
	Name       string `json:"name"`
	Currency   string `json:"currency"`
	Status     int    `json:"account_status"`
	BusinessID string `json:"business_id,omitempty"`
	Relation   string `json:"relation"`  // owned | client | personal
	Automated  bool   `json:"automated"` // the owner switched it on in the app
}

// Learning: one aggregate over an account's long history (engine-computed; the brain reads, never computes, these).
type Learning struct {
	AccountID string  `json:"account_id"`
	Dimension string  `json:"dimension"` // format | angle | audience | country | objective
	Key       string  `json:"key"`       // e.g. "VIDEO", "carousel", "advantage_audience", "AE"
	SpendAED  float64 `json:"spend"`
	Purchases float64 `json:"purchases"`
	Revenue   float64 `json:"revenue"`
	Ads       int     `json:"ads"`
	CPA       float64 `json:"cpa"`
	ROAS      float64 `json:"roas"`
	From      string  `json:"from"`
	To        string  `json:"to"`
}

// HistoryCreative: an ad's creative as the history import read it (all ads ever).
type HistoryCreative struct {
	AdID       string `json:"ad_id"`
	CreativeID string `json:"creative_id"`
	ObjectType string `json:"object_type,omitempty"` // Meta's own: SHARE, VIDEO, PHOTO, …
	Format     string `json:"format"`                // image | video | carousel | flexible | catalog | other
	Title      string `json:"title,omitempty"`
	Body       string `json:"body,omitempty"`
	CTA        string `json:"cta,omitempty"`
}
