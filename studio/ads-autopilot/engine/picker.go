package main

// picker.go — the owner chooses the products himself (21 Nov 2026: "in the approval i can see the products and text
// etc. but those products i don't want to promote, give me a way to skip on products or choose the products myself
// also in case i don't like the products for any planned campaign.")
//
//	GET  /api/catalog                  the whole shop for the picker: rank and why, stock, price, new / trending,
//	                                   pinned, and what the Playbook keeps out (shown disabled, never hidden)
//	POST /api/proposals/{id}/products  {product_ids}: rebuild THAT waiting proposal (an ad, an ad set, a campaign or
//	                                   a catalog product set) for exactly those products — template copy, or the
//	                                   API's copy when that mode is on, or a Creation request ("With me in Claude")
//	                                   — and run the executor's checks on it again
//	POST /api/products/{id}/avoid      Playbook Avoid + every waiting proposal and open job that used it rebuilt
//	POST /api/products/{id}/pin        pin / unpin (Playbook Focus): ranked first from now on
//	POST /api/undo                     the last of these, undone (one step)
//
// Nothing here re-plans and nothing calls Claude unless new copy is needed and the API mode allows it. Everything is
// logged to Activity. A proposal or job left with no allowed product says so instead of disappearing.

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"kaa/executor"
	"kaa/model"
)

const (
	msgJobAvoided = "all products for this job were avoided — choose products"
	msgAdAvoided  = "all products for this ad were avoided — choose products"
	stChoose      = "choose" // a waiting proposal with no allowed product left: it waits for the owner's pick
)

var productIDRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`)

// ── one undo step per account ──

type undoStep struct {
	Label   string
	Focus   []model.PBRule
	Avoid   []model.PBRule
	PB      bool       // the Playbook's Focus / Avoid changed
	Pending PendingSet // the waiting proposals before (restored only while it is still the same plan)
	HasPend bool
	Jobs    []Job // the jobs it changed, as they were
}

var undos sync.Map // account id → undoStep

func (ac *Acct) keepUndo(u undoStep) { undos.Store(ac.id, u) }

// ── the catalogue for the picker ──

type catItem struct {
	ID       string   `json:"id"`
	Title    string   `json:"title"`
	Brand    string   `json:"brand,omitempty"`
	Category string   `json:"category,omitempty"`
	Image    string   `json:"image,omitempty"`
	Price    float64  `json:"price"`
	Sale     float64  `json:"sale,omitempty"`
	Stock    *int     `json:"stock"`
	InStock  bool     `json:"in_stock"`
	Rank     int      `json:"rank"` // 1 = the autopilot's first choice; 0 = not ranked (no photo, out of stock, kept out)
	Score    float64  `json:"score"`
	Why      []string `json:"why,omitempty"`
	New      bool     `json:"new,omitempty"`
	Trending bool     `json:"trending,omitempty"`
	Pinned   bool     `json:"pinned,omitempty"`
	Avoided  bool     `json:"avoided,omitempty"`
	Allowed  bool     `json:"allowed"` // the picker may choose it
	Reason   string   `json:"reason,omitempty"`
}

func (ac *Acct) trendingIDs() []string {
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	if t, ok := br.(interface{ TrendingProductIDs() []string }); ok {
		return t.TrendingProductIDs()
	}
	return nil
}

// usable: what an ad can be made of (the same test materialise and the first launch use).
func usable(p model.Product) bool {
	return p.InStock && executor.ShopURL(p.URL) && executor.ShopURL(p.Image) && p.PriceAED > 0
}

func (ac *Acct) catalog() []catItem {
	a := ac.app
	a.mu.RLock()
	shop := a.shopSnap
	a.mu.RUnlock()
	fs, pb, ex := ac.filteredShop(shop)
	why := map[string]string{}
	for _, e := range ex {
		why[e.ID] = e.Reason
	}
	ranked := model.Rank(fs.Products, pb, model.RankSignals{Now: a.now(), Trending: ac.trendingIDs(), Baseline: firstSeenBaseline(fs)})
	rk := map[string]model.RankedProduct{}
	pos := map[string]int{}
	for i, r := range ranked {
		rk[r.ID], pos[r.ID] = r, i+1
	}
	out := make([]catItem, 0, len(shop.Products))
	for _, p := range shop.Products {
		it := catItem{ID: p.ID, Title: p.Title, Brand: p.Brand, Category: p.Category, Image: p.Image, Price: p.PriceAED, Sale: p.SaleAED, Stock: p.Stock, InStock: p.InStock, Pinned: pb.Pinned(p)}
		for _, r := range pb.Avoid {
			if r.Match(p) {
				it.Avoided = true
			}
		}
		if r, ok := rk[p.ID]; ok {
			it.Rank, it.Score, it.Trending = pos[p.ID], r.Score, r.Trending
			it.New = (r.AgeDays >= 0 && r.AgeDays <= 45) || r.Parts["new"] >= 0.72
			if len(r.Why) > 3 {
				r.Why = r.Why[:3]
			}
			it.Why = r.Why
		}
		switch {
		case it.Avoided:
			it.Reason = "Avoided (Strategy)"
		case why[p.ID] != "":
			it.Reason = why[p.ID]
		case !p.InStock:
			it.Reason = "out of stock"
		case !usable(p):
			it.Reason = "no shop photo, page or price"
		default:
			it.Allowed = true
		}
		out = append(out, it)
	}
	sort.SliceStable(out, func(i, j int) bool { // the ranked list first, then the rest, then what is kept out
		ri, rj := out[i].Rank, out[j].Rank
		if (ri > 0) != (rj > 0) {
			return ri > 0
		}
		if ri != rj {
			return ri < rj
		}
		return out[i].Allowed && !out[j].Allowed
	})
	return out
}

// chosen: the owner's ids, checked against the shop as the Playbook allows it, in his order, no repeats.
func (ac *Acct) chosen(ids []string) ([]model.Product, error) {
	if len(ids) == 0 {
		return nil, errors.New("choose at least one product")
	}
	if len(ids) > 10 {
		return nil, errors.New("at most 10 products")
	}
	a := ac.app
	a.mu.RLock()
	shop := a.shopSnap
	a.mu.RUnlock()
	fs, _, ex := ac.filteredShop(shop)
	by := map[string]model.Product{}
	for _, p := range fs.Products {
		by[p.ID] = p
	}
	why := map[string]string{}
	for _, e := range ex {
		why[e.ID] = e.Reason
	}
	var out []model.Product
	for _, id := range uniq(ids) {
		p, ok := by[id]
		switch {
		case why[id] != "":
			return nil, fmt.Errorf("product %s cannot be advertised: %s", id, why[id])
		case !ok:
			return nil, fmt.Errorf("product %s is not in the shop", id)
		case !usable(p):
			return nil, fmt.Errorf("%s is out of stock or has no shop photo / page", shortTitle(p))
		}
		out = append(out, p)
	}
	return out, nil
}

// ── reading products off the waiting proposals ──

func deepPending(ps PendingSet) PendingSet {
	b, _ := json.Marshal(ps)
	var out PendingSet
	_ = json.Unmarshal(b, &out)
	if out.Items == nil {
		out.Items = []PendingItem{}
	}
	return out
}

func pstr(p map[string]any, k string) string { s, _ := p[k].(string); return s }

func idxOf(items []PendingItem, id string) int {
	for i := range items {
		if items[i].ID == id {
			return i
		}
	}
	return -1
}

func open2(st string) bool { return waiting(st) || st == stChoose }

// uploadOf: the waiting upload_creative that makes this creative (a library id or a tmp: target), or -1.
func uploadOf(items []PendingItem, cid string) int {
	for i := range items {
		if items[i].Kind == model.KindUploadCreative && items[i].Target == cid && open2(items[i].Status) {
			return i
		}
	}
	return -1
}

// filterIDs: the retailer ids a product-set filter names (is_any), in order.
func filterIDs(filter string) []string {
	var v any
	if json.Unmarshal([]byte(filter), &v) != nil {
		return nil
	}
	var out []string
	var walk func(x any)
	walk = func(x any) {
		switch t := x.(type) {
		case map[string]any:
			for k, e := range t {
				if k == "retailer_id" {
					if m, ok := e.(map[string]any); ok {
						if xs, ok := m["is_any"].([]any); ok {
							for _, s := range xs {
								out = append(out, fmt.Sprint(s))
							}
						}
					}
					continue
				}
				walk(e)
			}
		case []any:
			for _, e := range t {
				walk(e)
			}
		}
	}
	walk(v)
	return out
}

func setFilter(ids []string) string {
	b, _ := json.Marshal(map[string]any{"and": []any{map[string]any{"availability": map[string]string{"eq": "in stock"}}, map[string]any{"retailer_id": map[string]any{"is_any": ids}}}})
	return string(b)
}

// productSetOf: the waiting create_product_set this item shows (catalog ads), or -1.
func productSetOf(items []PendingItem, i int) int {
	it := items[i]
	if it.Kind == model.KindCreateProductSet {
		return i
	}
	ref := pstr(it.Params, "product_set_id")
	if ref == "" && it.Kind == model.KindCreateAd {
		if u := uploadOf(items, pstr(it.Params, "creative_id")); u >= 0 {
			ref = pstr(items[u].Params, "product_set_id")
		}
	}
	if ref == "" && it.Kind == model.KindCreateCampaign {
		for j := range items {
			if items[j].Kind == model.KindCreateAdSet && pstr(items[j].Params, "campaign_id") == it.Target && pstr(items[j].Params, "product_set_id") != "" {
				ref = pstr(items[j].Params, "product_set_id")
			}
		}
	}
	if ref == "" {
		return -1
	}
	for j := range items {
		if items[j].Kind == model.KindCreateProductSet && items[j].Target == ref && open2(items[j].Status) {
			return j
		}
	}
	return -1
}

// adsOf: the waiting create_ad items this item stands for (itself, an ad set's, a campaign's, a creative's).
func adsOf(items []PendingItem, i int) []int {
	it := items[i]
	var out []int
	add := func(pred func(PendingItem) bool) {
		for j := range items {
			if items[j].Kind == model.KindCreateAd && open2(items[j].Status) && pred(items[j]) {
				out = append(out, j)
			}
		}
	}
	switch it.Kind {
	case model.KindCreateAd:
		if open2(it.Status) {
			out = []int{i}
		}
	case model.KindUploadCreative:
		add(func(x PendingItem) bool { return pstr(x.Params, "creative_id") == it.Target })
	case model.KindCreateAdSet:
		add(func(x PendingItem) bool { return pstr(x.Params, "adset_id") == it.Target })
	case model.KindCreateCampaign:
		sets := map[string]bool{}
		for _, s := range items {
			if s.Kind == model.KindCreateAdSet && pstr(s.Params, "campaign_id") == it.Target {
				sets[s.Target] = true
			}
		}
		add(func(x PendingItem) bool { return sets[pstr(x.Params, "adset_id")] })
	}
	return out
}

// adProducts: the product ids one waiting ad shows (image: its creative's; carousel: every card's).
func adProducts(items []PendingItem, a int, lib map[string]model.Creative) []string {
	cid := pstr(items[a].Params, "creative_id")
	if c, ok := lib[cid]; ok && len(c.ProductIDs) > 0 {
		return c.ProductIDs
	}
	if u := uploadOf(items, cid); u >= 0 {
		up := items[u]
		if pstr(up.Params, "format") == "carousel" {
			var out []string
			for _, cd := range cards(up.Params) {
				out = append(out, lib[pstr(cd, "creative_id")].ProductIDs...)
			}
			return uniq(out)
		}
		if c, ok := lib[pstr(up.Params, "creative_id")]; ok {
			return c.ProductIDs
		}
	}
	return nil
}

func cards(p map[string]any) []map[string]any {
	xs, _ := p["cards"].([]any)
	var out []map[string]any
	for _, x := range xs {
		if m, ok := x.(map[string]any); ok {
			out = append(out, m)
		}
	}
	return out
}

func isCarousel(items []PendingItem, a int) (int, bool) {
	u := uploadOf(items, pstr(items[a].Params, "creative_id"))
	return u, u >= 0 && pstr(items[u].Params, "format") == "carousel"
}

func isCatalogAd(items []PendingItem, a int) bool {
	u := uploadOf(items, pstr(items[a].Params, "creative_id"))
	return u >= 0 && pstr(items[u].Params, "format") == "catalog"
}

// itemProducts: what the Approvals card shows as product chips (nil = this card names no product).
func itemProducts(items []PendingItem, i int, lib map[string]model.Creative) []string {
	switch items[i].Kind {
	case model.KindCreateProductSet:
		return filterIDs(pstr(items[i].Params, "filter"))
	case model.KindCreateAd:
		if isCatalogAd(items, i) {
			return nil // the product set's card carries them
		}
		return adProducts(items, i, lib)
	case model.KindCreateAdSet:
		if pstr(items[i].Params, "product_set_id") != "" {
			return nil
		}
		var out []string
		for _, a := range adsOf(items, i) {
			if !isCatalogAd(items, a) {
				out = append(out, adProducts(items, a, lib)...)
			}
		}
		return uniq(out)
	}
	return nil
}

// ── the rebuild ──

type rebuilder struct {
	ac      *Acct
	ctx     context.Context
	in      model.CycleInput
	items   []PendingItem
	changed map[string]bool // proposal ids to check again
	newCopy []string        // products whose copy was written now (template / API)
	withMe  bool
	copyOf  func(model.Product) map[string][2]string
}

func (ac *Acct) newRebuilder(ctx context.Context, ps PendingSet) *rebuilder {
	in := ac.input()
	ac.app.mu.RLock()
	br, claudeOK := ac.app.brain, ac.app.conns.Claude.OK
	ac.app.mu.RUnlock()
	rb := &rebuilder{ac: ac, ctx: ctx, in: in, items: ps.Items, changed: map[string]bool{}}
	if w, ok := br.(interface{ CreationWithMe(string) bool }); ok {
		rb.withMe = w.CreationWithMe(ac.id)
	}
	pb := *in.Playbook
	cache := map[string]map[string][2]string{}
	rb.copyOf = func(p model.Product) map[string][2]string { // the API's copy only when that mode is on (credits)
		if m, ok := cache[p.ID]; ok {
			return m
		}
		m := map[string][2]string{}
		cache[p.ID] = m
		cw, ok := br.(model.Copywriter)
		if !ok || !claudeOK || rb.withMe {
			return m
		}
		cctx, cancel := context.WithTimeout(ctx, 90*time.Second)
		defer cancel()
		out, err := cw.Copy(cctx, model.CopyInput{ProductIDs: []string{p.ID}, Langs: []string{"ar", "en"}, Angle: "authentic Korean skincare", Brief: ownerCopyBrief(pb, ""), Shop: in.Shop, Research: in.Research})
		if err != nil {
			ac.st.Append("config", "copy from Claude failed, the benefit-first template is used: "+err.Error(), nil)
			return m
		}
		for _, v := range out.Variants {
			if _, have := m[v.Lang]; !have && v.Headline != "" && v.PrimaryText != "" {
				m[v.Lang] = [2]string{v.Headline, v.PrimaryText}
			}
		}
		return m
	}
	return rb
}

func (rb *rebuilder) lib() map[string]model.Creative {
	m := map[string]model.Creative{}
	for _, c := range rb.ac.creatives.all() {
		m[c.ID] = c
	}
	return m
}

func (rb *rebuilder) product(id string) (model.Product, bool) {
	for _, p := range rb.in.Shop.Products {
		if p.ID == id {
			return p, true
		}
	}
	return model.Product{}, false
}

// creative: the product's library creative in that language (a ready design, else the shop photo with the copy).
func (rb *rebuilder) creative(p model.Product, lang string) (model.Creative, error) {
	n := len(rb.ac.creatives.all())
	id, err := rb.ac.starterCreative(rb.ctx, p, lang, rb.copyOf)
	if err != nil {
		return model.Creative{}, err
	}
	if len(rb.ac.creatives.all()) > n {
		rb.newCopy = append(rb.newCopy, p.ID)
	}
	c, _, err := rb.ac.creatives.Get(id)
	if err != nil || strings.TrimSpace(c.Headline+c.PrimaryText) == "" { // never an ad with empty copy (QA)
		return model.Creative{}, fmt.Errorf("creative %s could not be read back: %v", id, err)
	}
	return c, nil
}

func adLang(it PendingItem, lib map[string]model.Creative) string {
	if c, ok := lib[pstr(it.Params, "creative_id")]; ok && (c.Lang == "ar" || c.Lang == "en") {
		return c.Lang
	}
	n := " " + pstr(it.Params, "name") + " "
	if strings.Contains(n, " EN ") && !strings.Contains(n, "AR") {
		return "en"
	}
	return "ar"
}

func renamed(name string, p model.Product) string {
	if i := strings.LastIndex(name, " · "); i > 0 {
		return name[:i] + " · " + shortTitle(p)
	}
	return name + " · " + shortTitle(p)
}

func (rb *rebuilder) refs(target string, except int) int {
	n := 0
	for j, it := range rb.items {
		if j == except || !open2(it.Status) {
			continue
		}
		set := map[string]bool{}
		collectStrings(it.Params, set)
		if set[target] {
			n++
		}
	}
	return n
}

// imageSlot: the waiting ad a now shows product p (its creative made or reused, its upload rewritten or added).
func (rb *rebuilder) imageSlot(a int, p model.Product, note string) error {
	lib := rb.lib()
	ad := &rb.items[a]
	c, err := rb.creative(p, adLang(*ad, lib))
	if err != nil {
		return err
	}
	old := pstr(ad.Params, "creative_id")
	up := map[string]any{"creative_id": c.ID, "format": "image", "headline": c.Headline, "primary_text": c.PrimaryText}
	if u := uploadOf(rb.items, old); u >= 0 && pstr(rb.items[u].Params, "format") != "carousel" && rb.refs(old, u) <= 1 {
		rb.items[u].Target, rb.items[u].Params, rb.items[u].Status, rb.items[u].Note = c.ID, up, "shadow", note
		rb.changed[rb.items[u].ID] = true
	} else if c.MetaID == "" && uploadOf(rb.items, c.ID) < 0 {
		nu := PendingItem{Proposal: model.Proposal{ID: rb.freeID(ad.ID + "-cr"), Kind: model.KindUploadCreative, Target: c.ID, Params: up, Why: ad.Why, Confidence: ad.Confidence}, Status: "shadow", By: "owner", Note: note}
		rb.items = append(rb.items[:a], append([]PendingItem{nu}, rb.items[a:]...)...)
		a++
		ad = &rb.items[a]
	}
	ad.Params["creative_id"] = c.ID
	ad.Params["name"] = renamed(pstr(ad.Params, "name"), p)
	ad.Status, ad.Note, ad.Result = "shadow", note, nil
	rb.changed[ad.ID] = true
	return nil
}

func (rb *rebuilder) freeID(base string) string {
	id := base
	for n := 2; idxOf(rb.items, id) >= 0; n++ {
		id = fmt.Sprintf("%s%d", base, n)
	}
	return id
}

// carouselSlot: the waiting carousel ad shows these products, one card each (2..10).
func (rb *rebuilder) carouselSlot(a, u int, ps []model.Product, note string) error {
	if len(ps) < 2 {
		return errors.New("a carousel needs at least 2 products")
	}
	lang := adLang(rb.items[a], rb.lib())
	var cs []any
	var first model.Creative
	for i, p := range ps {
		c, err := rb.creative(p, lang)
		if err != nil {
			return err
		}
		if i == 0 {
			first = c
		}
		cs = append(cs, map[string]any{"creative_id": c.ID, "headline": c.Headline, "link": p.URL})
	}
	up := &rb.items[u]
	up.Params["cards"], up.Params["headline"], up.Params["primary_text"] = cs, first.Headline, first.PrimaryText
	up.Status, up.Note, up.Result = "shadow", note, nil
	rb.items[a].Params["name"] = renamed(pstr(rb.items[a].Params, "name"), ps[0])
	rb.items[a].Status, rb.items[a].Note, rb.items[a].Result = "shadow", note, nil
	rb.changed[up.ID], rb.changed[rb.items[a].ID] = true, true
	return nil
}

// drop: a waiting ad (and its upload, when nothing else uses it) leaves the plan.
func (rb *rebuilder) drop(a int) {
	id := rb.items[a].ID
	cid := pstr(rb.items[a].Params, "creative_id")
	u := uploadOf(rb.items, cid)
	var keep []PendingItem
	for j, it := range rb.items {
		if it.ID == id || (j == u && rb.refs(cid, u) <= 1) {
			continue
		}
		keep = append(keep, it)
	}
	rb.items = keep
	delete(rb.changed, id)
}

// choose: the ad has no allowed product left: it stays, says so, and waits for the owner's pick.
func (rb *rebuilder) choose(a int, msg string) {
	rb.items[a].Status, rb.items[a].Note, rb.items[a].Result = stChoose, msg, nil
	if u := uploadOf(rb.items, pstr(rb.items[a].Params, "creative_id")); u >= 0 {
		rb.items[u].Status, rb.items[u].Note, rb.items[u].Result = stChoose, msg, nil
	}
	delete(rb.changed, rb.items[a].ID)
}

// slots: the ads get the products in order; more products than ads add ads in the same ad set (clone=true), fewer
// remove the extra ads.
func (rb *rebuilder) slots(ads []int, ps []model.Product, clone bool, note string) error {
	ids := make([]string, len(ads))
	for k, a := range ads {
		ids[k] = rb.items[a].ID
	}
	for k, p := range ps {
		if k < len(ids) {
			a := idxOf(rb.items, ids[k])
			if _, car := isCarousel(rb.items, a); car {
				if err := rb.imageSlotFromCarousel(a, p, note); err != nil {
					return err
				}
				continue
			}
			if err := rb.imageSlot(a, p, note); err != nil {
				return err
			}
			continue
		}
		if !clone {
			break
		}
		src := idxOf(rb.items, ids[0])
		ad := rb.items[src]
		nid := rb.freeID(ad.ID + "-p")
		np := map[string]any{}
		for k2, v := range ad.Params {
			np[k2] = v
		}
		cp := PendingItem{Proposal: model.Proposal{ID: nid, Kind: model.KindCreateAd, Target: ad.Target + "-" + strings.TrimPrefix(nid, ad.ID+"-"), Params: np, Why: ad.Why, Confidence: ad.Confidence, Evidence: ad.Evidence}, Status: "shadow", By: "owner"}
		last := idxOf(rb.items, ids[len(ids)-1])
		rb.items = append(rb.items[:last+1], append([]PendingItem{cp}, rb.items[last+1:]...)...)
		ids = append(ids, nid)
		if err := rb.imageSlot(idxOf(rb.items, nid), p, note); err != nil {
			return err
		}
	}
	for k := len(ps); k < len(ids); k++ {
		if a := idxOf(rb.items, ids[k]); a >= 0 {
			rb.drop(a)
		}
	}
	return nil
}

// imageSlotFromCarousel: a carousel slot given one product keeps the carousel when it can (the product first, the
// other cards kept); a single product makes it an image ad.
func (rb *rebuilder) imageSlotFromCarousel(a int, p model.Product, note string) error {
	u, _ := isCarousel(rb.items, a)
	lib := rb.lib()
	ps := []model.Product{p}
	for _, cd := range cards(rb.items[u].Params) {
		for _, id := range lib[pstr(cd, "creative_id")].ProductIDs {
			if q, ok := rb.product(id); ok && q.ID != p.ID && usable(q) {
				ps = append(ps, q)
			}
		}
	}
	return rb.carouselSlot(a, u, ps, note)
}

// apply: the owner's products onto proposal i (its whole scope).
func (rb *rebuilder) apply(i int, ps []model.Product) error {
	const note = "products chosen by you"
	if s := productSetOf(rb.items, i); s >= 0 {
		var ids []string
		for _, p := range ps {
			ids = append(ids, p.ID)
		}
		it := &rb.items[s]
		it.Params["filter"], it.Status, it.Note, it.Result = setFilter(ids), "shadow", note, nil
		rb.changed[it.ID] = true
		// the catalog ad and its creative were waiting on it: they wait again
		for j := range rb.items {
			if rb.items[j].Status == stChoose && strings.Contains(fmt.Sprint(rb.items[j].Params), it.Target) {
				rb.items[j].Status, rb.items[j].Note = "shadow", ""
				rb.changed[rb.items[j].ID] = true
			}
		}
		return nil
	}
	ads := adsOf(rb.items, i)
	if len(ads) == 0 {
		return errors.New("this proposal shows no product: choose them on its ad or ad set")
	}
	if len(ads) == 1 {
		if u, car := isCarousel(rb.items, ads[0]); car {
			if len(ps) >= 2 {
				return rb.carouselSlot(ads[0], u, ps, note)
			}
		}
	}
	return rb.slots(ads, ps, true, note)
}

// recheck: the executor's checks again (shadow: nothing is written) on every changed proposal and what it needs.
func (rb *rebuilder) recheck() {
	if len(rb.changed) == 0 {
		return
	}
	rb.ac.mu.RLock()
	ex := rb.ac.exec
	rb.ac.mu.RUnlock()
	if ex == nil {
		return
	}
	env := rb.ac.env(model.AutopilotShadow)
	done := map[string]bool{}
	for i := range rb.items {
		if !rb.changed[rb.items[i].ID] || done[rb.items[i].ID] || !waiting(rb.items[i].Status) {
			continue
		}
		order := withDeps(rb.items, i)
		var props []model.Proposal
		for _, k := range order {
			props = append(props, rb.items[k].Proposal)
		}
		res := ex.Run(rb.ctx, env, props)
		for k, r := range res {
			r := r
			it := &rb.items[order[k]]
			if rb.changed[it.ID] || waiting(it.Status) {
				it.Status, it.Result = r.Status, &r
				done[it.ID] = true
			}
		}
	}
}

// creation: "With me in Claude": new copy for the chosen products is the owner's to make — one Creation request for
// just this item; the template copy stands in so it can still be approved.
func (rb *rebuilder) creation(title, titleAR string, ids []string) string {
	if !rb.withMe || len(ids) == 0 {
		return ""
	}
	rb.ac.app.mu.RLock()
	br := rb.ac.app.brain
	rb.ac.app.mu.RUnlock()
	c, ok := br.(interface {
		OccasionCreation(acct, title, titleAR, brief string, productIDs []string) string
	})
	if !ok {
		return ""
	}
	return c.OccasionCreation(rb.ac.id, title, titleAR, ownerCopyBrief(*rb.in.Playbook, "New copy for the products the owner chose for: "+title), uniq(ids))
}

// ── the owner's actions ──

// changeProducts: POST /api/proposals/{id}/products.
func (ac *Acct) changeProducts(ctx context.Context, id string, ids []string) (map[string]any, int, error) {
	if !ac.work.TryLock() {
		return nil, 409, errBusy
	}
	defer ac.work.Unlock()
	ps0 := ac.Pending()
	ps := deepPending(ps0)
	i := idxOf(ps.Items, id)
	if i < 0 {
		return nil, 404, fmt.Errorf("no proposal %q in the last plan", id)
	}
	if !open2(ps.Items[i].Status) {
		return nil, 409, fmt.Errorf("%w (it is %s)", errNotWaiting, ps.Items[i].Status)
	}
	prods, err := ac.chosen(ids)
	if err != nil {
		return nil, 400, err
	}
	rb := ac.newRebuilder(ctx, ps)
	if err := rb.apply(i, prods); err != nil {
		return nil, 400, err
	}
	rb.recheck()
	name := pstr(ps.Items[i].Params, "name")
	if name == "" {
		name = ps.Items[i].Target
	}
	creq := rb.creation("Your products for "+name, "منتجاتك لـ "+name, rb.newCopy)
	if creq != "" {
		for k := range rb.items {
			if rb.changed[rb.items[k].ID] {
				rb.items[k].Creation = creq
			}
		}
	}
	ps.Items = rb.items
	ac.keepUndo(undoStep{Label: "products of " + name, Pending: ps0, HasPend: true})
	ac.savePending(ps)
	var titles []string
	for _, p := range prods {
		titles = append(titles, shortTitle(p))
	}
	ac.st.Append("approval", fmt.Sprintf("you chose the products of %s: %s (%d proposals rebuilt, checked again)", name, strings.Join(titles, ", "), len(rb.changed)), map[string]any{"proposal": id, "products": ids, "creation": creq})
	return map[string]any{"account": ac.id, "id": id, "products": ids, "rebuilt": len(rb.changed), "creation": creq, "copy": map[bool]string{true: "with_me", false: "template_or_api"}[rb.withMe], "proposals": ac.pendingView().Items}, 200, nil
}

// avoidProduct: POST /api/products/{id}/avoid — never again: Playbook Avoid, and every waiting proposal and open job
// that used it rebuilt (the next-ranked allowed product), or left saying "choose products" when none is left.
func (ac *Acct) avoidProduct(ctx context.Context, id string) (map[string]any, int, error) {
	if !ac.work.TryLock() {
		return nil, 409, errBusy
	}
	defer ac.work.Unlock()
	a := ac.app
	a.mu.RLock()
	shop := a.shopSnap
	a.mu.RUnlock()
	var prod model.Product
	found := false
	for _, p := range shop.Products {
		if p.ID == id {
			prod, found = p, true
		}
	}
	if !found {
		return nil, 404, fmt.Errorf("product %s is not in the shop", id)
	}
	pb := ac.playbook()
	u := undoStep{Label: "avoid " + shortTitle(prod), Focus: pb.Focus, Avoid: pb.Avoid, PB: true}
	rule := model.PBRule{Kind: "product", Value: id, Label: prod.Title}
	var focus []model.PBRule
	for _, r := range pb.Focus {
		if !(r.Kind == "product" && r.Match(prod)) {
			focus = append(focus, r)
		}
	}
	pb.Focus, pb.Avoid = focus, append(append([]model.PBRule{}, pb.Avoid...), rule)
	if err := ac.savePlaybook(pb, fmt.Sprintf("you avoided %s (Approvals): never advertised again", prod.Title)); err != nil {
		return nil, 500, err
	}
	byID := map[string]model.Product{}
	for _, p := range shop.Products {
		byID[p.ID] = p
	}
	gone := func(pid string) bool { // the product, or a variant of it ("123-45" is product 123)
		q, ok := byID[pid]
		return pid == id || ok && rule.Match(q)
	}
	// waiting proposals
	ps0 := ac.Pending()
	ps := deepPending(ps0)
	rb := ac.newRebuilder(ctx, ps)
	const note = "rebuilt: you avoided a product"
	lib := rb.lib()
	used := map[string]bool{}
	for k := range rb.items {
		if rb.items[k].Kind == model.KindCreateAd && open2(rb.items[k].Status) {
			for _, p := range adProducts(rb.items, k, lib) {
				used[p] = true
			}
		}
	}
	ranked := model.Rank(rb.in.Shop.Products, *rb.in.Playbook, model.RankSignals{Now: rb.in.Now, Trending: ac.trendingIDs(), Baseline: firstSeenBaseline(rb.in.Shop)})
	next := func() (model.Product, bool) {
		for _, r := range ranked {
			if p, ok := rb.product(r.ID); ok && !used[r.ID] && usable(p) {
				used[r.ID] = true
				return p, true
			}
		}
		return model.Product{}, false
	}
	rebuilt, blocked := 0, 0
	var ids []string
	for k := range rb.items {
		if rb.items[k].Kind == model.KindCreateAd && open2(rb.items[k].Status) {
			ids = append(ids, rb.items[k].ID)
		}
	}
	for _, aid := range ids {
		k := idxOf(rb.items, aid)
		if k < 0 || isCatalogAd(rb.items, k) {
			continue
		}
		lib = rb.lib()
		pids := adProducts(rb.items, k, lib)
		hit := false
		var keep []model.Product
		for _, pid := range pids {
			if gone(pid) {
				hit = true
			} else if p, ok := rb.product(pid); ok && usable(p) {
				keep = append(keep, p)
			}
		}
		if !hit {
			continue
		}
		if u, car := isCarousel(rb.items, k); car {
			for len(keep) < 2 {
				p, ok := next()
				if !ok {
					break
				}
				keep = append(keep, p)
			}
			if len(keep) >= 2 {
				if err := rb.carouselSlot(k, u, keep, note); err != nil {
					return nil, 500, err
				}
				rebuilt++
				continue
			}
			rb.choose(k, msgAdAvoided)
			blocked++
			continue
		}
		p, ok := next()
		if !ok {
			rb.choose(k, msgAdAvoided)
			blocked++
			continue
		}
		if err := rb.imageSlot(k, p, note); err != nil {
			return nil, 500, err
		}
		rebuilt++
	}
	for k := range rb.items { // catalog product sets lose it
		it := &rb.items[k]
		if it.Kind != model.KindCreateProductSet || !open2(it.Status) {
			continue
		}
		fids := filterIDs(pstr(it.Params, "filter"))
		var keep []string
		for _, f := range fids {
			if !gone(f) {
				keep = append(keep, f)
			}
		}
		if len(keep) == len(fids) {
			continue
		}
		rebuilt++
		if len(keep) == 0 {
			it.Status, it.Note, it.Result = stChoose, msgAdAvoided, nil
			blocked++
			continue
		}
		it.Params["filter"], it.Note = setFilter(keep), note
		rb.changed[it.ID] = true
	}
	rb.recheck()
	ps.Items = rb.items
	if rebuilt > 0 {
		u.Pending, u.HasPend = ps0, true
		ac.savePending(ps)
	}
	// open jobs
	js := ac.jobSet()
	now := a.now()
	nJobs := 0
	for k := range js.Jobs {
		j := &js.Jobs[k]
		if !open(j.Status) {
			continue
		}
		before := *j
		var keep []string
		for _, pid := range j.ProductIDs {
			if !gone(pid) {
				keep = append(keep, pid)
			}
		}
		var crs []string
		for _, cid := range j.Creatives {
			if c, _, err := ac.creatives.Get(cid); err == nil {
				out := false
				for _, pid := range c.ProductIDs {
					out = out || gone(pid)
				}
				if out {
					continue
				}
			}
			crs = append(crs, cid)
		}
		if len(keep) == len(j.ProductIDs) && len(crs) == len(j.Creatives) {
			continue
		}
		u.Jobs = append(u.Jobs, before)
		nJobs++
		if keep == nil {
			keep = []string{}
		}
		j.ProductIDs, j.Creatives = keep, crs
		if (len(keep) == 0 || j.Kind == "occasion" && len(crs) == 0) && (j.ID != jobFirstLaunch || j.Edited) { // an unedited first launch just picks the next-ranked
			j.ProductsGone, j.Waiting = true, msgJobAvoided
			j.logf(now, "you avoided %s: %s", shortTitle(prod), msgJobAvoided)
		} else {
			j.logf(now, "you avoided %s: it is out of this job", shortTitle(prod))
		}
	}
	if nJobs > 0 {
		ac.saveJobs(js)
	}
	ac.keepUndo(u)
	ac.st.Append("approval", fmt.Sprintf("you avoided %s: %d waiting proposals rebuilt (%d need your products), %d jobs changed", prod.Title, rebuilt, blocked, nJobs), map[string]any{"product": id})
	return map[string]any{"account": ac.id, "avoided": id, "title": prod.Title, "rebuilt": rebuilt, "choose": blocked, "jobs": nJobs, "undo": true}, 200, nil
}

// pinProduct: POST /api/products/{id}/pin — pin (or unpin) in the Playbook's Focus: ranked first, always considered.
func (ac *Acct) pinProduct(id string) (map[string]any, int, error) {
	a := ac.app
	a.mu.RLock()
	shop := a.shopSnap
	a.mu.RUnlock()
	var prod model.Product
	found := false
	for _, p := range shop.Products {
		if p.ID == id {
			prod, found = p, true
		}
	}
	if !found {
		return nil, 404, fmt.Errorf("product %s is not in the shop", id)
	}
	pb := ac.playbook()
	for _, r := range pb.Avoid {
		if r.Match(prod) {
			return nil, 409, errors.New("this product is avoided: take it off Avoid on Strategy first")
		}
	}
	u := undoStep{Label: "pin " + shortTitle(prod), Focus: pb.Focus, Avoid: pb.Avoid, PB: true}
	var focus []model.PBRule
	was := false
	for _, r := range pb.Focus {
		if r.Kind == "product" && r.Match(prod) {
			was = true
			continue
		}
		focus = append(focus, r)
	}
	what := "you unpinned " + prod.Title
	if !was {
		focus = append([]model.PBRule{{Kind: "product", Value: id, Label: prod.Title}}, focus...)
		what = "you pinned " + prod.Title + " (Approvals): ranked first, always considered"
	}
	pb.Focus = focus
	if focus == nil {
		pb.Focus = []model.PBRule{}
	}
	if err := ac.savePlaybook(pb, what); err != nil {
		return nil, 500, err
	}
	ac.keepUndo(u)
	return map[string]any{"account": ac.id, "product": id, "pinned": !was, "undo": true}, 200, nil
}

// undo: POST /api/undo — the last product action, undone. The waiting proposals come back only while they are still
// the same plan (a cycle since has made a new one).
func (ac *Acct) undo() (map[string]any, int, error) {
	v, ok := undos.LoadAndDelete(ac.id)
	if !ok {
		return nil, 409, errors.New("nothing to undo")
	}
	if !ac.work.TryLock() {
		undos.Store(ac.id, v)
		return nil, 409, errBusy
	}
	defer ac.work.Unlock()
	u := v.(undoStep)
	if u.PB {
		pb := ac.playbook()
		pb.Focus, pb.Avoid = u.Focus, u.Avoid
		if err := ac.savePlaybook(pb, "undo: "+u.Label); err != nil {
			return nil, 500, err
		}
	}
	plan := false
	if u.HasPend && ac.Pending().CycleAt.Equal(u.Pending.CycleAt) {
		ac.savePending(u.Pending)
		plan = true
	}
	if len(u.Jobs) > 0 {
		js := ac.jobSet()
		for _, old := range u.Jobs {
			for k := range js.Jobs {
				if js.Jobs[k].ID == old.ID && open(js.Jobs[k].Status) {
					js.Jobs[k] = old
				}
			}
		}
		ac.saveJobs(js)
	}
	ac.st.Append("approval", "you undid: "+u.Label, nil)
	return map[string]any{"account": ac.id, "undone": u.Label, "plan_restored": plan}, 200, nil
}

// savePlaybook: the Playbook as the owner changed it from Approvals / Plan (normalised, versioned, logged).
func (ac *Acct) savePlaybook(next model.Playbook, what string) error {
	cur := ac.playbook()
	next = next.Normalize()
	next.UpdatedAt, next.Version = ac.app.now().UTC(), cur.Version+1
	if err := ac.st.Save("playbook", next); err != nil {
		return err
	}
	ac.mu.Lock()
	ac.pb = &next
	ac.stateJSON = nil
	ac.mu.Unlock()
	ac.st.Append("config", fmt.Sprintf("playbook (v%d): %s", next.Version, what), map[string]any{"changes": diffPlaybook(cur, next)})
	return nil
}

// ── first launch / occasion jobs edited on the Plan ──

// starterProducts: the first-launch job's products onto the waiting first launch (Practice): each language's
// prospecting ads get the products in order (the funnel's own rule: Arabic ads first, English the top product).
func (ac *Acct) starterToProducts(ctx context.Context, ids []string) int {
	if len(ids) == 0 || !ac.work.TryLock() {
		return 0
	}
	defer ac.work.Unlock()
	prods, err := ac.chosen(ids)
	if err != nil {
		return 0
	}
	ps0 := ac.Pending()
	ps := deepPending(ps0)
	rb := ac.newRebuilder(ctx, ps)
	for _, l := range []string{"ar", "en"} {
		lib := rb.lib()
		var g []int // collected again per language: the first language's rebuild moves indices
		for k, it := range rb.items {
			if strings.HasPrefix(it.ID, "starter-") && it.Kind == model.KindCreateAd && open2(it.Status) && !rb.changed[it.ID] &&
				strings.Contains(pstr(it.Params, "name"), "Prospecting") && !isCatalogAd(rb.items, k) &&
				!strings.Contains(pstr(it.Params, "adset_id"), "existing") && adLang(it, lib) == l {
				g = append(g, k)
			}
		}
		if len(g) == 0 {
			continue
		}
		n := len(prods)
		if n > len(g) {
			n = len(g)
		}
		if err := rb.slots(g, prods[:n], false, "products chosen by you (Plan → first launch)"); err != nil {
			return 0
		}
	}
	rb.recheck()
	ps.Items = rb.items
	ac.savePending(ps)
	return len(rb.changed)
}

// occasionToProducts: an occasion package edited to other products gets its ads prepared again for them.
func (ac *Acct) occasionToProducts(ctx context.Context, j *Job) {
	if j.Occasion == nil {
		return
	}
	prods, err := ac.chosen(j.ProductIDs)
	if err != nil {
		return
	}
	rb := ac.newRebuilder(ctx, ac.Pending())
	pb := *rb.in.Playbook
	var crs []string
	for _, p := range prods {
		for _, lang := range pb.Languages {
			n := len(ac.creatives.all())
			if cid, err := ac.occasionCreative(p, lang, j.Occasion.Occasion, rb.copyOf); err == nil {
				crs = append(crs, cid)
				if len(ac.creatives.all()) > n {
					rb.newCopy = append(rb.newCopy, p.ID)
				}
			}
		}
	}
	j.Creatives, j.Rejected = crs, nil
	if rid := rb.creation(j.Occasion.Name+": your products", j.Occasion.NameAR+": منتجاتك", rb.newCopy); rid != "" {
		j.logf(ac.app.now(), "creation request %s raised for your products (With me in Claude)", rid)
	}
}

// ── what Approvals reads: each waiting proposal with the products it shows ──

type pickProd struct {
	ID     string  `json:"id"`
	Title  string  `json:"title"`
	Image  string  `json:"image,omitempty"`
	Price  float64 `json:"price"`
	Pinned bool    `json:"pinned,omitempty"`
}

type pendingOut struct {
	PendingItem
	Products []pickProd `json:"products,omitempty"`
	More     int        `json:"products_more,omitempty"` // a catalog product set names more than are shown
}

type pendingViewSet struct {
	CycleAt time.Time    `json:"cycle_at"`
	Mode    string       `json:"mode"`
	Items   []pendingOut `json:"proposals"`
}

func (ac *Acct) pendingView() pendingViewSet {
	ps := ac.Pending()
	a := ac.app
	a.mu.RLock()
	shop := a.shopSnap
	a.mu.RUnlock()
	by := map[string]model.Product{}
	for _, p := range shop.Products {
		by[p.ID] = p
	}
	pb := ac.playbook()
	lib := map[string]model.Creative{}
	for _, c := range ac.creatives.all() {
		lib[c.ID] = c
	}
	out := pendingViewSet{CycleAt: ps.CycleAt, Mode: ps.Mode, Items: make([]pendingOut, 0, len(ps.Items))}
	for i, it := range ps.Items {
		o := pendingOut{PendingItem: it}
		if open2(it.Status) {
			ids := itemProducts(ps.Items, i, lib)
			for k, id := range ids {
				if k >= 12 {
					o.More = len(ids) - k
					break
				}
				if p, ok := by[id]; ok {
					o.Products = append(o.Products, pickProd{p.ID, p.Title, p.Image, p.PriceAED, pb.Pinned(p)})
				}
			}
		}
		out.Items = append(out.Items, o)
	}
	return out
}

// ── routes ──

func (s *server) pickerAPI(w http.ResponseWriter, r *http.Request, ac *Acct, p string) {
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Minute)
	defer cancel()
	var out map[string]any
	var code int
	var err error
	switch {
	case p == "/api/catalog" && r.Method == http.MethodGet:
		writeJSON(w, map[string]any{"account": ac.id, "products": ac.catalog()})
		return
	case p == "/api/undo" && r.Method == http.MethodPost:
		out, code, err = ac.undo()
	case strings.HasPrefix(p, "/api/products/") && r.Method == http.MethodPost:
		rest := strings.TrimPrefix(p, "/api/products/")
		i := strings.LastIndex(rest, "/")
		if i < 0 || !productIDRe.MatchString(rest[:i]) {
			jsonErr(w, 404, "POST /api/products/{id}/avoid or /pin")
			return
		}
		switch rest[i+1:] {
		case "avoid":
			out, code, err = ac.avoidProduct(ctx, rest[:i])
		case "pin":
			out, code, err = ac.pinProduct(rest[:i])
		default:
			jsonErr(w, 404, "POST /api/products/{id}/avoid or /pin")
			return
		}
	default:
		jsonErr(w, 404, "GET /api/catalog, POST /api/products/{id}/avoid|pin, POST /api/undo")
		return
	}
	if err != nil {
		jsonErr(w, code, err.Error())
		return
	}
	ac.dirty()
	writeJSON(w, out)
}

// titlesOf: product id → title for the cards that list products (jobs, occasion packages).
func (ac *Acct) titlesOf(jobs []Job) map[string]string {
	a := ac.app
	a.mu.RLock()
	shop := a.shopSnap
	a.mu.RUnlock()
	want := map[string]bool{}
	for _, j := range jobs {
		for _, id := range j.ProductIDs {
			want[id] = true
		}
	}
	out := map[string]string{}
	for _, p := range shop.Products {
		if want[p.ID] {
			out[p.ID] = p.Title
		}
	}
	return out
}
