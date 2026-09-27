#!/usr/bin/env bash
# Instagram Profile — take every picture rule 2 asks for. Lane IG, Phase 21.
#
# Drives tools/ig-shots.cjs, writing the REAL module setting between shots rather
# than reading a layout out of a query string (see that file's header for why).
#
# The whole preview, from nothing:
#
#   cp .env .env.igpreview          # then set, in .env.igpreview:
#                                   #   APP_ENV=local, SESSION_DRIVER=file,
#                                   #   CACHE_STORE=file, and DB_DATABASE to an
#                                   #   absolute path for a fresh sqlite file.
#                                   # SESSION_DRIVER=array is the default here and
#                                   # it makes the admin login answer 419 Page
#                                   # Expired forever, which reads like a CSRF bug.
#   php artisan migrate --force --env=igpreview
#   php artisan db:seed  --force --env=igpreview
#   KBB_PUBLIC_PATH=$PWD/public php artisan tinker --env=igpreview \
#     --execute="require 'tools/ig-seed-preview.php';"
#   KBB_PUBLIC_PATH=$PWD/public APP_ENV=igpreview \
#     php -S 127.0.0.1:8951 -t public tools/ig-preview-router.php &
#   bash tools/ig-shots.sh docs/ig-profile-shots
#
# KBB_PUBLIC_PATH is not optional: without it usePublicPath() names a directory on
# the live server and the seeded pictures are written somewhere that does not exist.
#
# ── ROUND 2: THE PREVIEW AND THE POPUP ──────────────────────────────────────
#
# Two modes this loop deliberately does NOT drive, because each needs the shop put
# into a particular state first and a shell loop that writes credentials is a shell
# loop somebody runs against the wrong database:
#
#   # before connecting -- an app registered, nothing authorised, nothing fetched
#   KBB_PUBLIC_PATH=$PWD/public php artisan tinker --env=igpreview --execute='
#     \App\Models\InstagramPost::query()->delete();
#     app(\App\Services\InstagramSettings::class)->forgetProfile();
#     \App\Services\Instagram\InstagramCredentials::forgetToken();
#     \App\Services\Instagram\InstagramCredentials::saveApp("1234567890123456",
#       "abcdef0123456789abcdef0123456789");
#     \App\Services\SettingsService::forgetMemo(); \App\Models\Setting::flushMap();
#     \Illuminate\Support\Facades\Cache::flush();'
#   node tools/ig-shots.cjs preview docs/ig-profile-shots fresh
#
#   # after connecting -- re-seed, then store a 60-day token
#   KBB_PUBLIC_PATH=$PWD/public php artisan tinker --env=igpreview \
#     --execute="require 'tools/ig-seed-preview.php';"
#   KBB_PUBLIC_PATH=$PWD/public php artisan tinker --env=igpreview --execute='
#     \App\Services\Instagram\InstagramCredentials::saveApp("1234567890123456",
#       "abcdef0123456789abcdef0123456789");
#     \App\Services\Instagram\InstagramCredentials::saveToken("a-very-long-lived-token",
#       60*86400, "17841400000000000");
#     \App\Services\SettingsService::forgetMemo(); \App\Models\Setting::flushMap();
#     \Illuminate\Support\Facades\Cache::flush();'
#   node tools/ig-shots.cjs preview docs/ig-profile-shots connected
#   node tools/ig-shots.cjs popup   docs/ig-profile-shots
#
# NODE_PATH may need to point at a checkout that has playwright installed; this
# worktree does not carry its own node_modules.
#
# Expects the preview server already up on 127.0.0.1:8951.
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="${1:-docs/ig-profile-shots}"
mkdir -p "$OUT"

set_setting () {
  KBB_PUBLIC_PATH="$PWD/public" php artisan tinker --env=igpreview --execute="
    app(\App\Services\SettingsService::class)->setModuleSetting('instagram_profile', '$1', '$2');
    \App\Services\SettingsService::forgetMemo();
    \App\Services\InstagramFeed::flush();
    \Illuminate\Support\Facades\Cache::flush();
  " >/dev/null 2>&1
}

for layout in grid rail mosaic masonry strip; do
  echo "== layout $layout"
  set_setting layout "$layout"
  node tools/ig-shots.cjs shop "$OUT" "layout-$layout" >/dev/null
done

set_setting layout grid

for style in card bar inline off; do
  echo "== profile $style"
  set_setting profile_style "$style"
  node tools/ig-shots.cjs shop "$OUT" "profile-$style" >/dev/null
done

set_setting profile_style card

echo "== the homepage, in place"
node tools/ig-shots.cjs page "$OUT" >/dev/null

echo "== the lightbox (tap = embed)"
set_setting tap embed
node tools/ig-shots.cjs tap "$OUT" >/dev/null
set_setting tap permalink

echo "== the caption overlay on"
set_setting caption 1
node tools/ig-shots.cjs shop "$OUT" "caption-on" >/dev/null
set_setting caption 0

echo "== Content -> Instagram"
node tools/ig-shots.cjs admin "$OUT" >/dev/null

node -e '
const fs=require("fs");
const dir=process.argv[1];
const out={};
for (const f of fs.readdirSync(dir).filter(f=>f.startsWith("part-"))) {
  Object.assign(out, JSON.parse(fs.readFileSync(dir+"/"+f)));
  fs.unlinkSync(dir+"/"+f);
}
fs.writeFileSync(dir+"/ig-measurements.json", JSON.stringify(out,null,1));
console.log(Object.keys(out).length+" measurement sets");
' "$OUT"
