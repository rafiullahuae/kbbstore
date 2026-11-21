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
	"net/http"
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
		// always recomputed from what each picture shows against today's price (never an older list)
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
