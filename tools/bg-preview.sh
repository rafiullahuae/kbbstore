#!/bin/sh
# Boot a preview for the Lane BG screenshots: Appearance -> Page background,
# and the five real storefront pages the owner asked to see the colour wash
# behind -- the home page, /shop/, a product, the cart and /skincare-guide/.
#
# THE PREVIEW IS THE REAL SHOP, which is the whole design of this lane: the
# wash is switched on by ?kbbwash=a|b|c|d on an ORDINARY storefront URL, and
# only for a request carrying an admin session. So this script needs nothing
# special beyond a seeded catalogue and an owner to sign in as.
#
# Modelled on tools/sa-preview.sh, which is modelled on tools/cp-preview.sh.
set -e
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
# Same fix, and the same reasoning, as tools/cp-preview.sh and
# tools/px-progress-preview.sh.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-bg-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
# ── THE PORT IS ASKED FOR, NOT GUESSED ──────────────────────────────────────
#
# Every other preview script here takes a literal default, and this lane paid
# for that within the hour: `php -S` answered "Address already in use", the
# script reported a URL anyway because it never checks, and the next twenty
# minutes were spent reading ANOTHER LANE'S LEAKED PREVIEW as though it were
# this one -- a shop with the wrong catalogue in it, seeded products missing,
# and a 404 on a product that was definitely in the database.
#
# tests/Support/PreviewPort.php is the same fix for the suite and carries the
# same story. Here it is six lines of shell: walk up from the requested port and
# take the first one that will actually bind.
PORT=$(python3 - "${1:-8931}" <<'PORTPY'
import socket, sys
start = int(sys.argv[1])
for p in range(start, start + 400):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', p)); s.close(); print(p); break
    except OSError:
        s.close()
else:
    raise SystemExit('no free port in [%d, %d)' % (start, start + 400))
PORTPY
)

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

# ── THE PREVIEW REGISTERS routes/page-wash-admin.php FOR ITSELF ─────────────
#
# routes/web.php is the INTEGRATOR's file and this lane may not edit it, so the
# two Appearance -> Page background endpoints are not in the route table of a
# fresh checkout. Without them the screen answers an error rather than drawing
# its controls, and the screenshots would show that instead of the feature.
#
# So the PREVIEW's COPY of the front controller -- a file under
# storage/framework/testing, never the repository's own -- mounts the real route
# file, with the real middleware stack the integrator's require will give it:
# `web`, `auth:admin` and NoStoreAdminApi, under the admin-api prefix. It is the
# same mounting tests/Support/PageWashRoutes.php does for the suite.
#
# The moment routes/web.php carries the require this block is dead weight and
# can go; EverythingIsMountedOnceTest is the pin that says when.
python3 - "$ROOT/index.php" <<'PATCH'
import sys
p = sys.argv[1]
s = open(p).read()
old = "(require_once $base.'/bootstrap/app.php')\n    ->handleRequest(Request::capture());"
new = """$kbbApp = require_once $base.'/bootstrap/app.php';

$kbbApp->booted(function ($app) {
    \\Illuminate\\Support\\Facades\\Route::middleware(['web', 'auth:admin', \\App\\Http\\Middleware\\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/page-wash-admin.php'));
});

$kbbApp->handleRequest(Request::capture());"""
assert s.count(old) == 1, 'front controller shape changed'
open(p, 'w').write(s.replace(old, new, 1))
PATCH
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

# ── A THROWAWAY APP_KEY, GENERATED HERE ─────────────────────────────────────
#
# A fresh worktree has no .env, and without an encryption key EVERY page of the
# preview is a 500 -- "No application encryption key has been specified", thrown
# out of the session middleware, so even the 404 page cannot render. The other
# preview scripts here inherit a key from the main checkout's .env and therefore
# never had to say so.
#
# It is GENERATED rather than copied. This server holds seeded fixtures and
# nothing else; borrowing the shop's real key would put a live credential into a
# throwaway process for no benefit at all, and the cookies this signs are worth
# exactly one screenshot run.
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export APP_KEY

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
# STDIN IS CLOSED ON BOTH ARMS. `artisan tinker <file>` runs the file and then
# drops into its REPL, which BLOCKS on stdin for ever whenever a terminal is
# attached: the log says the seed is done and the server never comes up.
# PreviewSeedCannotHangTest fails by name if either redirect is dropped.
php "$APP/artisan" tinker "$APP/tools/bg-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/bg-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
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
# REPORTED ONLY IF IT ANSWERS. The line below used to print unconditionally, so
# a server that failed to bind still produced a URL to point a browser at.
if ! curl -fsS -o /dev/null "http://127.0.0.1:$PORT/"; then
  echo "preview did NOT come up on $PORT:"; tail -20 "$DIR/server.log"; exit 1
fi
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
echo "stop it with: kill $(cat "$DIR/server.pid")   # NEVER pkill -f: three lanes share this machine"
