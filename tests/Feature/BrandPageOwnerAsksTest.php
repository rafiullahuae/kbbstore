<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Services\SiteLayout;

/*
 * The brand page, 2.60.376. The owner, 4 October:
 *
 *   "for Brand Page, remove the Shop all button, and keep Name, along with
 *    description (we have option to add description but that text is not
 *    showing under the brand name), remove also popular right now, and view
 *    all. as the brand page visit will give full results without any
 *    pagination etc."
 *
 * Each ask ships ON because he asked; Appearance → Site layout → Brand page
 * has a switch that puts each one back.
 */

function bpBrand(array $extra = []): Brand
{
    return Brand::create(array_merge(['name' => 'Glowtest', 'slug' => 'glowtest'], $extra));
}

function bpProducts(Brand $brand, int $n): void
{
    for ($i = 1; $i <= $n; $i++) {
        Product::create([
            'slug' => "bp-prod-{$i}", 'name' => "BP Product {$i}", 'brand_id' => $brand->id,
            'price' => 1000 + $i, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
        ]);
    }
}

function bpSave(array $values): void
{
    app(SiteLayout::class)->save($values);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
}

it('shows the name and the description, with no Shop all button and no Popular right now / View all', function () {
    /*
     * The defect on the shop: a pink "Shop all <brand>" button under the name,
     * and "Popular right now" + "View all" over the grid. MUTATION: ship
     * brand_cta or brand_popular at true and the matching line is red.
     */
    $brand = bpBrand(['description' => 'Glowtest makes gentle cleansers.']);
    bpProducts($brand, 3);

    $html = $this->get('/brands/glowtest/')->assertOk()->getContent();

    expect($html)->toContain('<h1 class="brw-h1">Glowtest</h1>')
        ->and($html)->toContain('Glowtest makes gentle cleansers.')
        ->and($html)->not->toContain('class="brw-cta"')
        ->and($html)->not->toContain(__('store.brands.popular_heading'))
        ->and($html)->not->toContain('>'.__('store.product_grid.view_all').'</a>');
});

it('prints the description typed on the brand page itself, which used to be dropped', function () {
    /*
     * THE DEFECT: "we have option to add description but that text is not
     * showing under the brand name". The description typed on the brand page
     * (quick edit → Description) is stored in header_description and was only
     * printed inside a title header; a brand with no header picture printed
     * brands.description alone and dropped it. MUTATION: put
     * `strip_tags($brand->t('description'))` back in the hero and this is red.
     */
    $brand = bpBrand(['header_description' => '<p>Typed on the page <b>itself</b>.</p>']);
    bpProducts($brand, 1);

    $html = $this->get('/brands/glowtest/')->assertOk()->getContent();

    expect($html)->toContain('<div class="brw-sub brw-desc"><p>Typed on the page <b>itself</b>.</p></div>');
});

it('passes the description through the allowlist, so a script typed into it never reaches the page', function () {
    $brand = bpBrand(['description' => '<p>Safe</p><script>alert(1)</script><img src=x onerror=alert(2)>']);
    bpProducts($brand, 1);

    $html = $this->get('/brands/glowtest/')->assertOk()->getContent();

    expect($html)->toContain('Safe')
        ->and($html)->not->toContain('<script>alert(1)')
        ->and($html)->not->toContain('onerror=alert(2)');
});

it('lists every product of the brand on one page, with no pager', function () {
    /*
     * "the brand page visit will give full results without any pagination".
     * Before: 12, then a pager. MUTATION: make brandPerPage() return
     * perPage($arrows) and only twelve tiles come back, with rel="next".
     */
    $brand = bpBrand();
    bpProducts($brand, 30);

    $html = $this->get('/brands/glowtest/')->assertOk()->getContent();

    expect(substr_count($html, 'BP Product '))->toBeGreaterThanOrEqual(30)
        ->and(preg_match_all('#href="[^"]*/product/bp-prod-\d+/#', $html, $m))->toBeGreaterThanOrEqual(30)
        ->and($html)->not->toContain('?paged=2');
});

it('puts each of the three back when its switch is turned the other way', function () {
    $brand = bpBrand(['description' => 'Desc']);
    bpProducts($brand, 30);
    bpSave(['brand_cta' => true, 'brand_popular' => true, 'brand_all' => false]);

    $html = $this->get('/brands/glowtest/')->assertOk()->getContent();

    expect($html)->toContain('class="brw-cta"')
        ->and($html)->toContain(__('store.brands.popular_heading'))
        ->and($html)->toContain('?paged=2');
});

it('keeps the brand page flat in queries however many products the brand has', function () {
    /*
     * Every product at once must not mean a query per product. Same budget
     * as a brand with three products.
     */
    $brand = bpBrand();
    bpProducts($brand, 3);
    $this->get('/brands/glowtest/')->assertOk();

    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->get('/brands/glowtest/')->assertOk();
    $small = count(\Illuminate\Support\Facades\DB::getQueryLog());

    bpProducts2($brand, 40);
    \Illuminate\Support\Facades\DB::flushQueryLog();
    $this->get('/brands/glowtest/')->assertOk();
    $big = count(\Illuminate\Support\Facades\DB::getQueryLog());

    expect($big)->toBe($small);
});

function bpProducts2(Brand $brand, int $n): void
{
    for ($i = 1; $i <= $n; $i++) {
        Product::create([
            'slug' => "bp-more-{$i}", 'name' => "BP More {$i}", 'brand_id' => $brand->id,
            'price' => 2000 + $i, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
        ]);
    }
}

it('puts the three switches on their own tab, saved by the screen that owns the schema', function () {
    expect(SiteLayout::TABS['brandpage'][2])->toBe(['brand_all', 'brand_cta', 'brand_popular', 'brand_hero', 'brand_ring'])
        ->and(SiteLayout::SCHEMA['brand_all'][2])->toBeTrue()
        ->and(SiteLayout::SCHEMA['brand_cta'][2])->toBeFalse()
        ->and(SiteLayout::SCHEMA['brand_popular'][2])->toBeFalse();
});

it('sends an old ?paged=2 address to page 1 for good, instead of a 404', function () {
    /*
     * Every product is on page 1 now, so /brands/x/?paged=2 has nothing on it.
     * Crawlers and bookmarks hold those addresses; a 404 throws them away.
     * MUTATION: drop the redirect in BrandController::show() and this is 404.
     */
    $brand = bpBrand();
    bpProducts($brand, 30);

    $this->get('/brands/glowtest/?paged=2')->assertStatus(301)->assertRedirect('/brands/glowtest/');
});
