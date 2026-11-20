package meta

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"net/url"
	"strconv"
	"strings"

	"kaa/model"
)

// ManagedPrefix: every object and rule this app makes is named "KAA: …". The safety rules and the kill switch act
// on that prefix only, never on the owner's own campaigns.
const ManagedPrefix = "KAA:"

// MinorPerAED: Meta's offset for AED is 100 (META-FACTS §3, Currencies page). Reads convert minor → AED here;
// writes of money go through engine/executor (fils) only.
const MinorPerAED = 100

func minorToAED(s string) float64 {
	if s == "" {
		return 0
	}
	f, err := strconv.ParseFloat(s, 64)
	if err != nil {
		return 0
	}
	return math.Round(f) / MinorPerAED
}

func f64(s string) float64 {
	f, _ := strconv.ParseFloat(s, 64)
	return f
}

func i64(s string) int64 {
	n, _ := strconv.ParseInt(s, 10, 64)
	return n
}

type paging struct {
	Cursors struct {
		After string `json:"after"`
	} `json:"cursors"`
	Next string `json:"next"`
}

// getAll pages through an edge by cursor (never by following "next", which carries the token in its URL).
func getAll[T any](ctx context.Context, c *Client, path string, q url.Values, max int) ([]T, error) {
	var all []T
	for page := 0; page < 50; page++ {
		var r struct {
			Data   []T    `json:"data"`
			Paging paging `json:"paging"`
		}
		if err := c.Get(ctx, path, q, &r); err != nil {
			return all, err
		}
		all = append(all, r.Data...)
		if r.Paging.Next == "" || r.Paging.Cursors.After == "" || len(r.Data) == 0 || max > 0 && len(all) >= max {
			break
		}
		q = cloneValues(q)
		q.Set("after", r.Paging.Cursors.After)
	}
	return all, nil
}

func cloneValues(v url.Values) url.Values {
	o := url.Values{}
	for k, vs := range v {
		o[k] = append([]string(nil), vs...)
	}
	return o
}

// ── reads ──

func (c *Client) Account(ctx context.Context, act string) (model.Account, error) {
	var r struct {
		ID, Name, Currency string
		TimezoneName       string `json:"timezone_name"`
		SpendCap           string `json:"spend_cap"`
		AmountSpent        string `json:"amount_spent"`
		AccountStatus      int    `json:"account_status"`
		DisableReason      int    `json:"disable_reason"`
		MinDailyBudget     int64  `json:"min_daily_budget"`
	}
	q := url.Values{"fields": {"id,name,currency,timezone_name,spend_cap,amount_spent,account_status,disable_reason,min_daily_budget"}}
	if err := c.Get(ctx, act, q, &r); err != nil {
		return model.Account{}, err
	}
	return model.Account{ID: r.ID, Name: r.Name, Currency: r.Currency, Timezone: r.TimezoneName,
		SpendCapAED: minorToAED(r.SpendCap), AmountSpentAED: minorToAED(r.AmountSpent), Status: r.AccountStatus,
		DisableReason: r.DisableReason, MinDailyAED: float64(r.MinDailyBudget) / MinorPerAED}, nil
}

type rawCampaign struct {
	ID, Name, Objective, Status string
	EffectiveStatus             string `json:"effective_status"`
	DailyBudget                 string `json:"daily_budget"`
	LifetimeBudget              string `json:"lifetime_budget"`
	BidStrategy                 string `json:"bid_strategy"`
	CreatedTime                 string `json:"created_time"`
	UpdatedTime                 string `json:"updated_time"`
	Adv                         struct {
		State string `json:"advantage_state"`
	} `json:"advantage_state_info"`
}

type rawAdSet struct {
	ID, Name, Status string
	CampaignID       string          `json:"campaign_id"`
	EffectiveStatus  string          `json:"effective_status"`
	DailyBudget      string          `json:"daily_budget"`
	OptimizationGoal string          `json:"optimization_goal"`
	BidStrategy      string          `json:"bid_strategy"`
	BidAmount        json.RawMessage `json:"bid_amount"`
	Targeting        struct {
		Geo struct {
			Countries []string `json:"countries"`
		} `json:"geo_locations"`
	} `json:"targeting"`
	Learning struct {
		Status string `json:"status"`
	} `json:"learning_stage_info"`
}

type rawAd struct {
	ID, Name, Status string
	AdSetID          string `json:"adset_id"`
	CampaignID       string `json:"campaign_id"`
	EffectiveStatus  string `json:"effective_status"`
	Creative         struct {
		ID string `json:"id"`
	} `json:"creative"`
	Review struct {
		Global map[string]string `json:"global"`
	} `json:"ad_review_feedback"`
	Issues []struct {
		Summary string `json:"error_summary"`
		Message string `json:"error_message"`
	} `json:"issues_info"`
}

// Tree reads campaigns, ad sets and ads (every status but DELETED) and nests them.
func (c *Client) Tree(ctx context.Context, act string) ([]model.Campaign, error) {
	return c.tree(ctx, act, `[{"field":"effective_status","operator":"NOT_IN","value":["DELETED"]}]`)
}

func (c *Client) tree(ctx context.Context, act, filt string) ([]model.Campaign, error) {
	cs, err := getAll[rawCampaign](ctx, c, act+"/campaigns", url.Values{"limit": {"200"}, "filtering": {filt},
		"fields": {"id,name,objective,status,effective_status,daily_budget,lifetime_budget,bid_strategy,advantage_state_info,created_time,updated_time"}}, 2000)
	if err != nil {
		return nil, err
	}
	as, err := getAll[rawAdSet](ctx, c, act+"/adsets", url.Values{"limit": {"200"}, "filtering": {filt},
		"fields": {"id,campaign_id,name,status,effective_status,daily_budget,optimization_goal,bid_strategy,bid_amount,targeting,learning_stage_info"}}, 5000)
	if err != nil {
		return nil, err
	}
	ads, err := getAll[rawAd](ctx, c, act+"/ads", url.Values{"limit": {"200"}, "filtering": {filt},
		"fields": {"id,adset_id,campaign_id,name,status,effective_status,creative{id},ad_review_feedback,issues_info"}}, 10000)
	if err != nil {
		return nil, err
	}
	adsBy := map[string][]model.Ad{}
	for _, a := range ads {
		m := model.Ad{ID: a.ID, AdSetID: a.AdSetID, CampaignID: a.CampaignID, Name: a.Name, Status: a.Status, EffectiveStatus: a.EffectiveStatus, CreativeID: a.Creative.ID}
		if len(a.Review.Global) > 0 {
			m.ReviewFeedback = a.Review.Global
		}
		for _, is := range a.Issues {
			m.Issues = append(m.Issues, strings.TrimSpace(is.Summary+": "+is.Message))
		}
		adsBy[a.AdSetID] = append(adsBy[a.AdSetID], m)
	}
	setsBy := map[string][]model.AdSet{}
	for _, s := range as {
		bid := f64(strings.Trim(string(s.BidAmount), `"`)) // Meta: an int; tolerate "123" and ""
		m := model.AdSet{ID: s.ID, CampaignID: s.CampaignID, Name: s.Name, Status: s.Status, EffectiveStatus: s.EffectiveStatus,
			DailyBudgetAED: minorToAED(s.DailyBudget), OptimizationGoal: s.OptimizationGoal, BidStrategy: s.BidStrategy,
			BidAmountAED: bid / MinorPerAED, Countries: s.Targeting.Geo.Countries, LearningStage: s.Learning.Status, Ads: adsBy[s.ID]}
		if m.Ads == nil {
			m.Ads = []model.Ad{}
		}
		setsBy[s.CampaignID] = append(setsBy[s.CampaignID], m)
	}
	out := make([]model.Campaign, 0, len(cs))
	for _, x := range cs {
		m := model.Campaign{ID: x.ID, Name: x.Name, Objective: x.Objective, Status: x.Status, EffectiveStatus: x.EffectiveStatus,
			DailyBudgetAED: minorToAED(x.DailyBudget), LifetimeBudgetAED: minorToAED(x.LifetimeBudget), BidStrategy: x.BidStrategy,
			AdvantageState: x.Adv.State, CreatedTime: x.CreatedTime, UpdatedTime: x.UpdatedTime,
			Managed: strings.HasPrefix(x.Name, ManagedPrefix), AdSets: setsBy[x.ID]}
		if m.AdSets == nil {
			m.AdSets = []model.AdSet{}
		}
		out = append(out, m)
	}
	return out, nil
}

type rawInsight struct {
	DateStart    string `json:"date_start"`
	CampaignID   string `json:"campaign_id"`
	AdSetID      string `json:"adset_id"`
	AdID         string `json:"ad_id"`
	CampaignName string `json:"campaign_name"`
	AdSetName    string `json:"adset_name"`
	AdName       string `json:"ad_name"`
	Spend        string `json:"spend"`
	Impressions  string `json:"impressions"`
	Reach        string `json:"reach"`
	Frequency    string `json:"frequency"`
	LinkClicks   string `json:"inline_link_clicks"`
	Actions      []struct {
		Type  string `json:"action_type"`
		Value string `json:"value"`
	} `json:"actions"`
	ActionValues []struct {
		Type  string `json:"action_type"`
		Value string `json:"value"`
	} `json:"action_values"`
}

// PurchaseTypes: the action types that count as a purchase, in order of preference. Only the FIRST one present on a
// row is used, never a sum: Meta reports the same purchase under several of them (META-FACTS §8).
var PurchaseTypes = []string{"omni_purchase", "purchase", "offsite_conversion.fb_pixel_purchase"}

func pick(list []struct {
	Type  string `json:"action_type"`
	Value string `json:"value"`
}, types []string) float64 {
	for _, t := range types {
		for _, a := range list {
			if a.Type == t {
				return f64(a.Value)
			}
		}
	}
	return 0
}

// Insights: one row per object per day at this level (campaign | adset | ad), 7d_click + 1d_view attribution, in the
// account currency (insights money is in major units: spend "12.34" = AED 12.34).
func (c *Client) Insights(ctx context.Context, act, level, since, until string) ([]model.MetricRow, error) {
	return c.insights(ctx, act, level, since, until, "1")
}

func (c *Client) insights(ctx context.Context, act, level, since, until, increment string) ([]model.MetricRow, error) {
	tr, _ := json.Marshal(map[string]string{"since": since, "until": until})
	q := url.Values{
		"level":                      {level},
		"time_range":                 {string(tr)},
		"time_increment":             {increment},
		"action_attribution_windows": {`["7d_click","1d_view"]`},
		"fields":                     {"date_start,campaign_id,adset_id,ad_id,campaign_name,adset_name,ad_name,spend,impressions,reach,frequency,inline_link_clicks,actions,action_values"},
		"limit":                      {"500"},
	}
	rows, err := getAll[rawInsight](ctx, c, act+"/insights", q, 50000)
	if err != nil {
		return nil, err
	}
	out := make([]model.MetricRow, 0, len(rows))
	for _, r := range rows {
		id, name := r.CampaignID, r.CampaignName
		switch level {
		case "adset":
			id, name = r.AdSetID, r.AdSetName
		case "ad":
			id, name = r.AdID, r.AdName
		}
		out = append(out, model.MetricRow{Date: r.DateStart, Level: level, ObjectID: id, Name: name,
			SpendAED: f64(r.Spend), Impressions: i64(r.Impressions), Reach: i64(r.Reach), Frequency: f64(r.Frequency),
			Clicks: i64(r.LinkClicks), Purchases: pick(r.Actions, PurchaseTypes), PurchaseValueAED: pick(r.ActionValues, PurchaseTypes),
			ATC: pick(r.Actions, []string{"omni_add_to_cart", "add_to_cart", "offsite_conversion.fb_pixel_add_to_cart"})})
	}
	return out, nil
}

// ── writes (called by the executor and the safety net only) ──

type IDResp struct {
	ID      string `json:"id"`
	Success bool   `json:"success"`
}

func (c *Client) Create(ctx context.Context, act, edge string, form url.Values, idemKey string) (string, error) {
	var r IDResp
	if err := c.Post(ctx, act+"/"+edge, form, idemKey, &r); err != nil {
		return "", err
	}
	if r.ID == "" {
		return "", fmt.Errorf("Meta did not return an id for %s", edge)
	}
	return r.ID, nil
}

func (c *Client) Update(ctx context.Context, id string, form url.Values) error {
	var r IDResp
	return c.Post(ctx, id, form, "", &r)
}

// UploadImage: POST act/adimages with bytes (base64). Returns the image hash.
func (c *Client) UploadImage(ctx context.Context, act, name, b64 string) (string, error) {
	var r struct {
		Images map[string]struct {
			Hash string `json:"hash"`
		} `json:"images"`
	}
	if err := c.Post(ctx, act+"/adimages", url.Values{"bytes": {b64}, "name": {name}}, "", &r); err != nil {
		return "", err
	}
	for _, im := range r.Images {
		if im.Hash != "" {
			return im.Hash, nil
		}
	}
	return "", fmt.Errorf("Meta did not return an image hash")
}

// UploadVideo: POST act/advideos with the file as "source" (non-chunked; fine for reels under ~1 GB).
func (c *Client) UploadVideo(ctx context.Context, act, title string, data []byte) (string, error) {
	var r IDResp
	if err := c.PostFile(ctx, act+"/advideos", url.Values{"title": {title}}, "source", "video.mp4", data, &r); err != nil {
		return "", err
	}
	return r.ID, nil
}

// SetSpendCap writes the ACCOUNT spending limit. Meta takes it in the currency's STANDARD denomination (23.50 = AED
// 23.50), unlike every other money field, and 0 means NO cap (META-FACTS §6). Only engine/safety calls this, and it
// refuses anything ≤ 0 before it gets here; this refuses it again.
func (c *Client) SetSpendCap(ctx context.Context, act string, aed float64) error {
	if !(aed > 0) || math.IsInf(aed, 0) {
		return fmt.Errorf("refused: a spend cap of %.2f would remove the account limit", aed)
	}
	return c.Update(ctx, act, url.Values{"spend_cap": {strconv.FormatFloat(math.Floor(aed*100)/100, 'f', 2, 64)}})
}

// ── Automated Rules (adrules_library) ──

type Rule struct {
	ID             string          `json:"id"`
	Name           string          `json:"name"`
	Status         string          `json:"status"`
	EvaluationSpec json.RawMessage `json:"evaluation_spec"`
	ExecutionSpec  json.RawMessage `json:"execution_spec"`
	ScheduleSpec   json.RawMessage `json:"schedule_spec,omitempty"`
}

func (c *Client) Rules(ctx context.Context, act string) ([]Rule, error) {
	return getAll[Rule](ctx, c, act+"/adrules_library", url.Values{"limit": {"200"}, "fields": {"id,name,status,evaluation_spec,execution_spec,schedule_spec"}}, 1000)
}
