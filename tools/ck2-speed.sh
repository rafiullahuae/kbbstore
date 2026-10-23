#!/bin/sh
# Lane CK2: base vs branch, alternating, ROUNDS rounds, one database copy.
#   sh tools/ck2-speed.sh <base tree> <branch tree> <sqlite> <cart token> [rounds] > out.txt
# Each line: round tree path ms queries(first) status mapReads queries(warm)
BASE=$1; NEW=$2; DB=$3; TOKEN=$4; ROUNDS=${5:-5}
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
r=1
while [ "$r" -le "$ROUNDS" ]; do
  for p in /product/1025-dokdo-toner/ /collections/cleansers/ /brands/anua/ /cart/ /checkout/; do
    order="base:$BASE branch:$NEW"; [ $((r % 2)) -eq 0 ] && order="branch:$NEW base:$BASE"
    for t in $order; do
      name=${t%%:*}; tree=${t#*:}
      echo "$r $name $p $(php "$HERE/ck2-speed.php" "$tree" "$DB" "$p" "${RUNS:-8}" "$TOKEN" 2>/dev/null | tail -1)"
    done
  done
  r=$((r + 1))
done
