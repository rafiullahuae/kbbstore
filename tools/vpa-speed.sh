#!/bin/sh
# Lane VPA: run tools/vpa-speed.php against the database tools/vpa-preview.sh
# built, with that script's environment. sh tools/vpa-speed.sh [tools/vpa-mail.php]
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/vpa-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
SCRIPT=${1:-tools/vpa-speed.php}
php "$APP/artisan" tinker --execute="require '$APP/$SCRIPT';"
