<?php

declare(strict_types=1);

/**
 * Lane PS -- "Heading elements are not in a sequentially-descending order".
 *
 * WHAT LIGHTHOUSE REPORTED (mobile, /collections/sunscreens/, on the Lane PS
 * preview while reproducing the owner's 5 Oct PageSpeed report across the shop):
 * heading-order failing on `div.fpanel > div#filters > div.fgroup > h4` -- the
 * filter panel's facet titles are <h4> straight after the page's <h1>, so a
 * screen reader announces "heading level 4" with no level 2 or 3 above it.
 *
 * aria-level="2" corrects the announced level and leaves the tag -- and with
 * it `.fgroup h4` in kbb-shop.css -- exactly as it was, so nothing moves.
 *
 * MUTATION NOTE: drop aria-level="2" from any facet title in
 * store/shop.blade.php and this is red.
 */
it('announces every facet title as level 2, below the page\'s one h1', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $html = (string) $this->get('/shop')->assertOk()->getContent();

    preg_match_all('#<div class="fgroup"><h4([^>]*)>#', $html, $m);
    expect($m[1])->not->toBeEmpty('the filter panel drew no facet titles');
    foreach ($m[1] as $attrs) {
        expect($attrs)->toBe(' aria-level="2"');
    }

    expect(strpos($html, '<h1'))->toBeLessThan(strpos($html, '<div class="fgroup"><h4'));
});
