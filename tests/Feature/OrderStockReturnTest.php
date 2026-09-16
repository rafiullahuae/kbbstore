<?php

declare(strict_types=1);

/**
 * Lane CQ — units come back when an order is called off.
 *
 * WHAT WAS WRONG. Lane CM made a placed order take units off the shelf and
 * nothing anywhere put them back. So the shop sold one jar, the order was
 * cancelled an hour later, and that jar was gone from the count for ever: the
 * shelf said zero, `stock_status` said outofstock, and the shop then refused to
 * sell something it was physically holding. Every cancelled order made the
 * catalogue a little more wrong, permanently, in the direction of lost sales.
 *
 * ---------------------------------------------------------------------------
 * The three questions this had to answer before a single unit could go back
 * ---------------------------------------------------------------------------
 *
 * 1. WHICH ORDERS EVER TOOK ANYTHING. Imported WooCommerce orders never did.
 *    Orders typed by staff through ManualOrderBuilder never did, and that class
 *    says so in its own comment on purpose. An order placed while a product had
 *    `manage_stock` off took nothing even though it has a line for it. Crediting
 *    any of those invents inventory, which is worse than the bug.
 *
 * 2. WHAT STOPS A DOUBLE RETURN. Cancelling twice, or cancelling and then
 *    refunding, must not credit the shelf twice.
 *
 * 3. WHICH TRANSITIONS COUNT. A cancelled order that never shipped left its
 *    units in the stock room. A COMPLETED order moved to cancelled did not —
 *    that parcel is with the customer, and whether it came back is something
 *    only the person who handled it knows.
 *
 * All three are answered by the same thing: `order_stock_claims`, a record
 * written at claim time by App\Services\StockClaim saying which shelf lost how
 * many units for which order, and carrying its own `released_at`. It is a
 * recorded FACT rather than an inference, because every way of deriving the
 * answer afterwards — from the order's lines, from `manage_stock` as it stands
 * now, from which shelf a line points at today — is wrong in this shop in a
 * different way. The migration that creates the table sets out each one.
 *
 * WHAT IS DELIBERATELY NOT HERE: `refunded`. See OrderTransitionStock.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Orders\OrderTransitionStock;
use App\Services\StockClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
        'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function cqrProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'cqr-' . Str::random(8),
        'name' => 'Snail Mucin Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ], $overrides));
}

/** A real storefront placement, so the claim under test is the one place() makes. */
function cqrPlace(Product $product, int $qty = 1, ?ProductVariant $variant = null): ?Order
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'quantity' => $qty,
        'unit_price' => 13000,
    ]);

    test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ]);

    return Order::latest('id')->first();
}

/** Cancel through the rule, the way every status-write site now does. */
function cqrCancel(Order $order, ?string $from = null): int
{
    return app(OrderTransitionStock::class)
        ->applied((int) $order->id, $from ?? (string) $order->status, 'cancelled');
}

/*
|------------------------------------------------------------------------------
| 1. The bug itself
|------------------------------------------------------------------------------
*/

it('puts the units back when a placed order is cancelled', function () {
    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    expect((int) $product->fresh()->stock)->toBe(3, 'The placement did not take the units in the first place.');

    cqrCancel($order);

    expect((int) $product->fresh()->stock)
        ->toBe(5, 'A cancelled order kept its units off the shelf — the shop cannot sell what it is holding.');
});

it('puts a sold-out product back in the shop when the order that emptied it is cancelled', function () {
    // The half that costs the sales. Returning the number but leaving
    // stock_status at outofstock means every listing, every card and the
    // product page still say sold out, so nobody can buy the unit that is
    // sitting on the shelf again.
    $product = cqrProduct(['manage_stock' => true, 'stock' => 1]);

    $order = cqrPlace($product, 1);

    expect($product->fresh()->stock_status)->toBe('outofstock');

    cqrCancel($order);

    $product->refresh();

    expect((int) $product->stock)->toBe(1);
    expect($product->stock_status)->toBe('instock', 'The unit is back on the shelf and the shop still says sold out.');
});

it('returns a variant sale to the variant shelf, not the parent', function () {
    $product = cqrProduct(['type' => 'variable', 'manage_stock' => true, 'stock' => 10]);

    $variant = ProductVariant::create([
        'product_id' => $product->id, 'sku' => 'S-50', 'price' => 13000,
        'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 4, 'position' => 0,
    ]);

    $order = cqrPlace($product, 2, $variant);

    expect((int) $variant->fresh()->stock)->toBe(2);

    cqrCancel($order);

    expect((int) $variant->fresh()->stock)->toBe(4, 'The variant shelf did not get its units back.');
    expect((int) $product->fresh()->stock)->toBe(10, 'The parent shelf was credited for a variant sale — invented inventory.');
});

/*
|------------------------------------------------------------------------------
| 2. What stops a double return
|------------------------------------------------------------------------------
*/

it('does not credit the shelf twice when an order is cancelled twice', function () {
    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    expect(cqrCancel($order, 'pending'))->toBe(2);
    expect(cqrCancel($order, 'pending'))->toBe(0, 'A second cancellation credited the shelf again.');

    expect((int) $product->fresh()->stock)
        ->toBe(5, 'Cancelling twice invented two extra units of stock.');
});

it('does not credit the shelf again when a second returning transition arrives', function () {
    /*
     * Two different screens, two returning transitions, one set of units.
     *
     * Both calls below pass a pre-dispatch origin, so BOTH pass the transition
     * rule and reach the ledger — which is the point. The guard being tested is
     * the ledger's `released_at`, not OrderTransitionStock's opinion about
     * statuses: a test whose second call the rule refuses outright would pass
     * with no ledger guard at all.
     *
     * This is the real shape of it, too. An operator cancels an order on the
     * detail screen while a gateway webhook is marking the same order failed.
     */
    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    expect(app(OrderTransitionStock::class)->applied((int) $order->id, 'pending', 'cancelled'))->toBe(2);
    expect(app(OrderTransitionStock::class)->applied((int) $order->id, 'pending', 'failed'))
        ->toBe(0, 'A second returning transition credited the same units again.');

    expect((int) $product->fresh()->stock)
        ->toBe(5, 'Two routes to the same conclusion credited the same units twice.');
});

it('records the release rather than inferring it from the order status', function () {
    /*
     * The recorded fact, asserted directly. `cancelled` looks identical
     * whether the units went back a second ago or a month ago, so the order's
     * own status cannot be the guard. Each claim row carries its own
     * released_at and is claimed by a conditional UPDATE before any stock moves.
     */
    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    $claims = DB::table('order_stock_claims')->where('order_id', $order->id)->get();

    expect($claims)->toHaveCount(1, 'The placement recorded no claim, so nothing could ever be returned.');
    expect((int) $claims[0]->quantity)->toBe(2);
    expect($claims[0]->released_at)->toBeNull();

    cqrCancel($order, 'pending');

    expect(app(StockClaim::class)->outstandingFor((int) $order->id))
        ->toBe(0, 'The claim is still outstanding after a release.');
});

/*
|------------------------------------------------------------------------------
| 3. Orders that never claimed anything get nothing back
|------------------------------------------------------------------------------
*/

it('gives nothing back for an imported order that never claimed stock', function () {
    // The WooCommerce import writes order rows directly. They have lines, they
    // have statuses, and not one unit of their stock ever passed through this
    // application. Crediting them would add inventory the shop does not have.
    $product = cqrProduct(['manage_stock' => true, 'stock' => 3]);

    $order = Order::create([
        'order_number' => 'WC-48231',
        'email' => 'imported@example.com',
        'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 13000, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 13000,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'name' => $product->name,
        'quantity' => 2,
        'unit_price' => 13000,
        'subtotal' => 26000,
        'total' => 26000,
    ]);

    expect(cqrCancel($order, 'processing'))->toBe(0);

    expect((int) $product->fresh()->stock)
        ->toBe(3, 'Cancelling an imported order invented stock the shop never had.');
});

it('gives nothing back for an order placed while the product counted no stock', function () {
    /*
     * The case that catches a derivation dead. The order has a line for this
     * product and the product counts stock TODAY — so anything that looked at
     * `manage_stock` at return time would credit the shelf. Nothing was ever
     * taken: `manage_stock` was off when the order was written.
     */
    $product = cqrProduct(['manage_stock' => false, 'stock' => 3]);

    $order = cqrPlace($product, 2);

    expect((int) $product->fresh()->stock)->toBe(3, 'An uncounted product was decremented.');

    // The owner switches counting on afterwards, which is an ordinary thing to do.
    $product->forceFill(['manage_stock' => true])->save();

    expect(cqrCancel($order, 'pending'))->toBe(0);

    expect((int) $product->fresh()->stock)
        ->toBe(3, 'Units were credited for an order that never took any.');
});

it('returns what the claim took, not what the order lines now say', function () {
    /*
     * The admin order screen can add, edit and remove lines after placement
     * (AdminOrderController::addItem, updateItem, removeItem). A line added by
     * hand never came off a shelf, so a return derived from the lines would
     * credit it.
     */
    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);
    $other = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    // An operator adds a line for a different product after the fact.
    $order->items()->create([
        'product_id' => $other->id,
        'name' => $other->name,
        'quantity' => 3,
        'unit_price' => 13000,
        'subtotal' => 39000,
        'total' => 39000,
    ]);

    cqrCancel($order, 'pending');

    expect((int) $product->fresh()->stock)->toBe(5, 'The claimed units did not come back.');
    expect((int) $other->fresh()->stock)
        ->toBe(5, 'A line added by hand after placement had units credited for it that were never taken.');
});

it('leaves ManualOrderBuilder claiming and returning nothing, as its comment says', function () {
    /*
     * Structural, because the property is "this path does not participate" and
     * the way to break it is to add a call, not to change a number.
     *
     * READ OFF THE TOKENS, NOT THE TEXT, and this test failed on its first run
     * for precisely the reason CLAUDE.md's neighbours keep paying for: a regex
     * over a source file reads comments as code. ManualOrderBuilder's class
     * comment names claimStock() and StockClaim in the course of explaining
     * WHY it calls neither, so a str_contains() guard reported the explanation
     * as the offence. token_get_all() drops comments and doc comments, so what
     * is left is the code — which is what this is asking about.
     */
    $tokens = token_get_all(file_get_contents(base_path('app/Services/ManualOrderBuilder.php')));

    $code = '';

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    expect(str_contains($code, 'claimStock'))
        ->toBeFalse('ManualOrderBuilder now claims stock, which its class comment says it does not.');

    expect(str_contains($code, 'StockClaim'))
        ->toBeFalse('ManualOrderBuilder now moves stock, which its class comment says it does not.');
});

/*
|------------------------------------------------------------------------------
| 4. Which transitions count
|------------------------------------------------------------------------------
*/

it('does not credit the shelf for a dispatched order that is later cancelled', function () {
    /*
     * The parcel has gone. An operator cancelling a completed order is either
     * correcting the books or recording a return, and only they know which —
     * OrdersApiController makes them pass `force` for exactly this reason,
     * because it takes money off the store's figures. Crediting the shelf here
     * would tell the stock room it has a jar that is in somebody's bathroom.
     */
    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    // It shipped.
    $order->forceFill(['status' => 'shipped'])->save();

    expect(cqrCancel($order, 'shipped'))->toBe(0);
    expect(cqrCancel($order, 'completed'))->toBe(0);

    expect((int) $product->fresh()->stock)
        ->toBe(3, 'A parcel that went to the customer was credited back to the shelf.');
});

it('gives the units back when a gateway declines and the order is left failed', function () {
    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    expect(app(OrderTransitionStock::class)->applied((int) $order->id, 'pending', 'failed'))
        ->toBe(2, 'An order that will never be paid for is still holding its units.');

    expect((int) $product->fresh()->stock)->toBe(5);
});

it('does not re-list a product the owner marked sold out by hand', function () {
    /*
     * A claim re-lists a shelf only when THAT claim is what delisted it —
     * undoing our own write, not making a judgement about what belongs in the
     * shop window. Here the owner has discontinued the product; an unrelated
     * cancellation must not put it back on sale.
     */
    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    // Nothing was emptied, so no claim row flags a status change.
    $product->forceFill(['stock_status' => 'outofstock'])->save();

    cqrCancel($order, 'pending');

    $product->refresh();

    expect((int) $product->stock)->toBe(5, 'The units did not come back.');
    expect($product->stock_status)
        ->toBe('outofstock', 'A cancellation put a discontinued product back on sale.');
});

/*
|------------------------------------------------------------------------------
| 5. Every screen that writes the status means the same thing by it
|------------------------------------------------------------------------------
*/

it('returns stock through the orders list bulk action too', function () {
    // The bulk path is a mass Order::whereIn(...)->update() that fires no
    // Eloquent events at all, which is why no observer could ever have done
    // this and why it has to be called explicitly.
    \Tests\Support\OrdersAdminRoutes::wire(app());

    $admin = \App\Models\AdminUser::create([
        'name' => 'Owner',
        'email' => 'cqr-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $product = cqrProduct(['manage_stock' => true, 'stock' => 5]);

    $order = cqrPlace($product, 2);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders-bulk-status', [
            'ids' => [$order->id],
            'status' => 'cancelled',
            'force' => true,
        ])
        ->assertOk();

    expect((string) $order->fresh()->status)->toBe('cancelled');

    expect((int) $product->fresh()->stock)
        ->toBe(5, 'Cancelling from the orders list did not return the units, so two screens disagree.');
});

it('has every order-status write site calling the one rule', function () {
    /*
     * THE GUARD THAT MATTERS MOST, and the reason this lane could be written at
     * all. Store\CheckoutController::place() records at length why coupon
     * redemptions are not released on cancellation: `orders.status` is written
     * from four places, no two of them the same way, and a rule hooked to some
     * of them makes the books disagree depending on which screen the operator
     * used. A fifth site appearing unhooked is exactly how that comes back.
     *
     * Read off the FILES. Each name below is a file that writes the column;
     * each must mention OrderTransitionStock. This is coarse on purpose — it
     * cannot tell a correct call from a wrong one — but it is the thing that
     * fails the day somebody adds a screen and forgets.
     */
    $sites = [
        'app/Http/Controllers/Admin/AdminController.php',
        'app/Http/Controllers/Admin/AdminOrderController.php',
        'app/Http/Controllers/Admin/OrdersApiController.php',
        'app/Http/Controllers/Store/CheckoutController.php',
        'app/Http/Controllers/Api/CheckoutController.php',
        'app/Services/Payments/PaymentConfirmer.php',
    ];

    $unhooked = [];

    foreach ($sites as $relative) {
        if (! str_contains(file_get_contents(base_path($relative)), 'OrderTransitionStock')) {
            $unhooked[] = $relative;
        }
    }

    expect($unhooked)->toBe([], 'These write an order status without going through the one rule: ' . implode(', ', $unhooked));
});
