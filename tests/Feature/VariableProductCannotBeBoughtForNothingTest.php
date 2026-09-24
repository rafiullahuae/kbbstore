<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Services\ManualOrderBuilder;
use App\Services\SettingsService;
use App\Services\VariantRequired;
use App\Support\Money;

/**
 * A shopper could put a variable product in the basket for AED 0, and the shop
 * would take the order.
 *
 * ── THE DEFECT, END TO END ──────────────────────────────────────────────────
 *
 * A WooCommerce variable product is bought by its VARIATION. The parent row
 * carries no price — `products.price` is NULL and every figure lives in
 * `product_variants` — and Product::effectivePrice() ends `return (int)
 * $this->price`, which makes that 0. CartService::add() priced a line
 *
 *     $unitPrice = $variant?->effectivePrice() ?? $product->effectivePrice();
 *
 * so a variable parent with no variant became a basket line at ZERO. Nothing
 * downstream questioned it: the checkout sums `cart_items.unit_price`, writes
 * `order_items.unit_price` from it, and places the order.
 *
 * THREE LIVE WAYS IN, and the tiles were only two of them:
 *
 *   1. components/product-grid.blade.php drew `Add to cart` on every tile,
 *      including variable ones. components/product-card.blade.php had had the
 *      `$canAdd` guard since it was written; the skinned grid had no copy of
 *      the rule at all. Two copies of a rule is how one of them ends up wrong.
 *   2. The checkout's "you were looking at" strip. browsed() lists whatever is
 *      in the kbb_viewed cookie with no filter on `type`, and browsedAdd()
 *      passes NO variant by construction — so a variable product the shopper
 *      had viewed got a one-click Add, at zero, on the checkout page itself.
 *   3. POST /api/cart/add, where `variant_id` is `nullable` and nothing said
 *      that a VARIABLE product must send one. A fetch call, a tab left open, or
 *      a tile cached before this shipped all arrive here directly.
 *
 * And a fourth, on the other side of the admin: Services\ManualOrderBuilder
 * built a draft cart line the same way, so an operator writing a manual order
 * got a silent AED 0 line and a customer got an invoice for it.
 *
 * ── THE SERVER HALF IS THE FIX; THE BUTTONS ARE THE MANNERS ─────────────────
 *
 * The tile is a suggestion, the endpoint is the door. Every case below that
 * posts is testing the door. The two tile cases are there because a button that
 * offers something the server will refuse is its own small defect.
 *
 * ── MUTATION NOTES, ONE PER FIX ─────────────────────────────────────────────
 *
 *   - Delete the `requiresVariant()` guard in CartService::add() and
 *     'it refuses at the service even when a caller forgot to check' goes red:
 *     the line is written at AED 0.
 *   - Delete the guard in Store\CartController::add() and 'it refuses a
 *     variable product posted with no option' goes red with a 200.
 *   - Delete the guard in Store\CheckoutController::browsedAdd() and its case
 *     goes red — with a 500 rather than a 200, because the service backstop
 *     catches what the controller stopped catching. That is the layering
 *     working, and it is why both halves are tested separately.
 *   - Make Product::requiresVariant() `return $this->type === 'variable'` with
 *     no missing-column guard and 'it will not vouch for a product whose type
 *     was never selected' goes red.
 *   - Revert `$canAdd` / `$kbbCanAdd` to the old expressions and the two tile
 *     cases go red with a live `data-kbb-add` on a variable product.
 *   - Delete `$kbbHeadline` from store/product.blade.php and the product-page
 *     case goes red reading AED 0.
 */

/** A variable parent with no price of its own — the real WooCommerce shape. */
function vbnParent(string $slug = 'vbn-cushion', array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => $slug,
        'name' => 'Water Glow Cushion',
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
    ], $extra));
}

function vbnVariant(Product $parent, int $price): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $parent->id,
        'price' => $price,
        'stock_status' => 'instock',
    ]);
}

function vbnSimple(string $slug = 'vbn-toner', int $price = 8500): Product
{
    return Product::create([
        'slug' => $slug,
        'name' => 'Dokdo Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $price,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);
}

/* ─────────────────────────── the door: /api/cart/add ─────────────────────── */

it('refuses a variable product posted with no option', function () {
    $parent = vbnParent();
    vbnVariant($parent, 3500);
    vbnVariant($parent, 6000);

    $response = $this->postJson('/api/cart/add', ['product_id' => $parent->id, 'quantity' => 1]);

    $response->assertStatus(422)->assertJson(['ok' => false]);

    expect((string) $response->json('error'))->toContain('option');

    // AND NOTHING WAS WRITTEN. A refusal that still creates the line is the
    // defect with a message attached.
    expect(CartItem::query()->count())->toBe(0);
});

it('still adds a variable product when an option IS chosen, at that option price', function () {
    $parent = vbnParent('vbn-priced');
    $cheap = vbnVariant($parent, 3500);
    vbnVariant($parent, 6000);

    $this->postJson('/api/cart/add', [
        'product_id' => $parent->id,
        'variant_id' => $cheap->id,
    ])->assertOk()->assertJson(['ok' => true]);

    $item = CartItem::query()->firstOrFail();

    expect($item->product_variant_id)->toBe($cheap->id)
        ->and((int) $item->unit_price)->toBe(3500);
});

it('leaves a simple product exactly as it was', function () {
    $simple = vbnSimple();

    $this->postJson('/api/cart/add', ['product_id' => $simple->id])
        ->assertOk()
        ->assertJson(['ok' => true, 'count' => 1]);

    expect((int) CartItem::query()->firstOrFail()->unit_price)->toBe(8500);
});

it('refuses at the service even when a caller forgot to check', function () {
    /*
     * THE BACKSTOP, AND IT IS THE HALF THAT MATTERS. Every controller checks
     * first so the shopper gets a sentence, but a controller written next month
     * — or a queue job, or a console command — reaches CartService::add()
     * directly. Before this, that call wrote a line at AED 0 and returned it.
     */
    $parent = vbnParent('vbn-backstop');
    vbnVariant($parent, 4200);

    $cart = Cart::create([
        'token' => 'vbn-token',
        'currency' => 'AED',
        'status' => 'active',
        'last_activity_at' => now(),
    ]);

    expect(fn () => app(CartService::class)->add($cart, $parent->fresh(), 1))
        ->toThrow(VariantRequired::class);

    expect(CartItem::query()->count())->toBe(0);
});

/* ───────────────── the second door: the checkout's browsed strip ─────────── */

it('refuses a one-click add of a variable product from the checkout strip', function () {
    // This strip has no option picker and passes no variant by construction,
    // so every variable product it listed was a free line one press away —
    // and the shopper was already on the checkout when it was offered.
    $simple = vbnSimple('vbn-in-bag');
    $parent = vbnParent('vbn-browsed');
    vbnVariant($parent, 5500);

    // A basket has to exist for browsedAdd to answer at all.
    $this->postJson('/api/cart/add', ['product_id' => $simple->id])->assertOk();

    $response = $this->postJson('/checkout/browsed-add', ['product_id' => $parent->id]);

    $response->assertStatus(422)->assertJson(['ok' => false]);

    expect((string) $response->json('error'))->toContain('options');

    // The basket still holds only the simple product it held before.
    expect(CartItem::query()->count())->toBe(1);
});

/* ──────────────────── and the one inside the admin ───────────────────────── */

it('refuses to price a manual order line with no option, naming the product', function () {
    /*
     * Services\ManualOrderBuilder builds its draft cart through the same
     * CartService::add(), so an operator adding a variable product without an
     * option got a silent AED 0 line and the customer got an invoice for it.
     *
     * Translated into ManualOrderFailure rather than left to escape — the same
     * thing createWithin() already does with a CouponExhausted — so the screen
     * shows wording instead of a 500.
     */
    $parent = vbnParent('vbn-manual');
    vbnVariant($parent, 7700);

    $quote = app(ManualOrderBuilder::class)->quote([
        'items' => [['product_id' => $parent->id, 'quantity' => 1]],
        'address' => ['country' => 'AE'],
        'email' => 'someone@example.com',
    ]);

    expect($quote['ok'])->toBeFalse()
        // Named, because an operator building a ten-line order has to know
        // which line to fix.
        ->and((string) $quote['error'])->toContain('Water Glow Cushion')
        ->and((string) $quote['error'])->toContain('options');
});

/* ───────────────────────── the predicate itself ──────────────────────────── */

it('looks the answer up when type was never selected, rather than guessing', function () {
    /*
     * Half this application hydrates explicit column lists because the
     * endpoints are public. A model loaded without `type` answers null, and
     * `null !== 'variable'` is TRUE — which would mean "no option needed" for
     * every product in a query that had simply not asked. That fails OPEN, on
     * the one question where failing open means selling for nothing.
     *
     * So the absent column is looked up in the rows that can answer it: a
     * product is sold by options when it HAS options.
     */
    $parent = vbnParent('vbn-narrow');
    vbnVariant($parent, 3300);

    $narrowed = Product::query()->select(['id', 'slug', 'price'])->findOrFail($parent->id);

    expect($narrowed->requiresVariant())->toBeTrue()
        ->and($narrowed->isDirectlyBuyable())->toBeFalse();
});

it('does not refuse an ordinary product merely because type is absent', function () {
    /*
     * ▲ THE OTHER DIRECTION, AND IT IS THE ONE THAT ACTUALLY BIT. The first
     * version of requiresVariant() answered `true` for any absent `type`,
     * reasoning that an unfetched column cannot be vouched for. Eight existing
     * tests went red and none of them was doing anything exotic:
     * `Product::create([...])` with no `type` key leaves the attribute absent
     * on the returned model, which is a column the ROW never set rather than
     * one the QUERY declined to fetch — indistinguishable from inside the
     * model. A blanket `true` refused ordinary simple products, so the safe-
     * looking direction was a shop that could not sell anything.
     *
     * A product with no variations is buyable however it was loaded.
     */
    $product = Product::create([
        'slug' => 'vbn-typeless',
        'name' => 'Bar Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 4900,
        'stock_status' => 'instock',
        // No `type` key at all, exactly as several fixtures and the free-
        // delivery-bar helper build one.
    ]);

    expect(array_key_exists('type', $product->getAttributes()))->toBeFalse()
        ->and($product->requiresVariant())->toBeFalse()
        ->and($product->isDirectlyBuyable())->toBeTrue();

    $this->postJson('/api/cart/add', ['product_id' => $product->id])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect((int) CartItem::query()->firstOrFail()->unit_price)->toBe(4900);
});

it('is selected by every column list that reaches the cart', function () {
    /*
     * The guard above fails closed, so a column list that dropped `type` would
     * turn every Add to cart button in the shop into View product — safe, but
     * silently wrong. These three lists are the ones whose rows reach
     * CartService::add() or a tile, so `type` being in them is load-bearing in
     * both directions.
     */
    $lists = [
        'CartController::LINE_COLUMNS' => (new ReflectionClass(\App\Http\Controllers\Store\CartController::class))
            ->getConstant('LINE_COLUMNS'),
        'CheckoutController::LINE_COLUMNS' => (new ReflectionClass(\App\Http\Controllers\Store\CheckoutController::class))
            ->getConstant('LINE_COLUMNS'),
        'HomeController::CARD_COLUMNS' => (new ReflectionClass(\App\Http\Controllers\Store\HomeController::class))
            ->getConstant('CARD_COLUMNS'),
    ];

    foreach ($lists as $name => $columns) {
        expect($columns)->toBeArray()
            ->and(in_array('type', $columns, true))->toBeTrue($name . ' must select `type`');
    }
});

/* ───────────────────────────── the two tiles ─────────────────────────────── */

it('offers View product rather than Add to cart on the shop tile', function () {
    $parent = vbnParent('vbn-tile');
    vbnVariant($parent, 3500);
    vbnVariant($parent, 6000);
    vbnSimple('vbn-tile-simple');

    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $html = $this->get('/shop/')->assertOk()->getContent();

    $variableCard = vbnCard($html, 'vbn-tile');
    $simpleCard = vbnCard($html, 'vbn-tile-simple');

    // The variable tile arms nothing.
    expect($variableCard)->not->toContain('data-kbb-add')
        ->and($variableCard)->toContain('View product');

    // And the simple one is untouched.
    expect($simpleCard)->toContain('data-kbb-add')
        ->and($simpleCard)->toContain('Add to cart');
});

it('offers View product rather than Add to cart on the skinned grid', function () {
    /*
     * THE TEMPLATE THAT HAD NO GUARD AT ALL. <x-product-grid> drew
     * `<span class="kbb-card-cart" data-kbb-add=... data-price=...>Add to
     * cart</span>` for every product, and `data-price` was the AED 0 itself.
     * The brand pages render through it.
     */
    $brand = Brand::create(['slug' => 'vbn-brand', 'name' => 'Round Lab']);

    $parent = vbnParent('vbn-grid', ['brand_id' => $brand->id]);
    vbnVariant($parent, 3500);
    vbnVariant($parent, 6000);

    $simple = vbnSimple('vbn-grid-simple');
    $simple->forceFill(['brand_id' => $brand->id])->save();

    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $html = $this->get('/korean-skincare-brands/vbn-brand/')->assertOk()->getContent();

    $variableTile = vbnSkinTile($html, 'vbn-grid');
    $simpleTile = vbnSkinTile($html, 'vbn-grid-simple');

    expect($variableTile)->not->toContain('data-kbb-add')
        // data-price carried the zero, and it goes with it.
        ->and($variableTile)->not->toContain('data-price')
        ->and($variableTile)->toContain('View product');

    expect($simpleTile)->toContain('data-kbb-add')
        ->and($simpleTile)->toContain('Add to cart');
});

/* ───────────────────────── the product page headline ─────────────────────── */

it('quotes a range on the product page instead of AED 0', function () {
    /*
     * .bb-price and the sticky bar both printed Money::format($price) with
     * $price = effectivePrice() = 0. AND IT IS NOT A FLICKER: pdp.js writes the
     * real figure from a CLICK listener only, so the page stood at AED 0 with
     * an option already highlighted until the shopper tapped something.
     */
    $parent = vbnParent('vbn-page');
    vbnVariant($parent, 3500);
    vbnVariant($parent, 6000);

    /* The sticky bar ships OFF (`sticky_show` defaults to false), so it has to
       be switched on for this case to see it at all — and it has to be seen,
       because it is the second place the same zero was printed. Appearance →
       Product page → Sticky bar is the control; nothing about its default is
       changed here. */
    \App\Models\Setting::query()->updateOrCreate(
        ['key' => 'sticky_show'],
        ['value' => '1', 'autoload' => true]
    );
    \App\Models\Setting::flushMap();

    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
    app(SettingsService::class)->flush();

    $html = $this->get('/product/vbn-page/')->assertOk()->getContent();

    $headline = vbnBetween($html, '<div class="bb-price" id="bbPrice">', '</div>');
    $sticky = vbnBetween($html, '<span class="sp" id="stickyPrice">', '</span></span>');

    // Pest's toContain() is VARIADIC — a second argument is another needle,
    // not a message — so each expectation carries exactly one string.
    foreach ([$headline, $sticky] as $text) {
        expect($text)->toContain('35')      // the cheapest option
            ->and($text)->toContain('60')   // the dearest one
            ->and($text)->not->toContain('AED 0');
    }
});

it('leaves a simple product page headline exactly as it was', function () {
    vbnSimple('vbn-page-simple', 8500);

    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $html = $this->get('/product/vbn-page-simple/')->assertOk()->getContent();

    expect(vbnBetween($html, '<div class="bb-price" id="bbPrice">', '</div>'))
        ->toContain(strip_tags(Money::format(8500)));
});

/* ───────────────────────────────── helpers ───────────────────────────────── */

/** The `.pc` card for one slug, as markup. */
function vbnCard(string $html, string $slug): string
{
    foreach (preg_split('#<div class="pc">#', $html) as $card) {
        if (str_contains($card, '/product/' . $slug . '/')) {
            return $card;
        }
    }

    return '';
}

/** The `.kbb-card` tile for one slug, as markup. */
function vbnSkinTile(string $html, string $slug): string
{
    foreach (preg_split('#<a class="kbb-card"#', $html) as $tile) {
        if (str_contains($tile, '/product/' . $slug . '/')) {
            return $tile;
        }
    }

    return '';
}

/** The text between two markers, tags stripped and entities decoded. */
function vbnBetween(string $html, string $open, string $close): string
{
    $start = strpos($html, $open);

    if ($start === false) {
        return '';
    }

    $start += strlen($open);
    $end = strpos($html, $close, $start);

    return trim(html_entity_decode(strip_tags(substr($html, $start, $end === false ? 0 : $end - $start)), ENT_QUOTES, 'UTF-8'));
}
