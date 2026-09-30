#!/bin/sh
# BLANK THE THING A TEST CLAIMS TO CHECK, AND SEE IF THE TEST NOTICES.
#                                                          (Lane PLC)
#
#     ./tools/plc-needle-mutate.sh <file> <sed-expression> <test...>
#
# The needle survey says an assertion's needle occurs more than once on the page
# it ran against, and in more than one element. That is a SCREEN, not a verdict:
# it says the assertion COULD be answered by something other than its subject,
# not that it is. The verdict is this — blank the subject and re-run. A test
# that still passes was never watching it.
#
# The edit is applied, the tests are run, and the file is restored whether the
# run passed, failed or died, because a lane that leaves a blanked <h1> in the
# tree ships it.
#
# EVERY PATH IS THIS WORKTREE'S. Three lanes run at once and the shared session
# scratchpad is how one lane comes to report another's numbers (CLAUDE.md), so
# the backup lives beside the file it came from, under a name carrying this
# lane's own prefix and this process's pid.
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
FILE=$1
EXPR=$2
shift 2

[ -f "$APP/$FILE" ] || { echo "no such file: $APP/$FILE" >&2; exit 2; }

BAK="$APP/$FILE.plc-mutate-$$.bak"
cp "$APP/$FILE" "$BAK"

# Restored on any exit, including an interrupt. Never `pkill` anything here —
# three lanes run at once (CLAUDE.md).
restore() {
  cp "$BAK" "$APP/$FILE"
  rm -f "$BAK"
}
trap restore EXIT INT TERM

python3 - "$APP/$FILE" "$EXPR" <<'KBBMUTPY'
import re, sys

path, expr = sys.argv[1], sys.argv[2]
src = open(path, encoding='utf-8').read()
pattern, repl = expr.split('=>', 1)
out, n = re.subn(pattern, repl, src)

if n == 0:
    raise SystemExit('MUTATION MATCHED NOTHING: %r in %s — a mutation that does not\n'
                     'change the file proves nothing, and a green run after it is\n'
                     'the false green this tool exists to find.' % (pattern, path))

open(path, 'w', encoding='utf-8').write(out)
print('mutated %s (%d site%s)' % (path, n, '' if n == 1 else 's'))
KBBMUTPY

# The compiled Blade cache keys off the source file's mtime, but a view
# compiled a second ago and rewritten in the same second can keep its cache
# entry. Clearing is cheaper than debugging a mutation that did not take.
rm -rf "$APP/storage/framework/views"/*.php 2>/dev/null || true

cd "$APP"
KBB_WP_DB=${KBB_WP_DB:-kbb_wp_$(basename "$APP" | sed 's/^lane-//')} \
  vendor/bin/pest --compact "$@" 2>&1 | tail -20
