#!/bin/sh
# Lane MK -- a shadow of the application with the integrator's wiring applied.
#
#   sh tools/mk-shadow.sh <shadow dir>
#
# Every top-level entry of this worktree is SYMLINKED into <shadow dir> except
# routes/, resources/, bootstrap/ and storage/: routes/web.php and
# resources/views/admin/app.blade.php are COPIES with tools/mk-wire.py applied,
# bootstrap/ is copied (its app.php derives the base path from where it sits),
# and storage/ is the shadow's own. The worktree's tracked files are never
# touched -- the lane may not edit those two files, and this is how its preview
# and its wired suite run show the shop as it will be once the integrator has.
set -e
APP=$(cd "$(dirname "$0")/.." && pwd)
SH=$1
[ -n "$SH" ] || { echo "usage: mk-shadow.sh <dir>" >&2; exit 2; }
rm -rf "$SH"; mkdir -p "$SH"
for e in "$APP"/* "$APP"/.env "$APP"/.env.example; do
  [ -e "$e" ] || continue
  n=$(basename "$e")
  case "$n" in routes|resources|bootstrap|storage) continue;; esac
  ln -s "$e" "$SH/$n"
done
mkdir -p "$SH/routes" && for f in "$APP"/routes/*; do [ "$(basename "$f")" = web.php ] || ln -s "$f" "$SH/routes/"; done
cp "$APP/routes/web.php" "$SH/routes/web.php"
mkdir -p "$SH/resources" && for f in "$APP"/resources/*; do [ "$(basename "$f")" = views ] || ln -s "$f" "$SH/resources/"; done
mkdir -p "$SH/resources/views" && for f in "$APP"/resources/views/*; do [ "$(basename "$f")" = admin ] || ln -s "$f" "$SH/resources/views/"; done
mkdir -p "$SH/resources/views/admin" && for f in "$APP"/resources/views/admin/*; do [ "$(basename "$f")" = app.blade.php ] || ln -s "$f" "$SH/resources/views/admin/"; done
cp "$APP/resources/views/admin/app.blade.php" "$SH/resources/views/admin/app.blade.php"
mkdir -p "$SH/bootstrap/cache" && cp "$APP/bootstrap/app.php" "$APP/bootstrap/providers.php" "$SH/bootstrap/"
mkdir -p "$SH/storage/app/public" "$SH/storage/framework/cache/data" "$SH/storage/framework/sessions" "$SH/storage/framework/views" "$SH/storage/framework/testing" "$SH/storage/logs"
python3 "$APP/tools/mk-wire.py" "$SH/routes/web.php" "$SH/resources/views/admin/app.blade.php"
