<?php

declare(strict_types=1);

/*
 * The owner, 9 October: "the support strip and the floating whatsapp icon,
 * must not overlap ... the floating whatsapp icon function should hide
 * immidiately temporary ... no any code which comrpomise on site speed".
 *
 * Measured in Chromium on / and /about/ at 390 and 1280, scrolling top to
 * bottom in 60px steps: 0 positions with the strip over the button and the
 * button showing; it is back the moment the strip leaves its corner.
 *
 * MUTATIONS: drop initWaAway from app.js's STEPS, or the html.kft-near rule
 * from kbb.css -> red. Measure layout in wa-away.js -> red.
 */
it('watches the strip with one IntersectionObserver and measures nothing', function () {
    $js = file_get_contents(resource_path('js/kbb/wa-away.js'));

    expect($js)->toContain('new IntersectionObserver(')
        ->and($js)->toContain("classList.toggle('kft-near'")
        ->and($js)->toContain("document.querySelector('.kft-help')")
        ->and($js)->not->toMatch('/getBoundingClientRect|getClientRects|offset(Width|Height|Top|Left)|getComputedStyle|scroll(Y|Top)|addEventListener\(\s*[\'"]scroll/');
});

it('starts with the rest of the shop, last, from the one app bundle', function () {
    $app = file_get_contents(resource_path('js/kbb/app.js'));

    expect($app)->toContain("import { initWaAway } from './wa-away.js';")
        ->and($app)->toMatch('/initReadMore,\s*initWaAway,\s*\];/');
});

it('hides the button and its bubble at once while the class is on, in CSS', function () {
    expect(file_get_contents(resource_path('css/kbb/kbb.css')))
        ->toContain('html.kft-near #kbbWa{visibility:hidden!important;opacity:0!important;transition:none!important;pointer-events:none!important}');
});
