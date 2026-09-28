#!/bin/sh
# Boot a preview of Appearance -> Homepage content -> Live preview for the Lane
# HL screenshots. Mirrors tools/tc-preview.sh with ONE difference, and the
# difference is the point:
#
#   THE APP IS SERVED FROM A THROWAWAY COPY, because this lane's route is not
#   wired yet. CLAUDE.md makes routes/web.php the integrator's file, so the
#   tracked one is left exactly as it is and the COPY gets the one require line
#   the integrator is asked to add. The screenshots are therefore of the screen
#   as it will be once the package is applied, produced without this lane
#   touching a file it does not own. The copy lives under storage/ and is
#   deleted and rebuilt on every run.
#
#   vendor/ is symlinked rather than copied, so the copy costs nothing and the
#   PHP classes it runs are this worktree's own.
set -e
APP=/home/user/lane-hl
DIR=$APP/storage/framework/testing/lane-hl-preview
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8981}

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

# The one line the integrator is asked to add to routes/web.php, added HERE and
# only here. tests/Feature/HomepageLiveEditTest.php pins that the real file
# carries it exactly once, which is what goes green on the merge.
php -r '$p = $argv[1]; $s = file_get_contents($p);
  $a = "require __DIR__.\x27/homepage-preview-admin.php\x27;";
  if (substr_count($s, $a) !== 1) { fwrite(STDERR, "anchor not found\n"); exit(1); }
  file_put_contents($p, str_replace($a, $a."\n    require __DIR__.\x27/homepage-live-admin.php\x27;", $s));' "$SRC/routes/web.php"

# The front controller finds the application by walking a candidate list; this
# is the name on it that puts the copy where it will look.
ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

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
php "$SRC/artisan" tinker --execute="require '$APP/tools/hl-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  app $SRC"
