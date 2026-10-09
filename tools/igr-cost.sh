#!/bin/sh
# Run tools/igr-cost.php against the running Lane IGR preview's database.
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/igr-preview
KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=production APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" \
  SESSION_DRIVER=array CACHE_STORE=file APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes-cost.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" APP_PACKAGES_CACHE="$DIR/compiled/packages.php" \
  php "$APP/artisan" tinker --execute="require '$APP/tools/igr-cost.php';"
