#!/bin/sh
# Lane M4: server ms, query count and settings-map reads for the product,
# category and brand pages, in the environment tools/m4-preview.sh booted
# (CLAUDE.md "Speed is frozen"). Reuses tools/mn-speed.php unchanged.
#   sh tools/m4-speed.sh > docs/m4-shots/speed-after.json
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/m4-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=array CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$APP/artisan" tinker --execute="require '$APP/tools/mn-speed.php';"
