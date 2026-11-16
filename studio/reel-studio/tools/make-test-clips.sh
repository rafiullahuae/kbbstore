#!/bin/bash
# Test clips for the check scripts (into tools/out/, never committed):
#   portrait.mp4  20 s 1080x1920 30 fps, moving test pattern + a frame counter, 440 Hz tone (AAC)
#   wide.mp4       8 s 1920x1080 30 fps, different pattern, 660 Hz tone
#   photo.png      a 1200x800 still
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p out
[ -f out/portrait.mp4 ] || ffmpeg -loglevel error -y -f lavfi -i "testsrc2=size=1080x1920:rate=30:duration=20" -f lavfi -i "sine=frequency=440:duration=20:sample_rate=48000" \
  -vf "drawtext=text='%{n}':fontsize=120:fontcolor=white:x=60:y=60:box=1:boxcolor=black@0.6" \
  -c:v libx264 -preset veryfast -crf 30 -pix_fmt yuv420p -g 60 -c:a aac -b:a 96k -shortest -movflags +faststart out/portrait.mp4
[ -f out/wide.mp4 ] || ffmpeg -loglevel error -y -f lavfi -i "smptehdbars=size=1920x1080:rate=30:duration=8" -f lavfi -i "sine=frequency=660:duration=8:sample_rate=48000" \
  -vf "drawtext=text='WIDE %{n}':fontsize=90:fontcolor=yellow:x=(w-tw)/2:y=h-160" \
  -c:v libx264 -preset veryfast -crf 30 -pix_fmt yuv420p -g 60 -c:a aac -b:a 96k -shortest -movflags +faststart out/wide.mp4
[ -f out/photo.png ] || ffmpeg -loglevel error -y -f lavfi -i "mandelbrot=size=1200x800" -frames:v 1 out/photo.png
ls -la out/portrait.mp4 out/wide.mp4 out/photo.png
