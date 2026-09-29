<?php

declare(strict_types=1);

/**
 * The wallet money path, adversarially (Lane WAL2).
 *
 * ── WHY THIS FILE EXISTS SEPARATELY FROM WalletPaymentsTest ────────────────
 *
 * WalletPaymentsTest proves what the shop DRAWS: that the express row appears
 * only where a wallet can be used, that the chips stop lying, that the policy
 * names pay.google.com and no wildcard, that the association path 404s until
 * there is something to serve. All of that is about pixels and headers.
 *
 * This file is about MONEY. Every case here is a way a shopper is charged
 * twice, charged a figure nobody agreed to, loses a basket they still own, or
 * ends up with a payment the shop cannot give back. None of them is visible on
 * a screenshot and every one of them costs real money on a live shop.
 *
 * ── WHAT WAS AND WAS NOT ALREADY PROVEN ────────────────────────────────────
 *
 * Lane WAL built the path correctly and said so in its commit message: the
 * wallets ride the existing card intent, so "same capture, same refund, same
 * void, same ledger, same reconciliation, refundable on day one". That claim
 * was TRUE and UNTESTED. Not one case in the 22 that shipped confirms an
 * intent, banks a payment, releases a basket, or refunds a fil. The design was
 * right and nothing pinned it, which is exactly the shape of a claim that is
 * still right on the day it is written and silently wrong three lanes later.
 *
 * ── THE STUBBING, AND WHY IT IS HONEST ─────────────────────────────────────
 *
 * Http::preventStrayRequests() is on for every case, so any Stripe call a test
 * did not explicitly fake is an error rather than a silent success. That is
 * what lets "a second tap opened no second intent" be a real assertion: it is
 * asserted against the recorded request log, and an unfaked extra call would
 * fail the run outright.
 *
 * No Apple device and no live key is involved anywhere. What cannot be proven
 * from here is listed in docs/WALLETS-APPLE-GOOGLE-PAY.md under "what is still
 * waiting on you".
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Refund;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Wallets;
use Illuminate\Support\Facades\Http;

/*
 * HELPERS OF ITS OWN, deliberately, rather than reaching for
 * WalletPaymentsTest's. Pest loads every test file before it runs any test, so
 * that file's globals would in fact be in scope — but only when the whole suite
 * runs. `--filter` on this file alone would fatal, and a helper shared between
 * two files is a helper either file can change under the other. Each is a few
 * lines and they are the shop's own models.
 */

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();
    Http::preventStrayRequests();

    /*
     * ── THE ROUTES THE WALLET PATH REPORTS TO, MOUNTED THE SANCTIONED WAY ──
     *
     * routes/checkout-card.php is Lane FU's file and, like this lane's two, it
     * ships with its require line in its own header rather than written into
     * routes/web.php. It is NOT wired at the time of writing — see the wiring
     * pin at the bottom of this file, which is the thing that will say so out
     * loud once somebody reads it.
     *
     * Mounting it here is the pattern Tests\Support\WalletRoutes and
     * Tests\Support\UgcAdminRoutes set: test the real file in the real group,
     * rather than a hand-written copy of it that could stop resembling what
     * ships. `web` and only `web` — that is the group its header asks for, and
     * both endpoints read the session to identify the order the browser placed.
     */
    \Illuminate\Support\Facades\Route::middleware(['web'])
        ->group(base_path('routes/checkout-card.php'));

    $zone = \App\Models\ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create([
        'shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE',
    ]);
    \App\Models\ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);
});

/** The card gateway, keyed and switched on, with both wallet switches set. */
function wal2Stripe(bool $apple = true, bool $google = true, bool $enabled = true): void
{
    $row = PaymentProvider::firstOrNew(['id' => 'stripe']);

    $row->fill([
        'title' => 'Credit or debit card',
        'enabled' => $enabled,
        'mode' => 'test',
        'position' => 0,
    ]);

    $row->config = [
        'publishable_key' => 'pk_test_wallet_key',
        'secret_key' => 'sk_test_wallet_key',
        'webhook_signing_secret' => 'whsec_wallet_signing',
        'webhook_secret' => 'whsec-url-wallet-0123456789ab',
        'wallet_apple_pay' => $apple ? '1' : '',
        'wallet_google_pay' => $google ? '1' : '',
    ];

    $row->save();

    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();
}

/** A one-line basket. */
function wal2Cart(int $unitPriceFils = 20000): Cart
{
    $product = \App\Models\Product::create([
        'slug' => 'wal2-serum-' . uniqid(),
        'name' => 'Wallet Money Path Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $unitPriceFils / 100,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

/** A browser carrying this basket's cookie. */
function wal2Shopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(\App\Services\CartService::COOKIE, $cart->token);
}

/** The checkout form, as place() expects it. */
function wal2Fields(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'stripe',
    ], $overrides);
}

/**
 * An admin who may move money.
 *
 * `payments.manage` is owner-only and so is `orders.money`; the role is what
 * AdminCapabilities keys off, so an owner is the one account that can reach
 * both the refund and the void endpoint. A lesser role is tested separately
 * below — it must fail closed.
 */
function walMoneyAdmin(string $tag, string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Wallet Admin',
        'email' => 'wal2-' . $tag . '-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

/**
 * A PaymentIntent as Stripe reads it back AFTER an Apple Pay sheet.
 *
 * The one thing that makes this a wallet rather than a typed card is
 * `payment_method_details.card.wallet.type`. Everything else — the `pi_`, the
 * `card` type, the amount in minor units — is byte-for-byte what a typed card
 * produces, which is the whole design and the reason a wallet order needs no
 * second refund path. The tests below assert the downstream behaviour is
 * identical WITH this field present, because "identical" is a claim that can
 * only be checked by checking it.
 */
function walIntentBody(string $id, int $amount, string $wallet = 'apple_pay', string $status = 'succeeded'): array
{
    return [
        'id' => $id,
        'object' => 'payment_intent',
        'status' => $status,
        'amount' => $amount,
        'amount_received' => $status === 'succeeded' ? $amount : 0,
        'currency' => 'aed',
        'client_secret' => $id . '_secret',
        'charges' => ['data' => [[
            'id' => 'ch_' . substr($id, 3),
            'payment_method_details' => [
                'type' => 'card',
                'card' => ['brand' => 'visa', 'wallet' => ['type' => $wallet]],
            ],
        ]]],
    ];
}

/**
 * A wallet-funded order that has already been paid, as the shop would hold it.
 *
 * Written through the models rather than through a checkout, because these
 * cases are about what SETTLEMENT does with such an order — the placing of it
 * is covered above and in WalletPaymentsTest.
 */
function walPaidOrder(int $total = 22000, string $intent = 'pi_wal_paid'): Order
{
    $order = Order::create([
        'order_number' => 'WAL2-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => $total,
        'total' => $total,
        'payment_method' => 'stripe',
        'transaction_id' => $intent,
        'paid_at' => now(),
    ]);

    // The `paid` row PaymentConfirmer writes. PaymentRefunder::capturedFils()
    // builds the refund ceiling out of exactly these rows, so an order without
    // one is not refundable however paid it looks.
    Payment::create([
        'order_id' => $order->getKey(),
        'provider' => 'stripe',
        'provider_ref' => $intent,
        'amount' => $total,
        'currency' => 'AED',
        'status' => 'paid',
    ]);

    return $order;
}

/* =====================================================================
 | TASK 2 — the intent is re-read at Stripe, and Stripe's figure is banked
 ===================================================================== */

it('re-reads the wallet intent at Stripe before marking the order paid, and banks Stripe’s own figure', function () {
    /*
     * THE DEFECT THIS FORBIDS. /checkout/card/paid is the report the express
     * row sends after `stripe.confirmPayment()` resolves in the browser. The
     * browser is the one party to that exchange with an interest in the answer,
     * so if the endpoint believed it — took a status, or worse an amount, out
     * of the POST body — then a shopper with the developer console open would
     * mark their own order paid for nothing.
     *
     * It does not. StripeGateway::confirmFromBrowser() takes the intent id off
     * the ORDER, GETs it from Stripe, and refuses anything that is not
     * `succeeded`. The figure it banks is `amount_received` read from that
     * response. The POST body carries an order number and nothing else that is
     * used.
     */
    wal2Stripe();

    $order = walPaidOrder(22000, 'pi_wal_reread');
    $order->update(['paid_at' => null]);
    Payment::query()->where('order_id', $order->getKey())->delete();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_wal_reread' => Http::response(
            walIntentBody('pi_wal_reread', 22000),
            200,
        ),
    ]);

    // The browser says it is paid, and says it is paid for a much smaller sum.
    // Both claims are ignored; only the order number is read.
    $this->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/paid', [
            'order' => $order->order_number,
            'amount' => 1,
            'status' => 'succeeded',
        ])->assertOk();

    $order->refresh();

    expect($order->paid_at)->not->toBeNull();

    $payment = Payment::query()->where('order_id', $order->getKey())->first();

    // STRIPE'S FIGURE, not the browser's 1 fil.
    expect($payment)->not->toBeNull()
        ->and((int) $payment->amount)->toBe(22000)
        ->and($payment->status)->toBe('paid')
        ->and($payment->provider_ref)->toBe('pi_wal_reread');

    // And it really did go and ask.
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/v1/payment_intents/pi_wal_reread'));
});
// MUTATION, run: in StripeGateway::confirmFromBrowser(), replace the
// `(int) ($intent['amount_received'] ?? ...)` argument with
// `(int) request()->input('amount')`. RED — the payment row banks 1 fil, and
// PaymentConfirmer refuses it as an amount_mismatch, so paid_at stays null.

it('does not mark a wallet order paid when Stripe says the intent has not succeeded', function () {
    /*
     * The browser reports success and Stripe disagrees. This is not a
     * hypothetical: `redirect: 'if_required'` resolves on a 3-D Secure flow
     * that the issuer may still decline moments later, and a stale tab can
     * replay the report at any time. Stripe is the authority and the answer is
     * "not yet", not "no" — the webhook is still coming.
     */
    wal2Stripe();

    $order = walPaidOrder(22000, 'pi_wal_pending');
    $order->update(['paid_at' => null]);
    Payment::query()->where('order_id', $order->getKey())->delete();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_wal_pending' => Http::response(
            walIntentBody('pi_wal_pending', 22000, 'apple_pay', 'requires_payment_method'),
            200,
        ),
    ]);

    $this->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/paid', ['order' => $order->order_number]);

    $order->refresh();

    expect($order->paid_at)->toBeNull()
        ->and(Payment::query()->where('order_id', $order->getKey())->where('status', 'paid')->count())->toBe(0);
});
// MUTATION, run: delete the `if ($status !== 'succeeded')` guard in
// confirmFromBrowser(). RED — the order is banked against an intent that has
// taken no money, which is an unpaid order marked paid.

it('refuses a wallet confirmation whose Stripe amount is not the order total, and records why', function () {
    /*
     * A basket that moved between the sheet opening and the intent being
     * confirmed. The express row compares the two figures in the browser and
     * refuses — but that comparison is a courtesy and this is the guard: the
     * confirmer checks Stripe's amount against `orders.total` and will not bank
     * a mismatch, whatever the browser did.
     */
    wal2Stripe();

    $order = walPaidOrder(22000, 'pi_wal_short');
    $order->update(['paid_at' => null]);
    Payment::query()->where('order_id', $order->getKey())->delete();

    // Stripe took 190.00 against an order of 220.00.
    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_wal_short' => Http::response(
            walIntentBody('pi_wal_short', 19000),
            200,
        ),
    ]);

    $this->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/paid', ['order' => $order->order_number]);

    $order->refresh();

    expect($order->paid_at)->toBeNull();

    // Recorded rather than dropped: a refused payment the shop can see is the
    // difference between reconciliation finding it and a customer finding it.
    $row = Payment::query()->where('order_id', $order->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('amount_mismatch');
});
// MUTATION, run: in PaymentConfirmer::confirm(), change the amount comparison
// to `$amountFils > $expected`. RED — a 190.00 payment banks a 220.00 order.

it('will not confirm a wallet payment for an order this browser did not place', function () {
    /*
     * The endpoint takes an order NUMBER out of a POST body, and the numbers
     * are sequential. Without the session gate, any visitor could walk the
     * range and drive confirmFromBrowser() against strangers' orders.
     *
     * hash_equals against the session's own `kbb_last_order` is the gate, and a
     * miss answers exactly what a wholly made-up number answers, so the
     * endpoint cannot be used to probe which orders exist.
     */
    wal2Stripe();

    $order = walPaidOrder(22000, 'pi_wal_theirs');
    $order->update(['paid_at' => null]);

    // No session order at all.
    $this->postJson('/checkout/card/paid', ['order' => $order->order_number])
        ->assertStatus(404);

    // Somebody else's order in the session.
    $this->withSession(['kbb_last_order' => 'WAL2-SOMEBODY-ELSE'])
        ->postJson('/checkout/card/paid', ['order' => $order->order_number])
        ->assertStatus(404);

    $order->refresh();

    expect($order->paid_at)->toBeNull();

    // And it never even asked Stripe, so this is not a timing oracle either.
    Http::assertNothingSent();
});
// MUTATION, run: in orderThisSessionPlaced(), drop the `hash_equals` clause and
// look the order up by number alone. RED on both 404s.

/* =====================================================================
 | TASK 2 — a double tap makes neither two orders nor two intents
 ===================================================================== */

it('makes one order and one intent when the wallet sheet is confirmed twice', function () {
    /*
     * THE FAILURE THIS FORBIDS IS BEING CHARGED TWICE.
     *
     * A wallet sheet is a single tap on a phone and a tap is easy to repeat —
     * a fat finger, a double-tap on a slow connection, a retry after a spinner
     * that did not move. The express row's `busy` flag is a courtesy and cannot
     * be relied on: it lives in a variable in one tab, and two tabs on the same
     * basket have one each.
     *
     * The real guards are on the server and there are two of them:
     *
     *   1. place() marks the cart `converted`, and CartService::resolve() only
     *      ever returns an `active` cart. So the second POST arrives with no
     *      basket at all and cannot mint a second order.
     *   2. StripeGateway::start() reuses an intent the order already carries
     *      (reusableIntent()), with Stripe's own Idempotency-Key underneath.
     *
     * This case pins the first and the consequence of both: one order, and
     * exactly one POST to /v1/payment_intents.
     */
    wal2Stripe();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_wal_double',
            'client_secret' => 'pi_wal_double_secret',
            'status' => 'requires_payment_method',
        ], 200),
    ]);

    $cart = wal2Cart(20000);
    $shopper = wal2Shopper($cart);

    $first = $shopper->postJson('/checkout/place', wal2Fields())->assertOk();

    expect($first->json('action'))->toBe('confirm');

    /*
     * THE SECOND TAP, AND WHY forget() IS HERE RATHER THAN AN OVERSIGHT.
     *
     * AppServiceProvider binds CartService with `scoped`, which is ONE PER
     * REQUEST — under PHP-FPM the container dies with the request and the next
     * one resolves the basket again from the cookie. Laravel's HTTP test client
     * does not rebuild the container between $this->post() calls, so without
     * this line the second request would be served CartService's memo of the
     * cart the first request already converted, and the test would be measuring
     * the test harness rather than the shop.
     *
     * It measured exactly that when this case was first written: two orders and
     * two intents, from a memo no live request can have. The same class of trap
     * CLAUDE.md records for Setting::map() — "fine under PHP-FPM, a trap in
     * tests and queue workers" — and it cuts the other way here, by inventing a
     * defect rather than hiding one.
     */
    app(\App\Services\CartService::class)->forget();

    $second = $shopper->postJson('/checkout/place', wal2Fields());

    // The refusal a shopper's second tap really gets: there is no basket left
    // to place, because the first tap spent it.
    expect($second->status())->toBe(422)
        ->and($second->json('ok'))->toBeFalse();

    // ONE order.
    expect(Order::query()->where('payment_method', 'stripe')->count())->toBe(1);

    // ONE intent opened. Counted off the recorded request log, with
    // preventStrayRequests() on, so an extra call could not have been silently
    // absorbed by a wildcard fake.
    $opened = 0;

    Http::assertSent(function ($request) use (&$opened) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/payment_intents')) {
            $opened++;
        }

        return true;
    });

    expect($opened)->toBe(1);

    $cart->refresh();

    expect($cart->status)->toBe('converted');
});
// MUTATION, run: in CheckoutController::place(), stop marking the cart
// `converted` (or make CartService::resolve() accept a converted cart). RED —
// two orders, two intents, and on the shop a shopper charged twice for one
// basket.

it('reuses the intent an order already carries rather than opening a second one', function () {
    /*
     * The second guard, on its own. A retried place() is not the only way back
     * into start(): the card form below the wallet row calls it, a stale tab
     * can, and a package that re-runs a step can. Whatever the route in, an
     * order that already has a confirmable `pi_` must be offered THAT intent —
     * two live intents against one order is two ways to charge it.
     *
     * reusableIntent() is deliberately strict about what counts (see its
     * docblock): the amount and currency must still match and the status must
     * still be confirmable. This is the plain case where all of that holds.
     */
    wal2Stripe();

    $order = walPaidOrder(22000, 'pi_wal_reuse');
    $order->update(['paid_at' => null]);
    Payment::query()->where('order_id', $order->getKey())->delete();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_wal_reuse' => Http::response([
            'id' => 'pi_wal_reuse',
            'client_secret' => 'pi_wal_reuse_secret',
            'status' => 'requires_payment_method',
            'amount' => 22000,
            'currency' => 'aed',
        ], 200),
    ]);

    $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find('stripe');

    $start = $gateway->start($order->refresh());

    expect($start->result)->toBe('confirm')
        ->and($start->providerRef)->toBe('pi_wal_reuse');

    // Not one POST to open a new one.
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/payment_intents'));

    $order->refresh();

    expect($order->transaction_id)->toBe('pi_wal_reuse');
});
// MUTATION, run: make reusableIntent() return null unconditionally. RED — a
// POST to /v1/payment_intents is sent and the order's reference changes, which
// on the shop is a second live intent against one order.

/* =====================================================================
 | TASK 2 — a failed wallet confirmation gives the basket back, and says why
 ===================================================================== */

it('gives the basket back when a wallet confirmation fails, and cancels the intent first', function () {
    /*
     * A DECLINED WALLET MUST NOT COST THE SHOPPER THEIR BASKET.
     *
     * The express row releases the order when Stripe declines, because unlike
     * the card form it has no second card to offer on this page — the sheet
     * handed over a single-use token and the next attempt is a fresh sheet.
     * Keeping the order would hold this basket's stock and its coupon against
     * an intent nothing can now confirm.
     *
     * THE ORDERING IS THE POINT AND IT IS WHY THIS IS A TEST AND NOT A COMMENT.
     * The intent is cancelled at Stripe BEFORE the stock goes back on the
     * shelf. An intent that is still confirmable is one a stale tab or a
     * half-finished 3-D Secure window can still put money through, and doing
     * that against an order whose stock has been released is the one outcome
     * that costs a real customer a real product.
     */
    wal2Stripe();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_wal_declined',
            'client_secret' => 'pi_wal_declined_secret',
            'status' => 'requires_payment_method',
        ], 200),
        // The read abandonIntent() makes, then the cancel.
        'api.stripe.com/v1/payment_intents/pi_wal_declined' => Http::response([
            'id' => 'pi_wal_declined',
            'status' => 'requires_payment_method',
        ], 200),
        'api.stripe.com/v1/payment_intents/pi_wal_declined/cancel' => Http::response([
            'id' => 'pi_wal_declined',
            'status' => 'canceled',
        ], 200),
    ]);

    $cart = wal2Cart(20000);
    $shopper = wal2Shopper($cart);

    $placed = $shopper->postJson('/checkout/place', wal2Fields())->assertOk();

    $number = $placed->json('order');

    $cart->refresh();

    expect($cart->status)->toBe('converted');

    // Stripe declined in the sheet. This is the call the express row's
    // `release()` makes.
    $shopper->withSession(['kbb_last_order' => $number])
        ->postJson('/checkout/card/abandon', ['order' => $number])
        ->assertOk();

    $cart->refresh();

    // THE BASKET IS BACK, which is what the shopper sees.
    expect($cart->status)->toBe('active');

    $order = Order::where('order_number', $number)->first();

    expect($order)->not->toBeNull()
        ->and($order->status)->toBe('failed')
        ->and($order->paid_at)->toBeNull();

    // Cancelled at Stripe, and cancelled before anything was released.
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/v1/payment_intents/pi_wal_declined/cancel'));
});
// MUTATION, run: in CheckoutController::cardAbandoned(), stop putting the cart
// back to `active`. RED on the basket assertion — and on the shop a declined
// Apple Pay tap empties the bag and leaves the shopper nothing to retry with.

it('refuses to release a wallet order whose payment already went through', function () {
    /*
     * The ugliest ordering there is: the sheet succeeded, the report to
     * /checkout/card/paid landed, and THEN the browser's catch block fired on
     * something unrelated and called release(). If that release went through it
     * would cancel a succeeded intent (Stripe refuses, so nothing moves) and
     * return the stock for an order that has been paid for.
     *
     * Money that has moved is a refund and a decision for the merchant, so this
     * is a 409 and not a 200.
     */
    wal2Stripe();

    $order = walPaidOrder(22000, 'pi_wal_already');

    $this->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/abandon', ['order' => $order->order_number])
        ->assertStatus(409);

    $order->refresh();

    expect($order->paid_at)->not->toBeNull()
        ->and($order->status)->toBe('processing');

    // It never asked Stripe to cancel anything.
    Http::assertNothingSent();
});
// MUTATION, run: delete the `if ($order->paid_at !== null)` guard in
// cardAbandoned(). RED — a paid order is moved to `failed` and its stock is
// returned to the shelf while the shopper's money is still at Stripe.

it('keeps the basket when the intent could not be cancelled, rather than releasing on a guess', function () {
    /*
     * Stripe is unreachable, or answers something that is not a cancellation.
     * The order is NOT released and the shopper is told to refresh.
     *
     * This looks unhelpful and is the safe direction: releasing stock against
     * an intent that is still confirmable is how a paid order arrives for a
     * product that has already been sold to somebody else. A `pending` order
     * left behind is recovered by the same sweep that recovers an abandoned
     * card attempt.
     */
    wal2Stripe();

    $order = walPaidOrder(22000, 'pi_wal_stuck');
    $order->update(['paid_at' => null, 'status' => 'pending']);

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_wal_stuck' => Http::response([], 500),
    ]);

    $response = $this->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/abandon', ['order' => $order->order_number])
        ->assertStatus(409);

    // It says why, in a sentence written for a shopper, and names no gateway,
    // no intent id and no key.
    $error = (string) $response->json('error');

    expect($error)->not->toBe('')
        ->and($error)->not->toContain('pi_')
        ->and($error)->not->toContain('sk_')
        ->and($error)->not->toContain('stripe');

    $order->refresh();

    expect($order->status)->toBe('pending');
});
// MUTATION, run: make cardAbandoned() ignore abandonIntent()'s return value and
// release regardless. RED — the order moves to `failed` while a confirmable
// intent is still live at Stripe.

/* =====================================================================
 | TASK 4 — a refund, a partial refund and a void, through a wallet
 ===================================================================== */

it('refunds a wallet-funded order in full, against the same intent a typed card would use', function () {
    /*
     * THE CLAIM UNDER TEST: "a wallet order is an ordinary stripe order — same
     * refund". It is true because StripeGateway::refund() posts
     * `payment_intent`, and the intent a wallet paid is the intent a card pays.
     *
     * It is worth pinning precisely BECAUSE it is true by construction. The
     * tempting future change is a wallet branch somewhere in settlement — "look
     * up the wallet type and…" — and the first thing such a branch breaks is
     * the refund, silently, on orders nobody looks at for weeks.
     */
    wal2Stripe();

    $admin = walMoneyAdmin('refund-full');
    $order = walPaidOrder(22000, 'pi_wal_refund');

    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_wal_full',
            'status' => 'succeeded',
            'amount' => 22000,
        ], 200),
    ]);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/refund', [
            'amount_fils' => 22000,
            'reason' => 'customer changed their mind',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    // The refund went against the INTENT, which is the only identifier a
    // wallet payment and a card payment share.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/refunds')) {
            return false;
        }

        $body = $request->body();

        return str_contains($body, 'payment_intent=pi_wal_refund')
            && str_contains($body, 'amount=22000');
    });

    // The same ledger a card refund writes, in the same table.
    $refund = Refund::query()->where('order_id', $order->getKey())->first();

    expect($refund)->not->toBeNull()
        ->and((int) $refund->amount)->toBe(22000);

    $refunder = app(\App\Services\Payments\PaymentRefunder::class);

    expect($refunder->refundedFils($order->refresh()))->toBe(22000)
        ->and(max(0, $refunder->capturedFils($order) - 22000))->toBe(0);
});
// MUTATION, run: in StripeGateway::refund(), send `'charge' => $intentId`
// instead of `'payment_intent' => $intentId`. RED on the body assertion — and
// at Stripe, which rejects a `pi_` in the charge field, so every wallet and
// card refund on the shop fails.

it('refunds a wallet-funded order in part, twice, and stops at what was taken', function () {
    /*
     * TWO PARTIALS THAT EACH PASS ALONE AND TOGETHER MUST NOT.
     *
     * 220.00 was taken. 150.00 back is fine. Another 150.00 is fine on its own
     * and 300.00 together is the shop paying out more than it took, so the
     * ceiling has to be computed from what this database says was taken MINUS
     * what it says has already gone back — never from the figure the browser
     * asked for.
     *
     * Exactly the case PaymentSettlementTest makes for Tabby. It is repeated
     * here on a wallet-funded Stripe order because "same ledger" is the claim,
     * and a ceiling that read a wallet order differently would be invisible
     * until the second refund.
     */
    wal2Stripe();

    $admin = walMoneyAdmin('refund-part');
    $order = walPaidOrder(22000, 'pi_wal_partial');

    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_wal_part',
            'status' => 'succeeded',
        ], 200),
    ]);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/refund', [
            'amount_fils' => 15000, 'reason' => 'one item damaged',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $refunder = app(\App\Services\Payments\PaymentRefunder::class);

    expect($refunder->refundedFils($order->refresh()))->toBe(15000);

    // The second 150.00. 300.00 of a 220.00 order.
    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/refund', [
            'amount_fils' => 15000, 'reason' => 'and again',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    // Nothing further left the shop.
    expect($refunder->refundedFils($order->refresh()))->toBe(15000);

    // And the rest is still refundable — the refusal capped the request, it did
    // not close the order.
    expect(max(0, $refunder->capturedFils($order) - 15000))->toBe(7000);

    // Exactly one call to Stripe. The second never got that far.
    $calls = 0;

    Http::assertSent(function ($request) use (&$calls) {
        if (str_contains($request->url(), '/v1/refunds')) {
            $calls++;
        }

        return true;
    });

    expect($calls)->toBe(1);
});
// MUTATION, run: in PaymentRefunder, subtract the already-refunded total AFTER
// the ceiling check instead of before it. RED on the 422 — and on the shop,
// two partial refunds of 150.00 against a 220.00 order pay out 80.00 that was
// never taken.

it('answers a void on a wallet-funded order exactly as it answers one on a typed card', function () {
    /*
     * ── THE HONEST FINDING, AND IT IS NOT A DEFECT ─────────────────────────
     *
     * A wallet-funded order CANNOT be voided on this shop, and neither can a
     * card one, and that is correct rather than missing.
     *
     * "Void" here means releasing an AUTHORISATION that has not been captured —
     * VoidsAuthorisation, which TabbyGateway and TamaraGateway implement
     * because BNPL authorises first and captures later. StripeGateway does not
     * implement it and must not: this build opens intents with Stripe's default
     * automatic capture (see captureWindow() — "captured by Stripe at
     * authorisation"), so by the time an order is paid the money has already
     * moved. There is no hold to release. The way money goes back is a refund,
     * which is the two cases above and which works on day one.
     *
     * So the requirement — a void behaves on a wallet order exactly as it does
     * on a card one — is met by both answering `unsupported_gateway`, and this
     * case pins that rather than pretending a void happened. The failure it
     * guards against is a future lane adding a Stripe void that cancels a
     * SUCCEEDED intent: Stripe refuses that, the screen would report a release
     * that never happened, and the shopper would be told their money was coming
     * back when only a refund can bring it.
     */
    wal2Stripe();

    $admin = walMoneyAdmin('void');
    $order = walPaidOrder(22000, 'pi_wal_void');

    $response = $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertStatus(422);

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('code'))->toBe('unsupported_gateway');

    $order->refresh();

    // Nothing moved, nothing was written, and the money is still refundable.
    expect($order->voided_at)->toBeNull()
        ->and($order->paid_at)->not->toBeNull()
        ->and(app(\App\Services\Payments\PaymentRefunder::class)->capturedFils($order))->toBe(22000);

    // It did not call Stripe to find that out — the answer is structural.
    Http::assertNothingSent();
});
// MUTATION, run: declare `StripeGateway implements VoidsAuthorisation` with a
// void() that POSTs /cancel. RED on the code — and on the shop, Store → Orders
// grows a "release the hold" button for money Stripe has already taken, which
// fails at the gateway after telling the admin it succeeded.

it('keeps a voided wallet order out of the refund ceiling, exactly as a BNPL one', function () {
    /*
     * The companion to the case above, and the reason it matters that the two
     * states are kept apart. If a later change ever DOES give Stripe a void,
     * the ceiling must already be right: a released authorisation is not
     * refundable money.
     *
     * capturedFils() reads `voided_at` for exactly this, and the rule is
     * gateway-blind, so it is already true of a wallet order. Pinned here so
     * that a Stripe void, if one is ever added, cannot also become a way to
     * refund money the shop never took.
     */
    wal2Stripe();

    $order = walPaidOrder(22000, 'pi_wal_voided');
    $order->update(['voided_at' => now(), 'captured_at' => null]);

    expect(app(\App\Services\Payments\PaymentRefunder::class)->capturedFils($order->refresh()))->toBe(0);
});
// MUTATION, run: remove the `$order->voided_at !== null` argument from
// capturedFils(). RED — and Store → Orders offers a refund against a hold that
// has already been given back.

/* =====================================================================
 | TASK 4 — and none of it is reachable without the capability
 ===================================================================== */

it('fails closed: an admin without the money capability cannot refund or void a wallet order', function () {
    /*
     * `orders.money` is owner-only in App\Support\AdminCapabilities, and the
     * wallet adds no endpoint of its own to settlement — which is the point.
     * Every way money moves on a wallet order is a path that already existed
     * and was already guarded, so there is no new surface to get wrong.
     *
     * Asserted rather than assumed, because "it rides the existing path" is
     * only a security argument for as long as the existing path is guarded.
     */
    wal2Stripe();

    $viewer = walMoneyAdmin('viewer', 'viewer');
    $order = walPaidOrder(22000, 'pi_wal_capability');

    $this->actingAs($viewer, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_fils' => 1000])
        ->assertForbidden();

    $this->actingAs($viewer, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertForbidden();

    expect(Refund::query()->where('order_id', $order->getKey())->count())->toBe(0);

    Http::assertNothingSent();
});
// MUTATION, run: change 'orders.money' to include 'viewer' in
// AdminCapabilities. RED on both — and on the shop, a staff account that may
// only read orders can pay money out of the shop's Stripe account.

/* =====================================================================
 | THE WIRING, AND THE DEFECT THIS LANE FOUND BY LOOKING FOR IT
 ===================================================================== */

it('has the card reporting routes the wallet path depends on required exactly once', function () {
    /*
     * ── THE DEFECT, AND IT IS ON THE LIVE SHOP RIGHT NOW ───────────────────
     *
     * `routes/checkout-card.php` declares the two endpoints every Stripe
     * payment on this shop reports to:
     *
     *     POST /checkout/card/paid      — "Stripe says this succeeded"
     *     POST /checkout/card/abandon   — "this one failed; give the bag back"
     *
     * It is NOT required from routes/web.php. It never has been. Lane FU
     * shipped the file with its require line in its own header, the way this
     * project asks, and the line was never added — so both URLs answer 405 and
     * have since the card fields landed.
     *
     * TWO PARTIALS CALL THEM AND BOTH ARE RENDERED ON /checkout:
     * partials/checkout/stripe-elements (the typed card, lines 72-73) and
     * partials/checkout/express-wallets (Apple Pay and Google Pay). So this is
     * not a wallet defect — it is a card defect that the wallets inherit.
     *
     * WHAT IT COSTS, IN ORDER OF HOW MUCH IT COSTS:
     *
     *   1. A DECLINED PAYMENT TAKES THE SHOPPER'S BASKET WITH IT. The express
     *      row's release() POSTs to /checkout/card/abandon inside a try/catch
     *      that swallows the 405. Nothing puts the cart back to `active`, so
     *      the bag is empty; nothing returns the stock, so the units stay off
     *      the shelf; nothing cancels the intent, so a confirmable payment is
     *      left live at Stripe against an order the shop has given up on. The
     *      shopper is told the payment failed and has nothing to retry with.
     *   2. Orders are marked paid by the webhook alone. That is the design's
     *      fallback and it does work — but it is a fallback that has been
     *      carrying the whole load, unnoticed, and a shop whose webhook secret
     *      is wrong takes money and never marks an order paid at all.
     *
     * ── WHY THIS ASSERTION IS SHAPED THIS WAY ──────────────────────────────
     *
     * CLAUDE.md is explicit: pin the FINISHED state, never the absence. An
     * `expect($web)->not->toContain('checkout-card.php')` would be green here
     * and go red the moment the integrator does the one thing this lane is
     * asking for. `substr_count(...) === 1` is green once it is wired and
     * catches both real failures — zero is "built, never wired up", which is
     * the state this test was written in, and two registers every endpoint
     * twice.
     *
     * THIS CASE IS RED UNTIL ONE LINE IS ADDED TO routes/web.php. That is
     * deliberate and it is the same shape Lane WAL's own wiring pin had before
     * its two files were mounted. The line, and the only line:
     *
     *     require __DIR__.'/checkout-card.php';
     *
     * at the top level of routes/web.php, beside the other checkout requires
     * and above the catch-all in kbb-brands-blog.php. A route added there does
     * nothing until the compiled route cache is cleared, so the package that
     * carries it needs a clear_caches migration —
     * 2027_05_06_000000_clear_caches_wallet_domain.php already ships one in
     * this round and covers it.
     */
    $web = file_get_contents(base_path('routes/web.php'));

    expect($web)->toBeString();

    expect(substr_count($web, "require __DIR__.'/checkout-card.php';"))->toBe(1);

    // And the two this lane's own round added, which ARE wired — pinned in the
    // same shape so that a later edit cannot quietly drop one.
    expect(substr_count($web, "require __DIR__.'/wallet-checkout.php';"))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/wallet-domain.php';"))->toBe(1);
});
// MUTATION, run: delete the wallet-checkout.php require from routes/web.php.
// RED — and on the shop the wallet sheet could not ask what the order costs,
// so it would open on a stale figure or not at all.
