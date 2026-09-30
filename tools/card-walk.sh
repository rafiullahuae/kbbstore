#!/bin/sh
# Every page of this shop that draws a product grid, measured at three widths.
#
# "apply this everywhere" is the owner's sentence, and the last round proved it
# page by page rather than assuming it. This is that walk: the eight URLs below
# are the four grid-emitting templates (docs/PG2-SHOWCASE-CARD.md §4 names them)
# reached through every controller that reaches one, plus the basket, which must
# have NO product tile on it at all -- he excluded the cart and the checkout by
# name and that exclusion is worth measuring rather than remembering.
#
# 320 IS IN THE LIST AND NOT ONLY 390. This shop has had a strip break at 320
# before, and the phone rail is two columns from 320 up, so 320 is the narrowest
# card the grid ever draws.
#
#   sh tools/card-walk.sh <port> [outfile]
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${1:-8970}
OUT=${2:-$APP/storage/card-logs/walk-$PORT.txt}
BASE=http://127.0.0.1:$PORT

: > "$OUT"

for p in \
  / \
  /shop/ \
  /collections/skincare-sets/ \
  /brands/medicube/ \
  "/shop/?s=cream" \
  /product/card-toner/ \
  /my-wishlist \
  /cart \
; do
  echo "" >> "$OUT"
  echo "############ $p" >> "$OUT"
  KBB_BASE=$BASE node "$APP/tools/card-measure.cjs" "$p" 320 390 1280 >> "$OUT" 2>&1
done

grep -E '^############|@[0-9]+px|card heights|no product grid|columns=|OVERFLOW|NOT MEASURED' "$OUT"
echo ""
echo "full output: $OUT"
