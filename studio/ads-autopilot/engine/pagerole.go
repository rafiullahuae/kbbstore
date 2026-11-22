package main

// pagerole.go — the owner, 22 Nov: his ads failed with "Meta error 10/3858749: Application does not have permission
// for this action (To create posts for Page 297852810082118, contact an admin to get permission for Advertiser role
// or higher)". Every ad Meta builds is a post of the Page, so the token the app uses needs the Page's ADVERTISE task.
//
// Before anything is sent, the app now checks that, for the Page its ads run from (identity.go):
//   1. me/accounts lists the Page with ADVERTISE (or MANAGE)  → go.
//   2. it is missing → the app gives itself the role: POST /{page}/assigned_users (user = this token's user,
//      tasks ADVERTISE + ANALYZE + CREATE_CONTENT, business = the business that holds the Page) and checks again.
//   3. still missing → NOTHING is sent (no empty campaign, no half launch); the run says exactly what to switch on.
// A check that cannot be made (Meta did not answer) does not block: the executor still reports Meta's own refusal.

import (
	"context"
	"fmt"
	"net/url"
	"sync"
	"time"

	"kaa/model"
)

var assetNames sync.Map // id → name read from Meta (a Page the portfolio does not list)

type pageRoleOK struct {
	page string
	at   time.Time
}

var pageRoleCache sync.Map // app+account → pageRoleOK (a passed check is trusted for 6 hours)

func (ac *Acct) roleKey() string { return fmt.Sprintf("%p|%s", ac.app, ac.id) }

// errNoPageRole: the Page refuses this app's ads; Msg says what to switch on.
type errNoPageRole struct{ Msg string }

func (e errNoPageRole) Error() string { return e.Msg }

// pageRole: nil = the app may advertise for its Page (or the check could not be made); errNoPageRole = it may not,
// even after trying to give itself the role. fixed = it gave itself the role just now.
func (ac *Acct) pageRole(ctx context.Context) (fixed bool, err error) {
	mc := ac.app.meta
	if mc == nil {
		return false, nil
	}
	ac.mu.RLock()
	page := ac.settings.Links.PageID
	ac.mu.RUnlock()
	if page == "" {
		return false, nil
	}
	if v, ok := pageRoleCache.Load(ac.roleKey()); ok {
		if c := v.(pageRoleOK); c.page == page && ac.app.now().Sub(c.at) < 6*time.Hour {
			return false, nil
		}
	}
	has := func() (bool, bool) { // (may advertise, check made)
		var r struct {
			Data []struct {
				ID    string   `json:"id"`
				Name  string   `json:"name"`
				Tasks []string `json:"tasks"`
			} `json:"data"`
		}
		if err := mc.Get(ctx, "me/accounts", url.Values{"fields": {"id,name,tasks"}, "limit": {"500"}}, &r); err != nil {
			return false, false
		}
		for _, p := range r.Data {
			if p.ID != page {
				continue
			}
			if p.Name != "" {
				assetNames.Store(p.ID, p.Name)
			}
			for _, t := range p.Tasks {
				if t == "ADVERTISE" || t == "MANAGE" {
					return true, true
				}
			}
		}
		return false, true
	}
	ok, made := has()
	if !made {
		return false, nil
	}
	if ok {
		pageRoleCache.Store(ac.roleKey(), pageRoleOK{page, ac.app.now()})
		return false, nil
	}
	name := ac.assetName(page)
	if name == page {
		var p struct {
			Name string `json:"name"`
		}
		if mc.Get(ctx, page, url.Values{"fields": {"name"}}, &p) == nil && p.Name != "" {
			assetNames.Store(page, p.Name)
			name = p.Name
		}
	}
	// 2. give itself the role, through the business that holds the Page (then every business it knows)
	var me struct {
		ID string `json:"id"`
	}
	var tried []string
	var lastErr error
	if mc.Get(ctx, "me", url.Values{"fields": {"id"}}, &me) == nil && me.ID != "" {
		for _, biz := range ac.pageBusinesses(page) {
			form := url.Values{"user": {me.ID}, "tasks": {`["ADVERTISE","ANALYZE","CREATE_CONTENT"]`}, "business": {biz}}
			var out map[string]any
			tried = append(tried, biz)
			if lastErr = mc.Post(ctx, page+"/assigned_users", form, "", &out); lastErr == nil {
				if ok, _ := has(); ok {
					pageRoleCache.Store(ac.roleKey(), pageRoleOK{page, ac.app.now()})
					msg := fmt.Sprintf("The app gave itself permission to advertise for your Page %s (Meta had refused its ads: no Advertiser role). Nothing for you to do.", name)
					ac.st.Append("config", msg, map[string]string{"page": page, "business": biz})
					return true, nil
				}
			}
		}
	}
	why := ""
	if lastErr != nil {
		why = " (Meta refused the app giving it to itself: " + lastErr.Error() + ")"
	}
	return false, errNoPageRole{Msg: fmt.Sprintf("Meta does not let the app advertise for your Page %s yet, so nothing was sent (no campaign, no ad)%s. "+
		"FIX, once: business.facebook.com → Settings → Users → System users (or People, if you connected with your own Facebook login) → the user the app uses → Assign assets → Pages → %s → switch on \"Ads\" (Create ads) or Full control → Save. "+
		"Then press Run a cycle: the app checks it again before anything goes.", name, why, name)}
}

// pageBusinesses: the business that lists the Page first, then every other business the token sees.
func (ac *Acct) pageBusinesses(page string) []string {
	ac.app.mu.RLock()
	p := ac.app.portfolio
	ac.app.mu.RUnlock()
	var first, rest []string
	for _, b := range p.Businesses {
		holds := false
		for _, x := range b.Pages {
			if x.ID == page {
				holds = true
			}
		}
		if holds {
			first = append(first, b.ID)
		} else {
			rest = append(rest, b.ID)
		}
	}
	return append(first, rest...)
}

// needsPage: proposals that make Page posts (an ad's creative) — what a missing Page role blocks.
func needsPage(ps []model.Proposal) bool {
	for _, p := range ps {
		switch p.Kind {
		case model.KindUploadCreative, model.KindCreateAd, model.KindCreateCampaign, model.KindCreateAdSet:
			return true
		}
	}
	return false
}

// withoutNew: everything but the new campaigns / ad sets / creatives / ads (pauses, budgets, archives still run).
func withoutNew(ps []model.Proposal) (keep []model.Proposal, held int) {
	for _, p := range ps {
		switch p.Kind {
		case model.KindUploadCreative, model.KindCreateAd, model.KindCreateCampaign, model.KindCreateAdSet:
			held++
			continue
		}
		keep = append(keep, p)
	}
	return keep, held
}
