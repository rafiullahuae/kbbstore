#!/bin/sh
# usage: tools/qk7-set.sh 0|1 -- the free-delivery bar switch on tools/qk7-preview.sh's shop.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/qk7-preview
KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  php "$APP/artisan" tinker --execute="app(\App\Services\CheckoutPage::class)->save(['fs_bar_on' => (bool) $1]); \App\Services\SettingsService::forgetMemo(); \Illuminate\Support\Facades\Cache::flush(); echo 'fs_bar_on='.var_export(app(\App\Services\CheckoutPage::class)->freeDeliveryBar(), true);"
