#!/bin/sh
# Lane OHB: every state the brief asks for, shot and measured against the
# running preview (sh tools/ohb-preview.sh first).  sh tools/ohb-shoot-all.sh <port>
APP=$(cd "$(dirname "$0")/.." && pwd)
B=http://127.0.0.1:${1:-11900}
LOG=$APP/storage/ohb-logs/shots.jsonl
: > "$LOG"
cd "$APP" || exit 1
run() { # $1 json state, $2 label, $3 path, $4 sizes
  OHB_SET="$1" OHB_PATHS="$3" sh tools/ohb-run.sh "${PORT:-11900}" | tail -n +2 >> "$APP/storage/ohb-logs/measure.txt"
  OHB_CLICK=1 node tools/ohb-shots.cjs "$B" "$2" "$3" "$4" >> "$LOG"
}
: > "$APP/storage/ohb-logs/measure.txt"
run 'none' off-slider-only / 390x844,1280x800
run 'restore' restored / 390x844
run '{"display":"slider"}' display-slider / 390x844,1280x800
run '{"display":"both","layout":"G","template":"1111"}' both-G-1111 / 390x844,844x390,768x1024,1024x768,1280x800
run '{"display":"banner","layout":"G","template":"1111"}' banner-G-1111 / 390x844,844x390,1280x800
run '{"display":"banner","layout":"H","template":"uae"}' banner-H-uae / 390x844,844x390,768x1024,1024x768,1280x800
run '{"display":"banner","layout":"I","template":"ny"}' banner-I-ny / 390x844,844x390,768x1024,1024x768,1280x800
run '{"display":"both","layout":"I","template":"super"}' both-I-super / 390x844,844x390,1280x800
run '{"display":"both","layout":"H","template":"uae"}' ar-both-H-uae /ar/ 390x844,844x390,1280x800
run '{"display":"banner","layout":"G","template":"1111","animate":false}' banner-G-still / 1280x800
run '{"display":"both","layout":"G","template":"1111","animate":true}' final-both-G-1111 / 1280x800
echo done
