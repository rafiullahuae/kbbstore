#!/bin/sh
# Boot a preview for the Lane AR screenshots.
#
# Modelled on tools/pp-preview.sh. The one thing this lane needs that the others
# do not is the ability to boot the SAME tree in three language states, because
# the whole question this lane was given is "what does /ar actually render":
#
# PORTS 8991-8993, NOT 89xx AT RANDOM. Lane SPL is on 8981 in this same
# container and an earlier run of this harness talked to ITS preview for
# several minutes -- /ar came back dir="rtl" with none of this lane's products
# in it, which reads exactly like a broken seed. Pick a port no other lane is
# on, and check before blaming the fixture.
#
#   ar-preview.sh <port> en    English only            (language_ar_enabled off)
#   ar-preview.sh <port> ar    Arabic, LTR document    (ar on, rtl off)  <- DEFAULT STATE
#   ar-preview.sh <port> rtl   Arabic, RTL document    (ar on, rtl on)
#
# The middle one is not padding: it is the state the shop is in the moment the
# owner flips the one switch he has been told to flip, and it is the state every
# lane's Arabic harness has silently been testing against.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${1:-8991}
STATE=${2:-ar}
DIR=$APP/storage/framework/testing/lane-ar-preview-$STATE
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

rm -rf "$DIR"
mkdir -p "$ROOT"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads/ugc"
: > "$DB"

# ── THE RAIL NEEDS REAL MEDIA, OR ITS ARROWS ARE NOT DRAWN ─────────────────
#
# VP9 in WebM, not H.264: Playwright's bundled Chromium is the open-source
# build and has no H.264 at all (CLAUDE.md), so an .mp4 tile is a black box in
# every screenshot. Two clips, one bright and one dark, is enough for six
# tiles — this lane measures the rail's CHROME, not its encoder.
if command -v ffmpeg >/dev/null 2>&1; then
  for spec in "real-a:color=c=0xE8D8C8:s=270x480:r=25,drawbox=x='mod(t*120,270)':y=0:w=26:h=480:color=0x8A2E4A:t=fill" \
              "real-b:color=c=0x201822:s=270x480:r=25,drawbox=x=40:y='mod(t*150,480)':w=190:h=40:color=0xF0C040:t=fill"; do
    name=${spec%%:*}; graph=${spec#*:}
    ffmpeg -y -v error -f lavfi -i "$graph" -t 6 -r 25 \
      -c:v libvpx-vp9 -b:v 0 -crf 40 -pix_fmt yuv420p -an "$ROOT/uploads/ugc/$name.webm"
    ffmpeg -y -v error -f lavfi -i "$graph" -frames:v 1 "$ROOT/uploads/ugc/$name.jpg"
  done
fi

# ── CACHE_STORE=array, AND IT IS NOT A DETAIL ──────────────────────────────
#
# The file cache writes to $APP/storage/framework/cache, which is the SAME
# directory for all three of these previews however separate their databases
# and web roots are. Measured: with CACHE_STORE=file the `ar` preview served
# <html lang="ar" dir="rtl"> -- SettingsService had cached the `rtl` preview's
# language_rtl_enabled and the ar one read it back. That is the single most
# misleading failure this harness could have, because the whole question this
# lane exists to answer is which direction /ar renders in.
#
# An array store is per-request, so each of the three reads its own database
# and nothing leaks. It costs the memoisation, which a preview does not need.
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=array \
  PHP_CLI_SERVER_WORKERS=4 KBB_AR_STATE="$STATE" \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
mkdir -p "$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -40 "$DIR/migrate.log"; exit 1; }
php "$APP/artisan" tinker "$APP/tools/ar-seed.php" >>"$DIR/migrate.log" 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/ar-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -40 "$DIR/migrate.log"; exit 1; }

# A migration in this repository runs route:cache; a compiled table is a
# CompiledRouteCollection and nothing registered at runtime is matched against
# one. See tools/pp-preview.sh for the measurement.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
echo "preview[$STATE] on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT"
