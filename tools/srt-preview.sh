#!/bin/sh
# Lane SR (spelling mistakes in search, 9 October) preview: the corrected search against a seeded catalogue.
# usage: tools/srt-preview.sh <start port> <sqlite file>   (the file must already
# be migrated and seeded; the lane used DemoCatalogueSeeder plus Laneige and
# Some By Mi). The port is where the search for a free one STARTS; the script
# takes the first that binds and proves the server answering is its own before
# it prints the URL and the PID. Stop it with `kill <pid>`.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=$(python3 - "${1:-11520}" <<'KBBPORTPY'
import socket, sys
start = int(sys.argv[1])
for p in range(start, start + 400):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', p)); s.close(); print(p); break
    except OSError:
        s.close()
else:
    raise SystemExit('no free port')
KBBPORTPY
)
DIR=$APP/storage/framework/testing/lane-srt-preview-$PORT
ROOT=$DIR/webroot
if [ -f "$DIR/server.pid" ]; then kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; fi
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
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: the server on $PORT is not this preview" >&2; kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; exit 4
fi
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")"
