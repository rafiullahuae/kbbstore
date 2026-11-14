#!/bin/bash
# Sequential, polite fetch of EN + AR product pages; cached. A bot-check page
# ("One moment, please...") is discarded and retried after a pause.
D=$1
mkdir -p "$D/pages"
for f in "$D"/pages/*.html; do
  if grep -q "One moment, please" "$f" 2>/dev/null; then rm -f "$f"; fi
done
while read -r p; do
  slug=$(basename "$p")
  for loc in en ar; do
    f="$D/pages/$loc-$slug.html"
    [ -s "$f" ] && continue
    if [ "$loc" = en ]; then u="https://kbeautybliss.com$p"; else u="https://kbeautybliss.com/ar$p"; fi
    for try in 1 2 3 4; do
      code=$(curl -sS --compressed -A "Mozilla/5.0 (compatible; KBB-ar-audit)" -o "$f" -w "%{http_code}" "$u")
      if [ "$code" = 200 ] && ! grep -q "One moment, please" "$f"; then break; fi
      rm -f "$f"
      sleep $((try * 15))
    done
    [ -s "$f" ] || echo "$code $u" >> "$D/fetch-errors.txt"
    sleep 1
  done
done < "$D/all-products.txt"
echo DONE > "$D/fetch-done.txt"
