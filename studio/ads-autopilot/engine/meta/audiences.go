package meta

import (
	"context"
	"net/url"

	"kaa/model"
)

// Audiences: the ad account's custom audiences with Meta's size estimates and delivery status (META-FACTS §17:
// approximate_count_lower_bound / _upper_bound, delivery_status {code 200 = ready}, read on the custom-audience
// reference). One read per sync; the funnel waits for an audience big enough before it uses it.
func (c *Client) Audiences(ctx context.Context, act string) ([]model.Audience, error) {
	type raw struct {
		ID       string `json:"id"`
		Name     string `json:"name"`
		Subtype  string `json:"subtype"`
		Lower    int64  `json:"approximate_count_lower_bound"`
		Upper    int64  `json:"approximate_count_upper_bound"`
		Ret      int    `json:"retention_days"`
		Delivery struct {
			Code        int    `json:"code"`
			Description string `json:"description"`
		} `json:"delivery_status"`
	}
	rs, err := getAll[raw](ctx, c, act+"/customaudiences", url.Values{"limit": {"200"},
		"fields": {"id,name,subtype,approximate_count_lower_bound,approximate_count_upper_bound,retention_days,delivery_status"}}, 1000)
	if err != nil {
		return nil, err
	}
	out := make([]model.Audience, 0, len(rs))
	for _, r := range rs {
		out = append(out, model.Audience{ID: r.ID, Name: r.Name, Subtype: r.Subtype, Lower: r.Lower, Upper: r.Upper, RetentionDays: r.Ret,
			DeliveryCode: r.Delivery.Code, DeliveryText: r.Delivery.Description})
	}
	return out, nil
}
