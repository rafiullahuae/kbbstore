<?php

declare(strict_types=1);

use App\Support\ImageVariants;

/*
 * 2.60.465. The owner, on an iPhone, 10 October: "on product page, the related
 * products etc are loading thumbnails of 700x700 with this url
 * .../wp-content/uploads/2026/03/Dr.-Althea-345-Relief-Cream-Mist-Spray-Set-kbeautybliss.webp".
 *
 * WHAT IT LOOKED LIKE. A product card declared 50vw on a phone: 585 device
 * pixels on a 390px iPhone at 3x, past the 400w copy, so the 700px upload
 * itself was fetched (46 KB against 6 KB for the 400w copy). The main product
 * photo took its 1000px original (196 KB) over the 800w copy (9.5 KB) the same
 * way. Measured in Chromium with these exact strings, each pick in its own
 * page: 390@3x card original -> 400w, main photo original -> 800w; 390@2x,
 * 412@1.75x (Lighthouse), 1280@1x and 1280@2x pick exactly what they did.
 *
 * MUTATION: drop the min-resolution line from either string and this is red.
 */
it('caps product cards and the main product photo at about 2x on 3x phones, and leaves every other screen as it was', function () {
    $tile = ImageVariants::tileSizesAttribute();
    $detail = ImageVariants::detailSizesAttribute();

    expect($tile)->toStartWith('(max-width: 735px) and (min-resolution: 2.5dppx) 34vw, ')
        ->and(substr($tile, strlen('(max-width: 735px) and (min-resolution: 2.5dppx) 34vw, ')))
        ->toBe('(max-width: 735px) 50vw, (max-width: 971px) 35vw, (max-width: 1207px) 26vw, 260px')
        ->and($detail)->toStartWith('(max-width: 880px) and (min-resolution: 2.5dppx) calc((100vw - 40px) * 0.67), ')
        ->and(substr($detail, strlen('(max-width: 880px) and (min-resolution: 2.5dppx) calc((100vw - 40px) * 0.67), ')))
        ->toBe('(max-width: 880px) calc(100vw - 40px), (max-width: 1180px) 52vw, 563px');
});
