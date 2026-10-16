<?php

declare(strict_types=1);

/**
 * Giant empty space above and below a product's short description. (16 October 2026)
 *
 * What it looked like on the shop: on the VT Reedle Shot 100 2Step masks (and
 * every product whose short description was pasted from another shop, wrapped
 * in <div class="productDetailPage-module-..."><section>), a blank band sat
 * above the text and another below it, and on a narrow clamp the box showed one
 * line before "Read more". kbb.css's page-band rule `section{padding:52px 0}`
 * reached the pasted <section>; the long description already cancelled it
 * (`.details .dcontent section`), the short one did not.
 *
 * Measured in Chromium (tools/sdl-gap.cjs): section padding 52px / 52px -> 0;
 * lines shown before "Read more" 1 -> 4-5, at 390 and 1280, English and Arabic.
 *
 * MUTATION: delete `.pdp .bb-desc section{padding:0;margin:0}` -- red.
 */
it('cancels the page-band padding on a <section> pasted into the short description', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect($css)->toContain('.pdp .bb-desc section{padding:0;margin:0}')
        // The rule it has to beat is still there, so the reset still matters.
        ->and((string) file_get_contents(resource_path('css/kbb/kbb.css')))->toContain('section{padding:52px 0}');
});
