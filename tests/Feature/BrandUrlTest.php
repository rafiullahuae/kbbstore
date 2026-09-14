<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use Tests\Support\Phase9Routes;

/**
 * Brands at the address the owner settled on: /korean-skincare-brands/.
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

it('serves the brand directory at /korean-skincare-brands/', function () {
    $this->get('/korean-skincare-brands/')
        ->assertOk()
        ->assertSee('All brands')
        ->assertSee('T Beauty of Joseon');
});

it('serves a single brand page', function () {
    $this->get('/korean-skincare-brands/t-beauty-of-joseon/')
        ->assertOk()
        ->assertSee('T Beauty of Joseon')
        ->assertSee('Hanbang formulas, modern textures.');
});

it('links each directory tile at the brand page, not the old address', function () {
    $this->get('/korean-skincare-brands/')
        ->assertOk()
        ->assertSee('/korean-skincare-brands/t-beauty-of-joseon/', escape: false);
});

it('keeps the brand product listing on /shop per URL contract U-05', function () {
    // The landing page must link onward to the filtered listing rather than
    // reimplement it. Brand::url() is the contract and is unchanged.
    $this->get('/korean-skincare-brands/t-beauty-of-joseon/')
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

    $this->get('/korean-skincare-brands/t-beauty-of-joseon/')
        ->assertOk()
        ->assertSee('T Glow Serum')
        ->assertDontSee('T Draft Serum');
});

it('301s the old /brands/ index to the new address', function () {
    $response = $this->get('/brands/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/korean-skincare-brands/');
});

it('301s the mega menu\'s /brand/{slug}/ leaf to the brand page', function () {
    $response = $this->get('/brand/t-beauty-of-joseon/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/korean-skincare-brands/t-beauty-of-joseon/');
});

it('404s an unknown brand rather than landing the shopper somewhere plausible', function () {
    $this->get('/korean-skincare-brands/t-not-a-brand/')->assertNotFound();
    $this->get('/brand/t-not-a-brand/')->assertNotFound();
});

it('follows the old brand URLs through to a 200', function () {
    // A 301 into a 404 is worse than a 404: it costs a round trip and tells a
    // crawler the page moved when it did not.
    $this->followingRedirects()->get('/brands/')->assertOk();
    $this->followingRedirects()->get('/brand/t-beauty-of-joseon/')->assertOk();
});
