// Package meta is the Graph / Marketing API client: a versioned base URL, typed errors, retries with backoff, the
// rate-limit headers read on every answer, idempotency keys on creates. Facts it is coded to are in
// docs/META-FACTS.md with the Meta page each one was read on.
package meta

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/rand/v2"
	"mime/multipart"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	DefaultBase    = "https://graph.facebook.com"
	DefaultVersion = "v27.0" // META-FACTS §1: released 4 Nov 2026, "Available until TBD"
)

// Error: Meta's error object, typed.
type Error struct {
	HTTPStatus int    `json:"http_status"`
	Code       int    `json:"code"`
	Subcode    int    `json:"error_subcode,omitempty"`
	Type       string `json:"type,omitempty"`
	Message    string `json:"message"`
	UserMsg    string `json:"error_user_msg,omitempty"`
	Transient  bool   `json:"is_transient,omitempty"`
	TraceID    string `json:"fbtrace_id,omitempty"`
	Network    bool   `json:"network,omitempty"` // no answer at all (timeout, connection lost)
}

func (e *Error) Error() string {
	if e.Network {
		return "Meta did not answer: " + e.Message
	}
	s := fmt.Sprintf("Meta error %d", e.Code)
	if e.Subcode != 0 {
		s += fmt.Sprintf("/%d", e.Subcode)
	}
	s += ": " + e.Message
	if e.UserMsg != "" {
		s += " (" + e.UserMsg + ")"
	}
	return s
}

// RateLimited: one of Meta's throttling answers (META-FACTS §5): 4, 17, 32, 613, 80000-80014.
func (e *Error) RateLimited() bool {
	switch e.Code {
	case 4, 17, 32, 613:
		return true
	}
	return e.Code >= 80000 && e.Code <= 80014
}

// TokenInvalid: the token expired, was revoked or is otherwise unusable (190, 102, subcodes 463/467). Never retried.
func (e *Error) TokenInvalid() bool {
	return e.Code == 190 || e.Code == 102 || e.Subcode == 463 || e.Subcode == 467
}

// HourBlock: the budget of this ad set changed 4 times this hour (613/1487632) or the account spending limit changed
// 10 times today (17/1885172) -- retrying is pointless and counts against us.
func (e *Error) HourBlock() bool {
	return e.Code == 613 && e.Subcode == 1487632 || e.Code == 17 && e.Subcode == 1885172
}

func (e *Error) retryable() bool {
	if e.TokenInvalid() || e.HourBlock() {
		return false
	}
	return e.Network || e.Transient || e.HTTPStatus >= 500 || e.RateLimited()
}

func AsError(err error) (*Error, bool) {
	var me *Error
	ok := errors.As(err, &me)
	return me, ok
}

// Usage: the highest percentage any rate-limit header reported on the last answer, and when access comes back.
type Usage struct {
	At          time.Time `json:"at"`
	MaxPct      float64   `json:"max_pct"`
	RegainMin   int       `json:"regain_minutes"`
	Tier        string    `json:"ads_api_access_tier,omitempty"`
	InsightsPct float64   `json:"insights_pct"`
}

// Call: what the client tells its OnCall hook after every request (no token, no query string).
type Call struct {
	Method   string          `json:"method"`
	Path     string          `json:"path"`
	Status   int             `json:"status"`
	Ms       int64           `json:"ms"`
	Attempt  int             `json:"attempt"`
	Err      *Error          `json:"error,omitempty"`
	Usage    Usage           `json:"usage"`
	IdemKey  string          `json:"idempotency_key,omitempty"`
	Response json.RawMessage `json:"response,omitempty"` // writes only (the audit log keeps every Meta write answer)
}

type Client struct {
	Base       string
	Version    string
	Token      func() string
	AppSecret  func() string // optional: adds appsecret_proof
	HTTP       *http.Client
	MaxRetries int
	Sleep      func(ctx context.Context, d time.Duration) error
	OnCall     func(Call)

	mu    sync.Mutex
	usage Usage
}

func New(token func() string) *Client {
	return &Client{Base: DefaultBase, Version: DefaultVersion, Token: token, HTTP: &http.Client{Timeout: 90 * time.Second}, MaxRetries: 4}
}

func (c *Client) Usage() Usage {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.usage
}

func (c *Client) sleep(ctx context.Context, d time.Duration) error {
	if c.Sleep != nil {
		return c.Sleep(ctx, d)
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

func (c *Client) url(path string) string {
	return strings.TrimRight(c.Base, "/") + "/" + c.Version + "/" + strings.TrimLeft(path, "/")
}

func (c *Client) auth(v url.Values) {
	tok := c.Token()
	v.Set("access_token", tok)
	if c.AppSecret != nil {
		if sec := c.AppSecret(); sec != "" {
			m := hmac.New(sha256.New, []byte(sec))
			m.Write([]byte(tok))
			v.Set("appsecret_proof", hex.EncodeToString(m.Sum(nil)))
		}
	}
}

// Get: GET path with query q; the JSON answer is decoded into out.
func (c *Client) Get(ctx context.Context, path string, q url.Values, out any) error {
	return c.do(ctx, http.MethodGet, path, q, "", nil, out)
}

// Post: a form POST. idemKey (creates only: campaigns, adsets, ads, adcreatives) goes in X-Client-Idempotent-Key so a
// retry returns the first object instead of making a second one (META-FACTS §11).
func (c *Client) Post(ctx context.Context, path string, form url.Values, idemKey string, out any) error {
	return c.do(ctx, http.MethodPost, path, form, idemKey, nil, out)
}

func (c *Client) Delete(ctx context.Context, path string, out any) error {
	return c.do(ctx, http.MethodDelete, path, url.Values{}, "", nil, out)
}

// PostFile: multipart POST with one file field (ad videos).
func (c *Client) PostFile(ctx context.Context, path string, form url.Values, field, filename string, data []byte, out any) error {
	return c.do(ctx, http.MethodPost, path, form, "", &filePart{field, filename, data}, out)
}

type filePart struct {
	field, name string
	data        []byte
}

func (c *Client) do(ctx context.Context, method, path string, params url.Values, idemKey string, file *filePart, out any) error {
	if c.Token == nil || c.Token() == "" {
		return &Error{Code: 190, Message: "no Meta token saved (Settings → Connections → Meta)"}
	}
	write := method != http.MethodGet
	var last *Error
	for attempt := 0; attempt <= c.MaxRetries; attempt++ {
		if attempt > 0 {
			wait := backoff(attempt, last)
			if wait < 0 {
				break
			}
			if err := c.sleep(ctx, wait); err != nil {
				return err
			}
		}
		v := url.Values{}
		for k, vs := range params {
			v[k] = vs
		}
		c.auth(v)
		var req *http.Request
		var err error
		switch {
		case method == http.MethodGet || method == http.MethodDelete:
			req, err = http.NewRequestWithContext(ctx, method, c.url(path)+"?"+v.Encode(), nil)
		case file != nil:
			var buf bytes.Buffer
			mw := multipart.NewWriter(&buf)
			for k, vs := range v {
				for _, x := range vs {
					_ = mw.WriteField(k, x)
				}
			}
			fw, _ := mw.CreateFormFile(file.field, file.name)
			_, _ = fw.Write(file.data)
			_ = mw.Close()
			req, err = http.NewRequestWithContext(ctx, method, c.url(path), &buf)
			if req != nil {
				req.Header.Set("Content-Type", mw.FormDataContentType())
			}
		default:
			req, err = http.NewRequestWithContext(ctx, method, c.url(path), strings.NewReader(v.Encode()))
			if req != nil {
				req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
			}
		}
		if err != nil {
			return err
		}
		if idemKey != "" {
			req.Header.Set("X-Client-Idempotent-Key", idemKey)
		}
		t0 := time.Now()
		resp, err := c.HTTP.Do(req)
		call := Call{Method: method, Path: path, Attempt: attempt + 1, IdemKey: idemKey}
		if err != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			last = &Error{Network: true, Message: scrub(err.Error())}
			call.Err, call.Ms = last, time.Since(t0).Milliseconds()
			c.report(call)
			if !write || idemKey != "" || isIdempotentWrite(path, params) {
				continue
			}
			return last
		}
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
		resp.Body.Close()
		call.Status, call.Ms = resp.StatusCode, time.Since(t0).Milliseconds()
		call.Usage = c.readUsage(resp.Header)
		if resp.StatusCode >= 400 || bytes.HasPrefix(bytes.TrimSpace(body), []byte(`{"error"`)) {
			last = parseError(resp.StatusCode, body)
			call.Err = last
			c.report(call)
			if last.retryable() {
				continue
			}
			return last
		}
		if write {
			call.Response = json.RawMessage(clipJSON(body))
		}
		c.report(call)
		if out != nil {
			if err := json.Unmarshal(body, out); err != nil {
				return fmt.Errorf("Meta answer for %s was not JSON: %v", path, err)
			}
		}
		return nil
	}
	return last
}

// isIdempotentWrite: an update that sets fields to fixed values (status, budget, spend_cap, a rule) is safe to send
// twice; a create without an idempotency key is not.
func isIdempotentWrite(path string, params url.Values) bool {
	p := strings.Trim(path, "/")
	if strings.Contains(p, "/") { // act_x/campaigns, act_x/adimages … = creates
		return false
	}
	return !strings.HasPrefix(p, "act_") || params.Has("spend_cap") || params.Has("spend_cap_action")
}

func backoff(attempt int, last *Error) time.Duration {
	base := time.Duration(1<<uint(attempt)) * time.Second // 2s, 4s, 8s, 16s
	if last != nil && last.Code == 613 && last.Subcode == 4841018 && base < 30*time.Second {
		base = 30 * time.Second // one edit per object every 30 s (META-FACTS §5)
	}
	if base > 60*time.Second {
		base = 60 * time.Second
	}
	return base + time.Duration(rand.IntN(500))*time.Millisecond
}

func (c *Client) report(call Call) {
	c.mu.Lock()
	if !call.Usage.At.IsZero() {
		c.usage = call.Usage
	}
	h := c.OnCall
	c.mu.Unlock()
	if h != nil {
		h(call)
	}
}

func parseError(status int, body []byte) *Error {
	var w struct {
		Error struct {
			Message   string `json:"message"`
			Type      string `json:"type"`
			Code      int    `json:"code"`
			Subcode   int    `json:"error_subcode"`
			UserMsg   string `json:"error_user_msg"`
			Transient bool   `json:"is_transient"`
			Trace     string `json:"fbtrace_id"`
		} `json:"error"`
	}
	if json.Unmarshal(body, &w) != nil || w.Error.Message == "" && w.Error.Code == 0 {
		return &Error{HTTPStatus: status, Code: status, Message: "HTTP " + strconv.Itoa(status) + ": " + scrub(string(clipJSON(body)))}
	}
	e := w.Error
	return &Error{HTTPStatus: status, Code: e.Code, Subcode: e.Subcode, Type: e.Type, Message: scrub(e.Message), UserMsg: scrub(e.UserMsg), Transient: e.Transient, TraceID: e.Trace}
}

// readUsage: X-Business-Use-Case-Usage (per business object, a list), X-Ad-Account-Usage, X-App-Usage and
// X-FB-Ads-Insights-Throttle. We keep the highest percentage and the longest estimated_time_to_regain_access.
func (c *Client) readUsage(h http.Header) Usage {
	u := Usage{}
	seen := false
	if v := h.Get("X-Business-Use-Case-Usage"); v != "" {
		var m map[string][]map[string]any
		if json.Unmarshal([]byte(v), &m) == nil {
			seen = true
			for _, list := range m {
				for _, e := range list {
					for _, k := range []string{"call_count", "total_cputime", "total_time"} {
						u.MaxPct = maxf(u.MaxPct, num(e[k]))
					}
					if r := int(num(e["estimated_time_to_regain_access"])); r > u.RegainMin {
						u.RegainMin = r
					}
					if t, ok := e["ads_api_access_tier"].(string); ok {
						u.Tier = t
					}
				}
			}
		}
	}
	for _, name := range []string{"X-Ad-Account-Usage", "X-App-Usage"} {
		if v := h.Get(name); v != "" {
			var m map[string]any
			if json.Unmarshal([]byte(v), &m) == nil {
				seen = true
				for _, k := range []string{"acc_id_util_pct", "call_count", "total_cputime", "total_time"} {
					u.MaxPct = maxf(u.MaxPct, num(m[k]))
				}
				if t, ok := m["ads_api_access_tier"].(string); ok {
					u.Tier = t
				}
			}
		}
	}
	if v := h.Get("X-FB-Ads-Insights-Throttle"); v != "" {
		var m map[string]any
		if json.Unmarshal([]byte(v), &m) == nil {
			seen = true
			u.InsightsPct = maxf(num(m["app_id_util_pct"]), num(m["acc_id_util_pct"]))
		}
	}
	if seen {
		u.At = time.Now()
	}
	return u
}

func num(v any) float64 {
	switch x := v.(type) {
	case float64:
		return x
	case string:
		f, _ := strconv.ParseFloat(x, 64)
		return f
	}
	return 0
}

func maxf(a, b float64) float64 {
	if b > a {
		return b
	}
	return a
}

func clipJSON(b []byte) []byte {
	if len(b) > 4000 {
		return append(append([]byte(nil), b[:4000]...), []byte("…")...)
	}
	return b
}

// scrub: a URL or token Meta (or Go's net error) echoes back is cut before it reaches a log.
func scrub(s string) string {
	if i := strings.Index(s, "access_token="); i >= 0 {
		j := i + len("access_token=")
		k := j
		for k < len(s) && s[k] != '&' && s[k] != '"' && s[k] != ' ' {
			k++
		}
		s = s[:j] + "[redacted]" + s[k:]
	}
	return s
}
