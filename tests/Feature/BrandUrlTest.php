<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use Tests\Support\Phase9Routes;

/**
 * Brands at the address the scheme settled on: /brands/ for the directory and
 * /brands/{slug}/ for a brand's own page — a listing page, so the address is
 * plural and short. /korean-skincare-brands/, /korean-skincare-brands/{slug}/
 * and /brand/{slug}/ are the 301s, each one hop onto its final home.
 *
 * Fixtures use slugs prefixed "t-" so they cannot collide with the demo
 * catalogue a migration seeds, and so the first test in the process — which
 * RefreshDatabase does not isolate, see the note in tests/Pest.php — cannot
 * leave a row that makes a later test fail.
 */
beforeEach(function () {
    Phase9Routes::wire($this->app);

    $this->brand = Brand::create([
        'slug' => 't-beauty-of-joseon',
        'name' => 'T Beauty of Joseon',
        'description' => 'Hanbang formulas, modern textures.',
    ]);
});

it('serves the brand directory at /brands/', function () {
    /*
     * ▲ 'All brands' NAMES THE DIRECTORY'S <h1> NOW. (Lane PLC)
     *
     * Bare, it was also the <title>, the og:title meta and the JSON-LD name of
     * this page, so it could not tell a directory that had rendered its heading
     * from one that had rendered only its <head>. The tile name beside it was
     * already anchored — this brings the heading up to it.
     *
     * MUTATION, run: blank both `<h1 class="brw-h1">` in store/brands.blade.php
     * and this file plus UrlSchemeTest were 25 passed / 0 failed. With the
     * needles here and at the case below, red.
     */
    $this->get('/brands/')
        ->assertOk()
        ->assertSee('<h1 class="brw-h1">All brands</h1>', escape: false)
        ->assertSee('<span class="brw-name">T Beauty of Joseon', escape: false);
});

it('serves a single brand page', function () {
    $this->get('/brands/t-beauty-of-joseon/')
        ->assertOk()
        // The brand's own heading, not its <title>, its og:title, its CTA label
        // or the JSON-LD Brand `name` — this page carries the words in all five.
        // Lane BR2: the Panel header's <h1>, the default brand header.
        ->assertSee('<h1 class="brw-ph__name" id="brw-ph-title">T Beauty of Joseon</h1>', escape: false)
        // The standfirst the page prints, not the <meta description> and the
        // JSON-LD carrying the same sentence in the same document.
        // 2.60.376: through RichText's allowlist, in a block that can hold paragraphs.
        ->assertSee('<div class="brw-ph__desc brw-desc">Hanbang formulas, modern textures.</div>', escape: false);
});

it('links each directory tile at the brand page, not the old address', function () {
    $this->get('/brands/')
        ->assertOk()
        ->assertSee('/brands/t-beauty-of-joseon/', escape: false);
});

it('keeps the brand product listing on /shop per URL contract U-05', function () {
    // The landing page must link onward to the filtered listing rather than
    // reimplement it. Brand::filterUrl() is that contract and is unchanged --
    // U-05 kept the query string exactly where it was. What moved is
    // Brand::url(), which is now this landing page rather than the listing.
    // 2.60.376: the owner asked for the "Shop all" button gone, so the link is
    // there only with Appearance → Site layout → Brand page → "Show the Shop
    // all button" on -- and when it is, it is still the U-05 address.
    $this->get('/brands/t-beauty-of-joseon/')
        ->assertOk()
        ->assertDontSee('class="brw-cta"', escape: false);

    app(\App\Services\SiteLayout::class)->save(['brand_cta' => true]);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    $this->get('/brands/t-beauty-of-joseon/')
        ->assertOk()
        ->assertSee('/shop/?filter_brands=t-beauty-of-joseon', escape: false);
});

it('shows the brand\'s visible products and hides the rest', function () {
    Product::create([
        'slug' => 't-glow-serum', 'name' => 'T Glow Serum',
        'brand_id' => $this->brand->id, 'status' => 'publish',
        'is_visible' => true, 'price' => 12600,
    ]);

    Product::create([
        'slug' => 't-draft-serum', 'name' => 'T Draft Serum',
        'brand_id' => $this->brand->id, 'status' => 'draft',
        'is_visible' => true, 'price' => 9900,
    ]);

    $this->get('/brands/t-beauty-of-joseon/')
        ->assertOk()
        /*
         * ▲ The product name in a listing is drawn in `<span class="kbb-card-nm">`
     * and ALSO in the add-to-basket link's `data-name`, the tile image's `alt`
     * and the JSON-LD ItemList — so a bare needle here could not tell a grid
     * that had rendered its names from one that had rendered none of them.
     *
     * MUTATION, run: blank `<span class="kbb-card-nm">` in
     * components/product-card.blade.php, so every product card in the shop
     * carries no name, and this case was green. With the needle below it is
     * red. (Lane PLC; the same run settled BrandUrlTest, CategoryPathWalkCost-
     * Test, DeadCategoryViewTest and ConcernCollectionsTest together.)
         */
        ->assertSee('<span class="kbb-card-nm">T Glow Serum', escape: false)
        ->assertDontSee('T Draft Serum');
});

it('301s the retired /korean-skincare-brands/ index to the directory', function () {
    /*
     * The address scheme swapped which way round this pair goes. It used to
     * read "301s the old /brands/ index to the new address" and asserted a 301
     * from /brands/ — which is the directory itself now, so the assertion could
     * only have been made green by unmounting the page. Advanced deliberately,
     * with the old sentence quoted.
     *
     * MUTATION NOTE. Point BrandController::legacyIndex() back at
     * Url::redirect('/korean-skincare-brands/') and this is red on the Location.
     */
    $response = $this->get('/korean-skincare-brands/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/brands/');
});

it('301s the retired per-brand address to the brand page', function () {
    $response = $this->get('/korean-skincare-brands/t-beauty-of-joseon/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/brands/t-beauty-of-joseon/');
});

it('301s the mega menu\'s /brand/{slug}/ leaf to the brand page', function () {
    $response = $this->get('/brand/t-beauty-of-joseon/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/brands/t-beauty-of-joseon/');
});

it('404s an unknown brand rather than landing the shopper somewhere plausible', function () {
    $this->get('/brands/t-not-a-brand/')->assertNotFound();
    $this->get('/brand/t-not-a-brand/')->assertNotFound();
    $this->get('/korean-skincare-brands/t-not-a-brand/')->assertNotFound();
});

it('follows the old brand URLs through to a 200', function () {
    // A 301 into a 404 is worse than a 404: it costs a round trip and tells a
    // crawler the page moved when it did not.
    $this->followingRedirects()->get('/korean-skincare-brands/')->assertOk();
    $this->followingRedirects()->get('/korean-skincare-brands/t-beauty-of-joseon/')->assertOk();
    $this->followingRedirects()->get('/brand/t-beauty-of-joseon/')->assertOk();
});
