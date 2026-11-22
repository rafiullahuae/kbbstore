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
	mu  sync.Mutex
	run LiveRun
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
