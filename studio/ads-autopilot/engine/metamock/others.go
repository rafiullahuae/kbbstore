package metamock

import (
	"encoding/json"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"
)

// Aux is a fake Anthropic API (/v1/models/{id}, /v1/messages) and a fake product feed (/feed.xml) for the checks.
type Aux struct {
	Key   string
	mu    sync.Mutex
	Calls int
	Kinds map[string]int // scripted answers given, by kind (plan, copy, research-search, …)
	srv   *http.Server
	URL   string
}

const Feed = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel><title>K-Beauty Bliss UAE</title>
<item><g:id>3195</g:id><g:title>COSRX - Low pH Good Morning Gel Cleanser - 150ml</g:title><g:link>https://kbeautybliss.com/product/cosrx-low-ph/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/cosrx.webp</g:image_link><g:price>70.00 AED</g:price><g:sale_price>59.00 AED</g:sale_price><g:availability>in_stock</g:availability><g:brand>COSRX</g:brand><g:product_type>Cleanser</g:product_type></item>
<item><g:id>4410</g:id><g:title>Beauty of Joseon - Relief Sun SPF50+ - 50ml</g:title><g:link>https://kbeautybliss.com/product/boj-relief-sun/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/boj.webp</g:image_link><g:price>85.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Beauty of Joseon</g:brand><g:product_type>Sunscreen</g:product_type></item>
<item><g:id>5120</g:id><g:title>Anua - Heartleaf 77% Toner - 250ml</g:title><g:link>https://kbeautybliss.com/product/anua-toner/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/anua.webp</g:image_link><g:price>99.00 AED</g:price><g:availability>out_of_stock</g:availability><g:brand>Anua</g:brand><g:product_type>Toner</g:product_type></item>
<item><g:id>2210</g:id><g:title>COSRX - Advanced Snail 96 Mucin Power Essence - 100ml</g:title><g:link>https://kbeautybliss.com/product/cosrx-snail-96/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/cosrx-snail-96.webp</g:image_link><g:price>89.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>COSRX</g:brand><g:product_type>Essence</g:product_type></item>
<item><g:id>6120</g:id><g:title>Torriden - DIVE-IN Low Molecular Hyaluronic Acid Serum - 50ml</g:title><g:link>https://kbeautybliss.com/product/torriden-dive-in/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/torriden-dive-in.webp</g:image_link><g:price>95.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Torriden</g:brand><g:product_type>Serum</g:product_type></item>
<item><g:id>7301</g:id><g:title>Anua - Heartleaf Quercetinol Pore Deep Cleansing Foam - 150ml</g:title><g:link>https://kbeautybliss.com/product/anua-foam/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/anua-foam.webp</g:image_link><g:price>69.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Anua</g:brand><g:product_type>Cleanser</g:product_type></item>
<item><g:id>8015</g:id><g:title>Medicube - PDRN Pink Peptide Serum - 30ml</g:title><g:link>https://kbeautybliss.com/product/medicube-pdrn/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/medicube-pdrn.webp</g:image_link><g:price>129.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Medicube</g:brand><g:product_type>Serum</g:product_type></item>
<item><g:id>8022</g:id><g:title>Beauty of Joseon - Glow Gift Set</g:title><g:link>https://kbeautybliss.com/product/boj-glow-set/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/boj-glow-set.webp</g:image_link><g:price>159.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Beauty of Joseon</g:brand><g:product_type>Gift Set</g:product_type></item>
</channel></rss>`

func (a *Aux) Start() (string, error) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return "", err
	}
	a.srv = &http.Server{Handler: a, ReadHeaderTimeout: 10 * time.Second}
	go func() { _ = a.srv.Serve(ln) }()
	a.URL = "http://" + ln.Addr().String()
	return a.URL, nil
}

func (a *Aux) Close() {
	if a.srv != nil {
		_ = a.srv.Close()
	}
}

func (a *Aux) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mu.Lock()
	a.Calls++
	a.mu.Unlock()
	if r.URL.Path == "/__mock/claude-calls" {
		a.mu.Lock()
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{"calls": a.Calls, "kinds": a.Kinds})
		a.mu.Unlock()
		return
	}
	if r.URL.Path == "/feed.xml" {
		w.Header().Set("Content-Type", "application/xml")
		_, _ = w.Write([]byte(Feed))
		return
	}
	w.Header().Set("Content-Type", "application/json")
	if r.Header.Get("x-api-key") != a.Key {
		w.WriteHeader(401)
		_, _ = w.Write([]byte(`{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}`))
		return
	}
	switch {
	case strings.HasPrefix(r.URL.Path, "/v1/models/"):
		_ = json.NewEncoder(w).Encode(map[string]any{"type": "model", "id": strings.TrimPrefix(r.URL.Path, "/v1/models/"), "display_name": "mock"})
	case r.URL.Path == "/v1/messages" && r.Method == http.MethodPost:
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		ans, kind := claudeAnswer(body) // scripted tool calls (claude.go)
		a.mu.Lock()
		if a.Kinds == nil {
			a.Kinds = map[string]int{}
		}
		a.Kinds[kind]++
		a.mu.Unlock()
		_ = json.NewEncoder(w).Encode(ans)
	default:
		w.WriteHeader(404)
		_, _ = w.Write([]byte(`{"type":"error","error":{"type":"not_found_error","message":"no such path"}}`))
	}
}
