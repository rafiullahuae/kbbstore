#!/bin/sh
# Run a PHP file against the Lane GO preview database (tools/go-preview.sh),
# with the preview's own environment. Usage: sh tools/go-tinker.sh <file.php> [port]
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/${GO_LANE:-lane-go}-preview
export APP_URL="http://127.0.0.1:${2:-9530}"
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file MAIL_MAILER=log \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes-tinker.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
exec php "$APP/artisan" tinker --execute="require '$1';"
