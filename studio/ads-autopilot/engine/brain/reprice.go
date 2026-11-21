package brain

// Reprice: the owner, 21 Nov — "the system is picking the actual price, not the sale price ... the designs are
// absolutely stunning, i don't want to lose them, just the corrections i need in the price."
//
// Every design the app keeps (Create with Claude sessions, the cycle's archive, the designs still to draw) gets the
// price chip the shop charges today (Product.Selling). NOTHING else in a design changes: the same template,
// photo, palette, words and layout; the app redraws each saved picture / video from it and puts it back under the
// same library id (engine/reprice.go). Reprice returns, for each library creative that shows a stale price, the
// corrected design to draw it from.

import (
	"strings"

	"kaa/model"
)

type RepriceItem struct {
	CreativeID string     `json:"creative_id"`
	Format     string     `json:"format"` // 1:1 | 4:5 | 9:16 | mp4 (the 9:16 motion version)
	VideoS     int        `json:"video_s,omitempty"`
	OldPrice   string     `json:"old_price"`
	NewPrice   string     `json:"new_price"`
	Session    string     `json:"session,omitempty"`
	Design     DesignSpec `json:"design"`
	Skip       string     `json:"skip,omitempty"` // why it cannot be redrawn here (the owner's own photo stays in his browser)
}

// fixPrice sets the design's price chip to today's selling price. A design that shows no price is left alone.
func fixPrice(d *DesignSpec, sell map[string]float64) (old string, changed bool) {
	if d.Price == "" || len(d.ProductIDs) == 0 {
		return "", false
	}
	v, ok := sell[d.ProductIDs[0]]
	if !ok || v <= 0 {
		return "", false
	}
	nl := PriceLabel(d.Lang, v)
	if nl == d.Price {
		return "", false
	}
	old, d.Price = d.Price, nl
	return old, true
}

func sameIDs(a, b []string) bool {
	if len(a) == 0 || len(b) == 0 {
		return false
	}
	return a[0] == b[0]
}

// match: the design a library creative was drawn from — same language and headline (the creative's headline IS the
// design's), and a format the design has (a video is its motion version).
func matchDesign(c model.Creative, ds []DesignSpec, needProducts bool) int {
	for i, d := range ds {
		if d.Lang != c.Lang || strings.TrimSpace(d.Headline) != strings.TrimSpace(c.Headline) {
			continue
		}
		if needProducts && !sameIDs(d.ProductIDs, c.ProductIDs) {
			continue
		}
		if c.Media == "mp4" {
			return i
		}
		for _, f := range d.Formats {
			if f == c.Format {
				return i
			}
		}
		if len(d.Formats) == 0 {
			return i
		}
	}
	return -1
}

// Reprice corrects the price on every kept design and returns the library creatives to redraw. Idempotent: a
// second call finds nothing (the designs already carry today's price); the caller keeps the list until each is
// redrawn.
func (e *Engine) Reprice(acct string, shop model.ShopSnapshot, lib []model.Creative) []RepriceItem {
	sell := map[string]float64{}
	for _, p := range shop.Products {
		sell[p.ID] = p.Selling()
	}
	byID := map[string]model.Creative{}
	for _, c := range lib {
		byID[c.ID] = c
	}
	format := func(c model.Creative) string {
		if c.Media == "mp4" {
			return "mp4"
		}
		return c.Format
	}
	var out []RepriceItem
	seen := map[string]bool{}

	e.mu.Lock()
	defer e.mu.Unlock()

	// 1. Create with Claude sessions (the box, the Claude app, the cloud): their saved / run creatives
	ss := e.sessions(acct)
	sessChanged := false
	for si := range ss {
		s := &ss[si]
		olds := map[int]string{}
		for i := range s.Designs {
			if old, ok := fixPrice(&s.Designs[i], sell); ok {
				olds[i], sessChanged = old, true
			}
		}
		if len(olds) == 0 {
			continue
		}
		for _, cid := range append(append([]string{}, s.Saved...), s.Ran...) {
			c, ok := byID[cid]
			if !ok || seen[cid] || c.Media == "" {
				continue
			}
			i := matchDesign(c, s.Designs, false)
			if i < 0 {
				continue
			}
			old, ok := olds[i]
			if !ok {
				continue
			}
			seen[cid] = true
			it := RepriceItem{CreativeID: cid, Format: format(c), OldPrice: old, NewPrice: s.Designs[i].Price, Session: s.ID, Design: s.Designs[i]}
			if it.Format == "mp4" {
				it.VideoS = 6
				if strings.HasPrefix(cid, "cl-") { // the cloud worker's motion ads run 10 s
					it.VideoS = 10
				}
			}
			if s.Designs[i].Photo {
				it.Skip = "made with your own photo, which stays in the browser it was attached in: open the set in Create, attach the photo again and save"
			}
			out = append(out, it)
		}
	}
	if sessChanged {
		e.save(acct, "create_sessions", ss)
	}

	// 2. the cycle's designs (Creatives → Images, drawn automatically): matched by language, headline and product
	arch := []DesignSpec{}
	e.load(acct, "designs_archive", &arch)
	olds := map[int]string{}
	for i := range arch {
		if old, ok := fixPrice(&arch[i], sell); ok {
			olds[i] = old
		}
	}
	if len(olds) > 0 {
		for _, c := range lib {
			if seen[c.ID] || c.Source != "brain" || c.Media == "" {
				continue
			}
			i := matchDesign(c, arch, true)
			if old, ok := olds[i]; ok && i >= 0 {
				seen[c.ID] = true
				it := RepriceItem{CreativeID: c.ID, Format: format(c), OldPrice: old, NewPrice: arch[i].Price, Design: arch[i]}
				if it.Format == "mp4" {
					it.VideoS = 10
				}
				out = append(out, it)
			}
		}
		e.save(acct, "designs_archive", arch)
	}

	// 3. designs still waiting to be drawn: drawn with today's price
	var last CycleOutput
	if e.load(acct, "last_cycle", &last) {
		ch := false
		for i := range last.Designs {
			if _, ok := fixPrice(&last.Designs[i], sell); ok {
				ch = true
			}
		}
		if ch {
			e.save(acct, "last_cycle", last)
		}
	}
	return out
}
