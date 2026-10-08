#!/bin/sh
# Lane SEO: a throwaway SQLite shop for measuring and for samples.
#
#   sh tools/seo-env.sh [label] [N]   -> builds storage/seo-logs/env-<label>/ and
#                                        prints the env line to source
#
# Never touches .env's database. Webroot is the env dir's own webroot/, so the
# drawn /wp-content/uploads files land there and nowhere else.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
LABEL=${1:-a}
N=${2:-40}
DIR=$APP/storage/seo-logs/env-$LABEL
rm -rf "$DIR"
mkdir -p "$DIR/webroot" "$DIR/compiled"
cp -r "$APP/public/build" "$DIR/webroot/build"
: > "$DIR/db.sqlite"
KEY=$(grep '^APP_KEY=' "$APP/.env" | head -1 | cut -d= -f2-)
cat > "$DIR/env.sh" <<ENVSH
export APP_KEY='$KEY' APP_URL='https://kbeautybliss.test' KBB_PUBLIC_PATH='$DIR/webroot' APP_ENV=local APP_DEBUG=false
export DB_CONNECTION=sqlite DB_DATABASE='$DIR/db.sqlite' SESSION_DRIVER=array CACHE_STORE=array KBB_BASE_PATH=
export APP_CONFIG_CACHE='$DIR/compiled/config.php' APP_ROUTES_CACHE='$DIR/compiled/routes.php' APP_EVENTS_CACHE='$DIR/compiled/events.php' APP_SERVICES_CACHE='$DIR/compiled/services.php' APP_PACKAGES_CACHE='$DIR/compiled/packages.php'
ENVSH
. "$DIR/env.sh"
php "$APP/artisan" migrate --force --seed >"$DIR/migrate.log" 2>&1 || { tail -20 "$DIR/migrate.log"; exit 1; }
SEO_N=$N php "$APP/artisan" tinker --execute="require '$APP/tools/seo-seed.php';" >>"$DIR/migrate.log" 2>&1 || { tail -20 "$DIR/migrate.log"; exit 1; }
tail -1 "$DIR/migrate.log"
echo ". $DIR/env.sh"
