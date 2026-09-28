#!/bin/sh
# Take the Lane SP screenshots.
#
# ── WHY THIS WRAPS THE SHOT SCRIPT AT ALL ───────────────────────────────────
#
# Two of the shots the coordinator asked for -- Catalog → Products showing the
# Sets chip and the Set pill -- need the INTEGRATOR's two-line change to
# resources/views/admin/app.blade.php, which this lane may not make. The server
# half is done and tested (SetCatalogChipTest); what is missing is one entry in
# CP_DERIVED_CHIPS and one pill in cpTable().
#
# The alternative to this script is a report that says "the chip works, take my
# word for it", which is exactly what CLAUDE.md's rule 2 exists to refuse. So:
# this applies EXACTLY those two lines to the working copy, runs the shots, and
# puts the file back -- with a trap, so an interrupted run restores it too, and
# with a `git diff --quiet` afterwards that FAILS LOUDLY if it did not.
#
# The file is left byte-identical. `git status` is the check and this script
# runs it for you.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
SHELL_FILE=$APP/resources/views/admin/app.blade.php
BACKUP=$APP/storage/framework/testing/lane-sp-preview/app.blade.php.orig
PORT=${1:-8991}

mkdir -p "$(dirname "$BACKUP")"
cp "$SHELL_FILE" "$BACKUP"

restore() {
  cp "$BACKUP" "$SHELL_FILE"
}
trap restore EXIT INT TERM

python3 - "$SHELL_FILE" <<'PATCH'
import sys
p = sys.argv[1]
s = open(p).read()

# 1. The Sets chip, one entry in CP_DERIVED_CHIPS.
old = "    ['featured', 'Featured'], ['trashed', 'Trash']"
new = "    ['featured', 'Featured'], ['set', 'Sets'], ['trashed', 'Trash']"
assert s.count(old) == 1, 'CP_DERIVED_CHIPS shape changed'
s = s.replace(old, new, 1)

# 2. The Set pill, in cpTable()'s product cell.
old2 = ("            '<div class=\"pbrand\" style=\"font-size:11px;color:var(--ink-soft)\">' +\n"
        "              (p.is_visible ? '' : '<span class=\"pill grey\" style=\"font-size:9px;padding:1px 6px\">Hidden</span> ') +")
new2 = ("            '<div class=\"pbrand\" style=\"font-size:11px;color:var(--ink-soft)\">' +\n"
        "              (p.is_set ? '<span class=\"pill blue\" style=\"font-size:9px;padding:1px 6px\">Set</span> ' : '') +\n"
        "              (p.is_visible ? '' : '<span class=\"pill grey\" style=\"font-size:9px;padding:1px 6px\">Hidden</span> ') +")
assert s.count(old2) == 1, 'cpTable product cell shape changed'
s = s.replace(old2, new2, 1)

open(p, 'w').write(s)
print('applied the integrator two-line diff to the working copy')
PATCH

SP_BASE="http://127.0.0.1:$PORT" node "$APP/tools/sp-shots.cjs"

restore
trap - EXIT INT TERM

cd "$APP"
git diff --quiet -- resources/views/admin/app.blade.php \
  && echo "app.blade.php restored byte-identical" \
  || { echo "REFUSING TO FINISH: app.blade.php was NOT restored"; exit 1; }
