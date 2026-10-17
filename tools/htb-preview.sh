#!/bin/sh
# Lane HTB -- a copy of arf-preview.sh with its own directory, port 9600+ and CACHE_STORE=file,
# so a warm homepage (the cache GridSections::forHome() lives in) can be measured.
# Lane ARF -- the live state (drafts unapproved, highlight on) on the ARC catalogue, then ARF migrations unless KBB_ARF_SKIP=1.
#
#   arr-preview.sh <port> [fresh|serve]
#
# fresh (default): rebuild the preview database from migrations, seed the
#   tools/ar-seed.php fixture in the `rtl` state (Arabic ON, mirrored ON), then
#   tools/arr-seed.php: publish every shipped Arabic interface draft, write the
#   Arabic sample content (products, categories, brands, pages, menus, blog,
#   homepage copy) as PUBLISHED translations, a signed-in customer with orders
#   and addresses, and a several-line basket. PREVIEW DATABASE ONLY.
# serve: restart php -S on the existing database (after a CSS/JS rebuild).
#
# Port check and nonce probe are tools/ar-preview.sh's, for the same reasons.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=$(python3 - "${1:-9600}" <<'KBBPORTPY'
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
MODE=${2:-fresh}
DIR=$APP/storage/framework/testing/htb-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

export KBB_ARF_LIVE=1 KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  APP_KEY=base64:Lz4DLwIXgs9U4UHRTuswGGSyEZBBcgUKTbFYVQzW4gA= \
  PHP_CLI_SERVER_WORKERS=4 KBB_AR_STATE=rtl MAIL_MAILER=log \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

if [ -f "$DIR/server.pid" ]; then kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; fi

if [ "$MODE" = "fresh" ]; then
  rm -rf "$DIR"
  mkdir -p "$ROOT" "$DIR/compiled"
  cp "$APP/public-web-root/index.php" "$ROOT/index.php"
  ln -sfn "$APP" "$DIR/kbb-upgrade-app"
  mkdir -p "$ROOT/uploads/ugc"
  : > "$DB"
  php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -40 "$DIR/migrate.log"; exit 1; }
  php "$APP/artisan" tinker --execute="require '$APP/tools/ar-seed.php'; require '$APP/tools/arc-seed.php'; require '$APP/tools/arf-seed.php';" >>"$DIR/migrate.log" 2>&1 \
    || { tail -40 "$DIR/migrate.log"; exit 1; }
  tail -3 "$DIR/migrate.log"
fi
# Always the tree's current build (a CSS fix is rebuilt into public/build).
rm -rf "$ROOT/build"; cp -r "$APP/public/build" "$ROOT/build"
rm -f "$DIR/compiled/routes.php"
cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: 127.0.0.1:$PORT is not this preview" >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; exit 4
fi
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 120 "http://127.0.0.1:$PORT/ar/" || echo 000)
echo "ar-preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  probe $CODE"
