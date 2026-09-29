#!/bin/sh
# Lane PERF — boot a local copy of the storefront that reproduces the homepage
# PageSpeed Insights measured on extrabeauty.ae, so every number in this lane's
# report is a before/after pair taken on the same instrument.
#
# Modelled on tools/bn-preview.sh, including the reason it serves a THROWAWAY
# COPY: the copy is what gets mutated for an A/B run, never the worktree.
# No route is patched here — this lane adds no route.
#
# APP IS DERIVED FROM THIS SCRIPT'S OWN LOCATION, never hardcoded.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-perf-preview
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8991}

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"

for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock; do
  cp -a "$APP/$part" "$SRC/$part"
done
cp -a "$APP/app" "$SRC/app"
ln -sfn "$APP/vendor" "$SRC/vendor"
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" "$SRC/storage/app/public" \
         "$SRC/bootstrap/cache"

ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build"
mkdir -p "$ROOT/uploads"
cp "$APP/public-web-root/favicon.ico" "$ROOT/favicon.ico"
: > "$DB"

export APP_ENV=local APP_DEBUG=false APP_URL="http://127.0.0.1:$PORT" \
  APP_KEY=base64:bGFuZXBlcmZsYW5lcGVyZmxhbmVwZXJmbGFuZXBlcmY= \
  KBB_PUBLIC_PATH="$ROOT" \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=6 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -40 "$DIR/migrate.log"; exit 1; }
php "$SRC/artisan" db:seed --class=DemoCatalogueSeeder --force >>"$DIR/migrate.log" 2>&1 || true
php "$SRC/artisan" tinker --execute="require '$APP/tools/perf-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -40 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/perf-router.php" "$ROOT/router.php"

php -d zlib.output_compression=1 -d zlib.output_compression_level=6 -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  app $SRC"
