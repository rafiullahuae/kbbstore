#!/bin/sh
# Boot a storefront preview for the Lane JS screenshots.
#
# Modelled on tools/sx-preview.sh, with ONE deliberate difference: that script
# hard-codes `APP=/home/user/lane-sx`, which makes it unrunnable from any other
# worktree and is exactly the line a reader copies. This one derives the app
# root from its own location, so it works wherever the lane is checked out.
#
# The app is served from a throwaway copy under storage/, so nothing the preview
# writes — its SQLite file, its compiled views, its placeholder PNGs — can reach
# the worktree. vendor/ is symlinked, so the classes it runs are this
# worktree's own, and public/build is copied so the shot is of the BUNDLE, not
# of resources/js. That is the whole point of this lane's evidence.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=${JS_DIR:-$APP/storage/framework/testing/lane-js-preview}
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8991}

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"

for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock .env; do
  cp -a "$APP/$part" "$SRC/$part"
done
cp -a "$APP/app" "$SRC/app"
ln -sfn "$APP/vendor" "$SRC/vendor"
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" "$SRC/storage/app/public" \
         "$SRC/bootstrap/cache"

ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads/js"

# Two pictures the seed points at. `injected.png` is the one that must NEVER
# be requested: it is what the hostile review photo address tries to load.
python3 - "$ROOT/uploads/js" <<'PY'
import base64, sys, pathlib
# 1x1 PNGs, one blue and one red, written without a dependency on an encoder.
blue = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')
red  = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
d = pathlib.Path(sys.argv[1])
(d / 'photo.png').write_bytes(blue)
(d / 'injected.png').write_bytes(red)
PY

: > "$DB"

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$SRC/artisan" db:seed --class=DemoCatalogueSeeder --force >>"$DIR/migrate.log" 2>&1 || true
php "$SRC/artisan" tinker --execute="require '$APP/tools/js-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -40 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  app $SRC"
