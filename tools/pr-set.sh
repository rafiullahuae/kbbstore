#!/bin/sh
# Lane PR — run tools/pr-set.php against the database tools/pr-preview.sh left
# behind on <port> (default 8961). See that file for PR_SET's syntax.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${1:-8961}
DIR=$APP/storage/framework/testing/lane-pr-preview-$PORT

[ -f "$DIR/preview.sqlite" ] || { echo "no preview on $PORT (looked in $DIR)" >&2; exit 2; }

export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes-unused.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

# STDIN CLOSED: `artisan tinker <file>` otherwise drops into its REPL and waits.
php "$APP/artisan" tinker "$APP/tools/pr-set.php" </dev/null
