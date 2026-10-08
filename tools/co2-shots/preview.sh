#!/bin/sh
# Lane CO2: the sold-out dialog preview (tools/co-card-walk/preview.sh with its own seed: pictures, a set, Arabic on).
# With a GIT-REF the app is materialised from that commit (the BEFORE shots).
# Everything lives under storage/co2-logs/ of THIS worktree -- never the shared
# scratchpad (CLAUDE.md: one lane read another's numbers out of it).
set -e
LANE=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PORT=${1:-8871}
REF=${2:-}
DIR=$LANE/storage/co2-logs/preview-$PORT
rm -rf "$DIR"; mkdir -p "$DIR/state" "$DIR/web"
if [ -n "$REF" ]; then
  mkdir -p "$DIR/app"
  git -C "$LANE" archive "$REF" | tar -x -C "$DIR/app"
  # vendor/ shared entry by entry, with an App\\ autoloader of the COPY's own
  # in front of composer's (whose paths are absolute to this worktree) -- the
  # trap tools/ad-preview.sh records: without it a BEFORE preview serves the
  # old views with the new classes.
  mkdir -p "$DIR/app/vendor"
  for entry in "$LANE"/vendor/*; do
    name=$(basename "$entry"); [ "$name" = "autoload.php" ] && continue
    ln -sfn "$entry" "$DIR/app/vendor/$name"
  done
  cat > "$DIR/app/vendor/autoload.php" <<PHPSHIM
<?php
\$kbbLoader = require '$LANE/vendor/autoload.php';
spl_autoload_register(static function (\$class) {
    if (strncmp(\$class, 'App\\\\', 4) !== 0) { return; }
    \$file = __DIR__ . '/../app/' . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
    if (is_file(\$file)) { require \$file; }
}, true, true);
return \$kbbLoader;
PHPSHIM
  mkdir -p "$DIR/app/storage/framework/views" "$DIR/app/storage/framework/sessions" "$DIR/app/storage/framework/cache/data" "$DIR/app/storage/logs" "$DIR/app/bootstrap/cache"
  cp "$LANE/.env" "$DIR/app/.env"
  cp -r "$LANE/public/build" "$DIR/app/public/build" 2>/dev/null || true
  [ -d "$DIR/app/public/build" ] || git -C "$LANE" archive "$REF" public/build | tar -x -C "$DIR/app"
  APPDIR=$DIR/app
else
  APPDIR=$LANE
fi
DB=$DIR/preview.sqlite; : > "$DB"
export CO_APP="$APPDIR" CO_STATE="$DIR/state" KBB_PUBLIC_PATH="$APPDIR/public" APP_ENV=local APP_DEBUG=false \
  APP_URL="http://127.0.0.1:$PORT" KBB_BASE_PATH= DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/config.php" APP_ROUTES_CACHE="$DIR/routes.php" APP_EVENTS_CACHE="$DIR/events.php" \
  APP_SERVICES_CACHE="$DIR/services.php" APP_PACKAGES_CACHE="$DIR/packages.php" PHP_CLI_SERVER_WORKERS=4
php "$APPDIR/artisan" migrate --force > "$DIR/migrate.log" 2>&1 || { tail -20 "$DIR/migrate.log"; exit 1; }
php "$APPDIR/artisan" tinker --execute="require '$LANE/tools/co2-shots/seed.php';" >> "$DIR/migrate.log" 2>&1
cp "$LANE/tools/co-card-walk/preview-index.php" "$DIR/web/index.php"
cp "$LANE/tools/co-card-walk/preview-router.php" "$DIR/web/router.php"
php -S 127.0.0.1:"$PORT" -t "$DIR/web" "$DIR/web/router.php" > "$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:$PORT/product/co-glow-serum"
echo "preview on http://127.0.0.1:$PORT pid $(cat "$DIR/server.pid") state $DIR/state"
