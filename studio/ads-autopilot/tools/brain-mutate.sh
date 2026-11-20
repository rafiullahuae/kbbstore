#!/usr/bin/env bash
# LANE BRAIN mutation check: each line flips one decision rule in engine/brain
# and expects `go test` to go RED. Restores the file after each mutation.
# Usage: tools/brain-mutate.sh   (from studio/ads-autopilot)
set -u
cd "$(dirname "$0")/../engine/brain" || exit 1
pass=0; fail=0
mut() { # file, from, to, label
  local f=$1 from=$2 to=$3 label=$4
  cp "$f" "$f.mutbak"
  python3 -I -c 'import sys;p,a,b=sys.argv[1:4];s=open(p).read();assert a in s,("missing",a);open(p,"w").write(s.replace(a,b,1))' "$f" "$from" "$to" || { echo "SKIP $label (pattern missing)"; mv "$f.mutbak" "$f"; return; }
  if go test ./... >/dev/null 2>&1; then echo "SURVIVED  $label"; fail=$((fail+1)); else echo "killed    $label"; pass=$((pass+1)); fi
  mv "$f.mutbak" "$f"
}
mut stats.go 'const PriorWeight = 2.0' 'const PriorWeight = 1.0' 'prior weight 2 -> 1'
mut rules.go 'KillP: 0.2,' 'KillP: 0.05,' 'kill threshold 0.2 -> 0.05'
mut rules.go 'settled := func(r MetricRow) bool { return !r.Settling && r.Date <= winEnd && r.Date < today }' 'settled := func(r MetricRow) bool { return true }' 'judge settling days'
mut rules.go 'nb := round2(cur * (1 + step/100))' 'nb := round2(cur * (1 + 1.5*step/100))' 'scale step x1.5'
mut rules.go 'return last.IsZero() || now.Sub(last) >= cool' 'return true' 'ignore cooldown'
mut rules.go 'if inLearning && v.Verdict != "cut" {' 'if false {' 'ignore learning'
mut rules.go 'FatigueMinSignal: 2,' 'FatigueMinSignal: 3,' 'fatigue needs 3 signals'
mut rules.go 'if nb-cur > *headroom {' 'if false {' 'ignore daily-limit headroom'
mut rules.go 'if !res.LimitsSet {' 'if false {' 'launch without limits'
mut rules.go 'share = cfg.ArabicMin' 'share = cfg.ArabicShare' 'Arabic floor'
mut linter.go 'if !enProne.MatchString(sent[loc[1]:]) {' 'if false {' 'EN attribute assertion'
mut linter.go 'if m := arAsserted(sent); m != "" {' 'if m := ""; m != "" {' 'AR attribute assertion'
mut linter.go '"personal_attribute_context":  {"block", "warn", "warn"},' '"personal_attribute_context":  {"block", "block", "warn"},' 'balanced blocks acne topics'
mut linter.go 'strings.HasSuffix(base, "بشرت")' 'strings.HasSuffix(base, "zz")' 'AR skin-possessive near condition'
mut linter.go 'if strings.HasSuffix(w, x) && len([]rune(w))-len([]rune(x)) >= 3 {' 'if false {' 'AR pronoun suffix stripping'
mut linter.go '"discount_unapproved":         {"block", "warn", "warn"},' '"discount_unapproved":         {"warn", "warn", "warn"},' 'strict discount'
mut brain.go 'if v.Verdict == "keep" && contains(v.Signals, "winner") {' 'if false {' 'planner may pause a winner'
mut brain.go 'if !rr.BatchDay {' 'if false {' 'new ads off batch day'
mut research.go 'if len(srcs) == 0 || strings.TrimSpace(n.Title) == "" {' 'if strings.TrimSpace(n.Title) == "" {' 'unsourced research note'
mut video.go '(t.YTop < 0.14 || t.YBottom > 0.65)' '(t.YTop < 0.14)' 'video bottom safe zone'
mut video.go 'if pr, ok := idx[p.ProductID]; ok && p.Confidence >= 0.4 {' 'if pr, ok := idx[p.ProductID]; ok || true {' 'video product ids not checked'
mut video.go 'if i.Severity == "block" && s > 30 {' 'if false {' 'blocked video score cap'
mut brain.go 'if !videoRunnable(v) {' 'if false {' 'upload a blocked video'
mut profile.go 'if r.Placement != "" {
			addTo("placement"' 'if false {
			addTo("placement"' 'profile counts placement rows twice'
mut profile.go 'if w.CTR() <= 0.7*first.CTR() {' 'if w.CTR() <= 0.3*first.CTR() {' 'half-life threshold'
mut brain.go 'if n < 2 || n > 10 {' 'if n < 1 {' 'carousel product count'
echo "mutations killed: $pass, survived: $fail"
[ "$fail" -eq 0 ]
