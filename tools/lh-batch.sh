#!/bin/sh
# Lane LH: N Lighthouse runs of one URL, then the median row.
#   tools/lh-batch.sh <label> <mobile|desktop> <url> [n=5]
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
L=$1; P=$2; U=$3; N=${4:-5}
export LH_DIR=${LH_DIR:-$APP/storage/lh-logs/lhtool}
i=1; while [ $i -le $N ]; do node "$APP/tools/lh-run.mjs" "$L-$i" "$P" "$U" >/dev/null 2>&1 || echo "run $i failed" >&2; i=$((i+1)); done
node -e '
const fs=require("fs");const [l,p,n]=process.argv.slice(1);
const rows=fs.readFileSync(process.argv[4],"utf8").trim().split("\n").map(JSON.parse).filter(r=>r.profile===p&&new RegExp("^"+l+"-\\d+$").test(r.label)).slice(-n);
const med=k=>{const s=rows.map(r=>r[k]).filter(v=>v!=null).sort((a,b)=>a-b);return s[(s.length-1)>>1]};
const o={label:l,profile:p,runs:rows.length};for(const k of ["perf","fcp","lcp","si","tbt","cls","kib","blockKib","blockN","siObserved","fcpObserved","lcpObserved","lastFrame"])o[k]=med(k);
console.log(JSON.stringify(o));fs.appendFileSync(process.argv[4].replace("runs.jsonl","medians.jsonl"),JSON.stringify(o)+"\n");
' "$L" "$P" "$N" "$APP/storage/lh-logs/lh/runs.jsonl"
