#!/bin/sh
# Boot a preview for the Lane SEC screenshots: the admin console with a DEAD
# SESSION, which is the state the download gate exists for.
#
# WHAT THE PICTURES ARE OF. Eleven addresses in this console are reached by
# navigating the browser at them rather than by fetch. An admin-api address
# outside the secret admin path answers a signed-out browser with a plain 404
# now, so a download on an expired session used to land on the admin login and
# now lands on a blank 404 -- and for the four window.location.href sites that
# takes the whole console with it. tools/sec-shots.cjs signs in, DROPS THE
# SESSION COOKIE, and presses the buttons: nothing is stubbed and no response is
# intercepted, so what it photographs is what the owner would see.
#
# Run it once on a clean tree (SEC_LABEL=before) and once with
# tools/sec-apply-blocks.py applied (SEC_LABEL=after).
#
# NOTHING SPECIAL IS MOUNTED. Every address this lane touches is already in
# routes/web.php; the only edits this lane needs are in the CONSOLE, and
# tools/sec-apply-blocks.py applies those to a scratch tree.
#
# Modelled on tools/bg-preview.sh, which is modelled on tools/sa-preview.sh.
set -e
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
# Same fix, and the same reasoning, as tools/cp-preview.sh and
# tools/px-progress-preview.sh.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-sec-preview
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite
# ── THE PORT IS ASKED FOR, NOT GUESSED ──────────────────────────────────────
#
# Every other preview script here takes a literal default, and this lane paid
# for that within the hour: `php -S` answered "Address already in use", the
# script reported a URL anyway because it never checks, and the next twenty
# minutes were spent reading ANOTHER LANE'S LEAKED PREVIEW as though it were
# this one -- a shop with the wrong catalogue in it, seeded products missing,
# and a 404 on a product that was definitely in the database.
#
# tests/Support/PreviewPort.php is the same fix for the suite and carries the
# same story. Here it is six lines of shell: walk up from the requested port and
# take the first one that will actually bind.
PORT=$(python3 - "${1:-8977}" <<'KBBPORTPY'
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

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

cp -r "$APP/public/build" "$ROOT/build" 2>/dev/null || true
mkdir -p "$ROOT/uploads"
: > "$DB"

# ── A THROWAWAY APP_KEY, GENERATED HERE ─────────────────────────────────────
#
# A fresh worktree has no .env, and without an encryption key EVERY page of the
# preview is a 500 -- "No application encryption key has been specified", thrown
# out of the session middleware, so even the 404 page cannot render. The other
# preview scripts here inherit a key from the main checkout's .env and therefore
# never had to say so.
#
# It is GENERATED rather than copied. This server holds seeded fixtures and
# nothing else; borrowing the shop's real key would put a live credential into a
# throwaway process for no benefit at all, and the cookies this signs are worth
# exactly one screenshot run.
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export APP_KEY

# ── THE ADMIN PATH IS SET HERE, AND DELIBERATELY NOT "admin" ────────────────
#
# TWO REASONS, AND THE FIRST ONE COST HALF AN HOUR.
#
# AdminPathService::current() reads KBB_ADMIN_PATH, then the `admin_path`
# settings row, then falls back to "admin" -- and it MEMOISES INTO THE FILE
# CACHE, which on a default CACHE_STORE=file lives at
# storage/framework/cache/data INSIDE THE WORKTREE and is therefore shared with
# every pest run and every other preview this worktree has ever booted. This
# preview's own database has no admin_path row at all, and /admin still answered
# 404: the cache held `mr-cool` from something else entirely. A preview whose
# admin address depends on what a test left behind is not reproducible, so it is
# pinned here where it can be read.
#
# And it is pinned to a NON-DEFAULT value on purpose, because that is the shop
# this lane is photographing. routes/web.php answers `/admin/{any?}` with
# abort(404) as soon as the path is moved -- so it is only on a moved path that
# the console's hardcoded '/admin/logout' and '/admin/login' are dead, which is
# what tools/sec-shots.cjs measures in its third section.
export KBB_ADMIN_PATH=sec-console

export KBB_PUBLIC_PATH="$ROOT" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" \
  APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" \
  APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
mkdir -p "$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
# STDIN IS CLOSED ON BOTH ARMS. `artisan tinker <file>` runs the file and then
# drops into its REPL, which BLOCKS on stdin for ever whenever a terminal is
# attached: the log says the seed is done and the server never comes up.
# PreviewSeedCannotHangTest fails by name if either redirect is dropped.
php "$APP/artisan" tinker "$APP/tools/sec-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/sec-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

# ── THE COMPILED ROUTE TABLE HAS TO GO, OR THE BLOCK ABOVE DOES NOTHING ─────
#
# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file at $APP_ROUTES_CACHE. A compiled table
# is a CompiledRouteCollection, and routes registered at runtime against one of
# those are never matched -- which is the same trap
# tests/Support/SetsAdminRoutes.php copies its way out of. Measured here: with
# the file in place even a one-line marker route 404'd.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2
# REPORTED ONLY IF IT ANSWERS. The line below used to print unconditionally, so
# a server that failed to bind still produced a URL to point a browser at.
if ! curl -fsS -o /dev/null "http://127.0.0.1:$PORT/"; then
  echo "preview did NOT come up on $PORT:"; tail -20 "$DIR/server.log"; exit 1
fi

# ── AND IT PROVES THE SERVER ANSWERING IS THE ONE THIS SCRIPT STARTED ───────
#
# A 200 is not enough, which is the whole lesson of the twenty minutes above:
# ANOTHER LANE's preview answers 200 to everything too. Two checks, and they
# fail for different reasons on purpose.
#
# The NONCE is written into this script's own webroot and read back over HTTP.
# Another lane's server is rooted in another lane's directory, so it cannot have
# the file whatever its catalogue looks like. It is the general check, and every
# preview script in this repository carries it now.
kbbnonce=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s' "$kbbnonce" > "$ROOT/kbb-preview-id.txt"

if [ "$(curl -s "http://127.0.0.1:$PORT/kbb-preview-id.txt" || true)" != "$kbbnonce" ]; then
  echo "REFUSING TO HAND BACK A PREVIEW: the server answering on 127.0.0.1:$PORT" >&2
  echo "is not the one this script started -- it is serving another webroot." >&2
  tail -10 "$DIR/server.log" >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

# The FIXTURE check is Lane PG2's, and it answers the other half of the
# question: the server is mine, but has it got MY SEED in it? A webroot that
# came up before `artisan tinker` finished, or against a database an earlier run
# left half-migrated, passes the nonce and still photographs an empty shop. So
# ask for a product only tools/sec-seed.php creates, and require its name in the
# body rather than only a 200.
kbbfixture=$(curl -s -o "$DIR/probe.html" -w '%{http_code}' \
  "http://127.0.0.1:$PORT/product/lanesec-1/" || echo 000)

if [ "$kbbfixture" != "200" ] || ! grep -q 'Lane SEC Heartleaf Toner 1 250ml' "$DIR/probe.html"; then
  echo "the server on $PORT did not answer this lane's own fixture (HTTP $kbbfixture)." >&2
  echo "Refusing to hand back a preview that would photograph the wrong catalogue." >&2
  tail -10 "$DIR/server.log" >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 5
fi
echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  fixture OK"
echo "admin console: http://127.0.0.1:$PORT/$KBB_ADMIN_PATH   sign in: owner@preview.test / preview-secret-1"
echo "stop it with: kill $(cat "$DIR/server.pid")   # NEVER pkill -f: three lanes share this machine"
