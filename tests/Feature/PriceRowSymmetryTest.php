<?php

declare(strict_types=1);

use App\Services\ProductStyles;
use App\Services\SettingsService;

/*
 * 2.60.380. The owner, with the homepage's Big savings bundles on a phone:
 * "i want the price row must be same position, even if reviews are there or
 * not. i need semetry. also give control to set the pricing font size, and cut
 * price also. on the same product grid backend setting page. need seperate
 * controls for desktop and mobile same as we have already."
 *
 * Measured before (Chromium, every non-Showcase skin in the bundles carousel):
 * an unreviewed card's price sat 19px above a reviewed one's at 390 and 1280.
 * After: one value per row in every stacking skin, Showcase unchanged.
 */

const PRS_RULE = 'body:not(.pc-norate) .kbb-pgrid:not([data-skin="horizontal"]):not([data-skin="overlay"]):not([data-skin="glass"]):not([data-skin="reveal"]):not([data-skin="pricetag"]):not([data-skin^="showcase"]) .kbb-tile .cn:has(+ .cp){margin-bottom:calc(19px + var(--sc-rate-mt,4px))}';
const PRS_RATE = '.kbb-pgrid:not([data-skin="horizontal"]):not([data-skin="overlay"]):not([data-skin="glass"]):not([data-skin="reveal"]):not([data-skin="pricetag"]):not([data-skin^="showcase"]) .kbb-tile .kbb-card-rate{height:19px}';

it('keeps the star row\'s footprint under the name of an unreviewed card, in both stylesheets', function () {
    /*
     * The defect: no star row, no space -- the price rode up 19px. kbb.css is
     * the sheet every page loads; kbb-grid-skins.css is its copy, held equal by
     * GridSkinCopiesTest. MUTATION: delete the rule from kbb.css and the first
     * line is red; change 19px in one copy only and GridSkinCopiesTest is.
     */
    foreach (['kbb.css', 'kbb-grid-skins.css'] as $sheet) {
        $css = (string) file_get_contents(resource_path('css/kbb/'.$sheet));

        expect($css)->toContain(PRS_RULE)
            ->and($css)->toContain(PRS_RATE);
    }
});

it('reserves nothing when the stars are switched off, and leaves Showcase to its own slot', function () {
    /*
     * "Stars and review count" off prints no star row on ANY card; reserving
     * then would add an empty 23px band to every card. MUTATION: drop
     * `body:not(.pc-norate)` and this is red.
     */
    expect(PRS_RULE)->toStartWith('body:not(.pc-norate) ')
        ->and(PRS_RULE)->toContain(':not([data-skin^="showcase"])')
        ->and(PRS_RULE)->toContain(':not([data-skin="pricetag"])');
});

it('sizes the cut price from its own phone and desktop controls, and prints nothing at the default', function () {
    /*
     * The cut price had no control at all. The rule doubles .kbb-pgrid
     * because Showcase shrinks the cut price on a narrow phone with a :has()
     * selector worth five classes. MUTATION: write the rule as
     * `.kbb-pgrid[data-skin] .kbb-tile .kbb-card-reg` and a 360px Showcase
     * phone keeps its own size -- the selector line below is red.
     */
    expect(app(ProductStyles::class)->cardCss())->toBe('');

    app(SettingsService::class)->set('card_fs_reg_m', '10px');
    app(SettingsService::class)->set('card_fs_reg_d', '14px');
    // The styles and the settings map are memoised per process.
    app()->forgetInstance(ProductStyles::class);
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();

    $css = app(ProductStyles::class)->cardCss();

    expect($css)->toContain('.kbb-pgrid.kbb-pgrid[data-skin] .kbb-tile .cp .kbb-card-reg{font-size:10px}')
        ->and($css)->toContain('.kbb-pgrid.kbb-pgrid[data-skin] .kbb-tile .cp .kbb-card-reg{font-size:14px}');
});

it('puts Price and Cut price, phone and desktop, on Appearance → Product grid -- the same settings, not copies', function () {
    /*
     * "on the same product grid backend setting page". MUTATION: drop the
     * card_fs_reg row from PG_CARD_TYPE and this is red; save them anywhere
     * but /admin-api/product-styles and the last line is.
     */
    $html = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(preg_match('/const PG_CARD_TYPE = \[(.*?)\n\];/s', $html, $m))->toBe(1);
    preg_match_all("/'(card_fs_[a-z]+_[md])'/", $m[1], $keys);

    expect($keys[1])->toBe(['card_fs_price_m', 'card_fs_price_d', 'card_fs_reg_m', 'card_fs_reg_d']);

    foreach ($keys[1] as $key) {
        expect(ProductStyles::SCHEMA[$key][0] ?? null)->toBe('select', $key);
    }

    expect(substr_count($html, 'id="pgCardType"'))->toBe(1)
        ->and($html)->toContain("PG_CARD_TYPE.forEach(([m, d]) => [m, d].forEach(k => {");
});
