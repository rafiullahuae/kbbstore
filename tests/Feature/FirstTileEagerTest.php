<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Services\SettingsService;

/**
 * Lane PS -- the first product tile on /super-sale/ and on a brand page was
 * lazy-loaded although it is the page's Largest Contentful Paint.
 *
 * WHAT LIGHTHOUSE REPORTED (the 5 Oct PageSpeed work, run on the Lane PS preview
 * against the pages the owner's report did not cover): "LCP request discovery"
 * failing on /super-sale/ and /brands/anua/ with
 *
 *   ✗ fetchpriority=high should be applied
 *   ✗ LCP resources should not use loading=lazy
 *   div.kbb-card > div.kbb-card-thumb > a.kbb-card-shot > img.kbb-card-img
 *   <img class="kbb-card-img" … loading="lazy" decoding="async">
 *
 * /shop and every category already pass `:eager="$loop->first"`; the curated
 * collections (partials/home/grid) and the brand grid (x-product-grid) did not.
 * Only the FIRST tile changes, and only its loading attributes; every other
 * tile and every other caller of those two partials prints what it printed.
 *
 * MUTATION NOTE: drop `'eagerFirst' => …` from store/collection.blade.php (or
 * `:eager-first` from store/brands.blade.php) and its case is red.
 */
function fteImgs(string $uri): array
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
    $html = (string) test()->get($uri)->assertOk()->getContent();
    preg_match_all('/<img class="kbb-card-img"[^>]*>/', $html, $m);

    return $m[0];
}

beforeEach(function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $brand = Brand::firstOrCreate(['slug' => 'fte-house'], ['name' => 'Fte House']);
    foreach (range(1, 4) as $i) {
        Product::create([
            'slug' => 'fte-'.$i, 'name' => 'Fte Serum '.$i, 'status' => 'publish', 'is_visible' => true,
            'brand_id' => $brand->id, 'price' => 6000, 'sale_price' => 3000, 'stock_status' => 'instock',
            'type' => 'simple', 'rating' => 0.0, 'review_count' => 0, 'image' => '/uploads/fte/'.$i.'.webp',
        ]);
    }
});

// ▲ Lane LZ: "every other tile lazily" became "the rest of the FIRST ROW eager
// at the browser's own priority, everything after it lazy" -- the row beside the
// first tile is on screen and was painting late (AboveFoldImagesTest). The
// first tile alone still claims high priority, which is what this file pins.
it('loads the first Super Sale tile eagerly at high priority and no other tile at high priority', function () {
    $imgs = fteImgs('/super-sale/');
    $row = app(\App\Services\SiteLayout::class)->aboveFoldCards();

    expect(count($imgs))->toBeGreaterThan(1, 'the page drew fewer than two tiles');
    expect($imgs[0])->toContain('loading="eager" fetchpriority="high"');
    foreach (array_slice($imgs, 1) as $i => $img) {
        expect($img)->toContain($i + 1 < $row ? 'loading="eager"' : 'loading="lazy"')->and($img)->not->toContain('fetchpriority');
    }
});

it('loads the first tile of a brand page eagerly at high priority and no other tile at high priority', function () {
    $imgs = fteImgs('/brands/fte-house/');
    $row = app(\App\Services\SiteLayout::class)->aboveFoldCards();

    expect(count($imgs))->toBe(4);
    expect($imgs[0])->toContain('loading="eager" fetchpriority="high"');
    foreach (array_slice($imgs, 1) as $i => $img) {
        expect($img)->toContain($i + 1 < $row ? 'loading="eager"' : 'loading="lazy"')->and($img)->not->toContain('fetchpriority');
    }
});

it('leaves every homepage rail lazy, as it was', function () {
    foreach (fteImgs('/') as $img) {
        expect($img)->not->toContain('fetchpriority');
    }
});
