#!/bin/sh
# tools/fb-set.sh <key> <0|1> — flip one flag-bar switch inside the running preview.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-fb-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php" \
  FB_KEY="$1" FB_ON="$2"
php "$DIR/app/artisan" tinker --execute="require '$APP/tools/fb-toggle.php';"
