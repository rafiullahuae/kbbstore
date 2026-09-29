#!/bin/sh
# Boot a preview for the Lane IM2 measurement: the product gallery strip, the
# review photographs on the same page, and the admin Media Library.
#
# APP_SRC may point at ANOTHER CHECKOUT, which is how the "before" numbers are
# taken: the tools and the photographs come from this branch (they do not exist
# on the base commit) while the application code that is measured comes from
# wherever APP_SRC says. Without it everything comes from here.
#
# Modelled on tools/bp-preview.sh, with the wiring assertions dropped: this lane
# adds no route and no admin screen, so the tracked application runs as it is.
#
# It serves a THROWAWAY COPY under storage/ so nothing here can leave a file in
# the checkout, and APP is derived from this script's own location rather than
# hardcoded, so the harness still runs from the branch after the worktree goes.
#
# tools/m1-router.php is the router and not `php -S` alone: php -S hands every
# /uploads/ request to index.php and a JPEG comes back as text/html, which is
# exactly a broken image in a screenshot and only ever the preview.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CODE=${APP_SRC:-$APP}
DIR=$APP/storage/framework/testing/lane-im2-preview
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8979}

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"

for part in artisan bootstrap config database public public-web-root resources routes composer.json composer.lock .env; do
  cp -a "$CODE/$part" "$SRC/$part"
done
cp -a "$CODE/app" "$SRC/app"
# The harness itself always comes from THIS branch, whatever code is measured.
cp -a "$APP/tools" "$SRC/tools"
# VENDOR, AND WHY IT IS NOT A PLAIN SYMLINK.
#
# Composer's autoload_psr4.php computes its base directory as
# dirname(dirname(__FILE__)), and PHP resolves __FILE__ through symlinks — so a
# symlinked vendor/ makes `App\` map back to the REAL checkout's app/ and the
# preview silently runs this branch's code no matter what APP_SRC says. It cost
# a whole "before" run that came back identical to the "after" one, which is the
# most convincing wrong answer available. So vendor/ is a real directory holding
# a real vendor/composer/ (a few MB) and symlinks to everything else.
mkdir -p "$SRC/vendor"
for entry in "$APP"/vendor/*; do
  name=$(basename "$entry")
  if [ "$name" = composer ]; then
    cp -a "$entry" "$SRC/vendor/composer"
  else
    ln -sfn "$entry" "$SRC/vendor/$name"
  fi
done
rm -f "$SRC/vendor/autoload.php"
cp "$APP/vendor/autoload.php" "$SRC/vendor/autoload.php"
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" "$SRC/storage/app/public" \
         "$SRC/bootstrap/cache"

ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$CODE/public-web-root/index.php" "$ROOT/index.php"
cp -r "$CODE/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

# The photographs go in BEFORE the seed, because the seed runs the real variant
# batch over them and the batch reads the disk.
php "$APP/tools/im2-photos.php" "$ROOT"

export IM2_SITE_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$SRC/artisan" tinker --execute="require '$APP/tools/im2-seed.php';" 2>&1 | tail -6 | tee "$DIR/seed.log"

# Setting::map() is cached, and the seed writes site_url AFTER the migrations
# have already populated that cache. Without this the Media Library builds every
# tile URL out of the stale APP_URL and the measurement comes back as zero
# requests -- which reads exactly like a fix that worked.
php "$SRC/artisan" cache:clear >/dev/null 2>&1 || true


cp "$APP/tools/m1-router.php" "$ROOT/router.php"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
