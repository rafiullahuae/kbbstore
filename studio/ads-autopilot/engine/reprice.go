package main

// Correct the price on every ad (the owner, 21 Nov): the designs showed the regular price where the shop runs a
// sale. The designs stay exactly as they are; only the price chip changes.
//
//	POST /api/reprice              every kept design gets today's selling price; returns the plan: each library
//	                               creative to redraw (its corrected design), kept until it is redrawn
//	GET  /api/reprice              the plan as it stands
//	PUT  /api/reprice/{creative}   {png | video}: the redrawn picture / video goes back under the SAME library id
//	                               (occasion packages, queued ads and "Run it" keep pointing at it). If a live ad
//	                               shows the old one, a "reprice" job swaps it: the old ad paused, the same creative
//	                               with the right price uploaded and put live in the same ad set.
//	POST /api/reprice/{creative}/skip   leave that one as it is (drop it from the plan)

import (
	"fmt"
	"math"
	"net/http"
	"regexp"
	"strings"
	"time"

	"kaa/brain"
	"kaa/model"
)

type repricePlan struct {
	At    string              `json:"at"`
	Items []brain.RepriceItem `json:"items"`
	Done  []string            `json:"done,omitempty"`  // creative ids redrawn
	Swaps []string            `json:"swaps,omitempty"` // reprice job ids queued for live ads
	// the words: creatives whose written price was corrected (all time), and this call's live-ad swaps
	TextFixed int      `json:"text_fixed,omitempty"`
	TextSwaps []string `json:"text_swaps,omitempty"`
	TextNow   int      `json:"text_now,omitempty"`
}

func (ac *Acct) repricePlan() repricePlan {
	var p repricePlan
	_ = ac.st.Load("reprice_plan", &p)
	return p
}

func (s *server) repriceAPI(w http.ResponseWriter, r *http.Request, ac *Acct, p string) {
	a := s.app
	a.mu.RLock()
	eb, ok := a.brain.(*brain.Engine)
	a.mu.RUnlock()
	ac.repriceMu.Lock()
	defer ac.repriceMu.Unlock()
	plan := ac.repricePlan()
	switch {
	case p == "/api/reprice" && r.Method == http.MethodGet:
		writeJSON(w, plan)
	case p == "/api/reprice" && r.Method == http.MethodPost:
		if !ok {
			jsonErr(w, 501, "this brain keeps no designs")
			return
		}
		in := ac.input()
		if len(in.Shop.Products) == 0 {
			jsonErr(w, 409, "the shop's products are not loaded yet (Overview → Sync, then try again)")
			return
		}
		// the words first (fixCopyPrices), then the pictures: always recomputed from what each one shows against
		// today's price (never an older list)
		if n, sw := ac.fixCopyPrices(in.Shop.Products); n > 0 {
			plan.TextFixed += n
			plan.Swaps = append(plan.Swaps, sw...)
			plan.TextSwaps, plan.TextNow = sw, n
		} else {
			plan.TextSwaps, plan.TextNow = nil, 0
		}
		plan.Items = eb.Reprice(ac.id, in.Shop, ac.creatives.all())
		if plan.Items == nil {
			plan.Items = []brain.RepriceItem{}
		}
		if len(plan.Done) > 500 {
			plan.Done = plan.Done[len(plan.Done)-500:]
		}
		if len(plan.Swaps) > 500 {
			plan.Swaps = plan.Swaps[len(plan.Swaps)-500:]
		}
		plan.At = a.now().UTC().Format(time.RFC3339)
		_ = ac.st.Save("reprice_plan", plan)
		ac.st.Append("config", fmt.Sprintf("prices corrected to the shop's selling price: %d ad picture(s)/video(s) to redraw", len(plan.Items)), nil)
		writeJSON(w, plan)
	case strings.HasPrefix(p, "/api/reprice/") && (r.Method == http.MethodPut || r.Method == http.MethodPost):
		rest := strings.TrimPrefix(p, "/api/reprice/")
		skip := strings.HasSuffix(rest, "/skip")
		id := strings.TrimSuffix(rest, "/skip")
		k := -1
		for i, it := range plan.Items {
			if it.CreativeID == id {
				k = i
			}
		}
		if k < 0 {
			jsonErr(w, 404, "that creative is not waiting for a price correction")
			return
		}
		if skip {
			plan.Items = append(plan.Items[:k], plan.Items[k+1:]...)
			_ = ac.st.Save("reprice_plan", plan)
			writeJSON(w, plan)
			return
		}
		var in struct {
			PNG    string `json:"png"`
			Video  string `json:"video"`
			Layout int    `json:"layout"`
		}
		if err := readJSON(r, &in, 96<<20); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		it := plan.Items[k]
		shows := it.NewPrice
		if shows == "" {
			shows = brain.PriceNone
		}
		if in.Layout < brain.LayoutNoRepeats { // the app that drew it draws each photo once from 0.6.2 on
			in.Layout = brain.LayoutNoRepeats
		}
		cr, old, err := ac.creatives.ReplaceMedia(id, in.PNG, in.Video, shows, in.Layout, a.now())
		if err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		plan.Items = append(plan.Items[:k], plan.Items[k+1:]...)
		plan.Done = append(plan.Done, id)
		var swaps []string
		if old != "" {
			swaps = ac.queueRepriceSwaps(cr, old)
			plan.Swaps = append(plan.Swaps, swaps...)
		}
		_ = ac.st.Save("reprice_plan", plan)
		ac.st.Append("config", fmt.Sprintf("creative %s redrawn: price %s → %s (%d live ad(s) to swap)", id, it.OldPrice, it.NewPrice, len(swaps)), map[string]any{"creative": id, "swaps": swaps})
		ac.dirty()
		writeJSON(w, map[string]any{"ok": true, "left": len(plan.Items), "swaps": swaps})
	default:
		jsonErr(w, 404, "GET/POST /api/reprice, PUT /api/reprice/{creative_id}, POST /api/reprice/{creative_id}/skip")
	}
}

// queueRepriceSwaps: every running ad that shows the old Meta creative gets a "reprice" job (jobs.go materialise):
// pause it, upload the corrected creative, put a new ad with it live in the same ad set. Approved: the owner asked.
func (ac *Acct) queueRepriceSwaps(cr model.Creative, oldMeta string) []string {
	in := ac.input()
	now := ac.app.now()
	js := ac.jobSet()
	var ids []string
	for _, c := range in.Tree {
		for _, set := range c.AdSets {
			for _, ad := range set.Ads {
				if ad.CreativeID != oldMeta || ad.Status != "ACTIVE" {
					continue
				}
				id := fmt.Sprintf("reprice-%s-r%d", jobPID.ReplaceAllString(ad.ID, "-"), cr.MediaRev)
				j := Job{ID: id, Kind: "reprice", Title: "Correct the price on a live ad", TitleAR: "تصحيح السعر في إعلان يعمل",
					Why:  "You asked for the sale price on every ad: " + ad.Name + " showed the old price. The same design with the right price replaces it in the same ad set.",
					When: now, Status: "approved", Target: ad.ID, Creatives: []string{cr.ID}, Lang: cr.Lang, Source: "owner", ProductIDs: cr.ProductIDs, UpdatedAt: now.UTC()}
				j.logf(now, "queued: %s shows the old price", ad.Name)
				upsert(&js, j, now)
				ids = append(ids, id)
			}
		}
	}
	if len(ids) > 0 {
		ac.saveJobs(js)
	}
	return ids
}

// repriceProposals: the swap for one live ad (job kind "reprice"). The pause goes first, so the new ad never
// counts against Max active ads on top of the one it replaces. done: the ad is no longer running, nothing to do.
func (ac *Acct) repriceProposals(in model.CycleInput, j Job, pid func(string) string) (ps []model.Proposal, why string, done bool) {
	var old *model.Ad
	var set *model.AdSet
	for ci := range in.Tree {
		for si := range in.Tree[ci].AdSets {
			for ai := range in.Tree[ci].AdSets[si].Ads {
				if in.Tree[ci].AdSets[si].Ads[ai].ID == j.Target {
					old, set = &in.Tree[ci].AdSets[si].Ads[ai], &in.Tree[ci].AdSets[si]
				}
			}
		}
	}
	if old == nil || (old.Status != "ACTIVE" && !j.Swapped) { // stopped by the owner or the autopilot: not revived
		return nil, "", true
	}
	if len(j.Creatives) == 0 {
		return nil, "no corrected creative on this job", false
	}
	cid := j.Creatives[0]
	c, _, err := ac.creatives.Get(cid)
	if err != nil {
		return nil, err.Error(), false
	}
	up := model.Proposal{ID: pid("cr"), Kind: model.KindUploadCreative, Target: cid,
		Params: map[string]any{"creative_id": cid, "format": "image", "headline": c.Headline, "primary_text": c.PrimaryText}, Why: j.Why, Confidence: 0.8}
	if c.Media == "mp4" { // a video needs its cover: the 9:16 still of the same design
		up.Params["format"] = "video"
		for _, x := range ac.creatives.all() {
			if x.Media == "png" && x.Format == "9:16" && x.Lang == c.Lang && x.Headline == c.Headline {
				up.Params["thumbnail_creative_id"] = x.ID
				break
			}
		}
		if up.Params["thumbnail_creative_id"] == nil {
			return nil, "the video's 9:16 picture (its cover) is not in the library", false
		}
	}
	if j.Swapped { // paused by this job on an earlier run: the replacement goes live unless it already is
		meta := c.MetaID
		for _, a := range set.Ads {
			if meta != "" && a.CreativeID == meta && a.Status == "ACTIVE" {
				return nil, "", true
			}
		}
	} else {
		ps = append(ps, model.Proposal{ID: pid("pause"), Kind: model.KindPause, Target: old.ID, Params: map[string]any{}, Why: "Replaced by the same ad with the corrected (sale) price.", Confidence: 0.9})
	}
	ps = append(ps, up,
		model.Proposal{ID: pid("ad"), Kind: model.KindCreateAd, Target: "tmp:" + pid("new"), Params: map[string]any{"name": old.Name, "adset_id": set.ID, "creative_id": cid}, Why: j.Why, Confidence: 0.8})
	return ps, "", false
}

// repricePauseID: the proposal id of a reprice job's pause (materialise's pid("pause")).
func repricePauseID(jobID string) string {
	return "job-" + jobPID.ReplaceAllString(jobID, "-") + "-pause"
}

// creativeMediaAPI: PUT /api/creatives/{id}/media {png | video, price, layout} — the owner's "Redraw & save"
// (Create → an idea or the whole set): the same design drawn again goes back under the SAME library id, so every
// package and queue keeps pointing at it; a live ad showing the old picture is swapped (queueRepriceSwaps). An
// identical picture changes nothing, on disk or on Meta.
func (s *server) creativeMediaAPI(w http.ResponseWriter, r *http.Request, ac *Acct, id string) {
	var in struct {
		PNG    string `json:"png"`
		Video  string `json:"video"`
		Price  string `json:"price"`
		Layout int    `json:"layout"`
	}
	if err := readJSON(r, &in, 96<<20); err != nil {
		jsonErr(w, 400, err.Error())
		return
	}
	if in.Price == "" || len(in.Price) > 40 {
		in.Price = brain.PriceNone
	}
	if in.Layout < brain.LayoutNoRepeats {
		in.Layout = brain.LayoutNoRepeats
	}
	ac.repriceMu.Lock()
	defer ac.repriceMu.Unlock()
	cr, old, err := ac.creatives.ReplaceMedia(id, in.PNG, in.Video, in.Price, in.Layout, s.app.now())
	if err != nil {
		jsonErr(w, 400, err.Error())
		return
	}
	var swaps []string
	if old != "" {
		swaps = ac.queueRepriceSwaps(cr, old)
	}
	ac.st.Append("config", fmt.Sprintf("creative %s redrawn by you (%d live ad(s) to swap)", id, len(swaps)), map[string]any{"creative": id, "swaps": swaps})
	ac.dirty()
	writeJSON(w, map[string]any{"ok": true, "changed": cr.MediaRev, "swaps": swaps})
}

// ── prices written in the WORDS of an ad (the owner's Approvals, 21 Nov: Dr.Melaxin AED 108 in the shop, "AED 138"
// in its occasion ad). Pictures are redrawn by the app; words are corrected here: a price next to AED / درهم that
// equals the product's REGULAR price, while the shop sells it for less, becomes the selling price. Any other
// number (a size, "pay in 4", a different product's price) is left alone.

var arDigits = []rune("٠١٢٣٤٥٦٧٨٩")

func toArabicDigits(s string) string {
	out := []rune{}
	for _, r := range s {
		if r >= '0' && r <= '9' {
			r = arDigits[r-'0']
		}
		out = append(out, r)
	}
	return string(out)
}

func priceNum(v float64) string {
	if math.Abs(v-math.Round(v)) < 0.005 {
		return fmt.Sprintf("%.0f", v)
	}
	return fmt.Sprintf("%.2f", v)
}

// fixTextPrice: s with every "AED <reg>", "<reg> AED", "<reg> درهم" (Western or Arabic digits, ".00" allowed)
// replaced by the selling price, in the same style.
func fixTextPrice(s string, reg, sell float64) string {
	if reg <= 0 || sell <= 0 || sell >= reg {
		return s
	}
	r, n := priceNum(reg), priceNum(sell)
	for _, pair := range [][2]string{{r, n}, {toArabicDigits(r), toArabicDigits(n)}} {
		num := regexp.QuoteMeta(pair[0])
		re := regexp.MustCompile(`((?:AED|aed|Aed)\s?)` + num + `((?:\.00|٫٠٠)?)\b|` + `(^|[^0-9٠-٩.,])` + num + `((?:\.00|٫٠٠)?\s?(?:AED|درهم|درهماً|د\.إ))`)
		s = re.ReplaceAllStringFunc(s, func(m string) string {
			sm := re.FindStringSubmatch(m)
			if sm[1] != "" {
				return sm[1] + pair[1]
			}
			return sm[3] + pair[1] + strings.TrimPrefix(strings.TrimPrefix(sm[4], ".00"), "٫٠٠")
		})
	}
	return s
}

// fixCopyPrices: every library creative's words get today's selling price (fixTextPrice); a creative already on
// Meta is made again and its live ads swapped. Returns how many were corrected.
func (ac *Acct) fixCopyPrices(products []model.Product) (int, []string) {
	reg, sell := map[string]float64{}, map[string]float64{}
	for _, p := range products {
		reg[p.ID], sell[p.ID] = p.PriceAED, p.Selling()
	}
	n := 0
	var swaps []string
	for _, c := range ac.creatives.all() {
		if len(c.ProductIDs) == 0 {
			continue
		}
		id := c.ProductIDs[0]
		h, t := fixTextPrice(c.Headline, reg[id], sell[id]), fixTextPrice(c.PrimaryText, reg[id], sell[id])
		if h == c.Headline && t == c.PrimaryText {
			continue
		}
		cr, old, err := ac.creatives.SetCopy(c.ID, h, t)
		if err != nil {
			continue
		}
		n++
		if old != "" {
			swaps = append(swaps, ac.queueRepriceSwaps(cr, old)...)
		}
		ac.st.Append("config", fmt.Sprintf("creative %s: the price in its words corrected to AED %s", c.ID, priceNum(sell[id])), map[string]any{"creative": c.ID})
	}
	return n, swaps
}
