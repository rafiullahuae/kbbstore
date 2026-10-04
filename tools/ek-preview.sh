#!/bin/sh
# Lane EK — a preview of Emails → Customer emails, the template builder and the
# tabbed Emails screens, WITH the integrator's wiring applied (the lines in the
# lane report), for the screenshots in docs/ek-shots/.
#
# The worktree's routes/web.php and admin/app.blade.php are NEVER edited: the
# app is hard-linked into a throwaway copy, those two files are replaced in the
# COPY by patched copies, and the server runs from there. Derived from this
# script's own location; port asked for, checked, and proved (nonce) as
# tools/rk-preview.sh does.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-ek-preview${EK_BEFORE:+-before}
COPY=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-9883}" <<'KBBPORTPY'
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
mkdir -p "$ROOT" "$DIR/compiled" "$ROOT/uploads" "$COPY"
rsync -a --link-dest="$APP" --exclude storage --exclude .git "$APP/" "$COPY/"
cp -r "$APP/public/build" "$COPY/public/" 2>/dev/null || true
mkdir -p "$COPY/storage/framework/views" "$COPY/storage/framework/cache/data" "$COPY/storage/framework/sessions" "$COPY/storage/logs" "$COPY/storage/app/public"
if [ -n "$EK_BEFORE" ]; then
  # BEFORE: the two screens as they were at this lane's base (7e09c7b), for
  # the before/after pictures. Hard links broken first.
  for f in resources/views/admin/app.blade.php resources/views/admin/partials/emails-screens.blade.php; do
    rm -f "$COPY/$f"; git -C "$APP" show 7e09c7b:"$f" > "$COPY/$f"
  done
else
  python3 "$APP/tools/ek-wire.py" "$COPY"
fi
ln -sfn "$COPY" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
cp -r "$APP/public/fonts" "$ROOT/fonts" 2>/dev/null || true
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

php "$COPY/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$COPY/artisan" tinker --execute="require '$COPY/tools/rj-seed.php'; require '$COPY/tools/ek-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: 127.0.0.1:$PORT is not the server this script started." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")"
