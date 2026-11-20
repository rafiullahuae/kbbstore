package shop

// The authenticated shop source (orders_source "shop-api"). The website serves a read-only API at
// https://kbeautybliss.com/autopilot-api/v1/* behind a connection key the owner creates in the shop's admin
// (Growth & Marketing -> Ads Autopilot) and pastes in Settings -> Connections -> Shop; the engine keeps it in its
// secrets store as "shop_token". Products still come from the public feed (titles, images, links); the API adds
// what the feed cannot say: net revenue after refunds and cancellations by shop day, and cost/margin per product.
// Without a key this is exactly the feed source (orders_source "none").

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"kaa/model"
)

const DefaultAPI = "https://kbeautybliss.com/autopilot-api/v1"

// The only host a key is ever sent to.
const apiPrefix = "https://kbeautybliss.com/"

type APISource struct {
	Base     string
	Token    func() string // read on every Snapshot, so a key saved in Settings applies at once
	TokenErr func() error  // optional: why Token() was "" although a key may be saved (the Keychain refused the read)
	Feed     *FeedSource
	HTTP     *http.Client
	Days     int // how many shop days of orders (default 90)
	Now      func() time.Time

	anyBase bool // tests only: lets an httptest server stand in for the shop
}

// New is what the engine wires: the feed, plus the API whenever a key is saved.
func New(feedURL string, token func() string) *APISource {
	return &APISource{Base: DefaultAPI, Token: token, Feed: NewFeed(feedURL),
		HTTP: &http.Client{Timeout: 60 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}}
}

func (s *APISource) token() string {
	if s.Token == nil {
		return ""
	}
	return strings.TrimSpace(s.Token())
}

func (s *APISource) OrdersSource() string {
	if s.token() == "" {
		return "none"
	}
	return "shop-api"
}

type apiDaily struct {
	Days []struct {
		Date     string  `json:"date"`
		Orders   int     `json:"orders"`
		Net      float64 `json:"net"`
		Refunded float64 `json:"refunded_on_orders"`
	} `json:"days"`
}

type apiProducts struct {
	Products []struct {
		ID        string   `json:"id"`
		MarginPct *float64 `json:"margin_pct"`
		Stock     *int     `json:"stock"`
		Variants  []struct {
			CatalogID string `json:"catalog_id"`
			Stock     *int   `json:"stock"`
		} `json:"variants"`
	} `json:"products"`
	NextAfter *int `json:"next_after"`
}

// apiOrders: one page of GET /orders (only what the momentum signal needs).
type apiOrders struct {
	Orders []struct {
		Day    string `json:"day"`
		IsSale bool   `json:"is_sale"`
		Family string `json:"payment_family"`
		Items  []struct {
			ProductID *string `json:"product_id"`
			Qty       int     `json:"qty"`
		} `json:"items"`
	} `json:"orders"`
	NextAfter *int `json:"next_after"`
}

// ErrUnauthorized: the shop refused the key (unknown, revoked, or missing the scope).
var ErrUnauthorized = errors.New("shop api: the connection key was refused -- create a new one in the shop's admin (Growth & Marketing -> Ads Autopilot)")

func (s *APISource) get(ctx context.Context, path string, q url.Values, into any) error {
	base := strings.TrimRight(s.Base, "/")
	if !s.anyBase && !strings.HasPrefix(base+"/", apiPrefix) {
		return fmt.Errorf("shop api: refusing to send the key to %q (only %s)", base, apiPrefix)
	}
	u := base + path
	if len(q) > 0 {
		u += "?" + q.Encode()
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+s.token())
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "KBB-Ads-Autopilot/1")
	client := s.HTTP
	if client == nil {
		client = &http.Client{Timeout: 60 * time.Second}
	}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("shop api: %v", err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	switch {
	case resp.StatusCode == 401 || resp.StatusCode == 403:
		return ErrUnauthorized
	case resp.StatusCode == 404:
		return fmt.Errorf("shop api: %s is not on the shop yet (HTTP 404)", path)
	case resp.StatusCode != 200:
		return fmt.Errorf("shop api: %s answered HTTP %d", path, resp.StatusCode)
	}
	if err := json.Unmarshal(body, into); err != nil {
		return fmt.Errorf("shop api: %s: %v", path, err)
	}
	return nil
}

// Snapshot: the feed's products, then (with a key) the last Days of net revenue by day and each product's margin.
// On an API failure the feed snapshot comes back WITH the error, orders_source "none", so the caller can still
// show products while saying why orders are missing.
func (s *APISource) Snapshot(ctx context.Context) (model.ShopSnapshot, error) {
	snap, err := s.Feed.Snapshot(ctx)
	if err != nil {
		return snap, err
	}
	if s.token() == "" {
		if s.TokenErr != nil { // saved but unreadable: the products still show, and the error says why orders are missing
			if kerr := s.TokenErr(); kerr != nil {
				snap.OrdersSource, snap.Orders = "none", []model.OrderDay{}
				return snap, fmt.Errorf("the saved shop connection key could not be read: %w", kerr)
			}
		}
		return snap, err
	}
	if err := s.addShopData(ctx, &snap); err != nil {
		snap.OrdersSource, snap.Orders = "none", []model.OrderDay{}
		return snap, err
	}
	snap.OrdersSource = "shop-api"
	return snap, nil
}

func (s *APISource) addShopData(ctx context.Context, snap *model.ShopSnapshot) error {
	now := time.Now
	if s.Now != nil {
		now = s.Now
	}
	days := s.Days
	if days <= 0 {
		days = 90
	}
	dubai := time.FixedZone("GST", 4*3600)
	today := now().In(dubai)
	q := url.Values{"from": {today.AddDate(0, 0, -(days - 1)).Format("2006-01-02")}, "to": {today.Format("2006-01-02")}}
	var d apiDaily
	if err := s.get(ctx, "/orders/daily", q, &d); err != nil {
		return err
	}
	orders := make([]model.OrderDay, 0, len(d.Days))
	for _, r := range d.Days {
		orders = append(orders, model.OrderDay{Date: r.Date, Orders: r.Orders, NetRevenueAED: r.Net, RefundsAED: r.Refunded})
	}

	margins := map[string]float64{}
	stock := map[string]int{}
	after := 0
	for page := 0; page < 50; page++ { // 50 x 200 = 10,000 products, far past this shop
		var p apiProducts
		if err := s.get(ctx, "/products", url.Values{"limit": {"200"}, "after": {strconv.Itoa(after)}}, &p); err != nil {
			return err
		}
		for _, pr := range p.Products {
			if pr.Stock != nil {
				stock[pr.ID] = *pr.Stock
			}
			for _, v := range pr.Variants {
				if v.Stock != nil {
					stock[v.CatalogID] = *v.Stock
				} else if pr.Stock != nil {
					stock[v.CatalogID] = *pr.Stock
				}
			}
			if pr.MarginPct == nil {
				continue
			}
			margins[pr.ID] = *pr.MarginPct
			for _, v := range pr.Variants {
				margins[v.CatalogID] = *pr.MarginPct
			}
		}
		if p.NextAfter == nil {
			break
		}
		after = *p.NextAfter
	}

	// units sold per product: sale orders of the last 28 shop days, split 14 + 14 (the momentum signal)
	units, prev := map[string]int{}, map[string]int{}
	fam, nSale := map[string]float64{}, 0
	cut := today.AddDate(0, 0, -13).Format("2006-01-02")
	oq := url.Values{"from": {today.AddDate(0, 0, -27).Format("2006-01-02")}, "to": {today.Format("2006-01-02")}, "limit": {"200"}}
	after = 0
	for page := 0; page < 50; page++ { // 10,000 orders in 4 weeks, far past this shop
		oq.Set("after", strconv.Itoa(after))
		var o apiOrders
		if err := s.get(ctx, "/orders", oq, &o); err != nil {
			if errors.Is(err, ErrUnauthorized) {
				return err
			}
			units, prev, fam, nSale = map[string]int{}, map[string]int{}, map[string]float64{}, 0 // momentum is a bonus signal: without it the rest still holds
			break
		}
		for _, od := range o.Orders {
			if !od.IsSale {
				continue
			}
			nSale++
			f := od.Family
			if f != "bnpl" && f != "card" && f != "cod" {
				f = "other"
			}
			fam[f]++
			for _, it := range od.Items {
				if it.ProductID == nil || it.Qty <= 0 {
					continue
				}
				if od.Day >= cut {
					units[*it.ProductID] += it.Qty
				} else {
					prev[*it.ProductID] += it.Qty
				}
			}
		}
		if o.NextAfter == nil {
			break
		}
		after = *o.NextAfter
	}

	snap.Orders = orders
	if nSale > 0 {
		snap.Orders28, snap.PaymentMix = nSale, map[string]float64{}
		for k, v := range fam {
			snap.PaymentMix[k] = v / float64(nSale)
		}
	}
	for i := range snap.Products {
		p := &snap.Products[i]
		if m, ok := margins[p.ID]; ok {
			m := m
			p.MarginPct = &m
		}
		if n, ok := stock[p.ID]; ok {
			n := n
			p.Stock = &n
		}
		g := p.ID // a variant line ("123-45") counts its product's sales: the orders name the product
		if i := strings.IndexByte(g, '-'); i > 0 {
			g = g[:i]
		}
		p.Units14, p.UnitsPrev14 = units[g], prev[g]
	}
	return nil
}

// TestBase: -mock only: the fake shop API on 127.0.0.1 stands in for the shop (the key never leaves the machine).
func (s *APISource) TestBase(u string) { s.Base, s.anyBase = u, true }
