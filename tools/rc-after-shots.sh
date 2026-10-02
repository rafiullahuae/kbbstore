#!/bin/sh
# Lane RC: every AFTER picture and measurement, against a fresh preview.
#
#   sh tools/rc-preview.sh 9960 after
#   sh tools/rc-after-shots.sh http://127.0.0.1:9960
#
# Writes docs/rc-shots/after-*.png and storage/rc-logs/after-measure.jsonl.
set -e
HERE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
BASE=${1:-http://127.0.0.1:9960}
DB=$HERE/storage/framework/testing/lane-rc-after/preview.sqlite
LOG=$HERE/storage/rc-logs/after-measure.jsonl
: > "$LOG"
shot() { RC_BASE=$BASE RC_TAG=$1 RC_WIDTHS=${2:-390,1280} node "$HERE/tools/rc-measure.cjs" >> "$LOG"; }
set_() { php "$HERE/tools/rc-set.php" "$DB" "$@" > /dev/null; }

# 1. the shipped slider: Auto shape, whole pictures, no cap -- the gap is gone
set_ kind=slider slider_h=0 slider_h_m=0 bg_mode=none
shot after-slider
# 2. the height control at two values
set_ slider_h=260 slider_h_m=360
shot after-slider-h260-m360
set_ slider_h=320 slider_h_m=420 bg_mode=color bg_color=#fbe7ee
shot after-slider-h320-m420-bg
# 3. the single image, with a phone picture, at 390 / 1280 / 1920
set_ kind=single slider_h=0 slider_h_m=0 bg_mode=none
shot after-single 390,1280,1920
# 4. no phone pictures: the phone draws the wide picture whole, both kinds
set_ cards.image_m=
shot after-single-nophone 390
set_ kind=slider
shot after-slider-nophone 390
echo "after shots written; measurements in $LOG"
