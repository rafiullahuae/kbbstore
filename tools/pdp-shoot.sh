#!/bin/sh
# The whole Lane PDP round, in the one order that produces correct pictures.
#
# ── WHY THE ORDER MATTERS ──────────────────────────────────────────────────
#
# Turning Arabic on is NOT invisible to the English pages: Locale::enabledCodes()
# is what the header's language switcher and the <head>'s hreflang pair read, so
# a shop with Arabic on renders chrome that a shop with Arabic off does not. The
# ten English drawings are what the owner is choosing from and they must show the
# chrome this shop has TODAY. So: seed, shoot English, THEN turn Arabic on, then
# shoot the Arabic pass into the same folder.
#
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in this
# repository became unrunnable because they named a lane's worktree and that
# worktree was removed when the branch merged.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-pdp-preview
PORT=${1:-8987}

sh "$APP/tools/pdp-preview.sh" "$PORT"

PDP_BASE="http://127.0.0.1:$PORT" node "$APP/tools/pdp-shots.cjs"

# The preview server holds the same sqlite file, so the switch has to be applied
# through the same env the server booted with -- otherwise `artisan` opens the
# repository's own database and the pages keep rendering English.
KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" \
  SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php" \
  php "$APP/artisan" tinker "$APP/tools/pdp-arabic-on.php" </dev/null

PDP_AR=1 PDP_BASE="http://127.0.0.1:$PORT" node "$APP/tools/pdp-shots.cjs"

echo "shots in $APP/docs/lane-pdp-shots"
echo "preview still up on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")"
