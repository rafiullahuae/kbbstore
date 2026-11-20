package safety

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"kaa/meta"
	"kaa/metamock"
	"kaa/model"
)

// MUTATION: remove "currentAED == 0 → set" (treat 0 as a cap) → the "no cap" row reads kept and goes red.
// MUTATION: drop the lastOurs condition in the raise branch → the "owner's lower cap" row reads raised.
func TestDecideCap(t *testing.T) {
	cases := []struct {
		name                  string
		cur, target, lastOurs float64
		action                string
		write                 float64
	}{
		{"no cap on the account → set", 0, 5000, 0, "set", 5000},
		{"cap above target → lowered", 9000, 5000, 0, "lowered", 5000},
		{"cap within 1 AED → kept", 5000.5, 5000, 0, "kept", 0},
		{"owner's lower cap is never raised", 3000, 5000, 0, "kept", 0},
		{"owner's lower cap, ours was different", 3000, 5000, 2500, "kept", 0},
		{"our own cap from last month → raised", 3000, 5000, 3000, "raised", 5000},
		{"target 0 → nothing written (0 removes the cap)", 4000, 0, 0, "skipped", 0},
		{"negative target → nothing written", 0, -10, 0, "skipped", 0},
	}
	for _, c := range cases {
		a, w := DecideCap(c.cur, c.target, c.lastOurs)
		if a != c.action || w != c.write {
			t.Errorf("%s: got %s %.2f, want %s %.2f", c.name, a, w, c.action, c.write)
		}
		if w < 0 || (a != "skipped" && a != "kept" && w <= 0) {
			t.Errorf("%s: would write %.2f", c.name, w)
		}
	}
}

func TestCapTarget(t *testing.T) {
	cases := []struct {
		spent, monthly, month float64
		want                  float64
		ok                    bool
	}{
		{12345, 6000, 1000, 17345, true}, // amount_spent + what is left this month
		{12345, 6000, 7000, 12345, true}, // over the month: the cap = what is already spent (delivery stops)
		{0, 6000, 6000, 0, false},        // nothing positive expresses it: never 0
		{100.019, 50, 0, 150.01, true},   // rounded DOWN to the fils
		{0, 6000, 0, 6000, true},
	}
	for _, c := range cases {
		got, ok := CapTarget(c.spent, c.monthly, c.month)
		if got != c.want || ok != c.ok {
			t.Errorf("CapTarget(%v,%v,%v) = %v,%v want %v,%v", c.spent, c.monthly, c.month, got, ok, c.want, c.ok)
		}
	}
}

func rig(t *testing.T) (*metamock.Server, *Net) {
	t.Helper()
	m := metamock.New("EAATESTTOKEN00000000000000", "act_1000000001")
	u, err := m.Start()
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(m.Close)
	mc := meta.New(func() string { return m.Token })
	mc.Base = u
	mc.Sleep = func(context.Context, time.Duration) error { return nil }
	return m, &Net{Meta: mc, Act: func() string { return m.Account }, State: &State{}}
}

func lim() model.Limits {
	l := model.DefaultLimits()
	l.DailyAED, l.MonthlyAED, l.TargetCPAAED, l.Autopilot = 300, 6000, 60, model.AutopilotAuto
	return l
}

func capWrites(m *metamock.Server) []string {
	var out []string
	for _, w := range m.Writes() {
		if v, ok := w.Form["spend_cap"]; ok {
			out = append(out, v)
		}
	}
	return out
}

// The whole net, twice: the first run sets the cap and makes 4 rules; the second writes NOTHING.
// MUTATION: compare RuleHashes with a fresh hash every run (drop the "kept" case) → the second run updates 4 rules.
func TestEnsureIsIdempotent(t *testing.T) {
	m, n := rig(t)
	in := Input{Limits: lim(), MonthSpendAED: 1000}
	rep, err := n.Ensure(context.Background(), in)
	if err != nil {
		t.Fatal(err)
	}
	if rep.SpendCap.Action != "set" || rep.SpendCap.AfterAED != 17345 { // 12345 spent + (6000-1000)
		t.Fatalf("cap %+v", rep.SpendCap)
	}
	if got := capWrites(m); len(got) != 1 || got[0] != "17345.00" {
		t.Fatalf("spend_cap writes %v (Meta takes standard units: 17345.00)", got)
	}
	if len(m.Rules()) != 4 {
		t.Fatalf("%d rules, want 4", len(m.Rules()))
	}
	before := len(m.Writes())
	rep2, err := n.Ensure(context.Background(), in)
	if err != nil {
		t.Fatal(err)
	}
	if len(m.Writes()) != before {
		t.Fatalf("second run wrote %d times: %+v", len(m.Writes())-before, rep2)
	}
	for _, r := range rep2.Rules {
		if r.Action != "kept" {
			t.Fatalf("rule %s: %s", r.Name, r.Action)
		}
	}
	// every rule is scoped to the app's own objects and money is in fils
	for _, r := range m.Rules() {
		b, _ := json.Marshal(r["evaluation_spec"])
		if !strings.Contains(string(b), `"KAA:"`) {
			t.Fatalf("rule %v is not scoped to KAA: objects", r["name"])
		}
		if r["name"] == "KAA: no-purchase ad set" && !strings.Contains(string(b), `"value":18000`) { // 3 × 60 AED = 18000 fils
			t.Fatalf("threshold not in fils: %s", b)
		}
	}
}

// A lower cap the owner set by hand stays; a cap of 0 is never sent.
// MUTATION: in SetSpendCap drop the "aed > 0" guard and in DecideCap return "set" for target 0 → a "0.00" write appears.
func TestCapNeverRaisedNeverZero(t *testing.T) {
	m, n := rig(t)
	m.SetAccount("spend_cap", "1300000") // owner: AED 13,000 (below the 17,345 target)
	if _, err := n.Ensure(context.Background(), Input{Limits: lim(), MonthSpendAED: 1000}); err != nil {
		t.Fatal(err)
	}
	if got := capWrites(m); len(got) != 0 {
		t.Fatalf("the owner's lower cap was changed: %v", got)
	}
	// month used up and nothing ever spent on the account: no positive cap exists → nothing written, alert raised
	m.SetAccount("spend_cap", "0")
	m.SetAccount("amount_spent", "0")
	rep, _ := n.Ensure(context.Background(), Input{Limits: lim(), MonthSpendAED: 6000})
	for _, w := range capWrites(m) {
		if w == "0" || w == "0.00" {
			t.Fatal("spend_cap 0 was sent: that REMOVES the account limit")
		}
	}
	if rep.SpendCap.Action != "skipped" || len(rep.Alerts) == 0 {
		t.Fatalf("%+v", rep)
	}
	if err := n.Meta.SetSpendCap(context.Background(), m.Account, 0); err == nil {
		t.Fatal("SetSpendCap(0) was accepted")
	}
}

// Rules change with the limits and stale KAA: rules go; duplicates (a retried create) are cleaned; the owner's own
// rules are never touched.
func TestRulesFollowLimits(t *testing.T) {
	m, n := rig(t)
	ctx := context.Background()
	if _, err := n.Ensure(ctx, Input{Limits: lim(), MonthSpendAED: 0}); err != nil {
		t.Fatal(err)
	}
	// the owner's own rule and a duplicate KAA rule appear
	_ = n.Meta.Post(ctx, m.Account+"/adrules_library", map[string][]string{"name": {"My own rule"}, "evaluation_spec": {`{"evaluation_type":"SCHEDULE","filters":[]}`}, "execution_spec": {`{"execution_type":"NOTIFICATION"}`}}, "", nil)
	_ = n.Meta.Post(ctx, m.Account+"/adrules_library", map[string][]string{"name": {"KAA: daily limit"}, "evaluation_spec": {`{}`}, "execution_spec": {`{}`}}, "", nil)
	l := lim()
	l.TargetCPAAED, l.DailyAED = 0, 400 // CPA cleared: the three CPA rules go; the daily rule changes
	rep, err := n.Ensure(ctx, Input{Limits: l})
	if err != nil {
		t.Fatal(err)
	}
	names := map[string]int{}
	for _, r := range m.Rules() {
		names[r["name"].(string)]++
	}
	if names["KAA: daily limit"] != 1 || names["My own rule"] != 1 || len(names) != 2 {
		t.Fatalf("rules after: %v (report %+v)", names, rep.Rules)
	}
	for _, r := range m.Rules() {
		if r["name"] == "KAA: daily limit" {
			b, _ := json.Marshal(r["evaluation_spec"])
			if !strings.Contains(string(b), `"value":40000`) {
				t.Fatalf("daily rule not updated to 400 AED = 40000 fils: %s", b)
			}
		}
	}
}

func TestDailyRuleSplitsAcrossRunningCampaigns(t *testing.T) {
	tree := []model.Campaign{{Managed: true, Status: "ACTIVE"}, {Managed: true, Status: "ACTIVE"}, {Managed: false, Status: "ACTIVE"}}
	rules, _ := DesiredRules(lim(), tree)
	for _, r := range rules {
		if r.Name == "KAA: daily limit" {
			if v := r.Evaluation["trigger"].(map[string]any)["value"]; v != int64(15000) {
				t.Fatalf("per-campaign trigger %v, want 15000 fils (300 AED / 2)", v)
			}
			return
		}
	}
	t.Fatal("no daily rule")
}
