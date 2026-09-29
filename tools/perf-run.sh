#!/bin/sh
# Lane PERF — N Lighthouse runs on one profile, so a before/after is a median
# and not one sample. Writes storage/perf-logs/lh/<label>-<profile>-<i>.json.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
LABEL=$1; PROFILE=${2:-mobile}; N=${3:-3}; URL=${4:-http://127.0.0.1:8991/}
export CHROME_PATH=${CHROME_PATH:-/opt/pw-browsers/chromium-1194/chrome-linux/chrome}
i=1
while [ "$i" -le "$N" ]; do
  node "$APP/tools/perf-lh.mjs" "$LABEL-$i" "$PROFILE" "$URL"
  i=$((i + 1))
done
node "$APP/tools/perf-median.mjs" "$LABEL" "$PROFILE" "$N"
