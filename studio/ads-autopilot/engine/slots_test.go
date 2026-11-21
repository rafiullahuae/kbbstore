package main

// Occasion product slots (the owner, 21 Nov 2026, looking at a Winter package with ONE eye cream in it: "always give
// me 5 products from the most selling / latest ones, and if i remove any product, it should come in next suggested
// products. once i press Find Products button it should perform the new list and fill the missing products").
// Each test says the defect it catches and the mutation that turns it red.

import (
	"context"
	"testing"
	"time"

	"kaa/model"
)

// winterApp: the mock shop WITH its fake shop API (units sold per product), the clock on 1 December: the Winter
// package is prepared by the warm-up's Refresh.
func winterApp(t *testing.T) (*Acct, *server, Job) {
	t.Helper()
	t.Setenv("KAA_MOCK_SHOP_API", "1")
	ac, _ := testApp(t)
	ac.app.brain = quietBrain{}
	d := time.Date(ac.app.now().Year(), 12, 1, 9, 0, 0, 0, time.UTC)
	ac.app.now = func() time.Time { return d }
	ac.app.mu.Lock()
	ac.app.shopSnap.FetchedAt = d // fresh: Find products uses it as it is
	ac.app.mu.Unlock()
	ac.Refresh(context.Background())
	j := winterJob(ac)
	if j.ID == "" {
		t.Fatalf("no winter package: %+v", ac.jobSet().Jobs)
	}
	return ac, testServer(ac), j
}

func winterJob(ac *Acct) Job {
	for _, j := range ac.jobSet().Jobs {
		if j.Kind == "occasion" && j.Occasion != nil && j.Occasion.Occasion == "winter" {
			return j
		}
	}
	return Job{}
}

func idsOf(x any) []string {
	var out []string
	for _, v := range x.([]any) {
		out = append(out, v.(string))
	}
	return out
}

// Five products, the winter ones: the best-selling barrier cream and lip mask are in, the best-selling SUN GEL and
// the oil-control powder are not; every product has its Arabic and English ad. Before: one eye cream.
// MUTATION: occasionProducts back to `pb.Slots()` → 3, or to model.Rank (no season fit) → the sun gel (30 sold) is
// in the package, red.
func TestWinterPackageHasFiveWinterBestSellers(t *testing.T) {
	ac, _, j := winterApp(t)
	if len(j.ProductIDs) != 5 || len(j.Creatives) != 10 || j.Slots != 5 {
		t.Fatalf("package: %d products %v, %d ads, slots %d", len(j.ProductIDs), j.ProductIDs, len(j.Creatives), j.Slots)
	}
	for _, id := range []string{"8130", "8150"} {
		if !contains(j.ProductIDs, id) {
			t.Errorf("winter best seller %s missing: %v", id, j.ProductIDs)
		}
	}
	for _, id := range []string{"8170", "8178", "4410"} {
		if contains(j.ProductIDs, id) {
			t.Errorf("summer product %s in the winter package: %v", id, j.ProductIDs)
		}
	}
	picks := ac.slotPicks(ac.input(), j, nil)
	if len(picks) != 5 || picks[0].Reason == "" || picks[0].ReasonAR == "" || picks[0].Units90 == 0 {
		t.Fatalf("chips: %+v", picks)
	}
}

// ✕ on a chip: out of this package, the next suggestion in its slot (with its two ads), and it never comes back to
// THIS package — not on Find products either. It is NOT avoided anywhere else.
// MUTATION: drop `j.NotFor = append(...)` in slotAction → Find products brings it straight back, red.
func TestRemoveFillsTheSlotAndNeverReturns(t *testing.T) {
	ac, s, j := winterApp(t)
	gone := j.ProductIDs[0]
	code, out := call(t, s, "POST", "/api/jobs/"+j.ID+"/remove-product", ac.id, map[string]any{"product_id": gone})
	if code != 200 || len(idsOf(out["added"])) != 1 {
		t.Fatalf("remove: %d %v", code, out)
	}
	j2 := winterJob(ac)
	if len(j2.ProductIDs) != 5 || contains(j2.ProductIDs, gone) || !contains(j2.NotFor, gone) || len(j2.Creatives) != 10 {
		t.Fatalf("after remove: %v not_for %v, %d ads", j2.ProductIDs, j2.NotFor, len(j2.Creatives))
	}
	if code, out := call(t, s, "POST", "/api/jobs/"+j.ID+"/find-products", ac.id, map[string]any{}); code != 200 || len(idsOf(out["added"])) != 0 {
		t.Fatalf("find: %d %v", code, out)
	}
	if contains(winterJob(ac).ProductIDs, gone) {
		t.Fatal("a product he removed came back to the package")
	}
	for _, r := range ac.playbook().Avoid {
		if r.Value == gone {
			t.Fatal("✕ became a global Avoid")
		}
	}
}

// Find products: the empty slots refill to 5, an out-of-stock product is replaced, a pinned one stays (first).
// MUTATION: keep out-of-stock products in refill (drop the usable(p) test on his products) → the sold-out cream stays,
// red; fill only to len(ProductIDs) → 2, red.
func TestFindProductsRefillsReplacesAndKeepsPinned(t *testing.T) {
	ac, s, j := winterApp(t)
	pin, sold := j.ProductIDs[1], j.ProductIDs[0]
	if code, _ := call(t, s, "POST", "/api/products/"+pin+"/pin", ac.id, map[string]any{}); code != 200 {
		t.Fatal("pin")
	}
	js := ac.jobSet()
	for i := range js.Jobs {
		if js.Jobs[i].ID == j.ID {
			js.Jobs[i].ProductIDs = []string{sold, pin}
		}
	}
	ac.saveJobs(js)
	ac.app.mu.Lock()
	for i := range ac.app.shopSnap.Products {
		if ac.app.shopSnap.Products[i].ID == sold {
			ac.app.shopSnap.Products[i].InStock = false
		}
	}
	ac.app.mu.Unlock()
	code, out := call(t, s, "POST", "/api/jobs/"+j.ID+"/find-products", ac.id, map[string]any{})
	j2 := winterJob(ac)
	if code != 200 || len(j2.ProductIDs) != 5 || contains(j2.ProductIDs, sold) || j2.ProductIDs[0] != pin || len(idsOf(out["added"])) != 4 || !contains(idsOf(out["removed"]), sold) {
		t.Fatalf("find: %d %v → %v", code, out, j2.ProductIDs)
	}
}

// ⊘ Never promote on a package chip: avoided everywhere, its slot refilled at once, and it never appears again.
// MUTATION: drop the refill call for occasion jobs in avoidProduct → 4 products, red.
func TestAvoidedNeverAppearsAndItsSlotRefills(t *testing.T) {
	ac, s, j := winterApp(t)
	bad := j.ProductIDs[2]
	if code, out := call(t, s, "POST", "/api/products/"+bad+"/avoid", ac.id, map[string]any{}); code != 200 {
		t.Fatalf("avoid %d %v", code, out)
	}
	j2 := winterJob(ac)
	if len(j2.ProductIDs) != 5 || contains(j2.ProductIDs, bad) {
		t.Fatalf("after avoid: %v", j2.ProductIDs)
	}
	call(t, s, "POST", "/api/jobs/"+j.ID+"/find-products", ac.id, map[string]any{})
	for _, r := range model.RankForOccasion(ac.input().Shop.Products, ac.playbook(), occasionOf(ac.playbook(), j2), model.RankSignals{Now: ac.app.now()}) {
		if r.ID == bad {
			t.Fatal("an avoided product is still ranked")
		}
	}
	if contains(winterJob(ac).ProductIDs, bad) {
		t.Fatal("an avoided product came back")
	}
}
