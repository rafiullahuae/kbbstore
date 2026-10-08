#!/bin/sh
# Lane HB: tools/hb-speed.php inside a running tools/hb-preview.sh copy, OPcache on.
#   sh tools/hb-speed.sh after|before
set -e
HERE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$HERE/storage/framework/testing/lane-hb-${1:-after}
. "$DIR/env.sh"
export APP_DEBUG=false
php -d opcache.enable_cli=1 -d opcache.jit=off "$HB_APP_DIR/artisan" tinker --execute="require '$HERE/tools/hb-speed.php';"
