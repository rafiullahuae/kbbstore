#!/bin/sh
# Boot a preview for the Lane PG2 contact sheets.
# Modelled on tools/pp2-preview.sh, which is modelled on tools/sp-preview.sh.
#
# The skin under test is NOT chosen here: tools/pg2-set-skin.php writes
# `grid_skin` between shots, against the database this script leaves behind, so
# one boot produces every treatment at both widths from the same fixture and
# the same server. The path it writes to is printed below.
set -e
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-pg2-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
PORT=${1:-8931}

# ── A STALE SERVER ON THIS PORT IS NOT HARMLESS, AND THIS COST AN HOUR ──────
#
# `php -S` fails with "Address already in use" and this script carried on, so
# the OLD server kept answering -- holding an open handle on the sqlite file the
# line below was about to delete and recreate. A deleted inode is still a
# readable, EMPTY database, so every page rendered from a catalogue that was no
# longer there: /shop answered 200 with a grid of nothing and
# /collections/skincare-sets/ answered 404, which reads exactly like a broken
# route and sent this lane looking at CategoryPath::resolve() twice.
#
# Killed BY PID, off the port, and never `pkill -f php`: CLAUDE.md records that
# a pattern kill on this machine takes the other two lanes' suites down with it.
for kbbpid in $(ss -ltnp 2>/dev/null | grep ":$PORT " | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u); do
  kill "$kbbpid" 2>/dev/null || true
done
sleep 1

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

# ── NO ROUTE FILE TO MOUNT ──────────────────────────────────────────────────
#
# This lane adds no route and no screen: a skin is a CSS block and a row in
# App\Support\GridSkins, so the preview needs nothing but the repository's own
# routes. The one thing it does need is the BUILT stylesheet, copied below --
# `npx vite build` is manual here and a preview served against a stale
# public/build is a picture of the last release.
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
# STDIN IS CLOSED ON BOTH ARMS. `artisan tinker <file>` runs the file and then
# drops into its REPL, which BLOCKS on stdin for ever whenever a terminal is
# attached: the log says the seed is done and the server never comes up.
# PreviewSeedCannotHangTest fails by name if either redirect is dropped.
php "$APP/artisan" tinker "$APP/tools/pg2-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

# ── THE COMPILED ROUTE TABLE HAS TO GO, OR THE BLOCK ABOVE DOES NOTHING ─────
#
# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file at $APP_ROUTES_CACHE. A compiled table
# is a CompiledRouteCollection, and routes registered at runtime against one of
# those are never matched -- measured: with the file in place even a one-line
# marker route 404'd.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
