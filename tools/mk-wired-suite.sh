#!/bin/sh
# Lane MK -- run the suite against a WIRED SHADOW of the application: the
# integrator's two lines applied to COPIES of routes/web.php and
# resources/views/admin/app.blade.php (tools/mk-shadow.sh), nothing in the
# worktree touched. tests/ is copied and vendor/ hard-linked (cp -al: no bytes
# copied, nothing edited) INTO the shadow, because Pest takes the project root
# from where vendor/ really is and the suite's bootstrap from where tests/ is.
#
#   sh tools/mk-wired-suite.sh [pest args...]
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
SH=$APP/storage/framework/testing/mk-wired
sh "$APP/tools/mk-shadow.sh" "$SH" >/dev/null
rm "$SH/vendor" "$SH/tests" "$SH/phpunit.xml"
cp "$APP/phpunit.xml" "$SH/phpunit.xml"
cp -al "$APP/vendor" "$SH/vendor"
cp -r "$APP/tests" "$SH/tests"
# resources/ and routes/ as real directories too: a recursive directory walk
# does not descend into a symlinked directory, and two tests walk them.
rm -rf "$SH/resources" "$SH/routes" "$SH/app" "$SH/database" "$SH/config"
cp -r "$APP/resources" "$APP/routes" "$APP/app" "$APP/database" "$APP/config" "$SH/"
python3 "$APP/tools/mk-wire.py" "$SH/routes/web.php" "$SH/resources/views/admin/app.blade.php" >/dev/null
cd "$SH"
KBB_WP_DB=${KBB_WP_DB:-kbb_wp_mk} vendor/bin/pest "$@"
