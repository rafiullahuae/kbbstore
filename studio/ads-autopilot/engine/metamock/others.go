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
	Now   func() time.Time // the engine's clock (the shop API's days follow it)
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
<item><g:id>8101</g:id><g:title>Dr.Melaxin - Calcium Dark Spot Cover Eye Cream - 15ml</g:title><g:link>https://kbeautybliss.com/product/drmelaxin-eye/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/drmelaxin-eye.webp</g:image_link><g:price>119.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Dr.Melaxin</g:brand><g:product_type>Eye Cream</g:product_type><g:description>Calcium eye cream that brightens dark spots under the eyes.</g:description></item>
<item><g:id>8130</g:id><g:title>Aestura - Atobarrier 365 Ceramide Cream - 80ml</g:title><g:link>https://kbeautybliss.com/product/aestura-atobarrier/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/aestura-atobarrier.webp</g:image_link><g:price>139.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Aestura</g:brand><g:product_type>Moisturizer</g:product_type><g:description>Rich ceramide cream that repairs a dry, damaged skin barrier. كريم سيراميد غني لترطيب البشرة الجافة.</g:description></item>
<item><g:id>8144</g:id><g:title>Illiyoon - Ceramide Ato Concentrate Cream - 200ml</g:title><g:link>https://kbeautybliss.com/product/illiyoon-ato/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/illiyoon-ato.webp</g:image_link><g:price>99.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Illiyoon</g:brand><g:product_type>Body Care</g:product_type><g:description>Deeply moisturising ceramide cream for very dry face and body.</g:description></item>
<item><g:id>8150</g:id><g:title>Laneige - Lip Sleeping Mask Berry - 20g</g:title><g:link>https://kbeautybliss.com/product/laneige-lip/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/laneige-lip.webp</g:image_link><g:price>89.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Laneige</g:brand><g:product_type>Lip Care</g:product_type><g:description>Overnight lip mask: soft, hydrated lips by morning.</g:description></item>
<item><g:id>8162</g:id><g:title>Round Lab - Soybean Nourishing Cream - 80ml</g:title><g:link>https://kbeautybliss.com/product/roundlab-soybean/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/roundlab-soybean.webp</g:image_link><g:price>109.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Round Lab</g:brand><g:product_type>Moisturizer</g:product_type><g:description>Nourishing cream with soybean for dry skin, rich texture.</g:description></item>
<item><g:id>8170</g:id><g:title>Isntree - Hyaluronic Acid Watery Sun Gel SPF50+ - 50ml</g:title><g:link>https://kbeautybliss.com/product/isntree-sun-gel/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/isntree-sun-gel.webp</g:image_link><g:price>79.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Isntree</g:brand><g:product_type>Sunscreen</g:product_type><g:description>Lightweight watery sun gel, no white cast.</g:description></item>
<item><g:id>8178</g:id><g:title>Innisfree - No-Sebum Mineral Powder - 5g</g:title><g:link>https://kbeautybliss.com/product/innisfree-no-sebum/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/innisfree-no-sebum.webp</g:image_link><g:price>45.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Innisfree</g:brand><g:product_type>Oil Control</g:product_type><g:description>Oil control powder that keeps skin matte all day.</g:description></item>
<item><g:id>8185</g:id><g:title>Mixsoon - Centella Sleeping Mask - 100ml</g:title><g:link>https://kbeautybliss.com/product/mixsoon-centella-mask/</g:link><g:image_link>https://kbeautybliss.com/wp-content/uploads/mixsoon-centella-mask.webp</g:image_link><g:price>95.00 AED</g:price><g:availability>in_stock</g:availability><g:brand>Mixsoon</g:brand><g:product_type>Mask</g:product_type><g:description>Soothing overnight mask with centella for a calm skin barrier.</g:description></item>
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
	if a.shopAPI(w, r) {
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
