#!/bin/sh
# Boot a preview for the Lane OHB screenshots (Appearance -> Occasion banner).
#   sh tools/ohb-preview.sh [port]      default port 11900 (walks up to a free one)
#   OHB_SEED=tools/dnd-seed.php   (Lane DND: the shipped hand-made cut-outs)
#   OHB_DISPLAY=both|banner|slider OHB_LAYOUT=G|H|I OHB_TEMPLATE=1111|uae|ny|super
#
# Modelled on tools/sot-preview.sh: its own directory, its own port range, the
# nonce probe that proves the server answering is the one started here. The
# seed (tools/ohb-seed.php) makes real cut-outs with ProductCutouts::make().
#
# THE ROUTES AND THE SCREEN ARE NOT WIRED ON THIS BRANCH (the integrator adds
# the two lines). With OHB_WIRE=1 this script adds them to the worktree for the
# preview and tools/ohb-preview-stop.sh takes them out again; never commit the
# two files while a preview is running.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ohb-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-11900}" <<'KBBPORTPY'
import socket, sys

start = int(sys.argv[1])

for p in range(start, start + 100):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', p)); s.close(); print(p); break
    except OSError:
        s.close()
else:
    raise SystemExit('no free port in [%d, %d)' % (start, start + 100))
KBBPORTPY
)
if [ "${OHB_WIRE:-0}" = "1" ]; then
  grep -q "occasion-banner-admin.php" "$APP/routes/web.php" || \
    sed -i "s#^        require __DIR__.'/homepage-hub-admin.php';#        require __DIR__.'/homepage-hub-admin.php';\n        require __DIR__.'/occasion-banner-admin.php';#" "$APP/routes/web.php"
  grep -q "occasion-banner-screen" "$APP/resources/views/admin/app.blade.php" || \
    sed -i "s#^@include('admin.partials.homepage-hub')#@include('admin.partials.homepage-hub')\n@include('admin.partials.occasion-banner-screen')#" "$APP/resources/views/admin/app.blade.php"
fi
rm -rf "$DIR"
mkdir -p "$ROOT" "$DIR/compiled"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

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
php "$APP/artisan" tinker --execute="require '$APP/${OHB_SEED:-tools/ohb-seed.php}';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -12 "$DIR/migrate.log"
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING TO HAND BACK A PREVIEW: 127.0.0.1:$PORT is not the server this script started." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
