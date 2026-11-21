package main

// sourcepages.go — the products the autopilot may promote are the ones on the owner's source pages (by default the
// super-sale page, 22 Nov), read with the shop every hour, kept across restarts, never fetched inside a cycle's
// filter (filteredShop reads the last list only).

import (
	"context"
	"strings"
	"sync"
	"time"

	"kaa/model"
	"kaa/shop"
)

type pageList struct {
	IDs []string  `json:"ids"`
	At  time.Time `json:"at"`
	Err string    `json:"error,omitempty"`
}

var pagesMu sync.Mutex

func (a *App) pageLists() map[string]pageList {
	pagesMu.Lock()
	defer pagesMu.Unlock()
	m := map[string]pageList{}
	_ = a.st.Load("source_pages", &m)
	return m
}

// refreshSourcePages reads every account's source pages (each page once). A page that cannot be read keeps its
// last list (the error is noted beside it).
func (a *App) refreshSourcePages(ctx context.Context) {
	if a.pagesFromFeed.Load() {
		return
	}
	want := map[string]bool{model.DefaultSourcePage: true}
	for _, ac := range a.Accounts() {
		for _, u := range ac.playbook().Sources() {
			want[u] = true
		}
	}
	cur := a.pageLists()
	changed := false
	for u := range want {
		pctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
		ids, err := shop.PageProducts(pctx, nil, u)
		cancel()
		pl := cur[u]
		if err != nil {
			pl.Err = err.Error()
		} else {
			pl = pageList{IDs: ids, At: a.now().UTC()}
		}
		cur[u] = pl
		changed = true
	}
	if changed {
		pagesMu.Lock()
		_ = a.st.Save("source_pages", cur)
		pagesMu.Unlock()
		a.dirtyAll()
	}
}

// onSourcePages: the product ids on these pages; known=false when one of them was never read.
func (a *App) onSourcePages(pages []string) (map[string]bool, bool) {
	if a.pagesFromFeed.Load() { // -mock and the engine's tests: the fake shop's own products, never the real shop
		a.mu.RLock()
		defer a.mu.RUnlock()
		on := map[string]bool{}
		for _, p := range a.shopSnap.Products {
			on[p.ID] = true
		}
		return on, true
	}
	all := a.pageLists()
	on := map[string]bool{}
	for _, u := range pages {
		pl, ok := all[u]
		if !ok || len(pl.IDs) == 0 {
			return on, false
		}
		for _, id := range pl.IDs {
			on[id] = true
		}
	}
	return on, true
}

// sourcePagesView: for Settings / Strategy — each page, how many products it shows, when it was read.
func (a *App) sourcePagesView(pages []string) []map[string]any {
	all := a.pageLists()
	var out []map[string]any
	for _, u := range pages {
		pl := all[u]
		row := map[string]any{"url": u, "products": len(pl.IDs), "error": pl.Err}
		if !pl.At.IsZero() {
			row["at"] = pl.At
		} else if pl.Err == "" {
			row["error"] = "read with the shop, within the hour"
		}
		if a.pagesFromFeed.Load() {
			row["error"] = "test mode: the fake shop's products"
		}
		out = append(out, row)
	}
	return out
}

func cleanPages(xs []string) []string {
	var out []string
	for _, u := range xs {
		u = strings.TrimSpace(u)
		if shop.ShopPage(u) && len(u) < 300 {
			out = append(out, u)
		}
	}
	return out
}
