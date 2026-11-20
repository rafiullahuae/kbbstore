package meta

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func client(u string) *Client {
	c := New(func() string { return "EAASECRETTOKEN1234567890" })
	c.Base = u
	c.Sleep = func(context.Context, time.Duration) error { return nil }
	return c
}

// Rate-limit answers are retried (backoff), a dead token is not, and the token never appears in an error.
// MUTATION: make retryable() return false for RateLimited() → the 613 and 80004 rows fail after 1 attempt.
func TestRetryPolicy(t *testing.T) {
	cases := []struct {
		name     string
		body     string
		failN    int
		wantCall int32
		wantErr  bool
	}{
		{"613 ad account rate limit → retried", `{"error":{"message":"(#613) Calls to this api have exceeded the rate limit.","code":613,"error_subcode":1487742}}`, 2, 3, false},
		{"80004 BUC → retried", `{"error":{"message":"There have been too many calls from this ad-account.","code":80004}}`, 1, 2, false},
		{"17 user limit → retried", `{"error":{"message":"User request limit reached","code":17,"error_subcode":2446079}}`, 1, 2, false},
		{"190 token expired → not retried", `{"error":{"message":"Error validating access token","type":"OAuthException","code":190,"error_subcode":463}}`, 9, 1, true},
		{"613/1487632 budget 4/h → not retried", `{"error":{"message":"You can only change your ad set budget 4 times per hour.","code":613,"error_subcode":1487632}}`, 9, 1, true},
		{"100 bad parameter → not retried", `{"error":{"message":"Invalid parameter","code":100}}`, 9, 1, true},
		{"5 failures → gives up after 5 attempts", `{"error":{"message":"Service temporarily unavailable","code":2,"is_transient":true}}`, 99, 5, true},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			var n int32
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if atomic.AddInt32(&n, 1) <= int32(c.failN) {
					w.WriteHeader(400)
					_, _ = w.Write([]byte(c.body))
					return
				}
				_, _ = w.Write([]byte(`{"id":"1","success":true}`))
			}))
			defer srv.Close()
			err := client(srv.URL).Update(context.Background(), "123", map[string][]string{"status": {"PAUSED"}})
			if (err != nil) != c.wantErr || atomic.LoadInt32(&n) != c.wantCall {
				t.Fatalf("err=%v calls=%d, want err=%v calls=%d", err, n, c.wantErr, c.wantCall)
			}
			if err != nil && strings.Contains(err.Error(), "EAASECRET") {
				t.Fatal("token in an error message")
			}
		})
	}
}

func TestTypedErrorsAndUsage(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("appsecret_proof") == "" || r.URL.Query().Get("access_token") == "" {
			t.Error("no token / appsecret_proof")
		}
		w.Header().Set("X-Business-Use-Case-Usage", `{"123":[{"type":"ads_management","call_count":91,"total_cputime":10,"total_time":12,"estimated_time_to_regain_access":7,"ads_api_access_tier":"development_access"}]}`)
		w.Header().Set("X-FB-Ads-Insights-Throttle", `{"app_id_util_pct":40.5,"acc_id_util_pct":12}`)
		w.WriteHeader(400)
		_, _ = w.Write([]byte(`{"error":{"message":"Error validating access token: Session has expired","type":"OAuthException","code":190,"error_subcode":463}}`))
	}))
	defer srv.Close()
	c := client(srv.URL)
	c.AppSecret = func() string { return "appsecret" }
	_, err := c.Account(context.Background(), "act_1")
	me, ok := AsError(err)
	if !ok || !me.TokenInvalid() || me.RateLimited() {
		t.Fatalf("%v", err)
	}
	u := c.Usage()
	if u.MaxPct != 91 || u.RegainMin != 7 || u.InsightsPct != 40.5 || u.Tier != "development_access" {
		t.Fatalf("usage %+v", u)
	}
}

func TestReadsConvertFils(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"id":"act_1","currency":"AED","timezone_name":"Asia/Dubai","spend_cap":"1734500","amount_spent":"1234567","account_status":1,"min_daily_budget":367}`))
	}))
	defer srv.Close()
	a, err := client(srv.URL).Account(context.Background(), "act_1")
	if err != nil || a.SpendCapAED != 17345 || a.AmountSpentAED != 12345.67 || a.MinDailyAED != 3.67 {
		t.Fatalf("%+v %v", a, err)
	}
}

func TestSpendCapWriteIsStandardUnits(t *testing.T) {
	var got string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = r.ParseForm()
		got = r.Form.Get("spend_cap")
		_, _ = w.Write([]byte(`{"success":true}`))
	}))
	defer srv.Close()
	c := client(srv.URL)
	if err := c.SetSpendCap(context.Background(), "act_1", 17345.678); err != nil || got != "17345.67" {
		t.Fatalf("sent %q err %v, want 17345.67 (rounded down)", got, err)
	}
	for _, bad := range []float64{0, -1} {
		got = ""
		if err := c.SetSpendCap(context.Background(), "act_1", bad); err == nil || got != "" {
			t.Fatalf("SetSpendCap(%v) sent %q", bad, got)
		}
	}
}
