#!/bin/sh
# Boot a preview for Lane RC: the product page share icon's bottom sheet and the
# JPEG share image behind og:image.
#
#   sh tools/rc-preview.sh 9900                          AFTER (this tree)
#   RC_APP=/path/to/base-tree sh tools/rc-preview.sh 9910 before
#                                                        BEFORE (the base)
#
# A copy of tools/py-preview.sh (Lane PY) with Lane RC's seed. The application
# it boots can be another tree (RC_APP), so the BEFORE <head> is the base code
# the owner is running; the seed is tools/rc-seed.php from THIS tree whichever
# app it seeds. The port walk and the nonce probe that refuses to photograph
# another lane's server are that script's, for that script's reasons.
set -e
HERE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
APP=${RC_APP:-$HERE}
NAME=${2:-after}
DIR=$HERE/storage/framework/testing/lane-rc-$NAME
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-9900}" <<'KBBPORTPY'
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
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

export APP_URL="http://127.0.0.1:$PORT" RC_SITE_URL="http://127.0.0.1:$PORT"
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

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$HERE/tools/rc-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
grep -E "rc seed" "$DIR/migrate.log" || tail -6 "$DIR/migrate.log"

rm -f "$DIR/compiled/routes.php"

cp "$HERE/tools/m1-router.php" "$ROOT/router.php"
# index.php finds the application through this link; the preview's webroot is
# outside the app, as public_html is on the live host.
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then
  echo "REFUSING TO CONTINUE: port $PORT is already taken by another process." >&2
  exit 1
fi

kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"

if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING TO HAND BACK A PREVIEW: 127.0.0.1:$PORT is serving another webroot." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  app $APP"
