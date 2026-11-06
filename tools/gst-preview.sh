#!/usr/bin/env bash
# Lane GST preview: the homepage free-gifts strip and its admin screen.
#
#   tools/gst-preview.sh [first-port]      (default 12200; the next free one is used)
#
# Shaped on tools/go-preview.sh: its own sqlite DB and web root under
# storage/framework/testing/lane-gst-preview, the six live gifts with the shop's
# REAL photographs (fetched from kbeautybliss.com), the strip OFF as it ships.
# Owner login: owner@preview.test / preview-secret-1.
#
# It needs routes/gift-strip-admin.php required from routes/web.php and the
# screen included in admin/app.blade.php -- the integrator's two lines. It
# refuses to start without them rather than serve a screen whose every request
# 404s.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-gst-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=$(python3 - "${1:-12200}" <<'KBBPORTPY'
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
php -r '$s = file_get_contents($argv[1]); if (substr_count($s, "require __DIR__.\x27/gift-strip-admin.php\x27;") !== 1) { fwrite(STDERR, "routes/web.php does not require gift-strip-admin.php exactly once\n"); exit(1); }' "$APP/routes/web.php"
php -r '$s = file_get_contents($argv[1]); if (substr_count($s, "@include(\x27admin.partials.gift-strip-screen\x27)") !== 1) { fwrite(STDERR, "app.blade.php does not include the gift strip screen exactly once\n"); exit(1); }' "$APP/resources/views/admin/app.blade.php"

if [ -f "$DIR/server.pid" ]; then kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; fi
rm -rf "$DIR"
mkdir -p "$ROOT" "$DIR/compiled"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
ln -sfn "$APP/public/build" "$ROOT/build"
: > "$DB"

# The six live gifts' full-size photographs, as the shop stores them.
for p in wp-content/uploads/2024/10/AXIS-Y-Dark-Spot-Correcting-Glow-Serum.webp \
         wp-content/uploads/2026/04/Arencia-Vitamin-C-Booster-Shot.webp \
         wp-content/uploads/2024/12/Medicube-Red-Succinic-Acid-Peeling-Pad.webp \
         wp-content/uploads/2026/09/medicube-Vanilla-Pistachio-Deodorant-1.webp \
         wp-content/uploads/2026/09/medicube-PDRN-Collagen-Gua-Sha-Neck-Wrinkle-Cream.webp \
         uploads/products/medicube-medicube-pdrn-pink-collagen-jelly-eye-mask-6-pairs.webp; do
  mkdir -p "$ROOT/$(dirname "$p")"
  curl -sSf -m 60 "https://kbeautybliss.com/$p" -o "$ROOT/$p"
done

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
php "$APP/artisan" tinker --execute="require '$APP/tools/gst-seed.php';" >>"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -2 "$DIR/migrate.log"
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
echo "$PORT" > "$DIR/port"
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
