<?php

/**
 * A review card's stars stay inside the card, whatever the reviewer is called.
 *
 * THE DEFECT, MEASURED IN CHROMIUM AT 390. `.sr-cmeta` holds the reviewer's
 * name and the star row side by side, and the stars are `flex:0 0 auto` -- they
 * may not shrink. A name that is one long word cannot wrap either, and
 * "Anonymous" is exactly what imported WooCommerce reviews carry for a guest.
 * On a phone, where the cards sit two to a row at ~174px, the stars ran 29px
 * past the card's right edge and drew over the neighbouring card. Four cards,
 * stars past the edge by [-54, 29, -54, -54] px before; [-54, -54, -54, -54]
 * after, with only that card 9px taller and every other card unchanged.
 *
 * `flex-wrap:wrap` moves the stars to their own line only when they do not
 * fit, so a card that fits is laid out exactly as before.
 *
 * The product page inlines this file off disk (ProductController::reviewsCss),
 * so the file is the thing to pin. MUTATION: drop `flex-wrap:wrap` from the
 * rule and this is red.
 */
it('lets the star row wrap under a long reviewer name instead of running past the card', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/sorina-reviews.css'));
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    preg_match('/\.sr-cmeta\{([^}]*)\}/', $css, $rule);

    expect($rule[1] ?? '')->toContain('flex-wrap:wrap')
        ->and($rule[1] ?? '')->toContain('min-width:0');

    // The stars still may not shrink -- squashing them would be the other way
    // to "fit", and five stars at a fraction of their width read as none.
    preg_match('/\.sr-cs\{([^}]*)\}/', $css, $stars);
    expect($stars[1] ?? '')->toContain('flex:0 0 auto');
});
