#!/bin/sh
# Lane PG2: restart a tools/spd-preview.sh preview with a SMALL worker pool and
# a COLD picture cache, to see what after-response picture work does to the
# next request.
#
#   tools/pg2g-pool.sh LABEL WORKERS
#
# The preview's own img-cache links are removed (they are hard links to the
# seed's copies, so the files themselves are untouched) and its cache
# emptied, so every tile and share card is "missing" again, as on a catalogue
# whose copies are still being made. Same environment as spd-preview.sh.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
LABEL=$1
WORKERS=${2:-2}
DIR=$APP/storage/framework/testing/lane-spd-$LABEL
SRC=$DIR/app
ROOT=$DIR/webroot
PORT=$(cat "$DIR/port")
[ -f "$DIR/server.pid" ] && kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
sleep 1
rm -rf "$ROOT/img-cache"
rm -rf "$SRC/storage/framework/cache/data"/*
: > "$DIR/spd-after.log"
export APP_ENV=production APP_DEBUG=false APP_URL="http://127.0.0.1:$PORT" \
  APP_KEY=base64:bGFuZXBlcmZsYW5lcGVyZmxhbmVwZXJmbGFuZXBlcmY= \
  KBB_PUBLIC_PATH="$ROOT" \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=kbb_spd_$LABEL DB_USERNAME=kbb DB_PASSWORD=kbb \
  SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync LOG_LEVEL=error \
  PHP_CLI_SERVER_WORKERS=$WORKERS \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d memory_limit=512M \
  -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "pool $LABEL on $PORT with $WORKERS workers, pid $(cat "$DIR/server.pid")"
