package main

// preview.go — the owner, 22 Nov: "for run, i want a popup by seeing the final things which will actually run.
// incase i don't like anything, i can re-generate instantely or remove any product etc."
//
// POST /api/cycle {mode:"preview"}: the whole cycle as it would run — Meta read, plan, first launch, due jobs, every
// executor check — with NOTHING written (shadow), and no record changed (approvals, first-launch state, jobs). It
// answers every ad it would create with its picture, words, product, landing page, ad set, budget and Page.
// POST /api/cycle {mode:"auto", skip_creatives, skip_products}: the run he confirmed, leaving out what he removed.

import (
	"strconv"
	"strings"

	"kaa/executor"
	"kaa/model"
)

type PreviewAd struct {
	ProposalID string   `json:"proposal_id"`
	CreativeID string   `json:"creative_id"` // what "remove" sends back (a library id, or the plan's tmp: id)
	Name       string   `json:"name"`
	Lang       string   `json:"lang"`
	Format     string   `json:"format"`
	Headline   string   `json:"headline"`
	Text       string   `json:"text"`
	Image      string   `json:"image,omitempty"` // /api/creatives/{id}/file?v=… or a shop photo URL
	Media      string   `json:"media,omitempty"`
	Products   []string `json:"products,omitempty"`
	Titles     []string `json:"titles,omitempty"`
	AdSet      string   `json:"adset"`
	Campaign   string   `json:"campaign"`
	BudgetAED  float64  `json:"budget_aed,omitempty"`
	Link       string   `json:"link"`
	Page       string   `json:"page"`
	Instagram  string   `json:"instagram"`
	Check      string   `json:"check"` // ok | waits | refused: what every executor check said
	Why        string   `json:"why,omitempty"`
}

type PreviewOther struct {
	Kind   string `json:"kind"`
	Title  string `json:"title"`
	Check  string `json:"check"`
	Reason string `json:"reason,omitempty"`
}

type Preview struct {
	Ads    []PreviewAd    `json:"ads"`
	Others []PreviewOther `json:"others"`
}

func (ac *Acct) withoutSkipped(s model.ShopSnapshot) model.ShopSnapshot {
	ac.skipMu.Lock()
	skip := ac.skipProd
	ac.skipMu.Unlock()
	if len(skip) == 0 {
		return s
	}
	out := s
	out.Products = nil
	for _, p := range s.Products {
		if !skip[p.ID] {
			out.Products = append(out.Products, p)
		}
	}
	return out
}

// dropSkipped: the ads he removed (and their creative's upload) leave the plan.
func (ac *Acct) dropSkipped(ps []model.Proposal) []model.Proposal {
	ac.skipMu.Lock()
	skip := ac.skipCr
	ac.skipMu.Unlock()
	if len(skip) == 0 {
		return ps
	}
	var out []model.Proposal
	for _, p := range ps {
		if p.Kind == model.KindCreateAd && skip[pstr(p.Params, "creative_id")] {
			continue
		}
		if p.Kind == model.KindUploadCreative && skip[p.Target] {
			continue
		}
		out = append(out, p)
	}
	return out
}

// previewItems: every ad the plan would create, as he will see it; and the other changes, in one line each.
func (ac *Acct) previewItems(props []model.Proposal, results []executor.Result) Preview {
	res := map[string]executor.Result{}
	for _, r := range results {
		res[r.ProposalID] = r
	}
	byTarget := map[string]model.Proposal{}
	for _, p := range props {
		byTarget[p.Target] = p
	}
	ac.mu.RLock()
	links := ac.settings.Links
	tree := ac.tree
	ac.mu.RUnlock()
	setName := func(id string) (string, string, float64) {
		if p, ok := byTarget[id]; ok && p.Kind == model.KindCreateAdSet {
			cname, budget := "", 0.0
			if c, ok := byTarget[pstr(p.Params, "campaign_id")]; ok {
				cname = pstr(c.Params, "name")
				if b, ok := c.Params["daily_budget"].(float64); ok {
					budget = b
				}
			}
			if b, ok := p.Params["daily_budget"].(float64); ok && b > 0 {
				budget = b
			}
			return pstr(p.Params, "name"), cname, budget
		}
		for _, c := range tree {
			for _, s := range c.AdSets {
				if s.ID == id {
					b := c.DailyBudgetAED
					if s.DailyBudgetAED > 0 {
						b = s.DailyBudgetAED
					}
					return s.Name, c.Name, b
				}
			}
		}
		return id, "", 0
	}
	a := ac.app
	a.mu.RLock()
	shop := a.shopSnap
	a.mu.RUnlock()
	title := map[string]string{}
	for _, p := range shop.Products {
		title[p.ID] = p.Title
	}
	check := func(id string) (string, string) {
		r, ok := res[id]
		switch {
		case !ok:
			return "waits", "not run in this pass"
		case r.Status == "shadow" || r.Status == "done" || r.Status == "duplicate":
			return "ok", ""
		default:
			return "refused", firstNonEmpty(r.Error, strings.Join(r.Decision.Reasons, "; "))
		}
	}
	var pv Preview
	pv.Ads, pv.Others = []PreviewAd{}, []PreviewOther{}
	for _, p := range props {
		if p.Kind != model.KindCreateAd {
			if p.Kind == model.KindUploadCreative || p.Kind == model.KindCreateAdSet || p.Kind == model.KindCreateCampaign {
				continue // shown with the ad they serve
			}
			st, why := check(p.ID)
			t := kindLabel[p.Kind]
			if n := pstr(p.Params, "name"); n != "" {
				t += ": " + n
			} else {
				t += ": " + p.Target
			}
			if b, ok := p.Params["daily_budget"].(float64); ok {
				t += " → AED " + trimFloat(b) + "/day"
			}
			pv.Others = append(pv.Others, PreviewOther{Kind: p.Kind, Title: t, Check: st, Reason: firstNonEmpty(why, p.Why)})
			continue
		}
		crID := pstr(p.Params, "creative_id")
		ad := PreviewAd{ProposalID: p.ID, CreativeID: crID, Name: pstr(p.Params, "name"), Why: p.Why}
		ad.AdSet, ad.Campaign, ad.BudgetAED = setName(pstr(p.Params, "adset_id"))
		if c, _, err := ac.creatives.Get(crID); err == nil {
			ad.Lang, ad.Format, ad.Headline, ad.Text, ad.Media, ad.Products = c.Lang, c.Format, c.Headline, c.PrimaryText, c.Media, c.ProductIDs
			switch {
			case c.Media != "":
				ad.Image = "/api/creatives/" + c.ID + "/file?v=" + strconv.Itoa(c.MediaRev)
			case c.ImageURL != "":
				ad.Image = c.ImageURL
			}
			ad.Link = links.Landing(c.Lang, c.Link)
		} else if up, ok := byTarget[crID]; ok { // a carousel / catalog creative the plan makes
			ad.Headline, ad.Text, ad.Format = pstr(up.Params, "headline"), pstr(up.Params, "primary_text"), pstr(up.Params, "format")
			if t, ok := up.Params["template"].(map[string]any); ok {
				ad.Text, ad.Headline = firstNonEmpty(ad.Text, pstr(t, "primary_text")), firstNonEmpty(ad.Headline, pstr(t, "headline"))
			}
			if strings.Contains(ad.Headline, "{{product.name}}") {
				ad.Headline = "Each person sees the product they viewed"
			}
			ad.Lang = model.TextLang(ad.Text) // the executor links it the same way (build.go)
			ad.Link = links.Landing(ad.Lang, "https://kbeautybliss.com/")
		}
		for _, id := range ad.Products {
			ad.Titles = append(ad.Titles, title[id])
		}
		ad.Page, ad.Instagram = ac.assetName(links.PageID), ac.assetName(links.InstagramUserID)
		ad.Check, _ = check(p.ID)
		if st, why := check(p.ID); st == "refused" {
			ad.Why = why
		}
		pv.Ads = append(pv.Ads, ad)
	}
	return pv
}

func trimFloat(f float64) string { return strconv.FormatFloat(f, 'f', -1, 64) }
