#!/bin/sh
# Boot a preview for the Lane PT screenshots.
# Modelled on tools/sp-preview.sh, which is modelled on tools/cp-preview.sh.
set -e
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-pt-preview
ROOT=$DIR/webroot
VIEWS=$DIR/views
DB=$DIR/preview.sqlite
PORT=${1:-8977}

rm -rf "$DIR"
mkdir -p "$ROOT" "$VIEWS/admin"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

# ── THE PREVIEW WIRES THIS LANE UP FOR ITSELF, IN TWO PLACES ────────────────
#
# BOTH of the files the integrator has to touch are files CLAUDE.md forbids this
# lane from editing, so neither change is on the branch:
#
#   routes/web.php                        one require, inside the existing
#                                         admin-api group
#   resources/views/admin/app.blade.php   one @include, at the very end
#
# Without the first, the screen answers "not in this server's compiled route
# table yet". Without the second the screen is not in the console at all. The
# screenshots would then show an error, or nothing -- so the PREVIEW does both,
# in files under storage/framework/testing, never in the repository's own.
#
#   ROUTES. The preview's COPY of the front controller mounts the route file
#   with the real middleware stack the integrator's require will give it:
#   `web`, `auth:admin` and NoStoreAdminApi, under the admin-api prefix. It is
#   the same mounting tests/Support/ProductTabsAdminRoutes.php does for the
#   suite.
#
#   THE SCREEN. A COPY of admin/app.blade.php with the one @include appended is
#   written into $VIEWS, and $VIEWS is PREPENDED to the view finder's paths. A
#   view that exists in $VIEWS wins; everything else -- every other partial,
#   every storefront template -- falls through to the repository's own, so what
#   is photographed is the real console with one line added to it.
#
# The moment routes/web.php and app.blade.php carry those two lines, this block
# is dead weight and can go; the last case in ProductTabsTest is the pin that
# says when -- it is skipped until the wiring lands and asserts substr_count
# === 1 afterwards.
python3 - "$ROOT/index.php" "$VIEWS" <<'PATCH'
import sys
p, views = sys.argv[1], sys.argv[2]
s = open(p).read()
old = "(require_once $base.'/bootstrap/app.php')\n    ->handleRequest(Request::capture());"
new = """$kbbApp = require_once $base.'/bootstrap/app.php';

$kbbApp->booted(function ($app) {
    $kbbWired = str_contains(
        (string) @file_get_contents(base_path('routes/web.php')),
        "require __DIR__.'/product-tabs-admin.php';"
    );

    if (! $kbbWired) {
        \\Illuminate\\Support\\Facades\\Route::middleware(['web', 'auth:admin', \\App\\Http\\Middleware\\NoStoreAdminApi::class])
            ->prefix('admin-api')
            ->group(base_path('routes/product-tabs-admin.php'));
    }

    \\Illuminate\\Support\\Facades\\View::getFinder()->prependLocation('%%VIEWS%%');
});

$kbbApp->handleRequest(Request::capture());"""
new = new.replace('%%VIEWS%%', views)
assert s.count(old) == 1, 'front controller shape changed'
open(p, 'w').write(s.replace(old, new, 1))
PATCH

# The console shell, plus this lane's one @include. Appended at the very END,
# after that file closes its raw block, so the partial runs once window.go,
# window.kbbAddNavEntry and toast() are defined -- which is exactly where the
# integrator's line goes.
cp "$APP/resources/views/admin/app.blade.php" "$VIEWS/admin/app.blade.php"

# ── ONLY IF IT IS NOT ALREADY THERE ─────────────────────────────────────────
#
# The integrator has since landed both lines, so appending unconditionally
# would include the partial TWICE -- which registers the sidebar row twice and
# wraps window.go around its own wrapper. That is the exact shape CLAUDE.md
# names when it says to pin `substr_count(...) === 1` rather than an absence:
# zero is "built, never wired up" and two is this. The same guard is on the
# route mount in the front controller above, for the same reason.
if ! grep -q "admin.partials.product-tabs-screen" "$VIEWS/admin/app.blade.php"; then
  printf "\n@include('admin.partials.product-tabs-screen')\n" >> "$VIEWS/admin/app.blade.php"
fi

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
php "$APP/artisan" tinker "$APP/tools/pt-seed.php" >>"$DIR/migrate.log" 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/pt-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

# ── THE COMPILED ROUTE TABLE HAS TO GO, OR THE BLOCK ABOVE DOES NOTHING ─────
#
# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file. A compiled table is a
# CompiledRouteCollection, and routes registered at runtime against one of those
# are never matched -- measured: with the file in place even a one-line marker
# route 404'd.
rm -f "$DIR/compiled/routes.php"

# And the compiled VIEWS, for the same class of reason one layer up: a view is
# keyed by the path of its source, and the copy in $VIEWS is a different path
# from the original -- but a stale compiled copy of anything else in this tree
# would be served instead of a template this lane changed.
rm -f "$APP"/storage/framework/views/*.php 2>/dev/null || true

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
