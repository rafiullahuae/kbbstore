#!/bin/sh
# Boot a preview shop for the Lane SORT screenshots.
#
# Mirrors tools/bn-preview.sh, MINUS the route and console patching: this lane
# changes no routes and adds no admin screen, it changes what /shop's existing
# sort and facets ORDER BY. So the copy under storage/ is this worktree's code
# verbatim, which is also what makes the --before pass below honest: the same
# script, the same seed, one file reverted.
#
# APP IS DERIVED FROM THIS SCRIPT'S OWN LOCATION, never hardcoded — two
# harnesses in this repository were unrunnable once their lane's worktree was
# removed. The screenshots are a deliverable, so the thing that produces them
# travels with the branch.
#
#   tools/sort-preview.sh              boot on 8993 with this branch's code
#   tools/sort-preview.sh 8994 before  boot with EffectivePrice reverted
#
# ▲ IT REFUSES A PORT SOMEBODY ELSE IS ON. Three lanes run on this machine and
#   they all boot php -S previews. A `php -S` that cannot bind logs one line to
#   server.log and exits, leaving a harness that happily screenshots ANOTHER
#   LANE'S SHOP -- which is what happened here on the first run, and the shot
#   looked entirely plausible.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
# ── THE PORT IS ASKED FOR, NOT GUESSED ──────────────────────────────────────
#
# This read `PORT=${1:-8993}` and used it without checking. Thirty-nine preview
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
PORT=$(python3 - "${1:-8993}" <<'KBBPORTPY'
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
MODE=${2:-after}
DIR=$APP/storage/framework/testing/lane-sort-preview-$MODE
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"

for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock .env; do
  cp -a "$APP/$part" "$SRC/$part"
done
cp -a "$APP/app" "$SRC/app"
# ▲ NOT `ln -sfn "$APP/vendor" "$SRC/vendor"`, WHICH IS WHAT EVERY OTHER
#   PREVIEW IN tools/ DOES AND WHICH SILENTLY SERVES THE WRONG CODE.
#
#   composer's autoloader resolves `App\` against its OWN base directory, and an
#   optimised classmap in $APP/vendor/composer/autoload_classmap.php names every
#   application class by ABSOLUTE PATH under $APP. So a copy of app/ beside a
#   SYMLINKED vendor/ is never loaded at all: the preview runs the worktree's
#   live classes and the copy is decoration. That cost this lane an hour --
#   the "before" preview, with EffectivePrice reverted in the copy, rendered a
#   grid in the AFTER order and looked completely convincing.
#
#   So vendor/ is a real directory holding one shim: it boots the real
#   autoloader and then repoints `App\` -- both the PSR-4 rule and every
#   classmap entry -- at THIS copy. Nothing is duplicated on disk (the 568 MB
#   of packages are still read from $APP), and the copy is now the code that
#   runs, which is the only thing that makes a before/after pair mean anything.
#   `vendor/composer` is symlinked beside the shim because Laravel's package
#   discovery reads vendor/composer/installed.json, and without it `artisan`
#   loses every package command -- tinker first, which is how this copy is
#   seeded.
mkdir -p "$SRC/vendor"
ln -sfn "$APP/vendor/composer" "$SRC/vendor/composer"
cat > "$SRC/vendor/autoload.php" <<PHPSHIM
<?php
\$loader = require '$APP/vendor/autoload.php';
\$map = [];
foreach (require '$APP/vendor/composer/autoload_classmap.php' as \$class => \$file) {
    if (str_starts_with(\$class, 'App\\\\') && str_starts_with(\$file, '$APP/app/')) {
        \$map[\$class] = '$SRC/app/'.substr(\$file, strlen('$APP/app/'));
    }
}
\$loader->addClassMap(\$map);
\$loader->setPsr4('App\\\\', ['$SRC/app']);

return \$loader;
PHPSHIM
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" "$SRC/storage/app/public" \
         "$SRC/bootstrap/cache"

# THE "BEFORE" SHOT, produced by putting back the one expression this lane
# changed rather than by checking out an old tree: everything else on the page
# -- the tiles, the prices, the badges -- is then identical, so the two images
# differ in exactly the thing under test.
if [ "$MODE" = "before" ]; then
  php -r '$p = $argv[1]; $s = file_get_contents($p);
    $a = "return SetPricing::chargedSql(";
    if (substr_count($s, $a) !== 1) { fwrite(STDERR, "charged anchor not found\n"); exit(1); }
    $s = preg_replace("~return SetPricing::chargedSql\(\s*\n\s*(\x27COALESCE\(\x27 \. self::ownSql\(\\\$table\) \. \x27, \x27 \. self::variantSql\(\\\$table\) \. \x27\)\x27),\s*\n\s*\\\$table\s*\n\s*\);~", "return $1;", $s, 1, $n);
    if ($n !== 1) { fwrite(STDERR, "charged rewrite failed\n"); exit(1); }
    $s = preg_replace("~return SetPricing::compareSql\(\s*\n\s*(\x27COALESCE\(\x27 \. \\\$own \. \x27, \(SELECT MIN\(kbbr\.price\) FROM product_variants kbbr\x27\s*\n\s*\. \x27 WHERE kbbr\.product_id = \x27 \. \\\$parent \. \x27\.id\)\)\x27),\s*\n\s*\\\$table\s*\n\s*\);~", "return $1;", $s, 1, $m);
    if ($m !== 1) { fwrite(STDERR, "compare rewrite failed\n"); exit(1); }
    file_put_contents($p, $s);' "$SRC/app/Support/EffectivePrice.php"
fi

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
php "$SRC/artisan" tinker --execute="require '$APP/tools/sort-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -40 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

if grep -q 'Failed to listen' "$DIR/server.log" 2>/dev/null; then
  echo "port $PORT is already in use -- another lane is on it. Pick another." >&2
  exit 1
fi

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

echo "preview($MODE) on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  app $SRC"
