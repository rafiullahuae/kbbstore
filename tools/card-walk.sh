#!/bin/sh
# Every page of this shop that draws a product tile, measured at three widths.
#
# "apply this everywhere" is the owner's sentence, and it is a bigger claim than
# the eight URLs this script used to hold. THE EIGHT WERE THE PAGES SOMEBODY
# THOUGHT OF; the list below is every page reached by enumerating the templates
# that draw a tile and then every controller that reaches one. Seven surfaces
# were added on the third pass and not one of them had ever been measured:
#
#   /new-in /best-sellers /super-sale /everything-under-54-aed
#                             the four curated collections. They are NOT at
#                             /collections/<key>/ -- routes/web.php mounts each
#                             at its own top-level path, which is why a walk
#                             that guessed the URL got four 404s and printed
#                             "no product grid on this page" for all of them.
#   /concern/<slug>/          404 until MIN_PRODUCTS products are tagged
#   /routines/<slug>          a tile PER STEP and no .kbb-pgrid at all
#   /my-wishlist populated    tools/card-wishlist.cjs, because the guest page
#                             is the EMPTY state and measures as zero tiles
#
# Five of the seven are 404 or empty until tools/card-surfaces.php opens the
# gate, and a 404 measures as "no product grid on this page" -- which prints
# without a warning marker and reads exactly like a pass.
#
# THE BASKET IS IN THE LIST AND MUST STAY EMPTY OF TILES: he excluded the cart
# and the checkout by name ("exept cart and checkout pages") and that exclusion
# is worth measuring rather than remembering. The cart page's recommended rail
# draws its own card (.cpg-card, store/cart-inner.blade.php) and is reported by
# tools/card-measure.cjs under "NOT THE SHARED CARD" so it is a fact on the
# record rather than a surprise later.
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
  /new-in \
  /best-sellers \
  /super-sale \
  /everything-under-54-aed \
  /concern/hydration/ \
  /routines/hydration/ \
  /my-wishlist \
  /cart \
; do
  echo "" >> "$OUT"
  echo "############ $p" >> "$OUT"
  KBB_BASE=$BASE node "$APP/tools/card-measure.cjs" "$p" 320 390 1280 >> "$OUT" 2>&1
done

grep -E '^############|@[0-9]+px|card heights|no product grid|tiles on this page|NOT THE SHARED|columns=|OVERFLOW|NOT MEASURED' "$OUT"
echo ""
echo "full output: $OUT"
