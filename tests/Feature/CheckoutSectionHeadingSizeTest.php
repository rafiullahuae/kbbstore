<?php

declare(strict_types=1);

/*
 * "the headings of the sections like shipping details etc, make 2 font size
 * increase" (the owner, 9 October). The four numbered section bars on the
 * checkout were 13px; the base is now 15px, so 100% on Appearance -> Checkout
 * page -> Mobile / Desktop · Text sizes -> Section heading size is the new size
 * and the slider still scales from it.
 *
 * MUTATION: put 13px back in kbb-checkout.css -> red.
 */
it('draws the checkout section headings 2px larger than before', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    expect($css)->toContain('.kbb-checkout .sec > h2{font-size:calc(15px * var(--cop-th2))')
        ->not->toContain('.kbb-checkout .sec > h2{font-size:calc(13px * var(--cop-th2))');
});
