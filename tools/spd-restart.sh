#!/bin/sh
# Lane SP: restart one spd preview's server (same flags as spd-preview.sh), after editing its webroot.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
L=$1; D=$APP/storage/framework/testing/lane-spd-$L; P=$(cat "$D/port")
# This preview's own server only: matched on its port AND its own webroot path.
for pid in $(ps -eo pid,args | grep "[-]S 127.0.0.1:$P -t $D/webroot" | awk '{print $1}'); do kill "$pid" 2>/dev/null || true; done
kill "$(cat "$D/server.pid")" 2>/dev/null || true; sleep 1
[ -n "$KEEP_INDEX" ] || cp "$APP/tools/spd-index.php" "$D/webroot/index.php"
cd "$D"
env APP_ENV=${SPD_APP_ENV:-production} APP_DEBUG=false APP_URL=http://127.0.0.1:$P APP_KEY=base64:bGFuZXBlcmZsYW5lcGVyZmxhbmVwZXJmbGFuZXBlcmY= \
  KBB_PUBLIC_PATH=$D/webroot DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=kbb_spd_$L DB_USERNAME=kbb DB_PASSWORD=kbb \
  SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync LOG_LEVEL=error PHP_CLI_SERVER_WORKERS=6 \
  APP_CONFIG_CACHE=$D/compiled/config.php APP_ROUTES_CACHE=$D/compiled/routes.php APP_EVENTS_CACHE=$D/compiled/events.php \
  APP_SERVICES_CACHE=$D/compiled/services.php APP_PACKAGES_CACHE=$D/compiled/packages.php \
  nohup php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d memory_limit=512M -d zlib.output_compression=1 \
  -d zlib.output_compression_level=6 -S 127.0.0.1:$P -t $D/webroot $D/webroot/router.php > $D/server.log 2>&1 &
echo $! > "$D/server.pid"
sleep 1
echo "restarted $L on $P"
