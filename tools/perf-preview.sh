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
LABEL=${3:-tree}
DIR=$APP/storage/framework/testing/lane-perf-preview-$LABEL
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8991}
# An optional git ref to serve INSTEAD of the working tree. This is what makes
# the before/after honest on a container that six other lanes are sharing: both
# trees run at once, on the same machine, and the Lighthouse runs alternate
# between them, so machine drift lands on both halves rather than on one.
REF=${2:-}

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"

if [ -n "$REF" ]; then
  # `git archive` and not a checkout: it writes a tree without touching the
  # index, so it cannot disturb the working copy this lane is editing, and
  # public/build comes out as that commit built it.
  git -C "$APP" archive "$REF" | tar -x -C "$SRC"
  # tools/ is this lane's instrument and must be the SAME instrument on both
  # halves, so it is overlaid from the working tree rather than taken from the
  # ref -- a fixture that differs between before and after measures nothing.
  rm -rf "$SRC/tools"
  cp -a "$APP/tools" "$SRC/tools"
else
  for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock; do
    cp -a "$APP/$part" "$SRC/$part"
  done
  cp -a "$APP/app" "$SRC/app"
fi
ln -sfn "$APP/vendor" "$SRC/vendor"
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" "$SRC/storage/app/public" \
         "$SRC/bootstrap/cache"

ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$SRC/public/build" "$ROOT/build"
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
