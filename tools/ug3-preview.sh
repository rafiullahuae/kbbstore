#!/bin/sh
# Lane UG3 — boot the one-second rail: a cut clip, four uncut ones, and one
# whose file is gone.
#
#   sh tools/ug3-preview.sh 8993
#   BASE=http://127.0.0.1:8993 node tools/ug3-shots.cjs
#
# Same skeleton as tools/ug2-preview.sh, and the same two instrument facts
# behind it, both of which have cost this project days:
#
#   tools/m1-router.php AND NOT `php -S` ALONE. php -S does not implement HTTP
#     Range: it answers 200 with the whole body, and Chromium then reports a
#     perfectly healthy clip as MEDIA_ERR_SRC_NOT_SUPPORTED. It also makes the
#     bytes-per-tile number meaningless, which is half of what this round has
#     to measure.
#   VP9 IN WEBM. Playwright's bundled Chromium is the open-source build and has
#     no H.264, so an mp4 invents a media error the owner does not have.
#
# The DIFFERENCE from UG2 is the seed: tools/ug3-seed.php carries the tiles this
# round has to photograph -- a clip with its own teaser beside the same clip
# without one, a bright poster beside a dark one, and a tile whose file is not
# there.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ug3-preview${UG3_SUFFIX:-}
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8989}
MEDIA=$APP/storage/framework/testing/ug3-media

rm -rf "$DIR"
mkdir -p "$ROOT/uploads/ugc"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
[ -f "$MEDIA/teaser-1s-20260929-000000-ug3aaaaaaa.webm" ] || sh "$APP/tools/ug3-media.sh" "$MEDIA" >/dev/null
cp "$MEDIA"/* "$ROOT/uploads/ugc/"
: > "$DB"

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=6 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
mkdir -p "$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
# </dev/null ON BOTH ARMS, AND IT IS NOT TIDINESS. `artisan tinker <file>` runs
# the file and then drops into its REPL, which BLOCKS on stdin for ever when
# this script is run from anything that leaves a terminal attached. It seeded
# six clips and then sat there; the log says the work is done and the server
# never comes up, which reads like a broken seed and is not one.
php "$APP/artisan" tinker "$APP/tools/ug3-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/ug3-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -40 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
