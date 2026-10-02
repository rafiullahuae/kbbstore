#!/bin/sh
# Lane PR — tap a card on a phone under EVERY card design and report anything
# that still moves. See tools/pr-hover-skins.cjs. Leaves the shipped skin
# (row deleted) behind when it is done.
#
#   sh tools/pr-hover-skins.sh [port]
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${1:-8961}

SKINS=$(php -r "require '$APP/vendor/autoload.php'; echo implode(' ', array_keys(App\Support\GridSkins::ALL));")

for skin in $SKINS; do
  PR_SET="grid_skin=$skin" sh "$APP/tools/pr-set.sh" "$PORT" >/dev/null
  PR_BASE="http://127.0.0.1:$PORT" node "$APP/tools/pr-hover-skins.cjs" "$skin"
done

PR_SET="grid_skin=-" sh "$APP/tools/pr-set.sh" "$PORT" >/dev/null
