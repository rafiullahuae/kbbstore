#!/bin/sh
# Take every Lane SF screenshot, in the order the evidence needs them.
#
# ── WHY THIS SCRIPT EXISTS RATHER THAN ONE node CALL ────────────────────────
#
# The BEFORE shot cannot be taken against the branch as it stands, and this is
# the half of the evidence that actually shows what changed. The owner marked
# up a screenshot of the page as it WAS -- the bundle strip struck through, an
# arrow from the contents grid up into its place -- so "the strip is gone and
# the list is there instead" has to be a pair of pictures of one page, not a
# picture and a claim.
#
# So this backs out exactly the two edits that make the change, shoots, and
# puts them back:
#
#   1. the `if ($product->isSet())` guard in BundleService::forProduct(), so
#      the strip renders again;
#   2. the @include in store/product.blade.php moves back out of the buy
#      column to where it stood, so the contents draw as a section near the
#      foot. The PANEL itself is not reverted -- it would mean restoring a
#      deleted file -- so the "before" shot shows the old PLACE with the new
#      list in it. That is the honest limit of the reconstruction and it is
#      recorded here rather than left for a reader to work out: what the pair
#      proves is the STRIP and the PLACE, which is what the arrow was about.
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
PRODUCT="$APP/resources/views/store/product.blade.php"

dirty() {
    git -C "$APP" status --porcelain -- "$1" | grep -q . && return 0
    return 1
}

restore() {
    git -C "$APP" checkout -- "$BUNDLE" 2>/dev/null || true
    git -C "$APP" checkout -- "$PRODUCT" 2>/dev/null || true
}

trap 'restore' INT TERM

for f in "$BUNDLE" "$PRODUCT"; do
    if dirty "$f"; then
        echo "REFUSING: $f has uncommitted changes. Commit them first --" >&2
        echo "this script reverts that file and would throw them away." >&2
        exit 1
    fi
done

mkdir -p "$SF_OUT"

echo "── AFTER: the list in the buy column, the strip gone ──"
SF_TAG=after node "$APP/tools/sf-shots.cjs"

echo "── BEFORE: the strip back, the contents back near the foot ──"
python3 - "$BUNDLE" "$PRODUCT" <<'PATCH'
import sys
bundle, product = sys.argv[1], sys.argv[2]

s = open(bundle).read()
old = "        if ($product->isSet()) {\n            return [];\n        }\n"
assert s.count(old) == 1, 'the set guard is not where this script expects it'
open(bundle, 'w').write(s.replace(old, "        if (false) {\n            return [];\n        }\n", 1))

t = open(product).read()
inc = "        @include('partials.set-contents-panel')\n"
assert t.count(inc) == 1, 'the buy-column include is not where this script expects it'
t = t.replace(inc, '', 1)
anchor = "  @unless ($modules->hidden('fbt'))"
assert t.count(anchor) == 1, 'the old section anchor moved'
open(product, 'w').write(t.replace(anchor, "@include('partials.set-contents-panel')\n" + anchor, 1))
PATCH
SF_TAG=before node "$APP/tools/sf-shots.cjs"
restore

for f in "$BUNDLE" "$PRODUCT"; do
    if dirty "$f"; then
        echo "FAILED TO RESTORE. Check:" >&2
        git -C "$APP" status --porcelain -- "$f" >&2
        exit 1
    fi
done

echo "shots in $SF_OUT; tree is clean"
