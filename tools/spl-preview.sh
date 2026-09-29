#!/bin/sh
# Boot the Lane SPL preview: the shop, with two sets on it, so the three
# proposed treatments of the "What is in this set" BOX can be photographed on the
# real product page rather than in a mock-up.
#
# NO ROUTE IS MOUNTED HERE. The treatments are CSS over the markup
# partials/set-contents-panel.blade.php already emits, so the surface they are
# shot on is /product/{slug}/ — a route this shop already has. That is the whole
# reason this lane adds no route file, no controller and no preview template:
# there is nothing to unwire when the owner picks one.
# Modelled on tools/pp-preview.sh, which is modelled on tools/set-preview.sh.
set -e
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots ARE the
# deliverable here, so the thing that produces them has to travel with the branch.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-spl-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
# --- THE PORT, AND WHY THIS SCRIPT CHECKS WHOSE SHOP IT IS TALKING TO -------
#
# Twice in one afternoon this lane's shoot was pointed at ANOTHER LANE'S SHOP.
# First on 8981, which Lane AR's harness claims for one of its three language
# states; then on 8991, which that harness had moved to by the time this script
# did. Both times `php -S` failed to bind, said so only in a log nobody was
# reading, and the run carried on and photographed whatever was already
# answering on that port -- a different catalogue, a different fixture, and
# screenshots that look completely finished.
#
# A different default port is not the fix; it is the thing that just failed
# twice. The fix is that THIS SCRIPT PROVES THE SERVER IS ITS OWN before it
# hands the port to a camera: it refuses to start when something is already
# listening, and after booting it asks for a URL only this lane's fixture can
# answer. Neither check costs a measurable moment, and either one would have
# stopped both of those runs at second zero instead of at minute four.
PORT=${1:-8917}
MARKER=/product/spl-glow-ritual-set/

if command -v ss >/dev/null 2>&1 && ss -lnt 2>/dev/null | grep -q "127.0.0.1:$PORT "; then
  echo "REFUSED: something is already listening on 127.0.0.1:$PORT." >&2
  echo "That is another lane's preview. Pass a free port: sh tools/spl-preview.sh 8918" >&2
  exit 1
fi

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
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
mkdir -p "$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker "$APP/tools/spl-seed.php" </dev/null >>"$DIR/migrate.log" 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/spl-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

cp "$APP/tools/m1-router.php" "$ROOT/router.php"

# ── THE COMPILED ROUTE TABLE HAS TO GO, OR THE BLOCK ABOVE DOES NOTHING ─────
#
# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file at $APP_ROUTES_CACHE. A compiled table
# is a CompiledRouteCollection, and routes registered at runtime against one of
# those are never matched -- measured on the previous lane: with the file in
# place even a one-line marker route 404'd.
rm -f "$DIR/compiled/routes.php"


php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

# --- AND IT HAS TO BE OUR SHOP -------------------------------------------
# The bind can still lose a race with another lane that started half a second
# earlier, and `php -S` reports that by exiting rather than by refusing to serve
# -- so the port goes on answering, with somebody else's catalogue behind it.
# This asks for a slug only tools/spl-seed.php creates. Anything but 200 and the
# run stops here, with the log that says why.
STATUS=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT$MARKER" || echo 000)

if [ "$STATUS" != "200" ]; then
  echo "REFUSED: http://127.0.0.1:$PORT$MARKER answered $STATUS." >&2
  echo "This port is not serving this lane's fixture -- almost certainly another" >&2
  echo "lane's preview won the bind. Last lines of the server log:" >&2
  tail -5 "$DIR/server.log" >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 1
fi

echo "preview on http://127.0.0.1:$PORT$MARKER  pid $(cat "$DIR/server.pid")"
