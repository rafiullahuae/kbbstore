#!/bin/sh
# Lane SSB: tinker against the ssb preview's database (copy of tools/phb-tinker.sh).
#   sh tools/ssb-tinker.sh "require 'tools/ssb-measure.php';"
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ssb-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
${KBB_PHP:-php} "$APP/artisan" tinker --execute="$1; \App\Models\Setting::flushMap(); \Illuminate\Support\Facades\Cache::flush();"
