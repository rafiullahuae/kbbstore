<?php

declare(strict_types=1);

/**
 * Quick view: off on the shop, and in the middle of the photograph when on.
 *                                                    (Integrator, 2.60.354)
 *
 * THE DEFECT, ON THE LIVE SHOP (2 October 2026). The owner, with a screenshot
 * of "Big savings bundles": "turn off the quick view option by default, it's
 * coming on top of the product and hiding at top, it should be center -
 * middle." The pill sat across the TOP edge of each card, half of it cut off.
 *
 * The cause: kbb.css `.kbb-tile .qv-btn{position:relative}` (0,2,0, written to
 * lift the controls over the stretched link) beat layouts/store.blade.php's
 * `.qv-btn{position:absolute}` (0,1,0). Measured in Chromium at 1280 on
 * hover, before: on 29 of the 33 card templates the pill's centre was 111px
 * ABOVE the photograph's centre and outside the photograph; after: 0px on all
 * 33, Showcase included.
 *
 * MUTATIONS, RUN:
 *   - delete the `.kbb-tile .kbb-card-thumb > .qv-btn{position:absolute;...}`
 *     rule from kbb.css: the first case is red;
 *   - delete the two Showcase lines after it: the first case is red;
 *   - drop `! app()->runningUnitTests() &&` from the migration and rerun it
 *     normally: the last expectation of the second case is red (the suite's
 *     cards lose their button);
 *   - write `true` in the migration: the second case is red.
 */

use Illuminate\Support\Facades\DB;

it('centres the quick view pill on the photograph, Showcase included', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect($css)->toContain('.kbb-tile .kbb-card-thumb > .qv-btn{position:absolute;inset:auto;top:50%;left:50%;margin:0;align-self:auto;justify-self:auto;transform:translate(-50%,calc(-50% + 6px))}')
        ->and($css)->toContain(".kbb-tile:hover .kbb-card-thumb > .qv-btn,\n.kbb-tile .kbb-card-thumb > .qv-btn:focus-visible{transform:translate(-50%,-50%)}")
        ->and($css)->toContain('.kbb-pgrid[data-skin^="showcase"] .kbb-tile .kbb-card-thumb > .qv-btn{transform:translate(-50%,calc(-50% + 6px))}')
        ->and($css)->toContain('.kbb-pgrid[data-skin^="showcase"] .kbb-tile:hover .kbb-card-thumb > .qv-btn,');

    // And it comes AFTER the rule that made it relative, or it loses the tie.
    expect(strpos($css, '.kbb-tile .kbb-card-thumb > .qv-btn{position:absolute'))
        ->toBeGreaterThan(strpos($css, '.kbb-tile .qv-btn,.kbb-tile .heart,.kbb-tile .kbb-card-cart{position:relative;z-index:2}'));

    // The card still puts the button inside the photograph's frame.
    $card = (string) file_get_contents(resource_path('views/components/product-card.blade.php'));
    expect($card)->toMatch('#<div class="kbb-card-thumb">.*?<button class="qv-btn"[^>]*>.*?</div>\s*<div class="cb">#s');
});

it('switches Quick view off on the shop, and only on the shop', function () {
    $migration = require database_path('migrations/2027_07_17_000100_quick_view_off.php');

    $asShop = function () use ($migration): void {
        $env = app()['env'];
        app()['env'] = 'production';
        try {
            $migration->up();
        } finally {
            app()['env'] = $env;
        }
    };

    DB::table('module_toggles')->updateOrInsert(['module' => 'quick_view'], ['enabled' => true]);
    $asShop();
    expect((bool) DB::table('module_toggles')->where('module', 'quick_view')->value('enabled'))->toBeFalse()
        ->and(app(\App\Services\SettingsService::class)->moduleEnabled('quick_view', true))->toBeFalse();

    // A shop with no row at all gets one, off.
    DB::table('module_toggles')->where('module', 'quick_view')->delete();
    $asShop();
    expect(DB::table('module_toggles')->where('module', 'quick_view')->value('enabled'))->not->toBeNull()
        ->and((bool) DB::table('module_toggles')->where('module', 'quick_view')->value('enabled'))->toBeFalse();

    // Run as the suite runs it: nothing written.
    DB::table('module_toggles')->where('module', 'quick_view')->delete();
    $migration->up();
    expect(DB::table('module_toggles')->where('module', 'quick_view')->exists())->toBeFalse();
});
