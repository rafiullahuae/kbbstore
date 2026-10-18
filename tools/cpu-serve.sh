#!/bin/sh
# Lane CPU -- the arf-preview database (tools/arf-preview.sh, Arabic live-state)
# served the way the live host serves it: CACHE_STORE=file, SESSION_DRIVER=file,
# opcache ON, route + config cached, every request a fresh PHP execution, through
# the instrumented front controller tools/cpu-front.php (one JSON line per request
# in storage/cpu-logs/requests.jsonl). PREVIEW ONLY.
#   tools/cpu-serve.sh <port>      -> prints the port and the server pid
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/arf-preview
ROOT=$DIR/webroot
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=production APP_DEBUG=false LOG_LEVEL=error \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER="${KBB_CPU_SESSION:-file}" CACHE_STORE=file \
  APP_KEY=base64:Lz4DLwIXgs9U4UHRTuswGGSyEZBBcgUKTbFYVQzW4gA= \
  PHP_CLI_SERVER_WORKERS=2 KBB_AR_STATE=rtl MAIL_MAILER=log QUEUE_CONNECTION=sync \
  APP_CONFIG_CACHE="$DIR/compiled/cpu-config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/cpu-routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php" \
  KBB_CPU_APP="$APP" KBB_CPU_LOG="$APP/storage/cpu-logs/requests.jsonl"
mkdir -p "$APP/storage/cpu-logs"
php "$APP/artisan" config:cache >/dev/null
php "$APP/artisan" route:cache >/dev/null
php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d memory_limit=512M \
  -S 127.0.0.1:"$1" -t "$ROOT" "$APP/tools/cpu-front.php" >"$APP/storage/cpu-logs/server.log" 2>&1 &
echo $! > "$APP/storage/cpu-logs/server.pid"
sleep 2
echo "cpu-serve on http://127.0.0.1:$1 pid $(cat "$APP/storage/cpu-logs/server.pid")"
