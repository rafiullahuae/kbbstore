<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SetStockReconciler;
use App\Services\SettingsService;
use App\Services\StockSetRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * A SET IN THE BASKET MADE THE ORDER UNPLACEABLE
 * =============================================================================
 *
 * ── WHAT IT LOOKED LIKE ON THE SHOP ────────────────────────────────────────
 *
 * The owner, with a screenshot of his own checkout:
 *
 *     "along with set, the order is not placing, i don't know why. please make
 *      sure about the stock quantity, if any product is in the set, and also
 *      user added in the cart too. and the stock qnty is 1, then the individual
 *      product will be removed from the cart, and set will remain there."
 *
 * His basket held the Medicube booster set AND the 1025 Dokdo Toner that is
 * inside it. One toner on the shelf. Place order came back
 *
 *     "1025 Dokdo Toner (in Medicube booster set) is sold out. Please remove it
 *      from your basket to continue."
 *
 * and pressing it again did the same thing for ever, because nothing reachable
 * from the checkout changed the basket. Reproduced here before anything was
 * designed, with a one-unit shelf: 302 back to the checkout, the refusal
 * "Only 1 of 1025 Dokdo Toner is left. Please reduce the quantity in your
 * basket to continue.", ZERO orders written and the stock untouched. The same
 * defect; the wording differs only by whether the shelf is short or empty.
 *
 * ── THE MECHANISM, ESTABLISHED RATHER THAN ASSUMED ─────────────────────────
 *
 * Nothing is applied twice and nothing is counted twice by mistake. It is the
 * rule working as written against a basket it has no answer for:
 *
 *   1. StockSetRule::expand() (MODE_MEMBERS, the shipped default since the
 *      owner's decision of 29 September) adds one claim line per member.
 *   2. StockClaim::perShelf() sums the member's line and the shopper's own
 *      loose line, which share the toner's shelf, into a demand of TWO.
 *   3. takeFromShelf() finds one, refuses, and rolls the whole order back.
 *
 * What was missing was the decision about which claim loses, and the owner has
 * made it: THE LOOSE LINE GOES AND THE SET STAYS.
 *
 * ── WHAT EACH CASE BELOW IS HERE TO CATCH ──────────────────────────────────
 *
 *   1. The defect itself: the basket the owner described now places an order.
 *   2. The loose line is what goes, and the set is what stays.
 *   3. The shopper is told, in words, on the checkout and on the cart page.
 *   4. The totals, the item count and the free-delivery bar follow.
 *   5. THE SET IS STILL NOT OVERSOLD. A fix that unblocked the order by
 *      ignoring stock would be worse than the bug, so the shelf is read after
 *      the order and the ledger is read too.
 *   6. Nothing happens to a basket that does not have this problem — including
 *      a shelf deep enough for both, which must keep BOTH lines.
 *   7. It costs no query at all on a basket with no set in it, and it does not
 *      cost one per member on a basket that has one.
 */
beforeEach(function () {
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    PaymentProvider::create([
        'id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0,
    ]);

    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    // The shipped default since 29 September, set explicitly so this file says
    // which rule it is about rather than inheriting one.
    app(SettingsService::class)->set(StockSetRule::KEY, StockSetRule::MODE_MEMBERS);
    SettingsService::forgetMemo();
});

function slsProduct(string $name, int $stock, bool $manage = true): Product
{
    return Product::create([
        'slug' => 'sls-' . Str::slug($name) . '-' . Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9000,
        'stock_status' => 'instock',
        'manage_stock' => $manage,
        'stock' => $stock,
    ]);
}

/** @param list<array{0: Product, 1: int}> $members */
function slsSet(string $name, array $members): Product
{
    $set = Product::create([
        'slug' => 'sls-set-' . Str::random(8),
        'name' => $name,
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 19900,
        'stock_status' => 'instock',
        'manage_stock' => false,
    ]);

    $position = 0;

    foreach ($members as [$member, $quantity]) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $member->id,
            'quantity' => $quantity,
            'position' => $position++,
        ]);
    }

    return $set->fresh();
}

/** @param list<array{0: Product, 1: int}> $lines */
function slsCart(array $lines): Cart
{
    $cart = Cart::create([
        'token' => Str::random(32),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    foreach ($lines as [$product, $quantity]) {
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => (int) $product->price,
        ]);
    }

    return $cart->fresh(['items']);
}

/** A request as the shopper's own browser makes it, carrying their cart cookie. */
function slsAs(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function slsPlace(Cart $cart)
{
    return slsAs($cart)->post('/checkout/place', [
        'billing_email' => 'buyer@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ]);
}

it('places the order the owner could not place', function () {
    /*
     * ▲ THE DEFECT, END TO END, THROUGH THE REAL CHECKOUT.
     *
     * The basket he described: the set, and the same product loose, with one
     * unit on the shelf. Before the fix this was 0 orders and a refusal that
     * repeated on every press.
     *
     * The checkout is asked for first because that is what a shopper does, and
     * because reconciling at RENDER rather than inside place() is the whole
     * design: CartService::claimStock()'s own comment refuses to drop a line at
     * payment time — "the shopper then pays for a basket they never agreed to,
     * at a total they never saw."
     *
     * MUTATION NOTE. Delete the SetStockReconciler::reconcile() call from
     * Store\CheckoutController::page() and this is red: 0 orders, and the
     * response is a 302 carrying "Only 1 of 1025 Dokdo Toner is left". RUN.
     */
    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    slsAs($cart)->get('/checkout')->assertOk();

    slsPlace($cart)->assertRedirect();

    expect(Order::count())->toBe(1, 'The order the owner could not place must place.');
});

it('takes the loose line and keeps the set, which is the owner\'s decision', function () {
    /*
     * Not "drop whichever is cheaper" and not "refuse both": the owner said,
     * in as many words, "the individual product will be removed from the cart,
     * and set will remain there."
     *
     * MUTATION NOTE. Swap the partition in SetStockReconciler::reconcile() so
     * the set lines are the ones trimmed and this is red on both halves — the
     * set is gone and the toner is still there. RUN.
     */
    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    slsAs($cart)->get('/cart')->assertOk();

    $left = $cart->fresh(['items'])->items;

    expect($left)->toHaveCount(1)
        ->and((int) $left->first()->product_id)->toBe(
            (int) $set->id,
            'The set stays; the line that was also inside it goes.'
        );
});

it('tells the shopper what left and why, on the cart page', function () {
    /*
     * A basket that changes by itself and says nothing is a second defect
     * wearing the first one's clothes. The sentence names the product that
     * went, the set that kept it, and the reason.
     *
     * ▲ ONE BASKET PER CASE, and it is not tidiness. CartService::resolve()
     * remembers the cart in the SESSION as well as in the cookie, and the test
     * client carries one session across every request a case makes — so a
     * second basket introduced halfway through a case is never the one the
     * shop loads, and the assertion about it passes or fails against the FIRST
     * basket. This file cost a round of exactly that before it was split.
     *
     * MUTATION NOTE. Drop the @include('partials.set-stock-notice') line from
     * store/cart.blade.php and this is red. RUN.
     */
    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    $cartPage = slsAs($cart)->get('/cart')->assertOk()->getContent();

    expect($cartPage)->toContain('1025 Dokdo Toner')
        ->and($cartPage)->toContain('Medicube booster set')
        ->and($cartPage)->toContain('has been taken out of your bag');
});

it('tells the shopper what left and why, on the checkout', function () {
    /*
     * The same sentence, from the same partial, on the page the owner was
     * actually stuck on.
     *
     * MUTATION NOTE. Drop the @include('partials.set-stock-notice') line from
     * store/checkout.blade.php and this is red. RUN.
     */
    $toner = slsProduct('Heartleaf Ampoule', 1);
    $set = slsSet('Glow Starter Set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    $checkout = slsAs($cart)->get('/checkout')->assertOk()->getContent();

    expect($checkout)->toContain('Heartleaf Ampoule')
        ->and($checkout)->toContain('Glow Starter Set')
        ->and($checkout)->toContain('has been taken out of your bag');
});

it('reduces rather than removes when the shelf can cover part of the loose line', function () {
    /*
     * Three on the shelf, a set that takes one, and the shopper asking for four
     * loose. The set is served in full and the loose line keeps what is left —
     * two — rather than being deleted outright. Integer arithmetic, floored at
     * zero.
     *
     * MUTATION NOTE. Change `$keep = max(0, min($quantity, $allowed))` in
     * SetStockReconciler::trim() to `$keep = 0` and this is red: the line is
     * deleted outright instead of being reduced to two. RUN.
     */
    $toner = slsProduct('Heartleaf Toner', 3);
    $set = slsSet('Glow Set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 4]]);

    $page = slsAs($cart)->get('/cart')->assertOk()->getContent();

    $items = $cart->fresh(['items'])->items->keyBy('product_id');

    expect($items->has($toner->id))->toBeTrue('The loose line is reduced, not deleted.');

    expect((int) $items[$toner->id]->quantity)->toBe(2)
        ->and((int) $items[$set->id]->quantity)->toBe(1)
        ->and($page)->toContain('has been reduced to 2 in your bag');
});

it('makes the totals, the item count and the free-delivery bar follow', function () {
    /*
     * The figures on the page are read off the basket, so they can only be
     * right if the basket was reconciled BEFORE they were computed. That is
     * what the second hydration in CartController::loadCart() is for.
     *
     * MUTATION NOTE, AND IT TAKES TWO EDITS, which is the finding rather than
     * an inconvenience. Removing the second `$this->hydrate($cart)` from
     * CartController::loadCart() ALONE is green, and so is removing the
     * `$cart->unsetRelation('items')` from SetStockReconciler::trim() alone:
     * either one on its own is enough to make the page read the basket again,
     * because Eloquent lazy-loads an unset relation and load() always
     * re-queries. Remove BOTH and this is red — the heading counts two items
     * and the subtotal still carries the removed line. RUN (all three: each
     * alone, and both).
     */
    /*
     * A free-delivery threshold this basket does not reach either way, so the
     * bar has a figure to print and the figure has to be the reconciled one.
     * A `free_shipping` method with a `min_amount`, which is where
     * ShippingService::freeShippingThreshold() reads it from — never a
     * constant.
     */
    ShippingMethod::create([
        'shipping_zone_id' => ShippingZone::query()->value('id'),
        'type' => 'free_shipping',
        'title' => 'Free delivery',
        'cost' => 0,
        'min_amount' => 30000,
        'enabled' => true,
        'position' => 1,
    ]);
    SettingsService::forgetMemo();

    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    $page = slsAs($cart)->get('/cart')->assertOk()->getContent();

    /*
     * ▲ READ OFF THE RENDERED PAGE, and that is the whole point of this case.
     *
     * Asserting on a freshly fetched Cart would pass whether or not the page
     * was re-hydrated after the line went: the database is right either way.
     * What was wrong is what the SHOPPER SEES — the heading counted the removed
     * line, and so did the subtotal, because both are read off the relation in
     * memory and that relation still described the basket as it was.
     */
    expect($page)->toContain('(1 item)')
        ->and($page)->not->toContain('(2 items)');

    $totals = app(CartService::class)->totals($cart->fresh(['items']), 'AE');

    expect((int) $totals['item_count'])->toBe(1, 'One line is left, so one item is counted.')
        ->and((int) $totals['subtotal'])->toBe(
            (int) $set->price,
            'The subtotal is the set alone: 19900 fils, not 19900 + 9000.'
        );

    /*
     * The free-delivery bar is computed from that same subtotal, so proving it
     * follows is proving it moved WITH the line rather than against it: the
     * shopper is further from free delivery than they were, which is the honest
     * answer for a smaller basket. Both figures are integer fils.
     */
    expect((int) $totals['free_shipping_remaining'])->toBe(
        30000 - (int) $set->price,
        'The bar is the threshold minus the subtotal the reconciled basket actually has.'
    );
});

it('still refuses to oversell the set itself', function () {
    /*
     * ▲ THE HALF THAT MATTERS MOST. A fix that unblocked the order by ignoring
     * stock would be worse than the bug it fixed.
     *
     * One toner on the shelf and a set that needs TWO of it. Nothing this lane
     * wrote can make that box packable, and nothing does: the loose line is
     * still taken (it cannot help, and the set is served first), the order is
     * still refused, and not one unit leaves the shelf.
     *
     * MUTATION NOTE. Drop `app(StockSetRule::class)->expand(...)` from
     * StockClaim::claim() — so the placing transaction stops looking at the
     * members at all — and this is red: the order places for a box the shop
     * cannot fill and the toner's shelf is never touched. RUN.
     *
     * ▲ NOT "make the reconciler trim the set's own lines", which was the
     * obvious mutation and is GREEN. A set ships with `manage_stock` false
     * (the schema default, which is what Catalog → Sets creates), so it has no
     * counted shelf, and array_intersect_key() can only ever contest a shelf a
     * set MEMBER asked for. The `isSet()` skip in reconcile() is belt and
     * braces over that, not the thing holding this up.
     */
    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 2]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    slsAs($cart)->get('/checkout')->assertOk();

    slsPlace($cart);

    expect(Order::count())->toBe(0, 'A box the shop cannot fill is still refused.')
        ->and((int) $toner->fresh()->stock)->toBe(1, 'A refused order writes nothing.')
        ->and((string) $toner->fresh()->stock_status)->toBe('instock');
});

it('takes exactly the units the order needs, and no more', function () {
    /*
     * The other direction of the same worry: the reconciled basket must still
     * decrement the member's shelf once, for the set, and leave the ledger able
     * to give it back.
     *
     * MUTATION NOTE. Set StockSetRule::mode() to MODE_SET and this is red: the
     * set stops claiming its members at all, so the shelf is untouched. RUN.
     */
    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    slsAs($cart)->get('/checkout')->assertOk();
    slsPlace($cart)->assertRedirect();

    $order = Order::latest('id')->first();

    expect((int) $toner->fresh()->stock)->toBe(0, 'One unit left, for the set.')
        ->and((string) $toner->fresh()->stock_status)->toBe('outofstock')
        ->and((int) DB::table('order_stock_claims')->where('order_id', $order->id)->sum('quantity'))
        ->toBe(1, 'One unit is recorded against the order, so cancelling gives one back.');
});

it('leaves a basket alone when the shelf can cover the set and the loose line both', function () {
    /*
     * The case this must NOT touch, and the one rule 1 is about. Five on the
     * shelf, one in the box, one loose: both lines stay, nothing is said, and
     * the order places.
     *
     * MUTATION NOTE. Change `$allowed = max(0, $claim['available'] - $claim['units'])`
     * in SetStockReconciler::trim() to `$allowed = 0` and this is red: a basket
     * the shop can fill loses its loose line anyway. RUN.
     *
     * ▲ NOT `if ($wanted <= $allowed) { continue; }` -> `if (false)`, which was
     * the obvious mutation and which this file tried first: it is GREEN, and
     * correctly so. That branch is a pure early-out — with it removed the loop
     * below computes `$keep === $quantity` for every line, writes nothing and
     * reports nothing. A mutation note has to name a line that actually decides
     * something, and that one does not.
     */
    $toner = slsProduct('Heartleaf Toner', 5);
    $set = slsSet('Glow Set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    $page = slsAs($cart)->get('/cart')->assertOk()->getContent();

    expect($cart->fresh(['items'])->items)->toHaveCount(2)
        ->and($page)->not->toContain('has been taken out of your bag');

    slsPlace($cart)->assertRedirect();

    expect(Order::count())->toBe(1)
        ->and((int) $toner->fresh()->stock)->toBe(3, 'Two units left: one for the box, one loose.');
});

it('leaves a basket alone while the set rule is switched to set-only', function () {
    /*
     * With Catalog → Sets → Stock · When a set is sold set to "set", selling a
     * set moves no member stock at all, so a loose line can never collide with
     * one. Nothing may be taken out of that shop's baskets.
     *
     * MUTATION NOTE. Delete the `! $this->rule->decrementsMembers()` clause
     * from SetStockReconciler::reconcile() and this is red. RUN.
     */
    app(SettingsService::class)->set(StockSetRule::KEY, StockSetRule::MODE_SET);
    SettingsService::forgetMemo();

    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1], [$toner, 1]]);

    slsAs($cart)->get('/cart')->assertOk();

    expect($cart->fresh(['items'])->items)->toHaveCount(2);
});

it('leaves an ordinary basket alone and costs it no query at all', function () {
    /*
     * The performance half, measured rather than asserted. A basket with no set
     * in it is every basket on this shop today, and reconcile() must not cost
     * it a statement: it answers from a cached settings read and an in-memory
     * scan of lines the page had already loaded.
     *
     * MUTATION NOTE. Move the `$setItems->isEmpty()` early return in
     * SetStockReconciler::reconcile() below the memberDemand() call and this is
     * red. RUN.
     */
    $toner = slsProduct('Heartleaf Toner', 5);
    $cart = slsCart([[$toner, 2]]);
    $cart->load('items.product', 'items.variant');

    $reconciler = app(SetStockReconciler::class);

    // Warm the settings memo the way a real request already has by this point.
    $reconciler->reconcile($cart);

    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });

    expect($reconciler->reconcile($cart))->toBe([]);
    expect($queries)->toBe(0, 'A basket with no set in it must not cost a statement.');
});

it('costs the same number of queries for a nine-member set as for a three-member one', function () {
    /*
     * The N+1 this is most likely to become: one stock lookup per member,
     * inside a page render. The measurement SetRowSurfacesTest makes for
     * SetEagerLoad, made again for the shelf lookups this class adds — a count
     * that does not move when the set triples is the only evidence that it
     * batches.
     *
     * MUTATION NOTE. Replace the whereIn() in
     * SetStockReconciler::memberDemand() with a Product::find() inside the
     * loop and this is red. RUN.
     */
    $build = function (int $members): Cart {
        $parts = [];

        for ($i = 0; $i < $members; $i++) {
            $parts[] = [slsProduct('Member ' . $i . ' ' . Str::random(4), 5), 1];
        }

        $set = slsSet('Glow Set ' . Str::random(4), $parts);

        return slsCart([[$set, 1]]);
    };

    $reconciler = app(SetStockReconciler::class);

    $measure = function (Cart $cart) use ($reconciler): int {
        $cart->load(['items.product', 'items.product.setItems', 'items.variant']);

        $queries = 0;
        $listener = function () use (&$queries) { $queries++; };

        DB::listen($listener);
        $reconciler->reconcile($cart);

        // DB::listen ACCUMULATES rather than replaces, so the counter is reset
        // by the closure itself rather than by registering a second listener —
        // the mistake StorefrontQueryBudgetTest documents at length.
        $counted = $queries;
        $queries = -PHP_INT_MAX;

        return $counted;
    };

    $reconciler->reconcile($build(1)); // warm the settings memo

    $small = $measure($build(3));
    $large = $measure($build(9));

    expect($large)->toBe(
        $small,
        "reconcile() ran {$large} statements for a nine-member set and {$small} for a three-member one. "
        . 'A difference is one query per member, inside a page render.'
    );
});

it('says so on the add itself, not only when the basket is next drawn', function () {
    /*
     * The third surface. A shopper with the set already in the bag who presses
     * Add to bag on the toner gets the line taken straight back out — and
     * without this the reply's toast still read "Added to bag" while nothing
     * appeared in the drawer, which is the shape of a bug even though the
     * basket is right.
     *
     * MUTATION NOTE. Remove the `if ($this->setStockNotices !== [])` block from
     * CartController::fragments() and this is red: the toast is "Added to bag"
     * and the count is still 1. RUN.
     */
    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1]]);

    $response = slsAs($cart)->postJson('/api/cart/add', [
        'product_id' => $toner->id,
        'quantity' => 1,
    ])->assertOk();

    expect($response->json('toast'))
        ->toContain('1025 Dokdo Toner')
        ->and($response->json('toast'))->toContain('Medicube booster set')
        ->and($response->json('toast'))->toContain('has been taken out of your bag');

    // And the basket really is back to the set alone.
    expect($response->json('count'))->toBe(1)
        ->and($cart->fresh(['items'])->items)->toHaveCount(1);
});

it('keeps the sentence through a second load of the same basket', function () {
    /*
     * ▲ THE ONE THAT ONLY BITES ON SOME OF THE ENDPOINTS.
     *
     * remove() and coupon() each call CartController::loadCart() TWICE — once
     * to do the work and once to render the reply. The second call finds a
     * basket the first has already reconciled and correctly reports that it
     * took nothing. Assigned rather than accumulated, that second answer threw
     * away the sentence the first call earned, and the reply came back saying
     * "Removed" with no explanation of why a SECOND line had gone with the one
     * the shopper actually pressed.
     *
     * Two lines of the toner here, so removing one leaves a line for the
     * reconciler to find on the same request.
     *
     * MUTATION NOTE. Change the `array_merge($this->setStockNotices, $took)` in
     * CartController::loadCart() back to a plain assignment and this is red:
     * the toast is "Removed". RUN.
     */
    $toner = slsProduct('1025 Dokdo Toner', 1);
    $spare = slsProduct('Spare Cleanser', 9);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);

    $cart = slsCart([[$set, 1], [$spare, 1], [$toner, 1]]);
    $spareLine = $cart->items->firstWhere('product_id', $spare->id);

    $response = slsAs($cart)->postJson('/api/cart/remove', ['item_id' => $spareLine->id])->assertOk();

    expect($response->json('toast'))->toContain('has been taken out of your bag')
        ->and($response->json('toast'))->toContain('1025 Dokdo Toner');

    expect($cart->fresh(['items'])->items)->toHaveCount(1);
});

it('says it in the drawer as well, which is where the shopper is standing', function () {
    /*
     * The third surface a shopper meets their basket in, and the one they are
     * looking at when they press Add to bag. The drawer used to show the line
     * simply gone.
     *
     * ▲ AND IT IS NOT IN `.kc-ship`. That band is hidden outright by
     * `.cp-noship .kc-ship{display:none}` on a shop that has switched the
     * free-delivery bar off in Appearance → Cart panel, so a notice wearing
     * that class would be invisible on exactly the shops that turned one
     * control off. It sits inside `.dbody`, which carries the panel's own
     * padding at both widths.
     *
     * MUTATION NOTE. Delete the notice block from
     * resources/views/partials/cart-drawer.blade.php and this is red. RUN.
     */
    $toner = slsProduct('1025 Dokdo Toner', 1);
    $set = slsSet('Medicube booster set', [[$toner, 1]]);
    $cart = slsCart([[$set, 1]]);

    $response = slsAs($cart)->postJson('/api/cart/add', [
        'product_id' => $toner->id,
        'quantity' => 1,
    ])->assertOk();

    $drawer = (string) $response->json('drawer');

    expect($drawer)->toContain('1025 Dokdo Toner')
        ->and($drawer)->toContain('Medicube booster set')
        ->and($drawer)->toContain('has been taken out of your bag');

    /*
     * And it is inside the item list rather than in the band that can be
     * switched off — asserted at the markup, because the whole point of the
     * placement is that a setting cannot hide it.
     */
    expect($drawer)->toContain('class="kc-note"')
        ->and(strpos($drawer, 'class="kc-note"'))->toBeGreaterThan(
            (int) strpos($drawer, 'class="dbody"'),
            'the notice must sit inside .dbody, not in the switchable .kc-ship band'
        );

    // The set survives, so the drawer always has a body to put this in.
    expect($response->json('count'))->toBe(1);
});
