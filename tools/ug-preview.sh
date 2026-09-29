#!/bin/sh
# Lane UG — boot a preview of the storefront shoppable-video rail.
#
# Mirrors tools/ugcloop-preview.sh, with two differences that matter:
#   - it seeds a STOREFRONT page carrying the real [kbb_videos] shortcode, so
#     what is measured is the shipped rail and not an admin preview of it;
#   - it copies real WebM/VP9 media into the web root, because Playwright's
#     Chromium has no H.264 (CLAUDE.md, instrument warnings).
#
# The router is tools/m1-router.php, which serves real HTTP 206 — `php -S`
# alone answers 200 with the whole body and Chromium then reports a healthy
# clip as MEDIA_ERR_SRC_NOT_SUPPORTED.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ug-preview${UG_SUFFIX:-}
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
# ── THE PORT IS ASKED FOR, NOT GUESSED ──────────────────────────────────────
#
# This read `PORT=${1:-8961}` and used it without checking. Thirty-nine preview
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
PORT=$(python3 - "${1:-8961}" <<'KBBPORTPY'
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
MEDIA=${UG_MEDIA:-$APP/storage/framework/testing/ug-media}

rm -rf "$DIR"
mkdir -p "$ROOT/uploads/ugc"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
cp "$MEDIA"/* "$ROOT/uploads/ugc/" 2>/dev/null || { echo "no media in $MEDIA"; exit 1; }
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
# </dev/null ON BOTH ARMS. `artisan tinker <file>` runs the file and then drops
# into its REPL, which BLOCKS on stdin for ever whenever a terminal is attached:
# the log says the seed is done and the server never comes up. (Lane UG3 found
# it in its own copy; Lane FIN swept the other eleven.)
php "$APP/artisan" tinker "$APP/tools/ug-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/ug-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -40 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

# UG_NO_PROC_OPEN=1 boots the SAME app on a PHP that cannot start a program --
# which is the owner's Cloudways box exactly: /usr/bin/ffmpeg is a real file and
# proc_open is in the FPM pool's disable_functions (docs/SERVER-PROC-OPEN.md §1).
# UgcTranscoder::canSpawn() then answers false, reason() answers no_spawn, and
# the clips screen has to say so without guessing. There is no other way to
# reach that arm on this container.
if [ "${UG_NO_PROC_OPEN:-}" = "1" ]; then
  php -d disable_functions=proc_open -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
else
  php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
fi
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
