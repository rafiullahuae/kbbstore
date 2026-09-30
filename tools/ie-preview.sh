#!/bin/sh
# Boot a preview for the Lane IE cleanup screen shots.
#
# A copy of tools/bp-preview.sh with its own port range and its own seed. Every
# check in it, and the story behind each, is that script's and is kept verbatim
# because each one was paid for.
#
# WHY IT COPIES THE TREE RATHER THAN SYMLINKING IT. routes/cleanup-admin.php is
# NOT mounted -- CLAUDE.md forbids this lane from editing routes/web.php, so the
# integrator wires it. The screen therefore cannot be reached at all until the
# require line exists. Adding it to a COPY is how the shots get taken without
# this lane touching the integrator's file: $SRC is a full copy of the
# worktree, the require goes into $SRC/routes/web.php, and the worktree's own
# routes/web.php is never opened for writing.
#
# The require is added with substr_count checked FIRST, so that on the day the
# integrator mounts it this script adds nothing and still works -- rather than
# registering every /admin-api/cleanup route twice, which is the "two" failure
# CLAUDE.md names beside "zero".
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

PORT=$(python3 - "${1:-8951}" <<'KBBPORTPY'
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

DIR=$APP/storage/framework/testing/lane-ie-preview-$PORT
ROOT=$DIR/webroot
SRC=$DIR/src
DB=$DIR/preview.sqlite

# REFUSE rather than clear: only a listener whose command line names THIS
# worktree's preview is stopped, and then BY PID. CLAUDE.md's pkill hazard.
kbboccupied=$(ss -ltnp 2>/dev/null | grep ":$PORT " | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u)

for kbbpid in $kbboccupied; do
  kbbcmd=$(tr '\0' ' ' < "/proc/$kbbpid/cmdline" 2>/dev/null || true)
  [ -z "$kbbcmd" ] && continue
  case "$kbbcmd" in
    *"$APP/storage/framework/testing/lane-ie-preview"*) kill "$kbbpid" 2>/dev/null || true ;;
    *)
      echo "port $PORT is taken by pid $kbbpid, which is not this worktree's preview." >&2
      echo "  $kbbcmd" >&2
      echo "Pass another port: sh tools/ie-preview.sh <port>. Nothing was killed." >&2
      exit 3 ;;
  esac
done

[ -n "$kbboccupied" ] && sleep 1

rm -rf "$DIR"
mkdir -p "$ROOT" "$SRC" "$DIR/compiled"

for part in artisan bootstrap config database public public-web-root resources routes tools composer.json composer.lock .env; do
  cp -a "$APP/$part" "$SRC/$part"
done
cp -a "$APP/app" "$SRC/app"
ln -sfn "$APP/vendor" "$SRC/vendor"
mkdir -p "$SRC/storage/framework/views" "$SRC/storage/framework/sessions" \
         "$SRC/storage/framework/cache/data" "$SRC/storage/logs" \
         "$SRC/storage/app/public" "$SRC/storage/app/private" "$SRC/bootstrap/cache"

# ── MOUNT THE ROUTE FILE IN THE COPY, ONCE, AND ONLY IF IT IS NOT THERE ─────
php -r '
$p = $argv[1];
$s = file_get_contents($p);
$need = "require __DIR__.\x27/cleanup-admin.php\x27;";
$n = substr_count($s, $need);

if ($n > 1) {
    fwrite(STDERR, "routes/web.php already requires cleanup-admin.php {$n} times\n");
    exit(1);
}

if ($n === 1) {
    echo "cleanup-admin.php already mounted by the integrator; adding nothing.\n";
    exit(0);
}

// Beside the import route files, which is where the header asks for it.
$anchor = "require __DIR__.\x27/import-history-admin.php\x27;";

if (substr_count($s, $anchor) !== 1) {
    fwrite(STDERR, "could not find the import-history require to sit beside\n");
    exit(1);
}

file_put_contents($p, str_replace($anchor, $anchor."\n        ".$need, $s));
echo "mounted cleanup-admin.php in the preview copy.\n";
' "$SRC/routes/web.php"

ln -sfn "$SRC" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"
cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

php "$SRC/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }

# STDIN IS CLOSED. `artisan tinker <file>` drops into its REPL and blocks for
# ever whenever a terminal is attached; PreviewSeedCannotHangTest fails by name
# if either redirect is dropped.
php "$SRC/artisan" tinker --execute="require '$APP/tools/ie-seed.php';" \
  >>"$DIR/migrate.log" 2>&1 </dev/null || { tail -30 "$DIR/migrate.log"; exit 1; }

# A migration in this repository runs route:cache, and routes registered
# against a CompiledRouteCollection are never matched. The compiled table has to
# go or the mount above does nothing.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"

if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING TO HAND BACK A PREVIEW: the server answering on 127.0.0.1:$PORT" >&2
  echo "is not the one this script started." >&2
  tail -10 "$DIR/server.log" >&2 2>/dev/null || true
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  app $SRC"
