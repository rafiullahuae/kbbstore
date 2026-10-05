<?php

declare(strict_types=1);

/**
 * THE BRAND BANNER COVERS THE PHONE HEADER.                        (2.60.387)
 *
 * THE OWNER, 5 October: "in mobile brand page, also the background image
 * should cover the whole header area, instead of repeating horizontal or
 * vertical."
 *
 * WHAT THE SHOP LOOKED LIKE. Category header → "Show the whole picture on
 * phones" (2.60.358, on) applied to brand pages too: on a phone the banner was
 * shrunk to fit (object-fit:contain) and the room around it filled with a
 * blurred copy of the same file (.kbb-th__fill). On a wide brand banner that
 * copy reads as the picture repeated above and below it.
 *
 * NOW: Appearance → Site layout → Brand page → "Picture covers the header on
 * phones", ON as he asked. A brand page draws no .kbb-th--pw and no blurred
 * copy, so the banner fills the header edge to edge (the cropped frame).
 * Categories keep their own switch, untouched.
 *
 * MUTATIONS, each red here:
 *   · `&& ! ($brand && ...)` dropped from TitleHeader      → "covers"
 *   · brand_phone_cover default back to false              → "covers"
 *   · the category branch made to read brand_phone_cover   → "categories keep"
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;

function bpcSet(string $key, string $value): void
{
    app(SettingsService::class)->set($key, $value);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function bpcBrand(): Brand
{
    $brand = Brand::create(['name' => 'Covera', 'slug' => 'covera', 'header_image' => '/uploads/bpc/banner.jpg']);
    Product::create([
        'slug' => 'bpc-prod', 'name' => 'BPC Toner', 'brand_id' => $brand->id,
        'price' => 1000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    return $brand;
}

it('covers the phone header with the brand banner: no shrunk picture, no blurred copy', function () {
    bpcBrand();

    $html = (string) $this->get('/brands/covera/')->assertOk()->getContent();

    expect($html)->toMatch('#<section class="kbb-th kbb-th--img[^"]*"#')
        ->and($html)->toContain('class="kbb-th__img" src="/uploads/bpc/banner.jpg"')
        ->and($html)->not->toContain('kbb-th--pw')
        ->and($html)->not->toContain('kbb-th__fill');

    // The switch takes it back: the whole picture with its blurred copy.
    bpcSet('layout_brand_phone_cover', '0');
    $back = (string) $this->get('/brands/covera/')->getContent();
    expect($back)->toMatch('#<section class="kbb-th kbb-th--img[^"]* kbb-th--pw[ "]#')
        ->and($back)->toContain('<img class="kbb-th__fill" src="/uploads/bpc/banner.jpg"');
});

it('leaves category headers on their own whole-picture switch', function () {
    $c = Category::query()->create(['name' => 'Sunscreens', 'slug' => 'bpc-sun', 'parent_id' => null, 'header_image' => '/uploads/bpc/cat.jpg']);
    $c->forceFill(['path' => $c->slug, 'depth' => 0])->save();

    expect((string) $this->get('/collections/bpc-sun/')->getContent())->toContain('kbb-th--pw');
});

it('puts the switch on the Brand page tab, shipped on', function () {
    expect(\App\Services\SiteLayout::BRAND_KEYS)->toContain('brand_phone_cover')
        ->and(\App\Services\SiteLayout::SCHEMA['brand_phone_cover'][2])->toBeTrue()
        ->and(\App\Services\SiteLayout::SCHEMA['brand_phone_cover'][1])->toBe('Picture covers the header on phones');
});
