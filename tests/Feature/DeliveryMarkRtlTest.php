<?php

declare(strict_types=1);

/**
 * The product page's FAST DELIVERY mark on /ar. (16 October 2026)
 *
 * What it looked like on the shop: in the yellow delivery box, "FAST" and
 * "DELIVERY" were printed on top of the truck, and the arrow pointed right,
 * against the way Arabic reads -- on phones and on computers. The mark is an
 * inline SVG (TrustShareIcons::TRUCK) whose <text> inherited direction:rtl, so
 * each word grew leftwards from its x and ran over the truck.
 *
 * Measured in Chromium (tools/ptd-shots.cjs): before, 155/105 px² of overlap at
 * 390 and 242/169 at 1280, arrow right; after, 0 overlap, truck and arrow
 * facing left, words reading FAST / DELIVERY. English: unchanged, 0 overlap.
 *
 * MUTATION: drop direction:ltr -- the words overlap again; drop the text
 * rule -- the words read mirrored. Each is a line below.
 */
it('draws the Arabic delivery mark left-to-right inside, mirrored as a whole, with its words turned back', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-pdp-trust.css'));

    expect($css)->toContain('[dir="rtl"] .pts-del-logo .pts-truck{direction:ltr;transform:scaleX(-1)}')
        ->and($css)->toContain('[dir="rtl"] .pts-del-logo .pts-truck text{transform-box:fill-box;transform-origin:center;transform:scaleX(-1)}');

    // Only the drawn mark: an uploaded picture is an <img> and is never mirrored.
    expect(preg_match('/\[dir="rtl"\][^{]*\bimg\b[^{]*\{[^}]*scaleX/', $css))->toBe(0);
});
