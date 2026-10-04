#!/bin/sh
# Lane MK -- boot a preview of Marketing Emails, wired the way the integrator
# will wire it, for the screenshots in docs/mk-shots/.
#
#   sh tools/mk-preview.sh [port]        (default 9884)
#
# Modelled on the int-364 preview (scratchpad), with two differences:
#   1. the application served is a SHADOW (tools/mk-shadow.sh) carrying the
#      two wiring edits the lane may not make to routes/web.php and
#      resources/views/admin/app.blade.php itself;
#   2. the seed is tools/mk-seed.php: a catalogue with pictures, customers with
#      paid orders across the emirates, subscribers in every state, and
#      campaigns in every status.
# Derived from this script's own location, never hardcoded, so it travels with
# the branch. Stop it by its own PID only (CLAUDE.md: never pkill by pattern).
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/mk-preview
SH=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-9884}" <<'KBBPORTPY'
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
sh "$APP/tools/mk-shadow.sh" "$SH" >/dev/null
ln -sfn "$SH" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/wp-content/uploads/mk"
cp "$APP"/docs/rj-email-previews/assets/p-*-400.jpg "$ROOT/wp-content/uploads/mk/" 2>/dev/null || true
: > "$DB"

export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file MAIL_MAILER=log \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SH/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$SH/artisan" tinker --execute="require '$APP/tools/mk-seed.php';" >>"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -4 "$DIR/migrate.log"
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: the server on $PORT is not this preview." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi
echo "$PORT" > "$DIR/port"
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")"
