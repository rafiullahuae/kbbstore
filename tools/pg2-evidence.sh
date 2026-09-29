#!/bin/sh
# Lane PG2 — every picture in docs/pg2-shots, from one command.
#
# THE ORDER IS THE POINT and it is the same one tools/spl-arabic-on.php argues
# for: the English pages are shot BEFORE Arabic is switched on, because a shop
# with Arabic enabled renders a language switcher in its header that a shop
# without it does not — and the contact sheets are what the owner is choosing a
# card from, so they have to show the chrome this shop has today.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-pg2-preview
PORT=${KBB_PORT:-8931}
OUT=${KBB_SHOTS:-$APP/docs/pg2-shots}

# 1 · a fresh preview, and the BUILT stylesheet in its web root. `npx vite build`
#     is manual in this project and a preview served against a stale
#     public/build is a picture of the last release.
sh "$APP/tools/pg2-preview.sh" "$PORT" >/dev/null
rm -rf "$DIR/webroot/build"
cp -r "$APP/public/build" "$DIR/webroot/build"

env() { :; }
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite \
  DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file

# 2 · the five treatments, both widths — the contact sheets' panels.
KBB_PORT="$PORT" KBB_SHOTS="$OUT" sh "$APP/tools/pg2-shoot.sh"

# 3 · back to the shipped default, then every page that has a product grid,
#     plus the basket, which must have none.
KBB_SKIN=showcase php "$APP/artisan" tinker "$APP/tools/pg2-set-skin.php" </dev/null >/dev/null 2>&1 \
  || KBB_SKIN=showcase php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-set-skin.php';" </dev/null >/dev/null 2>&1
KBB_BASE="http://127.0.0.1:$PORT" KBB_SHOTS="$OUT" KBB_PLAN="$APP/tools/pg2-plan-pages.json" \
  KBB_MEASURE=measure-pages node "$APP/tools/pg2-shots.cjs"

# 4 · Arabic LAST, for the reason at the top of this file.
php "$APP/artisan" tinker "$APP/tools/pg2-arabic-on.php" </dev/null >/dev/null 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-arabic-on.php';" </dev/null >/dev/null 2>&1
KBB_BASE="http://127.0.0.1:$PORT" KBB_SHOTS="$OUT" KBB_PLAN="$APP/tools/pg2-plan-arabic.json" \
  KBB_MEASURE=measure-arabic node "$APP/tools/pg2-shots.cjs"

# 5 · the sheets.
KBB_SHOTS="$OUT" node "$APP/tools/pg2-sheet.cjs"
