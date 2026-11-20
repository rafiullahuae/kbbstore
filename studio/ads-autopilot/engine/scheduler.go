package main

import (
	"context"
	"log"
	"time"
)

// scheduler: while the app is open, for EACH automated ad account on its own: sync every cfg.SyncMinutes (default
// 60) and run the planner cfg.PlansPerDay times a day (default 4: every 6 h). On open it catches up at once (a cycle
// syncs first). Never two cycles on one account at a time (Acct.work); different accounts run side by side, sharing
// the Meta client's pacing. Sleep/wake: the loop ticks every 30 s on the wall clock, so after the Mac wakes the next
// tick sees what is due. Once a day: the token (expiry warning / refresh) and the portfolio (new ad accounts).
type scheduler struct {
	a          *App
	tick       time.Duration
	attempt    map[string]time.Time // last sync attempt per account: a failing sync is retried every 5 min
	tokenCheck time.Time
}

func newScheduler(a *App) *scheduler {
	return &scheduler{a: a, tick: 30 * time.Second, attempt: map[string]time.Time{}}
}

func (s *scheduler) intervals() (time.Duration, time.Duration) {
	s.a.mu.RLock()
	defer s.a.mu.RUnlock()
	syncEvery := time.Duration(s.a.cfg.SyncMinutes) * time.Minute
	if syncEvery < 15*time.Minute {
		syncEvery = 15 * time.Minute
	}
	per := s.a.cfg.PlansPerDay
	if per < 1 {
		per = 1
	}
	if per > 24 {
		per = 24
	}
	return syncEvery, 24 * time.Hour / time.Duration(per)
}

// due decides what to run now. Pure, for the tests.
func due(now, lastSync, lastCycle time.Time, syncEvery, cycleEvery time.Duration) (doSync, doCycle bool) {
	doCycle = lastCycle.IsZero() || now.Sub(lastCycle) >= cycleEvery
	doSync = !doCycle && (lastSync.IsZero() || now.Sub(lastSync) >= syncEvery)
	return
}

func (s *scheduler) start(stop <-chan struct{}) {
	go func() {
		last := time.Now()
		s.step()
		t := time.NewTicker(s.tick)
		defer t.Stop()
		for {
			select {
			case <-stop:
				return
			case now := <-t.C:
				if gap := now.Round(0).Sub(last.Round(0)); gap > 3*s.tick {
					log.Printf("scheduler: woke after %s; catching up", gap.Round(time.Second))
				}
				last = now
				s.step()
			}
		}
	}()
}

func (s *scheduler) step() {
	a := s.a
	now := a.now()
	if now.Sub(s.tokenCheck) >= 24*time.Hour {
		first := s.tokenCheck.IsZero()
		s.tokenCheck = now
		go func() {
			ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
			defer cancel()
			a.maintainToken(ctx)
			if !first && a.metaOK() { // new ad accounts / assets in the portfolio
				c := a.testMeta(ctx)
				a.mu.Lock()
				a.conns.Meta = c
				a.mu.Unlock()
			}
		}()
	}
	if !a.metaOK() || a.updating.Load() { // paused while an update installs
		return
	}
	syncEvery, cycleEvery := s.intervals()
	for _, ac := range a.Accounts() {
		if !ac.Automated() {
			continue
		}
		ac.mu.Lock()
		lastSync, lastCycle := ac.lastSync, ac.lastCycle
		ac.nextSync, ac.nextCycle = lastSync.Add(syncEvery), lastCycle.Add(cycleEvery)
		ac.mu.Unlock()
		doSync, doCycle := due(now, lastSync, lastCycle, syncEvery, cycleEvery)
		ac := ac
		switch {
		case doCycle:
			go func() {
				if _, err := ac.Cycle(context.Background(), "auto"); err != nil && err != errBusy {
					log.Printf("cycle %s: %v", ac.id, err)
				}
			}()
		case doSync && now.Sub(s.attempt[ac.id]) >= 5*time.Minute:
			s.attempt[ac.id] = now
			go func() {
				if !ac.work.TryLock() {
					return
				}
				defer ac.work.Unlock()
				ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
				defer cancel()
				if err := ac.Sync(ctx); err != nil {
					ac.alertOnce("Sync with Meta failed: " + err.Error())
				}
			}()
		}
	}
}
