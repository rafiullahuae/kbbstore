#!/bin/sh
# Lane GP: boot a preview of Growth & Marketing -> Free gifts with the
# "Gift-only products" panel.
#
#   sh tools/gp-preview.sh [PORT]
#
# A THROWAWAY COPY (tools/ad-preview.sh's approach, same autoloader shim):
# routes/free-gifts-quick-admin.php is not required from routes/web.php until
# the integrator wires it, so the copy's web.php gets that one line after
# free-gifts-admin.php -- in the copy, never in the worktree. vendor/ and docs/
# are shared by symlink. Seeds tools/fg2-seed.php, then tools/gp-seed.php.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=$(python3 - "${1:-10880}" <<'KBBPORTPY'
import socket, sys

start = int(sys.argv[1])

for p in range(start, start + 400):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', p)); s.close(); print(p); break
    except OSError:
        s.close()
else:
    raise SystemExit('no free port in [%d, %d)' % (start, start + 400))
KBBPORTPY
)
DIR=$APP/storage/framework/testing/gp-preview-$PORT
SRC=$DIR/app
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"
for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock; do
  cp -a "$APP/$part" "$SRC/$part"
done
cp -a "$APP/app" "$SRC/app"
ln -sfn "$APP/docs" "$SRC/docs"
cp -a "$APP/.env" "$SRC/.env" 2>/dev/null || true

python3 - "$SRC/routes/web.php" <<'KBBWIRE'
import sys
p = sys.argv[1]
s = open(p).read()
a = "require __DIR__.'/free-gifts-admin.php';"
assert s.count(a) == 1
open(p, 'w').write(s.replace(a, a + "\n        require __DIR__.'/free-gifts-quick-admin.php';"))
KBBWIRE

mkdir -p "$SRC/vendor"
for entry in "$APP"/vendor/*; do
  name=$(basename "$entry")
  [ "$name" = "autoload.php" ] && continue
  ln -sfn "$entry" "$SRC/vendor/$name"
done
cat > "$SRC/vendor/autoload.php" <<PHPSHIM
<?php
\$kbbLoader = require '$APP/vendor/autoload.php';

spl_autoload_register(static function (\$class) {
    if (strncmp(\$class, 'App\\\\', 4) !== 0) {
        return;
    }
    \$file = __DIR__ . '/../app/' . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
    if (is_file(\$file)) {
        require \$file;
    }
}, true, true);

return \$kbbLoader;
PHPSHIM
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" "$SRC/storage/app/public" \
         "$SRC/bootstrap/cache"

ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

export APP_URL="http://127.0.0.1:$PORT"
export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
php "$SRC/artisan" tinker --execute="require '$SRC/tools/fg2-seed.php'; require '$SRC/tools/gp-seed.php';" >>"$DIR/migrate.log" 2>&1 \
  || { tail -30 "$DIR/migrate.log"; exit 1; }
tail -3 "$DIR/migrate.log"
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING TO HAND BACK A PREVIEW: 127.0.0.1:$PORT is not this script's server." >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  dir $DIR"
