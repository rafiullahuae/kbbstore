#!/usr/bin/env python3
"""Lane RC mutation runner: apply one mutation, run the test that should catch
it, restore the file, report red/green. Logs to storage/rc-logs/mutations.txt.

    python3 tools/rc-mutate.py            # all
    python3 tools/rc-mutate.py M3 M4      # some
"""
import os, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOG = os.path.join(ROOT, 'storage', 'rc-logs', 'mutations.txt')

M = {
 'M1': ('app/Models/BannerSet.php',
        "        'single' => 'Single image — one picture, shown whole at its own height',\n", '',
        'tests/Feature/BannerSingleImageTest.php', 'is a kind'),
 'M2': ('app/Models/BannerSet.php',
        "        'slider' => 'padding-top:0',", "        'slider' => 'padding-top:8px',",
        'tests/Feature/HomepageBannerFlushTest.php', 'flush under the header'),
 'M3': ('app/Services/Banners.php',
        "            'slider_fit', 'slider_h', 'slider_h_m',\n        ];", "            'slider_fit',\n        ];",
        'tests/Feature/BannerSliderFitHeightTest.php', 'carries a stored height'),
 'M4': ('resources/views/partials/home/slider-banner.blade.php',
        "        $crop = $bsCrops\n", "        $crop = true\n",
        'tests/Feature/BannerSliderFitHeightTest.php', 'server-made crop unless'),
 'M5': ('app/Models/BannerSet.php',
        "        'slider_fit' => 'contain',\n        'slider_h' => 0,", "        'slider_fit' => 'cover',\n        'slider_h' => 0,",
        'tests/Feature/BannerSliderFitHeightTest.php', 'ships whole pictures'),
 'M6': ('app/Models/BannerCard.php',
        "            self::$sizes[$path] = \\App\\Support\\ImageVariants::sizeOf(\\App\\Support\\ImageVariants::rootRelative($path));",
        "            self::$sizes[$path] = null;",
        'tests/Feature/BannerSingleImageTest.php', 'reads the size off the file'),
 'M7': ('resources/views/partials/home/slider-banner.blade.php',
        "padding-inline:var(--kbbs-gut);padding-block:0 18px}", "padding-inline:var(--kbbs-gut);padding-block:18px}",
        'tests/Feature/HomepageBannerFlushTest.php', 'top padding'),
 'M8': ('resources/views/partials/home/slider-banner.blade.php',
        ".kbbs-vp{width:100%;aspect-ratio", ".kbbs-vp{aspect-ratio",
        'tests/Feature/BannerSliderFitHeightTest.php', 'carries a stored height'),
 'M9': ('resources/views/admin/partials/banners-screen.blade.php',
        "    'slider_fit', 'slider_h', 'slider_h_m'];", "    'slider_fit', 'slider_h_m'];",
        'tests/Feature/BannerSliderFitHeightTest.php', 'sends the fit'),
 'M10': ('app/Http/Controllers/Admin/BannerApiController.php',
        "'slider_fit' => ['sometimes', Rule::in(array_keys(BannerSet::SLIDER_FITS))],", "'slider_fit' => ['sometimes', 'string'],",
        'tests/Feature/BannerSliderFitHeightTest.php', 'stores one of its own fits'),
 'M11': ('resources/views/partials/home/slider-banner.blade.php',
        ".kbbs.is-whole .kbbs-a img{object-fit:contain}", "",
        'tests/Feature/BannerSliderFitHeightTest.php', 'only crops under cover'),
 'M12': ('app/Models/BannerSet.php',
        "        return $raw <= 0 ? 0 : max(80, min($max, $raw));", "        return $raw;",
        'tests/Feature/BannerSliderFitHeightTest.php', 'clamps a height'),
}

which = sys.argv[1:] or list(M)
out = open(LOG, 'a')
for key in which:
    path, old, new, test, filt = M[key]
    full = os.path.join(ROOT, path)
    src = open(full).read()
    assert src.count(old) == 1, (key, src.count(old))
    open(full, 'w').write(src.replace(old, new))
    try:
        env = dict(os.environ, KBB_WP_DB='kbb_wp_rc')
        r = subprocess.run(['vendor/bin/pest', '--compact', test, '--filter', filt],
                           cwd=ROOT, env=env, capture_output=True, text=True, timeout=600)
    finally:
        open(full, 'w').write(src)
    tail = [l for l in r.stdout.splitlines() if 'Tests:' in l]
    verdict = 'RED (caught)' if r.returncode != 0 else 'GREEN (MISSED)'
    line = f"{key} {path}: {verdict} :: {tail[-1].strip() if tail else r.stdout[-300:]}"
    print(line); out.write(line + '\n'); out.flush()
