package main

import (
	"context"
	"errors"
	"testing"
	"time"
)

// The owner, 21 Nov: an install sat on "Pause the autopilot" while a long sync held the account (the old pause only
// waited, 2 minutes, then refused). Now a running cycle or sync is cancelled after a 10 s grace and the install goes on.
// MUTATION: remove the cancel loop in pauseAutopilot, or the cancellable() registration, and this test times out
// (pause returns false).
func TestInstallPauseCancelsALongSync(t *testing.T) {
	ac := &Acct{id: "act_1"}
	a := &App{accounts: map[string]*Acct{"act_1": ac}}
	ac.app = a
	s := &server{app: a}

	started := make(chan struct{})
	go func() { // a scheduled sync that would run for minutes
		ac.work.Lock()
		defer ac.work.Unlock()
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
		defer cancel()
		done := ac.cancellable(cancel)
		defer done()
		close(started)
		<-ctx.Done()
	}()
	<-started
	t0 := time.Now()
	if !s.pauseAutopilot(30 * time.Second) {
		t.Fatal("the install could not pause the autopilot")
	}
	if d := time.Since(t0); d > 15*time.Second {
		t.Fatalf("pausing took %s", d)
	}
	if !a.updating.Load() {
		t.Fatal("the autopilot was not marked as updating")
	}
}

// The owner, 22 Nov: "no ANY patch is accepting, i have uploaded the 13, but this stucks". His Run it jobs ran at
// once (runJobNow, and Approve through Decide) holding the account for minutes with a context nothing could cancel,
// so the install waited out its 2 minutes and refused, and the jobs' retries took the account again. Work run under
// app.stoppable now ends at the install's grace, and nothing new starts while it installs.
// MUTATION: remove s.app.stopWork() from pauseAutopilot and this times out (pause returns false).
func TestInstallPauseStopsARunItJob(t *testing.T) {
	ac := &Acct{id: "act_1"}
	a := &App{accounts: map[string]*Acct{"act_1": ac}}
	ac.app = a
	s := &server{app: a}

	started := make(chan struct{})
	go func() { // runJobNow's shape: the account held, a 10-minute ctx, made stoppable
		ac.work.Lock()
		defer ac.work.Unlock()
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
		defer cancel()
		ctx, stop := a.stoppable(ctx)
		defer stop()
		close(started)
		<-ctx.Done()
	}()
	<-started
	t0 := time.Now()
	if !s.pauseAutopilot(30 * time.Second) {
		t.Fatal("the install could not stop the Run it job")
	}
	if d := time.Since(t0); d > 15*time.Second {
		t.Fatalf("pausing took %s", d)
	}
	// nothing new starts while it installs
	if _, code, err := ac.runJobNow(context.Background(), "x"); !errors.Is(err, errUpdating) || code != 409 {
		t.Fatalf("a Run it started during the install: %d %v", code, err)
	}
	// after the install, work is stoppable afresh (not born cancelled)
	s.resumeAutopilot()
	ctx, stop := a.stoppable(context.Background())
	defer stop()
	select {
	case <-ctx.Done():
		t.Fatal("work after the install was cancelled at once")
	case <-time.After(50 * time.Millisecond):
	}
}
