<?php

declare(strict_types=1);

/**
 * Arabic letters join, so the small tracked-out labels take no letter spacing
 * on /ar. (17 October 2026)
 *
 * What it looked like on the shop: on the Arabic product page "منتجات مشابهة"
 * and "شوهدت مؤخرًا" were printed with every letter pulled apart, and so were
 * the footer's column headings (التسوق / المساعدة / الحساب) -- the .12em-.2em
 * tracking meant for English capitals. Chromium (tools/als-scan.cjs) found 15
 * selectors carrying positive spacing on Arabic text across home, shop,
 * collections, brand, product, cart, blog, FAQ, account and the menu; after
 * the rule, none (the flag bar's 0.07px aside, inside the locked header).
 *
 * MUTATION: delete the rule -- red; scope it without html[lang="ar"] and
 * English loses its tracking -- the second assertion is red.
 */
it('takes the letter spacing off every tracked label on Arabic pages only', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    $start = strpos($css, 'html[lang="ar"] .sl-t .k,');
    expect($start)->not->toBeFalse();

    $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start + 1);

    foreach (['.sl-t .k', '.bndl-all', '.kbb-card-cart', '.kbb-soldout', '.kbb-badge', '.hs-eyebrow', '.hs-go', '.kft-avail', '.kft-ch', '.eyebrow', '.sr-eyebrow', '.gmain .cap', '.bt-label', '.sh .lnk', '.policy-body h2'] as $sel) {
        expect($rule)->toContain('html[lang="ar"] '.$sel);
    }

    // Every selector in the rule is scoped to Arabic pages.
    $selectors = array_map('trim', explode(',', substr($rule, 0, (int) strpos($rule, '{'))));
    foreach ($selectors as $sel) {
        expect($sel)->toStartWith('html[lang="ar"] ');
    }

    expect($rule)->toEndWith('{letter-spacing:0 !important}');
});
