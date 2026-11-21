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
	lastWall   time.Time            // onTick: the previous tick on the wall clock
	lastMono   time.Duration        // and on the monotonic clock
	opened     map[string]bool      // the account's daily plan was handled since the app opened (dailyplan.go)
	dueTry     map[string]time.Time // last between-cycles run of due jobs (volume.go runDue)
}

func newScheduler(a *App) *scheduler {
	return &scheduler{a: a, tick: 30 * time.Second, attempt: map[string]time.Time{}, opened: map[string]bool{}, dueTry: map[string]time.Time{}}
}

func (s *scheduler) intervals() (time.Duration, time.Duration) { return s.a.intervals() }

func (a *App) intervals() (time.Duration, time.Duration) {
	a.mu.RLock()
	defer a.mu.RUnlock()
	syncEvery := time.Duration(a.cfg.SyncMinutes) * time.Minute
	if syncEvery < 15*time.Minute {
		syncEvery = 15 * time.Minute
	}
	per := a.cfg.PlansPerDay
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
		t0 := time.Now()
		s.lastWall, s.lastMono = t0.Round(0), 0
		s.a.warmUp(context.Background(), "start") // on open: everything fresh before the regular rhythm takes over
		t := time.NewTicker(s.tick)
		defer t.Stop()
		for {
			select {
			case <-stop:
				return
			case now := <-t.C:
				s.onTick(now.Round(0), now.Sub(t0)) // wall clock, and the monotonic clock (Sub of two monotonic times)
			}
		}
	}()
}

// onTick: wall is the wall clock, mono the monotonic time since start. While a Mac sleeps the monotonic clock stops
// and the wall clock does not, so a wall gap much larger than the monotonic gap (or a wall gap of several ticks: a
// stalled process) is a wake: one warm-up runs instead of the step, catching up what fell due while away.
func (s *scheduler) onTick(wall time.Time, mono time.Duration) {
	wallGap, monoGap := wall.Sub(s.lastWall), mono-s.lastMono
	s.lastWall, s.lastMono = wall, mono
	if wallGap-monoGap > 2*s.tick || wallGap > 3*s.tick {
		log.Printf("scheduler: woke after %s; refreshing", wallGap.Round(time.Second))
		go s.a.warmUp(context.Background(), "wake")
		return
	}
	s.step()
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
	if a.warm.running.Load() { // a warm-up does this tick's work, in order
		return
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
		// the Daily Plan: at the first open of the day, or from 07:00 while open (dailyplan.go)
		if ac.maybeDailyPlan(context.Background(), !s.opened[ac.id]) {
			s.opened[ac.id] = true
		} else if d, ok := ac.dailyPlan(); ok && d.Date == now.In(ac.loc()).Format("2006-01-02") {
			s.opened[ac.id] = true
		}
		switch {
		case doCycle:
			go func() {
				if _, err := ac.Cycle(context.Background(), "auto"); err != nil && err != errBusy {
					log.Printf("cycle %s: %v", ac.id, err)
				}
				if ac.work.TryLock() { // close monitoring after the cycle's sync (watch.go)
					ac.watch(context.Background())
					ac.work.Unlock()
				}
			}()
		case ac.hasDue(now) && now.Sub(s.dueTry[ac.id]) >= 5*time.Minute: // jobs at their times between cycles
			s.dueTry[ac.id] = now
			go func() {
				ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
				defer cancel()
				if _, err := ac.runDue(ctx); err != nil && err != errBusy {
					ac.alertOnce("Running today's jobs failed: " + err.Error())
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
				defer ac.cancellable(cancel)() // an update can stop this sync (update_flow.go pauseAutopilot)
				if err := ac.Sync(ctx); err != nil {
					ac.alertOnce("Sync with Meta failed: " + err.Error())
					return
				}
				ac.watch(ctx) // the hourly close check (watch.go)
			}()
		}
	}
}
