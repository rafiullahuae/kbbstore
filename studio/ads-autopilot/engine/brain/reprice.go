package brain

// Reprice: the owner, 21 Nov — "the system is picking the actual price, not the sale price ... the designs are
// absolutely stunning, i don't want to lose them, just the corrections i need in the price" and, after 0.6.1,
// "i pressed the correct prices button, but still i can see the wrong prices ... i want a permanent fix".
//
// Two halves, both permanent:
//   - RefreshPrices: every design the app keeps (Create with Claude sessions, the cycle's archive, the designs still
//     to draw) carries the price the shop charges TODAY (Product.Selling). It runs whenever designs are served, so
//     a preview, a redraw, a save or a render job never sees an old price, and again when a sale starts or ends.
//   - Reprice: each library creative is compared with ITS design by what is printed on it (Creative.Price, recorded
//     at every save and redraw), not by whether a design happened to change in the same call. 0.6.1 looked only
//     for designs it changed in that one call and matched creatives by headline: a creative whose design had been
//     corrected earlier, or whose headline was edited after saving, was never listed. NOTHING else in a design
//     changes; the app redraws each listed picture / video and puts it back under the same library id.

import (
	"strings"

	"kaa/model"
)

type RepriceItem struct {
	CreativeID string     `json:"creative_id"`
	Format     string     `json:"format"` // 1:1 | 4:5 | 9:16 | mp4 (the 9:16 motion version)
	VideoS     int        `json:"video_s,omitempty"`
	OldPrice   string     `json:"old_price"` // what the picture shows ("" = not known)
	NewPrice   string     `json:"new_price"` // what it must show ("" = no price on this design)
	Repeats    bool       `json:"repeats,omitempty"`
	Session    string     `json:"session,omitempty"`
	Design     DesignSpec `json:"design"`
	Skip       string     `json:"skip,omitempty"` // why it cannot be redrawn here (the owner's own photo stays in his browser)
}

// LayoutNoRepeats: the designer version that draws each product photo once (render.js imgs / routine).
const LayoutNoRepeats = 2

// PriceNone: Creative.Price of a picture that shows no price.
const PriceNone = "none"

// repeats: the old designer drew the same photo more than once — the same image listed twice, or a routine with
// fewer than two different photos (one column per bullet, each the same photo; the owner's Anua set, 21 Nov).
func repeats(d DesignSpec) bool {
	seen := map[string]bool{}
	for _, u := range d.Images {
		if seen[u] {
			return true
		}
		seen[u] = true
	}
	return d.Template == "routine" && len(seen) < 2
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

type shopPrices struct{ sell, regular map[string]float64 }

func pricesOf(shop model.ShopSnapshot) shopPrices {
	p := shopPrices{sell: map[string]float64{}, regular: map[string]float64{}}
	for _, x := range shop.Products {
		p.sell[x.ID], p.regular[x.ID] = x.Selling(), x.PriceAED
	}
	return p
}

// RefreshPrices puts today's selling price on every kept design (sessions, archive, the designs still to draw)
// and saves what changed. Idempotent and cheap; the engine calls it before designs are served or redrawn.
func (e *Engine) RefreshPrices(acct string, shop model.ShopSnapshot) {
	if len(shop.Products) == 0 { // no feed yet: never blank or guess a price
		return
	}
	sell := pricesOf(shop).sell
	e.mu.Lock()
	defer e.mu.Unlock()
	e.refreshLocked(acct, sell)
}

func (e *Engine) refreshLocked(acct string, sell map[string]float64) (ss []CreateSession, arch []DesignSpec) {
	ss = e.sessions(acct)
	ch := false
	for si := range ss {
		for i := range ss[si].Designs {
			if _, ok := fixPrice(&ss[si].Designs[i], sell); ok {
				ch = true
			}
		}
	}
	if ch {
		e.save(acct, "create_sessions", ss)
	}
	arch = []DesignSpec{}
	e.load(acct, "designs_archive", &arch)
	ch = false
	for i := range arch {
		if _, ok := fixPrice(&arch[i], sell); ok {
			ch = true
		}
	}
	if ch {
		e.save(acct, "designs_archive", arch)
	}
	var last CycleOutput
	if e.load(acct, "last_cycle", &last) {
		ch = false
		for i := range last.Designs {
			if _, ok := fixPrice(&last.Designs[i], sell); ok {
				ch = true
			}
		}
		if ch {
			e.save(acct, "last_cycle", last)
		}
	}
	return ss, arch
}

// alnumTail: the last n letters / digits of s (draw.js creativeID names a saved picture by its design's id tail).
func alnumTail(s string, n int) string {
	var b []rune
	for _, r := range s {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			b = append(b, r)
		}
	}
	if len(b) > n {
		b = b[len(b)-n:]
	}
	return string(b)
}

// ccTail: the design tail inside a box / Claude-app creative id "cc-<8 hex><tail>-<1x1|4x5|9x16|mp4>".
func ccTail(id string) string {
	if !strings.HasPrefix(id, "cc-") || len(id) < 12 {
		return ""
	}
	k := strings.LastIndex(id, "-")
	if k <= 11 {
		return ""
	}
	return id[11:k]
}

// matchDesign: the design a library creative was drawn from. A box / Claude-app picture names its design in its id
// (the same in every later version of the session, whatever was edited); otherwise the same language and headline
// (the creative's headline IS the design's), and a format the design has (a video is its motion version).
func matchDesign(c model.Creative, ds []DesignSpec, needProducts bool) int {
	if t := ccTail(c.ID); t != "" {
		for i, d := range ds {
			if d.Lang == c.Lang && alnumTail(d.ID, 6) == t {
				return i
			}
		}
	}
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

func sameIDs(a, b []string) bool {
	if len(a) == 0 || len(b) == 0 {
		return false
	}
	return a[0] == b[0]
}

// printedWrong: does the picture show a price other than its design's (today's)? Known when the save recorded it
// (Creative.Price). A picture from before prices were recorded: redrawn by 0.6.1 (RepricedAt) shows that day's
// selling price; anything older was drawn with the REGULAR price (the bug), so it is wrong whenever the product
// is on sale now.
func printedWrong(c model.Creative, d DesignSpec, pr shopPrices) (shows string, wrong bool) {
	want := d.Price
	if want == "" {
		want = PriceNone
	}
	switch {
	case c.Price != "":
		return c.Price, c.Price != want
	case d.Price == "":
		return "", false
	case c.RepricedAt != "":
		return "", false
	}
	if len(d.ProductIDs) == 0 {
		return "", false
	}
	reg := pr.regular[d.ProductIDs[0]]
	if reg <= 0 {
		return "", false
	}
	shows = PriceLabel(d.Lang, reg)
	return shows, shows != d.Price
}

// Reprice refreshes every design's price, then lists every library creative whose picture shows a price other
// than today's, or a repeated product photo, with the design to redraw it from.
func (e *Engine) Reprice(acct string, shop model.ShopSnapshot, lib []model.Creative) []RepriceItem {
	if len(shop.Products) == 0 {
		return nil
	}
	pr := pricesOf(shop)
	format := func(c model.Creative) string {
		if c.Media == "mp4" {
			return "mp4"
		}
		return c.Format
	}
	byID := map[string]model.Creative{}
	for _, c := range lib {
		byID[c.ID] = c
	}
	e.mu.Lock()
	ss, arch := e.refreshLocked(acct, pr.sell)
	e.mu.Unlock()

	var out []RepriceItem
	seen := map[string]bool{}
	add := func(c model.Creative, d DesignSpec, sess string) {
		seen[c.ID] = true
		shows, wrong := printedWrong(c, d, pr)
		rep := repeats(d) && c.Layout < LayoutNoRepeats
		if !wrong && !rep {
			return
		}
		d.Headline = firstNonEmptyS(c.Headline, d.Headline) // the picture keeps the words it was saved with
		it := RepriceItem{CreativeID: c.ID, Format: format(c), OldPrice: shows, NewPrice: d.Price, Repeats: rep, Session: sess, Design: d}
		if it.Format == "mp4" {
			it.VideoS = 6
			if strings.HasPrefix(c.ID, "cl-") || c.Source == "brain" { // the cloud worker's and Creatives → Motion's run 10 s
				it.VideoS = 10
			}
		}
		if d.Photo {
			it.Skip = "made with your own photo, which stays in the browser it was attached in: open the set in Create, attach the photo again and save"
		}
		out = append(out, it)
	}
	// 1. Create with Claude sessions (the box, the Claude app, the cloud): their saved / run creatives
	for _, s := range ss {
		for _, cid := range append(append([]string{}, s.Saved...), s.Ran...) {
			c, ok := byID[cid]
			if !ok || seen[cid] || c.Media == "" {
				continue
			}
			if i := matchDesign(c, s.Designs, false); i >= 0 {
				add(c, s.Designs[i], s.ID)
			}
		}
	}
	// 2. any other drawn creative: the cycle's designs (Creatives → Images / Motion), by language, headline, product
	for _, c := range lib {
		if seen[c.ID] || c.Media == "" {
			continue
		}
		if i := matchDesign(c, arch, true); i >= 0 {
			add(c, arch[i], "")
		}
	}
	return out
}

func firstNonEmptyS(a, b string) string {
	if strings.TrimSpace(a) != "" {
		return a
	}
	return b
}
