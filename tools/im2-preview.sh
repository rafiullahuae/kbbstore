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
# ── THE PORT IS ASKED FOR, NOT GUESSED ──────────────────────────────────────
#
# This read `PORT=${1:-8979}` and used it without checking. Thirty-nine preview
# scripts share this repository and six of them defaulted to 8991, five to 8989
# and four to 8977, so two lanes colliding was not bad luck, it was the design.
#
# It fails in the way that costs the most: `php -S` prints "Address already in
# use" into its own log, the script reports a URL anyway, and the shot run that
# follows photographs WHOEVER IS ALREADY ON THAT PORT. Lane BG lost twenty
# minutes to exactly that -- a shop with the wrong catalogue in it and a 404 on
# a product that was certainly in the database -- and a leaked server outlives
# the run that started it, so the collision is permanent for everybody once it
# happens.
#
# tests/Support/PreviewPort.php is the same fix for the suite and carries the
# same story. Here it is: walk up from the requested port and take the first one
# that will actually bind.
PORT=$(python3 - "${1:-8979}" <<'KBBPORTPY'
import socket, sys

start = int(sys.argv[1])

for p in range(start, start + 400):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', p)); s.close(); print(p); break
    except OSError:
        s.close()
else:
    raise SystemExit('no free port in [%d, %d)' % (start, start + 400))
KBBPORTPY
)
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
# ── AND IT PROVES THE SERVER ANSWERING IS THE ONE THIS SCRIPT STARTED ───────
#
# A 200 is not enough. A dead bind leaves ANOTHER LANE's preview answering on
# this port, and that server returns 200 to everything -- so a run that checks
# only the status code goes on to photograph somebody else's shop and looks
# completely finished doing it. Lane PG2 caught this with a request for a slug
# only its own seed creates; this is the same idea made general, so that every
# script gets it whether or not it has a slug of its own to ask for.
#
# A nonce is written into THIS script's webroot and read back over HTTP. Another
# lane's server is rooted in another lane's directory, so it cannot have the
# file: the probe fails and the script refuses rather than handing back a URL.
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"

if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING TO HAND BACK A PREVIEW: the server answering on 127.0.0.1:$PORT" >&2
  echo "is not the one this script started -- it is serving another webroot, so" >&2
  echo "anything shot against it would be somebody else's shop." >&2
  tail -10 "$DIR/server.log" >&2 2>/dev/null || true
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
