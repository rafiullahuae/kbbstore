#!/bin/sh
# Quiqup P3 (timeline) copy of tools/qq-preview.sh: also mounts
# routes/quiqup-timeline.php and seeds tools/qqt-seed.php (orders at several
# delivery states). Original header follows.
#
# Boot a preview for the Lane QQB (Quiqup) screenshots: Store -> Quiqup, the
# Orders selection bar with Schedule with Quiqup..., the side sheet and the
# Delivery card. Copied from tools/pdp-preview.sh (port probe, nonce check).
#
# Lane QQB may not edit routes/web.php or resources/views/admin/app.blade.php,
# so the PREVIEW (never the repository) does the integrator's wiring for
# itself: its own front controller mounts routes/quiqup-admin.php with the real
# admin-api stack and binds QqPreviewFake (tools/qq-preview-fake.php) so no
# request ever reaches Quiqup; and a COPY of app.blade.php under the preview
# directory, put first on the view path, carries the two @include lines, the
# TITLES row and the LATE_RENDERED entry exactly as the report asks for them.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-qqt-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-8961}" <<'KBBPORTPY'
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
KBBPORTPY
)
rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

# ── THE INTEGRATOR'S WIRING, DONE FOR THE PREVIEW ONLY (see the header) ──
mkdir -p "$DIR/views/admin"
python3 - "$APP/resources/views/admin/app.blade.php" "$DIR/views/admin/app.blade.php" <<'VIEWS'
import sys
s = open(sys.argv[1]).read()
if "@include('admin.partials.quiqup-screen')" in s:  # wired by the integrator (2.60.668): use it as it is
    open(sys.argv[2], 'w').write(s)
    raise SystemExit(0)
inc = "@include('admin.partials.order-release-hold')"
assert s.count(inc) == 1
s = s.replace(inc, inc + "\n@include('admin.partials.quiqup-screen')\n@include('admin.partials.quiqup-orders')", 1)
t = "'inquiries':['Store','Inquiries'],"
assert s.count(t) == 1
s = s.replace(t, t + "'quiqup':['Store','Quiqup'],", 1)
l = "'recentlocalise']);"
assert s.count(l) == 1
s = s.replace(l, "'recentlocalise','quiqup']);", 1)
open(sys.argv[2], 'w').write(s)
VIEWS
python3 - "$ROOT/index.php" "$DIR/views" <<'PATCH'
import sys
p = sys.argv[1]
s = open(p).read()
old = "(require_once $base.'/bootstrap/app.php')\n    ->handleRequest(Request::capture());"
new = """$kbbApp = require_once $base.'/bootstrap/app.php';
require_once $base.'/tools/qq-preview-fake.php';

$kbbApp->booted(function ($app) {
    \\Illuminate\\Support\\Facades\\View::prependLocation('%s');
    $app->bind(\\App\\Services\\Quiqup\\QuiqupClient::class, fn ($a) => new \\QqPreviewFake($a->make(\\App\\Services\\Quiqup\\QuiqupSettings::class)));
    if (! str_contains((string) file_get_contents(base_path('routes/web.php')), "/quiqup-admin.php'")) {
        \\Illuminate\\Support\\Facades\\Route::middleware(['web', 'auth:admin', \\App\\Http\\Middleware\\NoStoreAdminApi::class])
            ->prefix('admin-api')
            ->group(base_path('routes/quiqup-admin.php'));
    }
    if (! str_contains((string) file_get_contents(base_path('routes/web.php')), "/quiqup-timeline.php'")) {
        \\Illuminate\\Support\\Facades\\Route::middleware('web')->group(base_path('routes/quiqup-timeline.php'));
    }
});

$kbbApp->handleRequest(Request::capture());""" % sys.argv[2]
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
# </dev/null ON BOTH ARMS. `artisan tinker <file>` runs the file and then drops
# into its REPL, which BLOCKS on stdin for ever whenever a terminal is attached:
# the log says the seed is done and the server never comes up. (Lane UG3 found
# it in its own copy; Lane FIN swept the other eleven.)
php "$APP/artisan" tinker "$APP/tools/qq-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/qq-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker "$APP/tools/qqt-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
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
# ── AND IT PROVES THE SERVER ANSWERING IS THE ONE THIS SCRIPT STARTED ───────
#
# A 200 is not enough. A dead bind leaves ANOTHER LANE's preview answering on
# this port, and that server returns 200 to everything -- so a run that checks
# only the status code goes on to photograph somebody else's shop and looks
# completely finished doing it. Lane PG2 caught this with a request for a slug
# only its own seed creates; this is the same idea made general, so that every
# script gets it whether or not it has a slug of its own to ask for.
#
# A nonce is written into THIS script's webroot and read back over HTTP. Another
# lane's server is rooted in another lane's directory, so it cannot have the
# file: the probe fails and the script refuses rather than handing back a URL.
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"

if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING TO HAND BACK A PREVIEW: the server answering on 127.0.0.1:$PORT" >&2
  echo "is not the one this script started -- it is serving another webroot, so" >&2
  echo "anything shot against it would be somebody else's shop." >&2
  tail -10 "$DIR/server.log" >&2 2>/dev/null || true
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
