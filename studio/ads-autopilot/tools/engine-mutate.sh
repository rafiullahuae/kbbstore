#!/usr/bin/env bash
# LANE ENGINE mutation check for the 20 Nov fixes (approve one, creative files, video_path, wording on /api/limits,
# scripted mock Claude, first launch, batch activation count): each line breaks one fix and expects `go test` of
# that package to go RED. Restores the file after each mutation.
# Usage: tools/engine-mutate.sh   (from studio/ads-autopilot)
set -u
cd "$(dirname "$0")/../engine" || exit 1
pass=0; fail=0
mut() { # file, from, to, label
  local f=$1 from=$2 to=$3 label=$4
  cp "$f" "$f.mutbak"
  python3 -I -c 'import sys;p,a,b=sys.argv[1:4];s=open(p).read();assert a in s,("missing",a);open(p,"w").write(s.replace(a,b,1))' "$f" "$from" "$to" || { echo "SKIP $label (pattern missing)"; mv "$f.mutbak" "$f"; fail=$((fail+1)); return; }
  local pkg=.
  case "$f" in executor/*) pkg=./executor ;; esac
  if ! go test -count=1 -run '^$' "$pkg" >/dev/null 2>&1; then echo "BROKEN    $label (does not compile: not a real kill)"; fail=$((fail+1))
  elif go test "$pkg" >/dev/null 2>&1; then echo "SURVIVED  $label"; fail=$((fail+1)); else echo "killed    $label"; pass=$((pass+1)); fi
  mv "$f.mutbak" "$f"
}
mut proposals.go '	walk(idx)
	var out []int' '	var out []int
	return []int{idx}' 'approve: run the proposal without the objects it needs'
mut proposals.go 'case st.Limits.Autopilot == model.AutopilotOff:' 'case false:' 'approve with the autopilot off'
mut proposals.go '		ps.Items[idx].Status, ps.Items[idx].At, ps.Items[idx].By = "rejected", now, "owner"' '		ps.Items[idx].At, ps.Items[idx].By = now, "owner"' 'Not now does not stick'
mut proposals.go '	for _, c := range ac.creatives.all() {
		if c.ID == id {
			c := c
			cr = &c
		}
	}' '	for _, o := range s.app.Accounts() {
		for _, c := range o.creatives.all() {
			if c.ID == id {
				c := c
				cr = &c
				ac = o
			}
		}
	}' 'creative file from any account'
mut proposals.go '	if !strings.HasPrefix(real, root+string(os.PathSeparator)) {' '	if !strings.HasPrefix(real, root[:1]) {' 'video file outside the videos folder'
mut executor/build.go '	vid := x.Ledger.Resolve(key)
	if vid == key {' '	vid := x.Ledger.Resolve(key)
	if true {' 'video uploaded again for every ad'
mut executor/formats.go '				if s, _ := vp.(string); !VideoPathRe.MatchString(s) {' '				if s, _ := vp.(string); s == "\x00" {' 'video_path not checked'
mut executor/execute.go '		n.ad.Status, n.ad.EffectiveStatus = status, status
	}' '		if status == "ACTIVE" {
			env.ActivatedNow++
		}
		n.ad.Status, n.ad.EffectiveStatus = status, status
	}' 'batch activations counted twice'
mut brain/adapter.go '	switch l.Wording {
	case Strict, Balanced, Relaxed:
		return l.Wording
	}' '' 'brain ignores the wording on /api/limits'
mut acct.go '	sp, stWhy := ac.starterPlan(ctx, in, co.Proposals, env)' '	sp, stWhy := []model.Proposal(nil), ""' 'first launch waits for the batch day'
mut starter.go '				return false, "the app'"'"'s campaigns are running"' '				_ = 0' 'a starter while an app campaign runs'
mut starter.go 'r.Date >= weekAgo && r.SpendAED > 0 {' 'r.Date >= weekAgo && r.SpendAED < 0 {' 'a starter while an app campaign spent this week'
mut starter.go 'if prev.State == "launched" && env.Now.Sub(prev.At) < 7*24*time.Hour {' 'if prev.State == "launched" && env.Now.Sub(prev.At) < 0 {' 'a second starter within 7 days'
mut starter.go '		if p.Kind == model.KindCreateCampaign {
			return false' '		if p.Kind == "never" {
			return false' 'a starter on top of the planner launch'
mut starter.go '	if !lim.Set() {
		return false, "no limits"' '	if false {
		return false, "no limits"' 'starter without limits'
mut acct.go '	cycleMode := model.AutopilotShadow' '	cycleMode := mode' 'practice cycle writes'
mut metamock/claude.go '	case props["proposals"] != nil:
		return respond(fixPlan), "plan"' '' 'mock Claude answers the planner with text'
echo "mutations killed: $pass, survived: $fail"
[ "$fail" -eq 0 ]
