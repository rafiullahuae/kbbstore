#!/bin/sh
# Boot a preview for the Lane SF screenshots.
# Modelled on tools/sp-preview.sh, which is modelled on tools/set-preview.sh.
set -e
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-sf-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8993}

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

# ── NO ROUTE FILE TO MOUNT ──────────────────────────────────────────────────
#
# An earlier draft of this lane added an admin screen and mounted its route
# file here, because routes/web.php is the INTEGRATOR's file and a lane may not
# edit it. The owner then chose the design outright, the screen went, and so
# did the block -- it is recorded here because the pattern is the right one the
# next time a lane needs a route the integrator has not wired yet. See
# tools/sp-preview.sh, which still does it.
#
# This lane changes only storefront Blade and one service, so the preview needs
# nothing but the repository's own routes.
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
php "$APP/artisan" tinker "$APP/tools/sf-seed.php" >>"$DIR/migrate.log" 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/sf-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

# ── THE COMPILED ROUTE TABLE HAS TO GO, OR THE BLOCK ABOVE DOES NOTHING ─────
#
# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file at $APP_ROUTES_CACHE. A compiled table
# is a CompiledRouteCollection, and routes registered at runtime against one of
# those are never matched -- measured: with the file in place even a one-line
# marker route 404'd.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
