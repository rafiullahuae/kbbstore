#!/bin/sh
# Run tools/cp-shots/measure.php against a running preview's app and database.
#   sh tools/cp-shots/measure.sh PORT [with-coupon]
LANE=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
DIR=$LANE/storage/cp-logs/preview-$1
APPDIR=$LANE; [ -d "$DIR/app" ] && APPDIR=$DIR/app
[ "$2" = "with-coupon" ] && export CP_WITH_COUPON=1
export KBB_PUBLIC_PATH="$APPDIR/public" APP_ENV=local APP_DEBUG=false APP_URL="http://127.0.0.1:$1" KBB_BASE_PATH= \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=array CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/config.php" APP_ROUTES_CACHE="$DIR/routes.php" APP_EVENTS_CACHE="$DIR/events.php" \
  APP_SERVICES_CACHE="$DIR/services.php" APP_PACKAGES_CACHE="$DIR/packages.php"
php "$APPDIR/artisan" tinker --execute="require '$LANE/tools/cp-shots/measure.php';" 2>&1 | grep '^{'
