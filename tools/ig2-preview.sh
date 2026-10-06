#!/bin/sh
# Lane SG (IG2) — boot a seeded preview of /kbeautybliss-spotted/ for the
# screenshots: tools/ig2-seed.php's synced @kbeauty.bliss account.
#
#   sh tools/ig2-preview.sh [port] [label] [app-dir]
#
# app-dir defaults to this script's own tree; pass a `git archive` of another
# ref (with vendor/ symlinked) to shoot the "before". IG2_CARD=a|b|c|d picks
# the card style. Modelled on tools/hs-preview.sh, including its port walk and
# its "is this server really mine" nonce check. No route is patched here: every
# route this lane adds is in routes/spotted-admin.php, which is already mounted.
set -e
SELF=$(cd "$(dirname "$0")/.." && pwd)
LABEL=${2:-tree}
APP=${3:-$SELF}
DIR=$SELF/storage/framework/testing/ig2-preview-$LABEL
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-10470}" <<'KBBPORTPY'
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
cp "$SELF/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"

export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php" \
  VIEW_COMPILED_PATH="$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$SELF/tools/ig2-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -2 "$DIR/migrate.log"
rm -f "$DIR/compiled/routes.php"

cp "$SELF/tools/m1-router.php" "$ROOT/router.php"
( cd "$DIR" && php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 & echo $! > "$DIR/server.pid" )
sleep 2
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: 127.0.0.1:$PORT is not this preview's server." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  label $LABEL"
