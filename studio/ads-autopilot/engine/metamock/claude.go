package metamock

// The fake Claude's scripted answers (-mock only). The brain asks for a structured answer as ONE strict client tool
// "respond" whose input_schema is the schema, and searches the web with the server tool web_search; the fake answers
// each by what is asked (the respond schema's properties, or a web search and its system prompt), so planning,
// research, trending products, copy and video analysis all run end to end without the real API:
//
//	schema with "proposals" → the planner     "sets"  → copy (AR + EN)         "notes" → research notes
//	schema with "items"     → trending items  "hook"  → video analysis (vision)
//	web_search tool only    → a cited briefing (trending or research, by the system prompt)
//	anything else           → plain text ("mock answer": the connection test)
//
// Every fixture is policy-clean copy for products in the fake feed (Feed), with sources only from the URLs the fake
// search "returned", so the brain's own checks (lint, unsourced-note drop, catalogue match) pass on their merits.

import (
	"encoding/json"
	"strings"
)

const (
	srcTrend = "https://example.com/mock/gcc-kbeauty-bestsellers-2026"
	srcSun   = "https://example.com/mock/uae-spf-season"
	srcComp  = "https://example.com/mock/uae-kbeauty-shops-ads"
)

const fixPlan = `{"summary_en":"Arabic-first: keep the best sellers running, judge every ad on settled days, test a routine angle next.",
"summary_ar":"العربية أولاً: إبقاء الأكثر مبيعاً، والحكم على كل إعلان بالأيام المكتملة، واختبار زاوية الروتين لاحقاً.",
"tests":[{"name":"AR cleanser morning routine","hypothesis":"A routine hook beats an ingredient hook for Arabic shoppers","lang":"ar","angle":"morning routine","product_ids":["3195"],"template":"routine","budget_aed":40,"format":"single_image","audience":"women_gcc_ar"}],
"allocation":[{"bucket":"Arabic prospecting","share_pct":56,"why":"owner: Arabic first"},{"bucket":"English prospecting","share_pct":24,"why":"expats"},{"bucket":"Creative tests","share_pct":20,"why":"fresh creative"}],
"watch":["CPM rising into White Friday"],
"proposals":[],
"copy_requests":[{"product_ids":["3195"],"angle":"morning routine","template":"routine","brief":"gentle low-pH morning cleanse","video_id":null}],
"report_en":"Steady day: results judged on settled days; one Arabic routine pair written for the next batch.",
"report_ar":"يوم مستقر: النتائج تُقيَّم بالأيام المكتملة؛ وكُتب زوج عربي لروتين الصباح للدفعة القادمة.",
"learnings":["Arabic routine hooks are the next test"]}`

const fixCopy = `{"sets":[
{"lang":"ar","hooks":["صباحك يبدأ بلطف"],"primary_text":"منتج كوري أصلي من K-Beauty Bliss، خطوة يومية لطيفة في روتينك. توصيل داخل الإمارات.","headline":"روتين كوري أصيل","description":"أصلي من كوريا","cta":"SHOP_NOW","sub":"روتين الصباح","bullets":["أصلي ١٠٠٪","توصيل داخل الإمارات"],"badge":"K-Beauty"},
{"lang":"en","hooks":["The gentlest start to your morning"],"primary_text":"Authentic Korean skincare from K-Beauty Bliss, a gentle daily step in your routine. Delivery across the UAE.","headline":"An authentic K-beauty routine","description":"Authentic from Korea","cta":"SHOP_NOW","sub":"Morning ritual","bullets":["100% authentic","UAE delivery"],"badge":"K-Beauty"}]}`

const fixNotes = `{"notes":[
{"topic":"trend","title":"Sunscreen and gentle cleansers lead GCC K-beauty baskets","title_ar":"واقيات الشمس والغسولات اللطيفة في صدارة سلال الجمال الكوري في الخليج","body":"Lightweight SPF and low-pH cleansers are the most re-bought K-beauty items in the UAE this season.","body_ar":"واقيات الشمس الخفيفة والغسولات منخفضة الحموضة هي الأكثر إعادة شراء في الإمارات هذا الموسم.","angles":["daily SPF ritual","gentle morning cleanse"],"source_urls":["` + srcTrend + `","` + srcSun + `"]},
{"topic":"arabic_audience","title":"Arabic routine content outperforms ingredient lists","title_ar":"محتوى الروتين بالعربية يتفوق على قوائم المكونات","body":"Step-by-step routines in Arabic hold attention longer than ingredient call-outs.","body_ar":"الروتين خطوة بخطوة بالعربية يجذب الانتباه أطول من إبراز المكونات.","angles":["3-step routine"],"source_urls":["` + srcTrend + `"]},
{"topic":"competitor","title":"UAE K-beauty shops push bundles before White Friday","title_ar":"متاجر الجمال الكوري في الإمارات تروّج للباقات قبل الجمعة البيضاء","body":"Competitors advertise routine bundles and free delivery thresholds.","body_ar":"يعلن المنافسون عن باقات الروتين وحدود التوصيل المجاني.","angles":["routine bundle"],"source_urls":["` + srcComp + `"]}]}`

const fixItems = `{"items":[
{"rank":1,"name":"Relief Sun SPF50+","brand":"Beauty of Joseon","kind":"product","momentum":"rising","region":"gcc","why":"Top re-buy in the UAE heat","why_ar":"الأكثر إعادة شراء في حرّ الإمارات","source_urls":["` + srcSun + `"]},
{"rank":2,"name":"Low pH Good Morning Gel Cleanser","brand":"COSRX","kind":"product","momentum":"steady","region":"global","why":"Evergreen best seller","why_ar":"الأكثر مبيعاً على الدوام","source_urls":["` + srcTrend + `"]}]}`

const fixVision = `{"hook":"Hand squeezes the gel cleanser onto fingertips in close-up","hook_strength":8,"language":"ar",
"on_screen_text":[{"text":"صباحكِ يبدأ بلطف","t":0.5,"y_top":0.40,"y_bottom":0.48}],
"captions":true,"products":[{"product_id":"3195","confidence":0.9,"first_seen_t":0.5}],
"brands_seen":["COSRX"],"angle":"routine","quality_issues":[],"trims":[],
"suggestions":["Open on the gel texture","Keep the Arabic caption above the bottom third"],"summary_en":"A clean routine clip.","summary_ar":"مقطع روتين أنيق.","negative_framing":false}`

// claudeAnswer: the response body for one /v1/messages request, and its kind (for the counters).
func claudeAnswer(body map[string]any) (map[string]any, string) {
	sys, _ := body["system"].(string)
	var props map[string]any
	search := false
	tools, _ := body["tools"].([]any)
	for _, t := range tools {
		m, _ := t.(map[string]any)
		if ty, _ := m["type"].(string); strings.HasPrefix(ty, "web_search") {
			search = true
		}
		if m["name"] == "respond" {
			if sc, ok := m["input_schema"].(map[string]any); ok {
				props, _ = sc["properties"].(map[string]any)
			}
		}
	}
	usage := map[string]any{"input_tokens": 1800, "output_tokens": 600, "cache_read_input_tokens": 1400, "cache_creation_input_tokens": 0, "server_tool_use": map[string]any{"web_search_requests": 0}}
	msg := func(stop string, content []any) map[string]any {
		return map[string]any{"id": "msg_mock", "type": "message", "role": "assistant", "model": body["model"], "stop_reason": stop, "content": content, "usage": usage}
	}
	respond := func(fix string) map[string]any {
		return msg("tool_use", []any{map[string]any{"type": "tool_use", "id": "toolu_mock_1", "name": "respond", "input": json.RawMessage(fix)}})
	}
	switch {
	case props["proposals"] != nil:
		return respond(fixPlan), "plan"
	case props["sets"] != nil:
		return respond(fixCopy), "copy"
	case props["notes"] != nil:
		return respond(fixNotes), "research-notes"
	case props["items"] != nil:
		return respond(fixItems), "trending-items"
	case props["hook"] != nil:
		return respond(fixVision), "video"
	case search:
		usage["server_tool_use"] = map[string]any{"web_search_requests": 2}
		res := func(u, t string) map[string]any {
			return map[string]any{"type": "web_search_result", "url": u, "title": t}
		}
		cite := func(u, t string) map[string]any {
			return map[string]any{"type": "web_search_result_location", "url": u, "title": t, "cited_text": t}
		}
		content := []any{
			map[string]any{"type": "server_tool_use", "id": "srvtoolu_mock_1", "name": "web_search", "input": map[string]any{"query": "K-beauty GCC trending"}},
			map[string]any{"type": "web_search_tool_result", "tool_use_id": "srvtoolu_mock_1", "content": []any{
				res(srcTrend, "GCC K-beauty best sellers 2026"), res(srcSun, "UAE SPF season"), res(srcComp, "UAE K-beauty shops on Meta")}},
		}
		if strings.HasPrefix(sys, "You track what is trending") {
			content = append(content, map[string]any{"type": "text", "text": "1. Beauty of Joseon Relief Sun SPF50+ is rising in the GCC. 2. COSRX Low pH Good Morning Gel Cleanser stays a best seller.",
				"citations": []any{cite(srcSun, "UAE SPF season"), cite(srcTrend, "GCC K-beauty best sellers 2026")}})
			return msg("end_turn", content), "trending-search"
		}
		content = append(content, map[string]any{"type": "text", "text": "Light SPF and low-pH cleansers lead UAE K-beauty baskets; Arabic routine content holds attention; competitors push bundles before White Friday.",
			"citations": []any{cite(srcTrend, "GCC K-beauty best sellers 2026"), cite(srcSun, "UAE SPF season"), cite(srcComp, "UAE K-beauty shops on Meta")}})
		return msg("end_turn", content), "research-search"
	}
	return msg("end_turn", []any{map[string]any{"type": "text", "text": "mock answer"}}), "text"
}
