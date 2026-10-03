#!/bin/sh
# Lane RK: render the order confirmation from the preview shop (after) and copy
# the pre-package render of the same fixture (before), for the footer shots.
#   sh tools/rk-render.sh
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
. "$APP/storage/framework/testing/lane-rk-preview/env.sh"
cd "$APP"
php artisan tinker --execute="require 'tools/rk-render-footer.php';"
mkdir -p docs/rk-emails
{ printf '<!doctype html><meta charset="utf-8"><body style="margin:0">'; git show 2b11208:docs/email-previews/order-confirmation.html; } > docs/rk-emails/footer-before.html
echo "footer-before.html written"
