#!/bin/sh
# Lane UG2 — boot the owner's rail: four demo tiles first, two real clips after.
#
# Same skeleton as tools/ug-preview.sh, and the same two instrument facts behind
# it: tools/m1-router.php serves real HTTP 206 (php -S does not implement Range
# and Chromium then calls a healthy clip unsupported), and the media is VP9 in
# WebM because Playwright's Chromium is the open-source build with no H.264.
#
# The DIFFERENCE is the seed: tools/ug2-seed.php reproduces the MIX in his
# screenshot rather than a tidy rail, because the mix is where the failure is.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ug2-preview${UG2_SUFFIX:-}
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8989}
MEDIA=$APP/storage/framework/testing/ug2-media

rm -rf "$DIR"
mkdir -p "$ROOT/uploads/ugc"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
[ -f "$MEDIA/real-a.webm" ] || sh "$APP/tools/ug2-media.sh" "$MEDIA" >/dev/null
cp "$MEDIA"/* "$ROOT/uploads/ugc/"
: > "$DB"

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=6 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
mkdir -p "$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker "$APP/tools/ug2-seed.php" >>"$DIR/migrate.log" 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/ug2-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -40 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
