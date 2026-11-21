package main

// The product picker (the owner, 21 Nov 2026: "those products i don't want to promote, give me a way to skip on
// products or choose the products myself"). Each test says the defect it catches and the mutation that turns it red.

import (
	"context"
	"strings"
	"testing"
	"time"

	"kaa/metamock"
	"kaa/model"
)

// practice: a Practice account whose first launch waits in Approvals.
func practice(t *testing.T) (*Acct, *server) {
	t.Helper()
	ac, m := testApp(t)
	mocks[ac] = m
	ac.app.brain = quietBrain{}
	ac.settings.Limits.Autopilot = model.AutopilotShadow
	if _, err := ac.Cycle(context.Background(), model.AutopilotShadow); err != nil {
		t.Fatal(err)
	}
	return ac, testServer(ac)
}

var mocks = map[*Acct]*metamock.Server{}

func firstAd(ac *Acct, lang string) PendingItem {
	for _, it := range ac.Pending().Items {
		if it.Kind == model.KindCreateAd && strings.HasPrefix(pstr(it.Params, "name"), "Prospecting "+strings.ToUpper(lang)) && waiting(it.Status) {
			return it
		}
	}
	return PendingItem{}
}

// shown: every product id a waiting proposal shows (what the owner sees on the Approvals cards).
func shown(ac *Acct) map[string]bool {
	m := map[string]bool{}
	for _, it := range ac.pendingView().Items {
		if open2(it.Status) {
			for _, p := range it.Products {
				m[p.ID] = true
			}
		}
	}
	return m
}

// Change products → the approved ad is exactly the chosen product on the wire, and the catalog product set is
// exactly the chosen ids. Before, the owner could only approve or set aside what the planner picked.
// MUTATION: skip `ad.Params["creative_id"] = c.ID` in imageSlot → the old product goes live, red; skip the
// setFilter line in apply → the product set keeps every in-stock product, red.
func TestChangeProductsRunsExactlyThose(t *testing.T) {
	ac, s := practice(t)
	m := mocks[ac]
	ad := firstAd(ac, "ar")
	if ad.ID == "" {
		t.Fatalf("no waiting Arabic ad: %+v", ac.Pending().Items)
	}
	code, out := call(t, s, "POST", "/api/proposals/"+ad.ID+"/products", ac.id, map[string]any{"product_ids": []string{"7301"}})
	if code != 200 {
		t.Fatalf("change: %d %v", code, out)
	}
	if code, _ := call(t, s, "POST", "/api/proposals/"+ad.ID+"/products", ac.id, map[string]any{"product_ids": []string{"5120"}}); code != 400 {
		t.Fatal("an out-of-stock product was accepted")
	}
	got := firstAd(ac, "ar")
	if got.ID != ad.ID || pstr(got.Params, "creative_id") != "st-7301-ar" || got.Status != "shadow" || got.Note == "" {
		t.Fatalf("rebuilt ad %+v", got)
	}
	var ps PendingItem
	for _, it := range ac.Pending().Items {
		if it.Kind == model.KindCreateProductSet {
			ps = it
		}
	}
	if ps.ID == "" {
		t.Fatal("no catalog product set waiting")
	}
	if code, out := call(t, s, "POST", "/api/proposals/"+ps.ID+"/products", ac.id, map[string]any{"product_ids": []string{"6120", "8022"}}); code != 200 {
		t.Fatalf("product set: %d %v", code, out)
	}
	n0 := len(m.Writes())
	catAd := ad.ID[:strings.LastIndex(ad.ID, "-ad-")] + "-ad-cat"
	for _, id := range []string{ad.ID, catAd} {
		if code, out := call(t, s, "POST", "/api/proposals/"+id+"/approve", ac.id, map[string]any{}); code != 200 {
			t.Fatalf("approve %s: %d %v", id, code, out)
		}
	}
	var creatives []string
	var filter string
	for _, w := range m.Writes()[n0:] {
		if w.Status != 200 {
			continue
		}
		if strings.HasSuffix(w.Path, "/adcreatives") && strings.Contains(w.Form["object_story_spec"], "link_data") {
			creatives = append(creatives, w.Form["object_story_spec"])
		}
		if strings.HasSuffix(w.Path, "/product_sets") {
			filter = w.Form["filter"]
		}
	}
	if len(creatives) != 1 || !strings.Contains(creatives[0], "anua-foam") {
		t.Fatalf("the approved ad is not exactly the chosen product: %v", creatives)
	}
	if !strings.Contains(filter, `"is_any":["6120","8022"]`) {
		t.Fatalf("catalog product set filter %q", filter)
	}
}

// Don't promote this product again → Playbook Avoid; every waiting proposal and job that used it is rebuilt without
// it; a job left with nothing says so; the next cycle never proposes it; Undo brings everything back.
// MUTATION: skip the waiting-proposal loop in avoidProduct → the avoided product stays on an Approvals card, red.
func TestAvoidFromApprovals(t *testing.T) {
	ac, s := practice(t)
	ad := firstAd(ac, "ar")
	lib := map[string]model.Creative{}
	for _, c := range ac.creatives.all() {
		lib[c.ID] = c
	}
	x := lib[pstr(ad.Params, "creative_id")].ProductIDs[0]
	if !shown(ac)[x] {
		t.Fatalf("%s is not on a card", x)
	}
	y := map[bool]string{true: "4410", false: "6120"}[x == "6120"]
	now := ac.app.now()
	js := ac.jobSet()
	js.Jobs = append(js.Jobs,
		Job{ID: "test-only", Kind: "test", Title: "only x", When: now.Add(time.Hour), Status: "planned", Lang: "ar", Format: "image", ProductIDs: []string{x}, Edited: true, Source: "planner"},
		Job{ID: "test-two", Kind: "test", Title: "x and y", When: now.Add(time.Hour), Status: "planned", Lang: "ar", Format: "carousel", ProductIDs: []string{x, y}, Edited: true, Source: "planner"})
	ac.saveJobs(js)
	code, out := call(t, s, "POST", "/api/products/"+x+"/avoid", ac.id, map[string]any{})
	if code != 200 || out["rebuilt"].(float64) < 1 {
		t.Fatalf("avoid: %d %v", code, out)
	}
	if shown(ac)[x] {
		t.Fatal("the avoided product is still on a waiting proposal")
	}
	for _, it := range ac.Pending().Items {
		if it.Kind == model.KindCreateProductSet && strings.Contains(pstr(it.Params, "filter"), `"`+x+`"`) {
			t.Fatalf("still in the catalog product set: %s", pstr(it.Params, "filter"))
		}
	}
	if got := firstAd(ac, "ar"); got.Status != "shadow" || pstr(got.Params, "creative_id") == pstr(ad.Params, "creative_id") {
		t.Fatalf("the ad was not rebuilt and checked again: %+v", got)
	}
	only, two := ac.jobByID("test-only"), ac.jobByID("test-two")
	if !only.ProductsGone || only.Waiting != msgJobAvoided || len(two.ProductIDs) != 1 || two.ProductIDs[0] != y {
		t.Fatalf("jobs: only %+v / two %v", only, two.ProductIDs)
	}
	if _, why := ac.materialise(context.Background(), ac.input(), *only, ac.env(model.AutopilotShadow)); why != msgJobAvoided {
		t.Fatalf("a job with every product avoided must say so, got %q", why)
	}
	avoided := false
	for _, r := range ac.playbook().Avoid {
		avoided = avoided || (r.Kind == "product" && r.Value == x)
	}
	if !avoided {
		t.Fatal("not in Playbook Avoid")
	}
	// the next cycle: never proposed again
	if _, err := ac.Cycle(context.Background(), model.AutopilotShadow); err != nil {
		t.Fatal(err)
	}
	if shown(ac)[x] {
		t.Fatal("the next cycle proposed the avoided product")
	}
	// undo (one step): Avoid comes off; the plan it rebuilt has been replaced by a cycle, so only the playbook returns
	code, out = call(t, s, "POST", "/api/undo", ac.id, map[string]any{})
	if code != 200 || out["plan_restored"] != false {
		t.Fatalf("undo: %d %v", code, out)
	}
	for _, r := range ac.playbook().Avoid {
		if r.Value == x {
			t.Fatal("undo left it avoided")
		}
	}
	if code, _ := call(t, s, "POST", "/api/undo", ac.id, map[string]any{}); code != 409 {
		t.Fatal("a second undo did something: it is one step")
	}
}

// Undo right after an avoid restores the waiting proposals exactly. MUTATION: drop the savePending in undo → red.
func TestUndoRestoresTheProposals(t *testing.T) {
	ac, s := practice(t)
	before := shown(ac)
	ad := firstAd(ac, "ar")
	x := ""
	for _, c := range ac.creatives.all() {
		if c.ID == pstr(ad.Params, "creative_id") {
			x = c.ProductIDs[0]
		}
	}
	if code, out := call(t, s, "POST", "/api/products/"+x+"/avoid", ac.id, map[string]any{}); code != 200 {
		t.Fatalf("avoid %d %v", code, out)
	}
	if code, out := call(t, s, "POST", "/api/undo", ac.id, map[string]any{}); code != 200 || out["plan_restored"] != true {
		t.Fatalf("undo %d %v", code, out)
	}
	after := shown(ac)
	if len(after) != len(before) || !after[x] || firstAd(ac, "ar").Params["creative_id"] != ad.Params["creative_id"] {
		t.Fatalf("not restored: before %v after %v", before, after)
	}
}

// Pin → the product is ranked first in the picker and the next job without its own products takes it first.
// MUTATION: append the pin at the end of Focus with Pinned ignored in Rank → still first (Rank's +100); drop the
// Focus rule in pinProduct → red.
func TestPinMovesItFirst(t *testing.T) {
	ac, s := practice(t)
	cat := ac.catalog()
	last := ""
	for _, c := range cat {
		if c.Allowed {
			last = c.ID
		}
	}
	if cat[0].ID == last {
		t.Fatal("pick a product that is not first already")
	}
	if code, out := call(t, s, "POST", "/api/products/"+last+"/pin", ac.id, map[string]any{}); code != 200 || out["pinned"] != true {
		t.Fatalf("pin %d %v", code, out)
	}
	if c := ac.catalog(); c[0].ID != last || !c[0].Pinned || c[0].Rank != 1 {
		t.Fatalf("pinned product is #%d %s", c[0].Rank, c[0].ID)
	}
	in := ac.input()
	if ps := ac.nextProducts(in, Job{ID: "t", Kind: "test"}, 1); len(ps) != 1 || ps[0].ID != last {
		t.Fatalf("the next job did not take the pinned product first: %v", ps)
	}
	// an avoided product cannot be pinned, and shows disabled in the picker with why
	if code, _ := call(t, s, "POST", "/api/products/2210/avoid", ac.id, map[string]any{}); code != 200 {
		t.Fatal("avoid")
	}
	if code, _ := call(t, s, "POST", "/api/products/2210/pin", ac.id, map[string]any{}); code != 409 {
		t.Fatal("an avoided product was pinned")
	}
	for _, c := range ac.catalog() {
		if c.ID == "2210" && (c.Allowed || !c.Avoided || c.Reason != "Avoided (Strategy)") {
			t.Fatalf("avoided product in the picker: %+v", c)
		}
	}
}

// With no allowed product left an ad says "choose products" and cannot be approved — never silently dropped; the
// owner's pick brings it back. MUTATION: drop rb.choose(...) in avoidProduct (leave the ad as it was) → red.
func TestNoAllowedProductSaysChoose(t *testing.T) {
	ac, s := practice(t)
	used := map[string]bool{}
	lib := map[string]model.Creative{}
	for _, c := range ac.creatives.all() {
		lib[c.ID] = c
	}
	items := ac.Pending().Items
	for k := range items {
		if items[k].Kind == model.KindCreateAd && waiting(items[k].Status) {
			for _, p := range adProducts(items, k, lib) {
				used[p] = true
			}
		}
	}
	a := ac.app // the shop holds only the products already in ads: no replacement exists
	a.mu.Lock()
	var keep []model.Product
	for _, p := range a.shopSnap.Products {
		if used[p.ID] {
			keep = append(keep, p)
		}
	}
	a.shopSnap.Products = keep
	a.mu.Unlock()
	ad := firstAd(ac, "ar")
	x := lib[pstr(ad.Params, "creative_id")].ProductIDs[0]
	if code, out := call(t, s, "POST", "/api/products/"+x+"/avoid", ac.id, map[string]any{}); code != 200 || out["choose"].(float64) < 1 {
		t.Fatalf("avoid %d %v", code, out)
	}
	var it PendingItem
	for _, p := range ac.Pending().Items {
		if p.ID == ad.ID {
			it = p
		}
	}
	if it.Status != stChoose || it.Note != msgAdAvoided {
		t.Fatalf("the ad disappeared or kept the product: %+v", it)
	}
	if code, out := call(t, s, "POST", "/api/proposals/"+ad.ID+"/approve", ac.id, map[string]any{}); code != 409 || !strings.Contains(out["error"].(string), "choose products") {
		t.Fatalf("approve: %d %v", code, out)
	}
	var other string
	for id := range used {
		if id != x {
			other = id
		}
	}
	if code, out := call(t, s, "POST", "/api/proposals/"+ad.ID+"/products", ac.id, map[string]any{"product_ids": []string{other}}); code != 200 {
		t.Fatalf("choose: %d %v", code, out)
	}
	if got := firstAd(ac, "ar"); got.ID != ad.ID || got.Status != "shadow" {
		t.Fatalf("after choosing: %+v", got)
	}
}
