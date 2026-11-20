package brain

// Claude through the engine's LLM client (model.LLM). The engine owns the key
// (macOS Keychain), the HTTP client, prompt caching, refusal fallbacks and the
// usage log. Its request has no output_config.format, so a structured answer is
// asked for as ONE strict client tool, "respond", whose input_schema is the
// schema; the tool input is handed back as the answer text. Forced tool_choice
// is refused by the current models, so the instruction to call it is in the
// system prompt, and a missing call is treated like an unparseable answer
// (the planner's repair round / the copy regeneration handle it).

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"kaa/model"
)

const respondTool = "respond"

func (c *Claude) viaLLM(ctx context.Context, req Request) (*Response, error) {
	var sys []string
	for _, b := range req.System {
		sys = append(sys, b.Text)
	}
	lr := model.LLMRequest{Purpose: firstNonEmpty(req.Purpose, "brain"), Model: req.Model, MaxTokens: req.MaxTokens}
	structured := false
	if req.Output != nil {
		if e, ok := req.Output["effort"].(string); ok {
			lr.Effort = e
		}
		if f, ok := req.Output["format"].(map[string]any); ok {
			structured = true
			t, _ := json.Marshal(map[string]any{"name": respondTool, "description": "Return your complete answer as this tool's input. Call it exactly once.",
				"input_schema": f["schema"], "strict": true})
			lr.Tools = append(lr.Tools, t)
			sys = append(sys, "Answer ONLY by calling the respond tool exactly once with the complete answer; write no other text.")
		}
	}
	for _, t := range req.Tools {
		m, _ := t.(map[string]any)
		if ty, _ := m["type"].(string); strings.HasPrefix(ty, "web_search") {
			lr.WebSearch = 8
			if n, ok := m["max_uses"].(int); ok && n > 0 {
				lr.WebSearch = n
			}
			continue
		}
		raw, _ := json.Marshal(t)
		lr.Tools = append(lr.Tools, raw)
	}
	lr.System = strings.Join(sys, "\n\n")
	// messages: a user turn after an assistant turn that called "respond" must
	// open with the tool_result for it
	var pending []string
	for _, m := range req.Messages {
		content := m.Content
		if m.Role == "user" && len(pending) > 0 {
			var blocks []any
			for _, id := range pending {
				blocks = append(blocks, map[string]any{"type": "tool_result", "tool_use_id": id, "content": "received"})
			}
			if s, ok := content.(string); ok {
				blocks = append(blocks, map[string]any{"type": "text", "text": s})
			}
			content, pending = blocks, nil
		}
		if m.Role == "assistant" {
			if arr, ok := content.([]any); ok {
				for _, x := range arr {
					var b struct{ Type, ID, Name string }
					raw, _ := json.Marshal(x)
					_ = json.Unmarshal(raw, &b)
					if b.Type == "tool_use" && b.Name == respondTool {
						pending = append(pending, b.ID)
					}
				}
			}
		}
		lr.Messages = append(lr.Messages, model.LLMMessage{Role: m.Role, Content: content})
	}
	resp, err := c.LLM.Complete(ctx, lr)
	if err != nil {
		return nil, err
	}
	out := &Response{Model: resp.Model, StopReason: resp.StopReason, Raw: resp.Content}
	for _, raw := range resp.Content {
		var b ContentBlock
		_ = json.Unmarshal(raw, &b)
		if b.Type == "tool_use" && b.Name == respondTool {
			continue
		}
		out.Blocks = append(out.Blocks, b)
	}
	if structured {
		var ans string
		for _, tu := range resp.ToolUses {
			if tu.Name == respondTool {
				ans = string(tu.Input)
			}
		}
		// the answer replaces any prose
		var kept []ContentBlock
		for _, b := range out.Blocks {
			if b.Type != "text" {
				kept = append(kept, b)
			}
		}
		out.Blocks = append(kept, ContentBlock{Type: "text", Text: ans})
		if out.StopReason == "tool_use" {
			out.StopReason = "end_turn"
		}
		if ans == "" && out.StopReason == "end_turn" {
			out.Blocks[len(out.Blocks)-1].Text = "(no respond call)"
		}
	}
	out.Usage.InputTokens, out.Usage.OutputTokens = resp.Usage.InputTokens, resp.Usage.OutputTokens
	out.Usage.CacheRead, out.Usage.CacheCreation = resp.Usage.CacheReadTokens, resp.Usage.CacheWriteTokens
	out.Usage.ServerToolUse.WebSearch = resp.Usage.WebSearches
	return out, nil
}

func jsonUnmarshalText(r *Response, v any) error {
	if r.StopReason == "refusal" || r.StopReason == "max_tokens" {
		return fmt.Errorf("model stopped: %s", r.StopReason)
	}
	return json.Unmarshal([]byte(strings.TrimSpace(r.Text())), v)
}
