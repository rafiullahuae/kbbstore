#!/bin/sh
# Lane SPD: Lighthouse, PSI's mobile profile, before (B) and after (A) previews,
# alternating per page so machine drift lands on both halves. N runs each, the
# median row appended to storage/spd-logs/lh/medians.jsonl.
#   tools/spd-lh-all.sh <beforeBase> <afterBase> [n=3] [profile=mobile]
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
B=$1; A=$2; N=${3:-3}; P=${4:-mobile}
for page in home:/ product:/product/relief-sun-rice-probiotics-spf50/ category:/collections/super-sale/ brand:/brands/beauty-of-joseon/ page:/privacy-policy; do
  name=${page%%:*}; path=${page#*:}
  sh "$APP/tools/spd-lh-batch.sh" "before-$name" "$P" "$B$path" "$N"
  sh "$APP/tools/spd-lh-batch.sh" "after-$name" "$P" "$A$path" "$N"
done
