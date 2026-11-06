#!/bin/sh
# Run tools/ohb-measure.php against the running Lane OHB preview's database.
#   OHB_SET='{"display":"banner"}' OHB_PATHS=/,/ar/ sh tools/ohb-run.sh <port>
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ohb-preview
PORT=${1:-11900}
export APP_URL="http://127.0.0.1:$PORT" KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$APP/artisan" tinker --execute="require '$APP/tools/ohb-measure.php';"
