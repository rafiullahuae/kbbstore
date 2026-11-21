package main

// runreport.go — the owner, 22 Nov: "cycle finished, what happend it showed two notices, but i didn't read those"
// / "i need every steps to see what happend etc." Every cycle (and every job run at once: Run it, Approve) leaves a
// report that STAYS: when, how long, and each step in order — reading Meta, the plan, the first launch, every change
// on Meta with its result (or the reason it was refused), the safety net — shown on Overview → Last cycle.

import (
	"fmt"
	"strings"
	"time"

	"kaa/executor"
	"kaa/model"
)

type RunStep struct {
	Title  string `json:"title"`
	Status string `json:"status"` // ok | warn | fail | info
	Detail string `json:"detail,omitempty"`
}

type RunReport struct {
	Title string    `json:"title"`
	At    time.Time `json:"at"`
	Ms    int64     `json:"ms"`
	Mode  string    `json:"mode"`
	Done  int       `json:"done"`
	Bad   int       `json:"bad"`
	Steps []RunStep `json:"steps"`
}

const maxRunReports = 12

var kindLabel = map[string]string{
	model.KindCreateCampaign: "New campaign", model.KindCreateAdSet: "New ad set", model.KindCreateAd: "New ad",
	model.KindUploadCreative: "Picture / video sent to Meta", model.KindPause: "Paused", model.KindResume: "Switched on",
	model.KindSetBudget: "Budget changed", model.KindSetBid: "Bid changed", model.KindArchive: "Archived",
	model.KindCreateAudience: "New audience", model.KindCreateLookalike: "New lookalike audience", model.KindCreateProductSet: "New product set",
}

// resultSteps: one step per change, in the order it ran, with what it was and what Meta said.
func resultSteps(props []model.Proposal, results []executor.Result) []RunStep {
	by := map[string]model.Proposal{}
	for _, p := range props {
		by[p.ID] = p
	}
	var out []RunStep
	for _, r := range results {
		p := by[r.ProposalID]
		what := kindLabel[r.Kind]
		if what == "" {
			what = r.Kind
		}
		name := ""
		if p.Params != nil {
			if n, ok := p.Params["name"].(string); ok {
				name = n
			} else if h, ok := p.Params["headline"].(string); ok {
				name = h
			}
		}
		if name == "" {
			name = r.Target
		}
		if b, ok := p.Params["daily_budget"].(float64); ok && b > 0 {
			name += fmt.Sprintf(" (AED %.0f/day)", b)
		}
		st := RunStep{Title: what + ": " + name}
		switch r.Status {
		case "done":
			st.Status, st.Detail = "ok", strings.Join(r.Steps, " · ")
		case "duplicate":
			st.Status, st.Detail = "info", "already done earlier (not repeated)"
		case "shadow":
			st.Status, st.Detail = "info", "waits for your approval (Practice)"
		case "refused":
			st.Status, st.Detail = "warn", "not done: "+strings.Join(r.Decision.Reasons, "; ")
		default:
			st.Status, st.Detail = "fail", "failed: "+firstNonEmpty(r.Error, strings.Join(r.Decision.Reasons, "; "))
			if h := metaFixHint(st.Detail); h != "" {
				st.Detail += " — FIX: " + h
			}
		}
		if p.Why != "" && len(st.Detail) < 400 {
			st.Detail = strings.TrimSpace(st.Detail + " — why: " + p.Why)
		}
		out = append(out, st)
	}
	return out
}

func (ac *Acct) saveRunReport(r RunReport) {
	var all []RunReport
	_ = ac.st.Load("run_reports", &all)
	all = append([]RunReport{r}, all...)
	if len(all) > maxRunReports {
		all = all[:maxRunReports]
	}
	_ = ac.st.Save("run_reports", all)
	ac.mu.Lock()
	ac.stateJSON = nil
	ac.mu.Unlock()
}

func (ac *Acct) runReports() []RunReport {
	var all []RunReport
	_ = ac.st.Load("run_reports", &all)
	return all
}

// cycleReport: the whole cycle, step by step, from its out map (finishCycle).
func (ac *Acct) cycleReport(t0 time.Time, out map[string]any, props []model.Proposal) RunReport {
	mode, _ := out["mode"].(string)
	r := RunReport{Title: "Cycle", At: t0.UTC(), Mode: mode}
	if ms, ok := out["ms"].(int64); ok {
		r.Ms = ms
	}
	ac.mu.RLock()
	tree := ac.tree
	ours, mine, live := 0, 0, 0
	for _, c := range tree {
		if c.Managed {
			ours++
		} else {
			mine++
		}
	}
	live = executor.ActiveAds(tree)
	stState := ac.starter
	ac.mu.RUnlock()
	if e, ok := out["sync_error"].(string); ok && e != "" {
		r.Steps = append(r.Steps, RunStep{Title: "Read Meta", Status: "fail", Detail: e})
	} else {
		r.Steps = append(r.Steps, RunStep{Title: "Read Meta", Status: "ok", Detail: fmt.Sprintf("%d campaign(s) of yours (not counted in your limits), %d of the autopilot's with %d live ad(s)", mine, ours, live)})
	}
	{ // the Page / Instagram every new ad runs from
		ac.mu.RLock()
		l := ac.settings.Links
		ac.mu.RUnlock()
		how := "the same as your own ads"
		if l.IdentityByHand {
			how = "picked by you"
		}
		st := RunStep{Title: "Page & Instagram", Status: "ok", Detail: fmt.Sprintf("%s · %s (%s)", ac.assetName(l.PageID), ac.assetName(l.InstagramUserID), how)}
		if m, ok := out["identity_changed"].(string); ok && m != "" {
			st.Status, st.Detail = "warn", m
		}
		r.Steps = append(r.Steps, st)
	}
	if n, ok := out["stock_alerts"].(int); ok && n > 0 {
		r.Steps = append(r.Steps, RunStep{Title: "Stock", Status: "warn", Detail: fmt.Sprintf("%d stock alert(s): see Overview alerts (ads are never paused for it)", n)})
	}
	if e, ok := out["error"].(string); ok && e != "" {
		r.Steps = append(r.Steps, RunStep{Title: "Plan", Status: "fail", Detail: "the planner failed, nothing was changed: " + e})
	} else if n, ok := out["proposals"].(int); ok {
		r.Steps = append(r.Steps, RunStep{Title: "Plan", Status: "ok", Detail: fmt.Sprintf("%d change(s) planned inside your limits", n)})
	}
	switch {
	case out["starter"] != nil:
		why := ""
		if m, ok := out["starter"].(map[string]any); ok {
			why, _ = m["why"].(string)
		}
		r.Steps = append(r.Steps, RunStep{Title: "First launch", Status: "ok", Detail: why})
	case stState.State == "skipped" || stState.State == "failed":
		r.Steps = append(r.Steps, RunStep{Title: "First launch", Status: "warn", Detail: stState.Why})
	default:
		if why, _ := out["starter_why"].(string); why != "" {
			st := "info"
			if ac.liveAppAds() == 0 { // nothing of the app's runs: the reason it did not build is the news
				st = "warn"
			}
			r.Steps = append(r.Steps, RunStep{Title: "First launch", Status: st, Detail: "not started: " + why})
		}
	}
	if res, ok := out["results"].([]executor.Result); ok {
		for _, s := range resultSteps(props, res) {
			r.Steps = append(r.Steps, s)
			switch s.Status {
			case "ok":
				r.Done++
			case "warn", "fail":
				r.Bad++
			}
		}
		if len(res) == 0 && out["error"] == nil {
			r.Steps = append(r.Steps, RunStep{Title: "Changes on Meta", Status: "info", Detail: "nothing needed changing this time"})
		}
	}
	// jobs that wait (Run it, occasions, tests): each with its reason
	for _, j := range ac.jobSet().Jobs {
		if open(j.Status) && j.Waiting != "" {
			r.Steps = append(r.Steps, RunStep{Title: "Waiting: " + j.Title, Status: "warn", Detail: j.Waiting})
		}
	}
	if e, ok := out["safety_error"].(string); ok && e != "" {
		r.Steps = append(r.Steps, RunStep{Title: "Meta safety rules", Status: "fail", Detail: e})
	}
	return r
}

func (ac *Acct) liveAppAds() int {
	ac.mu.RLock()
	defer ac.mu.RUnlock()
	return executor.ActiveAds(ac.tree)
}

// metaFixHint: what the owner does about a Meta refusal that only he can fix (his own Meta settings).
func metaFixHint(msg string) string {
	m := strings.ToLower(msg)
	switch {
	case strings.Contains(m, "development mode") || strings.Contains(m, "1885183"):
		return "your Meta app is not published (Development / \"Unpublished\"); Meta creates ads only through a published (Live) app: developers.facebook.com → My Apps → your app → App settings → Basic: Privacy Policy URL, Category and app icon, Save → then Publish (or switch App Mode to Live) at the top of the app's page; then Overview → Run a cycle"
	}
	return ""
}

// alertMetaFixes: one Overview alert per Meta setting the owner must change (not repeated while it stands).
func (ac *Acct) alertMetaFixes(r RunReport) {
	for _, s := range r.Steps {
		if i := strings.Index(s.Detail, " — FIX: "); i >= 0 {
			ac.alertOnce("Meta refused the ads: " + s.Detail[i+len(" — FIX: "):])
			return
		}
	}
}
