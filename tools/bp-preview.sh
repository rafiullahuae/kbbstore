#!/bin/sh
# Boot a preview of the cards banner for the Lane BP screenshots.
#
# Mirrors tools/hl-preview.sh, including the reason it serves a THROWAWAY COPY
# of the application: this lane's routes are not wired yet. CLAUDE.md makes
# routes/web.php the integrator's file, so the tracked one is left exactly as it
# is and the COPY gets the one require line the integrator is asked to add, plus
# the one @include of the admin screen that app.blade.php needs. The screenshots
# are therefore of the screen as it will be once the package is applied,
# produced without this lane touching a file it does not own. The copy lives
# under storage/ and is deleted and rebuilt on every run.
#
# vendor/ is symlinked rather than copied, so the copy costs nothing and the PHP
# classes it runs are this worktree's own.
#
# APP IS DERIVED FROM THIS SCRIPT'S OWN LOCATION, never hardcoded. Two harnesses
# in this repository were unrunnable because their lane's worktree had been
# removed when its branch merged; tools/cp-preview.sh carries the note. The
# screenshots are a deliverable, so the thing that produces them has to travel
# with the branch.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-bp-preview
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8974}

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"

for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock .env; do
  cp -a "$APP/$part" "$SRC/$part"
done
cp -a "$APP/app" "$SRC/app"
ln -sfn "$APP/vendor" "$SRC/vendor"
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" "$SRC/storage/app/public" \
         "$SRC/bootstrap/cache"

# ── THE WIRING IS ALREADY DONE, AND THIS CHECKS THAT RATHER THAN REDOING IT ──
#
# tools/bn-preview.sh had to ADD the require and the @include, because Lane BN's
# routes were not mounted when it was written. The integrator merged both in
# 2.60.305, so this round's copy inherits them — and adding them again would
# register every /admin-api/banners route twice and include the screen twice,
# which is the "two" failure CLAUDE.md names beside "zero". So this asserts the
# finished state and stops if it is not there, rather than patching blind.
php -r '$p = $argv[1]; $s = file_get_contents($p);
  if (substr_count($s, "require __DIR__.\x27/banners-admin.php\x27;") !== 1) {
    fwrite(STDERR, "routes/web.php does not require banners-admin.php exactly once\n"); exit(1); }' "$SRC/routes/web.php"

php -r '$p = $argv[1]; $s = file_get_contents($p);
  if (substr_count($s, "@include(\x27admin.partials.banners-screen\x27)") !== 1) {
    fwrite(STDERR, "app.blade.php does not include the banners screen exactly once\n"); exit(1); }' "$SRC/resources/views/admin/app.blade.php"

# The front controller finds the application by walking a candidate list; this
# is the name on it that puts the copy where it will look.
ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

export BP_SITE_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$SRC/artisan" db:seed --class=DemoCatalogueSeeder --force >>"$DIR/migrate.log" 2>&1 || true
php "$SRC/artisan" tinker --execute="require '$APP/tools/bp-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  app $SRC"
