#!/bin/sh
# Lane PG2 — every picture in docs/pg2-card-shots, from one command.
#
# THE ORDER IS THE POINT and it is the same one tools/spl-arabic-on.php argues
# for: the English pages are shot BEFORE Arabic is switched on, because a shop
# with Arabic enabled renders a language switcher in its header that a shop
# without it does not — and the contact sheets are what the owner is choosing a
# card from, so they have to show the chrome this shop has today.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${KBB_PORT:-8931}
# One directory per port -- see tools/pg2-preview.sh for what a shared one cost.
DIR=$APP/storage/framework/testing/lane-pg2-preview-$PORT
OUT=${KBB_SHOTS:-$APP/docs/pg2-card-shots}

# 1 · a fresh preview. It copies public/build into its own web root BEFORE the
#     server starts, which is where that belongs: this script used to re-copy it
#     AFTERWARDS, and twice the server was gone by the first screenshot with
#     nothing in its log -- `rm -rf` on a directory a live `php -S` is serving
#     out of. `npx vite build` is still manual in this project, so the bundle
#     must be built before this runs or every picture is of the last release.
sh "$APP/tools/pg2-preview.sh" "$PORT"

export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite \
  DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file

# 2 · the five treatments, both widths — the contact sheets' panels.
KBB_PORT="$PORT" KBB_SHOTS="$OUT" sh "$APP/tools/pg2-shoot.sh"

# 3 · back to the shipped default, then every page that has a product grid,
#     plus the basket, which must have none.
KBB_SKIN=showcase php "$APP/artisan" tinker "$APP/tools/pg2-set-skin.php" </dev/null >/dev/null 2>&1 \
  || KBB_SKIN=showcase php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-set-skin.php';" </dev/null >/dev/null 2>&1
KBB_BASE="http://127.0.0.1:$PORT" KBB_SHOTS="$OUT" KBB_PLAN="$APP/tools/pg2-plan-pages.json" \
  KBB_MEASURE=measure-pages node "$APP/tools/pg2-card-shots.cjs"

# 3a · the two close-ups: one tile measured part by part with the quick-view
#      pill showing, and the short-name / long-name pair that answers "what
#      happens to a long title". Both crop to the cards, so neither depends on
#      the page chrome around them.
KBB_BASE="http://127.0.0.1:$PORT" KBB_SHOTS="$OUT" node "$APP/tools/pg2-card-geometry.cjs"
KBB_BASE="http://127.0.0.1:$PORT" KBB_SHOTS="$OUT" node "$APP/tools/pg2-names-shot.cjs"

# 3b · and the SHIPPED state of the same card: Catalogue -> Wishlist is OFF on
#      this shop, so what applying the package actually draws is the button at
#      the card's full width and no heart. The contact sheets are shot with the
#      module ON because the heart is part of the design being chosen; this is
#      the other half of the truth, and it is one shot rather than five because
#      the difference is the same on all four treatments.
KBB_WISHLIST=0 php "$APP/artisan" tinker "$APP/tools/pg2-wishlist.php" </dev/null >/dev/null 2>&1 \
  || KBB_WISHLIST=0 php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-wishlist.php';" </dev/null >/dev/null 2>&1
KBB_BASE="http://127.0.0.1:$PORT" KBB_SHOTS="$OUT" KBB_PLAN="$APP/tools/pg2-plan-no-wishlist.json" \
  KBB_MEASURE=measure-no-wishlist node "$APP/tools/pg2-card-shots.cjs"

# 4 · Arabic LAST, for the reason at the top of this file — and the wishlist
#     back ON first, because the heart on the far side of the button is exactly
#     what the mirrored pair is for. Without this line the Arabic shots came
#     back with `heart=-` on all four rows, which is a picture of step 3b's
#     leftover state rather than of the Arabic card.
KBB_WISHLIST=1 php "$APP/artisan" tinker "$APP/tools/pg2-wishlist.php" </dev/null >/dev/null 2>&1 \
  || KBB_WISHLIST=1 php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-wishlist.php';" </dev/null >/dev/null 2>&1
# 4 · Arabic LAST, for the reason at the top of this file.
php "$APP/artisan" tinker "$APP/tools/pg2-arabic-on.php" </dev/null >/dev/null 2>&1 \
  || php "$APP/artisan" tinker --execute="require '$APP/tools/pg2-arabic-on.php';" </dev/null >/dev/null 2>&1
KBB_BASE="http://127.0.0.1:$PORT" KBB_SHOTS="$OUT" KBB_PLAN="$APP/tools/pg2-plan-arabic.json" \
  KBB_MEASURE=measure-arabic node "$APP/tools/pg2-card-shots.cjs"

# 5 · the sheets.
KBB_SHOTS="$OUT" node "$APP/tools/pg2-sheet.cjs"
