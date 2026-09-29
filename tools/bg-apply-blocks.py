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

# The LATE_NAV row, character for character what the partial's own
# kbbAddNavEntry() call declares. AdminSidebarIsCompleteAtBuildTest compares the
# label, the group and the anchors both ways and fails naming the id if either
# copy drifts.
LATE_NAV_ROW = (
    "  {screen:'pagewash',label:'Page background',group:'Appearance',"
    "after:['dividers','prodstyles','homepage','layout'],"
    "icon:'<rect x=\"3\" y=\"4\" width=\"18\" height=\"16\" rx=\"2\"/>"
    "<path d=\"M3 14c4-3 7 1 10-1s5-2 8 0\"/>'},\n"
)

# The include and its comment, character for character what
# docs/BG-ADMIN-APP-BLOCKS.md block 5 records as the replacement.
INCLUDE_BLOCK = "\n\n{{-- Appearance -> Page background (Lane BG). The soft multi-colour wash the\n     owner asked for, and the live preview he asked to see first.\n\n     IT REGISTERS ITS OWN SIDEBAR ROW through kbbAddNavEntry(), and block 3\n     above is the copy of that call LATE_NAV needs so the row exists when the\n     sidebar is first drawn rather than at the end of this document.\n\n     IT CHANGES NOTHING ON THE SHOP BY BEING APPLIED. App\\Services\\PageWash\n     ships `on` FALSE and css() returns the empty string while it is, so every\n     storefront page is byte-identical until somebody switches the wash on --\n     and the screen opens on its PREVIEW tab, which writes nothing at all.\n\n     The preview frames five real storefront addresses with ?kbbwash= on them.\n     That parameter is honoured only for a request carrying an admin session, so\n     a shopper who is handed one of those URLs gets the shop exactly as it is\n     today. --}}\n@include('admin.partials.page-wash-screen')"

app = 'resources/views/admin/app.blade.php'
web = 'routes/web.php'

s = open(app).read()

# Block 1 - TITLES
# Block 1 - TITLES, at the END of the map.
#
# NOT after 'sitelayout', which was the first draft: docs/GS-ADMIN-APP-BLOCKS.md
# records the run from 'sitelayout' to the closing brace VERBATIM and
# GridSectionConsoleReachTest asserts that record appears in the console exactly
# once, so inserting inside it splits another lane's record in half. Appending
# lengthens it instead, and that document's copy is lengthened in the same
# commit -- which is the instruction T1B gives about LATE_RENDERED, applied to
# the line next door.
a1 = "'gridsections':['Appearance','Grid sections']};"
assert s.count(a1) == 1, ('block 1 anchor', s.count(a1))
s = s.replace(a1, "'gridsections':['Appearance','Grid sections'],'pagewash':['Appearance','Page background']};", 1)

# Block 2 - LATE_RENDERED
# Block 2 - LATE_RENDERED. The id list is APPENDED TO rather than matched: this
# is the one line in the console that moves, and it moved once while this lane
# was open ('gridsections', Lane GS). Matching it exactly would make this script
# stop working the next time somebody arms a screen, for no benefit.
import re
m = re.search(r"const LATE_RENDERED=new Set\(\[([^\]]*)\]\);", s)
assert m, 'LATE_RENDERED not found'
assert "'pagewash'" not in m.group(1), 'pagewash is already armed'
s = s[:m.end(1)] + ",'pagewash'" + s[m.end(1):]

# Block 3 - LATE_NAV, so the sidebar row exists when the sidebar is first drawn
# rather than at 98.9% of a 3.4 MB document. LAST in the array, because the row
# arrives last (this partial's include is last) and the array's ORDER is what
# reproduces the sidebar the console settles on.
a3 = "{screen:'gridsections',label:'Grid sections',group:'Appearance',after:['banners','hpcontent','homepage'],"
assert s.count(a3) == 1, ('block 3 anchor', s.count(a3))
i = s.index(a3)
j = s.index("},\n", i) + 3
s = s[:j] + LATE_NAV_ROW + s[j:]

# Block 4 - the include, LAST among the screen partials
a4 = "@include('admin.partials.grid-sections-screen')"
assert s.count(a4) == 1, ('block 4 anchor', s.count(a4))
s = s.replace(a4, a4 + INCLUDE_BLOCK, 1)

open(app, 'w').write(s)

# Block 5 - the route require, beside the site-layout one
w = open(web).read()
a5 = "require __DIR__.'/site-layout-admin.php';"
assert w.count(a5) == 1, ('block 5 anchor', w.count(a5))
w = w.replace(a5, a5 + "\n        require __DIR__.'/page-wash-admin.php';", 1)
open(web, 'w').write(w)

print('five edits applied')
