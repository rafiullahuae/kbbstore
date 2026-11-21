package brain

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"kaa/model"
)

// Early stops only on STRONG evidence. The defect this guards: one quiet morning (a low CTR on a few hundred
// impressions, or a low CTR with normal clicks costs) stopping a new ad that would have sold. MUTATION: drop
// "len(sig) >= 2" in Early → the CTR-only case is a kill and this is red; drop the EarlyMinImpr gate → the 800-impression
// case is judged and red.
func TestEarlyKillOnlyOnStrongEvidence(t *testing.T) {
	med := EarlyStats{MedCTR: 0.012, MedCPC: 1.5, MedATCPerClick: 0.08, TargetCPA: 60}
	at := func(f func(*EarlyStats)) EarlyVerdict { s := med; s.AgeHours = 20; f(&s); return Early(s) }
	// 4,000 impressions, 8 clicks (0.2% vs 1.2%), AED 40 spent → CPC AED 5 (> 2 × 1.5): two independent signals
	if v := at(func(s *EarlyStats) { s.Impr, s.Clicks, s.SpendAED = 4000, 8, 40 }); v.Verdict != "kill" || v.PLow < EarlyKillP || len(v.Signals) < 2 {
		t.Fatalf("strong evidence not stopped: %+v", v)
	}
	// the same low CTR but clicks at a normal price: one signal → watched, not stopped
	if v := at(func(s *EarlyStats) { s.Impr, s.Clicks, s.SpendAED = 4000, 8, 12 }); v.Verdict != "weak" {
		t.Fatalf("one signal stopped an ad: %+v", v)
	}
	// too few impressions to read anything
	if v := at(func(s *EarlyStats) { s.Impr, s.Clicks, s.SpendAED = 800, 0, 30 }); v.Verdict != "too_early" {
		t.Fatalf("800 impressions judged: %+v", v)
	}
	// a purchase already: kept whatever the CTR
	if v := at(func(s *EarlyStats) { s.Impr, s.Clicks, s.SpendAED, s.Purch = 4000, 8, 40, 1 }); v.Verdict != "promising" {
		t.Fatalf("a buying ad: %+v", v)
	}
	// strong signals but under half the target CPA spent: not yet
	if v := at(func(s *EarlyStats) { s.Impr, s.Clicks, s.SpendAED = 4000, 6, 20 }); v.Verdict == "kill" {
		t.Fatalf("stopped before half the target CPA was spent: %+v", v)
	}
	// a CTR at the median: in range
	if v := at(func(s *EarlyStats) { s.Impr, s.Clicks, s.SpendAED, s.ATC = 4000, 50, 60, 4 }); v.Verdict == "kill" || v.Verdict == "weak" {
		t.Fatalf("a normal ad flagged: %+v", v)
	}
}

// The morning's creation requests: one request per missing ad (deduplicated on a second run), ONE notification for
// the day ("Today's ads: N to create — Start creating"), nothing in Automatic mode. MUTATION: drop the per-day
// "creation_daily" guard → two notifications, red; drop the dedupe → four requests, red.
func TestRaiseDailyOncePerDay(t *testing.T) {
	dir := t.TempDir()
	stub := filepath.Join(dir, "notify.sh")
	log := filepath.Join(dir, "notified.txt")
	os.WriteFile(stub, []byte("#!/bin/sh\necho \"$1|$2\" >> "+log+"\n"), 0o700)
	t.Setenv("KAA_NOTIFY_CMD", stub)
	e := &Engine{B: NewBrain(""), dir: dir, deps: model.BrainDeps{}}
	now := time.Date(2026, 11, 23, 7, 0, 0, 0, time.UTC)
	asks := []DailyAsk{{JobID: "vol-20261123-1", ProductIDs: []string{"p1"}, Product: "Serum", Lang: "ar", Format: "9:16", Angle: "hero ingredient", When: now.Add(2 * time.Hour)},
		{JobID: "vol-20261123-2", ProductIDs: []string{"p2"}, Product: "Toner", Lang: "en", Format: "4:5", Angle: "routine step", When: now.Add(2 * time.Hour)}}
	if n := e.RaiseDaily("act_1", now, "2026-11-23", asks); n != 2 {
		t.Fatalf("raised %d", n)
	}
	e.RaiseDaily("act_1", now.Add(time.Hour), "2026-11-23", asks)
	reqs := e.creations("act_1")
	got, _ := os.ReadFile(log)
	if len(reqs) != 2 || strings.Count(string(got), "Start creating") != 1 || !strings.Contains(string(got), "Today's ads: 2 to create") {
		t.Fatalf("requests %d, notified %q", len(reqs), got)
	}
	if !strings.Contains(reqs[1].Title, "9:16") || reqs[1].Lang != "ar" || !strings.Contains(reqs[1].Brief, "create_ad_set") {
		t.Fatalf("request %+v", reqs[1])
	}
	e.save("act_2", "settings", AccountSettings{CreationMode: CreationAPI})
	if n := e.RaiseDaily("act_2", now, "2026-11-23", asks); n != 0 || len(e.creations("act_2")) != 0 {
		t.Fatalf("Automatic mode raised %d", n)
	}
}
