package main

// runcampaign.go — each run its own campaign. The owner, 26 Nov: "if new campaigns / routine run, it should be as
// seperate compaign, not inside the previous campaign. I mean each set should have it's own main campaign."
//
// Until 0.6.23 every Run it, planned test / seasonal push, occasion package and funnel
// audience job put its ads into the FIRST campaign's prospecting ad set (prospectingSet, jobs.go), so on Meta one
// campaign kept growing and he could not tell one run from another. Now each of those jobs builds a new campaign
// of its own — "KAA: Run · <what> · 26 Nov 2026 14:05", Advantage+ sales, lowest cost with NO bid cap (as before),
// one ad set per language it needs — and its ads go only there. The old way is still one tick away
// (Strategy → Audience → "Put new ads into the main campaign instead"), and the new campaign's daily budget is his
// (Strategy → Audience → "Budget of each new campaign", 0 = automatic inside his limits).

import (
	"fmt"
	"math"
	"strings"

	"kaa/executor"
	"kaa/model"
)

// runCampaignMark: in the name of every campaign built for one run. findFunnel keeps them out of the funnel's
// main campaign, so "the prospecting campaign" never becomes the newest run by accident (Meta lists newest first).
const runCampaignMark = "Run · "

// minRunAED: the smallest daily budget a run's campaign is built with (below it Meta's learning starves).
const minRunAED = 10.0

// defaultRunAED: a run's campaign budget when neither the job nor his setting names one.
const defaultRunAED = 30.0

func isRunCampaign(name string) bool { return strings.Contains(name, runCampaignMark) }

// ownCampaign: true when this job's ads go into a new campaign of their own (the default since 0.6.23). A "set"
// is what he runs or approves as one thing: Run it, a planned test or seasonal push, an occasion package, a funnel
// audience. The autopilot's own one-ad drip and its replacements for tiring ads (batch / reword / refresh) stay in
// the campaign they feed: one campaign per single ad would starve every one of them of learning budget.
func ownCampaign(pb *model.Playbook, kind string) bool {
	if pb != nil && pb.ShareCampaign {
		return false
	}
	switch kind {
	case "owner_run", "occasion", "test", "seasonal", "funnel_existing", "funnel_lookalike":
		return true
	}
	return false
}

// runCampaignName: "Run · <what> · 26 Nov 2026 14:05" (the app adds "KAA:" in front, as on every campaign it makes).
func runCampaignName(title string, at string) string {
	t := strings.TrimSpace(title)
	if r := []rune(t); len(r) > 60 {
		t = strings.TrimSpace(string(r[:59])) + "…"
	}
	if t == "" {
		t = "Autopilot"
	}
	return runCampaignMark + t + " · " + at
}

// runBudget: the new campaign's daily budget: the job's own, else his setting, else AED 30 — never more than the
// room left under his daily limit (with the campaigns earlier jobs of this pass build counted).
func (ac *Acct) runBudget(in model.CycleInput, j Job, env executor.Env) (float64, string) {
	pb := *in.Playbook
	free := math.Max(0, env.Limits.DailyAED-executor.CommittedDaily(env.Tree)-env.CommitDelta)
	b := j.BudgetAED
	if b <= 0 {
		b = pb.RunCampaignAED
	}
	if b <= 0 {
		b = defaultRunAED
	}
	b = math.Min(b, free)
	b = math.Floor(b*100) / 100
	if b < minRunAED {
		return 0, fmt.Sprintf("a new campaign needs at least AED %.0f/day and your daily limit has AED %.0f free (Settings → Limits → Daily, or pause a campaign)", minRunAED, free)
	}
	return b, ""
}

// runCampaign: the proposals that build one run's campaign and an ad set for each language in langs.
// sets maps a language to its ad set's tmp id. extra: params every ad set of it carries (a funnel job's audience).
func (ac *Acct) runCampaign(in model.CycleInput, j Job, env executor.Env, pid func(string) string, langs []string, budget float64, extra map[string]any) (ps []model.Proposal, sets map[string]string) {
	pb := *in.Playbook
	tag := "j" + fmt.Sprintf("%x", hash32(j.ID))[:6]
	camp := "tmp:" + tag + "-rc"
	at := env.Now.In(ac.loc()).Format("2 Jan 2006 15:04")
	countries := funnelCountries(pb, env.Limits.Countries)
	cc := strings.Join(anyToStrings(countries), "+")
	f := findFunnel(in.Tree, in.Audiences)
	ps = append(ps, model.Proposal{ID: pid("runcamp"), Kind: model.KindCreateCampaign, Target: camp,
		Params: map[string]any{"name": runCampaignName(j.Title, at), "daily_budget": budget, "type": "sales", "bid_strategy": "LOWEST_COST_WITHOUT_CAP"},
		Why:    fmt.Sprintf("Its own campaign for this run (AED %.0f/day, lowest cost, no bid cap): nothing goes into an earlier campaign.", budget), Confidence: 0.7})
	sets = map[string]string{}
	seen := map[string]bool{}
	for _, l := range langs {
		l = strings.ToLower(strings.TrimSpace(l))
		if l != "ar" && l != "en" {
			l = "ar"
		}
		if seen[l] {
			continue
		}
		seen[l] = true
		pr := map[string]any{"name": fmt.Sprintf("Prospecting %s · new customers · %s", strings.ToUpper(l), cc), "campaign_id": camp,
			"countries": countries, "destination": "website", "advantage_audience": 1.0, "age_range": []any{float64(pb.AgeMin), float64(pb.AgeMax)}}
		if pb.Gender == "women" {
			pr["genders"] = []any{2.0}
		}
		if pb.ExcludeBuyersDays > 0 && f.buyers != nil {
			pr["excluded_custom_audiences"] = []any{f.buyers.ID}
		}
		for k, v := range extra { // a funnel audience job's own audience (existing customers / lookalikes); nil = leave out
			if v == nil {
				delete(pr, k)
			} else {
				pr[k] = v
			}
		}
		sets[l] = "tmp:" + tag + "-rs-" + l
		ps = append(ps, model.Proposal{ID: pid("runset-" + l), Kind: model.KindCreateAdSet, Target: sets[l], Params: pr, Why: j.Why, Confidence: 0.7})
	}
	return ps, sets
}

// budgetOf: the daily budget of the campaigns a job's proposals create (dueJobs reserves it for the next job).
func budgetOf(ps []model.Proposal) float64 {
	b := 0.0
	for _, p := range ps {
		if p.Kind == model.KindCreateCampaign {
			if v, ok := p.Params["daily_budget"].(float64); ok {
				b += v
			}
		}
	}
	return b
}
