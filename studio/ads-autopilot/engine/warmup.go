package main

import (
	"context"
	"fmt"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"kaa/brain"
	"kaa/model"
)

// Warm-up: the owner, 20 Nov 2026 -- "the system must refresh / warm up itself whenever I open it or open the laptop
// after away. it must be up to date."
//
// On engine start, on wake from sleep (scheduler.onTick: the wall clock jumped further than the monotonic clock, which
// does not run while a Mac sleeps) and when the page comes back (visibilitychange / online → POST /api/warmup), ONE
// warm-up runs, in this order:
//
//	connections  live checks of Meta, Claude and the shop (shop orders and stock come with the shop check)
//	sync         every automated account: tree + insights since the last sync (Sync re-reads from last-2 days, so
//	             the days missed while away are caught up); an account whose cycle is due syncs as the cycle's
//	             first step instead, so nothing is read twice
//	research     market notes, when older than brain.ResearchEvery (the scheduler never refreshed them before)
//	cycle        each automated account whose cycle fell due while away (creation requests and occasions are
//	             re-evaluated by the cycle itself)
//
// Never two at once (an atomic flag; the scheduler's own step waits while one runs). A page asking again within two
// minutes of a finished warm-up gets the state, not a second run: a new tab or a reload never re-runs heavy work.
// Offline (Meta did not answer): the steps after the connections wait, a retry is armed with backoff (30 s, 1, 2, 5
// min), and the page's `online` event retries at once. Every step is published ("warmup" over SSE) and every skip
// says why in Activity.

type warmStep struct {
	ID    string `json:"id"`    // connections | sync | research | cycle
	State string `json:"state"` // waiting | running | ok | skipped | failed | offline
	Note  string `json:"note,omitempty"`
}

type WarmState struct {
	Running  bool       `json:"running"`
	Reason   string     `json:"reason,omitempty"` // start | wake | page | online | retry
	Started  time.Time  `json:"started,omitempty"`
	Steps    []warmStep `json:"steps"`
	LastFull time.Time  `json:"last_full,omitempty"` // the last warm-up whose every step ended ok or skipped-for-a-reason
	Offline  bool       `json:"offline,omitempty"`
	RetryAt  time.Time  `json:"retry_at,omitempty"`
	Runs     int        `json:"runs"`
}

type warmer struct {
	running atomic.Bool
	mu      sync.Mutex
	st      WarmState
	fails   int
	retry   *time.Timer
}

// warmDedupe: a page asking within this long of a finished warm-up does not start another.
const warmDedupe = 2 * time.Minute

// warmPause: -mock only (/api/test/offline): each step waits this long, so a check can see the bar while it runs.
var warmPause atomic.Int64

var warmBackoff = []time.Duration{30 * time.Second, time.Minute, 2 * time.Minute, 5 * time.Minute}

func (a *App) WarmState() WarmState {
	a.warm.mu.Lock()
	defer a.warm.mu.Unlock()
	s := a.warm.st
	s.Steps = append([]warmStep(nil), s.Steps...)
	return s
}

func (a *App) warmPublish() {
	if a.publish != nil {
		a.publish("warmup", map[string]any{"account": "", "data": a.WarmState()})
	}
}

func (a *App) warmSet(id, state, note string) {
	a.warm.mu.Lock()
	for i := range a.warm.st.Steps {
		if a.warm.st.Steps[i].ID == id {
			a.warm.st.Steps[i].State, a.warm.st.Steps[i].Note = state, note
		}
	}
	a.warm.mu.Unlock()
	a.warmPublish()
	if d := warmPause.Load(); d > 0 && state == "running" {
		time.Sleep(time.Duration(d))
	}
}

// warmUp runs one warm-up and returns true, or returns false at once when one is running already (or, for a page's
// request, when one finished less than warmDedupe ago).
func (a *App) warmUp(ctx context.Context, reason string) bool {
	if a.updating.Load() {
		return false
	}
	if reason == "page" {
		a.warm.mu.Lock()
		recent := !a.warm.st.LastFull.IsZero() && a.now().Sub(a.warm.st.LastFull) < warmDedupe
		a.warm.mu.Unlock()
		if recent {
			return false
		}
	}
	if !a.warm.running.CompareAndSwap(false, true) {
		return false
	}
	defer a.warm.running.Store(false)
	a.warm.mu.Lock()
	if a.warm.retry != nil {
		a.warm.retry.Stop()
		a.warm.retry = nil
	}
	a.warm.st.Running, a.warm.st.Reason, a.warm.st.Started, a.warm.st.Offline, a.warm.st.RetryAt = true, reason, a.now(), false, time.Time{}
	a.warm.st.Runs++
	a.warm.st.Steps = []warmStep{{ID: "connections", State: "waiting"}, {ID: "sync", State: "waiting"}, {ID: "research", State: "waiting"}, {ID: "cycle", State: "waiting"}, {ID: "cloud", State: "waiting"}}
	a.warm.mu.Unlock()
	a.warmPublish()
	a.st.Append("warmup", "refresh started ("+reason+")", map[string]any{"reason": reason})
	a.warmSet("connections", "running", "")
	full := true
	defer func() {
		a.warm.mu.Lock()
		a.warm.st.Running = false
		if full {
			a.warm.st.LastFull = a.now()
			a.warm.fails = 0
		}
		a.warm.mu.Unlock()
		a.warmPublish()
	}()

	// 1. connections (live checks)
	cctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	conns := a.connectAll(cctx)
	cancel()
	notes := []string{}
	for _, x := range [][3]any{{"Meta", conns.Meta.OK, conns.Meta.Error}, {"Claude", conns.Claude.OK, conns.Claude.Error}, {"shop", conns.Shop.OK, conns.Shop.Error}} {
		if !x[1].(bool) {
			notes = append(notes, fmt.Sprintf("%s: %s", x[0], x[2]))
		}
	}
	if !conns.Meta.OK && offlineErr(conns.Meta.Error) {
		full = false
		d := a.warmRetryLater(ctx)
		a.warm.mu.Lock()
		a.warm.st.Offline = true
		a.warm.mu.Unlock()
		a.warmSet("connections", "offline", "Meta did not answer (no internet?). Trying again in "+d.Round(time.Second).String()+".")
		for _, s := range []string{"sync", "research", "cycle", "cloud"} {
			a.warmSet(s, "waiting", "waits for the internet")
		}
		a.st.Append("warmup", "refresh waits: Meta did not answer (no internet?); trying again in "+d.Round(time.Second).String(), map[string]any{"error": conns.Meta.Error})
		return true
	}
	if len(notes) == 0 {
		a.warmSet("connections", "ok", "")
	} else {
		full = false
		a.warmSet("connections", "failed", strings.Join(notes, " · "))
	}

	// 2. sync every automated account whose cycle is not due (a due cycle syncs first itself)
	syncEvery, cycleEvery := a.intervals()
	now := a.now()
	var cycleDue []*Acct
	if !conns.Meta.OK {
		a.warmSet("sync", "skipped", "Meta is not connected: "+conns.Meta.Error)
		a.st.Append("warmup", "sync skipped: Meta is not connected", map[string]any{"error": conns.Meta.Error})
	} else {
		a.warmSet("sync", "running", "")
		n, failed := 0, []string{}
		for _, ac := range a.Accounts() {
			if !ac.Automated() {
				continue
			}
			ac.mu.RLock()
			ls, lc := ac.lastSync, ac.lastCycle
			ac.mu.RUnlock()
			if _, doCycle := due(now, ls, lc, syncEvery, cycleEvery); doCycle {
				cycleDue = append(cycleDue, ac)
				continue
			}
			if !ac.work.TryLock() { // a cycle the owner started is running: it syncs itself
				continue
			}
			sctx, c := context.WithTimeout(ctx, 10*time.Minute)
			done := ac.cancellable(c) // an update can stop this sync (update_flow.go pauseAutopilot)
			err := ac.Sync(sctx)
			done()
			c()
			ac.work.Unlock()
			if err != nil {
				failed = append(failed, ac.id+": "+err.Error())
				ac.alertOnce("Sync with Meta failed: " + err.Error())
				continue
			}
			n++
		}
		switch {
		case len(failed) > 0:
			full = false
			a.warmSet("sync", "failed", strings.Join(failed, " · "))
		case n == 0 && len(cycleDue) == 0:
			a.warmSet("sync", "skipped", "no account is automated")
		default:
			a.warmSet("sync", "ok", "")
		}
	}

	// 3. research, when older than its schedule
	a.mu.RLock()
	rAt := a.research.UpdatedAt
	a.mu.RUnlock()
	switch {
	case !now.IsZero() && !rAt.IsZero() && now.Sub(rAt) < brain.ResearchEvery:
		a.warmSet("research", "ok", "up to date")
	case !conns.Claude.OK:
		a.warmSet("research", "skipped", "Claude is not connected")
		a.st.Append("warmup", "research skipped: Claude is not connected", map[string]any{"error": conns.Claude.Error})
	default:
		a.warmSet("research", "running", "")
		rctx, c := context.WithTimeout(ctx, 15*time.Minute)
		_, err := a.refreshResearch(rctx, "")
		c()
		if err != nil {
			full = false
			a.warmSet("research", "failed", err.Error())
			a.st.Append("warmup", "research failed: "+err.Error(), nil)
		} else {
			a.warmSet("research", "ok", "")
		}
	}

	// 4. the cycles that fell due while away, one account after another
	if !conns.Meta.OK {
		a.warmSet("cycle", "skipped", "Meta is not connected")
	} else if len(cycleDue) == 0 {
		for _, ac := range a.Accounts() { // no cycle due: occasions and creation still re-evaluated (occasions.go Refresh)
			if ac.Automated() {
				ac.Refresh(ctx)
			}
		}
		a.warmSet("cycle", "ok", "none due; occasions and creation requests re-checked")
	} else {
		a.warmSet("cycle", "running", "")
		failed := []string{}
		for _, ac := range cycleDue {
			out, err := ac.Cycle(ctx, "auto")
			if err == errBusy { // the owner's own Run a cycle is on it
				continue
			}
			if err != nil {
				failed = append(failed, ac.id+": "+err.Error())
			} else if se, _ := out["sync_error"].(string); se != "" {
				failed = append(failed, ac.id+": "+se)
			}
		}
		if len(failed) > 0 {
			full = false
			a.warmSet("cycle", "failed", strings.Join(failed, " · "))
		} else {
			a.warmSet("cycle", "ok", "")
		}
	}
	// 5. cloud creation (exchange_wire.go): push the jobs this refresh raised, bring back what the cloud made meanwhile
	if a.exRemote() == nil {
		a.warmSet("cloud", "skipped", "cloud creation is not connected")
	} else {
		a.warmSet("cloud", "running", "")
		if sum, err := a.exchangeSync(ctx, "warm-up"); err != nil {
			full = false
			a.warmSet("cloud", "failed", err.Error())
		} else {
			a.warmSet("cloud", "ok", fmt.Sprintf("%v ad(s) brought back, %v job(s) sent", sum["imported"], sum["pushed"]))
		}
	}
	if c := a.ccRunner(); c != nil { // Claude Code on this Mac: pending requests are made now (claudecode.go)
		c.kick("warm-up")
	}
	a.st.Append("warmup", fmt.Sprintf("refresh finished (%s)", map[bool]string{true: "up to date", false: "with problems: see the steps"}[full]), a.WarmState())
	return true
}

// warmRetryLater arms the next try with backoff and returns the wait.
func (a *App) warmRetryLater(ctx context.Context) time.Duration {
	a.warm.mu.Lock()
	defer a.warm.mu.Unlock()
	d := warmBackoff[min(a.warm.fails, len(warmBackoff)-1)]
	a.warm.fails++
	a.warm.st.RetryAt = a.now().Add(d)
	if a.warm.retry != nil {
		a.warm.retry.Stop()
	}
	a.warm.retry = time.AfterFunc(d, func() { go a.warmUp(context.WithoutCancel(ctx), "retry") })
	return d
}

// offlineErr: Meta did not answer at all (no internet, DNS, connection refused/reset, timeout).
func offlineErr(s string) bool {
	s = strings.ToLower(s)
	for _, x := range []string{"did not answer", "no such host", "connection refused", "network is unreachable", "connection reset", "i/o timeout", "deadline exceeded", "dial tcp"} {
		if strings.Contains(s, x) {
			return true
		}
	}
	return false
}

// refreshResearch: the market notes (Settings' "refresh research" and the warm-up).
func (a *App) refreshResearch(ctx context.Context, focus string) (model.Research, error) {
	a.mu.RLock()
	br, shopSnap, prev := a.brain, a.shopSnap, a.research
	a.mu.RUnlock()
	if br == nil {
		return prev, fmt.Errorf("the planner is not ready")
	}
	res, err := br.Research(ctx, model.ResearchInput{Now: a.now(), Shop: shopSnap, Previous: prev, Focus: focus})
	if err != nil {
		return prev, err
	}
	a.mu.Lock()
	a.research = res
	a.mu.Unlock()
	a.dirtyAll()
	_ = a.st.Save("research", res)
	a.st.Append("research", "research updated", map[string]any{"trends": len(res.Trends), "angles": len(res.Angles), "sources": len(res.Sources)})
	if a.publish != nil {
		a.publish("research.updated", map[string]any{"account": "", "data": map[string]any{"at": res.UpdatedAt}})
	}
	return res, nil
}
