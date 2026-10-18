#!/bin/sh
# Lane CPU -- what one minute of the live cron line costs: `php artisan
# schedule:run` (opcache OFF on the CLI, as a stock PHP CLI runs it) against the
# arf-preview database, wall + user + sys CPU INCLUDING the child processes the
# scheduler forks, R times; then each every-minute command alone. PREVIEW ONLY.
#   tools/cpu-cron.sh <runs> <label>    -> docs/lane-cpu/cron-<label>.txt
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
OUT=$APP/docs/lane-cpu/cron-$2.txt
: > "$OUT"
echo "## php artisan schedule:list" >> "$OUT"
php "$APP/artisan" schedule:list >> "$OUT" 2>&1
i=0
while [ $i -lt "$1" ]; do
  echo "## schedule:run #$i" >> "$OUT"
  python3 "$APP/tools/cpu-time.py" "$OUT" php "$APP/artisan" schedule:run >> "$OUT" 2>&1
  i=$((i + 1))
done
echo "## bare boot: php artisan --version" >> "$OUT"
python3 "$APP/tools/cpu-time.py" "$OUT" php "$APP/artisan" --version >> "$OUT" 2>&1
python3 "$APP/tools/cpu-time.py" "$OUT" php "$APP/artisan" --version >> "$OUT" 2>&1
for c in ugc:cut-covers\ --limit=20\ --unattended kbb:order-reminders kbb:campaigns-step kbb:push-step kbb:analytics-rollup kbb:order-emirates; do
  echo "## alone: $c" >> "$OUT"
  # shellcheck disable=SC2086
  python3 "$APP/tools/cpu-time.py" "$OUT" php "$APP/artisan" $c >> "$OUT" 2>&1
done
cat "$OUT"
