package main

// identity.go — the owner, 22 Nov: "all the ads ran from wrong page. i have multiple pages in the account, all the
// ads must run from K-Beauty Bliss page and also from kbeauty.bliss instagram. like my ads."
//
// The auto-link picked the Page whose NAME best matched the ad account's name ("KBB23" matched none, so the first
// Page of the business won). Now the autopilot takes its Facebook Page and Instagram account from the owner's OWN
// running ads in the same ad account (the identity most of them use), at every cycle, and its ads always run
// "like my ads". A Page / Instagram he picks by hand (Settings → Accounts → Link by hand) is kept and never changed.
// When the identity changes, every Meta creative the app made with the old one is dropped from the library (the
// pictures stay), so nothing is ever reused with the wrong Page.

import (
	"context"
	"encoding/json"
	"fmt"
	"net/url"
	"sort"
	"strings"

	"kaa/model"
)

type ownIdentity struct {
	PageID, InstagramID string
	Ads                 int // how many of his running ads use that Page
}

// ownAdsIdentity: the Page (and Instagram) most of the owner's own running ads use. Reads at most 25 creatives.
func (ac *Acct) ownAdsIdentity(ctx context.Context) (ownIdentity, error) {
	mc := ac.app.meta
	if mc == nil {
		return ownIdentity{}, nil
	}
	ac.mu.RLock()
	var ids []string
	seen := map[string]bool{}
	for _, c := range ac.tree {
		if c.Managed {
			continue
		}
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				if a.CreativeID != "" && !seen[a.CreativeID] && (a.Status == "ACTIVE" || len(ids) < 5) {
					seen[a.CreativeID] = true
					ids = append(ids, a.CreativeID)
				}
			}
		}
	}
	ac.mu.RUnlock()
	if len(ids) > 25 {
		ids = ids[:25]
	}
	pages, igs := map[string]int{}, map[string]map[string]int{}
	for _, id := range ids {
		var cr struct {
			ActorID       string          `json:"actor_id"`
			ObjectStoryID string          `json:"object_story_id"`
			Spec          json.RawMessage `json:"object_story_spec"`
			IG            string          `json:"instagram_user_id"`
		}
		if err := mc.Get(ctx, id, url.Values{"fields": {"actor_id,object_story_id,object_story_spec,instagram_user_id"}}, &cr); err != nil {
			continue
		}
		var spec struct {
			PageID string `json:"page_id"`
			IG     string `json:"instagram_user_id"`
		}
		if json.Unmarshal(cr.Spec, &spec) != nil { // a string in some answers
			var s string
			if json.Unmarshal(cr.Spec, &s) == nil {
				_ = json.Unmarshal([]byte(s), &spec)
			}
		}
		page := firstNonEmpty(spec.PageID, cr.ActorID)
		if page == "" && strings.Contains(cr.ObjectStoryID, "_") {
			page = strings.SplitN(cr.ObjectStoryID, "_", 2)[0]
		}
		if page == "" {
			continue
		}
		pages[page]++
		if ig := firstNonEmpty(spec.IG, cr.IG); ig != "" {
			if igs[page] == nil {
				igs[page] = map[string]int{}
			}
			igs[page][ig]++
		}
	}
	best := ownIdentity{}
	keys := make([]string, 0, len(pages))
	for k := range pages {
		keys = append(keys, k)
	}
	sort.Strings(keys) // a tie is decided the same way every time
	for _, p := range keys {
		if pages[p] > best.Ads {
			best = ownIdentity{PageID: p, Ads: pages[p]}
		}
	}
	n := 0
	for ig, c := range igs[best.PageID] {
		if c > n || (c == n && ig < best.InstagramID) {
			best.InstagramID, n = ig, c
		}
	}
	return best, nil
}

// syncIdentity: the autopilot's Page / Instagram follow the owner's own ads (unless he linked them by hand).
// Returns what changed ("" = nothing).
func (ac *Acct) syncIdentity(ctx context.Context) string {
	ac.mu.RLock()
	links := ac.settings.Links
	ac.mu.RUnlock()
	if links.IdentityByHand {
		return ""
	}
	id, err := ac.ownAdsIdentity(ctx)
	if err != nil || id.PageID == "" {
		return ""
	}
	if id.PageID == links.PageID && (id.InstagramID == "" || id.InstagramID == links.InstagramUserID) {
		return ""
	}
	was := links.PageID + " / " + links.InstagramUserID
	links.PageID = id.PageID
	if id.InstagramID != "" {
		links.InstagramUserID = id.InstagramID
	}
	ac.mu.Lock()
	ac.settings.Links = links
	ac.stateJSON = nil
	ac.mu.Unlock()
	ac.saveSettings()
	n := ac.creatives.ResetMeta()
	msg := fmt.Sprintf("The autopilot's ads now run from the same Facebook Page and Instagram as your own ads (%s / %s, used by %d of your ads; was %s). %d ad(s) made with the old Page will be made again with this one.",
		ac.assetName(id.PageID), ac.assetName(links.InstagramUserID), id.Ads, was, n)
	ac.st.Append("config", msg, links)
	ac.alertOnce(msg)
	return msg
}

// assetName: a Page / Instagram id's name from the portfolio (the id when unknown).
func (ac *Acct) assetName(id string) string {
	if id == "" {
		return "—"
	}
	if v, ok := assetNames.Load(id); ok { // read from Meta (pagerole.go)
		return v.(string)
	}
	ac.app.mu.RLock()
	p := ac.app.portfolio
	ac.app.mu.RUnlock()
	for _, b := range p.Businesses {
		for _, xs := range [][]model.AssetRef{b.Pages, b.Instagram} {
			for _, x := range xs {
				if x.ID == id && x.Name != "" {
					return x.Name
				}
			}
		}
	}
	return id
}

// identityLine: "K-Beauty Bliss · kbeauty.bliss" — names, not ids.
func (ac *Acct) identityLine() string {
	ac.mu.RLock()
	l := ac.settings.Links
	ac.mu.RUnlock()
	return ac.assetName(l.PageID) + " · " + ac.assetName(l.InstagramUserID)
}
