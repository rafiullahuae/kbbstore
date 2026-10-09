#!/bin/sh
# Lane SR (spelling mistakes in search, 9 October) preview: the corrected search against a seeded catalogue.
# usage: tools/srt-preview.sh <port> <sqlite file>   (the file must already be
# migrated and seeded; the lane used DemoCatalogueSeeder plus Laneige and
# Some By Mi). Prints the URL and the PID; stop it with `kill <pid>`.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-srt-preview-$1
ROOT=$DIR/webroot
rm -rf "$DIR"; mkdir -p "$ROOT" "$DIR/compiled"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
printf '<?php return %s;\n' "'$APP'" > "$ROOT/kbb-app-path.php"
cp -r "$APP/public/build" "$ROOT/build"
cp "$APP/tools/m1-router.php" "$ROOT/router.php"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE="$2" \
  SESSION_DRIVER=file CACHE_STORE=file PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php -S 127.0.0.1:"$1" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
nonce=$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n'); printf '%s' "$nonce" > "$ROOT/kbb-preview-id.txt"
[ "$(curl -s "http://127.0.0.1:$1/kbb-preview-id.txt")" = "$nonce" ] || { echo "port $1 is somebody else's" >&2; kill "$(cat "$DIR/server.pid")"; exit 4; }
echo "preview on http://127.0.0.1:$1  pid $(cat "$DIR/server.pid")"
