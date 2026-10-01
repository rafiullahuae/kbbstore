"""
Run the mutation notes in tests/Feature/UnfinishedDraftsTest.php (and the two
updated tests) for real: apply one change, run the test that claims to catch
it, put the file back, record red or green.                         (Lane PM)

    KBB_WP_DB=kbb_wp_pm python3 tools/pm-mutate.py

Every file is restored in a finally, from a copy taken before the change.
"""
import os
import subprocess
import sys

APP = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
V = 'resources/views/admin/'
REG = V + 'partials/unfinished-drafts.blade.php'
APPB = V + 'app.blade.php'
UD = 'tests/Feature/UnfinishedDraftsTest.php'

INCLUDE = "@include('admin.partials.unfinished-drafts')\n"

MUTATIONS = [
    # (name, file, old, new, test file, filter)
    ('include moved below banners-screen', APPB, INCLUDE, '',
     UD, 'before the screens that register', ("@include('admin.partials.banners-screen')", "@include('admin.partials.banners-screen')\n" + INCLUDE)),
    ('include moved inside the closing @verbatim block', APPB, INCLUDE, '',
     UD, 'before the screens that register', ("\n@endverbatim\n\n{{-- Store -> New Order.", "\n" + INCLUDE + "@endverbatim\n\n{{-- Store -> New Order.")),
    ('wrapper id back to kbbDrafts', REG, 'id="kbbUnfinished" data-u', 'id="kbbDrafts" data-u', UD, 'never names an element', None),
    ('confirm() back in grid mayLeave', V + 'partials/grid-sections-screen.blade.php',
     "    if (dirty() && window.kbbDrafts) window.kbbDrafts.flush('gridsections');\n    return true;",
     "    if (!dirty()) return true;\n    return window.confirm('You have ' + changed().length + ' unsaved change(s).');",
     UD, 'no longer asks before leaving', None),
    ('beforeunload back in banners', V + 'partials/banners-screen.blade.php',
     '  /* ----------------------------------------------------------------- init */',
     "  window.addEventListener('beforeunload', function(ev){ if (dirty()) ev.preventDefault(); });\n  /* ----------------------------------------------------------------- init */",
     UD, 'no longer asks before leaving', None),
    ('confirm() back in reorderConfirmDiscard', APPB,
     "  if(reorderDirty && window.kbbDrafts) kbbDrafts.flush('reorder');\n  return true;",
     "  if(!reorderDirty) return true;\n  return confirm('You have unsaved reorder changes on this page. Discard them?');",
     UD, 'no longer asks before leaving', None),
    ("saved('header') deleted", APPB, "if(j.ok){ if(window.kbbDrafts) kbbDrafts.saved('header'); msg.style", "if(j.ok){ msg.style",
     UD, 'wires every screen app.blade.php draws', None),
    ("search adapter deleted", REG, "schema({ id: 'search',", "schemaX({ id: 'search',",
     UD, 'wires every screen app.blade.php draws', None),
    ('paintMobileMenu renamed in app.blade.php', APPB, 'function paintMobileMenu(', 'function paintMobileMenuX(',
     UD, 'wires every screen app.blade.php draws', None),
    ("ready(SCREEN) removed from cart panel", V + 'partials/cart-panel-screen.blade.php',
     'if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);', '',
     UD, 'wires every partial screen', None),
    ("discarded('homepage') removed", APPB, "if(window.kbbDrafts) kbbDrafts.discarded('homepage'); renderHomepage();", 'renderHomepage();',
     UD, 'Discard reloads it', None),
    ('row built with innerHTML', REG, "      b.textContent = String(d.label || d.screen || '');",
     "      b.innerHTML = String(d.label || d.screen || '');", UD, 'prints every label as text', None),
    ('guard opt-in removed', V + 'partials/reset-guard.blade.php', 'if (!asks && !RESET_LABEL.test(label)) return;',
     'if (!RESET_LABEL.test(label)) return;', UD, 'Are you sure', None),
    ('bar button labelled Restore', REG, "BAR_A.textContent = 'Use my changes';", "BAR_A.textContent = 'Restore my changes';",
     UD, 'Are you sure', None),
    ('admin id dropped from the storage key', REG, "return 'kbb.drafts.v1.' + (HOME.getAttribute('data-u') || '0') + '.' + path;",
     "return 'kbb.drafts.v1.' + path;", UD, 'per admin', None),
    ('14 days changed to 30', REG, 'var MAX_AGE = 14 * 24 * 3600 * 1000;', 'var MAX_AGE = 30 * 24 * 3600 * 1000;',
     UD, 'per admin', None),
    ('secret filter removed from copy()', REG, "      if (SECRET.test(k)) return;\n      var v = map[k];", "      var v = map[k];",
     UD, 'per admin', None),
    ('banners go() no longer hands over', V + 'partials/banners-screen.blade.php', "      mayLeave();\n      draft = null;",
     "      draft = null;", 'tests/Feature/CardsBannerEditorTest.php', 'keeps it, without asking', None),
    ('banners mayLeave asks again', V + 'partials/banners-screen.blade.php',
     "    if (dirty() && window.kbbDrafts) window.kbbDrafts.flush('banners');\n    return true;",
     "    if (!dirty()) return true;\n    return window.confirm('Leave them?');",
     'tests/Feature/CardsBannerEditorTest.php', 'keeps it, without asking', None),
    ('set appearance registration deleted', V + 'partials/set-appearance-screen.blade.php',
     'if (window.kbbDrafts) window.kbbDrafts.track({', 'if (false) ({',
     'tests/Feature/SetAppearanceScreenGroupsTest.php', 'keeps every behaviour', None),
    ('set appearance beforeunload back', V + 'partials/set-appearance-screen.blade.php',
     '  addNavEntry();\n})();', "  window.addEventListener('beforeunload', function (e) { e.preventDefault(); });\n  addNavEntry();\n})();",
     'tests/Feature/SetAppearanceScreenGroupsTest.php', 'keeps every behaviour', None),
]


def run(test, flt):
    env = dict(os.environ)
    env.setdefault('KBB_WP_DB', 'kbb_wp_pm')
    p = subprocess.run(['vendor/bin/pest', '--compact', test, '--filter', flt], cwd=APP, env=env,
                       stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    return p.returncode


def main():
    out = []
    for name, path, old, new, test, flt, extra in MUTATIONS:
        full = os.path.join(APP, path)
        orig = open(full, encoding='utf-8').read()
        try:
            assert orig.count(old) >= 1, (name, 'anchor missing')
            s = orig.replace(old, new, 1)
            if extra:
                assert s.count(extra[0]) == 1, (name, 'extra anchor')
                s = s.replace(extra[0], extra[1])
            open(full, 'w', encoding='utf-8').write(s)
            code = run(test, flt)
        finally:
            open(full, 'w', encoding='utf-8').write(orig)
        verdict = 'RED (caught)' if code != 0 else 'GREEN (MISSED)'
        out.append((name, verdict))
        print(f'{verdict:16} {name}', flush=True)
    missed = [n for n, v in out if v.startswith('GREEN')]
    print(f'\n{len(out) - len(missed)}/{len(out)} mutations caught')
    sys.exit(1 if missed else 0)


main()
