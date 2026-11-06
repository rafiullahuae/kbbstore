#!/bin/sh
# Lane SOT: tinker against the sot preview's database (copy of tools/ssb-tinker.sh).
#   SOT_TPL=m1 sh tools/sot-tinker.sh
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/sot-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
${KBB_PHP:-php} "$APP/artisan" tinker --execute="require 'tools/sot-set.php'; \App\Models\Setting::flushMap(); \Illuminate\Support\Facades\Cache::flush();"
