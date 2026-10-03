#!/bin/sh
# Render the "before" emails against the running rj preview's database.
# Requires tools/rj-preview.sh to have run first (it writes env.sh).
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
. "$APP/storage/framework/testing/lane-rj-preview/env.sh"
cd "$APP"
php artisan tinker --execute="require '$APP/tools/rj-render-emails.php';" </dev/null
