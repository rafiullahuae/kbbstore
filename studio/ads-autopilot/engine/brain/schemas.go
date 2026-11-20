package brain

// JSON schemas for structured outputs. Structured outputs need
// additionalProperties:false and every property listed in required, and do
// not support min/max constraints -- so "optional" values are nullable
// (anyOf with null) and ranges are checked in code after parsing.

func obj(props map[string]any) map[string]any {
	req := make([]string, 0, len(props))
	for k := range props {
		req = append(req, k)
	}
	sortStrings(req)
	return map[string]any{"type": "object", "properties": props, "required": req, "additionalProperties": false}
}
func str() map[string]any                    { return map[string]any{"type": "string"} }
func num() map[string]any                    { return map[string]any{"type": "number"} }
func arr(item map[string]any) map[string]any { return map[string]any{"type": "array", "items": item} }
func enum(v ...string) map[string]any        { return map[string]any{"type": "string", "enum": v} }
func null(t map[string]any) map[string]any {
	return map[string]any{"anyOf": []any{t, map[string]any{"type": "null"}}}
}

var templateIDs = []string{"clean", "glow", "ingredient", "offer", "routine", "qa"}

var proposalKinds = []string{"create_campaign", "create_adset", "create_ad", "upload_creative", "pause", "resume", "set_budget", "set_bid", "archive"}

func plannerSchema() map[string]any {
	params := obj(map[string]any{
		"daily_budget_aed": null(num()),
		"bid_amount_aed":   null(num()),
		"name":             null(str()),
		"adset_id":         null(str()),
		"campaign_id":      null(str()),
		"creative_id":      null(str()),
		"lang":             null(enum("ar", "en")),
		"countries":        null(arr(str())),
		"objective":        null(str()),
		"video_id":         null(str()),
		"media":            null(enum("image", "video")),
	})
	return obj(map[string]any{
		"summary_en": str(),
		"summary_ar": str(),
		"tests": arr(obj(map[string]any{
			"name": str(), "hypothesis": str(), "lang": enum("ar", "en"), "angle": str(),
			"product_ids": arr(str()), "template": enum(templateIDs...), "budget_aed": num(),
		})),
		"allocation": arr(obj(map[string]any{"bucket": str(), "share_pct": num(), "why": str()})),
		"watch":      arr(str()),
		"proposals": arr(obj(map[string]any{
			"kind": enum(proposalKinds...), "target": str(), "params": params, "why": str(),
			"confidence": num(), "evidence": arr(str()),
		})),
		"copy_requests": arr(obj(map[string]any{
			"product_ids": arr(str()), "angle": str(), "template": enum(templateIDs...), "brief": str(), "video_id": null(str()),
		})),
		"report_en": str(),
		"report_ar": str(),
		"learnings": arr(str()),
	})
}

func copySchema() map[string]any {
	set := obj(map[string]any{
		"lang": enum("ar", "en"), "hooks": arr(str()), "primary_text": str(), "headline": str(),
		"description": str(), "cta": enum("SHOP_NOW", "LEARN_MORE", "ORDER_NOW", "BUY_NOW"),
		"sub": str(), "bullets": arr(str()), "badge": str(),
	})
	return obj(map[string]any{"sets": arr(set)})
}

func researchSchema() map[string]any {
	return obj(map[string]any{
		"notes": arr(obj(map[string]any{
			"topic":       enum("trend", "ingredient", "competitor", "arabic_audience", "format", "hook", "season"),
			"title":       str(),
			"title_ar":    str(),
			"body":        str(),
			"body_ar":     str(),
			"angles":      arr(str()),
			"source_urls": arr(str()),
		})),
	})
}

func sortStrings(s []string) {
	for i := 1; i < len(s); i++ {
		for j := i; j > 0 && s[j] < s[j-1]; j-- {
			s[j], s[j-1] = s[j-1], s[j]
		}
	}
}
