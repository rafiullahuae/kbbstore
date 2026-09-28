#!/bin/sh
# Boot a storefront preview for the Lane SX screenshots.
#
# Modelled on tools/hl-preview.sh, WITHOUT its one complication: this lane
# changed only Blade templates and one class under app/Support, so no route has
# to be wired and routes/web.php is served exactly as it is tracked.
#
# The app is still served from a throwaway copy under storage/, so nothing the
# preview writes — its SQLite file, its compiled views, the four placeholder
# PNGs — can reach the worktree. vendor/ is symlinked, so the classes it runs
# are this worktree's own.
set -e
APP=/home/user/lane-sx
DIR=${SX_DIR:-$APP/storage/framework/testing/lane-sx-preview}
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8987}
IMG=${SX_IMG:-$DIR/img}

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
mkdir -p "$ROOT/uploads/sx"

# The four placeholder pictures the seed points at. `injected.png` is the one
# that must NEVER appear: it is what the address in tools/sx-seed.php tries to
# paint over the tile by closing the url( early.
if [ -d "$IMG" ]; then cp "$IMG"/*.png "$ROOT/uploads/sx/"; fi

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
php "$SRC/artisan" tinker --execute="require '$APP/tools/sx-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  app $SRC"
