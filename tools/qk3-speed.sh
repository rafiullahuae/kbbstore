#!/bin/sh
# Lane QK3: median server time (curl time_starttransfer, 25 runs after 3 warm-ups)
# for product, category and brand pages WITH a basket, so the cart panel draws
# its footer and the coupon line.   sh tools/qk3-speed.sh BASE COOKIE
BASE=$1; CK=$2
for u in /product/barrier-repair-cream/ /collections/cleansers/ /brands/anua/; do
  for i in 1 2 3; do curl -s -o /dev/null -b "kbb_cart=$CK" "$BASE$u"; done
  T=$(for i in $(seq 25); do curl -s -o /dev/null -w '%{time_starttransfer}\n' -b "kbb_cart=$CK" "$BASE$u"; done | sort -n | sed -n 13p)
  B=$(curl -s -b "kbb_cart=$CK" "$BASE$u" | wc -c)
  L=$(curl -s -b "kbb_cart=$CK" "$BASE$u" | grep -c 'class="kc-cch"')
  echo "$u median_ms=$(python3 -c "print(round($T*1000,1))") html_bytes=$B coupon_line=$L"
done
