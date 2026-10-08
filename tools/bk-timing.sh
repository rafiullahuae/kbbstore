#!/bin/sh
# Lane BK: server ms (median of 7, after one warm-up) and HTML bytes of the shop
# pages this lane must not slow down, on the BK preview. Usage: bk-timing.sh <port> <label>
PORT=${1:-8761}; LABEL=${2:-now}
UA='Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'
for p in / /product/bk-glass-skin-serum/ /collections/cleansers/ /brands/anua/ /cart/ /checkout/; do
  curl -s -o /dev/null -A "$UA" "http://127.0.0.1:$PORT$p"
  T=$(for i in 1 2 3 4 5 6 7; do curl -s -o /dev/null -A "$UA" -w '%{time_starttransfer}\n' "http://127.0.0.1:$PORT$p"; done | sort -n | sed -n 4p)
  B=$(curl -s -A "$UA" "http://127.0.0.1:$PORT$p" | wc -c)
  C=$(curl -s -o /dev/null -A "$UA" -w '%{http_code}' "http://127.0.0.1:$PORT$p")
  echo "$LABEL $p status=$C ttfb_ms=$(echo "$T*1000" | bc | cut -d. -f1) html_bytes=$B"
done
