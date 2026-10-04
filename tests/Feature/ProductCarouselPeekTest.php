<?php

declare(strict_types=1);

/*
 * "YOU MAY ALSO LIKE" SHOWS 2.3 CARDS ON A PHONE.                    (Lane PX)
 *
 * The owner: "on product page, i want the same 2.3 cards to display by
 * default" — the homepage carousels' treatment (2.60.373).
 *
 * THE DEFECT, AS IT LOOKED ON THE SHOP: two whole cards filled a 390px screen
 * and the third began in the 22px gutter, so the row read as a grid of two
 * rather than something to swipe. Measured in Chromium after: cards 168px ->
 * 144.8px wide at 390 (2.4 in view counting the gutter sliver), and at 1280
 * still 232.8px, five in view, arrows as before (docs/lane-px-shots/*-ymal-*).
 *
 * Appearance → Product page → You may also like → Cards in view on a phone
 * (2.3, the homepage's option set) and Arrows on a phone (off); Cards in view
 * on a laptop stays 5.
 */

use App\Models\Product;
use App\Models\Setting;
use App\Services\AlsoLikeSettings;
use App\Services\HomepageContent;
use App\Services\SettingsService;

function pxYmPage(): string
{
    foreach (range(1, 5) as $i) {
        Product::create([
            'slug' => 'px-ym-'.$i, 'name' => 'PX Rail '.$i, 'status' => 'publish', 'is_visible' => true,
            'type' => 'simple', 'price' => 5000 + $i, 'stock_status' => 'instock',
        ]);
    }
    app(AlsoLikeSettings::class)->save(['rule' => 'newest']);
    SettingsService::forgetMemo();
    Setting::flushMap();
    app()->forgetScopedInstances();

    return (string) test()->get('/product/px-ym-1/')->assertOk()->getContent();
}

it('ships 2.3 on a phone, 5 on a laptop, no phone arrows', function () {
    $d = AlsoLikeSettings::defaults();
    expect($d['per_phone'])->toBe('2.3')
        ->and($d['per_desktop'])->toBe('5')
        ->and($d['arrows_m'])->toBeFalse()
        ->and(AlsoLikeSettings::TABS['ymal'][2])->toContain('per_phone', 'per_desktop', 'arrows_m');

    // The homepage's own choices, so the two screens offer the same thing.
    $home = HomepageContent::SCHEMA['home_hb_per_m']['options'];
    foreach (array_keys($home) as $k) {
        expect(AlsoLikeSettings::SCHEMA['per_phone'][4])->toHaveKey($k);
    }

    $html = pxYmPage();
    expect($html)->toContain('style="--ymal-d:5;--ymal-m:2.3"')
        ->and($html)->not->toContain('ymal-arr-m');
    // MUTATION NOTE — RUN: put `(int)` back on $ymalM in
    // partials/you-may-also-like.blade.php and this prints --ymal-m:2.
});

it('prints only one of its own options into the style attribute (rule 5)', function () {
    // A row written behind the cast's back is still not printed.
    Setting::query()->updateOrCreate(['key' => AlsoLikeSettings::PREFIX.'per_phone'], ['value' => '2;}body{display:none', 'autoload' => true]);

    expect(pxYmPage())->toContain('style="--ymal-d:5;--ymal-m:2.3"')
        ->and(app(AlsoLikeSettings::class)->all()['per_phone'])->toBe('2.3');
});

it('puts the arrows on a phone when asked, and the desktop rule is untouched', function () {
    app(AlsoLikeSettings::class)->save(['arrows_m' => true, 'per_phone' => '2.5']);

    $html = pxYmPage();
    expect($html)->toContain('<section class="sec ymal ymal-arr-m ')
        ->and($html)->toContain('--ymal-m:2.5"');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    expect($css)->toContain('.ymal-arr-m .ymal-nav{display:flex;')
        // The laptop track is the rule it was.
        ->and($css)->toContain("@media(min-width:901px){\n  .rel.ymal-track{\n    grid-auto-columns:calc((100% - (var(--ymal-d,5) - 1) * var(--kbb-gap)) / var(--ymal-d,5));");
});
