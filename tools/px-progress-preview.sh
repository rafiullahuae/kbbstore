#!/bin/sh
# Boot a preview of THIS checkout for the Lane PX import-progress screenshots.
# Modelled on tools/m1-preview.sh -- same shape, its own port and directory.
set -e
# DERIVED, NOT HARDCODED. This was `APP=/home/user/kbb-lane-px` -- one lane's
# worktree path, which does not exist in the next lane's worktree, so the
# script that produces the evidence for this work could not be re-run to
# produce it again. The screenshots are a deliverable; the thing that makes
# them has to travel with the branch.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-px-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
# ── THE PORT IS ASKED FOR, NOT GUESSED ──────────────────────────────────────
#
# This read `PORT=${1:-8992}` and used it without checking. Thirty-nine preview
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
PORT=$(python3 - "${1:-8992}" <<'KBBPORTPY'
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
mkdir -p "$ROOT" "$DIR/compiled"
# The front controller walks UP from the web root looking for the application,
# exactly as it does on Cloudways where private_html/kbb-app sits beside
# public_html. The symlink is what makes that search succeed here.
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
: > "$DB"

cat > "$DIR/env.sh" <<ENV
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true
export DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file
export APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php"
export APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php"
export APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
ENV
. "$DIR/env.sh"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker --execute='
  \App\Models\AdminUser::create(["name"=>"Preview Owner","email"=>"px@preview.test","password"=>"preview-secret-1","role"=>"owner"]);
' >>"$DIR/migrate.log" 2>&1

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

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  dir $DIR"
