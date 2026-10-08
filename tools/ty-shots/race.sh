#!/bin/sh
# Lane TY: the compiled-view race, measured. Against a running preview
# (tools/ty-shots/preview.sh PORT), clear the compiled views and request the
# order-received page twice at once -- what the Site App's navigation preload
# makes Chrome do -- ROUNDS times, and count the bodies that are not the whole
# page. Bodies under storage/ty-logs/race-PORT/.
#   sh tools/ty-shots/race.sh PORT ORDER_NUMBER [ROUNDS]
set -e
LANE=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PORT=$1; NUM=$2; ROUNDS=${3:-400}
D=$LANE/storage/ty-logs/race-$PORT; rm -rf "$D"; mkdir -p "$D"
curl -s -o "$D/ref.html" "http://127.0.0.1:$PORT/__ty/received/$NUM"
REF=$(wc -c < "$D/ref.html"); bad=0; err=0; shape=0; i=0
while [ $i -lt "$ROUNDS" ]; do
  i=$((i+1)); rm -f "$LANE"/storage/framework/views/*.php
  curl -s -o "$D/a.html" "http://127.0.0.1:$PORT/__ty/received/$NUM" & curl -s -o "$D/b.html" "http://127.0.0.1:$PORT/__ty/received/$NUM" & wait
  for x in a b; do
    if [ "$(wc -c < "$D/$x.html")" -ne "$REF" ]; then
      bad=$((bad+1)); cp "$D/$x.html" "$D/bad-$i-$x.html"
      grep -q '<title>Server Error' "$D/$x.html" && err=$((err+1))
      grep -q 'Delivering to' "$D/$x.html" && ! grep -q 'class="co-totals"' "$D/$x.html" && shape=$((shape+1))
    fi
  done
done
echo "rounds=$ROUNDS bodies=$((ROUNDS*2)) wrong=$bad server_error=$err empty_your_order=$shape"
