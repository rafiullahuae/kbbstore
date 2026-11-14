#!/bin/sh
# Lane GS2: write ProductGiftStrip settings into the tools/pdp-preview.sh
# database, for tools/gs2-shots.cjs. Each argument is key=value (without the
# pgs_ prefix); an empty value deletes the row (back to the shipped default).
#   sh tools/gs2-set.sh style=underline fs=
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-pdp-preview
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" \
  SESSION_DRIVER=file CACHE_STORE=file APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
GS2_SET="$*" php "$APP/artisan" tinker --execute='
$s = app(\App\Services\SettingsService::class);
foreach (explode(" ", getenv("GS2_SET") ?: "") as $pair) {
    if ($pair === "" || ! str_contains($pair, "=")) { continue; }
    [$k, $v] = explode("=", $pair, 2);
    if ($v === "") { \App\Models\Setting::query()->where("key", "pgs_".$k)->delete(); } else { $s->set("pgs_".$k, $v); }
}
\App\Models\Setting::flushMap(); $s->flush(); echo "set\n";' </dev/null
