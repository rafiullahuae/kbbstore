#!/bin/sh
# Boot a preview for Lane HB: the banner slider's text box (styles A and D).
#
#   sh tools/hb-preview.sh 10460                     AFTER (this tree)
#   HB_APP=/path/to/base-tree sh tools/hb-preview.sh 10470 before
#                                                    BEFORE (the base code)
#
# A copy of tools/rc-preview.sh with Lane HB's seed: an owner, the Arabic shop
# switched on, and one published slider set of three pictures with words in
# English and Arabic. The pictures come from HB_PICS (1920x550 and 500x600
# JPEGs); the scenario for each screenshot is set with tools/hb-set.php, which
# reads the env file this script writes.
set -e
HERE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
APP=${HB_APP:-$HERE}
NAME=${2:-after}
DIR=$HERE/storage/framework/testing/lane-hb-$NAME
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

PORT=$(python3 - "${1:-10460}" <<'KBBPORTPY'
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
mkdir -p "$ROOT/uploads/banners"
cp "${HB_PICS:-/home/user/kbb-hb-previews/pics}"/hb-*.jpg "$ROOT/uploads/banners/"
: > "$DB"

APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
cat > "$DIR/env.sh" <<ENV
export APP_URL="http://127.0.0.1:$PORT" HB_SITE_URL="http://127.0.0.1:$PORT" APP_KEY="$APP_KEY"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true
export DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file PHP_CLI_SERVER_WORKERS=4
export APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php"
export APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php"
export APP_PACKAGES_CACHE="$DIR/compiled/packages.php" HB_APP_DIR="$APP"
ENV
. "$DIR/env.sh"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
# The demo catalogue, so the product, category and brand pages exist for the
# before/after speed numbers (tools/hb-speed.php).
php "$APP/artisan" db:seed --class=DemoCatalogueSeeder --force >>"$DIR/migrate.log" 2>&1 || true
php "$APP/artisan" tinker --execute="require '$HERE/tools/hb-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
grep -E "hb seed" "$DIR/migrate.log" || tail -6 "$DIR/migrate.log"

rm -f "$DIR/compiled/routes.php"

cp "$HERE/tools/m1-router.php" "$ROOT/router.php"
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
