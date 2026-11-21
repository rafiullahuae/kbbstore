package main

// claudecode.go — "Auto-create with Claude Code (Max)": no Enter, the laptop on. The owner (Nov 2026): "i clicked on
// start, each time i need to press enter in the claude app? why don't the claude work auto when the laptop is on".
//
// When a creation request is raised ("With me in Claude (Max)" mode) and Settings → Connections → "Claude Code on this
// Mac (Max plan)" → "Create automatically" is ON (the default once the CLI is found and signed in), the engine runs
// Claude Code headless, one job at a time (code.claude.com/docs/en/headless, /cli-reference):
//
//	claude -p "<the request's brief + unattended steps>" --mcp-config <run dir>/mcp.json --strict-mcp-config
//	       --tools "" --allowedTools mcp__kbb__get_brief,…,mcp__kbb__run_it --permission-mode dontAsk
//	       --output-format json --max-turns 30 --no-session-persistence
//
// --strict-mcp-config + --tools "" + dontAsk: the only tools it has are this app's own connector (engine -mcp), the
// same one the Claude desktop app uses, so the same linter, quality gate and approval rules; no shell, no file edits,
// no web. stdin is closed, a run has a timeout (10 min), and the child's environment never carries ANTHROPIC_API_KEY
// (or any other credential that would outrank the Max login: docs "Authentication precedence"), so it never bills API
// credits. Sign-in is checked with `claude auth status` (exit 0 = signed in; authMethod) -- its credentials are never
// read here. A refused run (not signed in, usage limit, missing CLI, timeout) says why on the card and leaves the
// request for Start creating / the cloud: backoff per request, an hour's pause on a usage limit, never a tight loop.
// Local success marks the request done (create_ad_set with its request_id) and the cloud job is withdrawn; a request
// the cloud is already making or has made is not run here.
//
//	GET  /api/claudecode        detected / version / signed in / auto / the current run / recent runs
//	PUT  /api/claudecode        {auto: bool}
//	POST /api/claudecode/test   a tiny no-op prompt ("ok"), timed
//	POST /api/claudecode/run    {request?}: try now (clears that request's backoff)

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"kaa/brain"
	"kaa/model"
)

const ccServer = "kbb" // the connector's name in the run's own MCP config → tool names mcp__kbb__<tool>

var ccTools = []string{"get_brief", "list_products", "get_research", "create_ad_set", "revise", "save_to_library", "run_it"}

// ccAllowed: the ONLY tools a run may use without a prompt (and dontAsk refuses everything else).
func ccAllowed() []string {
	out := make([]string, len(ccTools))
	for i, t := range ccTools {
		out[i] = "mcp__" + ccServer + "__" + t
	}
	return out
}

// ccDropEnv: credentials that outrank the subscription login (docs: Authentication precedence) or route it elsewhere.
var ccDropEnv = map[string]bool{"ANTHROPIC_API_KEY": true, "ANTHROPIC_AUTH_TOKEN": true, "ANTHROPIC_BASE_URL": true,
	"CLAUDE_CODE_USE_BEDROCK": true, "CLAUDE_CODE_USE_VERTEX": true, "CLAUDE_CODE_USE_FOUNDRY": true,
	"ANTHROPIC_PROFILE": true, "ANTHROPIC_FEDERATION_RULE_ID": true, "ANTHROPIC_ORGANIZATION_ID": true}

func ccEnv(bin string) []string {
	var out []string
	path := ""
	for _, kv := range os.Environ() {
		k, v, _ := strings.Cut(kv, "=")
		if ccDropEnv[strings.ToUpper(k)] {
			continue
		}
		if k == "PATH" {
			path = v
			continue
		}
		out = append(out, kv)
	}
	// a Mac app started from Finder has a short PATH: the CLI's own folder and Homebrew's go first
	extra := []string{filepath.Dir(bin), "/opt/homebrew/bin", "/usr/local/bin", "/usr/bin", "/bin"}
	return append(out, "PATH="+strings.Join(append(extra, path), string(os.PathListSeparator)))
}

// ccFind: `claude` on PATH, then where the documented installers put it. KAA_CLAUDE_BIN names one (tests, -mock);
// a -mock engine never runs a real CLI (a real Max account must not be used by a self-test).
func ccFind() string {
	if p := os.Getenv("KAA_CLAUDE_BIN"); p != "" {
		if fi, err := os.Stat(p); err == nil && !fi.IsDir() && fi.Mode()&0o111 != 0 {
			return p
		}
		return ""
	}
	if mockURL != "" {
		return ""
	}
	if p, err := exec.LookPath("claude"); err == nil {
		return p
	}
	home, _ := os.UserHomeDir()
	for _, p := range []string{filepath.Join(home, ".local", "bin", "claude"), filepath.Join(home, ".claude", "local", "claude"),
		"/opt/homebrew/bin/claude", "/usr/local/bin/claude", filepath.Join(home, ".npm-global", "bin", "claude")} {
		if fi, err := os.Stat(p); err == nil && !fi.IsDir() && fi.Mode()&0o111 != 0 {
			return p
		}
	}
	return ""
}

type ccDetect struct {
	Found      bool      `json:"found"`
	Path       string    `json:"path,omitempty"`
	Version    string    `json:"version,omitempty"`
	SignedIn   bool      `json:"signed_in"`
	AuthMethod string    `json:"auth_method,omitempty"`
	Ready      bool      `json:"ready"`
	Problem    string    `json:"problem,omitempty"`
	ProblemAR  string    `json:"problem_ar,omitempty"`
	At         time.Time `json:"at"`
}

type ccRun struct {
	Account string    `json:"account"`
	Request string    `json:"request"`
	Title   string    `json:"title"`
	Start   time.Time `json:"start"`
	End     time.Time `json:"end,omitempty"`
	Seconds float64   `json:"seconds,omitempty"`
	State   string    `json:"state"` // working | done | failed
	Kind    string    `json:"kind,omitempty"`
	Reason  string    `json:"reason,omitempty"`
	Ads     int       `json:"ads"`
	Held    int       `json:"held"`
	Turns   int       `json:"turns,omitempty"`
	Tool    string    `json:"tool,omitempty"`
}

type ccCfg struct {
	Auto *bool `json:"auto,omitempty"` // nil = on when the CLI is found and signed in
}

type ccRunner struct {
	a        *App
	exe      string
	data     string
	tabs     func() int
	timeout  time.Duration
	maxTurns int
	debounce time.Duration

	mu          sync.Mutex
	cfg         ccCfg
	det         ccDetect
	busy        bool
	again       bool
	cur         *ccRun
	runs        []ccRun
	pausedUntil time.Time
	fails       map[string]int
	nextTry     map[string]time.Time
	timer       *time.Timer
}

func newCCRunner(a *App, exe, data string, tabs func() int) *ccRunner {
	c := &ccRunner{a: a, exe: exe, data: data, tabs: tabs, timeout: 10 * time.Minute, maxTurns: 30, debounce: 2 * time.Second,
		fails: map[string]int{}, nextTry: map[string]time.Time{}}
	_ = a.st.Load("claudecode", &c.cfg)
	var runs []ccRun
	_ = a.st.Load("claudecode_runs", &runs)
	c.runs = runs
	return c
}

func (c *ccRunner) autoOn() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.autoLocked()
}

func (c *ccRunner) autoLocked() bool {
	if c.cfg.Auto != nil {
		return *c.cfg.Auto && c.det.Ready
	}
	return c.det.Ready
}

func ccCmd(ctx context.Context, bin, dir string, args ...string) *exec.Cmd {
	cmd := exec.CommandContext(ctx, bin, args...)
	cmd.Env, cmd.Dir, cmd.Stdin = ccEnv(bin), dir, nil // stdin closed: it never waits for a key
	ccGroup(cmd)
	cmd.WaitDelay = 5 * time.Second
	return cmd
}

// detect: found? version? signed in with the subscription? (`claude auth status`: exit 0 when logged in.)
func (c *ccRunner) detect(ctx context.Context) ccDetect {
	d := ccDetect{At: time.Now().UTC()}
	d.Path = ccFind()
	if d.Path == "" {
		d.Problem = "Claude Code is not installed on this Mac."
		d.ProblemAR = "Claude Code غير مثبّت على هذا الجهاز."
		return c.setDet(d)
	}
	d.Found = true
	tmp := os.TempDir()
	vctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	out, _ := ccCmd(vctx, d.Path, tmp, "--version").Output()
	cancel()
	d.Version = strings.TrimSpace(strings.SplitN(string(out), "\n", 2)[0])
	actx, cancel2 := context.WithTimeout(ctx, 20*time.Second)
	cmd := ccCmd(actx, d.Path, tmp, "auth", "status")
	var so bytes.Buffer
	cmd.Stdout = &so
	err := cmd.Run()
	cancel2()
	var st struct {
		LoggedIn   *bool  `json:"loggedIn"`
		AuthMethod string `json:"authMethod"`
	}
	_ = json.Unmarshal(bytes.TrimSpace(so.Bytes()), &st)
	d.AuthMethod = st.AuthMethod
	d.SignedIn = err == nil && st.AuthMethod != "none" && (st.LoggedIn == nil || *st.LoggedIn)
	switch {
	case !d.SignedIn:
		d.Problem = "Not signed in: open Terminal, type claude, then /login with your Max account."
		d.ProblemAR = "لم يُسجَّل الدخول: افتحي Terminal واكتبي claude ثم ‎/login‎ بحساب Max."
	case st.AuthMethod == "api_key" || st.AuthMethod == "api_key_helper":
		d.Problem = "Claude Code is signed in with an API key, which bills credits. In Terminal: claude, /logout, then /login with your Max account."
		d.ProblemAR = "Claude Code مسجَّل بمفتاح API (برصيد). في Terminal: claude ثم ‎/logout‎ ثم ‎/login‎ بحساب Max."
	default:
		d.Ready = true
	}
	return c.setDet(d)
}

func (c *ccRunner) setDet(d ccDetect) ccDetect {
	c.mu.Lock()
	c.det = d
	c.mu.Unlock()
	c.publish()
	return d
}

func (c *ccRunner) detected() ccDetect {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.det
}

// kick: look for work in a moment (several kicks are one scan).
func (c *ccRunner) kick(reason string) {
	_ = reason
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.timer != nil {
		c.timer.Stop()
	}
	c.timer = time.AfterFunc(c.debounce, c.scan)
}

// changed: a request appeared or closed (brain.CreationChanged). It is marked "queued" at once so the cloud exchange,
// which syncs a few seconds later, leaves it to this Mac.
func (c *ccRunner) changed(acct string) {
	if c.autoOn() {
		c.markQueued(acct)
	}
	c.kick("request")
}

func ccEligible(r brain.CreationRequest) bool {
	return r.Status == "open" && r.Cloud != "working" && r.Cloud != "finished" && r.Cloud != "imported" && r.Local != "working" && r.Local != "done"
}

func (c *ccRunner) markQueued(acct string) {
	eb := c.a.brainEngine()
	if eb == nil || !eb.CreationWithMe(acct) {
		return
	}
	now := time.Now()
	for _, r := range eb.CreationRequests(acct) {
		c.mu.Lock()
		wait := now.Before(c.nextTry[r.ID]) || c.fails[r.ID] >= 3
		c.mu.Unlock()
		if ccEligible(r) && r.Local == "" && !wait {
			eb.SetLocal(acct, r.ID, "queued", "Claude Code on this Mac makes these in a moment.", 0)
		}
	}
}

// next: the oldest request to make now ("" when none).
func (c *ccRunner) next() (*Acct, brain.CreationRequest, bool) {
	eb := c.a.brainEngine()
	if eb == nil {
		return nil, brain.CreationRequest{}, false
	}
	now := time.Now()
	for _, ac := range c.a.Accounts() {
		if !eb.CreationWithMe(ac.id) {
			continue
		}
		reqs := eb.CreationRequests(ac.id)
		for i := len(reqs) - 1; i >= 0; i-- {
			r := reqs[i]
			c.mu.Lock()
			wait := now.Before(c.nextTry[r.ID]) || c.fails[r.ID] >= 3
			c.mu.Unlock()
			if ccEligible(r) && !wait {
				return ac, r, true
			}
		}
	}
	return nil, brain.CreationRequest{}, false
}

// scan: one job at a time; when it ends, the next eligible request.
func (c *ccRunner) scan() {
	c.mu.Lock()
	if c.busy {
		c.again = true
		c.mu.Unlock()
		return
	}
	c.busy = true
	c.mu.Unlock()
	defer func() {
		c.mu.Lock()
		c.busy = false
		again := c.again
		c.again = false
		c.mu.Unlock()
		if again {
			c.kick("again")
		}
	}()
	for {
		c.mu.Lock()
		ok := c.autoLocked() && time.Now().After(c.pausedUntil)
		c.mu.Unlock()
		if !ok {
			return
		}
		ac, r, found := c.next()
		if !found {
			return
		}
		if c.tabs != nil && c.tabs() == 0 { // the app's tab draws the pictures
			if eb := c.a.brainEngine(); eb != nil {
				eb.SetLocal(ac.id, r.ID, "queued", "Waiting for the app window: Claude Code starts as soon as KBB Ads Autopilot is open.", 0)
			}
			return
		}
		c.run(ac, r)
	}
}

var (
	ccUsageRe = regexp.MustCompile(`(?i)usage limit|limit reached|hit your (usage )?limit|out of (extra )?usage|(5-hour|weekly|session) limit|rate.?limit`)
	ccAuthRe  = regexp.MustCompile(`(?i)not logged in|please run /login|run /login|invalid api key|login expired|oauth token (has )?expired|authentication (failed|error)|unauthori[sz]ed`)
)

func ccName(r brain.CreationRequest) string {
	if strings.HasSuffix(r.Title, " — start creating") {
		return strings.TrimSuffix(r.Angle, " ads")
	}
	return firstNonEmptyStr(r.Product, r.Angle)
}

// ccPrompt: the Start button's brief, then the unattended steps (nobody is there to answer).
func ccPrompt(r brain.CreationRequest, approve bool) string {
	var b strings.Builder
	b.WriteString(r.Brief)
	b.WriteString("\n\nYou are running unattended on the owner's Mac (Claude Code on his Max plan). Nobody will answer questions, so do not ask any and do not wait for him. Use only the KBB Ads Autopilot tools. Steps:\n")
	b.WriteString("1. Call get_brief and follow its brand rules and the owner's instructions.\n")
	fmt.Fprintf(&b, "2. Call create_ad_set ONCE with request_id %s and %d ideas, Arabic first and English.\n", r.ID, max(1, min(5, r.Count)))
	b.WriteString("3. Call save_to_library with the session_id. If it holds any back (quality check), call revise once on those ideas fixing exactly what it said, then save_to_library again.\n")
	if approve {
		b.WriteString("4. Call run_it with the session_id and launch_now false (the autopilot's limits and mode apply). Never launch_now.\n")
	} else {
		b.WriteString("4. Do NOT call run_it: the owner approves these himself in Approvals.\n")
	}
	b.WriteString("Finish with one short line: how many ads were saved and how many were held back.")
	return b.String()
}

func (c *ccRunner) mcpConfig(acct string) []byte {
	exe := c.exe
	if exe == "" {
		exe, _ = os.Executable()
	}
	b, _ := json.Marshal(map[string]any{"mcpServers": map[string]any{ccServer: map[string]any{"type": "stdio", "command": exe,
		"args": []string{"-mcp", "-data", c.data}, "env": map[string]string{"KAA_MCP_NO_LAUNCH": "1", "KAA_MCP_ACCOUNT": acct}}}})
	return b
}

// ccArgs: the whole command line of an auto-run (tests read it back from the fake CLI).
func ccArgs(prompt, cfgPath string, maxTurns int) []string {
	return []string{"-p", prompt, "--mcp-config", cfgPath, "--strict-mcp-config", "--tools", "",
		"--allowedTools", strings.Join(ccAllowed(), ","), "--permission-mode", "dontAsk",
		"--output-format", "json", "--max-turns", fmt.Sprint(maxTurns), "--no-session-persistence"}
}

type ccResult struct {
	Type    string `json:"type"`
	Subtype string `json:"subtype"`
	IsError bool   `json:"is_error"`
	Result  string `json:"result"`
	Turns   int    `json:"num_turns"`
}

func (c *ccRunner) run(ac *Acct, r brain.CreationRequest) {
	eb := c.a.brainEngine()
	det := c.detected()
	name := ccName(r)
	run := ccRun{Account: ac.id, Request: r.ID, Title: r.Title, Start: time.Now().UTC(), State: "working"}
	c.mu.Lock()
	c.cur = &run
	c.mu.Unlock()
	eb.SetLocal(ac.id, r.ID, "working", fmt.Sprintf("Claude Code is making %d ads for %s…", r.Count, name), 0)
	c.publish()

	st := ac.Settings()
	approve := st.Limits.Autopilot == model.AutopilotAuto && st.Limits.Set()
	dir := filepath.Join(c.data, "claudecode", "run-"+r.ID)
	_ = os.MkdirAll(dir, 0o700)
	defer os.RemoveAll(dir)
	cfgPath := filepath.Join(dir, "mcp.json")
	_ = os.WriteFile(cfgPath, c.mcpConfig(ac.id), 0o600)
	ctx, cancel := context.WithTimeout(context.Background(), c.timeout)
	defer cancel()
	cmd := ccCmd(ctx, det.Path, dir, ccArgs(ccPrompt(r, approve), cfgPath, c.maxTurns)...)
	var so, se bytes.Buffer
	cmd.Stdout, cmd.Stderr = &so, &se
	err := cmd.Run()
	run.End = time.Now().UTC()
	run.Seconds = float64(run.End.Sub(run.Start).Milliseconds()) / 1000
	var res ccResult
	_ = json.Unmarshal(bytes.TrimSpace(so.Bytes()), &res)
	run.Turns = res.Turns
	said := strings.TrimSpace(res.Result + "\n" + se.String())
	if res.Result == "" {
		said = strings.TrimSpace(so.String() + "\n" + se.String())
	}

	// what actually happened: the request answered through the connector is the only success
	var req *brain.CreationRequest
	for _, x := range eb.CreationRequests(ac.id) {
		if x.ID == r.ID {
			x := x
			req = &x
		}
	}
	sess := eb.SessionByRequest(ac.id, r.ID)
	switch {
	case req != nil && req.Status == "done" && sess != nil && sess.Source != "cloud_max":
		run.State, run.Kind, run.Ads = "done", "ok", len(sess.Saved)
		if d := len(sess.Designs) - len(sess.Saved); d > 0 && len(sess.Saved) > 0 {
			run.Held = d
		}
		if len(sess.Saved) == 0 {
			run.Held = len(sess.Designs)
		}
	case req != nil && req.Status != "open":
		run.State, run.Kind, run.Reason = "failed", "closed", "The request was closed elsewhere meanwhile ("+req.Status+")."
	case errors.Is(ctx.Err(), context.DeadlineExceeded):
		run.State, run.Kind, run.Reason = "failed", "timeout", fmt.Sprintf("Claude Code did not finish within %d minutes.", int(c.timeout.Minutes()+0.5))
	case err != nil && (errors.Is(err, exec.ErrNotFound) || errors.Is(err, os.ErrNotExist) || det.Path == ""):
		run.State, run.Kind, run.Reason = "failed", "missing", "Claude Code is not installed on this Mac (Settings → Connections → Claude Code on this Mac)."
	case ccUsageRe.MatchString(said):
		run.State, run.Kind, run.Reason = "failed", "usage_limit", "Your Claude usage limit is reached: automatic runs pause for an hour. Start creating or the cloud can still make it."
	case ccAuthRe.MatchString(said):
		run.State, run.Kind, run.Reason = "failed", "not_signed_in", "Claude Code is not signed in: open Terminal, type claude, then /login with your Max account."
	default:
		why := truncStr(oneLine(said), 220)
		if why == "" && err != nil {
			why = err.Error()
		}
		run.State, run.Kind, run.Reason = "failed", "no_ads", "Claude Code finished without making the ads"+map[bool]string{true: ": " + why, false: "."}[why != ""]
	}

	c.mu.Lock()
	c.cur = nil
	if run.State == "done" {
		delete(c.fails, r.ID)
		delete(c.nextTry, r.ID)
	} else if run.Kind != "closed" {
		c.fails[r.ID]++
		c.nextTry[r.ID] = time.Now().Add(15 * time.Minute << (c.fails[r.ID] - 1))
		switch run.Kind {
		case "usage_limit":
			c.pausedUntil = time.Now().Add(time.Hour)
		case "not_signed_in":
			c.det.Ready, c.det.SignedIn, c.det.Problem = false, false, run.Reason
		case "missing":
			c.det.Ready, c.det.Found, c.det.Problem = false, false, run.Reason
		}
	}
	c.runs = append([]ccRun{run}, c.runs...)
	if len(c.runs) > 30 {
		c.runs = c.runs[:30]
	}
	runs := append([]ccRun(nil), c.runs...)
	c.mu.Unlock()
	_ = c.a.st.Save("claudecode_runs", runs)

	switch run.State {
	case "done":
		eb.SetLocal(ac.id, r.ID, "done", fmt.Sprintf("Made by Claude Code on this Mac: %d ads saved.", run.Ads), run.Ads)
		eb.LocalDone(ac.id, r.ID, r.Title, r.TitleAR, run.Ads, run.Held)
		ac.st.Append("creative", fmt.Sprintf("Claude Code on this Mac (Max): %d ad(s) for %s in %.0f s, %d held back by the quality check", run.Ads, name, run.Seconds, run.Held), run)
		if c.a.exRemote() != nil {
			c.a.exchangeSoon() // the cloud job for it is withdrawn
		}
	default:
		if run.Kind != "closed" {
			eb.SetLocal(ac.id, r.ID, "failed", run.Reason, 0)
		} else {
			eb.SetLocal(ac.id, r.ID, "", "", 0)
		}
		ac.st.Append("alert", fmt.Sprintf("Claude Code on this Mac could not make %s (%.0f s): %s", name, run.Seconds, run.Reason), run)
	}
	c.publish()
}

func oneLine(s string) string { return strings.Join(strings.Fields(s), " ") }

func truncStr(s string, n int) string {
	if r := []rune(s); len(r) > n {
		return string(r[:n]) + "…"
	}
	return s
}

// toolSeen: the connector named the tool it is running (POST /api/mcp/seen): the card's live line.
func (c *ccRunner) toolSeen(tool string) {
	c.mu.Lock()
	cur := c.cur
	if cur != nil {
		cur.Tool = tool
	}
	c.mu.Unlock()
	if cur == nil {
		return
	}
	step := map[string]string{"get_brief": "reading the brief", "list_products": "looking at the products", "get_research": "reading the research",
		"create_ad_set": "writing and drawing the ads", "revise": "fixing what the quality check said", "save_to_library": "saving to the library (quality check)",
		"run_it": "handing them to the autopilot"}[tool]
	if step == "" {
		return
	}
	if eb := c.a.brainEngine(); eb != nil {
		eb.SetLocal(cur.Account, cur.Request, "working", "Claude Code is "+step+"…", 0)
	}
	c.publish()
}

func (c *ccRunner) view() map[string]any {
	c.mu.Lock()
	defer c.mu.Unlock()
	runs := c.runs
	if len(runs) > 8 {
		runs = runs[:8]
	}
	if runs == nil {
		runs = []ccRun{}
	}
	out := map[string]any{"detect": c.det, "auto": c.autoLocked(), "auto_set": c.cfg.Auto != nil, "busy": c.busy && c.cur != nil, "runs": runs,
		"timeout_min": int(c.timeout.Minutes() + 0.5), "allowed_tools": ccAllowed()}
	if c.cfg.Auto != nil {
		out["auto_choice"] = *c.cfg.Auto
	}
	if c.cur != nil {
		out["current"] = *c.cur
	}
	if time.Now().Before(c.pausedUntil) {
		out["paused_until"] = c.pausedUntil
	}
	return out
}

func (c *ccRunner) publish() {
	if c.a.publish != nil {
		c.a.publish("claudecode", map[string]any{"account": "", "data": c.view()})
	}
}

// test: a tiny no-op prompt through the same login, timed (Settings → Test).
func (c *ccRunner) test(ctx context.Context) map[string]any {
	d := c.detect(ctx)
	if !d.Found {
		return map[string]any{"ok": false, "detect": d, "reason": d.Problem}
	}
	tctx, cancel := context.WithTimeout(ctx, 90*time.Second)
	defer cancel()
	t0 := time.Now()
	cmd := ccCmd(tctx, d.Path, os.TempDir(), "-p", "Reply with the single word: ok", "--output-format", "json", "--max-turns", "1",
		"--tools", "", "--strict-mcp-config", "--permission-mode", "dontAsk", "--no-session-persistence")
	var so, se bytes.Buffer
	cmd.Stdout, cmd.Stderr = &so, &se
	err := cmd.Run()
	var res ccResult
	_ = json.Unmarshal(bytes.TrimSpace(so.Bytes()), &res)
	said := strings.TrimSpace(res.Result + "\n" + se.String())
	out := map[string]any{"seconds": float64(time.Since(t0).Milliseconds()) / 1000, "reply": truncStr(oneLine(res.Result), 120)}
	switch {
	case errors.Is(tctx.Err(), context.DeadlineExceeded):
		out["ok"], out["reason"] = false, "Claude Code did not answer within 90 seconds."
	case ccUsageRe.MatchString(said):
		out["ok"], out["reason"] = false, "Your Claude usage limit is reached; try again later."
	case ccAuthRe.MatchString(said) || !d.SignedIn:
		out["ok"], out["reason"] = false, firstNonEmptyStr(d.Problem, "Not signed in: open Terminal, type claude, then /login with your Max account.")
	case err != nil || res.IsError:
		out["ok"], out["reason"] = false, truncStr(oneLine(firstNonEmptyStr(said, fmt.Sprint(err))), 200)
	default:
		out["ok"] = d.Ready
		if !d.Ready {
			out["reason"] = d.Problem
		}
	}
	out["detect"] = c.detected()
	return out
}

// startClaudeCode: hooks the creation feed, checks the CLI now and every 15 minutes, and looks for work.
func (a *App) startClaudeCode(c *ccRunner, stop <-chan struct{}) {
	a.mu.Lock()
	a.cc = c
	a.mu.Unlock()
	prev := brain.CreationChanged
	brain.CreationChanged = func(acct string) {
		c.changed(acct) // first: "queued" is set before the cloud exchange's sync reads the requests
		if prev != nil {
			prev(acct)
		}
	}
	go func() {
		c.detect(context.Background())
		for _, ac := range a.Accounts() {
			if c.autoOn() {
				c.markQueued(ac.id)
			}
		}
		c.kick("start")
		t := time.NewTicker(15 * time.Minute)
		defer t.Stop()
		for {
			select {
			case <-stop:
				return
			case <-t.C:
				c.detect(context.Background())
				c.kick("timer")
			}
		}
	}()
}

func (a *App) ccRunner() *ccRunner {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.cc
}

func (s *server) claudeCodeAPI(w http.ResponseWriter, r *http.Request, p string) {
	c := s.app.ccRunner()
	if c == nil {
		c = newCCRunner(s.app, s.exe, s.data, s.tabs) // -no-scheduler: status and test still answer
		s.app.mu.Lock()
		s.app.cc = c
		s.app.mu.Unlock()
	}
	switch {
	case p == "/api/claudecode" && r.Method == http.MethodGet:
		if d := c.detected(); d.At.IsZero() {
			c.detect(r.Context())
		} else if time.Since(d.At) > 10*time.Minute {
			go c.detect(context.Background())
		}
		writeJSON(w, c.view())
	case p == "/api/claudecode" && r.Method == http.MethodPut:
		var in struct {
			Auto *bool `json:"auto"`
		}
		if err := readJSON(r, &in, 4096); err != nil || in.Auto == nil {
			jsonErr(w, 400, "send {auto: true|false}")
			return
		}
		c.mu.Lock()
		v := *in.Auto
		c.cfg.Auto = &v
		cfg := c.cfg
		c.mu.Unlock()
		_ = s.app.st.Save("claudecode", cfg)
		s.app.st.Append("config", fmt.Sprintf("Claude Code on this Mac: create automatically %s", map[bool]string{true: "ON", false: "OFF"}[v]), cfg)
		if v {
			for _, ac := range s.app.Accounts() {
				c.markQueued(ac.id)
			}
			c.kick("on")
		} else if eb := s.app.brainEngine(); eb != nil { // off: the queued cards go back to Start creating
			for _, ac := range s.app.Accounts() {
				for _, q := range eb.CreationRequests(ac.id) {
					if q.Local == "queued" {
						eb.SetLocal(ac.id, q.ID, "", "", 0)
					}
				}
			}
		}
		c.publish()
		writeJSON(w, c.view())
	case p == "/api/claudecode/test" && r.Method == http.MethodPost:
		out := c.test(r.Context())
		s.app.st.Append("config", fmt.Sprintf("Claude Code on this Mac: test %s", map[bool]string{true: "passed", false: "failed"}[out["ok"] == true]), out)
		writeJSON(w, out)
	case p == "/api/claudecode/run" && r.Method == http.MethodPost:
		var in struct {
			Request string `json:"request"`
		}
		_ = readJSON(r, &in, 4096)
		c.mu.Lock()
		if in.Request != "" {
			delete(c.fails, in.Request)
			delete(c.nextTry, in.Request)
		}
		c.pausedUntil = time.Time{}
		c.mu.Unlock()
		if eb := s.app.brainEngine(); eb != nil && in.Request != "" {
			for _, ac := range s.app.Accounts() {
				for _, q := range eb.CreationRequests(ac.id) {
					if q.ID == in.Request && q.Local == "failed" {
						eb.SetLocal(ac.id, q.ID, "queued", "Claude Code on this Mac makes these in a moment.", 0)
					}
				}
			}
		}
		c.kick("owner")
		writeJSON(w, c.view())
	default:
		jsonErr(w, 404, "no such endpoint")
	}
}
