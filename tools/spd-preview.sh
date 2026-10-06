#!/bin/sh
# Lane SP (speed): boot a MySQL-backed preview of the storefront at live scale.
#
#   tools/spd-preview.sh PORT REF LABEL seed        build + seed kbb_spd_LABEL
#   tools/spd-preview.sh PORT REF LABEL from:LBL    load LBL's dump and media,
#                                                   then migrate FORWARD
#   REF '' = this worktree.
#
# Modelled on tools/ps-preview.sh (throwaway copy, vendor hardlinked so an old
# ref runs its own PHP, nonce probe so we never measure another lane's
# server). Differences: MySQL (live is MySQL), the measuring front controller
# tools/spd-index.php, and OPcache on for the CLI server as PHP-FPM has it.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
LABEL=$3
MODE=${4:-seed}
DIR=$APP/storage/framework/testing/lane-spd-$LABEL
SRC=$DIR/app
ROOT=$DIR/webroot
DBN=kbb_spd_$LABEL
PORT=$(python3 - "${1:-8771}" <<'KBBPORTPY'
import socket, sys
s0 = int(sys.argv[1])
for p in range(s0, s0 + 400):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', p)); s.close(); print(p); break
    except OSError:
        s.close()
KBBPORTPY
)
REF=${2:-}
[ -f "$DIR/server.pid" ] && kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled" "$DIR/spd-sql"
if [ -n "$REF" ]; then
  git -C "$APP" archive "$REF" | tar -x -C "$SRC"
  rm -rf "$SRC/tools"; cp -a "$APP/tools" "$SRC/tools"
else
  for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock app; do
    cp -a "$APP/$part" "$SRC/$part"
  done
fi
rm -rf "$SRC/vendor"; cp -al "$APP/vendor" "$SRC/vendor"
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" "$SRC/storage/framework/cache/data" \
         "$SRC/storage/logs" "$SRC/storage/app/public" "$SRC/bootstrap/cache"
ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/tools/spd-index.php" "$ROOT/index.php"
cp -r "$SRC/public/build" "$ROOT/build"
cp "$APP/public-web-root/favicon.ico" "$ROOT/favicon.ico"
cp "$APP/tools/perf-router.php" "$ROOT/perf-router.php"
cp "$APP/tools/spd-router.php" "$ROOT/router.php"

mysql -u root -e "DROP DATABASE IF EXISTS $DBN; CREATE DATABASE $DBN CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $DBN.* TO 'kbb'@'%';" 2>/dev/null \
  || mysql -u root -e "DROP DATABASE IF EXISTS $DBN; CREATE DATABASE $DBN CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $DBN.* TO 'kbb'@'localhost';"

export APP_ENV=${SPD_APP_ENV:-production} APP_DEBUG=false APP_URL="http://127.0.0.1:$PORT" \
  APP_KEY=base64:bGFuZXBlcmZsYW5lcGVyZmxhbmVwZXJmbGFuZXBlcmY= \
  KBB_PUBLIC_PATH="$ROOT" \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=$DBN DB_USERNAME=kbb DB_PASSWORD=kbb \
  SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync LOG_LEVEL=error \
  PHP_CLI_SERVER_WORKERS=6 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

if [ "$MODE" = seed ]; then
  php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -40 "$DIR/migrate.log"; exit 1; }
  php -d memory_limit=2G "$SRC/artisan" tinker --execute="require '$APP/tools/spd-seed.php';" >>"$DIR/migrate.log" 2>&1 \
    || { tail -40 "$DIR/migrate.log"; exit 1; }
  mysqldump -u root --single-transaction "$DBN" > "$DIR/seed.sql"
else
  FROM=$APP/storage/framework/testing/lane-spd-${MODE#from:}
  mysql -u root "$DBN" < "$FROM/seed.sql"
  for d in wp-content uploads img-cache; do
    [ -d "$FROM/webroot/$d" ] && cp -al "$FROM/webroot/$d" "$ROOT/$d"
  done
  php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -40 "$DIR/migrate.log"; exit 1; }
fi
php "$SRC/artisan" route:cache >/dev/null 2>&1 || true
php "$SRC/artisan" config:cache >/dev/null 2>&1 || true
php "$SRC/artisan" view:cache >/dev/null 2>&1 || true
php "$SRC/artisan" cache:clear >/dev/null 2>&1 || true

php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d memory_limit=512M \
  -d zlib.output_compression=1 -d zlib.output_compression_level=6 \
  -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: 127.0.0.1:$PORT is not this preview" >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi
echo "$PORT" > "$DIR/port"
echo "preview $LABEL on http://127.0.0.1:$PORT pid $(cat "$DIR/server.pid") db $DBN"
