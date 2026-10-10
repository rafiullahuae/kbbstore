<?php

declare(strict_types=1);

/**
 * Lane O1 — the order lifecycle audited end to end, and the two money holes it
 * found.
 *
 * Both are the same shape and neither is a gateway problem: a column that
 * records something that happened to an order's MONEY was believed on an order
 * it had not happened to.
 *
 *   1. DUPLICATE CARRIED THE CAPTURE. `runAction`'s `duplicate` replicated
 *      `captured_at`, `captured_total` and `capture_ref`, so a copy of a paid
 *      order was born reading "already captured" for money nobody had taken —
 *      no Capture button, and a full refund available against the original's
 *      capture reference.
 *
 *   2. CAPTURE COULD NOT SEE A RELEASED AUTHORISATION. `voided_at` was invisible
 *      to PaymentCapturer, so an order whose hold had been given back and which
 *      an operator then revived out of `cancelled` offered the Capture button
 *      and could only ever fail at the provider.
 *
 * Everything here is DRIVEN — an order is built, a button is pressed through the
 * real endpoint or the real service, and the assertion is on what came back.
 * Each case carries the mutation that turns it red, and every one of those
 * mutations was run.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentProvider;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\PaymentRefunder;
use App\Services\Payments\PaymentVoider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    PaymentProvider::query()->delete();

    /*
     * Any HTTP call a test here did not ask for is an error. It is what makes
     * "the duplicate was refused before anything reached a provider" a real
     * assertion rather than a hopeful one.
     */
    Http::preventStrayRequests();
});

function auditAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Audit Owner',
        'email' => 'audit-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A captured, completed, cash-on-delivery order: the shop's ordinary case. */
function auditCapturedOrder(array $attributes = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'AUD-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => 25000,
        'total' => 25000,              // AED 250.00 in fils
        'payment_method' => 'cod',
        'paid_at' => now(),
        'captured_at' => now(),
        'captured_total' => 25000,
        'capture_ref' => 'cod-capture-of-the-original',
        'invoiced_at' => now(),
    ], $attributes));

    OrderItem::create([
        'order_id' => $order->id,
        'name' => 'A jar of something',
        'quantity' => 1,
        'unit_price' => 25000,
        'total' => 25000,
    ]);

    return $order;
}

/** Tabby, configured, so `supported` is true and the release path is real. */
function auditTabby(): void
{
    $provider = PaymentProvider::create([
        'id' => 'tabby',
        'title' => 'Tabby',
        'enabled' => true,
        'mode' => 'test',
        'position' => 1,
    ]);

    $provider->config = [
        'public_key' => 'pk_test_11111111-2222-3333-4444-555555555555',
        'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555',
        'merchant_code' => 'AE',
        'webhook_secret' => 'whsec-tabby-abcdefghijklmnopqrstuvwxyz012345',
    ];

    $provider->save();

    app(GatewayCredentials::class)->forget();
}

/*
|------------------------------------------------------------------------------
| 1 — Duplicate must not inherit the original's capture
|------------------------------------------------------------------------------
*/

/**
 * THE DEFECT, AS IT LOOKED ON THE SHOP.
 *
 * Store → Orders → open a paid order → Actions → Duplicate. The new order opens
 * as a `draft` with its money panel reading
 *
 *     Captured · AED 250.00 on 27 Sep 2026 · ref cod-capture-of-the-original
 *
 * and no Capture button anywhere on it, because `capturable` is false. The shop
 * packs it, ships it, and is never paid — which is the exact failure
 * PaymentCapturer's own header says that class exists to end, arriving through
 * a different door. Pressing Capture answers `already_captured` with ok: true,
 * so even an operator who goes looking is told the money is in.
 *
 * MUTATION: take 'captured_at', 'captured_total' and 'capture_ref' back out of
 * AdminOrderController::NOT_DUPLICATED. RUN: red — `captured` is true, the
 * ceiling is 25000 and `capturable` is false.
 */
it('does not carry the original order\'s capture onto a duplicate', function () {
    $admin = auditAdmin();
    $original = auditCapturedOrder();

    $response = test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $original->id . '/action', ['action' => 'duplicate'])
        ->assertOk();

    $copy = Order::findOrFail($response->json('new_order_id'));

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->status)->toBe('draft');

    // The three columns, on the row itself.
    expect($copy->captured_at)->toBeNull('a copy of a paid order has not been captured');
    expect((int) ($copy->captured_total ?? 0))->toBe(0);
    expect($copy->capture_ref)->toBeNull(
        'carrying the original\'s capture reference points a refund on the copy at the original\'s money'
    );

    // And the money panel the order screen actually draws from.
    $panel = app(PaymentCapturer::class)->status($copy);

    expect($panel['captured'])->toBeFalse('the copy must not read as paid');
    expect($panel['capturable'])->toBeTrue(
        'the whole point of a duplicate is an order that can still be paid for — '
        . 'a copy with no Capture button is an order the shop ships for nothing'
    );

    // The original is untouched: it really was captured and still says so.
    $original->refresh();
    expect($original->captured_at)->not->toBeNull()
        ->and((int) $original->captured_total)->toBe(25000);
});

/**
 * THE SAME COLUMNS SEEN FROM THE REFUND SIDE, WHICH IS WHERE THE MONEY LEAVES.
 *
 * PaymentRefunder's ceiling is `captured_total` whenever `captured_at` is set,
 * and `refunds` rows are not replicated — so the copy read 250.00 captured
 * against 0.00 refunded and a full refund on it was ACCEPTED. On cash on
 * delivery that is a recorded instruction to hand AED 250 back in cash to
 * somebody who never paid a fil, and the copy moved itself to `refunded`. On a
 * gateway it is the original's capture reference being refunded a second time,
 * while the original's own refunded total stays at zero.
 *
 * MUTATION: as above. RUN: red — the ceiling is 25000 and the refund is applied
 * rather than refused.
 */
it('leaves a duplicate with nothing to refund', function () {
    $admin = auditAdmin();
    $original = auditCapturedOrder();

    $copy = Order::findOrFail(
        test()->actingAs($admin, 'admin')
            ->postJson('/admin-api/orders/' . $original->id . '/action', ['action' => 'duplicate'])
            ->assertOk()
            ->json('new_order_id')
    );

    $refunder = app(PaymentRefunder::class);

    expect($refunder->capturedFils($copy))->toBe(
        0,
        'nothing was ever captured on a duplicate, so nothing may be refunded from it'
    );

    $outcome = $refunder->refund($copy, 25000, 'audit', 'Audit Owner', 'audit-' . uniqid());

    expect($outcome->ok)->toBeFalse('a refund on an unpaid duplicate is money leaving for nothing');
    expect($outcome->code)->toBe('nothing_captured');

    $copy->refresh();
    expect($copy->status)->toBe('draft', 'a refused refund must not move the copy to `refunded`');
});

/**
 * `invoice_number` was already excluded and `invoiced_at` was not, so a
 * duplicate read as invoiced while carrying no invoice number — and opening its
 * invoice is what allocates one, so the screen's "already invoiced" was a claim
 * about a document that did not exist.
 *
 * MUTATION: remove 'invoiced_at' from NOT_DUPLICATED. RUN: red.
 */
it('does not carry the original order\'s invoice date onto a duplicate', function () {
    $admin = auditAdmin();
    $original = auditCapturedOrder();

    $copy = Order::findOrFail(
        test()->actingAs($admin, 'admin')
            ->postJson('/admin-api/orders/' . $original->id . '/action', ['action' => 'duplicate'])
            ->assertOk()
            ->json('new_order_id')
    );

    expect($copy->invoice_number)->toBeNull()
        ->and($copy->invoiced_at)->toBeNull(
            'invoice_number and invoiced_at are one fact; a copy carrying half of it reads as invoiced with no invoice'
        );
});

/*
|------------------------------------------------------------------------------
| 2 — A released authorisation is not capturable
|------------------------------------------------------------------------------
*/

/**
 * THE DEFECT, AS IT LOOKED ON THE SHOP.
 *
 * A Tabby order is cancelled and its authorisation released through Store →
 * Orders → the order → Release authorisation, so the customer's payment plan is
 * genuinely gone at Tabby. An operator then decides the cancellation was a
 * mistake and puts the status back to `processing` — which OrderStatus permits
 * on purpose, and which re-takes the units and the coupon use. It cannot
 * re-take the authorisation: nothing can, and PaymentVoider says so.
 *
 * Capture was offered anyway. `capturable` came back TRUE, measured, because
 * PaymentCapturer never looked at `voided_at`: the status guard that appears to
 * cover this only covers it while the order is still `cancelled`. Pressing it
 * claimed `captured_at`, called Tabby, was refused, released the claim, and left
 * an order note blaming the PROVIDER for a hold this shop had released itself.
 *
 * MUTATION: delete the `voided_at` clause from PaymentCapturer::status()'s
 * `capturable` expression. RUN: red on the first expectation.
 * MUTATION: delete the `authorisation_released` block from
 * PaymentCapturer::capture(). RUN: red on the second — and it is the one that
 * matters, because the only thing refusing the capture then is the gateway's own
 * live status read.
 */
it('will not capture an order whose authorisation has been released', function () {
    auditTabby();

    $order = Order::create([
        'order_number' => 'AUD-VOID-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'cancelled',
        'currency' => 'AED',
        'subtotal' => 25000,
        'total' => 25000,
        'payment_method' => 'tabby',
        'transaction_id' => 'pay_audit',
        'paid_at' => now(),
    ]);

    Http::fake([
        '*/api/v2/payments/pay_audit' => Http::response(['id' => 'pay_audit', 'status' => 'AUTHORIZED'], 200),
        '*/api/v1/payments/pay_audit/close' => Http::response(['id' => 'pay_audit', 'status' => 'CLOSED'], 200),
    ]);

    // Release it for real, through the service the button calls.
    $release = app(PaymentVoider::class)->void($order, 'Audit Owner');

    expect($release->ok)->toBeTrue('the fixture must really release the hold, or this asserts nothing');

    $order->refresh();
    expect($order->voided_at)->not->toBeNull();

    // The operator changes their mind. OrderStatus permits this deliberately.
    app(OrderStatus::class)->moveTo($order, 'processing', by: 'Audit Owner', reason: 'Audit revive.');
    $order->refresh();

    expect($order->status)->toBe('processing', 'the revive must succeed, or this asserts nothing');
    expect($order->paid_at)->not->toBeNull('paid_at survives a release — that is why the old guard missed this');

    // ── the button is not offered ──────────────────────────────────────────
    $panel = app(PaymentCapturer::class)->status($order);

    expect($panel['supported'])->toBeTrue('Tabby must be configured, or `capturable` is false for the wrong reason');
    expect($panel['capturable'])->toBeFalse(
        'the authorisation was released; offering Capture here is a button that can only fail'
    );
    expect($panel['expiring'])->toBeFalse('there is no capture window left to run out on a released hold');

    // ── and pressing it anyway is refused HERE, with no call to Tabby ──────
    // No Http::fake for a capture endpoint is registered, so preventStrayRequests()
    // fails this test if the refusal reaches the network.
    $result = app(PaymentCapturer::class)->capture($order, 'Audit Owner');

    expect($result->ok)->toBeFalse();
    expect($result->code)->toBe('authorisation_released');
    expect($result->message)->toContain('released');

    $order->refresh();
    expect($order->captured_at)->toBeNull('a refused capture must not leave the order marked captured');
    // The column defaults to 0 rather than null, which is why this is not a
    // toBeNull() — a capture that wrote nothing leaves it at its default.
    expect((int) $order->captured_total)->toBe(0);
});

/**
 * The order screen's own payload has to be able to SAY it, or the panel cannot
 * draw it.
 *
 * PaymentSettlementController::state() has carried a nested `void` key since the
 * release button shipped. The order detail drawer renders its money panel from
 * /admin-api/orders/{id}/detail, and that endpoint had no `void` key at all — so
 * the one screen the button lives on was the one screen blind to the release.
 *
 * Both endpoints are asserted, because the point is that they AGREE. Two
 * vocabularies for one fact is how the two screens come to contradict each
 * other, which is the defect Lane EZ closed elsewhere in this repo.
 *
 * MUTATION: remove the 'void' key from AdminOrderController::show()'s
 * `settlement` block. RUN: red.
 */
it('tells the order screen that an authorisation has been released', function () {
    auditTabby();
    $admin = auditAdmin();

    $order = Order::create([
        'order_number' => 'AUD-PANEL-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'cancelled',
        'currency' => 'AED',
        'subtotal' => 25000,
        'total' => 25000,
        'payment_method' => 'tabby',
        'transaction_id' => 'pay_panel',
        'paid_at' => now(),
        'voided_at' => now(),
        'void_ref' => 'cancel-ref-1',
    ]);

    $detail = test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/detail')
        ->assertOk();

    expect($detail->json('settlement.void.voided'))->toBeTrue(
        'the order detail payload must carry the release, or the money panel cannot draw it'
    );
    expect($detail->json('settlement.void.void_ref'))->toBe('cancel-ref-1');
    expect($detail->json('settlement.void.supported'))->toBeTrue();
    expect($detail->json('settlement.capturable'))->toBeFalse();

    // The same fact, the same shape, on the settlement endpoint.
    $panel = app(\App\Http\Controllers\Admin\PaymentSettlementController::class)
        ->show($order->id);

    expect($panel->getData(true)['void']['voided'])->toBeTrue();
    expect(array_keys($detail->json('settlement.void')))
        ->toBe(
            array_keys($panel->getData(true)['void']),
            'the two endpoints must describe a release with the same keys, or the two screens will disagree'
        );
});

/*
|------------------------------------------------------------------------------
| 3 — The order detail screen does not query per line, note or refund
|------------------------------------------------------------------------------
*/

/**
 * The slope, measured rather than asserted as a total.
 *
 * /admin-api/orders/{id}/detail is the busiest read on this path — it carries
 * the lines, the notes, the refund list, the capture panel and the release
 * panel — and a per-row query here is invisible on a two-line test order and
 * ruinous on the 40-line wholesale orders this shop takes.
 *
 * Measured at 1, 2, 5, 10 and 20 line items, with a matching number of order
 * notes and two refunds on every order, so anything that walked items, notes or
 * refunds would show. It is FLAT: 7 statements at every size (8 on an order
 * that is authorised but not captured, where the refund ceiling really does
 * have to read `payments`).
 *
 * It was 9 and 11. `refundedFils()` was called three times and `capturedFils()`
 * twice for the two figures the payload carries; they are computed once now.
 * See the note in show().
 *
 * MUTATION: put `$refunder->refundedFils($order)` back inline at each of the
 * three call sites and this is red on the ceiling, not on the slope — which is
 * the point of pinning both. RUN: red, 9 against a ceiling of 8.
 */
it('does not query per line, note or refund on the order detail screen', function () {
    $admin = auditAdmin();

    $counts = [];

    foreach ([1, 2, 5, 10, 20] as $lines) {
        $order = auditCapturedOrder(['status' => 'processing']);

        // Lines, notes and refunds all grow together, so a walk over any of the
        // three shows up as a slope.
        for ($i = 1; $i < $lines; $i++) {
            OrderItem::create([
                'order_id' => $order->id, 'name' => 'Line ' . $i,
                'quantity' => 1, 'unit_price' => 100, 'total' => 100,
            ]);
        }

        for ($i = 0; $i < $lines; $i++) {
            \App\Models\OrderNote::create([
                'order_id' => $order->id, 'author' => 'Audit',
                'is_customer_note' => false, 'content' => 'note ' . $i,
            ]);
        }

        for ($i = 0; $i < 2; $i++) {
            \App\Models\Refund::create([
                'order_id' => $order->id, 'amount' => 100, 'status' => 'succeeded',
                'refunded_by' => 'Audit', 'provider' => 'cod',
                'idempotency_key' => 'slope-' . uniqid(),
            ]);
        }

        // One warm-up: settings memoise per container, so the first request of a
        // process pays for all of them and comparing a cold reading with a warm
        // one reports a fall that is not there.
        \Tests\Support\SqlShape::capture(fn () => test()->actingAs($admin, 'admin')
            ->getJson('/admin-api/orders/' . $order->id . '/detail')->getContent());

        $counts[$lines] = count(\Tests\Support\SqlShape::capture(
            fn () => test()->actingAs($admin, 'admin')
                ->getJson('/admin-api/orders/' . $order->id . '/detail')->getContent()
        ));
    }

    $report = json_encode($counts);

    // FLAT is the property. Twenty lines must not cost more than one.
    expect($counts[20])->toBeLessThanOrEqual(
        $counts[1],
        "the order detail screen queries per row — statement counts by line count: {$report}"
    );

    /*
     * And a ceiling, because a flat count can still be flat and wasteful. 8
     * rather than 7 so that a paid-but-uncaptured order — which legitimately
     * reads `payments` for its refund ceiling — passes the same bar.
     *
     * ▲ 10, RAISED DELIBERATELY BY LANE TM (10 Oct 2026), not to fit a slope:
     * the owner asked for the customer journey and the order's emails on this
     * screen, and each is ONE query whatever the order holds — the Cart
     * Tracking basket with its events (App\Support\CustomerJourney) and the
     * mail log (App\Support\OrderEmails). Measured 9 on this COD order, flat
     * at 1..20 lines. PaymentJourneyTest pins both at 2 queries for 3 events
     * and for 40, and a COD order's payment journey at zero.
     */
    expect($counts[20])->toBeLessThanOrEqual(
        10,
        "the order detail screen costs more than it should: {$report}"
    );
})->group('budget');

/*
|------------------------------------------------------------------------------
| 4 — The refund endpoint's own response
|------------------------------------------------------------------------------
*/

/**
 * POST /admin-api/orders/{id}/refund returns the two figures the screen redraws
 * the money panel from, and NOTHING covered them.
 *
 * GiRefundsAndNotesTest asserts `refunded_total_aed` and `refundable_aed` on the
 * DETAIL endpoint; no test reads them off the refund response. This lane found
 * that gap the expensive way: a careless edit left both keys referring to
 * variables that did not exist in that method's scope, and because
 * AdminOrderController declares `strict_types=1`, `Money::toAed(null)` is a
 * TypeError — a 500 on every press of the Refund button. The full suite was
 * GREEN on it.
 *
 * So the response is pinned here. It is two assertions and it covers the one
 * button on this screen that moves money out of the merchant's account.
 *
 * MUTATION: change `Money::toAed($refundedFils)` in refund() to
 * `Money::toAed($refundedFilsTypo)`. RUN: red with a 500, where before it was
 * green.
 */
it('reports the refunded and refundable totals on the refund response', function () {
    $admin = auditAdmin();
    $order = auditCapturedOrder(['status' => 'processing']);   // AED 250 captured

    $response = test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/refund', [
            'amount_aed' => 100,
            'reason' => 'One jar arrived broken',
        ]);

    // Cash on delivery has no refund API, so this is `recorded_only` — a real
    // success that records the ledger entry a manual repayment needs.
    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('refund_status', 'succeeded')
        // Both figures, in major units, off the same pair of reads. Whole
        // dirhams encode as JSON integers, which is what the screen receives.
        ->assertJsonPath('refunded_total_aed', 100)
        ->assertJsonPath('refundable_aed', 150)
        // A partial refund is not a refunded order: the rest still has to ship.
        ->assertJsonPath('status', 'processing');

    // And the rest of it, so the ceiling really is what the response claims.
    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/refund', [
            'amount_aed' => 150,
            'reason' => 'The rest of it',
        ])
        ->assertOk()
        ->assertJsonPath('refunded_total_aed', 250)
        ->assertJsonPath('refundable_aed', 0)
        // Everything captured has gone back, so PaymentRefunder moves it.
        ->assertJsonPath('status', 'refunded');

    // One fil more is refused, against a ceiling read from our own tables.
    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/refund', ['amount_fils' => 1])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'over_captured');
});

/*
|------------------------------------------------------------------------------
| 5 — A refusal this shop made is not reported as a provider failure
|------------------------------------------------------------------------------
*/

/**
 * POST /admin-api/orders/{id}/capture answered a flat 502 to everything.
 *
 * 502 means "the provider was not fine, try again". It is the right answer to a
 * gateway that refused or could not be reached, and the wrong answer to every
 * refusal this shop made on its own before anything left the building: the
 * order is cancelled, the gateway cannot be captured, the authorisation has
 * been released. Those say the same thing for ever, so a status that invites a
 * retry is a failure reported as something it is not — the same defect as a
 * green tick on an error, one layer down.
 *
 * PaymentVoidController::statusFor() has drawn this line since the release
 * button shipped, and its docblock claims it is "the same distinction
 * PaymentSettlementController::capture() draws". That claim was false. It is
 * true now, which is what this pins.
 *
 * MUTATION: put `$result->ok ? 200 : 502` back in
 * PaymentSettlementController::capture(). RUN: red — 502 where 422 is expected.
 */
it('answers 422 for a capture this shop refused and 502 for one the gateway refused', function () {
    $admin = auditAdmin();

    // ── our own refusal: a cancelled order is not ours to take money for ──
    // COD, so no gateway is configured and no HTTP call can happen. The
    // beforeEach's preventStrayRequests() is what proves that.
    $cancelled = auditCapturedOrder([
        'status' => 'cancelled',
        'captured_at' => null,
        'captured_total' => 0,
        'capture_ref' => null,
    ]);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $cancelled->id . '/capture')
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'order_not_live');

    // ── and the release refusal this lane added, which is equally permanent ──
    auditTabby();

    $released = Order::create([
        'order_number' => 'AUD-422-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',          // revived out of `cancelled`
        'currency' => 'AED',
        'subtotal' => 25000,
        'total' => 25000,
        'payment_method' => 'tabby',
        'transaction_id' => 'pay_422',
        'paid_at' => now(),
        'voided_at' => now(),
        'void_ref' => 'pay_422',
    ]);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $released->id . '/capture')
        ->assertStatus(422)
        ->assertJsonPath('code', 'authorisation_released');

    // ── a GATEWAY refusal is still 502: the request was fine, Tabby was not ──
    $live = Order::create([
        'order_number' => 'AUD-502-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 25000,
        'total' => 25000,
        'payment_method' => 'tabby',
        'transaction_id' => 'pay_502',
        'paid_at' => now(),
    ]);

    Http::fake([
        'api.tabby.ai/api/v2/payments/*' => Http::response(['id' => 'pay_502', 'status' => 'AUTHORIZED'], 200),
        'api.tabby.ai/api/v1/payments/*/captures' => Http::response(['errorType' => 'capture_failed'], 400),
    ]);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $live->id . '/capture')
        ->assertStatus(502)
        ->assertJsonPath('ok', false);
});
