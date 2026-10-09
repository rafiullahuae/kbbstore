#!/bin/sh
# usage: tools/qk8-set.sh checkout|whatsapp '<php array>' -- save settings on tools/qk8-preview.sh's shop.
#   sh tools/qk8-set.sh checkout "['pay_bg' => true]"
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/qk8-preview
case "$1" in checkout) SVC='\App\Services\CheckoutPage' ;; whatsapp) SVC='\App\Services\WhatsAppButton' ;; *) echo "checkout|whatsapp" >&2; exit 2 ;; esac
KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  php "$APP/artisan" tinker --execute="app($SVC::class)->save($2); \App\Services\SettingsService::forgetMemo(); \Illuminate\Support\Facades\Cache::flush(); echo 'saved';"
