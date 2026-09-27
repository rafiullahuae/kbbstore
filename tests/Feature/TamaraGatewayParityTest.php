<?php

/**
 * Tamara — the gaps between this shop and the merchant's own WooCommerce plugin,
 * and the defects found closing them.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * TamaraWebhookTest already attacks the signature. This file attacks everything
 * else, and every case in it is a way the shop loses money, charges a customer
 * for an order that does not exist, or simply cannot sell:
 *
 *   - AN AMOUNT BLOCK THAT DOES NOT ADD UP. Tamara validates
 *     total = Σitems + shipping + tax − discount on every call and refuses the
 *     ones that fail. The payload sent `tax_amount = orders.tax_total` beside
 *     item totals that already contained that tax, so the day the owner sets a
 *     country to INCLUSIVE live VAT — which he said he would do for the UAE, in
 *     as many words — every Tamara session in the shop stops being created. The
 *     first four cases below are that bug, from both sides.
 *
 *   - NO WAY TO RELEASE AN AUTHORISATION. A cancelled order left the buyer's
 *     instalment plan live at Tamara for up to 180 days. The plugin has this;
 *     this shop had no verb for it at all.
 *
 *   - A WEBHOOK NOBODY REGISTERED. handleWebhook() has always handled
 *     `order_expired` and `order_declined`, and Tamara sends neither to an
 *     unregistered endpoint. The decline branch was unreachable code.
 *
 *   - NO BASKET LIMITS. Tamara refuses a session outside the merchant's agreed
 *     range, and this shop offered it on every basket down to one fil.
 *
 *   - ONE HARDCODED PAYMENT TYPE, ONE HARDCODED LOCALE, NO RISK BLOCK, AND NO
 *     PHONE CHECK. An Arabic shopper got an English Tamara page; every returning
 *     customer was scored as a stranger; an order with no phone burned a round
 *     trip to be told "choose another payment method".
 *
 * Http::preventStrayRequests() is on throughout, which is what makes "this path
 * makes NO call to Tamara" a real assertion rather than a hopeful one.
 *
 * MUTATION NOTES are on the cases that pin a specific defect: each says what to
 * put back to turn the test red, so a reader can prove the assertion asserts
 * something.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TamaraGateway;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

const PG1_URL_SECRET = 'whsec-tamara-pg1-0123456789abcd';
const PG1_NOTIFY_KEY = 'pg1-tamara-notification-token';
const PG1_API_TOKEN = 'PG1_TAMARA_API_TOKEN_CANARY';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();

    /*
     * Exactly the mounting routes/payments-tamara.php asks the integrator for:
     * the existing admin-api group, guard included.
     *
     * ONE middleware() CALL, NOT TWO — RouteRegistrar::middleware() REPLACES the
     * pending middleware rather than appending to it, so chaining two calls
     * registers routes carrying only the second and the guard test below would
     * pass against no guard at all. The same trap PaymentSettlementTest and
     * Tests\Support\UgcAdminRoutes both write out.
     */
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/payments-tamara.php'));
});

/* ───────────────────────────────────────────────────────────────── fixtures */

function pg1Provider(array $config = []): void
{
    $row = PaymentProvider::create([
        'id' => 'tamara',
        'title' => 'Tamara',
        'enabled' => true,
        'mode' => 'test',
        'position' => 2,
    ]);

    $row->config = array_merge([
        'api_token' => PG1_API_TOKEN,
        'notification_token' => PG1_NOTIFY_KEY,
        'webhook_secret' => PG1_URL_SECRET,
    ], $config);

    $row->save();

    app(GatewayCredentials::class)->forget();
}

function pg1Gateway(): TamaraGateway
{
    /** @var TamaraGateway $gateway */
    $gateway = app(GatewayRegistry::class)->find('tamara');

    return $gateway;
}

/**
 * An order with real line items, because the amount identity is about them.
 *
 * `total` is passed explicitly rather than derived, so a case can build the
 * exact arrangement it wants to test — an inclusive-VAT order has a tax_total
 * INSIDE its total and an exclusive one has it on top, and the whole point is
 * that both balance.
 */
function pg1Order(array $attributes = [], array $lines = [['qty' => 2, 'unit' => 5000]]): Order
{
    $itemsFils = 0;

    foreach ($lines as $line) {
        $itemsFils += $line['qty'] * $line['unit'];
    }

    $order = Order::create(array_merge([
        'order_number' => 'PG1-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => $itemsFils,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'fee_total' => 0,
        'total' => $itemsFils,
        'payment_method' => 'tamara',
    ], $attributes));

    foreach ($lines as $i => $line) {
        $order->items()->create([
            'name' => 'Line ' . $i,
            'sku' => 'SKU-' . $i,
            'quantity' => $line['qty'],
            'unit_price' => $line['unit'],
            'subtotal' => $line['qty'] * $line['unit'],
            'total' => $line['qty'] * $line['unit'],
        ]);
    }

    return $order->fresh();
}

/** The `/checkout` fake every start() case needs. */
function pg1FakeCheckout(): void
{
    Http::fake([
        '*/checkout' => Http::response(['order_id' => 'tam_pg1', 'checkout_url' => 'https://checkout.tamara.co/x']),
    ]);
}

/** The body of the one request sent to a path containing $needle. */
function pg1SentBody(string $needle): array
{
    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), $needle)) {
            return $request->data();
        }
    }

    return [];
}

function pg1Admin(string $tag): AdminUser
{
    return AdminUser::create([
        'name' => 'PG1 Admin',
        'email' => 'pg1-' . $tag . '-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

/**
 * Does one Tamara amount block satisfy the identity Tamara validates?
 *
 *     total_amount = Σ items[].total_amount + shipping_amount + tax_amount
 *                    − discount_amount
 *
 * Compared in FILS, through the same round-trip the gateway uses, because the
 * whole point of toFils() is that (int) (10.10 * 100) is 1009 on a binary float
 * and a one-fil disagreement is a refused payment on a correct order.
 */
function pg1Balances(array $body): bool
{
    $fils = fn ($block) => (int) round(((float) ($block['amount'] ?? 0)) * 100);

    $items = 0;

    foreach ($body['items'] ?? [] as $item) {
        $items += $fils($item['total_amount'] ?? []);
    }

    // `/checkout` nests the discount under `discount.amount`; capture and cancel
    // send a flat `discount_amount`. Both shapes are Tamara's, not a choice.
    $discount = isset($body['discount']['amount'])
        ? $fils($body['discount']['amount'])
        : $fils($body['discount_amount'] ?? []);

    return $fils($body['total_amount'] ?? [])
        === $items + $fils($body['shipping_amount'] ?? []) + $fils($body['tax_amount'] ?? []) - $discount;
}

/* ═════════════════════════════════════════ 1. the amount block adds up ═════ */

it('sends an amount block that adds up on a shipped-default order', function () {
    /*
     * THE BASELINE, AND IT IS ALSO A RULE-1 PIN.
     *
     * tax_mode = 'display' is what every existing install has: tax_total is 0,
     * there is no fee, and `total` is subtotal − discount + shipping. The block
     * this sends has to be BYTE-IDENTICAL to the one sent before amounts()
     * existed, or applying the package moves money on a shop that changed
     * nothing. tax 0 and discount = discount_total is exactly what the old
     * `$this->money((int) $order->tax_total, …)` produced here.
     */
    pg1Provider();
    pg1FakeCheckout();

    $order = pg1Order([
        'subtotal' => 10000,
        'discount_total' => 1000,
        'shipping_total' => 2000,
        'tax_total' => 0,
        'total' => 11000,             // 100 − 10 + 20
    ]);

    pg1Gateway()->start($order);

    $body = pg1SentBody('/checkout');

    expect(pg1Balances($body))->toBeTrue();
    expect($body['tax_amount']['amount'])->toBe(0.0);
    expect($body['discount']['amount']['amount'])->toBe(10.0);
});

it('sends an amount block that adds up when VAT is inclusive', function () {
    /*
     * THE DEFECT, AND THE REASON THIS FILE EXISTS.
     *
     * Inclusive VAT: the tax is ALREADY INSIDE the line prices and inside
     * `total`. VatDisplay records what was inside it in `orders.tax_total`, and
     * CartService does not add it to the total ($tax['added'] is false). So:
     *
     *     total       10000
     *     tax_total     476      (inside the 10000)
     *     Σ items     10000
     *
     * The old payload sent tax_amount = 476, making Tamara's sum 10476 against
     * a declared total of 10000. Tamara refuses that session — not one order,
     * every order in the shop, from the moment Store → Ecommerce → Tax has one
     * inclusive country on `live`. And it would have presented as "Tamara broke
     * when we turned on tax", with nothing to read in the log because
     * RemoteGateway deliberately never records request bodies.
     *
     * MUTATION: in TamaraGateway::start(), put
     *     'tax_amount' => $this->money((int) $order->tax_total, $currency),
     * back in place of the amounts() line. This case goes red — 10476 ≠ 10000 —
     * and the shipped-default case above stays green, which is the pair that
     * proves the fix is a fix and not a change.
     */
    pg1Provider();
    pg1FakeCheckout();

    $order = pg1Order([
        'subtotal' => 10000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 476,           // 5% of 10000, inclusive — already inside
        'tax_basis' => 'inclusive',
        'total' => 10000,             // unchanged by an inclusive rate
    ]);

    pg1Gateway()->start($order);

    $body = pg1SentBody('/checkout');

    expect(pg1Balances($body))->toBeTrue();
    // Zero, because the 4.76 is already inside the line totals. Sending it
    // again is double-counting, which is the whole bug.
    expect($body['tax_amount']['amount'])->toBe(0.0);
});

it('sends an amount block that adds up when VAT is exclusive', function () {
    /*
     * The other basis, where the tax IS additive and therefore IS the residual.
     * This is the case the old code got right by accident, and it has to stay
     * right: the fix must not trade one basis for the other.
     */
    pg1Provider();
    pg1FakeCheckout();

    $order = pg1Order([
        'subtotal' => 10000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 500,           // 5% exclusive — added on top
        'tax_basis' => 'exclusive',
        'total' => 10500,
    ]);

    pg1Gateway()->start($order);

    $body = pg1SentBody('/checkout');

    expect(pg1Balances($body))->toBeTrue();
    expect($body['tax_amount']['amount'])->toBe(5.0);
});

it('keeps the block balanced when the order carries a surcharge', function () {
    /*
     * `orders.fee_total` is real money inside `total` and Tamara has no field
     * for it — the COD surcharge and the gift-wrap fee both land there. Tamara
     * is never the gateway on a COD order, so this is defensive rather than
     * live; it is here because the residual is DERIVED from the order's columns
     * rather than assumed to be tax, and that property is worth pinning
     * directly. An order whose total exceeds its parts by a fee still balances.
     */
    pg1Provider();
    pg1FakeCheckout();

    $order = pg1Order([
        'subtotal' => 10000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'tax_total' => 0,
        'fee_total' => 700,
        'total' => 12700,
    ]);

    pg1Gateway()->start($order);

    expect(pg1Balances(pg1SentBody('/checkout')))->toBeTrue();
});

it('keeps the block balanced when the lines come to more than the total', function () {
    /*
     * The negative-residual branch. Nothing this shop's checkout produces lands
     * here — a hand-built or imported order can, and a negative `tax_amount` is
     * not a thing Tamara accepts. The balance is taken on the discount side.
     */
    pg1Provider();
    pg1FakeCheckout();

    $order = pg1Order([
        'subtotal' => 10000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'total' => 9000,              // 1000 less than its own lines
    ]);

    pg1Gateway()->start($order);

    $body = pg1SentBody('/checkout');

    expect(pg1Balances($body))->toBeTrue();
    expect($body['tax_amount']['amount'])->toBe(0.0);
    expect($body['discount']['amount']['amount'])->toBe(10.0);
});

it('sends a capture block that adds up under inclusive VAT', function () {
    /*
     * THE SAME DEFECT ON THE SECOND ENDPOINT, and the more expensive one: a
     * refused session costs a sale, a refused CAPTURE costs the whole order.
     * The goods have shipped, `paid_at` says paid, and the money is never taken
     * — which is precisely the failure PaymentCapturer's own header says this
     * project already shipped once.
     *
     * MUTATION: restore the three `(int) $order->…` amount lines in capture().
     * Red.
     */
    pg1Provider();

    $order = pg1Order([
        'subtotal' => 10000,
        'tax_total' => 476,
        'tax_basis' => 'inclusive',
        'total' => 10000,
        'transaction_id' => 'tam_cap',
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_cap',
            'order_reference_id' => $order->order_number,
            'status' => 'authorised',
        ]),
        '*/payments/capture' => Http::response(['capture_id' => 'cap_1']),
    ]);

    $result = pg1Gateway()->capture($order, 10000);

    expect($result->ok)->toBeTrue();
    expect(pg1Balances(pg1SentBody('/payments/capture')))->toBeTrue();
});

/* ═════════════════════════════════════════════════ 2. releasing the hold ═══ */

it('releases an uncaptured tamara authorisation and records it', function () {
    pg1Provider();

    $order = pg1Order([
        'status' => 'cancelled',
        'transaction_id' => 'tam_void',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_void',
            'order_reference_id' => $order->order_number,
            'status' => 'authorised',
        ]),
        '*/cancel' => Http::response(['order_id' => 'tam_void', 'cancel_id' => 'cancel_99']),
    ]);

    $result = app(\App\Services\Payments\PaymentVoider::class)->void($order, 'Tester');

    expect($result->ok)->toBeTrue()
        ->and($result->code)->toBe('voided')
        ->and($result->reference)->toBe('cancel_99');

    $order->refresh();

    expect($order->voided_at)->not->toBeNull()
        ->and($order->void_ref)->toBe('cancel_99');

    // Recorded in the ledger, and noted on the order, so a customer asking why
    // they are still being billed can be answered from the order screen.
    expect(PaymentEvent::where('type', 'void')->where('external_id', 'cancel_99')->count())->toBe(1);
    expect($order->notes()->get()->contains(fn ($n) => str_contains($n->content, 'Released the tamara authorisation')))
        ->toBeTrue();

    // And the cancel body balances, like every other amount block.
    expect(pg1Balances(pg1SentBody('/cancel')))->toBeTrue();
});

it('refuses to release an authorisation that has already been captured', function () {
    /*
     * The customer's money has moved. A cancel cannot take it back and Tamara
     * would refuse it; the answer is a refund, and this is the ONE message that
     * says so — the plugin writes the same sentence
     * ("...it was captured. Please try Refund function instead.").
     *
     * NO CALL IS MADE. That is the assertion worth having: the check is made
     * from this shop's own `capture_ref`, so a double-click on a captured order
     * costs nothing and cannot race a real capture.
     */
    pg1Provider();

    $order = pg1Order([
        'status' => 'cancelled',
        'transaction_id' => 'tam_cap2',
        'paid_at' => now(),
        'captured_at' => now(),
        'capture_ref' => 'cap_existing',
    ]);

    Http::fake();

    $result = app(\App\Services\Payments\PaymentVoider::class)->void($order);

    expect($result->ok)->toBeFalse()
        ->and($result->code)->toBe('already_captured');

    expect(str_contains((string) $result->message, 'Refund'))->toBeTrue();

    Http::assertNothingSent();
    expect($order->fresh()->voided_at)->toBeNull();
});

it('refuses to release an order tamara reports as captured', function () {
    /*
     * The second half of the same guard, and the case the cheap check cannot
     * see: a capture made in Tamara's own dashboard that this shop has never
     * heard of. Blindly cancelling there would be the worst available outcome.
     */
    pg1Provider();

    $order = pg1Order([
        'status' => 'cancelled',
        'transaction_id' => 'tam_remote_cap',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_remote_cap',
            'order_reference_id' => $order->order_number,
            'status' => 'fully_captured',
        ]),
    ]);

    $result = app(\App\Services\Payments\PaymentVoider::class)->void($order);

    expect($result->ok)->toBeFalse()->and($result->code)->toBe('already_captured');

    // The claim was taken and RELEASED, so the operator can act again once the
    // capture is reconciled. A claim left behind here would disable the only
    // button that could fix the order.
    expect($order->fresh()->voided_at)->toBeNull();
});

it('treats an order tamara has already cancelled as released', function () {
    pg1Provider();

    $order = pg1Order([
        'status' => 'cancelled',
        'transaction_id' => 'tam_already',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_already',
            'order_reference_id' => $order->order_number,
            'status' => 'canceled',       // Tamara's own spelling, one L
            'cancel_id' => 'cancel_old',
        ]),
    ]);

    $result = pg1Gateway()->void($order, 10000);

    expect($result->ok)->toBeTrue()
        ->and($result->code)->toBe('already_voided')
        ->and($result->reference)->toBe('cancel_old');
});

it('refuses to release when the tamara order belongs to a different reference', function () {
    /*
     * The same guard capture() and handleWebhook() make. A Tamara order id
     * pointed at somebody else's order would otherwise cancel THEIR payment
     * plan — a stranger's instalments closed by this shop's order screen.
     */
    pg1Provider();

    $order = pg1Order([
        'status' => 'cancelled',
        'transaction_id' => 'tam_other',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_other',
            'order_reference_id' => 'SOMEBODY-ELSES-ORDER',
            'status' => 'authorised',
        ]),
        '*/cancel' => Http::response(['cancel_id' => 'nope']),
    ]);

    $result = pg1Gateway()->void($order, 10000);

    expect($result->ok)->toBeFalse()->and($result->code)->toBe('reference_mismatch');

    // The cancel itself was never reached.
    $cancelled = collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]->url(), '/cancel'));
    expect($cancelled)->toBeFalse();
});

it('releases once when the button is pressed twice', function () {
    /*
     * A void can arrive from the order screen, from a bulk status change on the
     * orders list, and from an operator who did not see the first one work, all
     * within a few seconds. `voided_at` is claimed with one conditional UPDATE
     * before the provider is called, so two of those cannot become two cancel
     * calls at Tamara.
     *
     * MUTATION: drop the whereNull('voided_at') from PaymentVoider's claim and
     * the second press sends a second cancel. Red on the call count.
     */
    pg1Provider();

    $order = pg1Order([
        'status' => 'cancelled',
        'transaction_id' => 'tam_twice',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_twice',
            'order_reference_id' => $order->order_number,
            'status' => 'authorised',
        ]),
        '*/cancel' => Http::response(['cancel_id' => 'cancel_once']),
    ]);

    $voider = app(\App\Services\Payments\PaymentVoider::class);

    $first = $voider->void($order);
    $second = $voider->void($order->fresh());

    expect($first->code)->toBe('voided')
        ->and($second->ok)->toBeTrue()
        ->and($second->code)->toBe('already_voided');

    $cancels = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/cancel'))->count();
    expect($cancels)->toBe(1);
});

it('leaves the order retryable when tamara refuses the release', function () {
    /*
     * An order left marked voided after a failed call is an authorisation the
     * shop believes it gave back and did not — and the column that is wrong is
     * the one that disables the retry. The claim is released, the failure is in
     * the ledger, and the note says the authorisation is STILL LIVE.
     *
     * MUTATION: delete the release-the-claim UPDATE in PaymentVoider's failure
     * branch. Red on `voided_at` and on the second attempt.
     */
    pg1Provider();

    $order = pg1Order([
        'status' => 'cancelled',
        'transaction_id' => 'tam_fail',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_fail',
            'order_reference_id' => $order->order_number,
            'status' => 'authorised',
        ]),
        '*/cancel' => Http::response(['error_code' => 'cancel_not_allowed'], 409),
    ]);

    $result = app(\App\Services\Payments\PaymentVoider::class)->void($order);

    expect($result->ok)->toBeFalse();

    $order->refresh();

    expect($order->voided_at)->toBeNull()
        ->and($order->void_ref)->toBeNull();

    expect(PaymentEvent::where('type', 'void_failed')->count())->toBe(1);
    expect($order->notes()->get()->contains(fn ($n) => str_contains($n->content, 'STILL LIVE')))->toBeTrue();
});

it('refuses to release the authorisation on an order that is still live', function () {
    /*
     * The most expensive mistake available on this endpoint: releasing the
     * authorisation on an order the shop still intends to ship. The goods go
     * out and the plan the customer signed up to no longer exists, and nothing
     * recreates it — start() would have to send them through Tamara's checkout
     * again, and they have gone.
     */
    pg1Provider();

    $order = pg1Order([
        'status' => 'processing',
        'transaction_id' => 'tam_live',
        'paid_at' => now(),
    ]);

    Http::fake();

    $result = app(\App\Services\Payments\PaymentVoider::class)->void($order);

    expect($result->ok)->toBeFalse()->and($result->code)->toBe('order_still_live');
    Http::assertNothingSent();
});

it('says there is nothing to release on an order that was never authorised', function () {
    pg1Provider();

    $order = pg1Order(['status' => 'cancelled', 'transaction_id' => 'tam_never']);

    Http::fake();

    $result = app(\App\Services\Payments\PaymentVoider::class)->void($order);

    // A success: the caller asked for the authorisation to be released and
    // there is none, which is the state they wanted.
    expect($result->ok)->toBeTrue()->and($result->code)->toBe('nothing_to_void');
    Http::assertNothingSent();
});

it('offers the void button on exactly the orders void() accepts', function () {
    /*
     * A button that is offered and then refused reads as a bug in the screen
     * rather than as the rule it is — the point PaymentCapturer::status() makes
     * about `capturable`. status() and void() have to agree, and this is the
     * pin that keeps them agreeing.
     */
    pg1Provider();
    Http::fake();

    $voider = app(\App\Services\Payments\PaymentVoider::class);

    $releasable = pg1Order(['status' => 'cancelled', 'transaction_id' => 't1', 'paid_at' => now()]);
    expect($voider->status($releasable)['voidable'])->toBeTrue();
    expect($voider->status($releasable)['why_not'])->toBeNull();

    $live = pg1Order(['status' => 'processing', 'transaction_id' => 't2', 'paid_at' => now()]);
    expect($voider->status($live)['voidable'])->toBeFalse();
    expect(str_contains((string) $voider->status($live)['why_not'], 'Cancel it first'))->toBeTrue();

    $captured = pg1Order([
        'status' => 'cancelled', 'transaction_id' => 't3', 'paid_at' => now(),
        'captured_at' => now(), 'capture_ref' => 'c1',
    ]);
    expect($voider->status($captured)['voidable'])->toBeFalse();

    $cod = pg1Order(['status' => 'cancelled', 'payment_method' => 'cod', 'paid_at' => now()]);
    expect($voider->status($cod)['supported'])->toBeFalse();
    expect($voider->status($cod)['voidable'])->toBeFalse();

    Http::assertNothingSent();
});

/* ══════════════════════════════════════ 3. the webhook nobody registered ═══ */

it('registers the webhook at the exact url the webhook route serves', function () {
    /*
     * THE ASSERTION THAT MAKES THE REGISTRATION WORTH ANYTHING.
     *
     * handleWebhook()'s first gate compares the `{secret}` segment of the
     * incoming URL with the stored `webhook_secret` using hash_equals. If the
     * URL registered at Tamara is not byte-for-byte the one that route serves,
     * every delivery is rejected 401 — and the shop looks correctly configured
     * from both ends. So this pins the registered URL against the secret
     * itself, and against the two events handleWebhook() actually acts on.
     */
    pg1Provider();

    Http::fake(['*/webhooks' => Http::response(['webhook_id' => 'wh_123'])]);

    $result = pg1Gateway()->registerWebhook();

    expect($result['ok'])->toBeTrue()
        ->and($result['created'])->toBeTrue()
        ->and($result['webhook_id'])->toBe('wh_123');

    $body = pg1SentBody('/webhooks');

    expect(str_ends_with((string) $body['url'], '/api/payments/webhook/tamara/' . PG1_URL_SECRET))->toBeTrue();
    expect($body['events'])->toBe(['order_expired', 'order_declined']);

    // Stored, so the registration can be found and removed again.
    expect(app(GatewayCredentials::class)->get('tamara', 'webhook_id'))->toBe('wh_123');
});

it('does not register a second webhook when one is already registered', function () {
    /*
     * Two registrations mean every expiry delivered twice and Tamara offers no
     * "replace" call, so this has to be idempotent at our end.
     */
    pg1Provider(['webhook_id' => 'wh_existing']);

    Http::fake();

    $result = pg1Gateway()->registerWebhook();

    expect($result['ok'])->toBeTrue()
        ->and($result['created'])->toBeFalse()
        ->and($result['webhook_id'])->toBe('wh_existing');

    Http::assertNothingSent();
});

it('refuses to register a webhook before the url secret exists', function () {
    /*
     * Registering the endpoint without its secret hands Tamara a URL that
     * handleWebhook() rejects on its first gate — a webhook that exists,
     * delivers, and is 401ed every time.
     */
    pg1Provider(['webhook_secret' => '']);

    Http::fake();

    $result = pg1Gateway()->registerWebhook();

    expect($result['ok'])->toBeFalse()->and($result['error'])->toBe('no_webhook_secret');
    Http::assertNothingSent();
});

it('removes the webhook registration and forgets its id', function () {
    pg1Provider(['webhook_id' => 'wh_gone']);

    Http::fake(['*/webhooks/*' => Http::response([], 204)]);

    expect(pg1Gateway()->unregisterWebhook()['ok'])->toBeTrue();
    expect(app(GatewayCredentials::class)->get('tamara', 'webhook_id'))->toBe('');
});

it('forgets a webhook id tamara has never heard of', function () {
    /*
     * A 404 means the registration is not there. Leaving the id behind would
     * make registerWebhook() refuse for ever on the strength of a webhook that
     * does not exist — the shop permanently unable to hear about declines, with
     * a settings screen saying it is registered.
     *
     * MUTATION: drop `|| $attempt['status'] === 404` from unregisterWebhook().
     * Red — the id survives and the shop is stuck.
     */
    pg1Provider(['webhook_id' => 'wh_stale']);

    Http::fake(['*/webhooks/*' => Http::response(['error_code' => 'not_found'], 404)]);

    expect(pg1Gateway()->unregisterWebhook()['ok'])->toBeTrue();
    expect(app(GatewayCredentials::class)->get('tamara', 'webhook_id'))->toBe('');
});

it('keeps the webhook id when tamara refuses to delete it', function () {
    pg1Provider(['webhook_id' => 'wh_keep']);

    Http::fake(['*/webhooks/*' => Http::response(['error_code' => 'server_error'], 500)]);

    expect(pg1Gateway()->unregisterWebhook()['ok'])->toBeFalse();
    expect(app(GatewayCredentials::class)->get('tamara', 'webhook_id'))->toBe('wh_keep');
});

/* ═══════════════════════════════════════════════════ 4. the basket limits ══ */

it('offers tamara on every basket size until a limit is set', function () {
    /*
     * RULE 1. Both limits ship EMPTY, so applying this package changes nothing
     * about which baskets are offered Tamara — which is exactly the behaviour
     * availableFor() had before the limits existed. The owner moves them, or
     * presses the button that moves them, and only then does anything change.
     */
    pg1Provider();

    $gateway = pg1Gateway();

    expect($gateway->availableFor(1, 'AE'))->toBeTrue()
        ->and($gateway->availableFor(100_000_00, 'AE'))->toBeTrue();
});

it('withholds tamara from baskets outside the merchant limits', function () {
    /*
     * Tamara refuses a session outside the merchant's agreed range. Until this
     * existed the shopper picked Tamara, was told "Tamara is not available for
     * this order" AFTER pressing Place order, and had to choose again — the
     * plugin checks the basket before drawing the radio button
     * (`isCartTotalValid`).
     *
     * Limits are stored in MAJOR units, as Tamara's portal prints them.
     */
    pg1Provider(['min_limit' => '100', 'max_limit' => '5000']);

    $gateway = pg1Gateway();

    expect($gateway->availableFor(9999, 'AE'))->toBeFalse()      // AED 99.99
        ->and($gateway->availableFor(10000, 'AE'))->toBeTrue()   // AED 100.00 — inclusive
        ->and($gateway->availableFor(500000, 'AE'))->toBeTrue()  // AED 5000.00 — inclusive
        ->and($gateway->availableFor(500001, 'AE'))->toBeFalse();
});

it('reads a limit that is not a number as no limit at all', function () {
    /*
     * A max_limit read off the word "none" as zero would take Tamara off every
     * basket in the shop. `min_limit` of zero is harmless; `max_limit` of zero
     * is a shop that cannot sell.
     */
    pg1Provider(['min_limit' => 'none', 'max_limit' => '']);

    expect(pg1Gateway()->availableFor(25000, 'AE'))->toBeTrue();
});

it('pulls the limits for the payment type this shop actually sells', function () {
    /*
     * The response is a LIST of payment types. Read by `name` and not by
     * position: the order is not documented, and a shop reading element 0 would
     * silently take PAY_NOW's limits the day Tamara reorders them — a range
     * that is right for a product this shop does not sell.
     */
    pg1Provider(['payment_type' => 'PAY_BY_INSTALMENTS']);

    Http::fake([
        '*/checkout/payment-types*' => Http::response([
            ['name' => 'PAY_NOW', 'min_limit' => ['amount' => 1], 'max_limit' => ['amount' => 99]],
            ['name' => 'PAY_BY_LATER', 'min_limit' => ['amount' => 50], 'max_limit' => ['amount' => 2000]],
            ['name' => 'PAY_BY_INSTALMENTS', 'min_limit' => ['amount' => 200], 'max_limit' => ['amount' => 9000]],
        ]),
    ]);

    $limits = pg1Gateway()->refreshLimits('AE', 'AED');

    expect($limits)->not->toBeNull();
    expect($limits['min'])->toBe('200.00')
        ->and($limits['max'])->toBe('9000.00')
        ->and($limits['payment_type'])->toBe('PAY_BY_INSTALMENTS');

    app(GatewayCredentials::class)->forget();

    // And the stored limits are now what availableFor() enforces.
    expect(pg1Gateway()->availableFor(19999, 'AE'))->toBeFalse()
        ->and(pg1Gateway()->availableFor(20000, 'AE'))->toBeTrue();
});

it('leaves the stored limits alone when tamara cannot be read', function () {
    /*
     * A failed refresh that CLEARED the limits would take Tamara off every
     * basket, or put it on all of them, on the strength of a network blip.
     *
     * MUTATION: write the settings before checking $attempt['ok'] in
     * refreshLimits(). Red — the working limits are gone.
     */
    pg1Provider(['min_limit' => '100', 'max_limit' => '5000']);

    Http::fake(['*/checkout/payment-types*' => Http::response([], 500)]);

    expect(pg1Gateway()->refreshLimits('AE', 'AED'))->toBeNull();

    app(GatewayCredentials::class)->forget();

    expect(app(GatewayCredentials::class)->get('tamara', 'min_limit'))->toBe('100')
        ->and(app(GatewayCredentials::class)->get('tamara', 'max_limit'))->toBe('5000');
});

it('does not ask tamara about a market it does not serve', function () {
    /*
     * The country reaches refreshLimits() from a request body. Allowlisted
     * against the gateway's own COUNTRIES before it becomes part of a URL this
     * shop calls, so a posted "country" can never be appended to an outgoing
     * query.
     */
    pg1Provider();

    Http::fake();

    expect(pg1Gateway()->refreshLimits('GB', 'GBP'))->toBeNull();
    Http::assertNothingSent();
});

/* ════════════════════════════════════════ 5. payment type and instalments ══ */

it('asks for pay-by-later when no payment type is configured', function () {
    // Rule 1 again: the shipped default is the value this class always sent.
    pg1Provider();
    pg1FakeCheckout();

    pg1Gateway()->start(pg1Order());

    expect(pg1SentBody('/checkout')['payment_type'])->toBe('PAY_BY_LATER');
    expect(array_key_exists('instalments', pg1SentBody('/checkout')))->toBeFalse();
});

it('asks for the configured payment type', function () {
    pg1Provider(['payment_type' => 'pay_now']);       // lower case, deliberately
    pg1FakeCheckout();

    pg1Gateway()->start(pg1Order());

    expect(pg1SentBody('/checkout')['payment_type'])->toBe('PAY_NOW');
});

it('refuses to send a payment type tamara does not sell', function () {
    /*
     * CLAUDE.md rule 5 — "a select stores one of its own options or the
     * default" — enforced server-side, because the payments screen renders this
     * setting as a free text box (it has no `select` renderer, and this lane may
     * not edit resources/views/admin/app.blade.php). A typo here would 400 every
     * checkout in the shop.
     *
     * MUTATION: return the raw stored value from paymentType() instead of
     * allowlisting it. Red.
     */
    pg1Provider(['payment_type' => 'PAY_LATER_MAYBE']);
    pg1FakeCheckout();

    pg1Gateway()->start(pg1Order());

    expect(pg1SentBody('/checkout')['payment_type'])->toBe('PAY_BY_LATER');
});

it('sends an instalment count only with pay-by-instalments', function () {
    /*
     * Tamara rejects `instalments` against the other three payment types, so an
     * unconditional field would break the default configuration in order to
     * support an optional one.
     */
    pg1Provider(['payment_type' => 'PAY_BY_INSTALMENTS', 'instalments' => '4']);
    pg1FakeCheckout();

    pg1Gateway()->start(pg1Order());

    expect(pg1SentBody('/checkout')['instalments'])->toBe(4);
});

it('ignores an instalment count on a payment type that has none', function () {
    pg1Provider(['payment_type' => 'PAY_BY_LATER', 'instalments' => '4']);
    pg1FakeCheckout();

    pg1Gateway()->start(pg1Order());

    expect(array_key_exists('instalments', pg1SentBody('/checkout')))->toBeFalse();
});

it('ignores an instalment count outside the plans tamara offers', function () {
    pg1Provider(['payment_type' => 'PAY_BY_INSTALMENTS', 'instalments' => '36']);
    pg1FakeCheckout();

    pg1Gateway()->start(pg1Order());

    expect(array_key_exists('instalments', pg1SentBody('/checkout')))->toBeFalse();
});

/* ══════════════════════════════════════════════ 6. locale, phone, country ══ */

it('sends the shoppers own language to tamaras hosted pages', function () {
    /*
     * This was the constant 'en_US', so an Arabic shopper was handed an English
     * Tamara checkout in the middle of an Arabic order — on a shop whose whole
     * second half is Arabic.
     *
     * MUTATION: put 'locale' => 'en_US' back. Red on the Arabic case, green on
     * the English one, which is the pair that shows the default is preserved.
     */
    pg1Provider();
    pg1FakeCheckout();

    app()->setLocale('ar');
    pg1Gateway()->start(pg1Order());
    expect(pg1SentBody('/checkout')['locale'])->toBe('ar_SA');

    app()->setLocale('en');
    expect(pg1Gateway()->id())->toBe('tamara');
});

it('does not open a tamara session for an order with no phone number', function () {
    /*
     * Tamara scores a PERSON and in these markets the mobile number is the
     * identity — it refuses `POST /checkout` without one. This used to travel as
     * an empty string, so the round trip was spent discovering something already
     * knowable and the shopper was told "Tamara is not available for this order.
     * Please choose another payment method." That sentence is true of a basket
     * over the merchant's limit and FALSE here: Tamara is available, one field is
     * missing, and the shopper can fix it in five seconds if anybody says which.
     *
     * MUTATION: delete the phone guard in start(). The call is made, the fake
     * answers 200, and this goes red on both assertions.
     */
    pg1Provider();
    Http::fake();

    $order = pg1Order(['phone' => null]);

    $start = pg1Gateway()->start($order);

    expect($start->ok())->toBeFalse();
    expect(str_contains((string) $start->message, 'mobile number'))->toBeTrue();
    Http::assertNothingSent();
});

it('falls back to the currencys own market when an address has no country', function () {
    /*
     * A SAR order with an incomplete address was being scored in the UAE,
     * because this method returned 'AE' unconditionally when the billing country
     * was absent. The plugin derives the country from the shop currency and
     * nothing else; here that is the fallback and the address is asked first.
     */
    pg1Provider();
    pg1FakeCheckout();

    pg1Gateway()->start(pg1Order(['currency' => 'SAR', 'billing_address' => ['first_name' => 'A']]));

    expect(pg1SentBody('/checkout')['country_code'])->toBe('SA');
});

it('prefers the orders own billing country over the currency', function () {
    pg1Provider();
    pg1FakeCheckout();

    pg1Gateway()->start(pg1Order(['currency' => 'AED', 'billing_address' => ['country' => 'kw']]));

    expect(pg1SentBody('/checkout')['country_code'])->toBe('KW');
});

/* ════════════════════════════════════════════════ 7. the risk assessment ══ */

it('tells tamara about a returning customers real history', function () {
    /*
     * `risk_assessment` is how Tamara is told this is a regular with delivered
     * orders rather than an account created ninety seconds ago. It was absent
     * entirely, so every one of this shop's regulars was scored as a stranger —
     * not a crash, which is why it sat here unnoticed, just a quietly lower
     * approval rate on the customers the shop most wants to keep.
     *
     * MUTATION: drop the `risk_assessment` key from start()'s payload. Red.
     */
    pg1Provider();
    pg1FakeCheckout();

    // Three that counted, one that did not.
    pg1Order(['status' => 'completed', 'total' => 20000, 'subtotal' => 20000], [['qty' => 1, 'unit' => 20000]]);
    pg1Order(['status' => 'shipped', 'total' => 30000, 'subtotal' => 30000], [['qty' => 1, 'unit' => 30000]]);
    pg1Order(['status' => 'processing', 'total' => 10000, 'subtotal' => 10000], [['qty' => 1, 'unit' => 10000]]);
    pg1Order(['status' => 'pending', 'total' => 99999, 'subtotal' => 99999], [['qty' => 1, 'unit' => 99999]]);

    $order = pg1Order();

    pg1Gateway()->start($order);

    $risk = pg1SentBody('/checkout')['risk_assessment'];

    /*
     * Three, not four and not five. `pending` is a basket that reached the
     * payment page — counting those would tell Tamara this buyer has eleven
     * orders when they have one and ten abandoned attempts — and THIS order is
     * excluded because it is not history yet.
     */
    expect($risk['total_order_count'])->toBe(3);
    expect($risk['is_existing_customer'])->toBeTrue();
    expect($risk['has_delivered_order'])->toBeTrue();
    expect($risk['order_count_last3months'])->toBe(3);
    expect($risk['order_amount_last3months']['amount'])->toBe(600.0);   // 200 + 300 + 100
});

it('claims nothing about a buyer it has never seen', function () {
    pg1Provider();
    pg1FakeCheckout();

    $order = pg1Order(['email' => 'stranger-' . uniqid() . '@example.com']);

    pg1Gateway()->start($order);

    $risk = pg1SentBody('/checkout')['risk_assessment'];

    expect($risk['total_order_count'])->toBe(0);
    expect($risk['is_existing_customer'])->toBeFalse();
    expect($risk['has_delivered_order'])->toBeFalse();

    /*
     * A GUEST HAS NO ACCOUNT, so no account fields are sent. Filling
     * `account_creation_date` with the order's own date would tell Tamara every
     * guest is a brand-new account — the sort of plausible-looking lie a risk
     * engine is entitled to act on.
     */
    expect(array_key_exists('account_creation_date', $risk))->toBeFalse();
    expect(array_key_exists('is_email_verified', $risk))->toBeFalse();
    expect(array_key_exists('date_of_first_transaction', $risk))->toBeFalse();
});

it('builds the risk block in one query however long the history is', function () {
    /*
     * RULE 4, measured rather than asserted, and measured as a SLOPE: the same
     * work against one previous order and against twenty-five. Seven facts, one
     * index scan — a version that asked seven questions would show a flat
     * seven, and one that looped the orders would climb.
     */
    pg1Provider();
    pg1FakeCheckout();

    $count = function (int $history): int {
        $email = 'slope-' . uniqid() . '@example.com';

        for ($i = 0; $i < $history; $i++) {
            pg1Order(['email' => $email, 'status' => 'completed']);
        }

        $order = pg1Order(['email' => $email]);

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        pg1Gateway()->start($order);

        return $queries;
    };

    $small = $count(1);
    $large = $count(25);

    // Flat. Not "under some budget" — identical, which is the property that
    // cannot rot into an N+1 without this going red.
    expect($large)->toBe($small);
});

/* ═════════════════════════════════════════════════ 8. the admin endpoints ══ */

it('refuses every tamara admin endpoint to a stranger', function () {
    /*
     * Unauthenticated, POST /admin-api/orders/{id}/void cancels the payment plan
     * behind every cancelled order in the shop, and DELETE .../tamara/webhook
     * silently stops Tamara reporting declines — invisible until somebody audits
     * `pending` orders.
     */
    pg1Provider();
    $order = pg1Order(['status' => 'cancelled', 'paid_at' => now(), 'transaction_id' => 't']);

    $this->postJson('/admin-api/orders/' . $order->id . '/void')->assertUnauthorized();
    $this->getJson('/admin-api/payments/tamara')->assertUnauthorized();
    $this->postJson('/admin-api/payments/tamara/webhook')->assertUnauthorized();
    $this->deleteJson('/admin-api/payments/tamara/webhook')->assertUnauthorized();
    $this->postJson('/admin-api/payments/tamara/limits')->assertUnauthorized();
});

it('maps every tamara admin route to a capability', function () {
    /*
     * An unmapped admin route is owner-only at runtime, so it would not be a
     * hole — but the map is what a reader and this test can see, and the note
     * beside `platform.site_url` in AdminCapabilities explains why leaving a
     * real route to the deny-by-default is the wrong side of that line.
     *
     * Pinned BY NAME rather than by counting, so a rule that stops matching is
     * a red test and not a quietly smaller number.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/orders/7/void'))->toBe('orders.money');
    expect(AdminCapabilities::forPath('GET', 'admin-api/payments/tamara'))->toBe('payments.manage');
    expect(AdminCapabilities::forPath('POST', 'admin-api/payments/tamara/webhook'))->toBe('payments.manage');
    expect(AdminCapabilities::forPath('DELETE', 'admin-api/payments/tamara/webhook'))->toBe('payments.manage');
    expect(AdminCapabilities::forPath('POST', 'admin-api/payments/tamara/limits'))->toBe('payments.manage');

    // And the void route is NOT swallowed by the settlement rules beside it.
    expect(AdminCapabilities::forPath('POST', 'admin-api/orders/7/capture'))->toBe('orders.money');
});

it('never returns a tamara credential from the admin endpoints', function () {
    /*
     * THE CANARY. Every credential is a recognisable string, and no response
     * from this controller may contain any of them — not the API token, not the
     * notification token, and not the webhook secret, which is a credential
     * because it is the whole of handleWebhook()'s first gate. The webhook URL
     * embeds it, which is why that URL is deliberately absent here: the payments
     * screen already shows it with a copy button, and a second copy is a second
     * place it can be cached, proxied or shoulder-read for no gain.
     */
    pg1Provider(['webhook_id' => 'wh_shown', 'min_limit' => '100']);
    $this->actingAs(pg1Admin('leak'), 'admin');

    Http::fake([
        '*/webhooks' => Http::response(['webhook_id' => 'wh_new']),
        '*/checkout/payment-types*' => Http::response([
            ['name' => 'PAY_BY_LATER', 'min_limit' => ['amount' => 10], 'max_limit' => ['amount' => 20]],
        ]),
    ]);

    $bodies = [
        $this->getJson('/admin-api/payments/tamara')->getContent(),
        $this->postJson('/admin-api/payments/tamara/webhook')->getContent(),
        $this->postJson('/admin-api/payments/tamara/limits')->getContent(),
        $this->deleteJson('/admin-api/payments/tamara/webhook')->getContent(),
    ];

    foreach ($bodies as $body) {
        foreach ([PG1_API_TOKEN, PG1_NOTIFY_KEY, PG1_URL_SECRET] as $secret) {
            expect(str_contains((string) $body, $secret))->toBeFalse();
        }
    }

    // It DOES say whether a webhook is registered, which is the whole question.
    $state = $this->getJson('/admin-api/payments/tamara')->json();
    expect($state['webhook_registered'])->toBeTrue();
});

it('releases the authorisation through the order endpoint', function () {
    pg1Provider();
    $this->actingAs(pg1Admin('void'), 'admin');

    $order = pg1Order([
        'status' => 'cancelled',
        'transaction_id' => 'tam_ep',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_ep',
            'order_reference_id' => $order->order_number,
            'status' => 'authorised',
        ]),
        '*/cancel' => Http::response(['cancel_id' => 'cancel_ep']),
    ]);

    $this->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('code', 'voided')
        ->assertJsonPath('void.voided', true);

    expect($order->fresh()->void_ref)->toBe('cancel_ep');
});

it('answers 422 rather than 502 when the shop itself refused the release', function () {
    /*
     * 502 tells the screen a retry may work. On a refusal this shop made — the
     * order is still live, the money is already captured — it will say the same
     * thing for ever, and offering a retry would be a lie.
     */
    pg1Provider();
    $this->actingAs(pg1Admin('422'), 'admin');

    $order = pg1Order(['status' => 'processing', 'transaction_id' => 't', 'paid_at' => now()]);

    Http::fake();

    $this->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertStatus(422)
        ->assertJsonPath('code', 'order_still_live');
});

it('carries the void state on the settlement endpoint the order screen reads', function () {
    /*
     * Additive: nothing that reads this response today looks for `void`, and
     * the nested key keeps the capture vocabulary and the release vocabulary
     * from growing into each other.
     */
    pg1Provider();
    $this->actingAs(pg1Admin('state'), 'admin');

    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/payments-settlement.php'));

    $order = pg1Order(['status' => 'cancelled', 'transaction_id' => 't', 'paid_at' => now()]);

    Http::fake();

    $this->getJson('/admin-api/orders/' . $order->id . '/settlement')
        ->assertOk()
        ->assertJsonPath('void.supported', true)
        ->assertJsonPath('void.voidable', true);

    // Reading the order screen makes NO call to Tamara. A BNPL API having a
    // slow morning must not be the reason an admin cannot look at an order.
    Http::assertNothingSent();
});

/* ═════════════════════════════════════════════════════ 9. the DELETE verb ══ */

it('sends a DELETE and not a POST when removing a webhook', function () {
    /*
     * RemoteGateway's verb match has a `default => post`, so before DELETE was
     * listed this call POSTed to /webhooks/{id} — which on Tamara's API is not
     * "remove this webhook", it is an unknown route. The registration would have
     * survived and the shop would have reported it removed.
     *
     * MUTATION: delete the 'DELETE' arm from RemoteGateway::attempt(). Red on
     * the method assertion.
     */
    pg1Provider(['webhook_id' => 'wh_verb']);

    Http::fake(['*/webhooks/*' => Http::response([], 204)]);

    pg1Gateway()->unregisterWebhook();

    $methods = collect(Http::recorded())
        ->filter(fn ($p) => str_contains($p[0]->url(), '/webhooks/'))
        ->map(fn ($p) => $p[0]->method())
        ->all();

    expect($methods)->toBe(['DELETE']);
});

/* ══════════ 12. the approval nobody told this shop about ══════════════════ */

/*
 * THE HOLE: every Tamara approval reached this shop as a callback and nothing
 * else. A delivery that went missing — this host restarting mid-POST, a blip at
 * the egress, Tamara's retry budget running out, a webhook secret rotated
 * between session creation and approval — left the order `pending` FOR EVER.
 * It held its stock claim and its coupon use, no capture was possible, and at
 * Tamara's end the buyer was approved, had a payment plan and believed they had
 * bought something. The shop never shipped and never got paid, and the only
 * evidence was an absence.
 *
 * The merchant's plugin does not trust the callback either — `forceAuthorise-
 * TamaraOrder` sweeps on cron. App\Services\Payments\TamaraSweep is that sweep.
 */

/** GET /merchants/orders/{id} answering with one status. */
function pg1FakeRemote(string $status, array $extra = [], int $totalFils = 10000): void
{
    Http::fake([
        '*/merchants/orders/reference-id/*' => Http::response(['order_id' => 'tam_recovered']),
        '*/merchants/orders/*' => Http::response(array_merge([
            'order_id' => 'tam_pg1',
            'order_reference_id' => $extra['__ref'] ?? null,
            'status' => $status,
            'total_amount' => ['amount' => number_format($totalFils / 100, 2, '.', ''), 'currency' => 'AED'],
        ], array_diff_key($extra, ['__ref' => null]))),
        '*/authorise' => Http::response(['order_id' => 'tam_pg1', 'status' => 'authorised']),
    ]);
}

it('marks an order paid when tamara approved it and the notification never came', function () {
    pg1Provider();
    $order = pg1Order(['created_at' => now()->subHours(3)]);

    pg1FakeRemote('approved', ['__ref' => $order->order_number]);

    $report = app(\App\Services\Payments\TamaraSweep::class)->run(by: 'scheduled check');

    expect($report['ran'])->toBeTrue()
        ->and($report['examined'])->toBe(1)
        ->and($report['paid'])->toBe(1)
        ->and($order->fresh()->paid_at)->not->toBeNull();

    // And it AUTHORISED before confirming. An order marked paid here but never
    // authorised at Tamara is money the shop never receives, which is the whole
    // reason this is not just a status write.
    $authorised = false;

    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), '/authorise')) {
            $authorised = true;
        }
    }

    expect($authorised)->toBeTrue();

    /*
     * MUTATION: delete TamaraSweep and this is the state the shop was in — the
     * order stays `pending` with paid_at NULL for ever. Narrower: change
     * TamaraGateway::settleFromRemote()'s `if ($status === 'approved')` to
     * `if (false)` and $authorised is false while the order is still marked
     * paid, which is the exact shape of "shipped and never paid".
     */
});

it('does not touch an order tamara is still waiting on the shopper for', function () {
    pg1Provider();
    $order = pg1Order(['created_at' => now()->subHours(3)]);

    pg1FakeRemote('new', ['__ref' => $order->order_number]);

    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['examined'])->toBe(1)
        ->and($report['untouched'])->toBe(1)
        ->and($report['paid'])->toBe(0)
        ->and($order->fresh()->paid_at)->toBeNull()
        ->and((string) $order->fresh()->status)->toBe('pending');

    /*
     * A Tamara order sits `new` from session creation until the shopper finishes
     * the hosted flow. Treating that as a failure would cancel a checkout in
     * progress — the one mistake in this sweep that costs a sale that was going
     * to happen.
     *
     * MUTATION: move 'new' into settleFromRemote()'s terminal list beside
     * 'expired' and this order comes back cancelled.
     */
});

it('closes an order tamara declined while nobody was listening', function () {
    pg1Provider();
    $order = pg1Order(['created_at' => now()->subHours(3)]);

    pg1FakeRemote('declined', ['__ref' => $order->order_number]);

    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['examined'])->toBe(1)
        ->and($report['failed'])->toBe(1)
        ->and($order->fresh()->paid_at)->toBeNull()
        ->and((string) $order->fresh()->status)->not->toBe('pending');

    // This is the decline branch the unregistered webhook made unreachable,
    // reached a second way — so a shop whose webhook registration was never
    // pressed still releases the stock and the coupon use eventually.
});

it('recovers a tamara order id this shop never managed to save', function () {
    pg1Provider();

    // start() writes `transaction_id` AFTER POST /checkout returns. An order can
    // exist at Tamara while this shop holds no id for it: the response was lost,
    // or the process died between the call and the save. No callback can rescue
    // those either, because handleWebhook() needs an id to verify against.
    $order = pg1Order(['created_at' => now()->subHours(3), 'transaction_id' => null]);

    Http::fake([
        '*/merchants/orders/reference-id/*' => Http::response(['order_id' => 'tam_recovered']),
        '*/merchants/orders/tam_recovered' => Http::response([
            'order_id' => 'tam_recovered',
            'order_reference_id' => $order->order_number,
            'status' => 'approved',
            'total_amount' => ['amount' => '100.00', 'currency' => 'AED'],
        ]),
        '*/authorise' => Http::response(['status' => 'authorised']),
    ]);

    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['paid'])->toBe(1)
        ->and((string) $order->fresh()->transaction_id)->toBe('tam_recovered')
        ->and($order->fresh()->paid_at)->not->toBeNull();

    /*
     * MUTATION: remove the reference-id lookup from reconcileAuthorisation() and
     * this order can never be settled by any path at all.
     */
});

it('refuses to staple a tamara order onto the wrong shop order', function () {
    pg1Provider();
    $order = pg1Order(['created_at' => now()->subHours(3), 'transaction_id' => null]);

    // The lookup answers, but the order it points at belongs to somebody else's
    // reference. Saving the id on the strength of an unverified lookup would
    // attach a stranger's payment plan to this order PERMANENTLY.
    Http::fake([
        '*/merchants/orders/reference-id/*' => Http::response(['order_id' => 'tam_someone_else']),
        '*/merchants/orders/tam_someone_else' => Http::response([
            'order_id' => 'tam_someone_else',
            'order_reference_id' => 'SOMEBODY-ELSES-ORDER',
            'status' => 'approved',
            'total_amount' => ['amount' => '100.00', 'currency' => 'AED'],
        ]),
    ]);

    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['errors'])->toBe(1)
        ->and($report['paid'])->toBe(0)
        ->and($order->fresh()->transaction_id)->toBeNull()
        ->and($order->fresh()->paid_at)->toBeNull();

    // And nothing was authorised.
    foreach (Http::recorded() as [$request, $response]) {
        expect(str_contains($request->url(), '/authorise'))->toBeFalse();
    }

    /*
     * MUTATION: move the `transaction_id` write in reconcileAuthorisation()
     * above its reference check — the id sticks to this order and every later
     * capture and refund on it points at a stranger's plan.
     */
});

it('leaves an order alone that has already moved on', function () {
    pg1Provider();

    // A background job that reopened a decision somebody made is the worst thing
    // this file could do. `processing`, `shipped`, `cancelled` and `refunded` are
    // all decided; the sweep never fetches them.
    foreach (['processing', 'shipped', 'completed', 'cancelled', 'refunded'] as $status) {
        pg1Order(['created_at' => now()->subHours(3), 'status' => $status]);
    }

    // preventStrayRequests() is what makes this a real assertion: no fake is
    // registered at all, so ANY call to Tamara fails the test outright.
    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['examined'])->toBe(0);

    /*
     * MUTATION: drop `->where('status', 'pending')` from
     * TamaraSweep::candidates() and this throws on a stray request.
     */
});

it('leaves a shopper who is still on tamaras page alone', function () {
    pg1Provider();
    pg1Order(['created_at' => now()->subMinutes(2)]);

    // No fake registered: an order ninety seconds old is a shopper currently
    // looking at Tamara's hosted page, and asking about an approval that has not
    // happened yet is a round trip for nothing on every run.
    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['examined'])->toBe(0);

    // With the lower bound lifted the same order IS a candidate, which proves
    // the bound is what excluded it rather than something else about the row.
    expect(app(\App\Services\Payments\TamaraSweep::class)->candidates(minutes: 0)->count())->toBe(1);
});

it('sweeps the oldest orders first and never more than its limit', function () {
    pg1Provider();

    foreach (range(1, 6) as $days) {
        pg1Order(['order_number' => 'PG1-AGE-' . $days, 'created_at' => now()->subDays($days)]);
    }

    $numbers = app(\App\Services\Payments\TamaraSweep::class)
        ->candidates(limit: 3)
        ->pluck('order_number')
        ->all();

    // Oldest first: a capped run always makes progress on the orders closest to
    // being expired by Tamara, which are the ones where waiting costs money.
    expect($numbers)->toBe(['PG1-AGE-6', 'PG1-AGE-5', 'PG1-AGE-4']);

    /*
     * And the upper bound really excludes. Six orders, one per day from 1 to 6
     * days old; asked for 3 days, the ones at 1, 2 and 3 days are inside it and
     * the ones at 4, 5 and 6 are not.
     */
    expect(app(\App\Services\Payments\TamaraSweep::class)->candidates(days: 3)->count())->toBe(3)
        ->and(app(\App\Services\Payments\TamaraSweep::class)->candidates(days: 3)->pluck('order_number')->all())
        ->toBe(['PG1-AGE-3', 'PG1-AGE-2', 'PG1-AGE-1']);
});

it('finds the candidates in one query however many there are', function () {
    pg1Provider();

    $count = function (int $orders): int {
        Order::query()->forceDelete();

        foreach (range(1, $orders) as $i) {
            pg1Order(['order_number' => 'PG1-SLOPE-' . $orders . '-' . $i, 'created_at' => now()->subHours(3)]);
        }

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        app(\App\Services\Payments\TamaraSweep::class)->candidates();

        return $queries;
    };

    /*
     * THE SLOPE, AT 1 / 2 / 5 / 10 — not a total, per rule 4. The candidate scan
     * is one select whatever the size of the backlog, so the count does not move.
     * A per-order lookup here would read 1 / 2 / 5 / 10 and be the N+1 this
     * method was most likely to have had.
     */
    $at = [1 => $count(1), 2 => $count(2), 5 => $count(5), 10 => $count(10)];

    expect($at[1])->toBe(1)
        ->and($at[2])->toBe($at[1])
        ->and($at[5])->toBe($at[1])
        ->and($at[10])->toBe($at[1]);
});

it('does not sweep at all when tamara is not configured', function () {
    // No provider row. A shop that does not use Tamara has no stale Tamara
    // orders, and a sweep that reported FAILURE here would cry wolf on every
    // cron tick for ever.
    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['ran'])->toBeFalse()
        ->and($report['examined'])->toBe(0)
        ->and((string) $report['reason'])->toContain('nothing to sweep');
});

it('reports a sweep the owner asked for without naming an order', function () {
    pg1Provider();
    $order = pg1Order(['created_at' => now()->subHours(3)]);

    pg1FakeRemote('approved', ['__ref' => $order->order_number]);

    $response = $this->actingAs(pg1Admin('sweep'), 'admin')
        ->postJson('/admin-api/payments/tamara/sweep');

    $response->assertOk();

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('report.paid'))->toBe(1)
        ->and($order->fresh()->paid_at)->not->toBeNull();

    /*
     * NO ORDER CROSSES THE REQUEST, which is the point: "settle this order"
     * posted by a caller is "mark this order paid". The endpoint takes three
     * numbers and the service picks the orders itself.
     */
    $body = $response->json();

    expect(json_encode($body))->not->toContain(PG1_API_TOKEN)
        ->and(json_encode($body))->not->toContain(PG1_NOTIFY_KEY)
        ->and(json_encode($body))->not->toContain(PG1_URL_SECRET);
});

it('refuses the sweep endpoint to a stranger', function () {
    pg1Provider();

    // Mounted inside the admin-api group, so an unauthenticated POST never
    // reaches the controller. Unguarded this is a way for anybody to spend the
    // shop's Tamara rate limit and write to orders.
    $this->postJson('/admin-api/payments/tamara/sweep')->assertStatus(401);
});

it('clamps what the sweep endpoint will accept', function () {
    pg1Provider();

    // An unbounded `days` is a full table scan on demand and an unbounded
    // `limit` is a thousand round trips to a rate-limited API on one click.
    $this->actingAs(pg1Admin('clamp'), 'admin')
        ->postJson('/admin-api/payments/tamara/sweep', ['days' => 5000, 'limit' => 100000])
        ->assertStatus(422);
});

/* ══════════ 13. the failure branches, driven rather than assumed ══════════ */

it('treats a tamara timeout as reachable-again rather than as a decline', function () {
    pg1Provider();
    $order = pg1Order(['created_at' => now()->subHours(3)]);

    // A connection that never answers. RemoteGateway::call() catches and returns
    // null; nothing may conclude anything about the money from that.
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['errors'])->toBe(1)
        ->and($report['failed'])->toBe(0)
        ->and($order->fresh()->paid_at)->toBeNull()
        ->and((string) $order->fresh()->status)->toBe('pending');

    /*
     * THE BUG THIS FORBIDS: a timeout read as "Tamara says no" would cancel a
     * pending order the buyer HAD been approved for, release its stock and its
     * coupon, and leave a live payment plan behind — on nothing but a dropped
     * packet. The order must come out of this exactly as it went in.
     */
});

it('treats a malformed tamara body as unreadable and changes nothing', function () {
    pg1Provider();
    $order = pg1Order(['created_at' => now()->subHours(3)]);

    // 200 OK with HTML in it: a proxy error page, a WAF block, a maintenance
    // splash. Every one of these has been served by a payment API at some point.
    Http::fake(['*' => Http::response('<html>upstream is having a moment</html>', 200)]);

    $report = app(\App\Services\Payments\TamaraSweep::class)->run();

    expect($report['paid'])->toBe(0)
        ->and($report['failed'])->toBe(0)
        ->and($order->fresh()->paid_at)->toBeNull()
        ->and((string) $order->fresh()->status)->toBe('pending');

    // A body with no `status` at all must not read as any particular status —
    // least of all as one that moves money.
});

it('refuses to capture a tamara order whose body carries no capture id', function () {
    pg1Provider();
    $order = pg1Order([
        'transaction_id' => 'tam_pg1',
        'status' => 'processing',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_reference_id' => $order->order_number,
            'status' => 'authorised',
        ]),
        // 200, and no capture_id. The id is the handle a later REFUND has to
        // point at, so a capture without one is a capture that can never be
        // reversed.
        '*/payments/capture' => Http::response(['message' => 'ok']),
    ]);

    $result = pg1Gateway()->capture($order, (int) $order->total);

    expect($result->ok)->toBeFalse()
        ->and($result->code)->not->toBe('captured');

    /*
     * MUTATION: drop `|| $captureId === null` from capture()'s failure test and
     * this returns ok() with a null reference — PaymentCapturer then writes
     * `captured_at` with no `capture_ref`, and every refund on that order
     * afterwards answers `not_captured` for ever.
     */
});

it('treats a partial capture made outside this shop as already captured', function () {
    pg1Provider();
    $order = pg1Order(['transaction_id' => 'tam_pg1', 'status' => 'processing', 'paid_at' => now()]);

    // This build captures the whole order in one call and never makes a partial
    // one, so a partial capture on the account came from Tamara's dashboard.
    // Silently topping it up is the wrong guess to make with money.
    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_reference_id' => $order->order_number,
            'status' => 'partially_captured',
            'transactions' => ['captures' => [['capture_id' => 'cap_partial']]],
        ]),
    ]);

    $result = pg1Gateway()->capture($order, (int) $order->total);

    expect($result->ok)->toBeTrue()
        ->and($result->code)->toBe('already_captured')
        ->and($result->reference)->toBe('cap_partial');

    // And no capture call was made.
    foreach (Http::recorded() as [$request, $response]) {
        expect(str_contains($request->url(), '/payments/capture'))->toBeFalse();
    }
});

it('refunds part of a capture against the capture it reverses', function () {
    pg1Provider();
    $order = pg1Order([
        'transaction_id' => 'tam_pg1',
        'capture_ref' => 'cap_pg1',
        'status' => 'processing',
        'paid_at' => now(),
        'captured_at' => now(),
    ]);

    Http::fake([
        '*/payments/refund' => Http::response(['refunds' => [['refund_id' => 'ref_pg1']]]),
    ]);

    // Half of a 100.00 order.
    $result = pg1Gateway()->refund($order, 5000, 'Damaged in transit', 'cap_pg1', 'idem-pg1');

    expect($result->ok)->toBeTrue()
        ->and($result->reference)->toBe('ref_pg1');

    $body = pg1SentBody('/payments/refund');

    // ONE refund per call, pointed at the capture it reverses. Tamara batches
    // them; we send one so that one refund row maps to one provider
    // transaction, because a partial failure inside a batch cannot be
    // attributed to the right row in our own table.
    expect(count($body['refunds'] ?? []))->toBe(1)
        ->and($body['refunds'][0]['capture_id'] ?? null)->toBe('cap_pg1')
        ->and($body['refunds'][0]['total_amount']['amount'] ?? null)->toBe(50.0)
        ->and($body['refunds'][0]['total_amount']['currency'] ?? null)->toBe('AED');

    // The amount is the one asked for and NOT the order total: a partial refund
    // that sent the whole total would hand back twice what was agreed.
    expect($body['refunds'][0]['total_amount']['amount'] ?? null)->not->toBe(100.0);
});

it('refuses a refund before there is a capture to reverse', function () {
    pg1Provider();
    $order = pg1Order(['transaction_id' => 'tam_pg1', 'status' => 'processing', 'paid_at' => now()]);

    // No fake: this must not reach Tamara at all. Its refund endpoint requires a
    // capture_id, so the round trip is wasted and the generic error it produces
    // does not tell the operator that CAPTURE is the next action.
    $result = pg1Gateway()->refund($order, 5000, null, null, null);

    expect($result->ok)->toBeFalse()
        ->and($result->code)->toBe('not_captured');
});

/* ══════════ 14. baskets the merchant's contract does not cover ════════════ */

/*
 * Tamara agrees per merchant what may be bought on credit. The plugin honours
 * that with `excluded_products` and `excluded_product_categories` and takes
 * Tamara off the checkout for a basket containing one. This shop had no such
 * control at all, so a basket the contract excludes went through Tamara exactly
 * like any other — nothing crashes, the shop is simply selling on terms it has
 * not agreed.
 */

it('opens a tamara session for a basket with nothing excluded', function () {
    pg1Provider();
    $order = pg1Order();

    pg1FakeCheckout();

    // The shipped default: both boxes empty, so nothing is excluded and this is
    // byte-identical to the behaviour before the setting existed (rule 1).
    expect(pg1Gateway()->start($order)->redirectUrl)->not->toBeNull();
});

it('will not put an excluded product on a tamara plan', function () {
    pg1Provider(['excluded_products' => '  SKU-0 , 4242 ']);
    $order = pg1Order();

    // No fake registered. preventStrayRequests() makes "this never reaches
    // Tamara" a real assertion: the basket is refused before the call.
    $start = pg1Gateway()->start($order);

    expect($start->redirectUrl)->toBeNull()
        ->and((string) $start->message)->toContain('one of the items');

    /*
     * MUTATION: remove the basketAllowed() guard from start() and this throws on
     * a stray request to /checkout — which is the shop putting an excluded item
     * on credit.
     */
});

it('matches an excluded product by its id as well as its sku', function () {
    $order = pg1Order();

    // A real row: order_items.product_id is a foreign key, so an invented id
    // cannot be inserted -- which is itself worth knowing, because it means the
    // id half of the exclusion list only ever meets ids the catalogue has.
    $productId = \App\Models\Product::create([
        'name' => 'Gift card',
        'slug' => 'pg1-gift-card-' . uniqid(),
        'price' => 0,
        'status' => 'publish',
    ])->id;

    $order->items()->create([
        'name' => 'Gift card',
        'sku' => 'GC-1',
        'product_id' => $productId,
        'quantity' => 1,
        'unit_price' => 0,
        'subtotal' => 0,
        'total' => 0,
    ]);

    // The plugin only takes ids, which is a WooCommerce habit. This shop's own
    // catalogue screens show SKUs, so an owner told "paste product ids" will
    // paste SKUs about half the time; both are accepted.
    pg1Provider(['excluded_products' => (string) $productId]);

    expect(pg1Gateway()->start($order->fresh())->redirectUrl)->toBeNull();
});

it('will not put a product from an excluded category on a tamara plan', function () {
    $product = \App\Models\Product::create([
        'name' => 'Excluded thing',
        'slug' => 'pg1-excluded-thing-' . uniqid(),
        'price' => 5000,
        'status' => 'publish',
    ]);

    $category = \App\Models\Category::create([
        'name' => 'No credit',
        'slug' => 'pg1-no-credit-' . uniqid(),
    ]);

    \Illuminate\Support\Facades\DB::table('category_product')->insert([
        'product_id' => $product->id,
        'category_id' => $category->id,
    ]);

    $order = pg1Order();

    $order->items()->create([
        'name' => $product->name,
        'sku' => 'PG1-CAT-SKU',
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    pg1Provider(['excluded_categories' => (string) $category->id]);

    expect(pg1Gateway()->start($order->fresh())->redirectUrl)->toBeNull();

    // And the same basket is fine once the category is not excluded, which proves
    // the category list is what refused it rather than something else about the
    // row.
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    pg1Provider();
    pg1FakeCheckout();

    expect(pg1Gateway()->start($order->fresh())->redirectUrl)->not->toBeNull();
});

it('asks the category pivot once however many lines the order has', function () {
    $category = \App\Models\Category::create([
        'name' => 'Counted',
        'slug' => 'pg1-counted-' . uniqid(),
    ]);

    pg1Provider(['excluded_categories' => (string) $category->id]);

    $count = function (int $lines): int {
        $order = pg1Order(
            ['order_number' => 'PG1-CATSLOPE-' . $lines . '-' . uniqid()],
            array_fill(0, $lines, ['qty' => 1, 'unit' => 1000]),
        );

        foreach ($order->items as $i => $item) {
            $product = \App\Models\Product::create([
                'name' => 'P' . $i,
                'slug' => 'pg1-p-' . uniqid(),
                'price' => 1000,
                'status' => 'publish',
            ]);

            $item->forceFill(['product_id' => $product->id])->save();
        }

        $order = $order->fresh();
        $order->load('items');

        $pivot = 0;

        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$pivot) {
            if (str_contains($q->sql, 'category_product')) {
                $pivot++;
            }
        });

        pg1FakeCheckout();
        pg1Gateway()->start($order);

        return $pivot;
    };

    /*
     * THE SLOPE, AT 1 / 2 / 5 / 10 LINES — rule 4 asks for the slope and not a
     * total. One `whereIn` against the pivot whatever the basket size. Asking
     * each product for its categories through the relation would read 1 / 2 / 5 /
     * 10 here, which is the N+1 this method was most likely to have had and the
     * reason it does not use $product->categories at all.
     */
    $at = [1 => $count(1), 2 => $count(2), 5 => $count(5), 10 => $count(10)];

    expect($at[1])->toBe(1)
        ->and($at[2])->toBe(1)
        ->and($at[5])->toBe(1)
        ->and($at[10])->toBe(1);
});

it('does not touch the pivot at all when nothing is excluded', function () {
    pg1Provider();

    $order = pg1Order();

    /*
     * REAL product ids on the lines, and that is the whole point of this case.
     *
     * Written first against the plain fixture — whose items carry no product_id
     * — it passed with BOTH empty-list guards deleted from basketAllowed(),
     * because an order with no product ids can never reach the pivot however the
     * guards are written. It was green and it asserted nothing.
     *
     * With ids present, the only thing standing between this order and a pivot
     * query is the "nothing is excluded" guard, which is exactly the claim.
     */
    foreach ($order->items as $i => $item) {
        $product = \App\Models\Product::create([
            'name' => 'Ordinary ' . $i,
            'slug' => 'pg1-ordinary-' . uniqid(),
            'price' => 5000,
            'status' => 'publish',
        ]);

        $item->forceFill(['product_id' => $product->id])->save();
    }

    $order = $order->fresh();
    $order->load('items');

    expect($order->items->pluck('product_id')->filter()->count())->toBeGreaterThan(0);

    $pivot = 0;

    \Illuminate\Support\Facades\DB::listen(function ($q) use (&$pivot) {
        if (str_contains($q->sql, 'category_product')) {
            $pivot++;
        }
    });

    pg1FakeCheckout();
    pg1Gateway()->start($order);

    /*
     * The shipped shop — both boxes empty — pays NOTHING for this feature. Not
     * one query. basketAllowed() returns on the two settings it has already read
     * before it looks at a line.
     */
    expect($pivot)->toBe(0);
});

it('does not promise a widget this shop does not draw', function () {
    // `public_key` is stored and read by no code path here. Its help text used to
    // say "for the product-page widget only", which describes a feature that does
    // not exist — the owner fills it in and believes something happened.
    $help = pg1Gateway()->configSchema()['public_key'][2] ?? '';

    expect(str_contains(strtolower($help), 'not used yet'))->toBeTrue();

    // And nothing in the gateway reads it, which is what makes the help text
    // true rather than merely humble.
    $source = file_get_contents(app_path('Services/Payments/Gateways/TamaraGateway.php'));

    expect(str_contains($source, "'public_key')"))->toBeFalse();
});

/* ══════════ 15. the replayed notification that used to 503 for ever ═══════ */

it('answers a replayed tamara notification 200 once the order is authorised', function () {
    pg1Provider();
    $order = pg1Order(['total' => 10000, 'subtotal' => 10000]);

    /*
     * THE BUG, which was in the shipped code and is the reason this case exists.
     *
     * handleWebhook() decided whether a FAILED `POST /orders/{id}/authorise` was
     * fatal by reading `order_status` out of the DELIVERED BODY. Tamara resends a
     * notification it did not get a 200 for, and the body it resends still says
     * `approved` — so on the replay:
     *
     *   body says approved  ->  authorise the order again
     *   Tamara answers 409 (it is already authorised)
     *   RemoteGateway::call() collapses every non-2xx to null
     *   `$authorised === null && ! in_array('approved', [authorised, ...])` -> TRUE
     *   WebhookOutcome::failed('could not authorise with Tamara')  ->  503
     *
     * A 503 asks the sender to try again. Tamara would retry a notification for
     * an order that WAS paid, get another 503, and keep going until its retry
     * budget ran out — filling the provider's delivery log with failures against
     * a perfectly good order, and hiding any real failure in the noise.
     *
     * Reading the status off the AUTHENTICATED fetch instead fixes it for free:
     * Tamara reports the order as `authorised` on the second look, so the
     * authorise call is not made at all and PaymentConfirmer answers "already
     * applied".
     */
    $remoteStatus = 'approved';

    Http::fake([
        // A CALLBACK, not Http::response(fn () => ...): the closure form of
        // response() treats its argument as the body, so the array arrives as a
        // stream write and Guzzle throws "Array to string conversion". A callback
        // as the fake's VALUE is re-evaluated per request, which is what lets
        // Tamara's answer change between the two deliveries.
        '*/merchants/orders/*' => function () use ($order, &$remoteStatus) {
            return Http::response([
                'order_id' => 'tam_replay',
                'order_reference_id' => $order->order_number,
                'status' => $remoteStatus,
                'total_amount' => ['amount' => 100.00, 'currency' => 'AED'],
            ]);
        },
        // Exactly what Tamara does on a second authorise: 409 Conflict.
        '*/authorise' => Http::sequence()
            ->push(['order_id' => 'tam_replay', 'status' => 'authorised'], 200)
            ->push(['message' => 'order is already authorised'], 409),
    ]);

    /*
     * A genuinely signed delivery, built here rather than borrowed from
     * TamaraWebhookTest: Pest's helpers are global across files, so calling that
     * file's `tamaraRequest()` would make this case depend on a fixture another
     * file is free to change, and it would pass or fail for reasons invisible
     * from here.
     */
    $deliver = function () use ($order) {
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $head = $b64((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $claims = $b64((string) json_encode(['sub' => 'notification', 'iat' => time()]));
        $token = $head . '.' . $claims . '.'
            . $b64(hash_hmac('sha256', $head . '.' . $claims, PG1_NOTIFY_KEY, true));

        $request = \Illuminate\Http\Request::create(
            '/api/payments/webhook/tamara/' . PG1_URL_SECRET,
            'POST', [], [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            (string) json_encode([
                'order_id' => 'tam_replay',
                'order_reference_id' => $order->order_number,
                'order_status' => 'approved',
            ]),
        );

        $request->headers->set('Content-Type', 'application/json');

        $route = new \Illuminate\Routing\Route(
            ['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null,
        );
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return pg1Gateway()->handleWebhook($request);
    };

    $first = $deliver();

    expect($first->accepted)->toBeTrue()
        ->and($first->status)->toBe(200)
        ->and($order->fresh()->paid_at)->not->toBeNull();

    // Tamara now reports the order as authorised, which is what the real API
    // does the moment the first call succeeded.
    $remoteStatus = 'authorised';

    $second = $deliver();

    expect($second->accepted)->toBeTrue()
        ->and($second->status)->toBe(200)
        ->and($second->status)->not->toBe(503);

    /*
     * MUTATION: put the old line back —
     *
     *   $authorised = $this->call('POST', '/orders/'.urlencode($id).'/authorise');
     *   if ($authorised === null && ! in_array($statusFromBody, [...], true)) {
     *       return WebhookOutcome::failed('could not authorise with Tamara');
     *   }
     *
     * unconditionally rather than only from `approved`, and the second delivery
     * comes back 503.
     */
});
