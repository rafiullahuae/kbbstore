#!/bin/sh
# Lane CS: a preview of Appearance -> Coming Soon page, with extrabeauty.ae as
# the main address and kbeautybliss.com pointed at the same app.
#   sh tools/cs-preview.sh [port]
# Reach it as http://extrabeauty.ae:<port>/ and http://kbeautybliss.com:<port>/
# through Chromium's --host-resolver-rules (tools/cs-shots.cjs does).
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-cs-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-11470}" <<'KBBPORTPY'
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
if [ -f "$DIR/server.pid" ]; then kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; fi
rm -rf "$DIR"
mkdir -p "$ROOT" "$DIR/compiled"
cp "$APP/public-web-root/index.php" "$APP/public-web-root/favicon.ico" "$ROOT/" 2>/dev/null || cp "$APP/public-web-root/index.php" "$ROOT/"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
: > "$DB"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"

cat > "$DIR/env.sh" <<ENVSH
export KBB_PUBLIC_PATH='$ROOT' APP_ENV=local APP_DEBUG=true APP_URL=https://extrabeauty.ae
export DB_CONNECTION=sqlite DB_DATABASE='$DB' SESSION_DRIVER=file CACHE_STORE=file PHP_CLI_SERVER_WORKERS=4
export APP_CONFIG_CACHE='$DIR/compiled/config.php' APP_ROUTES_CACHE='$DIR/compiled/routes.php' APP_EVENTS_CACHE='$DIR/compiled/events.php' APP_SERVICES_CACHE='$DIR/compiled/services.php' APP_PACKAGES_CACHE='$DIR/compiled/packages.php'
ENVSH
. "$DIR/env.sh"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" db:seed --force >>"$DIR/migrate.log" 2>&1 </dev/null || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$APP/tools/cs-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null || { tail -30 "$DIR/migrate.log"; exit 1; }
grep -E "cs seed" "$DIR/migrate.log" || tail -6 "$DIR/migrate.log"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$APP/tools/cs-router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: the server on $PORT is not this preview" >&2; kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; exit 4
fi
echo "preview on http://127.0.0.1:$PORT dir $DIR pid $(cat "$DIR/server.pid")"
