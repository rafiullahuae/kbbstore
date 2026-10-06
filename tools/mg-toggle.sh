#!/bin/sh
# Lane MG: flip "Fit mega menus to the site width" on the running preview
# (tools/mg-preview.sh), for before/after pictures.  sh tools/mg-toggle.sh off|on
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/mg-preview
V=false; [ "$1" = "on" ] && V=true
DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" CACHE_STORE=file APP_ENV=local \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  php "$APP/artisan" tinker --execute="app(\App\Services\HeaderSettings::class)->save(['mega_fit' => $V]); \Illuminate\Support\Facades\Cache::flush(); echo 'mega_fit='.json_encode(app(\App\Services\HeaderSettings::class)->all()['mega_fit']);"
