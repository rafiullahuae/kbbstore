<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A BUDGET CAPS A TOTAL WITHOUT SAYING HOW THE TOTAL GROWS. This says how.
 *
 * ── WHY THIS FILE EXISTS BESIDE StorefrontQueryBudgetTest ───────────────────
 *
 * That file does two things: it caps each page, and it measures /shop and the
 * homepage against a catalogue of 24 products and again against 84. The cap is
 * the weaker half, and twice now a per-item cost has hidden underneath one:
 *
 *   - /cart was issuing one extra statement PER BASKET LINE for months, inside
 *     a ceiling of 10, because the budget fixture put no `product_variant_id`
 *     on any line and the code path never ran (Lane Q8).
 *   - The recommended rail was issuing one `select * from brands` PER CARD,
 *     inside the same ceiling, because the rail renders only on a layout the
 *     shop does not ship on (Lane Q9, the round this file comes from).
 *
 * Both are the same shape: a cost that is invisible at the fixture's size and
 * linear above it. A ceiling cannot see it. Two sizes can.
 *
 * ── WHAT IS PINNED, AND WHAT IS DELIBERATELY NOT ────────────────────────────
 *
 * THREE PAGES, not all of them. Lane Q9 measured ten storefront pages at 1, 2,
 * 5 and 10 of whatever repeats on them and found every one already flat —
 * docs/q9-storefront-slope-audit.md carries the table. Pinning all ten would
 * buy almost nothing and cost the suite a second or two on every run, so this
 * covers the three where an N+1 costs a SHOPPER rather than a crawler, and
 * where the per-item work is richest:
 *
 *   /shop         the grid every visitor lands on, and the page that once ran
 *                 390 queries for four products.
 *   product page  variations, each of which reads an option label off a
 *                 many-to-many — the relation Q8's cart defect was in.
 *   /cart         basket lines AND the recommended rail, on the screen where
 *                 somebody decides to pay.
 *
 * NO ABSOLUTE NUMBER APPEARS BELOW, on purpose. A total measured in a test
 * process is about four statements higher than the one a real visitor pays —
 * Route::getController() caches the controller on the Route object and the
 * Router outlives the container reset, so from the second request onward the
 * controller holds services that reset has evicted. That inflation is IDENTICAL
 * for both sizes, so it cancels out of a comparison and would poison a ceiling.
 * Ceilings live in StorefrontQueryBudgetTest; this file only ever compares.
 *
 * ── THE THREE THINGS THAT MAKE A MEASUREMENT HERE HONEST ────────────────────
 *
 * 1. A WARM-UP REQUEST, DISCARDED. Setting::map() memoises in a process-level
 *    static, so the first request of a process is dearer than every later one.
 * 2. forgetMemo() AND forgetScopedInstances() before each measured request,
 *    or the second request answers out of the first one's scoped memos.
 * 3. THE FIXTURE HAS TO BE PROVEN TO VARY. Every case below counts what the
 *    page actually RENDERED at each size and asserts those differ before it
 *    compares any statement count. A flat line over a fixture that renders the
 *    same thing twice is how the two defects above survived their own budget.
 */

function slopeReset(): void
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

/**
 * One measured request: statements run, and the body.
 *
 * The cart cookie is sent on EVERY request, including the ones with no cart —
 * Laravel's test client accumulates cookies for the life of the test, so a page
 * measured after a cart page would otherwise silently carry a basket. See
 * StorefrontQueryBudgetTest's budgetCount() for the full argument.
 */
function slopeRequest(string $path, ?string $cartToken = null): array
{
    slopeReset();

    $sql = [];
    DB::listen(function ($event) use (&$sql): void { $sql[] = $event->sql; });

    $html = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cartToken ?? 'slope-no-such-cart')
        ->get($path)
        ->assertOk()
        ->getContent();

    return ['total' => count($sql), 'html' => $html];
}

/** Warm-up discarded, then measured. */
function slopeMeasure(string $path, ?string $cartToken = null): array
{
    slopeRequest($path, $cartToken);

    return slopeRequest($path, $cartToken);
}

/** A published product with a brand and a category of its own. */
function slopeProduct(string $slug, array $extra = []): Product
{
    static $seq = 0;
    $slug .= '-' . (++$seq);

    $brand = Brand::create(['slug' => 'sl-b-' . $slug, 'name' => 'Slope Brand ' . $slug]);
    $category = Category::create(['slug' => 'sl-c-' . $slug, 'name' => 'Slope Cat ' . $slug]);

    $product = Product::create(array_merge([
        'slug' => 'sl-' . $slug,
        'name' => 'Slope ' . $slug,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'type' => 'simple',
        'brand_id' => $brand->id,
    ], $extra));

    $product->categories()->attach($category->id);

    return $product;
}

it('does not get dearer as the shop grid fills up', function () {
    /*
     * /shop, two tiles against twelve. Every tile carries its OWN brand and its
     * OWN category, so a per-tile read cannot be batched by luck — which is the
     * difference between this and a fixture of twelve products sharing one
     * brand, where a single `in (...)` covers everything and a lazy load looks
     * flat.
     *
     * The migration set seeds a demo catalogue, and `products_per_page` is 24 —
     * so an un-emptied /shop renders a full page of somebody else's products at
     * BOTH sizes and the tile counts come back 24 and 24. That is a fixture
     * that does not vary, which is the exact failure this file is written to
     * prevent, so it is emptied rather than worked around. RefreshDatabase
     * rolls the delete back with everything else.
     */
    Product::query()->delete();

    for ($i = 0; $i < 2; $i++) {
        slopeProduct('grid');
    }

    $small = slopeMeasure('/shop');

    for ($i = 0; $i < 10; $i++) {
        slopeProduct('grid');
    }

    $large = slopeMeasure('/shop');

    $tiles = fn (array $r) => substr_count($r['html'], 'data-kbb-qv=');

    // The fixture varies — asserted before anything is compared.
    expect($tiles($small))->toBe(2)
        ->and($tiles($large))->toBe(12);

    expect($large['total'])->toBe($small['total']);
});

it('does not get dearer as a product gains variations', function () {
    /*
     * The product page, one variation against ten.
     *
     * EVERY VARIATION CARRIES A REAL OPTION VALUE, and that is the whole point
     * of this case. store/product.blade.php prints `$v->label()`, which reads
     * `attributeValues` — a belongsToMany. A fixture of bare variants never
     * touches that relation, so the page measures flat whether it is eager
     * loaded or not. That is precisely the shape that hid the same defect on
     * /cart from StorefrontQueryBudgetTest for months.
     */
    $attribute = Attribute::firstOrCreate(
        ['slug' => 'size'],
        ['name' => 'Size', 'is_variation_axis' => true]
    );

    $make = function (string $slug, int $variations) use ($attribute): string {
        $product = slopeProduct($slug, ['type' => 'variable', 'price' => null]);

        for ($i = 0; $i < $variations; $i++) {
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'price' => 10000 + $i,
                'stock_status' => 'instock',
                'position' => $i,
            ]);

            $value = AttributeValue::create([
                'attribute_id' => $attribute->id,
                'name' => (10 * ($i + 1)) . 'ml',
                'slug' => 'sl-sz-' . $product->id . '-' . $i,
            ]);

            DB::table('product_variant_attribute_value')->insert([
                'product_variant_id' => $variant->id,
                'attribute_value_id' => $value->id,
            ]);
        }

        return $product->slug;
    };

    $small = slopeMeasure('/product/' . $make('one-option', 1));
    $large = slopeMeasure('/product/' . $make('ten-options', 10));

    $rows = fn (array $r) => substr_count($r['html'], 'data-vid="');

    expect($rows($small))->toBe(1)
        ->and($rows($large))->toBe(10);

    expect($large['total'])->toBe($small['total']);
});

it('does not get dearer as the basket and the recommended rail fill up', function () {
    /*
     * /cart, carrying BOTH of the per-item costs this page has had: the basket
     * lines and the recommended rail.
     *
     * ▲ ONE CART, GROWN IN PLACE, never a fresh one per size.
     * Route::getController() caches the controller on the Route object and the
     * Router outlives the container, so a second cart is measured through a
     * CartService still bound to the first — which by then has been deleted.
     * The page then renders an EMPTY basket, costs far less, and reports a
     * flat line that means nothing at all. Measured while writing this: 7
     * statements and no rail, against 11 with the cart intact.
     */
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $fill = function (int $lines, int $railCards) use ($cart): void {
        while ($cart->items()->count() < $lines) {
            $cart->items()->create([
                'product_id' => slopeProduct('line')->id,
                'quantity' => 1,
                'unit_price' => 10000,
            ]);
        }

        $ids = [];

        for ($i = 0; $i < $railCards; $i++) {
            $ids[] = slopeProduct('rail')->id;
        }

        slopeReset();

        // The squeeze layout, because that is the only one the rail renders on.
        // The shop ships on `classic`, so this cost is latent there and becomes
        // real the moment Appearance → Cart page is switched over.
        app(CartPage::class)->save([
            'layout' => 'squeeze',
            'rec_on' => true,
            'rec_ids' => implode(',', $ids),
        ]);
    };

    $fill(1, 1);
    $small = slopeMeasure('/cart', $cart->token);

    $fill(6, 10);
    $large = slopeMeasure('/cart', $cart->token);

    $lines = fn (array $r) => substr_count($r['html'], '<div class="ci"');
    $cards = fn (array $r) => substr_count($r['html'], '<div class="cpg-card">');

    expect($cards($small))->toBe(1)
        ->and($cards($large))->toBe(10)
        ->and($lines($large))->toBeGreaterThan($lines($small));

    expect($large['total'])->toBe($small['total']);
});
