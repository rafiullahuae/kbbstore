#!/bin/sh
# Lane HT -- Product tabs previews on the ARF preview database.
#
#   sh tools/arf-preview.sh 9741 fresh       # once: build the preview DB (Arabic on)
#   sh tools/arf-artisan.sh tinker --execute="require 'tools/ht-seed.php';"
#   sh tools/ht-preview.sh <port>            # serve /ht-preview/{a..e} and /ar/ht-preview/{a..e}
#
# Serves the same webroot with tools/ht-router.php, which registers the preview
# route at boot. routes/web.php is not touched; nothing here ships.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
# Asks for a free port from the one given rather than typing one, and proves
# the server answering is its own before printing a URL (the rules in
# PreviewScriptsCannotShootTheWrongShopTest; the same block as arf-preview.sh).
PORT=$(python3 - "${1:-9751}" <<'KBBPORTPY'
import socket, sys
start = int(sys.argv[1])
for p in range(start, start + 400):
    s = socket.socket()
    try:
        s.bind(('127.0.0.1', p)); s.close(); print(p); break
    except OSError:
        s.close()
else:
    raise SystemExit('no free port')
KBBPORTPY
)
DIR=$APP/storage/framework/testing/arf-preview
ROOT=$DIR/webroot
export KBB_ARF_LIVE=1 KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=false \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=array \
  APP_KEY=base64:Lz4DLwIXgs9U4UHRTuswGGSyEZBBcgUKTbFYVQzW4gA= \
  PHP_CLI_SERVER_WORKERS=4 KBB_AR_STATE=rtl MAIL_MAILER=log \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/ht-routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
if [ -f "$DIR/server.pid" ]; then kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; fi
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$APP/tools/ht-router.php" >"$DIR/ht-server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"
if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING: 127.0.0.1:$PORT is not this preview" >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true; exit 4
fi
echo "ht-preview on http://127.0.0.1:$PORT/ht-preview/a  pid $(cat "$DIR/server.pid")"
