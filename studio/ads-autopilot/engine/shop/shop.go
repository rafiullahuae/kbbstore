// Package shop reads what the engine knows about kbeautybliss.com. v0: the public Meta catalog feed (RSS 2.0 with the
// g: namespace -- 902 items on 20 Nov 2026) for products, prices, stock and images. Orders and margins need an
// authenticated read-only shop endpoint, a website change done separately; until then OrdersSource is "none".
package shop

import (
	"context"
	"encoding/xml"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"

	"kaa/model"
)

const DefaultFeed = "https://kbeautybliss.com/feeds/meta-catalog.xml"

// ShopSource is what the engine asks the shop. A later source (the authenticated orders endpoint) implements the same.
type ShopSource interface {
	Snapshot(ctx context.Context) (model.ShopSnapshot, error)
	OrdersSource() string
}

type FeedSource struct {
	URL  string
	HTTP *http.Client
	Max  int64 // bytes (default 32 MB)
}

func NewFeed(url string) *FeedSource {
	if url == "" {
		url = DefaultFeed
	}
	return &FeedSource{URL: url, HTTP: &http.Client{Timeout: 60 * time.Second}}
}

func (f *FeedSource) OrdersSource() string { return "none" }

type rss struct {
	Items []struct {
		ID           string `xml:"http://base.google.com/ns/1.0 id"`
		Title        string `xml:"http://base.google.com/ns/1.0 title"`
		Link         string `xml:"http://base.google.com/ns/1.0 link"`
		Image        string `xml:"http://base.google.com/ns/1.0 image_link"`
		Price        string `xml:"http://base.google.com/ns/1.0 price"`
		SalePrice    string `xml:"http://base.google.com/ns/1.0 sale_price"`
		Availability string `xml:"http://base.google.com/ns/1.0 availability"`
		Brand        string `xml:"http://base.google.com/ns/1.0 brand"`
		ProductType  string `xml:"http://base.google.com/ns/1.0 product_type"`
		Desc         string `xml:"http://base.google.com/ns/1.0 description"`
		PlainDesc    string `xml:"description"`
	} `xml:"channel>item"`
}

// ParsePrice: "70.00 AED" → 70. Other currencies are refused (0) rather than mixed into AED.
func ParsePrice(s string) float64 {
	s = strings.TrimSpace(s)
	if s == "" {
		return 0
	}
	parts := strings.Fields(s)
	if len(parts) == 2 && !strings.EqualFold(parts[1], "AED") {
		return 0
	}
	v, err := strconv.ParseFloat(parts[0], 64)
	if err != nil || v < 0 {
		return 0
	}
	return v
}

func (f *FeedSource) Snapshot(ctx context.Context) (model.ShopSnapshot, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, f.URL, nil)
	if err != nil {
		return model.ShopSnapshot{}, err
	}
	req.Header.Set("User-Agent", "KBB-Ads-Autopilot/1")
	resp, err := f.HTTP.Do(req)
	if err != nil {
		return model.ShopSnapshot{}, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return model.ShopSnapshot{}, fmt.Errorf("product feed: HTTP %d", resp.StatusCode)
	}
	max := f.Max
	if max <= 0 {
		max = 32 << 20
	}
	return Parse(io.LimitReader(resp.Body, max))
}

func Parse(r io.Reader) (model.ShopSnapshot, error) {
	var doc rss
	if err := xml.NewDecoder(r).Decode(&doc); err != nil {
		return model.ShopSnapshot{}, fmt.Errorf("product feed: %v", err)
	}
	snap := model.ShopSnapshot{FetchedAt: time.Now().UTC(), OrdersSource: "none", Orders: []model.OrderDay{}}
	for _, it := range doc.Items {
		if it.ID == "" {
			continue
		}
		p := model.Product{ID: strings.TrimSpace(it.ID), Title: strings.TrimSpace(it.Title), Brand: strings.TrimSpace(it.Brand),
			PriceAED: ParsePrice(it.Price), SaleAED: ParsePrice(it.SalePrice), InStock: strings.TrimSpace(it.Availability) == "in_stock",
			Image: strings.TrimSpace(it.Image), URL: strings.TrimSpace(it.Link), Category: strings.TrimSpace(it.ProductType)}
		desc := it.Desc
		if desc == "" {
			desc = it.PlainDesc
		}
		if r := []rune(desc); len(r) > 2000 { // what it is is said early; the tags are kept, never the text
			desc = string(r[:2000])
		}
		p.Tags = model.ClassifyProduct(p.Title, p.Category, desc)
		snap.Products = append(snap.Products, p)
	}
	if len(snap.Products) == 0 {
		return snap, fmt.Errorf("product feed: no products in it")
	}
	return snap, nil
}
