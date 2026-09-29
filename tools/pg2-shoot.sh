#!/bin/sh
# Lane PG2 — every treatment, both widths, from one preview and one fixture.
#
# The loop is the point: `grid_skin` is written between passes and the SAME
# page is re-rendered, so the five panels differ by the setting under test and
# by nothing else. A sheet assembled from five separately-booted previews could
# differ by the fixture as well, and nobody could tell which.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${KBB_PORT:-8931}
# One directory per port -- see tools/pg2-preview.sh for what a shared one cost.
DIR=$APP/storage/framework/testing/lane-pg2-preview-$PORT
OUT=${KBB_SHOTS:-$APP/docs/pg2-card-shots}
PAGE=${KBB_PAGE:-/collections/skincare-sets/}
SUFFIX=${KBB_SUFFIX:-}

export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite \
  DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

mkdir -p "$OUT"

for SKIN in ${KBB_SKINS:-classic showcase showcase-compact showcase-row showcase-airy}; do
  KBB_SKIN=$SKIN php "$APP/artisan" tinker "$APP/tools/pg2-set-skin.php" </dev/null >/dev/null 2>&1 \
    || KBB_SKIN=$SKIN php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-set-skin.php';" </dev/null >/dev/null 2>&1
  cat > "$DIR/plan.json" <<PLAN
[
  {"name":"$SKIN$SUFFIX-390","panel":"panel-$SKIN$SUFFIX-390","skin":"$SKIN","path":"$PAGE","w":390,"h":1500},
  {"name":"$SKIN$SUFFIX-1280","panel":"panel-$SKIN$SUFFIX-1280","skin":"$SKIN","path":"$PAGE","w":1280,"h":1400}
]
PLAN
  KBB_BASE="http://127.0.0.1:$PORT" KBB_SHOTS="$OUT" KBB_PLAN="$DIR/plan.json" \
    KBB_MEASURE="measure-$SKIN$SUFFIX" node "$APP/tools/pg2-card-shots.cjs"
done
