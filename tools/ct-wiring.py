#!/usr/bin/env python3
"""Apply (or revert) Lane CT's five integrator blocks — docs/CT-ADMIN-APP-BLOCKS.md.

    python3 tools/ct-wiring.py apply    # edits routes/web.php and app.blade.php
    python3 tools/ct-wiring.py check    # says whether each block is present once

Used by the lane to measure the FINISHED state (suite, screenshots) and then
`git checkout -- routes/web.php resources/views/admin/app.blade.php` to undo:
the lane may not commit either file. Each anchor must occur exactly once.
"""
import os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WEB = os.path.join(ROOT, 'routes/web.php')
APP = os.path.join(ROOT, 'resources/views/admin/app.blade.php')

ROW = ("  {screen:'carttracking',label:'Cart Tracking',group:'Growth & Marketing',"
       "after:['searchterms','pixels','meta','labels','newsletter'],"
       "icon:'<path d=\"M6 6h15l-1.5 9h-12z\"/><circle cx=\"9\" cy=\"20\" r=\"1.3\"/><circle cx=\"18\" cy=\"20\" r=\"1.3\"/><path d=\"M6 6 5 3H2\"/><path d=\"m11 10 2 2 3-3\"/>'}")

BLOCKS = [
    (WEB, "        require __DIR__.'/security-admin.php';\n",
     "        require __DIR__.'/security-admin.php';\n\n"
     "        // Growth & Marketing → Cart Tracking (Lane CT). Same group and the\n"
     "        // same reason as Security: every row carries a shopper's IP address.\n"
     "        // carttracking.view / carttracking.block, owner and manager.\n"
     "        require __DIR__.'/cart-tracking-admin.php';\n"),
    (APP, "  {screen:'searchterms',label:'Search Terms',group:'Growth & Marketing',after:['pixels','meta','labels','newsletter'],icon:'<circle cx=\"11\" cy=\"11\" r=\"7\"/><path d=\"m21 21-4-4\"/><path d=\"M8 11h6\"/><path d=\"M11 8v6\"/>'}\n",
     "  {screen:'searchterms',label:'Search Terms',group:'Growth & Marketing',after:['pixels','meta','labels','newsletter'],icon:'<circle cx=\"11\" cy=\"11\" r=\"7\"/><path d=\"m21 21-4-4\"/><path d=\"M8 11h6\"/><path d=\"M11 8v6\"/>'},\n" + ROW + "\n"),
    (APP, "'searchterms':['Growth & Marketing','Search Terms']};",
     "'searchterms':['Growth & Marketing','Search Terms'],'carttracking':['Growth & Marketing','Cart Tracking']};"),
    (APP, "'gridsections','pagewash','searchterms','emails'",
     "'gridsections','pagewash','searchterms','carttracking','emails'"),
    (APP, "@include('admin.partials.search-terms-screen')\n",
     "@include('admin.partials.search-terms-screen')\n"
     "{{-- Growth & Marketing → Cart Tracking (Lane CT). After Search Terms, whose\n"
     "     row its sidebar entry anchors on. --}}\n"
     "@include('admin.partials.cart-tracking-screen')\n"),
]


def main(mode):
    ok = True
    for path, anchor, repl in BLOCKS:
        src = open(path, encoding='utf-8').read()
        done = src.count(repl) == 1
        if mode == 'check':
            print(('present ' if done else 'MISSING ') + os.path.relpath(path, ROOT) + ': ' + anchor.strip()[:70])
            ok = ok and done
            continue
        if done:
            continue
        if src.count(anchor) != 1:
            print('anchor not found exactly once in %s: %r' % (path, anchor[:80]))
            sys.exit(1)
        open(path, 'w', encoding='utf-8').write(src.replace(anchor, repl))
    if mode == 'check' and not ok:
        sys.exit(1)


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else 'check')
