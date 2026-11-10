#!/bin/sh
# Boot a preview for Lane GO (Marketing -> Gift offers). Seeded by
# tools/go-seed.php (the popup is switched ON in THIS preview database only);
# driven by tools/go-shots.cjs. A copy of tools/pk-preview.sh with its own
# directory, seed and default port -- see that script for why each guard exists
# (a port nobody else is on, a nonce proving the server answering is ours).
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
# GO_LANE / GO_SEED (Lane GQ): another lane can boot its own copy with its own
# seed (tools/gq-seed.php) without touching this one's database. Defaults: Lane GO.
DIR=$APP/storage/framework/testing/${GO_LANE:-lane-go}-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-9530}" <<'KBBPORTPY'
import socket, sys

start = int(sys.argv[1])

for p in range(start, start + 400):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', p)); s.close(); print(p); break
    except OSError:
        s.close()
else:
    raise SystemExit('no free port in [%d, %d)' % (start, start + 400))
KBBPORTPY
)
php -r '$s = file_get_contents($argv[1]);
  foreach (["gift-offers.php", "gift-offers-admin.php"] as $f) { if (substr_count($s, "require __DIR__.\x27/".$f."\x27;") !== 1) { fwrite(STDERR, "routes/web.php does not require $f exactly once\n"); exit(1); } }' \
  "$APP/routes/web.php"
php -r '$s = file_get_contents($argv[1]);
  if (substr_count($s, "@include(\x27admin.partials.gift-offers-screen\x27)") !== 1) { fwrite(STDERR, "app.blade.php does not include the gift offers screen exactly once\n"); exit(1); }' \
  "$APP/resources/views/admin/app.blade.php"

rm -rf "$DIR"
mkdir -p "$ROOT" "$DIR/compiled"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
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

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$APP/tools/${GO_SEED:-go-seed.php}';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -4 "$DIR/migrate.log"
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then
  echo "REFUSING TO CONTINUE: port $PORT is already taken by another process." >&2
  exit 1
fi

kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"

if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING TO HAND BACK A PREVIEW: the server on 127.0.0.1:$PORT is not the one this script started." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
