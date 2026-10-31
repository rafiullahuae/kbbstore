#!/bin/sh
# Lane LT3 preview: the 31 October Arabic package on a local
# shop seeded with the live English text (tools/lt3-seed.php). Same guards as
# tools/ar-preview.sh: a port that will bind, refuse a busy one, and prove by a
# nonce that the server answering is this one.
#   tools/lt3-preview.sh [port]
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=$(python3 - "${1:-8771}" <<'PY'
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
PY
)
DIR=$APP/storage/framework/testing/lane-lt3-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
rm -rf "$DIR"
mkdir -p "$ROOT/uploads/products" "$DIR/compiled"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
: > "$DB"

# The product photographs, from the live shop, so the pages read as they do there.
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
python3 -I -c "import json,sys; [print(k, v['image']) for k, v in json.load(open(sys.argv[1])).items() if v.get('image')]" \
  "$APP/storage/lt3-logs/lt3-fixture.json" | while read -r slug url; do
  curl -sS -A "$UA" -o "$ROOT/uploads/products/$slug.webp" "$url" || true
done

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=array \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -40 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$APP/tools/lt3-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -40 "$DIR/migrate.log"; exit 1; }
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

nonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$nonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$nonce" ]; then
  echo "REFUSING: the server on 127.0.0.1:$PORT is not this preview." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi
tail -1 "$DIR/migrate.log"
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")"
