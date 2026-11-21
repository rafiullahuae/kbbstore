package main

// slots.go — every occasion package (and every job that names products) holds a fixed number of product slots, filled
// with the best products for it (the owner, 21 Nov 2026: "always give me 5 products from the most selling / latest
// ones, and if i remove any product, it should come in next suggested products. once i press Find Products button
// it should perform the new list and fill the missing products").
//
//	POST /api/jobs/{id}/remove-product {product_id}  ✕ on a chip: out of THIS job ("not for this occasion", never
//	                                                 suggested to it again) and the next-best product takes its slot,
//	                                                 its Arabic + English ads prepared in place
//	POST /api/jobs/{id}/find-products                fresh shop data (sales, stock, new arrivals), then every empty
//	                                                 slot refilled and every product that can no longer be advertised
//	                                                 (out of stock, avoided, under the stock floor) replaced; the
//	                                                 products he kept stay, pinned ones first
//
// Instant: the ranking is code (model/season.go), the ads are the benefit-first template (or the API's copy when he
// switched that mode on). Logged to Activity and on the job.

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"kaa/model"
)

// occasionOf: the occasion a package was prepared for (the Playbook's current wording of it).
func occasionOf(pb model.Playbook, j Job) model.Occasion {
	if j.Occasion != nil {
		for _, o := range pb.OccasionsOf() {
			if o.ID == j.Occasion.Occasion {
				return o
			}
		}
		return model.Occasion{ID: j.Occasion.Occasion, Name: j.Occasion.Name, Angle: j.Occasion.Angle}
	}
	return model.Occasion{ID: "sale", Name: "best sellers"} // a plain job: best sellers and new arrivals
}

// slotsOf: how many products this job carries.
func slotsOf(pb model.Playbook, j Job) int {
	switch {
	case j.Slots > 0:
		return j.Slots
	case j.Kind == "occasion":
		return pb.Slots()
	case len(j.ProductIDs) > 0:
		return len(j.ProductIDs)
	}
	return 1
}

// ranking: the ids a job's empty slots are filled from, best first (occasions: their season; others: the general
// fresh-first ranking the planner uses).
func (ac *Acct) ranking(in model.CycleInput, j Job) []string {
	sig := model.RankSignals{Now: in.Now, Trending: ac.trendingIDs(), Baseline: firstSeenBaseline(in.Shop)}
	var ids []string
	if j.Kind == "occasion" {
		for _, r := range model.RankForOccasion(in.Shop.Products, *in.Playbook, occasionOf(*in.Playbook, j), sig) {
			ids = append(ids, r.ID)
		}
		return ids
	}
	for _, r := range model.Rank(in.Shop.Products, *in.Playbook, sig) {
		ids = append(ids, r.ID)
	}
	return ids
}

// refill: the job's products that can still be advertised stay (in his order), the empty slots take the next-best
// ones that are not "not for this job"; an occasion package gets its ads prepared for exactly those products.
func (ac *Acct) refill(ctx context.Context, j *Job) (added, dropped []string) {
	rb := ac.newRebuilder(ctx, ac.Pending())
	in := rb.in
	pb := *in.Playbook
	n := slotsOf(pb, *j)
	j.Slots = n
	by := map[string]model.Product{}
	for _, p := range in.Shop.Products { // already Playbook-filtered: avoided / under the floors are not here
		by[p.ID] = p
	}
	notFor := map[string]bool{}
	for _, id := range j.NotFor {
		notFor[id] = true
	}
	var keep []string
	have := map[string]bool{}
	for _, id := range j.ProductIDs {
		if p, ok := by[id]; ok && usable(p) && !notFor[id] && !have[id] {
			keep, have[id] = append(keep, id), true
		} else if !have[id] {
			dropped = append(dropped, id)
		}
	}
	for _, id := range ac.ranking(in, *j) {
		if len(keep) >= n {
			break
		}
		if p := by[id]; !have[id] && !notFor[id] && usable(p) {
			keep, have[id], added = append(keep, id), true, append(added, id)
		}
	}
	if keep == nil {
		keep = []string{}
	}
	j.ProductIDs = keep
	if len(keep) > 0 {
		j.ProductsGone = false
		if j.Waiting == msgJobAvoided {
			j.Waiting = ""
		}
	}
	if j.Kind == "occasion" && j.Occasion != nil {
		var crs []string
		for _, id := range keep {
			for _, lang := range pb.Languages {
				if cid, err := ac.occasionCreative(by[id], lang, j.Occasion.Occasion, rb.copyOf); err == nil {
					crs = append(crs, cid)
				}
			}
		}
		var rej []string
		for _, r := range j.Rejected {
			if contains(crs, r) {
				rej = append(rej, r)
			}
		}
		j.Creatives, j.Rejected = crs, rej
	}
	return added, dropped
}

func (ac *Acct) titleList(ids []string) string {
	t := ac.titlesOf([]Job{{ProductIDs: ids}})
	var out []string
	for _, id := range ids {
		s := t[id]
		if s == "" {
			s = id
		}
		if i := strings.LastIndex(s, " - "); i > 20 {
			s = s[:i]
		}
		out = append(out, s)
	}
	return strings.Join(out, ", ")
}

// freshShop: Find products reads the shop again when the snapshot is older than 10 minutes (sales, stock, new
// arrivals); a newer one is used as it is, so the button stays instant.
func (ac *Acct) freshShop(ctx context.Context) {
	a := ac.app
	a.mu.RLock()
	at, src := a.shopSnap.FetchedAt, a.shopSrc
	a.mu.RUnlock()
	if src == nil || a.now().Sub(at) < 10*time.Minute {
		return
	}
	cctx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()
	_ = a.testShop(cctx)
}

// slotAction: remove-product / find-products on job j (the caller saves the job set).
func (ac *Acct) slotAction(ctx context.Context, j *Job, verb, productID string) (map[string]any, error) {
	if !open(j.Status) {
		return nil, fmt.Errorf("this job already ran (%s)", j.Status)
	}
	now := ac.app.now()
	if j.Kind != "occasion" && j.ID != jobFirstLaunch && len(j.ProductIDs) == 0 && j.Slots == 0 {
		return nil, errors.New("this job does not advertise products")
	}
	if j.Slots == 0 { // the count is fixed before anything is taken out
		j.Slots = slotsOf(ac.playbook(), *j)
	}
	var removed []string
	switch verb {
	case "remove-product":
		if !contains(j.ProductIDs, productID) {
			return nil, errors.New("that product is not in this job")
		}
		if !contains(j.NotFor, productID) {
			j.NotFor = append(j.NotFor, productID)
			if len(j.NotFor) > 200 {
				j.NotFor = j.NotFor[len(j.NotFor)-200:]
			}
		}
		removed = []string{productID}
	case "find-products":
		ac.freshShop(ctx)
	}
	added, dropped := ac.refill(ctx, j)
	for _, d := range dropped {
		if !contains(removed, d) {
			removed = append(removed, d)
		}
	}
	if j.ID == jobFirstLaunch { // the waiting first launch follows at once (its ads hold these products)
		j.Edited = true
		ac.starterToProducts(ctx, j.ProductIDs)
	}
	msg := ""
	switch {
	case verb == "remove-product" && len(added) > 0:
		msg = fmt.Sprintf("you removed %s (not for this job); %s took its slot", ac.titleList(removed), ac.titleList(added))
	case verb == "remove-product":
		msg = fmt.Sprintf("you removed %s (not for this job); no other product fits, %d of %d slots filled", ac.titleList(removed), len(j.ProductIDs), j.Slots)
	case len(added)+len(removed) == 0:
		msg = fmt.Sprintf("Find products: the %d products are still the best; nothing changed", len(j.ProductIDs))
	default:
		msg = fmt.Sprintf("Find products: added %d (%s)", len(added), ac.titleList(added))
		if len(removed) > 0 {
			msg += fmt.Sprintf(", replaced %d that can no longer run (%s)", len(removed), ac.titleList(removed))
		}
	}
	if len(j.ProductIDs) == 0 {
		j.ProductsGone, j.Waiting = true, msgJobAvoided
	}
	j.logf(now, "%s", msg)
	ac.st.Append("approval", fmt.Sprintf("%s: %s", j.Title, msg), map[string]any{"job": j.ID, "added": added, "removed": removed, "products": j.ProductIDs})
	if added == nil {
		added = []string{}
	}
	if removed == nil {
		removed = []string{}
	}
	return map[string]any{"account": ac.id, "added": added, "removed": removed, "added_titles": ac.titleList(added), "removed_titles": ac.titleList(removed),
		"filled": len(j.ProductIDs), "slots": j.Slots, "message": msg}, nil
}

// slotPicks: what the package's chips show for each of its products (photo, units sold, new, trending, the fit
// reason), in the package's order. A product that can no longer run says so.
func (ac *Acct) slotPicks(in model.CycleInput, j Job, trending []string) []model.SlotPick {
	if len(j.ProductIDs) == 0 {
		return []model.SlotPick{}
	}
	sig := model.RankSignals{Now: in.Now, Trending: trending, Baseline: firstSeenBaseline(in.Shop)}
	got := map[string]model.SlotPick{}
	for _, r := range model.RankForOccasion(in.Shop.Products, *in.Playbook, occasionOf(*in.Playbook, j), sig) {
		got[r.ID] = r
	}
	t := ac.titlesOf([]Job{j})
	out := make([]model.SlotPick, 0, len(j.ProductIDs))
	for _, id := range j.ProductIDs {
		if p, ok := got[id]; ok {
			out = append(out, p)
			continue
		}
		out = append(out, model.SlotPick{ID: id, Title: firstNonEmpty(t[id], id), Tags: []string{}, Seasons: []string{},
			Reason: "can no longer run (stock or your playbook) — Find products replaces it", ReasonAR: "لم يعد متاحاً (المخزون أو تعليماتك) — «ابحث عن منتجات» يستبدله"})
	}
	return out
}
