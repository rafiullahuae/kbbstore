<?php

declare(strict_types=1);

/**
 * A product-page review vanishing under the pointer on /ar. (16 October 2026)
 *
 * What it looked like on the shop: on an Arabic product page, pointing at a
 * review made that review disappear; moving the pointer brought it back, so it
 * blinked. The cards sit in CSS columns (.sr-grid) and lifted on hover with
 * transform:translateY(-3px). A transformed box gets a layer of its own, and
 * in right-to-left columns the browser could draw that layer in another column:
 * the card left the pointer, lost :hover, returned, and so on.
 *
 * On /ar the card now lifts the same 3px with position:relative + top, which
 * makes no layer (measured in Chromium: 3.0px lift, transform none). English
 * keeps its transform: unchanged.
 *
 * MUTATION: delete the [dir="rtl"] .sr-card:hover rule -- red.
 */
it('lifts an Arabic review card without transform, and leaves English as it was', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/sorina-reviews.css'));

    expect($css)->toContain('[dir="rtl"] .sr-card{position:relative;top:0;transition:top .18s,box-shadow .18s}')
        ->and($css)->toContain('[dir="rtl"] .sr-card:hover{transform:none;top:-3px}')
        ->and($css)->toContain('.sr-card:hover{transform:translateY(-3px);box-shadow:0 16px 34px -24px rgba(0,0,0,.28)}');
});
