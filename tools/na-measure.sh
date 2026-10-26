#!/bin/sh
# Lane NA: run tools/na-measure.php against the preview tools/na-preview.sh built.
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/na-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=false APP_URL=http://127.0.0.1 \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=array CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/m-config.php" APP_ROUTES_CACHE="$DIR/compiled/m-routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/m-events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$APP/tools/na-measure.php"
