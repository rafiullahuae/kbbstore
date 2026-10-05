#!/bin/sh
# Lane PS — Lighthouse before/after, INTERLEAVED per run so the load of a shared
# container lands on both trees (Lane PERF's perf-ab.sh method), five pages.
#   LH_DIR=... sh tools/ps-ab.sh <before-base> <after-base> <mobile-pairs> <desktop-pairs>
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
B=$1; A=$2; MP=${3:-3}; DP=${4:-2}
PAGES="home:/ category:/collections/sunscreens/ product:/product/heartleaf-77-soothing-toner/ super-sale:/super-sale/ brand:/brands/anua/"
for pg in $PAGES; do
  name=${pg%%:*}; path=${pg#*:}
  i=1; while [ "$i" -le "$MP" ]; do
    node "$APP/tools/ps-lh.mjs" "ab-before-$name-$i" mobile "$B$path" || true
    node "$APP/tools/ps-lh.mjs" "ab-after-$name-$i" mobile "$A$path" || true
    i=$((i+1)); done
  i=1; while [ "$i" -le "$DP" ]; do
    node "$APP/tools/ps-lh.mjs" "ab-before-$name-$i" desktop "$B$path" || true
    node "$APP/tools/ps-lh.mjs" "ab-after-$name-$i" desktop "$A$path" || true
    i=$((i+1)); done
done
