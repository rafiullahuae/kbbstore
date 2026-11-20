package brain

// Minimal Claude Messages API client on net/http. The engine is stdlib-only
// (CONTRACT.md: no new Go modules), so this is raw HTTP rather than the Go SDK.
// Shapes verified on platform.claude.com on 20 Nov 2026:
//   - header anthropic-version: 2026-10-21 (no temperature/top_p/top_k there)
//   - structured output: output_config.format = {type:"json_schema", schema}
//   - effort: output_config.effort; thinking is adaptive by default on 5.5
//   - web search server tool: {type:"web_search_20260209", name:"web_search",
//     max_uses, user_location:{type:"approximate", country, city, timezone}};
//     errors come back as a 200 with a web_search_tool_result error object;
//     stop_reason "pause_turn" means send the assistant turn back unchanged
//   - prompt caching: cache_control {type:"ephemeral", ttl:"1h"} on the stable
//     system block; volatile data goes in the user turn after it
//   - refusals: stop_reason "refusal"; server-side fallbacks opt-in with
//     anthropic-beta: server-side-fallback-2026-07-01 + fallbacks:"default"

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"

	"kaa/model"
)

const (
	APIVersion    = "2026-10-21"
	FallbackBeta  = "server-side-fallback-2026-07-01"
	ModelPlanner  = "claude-sonnet-5-5" // the owner's brief: Sonnet by default
	ModelSmall    = "claude-haiku-5-5"  // cheap model for small tasks (normalising, repairs)
	WebSearchTool = "web_search_20260209"
)

type Claude struct {
	APIKey   string
	BaseURL  string // default https://api.anthropic.com; tests point it at httptest
	HTTP     *http.Client
	Fallback bool          // send server-side fallbacks (dropped automatically if the API refuses the field)
	Backoff  time.Duration // retry base delay (default 3s; tests shorten it)
	LLM      model.LLM     // the engine's client: when set, every call goes through it (llm.go)
}

type Block struct {
	Type         string         `json:"type"`
	Text         string         `json:"text,omitempty"`
	CacheControl map[string]any `json:"cache_control,omitempty"`
}

type Message struct {
	Role    string `json:"role"`
	Content any    `json:"content"` // string or []json.RawMessage (assistant turns sent back unchanged)
}

type Request struct {
	Purpose   string         `json:"-"` // plan | research | copy | video (usage log)
	Model     string         `json:"model"`
	MaxTokens int            `json:"max_tokens"`
	System    []Block        `json:"system,omitempty"`
	Messages  []Message      `json:"messages"`
	Tools     []any          `json:"tools,omitempty"`
	Output    map[string]any `json:"output_config,omitempty"`
	Fallbacks any            `json:"fallbacks,omitempty"`
}

type Citation struct {
	Type      string `json:"type"`
	URL       string `json:"url"`
	Title     string `json:"title"`
	CitedText string `json:"cited_text"`
}

type ContentBlock struct {
	Type      string          `json:"type"`
	Text      string          `json:"text"`
	Citations []Citation      `json:"citations"`
	Name      string          `json:"name"`
	Input     json.RawMessage `json:"input"`
	Content   json.RawMessage `json:"content"`
}

type Response struct {
	ID          string            `json:"id"`
	Model       string            `json:"model"`
	Raw         []json.RawMessage `json:"content"`
	Blocks      []ContentBlock    `json:"-"`
	StopReason  string            `json:"stop_reason"`
	StopDetails *struct {
		Category    string `json:"category"`
		Explanation string `json:"explanation"`
	} `json:"stop_details"`
	Usage struct {
		InputTokens   int `json:"input_tokens"`
		OutputTokens  int `json:"output_tokens"`
		CacheRead     int `json:"cache_read_input_tokens"`
		CacheCreation int `json:"cache_creation_input_tokens"`
		ServerToolUse struct {
			WebSearch int `json:"web_search_requests"`
		} `json:"server_tool_use"`
	} `json:"usage"`
}

// Text joins the text blocks.
func (r *Response) Text() string {
	var b strings.Builder
	for _, c := range r.Blocks {
		if c.Type == "text" {
			b.WriteString(c.Text)
		}
	}
	return b.String()
}

type APIError struct {
	Status  int
	Type    string
	Message string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("claude: HTTP %d %s: %s", e.Status, e.Type, e.Message)
}

var ErrNoKey = errors.New("claude: no API key saved (Settings -> Connections)")

// Send posts one Messages request, retrying 429/5xx/529 twice with backoff.
func (c *Claude) Send(ctx context.Context, req Request) (*Response, error) {
	if c != nil && c.LLM != nil {
		return c.viaLLM(ctx, req)
	}
	if c == nil || c.APIKey == "" {
		return nil, ErrNoKey
	}
	base := c.BaseURL
	if base == "" {
		base = "https://api.anthropic.com"
	}
	hc := c.HTTP
	if hc == nil {
		hc = &http.Client{Timeout: 10 * time.Minute}
	}
	back := c.Backoff
	if back <= 0 {
		back = 3 * time.Second
	}
	useFallback := c.Fallback && strings.HasPrefix(req.Model, "claude-sonnet-5-5")
	var lastErr error
	for attempt := 0; attempt < 3; attempt++ {
		r := req
		if useFallback {
			r.Fallbacks = "default"
		}
		body, _ := json.Marshal(r)
		hr, err := http.NewRequestWithContext(ctx, "POST", base+"/v1/messages", bytes.NewReader(body))
		if err != nil {
			return nil, err
		}
		hr.Header.Set("content-type", "application/json")
		hr.Header.Set("x-api-key", c.APIKey)
		hr.Header.Set("anthropic-version", APIVersion)
		if useFallback {
			hr.Header.Set("anthropic-beta", FallbackBeta)
		}
		resp, err := hc.Do(hr)
		if err != nil {
			lastErr = err
			if ctx.Err() != nil {
				return nil, err
			}
			sleep(ctx, time.Duration(attempt+1)*back)
			continue
		}
		data, _ := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
		resp.Body.Close()
		if resp.StatusCode == 200 {
			var out Response
			if err := json.Unmarshal(data, &out); err != nil {
				return nil, fmt.Errorf("claude: bad JSON: %w", err)
			}
			for _, raw := range out.Raw {
				var b ContentBlock
				_ = json.Unmarshal(raw, &b)
				out.Blocks = append(out.Blocks, b)
			}
			return &out, nil
		}
		var e struct {
			Error struct{ Type, Message string } `json:"error"`
		}
		_ = json.Unmarshal(data, &e)
		ae := &APIError{Status: resp.StatusCode, Type: e.Error.Type, Message: e.Error.Message}
		lastErr = ae
		switch {
		case resp.StatusCode == 400 && useFallback && strings.Contains(strings.ToLower(ae.Message), "fallback"):
			useFallback = false // the account/model does not take fallbacks: send without
			continue
		case resp.StatusCode == 429 || resp.StatusCode >= 500:
			wait := time.Duration(attempt+1) * back
			if s, err := strconv.Atoi(resp.Header.Get("retry-after")); err == nil && s > 0 && s < 120 && c.Backoff == 0 {
				wait = time.Duration(s) * time.Second
			}
			sleep(ctx, wait)
			continue
		default:
			return nil, ae
		}
	}
	return nil, lastErr
}

func sleep(ctx context.Context, d time.Duration) {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
	case <-t.C:
	}
}

// price per million tokens: input, output, 1h cache write, cache read (USD,
// platform.claude.com pricing, read 20 Nov 2026).
var prices = map[string][4]float64{
	"claude-sonnet-5-5": {2, 10, 4, 0.10},
	"claude-haiku-5-5":  {0.10, 0.50, 0.20, 0.01},
	"claude-opus-5-5":   {4, 20, 8, 0.20},
}

// AddUsage accumulates usage and cost (web search $10 / 1,000).
func AddUsage(u *Usage, model string, r *Response) {
	if r == nil {
		return
	}
	p, ok := prices[model]
	if !ok {
		p = prices[ModelPlanner]
	}
	u.InputTokens += r.Usage.InputTokens
	u.OutputTokens += r.Usage.OutputTokens
	u.CacheReadTokens += r.Usage.CacheRead
	u.CacheWriteTokens += r.Usage.CacheCreation
	u.WebSearches += r.Usage.ServerToolUse.WebSearch
	u.CostUSD += (float64(r.Usage.InputTokens)*p[0] + float64(r.Usage.OutputTokens)*p[1] +
		float64(r.Usage.CacheCreation)*p[2] + float64(r.Usage.CacheRead)*p[3]) / 1e6
	u.CostUSD += float64(r.Usage.ServerToolUse.WebSearch) * 0.01
	u.CostUSD = float64(int(u.CostUSD*10000+0.5)) / 10000
}

// JSONOutput builds output_config for a structured JSON answer.
func JSONOutput(schema map[string]any, effort string) map[string]any {
	o := map[string]any{"format": map[string]any{"type": "json_schema", "schema": schema}}
	if effort != "" {
		o["effort"] = effort
	}
	return o
}

// CachedSystem is the stable system prompt with a 1-hour cache breakpoint
// (cycles run hours apart; the 5-minute cache would expire between them).
func CachedSystem(text string) []Block {
	return []Block{{Type: "text", Text: text, CacheControl: map[string]any{"type": "ephemeral", "ttl": "1h"}}}
}
