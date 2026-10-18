#!/bin/sh
# Lane CPU -- queries per every-minute command (tools/cpu-cron-queries.php), same env as tools/cpu-cron.sh.
# schedule:run` (opcache OFF on the CLI, as a stock PHP CLI runs it) against the
# arf-preview database, wall + user + sys CPU INCLUDING the child processes the
# scheduler forks, R times; then each every-minute command alone. PREVIEW ONLY.
#   tools/cpu-cron-queries.sh <label>
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/arf-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=production APP_DEBUG=false LOG_LEVEL=error \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_KEY=base64:Lz4DLwIXgs9U4UHRTuswGGSyEZBBcgUKTbFYVQzW4gA= \
  KBB_AR_STATE=rtl MAIL_MAILER=log QUEUE_CONNECTION=sync \
  APP_CONFIG_CACHE="$DIR/compiled/cpu-config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/cpu-routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
exec php "$APP/tools/cpu-cron-queries.php" "$APP" "$1"
