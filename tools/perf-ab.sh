#!/bin/sh
# Lane PERF — the before/after pair, INTERLEAVED.
#
# Six other lanes share this container and its rendering is six times slower
# than the machine PageSpeed Insights ran on, so a "before" measured at 20:05
# and an "after" measured at 21:40 differ by the load average as well as by the
# code. Both trees are served at once -- tools/perf-preview.sh takes a git ref
# -- and the runs alternate, so drift lands on both halves.
#
#   sh tools/perf-ab.sh <mobile|desktop> <pairs>
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PROFILE=${1:-mobile}; PAIRS=${2:-5}
export CHROME_PATH=${CHROME_PATH:-/opt/pw-browsers/chromium-1194/chrome-linux/chrome}
i=1
while [ "$i" -le "$PAIRS" ]; do
  node "$APP/tools/perf-lh.mjs" "AB-before-$i" "$PROFILE" http://127.0.0.1:8992/
  node "$APP/tools/perf-lh.mjs" "AB-after-$i"  "$PROFILE" http://127.0.0.1:8991/
  i=$((i + 1))
done
node "$APP/tools/perf-median.mjs" AB-before "$PROFILE" "$PAIRS"
node "$APP/tools/perf-median.mjs" AB-after  "$PROFILE" "$PAIRS"
