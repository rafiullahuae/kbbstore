#!/bin/sh
# Lane CPU -- hit each URL 1 + N times against tools/cpu-serve.sh (first hit is
# the cold one and is reported separately), then summarise the per-request
# JSON lines tools/cpu-front.php wrote: median ms / cpu ms, queries, translation
# queries, file-cache reads, peak memory.  PREVIEW ONLY.
#   tools/cpu-bench.sh <port> <label> <N> url...
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=$1; LABEL=$2; N=$3; shift 3
LOG=$APP/storage/cpu-logs/requests.jsonl
: > "$LOG"
for u in "$@"; do
  i=0
  while [ $i -le "$N" ]; do
    curl -s -o /dev/null -H 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) Chrome/141.0 Safari/537.36' "http://127.0.0.1:$PORT$u"
    i=$((i + 1))
  done
done
mkdir -p "$APP/docs/lane-cpu"
php "$APP/tools/cpu-summary.php" "$LOG" "$APP/docs/lane-cpu/requests-$LABEL.json"
