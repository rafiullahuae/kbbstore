#!/bin/sh
# Stop the Lane OHB preview (only the PID tools/ohb-preview.sh started) and take
# the two preview-only wiring lines back out of the worktree.
APP=$(cd "$(dirname "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/ohb-preview
[ -f "$DIR/server.pid" ] && kill "$(cat "$DIR/server.pid")" 2>/dev/null
sed -i "/^        require __DIR__.'\/occasion-banner-admin.php';$/d" "$APP/routes/web.php"
sed -i "/^@include('admin.partials.occasion-banner-screen')$/d" "$APP/resources/views/admin/app.blade.php"
echo stopped
