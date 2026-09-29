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

# ── REFUSE THE PORT IF ANYTHING IS ALREADY ON IT ───────────────────────────
#
# THE FAILURE THIS PREVENTS HAS ALREADY HAPPENED TWICE IN THIS CONTAINER. If
# `php -S` cannot bind it says so in server.log and EXITS -- and the old
# version of this script slept two seconds and then printed a confident
# "preview on http://..." line regardless. The shoot that followed talked to
# whatever WAS on the port, which was another lane's preview, and produced 84
# photographs of a different catalogue that looked completely finished.
#
# So: check first, and refuse loudly. A port that is busy is not a thing to
# work around, because the only way to "work around" it is to photograph
# somebody else's shop.
if command -v ss >/dev/null 2>&1; then
  BUSY=$(ss -ltn "sport = :$PORT" 2>/dev/null | grep -c LISTEN || true)
else
  BUSY=$( (netstat -ltn 2>/dev/null || true) | grep -c "[:.]$PORT " || true)
fi
if [ "${BUSY:-0}" -gt 0 ]; then
  echo "REFUSING TO START: something is already listening on 127.0.0.1:$PORT." >&2
  echo "Five other lanes share this container. Pick another port -- do NOT" >&2
  echo "shoot against this one, you would be photographing their catalogue." >&2
  exit 2
fi

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
php "$APP/artisan" tinker "$APP/tools/ar-seed.php" </dev/null >>"$DIR/migrate.log" 2>&1 \
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

# ── AND PROVE THE THING THAT ANSWERED IS THIS FIXTURE ──────────────────────
#
# The port check above closes the "somebody got there first" case. This closes
# the other one: our own php -S died on boot (a fatal in the router, a web root
# that is not there) and something else took the port in the two seconds since,
# or never left it. Both end with a server answering 200 on the right port with
# the wrong shop in it.
#
# `lanear-glow-starter-set` is created by tools/ar-seed.php and by nothing else
# in this repository, so a 200 on it is proof of identity and not just proof of
# life. The state prefix is included because /ar/ MUST 404 in the `en` state --
# that is the shop as it ships -- so the English preview is asked for the
# unprefixed address and the two Arabic ones for /ar/.
PROBE_PREFIX=""
[ "$STATE" = "en" ] || PROBE_PREFIX="/ar"
PROBE="http://127.0.0.1:$PORT$PROBE_PREFIX/product/lanear-glow-starter-set/"
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$PROBE" || echo 000)
if [ "$CODE" != "200" ]; then
  echo "PREVIEW DID NOT COME UP: $PROBE answered $CODE, not 200." >&2
  echo "--- server.log ---" >&2; tail -20 "$DIR/server.log" >&2 || true
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 3
fi

echo "preview[$STATE] on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  probe $CODE"
