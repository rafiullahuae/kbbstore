<?php

/**
 * Checking the provider's books against ours.
 *
 * Every case here is a way money goes missing, not a feature:
 *
 *   - a payment taken at the provider whose webhook never arrived is a
 *     customer charged for goods nobody will ever pick;
 *   - a payment we believe we hold that the provider cannot confirm is a
 *     figure in this database that is not money;
 *   - two sides that agree a transaction happened and disagree about the
 *     amount is one of them being wrong about a sum of money;
 *   - a refund on one side only is either a customer paid twice or a customer
 *     not paid at all;
 *   - and a run that could not READ a provider reporting "all clear" is the
 *     worst of the lot, because it is the one that stops anybody looking.
 *
 * NOTHING HERE TOUCHES THE NETWORK. Http::preventStrayRequests() is on for the
 * whole file, so any call a gateway makes that a test did not explicitly fake
 * is an error rather than a silent success. The faked responses are asserted
 * on BOTH SIDES: what came back is parsed correctly, and the request that went
 * out had the URL, the auth header and the paging parameters it should have.
 * A parser tested against a response nobody checked the request for is a
 * parser that can be pointed at the wrong endpoint forever.
 *
 * The routes ship UNMOUNTED (CLAUDE.md forbids editing web.php), so the guard
 * tests register routes/payments-reconcile.php into exactly the group its own
 * header tells the integrator to mount it in.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentProvider;
use App\Models\Refund;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Reconciliation\CashOnDeliveryPosition;
use App\Services\Payments\Reconciliation\ListsTransactions;
use App\Services\Payments\Reconciliation\ReconcileWindow;
use App\Services\Payments\Reconciliation\Reconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * A canary long enough to be redacted (the eight-character floor) and
 * distinctive enough that a substring search for it cannot match anything else
 * in a response body.
 */
const RC_STRIPE_SECRET = 'sk_test_RECONCILECANARY00001';

const RC_TABBY_SECRET = 'sk_test_RECONCILECANARY00002';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();
});

/* ------------------------------------------------------------------ fixture */

function rcStripe(): void
{
    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'live', 'position' => 1,
    ]);

    $row->config = [
        'publishable_key' => 'pk_live_visible',
        'secret_key' => RC_STRIPE_SECRET,
        'webhook_signing_secret' => 'whsec_RECONCILECANARY0003',
        'webhook_secret' => 'url-secret-reconcile-00001',
    ];

    $row->save();

    app(GatewayCredentials::class)->forget();
}

function rcTabby(): void
{
    $row = PaymentProvider::create([
        'id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'live', 'position' => 2,
    ]);

    $row->config = [
        'public_key' => 'pk_test_tabby',
        'secret_key' => RC_TABBY_SECRET,
        'merchant_code' => 'AE',
        'webhook_secret' => 'url-secret-reconcile-00002',
    ];

    $row->save();

    app(GatewayCredentials::class)->forget();
}

function rcOrder(string $number, array $attributes = []): Order
{
    return Order::create(array_merge([
        'order_number' => $number,
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 25000,
        'total' => 25000,           // 250.00 AED, in fils
        'payment_method' => 'stripe',
    ], $attributes));
}

function rcPayment(Order $order, string $ref, array $attributes = []): Payment
{
    return Payment::create(array_merge([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'provider_ref' => $ref,
        'amount' => 25000,
        'currency' => 'AED',
        'status' => 'paid',
    ], $attributes));
}

/** One Stripe charge, in the shape GET /v1/charges returns them. */
function rcCharge(string $id, string $intent, int $amount, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'object' => 'charge',
        'amount' => $amount,
        'currency' => 'aed',
        'created' => now()->getTimestamp(),
        'status' => 'succeeded',
        'captured' => true,
        'paid' => true,
        'payment_intent' => $intent,
        'metadata' => [],
    ], $overrides);
}

/**
 * Fake Stripe with one page of charges and one page of refunds.
 *
 * Deliberately NOT a wildcard on api.stripe.com: the two endpoints answer
 * differently, and a single fake would make a test that read the wrong one
 * still pass.
 */
function rcFakeStripe(array $charges, array $refunds = [], bool $chargesHaveMore = false): void
{
    Http::fake([
        'api.stripe.com/v1/charges*' => Http::response(['object' => 'list', 'data' => $charges, 'has_more' => $chargesHaveMore]),
        'api.stripe.com/v1/refunds*' => Http::response(['object' => 'list', 'data' => $refunds, 'has_more' => false]),
    ]);
}

/** Drive a run to completion, the way the browser does. */
function rcRun(array $providers = ['stripe'], ?ReconcileWindow $window = null, bool $restart = false): int
{
    $reconciler = app(Reconciler::class);
    $window ??= ReconcileWindow::lastDays(7);

    $runId = $reconciler->open($window, $providers, 'tester', $restart);

    for ($i = 0; $i < 200; $i++) {
        if (($reconciler->step($runId)['done'] ?? false) === true) {
            break;
        }
    }

    return $runId;
}

/** @return array<int, object> */
function rcFindings(int $runId, ?string $kind = null): array
{
    $q = DB::table(Reconciler::FINDINGS)->where('run_id', $runId);

    if ($kind !== null) {
        $q->where('kind', $kind);
    }

    return $q->orderBy('id')->get()->all();
}

/* ====================================================== the absence it fills */

it('offers cash on delivery no reconciliation at all, and says so rather than leaving it blank', function () {
    // COD must not merely be missing from the list of gateways that can be
    // asked. "No discrepancies found" against a method with no second set of
    // books is a lie told by omission, so the absence is a stated fact.
    $cod = app(\App\Services\Payments\GatewayRegistry::class)->find('cod');

    expect($cod)->not->toBeNull()
        ->and($cod instanceof ListsTransactions)->toBeFalse()
        ->and(app(Reconciler::class)->reconcilable())->not->toContain('cod');

    // And the separate report exists, is one-sided by construction, and admits
    // which half it cannot know.
    $position = app(CashOnDeliveryPosition::class)->forWindow(ReconcileWindow::lastDays(30));

    expect($position['reconcilable'])->toBeFalse()
        ->and($position['note'])->toContain('no provider to check against')
        // The word the owner needs to see, in the shouted form the payload uses
        // deliberately: this is the half the report cannot know.
        ->and($position['note'])->toContain('BANKED');
});

it('counts cash on delivery as collected only when somebody marked it collected', function () {
    // Shipped, nobody has said the cash came back: owed, not collected.
    rcOrder('RC-COD-1', ['payment_method' => 'cod', 'status' => 'shipped', 'total' => 30000]);

    // Collected, and for LESS than the order total. captured_total is the
    // figure, not `total` — reporting the order total here would overstate the
    // drawer by the difference on every short collection.
    rcOrder('RC-COD-2', [
        'payment_method' => 'cod', 'status' => 'completed', 'total' => 40000,
        'captured_at' => now(), 'captured_total' => 35000,
    ]);

    // Cancelled and never collected: neither owed nor banked.
    rcOrder('RC-COD-3', ['payment_method' => 'cod', 'status' => 'cancelled', 'total' => 50000]);

    $position = app(CashOnDeliveryPosition::class)->forWindow(ReconcileWindow::lastDays(7));

    expect($position['collected']['fils'])->toBe(35000)
        ->and($position['collected']['orders'])->toBe(1)
        ->and($position['outstanding']['fils'])->toBe(30000)
        ->and($position['closed_uncollected']['fils'])->toBe(50000);
});

/* ============================================ Q1: money we have no record of */

it('finds a payment the provider took that never reached this database', function () {
    rcStripe();

    // The order exists and is still waiting. This is the webhook that never
    // arrived: the customer is charged and nothing has shipped.
    $order = rcOrder('RC-1001');

    rcFakeStripe([
        rcCharge('ch_1001', 'pi_1001', 25000, ['metadata' => ['order_number' => 'RC-1001']]),
    ]);

    $runId = rcRun();

    $findings = rcFindings($runId, Reconciler::MONEY_NOT_RECORDED);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe('alarm')
        ->and($findings[0]->order_number)->toBe('RC-1001')
        ->and($findings[0]->remote_ref)->toBe('ch_1001')
        // Fils, as an integer, both here and on the wire. Compared as an int
        // rather than a string: a decimal column reads back '5' on SQLite and
        // '5.000' on MySQL, and these are integers precisely so they cannot.
        ->and((int) $findings[0]->amount_remote)->toBe(25000)
        ->and($findings[0]->amount_local)->toBeNull();

    // The order is untouched. This reports; it does not repair.
    $order->refresh();

    expect($order->paid_at)->toBeNull()
        ->and($order->status)->toBe('pending')
        ->and(Payment::count())->toBe(0)
        ->and(PaymentEvent::count())->toBe(0);
});

it('sends the request it should: the right URL, the secret key, and Stripe-shaped date and paging parameters', function () {
    rcStripe();
    rcFakeStripe([]);

    rcRun();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/charges')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.stripe.com/v1/charges?')
            && $request->hasHeader('Authorization', 'Bearer ' . RC_STRIPE_SECRET)
            // A UNIX INTEGER, not a date string. Stripe coerces a string to 0
            // and then quietly answers with everything since 1970 — a request
            // that succeeds and asks the wrong question.
            && ctype_digit((string) ($query['created']['gte'] ?? ''))
            && ctype_digit((string) ($query['created']['lte'] ?? ''))
            && (int) $query['created']['gte'] < (int) $query['created']['lte']
            // Stripe's own ceiling. Asking for more is a 400, not a clamp.
            && (int) ($query['limit'] ?? 0) === 100
            // No cursor on the first page.
            && ! array_key_exists('starting_after', $query);
    });

    // And the refunds half really is a second endpoint, not the same one twice.
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.stripe.com/v1/refunds?'));
});

it('pages with the last id it was given, rather than with a row count of its own', function () {
    rcStripe();

    $first = [rcCharge('ch_page_a', 'pi_page_a', 1000), rcCharge('ch_page_b', 'pi_page_b', 1000)];

    Http::fake(function ($request) use ($first) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (str_contains($request->url(), '/v1/refunds')) {
            return Http::response(['data' => [], 'has_more' => false]);
        }

        // The second page is only served to a request carrying the LAST ID of
        // the first, which is what makes this an assertion about the cursor and
        // not merely about being called twice.
        return isset($query['starting_after']) && $query['starting_after'] === 'ch_page_b'
            ? Http::response(['data' => [rcCharge('ch_page_c', 'pi_page_c', 1000)], 'has_more' => false])
            : Http::response(['data' => $first, 'has_more' => true]);
    });

    $runId = rcRun();

    // All three pages' worth were read and judged: three charges with no local
    // record, so three findings.
    expect(rcFindings($runId, Reconciler::MONEY_NOT_RECORDED))->toHaveCount(3);
});

/* ================================ Q2: money we think we have and they do not */

it('finds a payment recorded here that the provider never listed', function () {
    rcStripe();

    $order = rcOrder('RC-1002', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1002');

    // Stripe's books are readable and simply do not contain it.
    rcFakeStripe([]);

    $runId = rcRun();

    $findings = rcFindings($runId, Reconciler::MONEY_NOT_CONFIRMED);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->local_ref)->toBe('pi_1002')
        ->and((int) $findings[0]->amount_local)->toBe(25000)
        ->and($findings[0]->amount_remote)->toBeNull();
});

it('concludes NOTHING from a provider it could not read', function () {
    rcStripe();

    $order = rcOrder('RC-1003', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1003');

    // A rotated key. This is indistinguishable from an empty list unless
    // somebody makes it distinguishable, which is the entire point of
    // RemotePage::failed().
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['code' => 'api_key_expired']], 401)]);

    $runId = rcRun();

    // The run says out loud that it could not look...
    expect(rcFindings($runId, Reconciler::PAYMENTS_SOURCE_UNAVAILABLE))->toHaveCount(1)
        ->and(rcFindings($runId, Reconciler::REFUNDS_SOURCE_UNAVAILABLE))->toHaveCount(1)
        // ...and does not report the perfectly good payment as unconfirmed.
        // This is the report that would convince an owner he had been
        // defrauded, produced by a typo.
        ->and(rcFindings($runId, Reconciler::MONEY_NOT_CONFIRMED))->toHaveCount(0);

    $summary = rcFindings($runId, Reconciler::PAYMENTS_SOURCE_UNAVAILABLE)[0]->summary;

    expect($summary)->toContain('could not be read')
        ->and($summary)->toContain('skipped');
});

it('reports a payment the provider says never succeeded', function () {
    rcStripe();

    $order = rcOrder('RC-1004', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1004');

    rcFakeStripe([rcCharge('ch_1004', 'pi_1004', 25000, ['status' => 'failed', 'captured' => false, 'paid' => false])]);

    $runId = rcRun();

    $findings = rcFindings($runId, Reconciler::MONEY_NOT_CONFIRMED);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->summary)->toContain('the money did not move');
});

/* ==================================================== Q3: amount disagreement */

it('finds a transaction both sides have and disagree about', function () {
    rcStripe();

    $order = rcOrder('RC-1005', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1005', ['amount' => 25000]);

    // The provider took 249.00, we think we took 250.00.
    rcFakeStripe([rcCharge('ch_1005', 'pi_1005', 24900)]);

    $runId = rcRun();

    $findings = rcFindings($runId, Reconciler::AMOUNT_DISAGREEMENT);

    expect($findings)->toHaveCount(1)
        ->and((int) $findings[0]->amount_local)->toBe(25000)
        ->and((int) $findings[0]->amount_remote)->toBe(24900)
        ->and($findings[0]->severity)->toBe('alarm');
});

it('finds a currency disagreement even when the number matches', function () {
    rcStripe();

    $order = rcOrder('RC-1006', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1006', ['amount' => 25000, 'currency' => 'AED']);

    // 250.00 SAR against a 250.00 AED order matches on the number and is not
    // the same money — PaymentConfirmer's rule, applied to the books.
    rcFakeStripe([rcCharge('ch_1006', 'pi_1006', 25000, ['currency' => 'sar'])]);

    $runId = rcRun();

    $findings = rcFindings($runId, Reconciler::AMOUNT_DISAGREEMENT);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->summary)->toContain('currency');
});

it('says nothing at all when both sides agree', function () {
    rcStripe();

    $order = rcOrder('RC-1007', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1007');

    rcFakeStripe([rcCharge('ch_1007', 'pi_1007', 25000)]);

    $runId = rcRun();

    // A reconciliation that finds something on a correct shop is a
    // reconciliation nobody will read twice.
    expect(rcFindings($runId))->toHaveCount(0);
});

it('matches a charge to our row through the PaymentIntent, which is the id we actually stored', function () {
    rcStripe();

    $order = rcOrder('RC-1008', ['paid_at' => now(), 'status' => 'processing']);

    // What the webhook writes is the INTENT, never the charge id.
    rcPayment($order, 'pi_1008');

    rcFakeStripe([rcCharge('ch_1008', 'pi_1008', 25000)]);

    $runId = rcRun();

    // Matching on the charge id alone would have called this unrecorded money.
    expect(rcFindings($runId))->toHaveCount(0);
});

/* ============================================================== Q4: refunds */

it('finds a refund the provider made that is not recorded here', function () {
    rcStripe();

    $order = rcOrder('RC-1009', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1009');

    rcFakeStripe(
        [rcCharge('ch_1009', 'pi_1009', 25000)],
        [['id' => 're_1009', 'amount' => 5000, 'currency' => 'aed', 'status' => 'succeeded', 'payment_intent' => 'pi_1009', 'created' => now()->getTimestamp()]],
    );

    $runId = rcRun();

    $findings = rcFindings($runId, Reconciler::REFUND_NOT_RECORDED);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->remote_ref)->toBe('re_1009')
        ->and((int) $findings[0]->amount_remote)->toBe(5000)
        ->and($findings[0]->summary)->toContain('overstated')
        // A Stripe refund carries no order reference of any kind, so this had
        // to be reached through the PaymentIntent it reverses. Without it the
        // row reads "order —" and the owner has nothing to open, which is a
        // finding he cannot act on.
        ->and($findings[0]->order_number)->toBe('RC-1009');
});

it('does not let a refund inherit its payment\'s id as something it can be matched by', function () {
    rcStripe();

    $order = rcOrder('RC-1009b', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1009b');

    // A refund WE recorded whose provider_ref happens to be the intent. This is
    // not a shape the refunder writes, and that is the point: if the intent
    // were treated as one of the refund's own match keys, a refund Stripe never
    // made would be reported as confirmed. That is the one direction this
    // report must never get wrong.
    Refund::create([
        'order_id' => $order->id, 'amount' => 5000, 'provider' => 'stripe',
        'provider_ref' => 'pi_1009b', 'status' => 'succeeded',
    ]);

    rcFakeStripe(
        [rcCharge('ch_1009b', 'pi_1009b', 25000)],
        [['id' => 're_1009b', 'amount' => 5000, 'currency' => 'aed', 'status' => 'succeeded', 'payment_intent' => 'pi_1009b', 'created' => now()->getTimestamp()]],
    );

    $runId = rcRun();

    // Stripe's refund is not ours (different id), and ours is not confirmed by
    // Stripe. Both halves are reported; neither is silently matched.
    expect(rcFindings($runId, Reconciler::REFUND_NOT_RECORDED))->toHaveCount(1)
        ->and(rcFindings($runId, Reconciler::REFUND_NOT_CONFIRMED))->toHaveCount(1);
});

it('finds a refund recorded here that the provider never made', function () {
    rcStripe();

    $order = rcOrder('RC-1010', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1010');

    Refund::create([
        'order_id' => $order->id,
        'amount' => 5000,
        'provider' => 'stripe',
        'provider_ref' => 're_1010',
        'status' => 'succeeded',
    ]);

    rcFakeStripe([rcCharge('ch_1010', 'pi_1010', 25000)]);

    $runId = rcRun();

    expect(rcFindings($runId, Reconciler::REFUND_NOT_CONFIRMED))->toHaveCount(1);
});

it('does not report a hand-logged refund, which has no provider reference to match', function () {
    rcStripe();

    $order = rcOrder('RC-1011', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1011');

    // The shape every row written before the capture/refund migration has, and
    // the shape the old admin endpoint wrote: real money, moved by hand, with
    // nothing at the provider to be a discrepancy with.
    Refund::create([
        'order_id' => $order->id, 'amount' => 5000, 'provider' => 'stripe',
        'provider_ref' => null, 'status' => 'succeeded',
    ]);

    rcFakeStripe([rcCharge('ch_1011', 'pi_1011', 25000)]);

    expect(rcFindings(rcRun()))->toHaveCount(0);
});

/* ============================================= Q5: our own two books, and back */

it('finds an order marked paid with no payment row behind it', function () {
    rcStripe();
    rcFakeStripe([]);

    rcOrder('RC-1012', ['paid_at' => now(), 'status' => 'processing', 'payment_method' => 'tamara']);

    $runId = rcRun();

    $findings = rcFindings($runId, Reconciler::ORDER_PAID_NO_PAYMENT);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->order_number)->toBe('RC-1012')
        ->and($findings[0]->severity)->toBe('alarm');
});

it('finds money held at a provider for an order that was already cancelled', function () {
    rcStripe();
    rcFakeStripe([]);

    // Exactly what PaymentConfirmer::recordLate writes: a verified payment for
    // an order whose stock went back on the shelf. Somebody has to give it back
    // at the provider's end, and nothing in this shop will do it.
    $order = rcOrder('RC-1013', ['status' => 'cancelled']);
    rcPayment($order, 'pi_1013', ['status' => 'late_confirmation']);

    $runId = rcRun();

    $findings = rcFindings($runId, Reconciler::PAYMENT_NO_PAID_ORDER);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->summary)->toContain('already been cancelled');
});

it('does not mistake a cash-on-delivery capture for a payment with no paid order', function () {
    rcStripe();
    rcFakeStripe([]);

    // COD never sets paid_at — CashOnDelivery's own class comment says why —
    // and PaymentLedger writes a `capture` row for the collected cash. Counting
    // that as a claim on money would report every collected COD order as a
    // discrepancy.
    $order = rcOrder('RC-1014', [
        'payment_method' => 'cod', 'status' => 'completed',
        'captured_at' => now(), 'captured_total' => 25000,
    ]);

    rcPayment($order, 'cod:RC-1014', ['provider' => 'cod', 'status' => 'capture']);

    expect(rcFindings(rcRun()))->toHaveCount(0);
});

/* ======================================================== authorised, unTaken */

it('reports an authorisation nobody captured as its own, quieter thing', function () {
    rcStripe();

    $order = rcOrder('RC-1015', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1015');

    // Succeeded but not captured: a manual-capture intent Stripe will release.
    // Money committed, not money taken — calling it settled would send the
    // owner looking for funds that are not there.
    rcFakeStripe([rcCharge('ch_1015', 'pi_1015', 25000, ['captured' => false])]);

    $runId = rcRun();

    expect(rcFindings($runId, Reconciler::UNCAPTURED))->toHaveCount(1)
        ->and(rcFindings($runId, Reconciler::MONEY_NOT_RECORDED))->toHaveCount(0);

    expect(rcFindings($runId, Reconciler::UNCAPTURED)[0]->severity)->toBe('warn');
});

/* ================================================== idempotent and resumable */

it('reports the same thing twice and writes no duplicate findings', function () {
    rcStripe();
    rcOrder('RC-1016');
    rcFakeStripe([rcCharge('ch_1016', 'pi_1016', 25000, ['metadata' => ['order_number' => 'RC-1016']])]);

    $first = rcRun();
    $firstFindings = rcFindings($first);

    // Same window, same gateways: the run key is derived from both, so this
    // continues rather than starting a second run beside it.
    $second = rcRun();

    expect($second)->toBe($first)
        ->and(rcFindings($second))->toHaveCount(count($firstFindings))
        ->and(DB::table(Reconciler::RUNS)->count())->toBe(1);

    // And the sightings did not double either, which is what the unique key on
    // (run, provider, kind, remote_key) is there for.
    expect(DB::table(Reconciler::SIGHTINGS)->where('run_id', $first)->count())
        ->toBe(DB::table(Reconciler::SIGHTINGS)->where('run_id', $second)->count());
});

it('continues from where it stopped when a step is interrupted', function () {
    rcStripe();

    // Three pages of one charge each, so there are real boundaries to resume
    // at rather than a single page that either happened or did not.
    $pages = [
        'a' => ['data' => [rcCharge('ch_r1', 'pi_r1', 1000)], 'has_more' => true],
        'ch_r1' => ['data' => [rcCharge('ch_r2', 'pi_r2', 1000)], 'has_more' => true],
        'ch_r2' => ['data' => [rcCharge('ch_r3', 'pi_r3', 1000)], 'has_more' => false],
    ];

    Http::fake(function ($request) use ($pages) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (str_contains($request->url(), '/v1/refunds')) {
            return Http::response(['data' => [], 'has_more' => false]);
        }

        $key = $query['starting_after'] ?? 'a';

        return Http::response($pages[$key] ?? ['data' => [], 'has_more' => false]);
    });

    $reconciler = app(Reconciler::class);
    $window = ReconcileWindow::lastDays(7);
    $runId = $reconciler->open($window, ['stripe'], 'tester');

    // ONE step. Two pages is the per-step budget, so this stops mid-phase with
    // the third page unread — which is exactly the shape of a request the host
    // killed.
    $reconciler->step($runId);

    $checkpoint = DB::table(Reconciler::CHECKPOINTS)
        ->where('run_id', $runId)->where('phase', Reconciler::PHASE_REMOTE_PAYMENTS)->first();

    expect($checkpoint->finished_at)->toBeNull()
        // The cursor is the provider's own id, not a count of rows we think we
        // have seen. A count would drift; this cannot.
        ->and($checkpoint->cursor)->toBe('ch_r2')
        ->and((int) $checkpoint->processed)->toBe(2);

    // Resume. The third page is read, and the first two are not re-judged into
    // duplicate findings.
    for ($i = 0; $i < 200; $i++) {
        if (($reconciler->step($runId)['done'] ?? false) === true) {
            break;
        }
    }

    expect(rcFindings($runId, Reconciler::MONEY_NOT_RECORDED))->toHaveCount(3)
        ->and(DB::table(Reconciler::RUNS)->where('id', $runId)->value('status'))->toBe('complete');
});

it('starts over, findings and all, when the owner asks for a fresh look', function () {
    rcStripe();
    rcFakeStripe([rcCharge('ch_1017', 'pi_1017', 25000)]);

    $first = rcRun();

    expect(rcFindings($first))->toHaveCount(1);

    // He has fixed it: the payment is recorded now.
    $order = rcOrder('RC-1017', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1017');

    $second = rcRun(['stripe'], null, restart: true);

    expect($second)->toBe($first)
        ->and(rcFindings($second))->toHaveCount(0);
});

/* ===================================================== nothing leaks, nothing writes */

it('never lets a stored credential into a finding, however the provider phrases its refusal', function () {
    rcStripe();

    $order = rcOrder('RC-1018', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_1018');

    // A provider that echoes the key back inside its own error code. Stripe
    // does not do this; something will, and a redaction that only covers the
    // Authorization header is a redaction that only covers what somebody
    // thought of.
    Http::fake(['api.stripe.com/*' => Http::response([
        'error' => ['code' => 'key_' . RC_STRIPE_SECRET . '_revoked'],
    ], 401)]);

    $runId = rcRun();

    $all = DB::table(Reconciler::FINDINGS)->where('run_id', $runId)->get();

    expect($all)->not->toHaveCount(0);

    foreach ($all as $finding) {
        $blob = $finding->summary . '|' . (string) $finding->detail;

        expect($blob)->not->toContain(RC_STRIPE_SECRET);
    }

    // Redacted VISIBLY, not silently. A value that vanished reads as "no
    // credential was involved", which is the wrong lesson for anyone debugging
    // a 401.
    $source = rcFindings($runId, Reconciler::PAYMENTS_SOURCE_UNAVAILABLE)[0];

    expect($source->summary)->toContain('«secret_key as stored»');
});

it('writes to nothing but its own four tables', function () {
    rcStripe();

    // One of everything the run has an opinion about.
    $paid = rcOrder('RC-1019', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($paid, 'pi_1019');
    $unpaid = rcOrder('RC-1020');

    rcFakeStripe(
        [rcCharge('ch_1020', 'pi_1020', 25000, ['metadata' => ['order_number' => 'RC-1020']])],
        [['id' => 're_x', 'amount' => 100, 'currency' => 'aed', 'status' => 'succeeded', 'created' => now()->getTimestamp()]],
    );

    $before = [
        'orders' => DB::table('orders')->get()->toJson(),
        'payments' => DB::table('payments')->get()->toJson(),
        'refunds' => DB::table('refunds')->count(),
        'events' => DB::table('payment_events')->count(),
        'notes' => DB::table('order_notes')->count(),
    ];

    $runId = rcRun();

    // It found things, so this is not passing by doing nothing.
    expect(rcFindings($runId))->not->toHaveCount(0);

    expect(DB::table('orders')->get()->toJson())->toBe($before['orders'])
        ->and(DB::table('payments')->get()->toJson())->toBe($before['payments'])
        ->and(DB::table('refunds')->count())->toBe($before['refunds'])
        ->and(DB::table('payment_events')->count())->toBe($before['events'])
        // Not even a note. An order note is a write to the order screen, and
        // this run has no business changing what an operator sees there.
        ->and(DB::table('order_notes')->count())->toBe($before['notes']);

    $paid->refresh();
    $unpaid->refresh();

    expect($unpaid->paid_at)->toBeNull()->and($unpaid->status)->toBe('pending');
});

/* ================================================================= the window */

it('refuses a window longer than this host can finish', function () {
    expect(fn () => ReconcileWindow::between('2024-01-01', '2026-01-01'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ReconcileWindow::between('2026-09-30', '2026-09-01'))
        ->toThrow(InvalidArgumentException::class);

    // One day, typed as the same date twice, is one day and not zero.
    expect(ReconcileWindow::between('2026-09-01', '2026-09-01')->days())->toBe(1);
});

it('gives a local row hours of grace at the boundary rather than a midnight finding', function () {
    $window = ReconcileWindow::between('2026-09-10', '2026-09-10');

    // The window sent to a provider is the day itself...
    expect($window->from->toDateTimeString())->toBe('2026-09-10 00:00:00')
        ->and($window->to->toDateTimeString())->toBe('2026-09-10 23:59:59')
        // ...and the window local rows are matched in is wider, so an
        // authorisation at 23:58 confirmed at 00:03 does not become two
        // findings about one transaction.
        ->and($window->localFrom()->toDateTimeString())->toBe('2026-09-09 12:00:00')
        ->and($window->localTo()->toDateTimeString())->toBe('2026-09-11 11:59:59');
});

it('does not invent an unconfirmed payment for a row that sits in the grace margin', function () {
    rcStripe();

    // Yesterday's window. The payment below is recorded hours AFTER the end of
    // it — inside the grace, outside the period Stripe was asked about.
    $window = ReconcileWindow::between(
        now('UTC')->subDay()->toDateString(),
        now('UTC')->subDay()->toDateString(),
    );

    $order = rcOrder('RC-GRACE', ['paid_at' => now(), 'status' => 'processing']);
    rcPayment($order, 'pi_grace', ['created_at' => $window->to->addHours(2)]);

    rcFakeStripe([]);

    $runId = rcRun(['stripe'], $window);

    /*
     * Stripe could not have listed it — it was created after the period the
     * query covered — so its absence says nothing, and saying something anyway
     * would put one false alarm on this screen for every midnight the shop
     * takes money over. The grace is for MATCHING, not for concluding.
     */
    expect(rcFindings($runId, Reconciler::MONEY_NOT_CONFIRMED))->toHaveCount(0);
});

/* =================================================================== Tabby */

it('asks Tabby for its payments with a date filter, an offset and the merchant code', function () {
    rcTabby();

    Http::fake(['api.tabby.ai/*' => Http::response(['payments' => []])]);

    rcRun(['tabby']);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.tabby.ai/api/v2/payments?')
            && $request->hasHeader('Authorization', 'Bearer ' . RC_TABBY_SECRET)
            // Per-country header, which is not the Authorization one and is
            // exactly why redaction here is by value rather than by name.
            && $request->hasHeader('X-Merchant-Code', 'AE')
            // RFC3339 with an explicit Z, because both sides are UTC.
            && str_ends_with((string) ($query['created_at__gte'] ?? ''), 'Z')
            && (int) ($query['limit'] ?? 0) === 100
            && (string) ($query['offset'] ?? '') === '0';
    });
});

it('reads Tabby refunds out of the payments they belong to, because Tabby keeps them there', function () {
    rcTabby();

    $order = Order::create([
        'order_number' => 'RC-TB-1', 'email' => 'buyer@example.com', 'status' => 'processing',
        'currency' => 'AED', 'subtotal' => 25000, 'total' => 25000,
        'payment_method' => 'tabby', 'paid_at' => now(),
    ]);

    Payment::create([
        'order_id' => $order->id, 'provider' => 'tabby', 'provider_ref' => 'pay_tb_1',
        'amount' => 25000, 'currency' => 'AED', 'status' => 'paid',
    ]);

    Http::fake(['api.tabby.ai/*' => Http::response(['payments' => [[
        'id' => 'pay_tb_1',
        'status' => 'CLOSED',
        // Major-unit decimal STRING on this API. 250.10 through a float cast
        // would be 25009 fils; through round() it is 25010.
        'amount' => '250.00',
        'currency' => 'AED',
        'created_at' => now()->toIso8601ZuluString(),
        'order' => ['reference_id' => 'RC-TB-1'],
        'refunds' => [['id' => 'ref_tb_1', 'amount' => '50.10', 'created_at' => now()->toIso8601ZuluString()]],
    ]]])]);

    $runId = rcRun(['tabby']);

    // The payment matched; only the refund is outstanding.
    $findings = rcFindings($runId, Reconciler::REFUND_NOT_RECORDED);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->remote_ref)->toBe('ref_tb_1')
        // 50.10 in fils. Not 5009.
        ->and((int) $findings[0]->amount_remote)->toBe(5010)
        ->and(rcFindings($runId, Reconciler::MONEY_NOT_RECORDED))->toHaveCount(0);
});

it('treats a Tabby authorisation as money not yet taken, not as money taken', function () {
    rcTabby();

    Http::fake(['api.tabby.ai/*' => Http::response(['payments' => [[
        'id' => 'pay_tb_2', 'status' => 'AUTHORIZED', 'amount' => '250.00', 'currency' => 'AED',
        'order' => ['reference_id' => 'RC-TB-2'], 'created_at' => now()->toIso8601ZuluString(),
    ]]])]);

    $runId = rcRun(['tabby']);

    expect(rcFindings($runId, Reconciler::UNCAPTURED))->toHaveCount(1)
        ->and(rcFindings($runId, Reconciler::MONEY_NOT_RECORDED))->toHaveCount(0);
});

/* ================================================================== the guard */

it('refuses every reconciliation endpoint to a caller who is not signed in', function () {
    // The mounting routes/payments-reconcile.php asks the integrator for. One
    // middleware() call, not two: RouteRegistrar::middleware() REPLACES the
    // attribute rather than appending, so a second call would silently drop
    // auth:admin and this test would pass against no guard at all.
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/payments-reconcile.php'));

    $this->getJson('/admin-api/payments/reconcile')->assertUnauthorized();
    $this->postJson('/admin-api/payments/reconcile/start', ['from' => '2026-09-01', 'to' => '2026-09-02'])
        ->assertUnauthorized();
    $this->postJson('/admin-api/payments/reconcile/step', ['run_id' => 1])->assertUnauthorized();
    $this->getJson('/admin-api/payments/reconcile/1')->assertUnauthorized();
    $this->getJson('/admin-api/payments/reconcile/1/findings')->assertUnauthorized();
    $this->getJson('/admin-api/payments/reconcile/cod?from=2026-09-01&to=2026-09-02')->assertUnauthorized();
    $this->postJson('/admin-api/payments/reconcile/1/findings/1/ack')->assertUnauthorized();

    expect(DB::table(Reconciler::RUNS)->count())->toBe(0);

    Http::assertNothingSent();
});

it('drives a whole run through the endpoints the screen uses', function () {
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/payments-reconcile.php'));

    $admin = AdminUser::create([
        'name' => 'Recon Owner',
        'email' => 'recon-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    rcStripe();
    rcOrder('RC-2001');
    rcFakeStripe([rcCharge('ch_2001', 'pi_2001', 25000, ['metadata' => ['order_number' => 'RC-2001']])]);

    $this->actingAs($admin, 'admin');

    // What can be asked, and what cannot.
    $index = $this->getJson('/admin-api/payments/reconcile')->assertOk()->json();

    $cod = collect($index['gateways'])->firstWhere('id', 'cod');

    expect($cod['reconcilable'])->toBeFalse()
        ->and($cod['why_not'])->toContain('separate report');

    $today = now('UTC')->toDateString();

    $start = $this->postJson('/admin-api/payments/reconcile/start', [
        'from' => $today, 'to' => $today, 'providers' => ['stripe'],
    ])->assertOk()->json();

    $runId = $start['run_id'];

    for ($i = 0; $i < 200; $i++) {
        if ($this->postJson('/admin-api/payments/reconcile/step', ['run_id' => $runId])->assertOk()->json('done')) {
            break;
        }
    }

    $report = $this->getJson('/admin-api/payments/reconcile/' . $runId . '/findings')->assertOk()->json();

    expect($report['total'])->toBe(1)
        ->and($report['findings'][0]['kind'])->toBe(Reconciler::MONEY_NOT_RECORDED)
        ->and($report['findings'][0]['order_number'])->toBe('RC-2001')
        ->and($report['findings'][0]['amount_remote'])->toBe(25000);

    // Acknowledging is not a repair: it hides the row from the default view and
    // records who hid it, and it moves nothing.
    $findingId = $report['findings'][0]['id'];

    $this->postJson('/admin-api/payments/reconcile/' . $runId . '/findings/' . $findingId . '/ack', [
        'note' => 'Refunded at Stripe by hand.',
    ])->assertOk();

    expect($this->getJson('/admin-api/payments/reconcile/' . $runId . '/findings')->json('total'))->toBe(0)
        ->and(DB::table(Reconciler::FINDINGS)->where('id', $findingId)->value('acknowledged_by'))
        ->toBe('Recon Owner');

    expect(Order::where('order_number', 'RC-2001')->value('paid_at'))->toBeNull();
});

it('refuses an unreadable window with the owner\'s own words rather than a bare 422', function () {
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/payments-reconcile.php'));

    $admin = AdminUser::create([
        'name' => 'Recon Owner',
        'email' => 'recon-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/payments/reconcile/start', ['from' => '2024-01-01', 'to' => '2026-01-01'])
        ->assertStatus(422)
        ->assertJsonPath('error', fn ($e) => str_contains((string) $e, 'shorter period'));
});
