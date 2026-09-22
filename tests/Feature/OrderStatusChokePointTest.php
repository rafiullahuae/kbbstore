<?php

declare(strict_types=1);

/**
 * The one funnel every order status change goes through, and the coupon use it
 * hands back.
 *
 * WHAT WAS WRONG. A coupon redemption was recorded when an order was placed and
 * was never given back when the order came to nothing. A shopper whose one go
 * at a per-customer code went on an order that was then cancelled had spent it
 * for good: the code refused them, the order never shipped, and nothing on any
 * screen explained why. A public code with a usage limit could be exhausted by
 * anyone willing to place orders and cancel them.
 *
 * It was not an oversight. Store\CheckoutController::place() set out, at
 * length, why it released the use on a declined payment and could NOT do the
 * same for a cancellation: `orders.status` was written from nine places in
 * four shapes, and a release wired into some of them would make
 * `coupons.usage_count` disagree with the redemption rows depending on which
 * screen the operator happened to use. The funnel —
 * App\Services\Orders\OrderStatus — is what that note was waiting for.
 *
 * THESE TESTS ARE BEHAVIOURAL. None of them asserts that the funnel was called.
 * A future change that called it from four sites out of nine would satisfy that
 * and put the bug straight back; what is asserted is that the use is usable
 * again, that it comes back exactly once however many ways the order is closed,
 * and that an order which never redeemed anything gets nothing invented for it.
 */

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\PaymentConfirmer;
use App\Services\Payments\PaymentRefunder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OrdersAdminRoutes;

/* ------------------------------------------------------------------ fixtures */

function chokeAdmin(): void
{
    OrdersAdminRoutes::wire(app());

    test()->actingAs(\App\Models\AdminUser::create([
        'name' => 'Choke Owner',
        'email' => 'choke-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
}

function chokeCustomer(string $email = 'buyer@example.ae'): Customer
{
    return Customer::create(['name' => 'Choke Buyer', 'email' => $email]);
}

function chokeOrder(array $attributes = []): Order
{
    static $n = 0;
    $n++;

    return Order::create($attributes + [
        'order_number' => 'CHOKE-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'email' => 'buyer@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'discount_total' => 5000,
        'total' => 15000,
        'payment_method' => 'cod',
    ]);
}

/**
 * An order with a coupon really spent against it — through
 * CouponService::recordRedemption(), inside a transaction, exactly as both
 * placement paths do it. Seeding the redemption row by hand would prove
 * nothing about the counter it is supposed to move.
 */
function chokeOrderWithCoupon(Coupon $coupon, array $attributes = [], ?Customer $customer = null): Order
{
    return DB::transaction(function () use ($coupon, $attributes, $customer) {
        $order = chokeOrder($attributes + [
            'coupon_code' => $coupon->code,
            'customer_id' => $customer?->id,
            'email' => $customer?->email ?? 'buyer@example.ae',
        ]);

        app(CouponService::class)->recordRedemption(
            $coupon,
            5000,
            (int) $order->id,
            $customer?->id,
            (string) $order->email,
        );

        return $order;
    });
}

function chokeCoupon(array $attributes = []): Coupon
{
    return Coupon::create($attributes + [
        'code' => 'WELCOME10',
        'type' => 'fixed_cart',
        'amount' => 5000,
    ]);
}

/* ============================================================
 | 1. A cancelled order gives the code back
 |============================================================ */

it('hands a coupon use back when an order is cancelled from the order screen', function () {
    chokeAdmin();

    $coupon = chokeCoupon(['usage_limit' => 1]);
    $order = chokeOrderWithCoupon($coupon);

    expect($coupon->fresh()->usage_count)->toBe(1);

    test()->postJson("/admin-api/orders/{$order->id}/action", ['action' => 'cancel'])
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'cancelled']);

    expect($order->fresh()->status)->toBe('cancelled')
        // The counter the usage limit is read against has moved back.
        ->and($coupon->fresh()->usage_count)->toBe(0)
        // And the row is still there, stamped, rather than deleted: it is the
        // only record that this code was ever accepted on this order.
        ->and(CouponRedemption::where('order_id', $order->id)->count())->toBe(1)
        ->and(CouponRedemption::where('order_id', $order->id)->whereNotNull('released_at')->count())->toBe(1);
});

it('lets the shopper use their one-per-customer code again after the order is cancelled', function () {
    chokeAdmin();

    $coupon = chokeCoupon(['usage_limit_per_user' => 1]);
    $customer = chokeCustomer();
    $order = chokeOrderWithCoupon($coupon, [], $customer);

    // The point of the whole lane, stated as the shopper experiences it: the
    // code is refused while the order stands...
    $cart = chokeCart();
    expect(app(CouponService::class)->validate('WELCOME10', $cart, $customer->email)['ok'])->toBeFalse();

    test()->postJson("/admin-api/orders/{$order->id}/action", ['action' => 'cancel'])->assertOk();

    // ...and works again once it does not.
    $again = app(CouponService::class)->validate('WELCOME10', chokeCart(), $customer->email);

    expect($again['ok'])->toBeTrue()
        ->and($again['error'])->toBeNull();
});

/* ============================================================
 | 2. It comes back exactly once
 |============================================================ */

it('hands back one use when the same order is cancelled twice', function () {
    chokeAdmin();

    $coupon = chokeCoupon(['usage_limit' => 5]);
    $coupon->forceFill(['usage_count' => 0])->save();

    $order = chokeOrderWithCoupon($coupon);
    expect($coupon->fresh()->usage_count)->toBe(1);

    test()->postJson("/admin-api/orders/{$order->id}/action", ['action' => 'cancel'])->assertOk();
    test()->postJson("/admin-api/orders/{$order->id}/action", ['action' => 'cancel'])->assertOk();

    // Not -1. An unsigned column would have refused the second write and a
    // signed one would make the limit read backwards.
    expect($coupon->fresh()->usage_count)->toBe(0);
});

it('hands back one use when an order is cancelled and then refunded', function () {
    chokeAdmin();

    $coupon = chokeCoupon(['usage_limit' => 5]);
    $order = chokeOrderWithCoupon($coupon);

    // Two DIFFERENT transitions, so the "already at that status" refusal does
    // not cover this one. Only the released stamp on the redemption row does.
    test()->postJson("/admin-api/orders/{$order->id}/action", ['action' => 'cancel'])->assertOk();

    app(OrderStatus::class)->moveTo($order, 'refunded', by: 'Admin');

    expect($order->fresh()->status)->toBe('refunded')
        ->and($coupon->fresh()->usage_count)->toBe(0);
});

/* ============================================================
 | 3. Nothing is invented
 |============================================================ */

it('invents no release for an imported order that never redeemed anything', function () {
    chokeAdmin();

    // What the WooCommerce import really leaves behind: a counter carried over
    // verbatim, the code printed on the order, and no redemption row in this
    // application at all. Releasing one would credit a use the shop never took.
    $coupon = chokeCoupon();
    $coupon->forceFill(['usage_count' => 40])->save();

    $order = chokeOrder(['coupon_code' => 'WELCOME10', 'origin' => 'woocommerce-import']);

    test()->postJson("/admin-api/orders/{$order->id}/action", ['action' => 'cancel'])->assertOk();

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($coupon->fresh()->usage_count)->toBe(40)
        ->and(CouponRedemption::count())->toBe(0);
});

it('leaves other orders redemptions alone when one of them is cancelled', function () {
    chokeAdmin();

    $coupon = chokeCoupon();
    $kept = chokeOrderWithCoupon($coupon);
    $cancelled = chokeOrderWithCoupon($coupon, ['email' => 'second@example.ae']);

    expect($coupon->fresh()->usage_count)->toBe(2);

    test()->postJson("/admin-api/orders/{$cancelled->id}/action", ['action' => 'cancel'])->assertOk();

    expect($coupon->fresh()->usage_count)->toBe(1)
        ->and(CouponRedemption::where('order_id', $kept->id)->whereNull('released_at')->count())->toBe(1);
});

/* ============================================================
 | 4. Refunds: which kind gives the code back
 |============================================================ */

it('leaves the coupon alone on a partial refund and releases it when the last of the money goes back', function () {
    chokeAdmin();

    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'position' => 0]);

    $coupon = chokeCoupon();
    $order = chokeOrderWithCoupon($coupon, [
        'status' => 'completed',
        'paid_at' => now(),
        'captured_at' => now(),
        'captured_total' => 15000,
    ]);

    $refunder = app(PaymentRefunder::class);

    // Half of it back. The customer keeps the goods and the order stands, so
    // they have still used the code.
    $refunder->refund($order->fresh(), 7000, 'Goodwill', 'Admin', 'key-partial');

    expect($order->fresh()->status)->toBe('completed')
        ->and($coupon->fresh()->usage_count)->toBe(1);

    // The balance. Now nothing was paid for, and the use goes back.
    $refunder->refund($order->fresh(), 8000, 'Returned', 'Admin', 'key-rest');

    expect($order->fresh()->status)->toBe('refunded')
        ->and($coupon->fresh()->usage_count)->toBe(0);
});

/* ============================================================
 | 5. The bulk list is not a second answer
 |============================================================ */

it('releases every order in a bulk cancellation, and nothing more when it is repeated', function () {
    chokeAdmin();

    $coupon = chokeCoupon();
    $a = chokeOrderWithCoupon($coupon);
    $b = chokeOrderWithCoupon($coupon, ['email' => 'b@example.ae']);

    expect($coupon->fresh()->usage_count)->toBe(2);

    $ids = [$a->id, $b->id];

    // force, because these orders count as revenue and the list refuses to
    // take money off the store's figures without being asked twice. That guard
    // is another lane's and is left exactly as it was.
    test()->postJson('/admin-api/orders-bulk-status', ['ids' => $ids, 'status' => 'cancelled', 'force' => true])
        ->assertOk()
        ->assertJson(['changed' => 2]);

    expect($coupon->fresh()->usage_count)->toBe(0);

    // The same operator pressing the same button again. Both orders are
    // already cancelled, so there is no transition and nothing to give back.
    test()->postJson('/admin-api/orders-bulk-status', ['ids' => $ids, 'status' => 'cancelled', 'force' => true])
        ->assertOk()
        ->assertJson(['changed' => 0]);

    expect($coupon->fresh()->usage_count)->toBe(0);
});

it('emails the customer about a bulk status change without a second copy of the decision', function () {
    Mail::fake();
    chokeAdmin();

    $order = chokeOrder(['status' => 'processing']);

    test()->postJson('/admin-api/orders-bulk-status', ['ids' => [$order->id], 'status' => 'shipped'])
        ->assertOk();

    // The bulk screen used to send these itself, because a mass update fires no
    // model events. It goes through the observer now, like every other screen,
    // and it still goes exactly once.
    Mail::assertSent(\App\Mail\OrderStatusChanged::class, 1);
});

/* ============================================================
 | 6. The transition is recorded
 |============================================================ */

it('records where the order came from, not just where it went', function () {
    chokeAdmin();

    $order = chokeOrder(['status' => 'processing']);

    test()->putJson("/admin-api/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();

    $note = (string) $order->notes()->first()?->content;

    expect(str_contains($note, 'processing'))->toBeTrue('the note does not say what the order moved FROM: ' . $note);
    expect(str_contains($note, 'shipped'))->toBeTrue('the note does not say what it moved TO: ' . $note);
});

it('names the code on the order when a cancellation gives it back', function () {
    chokeAdmin();

    $coupon = chokeCoupon();
    $order = chokeOrderWithCoupon($coupon);

    test()->postJson("/admin-api/orders/{$order->id}/action", ['action' => 'cancel'])->assertOk();

    $note = (string) $order->notes()->first()?->content;

    expect(str_contains($note, 'WELCOME10'))->toBeTrue('the history does not say which code came back: ' . $note);
});

it('writes nothing at all for a move to the status the order already has', function () {
    Mail::fake();
    chokeAdmin();

    $order = chokeOrder(['status' => 'shipped']);

    test()->putJson("/admin-api/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();

    expect($order->notes()->count())->toBe(0);
    Mail::assertNothingSent();
});

/* ============================================================
 | 7. A human can still fix a mistake
 |============================================================ */

it('lets an operator put a completed order back to pending', function () {
    chokeAdmin();

    // Nobody plans this transition. Somebody marks the wrong order completed
    // about once a week, and on a host with no shell the dropdown is the only
    // way it ever gets corrected — so it is allowed, and recorded.
    $order = chokeOrder(['status' => 'completed']);

    test()->putJson("/admin-api/orders/{$order->id}/status", ['status' => 'pending'])
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'pending']);

    expect($order->fresh()->status)->toBe('pending')
        ->and($order->notes()->count())->toBe(1);
});

/* ============================================================
 | 8. The payment paths mean the same thing as the screens
 |============================================================ */

it('hands the use back when the gateway declines the payment at placement', function () {
    // The storefront path. There is an existing test for this that cannot
    // reach it: it points the checkout at a Stripe with no credentials, which
    // is refused at validation, so no order is ever placed and the assertion
    // that nothing was redeemed passes because nothing happened at all. This
    // one configures the gateway properly and makes the network call fail.
    Http::fake(['api.stripe.com/*' => Http::response([], 500)]);

    chokeShop();

    $coupon = chokeCoupon(['usage_limit' => 1]);
    $cart = chokeCart($coupon);

    test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', chokeForm(['payment_method' => 'stripe']))
        ->assertSessionHasErrors();

    $order = Order::latest('id')->first();

    expect($order)->not->toBeNull()
        ->and($order->status)->toBe('failed')
        // The retry the failed order is kept for is not refused by the limit
        // the shopper just consumed.
        ->and($coupon->fresh()->usage_count)->toBe(0);
});

it('hands the use back when the decline arrives later, by webhook', function () {
    $coupon = chokeCoupon(['usage_limit' => 1]);
    $order = chokeOrderWithCoupon($coupon, ['status' => 'pending']);

    app(PaymentConfirmer::class)->fail($order, 'stripe', 'ref-1', 'card_declined');

    expect($order->fresh()->status)->toBe('failed')
        ->and($coupon->fresh()->usage_count)->toBe(0);
});

it('still ignores a failure notice for an order that has already been paid', function () {
    $coupon = chokeCoupon();
    $order = chokeOrderWithCoupon($coupon, ['status' => 'processing', 'paid_at' => now()]);

    app(PaymentConfirmer::class)->fail($order->fresh(), 'stripe', 'ref-2', 'expired');

    // A late "expired" notice must not cancel a real sale, and must certainly
    // not hand back the code that was paid for.
    expect($order->fresh()->status)->toBe('processing')
        ->and($coupon->fresh()->usage_count)->toBe(1);
});

it('still applies a payment confirmation at most once', function () {
    $order = chokeOrder(['status' => 'pending', 'total' => 15000]);

    $confirmer = app(PaymentConfirmer::class);

    $first = $confirmer->confirm($order, 'stripe', 'ref-3', 15000, 'AED');
    $second = $confirmer->confirm($order->fresh(), 'stripe', 'ref-3', 15000, 'AED');

    // 'applied' and 'already applied' are both 200s to the provider, so the
    // difference is read off the message rather than the status code.
    expect($first->message)->toBe('payment applied')
        ->and($second->message)->toBe('already applied')
        ->and($order->fresh()->status)->toBe('processing')
        ->and(\App\Models\Payment::count())->toBe(1);
});

/* ============================================================
 | 9. Nothing else may write the column
 |============================================================ */

it('has nothing but the funnel writing an order status', function () {
    /*
     * A structural guard, and the only kind available: "every writer goes
     * through one method" is a property of the source, not of a request.
     *
     * IT READS CODE, NOT PROSE. Every file is run through token_get_all() and
     * its comments are dropped before anything is matched. A regex guard that
     * reads comments as code has been written twice in this repo and has been
     * wrong twice — a sentence describing the bug trips the guard meant to
     * catch it, and the only fix left is to stop describing the bug. Dropping
     * the comments properly means a file may explain itself freely, and the
     * funnel, which necessarily writes the column, is the only thing that needs
     * excusing.
     *
     * WHAT IT LOOKS FOR: a write idiom whose receiver is an order. That is what
     * all nine sites were, in four shapes, and it is coarse on purpose. It
     * cannot see a write built out of string pieces or made through a variable
     * called something else, and the behavioural tests above are what actually
     * pin the money. What this catches is the ordinary case: somebody adding a
     * tenth screen next year and setting the column the obvious way.
     */
    $allowed = [
        // The funnel. It is the thing that writes the column.
        'app/Services/Orders/OrderStatus.php' => 'the funnel itself',
        // $new is a REPLICA being created as a draft, not an existing order
        // moving. A creation has no transition to record, nothing to release
        // and nothing to return; the funnel governs orders that already exist.
        'app/Http/Controllers/Admin/AdminOrderController.php' => 'duplicate() builds a new draft order',
    ];

    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = 'app/' . ltrim(str_replace(app_path(), '', $file->getPathname()), '/');

        if (array_key_exists($relative, $allowed)) {
            continue;
        }

        foreach (chokeStatusWrites(chokeCodeWithoutComments($file->getPathname())) as $write) {
            $offenders[] = $relative . ': ' . $write;
        }
    }

    expect($offenders)->toBe([], "These write an order's status without going through the funnel, "
        . 'so whatever it does — release the coupon use, record the move, put the units back — '
        . "does not happen for them:\n" . implode("\n", $offenders));
});

/** One file's source with every comment removed and whitespace flattened. */
function chokeCodeWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return (string) preg_replace('/\s+/', ' ', $code);
}

/**
 * Writes to an order's status found in $code.
 *
 * "An order's" is decided by the receiver: the names this codebase gives an
 * order model, or the class itself. A refund row, a cart or a product carrying
 * a column also called `status` is somebody else's business and is not
 * reported — those exist today and are not this lane's to police.
 *
 * @return list<string>
 */
function chokeStatusWrites(string $code): array
{
    $found = [];

    if (preg_match_all('/(\$order\w*|\$o|\$locked)->status\s*=[^=]/', $code, $matches)) {
        foreach ($matches[0] as $hit) {
            $found[] = trim($hit);
        }
    }

    foreach (['update(', 'forceFill('] as $idiom) {
        $offset = 0;

        while (($position = strpos($code, $idiom, $offset)) !== false) {
            $offset = $position + 1;

            $window = substr($code, $position, 200);

            if (! str_contains($window, "'status'")) {
                continue;
            }

            // The receiver, read back from the idiom with no statement boundary
            // in between, so a model call and a query-builder chain are both
            // recognised.
            $before = substr($code, max(0, $position - 160), min(160, $position));

            if (! preg_match('/(\$order\w*|\$o|\$locked|Order::)[^;{}]*$/', $before)) {
                continue;
            }

            $found[] = trim(substr($window, 0, 60));
        }
    }

    return $found;
}

/* ============================================================
 | 9. Helpers that need the shop set up
 |============================================================ */

/** A shop that can take a card order: one zone, one rate, a working Stripe. */
function chokeShop(): void
{
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $stripe = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0,
    ]);

    $stripe->config = ['publishable_key' => 'pk_test_kbb', 'secret_key' => 'sk_test_kbb'];
    $stripe->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

/** A cart holding one line, with $coupon applied if there is one. */
function chokeCart(?Coupon $coupon = null): \App\Models\Cart
{
    if (ShippingZone::count() === 0) {
        $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
        ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
        ShippingMethod::create([
            'shipping_zone_id' => $zone->id,
            'type' => 'flat_rate',
            'title' => 'Standard delivery',
            'cost' => 2000,
            'enabled' => true,
            'position' => 0,
        ]);
    }

    $product = Product::create([
        'slug' => 'choke-' . \Illuminate\Support\Str::random(12),
        'name' => 'Choke Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 20000,
        'stock_status' => 'instock',
    ]);

    $cart = \App\Models\Cart::create([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'coupon_id' => $coupon?->id,
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 20000,
    ]);

    return $cart->load('items.product', 'coupon');
}

/** The checkout form, filled in the way a shopper would. */
function chokeForm(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.ae',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Choke',
        'billing_last_name' => 'Buyer',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'stripe',
    ], $overrides);
}
