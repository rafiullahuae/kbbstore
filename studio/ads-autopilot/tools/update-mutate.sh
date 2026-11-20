#!/usr/bin/env bash
# The updater's mutation check (Settings → Updates, 20 Nov 2026: "must be super reliable"): each line breaks one
# guarantee of engine/update.go / update_flow.go and expects the updater's Go tests to go RED. Restores the file
# after each mutation. Usage: tools/update-mutate.sh   (from studio/ads-autopilot)
set -u
cd "$(dirname "$0")/../engine" || exit 1
RUN='Health|Package|Patch|Install|Interrupted|Finished|Health|OneInstall|Autopilot|Resumable|Refused|Version'
pass=0; fail=0
mut() { # file, from, to, label
  local f=$1 from=$2 to=$3 label=$4
  cp "$f" "$f.mutbak"
  python3 -I -c 'import sys;p,a,b=sys.argv[1:4];s=open(p).read();assert a in s,("missing",a);open(p,"w").write(s.replace(a,b,1))' "$f" "$from" "$to" || { echo "SKIP $label (pattern missing)"; mv "$f.mutbak" "$f"; fail=$((fail+1)); return; }
  if ! go test -count=1 -run '^$' . >/dev/null 2>&1; then echo "BROKEN    $label (does not compile: not a real kill)"; fail=$((fail+1))
  elif go test -count=1 -timeout 120s -run "$RUN" . >/dev/null 2>&1; then echo "SURVIVED  $label"; fail=$((fail+1)); else echo "killed    $label"; pass=$((pass+1)); fi
  mv "$f.mutbak" "$f"
}
mut update.go '		case nwErr != nil && curErr == nil:' '		case curErr == nil || nwErr == nil && curErr == nil:' 'power cut: the old unswap moves units the swap never reached out of the app folder'
mut update.go '	if err := writeFileSync(filepath.Join(backup, "journal.json"), j); err != nil {' '	if err := writeFileSync(filepath.Join(backup, "journal-off.json"), j); err != nil {' 'no journal: a power cut midway is never put back'
mut update.go '			if _, err := os.Stat(filepath.Join(p, "info.json")); err == nil {' '			if false {' 'a finished install loses its backup after a power cut'
mut update.go '	if m := s.readMarker(); m != nil && appVersionAt(s.root) != m.To {' '	if false {' 'a stale health marker survives an undone install'
mut update.go '		if root == "" || current != man.From {' '		if root == "" {' 'a patch lays itself over the wrong version'
mut update.go '		if seg == "" || seg == "." || seg == ".." {' '		if seg == "" {' 'zip-slip (..) accepted'
mut update.go '		_ = os.Remove(p)
		for d := filepath.Dir(p)' '		for d := filepath.Dir(p)' 'a patch does not remove the files it removes'
mut update.go '				if err := linkTree(src, filepath.Join(staging, filepath.FromSlash(u)), st); err != nil {' '				if err := error(nil); err != nil || st == nil {' 'a patch stages only its own files (the rest of app/ would vanish)'
mut update.go '			if !d.Type().IsRegular() {
				return refuse' '			if false {
				return refuse' 'verifyStaged accepts a non-file'
mut update_flow.go '	if expected != nil {
		if err := verifyStaged(src, units, expected)' '	if false {
		if err := verifyStaged(src, units, expected)' 'Install does not re-check the staged files'
mut update_flow.go '	if want := strings.ToLower(r.Header.Get("X-Chunk-SHA256")); want == "" || want != hex.EncodeToString(sum[:]) {' '	if want := ""; want != "" && want != hex.EncodeToString(sum[:]) {' 'a damaged chunk is counted'
mut update_flow.go '	if offset != u.Received {' '	if false {' 'a chunk at any offset is accepted'
# (a chunk cut short: io.ReadFull refuses it, and the chunk's own sha256 would refuse it again -- two guards, so
#  breaking one alone is not visible; the sha guard is mutated above)
mut update_flow.go '				queue = append(queue, cleanURLPath(p, string(mm[1])))' '				_ = mm' 'the health check does not follow the app'"'"'s imports'
mut update_flow.go '	if m.Auto && prev != nil && prev.Version == m.From {' '	if false {' 'a failed health check does not roll back'
mut update_flow.go '		if s.updBusy || (s.health != nil && s.health.State == "checking") {' '		if false {' 'two installs at once (one process)'
mut update_flow.go '	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {' '	if false {' 'two installs at once (two windows)'
mut update_flow.go '	for _, ac := range s.app.Accounts() {
		for !ac.work.TryLock() {' '	for _, ac := range []*Acct{} {
		for !ac.work.TryLock() {' 'install does not wait for a running cycle'
mut acct.go '	if ac.app.updating.Load() {
		return nil, errUpdating' '	if false {
		return nil, errUpdating' 'a cycle runs during an install'
mut update_flow.go '	if info.Version != m.To {' '	if false {' 'an old engine serving the new app passes the health check'
echo "mutations killed: $pass, survived: $fail"
[ "$fail" -eq 0 ]
