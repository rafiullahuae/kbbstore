#!/bin/sh
# Run one PHP snippet against the Lane LG2 preview's database (tools/lg2-preview.sh).
#     sh tools/lg2-tinker.sh 'app(\App\Services\SiteLayout::class)->save(["brand_space_top_m" => 0]);'
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lg2-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$APP/artisan" tinker --execute="$1; \App\Models\Setting::flushMap(); \Illuminate\Support\Facades\Cache::flush();"
