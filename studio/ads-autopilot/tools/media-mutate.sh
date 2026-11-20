#!/usr/bin/env bash
# Media-buying lane mutation check (20 Nov 2026): each line breaks one rule of the Playbook / funnel / job queue /
# occasions / offers / learning and expects `go test` of that package to go RED. Restores the file after each.
# Usage: tools/media-mutate.sh   (from studio/ads-autopilot)
set -u
cd "$(dirname "$0")/../engine" || exit 1
pass=0; fail=0
mut() { # file, from, to, label [, package]
  local f=$1 from=$2 to=$3 label=$4 forced=${5:-}
  cp "$f" "$f.mutbak"
  python3 -I -c 'import sys;p,a,b=sys.argv[1:4];s=open(p).read();assert a in s,("missing",a);open(p,"w").write(s.replace(a,b,1))' "$f" "$from" "$to" || { echo "SKIP $label (pattern missing)"; mv "$f.mutbak" "$f"; fail=$((fail+1)); return; }
  local pkg=.
  case "$f" in executor/*) pkg=./executor ;; brain/*) pkg=./brain ;; model/*) pkg=./model ;; esac
  [ -n "$forced" ] && pkg=$forced
  if ! go test -count=1 -run '^$' "$pkg" >/dev/null 2>&1; then echo "BROKEN    $label (does not compile: not a real kill)"; fail=$((fail+1))
  elif go test -count=1 "$pkg" >/dev/null 2>&1; then echo "SURVIVED  $label"; fail=$((fail+1)); else echo "killed    $label"; pass=$((pass+1)); fi
  mv "$f.mutbak" "$f"
}
mut acct.go '	in.Shop, in.Playbook, in.JobNotes = fshop, &pb, ac.jobNotes()' '	in.Shop, in.Playbook, in.JobNotes = shopSnap, &pb, ac.jobNotes()
	_ = fshop' 'the planner sees the unfiltered shop (Avoid reaches it)'
mut model/playbook.go '		if r, ok := matchAny(p.Avoid, pr); ok {' '		if r, ok := matchAny(p.Avoid, pr); ok && !p.Pinned(pr) {' 'a pinned product beats Avoid'
mut model/playbook.go '		score := F*(0.5*trend+0.5*newS) + (1-F)*0.5*mg + 0.35*mom' '		score := F*(0.5*trend+0.5*newS) + (1-F)*0.5*mg + 0*mom' 'sales momentum ignored (an evergreen never earns its slot)'
mut model/occasions.go '(3+11*y)/30' '(3+11*y)/31' 'Hijri leap term wrong'
mut executor/build.go '	if d.goal == "OFFSITE_CONVERSIONS" { // 7-day click' '	if false { // 7-day click' 'no attribution_spec on sales ad sets' .
mut starter.go '		pr["excluded_custom_audiences"] = exclude' '		_ = exclude' 'prospecting does not exclude recent buyers'
mut jobs.go '		if j.Kind == "occasion" && j.Status != "approved" && !in.Playbook.AutoApproveOccasions {' '		if false {' 'occasions go live without approval'
mut jobs.go '	for _, id := range j.ProductIDs {
		if p, ok := by[id]' '	for _, id := range []string{} {
		if p, ok := by[id]' 'a job edit (products) is ignored'
mut jobs.go '		if j.ID == jobFirstLaunch || (j.Status != "planned" && j.Status != "approved") || j.When.After(now) {' '		if j.ID == jobFirstLaunch || (j.Status != "planned" && j.Status != "approved" && j.Status != "skipped") || j.When.After(now) {' 'a skipped job runs'
mut readiness.go '	if st.Links.PageID == "" || st.Links.PixelID == "" {' '	if false {' 'Approvals empty without saying "link the Page / pixel"'
mut brain/linter.go '	"discount_amount":             {"block", "block", "block"},' '	"discount_amount":             {"block", "block", "warn"},' 'a % discount passes in relaxed mode'
mut brain/linter.go '	"gift_tier":                   {"block", "block", "block"},' '	"gift_tier":                   {"warn", "warn", "warn"},' 'gift tiers pass'
mut brain/learn.go '	for _, k := range cellKeys(p.Angle, p.Format, p.Lang, p.ProductIDs) {
		c := L.Priors[k]' '	for _, k := range []string{} {
		c := L.Priors[k]' 'outcomes never update the priors (no learning)'
mut brain/learn.go '	next = math.Max(b.HardStopMin, math.Min(b.HardStopMax, next))' '	_ = b' 'self-tuning leaves the owner'"'"'s bounds'
mut brain/economics.go '	exVAT := e.AOV / (1 + cfg.VATPct) // VAT comes off first' '	exVAT := e.AOV // VAT comes off first' 'VAT not removed first'
echo "mutations killed: $pass, survived: $fail"
[ "$fail" -eq 0 ]
