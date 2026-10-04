<?php

declare(strict_types=1);

/*
 * THE BRAND NAME ON A LIGHT CAPSULE.                                 (Lane PX)
 *
 * The owner, arrow on "SKIN 1004" above a product title: "The bran name should
 * have light background color like capsule with less border radius. but make
 * less height, just put background light color."
 *
 * THE DEFECT, AS IT LOOKED ON THE SHOP: the brand line was bare pink capitals,
 * the full width of the column, with nothing to set it apart from the title
 * under it. Measured after, in Chromium: a 51px-wide, 22px-tall tint (was an
 * 18px line) with 5px corners, the text its own pink and 12px as before.
 *
 * Appearance → Product page → Type · Buy column → Brand name background (On),
 * Brand name background colour (#FCE8EE), Brand name corner radius (5px).
 * kbb-product.css draws the capsule from those three with the SAME values as
 * fallbacks, so at the defaults the page prints no <style> for them and its
 * markup is byte-identical.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ProductLayout;
use App\Services\SettingsService;

function pxBcFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    app()->forgetScopedInstances();
}

function pxBcPage(): string
{
    $brand = Brand::firstOrCreate(['slug' => 'px-skin'], ['name' => 'SKIN 1004']);
    $p = Product::create([
        'slug' => 'px-bc-'.uniqid(), 'name' => 'PX Sun Serum', 'status' => 'publish', 'is_visible' => true,
        'brand_id' => $brand->id, 'price' => 6800, 'stock_status' => 'instock',
    ]);
    pxBcFresh();

    return (string) test()->get('/product/'.$p->slug)->assertOk()->getContent();
}

it('ships the capsule on, from the stylesheet, with fallbacks equal to the defaults', function () {
    expect(ProductLayout::SCHEMA['brand_cap'][2])->toBe('1')
        ->and(ProductLayout::SCHEMA['brand_bg'][2])->toBe('#FCE8EE')
        ->and(ProductLayout::SCHEMA['brand_r'][2])->toBe(5)
        ->and(ProductLayout::TABS['ty_buy'][2])->toContain('brand_cap', 'brand_bg', 'brand_r');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    $rule = '.pdp .bb-brand{width:fit-content;max-width:100%;padding:calc(var(--pl-brand-cap,1) * 2px) calc(var(--pl-brand-cap,1) * 7px);border-radius:var(--pl-brand-r,5px);background:linear-gradient(var(--pl-brand-bg,#FCE8EE),var(--pl-brand-bg,#FCE8EE)) 0 0/calc(var(--pl-brand-cap,1) * 100%) 100% no-repeat}';
    expect(substr_count($css, $rule))->toBe(1);

    // Defaults: no <style> at all — the page is the one that shipped, and the
    // capsule comes from the fallbacks above.
    $html = pxBcPage();
    expect($html)->toContain('<div class="bb-brand" id="bbBrand">')
        ->and($html)->not->toContain('id="kbb-pdp-layout"');
    // MUTATION NOTE — RUN: change the fallback in the rule to #FFFFFF and the
    // substr_count is 0; ProductPageLayoutTest's fallback-equals-default walk
    // goes red on --pl-brand-bg as well.
});

it('off zeroes the tint and the padding; a colour and a radius reach the page', function () {
    app(ProductLayout::class)->save(['brand_cap' => '0', 'brand_bg' => '#EAF4FF', 'brand_r' => 9]);

    $html = pxBcPage();
    preg_match('#<style id="kbb-pdp-layout">(.*?)</style>#s', $html, $m);

    expect($m[1] ?? '')->toContain('--pl-brand-cap:0;')
        ->and($m[1])->toContain('--pl-brand-bg:#EAF4FF;')
        ->and($m[1])->toContain('--pl-brand-r:9px');
});

it('cannot be talked out of its declaration (rule 5)', function () {
    app(ProductLayout::class)->save(['brand_bg' => 'red;}body{display:none', 'brand_r' => 999, 'brand_cap' => 'x']);

    $c = app(ProductLayout::class)->all();
    $vars = ProductLayout::vars($c);

    expect($vars['--pl-brand-bg'])->toBe('#FCE8EE')   // refused → default
        ->and($vars['--pl-brand-r'])->toBe('12px')     // clamped to its max
        ->and($vars['--pl-brand-cap'])->toBe('1');     // not an option → default
});
