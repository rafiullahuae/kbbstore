package main

import (
	"context"
	"log"
	"time"
)

// scheduler: while the app is open, sync every cfg.SyncMinutes (default 60) and run the planner cfg.PlansPerDay
// times a day (default 4: every 6 h). On open it catches up at once: a sync (Insights since the last run) and, when the
// last plan is older than one interval, a cycle. Never two at a time (App.work). Sleep/wake: the loop ticks every
// 30 s on the wall clock, so after the Mac wakes the next tick sees what is due (and a long gap reads as a wake:
// catch-up at once).
type scheduler struct {
	a          *App
	tick       time.Duration
	attempt    time.Time // last sync attempt: a failing sync is retried every 5 min, not every tick
	tokenCheck time.Time
}

func newScheduler(a *App) *scheduler { return &scheduler{a: a, tick: 30 * time.Second} }

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
	a.mu.RLock()
	lastSync, lastCycle, ok := a.lastSync, a.lastCycle, a.conns.Meta.OK
	a.mu.RUnlock()
	syncEvery, cycleEvery := s.intervals()
	now := a.now()
	a.mu.Lock()
	a.nextSync, a.nextCycle = lastSync.Add(syncEvery), lastCycle.Add(cycleEvery)
	a.mu.Unlock()
	if now.Sub(s.tokenCheck) >= 24*time.Hour { // the token: expiry warning and refresh, once a day
		s.tokenCheck = now
		go func() {
			ctx, cancel := context.WithTimeout(context.Background(), time.Minute)
			defer cancel()
			a.maintainToken(ctx)
		}()
	}
	if !ok {
		return
	}
	doSync, doCycle := due(now, lastSync, lastCycle, syncEvery, cycleEvery)
	switch {
	case doCycle:
		go func() {
			if _, err := a.Cycle(context.Background(), "auto"); err != nil && err != errBusy {
				log.Printf("cycle: %v", err)
			}
		}()
	case doSync && now.Sub(s.attempt) >= 5*time.Minute:
		s.attempt = now
		go func() {
			if !a.work.TryLock() {
				return
			}
			defer a.work.Unlock()
			ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
			defer cancel()
			if err := a.Sync(ctx); err != nil {
				a.alertOnce("Sync with Meta failed: " + err.Error())
			}
		}()
	}
}
