#!/bin/sh
# Press "Approve all" in one of the previews, in the preview's own environment.
#
# The env block has to match tools/ar-preview.sh's exactly or artisan opens the
# repository's own database instead of the preview's -- which writes 1,018
# published rows into a file the suite uses, and is the kind of mistake that is
# only noticed three tests later.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
STATE=${1:?usage: ar-approve.sh <en|ar|rtl>}
DIR=$APP/storage/framework/testing/lane-ar-preview-$STATE

export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=array \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$APP/artisan" tinker "$APP/tools/ar-publish-drafts.php" </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/ar-publish-drafts.php';"
