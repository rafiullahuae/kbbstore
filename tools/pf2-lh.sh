#!/bin/sh
# Lane PF2: Lighthouse 12.8.2, three runs per cell, BEFORE and AFTER
# interleaved run by run so the machine's load lands on both columns alike.
#
#   sh tools/pf2-lh.sh <before-base> <after-base> [runs]
#
# Mobile is Lighthouse's own profile with the CPU slowdown PSI reported for
# the owner's run (1.2x; Lane PERF's perf-lh.mjs records why); desktop is the
# desktop preset at 1x. JSON lands in storage/pf2-logs/lh/ inside this
# worktree, never in the shared scratchpad.
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
OUT=$HERE/../storage/pf2-logs/lh
mkdir -p "$OUT"
B=$1; A=$2; RUNS=${3:-3}
CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome
FLAGS="--headless=new --no-sandbox --disable-dev-shm-usage --disable-gpu --ignore-certificate-errors"
for n in $(seq 1 "$RUNS"); do
  for page in home shop; do
    path=/; [ "$page" = shop ] && path=/shop/
    for profile in mobile desktop; do
      for side in before after; do
        base=$B; [ "$side" = after ] && base=$A
        extra="--throttling.cpuSlowdownMultiplier=1.2"
        [ "$profile" = desktop ] && extra="--preset=desktop --throttling.cpuSlowdownMultiplier=1"
        CHROME_PATH="$CHROME" npx -y lighthouse@12.8.2 "$base$path" --quiet --only-categories=performance \
          --output=json --output-path="$OUT/$side-$page-$profile-$n.json" \
          --chrome-path="$CHROME" --chrome-flags="$FLAGS" $extra >/dev/null 2>&1 \
          || echo "FAILED $side $page $profile $n"
        echo "done $side $page $profile $n"
      done
    done
  done
done
