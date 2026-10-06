#!/bin/sh
# Lane SP: copy this worktree's PHP/Blade into an spd preview, wire the lane's
# route file THERE ONLY (preview copy of routes/web.php), recache, restart.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
L=$1; D=$APP/storage/framework/testing/lane-spd-$L; S=$D/app
rm -rf "$S/app" "$S/resources" "$S/routes" "$S/config"
cp -a "$APP/app" "$APP/resources" "$APP/routes" "$APP/config" "$S/"
cp "$APP/bootstrap/app.php" "$S/bootstrap/app.php"
grep -q "instant-nav.php" "$S/routes/web.php" || printf "\nrequire __DIR__.'/instant-nav.php';\n" >> "$S/routes/web.php"
P=$(cat "$D/port")
export APP_ENV=${SPD_APP_ENV:-production} APP_URL=http://127.0.0.1:$P APP_KEY=base64:bGFuZXBlcmZsYW5lcGVyZmxhbmVwZXJmbGFuZXBlcmY= KBB_PUBLIC_PATH=$D/webroot \
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=kbb_spd_$L DB_USERNAME=kbb DB_PASSWORD=kbb CACHE_STORE=file SESSION_DRIVER=file \
  APP_CONFIG_CACHE=$D/compiled/config.php APP_ROUTES_CACHE=$D/compiled/routes.php APP_EVENTS_CACHE=$D/compiled/events.php \
  APP_SERVICES_CACHE=$D/compiled/services.php APP_PACKAGES_CACHE=$D/compiled/packages.php
php "$S/artisan" migrate --force >/dev/null 2>&1 || true
php "$S/artisan" view:clear >/dev/null 2>&1; php "$S/artisan" route:cache >/dev/null 2>&1; php "$S/artisan" config:cache >/dev/null 2>&1
php "$S/artisan" cache:clear >/dev/null 2>&1 || true
sh "$APP/tools/spd-restart.sh" "$L"
