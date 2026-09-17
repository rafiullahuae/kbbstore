<?php

/**
 * What a shopper is actually shown by Growth & Marketing → Product Labels.
 *
 * ── WHY THIS FILE EXISTS
 *
 * Before it, the module had NO test of any kind: nothing in tests/ mentioned
 * ProductLabels or `product_labels`. A service, an admin screen, an admin API
 * and two storefront templates, and not one assertion between them. Silently
 * unhooking `app(ProductLabels::class)->for($product)` from
 * components/product-card.blade.php would have left the admin screen saving,
 * loading, previewing and reporting success with no badge on the storefront at
 * all — the exact shape of the four guards this repo found asserting nothing
 * while being counted as coverage.
 *
 * ── WHAT IS ASSERTED, AND WHAT DELIBERATELY IS NOT
 *
 * Every assertion is made against the BYTES OF A RENDERED PAGE, fetched over
 * the HTTP stack. A test that writes a setting and reads it back proves the
 * settings table works; it proves nothing whatever about a badge. So the shop
 * grid and the product page are both fetched, and the `<span class="lbl">` is
 * parsed out of each.
 *
 * Both surfaces are covered because the badge is placed twice — the grid tile
 * in components/product-card.blade.php and the gallery frame in
 * partials/product-gallery.blade.php — from one service. A regression in
 * either one is invisible from the other.
 *
 * ── THE BUG THIS FILE WAS WRITTEN AROUND
 *
 * ProductLabels::for() computed the sale percentage itself rather than asking
 * Product::discountPercent(), the method the price block on the very same card
 * already uses. Two copies of one rule, and they disagreed: a markdown that
 * rounds to nothing (AED 100.00 → AED 99.80) published a badge in the sale
 * colour reading "-0% OFF", on a card whose price block correctly showed no
 * saving. Reproduced on a running /shop before the fix. The theme's own badge
 * has always guarded it (`$off ? … : ''`); the module did not, so turning the
 * module ON introduced the lie. `it('never advertises a discount of nothing')`
 * is that case.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductLabels;
use App\Services\SettingsService;

/** Turn the module on or off the way the Modules screen does. */
function plModule(bool $on): void
{
    app(SettingsService::class)->setModule('product_labels', $on);
}

/** Save badge options the way ProductLabelsApiController::save() does. */
function plSave(array $values): void
{
    app(ProductLabels::class)->save($values);
}

/**
 * A visible product in a category, so it appears on /shop and has a page.
 *
 * `created_at` is pinned two years back by default: every "new" assertion in
 * this file must be about the configured window, and a freshly-created row is
 * new by accident. Carbon's created_at is honoured on insert, so it is written
 * explicitly rather than nudged afterwards.
 */
function plProduct(array $attributes = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'pl-roundlab'], ['name' => 'Round Lab']);
    $category = Category::firstOrCreate(
        ['slug' => 'pl-toners'],
        ['name' => 'Toners', 'path' => 'pl-toners']
    );

    $product = Product::create(array_merge([
        'slug' => 'pl-' . uniqid(),
        'name' => 'Heartleaf 77% Soothing Toner',
        'sku' => 'PL-' . strtoupper(uniqid()),
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'featured' => false,
        'created_at' => now()->subYears(2),
        'updated_at' => now()->subYears(2),
    ], $attributes));

    $product->categories()->syncWithoutDetaching([$category->id]);

    return $product;
}

/**
 * Every badge on a page, as [text => background].
 *
 * Both templates render `class="lbl"` with the colour inline, but the gallery
 * adds a module class in front of it and its own top/left, so the pattern is
 * written to match either shape rather than the grid's exact attribute order.
 */
function plBadges(string $html): array
{
    preg_match_all(
        '#<span class="[^"]*\blbl\b[^"]*"[^>]*style="[^"]*background:\s*([^;"]+)[^"]*"[^>]*>([^<]*)</span>#i',
        $html,
        $matches,
        PREG_SET_ORDER
    );

    $out = [];

    foreach ($matches as $m) {
        $out[html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8')] = strtoupper(trim($m[1]));
    }

    return $out;
}

/** The badges on the product's own page. */
function plOnProductPage(Product $product): array
{
    return plBadges(test()->get('/product/' . $product->slug)->assertOk()->getContent());
}

/** The badges on the shop grid. */
function plOnShop(): array
{
    return plBadges(test()->get('/shop')->assertOk()->getContent());
}

/* ─────────────────────────── the switch is real ─────────────────────────── */

it('shows no module badge at all while the module is off', function () {
    plModule(false);

    // On sale by 30%, so the THEME's own badge is what this page falls back to.
    $product = plProduct(['price' => 10000, 'sale_price' => 7000]);

    $badges = plOnProductPage($product);

    // The theme's fallback still runs — turning the module off must not strip
    // the storefront's own badge — but the module's configured text must not
    // appear anywhere.
    expect($badges)->not->toHaveKey('Sold out');

    plSave(['sale_text' => 'MODULE SALE {off}']);
    $product->refresh();

    expect(plOnProductPage($product))->not->toHaveKey('MODULE SALE 30');
});

it('puts the configured badge on the grid tile and the product page once it is on', function () {
    plModule(true);
    plSave(['sale_text' => 'SAVE {off}%', 'sale_color' => '#123ABC']);

    $product = plProduct(['price' => 10000, 'sale_price' => 7000]);

    expect(plOnProductPage($product))->toHaveKey('SAVE 30%');
    expect(plOnShop())->toHaveKey('SAVE 30%');

    // The colour the screen saved is the colour the page paints, so a wired
    // text field beside an unwired colour picker cannot pass.
    expect(plOnProductPage($product)['SAVE 30%'])->toBe('#123ABC');
});

/* ───────────────────── driven by state, not by a literal ────────────────── */

it('reads the sold-out badge off real stock, not off a hardcoded string', function () {
    plModule(true);
    plSave(['oos_text' => 'Out of stock']);

    $product = plProduct(['stock_status' => 'instock']);
    expect(plOnProductPage($product))->not->toHaveKey('Out of stock');

    $product->update(['stock_status' => 'outofstock']);
    expect(plOnProductPage($product))->toHaveKey('Out of stock');
});

it('reads the new badge off created_at against the configured window', function () {
    plModule(true);
    plSave(['new_on' => true, 'new_days' => 30, 'new_text' => 'Just landed', 'sale_on' => false]);

    // Older than the window: no badge.
    $old = plProduct(['created_at' => now()->subDays(45), 'updated_at' => now()->subDays(45)]);
    expect(plOnProductPage($old))->not->toHaveKey('Just landed');

    // Inside it: badge.
    $fresh = plProduct(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
    expect(plOnProductPage($fresh))->toHaveKey('Just landed');

    // And the window is the setting, not a constant: widening it to 90 days
    // must bring the 45-day-old product in. A badge driven by a literal passes
    // the two assertions above and fails this one.
    plSave(['new_days' => 90]);
    expect(plOnProductPage($old))->toHaveKey('Just landed');
});

it('reads the bestseller badge off the featured column', function () {
    plModule(true);
    plSave(['feat_text' => 'Top seller', 'sale_on' => false, 'new_on' => false]);

    $product = plProduct(['featured' => false]);
    expect(plOnProductPage($product))->not->toHaveKey('Top seller');

    $product->update(['featured' => true]);
    expect(plOnProductPage($product))->toHaveKey('Top seller');
});

/* ───────────────────────────── the -0% guard ────────────────────────────── */

it('never advertises a discount of nothing', function () {
    plModule(true);
    plSave(['sale_text' => '-{off}% OFF', 'new_on' => false, 'feat_on' => false]);

    // AED 100.00 marked down to AED 99.80. isOnSale() is true, and the rounded
    // discount is 0 — so the badge would have to claim a saving of nothing.
    $trivial = plProduct(['price' => 10000, 'sale_price' => 9980]);

    expect(plOnProductPage($trivial))->not->toHaveKey('-0% OFF');
    expect(plOnShop())->not->toHaveKey('-0% OFF');

    // One fils more of markdown is still not 1%, and still says nothing.
    $trivial->update(['sale_price' => 9951]);
    expect(plOnProductPage($trivial))->not->toHaveKey('-0% OFF');

    // A real markdown still gets its badge, so the guard is a floor and not an
    // amputation.
    $trivial->update(['sale_price' => 9000]);
    expect(plOnProductPage($trivial))->toHaveKey('-10% OFF');
});

it('agrees with the price block about what counts as a sale', function () {
    plModule(true);
    plSave(['sale_text' => '-{off}% OFF', 'new_on' => false, 'feat_on' => false]);

    // A scheduled sale that has already ended is not a sale: effectivePrice()
    // returns the ordinary price and the price block shows no saving. The badge
    // used to run its own comparison and could disagree.
    $expired = plProduct([
        'price' => 10000,
        'sale_price' => 5000,
        'sale_starts_at' => now()->subDays(30),
        'sale_ends_at' => now()->subDays(2),
    ]);

    expect(plOnProductPage($expired))->not->toHaveKey('-50% OFF');

    // A sale that has not opened yet is not one either.
    $future = plProduct([
        'price' => 10000,
        'sale_price' => 5000,
        'sale_starts_at' => now()->addDays(2),
    ]);

    expect(plOnProductPage($future))->not->toHaveKey('-50% OFF');
});

/* ─────────────────────────────── precedence ─────────────────────────────── */

it('shows one badge at a time, in the plugin\'s order', function () {
    plModule(true);
    plSave([
        'oos_text' => 'Sold out', 'sale_text' => 'On sale',
        'new_text' => 'New in', 'feat_text' => 'Bestseller',
    ]);

    // Everything true at once: sold out, on sale, brand new, featured.
    $product = plProduct([
        'stock_status' => 'outofstock',
        'price' => 10000,
        'sale_price' => 7000,
        'featured' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $badges = plOnProductPage($product);

    expect($badges)->toHaveKey('Sold out');
    expect($badges)->not->toHaveKey('On sale');
    expect($badges)->not->toHaveKey('New in');
    expect($badges)->not->toHaveKey('Bestseller');

    // Back in stock, the next rule down takes it.
    $product->update(['stock_status' => 'instock']);
    $badges = plOnProductPage($product);

    expect($badges)->toHaveKey('On sale');
    expect($badges)->not->toHaveKey('New in');
});

it('turning one badge off falls through to the next rule, not to the theme', function () {
    plModule(true);
    plSave([
        'oos_on' => false, 'oos_text' => 'Sold out',
        'sale_on' => false, 'new_on' => false,
        'feat_on' => true, 'feat_text' => 'Bestseller',
    ]);

    $product = plProduct([
        'stock_status' => 'outofstock',
        'price' => 10000,
        'sale_price' => 7000,
        'featured' => true,
    ]);

    $badges = plOnProductPage($product);

    expect($badges)->not->toHaveKey('Sold out');
    expect($badges)->toHaveKey('Bestseller');

    // All four off, module on: no badge at all. The plugin returns an empty
    // string here rather than handing the thumbnail back to the theme, and a
    // fall-through to the theme's own "-30% OFF" would be visible right here.
    plSave(['feat_on' => false]);

    expect(plOnProductPage($product))->toBe([]);
});

/* ───────────────────────── the screen cannot lie ────────────────────────── */

it('stores a colour the page can actually paint', function () {
    plModule(true);

    // isValidHex() accepts a hex with or without the '#'. Stored without one,
    // the badge rendered style="background:E23A4E" — not a colour — so it drew
    // with no background and its white text vanished.
    plSave(['sale_on' => true, 'sale_text' => 'Sale', 'sale_color' => 'e23a4e']);

    $product = plProduct(['price' => 10000, 'sale_price' => 7000]);

    expect(plOnProductPage($product)['Sale'])->toBe('#E23A4E');
});

it('reports the module switch to the admin screen it dims', function () {
    $admin = \App\Models\AdminUser::create([
        'name' => 'Lane ER', 'email' => 'labels@example.test',
        'password' => bcrypt('secret-secret'), 'role' => 'owner',
    ]);

    plModule(false);

    $base = '/admin-api/product-labels';

    $off = test()->actingAs($admin, 'admin')->getJson($base)->assertOk();
    expect($off->json('module_on'))->toBeFalse();

    plModule(true);

    $on = test()->actingAs($admin, 'admin')->getJson($base)->assertOk();
    expect($on->json('module_on'))->toBeTrue();

    // And every key the screen renders is a key the storefront reads: a field
    // on this endpoint with no consumer is dead interface.
    $keys = collect($on->json('tabs'))->flatMap(fn ($t) => collect($t['fields'])->pluck('key'))->all();

    expect($keys)->toEqualCanonicalizing(array_keys(ProductLabels::SCHEMA));
});
