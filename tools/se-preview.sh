#!/bin/sh
# Boot a preview of the Set for the Lane SET screenshots.
# Modelled on tools/cp-preview.sh.
set -e
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
# Same fix, and the same reasoning, as tools/cp-preview.sh and
# tools/px-progress-preview.sh.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-se-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8989}

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

# ── THE PREVIEW REGISTERS routes/sets-admin.php FOR ITSELF ───────────────────
#
# routes/web.php is the INTEGRATOR's file and this lane may not edit it, so the
# six Catalog -> Sets endpoints are not in the route table of a fresh checkout.
# Without them the Sets screen answers "not in this server's compiled route
# table yet" and the screenshots would show an error rather than the feature.
#
# So the PREVIEW's COPY of the front controller -- a file under
# storage/framework/testing, never the repository's own -- mounts the real route
# file, with the real middleware stack the integrator's require will give it:
# `web`, `auth:admin` and NoStoreAdminApi, under the admin-api prefix. It is the
# same mounting tests/Support/SetsAdminRoutes.php does for the suite.
#
# The moment routes/web.php carries the require this block is dead weight and
# can go; SetRoutesWiredTest is the pin that says when.
python3 - "$ROOT/index.php" <<'PATCH'
import sys
p = sys.argv[1]
s = open(p).read()
old = "(require_once $base.'/bootstrap/app.php')\n    ->handleRequest(Request::capture());"
new = """$kbbApp = require_once $base.'/bootstrap/app.php';

$kbbApp->booted(function ($app) {
    \\Illuminate\\Support\\Facades\\Route::middleware(['web', 'auth:admin', \\App\\Http\\Middleware\\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/sets-admin.php'));
});

$kbbApp->handleRequest(Request::capture());"""
assert s.count(old) == 1, 'front controller shape changed'
open(p, 'w').write(s.replace(old, new, 1))
PATCH
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
php "$APP/artisan" tinker "$APP/tools/se-seed.php" >>"$DIR/migrate.log" 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/se-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

# ── THE COMPILED ROUTE TABLE HAS TO GO, OR THE BLOCK ABOVE DOES NOTHING ─────
#
# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file at $APP_ROUTES_CACHE. A compiled table
# is a CompiledRouteCollection, and routes registered at runtime against one of
# those are never matched -- which is the same trap
# tests/Support/SetsAdminRoutes.php copies its way out of. Measured here: with
# the file in place even a one-line marker route 404'd.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
