#!/bin/sh
# Lane GU2: run tools/gu2-set.php against tools/fg-preview.sh's preview database
# (same environment the preview server runs with). GU2_HOME / GU2_LOOK pass through.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/fg-preview
KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php" \
  php "$APP/artisan" tinker --execute="require '$APP/tools/gu2-set.php';" 2>&1 | grep '^gu2:'
