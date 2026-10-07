#!/bin/sh
# Lane PG2: run an artisan command inside a tools/spd-preview.sh preview, with
# its environment.   tools/pg2g-artisan.sh LABEL cache:clear
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
LABEL=$1; shift
DIR=$APP/storage/framework/testing/lane-spd-$LABEL
export APP_ENV=production APP_DEBUG=false APP_URL="http://127.0.0.1:$(cat "$DIR/port")" \
  APP_KEY=base64:bGFuZXBlcmZsYW5lcGVyZmxhbmVwZXJmbGFuZXBlcmY= KBB_PUBLIC_PATH="$DIR/webroot" \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=kbb_spd_$LABEL DB_USERNAME=kbb DB_PASSWORD=kbb \
  SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync LOG_LEVEL=error \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$DIR/app/artisan" "$@"
