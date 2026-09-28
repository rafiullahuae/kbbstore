#!/bin/sh
# Lane UG2 — the media the "his shop" rail is seeded from.
#
# TWO REAL CLIPS AND NOTHING ELSE. The four PLACEHOLDER tiles do not get files
# here: they reuse App\Support\UgcDemoMedia's own `demo-clip.webm` /
# `demo-poster.jpg`, materialised by tools/ug2-seed.php, so the reproduction
# carries the OWNER'S ACTUAL BYTES — the 270x480 plum gradient
# DemoContentController::seedVideos() writes — and not a lane's idea of one.
#
# VP9 IN WEBM, because Playwright's bundled Chromium is the open-source build
# and has no H.264 (CLAUDE.md). An mp4 here reports MEDIA_ERR_SRC_NOT_SUPPORTED
# and invents a bug the owner does not have.
#
# The two clips MOVE VISIBLY and differently from each other (a sweeping bar,
# a counting square), because the whole question this round asks is "is this
# tile advancing", and a still frame of a gradient cannot answer it.
set -e
OUT=${1:?usage: ug2-media.sh <dir>}
mkdir -p "$OUT"

mk() { # name, filtergraph
  ffmpeg -y -v error -f lavfi -i "$2" -t 6 -r 25 \
    -c:v libvpx-vp9 -b:v 0 -crf 40 -pix_fmt yuv420p -an "$OUT/$1.webm"
  ffmpeg -y -v error -f lavfi -i "$2" -frames:v 1 "$OUT/$1.jpg"
}

# A bright clip with a bar sweeping left to right — one glance says "moving".
mk real-a "color=c=0xE8D8C8:s=270x480:r=25,drawbox=x='mod(t*120,270)':y=0:w=26:h=480:color=0x8A2E4A:t=fill"
# A dark clip with a block stepping down the frame.
mk real-b "color=c=0x201822:s=270x480:r=25,drawbox=x=40:y='mod(t*150,480)':w=190:h=40:color=0xF0C040:t=fill"

ls -l "$OUT"
