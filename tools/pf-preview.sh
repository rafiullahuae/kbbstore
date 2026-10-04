#!/bin/sh
# Boot a preview for Lane PF (row 55 follow-up: section typography, speed,
# the Signature preset). Modelled on the integrator's int-364 preview.
#
#   APP=<tree> sh tools/pf-preview.sh [port]
#
# APP defaults to the tree this script lives in; pass another to boot a
# DIFFERENT checkout (the 2.60.370 "before" tree for the Lighthouse baseline)
# against the SAME seed, which is why the seed and the router are taken from
# this script's own directory rather than from $APP.
#
# The port is asked for and then proved: the first free port from the one
# given (default 9882), and a nonce written into this preview's webroot read
# back over HTTP, so a run can never photograph another lane's server.
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
APP=${APP:-$(cd "$HERE/.." && pwd)}
NAME=${PF_NAME:-pf-preview}
DIR=$APP/storage/framework/testing/$NAME
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-9882}" <<'KBBPORTPY'
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
: > "$DB"

export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=production APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$HERE/pf-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -3 "$DIR/migrate.log"
rm -f "$DIR/compiled/routes.php"

cp "$HERE/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then
  echo "REFUSING TO CONTINUE: port $PORT is already taken." >&2
  exit 1
fi

kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: the server on 127.0.0.1:$PORT is not the one this script started." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
