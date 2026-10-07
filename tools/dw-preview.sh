#!/bin/sh
# Lane DW: a preview of Platform -> Domain switch with the OUTSIDE WORLD FAKED AT
# THE BOUNDARY ONLY (tools/dw-router.php): the public DNS resolver, the TLS
# fetch of https://kbeautybliss.com/robots.txt, Stripe, Tabby, Tamara and the
# WordPress picture host answer from $DIR/scenario.json, which tools/dw-e2e.cjs
# rewrites between steps. Everything inside the shop is the real code.
#
#   sh tools/dw-preview.sh [port]      -> prints "preview on http://127.0.0.1:<port> dir <dir>"
#   admin  owner@example.com / preview-password   (Full Admin)
#          manager@example.com / preview-password (Store Manager -- must get 403)
#
# Needs the wiring applied (php tools/dw-wire.php) so the console includes the
# screen; revert routes/web.php and app.blade.php afterwards.
#
# The browser reaches the shop as extrabeauty.ae and kbeautybliss.com through
# Chromium's --host-resolver-rules, and the router plays Cloudways' TLS-ending
# proxy for those two names (HTTPS on, port 443), so "served over https on the
# new address" is exercised exactly as on the server. APP_URL lives in
# $DIR/.env and is NOT exported, so "Use this address" really rewrites it.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/dw-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-10780}" <<'KBBPORTPY'
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
cp "$APP/public-web-root/index.php" "$APP/public-web-root/favicon.ico" "$ROOT/"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
: > "$DB"

KEY=$(grep '^APP_KEY=' "$APP/.env" | head -1 | cut -d= -f2-)
printf 'APP_KEY=%s\nAPP_URL=https://extrabeauty.ae\n' "$KEY" > "$DIR/.env"
printf '{"dns":{},"tls":"nodns","stripe":"ok","tabby":"ok","tamara":"ok"}\n' > "$DIR/scenario.json"
: > "$DIR/outbound.log"

export APP_KEY="$KEY" KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 DW_PREVIEW_DIR="$DIR" DW_APP="$APP" \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

APP_URL=https://extrabeauty.ae php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
APP_URL=https://extrabeauty.ae php "$APP/artisan" tinker --execute="require '"$APP"/tools/dw-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -4 "$DIR/migrate.log"
rm -f "$DIR/compiled/"*.php

# What tools/dw-e2e.cjs needs to run a database step (the "owner fixed it" moment).
cat > "$DIR/env.sh" <<ENVSH
export APP_KEY='$KEY' KBB_PUBLIC_PATH='$ROOT' APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE='$DB' SESSION_DRIVER=file CACHE_STORE=file
export APP_CONFIG_CACHE='$DIR/compiled/config.php' APP_ROUTES_CACHE='$DIR/compiled/routes.php' APP_EVENTS_CACHE='$DIR/compiled/events.php' APP_SERVICES_CACHE='$DIR/compiled/services.php' APP_PACKAGES_CACHE='$DIR/compiled/packages.php'
ENVSH

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$APP/tools/dw-router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then echo "port $PORT taken" >&2; exit 1; fi
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: the server on $PORT is not this preview" >&2; kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; exit 4
fi
echo "preview on http://127.0.0.1:$PORT dir $DIR pid $(cat "$DIR/server.pid")"
