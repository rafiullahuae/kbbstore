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
# docs/ as a real directory of links, with COPIES of the two handover
# documents whose LATE_RENDERED line the wiring moves.
rm "$SH/docs" && mkdir "$SH/docs"
for f in "$APP"/docs/*; do ln -s "$f" "$SH/docs/"; done
for d in GS-ADMIN-APP-BLOCKS.md T1B-ADMIN-APP-BLOCKS.md; do rm "$SH/docs/$d"; cp "$APP/docs/$d" "$SH/docs/$d"; done
python3 "$APP/tools/mk-wire.py" "$SH/routes/web.php" "$SH/resources/views/admin/app.blade.php" "$SH/docs/GS-ADMIN-APP-BLOCKS.md" "$SH/docs/T1B-ADMIN-APP-BLOCKS.md" >/dev/null
# The dotfiles (.git, .gitignore …) and the checked-in catalogue snapshot:
# several tests read them through base_path().
for e in "$APP"/.[!.]*; do n=$(basename "$e"); [ -e "$SH/$n" ] || ln -s "$e" "$SH/$n"; done
[ -e "$APP/storage/catalog" ] && ln -s "$APP/storage/catalog" "$SH/storage/catalog"
cd "$SH"
KBB_WP_DB=${KBB_WP_DB:-kbb_wp_mk} vendor/bin/pest "$@"
