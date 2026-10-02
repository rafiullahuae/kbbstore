<?php

/**
 * Catalog → Products fits a desktop screen, and the row actions never scroll
 * out of reach.
 *
 * THE DEFECT, FROM THE OWNER'S SCREENSHOT (2 October 2026, 788 imported
 * products): Visit / Edit were off the right edge on a desktop and only
 * reachable by scrolling the table sideways. The Product cell had no width
 * limit, so one long imported name widened the table past the screen; and the
 * default columns alone measured 1,122px in a 994px table at 1280.
 *
 * Measured in Chromium after the fix, with long names seeded: at 1280 the
 * table is 994px in a 994px area (Product 233px), at 1440 1150 in 1150
 * (Product 388px); at 390 the table scrolls inside its card and the actions
 * cell's right edge equals the area's right edge (pinned).
 *
 * MUTATIONS, RUN: drop the `position:sticky` rule and the pinned-actions
 * expectation is red; put `sku: true` back as a default and the defaults case
 * is red.
 */
it('pins the row actions to the right edge and lets Product flex on desktop', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($app)->toContain(".cplscroll th.cplact,.cplscroll td.cplact{position:sticky;right:0;")
        ->and($app)->toContain('@media(min-width:901px){.cplscroll th.cplprod,.cplscroll td.cplprod{width:100%;max-width:0}}')
        ->and($app)->toContain(".cplscroll td.cplprod{min-width:230px;max-width:360px}")
        ->and($app)->toContain('<th class="cplact"')
        ->and($app)->toContain('<td class="cplact">')
        ->and($app)->toContain('<td class="cplprod" title="');
});

it('ships the columns that fit 1280 on by default, the rest one click away', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($app)->toContain('sku: false, brand: true, status: true, stock: true, price: true,')
        ->and($app)->toContain('sale: false, categories: true, featured: true, orders: true, date: false, wc: false')
        ->and($app)->toContain('>Customize columns ');
});
