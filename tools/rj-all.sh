#!/bin/sh
# Lane RJ — regenerate every preview and screenshot in docs/rj-email-previews/.
#
#   sh tools/rj-all.sh
#
# Boots its own preview (tools/rj-preview.sh), renders today's emails through
# the real Mailables, builds the proposed ones, photographs everything, shoots
# the admin (today's and the proposed), composes OVERVIEW.png, and stops the
# server it started — by PID, never by pattern.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
D=$APP/docs/rj-email-previews
cd "$APP"

URL=$(sh tools/rj-preview.sh | sed -n 's/^preview on \(http[^ ]*\).*/\1/p')
PID=$(cat storage/framework/testing/lane-rj-preview/server.pid)
trap 'kill "$PID" 2>/dev/null || true' EXIT
echo "preview $URL (pid $PID)"

sh tools/rj-render.sh
node tools/rj-build-after.cjs
rm -rf "$D/before/shots" "$D/after/shots" "$D/directions/shots" "$D/marketing/shots"
node tools/rj-shots.cjs "$D/before" 390,600
node tools/rj-shots.cjs "$D/before" 1280 01-
node tools/rj-shots.cjs "$D/after" 390,600
node tools/rj-shots.cjs "$D/directions" 390,600
node tools/rj-shots.cjs "$D/directions" 1280 A-
RJ_DARK=1 node tools/rj-shots.cjs "$D/directions" 390 A-
node tools/rj-shots.cjs "$D/marketing" 390,600
node tools/rj-admin-before.cjs "$URL"
node tools/rj-admin-mockups.cjs "$URL"
node tools/rj-overview.cjs
