#!/bin/sh
# Lane ASEO -- server ms, queries and settings-map reads per page against the aseo-preview database (tools/aseo-speed.php).
# Port check and nonce probe are tools/ar-preview.sh's, for the same reasons.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/aseo-preview
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


exec php "$APP/tools/aseo-speed.php" "$APP" "$@"
