#!/bin/sh
# Boot a preview for the Lane PU screenshots.
#
#   tools/pu-preview.sh [PORT] [GIT-REF]
#
# With a GIT-REF the application is materialised from that commit instead of the
# working tree, which is how the BEFORE shots of the order screen are
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
REF=${2:-}
DIR=$APP/storage/framework/testing/lane-pu-preview-$PORT
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
# Lane PU's routes are not wired until the integrator adds the one require
# CLAUDE.md reserves for him. The COPY is wired exactly where that require
# goes -- beside orders-admin.php inside the admin-api group -- and the worktree
# is never touched. A copy that already requires it is left alone.
if [ -f "$SRC/routes/order-detail-admin.php" ] && ! grep -q "order-detail-admin.php" "$SRC/routes/web.php"; then
  sed -i "s#^\(\s*\)require __DIR__.'/orders-admin.php';#&\n\1require __DIR__.'/order-detail-admin.php';#" "$SRC/routes/web.php"
fi
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

# A lane worktree has no .env (it is not tracked), and without a key every
# request that touches the session 500s with "No application encryption key".
# A throwaway key for a throwaway database.
if ! grep -q '^APP_KEY=base64' "$SRC/.env" 2>/dev/null; then
  APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
  export APP_KEY
fi

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
php "$SRC/artisan" tinker --execute="require '$APP/tools/pu-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

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

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  app $SRC  root $ROOT"
