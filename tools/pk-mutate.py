"""Lane PK mutation runner: apply one textual mutation, run the named test,
report pass/fail, restore the file byte-for-byte. Usage: python3 mutate.py <n>"""
import os, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ED = 'resources/views/admin/partials/product-editor-screen.blade.php'
PEC = 'app/Http/Controllers/Admin/ProductEditorApiController.php'
CPC = 'app/Http/Controllers/Admin/CatalogProductsApiController.php'
T = 'tests/Feature/SetPriceApplyButtonTest.php'

OLD_HANDLER = """      use.addEventListener('click', function(){
        collect();
        model.price_mode = 'discount_amount';
        model.discount_amount = '0';
        dirty = true;
        render();
      });"""

NEW_HANDLER_START = "      use.addEventListener('click', function(){\n        collect();\n\n        var t = setTotals();"

MUTATIONS = {
    # 1. the old button, verbatim, and no setApplyFigures
    '1': [(ED, None, 'OLD')],
    # 2. rule branch fills Price only
    '2': [(ED, "return { price_aed: String(total / 100), sale_aed: set > 0 && set < total ? String(set / 100) : '' };",
           "return { price_aed: String(total / 100), sale_aed: '' };")],
    # 3. floor instead of half-up
    '3': [(ED, "return Math.floor((Math.max(0, Number(f) || 0) + 50) / 100) * 100;",
           "return Math.floor((Math.max(0, Number(f) || 0)) / 100) * 100;")],
    # 4. the tile ignores the sale price
    '4': [(ED, "    return sale < typed ? sale : typed;\n  }", "    return typed;\n  }")],
    # 5. the request the OLD button made
    '5': [(T, "        'price_mode' => 'fixed',\n        'price_aed' => '1310',\n        'sale_aed' => '1179',\n        'reanchor' => true,\n    ])->assertOk()->json('product');",
           "        'price_mode' => 'discount_amount',\n        'discount_amount' => '0',\n    ])->assertOk()->json('product');")],
    # 6. live always true
    '6': [(PEC, "'live' => $product->exists && ! $product->trashed()\n                    && \\App\\Support\\ProductVisibility::isLive($product),",
           "'live' => true,")],
    # 7. hand-built address
    '7': [(PEC, "                'url' => $product->url(),\n                /*", "                'url' => '/product/'.$product->slug.'/',\n                /*")],
    # 8. Visit never wired into the bar
    '8': [(ED, "        + visitButton()\n", "")],
    # 9. no `live` on list rows
    '9': [(CPC, "            'live' => $p->deleted_at === null && \\App\\Support\\ProductVisibility::isLive($p),\n", "")],
    # 10. noopener dropped
    '10': [(ED, 'target="_blank" rel="noopener"', 'target="_blank"')],
    # 11. live/not-live branches swapped
    '11': [(ED, "var href = model && model.id && ro.live ? url(ro.url) : '';", "var href = model && model.id && !ro.live ? url(ro.url) : '';")],
}

FILTERS = {'1': None, '2': 'fills Price', '3': 'fills Price', '4': 'Set price tile', '5': 'struck',
           '6': 'every product', '7': 'base path', '8': 'editor bar once', '9': 'Catalog', '10': 'editor bar once', '11': 'disabled button when not'}

n = sys.argv[1]
backups = {}
try:
    for path, old, new in MUTATIONS[n]:
        full = os.path.join(ROOT, path)
        src = open(full).read()
        backups.setdefault(full, src)
        if new == 'OLD':
            start = src.index(NEW_HANDLER_START)
            end = src.index("        render();\n      });", start) + len("        render();\n      });")
            src = src[:start] + OLD_HANDLER + src[end:]
            a = src.index('  function setApplyFigures(')
            b = src.index('  function setTotals(){')
            src = src[:a] + src[b:]
        else:
            assert src.count(old) == 1, (n, path, old[:60])
            src = src.replace(old, new)
        open(full, 'w').write(src)
    cmd = ['vendor/bin/pest', '--compact', T]
    if FILTERS[n]:
        cmd += ['--filter', FILTERS[n]]
    env = dict(os.environ, KBB_WP_DB='kbb_wp_pk')
    r = subprocess.run(cmd, cwd=ROOT, env=env, capture_output=True, text=True)
    lines = [l for l in r.stdout.splitlines() if 'Tests:' in l or 'FAILED' in l or '✘' in l]
    print('mutation', n, 'exit', r.returncode, '|', ' / '.join(l.strip() for l in lines))
finally:
    for full, src in backups.items():
        open(full, 'w').write(src)
