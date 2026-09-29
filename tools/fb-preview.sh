#!/bin/sh
# Boot a preview of the flag bar for the Lane FB screenshots.
#
# Mirrors tools/bn-preview.sh and tools/hl-preview.sh, MINUS the two patches
# they carry: this lane adds no route file and no admin partial, so there is
# nothing for the integrator to wire and nothing for this script to fake. The
# copy under storage/ is the tracked tree exactly as it stands.
#
# It is still a THROWAWAY COPY rather than the worktree, for the reason those
# scripts give: the preview writes a database, uploads and compiled views, and
# a screenshot harness that leaves those behind in the tree is a harness that
# shows up in `git status` as a lane's work.
#
# vendor/ is symlinked rather than copied, so the copy costs nothing and the PHP
# classes it runs are this worktree's own.
#
# APP IS DERIVED FROM THIS SCRIPT'S OWN LOCATION, never hardcoded — two
# harnesses in this repository were unrunnable because their lane's worktree had
# been removed when its branch merged.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-fb-preview
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8978}

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

# The front controller finds the application by walking a candidate list; this
# is the name on it that puts the copy where it will look.
ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  APP_URL="http://127.0.0.1:$PORT" \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$SRC/artisan" db:seed --class=DemoCatalogueSeeder --force >>"$DIR/migrate.log" 2>&1 || true
php "$SRC/artisan" tinker --execute="require '$APP/tools/fb-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  app $SRC"
