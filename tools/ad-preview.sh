#!/bin/sh
# Boot a preview for the Lane AD screenshots.
#
#   tools/ad-preview.sh [PORT] [GIT-REF]
#
# With a GIT-REF the application is materialised from that commit instead of the
# working tree, which is how the BEFORE shots of Appearance -> Product styles are
# taken without checking anything out: the two previews then differ by exactly
# the commits under review and nothing else.
#
# Modelled on tools/bn-preview.sh, including the reason it serves a THROWAWAY
# COPY. This lane needs no route patching -- every screen it touches is already
# wired -- so the copy is verbatim, and the only edit any run makes is the fault
# each screenshot is there to show, made in the copy and never in the worktree.
#
# APP IS DERIVED FROM THIS SCRIPT'S OWN LOCATION, never hardcoded: two harnesses
# here were unrunnable once their lane's worktree was removed.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${1:-8991}
REF=${2:-}
DIR=$APP/storage/framework/testing/lane-ad-preview-$PORT
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"

if [ -n "$REF" ]; then
  git -C "$APP" archive "$REF" | tar -x -C "$SRC"
else
  for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock; do
    cp -a "$APP/$part" "$SRC/$part"
  done
  cp -a "$APP/app" "$SRC/app"
fi
cp -a "$APP/.env" "$SRC/.env" 2>/dev/null || true
# ── THE AUTOLOADER HAS TO POINT AT THE COPY, AND A SYMLINK DOES NOT ─────────
#
# vendor/ is shared rather than copied, for the reason bn-preview.sh gives: it
# costs nothing and the classes are this worktree's own. But composer bakes
# ABSOLUTE paths into vendor/composer/autoload_psr4.php, so `App\` resolves to
# $APP/app no matter which copy is being served. Measured the hard way: a
# preview built from an older commit served that commit's BLADE VIEWS (resolved
# by path) and the WORKING TREE's PHP CLASSES, so a before/after pair of one
# admin screen came out byte-identical while the storefront differed.
#
# So vendor/ is a real directory here holding one shim: an autoloader for `App\`
# that points into THIS copy, registered before composer's own and therefore
# consulted first. Everything else still comes from the shared vendor.
# Every entry of the shared vendor is symlinked INDIVIDUALLY -- composer/ among
# them, so Laravel's package discovery still finds installed.json -- and only
# autoload.php is this copy's own.
mkdir -p "$SRC/vendor"
for entry in "$APP"/vendor/*; do
  name=$(basename "$entry")
  [ "$name" = "autoload.php" ] && continue
  ln -sfn "$entry" "$SRC/vendor/$name"
done
cat > "$SRC/vendor/autoload.php" <<PHPSHIM
<?php
// Composer's own autoload_real.php calls \$loader->register(true) -- it PREPENDS
// itself. So this has to register AFTER it, also prepending, or composer's
// absolute App\\ mapping stays in front and the copy is never consulted.
\$kbbLoader = require '$APP/vendor/autoload.php';

spl_autoload_register(static function (\$class) {
    if (strncmp(\$class, 'App\\\\', 4) !== 0) {
        return;
    }
    \$file = __DIR__ . '/../app/' . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
    if (is_file(\$file)) {
        require \$file;
    }
}, true, true);

return \$kbbLoader;
PHPSHIM
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" "$SRC/storage/app/public" \
         "$SRC/bootstrap/cache"

ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$SRC/artisan" db:seed --class=DemoCatalogueSeeder --force >>"$DIR/migrate.log" 2>&1 || true
php "$SRC/artisan" tinker --execute="require '$APP/tools/ad-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  app $SRC  root $ROOT"
