#!/bin/sh
# Everything Lane CARD owes as a picture, in the order the order matters.
#
#   sh tools/card-evidence.sh [port]
#
# ── WHY THE ORDER IS THE POINT ──────────────────────────────────────────────
#
# The state under test is written with tools/card-set.php against the database
# one booted preview leaves behind, so every panel is the same fixture on the
# same server and differs by the setting alone. And it ENDS at the shipped
# state: a run that left `show_brand` set behind would leave whatever ran next
# photographing a shop nobody chose — tools/pg2-wishlist.php carries the same
# note, and the reason it does is a shoot that came out with no heart in it.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
LOG=$APP/storage/card-logs/evidence.txt

mkdir -p "$APP/storage/card-logs"
: > "$LOG"

# ── THE PORT IS READ BACK, NEVER ASSUMED ────────────────────────────────────
#
# tools/card-preview.sh WALKS UP from the port it is asked for and takes the
# first one that will bind — thirty-nine preview scripts share this repository
# and a collision is the design, so that walk is correct. This script asked for
# 8995, the preview came up on 8996, and the whole run then photographed
# whatever was on 8995: /shop answered 200 with twenty-four branded tiles, the
# fixture's own collection answered 404, and the shots looked completely
# finished. That is CLAUDE.md's leaked-server hazard arriving from the one
# direction the preview's own two probes cannot see, because they check the
# server the SCRIPT started and this checked a different one.
#
# So the port comes out of the preview's own last line, and the preview's own
# database is what the setters below write to.
PREVIEW=$(sh "$APP/tools/card-preview.sh" "${1:-8990}" | tee -a "$LOG" | tail -1)
echo "$PREVIEW"
PORT=$(echo "$PREVIEW" | sed -n 's#.*127\.0\.0\.1:\([0-9]*\).*#\1#p')
[ -n "$PORT" ] || { echo "could not read the preview's port out of: $PREVIEW" >&2; exit 5; }
BASE=http://127.0.0.1:$PORT
DIR=$APP/storage/framework/testing/lane-card-preview-$PORT

say() { echo ""; echo "── $1"; echo "" >> "$LOG"; echo "── $1" >> "$LOG"; }
shot() { KBB_BASE=$BASE node "$APP/tools/card-shots.cjs" "$@" | tee -a "$LOG"; }

# ── AND THE SETTERS WRITE TO THE PREVIEW'S DATABASE, NOT THE SUITE'S ────────
#
# `php artisan tinker tools/card-set.php` run from $APP with no environment
# reads .env, which points DB_DATABASE at database/testing.sqlite — so the first
# version of this deleted settings rows out of the suite's database and left
# every panel showing the state before it. Same three env vars the preview
# script exports, and CACHE_STORE matters as much as the database: the server
# reads that file cache, and a setter that flushes a different one leaves the
# next screenshot showing the previous setting.
preview_artisan() {
  script=$1
  shift
  ( cd "$APP" && env DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" \
      CACHE_STORE=file SESSION_DRIVER=file APP_ENV=local \
      "$@" php artisan tinker "tools/$script" >/dev/null 2>&1 </dev/null )
}
set_state() { preview_artisan card-set.php "$@"; }
set_wishlist() { preview_artisan pg2-wishlist.php "KBB_WISHLIST=$1"; }

# ── 1 · THE CARD AS THE PACKAGE LEAVES IT ───────────────────────────────────
# The wishlist ships OFF, so this is the card the owner will actually see: the
# button at the text column's full width and no heart.
set_wishlist 0

say "the shipped card — name, rating where there is one, price, button"
shot card-after-category "/collections/skincare-sets/" 320,390,1280
shot card-after-shop "/shop/" 320,390,1280

# ── 2 · THE PAIR THE OWNER'S SENTENCE IS ABOUT ──────────────────────────────
# `Toner` and an 85-character name in the same row at both widths, one with 96
# reviews and one with none.
say "Toner beside the 85-character name, and the 129-character one"
KBB_BASE=$BASE node "$APP/tools/card-names-shot.cjs" | tee -a "$LOG"

# ── 3 · THE HEART, WHICH IS ONE SWITCH AWAY ─────────────────────────────────
set_wishlist 1
say "the same card with Catalogue → Wishlist on"
shot card-after-wishlist "/collections/skincare-sets/" 390,1280
set_wishlist 0

# ── 4 · THE TWO CONTROLS, BOTH WAYS ─────────────────────────────────────────
say "Brand name ON and Category label ON — the before, and the proof both switches still work"
set_state KBB_SHOW_BRAND=1 KBB_SHOW_CAT=1
shot card-lines-on-category "/collections/skincare-sets/" 320,390,1280
shot card-lines-on-shop "/shop/" 320,390,1280

say "back to the shipped default"
set_state KBB_SHOW_BRAND=0 KBB_SHOW_CAT=0

# ── 5 · THE OTHER THREE TREATMENTS, which share this anatomy ────────────────
for skin in showcase-compact showcase-row showcase-airy; do
  say "$skin"
  set_state KBB_SKIN=$skin
  shot "card-$skin" "/collections/skincare-sets/" 390,1280
done

say "back to the shipped skin"
set_state KBB_SKIN=showcase

# ── 6 · EVERY PAGE THAT HAS A GRID, AND THE TWO THAT MUST NOT ───────────────
say "the walk"
shot card-after-home "/" 390,1280 full
shot card-after-brand "/brands/medicube/" 390,1280
shot card-after-related "/product/card-toner/" 390,1280
shot card-after-search "/shop/?s=cream" 390,1280
shot card-after-cart "/cart" 390,1280

# ── 7 · ARABIC, AND IT IS LAST FOR A REASON ─────────────────────────────────
#
# Turning Arabic on is not invisible to the ENGLISH pages: a shop with Arabic
# enabled renders a language switcher in its header that a shop without it does
# not, so every English panel above has to be taken first.
#
# What the mirrored shot exists to show is that the family is laid out on the
# LOGICAL axis and carries no `[dir]` selector: the grid reads right to left,
# the name and the rating row are right-aligned, the struck original sits at the
# row's start, and the card measures the same. English is re-shot afterwards
# from the same server as the control.
say "Arabic, and the English control taken from the same server afterwards"
preview_artisan card-arabic-on.php
shot card-ar-category "/ar/collections/skincare-sets/" 390,1280
shot card-en-after-ar "/collections/skincare-sets/" 390,1280

echo ""
echo "shots in $APP/docs/card-shots, numbers in $LOG"
