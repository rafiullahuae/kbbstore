#!/bin/sh
# Lane UG3 — the media the one-second rail is measured against.
#
# FOUR THINGS, and each answers a question this round has to answer with a
# number or a picture:
#
#   real-a  a BRIGHT clip (cream, 0xE8D8C8) with a bar sweeping across it. The
#           loader has to read over a bright poster, and a white frosted disc on
#           cream is the hard case.
#   real-b  a DARK clip (0x201822) with a block stepping down it. The other hard
#           case, and the one where a white rim is easy.
#   long-a  the SAME bright clip, but eight seconds of it at a phone's bitrate.
#           This is the "no teaser file" tile, and what it costs to loop one
#           second of it is the number the owner is actually feeling.
#   teaser-1s-*  cut from long-a with the SHOP'S OWN argv (UgcTranscoder::
#           teaserCommand, transcribed), so the byte comparison is between the
#           two things the shop really serves and not between two approximations.
#
# VP9 IN WEBM for anything a tile PLAYS, because Playwright's bundled Chromium
# is the open-source build and has no H.264 (CLAUDE.md). The teaser is cut to
# WebM for the same reason, with the shop's numbers otherwise unchanged --
# 360x640, ~400 kbps, no audio, one second.
set -e
OUT=${1:?usage: ug3-media.sh <dir>}
mkdir -p "$OUT"

BRIGHT="color=c=0xE8D8C8:s=270x480:r=25,drawbox=x='mod(t*120,270)':y=0:w=26:h=480:color=0x8A2E4A:t=fill"
DARK="color=c=0x201822:s=270x480:r=25,drawbox=x=40:y='mod(t*150,480)':w=190:h=40:color=0xF0C040:t=fill"

mk() { # name, filtergraph, seconds
  ffmpeg -y -v error -f lavfi -i "$2" -t "$3" -r 25 \
    -c:v libvpx-vp9 -b:v 0 -crf 40 -pix_fmt yuv420p -an "$OUT/$1.webm"
  ffmpeg -y -v error -f lavfi -i "$2" -frames:v 1 "$OUT/$1.jpg"
}

mk real-a "$BRIGHT" 6
mk real-b "$DARK" 6

# The uncut clip, at a real phone's resolution and bitrate. 1080x1920 is what
# comes off a phone, and this is the file a tile with no teaser has to fetch in
# order to loop one second of.
# testsrc2 AND NOT A FLAT COLOUR. The first cut of this file drew a plum bar
# sweeping over cream, which is what the two small clips above are -- and VP9
# compressed eight seconds of it to 11 KB, which would have made the whole
# "a full clip is expensive" measurement a lie in this lane's favour. testsrc2
# has fine detail in every frame and lands at ~3.2 MB, which is the order the
# owner's own clips measured at (5,959 KB on a fast link, UG2).
ffmpeg -y -v error -f lavfi -i "testsrc2=s=1080x1920:r=30:d=8" \
  -c:v libvpx-vp9 -b:v 4000k -deadline realtime -cpu-used 8 -pix_fmt yuv420p -an "$OUT/long-a.webm"
ffmpeg -y -v error -f lavfi -i "testsrc2=s=1080x1920:r=30:d=8" -frames:v 1 "$OUT/long-a.jpg"

# THE SHOP'S OWN TEASER ARGV, one second, with the codec swapped for the one
# Playwright's Chromium can decode. Everything else is UgcTranscoder's.
cut() { # seconds, out
  ffmpeg -nostdin -hide_banner -loglevel error -y -ss 0 -i "$OUT/long-a.webm" -t "$1" -an \
    -vf 'scale=360:640:force_original_aspect_ratio=increase,crop=360:640' \
    -b:v 400k -deadline realtime -cpu-used 8 -c:v libvpx-vp9 -pix_fmt yuv420p "$OUT/$2"
}
cut 1   teaser-1s-20260929-000000-ug3aaaaaaa.webm
# ...and the same clip at the OLD length, kept only so the two can be weighed
# against each other on the same source. Nothing in the rail points at it.
cut 2.5 teaser-old-20260929-000000-ug3bbbbbbb.webm

ls -l "$OUT"
