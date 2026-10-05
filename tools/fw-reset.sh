#!/bin/sh
# Put the Lane FW preview back to its seeded state between shot runs:
# /super-sale/'s header as tools/fw-seed.php wrote it. Preview database only.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/fw-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" \
  SESSION_DRIVER=file CACHE_STORE=file APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes-reset.php" APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$APP/artisan" tinker --execute="
\Illuminate\Support\Facades\DB::table('settings')->where('key', 'page_header')->delete();
\App\Services\SettingsService::forgetMemo(); \Illuminate\Support\Facades\Cache::forget('kbb.settings'); \Illuminate\Support\Facades\Cache::forget('kbb.settings.map');
\$ph = \App\Services\PageHeaders::defaults(); \$s = \$ph['pages']['collection:super-sale']; \$s['img'] = '/uploads/ph/header-wide.png';
foreach (['d','m'] as \$d) { \$s[\$d]['crumb'] = false; \$s[\$d]['title'] = false; \$s[\$d]['fit'] = 'cover'; }
\$ph['pages']['collection:super-sale'] = \$s; app(\App\Services\SettingsService::class)->set('page_header', \$ph); echo 'reset';"
