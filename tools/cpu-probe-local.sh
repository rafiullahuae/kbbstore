#!/bin/sh
# Lane CPU -- tools/cpu-live-probe.sh against the arf-preview database, with tools/cpu-serve.sh environment.
# served the way the live host serves it: CACHE_STORE=file, SESSION_DRIVER=file,
# opcache ON, route + config cached, every request a fresh PHP execution, through
# the instrumented front controller tools/cpu-front.php (one JSON line per request
# in storage/cpu-logs/requests.jsonl). PREVIEW ONLY.
#   tools/cpu-probe-local.sh url...
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/arf-preview
ROOT=$DIR/webroot
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=production APP_DEBUG=false LOG_LEVEL=error \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=database CACHE_STORE=file \
  APP_KEY=base64:Lz4DLwIXgs9U4UHRTuswGGSyEZBBcgUKTbFYVQzW4gA= \
  PHP_CLI_SERVER_WORKERS=2 KBB_AR_STATE=rtl MAIL_MAILER=log QUEUE_CONNECTION=sync \
  APP_CONFIG_CACHE="$DIR/compiled/cpu-config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/cpu-routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php" \
  KBB_CPU_HOST=http://localhost \
  KBB_CPU_SESSION=x
exec sh "$APP/tools/cpu-live-probe.sh" "$@"
