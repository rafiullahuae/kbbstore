<?php

declare(strict_types=1);

/**
 * Lane EW — an order that comes back from the dead takes back what it gave up.
 *
 * ---------------------------------------------------------------------------
 * WHAT WAS WRONG
 * ---------------------------------------------------------------------------
 *
 * Release was one-way by construction. OrderTransitionStock::RETURNS_STOCK
 * only ever returned units; CouponService::releaseRedemptions() only ever
 * released uses; nothing anywhere re-took either one. And both revive paths
 * were wide open — AdminController::updateOrderStatus validated `status`
 * against the whole nine-value vocabulary with no from->to rules at all, and
 * OrdersApiController::BULK_SETTABLE carries `pending`, `processing` and
 * `onhold`, so a bulk action did it forty orders at a time.
 *
 * So: an order for the last jar in the shop, with a one-use code on it.
 * Cancel — the jar goes back on the shelf, `coupons.usage_count` drops 1 -> 0,
 * the redemption row is stamped `released_at`. Then an operator sets the
 * status back to `processing` from the dropdown on the order screen.
 *
 * The result was `stock = 1` and `usage_count = 0` while a LIVE `processing`
 * order still held a line for that jar and still carried the discount. The jar
 * was on the shelf and sellable to the next shopper while the revived order
 * still had to ship it — an OVERSELL. The one-use code read unused while a
 * live order was using it — a DOUBLE-SPEND. Neither was visible anywhere: the
 * endpoint answered `ok: true` and the screen said "Order updated".
 *
 * Separately: a late payment webhook that PaymentConfirmer correctly refused
 * while the order was cancelled ("order is no longer live") was ACCEPTED once
 * the status had been moved back out of the void statuses. The protection
 * shipped in 2.60.199 was walked around by an operator action that had no idea
 * it was doing it.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS PINNED HERE
 * ---------------------------------------------------------------------------
 *
 *  1. The two bugs themselves, reproduced through the real endpoints.
 *  2. The refusal: when the jar has been sold since, or the code redeemed to
 *     its limit since, the WHOLE transition is refused, the order is left
 *     exactly as it was, and the sentence names what stopped it.
 *  3. The four judgement calls, each of which is a way to get this wrong in
 *     the opposite direction — re-taking twice, re-taking what was never
 *     given back, refusing a revive that costs nothing, and half-applying.
 *  4. Both paths. A guard on the single-order endpoint that the bulk endpoint
 *     walks around is not a guard.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\OrderTransitionStock;
use App\Services\Payments\PaymentConfirmer;
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

function ewAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Revive Admin',
        'email' => 'ew-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

function ewProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'ew-' . Str::random(8),
        'name' => 'Last Jar Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ], $overrides));
}

/**
 * A REAL storefront placement, so every claim and redemption under test is the
 * one Store\CheckoutController::place() actually writes. Nothing here is
 * hand-built: a ledger row inserted by a test proves only that the test can
 * insert one.
 */
function ewPlace(Product $product, int $qty = 1, ?Coupon $coupon = null): ?Order
{
    /*
     * SEVERAL PLACEMENTS IN ONE TEST, which nearly every case below needs —
     * the whole point is what happens when somebody ELSE buys the jar a
     * cancellation put back.
     *
     * CartService is bound `scoped` and memoises the cart it resolved for the
     * life of that scope. In production the scope is one request; inside one
     * test method the container is not rebooted between posts, so the second
     * placement is handed the FIRST basket and refuses it as sold out —
     * which measures the harness rather than the shop. Clearing the memo is
     * exactly what the end of a request does to it, and it is all this stands
     * in for; nothing about the code under test is relaxed.
     */
    app(CartService::class)->forget();

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'coupon_id' => $coupon?->id,
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => $qty,
        'unit_price' => 13000,
    ]);

    $before = (int) Order::max('id');

    test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
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

    /*
     * The order this call made, by id, rather than `latest('id')->first()`.
     * A placement that is REFUSED — which several cases below arrange on
     * purpose — writes no order, and `latest` would hand back the previous
     * test's one and quietly measure the wrong thing.
     */
    return Order::where('id', '>', $before)->orderBy('id')->first();
}

/** A one-use code, the kind a shop actually gets burned by. */
function ewCoupon(array $overrides = []): Coupon
{
    return Coupon::create(array_merge([
        'code' => 'EW' . strtoupper(Str::random(6)),
        'type' => 'fixed_cart',
        'amount' => 1000,
        'usage_limit' => 1,
        'usage_count' => 0,
    ], $overrides));
}

/** The operator's dropdown on the order screen. */
function ewSetStatus(Order $order, string $status)
{
    return test()->actingAs(ewAdmin(), 'admin')
        ->putJson('/admin-api/orders/' . $order->id . '/status', ['status' => $status]);
}

/** The orders list, with a selection. */
function ewBulkStatus(array $ids, string $status, bool $force = false)
{
    return test()->actingAs(ewAdmin(), 'admin')
        ->postJson('/admin-api/orders-bulk-status', ['ids' => $ids, 'status' => $status, 'force' => $force]);
}

/*
|------------------------------------------------------------------------------
| 1. The oversell, through the screen an operator actually uses
|------------------------------------------------------------------------------
*/

it('takes the unit back off the shelf when a cancelled order is set to processing', function () {
    $product = ewProduct(['manage_stock' => true, 'stock' => 1]);

    $order = ewPlace($product, 1);

    expect((int) $product->fresh()->stock)->toBe(0, 'The placement did not take the unit in the first place.');

    ewSetStatus($order, 'cancelled')->assertOk();

    expect((int) $product->fresh()->stock)->toBe(1, 'The cancellation did not put the unit back.');

    // The revive. This is the press that used to oversell.
    ewSetStatus($order, 'processing')->assertOk();

    expect($order->fresh()->status)->toBe('processing')
        ->and((int) $product->fresh()->stock)
        ->toBe(0, 'The revived order is live and still has to ship this unit, but the shop is offering it for sale again.')
        // And the shop window agrees with the shelf, which is the half that
        // costs the sale rather than the goodwill.
        ->and($product->fresh()->stock_status)->toBe('outofstock')
        ->and(app(StockClaim::class)->outstandingFor((int) $order->id))->toBe(1);
});

it('takes the coupon use back when a cancelled order is set to processing', function () {
    $coupon = ewCoupon();
    $product = ewProduct(['manage_stock' => true, 'stock' => 5]);

    $order = ewPlace($product, 1, $coupon);

    expect((int) $coupon->fresh()->usage_count)->toBe(1, 'The placement did not spend the code.');

    ewSetStatus($order, 'cancelled')->assertOk();

    expect((int) $coupon->fresh()->usage_count)->toBe(0)
        ->and(CouponRedemption::where('order_id', $order->id)->first()->released_at)->not->toBeNull();

    ewSetStatus($order, 'processing')->assertOk();

    expect((int) $coupon->fresh()->usage_count)
        ->toBe(1, 'A live order is carrying this discount while the one-use code reads unused — anyone can spend it again.')
        ->and(CouponRedemption::where('order_id', $order->id)->first()->released_at)->toBeNull();
});

it('says in the order history what the revive cost', function () {
    // The behaviour change is operator-facing, so it has to be legible. The
    // cancellation note says what was handed back; this one has to say what was
    // taken again, or the pair does not add up on the screen.
    $coupon = ewCoupon();
    $product = ewProduct(['manage_stock' => true, 'stock' => 2]);

    $order = ewPlace($product, 1, $coupon);

    ewSetStatus($order, 'cancelled')->assertOk();
    ewSetStatus($order, 'processing')->assertOk();

    $note = DB::table('order_notes')->where('order_id', $order->id)->orderByDesc('id')->value('content');

    expect($note)->toContain('cancelled to processing')
        ->and($note)->toContain('1 unit taken back off the shelf')
        ->and($note)->toContain($coupon->code)
        ->and($note)->toContain('re-applied');
});

/*
|------------------------------------------------------------------------------
| 2. The refusal — and that it leaves NOTHING half-applied
|------------------------------------------------------------------------------
*/

it('refuses the revive and names the product when the unit has been sold since', function () {
    $product = ewProduct(['manage_stock' => true, 'stock' => 1]);

    $order = ewPlace($product, 1);
    ewSetStatus($order, 'cancelled')->assertOk();

    // Somebody else bought the jar that went back on the shelf. This is the
    // whole point of the cancellation having returned it.
    $second = ewPlace($product, 1);

    expect((int) $product->fresh()->stock)->toBe(0)
        ->and($second->id)->not->toBe($order->id);

    $response = ewSetStatus($order, 'processing');

    $response->assertStatus(422)
        ->assertJsonPath('error', 'revive_refused')
        ->assertJsonPath('kind', 'stock')
        ->assertJsonPath('status', 'cancelled');

    // The refusal has to name the product, or it tells the owner nothing he
    // can act on — least of all on a forty-order bulk action.
    expect($response->json('message'))
        ->toContain('Last Jar Serum')
        ->toContain('short by 1');

    // NOTHING moved. Not the status, not the shelf, not the second order's
    // claim on it.
    expect($order->fresh()->status)->toBe('cancelled')
        ->and((int) $product->fresh()->stock)->toBe(0)
        ->and(app(StockClaim::class)->outstandingFor((int) $order->id))->toBe(0)
        ->and(app(StockClaim::class)->outstandingFor((int) $second->id))->toBe(1);
});

it('refuses the revive and names the coupon when the code has been redeemed to its limit since', function () {
    $coupon = ewCoupon();
    $product = ewProduct(['manage_stock' => true, 'stock' => 10]);

    $order = ewPlace($product, 1, $coupon);
    ewSetStatus($order, 'cancelled')->assertOk();

    // Somebody else spent the use that was handed back.
    $second = ewPlace($product, 1, $coupon);

    expect((int) $coupon->fresh()->usage_count)->toBe(1);

    $response = ewSetStatus($order, 'processing');

    $response->assertStatus(422)
        ->assertJsonPath('error', 'revive_refused')
        ->assertJsonPath('kind', 'coupon');

    expect($response->json('message'))->toContain($coupon->code);

    expect($order->fresh()->status)->toBe('cancelled')
        ->and((int) $coupon->fresh()->usage_count)->toBe(1)
        ->and(CouponRedemption::where('order_id', $order->id)->first()->released_at)->not->toBeNull();
});

it('leaves the stock alone when the coupon half is what refuses', function () {
    /*
     * THE HALF-APPLIED CASE, and the reason both halves are in one
     * transaction. Stock is re-taken first; if the coupon then refuses, a
     * revive that "failed" would otherwise have quietly taken units off the
     * shelf for an order that is still cancelled — inventory destroyed by a
     * refusal.
     */
    $coupon = ewCoupon();
    $product = ewProduct(['manage_stock' => true, 'stock' => 3]);

    $order = ewPlace($product, 2, $coupon);

    expect((int) $product->fresh()->stock)->toBe(1);

    ewSetStatus($order, 'cancelled')->assertOk();

    expect((int) $product->fresh()->stock)->toBe(3);

    // The code is spent elsewhere, so only the coupon half can fail.
    DB::table('coupons')->where('id', $coupon->id)->update(['usage_count' => 1]);

    ewSetStatus($order, 'processing')->assertStatus(422);

    expect((int) $product->fresh()->stock)
        ->toBe(3, 'The stock half was applied and left applied by a transition that was refused.')
        ->and($product->fresh()->stock_status)->toBe('instock')
        ->and(app(StockClaim::class)->outstandingFor((int) $order->id))->toBe(0)
        ->and($order->fresh()->status)->toBe('cancelled');
});

/*
|------------------------------------------------------------------------------
| 3. The judgement calls, each a way to be wrong in the other direction
|------------------------------------------------------------------------------
*/

it('does not take the units twice when an order is cancelled and revived twice', function () {
    $product = ewProduct(['manage_stock' => true, 'stock' => 4]);
    $coupon = ewCoupon(['usage_limit' => null]);

    $order = ewPlace($product, 1, $coupon);

    foreach ([1, 2] as $round) {
        ewSetStatus($order, 'cancelled')->assertOk();
        ewSetStatus($order, 'processing')->assertOk();

        expect((int) $product->fresh()->stock)->toBe(3, 'Round ' . $round . ' double-claimed the shelf.')
            ->and((int) $coupon->fresh()->usage_count)->toBe(1, 'Round ' . $round . ' double-spent the code.');
    }
});

it('does not take back stock an order never gave up', function () {
    /*
     * The same bug pointing the other way. A `shipped` order cancelled returns
     * NOTHING — the parcel is with the customer and OrderTransitionStock says
     * so in as many words. Re-taking on the way back would invent a shortage
     * out of thin air, and on a shop with one jar left it would refuse a revive
     * that costs nothing at all.
     */
    $product = ewProduct(['manage_stock' => true, 'stock' => 2]);

    $order = ewPlace($product, 1);

    expect((int) $product->fresh()->stock)->toBe(1);

    // Dispatched, then cancelled from there — a correction to the books.
    ewSetStatus($order, 'shipped')->assertOk();
    ewSetStatus($order, 'cancelled')->assertOk();

    expect((int) $product->fresh()->stock)
        ->toBe(1, 'The cancellation credited the shelf for a parcel that had gone.');

    // And back again. Nothing was returned, so nothing may be taken.
    ewSetStatus($order, 'processing')->assertOk();

    expect((int) $product->fresh()->stock)
        ->toBe(1, 'The revive took units the cancellation never put back.')
        ->and(app(StockClaim::class)->outstandingFor((int) $order->id))->toBe(1);
});

it('takes back the units of an order that was cancelled, then refunded, then revived', function () {
    /*
     * Why the LEDGER decides and not the from->to statuses. `refunded` is not
     * in RETURNS_STOCK — deliberately; a refund is a statement about money,
     * not goods — so a rule written as "returns(), read backwards" answers
     * "this order gave nothing back" about an order whose jar is sitting on
     * the shelf from the cancellation two steps earlier. That is the oversell
     * surviving on the likeliest path of all.
     */
    $product = ewProduct(['manage_stock' => true, 'stock' => 1]);

    $order = ewPlace($product, 1);

    ewSetStatus($order, 'cancelled')->assertOk();

    expect((int) $product->fresh()->stock)->toBe(1);

    // cancelled -> refunded is not a revive: both mean the sale is off.
    ewSetStatus($order, 'refunded')->assertOk();

    expect((int) $product->fresh()->stock)
        ->toBe(1, 'cancelled -> refunded is not a revive and must take nothing.');

    ewSetStatus($order, 'processing')->assertOk();

    expect((int) $product->fresh()->stock)
        ->toBe(0, 'The units released by the cancellation two steps back were never re-taken.');
});

it('revives a refunded order that never released anything without touching the shelf', function () {
    /*
     * The strangest case, and the answer is that `refunded` is not special —
     * the ledger is. A refund on its own returns no units (OrderTransitionStock
     * excludes it on purpose), so reviving from `refunded` takes no units. It
     * DOES take the coupon use back, because `refunded` IS in RELEASES_COUPON
     * and the refund handed that use out.
     */
    $coupon = ewCoupon();
    $product = ewProduct(['manage_stock' => true, 'stock' => 3]);

    $order = ewPlace($product, 1, $coupon);

    expect((int) $product->fresh()->stock)->toBe(2);

    ewSetStatus($order, 'refunded')->assertOk();

    expect((int) $product->fresh()->stock)->toBe(2, 'A refund returned units it has no business returning.')
        ->and((int) $coupon->fresh()->usage_count)->toBe(0, 'The refund did not hand the use back.');

    ewSetStatus($order, 'processing')->assertOk();

    expect($order->fresh()->status)->toBe('processing')
        ->and((int) $product->fresh()->stock)
        ->toBe(2, 'Reviving a refunded order invented a shortage: nothing was ever returned to take back.')
        ->and((int) $coupon->fresh()->usage_count)
        ->toBe(1, 'A live order is carrying a discount the code reads as unspent.');
});

it('does not refuse a revive over a coupon that has merely expired', function () {
    /*
     * A refusal that fires when it should not stops an owner fixing a
     * mis-cancelled order, and on a host with no shell there is no other way
     * in. An expiry date governs who may APPLY a code; it is not a scarce
     * resource. Nobody else can spend an expired code, so taking its use back
     * costs the shop nothing — and the row going back to counting is what
     * keeps Store -> Coupons agreeing with the order that is printing the
     * discount.
     */
    $coupon = ewCoupon();
    $product = ewProduct(['manage_stock' => true, 'stock' => 3]);

    $order = ewPlace($product, 1, $coupon);

    ewSetStatus($order, 'cancelled')->assertOk();

    $coupon->forceFill(['expires_at' => now()->subDay()])->save();

    ewSetStatus($order, 'processing')->assertOk();

    expect($order->fresh()->status)->toBe('processing')
        ->and((int) $coupon->fresh()->usage_count)->toBe(1);
});

it('is not a revive to move between two statuses that both mean the sale is off', function () {
    // cancelled -> failed. Nothing was handed back on that edge, so nothing
    // may be taken on it, and the order is still not live.
    $product = ewProduct(['manage_stock' => true, 'stock' => 2]);

    $order = ewPlace($product, 1);

    ewSetStatus($order, 'cancelled')->assertOk();

    expect((int) $product->fresh()->stock)->toBe(2);

    ewSetStatus($order, 'failed')->assertOk();

    expect((int) $product->fresh()->stock)
        ->toBe(2, 'Moving between two void statuses took units off the shelf.');
});

/*
|------------------------------------------------------------------------------
| 4. The bulk path — a guard the bulk endpoint walks around is not a guard
|------------------------------------------------------------------------------
*/

it('takes stock and the coupon back on a bulk revive too', function () {
    $coupon = ewCoupon(['usage_limit' => null]);
    $product = ewProduct(['manage_stock' => true, 'stock' => 5]);

    $order = ewPlace($product, 2, $coupon);

    ewSetStatus($order, 'cancelled')->assertOk();

    expect((int) $product->fresh()->stock)->toBe(5)
        ->and((int) $coupon->fresh()->usage_count)->toBe(0);

    ewBulkStatus([$order->id], 'processing')
        ->assertOk()
        ->assertJsonPath('changed', 1)
        ->assertJsonPath('skipped', []);

    expect((int) $product->fresh()->stock)
        ->toBe(3, 'The orders list revived an order without taking its units back.')
        ->and((int) $coupon->fresh()->usage_count)->toBe(1);
});

it('reports a bulk revive it cannot pay for by order number, and moves the rest', function () {
    $product = ewProduct(['manage_stock' => true, 'stock' => 1]);
    $plenty = ewProduct(['manage_stock' => true, 'stock' => 9, 'name' => 'Plenty Cream']);

    $short = ewPlace($product, 1);
    $fine = ewPlace($plenty, 1);

    ewSetStatus($short, 'cancelled')->assertOk();
    ewSetStatus($fine, 'cancelled')->assertOk();

    // The jar that went back on the shelf is sold to somebody else.
    ewPlace($product, 1);

    $response = ewBulkStatus([$short->id, $fine->id], 'processing');

    $response->assertOk()
        // The safe half of the selection is still what the operator meant.
        ->assertJsonPath('changed', 1)
        ->assertJsonPath('skipped.0.id', $short->id)
        ->assertJsonPath('skipped.0.label', (string) $short->order_number)
        // `force` is the operator's answer to "this takes money off your
        // figures". It is not an answer to "that jar has been sold".
        ->assertJsonPath('skipped.0.forceable', false);

    expect($response->json('skipped.0.reason'))->toContain('Last Jar Serum');

    expect($short->fresh()->status)->toBe('cancelled')
        ->and($fine->fresh()->status)->toBe('processing')
        ->and((int) $plenty->fresh()->stock)->toBe(8);
});

it('will not let force push a bulk revive past a shelf that cannot cover it', function () {
    // The screen offers "change those too" against the revenue guard. If that
    // button reached this refusal it would be a button whose entire job is to
    // create the oversell.
    $product = ewProduct(['manage_stock' => true, 'stock' => 1]);

    $order = ewPlace($product, 1);
    ewSetStatus($order, 'cancelled')->assertOk();
    ewPlace($product, 1);

    ewBulkStatus([$order->id], 'processing', force: true)
        ->assertOk()
        ->assertJsonPath('changed', 0)
        ->assertJsonPath('skipped.0.id', $order->id);

    expect($order->fresh()->status)->toBe('cancelled')
        ->and((int) $product->fresh()->stock)->toBe(0);
});

/*
|------------------------------------------------------------------------------
| 5. What this makes true about a late payment
|------------------------------------------------------------------------------
*/

it('goes on refusing a late payment while the revive is refused', function () {
    /*
     * PaymentConfirmer refuses on `cancelled` because the order has given back
     * what it was holding. An operator moving the status out of the void used
     * to walk straight around that. It is not reachable around any more: the
     * only way past the guard is a revive that has paid for itself, and this
     * one cannot.
     */
    $product = ewProduct(['manage_stock' => true, 'stock' => 1]);

    $order = ewPlace($product, 1);
    ewSetStatus($order, 'cancelled')->assertOk();
    ewPlace($product, 1);

    ewSetStatus($order, 'processing')->assertStatus(422);

    $outcome = app(PaymentConfirmer::class)->confirm(
        $order->fresh(), 'stripe', 'pi_ew_late', (int) $order->total, 'AED',
    );

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->message)->toBe('order is no longer live')
        ->and($order->fresh()->paid_at)->toBeNull();
});

it('accepts a late payment once the revive has paid for itself', function () {
    /*
     * And the honest other half: reviving DOES re-arm that path, on purpose.
     * The order is genuinely live again — its units are off the shelf and its
     * coupon use is spent — so a payment for it genuinely should be recorded.
     * Refusing would leave the shop holding money for an order it is shipping.
     */
    $product = ewProduct(['manage_stock' => true, 'stock' => 3]);

    $order = ewPlace($product, 1);
    ewSetStatus($order, 'cancelled')->assertOk();
    ewSetStatus($order, 'processing')->assertOk();

    $outcome = app(PaymentConfirmer::class)->confirm(
        $order->fresh(), 'stripe', 'pi_ew_ok', (int) $order->total, 'AED',
    );

    expect($outcome->accepted)->toBeTrue()
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and((int) $product->fresh()->stock)->toBe(2);
});

/*
|------------------------------------------------------------------------------
| 6. The funnel itself, directly
|------------------------------------------------------------------------------
*/

it('throws rather than returning quietly when a revive cannot be paid for', function () {
    // Every other caller of moveTo() — the gateways, the refunder, the
    // checkout controllers — moves orders INTO the void, never out. The throw
    // is what makes a new caller that revives one unable to ignore this.
    $product = ewProduct(['manage_stock' => true, 'stock' => 1]);

    $order = ewPlace($product, 1);

    app(OrderStatus::class)->moveTo($order, 'cancelled', by: 'test');

    ewPlace($product, 1);

    expect(fn () => app(OrderStatus::class)->moveTo($order, 'processing', by: 'test'))
        ->toThrow(\App\Services\Orders\OrderReviveRefused::class);

    expect($order->fresh()->status)->toBe('cancelled');
});

it('re-takes exactly the ledger rows a release stamped and no others', function () {
    // The property the whole design rests on, measured on the ledger rather
    // than inferred from a status.
    $product = ewProduct(['manage_stock' => true, 'stock' => 6]);

    $order = ewPlace($product, 2);
    $claims = DB::table('order_stock_claims')->where('order_id', $order->id);

    expect($claims->whereNull('released_at')->count())->toBe(1);

    app(OrderTransitionStock::class)->applied((int) $order->id, 'processing', 'cancelled');

    expect(DB::table('order_stock_claims')->where('order_id', $order->id)->whereNotNull('released_at')->count())->toBe(1);

    $taken = app(OrderTransitionStock::class)->reclaimed((int) $order->id, 'cancelled', 'processing');

    expect($taken)->toBe(2)
        ->and(DB::table('order_stock_claims')->where('order_id', $order->id)->whereNotNull('released_at')->count())->toBe(0)
        // And again: there is nothing left stamped, so nothing left to take.
        ->and(app(OrderTransitionStock::class)->reclaimed((int) $order->id, 'cancelled', 'processing'))->toBe(0)
        ->and((int) $product->fresh()->stock)->toBe(4);
});
