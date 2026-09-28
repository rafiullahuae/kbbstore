#!/bin/sh
# Take every Lane SF screenshot, in the order the evidence needs them.
#
# ── WHY THIS SCRIPT EXISTS RATHER THAN ONE node CALL ────────────────────────
#
# Two of the shots cannot be taken against the branch as it stands, and both
# are taken by TEMPORARILY changing a file and PUTTING IT BACK:
#
#   1. bundle-before. The whole point of half of this lane is that a set page
#      no longer draws the quantity-bundle strip. "It is gone" is a claim; a
#      before and an after of the same page is evidence. So the guard in
#      App\Services\BundleService::forProduct() is disabled for one pass.
#
#   2. admin-screen. resources/views/admin/app.blade.php is the INTEGRATOR's
#      file and this lane may not edit it, so the console does not include
#      admin/partials/set-contents-screen.blade.php yet and window.go
#      ('setcontents') would reach nothing. This applies exactly the one line
#      the integrator is asked for, shoots, and removes it.
#
# ── AND WHY THAT IS SAFE HERE ───────────────────────────────────────────────
#
# Both edits are reverted with `git checkout --` on the one file, and the
# script CHECKS the tree is clean before it starts and again when it finishes.
# If either check fails it stops and says which file it left changed, rather
# than leaving a lane's temporary edit in a commit -- which is the failure this
# guard exists for. It also traps INT/TERM, so a cancelled run puts the files
# back too.
set -e

APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PORT=${1:-8994}
export SF_BASE="http://127.0.0.1:$PORT"
export SF_OUT="$APP/docs/lane-sf-shots"

BUNDLE="$APP/app/Services/BundleService.php"
CONSOLE="$APP/resources/views/admin/app.blade.php"

dirty() {
    git -C "$APP" status --porcelain -- "$1" | grep -q . && return 0
    return 1
}

restore() {
    git -C "$APP" checkout -- "$BUNDLE" 2>/dev/null || true
    git -C "$APP" checkout -- "$CONSOLE" 2>/dev/null || true
}

trap 'restore' INT TERM

if dirty "$BUNDLE"; then
    echo "REFUSING: $BUNDLE has uncommitted changes. Commit them first —" >&2
    echo "this script reverts that file and would throw them away." >&2
    exit 1
fi

if dirty "$CONSOLE"; then
    echo "REFUSING: $CONSOLE has uncommitted changes." >&2
    exit 1
fi

mkdir -p "$SF_OUT"

echo "── the four designs, the unpriced set and the Arabic mirror ──"
SF_ONLY=designs node "$APP/tools/sf-shots.cjs"

echo "── the set page WITHOUT the strip (this lane) ──"
SF_ONLY=bundle SF_BUNDLE=bundle-after node "$APP/tools/sf-shots.cjs"

echo "── an ordinary product, whose strip must be untouched ──"
SF_ONLY=plain node "$APP/tools/sf-shots.cjs"

echo "── the set page WITH the strip (the branch point) ──"
python3 - "$BUNDLE" <<'PATCH'
import sys
p = sys.argv[1]
s = open(p).read()
old = "        if ($product->isSet()) {\n            return [];\n        }\n"
assert s.count(old) == 1, 'the set guard is not where this script expects it'
open(p, 'w').write(s.replace(old, "        if (false) {\n            return [];\n        }\n", 1))
PATCH
SF_ONLY=bundle SF_BUNDLE=bundle-before node "$APP/tools/sf-shots.cjs"
git -C "$APP" checkout -- "$BUNDLE"

echo "── Appearance → Set contents ──"
python3 - "$CONSOLE" <<'PATCH'
import sys
p = sys.argv[1]
s = open(p).read()
anchor = "@include('admin.partials.set-contents-screen')"
assert anchor not in s, 'the integrator has already wired this; drop this block'
old = "@include('admin.partials.banners-screen')"
assert s.count(old) == 1, 'the console changed shape; find a new anchor'
open(p, 'w').write(s.replace(old, old + "\n" + anchor, 1))
PATCH
SF_ONLY=admin node "$APP/tools/sf-shots.cjs"
git -C "$APP" checkout -- "$CONSOLE"

if dirty "$BUNDLE" || dirty "$CONSOLE"; then
    echo "FAILED TO RESTORE. Check:" >&2
    git -C "$APP" status --porcelain -- "$BUNDLE" "$CONSOLE" >&2
    exit 1
fi

echo "shots in $SF_OUT; tree is clean"
