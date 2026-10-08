#!/bin/sh
# Lane RP: run tools/rp-speed.php inside a running rp-preview.sh copy, with
# OPcache ON (production PHP-FPM has it; the CLI default is off, which re-parses
# every compiled view on every include and inflates each card ~5x), and
# the preview's own environment. Usage: tools/rp-speed.sh <label> <port>
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
php -d opcache.enable_cli=1 -d opcache.jit=off "$DIR/app/artisan" tinker --execute="require '$APP/tools/rp-speed.php';"
