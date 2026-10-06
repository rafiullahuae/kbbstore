#!/bin/sh
# Flip the Lane CK preview between states without restarting it:
#   sh tools/ck-state.sh <picker 0|1> <arabic 0|1>
# Re-runs the idempotent seed against the preview's own database.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
D=$APP/storage/framework/testing/lane-ck-preview
export KBB_PUBLIC_PATH=$D/webroot APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=$D/preview.sqlite \
  SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE=$D/compiled/config.php APP_ROUTES_CACHE=$D/compiled/routes.php \
  APP_EVENTS_CACHE=$D/compiled/events.php APP_SERVICES_CACHE=$D/compiled/services.php \
  APP_PACKAGES_CACHE=$D/compiled/packages.php CK_PICKER="${1:-0}" CK_AR="${2:-0}"
php "$APP/artisan" tinker "$APP/tools/ck-seed.php" </dev/null 2>&1 | tail -1
php "$APP/artisan" cache:clear >/dev/null 2>&1 || true
