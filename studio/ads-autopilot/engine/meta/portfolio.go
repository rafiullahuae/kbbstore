package meta

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"kaa/model"
)

// ── portfolio discovery (META-FACTS §13) ──
//
// me/businesses → each business's owned_ad_accounts, client_ad_accounts, owned_pages, owned_instagram_accounts,
// owned_pixels, owned_product_catalogs, owned_whatsapp_business_accounts; plus me/adaccounts, which also lists
// accounts outside any business (a personal ad account, or a token without business_management). A system-user
// token reads the same edges as itself. An edge the token may not read is noted on the business, never fatal.

type named struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Currency string `json:"currency"`
	Status   int    `json:"account_status"`
}

func refs(xs []named) []model.AssetRef {
	out := []model.AssetRef{}
	for _, x := range xs {
		n := x.Name
		if n == "" {
			n = x.Username
		}
		out = append(out, model.AssetRef{ID: x.ID, Name: n})
	}
	return out
}

func (c *Client) Portfolio(ctx context.Context) (model.Portfolio, error) {
	p := model.Portfolio{ReadAt: time.Now().UTC(), Businesses: []model.Business{}, AdAccounts: []model.AdAccountRef{}}
	seen := map[string]int{}
	add := func(a named, biz, rel string) {
		if i, ok := seen[a.ID]; ok {
			if p.AdAccounts[i].BusinessID == "" {
				p.AdAccounts[i].BusinessID, p.AdAccounts[i].Relation = biz, rel
			}
			return
		}
		seen[a.ID] = len(p.AdAccounts)
		p.AdAccounts = append(p.AdAccounts, model.AdAccountRef{ID: a.ID, Name: a.Name, Currency: a.Currency, Status: a.Status, BusinessID: biz, Relation: rel})
	}
	accFields := url.Values{"fields": {"id,name,currency,account_status"}, "limit": {"100"}}
	bizs, berr := getAll[named](ctx, c, "me/businesses", url.Values{"fields": {"id,name"}, "limit": {"100"}}, 200)
	for _, b := range bizs {
		B := model.Business{ID: b.ID, Name: b.Name}
		var errs []string
		for _, e := range []struct {
			edge, rel string
		}{{"owned_ad_accounts", "owned"}, {"client_ad_accounts", "client"}} {
			as, err := getAll[named](ctx, c, b.ID+"/"+e.edge, accFields, 1000)
			if err != nil {
				errs = append(errs, e.edge+": "+err.Error())
			}
			for _, a := range as {
				add(a, b.ID, e.rel)
				B.AdAccounts = append(B.AdAccounts, a.ID)
			}
		}
		get := func(edge, fields string) []model.AssetRef {
			xs, err := getAll[named](ctx, c, b.ID+"/"+edge, url.Values{"fields": {fields}, "limit": {"100"}}, 500)
			if err != nil {
				errs = append(errs, edge+": "+err.Error())
			}
			return refs(xs)
		}
		B.Pages = get("owned_pages", "id,name")
		B.Instagram = get("owned_instagram_accounts", "id,username")
		B.Pixels = get("owned_pixels", "id,name")
		B.Catalogs = get("owned_product_catalogs", "id,name")
		B.WhatsApp = get("owned_whatsapp_business_accounts", "id,name")
		if len(errs) > 0 {
			B.Error = strings.Join(errs, "; ")
		}
		p.Businesses = append(p.Businesses, B)
	}
	mine, merr := getAll[named](ctx, c, "me/adaccounts", accFields, 1000)
	for _, a := range mine {
		add(a, "", "personal")
	}
	if berr != nil && merr != nil {
		return p, fmt.Errorf("could not list businesses (%v) nor ad accounts (%v)", berr, merr)
	}
	return p, nil
}

// CheckAsset: a live check that the token can read a linked object (page, Instagram account, pixel, catalog).
func (c *Client) CheckAsset(ctx context.Context, id, fields string) (map[string]any, error) {
	var out map[string]any
	err := c.Get(ctx, id, url.Values{"fields": {fields}}, &out)
	return out, err
}

// ── history import (META-FACTS §8: Insights reach back 37 months; error 3018 beyond) ──

// AllObjects: every campaign/ad set/ad ever (archived and deleted included) with the creative fields that say what
// kind of ad it was.
func (c *Client) AllObjects(ctx context.Context, act string) ([]model.Campaign, []model.HistoryCreative, error) {
	filt := `[{"field":"effective_status","operator":"IN","value":["ACTIVE","PAUSED","ARCHIVED","DELETED","CAMPAIGN_PAUSED","ADSET_PAUSED","DISAPPROVED","WITH_ISSUES","IN_PROCESS","PENDING_REVIEW"]}]`
	tree, err := c.tree(ctx, act, filt)
	if err != nil {
		return nil, nil, err
	}
	type rawCr struct {
		ID       string `json:"id"`
		Creative struct {
			ID         string          `json:"id"`
			ObjectType string          `json:"object_type"`
			Title      string          `json:"title"`
			Body       string          `json:"body"`
			CTA        string          `json:"call_to_action_type"`
			AssetFeed  json.RawMessage `json:"asset_feed_spec"`
			ProductSet string          `json:"product_set_id"`
			Story      json.RawMessage `json:"object_story_spec"`
		} `json:"creative"`
	}
	ads, err := getAll[rawCr](ctx, c, act+"/ads", url.Values{"limit": {"100"}, "filtering": {filt},
		"fields": {"id,creative{id,object_type,title,body,call_to_action_type,asset_feed_spec,product_set_id,object_story_spec}"}}, 20000)
	if err != nil {
		return tree, nil, err
	}
	var crs []model.HistoryCreative
	for _, a := range ads {
		cr := a.Creative
		f := "other"
		switch {
		case cr.ProductSet != "" || bytes.Contains(cr.Story, []byte(`"template_data"`)):
			f = "catalog"
		case len(cr.AssetFeed) > 2 && string(cr.AssetFeed) != "null":
			f = "flexible"
		case bytes.Contains(cr.Story, []byte(`"child_attachments"`)):
			f = "carousel"
		case cr.ObjectType == "VIDEO" || bytes.Contains(cr.Story, []byte(`"video_data"`)):
			f = "video"
		case cr.ObjectType == "SHARE" || cr.ObjectType == "PHOTO" || bytes.Contains(cr.Story, []byte(`"link_data"`)):
			f = "image"
		}
		crs = append(crs, model.HistoryCreative{AdID: a.ID, CreativeID: cr.ID, ObjectType: cr.ObjectType, Format: f, Title: cr.Title, Body: cr.Body, CTA: cr.CTA})
	}
	return tree, crs, nil
}

// InsightsRange: like Insights, with a time increment ("1" daily, "monthly").
func (c *Client) InsightsRange(ctx context.Context, act, level, since, until, increment string) ([]model.MetricRow, error) {
	return c.insights(ctx, act, level, since, until, increment)
}

// ── writes for the other ad types ──

// CreateAudience: POST act/customaudiences (website rule, engagement rule, or subtype=LOOKALIKE). META-FACTS §15.
func (c *Client) CreateAudience(ctx context.Context, act string, form url.Values) (string, error) {
	return c.Create(ctx, act, "customaudiences", form, "")
}

// CreateProductSet: POST <catalog>/product_sets {name, filter}.
func (c *Client) CreateProductSet(ctx context.Context, catalog, name, filter string) (string, error) {
	var r IDResp
	form := url.Values{"name": {name}}
	if filter != "" {
		form.Set("filter", filter)
	}
	if err := c.Post(ctx, catalog+"/product_sets", form, "", &r); err != nil {
		return "", err
	}
	return r.ID, nil
}

// RuploadBase: Meta's resumable video upload host (META-FACTS §9).
var RuploadBase = "https://rupload.facebook.com/video-ads-upload"

// UploadVideoResumable: act/video_ads upload_phase=start → POST the bytes to rupload (offset, file_size headers;
// resumed from status.uploading_phase.bytes_transferred after a drop) → upload_phase=finish → poll status until
// video_status "ready" (processing makes the thumbnails). Returns the video id.
func (c *Client) UploadVideoResumable(ctx context.Context, act string, data []byte, waitReady time.Duration) (string, error) {
	return c.UploadVideoReader(ctx, act, bytes.NewReader(data), int64(len(data)), waitReady)
}

// UploadVideoReader: the same resumable upload, read from r (an open file of the owner's video in the data folder),
// so a long video is streamed from disk rather than held in memory.
func (c *Client) UploadVideoReader(ctx context.Context, act string, r io.ReaderAt, size int64, waitReady time.Duration) (string, error) {
	if size <= 0 {
		return "", fmt.Errorf("the video is empty")
	}
	var st struct {
		VideoID   string `json:"video_id"`
		UploadURL string `json:"upload_url"`
	}
	if err := c.Post(ctx, act+"/video_ads", url.Values{"upload_phase": {"start"}}, "", &st); err != nil {
		return "", err
	}
	if st.VideoID == "" {
		return "", fmt.Errorf("Meta did not start a video upload")
	}
	up := strings.TrimRight(RuploadBase, "/") + "/" + c.Version + "/" + st.VideoID
	offset := int64(0)
	for attempt := 0; attempt < 5; attempt++ {
		if attempt > 0 { // resume where Meta says it got to
			s, err := c.VideoStatus(ctx, st.VideoID)
			if err == nil {
				offset = s.BytesTransferred
			}
			_ = c.sleep(ctx, time.Duration(2<<attempt)*time.Second)
		}
		if offset < 0 || offset > size {
			offset = 0
		}
		req, err := http.NewRequestWithContext(ctx, http.MethodPost, up, io.NewSectionReader(r, offset, size-offset))
		if err != nil {
			return "", err
		}
		req.ContentLength = size - offset
		req.Header.Set("Authorization", "OAuth "+c.Token())
		req.Header.Set("offset", strconv.FormatInt(offset, 10))
		req.Header.Set("file_size", strconv.FormatInt(size, 10))
		resp, err := c.HTTP.Do(req)
		if err != nil {
			continue
		}
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
		resp.Body.Close()
		c.report(Call{Method: "POST", Path: "rupload/" + st.VideoID, Status: resp.StatusCode})
		if resp.StatusCode == 200 && bytes.Contains(b, []byte(`"success":true`)) {
			goto finish
		}
	}
	return "", fmt.Errorf("the video upload did not complete")
finish:
	if err := c.Post(ctx, act+"/video_ads", url.Values{"upload_phase": {"finish"}, "video_id": {st.VideoID}}, "", nil); err != nil {
		return "", err
	}
	deadline := time.Now().Add(waitReady)
	for {
		s, err := c.VideoStatus(ctx, st.VideoID)
		if err == nil && s.VideoStatus == "ready" {
			return st.VideoID, nil
		}
		if err == nil && (s.VideoStatus == "error" || s.VideoStatus == "expired") {
			return "", fmt.Errorf("Meta could not process the video (%s)", s.VideoStatus)
		}
		if time.Now().After(deadline) {
			return st.VideoID, fmt.Errorf("the video is still processing; the ad is made on the next cycle")
		}
		if err := c.sleep(ctx, 5*time.Second); err != nil {
			return "", err
		}
		if c.Sleep != nil && time.Now().After(deadline.Add(-waitReady/2)) {
			return st.VideoID, fmt.Errorf("the video is still processing")
		}
	}
}

type VideoStat struct {
	VideoStatus      string
	BytesTransferred int64
}

func (c *Client) VideoStatus(ctx context.Context, id string) (VideoStat, error) {
	var r struct {
		Status struct {
			VideoStatus string `json:"video_status"`
			Uploading   struct {
				Bytes int64 `json:"bytes_transferred"`
			} `json:"uploading_phase"`
		} `json:"status"`
	}
	if err := c.Get(ctx, id, url.Values{"fields": {"status"}}, &r); err != nil {
		return VideoStat{}, err
	}
	return VideoStat{VideoStatus: r.Status.VideoStatus, BytesTransferred: r.Status.Uploading.Bytes}, nil
}
