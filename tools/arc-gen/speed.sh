#!/bin/sh
# Lane ARC -- the Arabic preview on a live-shaped catalogue (tools/arc-seed.php).
#
#   arr-preview.sh <port> [fresh|serve]
#
# fresh (default): rebuild the preview database from migrations, seed the
#   tools/ar-seed.php fixture in the `rtl` state (Arabic ON, mirrored ON), then
#   tools/arr-seed.php: publish every shipped Arabic interface draft, write the
#   Arabic sample content (products, categories, brands, pages, menus, blog,
#   homepage copy) as PUBLISHED translations, a signed-in customer with orders
#   and addresses, and a several-line basket. PREVIEW DATABASE ONLY.
# serve: restart php -S on the existing database (after a CSS/JS rebuild).
#
# Port check and nonce probe are tools/ar-preview.sh's, for the same reasons.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
DIR=$APP/storage/framework/testing/arc-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=array \
  APP_KEY=base64:Lz4DLwIXgs9U4UHRTuswGGSyEZBBcgUKTbFYVQzW4gA= \
  PHP_CLI_SERVER_WORKERS=4 KBB_AR_STATE=rtl MAIL_MAILER=log \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"


exec php "$APP/storage/arc-logs/tools/speed.php" "$APP" "$@"
