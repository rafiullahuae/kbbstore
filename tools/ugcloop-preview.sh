#!/bin/sh
# Boot a preview of THIS cart panel for the Lane CP screenshots.
# Mirrors tools/m1-preview.sh.
set -e
# DERIVED, NOT HARDCODED. This read `APP=/home/user/kbb-lane-cp` -- Lane CP's
# worktree, which was removed when its branch merged, so the script that makes
# this lane's evidence could not make it again. The screenshots are a
# deliverable; the thing that produces them has to travel with the branch.
# Same fix, and the same reasoning, as tools/px-progress-preview.sh.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ugcloop-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8957}

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
mkdir -p "$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
# </dev/null ON BOTH ARMS. `artisan tinker <file>` runs the file and then drops
# into its REPL, which BLOCKS on stdin for ever whenever a terminal is attached:
# the log says the seed is done and the server never comes up. (Lane UG3 found
# it in its own copy; Lane FIN swept the other eleven.)
php "$APP/artisan" tinker "$APP/tools/ugcloop-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/ugcloop-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
