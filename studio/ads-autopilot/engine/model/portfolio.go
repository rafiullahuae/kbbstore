package model

import (
	"strings"
	"time"
)

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
	// the Page / Instagram were picked by hand (Settings → Accounts → Link by hand): never changed by the app.
	// Otherwise they follow the owner's own running ads (engine/identity.go, 22 Nov).
	IdentityByHand bool `json:"identity_by_hand,omitempty"`
	// LandingURL: where every ad the app makes sends people (the owner, 22 Nov: "all the campaigns must go to this
	// landing page https://kbeautybliss.com/super-sale/"). "" = that page (his default); "product" = each ad's own
	// product page; otherwise a kbeautybliss.com page. Ads he changes by hand in Ads Manager are never touched.
	LandingURL      string `json:"landing_url,omitempty"`
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

// DefaultLanding: the owner's landing page for every ad (22 Nov); an Arabic ad goes to its Arabic version (ShopLang).
const DefaultLanding = "https://kbeautybliss.com/super-sale/"

// Landing: the page an ad in lang sends people to; own = the ad's own (product) page, used when he chose
// "product". The owner, 22 Nov: "if the ad is in arabic, the arabic super sale should be linked, and if in english,
// the english super sale page" — every link follows the ad's language (ShopLang).
func (l AccountLinks) Landing(lang, own string) string {
	u := own
	switch {
	case l.LandingURL == "":
		u = DefaultLanding
	case l.LandingURL == "product":
	case strings.HasPrefix(l.LandingURL, "https://kbeautybliss.com/"):
		u = l.LandingURL
	default:
		u = DefaultLanding
	}
	return ShopLang(u, lang)
}

// ShopLang: a kbeautybliss.com page in the ad's language — Arabic ("ar") at /ar/… (the live shop's hreflang="ar":
// https://kbeautybliss.com/ar/super-sale/); English, or a MIXED Arabic + English ad (lang "" / "mixed"), the
// English page. Other links are left as they are.
func ShopLang(u, lang string) string {
	const base = "https://kbeautybliss.com/"
	if !strings.HasPrefix(u, base) {
		return u
	}
	rest := strings.TrimPrefix(u, base)
	isAr := rest == "ar" || strings.HasPrefix(rest, "ar/")
	switch {
	case lang == "ar" && !isAr:
		return base + "ar/" + rest
	case lang != "ar" && isAr: // English, or mixed Arabic + English (the owner, 22 Nov): the English page
		return base + strings.TrimPrefix(strings.TrimPrefix(rest, "ar"), "/")
	}
	return u
}

// TextLang: "ar" for Arabic words only, "en" for Latin only, "mixed" for both (the landing-page rule, 22 Nov). Brand
// names in Latin letters inside Arabic copy ("COSRX", "K-Beauty Bliss", "Beauty of Joseon") do not make it mixed:
// it is mixed only with more than 4 Latin words (an English sentence).
func TextLang(s string) string {
	ar, latinWords, inWord := false, 0, false
	for _, r := range s {
		switch {
		case r >= 0x0600 && r <= 0x06FF:
			ar, inWord = true, false
		case (r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z'):
			if !inWord {
				latinWords++
			}
			inWord = true
		default:
			inWord = false
		}
	}
	switch {
	case ar && latinWords > 4:
		return "mixed"
	case ar:
		return "ar"
	}
	return "en"
}
