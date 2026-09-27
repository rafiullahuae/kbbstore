#!/bin/sh
# Boot a preview of THIS checkout for the Lane PX import-progress screenshots.
# Modelled on tools/m1-preview.sh -- same shape, its own port and directory.
set -e
APP=/home/user/kbb-lane-px
DIR=$APP/storage/framework/testing/lane-px-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8992}

rm -rf "$DIR"
mkdir -p "$ROOT" "$DIR/compiled"
# The front controller walks UP from the web root looking for the application,
# exactly as it does on Cloudways where private_html/kbb-app sits beside
# public_html. The symlink is what makes that search succeed here.
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
: > "$DB"

cat > "$DIR/env.sh" <<ENV
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true
export DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file
export APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php"
export APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php"
export APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
ENV
. "$DIR/env.sh"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute='
  \App\Models\AdminUser::create(["name"=>"Preview Owner","email"=>"px@preview.test","password"=>"preview-secret-1","role"=>"owner"]);
' >>"$DIR/migrate.log" 2>&1

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  dir $DIR"
