package model

import (
	"testing"
	"time"
)

// seasonShop: a small catalogue with winter-type and summer-type products, all in stock with a photo, same age.
func seasonShop() []Product {
	mk := func(id, title, cat, desc string, units int) Product {
		return Product{ID: id, Title: title, Category: cat, PriceAED: 100, InStock: true, Image: "https://kbeautybliss.com/i/" + id + ".webp",
			URL: "https://kbeautybliss.com/p/" + id, Units90: units, Tags: ClassifyProduct(title, cat, desc)}
	}
	return []Product{
		mk("101", "Aestura - Atobarrier 365 Ceramide Cream", "Moisturizer", "rich barrier cream for dry skin", 0),
		mk("102", "Laneige - Lip Sleeping Mask", "Lip Care", "", 0),
		mk("103", "Isntree - Watery Sun Gel SPF50+", "Sunscreen", "lightweight", 0),
		mk("104", "Innisfree - No-Sebum Mineral Powder", "Oil Control", "keeps skin matte", 0),
		mk("105", "Anua - Heartleaf Pore Cleansing Foam", "Cleanser", "", 0),
		mk("106", "Round Lab - Soybean Nourishing Cream", "Moisturizer", "nourishing cream, rich texture", 0),
	}
}

var winter = Occasion{ID: "winter", Name: "Winter (dry-skin season)", Angle: "barrier repair, rich hydration"}
var summer = Occasion{ID: "summer", Name: "Summer skin", Angle: "lightweight, oil control, sunscreen textures"}

func pb0() Playbook { p := DefaultPlaybook(); p.FreshnessPct = 0; return p.Normalize() }

func posOf(ps []SlotPick, id string) int {
	for i, p := range ps {
		if p.ID == id {
			return i
		}
	}
	return -1
}

// The owner's Winter package held one eye cream. Winter must rank the hydration / barrier creams and the lip mask
// above the SPF and the oil-control powder; Summer the other way round.
// MUTATION: zero the "winter" profile (seasonProfile["winter"] = {}) → every fit is flat and the ids decide, red.
func TestWinterRanksBarrierCreamsAboveSPF(t *testing.T) {
	sig := RankSignals{Now: time.Date(2026, 11, 21, 9, 0, 0, 0, time.UTC)}
	w := RankForOccasion(seasonShop(), pb0(), winter, sig)
	for _, good := range []string{"101", "102", "106"} {
		for _, bad := range []string{"103", "104"} {
			if posOf(w, good) > posOf(w, bad) {
				t.Errorf("winter: %s (#%d) below %s (#%d): %+v", good, posOf(w, good), bad, posOf(w, bad), w)
			}
		}
	}
	if w[0].Reason == "" || w[0].ReasonAR == "" {
		t.Fatalf("no fit reason: %+v", w[0])
	}
	s := RankForOccasion(seasonShop(), pb0(), summer, sig)
	if posOf(s, "103") > 1 || posOf(s, "101") < posOf(s, "104") || posOf(s, "106") < posOf(s, "103") {
		t.Fatalf("summer: %+v", s)
	}
}

// Equal fit: the best seller first, a zero-sales twin after it. MUTATION: drop salesN from the score → the tie goes
// to the higher id (106), red.
func TestBestSellerOutranksZeroSalesWithEqualFit(t *testing.T) {
	ps := seasonShop()
	ps[5].Tags = ps[0].Tags // 106 = exactly 101's fit
	ps[0].Units90 = 30
	w := RankForOccasion(ps, pb0(), winter, RankSignals{Now: time.Now()})
	if posOf(w, "101") > posOf(w, "106") || w[posOf(w, "101")].Units90 != 30 {
		t.Fatalf("%+v", w)
	}
}

// His word wins: a product he says is for summer is not a winter pick, and a pinned one is always first.
// MUTATION: ignore ProductSeasons in RankForOccasion → the cream stays in the winter top 3, red.
func TestOwnerSeasonsAndPinWin(t *testing.T) {
	pb := pb0()
	pb.ProductSeasons = map[string][]string{"101": {"summer"}}
	pb.Focus = []PBRule{{Kind: "product", Value: "104"}}
	pb = pb.Normalize()
	w := RankForOccasion(seasonShop(), pb, winter, RankSignals{Now: time.Now()})
	if w[0].ID != "104" || !w[0].Pinned {
		t.Fatalf("pinned not first: %+v", w[0])
	}
	if posOf(w, "101") < 3 || !w[posOf(w, "101")].Yours {
		t.Fatalf("his summer cream in the winter top: %+v", w)
	}
	if pb.Slots() != 5 || (Playbook{OccasionSlots: 9}).Slots() != 5 || (Playbook{OccasionSlots: 3}).Slots() != 3 {
		t.Fatal("slots: default 5, 3..8")
	}
}

func TestClassifyArabicAndExactWords(t *testing.T) {
	if g := ClassifyProduct("كريم مرطب غني للبشرة الجافة", "", ""); !has(g, "cream") || !has(g, "hydration") || !has(g, "rich") {
		t.Fatalf("arabic: %v", g)
	}
	if g := ClassifyProduct("Sunflower Oil Settings Spray", "", ""); has(g, "spf") || has(g, "set") {
		t.Fatalf("sun/set are whole words: %v", g)
	}
	if OccasionSeason(Occasion{ID: "white-friday"}) != "sale" || OccasionSeason(Occasion{ID: "eid-al-fitr"}) != "gifting" || OccasionSeason(Occasion{ID: "my-own", Name: "Winter glow"}) != "winter" {
		t.Fatal("occasion seasons")
	}
}
