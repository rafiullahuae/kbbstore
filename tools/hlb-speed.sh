#!/bin/sh
# Lane HLB -- server ms (median of 7 warm runs), queries and settings-map reads
# for the given pages, through the HTTP kernel, against the tools/arr-preview.sh
# database (Arabic on) of the tree named by $1 -- so the same script measures a
# clean checkout (before) and this worktree (after). Uses tools/arf-speed.php.
#   tools/hlb-speed.sh <app dir> <label> url...   -> <app dir>/storage/arf-logs/speed-<label>.json
set -e
APP=$1; shift
DIR=$APP/storage/framework/testing/ar-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=array \
  APP_KEY=base64:Lz4DLwIXgs9U4UHRTuswGGSyEZBBcgUKTbFYVQzW4gA= \
  KBB_AR_STATE=rtl MAIL_MAILER=log \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

exec php "$APP/tools/arf-speed.php" "$APP" "$@"
