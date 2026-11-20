package main

// readiness.go — why nothing is waiting (the owner, 20 Nov 2026: after Run a cycle in Practice, Approvals was
// completely empty, twice, and nothing said why). Every reason the first launch or a job can be held is listed here
// in his words with a one-click fix, on Overview and Approvals (state.readiness), and every skip is also in Activity.
// Plus "Auto-link": the account's Page / Instagram / pixel / catalog picked from the discovered portfolio.

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"time"

	"kaa/model"
)

type Blocker struct {
	Key    string `json:"key"`
	What   string `json:"what"`
	WhatAR string `json:"what_ar"`
	Fix    string `json:"fix"` // the app route that fixes it (#/settings …) or an action id (autolink)
	Label  string `json:"label"`
	Step   int    `json:"step"`
}

// readiness: what holds this account back, in order (step 1 first). Caller holds no lock.
func (ac *Acct) readiness() []Blocker {
	a := ac.app
	a.mu.RLock()
	metaOK, claudeOK, products := a.conns.Meta.OK, a.conns.Claude.OK, len(a.shopSnap.Products)
	a.mu.RUnlock()
	ac.mu.RLock()
	st, starter, name := ac.settings, ac.starter, ac.account.Name
	ac.mu.RUnlock()
	if name == "" {
		name = ac.id
	}
	var b []Blocker
	add := func(key, what, ar, fix, label string) {
		b = append(b, Blocker{Key: key, What: what, WhatAR: ar, Fix: fix, Label: label, Step: len(b) + 1})
	}
	if !metaOK {
		add("meta", "Meta is not connected.", "ميتا غير متصلة.", "#/settings", "Settings → Connections")
	}
	if !st.Automated {
		add("automated", name+" is not switched on for the autopilot.", "الحساب "+name+" غير مفعّل للطيار الآلي.", "#/portfolio", "Portfolio → switch it on")
	}
	if st.Links.PageID == "" || st.Links.PixelID == "" {
		var miss []string
		if st.Links.PageID == "" {
			miss = append(miss, "Facebook Page")
		}
		if st.Links.PixelID == "" {
			miss = append(miss, "pixel")
		}
		add("links", "No "+strings.Join(miss, " and no ")+" linked to "+name+": an ad needs a Page, a sales campaign needs the pixel.", "لا توجد صفحة فيسبوك أو بكسل مرتبطان بالحساب "+name+".", "autolink", "Auto-link from this ad account")
	}
	if !st.Limits.Set() {
		add("limits", "Your daily and monthly limits are not set: nothing launches without them.", "حدود الإنفاق اليومية والشهرية غير محددة: لا يُطلق شيء بدونها.", "#/settings", "Settings → Limits")
	}
	if st.Limits.Autopilot == model.AutopilotOff {
		add("autopilot", "The autopilot is off for this account (choose Practice or Auto).", "الطيار الآلي متوقف لهذا الحساب.", "#/settings", "Settings → Limits → Autopilot")
	}
	if products == 0 {
		add("shop", "No products from the shop feed yet.", "لا توجد منتجات من المتجر بعد.", "#/settings", "Settings → Connections → Shop")
	}
	if !claudeOK {
		add("claude", "Claude is not connected: ads use the plain template copy until it is.", "Claude غير متصل: تُستخدم نصوص القالب حتى يتصل.", "#/settings", "Settings → Connections → Claude")
	}
	if starter.State == "skipped" && starter.Why != "" {
		add("starter", "The first launch is waiting: "+starter.Why+".", "الإطلاق الأول ينتظر: "+starter.Why, "#/plan", "Plan")
	}
	js := ac.jobSet()
	for _, j := range js.Jobs {
		if j.Status == "planned" && j.Kind == "occasion" {
			add("occasion-"+j.ID, j.Title+" is ready for your approval.", j.TitleAR+" جاهزة لموافقتك.", "#/approvals", "Approvals → Occasions")
		}
	}
	if b == nil {
		b = []Blocker{}
	}
	return b
}

// autolink: POST /api/accounts/{act}/autolink — fill the missing links from the business that owns the ad account
// (its Page, Instagram account, pixel and catalog; when a business has several, the one whose name matches the ad
// account best). Every link is then read live (checkLinks) and the answer says what was picked and why.
func (s *server) autolink(w http.ResponseWriter, r *http.Request, id string) {
	ac, err := s.app.Acct(id)
	if err != nil {
		jsonErr(w, 404, err.Error())
		return
	}
	a := s.app
	a.mu.RLock()
	p := a.portfolio
	a.mu.RUnlock()
	ac.mu.RLock()
	links, name := ac.settings.Links, ac.account.Name
	ac.mu.RUnlock()
	var biz *model.Business
	for i := range p.Businesses {
		for _, x := range p.Businesses[i].AdAccounts {
			if x == id {
				biz = &p.Businesses[i]
			}
		}
	}
	if biz == nil && len(p.Businesses) == 1 {
		biz = &p.Businesses[0]
	}
	if biz == nil {
		jsonErr(w, 409, "no business in the portfolio owns this ad account: pick the Page and pixel by hand (Settings → Accounts)")
		return
	}
	best := func(xs []model.AssetRef) (model.AssetRef, string) {
		if len(xs) == 0 {
			return model.AssetRef{}, "none in " + biz.Name
		}
		pick, score := xs[0], -1
		for _, x := range xs {
			sc := 0
			for _, w := range strings.Fields(strings.ToLower(name)) {
				if len(w) > 2 && strings.Contains(strings.ToLower(x.Name), w) {
					sc++
				}
			}
			if sc > score {
				pick, score = x, sc
			}
		}
		if len(xs) == 1 {
			return pick, "the only one in " + biz.Name
		}
		return pick, fmt.Sprintf("best name match of %d in %s", len(xs), biz.Name)
	}
	picked := map[string]string{}
	set := func(cur *string, xs []model.AssetRef, key string) {
		if *cur != "" {
			picked[key] = "kept " + *cur
			return
		}
		x, why := best(xs)
		if x.ID != "" {
			*cur = x.ID
			picked[key] = x.Name + " (" + why + ")"
		} else {
			picked[key] = why
		}
	}
	set(&links.PageID, biz.Pages, "page")
	set(&links.InstagramUserID, biz.Instagram, "instagram")
	set(&links.PixelID, biz.Pixels, "pixel")
	set(&links.CatalogID, biz.Catalogs, "catalog")
	ac.mu.Lock()
	ac.settings.Links = links
	ac.stateJSON = nil
	ac.mu.Unlock()
	ac.saveSettings()
	ac.st.Append("config", fmt.Sprintf("auto-linked from %s: %v", biz.Name, picked), links)
	ctx, cancel := context.WithTimeout(r.Context(), time.Minute)
	defer cancel()
	checks := map[string]linkCheck{}
	if a.metaOK() {
		checks = ac.checkLinks(ctx)
	}
	writeJSON(w, map[string]any{"account": id, "links": links, "picked": picked, "link_checks": checks, "readiness": ac.readiness()})
}
