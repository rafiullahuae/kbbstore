#!/bin/sh
# The whole Lane SPL deliverable, from nothing: boot a preview with two sets on
# it, photograph today's "What is in this set" box and the three proposed
# treatments at 390 and 1280, build the contact sheets, then turn Arabic on and
# do the Arabic pass.
#
#   sh tools/spl-box-shoot.sh [port]
#
# ── THE ORDER MATTERS, AND IT IS THE ONLY REASON THIS IS A SCRIPT ───────────
#
# Arabic is a settings row, and turning it on is NOT invisible to the English
# pages: App\Support\Locale::enabledCodes() is what the language switcher and
# the hreflang tags read, so a shop with Arabic on renders chrome in its header
# that a shop with Arabic off does not. The English screenshots are the ones the
# owner picks from and they have to show the chrome this shop has TODAY. So
# English first, against a fixture with Arabic off, and the Arabic step second —
# which is a one-way door, hence a fresh preview each run.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-spl-preview
OUT=$APP/docs/lane-spl-shots
PORT=${1:-8917}

sh "$APP/tools/spl-preview.sh" "$PORT"
mkdir -p "$OUT"

node "$APP/tools/spl-box-shots.cjs" "http://127.0.0.1:$PORT" "$OUT"
node "$APP/tools/spl-box-sheet.cjs" "$OUT"

# ── ARABIC ─────────────────────────────────────────────────────────────────
# Run against the SAME preview database the server is serving, with the same
# environment spl-preview.sh exported — artisan would otherwise open
# database/testing.sqlite and write the settings row into a database nobody is
# reading. </dev/null because tinker reads stdin when it has one, and a shoot
# script that blocks on a prompt is a shoot script that never finishes.
KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local \
DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" \
SESSION_DRIVER=file CACHE_STORE=file \
APP_CONFIG_CACHE="$DIR/compiled/config.php" \
APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
APP_EVENTS_CACHE="$DIR/compiled/events.php" \
APP_SERVICES_CACHE="$DIR/compiled/services.php" \
APP_PACKAGES_CACHE="$DIR/compiled/packages.php" \
  php "$APP/artisan" tinker "$APP/tools/spl-arabic-on.php" </dev/null

node "$APP/tools/spl-box-shots.cjs" "http://127.0.0.1:$PORT" "$OUT" ar
node "$APP/tools/spl-box-sheet.cjs" "$OUT" ar-

# The whole-page shots are context, not the deliverable, and there is one per
# treatment per width in each language. Only the English 1280 pair is kept —
# enough to show the box in the buy column between the price and Add to cart,
# which is the thing a box shot cannot show.
rm -f "$OUT"/page-*-390.png "$OUT"/ar-page-*.png

kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
echo "shots in $OUT"
