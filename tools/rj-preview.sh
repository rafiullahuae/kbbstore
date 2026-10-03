#!/bin/sh
# Lane RJ preview: a throwaway SQLite shop seeded by tools/rj-seed.php, served
# by `php -S` on a port this script has actually bound, plus an env file the
# email renderer (tools/rj-render-emails.php) sources so it renders against
# the SAME database the admin screenshots are taken from.
#
#   sh tools/rj-preview.sh [start-port]      -> prints URL and PID
#   kill "$(cat storage/framework/testing/lane-rj-preview/server.pid)"   # stop it
#
# Modelled on tools/rg-preview.sh. Derived from its own location, never a
# hardcoded worktree path. Kill only the PID it prints — never pkill a pattern.
set -e

APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-rj-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-9940}" <<'KBBPORTPY'
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

rm -rf "$DIR"
mkdir -p "$ROOT" "$DIR/compiled" "$ROOT/uploads"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
: > "$DB"

cat > "$DIR/env.sh" <<ENV
export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true
export DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file
export PHP_CLI_SERVER_WORKERS=4 MAIL_MAILER=kbb
export APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php"
export APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php"
export APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
ENV
. "$DIR/env.sh"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$APP/tools/rj-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -3 "$DIR/migrate.log"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

nonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$nonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$nonce" ]; then
  echo "REFUSING: 127.0.0.1:$PORT is not the server this script started." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")"
