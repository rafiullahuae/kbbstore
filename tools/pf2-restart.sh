#!/bin/sh
# Lane PF2: restart a pf-preview.sh server WITHOUT re-seeding, optionally after
# copying another preview's database and uploads over it -- so the BEFORE and
# AFTER trees serve the very same catalogue in the very same order (the seed
# shuffles products, so two seeds give two different homepages and a pixel
# diff of them would measure the shuffle).
#
#   APP=<tree> PF_NAME=<name> PORT=<port> [FROM=<other preview dir>] sh tools/pf2-restart.sh
#
# Kills only the PID this preview recorded in its own server.pid.
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
DIR=$APP/storage/framework/testing/$PF_NAME
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
[ -f "$DIR/server.pid" ] && kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
sleep 1
if [ -n "$FROM" ]; then
  cp "$FROM/preview.sqlite" "$DB"
  rm -rf "$ROOT/uploads" "$ROOT/img-cache"
  cp -r "$FROM/webroot/uploads" "$ROOT/uploads"
  # Only the copies every tree makes (200/400/800 and crops); the banner tier
  # is left for the tree under test to make, or not.
  mkdir -p "$ROOT/img-cache"
  for d in "$FROM"/webroot/img-cache/*; do
    case "$(basename "$d")" in 1280|1440|1600) ;; *) cp -r "$d" "$ROOT/img-cache/";; esac
  done
fi
rm -rf "$APP/storage/framework/cache/data/"* "$DIR/compiled/routes.php"
export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=production APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$APP/artisan" view:clear >/dev/null 2>&1 || true
php -d zlib.output_compression=On -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: the server on 127.0.0.1:$PORT is not the one this script started." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi
echo "restarted http://127.0.0.1:$PORT pid $(cat "$DIR/server.pid") root $ROOT"
