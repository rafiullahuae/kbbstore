package main

// live.go — the owner, 22 Nov: "where i can see what's running currently, i need the live real time progress with
// proper list." Every cycle (hourly, warm-up, his Run a cycle, its preview) and every job run at once (Run it,
// Approve) keeps a LIVE list of its steps — reading Meta, the Page check, planning, the first launch, and each
// campaign / ad set / picture / ad as it is sent, with ✓, ✕ and the reason — pushed to the app the moment each
// changes (SSE "live") and in the state (Overview → Running now; the top bar's "● Running").

import (
	"fmt"
	"sync"
	"time"

	"kaa/executor"
	"kaa/model"
)

type LiveRun struct {
	Running bool      `json:"running"`
	Kind    string    `json:"kind"` // "Cycle (every hour)" | "Your Run a cycle" | "Preview" | a job's title
	Started time.Time `json:"started"`
	Ended   time.Time `json:"ended,omitempty"`
	Now     string    `json:"now"` // what it is doing this moment
	Steps   []RunStep `json:"steps"`
	ids     map[string]int
}

type liveBox struct {
	mu    sync.Mutex
	run   LiveRun
	props map[string]model.Proposal // the proposals of this run, by id (for the launched log)
}

// Launched: one thing the app really made on Meta (the owner, 23 Nov: Plan → "Already launched (which actually run
// in meta)", date-wise). Written when Meta answered with the new object's id; previews never write here.
type Launched struct {
	At       time.Time `json:"at"`
	Kind     string    `json:"kind"` // create_campaign | create_adset | create_ad
	Title    string    `json:"title"`
	MetaID   string    `json:"meta_id"`
	Creative string    `json:"creative_id,omitempty"`
	Run      string    `json:"run"` // what it ran in: a cycle, a Run it job
}

const maxLaunched = 1000

func (ac *Acct) noteLaunched(l Launched) {
	ac.launchMu.Lock()
	defer ac.launchMu.Unlock()
	var all []Launched
	_ = ac.st.Load("launched", &all)
	all = append(all, l)
	if len(all) > maxLaunched {
		all = all[len(all)-maxLaunched:]
	}
	_ = ac.st.Save("launched", all)
}

func (ac *Acct) launched() []Launched {
	ac.launchMu.Lock()
	defer ac.launchMu.Unlock()
	var all []Launched
	_ = ac.st.Load("launched", &all)
	return all
}

func (ac *Acct) liveSnap() LiveRun {
	ac.live.mu.Lock()
	defer ac.live.mu.Unlock()
	r := ac.live.run
	r.Steps = append([]RunStep(nil), r.Steps...)
	return r
}

func (ac *Acct) livePush() {
	r := ac.liveSnap()
	ac.publish("live", r)
	ac.mu.Lock()
	ac.stateJSON = nil
	ac.mu.Unlock()
}

// liveStart: a new run; the previous one's list is replaced.
func (ac *Acct) liveStart(kind string) {
	ac.live.mu.Lock()
	ac.live.run = LiveRun{Running: true, Kind: kind, Started: ac.app.now().UTC(), Now: "starting", ids: map[string]int{}}
	ac.live.mu.Unlock()
	ac.livePush()
}

func (ac *Acct) liveEnd() {
	ac.live.mu.Lock()
	if !ac.live.run.Running {
		ac.live.mu.Unlock()
		return
	}
	ac.live.run.Running, ac.live.run.Ended, ac.live.run.Now = false, ac.app.now().UTC(), "finished"
	ac.live.mu.Unlock()
	ac.livePush()
}

// liveStep adds a step (or, with key, updates the step with that key in place).
func (ac *Acct) liveStep(key, title, status, detail string) {
	ac.live.mu.Lock()
	r := &ac.live.run
	if !r.Running {
		ac.live.mu.Unlock()
		return
	}
	if r.ids == nil {
		r.ids = map[string]int{}
	}
	st := RunStep{Title: title, Status: status, Detail: detail}
	if i, ok := r.ids[key]; key != "" && ok {
		if title == "" {
			st.Title = r.Steps[i].Title
		}
		r.Steps[i] = st
	} else {
		if len(r.Steps) >= 300 {
			r.Steps = r.Steps[1:]
			for k, v := range r.ids {
				r.ids[k] = v - 1
			}
		}
		r.Steps = append(r.Steps, st)
		if key != "" {
			r.ids[key] = len(r.Steps) - 1
		}
	}
	if status == "run" {
		r.Now = st.Title
	}
	ac.live.mu.Unlock()
	ac.livePush()
}

// liveFromLog: the executor's own log lines become live steps (logf): a proposal starts, its check, its result.
func (ac *Acct) liveFromLog(kind, summary string, data any) {
	switch kind {
	case "proposal":
		if p, ok := data.(model.Proposal); ok {
			ac.live.mu.Lock()
			if ac.live.props == nil || len(ac.live.props) > 2000 {
				ac.live.props = map[string]model.Proposal{}
			}
			ac.live.props[p.ID] = p
			ac.live.mu.Unlock()
			ac.liveStep(p.ID, proposalTitle(p), "run", "sending to Meta…")
		}
	case "check":
		if r, ok := data.(executor.Result); ok && r.ProposalID != "" {
			switch r.Status {
			case "refused":
				ac.liveStep(r.ProposalID, "", "warn", "not done: "+joinReasons(r.Decision.Reasons))
			case "shadow":
				ac.liveStep(r.ProposalID, "", "info", "ready (preview: not sent)")
			case "duplicate":
				ac.liveStep(r.ProposalID, "", "info", "already done earlier")
			}
		}
	case "execution":
		if r, ok := data.(executor.Result); ok {
			if r.Status == "done" {
				ac.liveStep(r.ProposalID, "", "ok", joinSteps(r.Steps))
				switch r.Kind {
				case model.KindCreateCampaign, model.KindCreateAdSet, model.KindCreateAd:
					ac.live.mu.Lock()
					p, run := ac.live.props[r.ProposalID], ac.live.run.Kind
					ac.live.mu.Unlock()
					title := proposalTitle(p)
					if p.ID == "" {
						title = kindLabel[r.Kind] + ": " + r.Target
					}
					ac.noteLaunched(Launched{At: ac.app.now().UTC(), Kind: r.Kind, Title: title, MetaID: r.MetaID, Creative: pstr(p.Params, "creative_id"), Run: run})
				}
			} else {
				d := "failed: " + r.Error
				if h := metaFixHint(d); h != "" {
					d += " — FIX: " + h
				}
				ac.liveStep(r.ProposalID, "", "fail", d)
			}
		}
	}
}

func proposalTitle(p model.Proposal) string {
	t := kindLabel[p.Kind]
	if t == "" {
		t = p.Kind
	}
	n := pstr(p.Params, "name")
	if n == "" {
		n = pstr(p.Params, "headline")
	}
	if n == "" {
		n = p.Target
	}
	if b, ok := p.Params["daily_budget"].(float64); ok && b > 0 {
		n += fmt.Sprintf(" (AED %.0f/day)", b)
	}
	return t + ": " + n
}

func joinReasons(xs []string) string {
	s := ""
	for i, x := range xs {
		if i > 0 {
			s += "; "
		}
		s += x
	}
	return s
}

func joinSteps(xs []string) string {
	s := ""
	for i, x := range xs {
		if i > 0 {
			s += " · "
		}
		s += x
	}
	return s
}
