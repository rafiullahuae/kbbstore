"""Lane PV mutation runs: apply one mutation, run the named test file, restore.

    python3 tools/pv-mutate.py         (from the worktree root)

Each mutation must turn ProductTopOfPageTest red; the script prints the
pass/fail summary for each and restores the file from git afterwards.
"""
import os
import subprocess

T = 'tests/Feature/ProductTopOfPageTest.php'

MUTATIONS = [
    ('M1 template back to forStorefront()', 'resources/views/store/product.blade.php',
     "RichText::forShortDescription($product->t('short_description'))",
     "RichText::forStorefront($product->t('short_description'))"),
    ('M2 trimEdge() trims nothing', 'app/Support/RichText.php',
     "        $changed = false;\n\n        while (",
     "        $changed = false;\n        return false;\n\n        while ("),
    ('M3 always re-serialise', 'app/Support/RichText.php',
     "        if (! $changed) {\n            return $html;\n        }",
     ""),
    ('M4 blank blurb still drawn', 'resources/views/store/product.blade.php',
     "@if ($product->short_description && $kbbBlurb !== '' && ! $kbbShortBelow)",
     "@if ($product->short_description && ! $kbbShortBelow)"),
    ('M5 badge loses lbl-off', 'resources/views/partials/product-gallery.blade.php',
     " lbl lbl-off\"", " lbl\""),
    ('M6 thumbs back to -30px (source)', 'resources/css/kbb/kbb-product.css',
     "margin-block-start:calc(10px - var(--pl-thumb-over,0) * 40px)",
     "margin-block-start:-30px"),
    ('M7 migration copies nothing', 'database/migrations/2027_07_12_000000_clear_caches_product_top_controls.php',
     "        'head_gap' => ['head_gap_d'],", ""),
    ('M8 migration forgets the +26', 'database/migrations/2027_07_12_000000_clear_caches_product_top_controls.php',
     "(int) $buybox + 26", "(int) $buybox"),
    ('M9 hex() lock removed', 'app/Services/ProductLayout.php',
     "return preg_match('/^#(?:[0-9A-F]{3}|[0-9A-F]{6})$/', $value) === 1 ? $value : $fallback;",
     "return $value === '' ? $fallback : $value;"),
]

env = dict(os.environ, KBB_WP_DB='kbb_wp_pv')

for name, path, old, new in MUTATIONS:
    src = open(path).read()
    assert src.count(old) == 1, (name, src.count(old))
    open(path, 'w').write(src.replace(old, new))
    try:
        out = subprocess.run(['vendor/bin/pest', '--compact', T], capture_output=True, text=True, env=env, timeout=600).stdout
    finally:
        subprocess.run(['git', 'checkout', '--', path])
    summary = [l for l in out.splitlines() if 'Tests:' in l]
    failed = [l.strip() for l in out.splitlines() if 'FAILED' in l]
    print(f"{name}: {summary[-1].strip() if summary else '??'}")
    for f in failed:
        print('    ', f[:160])
