#!/bin/sh
# Lane CC: interleaved Lighthouse A/B, preview cchead (:8840) vs ccnew (:8860), both from
# tools/spd-preview.sh with SPD_SEED=tools/cc-seed.php; storage/cc-logs/cookie.txt from tools/cc-cookie.php.
#   tools/cc-lh-ab.sh <tag> <runs> ["name:/path ..."] ["mobile desktop"]   then node tools/cc-lh-table.cjs <tag>
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd); cd "$APP"
export LH_DIR=${LH_DIR:-$APP/storage/cc-logs/lhtool} CHROME_PATH=/opt/pw-browsers/chromium
TAG=$1; N=${2:-5}
PAGES=${3:-"home:/ category:/collections/spd-dept-1/ shop:/shop/ brand:/brands/anua/ product:/product/spd-product-500/ blog:/blog/ cart:/cart/ checkout:/checkout/"}
PROFILES=${4:-"mobile desktop"}
C=$(cat storage/cc-logs/cookie.txt)
for pp in $PAGES; do u=${pp#*:}; for port in 8840 8860; do curl -s -o /dev/null -A Mozilla/5.0 -b "kbb_cart=$C" http://127.0.0.1:$port$u; done; done
i=1; while [ $i -le $N ]; do
  for pp in $PAGES; do name=${pp%%:*}; u=${pp#*:}
    case $name in cart|checkout) CK="kbb_cart=$C";; *) CK="";; esac
    for prof in $PROFILES; do
      LH_COOKIE=$CK node tools/lh-run.mjs $TAG-head-$name-$i $prof http://127.0.0.1:8840$u >/dev/null 2>&1 || echo "fail head $name $prof $i"
      LH_COOKIE=$CK node tools/lh-run.mjs $TAG-new-$name-$i $prof http://127.0.0.1:8860$u >/dev/null 2>&1 || echo "fail new $name $prof $i"
    done
  done
  echo "round $i done $(date +%T)"
  i=$((i+1))
done
echo AB-DONE
