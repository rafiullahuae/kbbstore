<?php

declare(strict_types=1);

/*
 * The desktop mega menu opens on EVERY shop page, not only the homepage.
 *
 * The owner, 7 October, hovering "Trending Brands" on the Skincare Sets
 * category: "the DESKTOP mega menu is not showing anything upon mouse hover."
 * The pointer under the item drew, the panel never did. kbb-shop.css and
 * kbb-product.css -- loaded on category, shop and product pages, AFTER
 * kbb.css -- still carried the 2.60.41 baseline's `.mega{...display:none}`
 * for a `.cat.has-mega` header that no longer exists. Same specificity, later
 * file: it won, so every multi-column panel (Brands, Skincare...) stayed
 * hidden on those pages while single-column dropdowns, which are not `.mega`,
 * kept working. The panel's only owner is resources/css/kbb/kbb.css.
 *
 * MUTATION NOTE, RUN: put `.mega{display:none}` back into kbb-shop.css or
 * kbb-product.css → RED.
 */

it('leaves the mega panel to kbb.css: no other shop stylesheet declares a .mega rule', function () {
    $offenders = [];

    foreach (glob(resource_path('css/kbb/*.css')) as $file) {
        if (basename($file) === 'kbb.css') {
            continue;
        }

        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));

        if (preg_match_all('/(?:^|[}\s,])(\.mega(?:[\s{.:,>\[-]|$)[^{]*)\{/m', $css, $m)) {
            foreach ($m[1] as $selector) {
                $offenders[] = basename($file).': '.trim($selector);
            }
        }
    }

    expect($offenders)->toBe([], "these rules reach the header's mega panel from a page stylesheet:\n".implode("\n", $offenders));
});
