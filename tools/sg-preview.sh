#!/bin/sh
# Boot a preview for the Lane SP2 screenshots.
#
# Modelled on tools/sp2-preview.sh, MINUS its two wiring guards: Lane SG adds no
# route and no screen partial. Every surface it changes is a storefront page
# that is already mounted -- /shop/, a product page and /cart/ -- so there is
# nothing to assert is wired and nothing for this preview to patch.
#
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-sg-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
# ▲ A PORT NOBODY ELSE IS ON. Three lanes run at once and this machine had
# stale preview servers on 8993 and 8994 from other branches; php -S then fails
# to bind, says so only in its log, and the shots are taken against ANOTHER
# lane's application -- which is how the first run of this harness photographed
# somebody else's "Glow Starter Set". The script now stops rather than shooting
# blind.
PORT=${1:-8710}

rm -rf "$DIR"
mkdir -p "$ROOT" "$DIR/compiled"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

# APP_URL is the preview's own address, so a media path chosen in the picker
# resolves to a picture this server can serve. Left at the packaged default it
# is http://localhost/, and every image in the screenshots is a broken icon.
export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute="require '$APP/tools/sg-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -6 "$DIR/migrate.log"

# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file. Left in place it is the table the
# server serves, which is a table built before the seed -- tools/sp-preview.sh
# carries the measurement.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q "Address already in use" "$DIR/server.log" 2>/dev/null; then
  echo "REFUSING TO CONTINUE: port $PORT is already taken by another process." >&2
  echo "Pass a free port: sh tools/sg-preview.sh 8711" >&2
  exit 1
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
