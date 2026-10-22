#!/bin/sh
# Lane FG: before/after server ms and queries for product, category, brand,
# cart and checkout. BEFORE=<a tree of the integration head> sh tools/fg-measure.sh
# Needs tools/fg-preview.sh to have built its database first.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
WORK=${WORK:-$APP/storage/fg-logs}
mkdir -p "$WORK"
cp "$APP/storage/framework/testing/fg-preview/preview.sqlite" "$WORK/fg-on.sqlite"
cp "$WORK/fg-on.sqlite" "$WORK/fg-off.sqlite"
php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("update settings set value=json_set(value, \"$.on\", json(\"false\")) where key=\"free_gifts\"");' "$WORK/fg-off.sqlite"

run() {
  echo "== $1"
  (cd "$2" && KBB_PUBLIC_PATH="$2/public" DB_CONNECTION=sqlite DB_DATABASE="$3" CACHE_STORE=array SESSION_DRIVER=array APP_ENV=local APP_DEBUG=false \
    php artisan tinker --execute="require '$APP/tools/fg-measure.php';" 2>&1 | grep '^{')
}

[ -n "$BEFORE" ] && run before "$BEFORE" "$WORK/fg-off.sqlite"
run after-off "$APP" "$WORK/fg-off.sqlite"
run after-on "$APP" "$WORK/fg-on.sqlite"
