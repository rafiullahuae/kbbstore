#!/bin/sh
# Lane PS — Lighthouse over the five pages the brief names, on one preview.
#   LH_DIR=... sh tools/ps-run.sh <label> <base-url> <mobile-runs> <desktop-runs>
# Rows land in storage/ps-logs/lh/runs.jsonl (worktree-local).
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
LABEL=$1; BASE=$2; MR=${3:-3}; DR=${4:-2}
PAGES="home:/ category:/collections/sunscreens/ product:/product/heartleaf-77-soothing-toner/ super-sale:/super-sale/ brand:/brands/anua/"
for pg in $PAGES; do
  name=${pg%%:*}; path=${pg#*:}
  i=1; while [ "$i" -le "$MR" ]; do node "$APP/tools/ps-lh.mjs" "$LABEL-$name-$i" mobile "$BASE$path" || true; i=$((i+1)); done
  i=1; while [ "$i" -le "$DR" ]; do node "$APP/tools/ps-lh.mjs" "$LABEL-$name-$i" desktop "$BASE$path" || true; i=$((i+1)); done
done
