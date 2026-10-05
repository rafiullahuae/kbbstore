#!/bin/sh
# Lane SO previews: the SAME shop on the base code and on this lane's code.
#
#   sh tools/so-preview.sh base  <port> <app-dir>   seed tools/so-seed.php on the
#                                                 app at <app-dir> (a worktree
#                                                 of the base commit)
#   sh tools/so-preview.sh after <port> <db-file>   copy that seeded database and
#                                                 run THIS lane's migrations over
#                                                 it, as the live upgrade does
#
# "after" is never seeded on the new code: the per-category and per-brand order
# must come from the migration, or the before/after comparison proves nothing.
set -e
MODE=${1:?base or after}
HERE=$(cd "$(dirname "$0")/.." && pwd)
if [ "$MODE" = base ]; then APP=${3:?app dir}; else APP=$HERE; fi
DIR=$APP/storage/framework/testing/so-preview-$MODE
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${2:-10560}" <<'KBBPORTPY'
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
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
if [ "$MODE" = after ]; then
  cp "${3:?db file}" "$DB"
  cp -r "$(dirname "$3")/webroot/uploads/." "$ROOT/uploads/" 2>/dev/null || true
else
  : > "$DB"
fi

export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=array \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
if [ "$MODE" = base ]; then
  php "$APP/artisan" tinker --execute="require '$HERE/tools/so-seed.php';" >>"$DIR/migrate.log" 2>&1 \
    || { tail -30 "$DIR/migrate.log"; exit 1; }
fi
tail -3 "$DIR/migrate.log"
rm -f "$DIR/compiled/routes.php"
php "$APP/artisan" view:clear >/dev/null 2>&1 || true

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then
  echo "REFUSING TO CONTINUE: port $PORT is already taken by another process." >&2
  echo "Pass a free port: sh tools/so-preview.sh base 10141" >&2
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
