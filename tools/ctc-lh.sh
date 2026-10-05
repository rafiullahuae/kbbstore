#!/bin/sh
# Lane CT (contrast): Lighthouse 12.8.2 ACCESSIBILITY on six pages, phone and
# desktop, before and after. Lighthouse is run from the npx cache already on
# this machine; nothing is added to package.json. JSON lands in this
# worktree's storage/ct-logs/lh/, never the shared scratchpad.
#
#   sh tools/ctc-lh.sh <before-base> <after-base>
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
OUT=$HERE/../storage/ct-logs/lh
mkdir -p "$OUT"
B=$1; A=$2
LH=${CTC_LH:-/root/.npm/_npx/8003d8991b0d346b/node_modules/lighthouse/cli/index.js}
CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome
FLAGS="--headless=new --no-sandbox --disable-dev-shm-usage --disable-gpu"
for page in home:/ shop:/shop/ category:/collections/serums/ product:/product/relief-sun-rice-probiotics-spf50/ cart:/cart/ super-sale:/super-sale/; do
  name=${page%%:*}; path=${page#*:}
  for profile in mobile desktop; do
    for side in before after; do
      base=$B; [ "$side" = after ] && base=$A
      extra=""; [ "$profile" = desktop ] && extra="--preset=desktop"
      CHROME_PATH="$CHROME" node "$LH" "$base$path" --quiet --only-categories=accessibility \
        --output=json --output-path="$OUT/$side-$name-$profile.json" \
        --chrome-path="$CHROME" --chrome-flags="$FLAGS" $extra >/dev/null 2>&1 \
        || echo "FAILED $side $name $profile"
    done
  done
done
node -e '
const fs=require("fs"),d=process.argv[1];
for (const f of fs.readdirSync(d).filter(f=>f.endsWith(".json")).sort()) {
  const j=JSON.parse(fs.readFileSync(d+"/"+f));
  const a=j.audits, n=(k)=>a[k]?(a[k].score===null?"n/a":a[k].score===1?"pass":"FAIL("+((a[k].details||{}).items||[]).length+")"):"-";
  console.log(f.replace(".json","").padEnd(28), "score", Math.round(j.categories.accessibility.score*100), " color-contrast", n("color-contrast"), " target-size", n("target-size"));
}' "$OUT"
