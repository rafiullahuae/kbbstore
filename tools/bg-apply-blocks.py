#!/usr/bin/env python3
"""
Apply the four integrator edits to a SCRATCH tree.                  (Lane BG)

    python3 tools/bg-apply-blocks.py
    ...run the guards, take the screenshots...
    git checkout -- resources/views/admin/app.blade.php routes/web.php

── WHAT THIS IS FOR, AND WHAT IT IS NOT ────────────────────────────────────

It is NOT part of the package and it is not how the edits ship.
docs/BG-ADMIN-APP-BLOCKS.md is the record the integrator applies, by hand, with
its anchors and replacements quoted in full.

This exists because a lane that cannot edit resources/views/admin/app.blade.php
or routes/web.php still owes an answer to "and does it go green afterwards?".
Three assertions in PageWashScreenTest are RED until those four edits land --
deliberately, because CLAUDE.md forbids pinning the absence instead -- and the
only honest way to say they turn green is to apply the edits, run the suite, and
put the file back. Lane FC did exactly this for T1b and said so.

It asserts each anchor occurs EXACTLY ONCE before it touches anything, so a
console another lane has since edited stops it rather than silently producing a
half-applied file.

▲ IT EDITS TWO FILES THIS LANE MAY NOT SHIP. Revert them the moment you are
done -- `git checkout --` on both -- and check `git status` before committing.
"""
import sys, re

app = 'resources/views/admin/app.blade.php'
web = 'routes/web.php'

s = open(app).read()

# Block 1 - TITLES
a1 = "'sitelayout':['Appearance','Site layout'],"
assert s.count(a1) == 1, ('block 1 anchor', s.count(a1))
s = s.replace(a1, a1 + "'pagewash':['Appearance','Page background'],", 1)

# Block 2 - LATE_RENDERED
a2 = "'sitelayout','slimfooter']);"
assert s.count(a2) == 1, ('block 2 anchor', s.count(a2))
s = s.replace(a2, "'sitelayout','slimfooter','pagewash']);", 1)

# Block 3 - the include
a3 = "@include('admin.partials.set-appearance-screen')"
assert s.count(a3) == 1, ('block 3 anchor', s.count(a3))
s = s.replace(a3, a3 + "\n\n@include('admin.partials.page-wash-screen')", 1)

open(app, 'w').write(s)

# Block 4 - the route require, beside the site-layout one
w = open(web).read()
a4 = "require __DIR__.'/site-layout-admin.php';"
assert w.count(a4) == 1, ('block 4 anchor', w.count(a4))
w = w.replace(a4, a4 + "\n        require __DIR__.'/page-wash-admin.php';", 1)
open(web, 'w').write(w)

print('four edits applied')
