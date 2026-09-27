#!/bin/sh
# Boot a preview of THIS checkout for the Lane M1 screenshots.
# Mirrors tests/Feature/AdminMobileOverflowTest::bootOverflowPreview().
set -e
APP=/home/user/kbb-lane-m1
DIR=$APP/storage/framework/testing/lane-m1-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8951}

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads/ugc"
: > "$DB"

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
mkdir -p "$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute='
  \App\Models\AdminUser::create(["name"=>"Preview Owner","email"=>"owner@preview.test","password"=>"preview-secret-1","role"=>"owner"]);
' >>"$DIR/migrate.log" 2>&1

cp "$APP/tools/m1-router.php" "$ROOT/m1-router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/m1-router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
