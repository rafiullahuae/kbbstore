<?php

/**
 * A partial capture must not inflate the refund ceiling.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ─────────────────────────────────────────────────────────────
 *
 * PaymentCapturer::capture() wrote `captured_total` = the amount it ASKED for
 * on every success:
 *
 *     'captured_total' => $amountFils,        // $amountFils = (int) $order->total
 *
 * That is right for the successes this shop causes — it asks for the whole
 * order total and the provider either takes it or refuses — and wrong for the
 * successes it merely FINDS. Three of the four gateways answer
 * `already_captured` on a captured state the provider reached without us, and
 * on all three that state can be a PARTIAL capture:
 *
 *   Tamara   `partially_captured`, which TamaraGateway::capture() deliberately
 *            does not top up — "silently topping it up would be the wrong guess
 *            to make with money" — and which was then recorded at full value
 *            anyway, which is a worse guess by the same measure.
 *   Tabby    CLOSED with a short `captures[]`. Tabby's capture endpoint takes an
 *            amount, so a capture made in the merchant portal or by the
 *            WooCommerce plugin this store is migrating off can be a partial
 *            one; capture() read the last capture's id and never its amount.
 *   Stripe   a `succeeded` intent whose `amount_received` is below its
 *            `amount`, which is what a partially captured intent reports.
 *
 * `captured_total` is the ceiling PaymentRefunder::capturedFils() measures a
 * refund against. An order captured at 120.00 out of 300.00 recorded 300.00 and
 * accepted a 300.00 refund: **180.00 of the shop's own money returned to a
 * buyer who never paid it.** On the shop that is a Payment panel reading
 * "Captured · AED 300.00" over a provider dashboard reading 120.00, with
 * "AED 300.00 still refundable" underneath it.
 *
 * ── THE FIX THESE CASES PIN ────────────────────────────────────────────────
 *
 * SettlementResult carries an optional `capturedFils` — integer fils, null
 * meaning "the provider named no figure, use what was requested". Each of the
 * three gateways fills it from the SAME response body its existing parser
 * reads, and PaymentCapturer::settledFils() decides:
 *
 *   null / <= 0   the amount requested, which is what COD and every ordinary
 *                 full capture have always written.
 *   otherwise     the provider's figure, capped at the amount requested.
 *
 * So this change can only ever LOWER a `captured_total`, never raise one. The
 * second block below is that claim, driven rather than asserted.
 *
 * ── MONEY ──────────────────────────────────────────────────────────────────
 *
 * Integer fils everywhere, including in the fixtures. pccMajor() builds the
 * major-unit decimal string the two Buy-Now-Pay-Later APIs answer in out of
 * intdiv() and %, so not one float is created anywhere in this file — see its
 * note, and RemoteGateway::toFils() for why one would matter.
 *
 * MUTATION NOTES are on every case. Each names the exact edit that turns it
 * red, and each was run.
 *
 * Http::preventStrayRequests() is on throughout, so "no second call was made"
 * is a real assertion rather than a hopeful one.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\PaymentRefunder;
use App\Services\Payments\SettlementResult;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();
});

/* ───────────────────────────────────────────────────────────────── fixtures */

/** 300.00 AED. Every order in this file. */
const PCC_TOTAL = 30000;

/** 120.00 AED — what the provider actually took. */
const PCC_PART = 12000;

/**
 * Integer fils to the major-unit decimal string Tabby and Tamara answer in.
 *
 * intdiv() and %, NOT $fils / 100. A fixture built by dividing is a float in
 * the test even when the code under test is careful, and this file's whole
 * subject is a money path that must not contain one. `12000` becomes '120.00'
 * with no float anywhere in the expression.
 */
function pccMajor(int $fils): string
{
    return intdiv($fils, 100) . '.' . str_pad((string) ($fils % 100), 2, '0', STR_PAD_LEFT);
}

function pccProvider(string $id, array $config): void
{
    $row = PaymentProvider::create([
        'id' => $id,
        'title' => ucfirst($id),
        'enabled' => true,
        'mode' => 'test',
        'position' => 1,
    ]);

    $row->config = $config;
    $row->save();

    app(GatewayCredentials::class)->forget();
}

function pccTabby(): void
{
    pccProvider('tabby', [
        'public_key' => 'pk_test_pcc',
        'secret_key' => 'sk_test_pcc',
        'merchant_code' => 'AE',
        'webhook_secret' => 'whsec-pcc-0123456789abcdef',
    ]);
}

function pccTamara(): void
{
    pccProvider('tamara', [
        'api_token' => 'PCC_TAMARA_API_TOKEN',
        'notification_token' => 'pcc-notification-token',
        'webhook_secret' => 'whsec-tamara-pcc-0123456789',
    ]);
}

function pccStripe(): void
{
    pccProvider('stripe', [
        'secret_key' => 'sk_test_pcc',
        'webhook_signing_secret' => 'whsec_pcc',
    ]);
}

/**
 * An authorised, uncaptured order on $provider, ready for the Capture button.
 *
 * `paid_at` is set because every gateway here reports a capture window, and
 * PaymentCapturer refuses to capture what was never authorised.
 */
function pccOrder(string $provider, array $attributes = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'PCC-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => PCC_TOTAL,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'fee_total' => 0,
        'total' => PCC_TOTAL,
        'payment_method' => $provider,
        'paid_at' => now()->subDay(),
        'transaction_id' => 'pcc_' . $provider . '_' . uniqid(),
    ], $attributes));

    $order->items()->create([
        'name' => 'Line 0',
        'sku' => 'SKU-0',
        'quantity' => 1,
        'unit_price' => PCC_TOTAL,
        'subtotal' => PCC_TOTAL,
        'total' => PCC_TOTAL,
    ]);

    return $order->fresh();
}

/** Tabby answering CLOSED with a captures[] list built from integer fils. */
function pccFakeTabbyClosed(array $captures): void
{
    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response([
            'id' => 'pay_pcc',
            'status' => 'CLOSED',
            'amount' => pccMajor(PCC_TOTAL),
            'currency' => 'AED',
            'captures' => $captures,
        ], 200),
    ]);
}

/** Tamara answering an already-captured status with a transactions block. */
function pccFakeTamaraCaptured(Order $order, string $status, array $captures): void
{
    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => (string) $order->transaction_id,
            'order_reference_id' => (string) $order->order_number,
            'status' => $status,
            'total_amount' => ['amount' => pccMajor(PCC_TOTAL), 'currency' => 'AED'],
            'transactions' => ['captures' => $captures],
        ], 200),
    ]);
}

/** Stripe answering a succeeded intent. `$body` is merged over the defaults. */
function pccFakeStripeSucceeded(array $body = []): void
{
    Http::fake([
        'api.stripe.com/v1/payment_intents/*' => Http::response(array_merge([
            'id' => 'pi_pcc',
            'status' => 'succeeded',
            'amount' => PCC_TOTAL,
        ], $body), 200),
    ]);
}

function pccCapture(Order $order): SettlementResult
{
    return app(PaymentCapturer::class)->capture($order, 'Admin');
}

/* ═══════════════════════════ 1. the three providers that can report a partial */

it('records what tamara actually took on a partially captured order', function () {
    /*
     * THE DEFECT, on the one status TamaraGateway::capture() was already being
     * careful about. Its docblock says a partial capture on the account "came
     * from elsewhere and silently topping it up would be the wrong guess to
     * make with money" — and it was right not to top it up. What was wrong is
     * that PaymentCapturer then recorded the WHOLE order total against that
     * ok(), so the shop's refund ceiling was 300.00 over a 120.00 capture.
     *
     * MUTATION: drop `capturedFils: $this->capturedSumFils($remote)` from the
     * `already_captured` ok() in TamaraGateway::capture(), or change
     * PaymentCapturer's write back to `'captured_total' => $amountFils`. Red
     * either way — `captured_total` comes back 30000 and the 300.00 refund is
     * accepted. RUN, both ways.
     */
    pccTamara();
    $order = pccOrder('tamara');

    pccFakeTamaraCaptured($order, 'partially_captured', [[
        'capture_id' => 'cap_pcc_partial',
        'total_amount' => ['amount' => pccMajor(PCC_PART), 'currency' => 'AED'],
    ]]);

    $result = pccCapture($order);
    $order->refresh();

    expect($result->ok)->toBeTrue()
        ->and($result->code)->toBe('already_captured')
        ->and($result->capturedFils)->toBe(PCC_PART)
        ->and((int) $order->captured_total)->toBe(PCC_PART)
        ->and($order->capture_ref)->toBe('cap_pcc_partial')
        // The whole point: the ceiling is 120.00, not 300.00.
        ->and(app(PaymentRefunder::class)->capturedFils($order))->toBe(PCC_PART);

    // And the money consequence, driven rather than argued. Refused before any
    // provider call, so preventStrayRequests() would catch a call if one were
    // made.
    $over = app(PaymentRefunder::class)->refund($order, PCC_TOTAL, 'returned', 'Admin');

    expect($over->ok)->toBeFalse()
        ->and($over->code)->toBe('over_captured')
        ->and($over->message)->toContain('120.00');
});

it('records what tabby actually took when it closes a payment over a short capture', function () {
    /*
     * THE SAME DEFECT ONE STEP ALONG FROM THE VOID ONE TabbyGateway ALREADY
     * NAMES. CLOSED says the authorisation is finished with, not that it was
     * finished with in full — Tabby's capture endpoint takes an amount, and
     * capture() read the last capture's `id` and never its `amount`. So a
     * 120.00 capture made in the merchant portal closed the payment and this
     * shop wrote 300.00 captured against it.
     *
     * MUTATION: drop `capturedFils: $this->capturedSumFils($payment)` from the
     * `already_captured` ok() in TabbyGateway::capture(). Red — `captured_total`
     * is 30000. RUN.
     */
    pccTabby();
    $order = pccOrder('tabby');

    pccFakeTabbyClosed([['id' => 'cap_pcc_short', 'amount' => pccMajor(PCC_PART)]]);

    $result = pccCapture($order);
    $order->refresh();

    expect($result->code)->toBe('already_captured')
        ->and($result->capturedFils)->toBe(PCC_PART)
        ->and((int) $order->captured_total)->toBe(PCC_PART)
        ->and($order->capture_ref)->toBe('cap_pcc_short')
        ->and(app(PaymentRefunder::class)->capturedFils($order))->toBe(PCC_PART);

    // No POST to a capture endpoint: the payment was already CLOSED and this
    // path must not top it up.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/captures'));
});

it('adds up every tabby capture rather than reading only the last one', function () {
    /*
     * lastId() takes the LAST entry, because that is the transaction a call
     * just created. The money question is a different one: two captures of
     * 120.00 and 60.00 are 180.00 of taken money, and an implementation that
     * reused lastId()'s "last entry" rule for the amount would record 60.00 —
     * under-stating the ceiling, which blocks a refund the shop genuinely owes.
     *
     * MUTATION: make capturedSumFils() return the last entry's amount only
     * (`$sum = $this->toFils($capture['amount'])` instead of `+=`). Red — 6000
     * instead of 18000. RUN.
     */
    pccTabby();
    $order = pccOrder('tabby');

    pccFakeTabbyClosed([
        ['id' => 'cap_a', 'amount' => pccMajor(12000)],
        ['id' => 'cap_b', 'amount' => pccMajor(6000)],
    ]);

    pccCapture($order);

    expect((int) $order->fresh()->captured_total)->toBe(18000);
});

it('records what stripe actually received on a partially captured intent', function () {
    /*
     * `amount_received`, not `amount`. StripeGateway::handleWebhook() already
     * makes exactly this distinction on `payment_intent.succeeded` and says why
     * in its own comment — "`amount` is what the intent asked for;
     * `amount_received` is what was actually taken, and on a partial capture
     * the two differ". capture() read neither: it answered `already_captured`
     * on `succeeded` and let PaymentCapturer write the order total.
     *
     * MUTATION: drop `capturedFils: is_numeric($received) ? (int) $received :
     * null` from the `succeeded` ok() in StripeGateway::capture(), or read
     * `$read['body']['amount']` instead of `amount_received`. Red either way —
     * 30000. RUN, both ways.
     */
    pccStripe();
    $order = pccOrder('stripe', ['transaction_id' => 'pi_pcc']);

    pccFakeStripeSucceeded(['amount_received' => PCC_PART]);

    $result = pccCapture($order);
    $order->refresh();

    expect($result->code)->toBe('already_captured')
        ->and($result->capturedFils)->toBe(PCC_PART)
        ->and((int) $order->captured_total)->toBe(PCC_PART)
        ->and(app(PaymentRefunder::class)->capturedFils($order))->toBe(PCC_PART);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/capture'));
});

/* ═════════════════════════ 2. and nothing that already worked has moved ════ */

it('writes the same captured_total it always did on cash on delivery', function () {
    /*
     * RULE 1 OF THIS REPO, driven rather than asserted. COD has no provider and
     * names no figure, so CashOnDelivery::capture() leaves `capturedFils` null
     * and PaymentCapturer must write the amount it asked for — the exact fil
     * this gateway has written since it existed.
     *
     * The second expectation is the one that would catch the tempting wrong
     * fix: a gateway "helpfully" filling `capturedFils` in with `$amountFils`
     * would make "the provider agreed" and "we assumed" indistinguishable, and
     * this pins COD as the gateway that agrees to nothing.
     *
     * MUTATION: return `capturedFils: $amountFils` from CashOnDelivery::
     * capture(). Red on the `capturedFils` expectation (the total stays right,
     * which is the point — the value is correct and the CLAIM is a lie). RUN.
     */
    pccProvider('cod', []);
    $order = pccOrder('cod', ['paid_at' => null]);

    $result = pccCapture($order);

    expect($result->code)->toBe('captured')
        ->and($result->capturedFils)->toBeNull()
        ->and((int) $order->fresh()->captured_total)->toBe(PCC_TOTAL)
        ->and(Http::recorded())->toHaveCount(0);
});

it('writes the whole order total on an ordinary full capture, on every gateway', function (string $provider, callable $fake, string $ref) {
    /*
     * THE OTHER HALF OF RULE 1, across all four gateways at once: a capture
     * this shop makes, or finds already made IN FULL, records exactly what it
     * always recorded. If this file's change could move an ordinary capture by
     * one fil, this is where it would show.
     *
     * Note the two Tamara rows and the Stripe "no figure" row: those bodies
     * carry NO amount at all, which is the shape the existing suite fakes
     * (PaymentSettlementTest's Stripe case sends `{id, status}` and nothing
     * else). They exercise the null path — the provider named no figure, so the
     * requested amount stands.
     *
     * MUTATION: make PaymentCapturer::settledFils() `return (int) $result->
     * capturedFils;` — i.e. treat a null as a zero. Red on the three rows whose
     * provider names no figure, on the COD case above, and on both clamp cases
     * below (6 failed, 9 passed, run): `captured_total` collapses to 0, which
     * is also the state that sends PaymentRefunder::ceilingFrom() falling
     * THROUGH its capture branch into the confirmed-payments sum. The three
     * rows that DO carry a full figure stay green, which is why the rows with
     * no figure are in this dataset at all.
     */
    $provider === 'tabby' ? pccTabby() : ($provider === 'tamara' ? pccTamara() : pccStripe());

    $order = pccOrder($provider, $provider === 'stripe' ? ['transaction_id' => 'pi_pcc'] : []);

    $fake($order);

    $result = pccCapture($order);
    $order->refresh();

    expect($result->ok)->toBeTrue()
        ->and((int) $order->captured_total)->toBe(PCC_TOTAL)
        ->and($order->capture_ref)->toBe($ref)
        ->and(app(PaymentRefunder::class)->capturedFils($order))->toBe(PCC_TOTAL);
})->with([
    // A real capture this shop made: AUTHORIZED, then a POST that Tabby accepts.
    'tabby captures it now' => ['tabby', function (Order $order) {
        Http::fake([
            'api.tabby.ai/api/v2/payments/*' => Http::response([
                'id' => 'pay_pcc', 'status' => 'AUTHORIZED',
                'amount' => pccMajor(PCC_TOTAL), 'currency' => 'AED',
            ], 200),
            'api.tabby.ai/api/v1/payments/*/captures' => Http::response([
                'id' => 'pay_pcc',
                'captures' => [['id' => 'cap_pcc_full', 'amount' => pccMajor(PCC_TOTAL)]],
            ], 200),
        ]);
    }, 'cap_pcc_full'],

    // Already captured, in full, and Tabby says so with an amount.
    'tabby closed over a full capture' => ['tabby', function (Order $order) {
        pccFakeTabbyClosed([['id' => 'cap_pcc_full', 'amount' => pccMajor(PCC_TOTAL)]]);
    }, 'cap_pcc_full'],

    // Already captured, in full, and Tamara says so with an amount.
    'tamara fully captured with a figure' => ['tamara', function (Order $order) {
        pccFakeTamaraCaptured($order, 'fully_captured', [[
            'capture_id' => 'cap_pcc_full',
            'total_amount' => ['amount' => pccMajor(PCC_TOTAL), 'currency' => 'AED'],
        ]]);
    }, 'cap_pcc_full'],

    // Already captured and Tamara volunteers NO amount: the null path.
    'tamara fully captured with no figure' => ['tamara', function (Order $order) {
        pccFakeTamaraCaptured($order, 'fully_captured', [['capture_id' => 'cap_pcc_full']]);
    }, 'cap_pcc_full'],

    // Stripe's ordinary Checkout shape: succeeded, and the body the existing
    // suite fakes carries no `amount_received` at all.
    'stripe succeeded with no figure' => ['stripe', function (Order $order) {
        Http::fake([
            'api.stripe.com/v1/payment_intents/*' => Http::response([
                'id' => 'pi_pcc', 'status' => 'succeeded',
            ], 200),
        ]);
    }, 'pi_pcc'],

    // Stripe succeeded having received all of it.
    'stripe succeeded in full' => ['stripe', function (Order $order) {
        pccFakeStripeSucceeded(['amount_received' => PCC_TOTAL]);
    }, 'pi_pcc'],
]);

/* ═══════════════════════════ 3. the two clamps, each load-bearing ══════════ */

it('falls back to the requested amount when a provider reports a zero capture', function () {
    /*
     * A ZERO IS NOT A MEASUREMENT, AND WRITING ONE IS WORSE THAN THE DEFECT.
     *
     * PaymentRefunder::ceilingFrom() opens with `if ($captured &&
     * $capturedTotal > 0)`. A `captured_total` of 0 with `captured_at` set
     * falls THROUGH that branch into the confirmed-payments sum, which is built
     * out of the AUTHORISATION — so a zero would hand back the full inflated
     * ceiling this lane exists to remove, by a longer route and with no column
     * on the order to show for it.
     *
     * A provider reporting a capture and no money is a response we do not
     * understand; the safe reading of one of those is the amount we asked for.
     *
     * MUTATION: change settledFils()'s guard from `$reported === null ||
     * $reported <= 0` to `$reported === null`. Red — `captured_total` is 0 and
     * the ceiling comes back 30000 off the payments table.
     */
    pccTamara();
    $order = pccOrder('tamara');

    pccFakeTamaraCaptured($order, 'partially_captured', [[
        'capture_id' => 'cap_pcc_zero',
        'total_amount' => ['amount' => pccMajor(0), 'currency' => 'AED'],
    ]]);

    pccCapture($order);

    expect((int) $order->fresh()->captured_total)->toBe(PCC_TOTAL);
});

it('never raises the ceiling above the amount it asked for', function () {
    /*
     * THE ONE DIRECTION THIS CHANGE MUST NOT BE ABLE TO MOVE.
     *
     * No provider can capture more than it authorised, so a figure above the
     * requested amount is a response shape we have misread rather than money
     * the shop is holding — and a misreading in this lane must not be able to
     * RAISE a refund ceiling, which is the whole defect it was written to
     * remove. Capped, so the worst this change can ever do is leave
     * `captured_total` exactly where it was before.
     *
     * MUTATION: change settledFils()'s `return min($reported, $amountFils);` to
     * `return $reported;`. Red — 90000 captured against a 30000 order, and a
     * 600.00 refund on a 300.00 order accepted.
     */
    pccTamara();
    $order = pccOrder('tamara');

    pccFakeTamaraCaptured($order, 'partially_captured', [[
        'capture_id' => 'cap_pcc_over',
        'total_amount' => ['amount' => pccMajor(90000), 'currency' => 'AED'],
    ]]);

    pccCapture($order);
    $order->refresh();

    expect((int) $order->captured_total)->toBe(PCC_TOTAL)
        ->and(app(PaymentRefunder::class)->capturedFils($order))->toBe(PCC_TOTAL);
});

/* ═══════════════════════════ 4. the order note says which it was ══════════ */

it('says PARTIAL in the order note when the provider took less', function () {
    /*
     * The operator reading the order is the person who has to chase the rest of
     * the money, and a note reading "Captured 300.00" over a 120.00 capture is
     * the same lie `captured_total` was telling, on the surface he actually
     * reads.
     *
     * MUTATION: delete the `$capturedFils < $amountFils ? sprintf(...)` arm
     * from PaymentCapturer's note. Red — the note reads "Captured 300.00 AED"
     * and carries no PARTIAL. RUN.
     */
    pccTamara();

    $order = pccOrder('tamara');
    pccFakeTamaraCaptured($order, 'partially_captured', [[
        'capture_id' => 'cap_pcc_note',
        'total_amount' => ['amount' => pccMajor(PCC_PART), 'currency' => 'AED'],
    ]]);
    pccCapture($order);

    $note = $order->fresh()->notes()->latest('id')->value('content');

    expect($note)->toContain('Captured 120.00 AED via tamara.')
        ->and($note)->toContain('PARTIAL')
        ->and($note)->toContain('300.00');
});

it('writes the note it has always written when the capture was not short', function () {
    /*
     * RULE 1 ON THE SENTENCE ITSELF, and a separate case from the one above
     * rather than its second half on purpose: Http::fake() MERGES rather than
     * replaces, so a second Tamara order faked inside one test is answered by
     * the FIRST order's stub and refused as a reference_mismatch. Two orders,
     * two tests, two fakes.
     *
     * The assertion is toBe() rather than toContain(): applying this package
     * must leave an ordinary capture's note byte-identical, and the only way to
     * pin "and nothing was appended" is to pin the whole string.
     *
     * MUTATION: make the PARTIAL arm unconditional in PaymentCapturer's note
     * (drop the `$capturedFils < $amountFils ?` test). Red — the sentence grows
     * a PARTIAL clause on a full capture. RUN.
     */
    pccTamara();

    $order = pccOrder('tamara');
    pccFakeTamaraCaptured($order, 'fully_captured', [[
        'capture_id' => 'cap_pcc_full',
        'total_amount' => ['amount' => pccMajor(PCC_TOTAL), 'currency' => 'AED'],
    ]]);
    pccCapture($order);

    expect($order->fresh()->notes()->latest('id')->value('content'))
        ->toBe('Captured 300.00 AED via tamara. Capture reference cap_pcc_full.');
});
