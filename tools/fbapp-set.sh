#!/bin/sh
# tools/fbapp-set.sh <sitefooter key> <json value> — set one footer setting in
# the running Lane FB app-row preview (tools/fbapp-preview.sh). Preview only.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/fbapp-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php" FBA_KEY="$1" FBA_VAL="$2"
php "$APP/artisan" tinker --execute="app(\App\Services\SiteFooter::class)->save([getenv('FBA_KEY') => json_decode(getenv('FBA_VAL'), true)]); app(\App\Services\SettingsService::class)->flush(); \Illuminate\Support\Facades\Cache::flush(); echo 'set';"
