<?php

/**
 * The seams between the payment pieces — Lane ET.
 *
 * Capture, refunds, the payment-events log, cash on delivery, Tabby, Tamara,
 * reconciliation, Stripe connect, coupon release and stock return were each
 * built and reviewed on their own. Every case in this file is a way two of
 * them, each correct, are wrong together. None of them is reachable through
 * one piece alone, which is why none of them was found by the lane that wrote
 * it.
 *
 *   1. THE REFUND CEILING WAS A COLUMN AN OPERATOR CAN EDIT.
 *      `paid_at` is set by a verified callback; `captured_at` is set only by
 *      somebody pressing Capture. Between them the order sits in `processing`,
 *      which is in AdminOrderController::EDITABLE_STATUSES — and the ceiling
 *      was `orders.total`, so editing the order moved the ceiling with it.
 *
 *   2. ONE METHOD, TWO BRANCHES, TWO ANSWERS.
 *      recalcTotals() clamped an over-large discount on a taxed order and did
 *      not on an untaxed one, and wrote a negative `orders.total`.
 *
 *   3. A WINDOW A PROVIDER WAS DOWN FOR COULD NEVER BE ANSWERED.
 *      A run is keyed on its window, so pressing Run again continues it — and
 *      every phase the outage closed was already marked finished, so the
 *      second press did nothing at all and said nothing about it.
 *
 *   4. ONE GUARD ON confirm(), NONE ON fail().
 *      confirm() has refused to move a `shipped` or `completed` order since
 *      HOLDS_PLACE was written. fail() guarded only on `paid_at`, and a
 *      dispatched order with no `paid_at` is the ordinary COD case — so a
 *      late expiry notice un-sold it.
 *
 * Plus one property that turned out to be sound and is pinned because the
 * thing that makes it sound is not the obvious thing (see the Stripe replay
 * case at the bottom).
 */

use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentConfirmer;
use App\Services\Payments\PaymentRefunder;
use App\Services\Payments\Reconciliation\ReconcileWindow;
use App\Services\Payments\Reconciliation\Reconciler;
use App\Services\CouponService;
use App\Services\StockClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const SEAM_STRIPE_KEY = 'sk_test_SEAMCANARY000001';

const SEAM_URL_SECRET = 'url-secret-seam-000001';

const SEAM_SIGNING = 'whsec_seam_signing_secret';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();
});

function seamAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Seam Admin',
        'email' => 'seam-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

function seamStripeConfigured(): void
{
    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 3,
    ]);

    $row->config = [
        'publishable_key' => 'pk_test_seam',
        'secret_key' => SEAM_STRIPE_KEY,
        'webhook_signing_secret' => SEAM_SIGNING,
        'webhook_secret' => SEAM_URL_SECRET,
    ];

    $row->save();

    app(GatewayCredentials::class)->forget();
}

function seamProduct(int $price = 10000): Product
{
    return Product::create([
        'name' => 'Seam Serum', 'slug' => 'seam-' . uniqid(), 'sku' => 'SEAM-' . uniqid(),
        'price' => $price, 'status' => 'publish', 'stock_status' => 'instock',
    ]);
}

/**
 * A Stripe order confirmed for 200.00 and NOT captured through this lane —
 * which is where every Stripe, Tabby and Tamara order sits between the
 * callback landing and somebody pressing Capture.
 *
 * @return array{0: Order, 1: \App\Models\OrderItem, 2: Product}
 */
function seamPaidOrder(): array
{
    seamStripeConfigured();

    $product = seamProduct();

    $order = Order::create([
        'order_number' => 'SEAM-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'total' => 20000,
        'payment_method' => 'stripe',
        'transaction_id' => 'pi_seam',
        'paid_at' => now(),
    ]);

    $item = $order->items()->create([
        'product_id' => $product->id, 'name' => 'Seam Serum', 'sku' => $product->sku,
        'quantity' => 2, 'unit_price' => 10000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    // What PaymentConfirmer writes inside the same transaction that sets
    // `paid_at`: the amount the provider actually confirmed.
    Payment::create([
        'order_id' => $order->id, 'provider' => 'stripe', 'provider_ref' => 'pi_seam',
        'amount' => 20000, 'currency' => 'AED', 'status' => 'paid',
    ]);

    return [$order, $item, $product];
}

/* ================================================== 1. the refund ceiling === */

it('will not refund more than the provider confirmed, however the order is edited afterwards', function () {
    [$order, , $product] = seamPaidOrder();

    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_seam', 'status' => 'succeeded'], 200)]);

    // The operator adds a 100.00 line to an order that was paid for at 200.00.
    $this->actingAs(seamAdmin(), 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/items', [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertOk();

    $order->refresh();

    // The order is now worth 300.00 — and 200.00 is still all that was taken.
    expect((int) $order->total)->toBe(30000)
        ->and(app(PaymentRefunder::class)->capturedFils($order))->toBe(20000);

    $outcome = app(PaymentRefunder::class)->refund($order, 30000, 'seam', 'Admin');

    expect($outcome->ok)->toBeFalse()
        ->and($outcome->code)->toBe('over_captured')
        // Nothing was sent to Stripe and nothing was recorded as returned.
        ->and(app(PaymentRefunder::class)->refundedFils($order->fresh()))->toBe(0)
        ->and($order->fresh()->status)->toBe('processing');

    Http::assertNothingSent();
});

it('can still give back everything the customer paid after the order is edited down', function () {
    [$order, $item] = seamPaidOrder();

    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_seam2', 'status' => 'succeeded'], 200)]);

    // One of the two units is dropped, so the order reads 100.00.
    $this->actingAs(seamAdmin(), 'admin')
        ->putJson('/admin-api/orders/' . $order->id . '/items/' . $item->id, ['quantity' => 1])
        ->assertOk();

    $order->refresh();

    expect((int) $order->total)->toBe(10000);

    $refunder = app(PaymentRefunder::class);

    // 100.00 back for the unit that is not being sent...
    expect($refunder->refund($order, 10000, 'one unit returned', 'Admin')->ok)->toBeTrue();

    $order->refresh();

    // ...and the other 100.00 the customer actually paid is STILL refundable.
    // This is the half that strands money: with the ceiling read off
    // `orders.total` the order was marked `refunded` here, with 100.00 of the
    // buyer's money still in the shop's account and the panel reporting the
    // order settled in full.
    expect($refunder->capturedFils($order) - $refunder->refundedFils($order))->toBe(10000)
        ->and($order->status)->not->toBe('refunded');

    expect($refunder->refund($order, 10000, 'the rest', 'Admin')->ok)->toBeTrue();

    expect($order->fresh()->status)->toBe('refunded');
});

it('still uses the captured amount once somebody has captured', function () {
    [$order] = seamPaidOrder();

    // A capture for less than the order — a partial capture, which is the
    // whole reason `captured_total` exists as its own column.
    $order->forceFill(['captured_at' => now(), 'captured_total' => 15000, 'capture_ref' => 'ch_seam'])->save();

    expect(app(PaymentRefunder::class)->capturedFils($order->fresh()))->toBe(15000);
});

it('falls back to the order total for a paid order that carries no payment row', function () {
    // A WooCommerce order brought in by Import\Entities\OrderImporter: it sets
    // `paid_at` from `date_paid` and has no provider record to offer. Refusing
    // to refund those would be worse than a soft ceiling, so the fallback is
    // deliberate and is pinned here so it cannot be removed by accident.
    $order = Order::create([
        'order_number' => 'SEAM-IMP-' . uniqid(),
        'email' => 'legacy@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 12345,
        'total' => 12345,
        'payment_method' => 'legacy',
        'paid_at' => now(),
    ]);

    expect(Payment::where('order_id', $order->id)->count())->toBe(0)
        ->and(app(PaymentRefunder::class)->capturedFils($order))->toBe(12345);
});

it('builds the ceiling out of confirmed money only, never out of a refused callback', function () {
    [$order, , $product] = seamPaidOrder();

    // A second callback that disagreed about the amount. PaymentConfirmer
    // records it rather than discarding it, with the mismatch as its status —
    // and a row recording money that was REFUSED must not raise the ceiling.
    Payment::create([
        'order_id' => $order->id, 'provider' => 'stripe', 'provider_ref' => 'pi_wrong',
        'amount' => 99900, 'currency' => 'AED', 'status' => 'amount_mismatch',
    ]);

    // And the order is edited too, so `orders.total` is a third figure again:
    // 200.00 confirmed, 999.00 refused, 300.00 on the order. Only the first is
    // money, and it is the only one the ceiling may be built from.
    $this->actingAs(seamAdmin(), 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/items', [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertOk();

    expect((int) $order->fresh()->total)->toBe(30000)
        ->and(app(PaymentRefunder::class)->capturedFils($order->fresh()))->toBe(20000);
});

/* ============================================== 2. the two-branch total === */

it('never writes a negative order total, taxed or untaxed', function () {
    $admin = seamAdmin();

    // The same order twice: once with a tax rule recorded on it, once without.
    // The untaxed branch took `subtotal - discount` with nothing stopping the
    // subtraction and wrote total = -50.00.
    foreach ([null, 'exclusive'] as $basis) {
        $a = seamProduct(10000);
        $b = seamProduct(5000);

        $order = Order::create([
            'order_number' => 'SEAM-N-' . uniqid(),
            'email' => 'buyer@example.com',
            'status' => 'processing',
            'currency' => 'AED',
            'subtotal' => 15000,
            'discount_total' => 10000,      // a 100.00 coupon against a 150.00 basket
            'shipping_total' => 0,
            'fee_total' => 0,
            'tax_total' => 0,
            'total' => 5000,
            'tax_basis' => $basis,
            'tax_rate' => $basis !== null ? 5.00 : null,
        ]);

        $line = $order->items()->create(['product_id' => $a->id, 'name' => 'A', 'sku' => $a->sku,
            'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000]);
        $order->items()->create(['product_id' => $b->id, 'name' => 'B', 'sku' => $b->sku,
            'quantity' => 1, 'unit_price' => 5000, 'subtotal' => 5000, 'total' => 5000]);

        // Remove the line the discount was worth more than what is left.
        $this->actingAs($admin, 'admin')
            ->deleteJson('/admin-api/orders/' . $order->id . '/items/' . $line->id)
            ->assertOk();

        $order->refresh();

        expect((int) $order->total)
            ->toBeGreaterThanOrEqual(0, 'an order total must never be negative, basis: ' . var_export($basis, true))
            // Both branches answer the same question the same way: a discount
            // larger than what is left of the basket forgives the excess, it
            // does not become money owed back.
            ->toBe(0);
    }
});

it('keeps the untaxed total exactly equal to its parts when the discount fits', function () {
    $admin = seamAdmin();
    $product = seamProduct(10000);

    $order = Order::create([
        'order_number' => 'SEAM-P-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 10000,
        'discount_total' => 2500,
        'shipping_total' => 1500,
        'fee_total' => 500,
        'tax_total' => 300,
        'total' => 9800,
    ]);

    $order->items()->create(['product_id' => $product->id, 'name' => 'A', 'sku' => $product->sku,
        'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000]);

    // A second line, so the recalculation runs over a real edit.
    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/items', ['product_id' => $product->id, 'quantity' => 2])
        ->assertOk();

    $order->refresh();

    $lines = (int) $order->items()->sum('total');

    expect((int) $order->subtotal)->toBe($lines)
        ->and((int) $order->total)->toBe(
            $lines - (int) $order->discount_total
            + (int) $order->shipping_total + (int) $order->fee_total + (int) $order->tax_total
        );
});

/* ============================================ 3. reconciliation coverage === */

function seamRun(): int
{
    $reconciler = app(Reconciler::class);
    $runId = $reconciler->open(ReconcileWindow::lastDays(7), ['stripe'], 'tester', false);

    for ($i = 0; $i < 200; $i++) {
        if (($reconciler->step($runId)['done'] ?? false) === true) {
            break;
        }
    }

    return $runId;
}

/** @return array<int, object> */
function seamFindings(int $runId, ?string $kind = null): array
{
    $q = DB::table(Reconciler::FINDINGS)->where('run_id', $runId);

    if ($kind !== null) {
        $q->where('kind', $kind);
    }

    return $q->orderBy('id')->get()->all();
}

it('asks the provider again when the last run over that window could not read it', function () {
    seamStripeConfigured();

    $order = Order::create([
        'order_number' => 'SEAM-RC-1', 'email' => 'buyer@example.com', 'status' => 'processing',
        'currency' => 'AED', 'subtotal' => 25000, 'total' => 25000,
        'payment_method' => 'stripe', 'paid_at' => now(),
    ]);

    Payment::create([
        'order_id' => $order->id, 'provider' => 'stripe', 'provider_ref' => 'pi_ghost',
        'amount' => 25000, 'currency' => 'AED', 'status' => 'paid',
    ]);

    // ONE stub for the whole test: Http::fake() MERGES stubs and the first
    // match wins, so a second fake() here would never be reached and the test
    // would quietly assert nothing.
    $down = true;
    $calls = ['down' => 0, 'up' => 0];

    Http::fake(['api.stripe.com/*' => function () use (&$down, &$calls) {
        $calls[$down ? 'down' : 'up']++;

        return $down
            ? Http::response(['error' => ['code' => 'api_key_expired']], 401)
            : Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
    }]);

    // Monday. The key has been rotated behind the shop's back.
    $first = seamRun();

    expect(seamFindings($first, Reconciler::PAYMENTS_SOURCE_UNAVAILABLE))->toHaveCount(1)
        ->and(seamFindings($first, Reconciler::REFUNDS_SOURCE_UNAVAILABLE))->toHaveCount(1)
        // Correctly concluding nothing from silence.
        ->and(seamFindings($first, Reconciler::MONEY_NOT_CONFIRMED))->toHaveCount(0)
        ->and($calls['down'])->toBeGreaterThan(0);

    // Tuesday. The owner fixes the key and presses Run over the same dates.
    $down = false;
    $second = seamRun();

    // Same run — that is the resumability this screen is built on, and it is
    // right. What was wrong is that it used to make NO request at all and show
    // Monday's two notices again, leaving the window permanently unanswered
    // while looking answered.
    expect($second)->toBe($first)
        ->and($calls['up'])->toBeGreaterThan(0)
        ->and(seamFindings($second, Reconciler::PAYMENTS_SOURCE_UNAVAILABLE))->toHaveCount(0)
        ->and(seamFindings($second, Reconciler::REFUNDS_SOURCE_UNAVAILABLE))->toHaveCount(0)
        // And the question the whole class exists to ask finally gets asked:
        // this shop has a payment Stripe has never heard of.
        ->and(seamFindings($second, Reconciler::MONEY_NOT_CONFIRMED))->toHaveCount(1);
});

it('does not re-walk a provider that answered, however often the owner presses Run', function () {
    seamStripeConfigured();

    $calls = 0;

    Http::fake(['api.stripe.com/*' => function () use (&$calls) {
        $calls++;

        return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
    }]);

    $first = seamRun();
    $after = $calls;

    $second = seamRun();

    // A completed phase keeps its cursor and its findings. Re-arming is for
    // the outage case alone.
    expect($second)->toBe($first)
        ->and($calls)->toBe($after);
});

/* ================================== 4. the late failure notice === */

it('will not un-sell an order that has already shipped', function () {
    seamStripeConfigured();

    $product = seamProduct();

    $coupon = Coupon::create([
        'code' => 'SEAM' . strtoupper(substr(uniqid(), -6)),
        'type' => 'percent', 'amount' => 1000, 'usage_limit' => 1, 'usage_count' => 0,
    ]);

    // The shopper opened Stripe Checkout, gave up, and paid cash on delivery.
    // The order shipped. Stripe's session expiry notice arrives the next day.
    $order = Order::create([
        'order_number' => 'SEAM-SH-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'shipped',
        'currency' => 'AED',
        'subtotal' => 10000, 'discount_total' => 1000, 'total' => 9000,
        'payment_method' => 'stripe',
        'coupon_code' => $coupon->code,
    ]);

    $order->items()->create([
        'product_id' => $product->id, 'name' => 'Seam Serum', 'sku' => $product->sku,
        'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000,
    ]);

    DB::transaction(fn () => app(CouponService::class)
        ->recordRedemption($coupon, 1000, $order->id, null, 'buyer@example.com'));

    expect((int) $coupon->fresh()->usage_count)->toBe(1);

    $outcome = app(PaymentConfirmer::class)
        ->fail($order, 'stripe', 'cs_expired', 'checkout_session_expired');

    $order->refresh();

    expect($outcome->accepted)->toBeFalse()
        ->and($order->status)->toBe('shipped')
        // Still revenue. `failed` is not in REAL_STATUSES, so this order used
        // to vanish from the dashboard, the customer history and the exports.
        ->and(in_array($order->status, Order::REAL_STATUSES, true))->toBeTrue()
        // And the one-use coupon is still spent, on goods that have shipped.
        ->and((int) $coupon->fresh()->usage_count)->toBe(1);

    // It is recorded, and loudly: goods shipped against a payment the provider
    // says never happened is exactly what an operator has to see.
    expect(Payment::where('order_id', $order->id)->where('status', 'late_failure')->count())->toBe(1)
        ->and($order->notes()->where('content', 'like', 'ACTION NEEDED%')->count())->toBe(1);

    // However many times the provider retries, one note.
    app(PaymentConfirmer::class)->fail($order, 'stripe', 'cs_expired', 'checkout_session_expired');
    app(PaymentConfirmer::class)->fail($order, 'stripe', 'cs_expired', 'checkout_session_expired');

    expect($order->notes()->where('content', 'like', 'ACTION NEEDED%')->count())->toBe(1)
        ->and($order->fresh()->status)->toBe('shipped');
});

it('still fails an order that has not been dispatched', function () {
    seamStripeConfigured();

    // The guard is narrow on purpose: an undispatched order whose payment the
    // provider declines must still fail, give its stock back and release its
    // coupon. That is the behaviour fail() exists for.
    $product = seamProduct();

    $order = Order::create([
        'order_number' => 'SEAM-PD-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 10000, 'total' => 10000,
        'payment_method' => 'stripe',
    ]);

    $order->items()->create([
        'product_id' => $product->id, 'name' => 'Seam Serum', 'sku' => $product->sku,
        'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000,
    ]);

    app(StockClaim::class)->claim([['product_id' => $product->id, 'quantity' => 1]], $order->id);

    $outcome = app(PaymentConfirmer::class)
        ->fail($order, 'stripe', 'cs_declined', 'payment_intent_payment_failed');

    expect($outcome->accepted)->toBeTrue()
        ->and($order->fresh()->status)->toBe('failed')
        ->and(app(StockClaim::class)->outstandingFor($order->id))->toBe(0);
});

/* ================================ the property that turned out to be sound === */

it('applies one payment however many fresh ids the provider retries under', function () {
    seamStripeConfigured();

    $order = Order::create([
        'order_number' => 'SEAM-RP-' . uniqid(), 'email' => 'buyer@example.com', 'status' => 'pending',
        'currency' => 'AED', 'subtotal' => 40000, 'total' => 40000, 'payment_method' => 'stripe',
    ]);

    $deliver = function (string $eventId, string $intent) use ($order) {
        $raw = json_encode([
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_' . $intent,
                'payment_intent' => $intent,
                'client_reference_id' => $order->order_number,
                'payment_status' => 'paid',
                'amount_total' => 40000,
                'currency' => 'aed',
            ]],
        ]);

        $ts = time();
        $mac = hash_hmac('sha256', $ts . '.' . $raw, SEAM_SIGNING);

        $request = Request::create(
            '/api/payments/webhook/stripe/' . SEAM_URL_SECRET, 'POST', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$mac}"], $raw,
        );

        $request->headers->set('Content-Type', 'application/json');

        $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return app(GatewayRegistry::class)->find('stripe')->handleWebhook($request);
    };

    // Not the same delivery replayed — a DIFFERENT event carrying a DIFFERENT
    // PaymentIntent for the same order, which is what a provider that reopened
    // a session sends. A dedupe table keyed on the event id would let this
    // through; what actually stops it is the conditional UPDATE against a null
    // `paid_at` in OrderStatus::moveTo(), which is an order-scoped mutex and
    // does not care what the delivery calls itself.
    expect($deliver('evt_one', 'pi_one')->message)->toBe('payment applied');
    expect($deliver('evt_two', 'pi_two')->message)->toBe('already applied');

    $order->refresh();

    expect(Payment::where('order_id', $order->id)->where('status', 'paid')->count())->toBe(1)
        ->and((int) Payment::where('order_id', $order->id)->where('status', 'paid')->sum('amount'))->toBe(40000)
        ->and($order->transaction_id)->toBe('pi_one');
});
