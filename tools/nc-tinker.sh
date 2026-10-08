#!/bin/sh
# Lane MN (2.60.441): artisan tinker (any code: $1) inside the running tools/nc-preview.sh
# copy, OPcache on, the preview's own SQLite file. Used for the style shots and the admin owner.
set -e
APP=/home/user/lane-mn
DIR=${NC_DIR:-$APP/storage/framework/testing/lane-nc-preview}
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$DIR/app/artisan" tinker --execute="$1"
