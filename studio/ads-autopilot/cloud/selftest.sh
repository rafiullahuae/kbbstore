#!/usr/bin/env bash
# Cloud creation, end to end, with no network: a local bare git repository stands in for GitHub.
#   1  the engine (-mock: fake Meta / Claude / shop) raises a creation request and pushes it as a job
#      (KAA_EXCHANGE_DIR: the engine writes into a clone; this script commits and pushes what it wrote, as the
#      GitHub API does on the Mac — that half is covered against a fake GitHub API by the Go tests)
#   2  two cloud workers claim: exactly one gets the job
#   3  "Claude" writes ads.json (here: a fixed answer from the brief, with one English line the linter must refuse)
#   4  the worker renders every idea x language x format with the app's OWN renderer + quality gate in headless
#      Chromium, and one MP4; finish pushes results + done.json
#   5  the PNGs are real (size per format), the MP4 starts with ftyp
#   6  the engine pulls, re-checks and imports: cloud_max creatives in the library, the bad set refused, feedback
#      pushed back, the request done
# Usage: NODE_PATH=/path/to/node_modules cloud/selftest.sh     (exit 0 = all passed)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
AP="$(cd "$HERE/.." && pwd)"
T="$(mktemp -d "${TMPDIR:-/tmp}/kbb-cloud-selftest.XXXXXX")"
ENGINE_PID=""
cleanup() { if [ -n "$ENGINE_PID" ]; then kill "$ENGINE_PID" 2>/dev/null || true; wait "$ENGINE_PID" 2>/dev/null || true; fi; [ "${KEEP:-}" = 1 ] || rm -rf "$T"; }
trap cleanup EXIT
fail() { echo "SELFTEST FAILED: $*" >&2; [ -f "$T/engine.log" ] && tail -20 "$T/engine.log" >&2; exit 1; }
ok() { echo "  ok  $*"; }
export GIT_AUTHOR_NAME=selftest GIT_AUTHOR_EMAIL=noreply@anthropic.com GIT_COMMITTER_NAME=selftest GIT_COMMITTER_EMAIL=noreply@anthropic.com

echo "== exchange: a bare repo in $T"
git init --quiet --bare -b main "$T/ex.git"
git clone --quiet "$T/ex.git" "$T/seed" 2>/dev/null
mkdir -p "$T/seed/jobs" "$T/seed/results" && touch "$T/seed/jobs/.keep" "$T/seed/results/.keep" && echo "# exchange" > "$T/seed/README.md"
git -C "$T/seed" add -A && git -C "$T/seed" commit --quiet -m init && git -C "$T/seed" push --quiet origin main
git clone --quiet "$T/ex.git" "$T/mac"

echo "== engine (-mock)"
PORT=$((44000 + RANDOM % 900))
mkdir -p "$T/data"  # the engine wants its data folder to exist (as create-check does)
(cd "$AP/engine" && CGO_ENABLED=0 go build -o "$T/kaa" .) || fail "engine build"
KAA_EXCHANGE_DIR="$T/mac" KAA_NOTIFY_CMD=/bin/true "$T/kaa" -mock -no-browser -no-scheduler -data "$T/data" -dir "$AP" -port "$PORT" -idle 1800s >"$T/engine.log" 2>&1 &
ENGINE_PID=$!
for _ in $(seq 1 100); do grep -q "running at" "$T/engine.log" 2>/dev/null && break; sleep 0.1; done
BASE="$(grep "running at" "$T/engine.log" | grep -o "http://127.0.0.1:[0-9]*" | head -1 || true)"
[ -n "$BASE" ] || fail "engine did not start"
TOK="$(curl -s "$BASE/api/info" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>console.log(JSON.parse(s).token))')"
api() { local m="$1" p="$2"; shift 2; curl -s -X "$m" -H "X-KAA: $TOK" -H 'content-type: application/json' "$BASE$p" "$@"; }
# like create-check: wait for the accounts, then for the mock to link them (asking earlier races the engine's start)
for _ in $(seq 1 100); do api GET /api/accounts | grep -q '"accounts":\[{' && break; sleep 0.2; done
sleep 1.5
PID_=""
for _ in $(seq 1 150); do
  PID_="$(api GET /api/brain/x/products | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{const a=JSON.parse(s);console.log(Array.isArray(a)&&a.length?a[0].id:"")}catch{console.log("")}})')"
  [ -n "$PID_" ] && break; sleep 0.2
done
[ -n "$PID_" ] || fail "the mock shop has no product: $(api GET /api/brain/x/products | head -c 300)"
ok "engine at $BASE, product $PID_"
api GET "/api/brain/x/img?u=x" -o "$T/photo.png"
[ "$(head -c 4 "$T/photo.png" | od -An -c | tr -d ' ')" = "211PNG" ] || fail "no product photo from the mock"

echo "== 1. the request becomes a job"
REQ="$(api POST /api/exchange/test-request -d "{\"product_ids\":[\"$PID_\"],\"angle\":\"glass skin glow reels\"}" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>console.log(JSON.parse(s).id))')"
api POST /api/exchange/sync >/dev/null
ACCT="$(ls "$T/mac/jobs" | grep -v '^\.keep$' | head -1)"
[ -f "$T/mac/jobs/$ACCT/$REQ/brief.json" ] || fail "no brief for $REQ"
grep -q '"queued"' "$T/mac/jobs/$ACCT/$REQ/status.json" || fail "status not queued"
! grep -rqiE 'sk-ant-|EAA[A-Za-z0-9]{10}|kbbap_|github_pat_|@[a-z0-9-]+\.(com|ae)\b' "$T/mac/jobs" || fail "a key or an e-mail in the job"
git -C "$T/mac" add -A && git -C "$T/mac" commit --quiet -m "job $REQ" && git -C "$T/mac" push --quiet origin main
api POST /api/exchange/sync >/dev/null
[ -z "$(git -C "$T/mac" status --porcelain)" ] || fail "a second sync wrote the job again"
ok "job $ACCT/$REQ pushed once"

echo "== 2. two workers, one claim"
git clone --quiet "$T/ex.git" "$T/cloud"; git clone --quiet "$T/ex.git" "$T/cloud2"
W1="$(node "$HERE/worker.mjs" claim --exchange "$T/cloud" --worker cloud-one)"
W2="$(node "$HERE/worker.mjs" claim --exchange "$T/cloud2" --worker cloud-two)"
echo "$W1" | grep -q "$ACCT/$REQ" || fail "worker one did not claim: $W1"
echo "$W2" | grep -q "no waiting jobs" || fail "worker two claimed too: $W2"
ok "claimed by cloud-one only"

echo "== 3. Claude writes ads.json (fixed answer here)"
node "$HERE/worker.mjs" brief --exchange "$T/cloud" --job "$ACCT/$REQ" > "$T/brief.txt"
node -e '
const fs=require("fs");const [b,out]=process.argv.slice(1);const brief=JSON.parse(fs.readFileSync(b,"utf8"));const id=brief.products[0].id;
const ideas=[{product_ids:[id],angle:"glass skin glow",hook:"dewy in one step",template:"glow",palette:[],show_price:true,formats:["1:1","4:5","9:16"],motion:true,scene:"",
 sets:[{lang:"ar",headline:"توهّج زجاجي كل صباح",primary_text:"قوام خفيف يمنح بشرتكِ إشراقة ندية.\nعناية كورية لطيفة، توصيل داخل الإمارات.",sub:"ترطيب يدوم",bullets:["قوام خفيف","إشراقة ندية"],badge:"",cta:"SHOP_NOW",hooks:["إشراقة من أول استخدام"]},
       {lang:"en",headline:"Glass-skin glow, every morning",primary_text:"A light texture for a dewy finish.\nGentle Korean skincare, delivered in the UAE.",sub:"Lasting hydration",bullets:["Light texture","Dewy finish"],badge:"",cta:"SHOP_NOW",hooks:["Dewy from the first use"]}]},
 {product_ids:[id],angle:"clear skin",hook:"",template:"hero",palette:[],show_price:false,formats:["1:1"],motion:false,scene:"",
 sets:[{lang:"en",headline:"Cures acne in 3 days",primary_text:"This serum cures acne and heals your skin for good.",sub:"Fast fix",bullets:[],badge:"",cta:"SHOP_NOW",hooks:[]}]}];
fs.mkdirSync(require("path").dirname(out),{recursive:true});fs.writeFileSync(out,JSON.stringify({job_id:brief.job_id,worker:"cloud-one",ideas},null,1));
' "$T/cloud/jobs/$ACCT/$REQ/brief.json" "$T/cloud/results/$ACCT/$REQ/ads.json"
ok "ads.json: 2 ideas (one English set the Mac must refuse)"

echo "== 4. render with the app's own renderer + quality gate"
node "$HERE/worker.mjs" render --exchange "$T/cloud" --worker cloud-one --job "$ACCT/$REQ" --offline-photo "$T/photo.png" | tee "$T/render.txt"
node "$HERE/worker.mjs" finish --exchange "$T/cloud" --worker cloud-one --job "$ACCT/$REQ"

echo "== 5. outputs"
node -e '
const fs=require("fs"),path=require("path");const d=process.argv[1];const ads=JSON.parse(fs.readFileSync(path.join(d,"ads.json")));
const F={"1:1":[1080,1080],"4:5":[1080,1350],"9:16":[1080,1920]};let png=0,mp4=0;
for(const r of ads.renders){const b=fs.readFileSync(path.join(d,r.file));
 if(r.kind==="png"){if(b.toString("latin1",1,4)!=="PNG")throw new Error(r.file+" not PNG");const w=b.readUInt32BE(16),h=b.readUInt32BE(20);if(w!==F[r.format][0]||h!==F[r.format][1])throw new Error(r.file+" "+w+"x"+h);if(!r.quality.ok)throw new Error(r.file+" gate");png++;}
 else{if(b.toString("latin1",4,8)!=="ftyp")throw new Error(r.file+" not MP4");mp4++;}}
if(!fs.existsSync(path.join(d,"done.json")))throw new Error("no done.json");
console.log("  ok  "+png+" PNG ("+ads.renders.filter(r=>r.kind==="png").map(r=>r.quality.score).join(",")+" gate scores), "+mp4+" MP4, "+(ads.refused||[]).length+" refused by the cloud gate");
if(png<4)throw new Error("too few PNGs: "+png);if(mp4<1)throw new Error("no MP4");
' "$T/cloud/results/$ACCT/$REQ" || fail "outputs"

echo "== 6. the engine imports"
git -C "$T/mac" pull --quiet --rebase origin main
api POST /api/exchange/sync > "$T/sync.json"
node -e '
const fs=require("fs");const [cr,ex,fbp]=process.argv.slice(1);const c=JSON.parse(fs.readFileSync(cr));const x=JSON.parse(fs.readFileSync(ex));
const cl=(Array.isArray(c)?c:(c.creatives||c.items||[])).filter(z=>z.origin==="cloud_max");
const fb=JSON.parse(fs.readFileSync(fbp));const why=fb.rejected.map(r=>r.file+": "+r.why);
if(cl.length<4)throw new Error("library has "+cl.length+" cloud creatives");
if(!cl.some(z=>z.media==="mp4"))throw new Error("the MP4 was not imported");
if(!why.some(w=>/i2-en/.test(w)&&/wording/.test(w)))throw new Error("the cure claim was not refused: "+why.join(" | "));
if((x.counts||{}).finished!==1)throw new Error("counts "+JSON.stringify(x.counts));
console.log("  ok  imported "+cl.length+" ("+cl.filter(z=>z.media==="mp4").length+" MP4), refused "+why.length+": "+why.join(" | "));
' <(api GET /api/creatives) "$T/sync.json" "$T/mac/feedback/$ACCT/$REQ/import.json" || fail "import"
api GET /api/brain/x/creation | grep -q "\"cloud\":\"imported\"" || fail "request not marked imported"
git -C "$T/mac" add -A && git -C "$T/mac" commit --quiet -m feedback && git -C "$T/mac" push --quiet origin main
node "$HERE/worker.mjs" status --exchange "$T/cloud" | grep -q "refused" || fail "the worker does not see the feedback"
ok "feedback reached the worker"
echo "SELFTEST PASSED"
