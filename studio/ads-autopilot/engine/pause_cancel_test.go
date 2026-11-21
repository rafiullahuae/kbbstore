package main

import (
	"context"
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
