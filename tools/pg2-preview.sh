#!/bin/sh
# Boot a preview for the Lane PG2 contact sheets.
# Modelled on tools/pp2-preview.sh, which is modelled on tools/sp-preview.sh.
#
# The skin under test is NOT chosen here: tools/pg2-set-skin.php writes
# `grid_skin` between shots, against the database this script leaves behind, so
# one boot produces every treatment at both widths from the same fixture and
# the same server. The path it writes to is printed below.
set -e
# DERIVED FROM THIS SCRIPT'S OWN LOCATION, NEVER HARDCODED. Two harnesses in
# this repository became unrunnable because they named their lane's worktree and
# that worktree was removed when the branch merged -- the screenshots are a
# deliverable, so the thing that produces them has to travel with the branch.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${1:-8931}
# ── THE DIRECTORY IS NAMED FOR THE PORT, AND THAT IS NOT COSMETIC ───────────
#
# It was one fixed path, and running this script on a SECOND port therefore
# `rm -rf`d the first port's database out from under a server that was still
# happily listening. Measured while proving the two checks below: a preview on
# 8931 started answering 404 for its own category the moment a mutation run
# booted one on 8939. That is the same failure as the stale-server one -- a live
# server holding an open handle on a deleted inode -- arriving from the other
# direction, and it is why two ports now mean two databases.
DIR=$APP/storage/framework/testing/lane-pg2-preview-$PORT
ROOT=$DIR/webroot
DB=$DIR/preview.sqlite

# ── THE PORT IS CHECKED, AND WHAT IS ON IT IS NOT ASSUMED TO BE MINE ────────
#
# Two failures, one of them another lane's and one of them this one's, and the
# same rule ends both.
#
# MINE: `php -S` fails with "Address already in use" and this script used to
# carry on, so the OLD server kept answering -- holding an open handle on the
# sqlite file the line below was about to delete and recreate. A deleted inode
# is still a readable, EMPTY database, so every page rendered from a catalogue
# that was no longer there: /shop answered 200 with a grid of nothing and
# /collections/skincare-sets/ answered 404, which reads exactly like a broken
# route and sent this lane looking at CategoryPath::resolve() twice.
#
# ANOTHER LANE'S: it wanted this port, found this lane's server on it, and
# cleared it with `ps | grep '[p]hp -S 127.0.0.1:8931' | xargs kill -9`. That is
# CLAUDE.md's `pkill` hazard wearing a different hat -- a PATTERN kill, which
# matched every worker of a server that was not its own, and the shoot that came
# after it looked completely finished.
#
# So: REFUSE rather than clear. If something is listening here and it is not
# this worktree's own preview, this script stops and says so, because the one
# thing it must never do is take another lane's server down. Only a listener
# whose own command line names THIS webroot is killed, and then BY PID.
kbboccupied=$(ss -ltnp 2>/dev/null | grep ":$PORT " | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u)

for kbbpid in $kbboccupied; do
  # ONE READ OF THE COMMAND LINE, AND AN EMPTY ANSWER MEANS GONE.
  #
  # `ss` lists a socket whose owner has already exited, and this raced twice:
  # first with a `[ -r ... ]` test that passed and a `tr` that then found
  # nothing, and the script refused to start over a process that no longer
  # existed. A dead PID is not an occupied port and it is not somebody else's
  # server, so it is skipped.
  kbbcmd=$(tr '\0' ' ' < "/proc/$kbbpid/cmdline" 2>/dev/null || true)

  if [ -z "$kbbcmd" ]; then
    continue
  fi

  # Matched on THIS WORKTREE's preview root, not on this port's own directory:
  # a server left over from before the per-port rename is still mine and still
  # safe to stop, and anything outside this worktree never is.
  case "$kbbcmd" in
    *"$APP/storage/framework/testing/lane-pg2-preview"*)
      kill "$kbbpid" 2>/dev/null || true
      ;;
    *)
      echo "port $PORT is taken by pid $kbbpid, which is not this worktree's preview." >&2
      echo "  $kbbcmd" >&2
      echo "Pass another port: sh tools/pg2-preview.sh <port>. Nothing was killed." >&2
      exit 3
      ;;
  esac
done

[ -n "$kbboccupied" ] && sleep 1

rm -rf "$DIR"
mkdir -p "$ROOT"
ln -sfn "$APP" "$DIR/kbb-upgrade-app"
cp "$APP/public-web-root/index.php" "$ROOT/index.php"

# ── NO ROUTE FILE TO MOUNT ──────────────────────────────────────────────────
#
# This lane adds no route and no screen: a skin is a CSS block and a row in
# App\Support\GridSkins, so the preview needs nothing but the repository's own
# routes. The one thing it does need is the BUILT stylesheet, copied below --
# `npx vite build` is manual here and a preview served against a stale
# public/build is a picture of the last release.
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
mkdir -p "$DIR/compiled"

php "$APP/artisan" migrate --force >"$DIR/migrate.log" 2>&1 || { tail -30 "$DIR/migrate.log"; exit 1; }
# STDIN IS CLOSED ON BOTH ARMS. `artisan tinker <file>` runs the file and then
# drops into its REPL, which BLOCKS on stdin for ever whenever a terminal is
# attached: the log says the seed is done and the server never comes up.
# PreviewSeedCannotHangTest fails by name if either redirect is dropped.
php "$APP/artisan" tinker "$APP/tools/pg2-seed.php" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-seed.php';" >>"$DIR/migrate.log" 2>&1 </dev/null \
  || { tail -30 "$DIR/migrate.log"; exit 1; }

# ── THE COMPILED ROUTE TABLE HAS TO GO, OR THE BLOCK ABOVE DOES NOTHING ─────
#
# A migration in this repository runs route:cache, so by the end of `migrate`
# the preview has a compiled routes file at $APP_ROUTES_CACHE. A compiled table
# is a CompiledRouteCollection, and routes registered at runtime against one of
# those are never matched -- measured: with the file in place even a one-line
# marker route 404'd.
rm -f "$DIR/compiled/routes.php"

cp "$APP/tools/m1-router.php" "$ROOT/router.php"
php -S 127.0.0.1:"$PORT" -t "$ROOT" "$ROOT/router.php" >"$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 2

# ── AND IT PROVES THE SERVER ANSWERING IS THIS FIXTURE'S ────────────────────
#
# A 200 is not enough and neither is a screenshot that looks right: a dead bind
# on this port has, in this session, produced a run that photographed ANOTHER
# lane's catalogue while looking completely finished. So the smoke test asks for
# a slug only tools/pg2-seed.php creates, and requires both a 200 and the
# fixture's own product name in the body. Either check would have caught that at
# second zero rather than at minute four.
kbbprobe=$(curl -s -o "$DIR/probe.html" -w '%{http_code}' \
  "http://127.0.0.1:$PORT/product/pg2-relief-sun/" || echo 000)

if [ "$kbbprobe" != "200" ] || ! grep -q 'Relief Sun Rice + Probiotics SPF50' "$DIR/probe.html"; then
  echo "the server on $PORT did not answer this lane's own fixture (HTTP $kbbprobe)." >&2
  echo "Refusing to hand back a preview that would photograph somebody else's shop." >&2
  tail -5 "$DIR/server.log" >&2
  kill "$(cat "$DIR/server.pid")" 2>/dev/null || true
  exit 4
fi

echo "preview on http://127.0.0.1:$PORT  pid $(cat "$DIR/server.pid")  root $ROOT  fixture OK"
