package main

// pagerole.go — the owner, 22 Nov: his ads failed with "Meta error 10/3858749: Application does not have permission
// for this action (To create posts for Page 297852810082118, contact an admin to get permission for Advertiser role
// or higher)". Every ad Meta builds is a post of the Page, so the token the app uses needs the Page's ADVERTISE task.
//
// Before anything is sent, the app now checks that, for the Page its ads run from (identity.go):
// The app NEVER assigns the Page or changes its roles (the owner: "i don't want to assign my pages, bcz it's already
// in another business portfolio, and i don't to disturb those"). The fix is connecting with his own login.
//   1. me/accounts lists the Page with ADVERTISE (or MANAGE)  → go.
//   2. it is missing → NOTHING is sent (no empty campaign, no half launch); the run says exactly what to switch on.
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
	return false, errNoPageRole{Msg: fmt.Sprintf("The app's Meta login cannot advertise for your Page %s (it sits in another business portfolio), so nothing was sent (no campaign, no ad). "+
		"FIX, once, without touching the Page or its portfolio: connect the app with YOUR OWN Facebook login, which already advertises for %s — Settings → Connections → Meta → way \"My user token\" (docs/META-SETUP.md Path B; permissions ads_management, ads_read, business_management, pages_show_list, pages_read_engagement, pages_manage_ads). "+
		"Then press Run a cycle: the app checks it again before anything goes.", name, name)}
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
