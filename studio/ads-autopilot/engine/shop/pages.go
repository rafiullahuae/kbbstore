package shop

// pages.go — the owner, 22 Nov: "don't give me the list of products or the system should not find the product, if
// those are not on the super sale page. bcz we update the super sale manually and keep only the trending latest
// products" / "the system must choose all the products from the super sale page, unless i add another page in the
// setting." PageProducts reads a kbeautybliss.com listing page, every page of it (rel="next"), and returns the
// product ids it shows (data-product_id, the same ids as the catalog feed's g:id / item_group_id).

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"
)

var (
	pageProductRe = regexp.MustCompile(`data-product_id="(\d+)"`)
	pageNextRe    = regexp.MustCompile(`<link[^>]+rel="next"[^>]+href="([^"]+)"|<a[^>]+rel="next"[^>]+href="([^"]+)"|href="([^"]+)"[^>]+rel="next"`)
)

// ShopPage: only pages of the shop itself are read.
func ShopPage(u string) bool {
	p, err := url.Parse(u)
	return err == nil && p.Scheme == "https" && p.Host == "kbeautybliss.com"
}

// PageProducts: every product id a listing page shows, across its pages (at most 30 pages).
func PageProducts(ctx context.Context, hc *http.Client, page string) ([]string, error) {
	if !ShopPage(page) {
		return nil, fmt.Errorf("%q is not a page of https://kbeautybliss.com/", page)
	}
	if hc == nil {
		hc = &http.Client{Timeout: 45 * time.Second}
	}
	seen, seenPage := map[string]bool{}, map[string]bool{}
	var ids []string
	next := page
	for i := 0; i < 30 && next != "" && !seenPage[next]; i++ {
		seenPage[next] = true
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, next, nil)
		if err != nil {
			return nil, err
		}
		req.Header.Set("User-Agent", "KBB-Ads-Autopilot")
		res, err := hc.Do(req)
		if err != nil {
			return nil, err
		}
		b, err := io.ReadAll(io.LimitReader(res.Body, 8<<20))
		res.Body.Close()
		if err != nil {
			return nil, err
		}
		if res.StatusCode != 200 {
			return nil, fmt.Errorf("%s answered HTTP %d", next, res.StatusCode)
		}
		html := string(b)
		for _, m := range pageProductRe.FindAllStringSubmatch(html, -1) {
			if !seen[m[1]] {
				seen[m[1]] = true
				ids = append(ids, m[1])
			}
		}
		next = ""
		if m := pageNextRe.FindStringSubmatch(html); m != nil {
			n := strings.ReplaceAll(firstNon(m[1], m[2], m[3]), "&amp;", "&")
			if u, err := url.Parse(next0(page)); err == nil {
				if r, err := u.Parse(n); err == nil && ShopPage(r.String()) {
					next = r.String()
				}
			}
		}
	}
	if len(ids) == 0 {
		return nil, fmt.Errorf("%s shows no products", page)
	}
	return ids, nil
}

func next0(s string) string { return s }

func firstNon(xs ...string) string {
	for _, x := range xs {
		if x != "" {
			return x
		}
	}
	return ""
}
