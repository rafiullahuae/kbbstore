"""Lane RG mutation runner: apply one textual mutation, run the named test
filter, restore the file byte for byte, report RED/GREEN."""
import os, subprocess, sys, shutil

W = '/home/user/kbbstore/.claude/worktrees/agent-aaa3d26cb75532bd5'
T = 'tests/Feature/ProductPageLaneRgTest.php'

M = [
    ('1 wrapperClass skips pd-off', 'app/Services/ProductDesktopSections.php', "                $out .= ' pd-off-'.$key;", "                // $out .= ' pd-off-'.$key;", 'hides the whole bundles block'),
    ('2 no pd-off-bundles rule', 'resources/css/kbb/kbb-product.css', '.pdp-page.pd-off-bundles .pm-bundles,', '', 'hides the whole bundles block'),
    ('3a d-off at 901', 'resources/css/kbb/kbb-product.css', '@media (min-width:881px){.pdp-page .d-off{', '@media (min-width:901px){.pdp-page .d-off{', 'not from kbb'),
    ('3b classFor m-off again', 'app/Services/ProductSections.php', "            $mobileOff = 'pdp-m-off';", "            $mobileOff = 'm-off';", 'not from kbb'),
    ('4 saveLaptop all into pdpds_off', 'app/Services/ProductDesktopSections.php', "            $module = self::moduleOf($key);\n\n            if ($module !== null) {\n                $row = is_array", "            $module = null;\n\n            if ($module !== null) {\n                $row = is_array", 'in the Sections row itself'),
    ('5 validateLaptop no key check', 'app/Services/ProductDesktopSections.php', "if (! is_string($key) || ! in_array($key, $all, true)) {", "if (! is_string($key)) {", 'refuses an unknown section'),
    ('6 no pd-off-auth rule', 'resources/css/kbb/kbb-product.css', '.pdp-page.pd-off-auth .kbb-cart-form > .pts-stack,', '', 'every switchable section'),
    ('7 paylater laptop hide back', 'resources/css/kbb/kbb-product.css', '/* ── 3 ── THE BUY COLUMN', '@media (min-width:881px){.pdp .pm-paylater{display:none}}\n/* ── 3 ── THE BUY COLUMN', 'Tabby & Tamara on a laptop'),
    ('8a firstBuy ignores drawn', 'app/Services/ProductDesktopSections.php', "if (($laptop[$key] ?? true) && ($buyDrawn[$key] ?? true)) {", "if (($laptop[$key] ?? true)) {", 'buy column order only once moved'),
    ('8b pdsb block outside media', 'resources/css/kbb/kbb-product.css', "@media (min-width:881px){\n  .pdp-page.pdsb-on .pdp .buybox{display:flex;flex-direction:column}", ".pdp-page.pdsb-on .pdp .buybox{display:flex;flex-direction:column}\n@media (min-width:881px){", 'buy column order only once moved'),
    ('9 bundles gap phone slider', 'resources/css/kbb/kbb-product.css', 'order:var(--pdsb-o-bundles,5);margin-block-start:var(--pl-opt-gap-d,20px)}\n  .pdp-page.pdsb-on .pm-ready', 'order:var(--pdsb-o-bundles,5);margin-block-start:var(--pl-opt-gap-m,20px)}\n  .pdp-page.pdsb-on .pm-ready', 'very sliders'),
    ('10 trim trailing too', 'app/Support/RichText.php', "return self::trimBlank($html, false);", "return self::trimBlank($html, true);", 'leading blank lines and nothing else'),
    ('11 controller loop removed', 'app/Http/Controllers/Store/ProductController.php', "            if ($trimmed !== '') {\n                $tabs[$i]['body'] = $trimmed;", "            if (false) {\n                $tabs[$i]['body'] = $trimmed;", 'in both prints'),
    ('12 no laptop tab gap rule', 'resources/css/kbb/kbb-product.css', '@media (min-width:881px){.details .dtabbar{margin-block-end:var(--pl-tab-body-gap-d,18px)}}', '', 'phone gap and a laptop gap'),
    ('13 migration no max', 'database/migrations/2027_07_21_000100_product_tab_body_gap_per_device.php', '$value = max(self::ASKED, min(48, $saved));', '$value = min(48, $saved);', 'lifts a saved tab-row gap'),
    ('14 no pd-dh2', 'app/Services/ProductMobileSections.php', ", 'details_h2_d' => 'pd-dh2']", "]", 'by default, each with its own switch'),
    ('15 no laptop in POST', 'resources/views/admin/partials/product-desktop-sections-screen.blade.php', 'buy: LISTS.buy.slice(), laptop: laptop } })', 'buy: LISTS.buy.slice() } })', 'laptop switch on every Desktop sections row'),
]

only = sys.argv[1:] or None
os.chdir(W)
env = dict(os.environ, KBB_WP_DB='kbb_wp_rg')
for name, path, old, new, flt in M:
    if only and name.split()[0] not in only:
        continue
    src = open(path, encoding='utf-8').read()
    if src.count(old) != 1:
        print(f'{name}: PATTERN NOT FOUND ({src.count(old)})', flush=True)
        continue
    bak = path + '.rgbak'
    shutil.copyfile(path, bak)
    try:
        open(path, 'w', encoding='utf-8').write(src.replace(old, new))
        r = subprocess.run(['vendor/bin/pest', '--compact', T, '--filter', flt], capture_output=True, text=True, env=env, timeout=500)
        out = r.stdout + r.stderr
        status = 'RED' if r.returncode != 0 else 'GREEN (mutation survived!)'
        tail = [l for l in out.splitlines() if 'Tests:' in l]
        print(f'{name}: {status} {tail[-1].strip() if tail else ""}', flush=True)
    finally:
        shutil.move(bak, path)
