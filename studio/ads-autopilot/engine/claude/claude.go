// Package claude is a small Messages API client (POST /v1/messages) written on the standard library: the app is
// Go-stdlib-only, so the official Go SDK (a module) is not used; this file is the whole surface the brain needs.
//
//   - model configurable, default claude-sonnet-5-5 (the latest Sonnet; the owner's brief)
//   - client tools (strict JSON-schema tools) and the server web_search tool (web_search_20260209)
//   - prompt caching: top-level cache_control {type: ephemeral}, so the stable system prompt + tools are re-read at
//     the cache price on the next call
//   - server-side refusal fallback (beta server-side-fallback-2026-07-01, fallbacks: "default")
//   - every call metered: tokens, cache reads/writes, web searches, estimated USD, through OnUsage (the audit log)
//
// No thinking parameter is sent (adaptive by default on current models), no temperature/top_p/top_k, no forced
// tool_choice (all three are refused by the current models).
package claude

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync"
	"time"

	"kaa/model"
)

const (
	DefaultBase    = "https://api.anthropic.com"
	DefaultModel   = "claude-sonnet-5-5"
	APIVersion     = "2023-06-01"
	FallbackBeta   = "server-side-fallback-2026-07-01"
	WebSearchType  = "web_search_20260209"
	webSearchPerK  = 10.0 // USD per 1,000 searches
	defaultMaxToks = 16000
)

// prices per million tokens (input, output, cache read); cache writes are 1.25 × input.
var prices = map[string][3]float64{
	"claude-sonnet-5-5": {2, 10, 0.10},
	"claude-sonnet-5":   {2, 10, 0.20},
	"claude-opus-5-5":   {4, 20, 0.20},
	"claude-haiku-5-5":  {0.10, 0.50, 0.01},
}

type Client struct {
	Base    string
	Model   string
	Key     func() string
	HTTP    *http.Client
	OnUsage func(purpose, model string, u model.LLMUsage, ms int64, err error)
	Retries int

	mu    sync.Mutex
	total model.LLMUsage
}

func New(key func() string) *Client {
	return &Client{Base: DefaultBase, Model: DefaultModel, Key: key, HTTP: &http.Client{Timeout: 10 * time.Minute}, Retries: 2}
}

// Total: usage summed since launch (for the dashboard's cost line).
func (c *Client) Total() model.LLMUsage {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.total
}

type APIError struct {
	Status  int
	Type    string
	Message string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("Claude %d %s: %s", e.Status, e.Type, e.Message)
}

// Complete runs one Messages request. The brain drives any tool loop itself by appending resp.Content.
func (c *Client) Complete(ctx context.Context, req model.LLMRequest) (model.LLMResponse, error) {
	key := ""
	if c.Key != nil {
		key = c.Key()
	}
	if key == "" {
		return model.LLMResponse{}, errors.New("no Anthropic key saved (Settings → Connections → Claude)")
	}
	mdl := req.Model
	if mdl == "" {
		mdl = c.Model
	}
	maxT := req.MaxTokens
	if maxT <= 0 {
		maxT = defaultMaxToks
	}
	body := map[string]any{
		"model":         mdl,
		"max_tokens":    maxT,
		"messages":      req.Messages,
		"cache_control": map[string]string{"type": "ephemeral"},
		"fallbacks":     "default",
	}
	if req.System != "" {
		body["system"] = req.System
	}
	var tools []any
	for _, t := range req.Tools {
		tools = append(tools, t)
	}
	if req.WebSearch > 0 {
		tools = append(tools, map[string]any{"type": WebSearchType, "name": "web_search", "max_uses": req.WebSearch})
	}
	if len(tools) > 0 {
		body["tools"] = tools
	}
	if req.Effort != "" {
		body["output_config"] = map[string]string{"effort": req.Effort}
	}
	b, _ := json.Marshal(body)

	var lastErr error
	for attempt := 0; attempt <= c.Retries; attempt++ {
		if attempt > 0 {
			select {
			case <-ctx.Done():
				return model.LLMResponse{}, ctx.Err()
			case <-time.After(time.Duration(2<<attempt) * time.Second):
			}
		}
		t0 := time.Now()
		hr, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(c.Base, "/")+"/v1/messages", bytes.NewReader(b))
		if err != nil {
			return model.LLMResponse{}, err
		}
		hr.Header.Set("content-type", "application/json")
		hr.Header.Set("x-api-key", key)
		hr.Header.Set("anthropic-version", APIVersion)
		hr.Header.Set("anthropic-beta", FallbackBeta)
		resp, err := c.HTTP.Do(hr)
		if err != nil {
			lastErr = err
			c.meter(req.Purpose, mdl, model.LLMUsage{}, time.Since(t0).Milliseconds(), err)
			continue
		}
		rb, _ := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
		resp.Body.Close()
		if resp.StatusCode != 200 {
			var e struct {
				Error struct{ Type, Message string } `json:"error"`
			}
			_ = json.Unmarshal(rb, &e)
			lastErr = &APIError{Status: resp.StatusCode, Type: e.Error.Type, Message: e.Error.Message}
			c.meter(req.Purpose, mdl, model.LLMUsage{}, time.Since(t0).Milliseconds(), lastErr)
			if resp.StatusCode == 429 || resp.StatusCode >= 500 || resp.StatusCode == 408 {
				continue
			}
			return model.LLMResponse{}, lastErr
		}
		out, err := parse(rb)
		if err != nil {
			return model.LLMResponse{}, err
		}
		out.Usage.CostUSD = Cost(out.Model, out.Usage)
		c.meter(req.Purpose, out.Model, out.Usage, time.Since(t0).Milliseconds(), nil)
		if out.StopReason == "refusal" {
			return out, errors.New("Claude declined this request (refusal); nothing was acted on")
		}
		return out, nil
	}
	return model.LLMResponse{}, lastErr
}

func (c *Client) meter(purpose, mdl string, u model.LLMUsage, ms int64, err error) {
	c.mu.Lock()
	c.total.InputTokens += u.InputTokens
	c.total.OutputTokens += u.OutputTokens
	c.total.CacheReadTokens += u.CacheReadTokens
	c.total.CacheWriteTokens += u.CacheWriteTokens
	c.total.WebSearches += u.WebSearches
	c.total.CostUSD += u.CostUSD
	h := c.OnUsage
	c.mu.Unlock()
	if h != nil {
		h(purpose, mdl, u, ms, err)
	}
}

func parse(rb []byte) (model.LLMResponse, error) {
	var r struct {
		Model      string            `json:"model"`
		StopReason string            `json:"stop_reason"`
		Content    []json.RawMessage `json:"content"`
		Usage      struct {
			In    int `json:"input_tokens"`
			Out   int `json:"output_tokens"`
			CR    int `json:"cache_read_input_tokens"`
			CW    int `json:"cache_creation_input_tokens"`
			Tools struct {
				WS int `json:"web_search_requests"`
			} `json:"server_tool_use"`
		} `json:"usage"`
	}
	if err := json.Unmarshal(rb, &r); err != nil {
		return model.LLMResponse{}, fmt.Errorf("Claude answer was not JSON: %v", err)
	}
	out := model.LLMResponse{Model: r.Model, StopReason: r.StopReason, Content: r.Content,
		Usage: model.LLMUsage{InputTokens: r.Usage.In, OutputTokens: r.Usage.Out, CacheReadTokens: r.Usage.CR, CacheWriteTokens: r.Usage.CW, WebSearches: r.Usage.Tools.WS}}
	var texts []string
	for _, raw := range r.Content {
		var blk struct {
			Type      string          `json:"type"`
			Text      string          `json:"text"`
			ID        string          `json:"id"`
			Name      string          `json:"name"`
			Input     json.RawMessage `json:"input"`
			Citations []struct {
				URL string `json:"url"`
			} `json:"citations"`
		}
		if json.Unmarshal(raw, &blk) != nil {
			continue
		}
		switch blk.Type {
		case "text":
			texts = append(texts, blk.Text)
			for _, ci := range blk.Citations {
				if ci.URL != "" {
					out.Citations = append(out.Citations, ci.URL)
				}
			}
		case "tool_use":
			out.ToolUses = append(out.ToolUses, model.ToolUse{ID: blk.ID, Name: blk.Name, Input: blk.Input})
		}
	}
	out.Text = strings.Join(texts, "")
	return out, nil
}

// Cost: an estimate in USD from list prices (cache writes at 1.25 × input, web search $10 / 1,000).
func Cost(mdl string, u model.LLMUsage) float64 {
	p, ok := prices[mdl]
	if !ok {
		p = prices[DefaultModel]
	}
	return (float64(u.InputTokens)*p[0]+float64(u.OutputTokens)*p[1]+float64(u.CacheReadTokens)*p[2]+float64(u.CacheWriteTokens)*p[0]*1.25)/1e6 +
		float64(u.WebSearches)*webSearchPerK/1000
}

// Ping: checks the key and the model without spending tokens (GET /v1/models/{model}).
func (c *Client) Ping(ctx context.Context) error {
	key := ""
	if c.Key != nil {
		key = c.Key()
	}
	if key == "" {
		return errors.New("no Anthropic key saved (Settings → Connections → Claude)")
	}
	hr, err := http.NewRequestWithContext(ctx, http.MethodGet, strings.TrimRight(c.Base, "/")+"/v1/models/"+c.Model, nil)
	if err != nil {
		return err
	}
	hr.Header.Set("x-api-key", key)
	hr.Header.Set("anthropic-version", APIVersion)
	resp, err := c.HTTP.Do(hr)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	rb, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode != 200 {
		var e struct {
			Error struct{ Type, Message string } `json:"error"`
		}
		_ = json.Unmarshal(rb, &e)
		return &APIError{Status: resp.StatusCode, Type: e.Error.Type, Message: e.Error.Message}
	}
	return nil
}
