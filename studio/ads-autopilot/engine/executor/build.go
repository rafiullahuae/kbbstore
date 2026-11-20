package executor

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"kaa/model"
)

// build.go — the Meta forms for each create kind. Every object is created PAUSED (audiences and product sets have no
// status); activation is the separate checked step in apply().

func (x *Executor) links() model.AccountLinks {
	if x.Links == nil {
		return model.AccountLinks{}
	}
	return x.Links()
}

func (x *Executor) createCampaign(ctx context.Context, env *Env, act string, p model.Proposal, key string) (string, []string, error) {
	bs := str(p.Params, "bid_strategy")
	if bs == "" {
		bs = "LOWEST_COST_WITHOUT_CAP"
	}
	if !bidStrategies[bs] {
		return "", nil, fmt.Errorf("bid strategy %q is not one Meta knows", bs)
	}
	budget, _ := num(p.Params, "daily_budget")
	fs, err := filsStr(budget)
	if err != nil {
		return "", nil, err
	}
	name := managedName(str(p.Params, "name"))
	objective := "OUTCOME_SALES"
	form := url.Values{"name": {name}, "status": {"PAUSED"}, "special_ad_categories": {"[]"}, "buying_type": {"AUCTION"}, "daily_budget": {fs}, "bid_strategy": {bs}}
	if str(p.Params, "type") == "catalog" { // Advantage+ catalog ads (META-FACTS §14)
		objective = "PRODUCT_CATALOG_SALES"
		form.Set("promoted_object", jsonStr(map[string]string{"product_catalog_id": x.links().CatalogID}))
	}
	form.Set("objective", objective)
	id, err := x.Meta.Create(ctx, act, "campaigns", form, key)
	if err != nil {
		return "", nil, err
	}
	if strings.HasPrefix(p.Target, "tmp:") {
		x.Ledger.MapTmp(p.Target, id)
	}
	env.Tree = append(env.Tree, model.Campaign{ID: id, Name: name, Objective: objective, Status: "PAUSED", EffectiveStatus: "PAUSED", DailyBudgetAED: budget, BidStrategy: bs, Managed: true, AdSets: []model.AdSet{}})
	return id, []string{"created PAUSED (" + objective + ", campaign budget " + fs + " fils)"}, nil
}

func (x *Executor) createAdSet(ctx context.Context, env *Env, act string, p model.Proposal, key string) (string, []string, error) {
	l := x.links()
	cid := str(p.Params, "campaign_id")
	cn := find(env.Tree, cid)
	if cn == nil || cn.level != "campaign" || !cn.campaign.Managed {
		return "", nil, fmt.Errorf("campaign %s is not one the app made", cid)
	}
	dest := str(p.Params, "destination")
	if dest == "" {
		dest = "website"
	}
	d := destinations[dest]
	countries := strs(p.Params, "countries")
	targeting, err := adSetTargeting(p.Params, countries)
	if err != nil {
		return "", nil, err
	}
	name := managedName(str(p.Params, "name"))
	form := url.Values{"name": {name}, "campaign_id": {cid}, "status": {"PAUSED"}, "billing_event": {"IMPRESSIONS"},
		"optimization_goal": {d.goal}, "targeting": {jsonStr(targeting)}, "start_time": {env.Now.UTC().Format(time.RFC3339)}}
	if d.goal == "OFFSITE_CONVERSIONS" { // 7-day click / 1-day view, sent explicitly (META-FACTS §16b: attribution_spec)
		form.Set("attribution_spec", jsonStr([]map[string]any{{"event_type": "CLICK_THROUGH", "window_days": 7}, {"event_type": "VIEW_THROUGH", "window_days": 1}}))
	}
	if b, ok := num(p.Params, "spend_cap"); ok { // an ad-set spend cap under a campaign budget (existing customers, §2b)
		if cn.campaign.DailyBudgetAED <= 0 {
			return "", nil, errors.New("an ad-set spend cap needs a campaign budget")
		}
		if b > cn.campaign.DailyBudgetAED {
			return "", nil, fmt.Errorf("the ad-set spend cap AED %.2f is above its campaign budget AED %.2f", b, cn.campaign.DailyBudgetAED)
		}
		fs, err := filsStr(b)
		if err != nil {
			return "", nil, err
		}
		form.Set("daily_spend_cap", fs)
	}
	switch {
	case str(p.Params, "product_set_id") != "": // catalog ads: the product set is what the ad set promotes
		po := map[string]string{"product_set_id": str(p.Params, "product_set_id")}
		if l.PixelID != "" {
			po["custom_event_type"] = "PURCHASE"
		}
		form.Set("promoted_object", jsonStr(po))
	case dest == "website":
		form.Set("promoted_object", jsonStr(map[string]string{"pixel_id": l.PixelID, "custom_event_type": "PURCHASE"}))
	default: // click-to-message (META-FACTS §15): destination_type + the Page (+ the WhatsApp number)
		form.Set("destination_type", d.destType)
		po := map[string]string{"page_id": l.PageID}
		if dest == "whatsapp" {
			po["whatsapp_phone_number"] = l.WhatsAppNumber
		}
		form.Set("promoted_object", jsonStr(po))
	}
	budget := 0.0
	if b, ok := num(p.Params, "daily_budget"); ok {
		if cn.campaign.DailyBudgetAED > 0 {
			return "", nil, errors.New("the campaign has its own budget; an ad set under it cannot have one too")
		}
		fs, err := filsStr(b)
		if err != nil {
			return "", nil, err
		}
		form.Set("daily_budget", fs)
		budget = b
	}
	if b, ok := num(p.Params, "bid_amount"); ok {
		fs, err := filsStr(b)
		if err != nil {
			return "", nil, err
		}
		form.Set("bid_amount", fs)
	}
	id, err := x.Meta.Create(ctx, act, "adsets", form, key)
	if err != nil {
		return "", nil, err
	}
	if strings.HasPrefix(p.Target, "tmp:") {
		x.Ledger.MapTmp(p.Target, id)
	}
	cn.campaign.AdSets = append(cn.campaign.AdSets, model.AdSet{ID: id, CampaignID: cid, Name: name, Status: "PAUSED", EffectiveStatus: "PAUSED", DailyBudgetAED: budget, OptimizationGoal: d.goal, Countries: countries, Ads: []model.Ad{}})
	return id, []string{"created PAUSED (" + dest + ", " + d.goal + ")"}, nil
}

// media uploads one library creative's picture or video (once; the hash / id is kept on the creative).
func (x *Executor) media(ctx context.Context, act, id string) (model.Creative, error) {
	if x.Creatives == nil {
		return model.Creative{}, errors.New("no creative library")
	}
	c, data, err := x.Creatives.Get(id)
	if err != nil {
		return c, err
	}
	switch c.Media {
	case "png":
		if c.ImageHash == "" {
			h, err := x.Meta.UploadImage(ctx, act, "kaa-"+c.ID+".png", b64(data))
			if err != nil {
				return c, err
			}
			c.ImageHash = h
			_ = x.Creatives.Put(c)
		}
	case "mp4":
		if c.VideoID == "" {
			v, err := x.Meta.UploadVideoResumable(ctx, act, data, 3*time.Minute)
			if v != "" {
				c.VideoID = v
				_ = x.Creatives.Put(c)
			}
			if err != nil {
				return c, err
			}
		}
	case "":
		if !ShopURL(c.ImageURL) {
			return c, fmt.Errorf("creative %s has no picture or video", id)
		}
	default:
		return c, fmt.Errorf("creative %s has no picture or video", id)
	}
	return c, nil
}

// ShopURL: an https page or photo on the shop itself (the only picture URL a creative may name).
func ShopURL(u string) bool {
	pu, err := url.Parse(u)
	return err == nil && pu.Scheme == "https" && pu.Host == "kbeautybliss.com" && pu.User == nil
}

// VideoPathRe: the owner's videos as the brain names them, relative to the data folder (videos/<id>.<ext>).
var VideoPathRe = regexp.MustCompile(`^videos/[A-Za-z0-9][A-Za-z0-9_-]{0,80}\.(mp4|mov|m4v)$`)

// ownerVideo uploads one of the owner's videos straight from the data folder (once per file: the Meta video id is
// kept in the ledger) and builds video_data. Its cover is thumbnail_url, a library picture, or the frame the brain
// saved next to the video (videos/<id>.jpg).
func (x *Executor) ownerVideo(ctx context.Context, act string, p model.Proposal) (map[string]any, []string, error) {
	vp := str(p.Params, "video_path")
	if !VideoPathRe.MatchString(vp) {
		return nil, nil, fmt.Errorf("video_path %q is not a video in the data folder (videos/<name>.mp4|mov|m4v)", vp)
	}
	if x.VideoFile == nil {
		return nil, nil, errors.New("this engine cannot read the owner's videos")
	}
	var steps []string
	key := "vpath:" + act + ":" + vp
	vid := x.Ledger.Resolve(key)
	if vid == key {
		f, size, err := x.VideoFile(vp)
		if err != nil {
			return nil, nil, err
		}
		v, err := x.Meta.UploadVideoReader(ctx, act, f, size, 3*time.Minute)
		f.Close()
		if v != "" {
			x.Ledger.MapTmp(key, v)
		}
		if err != nil {
			return nil, nil, err
		}
		vid = v
		steps = append(steps, fmt.Sprintf("video uploaded from the data folder (%.1f MB)", float64(size)/1048576))
	} else {
		steps = append(steps, "video already on Meta")
	}
	link := str(p.Params, "link")
	if link == "" {
		link = "https://kbeautybliss.com/"
	}
	if !strings.HasPrefix(link, "https://kbeautybliss.com/") {
		return nil, steps, errors.New("link must be a kbeautybliss.com page")
	}
	text := str(p.Params, "primary_text")
	if text == "" {
		return nil, steps, errors.New("a video ad needs its text (primary_text)")
	}
	c := model.Creative{CTA: str(p.Params, "cta"), Link: link}
	vd := map[string]any{"video_id": vid, "message": text, "title": str(p.Params, "headline"), "call_to_action": ctaFor(p, c, link)}
	switch {
	case str(p.Params, "thumbnail_url") != "":
		if !ShopURL(str(p.Params, "thumbnail_url")) {
			return nil, steps, errors.New("thumbnail_url must be on https://kbeautybliss.com/")
		}
		vd["image_url"] = str(p.Params, "thumbnail_url")
	case str(p.Params, "thumbnail_creative_id") != "":
		tc, err := x.media(ctx, act, str(p.Params, "thumbnail_creative_id"))
		if err != nil {
			return nil, steps, err
		}
		vd["image_hash"] = tc.ImageHash
	default:
		thumb := strings.TrimSuffix(vp, filepath.Ext(vp)) + ".jpg"
		f, size, err := x.VideoFile(thumb)
		if err != nil {
			return nil, steps, errors.New("a video ad needs a cover: thumbnail_url, thumbnail_creative_id, or the frame saved by the video analysis")
		}
		b := make([]byte, size)
		_, err = f.ReadAt(b, 0)
		f.Close()
		if err != nil || size > 8<<20 {
			return nil, steps, errors.New("the video's cover frame could not be read")
		}
		h, err := x.Meta.UploadImage(ctx, act, "kaa-cover-"+filepath.Base(thumb), b64(b))
		if err != nil {
			return nil, steps, err
		}
		vd["image_hash"] = h
		steps = append(steps, "cover from the analysed frame")
	}
	return vd, steps, nil
}

func ctaFor(p model.Proposal, c model.Creative, link string) map[string]any {
	if d, ok := destinations[str(p.Params, "destination")]; ok && d.appDest != "" {
		return map[string]any{"type": d.cta, "value": map[string]string{"app_destination": d.appDest}}
	}
	t := str(p.Params, "cta")
	if t == "" {
		t = c.CTA
	}
	if t == "" {
		t = "SHOP_NOW"
	}
	return map[string]any{"type": t, "value": map[string]string{"link": link}}
}

func (x *Executor) createCreative(ctx context.Context, act string, p model.Proposal, key string) (string, []string, error) {
	l := x.links()
	if l.PageID == "" {
		return "", nil, errors.New("no Facebook Page linked (Settings → Connections → Meta)")
	}
	format := str(p.Params, "format")
	if format == "" {
		format = "image"
	}
	spec := map[string]any{"page_id": l.PageID}
	form := url.Values{}
	var steps []string
	var lib *model.Creative
	switch format {
	case "video", "image":
		if format == "video" && str(p.Params, "video_path") != "" { // the owner's own video, read from the data folder
			vd, st, err := x.ownerVideo(ctx, act, p)
			if err != nil {
				return "", st, err
			}
			spec["video_data"] = vd
			steps = append(steps, st...)
			break
		}
		cid := str(p.Params, "creative_id")
		if cid == "" {
			cid = p.Target
		}
		c, err := x.media(ctx, act, cid)
		if err != nil {
			return "", nil, err
		}
		if c.MetaID != "" && str(p.Params, "destination") == "" {
			return c.MetaID, []string{"already uploaded"}, nil
		}
		if !strings.HasPrefix(c.Link, "https://") {
			return "", nil, errors.New("the creative's link must be an https:// shop page")
		}
		lib = &c
		if c.Media == "png" {
			spec["link_data"] = map[string]any{"message": c.PrimaryText, "link": c.Link, "name": c.Headline, "image_hash": c.ImageHash, "call_to_action": ctaFor(p, c, c.Link)}
		} else if c.Media == "" { // a shop photo by URL (the starter set before any design is rendered): link_data.picture
			spec["link_data"] = map[string]any{"message": c.PrimaryText, "link": c.Link, "name": c.Headline, "picture": c.ImageURL, "call_to_action": ctaFor(p, c, c.Link)}
		} else {
			thumb := str(p.Params, "thumbnail_url")
			vd := map[string]any{"video_id": c.VideoID, "message": c.PrimaryText, "title": c.Headline, "call_to_action": ctaFor(p, c, c.Link)}
			if thumb != "" {
				vd["image_url"] = thumb
			} else if th := str(p.Params, "thumbnail_creative_id"); th != "" {
				tc, err := x.media(ctx, act, th)
				if err != nil {
					return "", nil, err
				}
				vd["image_hash"] = tc.ImageHash
			} else {
				return "", nil, errors.New("a video ad needs a cover: thumbnail_url or thumbnail_creative_id")
			}
			spec["video_data"] = vd
		}
		steps = append(steps, format+" ready")
	case "carousel": // link_data.child_attachments, 2–10 cards (META-FACTS §14)
		var kids []map[string]any
		var first model.Creative
		for i, raw := range anyList(p.Params, "cards") {
			m := raw.(map[string]any)
			c, err := x.media(ctx, act, str(m, "creative_id"))
			if err != nil {
				return "", nil, err
			}
			if i == 0 {
				first = c
			}
			link := str(m, "link")
			if link == "" {
				link = c.Link
			}
			if !strings.HasPrefix(link, "https://kbeautybliss.com/") {
				return "", nil, fmt.Errorf("card %d: link must be a kbeautybliss.com page", i+1)
			}
			h := str(m, "headline")
			if h == "" {
				h = c.Headline
			}
			k := map[string]any{"link": link, "name": h, "call_to_action": ctaFor(p, c, link)}
			if c.Media == "png" {
				k["image_hash"] = c.ImageHash
			} else if c.Media == "" && ShopURL(c.ImageURL) { // a shop-photo creative (no stored media): the card shows the shop's photo
				k["picture"] = c.ImageURL
			} else {
				k["video_id"] = c.VideoID
				if th := str(m, "thumbnail_url"); th != "" {
					k["picture"] = th
				}
			}
			kids = append(kids, k)
		}
		msg := str(p.Params, "primary_text")
		if msg == "" {
			msg = first.PrimaryText
		}
		spec["link_data"] = map[string]any{"message": msg, "link": "https://kbeautybliss.com/", "child_attachments": kids, "multi_share_optimized": true}
		steps = append(steps, fmt.Sprintf("carousel of %d cards", len(kids)))
	case "flexible": // asset_feed_spec, no customization rules = dynamic creative (META-FACTS §14)
		var images, videos []map[string]any
		link := ""
		for _, id := range strs(p.Params, "creative_ids") {
			c, err := x.media(ctx, act, id)
			if err != nil {
				return "", nil, err
			}
			if c.Media == "png" {
				images = append(images, map[string]any{"hash": c.ImageHash})
			} else {
				videos = append(videos, map[string]any{"video_id": c.VideoID})
			}
			if link == "" {
				link = c.Link
			}
		}
		textObjs := func(xs []string) []map[string]string {
			var o []map[string]string
			for _, t := range xs {
				o = append(o, map[string]string{"text": t})
			}
			return o
		}
		formats := []string{"SINGLE_IMAGE"}
		if len(videos) > 0 {
			formats = []string{"AUTOMATIC_FORMAT"}
		}
		cta := str(p.Params, "cta")
		if cta == "" {
			cta = "SHOP_NOW"
		}
		afs := map[string]any{"images": images, "videos": videos, "bodies": textObjs(strs(p.Params, "texts")), "titles": textObjs(strs(p.Params, "headlines")),
			"ad_formats": formats, "call_to_action_types": []string{cta}, "link_urls": []map[string]string{{"website_url": link}}}
		form.Set("asset_feed_spec", jsonStr(afs))
		steps = append(steps, fmt.Sprintf("flexible: %d images, %d videos", len(images), len(videos)))
	case "catalog": // template_data + product_set_id (META-FACTS §14)
		t := p.Params["template"].(map[string]any)
		td := map[string]any{"message": str(t, "primary_text"), "name": str(t, "headline"), "link": str(t, "link"), "description": "{{product.price}}"}
		if str(t, "headline") == "" {
			td["name"] = "{{product.name}}"
		}
		spec["template_data"] = td
		form.Set("product_set_id", str(p.Params, "product_set_id"))
		steps = append(steps, "catalog template")
	case "collection": // an Instant Experience the owner built in Ads Manager (META-FACTS §14)
		c, err := x.media(ctx, act, str(p.Params, "creative_id"))
		if err != nil {
			return "", nil, err
		}
		spec["link_data"] = map[string]any{"message": c.PrimaryText, "name": c.Headline, "image_hash": c.ImageHash, "link": "https://fb.com/canvas_doc/" + str(p.Params, "canvas_id"),
			"call_to_action": map[string]any{"type": "LEARN_MORE"}}
		steps = append(steps, "collection on Instant Experience "+str(p.Params, "canvas_id"))
	}
	if l.InstagramUserID != "" {
		form.Set("instagram_user_id", l.InstagramUserID)
	}
	form.Set("object_story_spec", jsonStr(spec))
	form.Set("degrees_of_freedom_spec", jsonStr(enhancementsSpec(p.Params)))
	form.Set("name", managedName(format+" "+p.ID))
	id, err := x.Meta.Create(ctx, act, "adcreatives", form, key)
	if err != nil {
		return "", steps, err
	}
	if strings.HasPrefix(p.Target, "tmp:") {
		x.Ledger.MapTmp(p.Target, id)
	}
	if lib != nil && str(p.Params, "destination") == "" {
		lib.MetaID = id
		_ = x.Creatives.Put(*lib)
	}
	return id, append(steps, "creative "+id), nil
}

func (x *Executor) createAudience(ctx context.Context, act string, p model.Proposal) (string, []string, error) {
	l := x.links()
	pr := p.Params
	days, _ := num(pr, "retention_days")
	form := url.Values{"name": {managedName(str(pr, "name"))}}
	var rule map[string]any
	switch str(pr, "source") {
	case "website": // META-FACTS §15: event_sources pixel + an event filter
		rule = map[string]any{"inclusions": map[string]any{"operator": "or", "rules": []any{map[string]any{
			"event_sources":     []any{map[string]string{"id": l.PixelID, "type": "pixel"}},
			"retention_seconds": int(days) * 86400,
			"filter":            map[string]any{"operator": "and", "filters": []any{map[string]string{"field": "event", "operator": "eq", "value": str(pr, "event")}}}}}}}
		// exclusions (META-FACTS §15b: rule {inclusions, exclusions}, each a rule set): "bought in the last N days"
		if ev, xd := str(pr, "exclude_event"), intOf(pr, "exclude_days"); ev != "" && xd > 0 {
			rule["exclusions"] = map[string]any{"operator": "or", "rules": []any{map[string]any{
				"event_sources":     []any{map[string]string{"id": l.PixelID, "type": "pixel"}},
				"retention_seconds": xd * 86400,
				"filter":            map[string]any{"operator": "and", "filters": []any{map[string]string{"field": "event", "operator": "eq", "value": ev}}}}}}
		}
		form.Set("prefill", "1")
	case "page", "instagram":
		src, ev := map[string]string{"id": l.PageID, "type": "page"}, "page_engaged"
		if str(pr, "source") == "instagram" {
			src, ev = map[string]string{"id": l.InstagramUserID, "type": "ig_business"}, "ig_business_profile_all"
		}
		rule = map[string]any{"inclusions": map[string]any{"operator": "or", "rules": []any{map[string]any{
			"event_sources": []any{src}, "retention_seconds": int(days) * 86400,
			"filter": map[string]any{"operator": "and", "filters": []any{map[string]string{"field": "event", "operator": "eq", "value": ev}}}}}}}
	}
	form.Set("rule", jsonStr(rule))
	id, err := x.Meta.CreateAudience(ctx, act, form)
	if err != nil {
		return "", nil, err
	}
	if strings.HasPrefix(p.Target, "tmp:") {
		x.Ledger.MapTmp(p.Target, id)
	}
	return id, []string{"audience " + str(pr, "source")}, nil
}

func (x *Executor) createLookalike(ctx context.Context, act string, p model.Proposal) (string, []string, error) {
	r, _ := num(p.Params, "ratio")
	// v27: no country / location_spec in lookalike_spec (META-FACTS §15)
	form := url.Values{"name": {managedName(str(p.Params, "name"))}, "subtype": {"LOOKALIKE"}, "origin_audience_id": {str(p.Params, "origin_audience_id")},
		"lookalike_spec": {jsonStr(map[string]any{"ratio": r, "allow_international_seeds": true})}}
	id, err := x.Meta.CreateAudience(ctx, act, form)
	if err != nil {
		return "", nil, err
	}
	if strings.HasPrefix(p.Target, "tmp:") {
		x.Ledger.MapTmp(p.Target, id)
	}
	return id, []string{fmt.Sprintf("lookalike %.0f%%", r*100)}, nil
}

func (x *Executor) createProductSet(ctx context.Context, p model.Proposal) (string, []string, error) {
	id, err := x.Meta.CreateProductSet(ctx, x.links().CatalogID, managedName(str(p.Params, "name")), str(p.Params, "filter"))
	if err != nil {
		return "", nil, err
	}
	if strings.HasPrefix(p.Target, "tmp:") {
		x.Ledger.MapTmp(p.Target, id)
	}
	return id, []string{"product set"}, nil
}
