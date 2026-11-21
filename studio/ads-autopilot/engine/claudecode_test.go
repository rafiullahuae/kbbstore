package main

import (
	"context"
	"encoding/json"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"kaa/brain"
	"kaa/model"
)

// The fake `claude`: answers --version and `auth status` like the real CLI (code.claude.com/docs/en/cli-reference),
// and for a `-p` run records its arguments, environment, stdin and MCP config, then acts out FAKE_CC_MODE:
//
//	success   does what the connector's create_ad_set + save_to_library do (the same engine routes), prints the
//	          JSON result
//	usage     the usage-limit refusal        authfail  "Invalid API key · Please run /login"
//	timeout   never answers                  empty     finishes without calling a tool
const fakeClaude = `#!/bin/bash
L="$FAKE_CC_LOG"
if [ "$1" = "--version" ]; then echo "2.1.330 (Claude Code)"; exit 0; fi
if [ "$1" = "auth" ]; then
  if [ "$FAKE_CC_AUTH" = "out" ]; then echo '{"loggedIn":false,"authMethod":"none"}'; exit 1; fi
  if [ "$FAKE_CC_AUTH" = "apikey" ]; then echo '{"loggedIn":true,"authMethod":"api_key"}'; exit 0; fi
  echo '{"loggedIn":true,"authMethod":"claude.ai"}'; exit 0
fi
if ! mkdir "$L/lock" 2>/dev/null; then echo overlap >> "$L/overlap"; fi
n=$(ls "$L" | grep -c '^args-'); n=$((n+1))
printf '%s\0' "$@" > "$L/args-$n"
env > "$L/env-$n"
readlink /proc/$$/fd/0 > "$L/stdin-$n" 2>/dev/null
cfg=""; prev=""; prompt=""
for a in "$@"; do [ "$prev" = "--mcp-config" ] && cfg="$a"; [ "$prev" = "-p" ] && prompt="$a"; prev="$a"; done
[ -n "$cfg" ] && cp "$cfg" "$L/mcp-$n.json"
case "$FAKE_CC_MODE" in
  success)
    rid=$(printf '%s' "$prompt" | grep -o 'request_id cq[0-9a-f]*' | head -1 | cut -d' ' -f2)
    sleep "${FAKE_CC_SLEEP:-0}"
    curl -s -X POST "$FAKE_CC_URL/api/mcp/seen" -H "X-KAA: $FAKE_CC_TOKEN" -H 'Content-Type: application/json' -d '{"tool":"create_ad_set"}' >/dev/null
    body="${FAKE_CC_BODY//__RID__/$rid}"
    curl -s -X POST "$FAKE_CC_URL/api/brain/x/create" -H "X-KAA: $FAKE_CC_TOKEN" -H "X-KAA-Account: $FAKE_CC_ACCT" -H 'Content-Type: application/json' -d "$body" > "$L/create-$n"
    sid=$(grep -o '"session":{"id":"[^"]*"' "$L/create-$n" | head -1 | sed 's/.*"id":"//; s/"$//')
    curl -s -X PUT "$FAKE_CC_URL/api/brain/x/create/$sid" -H "X-KAA: $FAKE_CC_TOKEN" -H "X-KAA-Account: $FAKE_CC_ACCT" -H 'Content-Type: application/json' -d '{"saved":["cr-ar-1"]}' >/dev/null
    echo '{"type":"result","subtype":"success","is_error":false,"result":"Saved 1 ad, 0 held back.","num_turns":6}'
    ;;
  usage) echo '{"type":"result","subtype":"success","is_error":true,"result":"Claude AI usage limit reached|1763700000","num_turns":1}'; rmdir "$L/lock"; exit 1 ;;
  authfail) echo 'Invalid API key · Please run /login' >&2; rmdir "$L/lock"; exit 1 ;;
  timeout) sleep 30 ;;
  empty) echo '{"type":"result","subtype":"success","is_error":false,"result":"I could not reach the tools.","num_turns":2}' ;;
esac
rmdir "$L/lock" 2>/dev/null
exit 0
`

type ccEnvT struct {
	ac  *Acct
	eb  *brain.Engine
	s   *server
	c   *ccRunner
	log string
	pid string
}

// ccSetup: the mock app with the real brain, served on a real port (the fake CLI calls it like the connector does),
// one open app tab, the fake `claude` first on PATH, HOME empty, and an API key in the engine's own environment
// that must never reach the child.
func ccSetup(t *testing.T, mode string) *ccEnvT {
	t.Helper()
	ac, eb, _, s := cloudApp(t)
	s.token, s.data, s.subs = "tok-cc", t.TempDir(), map[chan []byte]bool{make(chan []byte, 256): true}
	srv := httptest.NewServer(s)
	t.Cleanup(srv.Close)
	s.addr = strings.TrimPrefix(srv.URL, "http://")
	bin, log := t.TempDir(), t.TempDir()
	if err := os.WriteFile(filepath.Join(bin, "claude"), []byte(fakeClaude), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+":/usr/bin:/bin")
	t.Setenv("KAA_CLAUDE_BIN", filepath.Join(bin, "claude"))
	t.Setenv("HOME", t.TempDir())
	t.Setenv("ANTHROPIC_API_KEY", "sk-ant-api03-MUST-NOT-REACH-THE-CHILD")
	t.Setenv("ANTHROPIC_AUTH_TOKEN", "also-not")
	t.Setenv("FAKE_CC_MODE", mode)
	t.Setenv("FAKE_CC_LOG", log)
	t.Setenv("FAKE_CC_URL", srv.URL)
	t.Setenv("FAKE_CC_TOKEN", s.token)
	t.Setenv("FAKE_CC_ACCT", ac.id)
	p := stockProduct(t, ac.app)
	body, _ := json.Marshal(map[string]any{"request_id": "__RID__", "key": "cc-test-__RID__", "authored": []any{map[string]any{
		"product_ids": []string{p.ID}, "angle": "glow", "hook": "glass skin", "template": "glow", "formats": []string{"1:1"},
		"sets": []any{map[string]any{"lang": "ar", "headline": "إشراقة يومية لبشرتك", "primary_text": "روتين كوري لطيف.", "cta": "SHOP_NOW"},
			map[string]any{"lang": "en", "headline": "Your daily glow", "primary_text": "A gentle Korean routine.", "cta": "SHOP_NOW"}}}}})
	t.Setenv("FAKE_CC_BODY", string(body))
	c := newCCRunner(ac.app, "/opt/kaa/engine", s.data, s.tabs)
	c.debounce = 5 * time.Millisecond
	ac.app.mu.Lock()
	ac.app.cc = c
	ac.app.mu.Unlock()
	old := brain.CreationChanged
	t.Cleanup(func() { brain.CreationChanged = old })
	return &ccEnvT{ac: ac, eb: eb, s: s, c: c, log: log, pid: p.ID}
}

func (e *ccEnvT) raise(t *testing.T, angle string) brain.CreationRequest {
	return e.eb.AddCreationRequest(e.ac.id, e.ac.brainInput(), []string{e.pid}, angle)
}

func (e *ccEnvT) req(id string) brain.CreationRequest {
	for _, r := range e.eb.CreationRequests(e.ac.id) {
		if r.ID == id {
			return r
		}
	}
	return brain.CreationRequest{}
}

func (e *ccEnvT) runs() int {
	m, _ := filepath.Glob(filepath.Join(e.log, "args-*"))
	return len(m)
}

func (e *ccEnvT) file(t *testing.T, name string) string {
	b, err := os.ReadFile(filepath.Join(e.log, name))
	if err != nil {
		t.Fatalf("%s: %v", name, err)
	}
	return string(b)
}

// waitIdle: the runner finished every scan it was kicked into.
func (e *ccEnvT) waitIdle(t *testing.T, d time.Duration) {
	t.Helper()
	deadline := time.Now().Add(d)
	time.Sleep(50 * time.Millisecond)
	for time.Now().Before(deadline) {
		e.c.mu.Lock()
		idle := !e.c.busy && !e.c.again
		e.c.mu.Unlock()
		if idle {
			time.Sleep(30 * time.Millisecond)
			e.c.mu.Lock()
			idle = !e.c.busy
			e.c.mu.Unlock()
			if idle {
				return
			}
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("the runner never went idle")
}

// The owner's ask, end to end: a request is raised, Claude Code runs headless on this Mac with no Enter pressed, the
// ads come back through the connector's own routes and the request is DONE, its card says so, Activity logs the run.
// The child's command line is exactly the locked-down one (only the KBB tools, no built-ins, strict MCP, dontAsk,
// JSON, a turn cap), stdin is closed and its environment carries NO ANTHROPIC_API_KEY / AUTH_TOKEN (they outrank the
// Max login and would bill credits). The defect this guards: every request waited for the owner's Enter.
// MUTATION: drop the ccDropEnv filter → the key is in env-1, red; add "Bash" to ccTools → allowedTools, red.
func TestClaudeCodeAutoRunMakesTheRequest(t *testing.T) {
	e := ccSetup(t, "success")
	if d := e.c.detect(context.Background()); !d.Ready || d.Version != "2.1.330 (Claude Code)" || d.AuthMethod != "claude.ai" {
		t.Fatalf("detect %+v", d)
	}
	if !e.c.autoOn() {
		t.Fatal("auto is not ON by default with the CLI found and signed in")
	}
	r := e.raise(t, "glow")
	e.c.changed(e.ac.id)
	e.waitIdle(t, 20*time.Second)
	got := e.req(r.ID)
	if got.Status != "done" || got.Local != "done" || got.LocalN != 1 {
		t.Fatalf("request after the run: status %s local %s (%s) n %d; create said %s", got.Status, got.Local, got.LocalNote, got.LocalN, e.file(t, "create-1"))
	}
	if e.runs() != 1 {
		t.Fatalf("%d runs", e.runs())
	}
	args := strings.Split(strings.TrimSuffix(e.file(t, "args-1"), "\x00"), "\x00")
	want := map[string]string{"--allowedTools": strings.Join(ccAllowed(), ","), "--permission-mode": "dontAsk", "--output-format": "json", "--max-turns": "30", "--tools": ""}
	for i, a := range args {
		if v, ok := want[a]; ok {
			if i+1 >= len(args) && v != "" || i+1 < len(args) && args[i+1] != v {
				t.Fatalf("%s = %q, want %q", a, args[i+1], v)
			}
			delete(want, a)
		}
	}
	if len(want) > 0 || !contains(args, "--strict-mcp-config") || !contains(args, "--no-session-persistence") || args[0] != "-p" {
		t.Fatalf("missing flags %v in %q", want, args)
	}
	for _, tool := range strings.Split(strings.Join(ccAllowed(), ","), ",") {
		if !strings.HasPrefix(tool, "mcp__kbb__") {
			t.Fatalf("a non-KBB tool is allowed: %s", tool)
		}
	}
	if len(ccAllowed()) != 7 {
		t.Fatalf("allowed %v", ccAllowed())
	}
	if !strings.Contains(args[1], r.Brief) || !strings.Contains(args[1], "Nobody will answer") || strings.Contains(args[1], "Do NOT call run_it") == ccEnvApprove(e.ac) {
		t.Fatalf("prompt %q", args[1])
	}
	env := e.file(t, "env-1")
	if strings.Contains(env, "ANTHROPIC_API_KEY") || strings.Contains(env, "MUST-NOT-REACH") || strings.Contains(env, "ANTHROPIC_AUTH_TOKEN") {
		t.Fatal("an API credential reached Claude Code's environment")
	}
	if in := strings.TrimSpace(e.file(t, "stdin-1")); in != "/dev/null" {
		t.Fatalf("stdin %q: a run must never wait for a key", in)
	}
	var cfg struct {
		MCPServers map[string]struct {
			Command string            `json:"command"`
			Args    []string          `json:"args"`
			Env     map[string]string `json:"env"`
		} `json:"mcpServers"`
	}
	if err := json.Unmarshal([]byte(e.file(t, "mcp-1.json")), &cfg); err != nil || len(cfg.MCPServers) != 1 {
		t.Fatalf("mcp config %v %+v", err, cfg)
	}
	k := cfg.MCPServers["kbb"]
	if k.Command != "/opt/kaa/engine" || strings.Join(k.Args, " ") != "-mcp -data "+e.s.data || k.Env["KAA_MCP_NO_LAUNCH"] != "1" || k.Env["KAA_MCP_ACCOUNT"] != e.ac.id {
		t.Fatalf("connector entry %+v", k)
	}
	if _, err := os.Stat(filepath.Join(e.s.data, "claudecode", "run-"+r.ID)); !os.IsNotExist(err) {
		t.Fatal("the run's folder (its MCP config) was left behind")
	}
	v := e.eb.CreationRequests(e.ac.id)
	_ = v
	found := false
	for _, l := range e.ac.st.Recent(50) {
		if strings.Contains(l.Summary, "Claude Code on this Mac (Max): 1 ad(s)") {
			found = true
		}
	}
	if !found {
		t.Fatal("Activity has no line for the run")
	}
	vw := e.c.view()
	if runs := vw["runs"].([]ccRun); len(runs) != 1 || runs[0].State != "done" || runs[0].Ads != 1 || runs[0].Seconds <= 0 {
		t.Fatalf("runs %+v", vw["runs"])
	}
	// the live line: the connector named its tool while the run was on
	if !strings.Contains(e.file(t, "create-1"), `"done"`) {
		t.Fatalf("create %s", e.file(t, "create-1"))
	}
}

func ccEnvApprove(ac *Acct) bool {
	st := ac.Settings()
	return st.Limits.Autopilot == model.AutopilotAuto && st.Limits.Set()
}

// A usage limit pauses every automatic run for an hour (no tight loop); the card says why; Start stays possible.
// MUTATION: drop the pausedUntil line → the second request runs at once, red.
func TestClaudeCodeUsageLimitPauses(t *testing.T) {
	e := ccSetup(t, "usage")
	e.c.detect(context.Background())
	r1 := e.raise(t, "glow")
	e.c.changed(e.ac.id)
	e.waitIdle(t, 10*time.Second)
	got := e.req(r1.ID)
	if got.Status != "open" || got.Local != "failed" || !strings.Contains(got.LocalNote, "usage limit") {
		t.Fatalf("after a usage limit: %s %s %q", got.Status, got.Local, got.LocalNote)
	}
	r2 := e.raise(t, "hydration")
	e.c.changed(e.ac.id)
	e.c.kick("again")
	e.waitIdle(t, 5*time.Second)
	if e.runs() != 1 {
		t.Fatalf("%d runs during the pause", e.runs())
	}
	if v := e.c.view(); v["paused_until"] == nil {
		t.Fatal("no pause shown")
	}
	if e.req(r2.ID).Status != "open" {
		t.Fatal("the second request was touched")
	}
}

// Not signed in: found but `auth status` exits 1 → not ready, auto OFF, nothing runs, the problem names /login.
// A run that is refused with an auth error mid-way says the same and stops further runs.
func TestClaudeCodeNotSignedIn(t *testing.T) {
	e := ccSetup(t, "success")
	t.Setenv("FAKE_CC_AUTH", "out")
	d := e.c.detect(context.Background())
	if !d.Found || d.SignedIn || d.Ready || !strings.Contains(d.Problem, "/login") || e.c.autoOn() {
		t.Fatalf("detect %+v auto %v", d, e.c.autoOn())
	}
	r0 := e.raise(t, "glow")
	e.c.changed(e.ac.id)
	e.waitIdle(t, 5*time.Second)
	if e.runs() != 0 {
		t.Fatal("ran while not signed in")
	}
	// signed in with an API key = credits: refused too
	t.Setenv("FAKE_CC_AUTH", "apikey")
	if d := e.c.detect(context.Background()); d.Ready || !strings.Contains(d.Problem, "API key") {
		t.Fatalf("api-key login accepted: %+v", d)
	}
	// signed in, but the run itself says the login is gone
	t.Setenv("FAKE_CC_AUTH", "")
	t.Setenv("FAKE_CC_MODE", "authfail")
	e.c.detect(context.Background())
	e.raise(t, "hydration")
	e.c.changed(e.ac.id)
	e.waitIdle(t, 10*time.Second)
	if got := e.req(r0.ID); got.Local != "failed" || !strings.Contains(got.LocalNote, "not signed in") || e.c.detected().Ready {
		t.Fatalf("auth refusal: %s %q ready %v", got.Local, got.LocalNote, e.c.detected().Ready)
	}
	if e.runs() != 1 {
		t.Fatalf("%d runs (one, then stop)", e.runs())
	}
}

// A run that never answers is stopped at the timeout (with the connector it started) and says so; the request stays
// open for Start creating / the cloud and is not retried at once. MUTATION: drop the context timeout → hangs, red.
func TestClaudeCodeTimeout(t *testing.T) {
	e := ccSetup(t, "timeout")
	e.c.timeout = 1 * time.Second
	e.c.detect(context.Background())
	r := e.raise(t, "glow")
	t0 := time.Now()
	e.c.changed(e.ac.id)
	e.waitIdle(t, 15*time.Second)
	if el := time.Since(t0); el > 9*time.Second {
		t.Fatalf("took %s: the child was not stopped", el)
	}
	got := e.req(r.ID)
	if got.Status != "open" || got.Local != "failed" || !strings.Contains(got.LocalNote, "did not finish") {
		t.Fatalf("timeout: %s %s %q", got.Status, got.Local, got.LocalNote)
	}
	e.c.kick("again")
	e.waitIdle(t, 3*time.Second)
	if e.runs() != 1 {
		t.Fatalf("retried at once: %d runs", e.runs())
	}
	e.c.mu.Lock()
	next := e.c.nextTry[r.ID]
	e.c.mu.Unlock()
	if time.Until(next) < 10*time.Minute {
		t.Fatalf("backoff %s", time.Until(next))
	}
}

// Missing CLI: nothing on PATH or in the install folders → not found, auto off, the card offers the install steps.
func TestClaudeCodeMissing(t *testing.T) {
	e := ccSetup(t, "success")
	t.Setenv("KAA_CLAUDE_BIN", filepath.Join(t.TempDir(), "claude"))
	d := e.c.detect(context.Background())
	if d.Found || d.Ready || !strings.Contains(d.Problem, "not installed") || e.c.autoOn() {
		t.Fatalf("detect %+v", d)
	}
	e.raise(t, "glow")
	e.c.changed(e.ac.id)
	e.waitIdle(t, 3*time.Second)
	if e.runs() != 0 {
		t.Fatal("ran without a CLI")
	}
	e.s.addr, e.s.token = "127.0.0.1:1", "t"
	code, m := call(t, e.s, "GET", "/api/claudecode", "", nil)
	if code != 200 || m["auto"] != false || m["detect"].(map[string]any)["found"] != false {
		t.Fatalf("GET %d %v", code, m)
	}
}

// One run at a time: three requests and a burst of kicks → three runs, never two at once.
// MUTATION: drop the busy check in scan → "overlap" is written, red.
func TestClaudeCodeOneAtATime(t *testing.T) {
	e := ccSetup(t, "success")
	t.Setenv("FAKE_CC_SLEEP", "0.4")
	e.c.detect(context.Background())
	var ids []string
	for _, a := range []string{"glow", "hydration", "barrier"} {
		ids = append(ids, e.raise(t, a).ID)
	}
	for i := 0; i < 6; i++ {
		go e.c.scan()
		e.c.kick("burst")
	}
	e.waitIdle(t, 30*time.Second)
	if _, err := os.Stat(filepath.Join(e.log, "overlap")); err == nil {
		t.Fatal("two Claude Code runs at once")
	}
	if e.runs() != 3 {
		t.Fatalf("%d runs for 3 requests", e.runs())
	}
	for _, id := range ids {
		if e.req(id).Status != "done" {
			t.Fatalf("%s not done", id)
		}
	}
}

// The cloud and this Mac never make the same request twice: a request already being made in the cloud is not run
// here; one made here has its cloud job withdrawn; one queued here is not pushed to the cloud.
// MUTATION: drop the Cloud checks in ccEligible → runs while the cloud works, red.
func TestClaudeCodeAndTheCloud(t *testing.T) {
	e := ccSetup(t, "success")
	connect(t, e.s)
	e.c.detect(context.Background())
	// 1. auto off: the request goes to the cloud as before
	off := false
	e.c.cfg.Auto = &off
	r := e.raise(t, "glow")
	if _, err := e.ac.app.exchangeSync(context.Background(), "test"); err != nil {
		t.Fatal(err)
	}
	if e.req(r.ID).Cloud != "queued" {
		t.Fatalf("not pushed: %q", e.req(r.ID).Cloud)
	}
	// 2. the cloud is working on it: not run here
	e.eb.SetCloud(e.ac.id, r.ID, "working", "cloud-1")
	on := true
	e.c.cfg.Auto = &on
	e.c.changed(e.ac.id)
	e.waitIdle(t, 5*time.Second)
	if e.runs() != 0 {
		t.Fatal("ran here while the cloud was making it")
	}
	// 3. back in the queue: made here, and the cloud job is withdrawn at the next sync
	e.eb.SetCloud(e.ac.id, r.ID, "queued", "")
	e.c.kick("t")
	e.waitIdle(t, 20*time.Second)
	if e.req(r.ID).Status != "done" {
		t.Fatalf("not made here: %+v", e.req(r.ID))
	}
	sum, err := e.ac.app.exchangeSync(context.Background(), "test")
	if err != nil || sum["withdrawn"].(int) != 1 || e.req(r.ID).Cloud != "withdrawn" {
		t.Fatalf("withdraw: %v %v cloud %q", sum, err, e.req(r.ID).Cloud)
	}
	// 4. a request queued for this Mac is not pushed to the cloud
	r2 := e.raise(t, "hydration")
	e.c.markQueued(e.ac.id)
	if e.req(r2.ID).Local != "queued" {
		t.Fatalf("not queued: %q", e.req(r2.ID).Local)
	}
	if sum, _ := e.ac.app.exchangeSync(context.Background(), "test"); sum["pushed"].(int) != 0 {
		t.Fatalf("pushed a request this Mac has: %v", sum)
	}
}

// A run that ends without making anything (the tools never called) is a failure with Claude's own words, never "done".
func TestClaudeCodeNoAdsIsAFailure(t *testing.T) {
	e := ccSetup(t, "empty")
	e.c.detect(context.Background())
	r := e.raise(t, "glow")
	e.c.changed(e.ac.id)
	e.waitIdle(t, 10*time.Second)
	if got := e.req(r.ID); got.Status != "open" || got.Local != "failed" || !strings.Contains(got.LocalNote, "could not reach the tools") {
		t.Fatalf("%s %s %q", got.Status, got.Local, got.LocalNote)
	}
}

// Settings: PUT auto off sends the queued cards back to Start creating; Test runs the tiny prompt and says ok.
func TestClaudeCodeSettingsAPI(t *testing.T) {
	e := ccSetup(t, "empty")
	e.c.detect(context.Background())
	r := e.raise(t, "glow")
	e.c.markQueued(e.ac.id)
	e.s.addr, e.s.token = "127.0.0.1:1", "t"
	code, m := call(t, e.s, "PUT", "/api/claudecode", "", map[string]any{"auto": false})
	if code != 200 || m["auto"] != false || e.req(r.ID).Local != "" {
		t.Fatalf("off: %d %v local %q", code, m, e.req(r.ID).Local)
	}
	code, m = call(t, e.s, "POST", "/api/claudecode/test", "", map[string]any{})
	if code != 200 || m["ok"] != true || m["reply"] == nil {
		t.Fatalf("test: %d %v", code, m)
	}
	if code, _ := call(t, e.s, "PUT", "/api/claudecode", "", map[string]any{}); code != 400 {
		t.Fatal("an empty PUT was accepted")
	}
}

// The owner (21 Nov): "X in N days — not approved yet" stayed after he approved the package. Approving an occasion
// package now closes its notices and its Overview alerts at once; any notice can be closed with ✕.
// MUTATION: drop the occasionSettled defer in jobs.go → the notices stay, red.
func TestOccasionNoticesClearOnApproval(t *testing.T) {
	ac, eb, _, _ := cloudApp(t)
	j := Job{ID: "occ-nd", Kind: "occasion", Status: "planned", Title: "UAE National Day in 3 days — ads ready for your approval", When: ac.app.now().Add(48 * time.Hour),
		Occasion: &model.OccasionInstance{Name: "UAE National Day", NameAR: "اليوم الوطني"}}
	js := ac.jobSet()
	js.Jobs = append(js.Jobs, j)
	ac.saveJobs(js)
	ac.notifyOwnerRef(j.ID, j.Title, "starts soon", "قريباً")
	ac.notifyOwnerRef(j.ID, "UAE National Day in 3 days — not approved yet · اليوم الوطني", "Approvals → Occasions", "الموافقات")
	eb.Notify(ac.id, "Something else", "keep me", "ابقَ")
	shown := func() []brain.Notice {
		var ns []brain.Notice
		b, _ := json.Marshal(eb.CreationRequests(ac.id)) // touch
		_ = b
		v := creationNotices(t, eb, ac)
		ns = append(ns, v...)
		return ns
	}
	if n := len(shown()); n != 3 {
		t.Fatalf("%d notices before", n)
	}
	s := testServer(ac)
	code, out := call(t, s, "POST", "/api/jobs/"+j.ID+"/approve", ac.id, map[string]any{})
	if code != 200 {
		t.Fatalf("approve %d %v", code, out)
	}
	ns := shown()
	if len(ns) != 1 || ns[0].Title != "Something else" {
		t.Fatalf("after approval: %+v", ns)
	}
	ac.mu.RLock()
	for _, al := range ac.alerts {
		if strings.Contains(al.Text, "UAE National Day") {
			t.Fatalf("alert stayed: %s", al.Text)
		}
	}
	ac.mu.RUnlock()
	// ✕ on the last one
	code, _ = call(t, s, "POST", "/api/brain/x/creation/notice/"+ns[0].ID+"/dismiss", ac.id, nil)
	if code != 200 || len(shown()) != 0 {
		t.Fatalf("dismiss %d, %d left", code, len(shown()))
	}
	if code, _ := call(t, s, "POST", "/api/brain/x/creation/notice/nope/dismiss", ac.id, nil); code != 404 {
		t.Fatal("unknown notice accepted")
	}
}

func creationNotices(t *testing.T, eb *brain.Engine, ac *Acct) []brain.Notice {
	t.Helper()
	s := testServer(ac)
	_, m := call(t, s, "GET", "/api/brain/x/creation", ac.id, nil)
	b, _ := json.Marshal(m["notifications"])
	var ns []brain.Notice
	_ = json.Unmarshal(b, &ns)
	return ns
}

// The runner's allowed tools are EXACTLY the connector's tools (renaming one in mcp.go without the runner would make
// every auto-run refuse that tool under dontAsk), and the unattended prompt names the steps it must call.
// MUTATION: rename "save_to_library" in ccTools → red.
func TestClaudeCodeToolsMatchTheConnector(t *testing.T) {
	var names []string
	for _, x := range mcpTools() {
		names = append(names, x.(map[string]any)["name"].(string))
	}
	if strings.Join(names, ",") != strings.Join(ccTools, ",") {
		t.Fatalf("connector %v, runner %v", names, ccTools)
	}
	p := ccPrompt(brain.CreationRequest{ID: "cq1", Count: 2, Brief: "brief"}, true)
	for _, step := range []string{"get_brief", "create_ad_set ONCE with request_id cq1", "save_to_library with the session_id", "revise once", "run_it with the session_id and launch_now false"} {
		if !strings.Contains(p, step) {
			t.Fatalf("prompt lacks %q", step)
		}
	}
	if strings.Contains(ccPrompt(brain.CreationRequest{ID: "cq1"}, false), "Call run_it") {
		t.Fatal("run_it asked for outside Auto")
	}
}

// Where the CLI is found: PATH first, then the documented install folders (~/.local/bin from the native installer);
// nothing → "". A -mock engine never finds a real CLI (only KAA_CLAUDE_BIN), so a self-test can never spend the
// owner's Max usage. MUTATION: drop the mockURL check → the mock finds the PATH one, red.
func TestClaudeCodeFind(t *testing.T) {
	old := mockURL
	t.Cleanup(func() { mockURL = old })
	mockURL = ""
	t.Setenv("KAA_CLAUDE_BIN", "")
	home, onPath := t.TempDir(), t.TempDir()
	t.Setenv("HOME", home)
	t.Setenv("PATH", t.TempDir())
	if p := ccFind(); p != "" {
		t.Fatalf("found %q with nothing installed", p)
	}
	native := filepath.Join(home, ".local", "bin", "claude")
	os.MkdirAll(filepath.Dir(native), 0o755)
	os.WriteFile(native, []byte("#!/bin/sh\n"), 0o755)
	if p := ccFind(); p != native {
		t.Fatalf("native install: %q", p)
	}
	os.WriteFile(filepath.Join(onPath, "claude"), []byte("#!/bin/sh\n"), 0o755)
	t.Setenv("PATH", onPath)
	if p := ccFind(); p != filepath.Join(onPath, "claude") {
		t.Fatalf("PATH first: %q", p)
	}
	mockURL = "http://127.0.0.1:1"
	if p := ccFind(); p != "" {
		t.Fatalf("-mock found a real CLI: %q", p)
	}
}
