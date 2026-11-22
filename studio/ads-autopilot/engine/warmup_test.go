package main

import (
	"context"
	"strings"
	"testing"
	"time"
)

func cyclesStarted(ac *Acct) int {
	n := 0
	for _, e := range ac.st.Recent(2000, "cycle") {
		if strings.HasPrefix(e.Summary, "cycle start") {
			n++
		}
	}
	return n
}

func waitWarm(t *testing.T, a *App, runs int) WarmState {
	t.Helper()
	for i := 0; i < 400; i++ {
		if w := a.WarmState(); w.Runs >= runs && !w.Running && !a.warm.running.Load() {
			return w
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("the warm-up did not finish: %+v", a.WarmState())
	return WarmState{}
}

func automate(ac *Acct) {
	ac.mu.Lock()
	ac.settings.Automated = true
	ac.mu.Unlock()
}

// The owner, 20 Nov 2026: "the system must refresh / warm up itself whenever I open it or open the laptop after away.
// it must be up to date." The Mac sleeps 9 hours (the wall clock jumps, the monotonic clock does not): the next tick
// runs ONE warm-up -- connections, a sync that re-reads the days missed, research, the cycle that fell due -- and a
// second tab, a reload or the next tick never runs a second one. MUTATION: drop the wake test in onTick → no warm-up
// (Runs stays 1, no catch-up cycle); drop the page dedupe → Runs 3.
func TestWarmUpAfterNineHourSleep(t *testing.T) {
	ac, _ := testApp(t)
	a := ac.app
	automate(ac)
	t.Cleanup(func() { mockOffset.Store(0) })
	// registered after testApp's TempDir, so it runs FIRST: the background warm-up / cycle finishes writing into
	// the data folder before the folder is removed ("TempDir RemoveAll cleanup: directory not empty")
	t.Cleanup(func() {
		for i := 0; i < 200 && a.WarmState().Running; i++ {
			time.Sleep(10 * time.Millisecond)
		}
		ac.work.Lock()
		ac.work.Unlock() //nolint:staticcheck // waits for a cycle still holding the account
		time.Sleep(50 * time.Millisecond)
	})
	ctx := context.Background()

	if !a.warmUp(ctx, "start") {
		t.Fatal("the start warm-up did not run")
	}
	w := waitWarm(t, a, 1)
	if w.LastFull.IsZero() || cyclesStarted(ac) != 1 {
		t.Fatalf("start: %+v, cycles %d", w, cyclesStarted(ac))
	}
	ac.mu.RLock()
	beforeSleep := ac.lastSync
	ac.mu.RUnlock()

	s := newScheduler(a)
	w0 := time.Now().Round(0)
	s.lastWall, s.lastMono = w0, 0
	s.onTick(w0.Add(s.tick), s.tick) // an ordinary tick: no warm-up
	if a.WarmState().Runs != 1 {
		t.Fatal("an ordinary tick started a warm-up")
	}

	mockOffset.Add(int64(9 * time.Hour)) // the Mac sleeps 9 hours
	s.onTick(w0.Add(9*time.Hour+2*s.tick), 2*s.tick)
	s.onTick(w0.Add(9*time.Hour+3*s.tick), 3*s.tick) // the next tick, while the warm-up may still run
	w = waitWarm(t, a, 2)
	if w.Reason != "wake" {
		t.Fatalf("the wake did not start the warm-up: %+v", w)
	}
	a.warmUp(ctx, "page") // the page comes back / a reload right after: served, not re-run
	s.onTick(w0.Add(9*time.Hour+4*s.tick), 4*s.tick)
	time.Sleep(50 * time.Millisecond)
	if w = a.WarmState(); w.Runs != 2 {
		t.Fatalf("expected exactly one warm-up after the wake, got %+v", w)
	}
	if n := cyclesStarted(ac); n != 2 {
		t.Fatalf("cycles after the 9-hour sleep: %d (want exactly 1 more)", n-1)
	}
	for _, st := range w.Steps {
		if st.State != "ok" && st.State != "skipped" {
			t.Fatalf("step %+v", st)
		}
	}
	// the missed days were read again: the sync ran from before the sleep's day to today
	ac.mu.RLock()
	after := ac.lastSync
	ac.mu.RUnlock()
	if !after.After(beforeSleep.Add(8 * time.Hour)) {
		t.Fatalf("no sync after the wake (%v → %v)", beforeSleep, after)
	}
	var line string // the catch-up sync (the executor re-reads the account again after any write the cycle makes)
	// the account's own day (Asia/Dubai), as the sync counts it: in UTC this test failed every night 20:00–24:00 UTC
	since := beforeSleep.In(ac.loc()).AddDate(0, 0, -2).Format("2006-01-02")
	for _, e := range ac.st.Recent(50, "snapshot") {
		if strings.HasPrefix(e.Summary, "sync ") && (line == "" || strings.Contains(e.Summary, "sync "+since+"..")) {
			line = e.Summary
		}
	}
	if !strings.Contains(line, "sync "+since+".."+after.In(ac.loc()).Format("2006-01-02")) {
		t.Fatalf("the catch-up sync did not cover %s..today: %q", since, line)
	}
}

// Offline when the lid opens: the warm-up says it is waiting for the internet, runs no cycle on stale data, arms a
// retry, and finishes by itself once Meta answers again. MUTATION: treat the offline Meta like any failure (go on to
// the cycle) → a cycle runs offline; drop warmRetryLater's timer → never finishes.
func TestWarmUpWaitsWhileOffline(t *testing.T) {
	ac, m := testApp(t)
	a := ac.app
	automate(ac)
	old := warmBackoff
	warmBackoff = []time.Duration{150 * time.Millisecond}
	t.Cleanup(func() { warmBackoff = old })

	a.meta.Base = "http://127.0.0.1:1" // no internet
	a.warmUp(context.Background(), "wake")
	w := a.WarmState()
	if !w.Offline || w.RetryAt.IsZero() || w.Steps[0].State != "offline" || w.Steps[3].State != "waiting" || !w.LastFull.IsZero() {
		t.Fatalf("offline warm-up: %+v", w)
	}
	if n := cyclesStarted(ac); n != 0 {
		t.Fatalf("%d cycle(s) ran while offline", n)
	}
	a.meta.Base = m.URL // the network is back: the armed retry finishes the job by itself
	w = waitWarm(t, a, 2)
	if w.Offline || w.LastFull.IsZero() || cyclesStarted(ac) != 1 {
		t.Fatalf("after the network came back: %+v, cycles %d", w, cyclesStarted(ac))
	}
}
