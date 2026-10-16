#!/bin/sh
# Lane SC: preview for Catalog -> Shop by Concern (tools/sc-seed.php). Mount the
# screen first (require routes/concern-sync-admin.php in the admin-api group,
# include the partial) -- tools/sc-shots.cjs photographs it.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-sc-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-9660}" <<'KBBPORTPY'
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
php "$APP/artisan" tinker --execute="require '$APP/tools/sc-seed.php';" >>"$DIR/migrate.log" 2>&1 \
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
cat > "$ROOT/sc-router.php" <<'KBBROUTER'
<?php
$_SERVER['LARAVEL_STORAGE_PATH'] = $_ENV['LARAVEL_STORAGE_PATH'] = getenv('LARAVEL_STORAGE_PATH');
return require __DIR__.'/router.php';
KBBROUTER
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/sc-router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then
  echo "REFUSING TO CONTINUE: port $PORT is already taken by another process." >&2
  echo "Pass a free port: sh tools/sc-preview.sh 9661" >&2
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
