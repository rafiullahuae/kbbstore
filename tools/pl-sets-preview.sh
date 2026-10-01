#!/bin/sh
# Boot a preview for Lane PL: "Set shown first, by brand" on Store -> Site
# Search -> Sets in search, and the search box honouring it. Seeded by
# tools/pl-sets-seed.php; driven by tools/pl-sets-shots.cjs. A copy of
# tools/pia-set-preview.sh with its own directory, seed and default port
# (9470), minus the product-editor wiring asserts, which this lane does not
# depend on. No route is mounted: the list rides admin-api/site-search.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-pl-sets-preview
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
# This read `PORT=${1:-8700}` and used it without checking. Thirty-nine preview
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
PORT=$(python3 - "${1:-9470}" <<'KBBPORTPY'
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
mkdir -p "$ROOT" "$DIR/compiled"
# ▲ ITS OWN storage/. The clear_caches_* migrations `migrate` runs unlink
# storage/framework/views/*.php, and in a worktree whose test suite is running
# at the same time those are the suite's compiled views. A private storage path
# keeps the preview's migrate from pulling files out from under it.
mkdir -p "$DIR/storage/framework/cache/data" "$DIR/storage/framework/sessions" \
  "$DIR/storage/framework/views" "$DIR/storage/logs" "$DIR/storage/app/public"
export LARAVEL_STORAGE_PATH="$DIR/storage"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
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
php "$APP/artisan" tinker --execute="require '$APP/tools/pl-sets-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -6 "$DIR/migrate.log"

# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file. Left in place it is the table the
# server serves, which is a table built before the seed -- tools/sp-preview.sh
# carries the measurement.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
# ▲ php -S DOES NOT PUT THE ENVIRONMENT IN $_SERVER OR $_ENV (measured: getenv()
# sees it, $_SERVER does not), and Laravel reads LARAVEL_STORAGE_PATH from
# those two only. Without this wrapper the server quietly used the worktree's
# own storage/ while `artisan` used the private one -- two caches, and the
# first run of this harness photographed a settings payload cached by an
# EARLIER preview: a COSRX choice the fresh seed never made.
cat > "$ROOT/pl-router.php" <<'KBBROUTER'
<?php
$_SERVER['LARAVEL_STORAGE_PATH'] = $_ENV['LARAVEL_STORAGE_PATH'] = getenv('LARAVEL_STORAGE_PATH');
return require __DIR__.'/router.php';
KBBROUTER
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/pl-router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then
  echo "REFUSING TO CONTINUE: port $PORT is already taken by another process." >&2
  echo "Pass a free port: sh tools/pl-sets-preview.sh 9471" >&2
  exit 1
fi

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
