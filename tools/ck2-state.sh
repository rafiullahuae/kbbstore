#!/bin/sh
# Lane CK2: apply one text-size scenario to a running Lane CK preview
# (tools/ck-preview.sh) of the app tree given, without restarting it.
#   CK2_SET='{"checkout":{"d_ov_text":10}}' sh tools/ck2-state.sh <app-tree>
set -e
APP=$(CDPATH= cd -- "${1:?app tree}" && pwd)
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
D=$APP/storage/framework/testing/lane-ck-preview
export KBB_PUBLIC_PATH=$D/webroot APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=$D/preview.sqlite \
  SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE=$D/compiled/config.php APP_ROUTES_CACHE=$D/compiled/routes.php \
  APP_EVENTS_CACHE=$D/compiled/events.php APP_SERVICES_CACHE=$D/compiled/services.php \
  APP_PACKAGES_CACHE=$D/compiled/packages.php
php "$APP/artisan" tinker "$HERE/ck2-set.php" </dev/null 2>&1 | tail -1
php "$APP/artisan" cache:clear >/dev/null 2>&1 || true
