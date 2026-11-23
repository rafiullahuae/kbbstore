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
	"strings"
	"sync"
	"time"

	"kaa/model"
)

var assetNames sync.Map // id → name read from Meta (a Page the portfolio does not list)

type pageRoleOK struct {
	page string
	at   time.Time
}

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
	if v, ok := ac.app.roleOK.Load(ac.id); ok {
		if c := v.(pageRoleOK); c.page == page && ac.app.now().Sub(c.at) < 6*time.Hour {
			return false, nil
		}
	}
	seen, listed, tasks := 0, false, []string(nil) // what me/accounts showed, for the message
	has := func() (bool, bool) {                   // (may advertise, check made)
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
		seen = len(r.Data)
		for _, p := range r.Data {
			if p.ID != page {
				continue
			}
			listed, tasks = true, p.Tasks
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
		ac.app.roleOK.Store(ac.id, pageRoleOK{page, ac.app.now()})
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
	// who the app is connected as, and what Meta showed it: so the fix is read, not guessed
	var me struct {
		Name string `json:"name"`
	}
	_ = mc.Get(ctx, "me", url.Values{"fields": {"name"}}, &me)
	ac.app.mu.RLock()
	kind := ac.app.cfg.TokenType
	ac.app.mu.RUnlock()
	who := firstNonEmpty(me.Name, "unknown")
	if kind != "" {
		who += " (" + strings.ToLower(strings.ReplaceAll(kind, "_", " ")) + " token)"
	}
	what := fmt.Sprintf("Meta shows this login %d Page(s), and %s is not one of them", seen, name)
	if listed {
		what = fmt.Sprintf("Meta shows this login %s, but only with %s (no Advertise)", name, strings.Join(tasks, ", "))
	}
	fix := "connect with YOUR OWN Facebook login, which already advertises for " + name + ": Settings → Connections → Meta → Connect with \"My own Facebook login (user token)\" + the App ID and App secret of the same app"
	if strings.EqualFold(kind, "USER") {
		fix = "make a new token in Graph API Explorer (Get User Access Token) and, in the Facebook window that opens, choose \"Edit settings\" / \"Opt in to current Pages only\" and TICK " + name + " (and its Instagram and the KBB23 ad account); permissions ads_management, ads_read, business_management, pages_show_list, pages_read_engagement, pages_manage_ads. Paste it in Settings → Connections → Meta → Save & test"
	}
	return false, errNoPageRole{Msg: fmt.Sprintf("The app cannot advertise for your Page %s yet, so nothing was sent (no campaign, no ad). Connected as: %s. %s. FIX, once, without touching the Page or its portfolio: %s. Then press Run a cycle: the app checks it again before anything goes.", name, who, what, fix)}
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
