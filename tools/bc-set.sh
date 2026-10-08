#!/bin/sh
# Lane BC: run one line of PHP inside a running rp-preview.sh copy (its own
# env and database) — to switch a setting between shots, as the admin would.
# Usage: tools/bc-set.sh <label> <port> '<php>'
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-rp-preview-$1
export APP_ENV=local APP_DEBUG=false APP_URL="http://127.0.0.1:$2" \
  APP_KEY=base64:bGFuZXBlcmZsYW5lcGVyZmxhbmVwZXJmbGFuZXBlcmY= \
  KBB_PUBLIC_PATH="$DIR/webroot" DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" \
  SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$DIR/app/artisan" tinker --execute="$3"
