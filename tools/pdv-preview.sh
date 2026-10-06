#!/bin/sh
# Boot a preview for the Lane PD (Devices) screenshots: Growth & Marketing -> Push
# Notifications, every tab, with 64 seeded phones across the emirates.
#
# Lane PN adds routes and a console screen that only the integrator mounts, so
# this preview needs them wired. It does NOT edit the integrator's files itself:
#     php tools/pn-wire.php
# first, take the shots (node tools/pn-shots.cjs <url> docs/pn-shots), then
#     git checkout -- routes/web.php resources/views/admin/app.blade.php \
#         tests/Feature/AdminSidebarIsCompleteAtBuildTest.php \
#         docs/T1B-ADMIN-APP-BLOCKS.md docs/BG-ADMIN-APP-BLOCKS.md
# It refuses to start when the wiring is absent, rather than photographing a
# console with no Pagination row.
#
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/pdv-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
# ▲ A PORT NOBODY ELSE IS ON. Three lanes run at once and this machine had
# stale preview servers on 8993 and 8994 from other branches; php -S then fails
# to bind, says so only in its log, and the shots are taken against ANOTHER
# lane's application -- which is how the first run of this harness photographed
# somebody else's "Glow Starter Set". The script now stops rather than shooting
# blind.
# ── THE PORT IS ASKED FOR, NOT GUESSED ──────────────────────────────────────
#
# This read `PORT=${1:-8710}` and used it without checking. Thirty-nine preview
# scripts share this repository and six of them defaulted to 8991, five to 8989
# and four to 8977, so two lanes colliding was not bad luck, it was the design.
#
# It fails in the way that costs the most: `php -S` prints "Address already in
# use" into its own log, the script reports a URL anyway, and the shot run that
# follows photographs WHOEVER IS ALREADY ON THAT PORT. Lane BG lost twenty
# minutes to exactly that -- a shop with the wrong catalogue in it and a 404 on
# a product that was certainly in the database -- and a leaked server outlives
# the run that started it, so the collision is permanent for everybody once it
# happens.
#
# tests/Support/PreviewPort.php is the same fix for the suite and carries the
# same story. Here it is: walk up from the requested port and take the first one
# that will actually bind.
PORT=$(python3 - "${1:-10660}" <<'KBBPORTPY'
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
if ! grep -q "push-admin.php" "$APP/routes/web.php" || ! grep -q "admin.partials.push-notifications-screen" "$APP/resources/views/admin/app.blade.php"; then
  echo "REFUSING TO START: the Lane PN wiring is not applied. Run: php tools/pn-wire.php" >&2
  exit 3
fi
rm -rf "$DIR"
mkdir -p "$ROOT" "$DIR/compiled"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

# ── THE PREVIEW MOUNTS routes/push-admin.php FOR ITSELF ───────────
# routes/web.php is the integrator's file (tools/pn-wire.php adds the require),
# so the PREVIEW's copy of the front controller -- never the repository's --
# mounts it with the real admin-api stack, as tools/sp-preview.sh does. Once
# web.php carries the require, the mount is skipped.
python3 - "$ROOT/index.php" <<'PATCH'
import sys
p = sys.argv[1]
s = open(p).read()
old = "(require_once $base.'/bootstrap/app.php')\n    ->handleRequest(Request::capture());"
new = """$kbbApp = require_once $base.'/bootstrap/app.php';

$kbbApp->booted(function ($app) {
    // Lane PD preview only: the push services answer from here, never the
    // network -- FCM accepts (201), Mozilla's answers 410 (an uninstalled
    // app) -- so "Send test" runs the real code path end to end.
    \\Illuminate\\Support\\Facades\\Http::fake(function ($r) {
        return \\Illuminate\\Support\\Facades\\Http::response('', str_contains($r->url(), 'mozilla.com') ? 410 : 201);
    });
    if (! str_contains((string) file_get_contents(base_path('routes/web.php')), 'push-admin.php')) {
        \\Illuminate\\Support\\Facades\\Route::middleware(['web', 'auth:admin', \\App\\Http\\Middleware\\NoStoreAdminApi::class])
            ->prefix('admin-api')
            ->group(base_path('routes/push-admin.php'));
    }
});

$kbbApp->handleRequest(Request::capture());"""
assert s.count(old) == 1, 'front controller shape changed'
open(p, 'w').write(s.replace(old, new, 1))
PATCH
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

# APP_URL is the preview's own address, so a media path chosen in the picker
# resolves to a picture this server can serve. Left at the packaged default it
# is http://localhost/, and every image in the screenshots is a broken icon.
export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$APP/tools/pn-seed.php'; require '$APP/tools/pdv-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -6 "$DIR/migrate.log"

# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file. Left in place it is the table the
# server serves, which is a table built before the seed -- tools/sp-preview.sh
# carries the measurement.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then
  echo "REFUSING TO CONTINUE: port $PORT is already taken by another process." >&2
  echo "Pass a free port: sh tools/rf-preview.sh 9861" >&2
  exit 1
fi

# ── AND IT PROVES THE SERVER ANSWERING IS THE ONE THIS SCRIPT STARTED ───────
#
# A 200 is not enough. A dead bind leaves ANOTHER LANE's preview answering on
# this port, and that server returns 200 to everything -- so a run that checks
# only the status code goes on to photograph somebody else's shop and looks
# completely finished doing it. Lane PN2 caught this with a request for a slug
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
