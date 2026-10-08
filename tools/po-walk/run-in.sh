#!/bin/sh
# Lane PO: run a PHP fixture file inside a running preview's app and database.
#   tools/po-walk/run-in.sh PORT file.php
LANE=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
DIR=$LANE/storage/po-logs/preview-$1
APPDIR=$LANE; [ -d "$DIR/app" ] && APPDIR=$DIR/app
APP_ENV=local KBB_BASE_PATH= DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/config.php" APP_ROUTES_CACHE="$DIR/routes.php" APP_EVENTS_CACHE="$DIR/events.php" \
  APP_SERVICES_CACHE="$DIR/services.php" APP_PACKAGES_CACHE="$DIR/packages.php" \
  php "$APPDIR/artisan" tinker --execute="require '$(realpath "$2")';"
