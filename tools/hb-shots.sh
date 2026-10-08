#!/bin/sh
# Lane HB: every scenario the report shows, against a running tools/hb-preview.sh.
#   sh tools/hb-shots.sh http://127.0.0.1:10460 [preview-name]
set -e
HERE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
BASE=${1:-http://127.0.0.1:10460}
DIR=$HERE/storage/framework/testing/lane-hb-${2:-after}
export NODE_PATH=${NODE_PATH:-/opt/node22/lib/node_modules}
run() { # tag box tb langs
  ( . "$DIR/env.sh" && HB_BOX="$2" HB_TB="$3" php "$HB_APP_DIR/artisan" tinker --execute="require '$HERE/tools/hb-set.php';" >/dev/null )
  HB_BASE=$BASE HB_TAG=$1 HB_LANGS=${4:-en} HB_CLICK=${HB_CLICK:-} node "$HERE/tools/hb-shots.cjs"
}
run off 0 '{}'
run a-default 1 '{}' en,ar
run d-default 1 '{"style":"d"}' en,ar
run a-glow-white 1 '{"glow":"white"}'
run d-glow-white 1 '{"style":"d","glow":"white"}'
run a-minimal 1 '{"show_eyebrow":false,"show_text":false}'
run d-minimal 1 '{"style":"d","show_eyebrow":false,"show_text":false,"show_sticker":false}'
run a-btn-outline 1 '{"button":"outline"}'
run a-btn-text 1 '{"button":"text"}'
run d-btn-soft 1 '{"style":"d","button":"soft"}'
run d-btn-grad 1 '{"style":"d","button":"grad"}'
run a-btn-under 1 '{"button":"under"}'
run a-max 1 '{"size_h_d":44,"size_t_d":18,"size_e_d":15,"size_b_d":130,"size_w_d":560,"size_h_m":36,"size_t_m":17,"size_e_m":14,"size_b_m":130,"size_w_m":100}'
run d-max 1 '{"style":"d","size_h_d":44,"size_t_d":18,"size_e_d":15,"size_b_d":130,"size_w_d":560,"size_h_m":36,"size_t_m":17,"size_e_m":14,"size_b_m":130,"size_w_m":100}'
run a-default 1 '{}' en >/dev/null
