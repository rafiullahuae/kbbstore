package claude

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// Claude's refusals reached Activity and the cycle alert as "Claude 529 overloaded_error: Overloaded" or a bare Go
// network error: nothing said what to do, or that nothing was changed. Each now says it in plain words, and a
// saved-but-unreadable key is never "no key saved". MUTATION: return "" from hint() → red; drop the KeyErr branch in
// noKey → red.
func TestClaudeErrorsSayWhatToDo(t *testing.T) {
	cases := map[int][2]string{
		401: {`{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}`, "paste a new one"},
		429: {`{"type":"error","error":{"type":"rate_limit_error","message":"Number of requests has exceeded"}}`, "rate limit"},
		529: {`{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}`, "Claude is busy"},
		400: {`{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low"}}`, "out of credit"},
	}
	for status, c := range cases {
		calls := 0
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			calls++
			w.WriteHeader(status)
			_, _ = w.Write([]byte(c[0]))
		}))
		cl := New(func() string { return "sk-ant-x" })
		cl.Base, cl.Retries = srv.URL, 0
		_, err := cl.Complete(context.Background(), model.LLMRequest{})
		srv.Close()
		if err == nil || !strings.Contains(err.Error(), c[1]) {
			t.Errorf("%d: %v", status, err)
		}
	}
	// no internet
	cl := New(func() string { return "sk-ant-x" })
	cl.Base, cl.Retries = "http://127.0.0.1:1", 0
	cl.HTTP.Timeout = 2 * time.Second
	if _, err := cl.Complete(context.Background(), model.LLMRequest{}); err == nil || !strings.Contains(err.Error(), "did not answer") {
		t.Errorf("offline: %v", err)
	}
	// a key that is saved but could not be read
	cl = New(func() string { return "" })
	cl.KeyErr = func() error { return errTest("macOS didn't let the app read its saved keys") }
	if err := cl.Ping(context.Background()); err == nil || !strings.Contains(err.Error(), "could not be read") {
		t.Errorf("unreadable key: %v", err)
	}
}

type errTest string

func (e errTest) Error() string { return string(e) }
