#!/bin/sh
# Lane UG — boot a preview of the storefront shoppable-video rail.
#
# Mirrors tools/ugcloop-preview.sh, with two differences that matter:
#   - it seeds a STOREFRONT page carrying the real [kbb_videos] shortcode, so
#     what is measured is the shipped rail and not an admin preview of it;
#   - it copies real WebM/VP9 media into the web root, because Playwright's
#     Chromium has no H.264 (CLAUDE.md, instrument warnings).
#
# The router is tools/m1-router.php, which serves real HTTP 206 — `php -S`
# alone answers 200 with the whole body and Chromium then reports a healthy
# clip as MEDIA_ERR_SRC_NOT_SUPPORTED.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ug-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8961}
MEDIA=${UG_MEDIA:-$APP/storage/framework/testing/ug-media}

rm -rf "$DIR"
mkdir -p "$ROOT/uploads/ugc"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
cp "$MEDIA"/* "$ROOT/uploads/ugc/" 2>/dev/null || { echo "no media in $MEDIA"; exit 1; }
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
php "$APP/artisan" tinker "$APP/tools/ug-seed.php" >>"$DIR/migrate.log" 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/ug-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -40 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
