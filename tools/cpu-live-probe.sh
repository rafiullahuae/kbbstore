#!/bin/sh
# Lane CPU -- READ-ONLY probe to run ON THE LIVE SERVER over SSH, from the app
# root, to pin where an English page and its Arabic twin spend server time:
#
#   cd <app root> && KBB_CPU_HOST=https://<the shop domain, as APP_URL> sh tools/cpu-live-probe.sh / /ar/ /super-sale/ /ar/super-sale/
#
# Each request is rendered in a FRESH php process (as PHP-FPM does: statics
# empty, the shop's own file cache shared), 8 times per address, through
# tools/cpu-front.php with KBB_CPU_PROFILE=1: wall ms, CPU ms, queries,
# translation queries, cache hits/misses by key, Blade time per view and
# translator calls. The first run of each is reported separately (cold).
# Writes only: storage/cpu-logs/ (its own output), one sessions row per run
# (garbage-collected like any visitor's), and cache entries the page itself
# would have written anyway. Changes no setting, no file of the shop.
# opcache: the CLI normally has it off, which would charge every run for
# compiling the framework; opcache.file_cache under storage/cpu-logs gives the
# second run onward the compiled scripts, which is what PHP-FPM has.
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
OUT=$APP/storage/cpu-logs
mkdir -p "$OUT/opcache"
LOG=$OUT/probe.jsonl
: > "$LOG"
for u in "$@"; do
  i=0
  while [ $i -le 8 ]; do
    KBB_CPU_APP="$APP" KBB_CPU_LOG="$LOG" KBB_CPU_PROFILE=1 REQUEST_URI="$u" \
      php -d opcache.enable_cli=1 -d opcache.file_cache="$OUT/opcache" -d opcache.file_cache_only=0 \
        -r '$_SERVER["REQUEST_URI"]=getenv("REQUEST_URI"); $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["HTTP_HOST"]=parse_url((string) (getenv("KBB_CPU_HOST") ?: "http://localhost"), PHP_URL_HOST); $_SERVER["HTTPS"]="on"; $_SERVER["HTTP_USER_AGENT"]="Mozilla/5.0 (KBB cpu probe) Chrome/141.0"; $_SERVER["DOCUMENT_ROOT"]=sys_get_temp_dir()."/kbb-no-docroot"; ob_start(); require getenv("KBB_CPU_APP")."/tools/cpu-front.php"; ob_end_clean();'
    i=$((i + 1))
  done
done
php "$APP/tools/cpu-summary.php" "$LOG" "$OUT/probe-summary.json"
if [ $# -ge 2 ]; then php "$APP/tools/cpu-prof-diff.php" "$LOG" "$1" "$2" "$OUT/probe-profile.json"; fi
