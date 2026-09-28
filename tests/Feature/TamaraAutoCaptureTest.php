<?php

/**
 * Tamara auto-capture — the money that never arrives, and the switch that is off.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Tamara AUTHORISES at checkout and pays nothing. PaymentConfirmer writes
 * `paid_at` for that authorisation, so the order reads PAID on every screen in
 * the console while not one fil has moved; the money moves when somebody
 * captures, and Tamara voids an authorisation nobody captured after about 180
 * days. An order that ships and is never captured is one this shop gave the
 * goods away for, and the evidence is an absence — no failed payment, no error,
 * no unhappy customer.
 *
 * App\Services\Payments\TamaraCaptureSweep is the job that closes that. This
 * file is what proves it does not do anything worse in the process:
 *
 *   1. IT IS ASLEEP ON EVERY INSTALL. Whether capture follows fulfilment is a
 *      commercial decision and it is the owner's; applying this package captures
 *      nothing and calls Tamara not once. Cases 1–3.
 *   2. IT CANNOT DOUBLE-CAPTURE. Not because this sweep is careful but because
 *      PaymentCapturer claims `captured_at` in one conditional UPDATE before the
 *      provider is called. Cases 4–6, and case 6 is the one that matters: it
 *      takes the claim away from the sweep's own cheap guard and proves the
 *      correctness lives in the claim.
 *   3. IT ONLY EVER CAPTURES WHAT IT IS ALLOWED TO. Fulfilled, authorised, not
 *      released, inside the window. Cases 7–10.
 *   4. IT HANDS PaymentCapturer A WHOLE MODEL. Case 11, which is the shape
 *      PaymentRefunder::gatewayPosition() was found in this round — an explicit
 *      select() that left `voided_at` out, so a correct rule read NULL and was
 *      silently inert. That defect cannot be reintroduced here without this
 *      going red.
 *   5. IT TELLS NO CUSTOMER ANYTHING. Case 12.
 *
 * Http::preventStrayRequests() is on throughout, which is what makes "this path
 * makes NO call to Tamara" a real assertion rather than a hopeful one.
 *
 * MUTATION NOTES are on every case that pins a defect: each says what to change
 * back to turn the case red, so a reader can prove the assertion asserts
 * something.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TamaraGateway;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\TamaraCaptureSweep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

const TAC_API_TOKEN = 'TAC_TAMARA_API_TOKEN_CANARY';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();
});

/* ───────────────────────────────────────────────────────────────── fixtures */

/**
 * Tamara configured. `$config` is merged LAST so a case can set `auto_capture`.
 *
 * Note what is NOT in the defaults: `auto_capture`. Every case that does not ask
 * for it therefore runs against the config blob a real install carries, which is
 * the whole of case 1.
 */
function tacProvider(array $config = []): void
{
    $row = PaymentProvider::create([
        'id' => 'tamara',
        'title' => 'Tamara',
        'enabled' => true,
        'mode' => 'test',
        'position' => 2,
    ]);

    $row->config = array_merge([
        'api_token' => TAC_API_TOKEN,
        'notification_token' => 'tac-notification-token',
        'webhook_secret' => 'whsec-tamara-tac-0123456789ab',
    ], $config);

    $row->save();

    app(GatewayCredentials::class)->forget();
}

function tacGateway(): TamaraGateway
{
    /** @var TamaraGateway $gateway */
    $gateway = app(GatewayRegistry::class)->find('tamara');

    return $gateway;
}

function tacSweep(): TamaraCaptureSweep
{
    return app(TamaraCaptureSweep::class);
}

/**
 * A fulfilled, authorised, uncaptured Tamara order — the exact row this sweep
 * exists for.
 *
 * `updated_at` is pushed back an hour on every fixture, because the sweep's
 * grace period is measured on it and a row created a millisecond ago is younger
 * than the default thirty minutes. Written with a query-builder update rather
 * than on create(), because Eloquent stamps `updated_at` itself on save.
 */
function tacOrder(array $attributes = [], int $unit = 5000, int $qty = 2): Order
{
    $itemsFils = $unit * $qty;

    $order = Order::create(array_merge([
        'order_number' => 'TAC-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'shipped',
        'currency' => 'AED',
        'subtotal' => $itemsFils,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'fee_total' => 0,
        'total' => $itemsFils,
        'payment_method' => 'tamara',
        'paid_at' => now()->subDays(3),
        'transaction_id' => 'tam_' . uniqid(),
    ], $attributes));

    $order->items()->create([
        'name' => 'Line 0',
        'sku' => 'SKU-0',
        'quantity' => $qty,
        'unit_price' => $unit,
        'subtotal' => $itemsFils,
        'total' => $itemsFils,
    ]);

    Order::query()->whereKey($order->getKey())->update(['updated_at' => now()->subHour()]);

    return $order->fresh();
}

/** Tamara answering "authorised" on the read, then accepting the capture. */
function tacFakeCapture(Order $order, string $captureId = 'cap_tac'): void
{
    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => (string) $order->transaction_id,
            'order_reference_id' => (string) $order->order_number,
            'status' => 'authorised',
        ]),
        '*/payments/capture' => Http::response(['capture_id' => $captureId]),
    ]);
}

/** How many times a path containing $needle was requested. */
function tacCalls(string $needle): int
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), $needle))
        ->count();
}

/* ══════════════════════════════════════ 1. off until the owner says yes ═════ */

it('captures nothing and calls tamara not once while the switch is off', function () {
    /*
     * RULE 1, AND THE WHOLE COMMERCIAL POINT.
     *
     * Applying the package that adds this sweep must move no money. The order
     * below is the perfect candidate — Tamara, shipped, authorised, uncaptured,
     * three days old — and a shop that has just applied the update has a config
     * blob with no `auto_capture` key in it at all. Nothing may happen.
     *
     * Http::preventStrayRequests() makes the "no call" half real: if run() got as
     * far as the gateway this would fail on an unfaked request rather than pass
     * quietly.
     *
     * MUTATION: delete the `if (! $gateway->autoCapture())` block from
     * TamaraCaptureSweep::run(). Red — `ran` becomes true and the request is
     * refused as stray.
     */
    tacProvider();
    $order = tacOrder();

    $report = tacSweep()->run();

    expect($report['ran'])->toBeFalse()
        ->and($report['captured'])->toBe(0)
        ->and($report['examined'])->toBe(0)
        ->and($report['reason'])->toContain('Store → Payments → Tamara')
        ->and($order->fresh()->captured_at)->toBeNull()
        ->and(Http::recorded())->toHaveCount(0);
});

it('reads a missing, empty or not-quite-yes switch as off', function () {
    /*
     * The flag that decides whether this shop takes money without a human has to
     * fail closed on anything it cannot positively recognise as "yes". The
     * payments screen writes '1' for On and '' for Off; the other four values
     * below are what a hand-edited blob, an older build or a JSON `false` leaves
     * behind.
     *
     * MUTATION: make autoCapture() `!== ''` instead of `=== '1'` and the '0',
     * 'false' and 'off' rows go red — which is the shape that would have this
     * shop capturing automatically because somebody typed the word "off".
     */
    foreach ([null, '', '0', 'false', 'off', 'yes'] as $stored) {
        PaymentProvider::query()->delete();
        app(GatewayCredentials::class)->forget();

        tacProvider($stored === null ? [] : ['auto_capture' => $stored]);

        expect(tacGateway()->autoCapture())->toBeFalse("stored: " . var_export($stored, true));
    }

    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    tacProvider(['auto_capture' => '1']);

    expect(tacGateway()->autoCapture())->toBeTrue();
});

it('offers the switch on the payments screen as a bool in the settings column', function () {
    /*
     * The control has to be REACHABLE, and reachable without editing
     * resources/views/admin/app.blade.php — which this lane may not touch. The
     * screen renders `secret`, `bool`, and then everything else as a text input,
     * so `bool` is the one declaration that paints the On/Off select and stores
     * '1'/''. Declaring, say, `select` here would paint a text box and lie about
     * it in configSchema(), which is the defect the `payment_type` field's own
     * comment records.
     *
     * `settings`, not `keys`: this is a decision THIS SHOP makes, not a value
     * Tamara issues — the line PaymentGateway::configSchema() draws, and
     * PaymentsFieldGroupsTest fails a schema that leaves the group out.
     *
     * MUTATION: change the type to 'text' and this is red; so is moving it to
     * 'keys'.
     */
    $schema = tacGateway()->configSchema();

    expect($schema)->toHaveKey('auto_capture');

    [$type, $label, $help, $group] = $schema['auto_capture'];

    expect($type)->toBe('bool')
        ->and($group)->toBe('settings')
        ->and($label)->not->toBe('');

    /*
     * ── THE PIN WAS ADVANCED, NOT DELETED, AND HERE IS WHY ──────────────────
     *
     * This read `toContain('off by default')`, and the reasoning beside it was
     * right: the help has to say what the default is, because that sentence is
     * the only reason the owner can trust an update not to have moved money
     * before he read this screen.
     *
     * On 28 September 2026 he answered the question this lane deliberately left
     * him — *"yes"* — so the default is ON and the migration
     * 2027_03_21_000000_tamara_auto_capture_on_by_default writes it. The old
     * needle then pinned a sentence that would have been FALSE. Deleting the
     * assertion would have thrown away the rule with the needle; keeping it
     * would have kept a help text that lied.
     *
     * So the RULE is what is pinned, and it is pinned harder than before: the
     * help must state the default AND say how to stop it, because a switch that
     * takes money on its own and does not say how to turn it off is worse than
     * one that never moved.
     */
    $lower = strtolower($help);

    expect(str_starts_with($lower, 'on.'))
        ->toBeTrue('the help no longer opens by saying what the default is');

    expect(str_contains($lower, 'set this to off'))
        ->toBeTrue('the help does not tell the owner how to stop it capturing on its own');
});

/* ══════════════════════════════════════════ 2. switched on, it captures ═════ */

it('captures a shipped tamara order once the owner turns it on', function () {
    /*
     * The happy path, and the figures that have to be exactly right afterwards:
     * `captured_at` set, `captured_total` the order total IN FILS, and
     * `capture_ref` Tamara's own capture id — which is what a later refund has
     * to be pointed at, so a null there is a refund that cannot be made.
     */
    tacProvider(['auto_capture' => '1']);
    $order = tacOrder();
    tacFakeCapture($order, 'cap_happy');

    $report = tacSweep()->run(by: 'scheduled capture');

    expect($report['ran'])->toBeTrue()
        ->and($report['examined'])->toBe(1)
        ->and($report['captured'])->toBe(1)
        ->and($report['already'])->toBe(0)
        ->and($report['failed'])->toBe(0)
        // Integer fils, summed as an int. 10000 and not 100.0.
        ->and($report['captured_fils'])->toBe(10000)
        ->and($report['captured_fils'])->toBeInt();

    $fresh = $order->fresh();

    expect($fresh->captured_at)->not->toBeNull()
        ->and($fresh->captured_total)->toBe(10000)
        ->and($fresh->capture_ref)->toBe('cap_happy')
        ->and(tacCalls('/payments/capture'))->toBe(1);

    // The trail is on the order, not only in the report the cron log ate.
    expect($fresh->notes()->where('content', 'like', 'Captured%')->count())->toBe(1);
});

it('reports a capture tamara refused without marking the order captured', function () {
    /*
     * An order left marked captured after a failed call is the exact lie
     * PaymentCapturer exists to stop telling, and on this path nobody is
     * watching: a cron run that recorded a capture that did not happen is money
     * the owner believes he has and has not.
     *
     * MUTATION: delete the "release the claim" update in PaymentCapturer's
     * failure branch. Red on `captured_at`, and the order would never be a
     * candidate again.
     */
    tacProvider(['auto_capture' => '1']);
    $order = tacOrder();

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => (string) $order->transaction_id,
            'order_reference_id' => (string) $order->order_number,
            'status' => 'authorised',
        ]),
        '*/payments/capture' => Http::response(['error_code' => 'server_error'], 500),
    ]);

    $report = tacSweep()->run();

    expect($report['ran'])->toBeTrue()
        ->and($report['examined'])->toBe(1)
        ->and($report['captured'])->toBe(0)
        ->and($report['failed'])->toBe(1)
        ->and($report['captured_fils'])->toBe(0);

    $fresh = $order->fresh();

    // `captured_total` is 0 on an uncaptured order, not null — the column carries
    // a default. What matters is that the failed attempt did not move it.
    expect($fresh->captured_at)->toBeNull()
        ->and((int) $fresh->captured_total)->toBe(0)
        ->and($fresh->capture_ref)->toBeNull();

    /*
     * AND IT IS STILL A CANDIDATE, so the next run retries it — a failure that
     * quietly removed an order from the sweep would be the same lost money one
     * layer along.
     *
     * NOT IMMEDIATELY, THOUGH, AND THAT IS WORTH WRITING DOWN. Releasing the
     * claim is a write to the row, so `updated_at` is now, so the grace period
     * measured on it holds the retry back for half an hour. That is the proxy
     * being conservative in the direction it is allowed to be conservative in —
     * later, never sooner — and against an hourly cron and a 180-day window it
     * costs one tick. Both halves are asserted so a later reader is not
     * surprised by either.
     */
    expect(tacSweep()->candidates())->toHaveCount(0)
        ->and(tacSweep()->candidates(minutes: 0)->pluck('id')->all())->toBe([$order->id]);
});

/* ═══════════════════════════════════════════ 3. it cannot double-capture ════ */

it('captures once when the sweep runs twice', function () {
    /*
     * Cron overlapping itself, a slow run, or an operator running the command by
     * hand while the schedule fires. Two runs must be one capture at Tamara —
     * two captures are the customer charged twice for one order.
     *
     * MUTATION: drop `->whereNull('captured_at')` from PaymentCapturer's claim
     * UPDATE and the second run sends a second capture. Red on the call count.
     */
    tacProvider(['auto_capture' => '1']);
    $order = tacOrder();
    tacFakeCapture($order, 'cap_once');

    $first = tacSweep()->run();
    $second = tacSweep()->run();

    expect($first['captured'])->toBe(1)
        // Second run: not even examined, because the candidate query asks for
        // `captured_at IS NULL`. That is the cheap guard; the next case proves
        // the correctness does not depend on it.
        ->and($second['examined'])->toBe(0)
        ->and($second['captured'])->toBe(0)
        ->and(tacCalls('/payments/capture'))->toBe(1);

    expect($order->fresh()->captured_total)->toBe(10000);
});

it('captures once when the capture button has already taken the money', function () {
    /*
     * The other collision, and the likelier one: an operator presses Capture on
     * the order screen at 09:00:03 and the schedule fires at 09:00:00. Whichever
     * lands first, the money is taken once.
     */
    tacProvider(['auto_capture' => '1']);
    $order = tacOrder();
    tacFakeCapture($order, 'cap_button');

    $button = app(PaymentCapturer::class)->capture($order, 'Admin');

    expect($button->ok)->toBeTrue()
        ->and($button->code)->toBe('captured');

    $report = tacSweep()->run();

    expect($report['examined'])->toBe(0)
        ->and(tacCalls('/payments/capture'))->toBe(1);
});

it('sends no second capture when another caller wins the claim mid-run', function () {
    /*
     * THE CASE THAT MATTERS, AND THE ONLY ONE THAT TESTS THE REAL GUARD.
     *
     * The two cases above are answered by the candidate query — the order was
     * already captured when the SELECT ran, so it was never fetched. That is a
     * cost saving, not correctness: between the sweep's SELECT and its capture
     * of the fiftieth order in the batch, minutes of HTTP can pass, and the
     * Capture button can be pressed inside them.
     *
     * So this case reproduces exactly that: the candidate is FETCHED while it is
     * capturable, another caller then wins `captured_at`, and only then is the
     * fetched model handed to PaymentCapturer. The claim is a single conditional
     * UPDATE against a null timestamp, so it matches zero rows, and the answer is
     * `already_captured` with NO call to Tamara. Http::preventStrayRequests()
     * plus a fake with no `/payments/capture` route is what makes "no call" an
     * assertion: a second capture would fail as a stray request.
     *
     * MUTATION: drop `->whereNull('captured_at')` from PaymentCapturer's claim.
     * Red — the claim succeeds, a capture is sent, and the stray-request guard
     * fires.
     */
    tacProvider(['auto_capture' => '1']);
    $order = tacOrder();

    Http::fake([
        // The status read is allowed; a capture call is not faked at all, so one
        // would be refused as stray.
        '*/merchants/orders/*' => Http::response([
            'order_id' => (string) $order->transaction_id,
            'order_reference_id' => (string) $order->order_number,
            'status' => 'authorised',
        ]),
    ]);

    // Fetched while capturable — this is the model the sweep's loop is holding.
    $candidates = tacSweep()->candidates();

    expect($candidates->pluck('id')->all())->toBe([$order->id]);

    // Somebody else captures in the gap. Written straight onto the row, which is
    // what PaymentCapturer's own claim does.
    Order::query()->whereKey($order->getKey())->update([
        'captured_at' => now(),
        'captured_total' => 10000,
        'capture_ref' => 'cap_theirs',
    ]);

    $result = app(PaymentCapturer::class)->capture($candidates->first(), 'scheduled capture');

    expect($result->ok)->toBeTrue()
        ->and($result->code)->toBe('already_captured')
        ->and($result->reference)->toBe('cap_theirs')
        ->and(tacCalls('/payments/capture'))->toBe(0);

    // And the winner's figures are untouched: the loser did not overwrite them.
    expect($order->fresh()->captured_total)->toBe(10000);
});

/* ═════════════════════════════════ 4. only what it is allowed to capture ════ */

it('leaves an order nobody has shipped alone', function () {
    /*
     * FULFILMENT IS THE WHOLE LICENCE. A `processing` order has been packed by
     * nobody; capturing it charges a customer for something still on the shelf,
     * and does it with no human in the loop, which is what makes it worse than
     * the same mistake made by hand.
     *
     * `onhold` and `pending` are here for the same reason, and `processing` is
     * the one that matters: it is in Order::REAL_STATUSES, so a sweep that had
     * reused that constant — the obvious thing to reach for — would capture it.
     *
     * MUTATION: swap `self::FULFILLED` for `Order::REAL_STATUSES` in
     * candidates(). Red on `processing` and `onhold`.
     */
    tacProvider(['auto_capture' => '1']);

    foreach (['pending', 'processing', 'onhold', 'draft', 'cancelled', 'refunded', 'failed'] as $status) {
        tacOrder(['status' => $status]);
    }

    expect(tacSweep()->candidates())->toHaveCount(0);

    Http::fake();
    expect(tacSweep()->run()['examined'])->toBe(0);
});

it('captures both statuses that mean the goods have gone', function () {
    tacProvider(['auto_capture' => '1']);

    $shipped = tacOrder(['status' => 'shipped']);
    $completed = tacOrder(['status' => 'completed']);

    expect(tacSweep()->candidates()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$shipped->id, $completed->id])->sort()->values()->all());
});

it('leaves a released authorisation alone even after somebody revived the order', function () {
    /*
     * THE SIBLING OF THE DEFECT FIXED IN PaymentRefunder THIS ROUND.
     *
     * PaymentVoider releases the hold at Tamara and records `voided_at`, and
     * nothing recreates it — start() would have to send the shopper through
     * Tamara's checkout again and they have gone. OrderStatus deliberately
     * permits a revive out of `cancelled`, so an operator can put such an order
     * back to `shipped`, and the row then reads: Tamara, shipped, `paid_at` set,
     * `captured_at` null. Every condition this sweep looks for, and there is no
     * authorisation left to capture.
     *
     * Both halves are asserted, because the cheap guard and the correct one are
     * in different files: the order must not be a CANDIDATE, and PaymentCapturer
     * must refuse it if a hand-written caller hands it over anyway.
     *
     * MUTATION: drop `->whereNull('voided_at')` from candidates() and the first
     * expectation is red. Drop the `$order->voided_at !== null` refusal from
     * PaymentCapturer::capture() and the second is.
     */
    tacProvider(['auto_capture' => '1']);

    $order = tacOrder(['status' => 'shipped', 'voided_at' => now()->subDay()]);

    expect(tacSweep()->candidates())->toHaveCount(0);

    Http::fake();

    $result = app(PaymentCapturer::class)->capture($order, 'scheduled capture');

    expect($result->ok)->toBeFalse()
        ->and($result->code)->toBe('authorisation_released')
        ->and($order->fresh()->captured_at)->toBeNull()
        ->and(Http::recorded())->toHaveCount(0);
});

it('leaves an order that was never authorised alone', function () {
    /*
     * `paid_at` IS the authorisation. Without it there is no hold to capture,
     * and PaymentCapturer refuses it as `not_authorised` — so including it in
     * the batch would spend a round trip per order to be told so.
     */
    tacProvider(['auto_capture' => '1']);

    tacOrder(['paid_at' => null]);

    expect(tacSweep()->candidates())->toHaveCount(0);
});

it('does not let long-expired authorisations starve the ones it can still capture', function () {
    /*
     * A REAL DEFECT SHAPE, NOT A TIDINESS RULE, and it comes out of two correct
     * decisions meeting: the batch is OLDEST FIRST, so a capped run works on the
     * orders closest to being voided, and the batch is LIMITED, so one cron tick
     * cannot become four hundred round trips to a rate-limited API.
     *
     * Put those together with no window bound and an install carrying a tail of
     * long-expired authorisations spends every run's whole limit asking Tamara
     * about orders it voided months ago. The orders that can still be captured
     * sit behind them until they expire too — the sweep runs hourly, reports
     * failures hourly, and never once reaches the money it exists to collect.
     *
     * MUTATION: delete the `where('paid_at', '>=', ...)` bound from candidates()
     * and this is red — the 200-day-old order takes the single slot.
     */
    tacProvider(['auto_capture' => '1']);

    $expired = tacOrder(['paid_at' => now()->subDays(200)]);
    $live = tacOrder(['paid_at' => now()->subDays(5)]);

    $candidates = tacSweep()->candidates(limit: 1);

    expect($candidates->pluck('id')->all())->toBe([$live->id])
        ->and($candidates->pluck('id')->all())->not->toContain($expired->id);

    // And the window follows the gateway's own `capture_days`, so the number
    // that warns the operator on the order screen and the number that bounds
    // this scan cannot disagree.
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    tacProvider(['auto_capture' => '1', 'capture_days' => '365']);

    expect(tacSweep()->candidates()->pluck('id')->all())->toBe([$expired->id, $live->id]);
});

it('gives an operator time to undo a status they typed by mistake', function () {
    /*
     * Somebody marks the wrong order Shipped and notices within a minute. A
     * capture that lands inside that minute is a customer charged for goods on
     * the shelf — refundable, and a worse thing to do to a buyer than waiting
     * half an hour.
     *
     * The grace period is measured on `updated_at`, which is a PROXY: there is
     * no column recording when an order became `shipped`. The honest thing to
     * assert is which way the proxy is wrong, and it is this — ANY edit bumps
     * `updated_at`, so an order being worked on waits LONGER and an order nobody
     * has touched waits exactly the grace period. It can never bring a capture
     * forward, which is the only direction a proxy about somebody's money is
     * allowed to be wrong in.
     *
     * MUTATION: delete the `where('updated_at', '<=', ...)` bound and the first
     * expectation is red.
     */
    tacProvider(['auto_capture' => '1']);

    $order = tacOrder();
    Order::query()->whereKey($order->getKey())->update(['updated_at' => now()]);

    expect(tacSweep()->candidates())->toHaveCount(0);

    // Explicitly asking for no grace finds it, which is what `--minutes=0` is
    // for on a one-off catch-up.
    expect(tacSweep()->candidates(minutes: 0)->pluck('id')->all())->toBe([$order->id]);
});

it('leaves every other gateway out of a tamara sweep', function () {
    /*
     * THE ONE THAT WOULD HAVE BEEN WORST. PaymentCapturer is gateway-agnostic, so
     * a sweep written over `Order::REAL_STATUSES` with no `payment_method` bound
     * would have captured cash-on-delivery orders — and a COD capture writes
     * `captured_total`, which is the ceiling PaymentRefunder::capturedFils()
     * measures a refund against. Every shipped COD order would have become
     * refundable for its full value against cash nobody collected.
     *
     * That is also why the switch is on the GATEWAY and not in `settings`: there
     * is no shop-wide "auto-capture" flag to turn on by accident.
     *
     * MUTATION: drop `->where('payment_method', 'tamara')` from candidates(). Red.
     */
    tacProvider(['auto_capture' => '1']);

    tacOrder(['payment_method' => 'cod']);
    tacOrder(['payment_method' => 'tabby']);
    tacOrder(['payment_method' => 'stripe']);

    expect(tacSweep()->candidates())->toHaveCount(0);
});

it('leaves a trashed order out of the batch', function () {
    tacProvider(['auto_capture' => '1']);

    $order = tacOrder();
    $order->delete();

    expect(tacSweep()->candidates())->toHaveCount(0);
});

/* ═══════════════════════ 5. the column that must not go missing ═════════════ */

it('hands the capturer a whole order and not a short select list', function () {
    /*
     * THE SHAPE THAT WAS FOUND IN PaymentRefunder THIS ROUND, PINNED SO IT
     * CANNOT BE REINTRODUCED HERE.
     *
     * `capturedFils()` was taught that a released authorisation is not
     * refundable money, and the rule was right. The SECOND call site named its
     * columns explicitly and left `voided_at` out of the list — and AN ATTRIBUTE
     * THAT WAS NEVER SELECTED READS NULL WITH NO ERROR, so on that path the new
     * rule was silently inert. Nothing raised, nothing logged, and the only
     * symptom was a number on a screen.
     *
     * Every column below is read off the model by PaymentCapturer or by
     * TamaraGateway::capture(), and the most dangerous one to lose is
     * `voided_at`: its absence does not break the sweep, it disarms the
     * refusal that stops this shop capturing a hold it cancelled itself.
     *
     * getAttributes() is the right instrument rather than a null check, because
     * the whole defect is that a MISSING attribute and a NULL one are
     * indistinguishable when you read them with `->`. A key that is absent from
     * the array is a column the query did not ask for.
     *
     * MUTATION: add `->select(['id', 'order_number', 'status', 'total',
     * 'payment_method', 'paid_at', 'captured_at'])` to candidates() — the
     * plausible short list, and the same omission as the defect — and this is
     * red on `voided_at`, `currency`, `transaction_id` and the rest.
     */
    tacProvider(['auto_capture' => '1']);
    $order = tacOrder();

    $candidate = tacSweep()->candidates()->first();

    expect($candidate)->not->toBeNull();

    $attributes = $candidate->getAttributes();

    $reads = [
        // PaymentCapturer::capture() and ::status()
        'status', 'payment_method', 'total', 'currency', 'paid_at', 'captured_at',
        'voided_at', 'captured_total', 'capture_ref', 'deleted_at',
        // TamaraGateway::capture() — the provider read, the reference guard,
        // the shipping block and the amount identity.
        'transaction_id', 'order_number', 'shipping_method',
        'shipping_total', 'discount_total', 'tax_total',
    ];

    /*
     * Reported as a LIST of what is missing rather than one assertion per
     * column, so a short select list fails once and names every column it left
     * out — which is the information the next reader needs, and is what a
     * per-column loop would hide behind whichever column happened to be first.
     */
    $missing = array_values(array_filter(
        $reads,
        fn (string $column): bool => ! array_key_exists($column, $attributes),
    ));

    expect($missing)->toBe([]);

    // And the line items are on the model already: capture() sends them to
    // Tamara on every call, so a lazy load here would be one query per order.
    expect($candidate->relationLoaded('items'))->toBeTrue()
        ->and($candidate->items)->toHaveCount(1);
});

/* ═══════════════════════════════════ 6. it tells no customer anything ══════ */

it('tells the customer nothing when it captures', function () {
    /*
     * CAPTURE IS NOT A STATUS CHANGE AND MUST NOT BECOME ONE.
     *
     * OrderMailObserver mails the customer when `orders.status` changes, and
     * nothing else on an order mails them. PaymentCapturer writes `captured_at`,
     * `captured_total` and `capture_ref` through the QUERY BUILDER, which fires
     * no model events at all, and it moves no status — so a capture is silent by
     * construction.
     *
     * Worth pinning rather than assuming: this is the one path in the
     * application where money moves with nobody watching, and a shop that mailed
     * every buyer "your payment has been taken" thirty minutes after dispatch
     * would be a wording change nobody asked for, sent hourly, from cron.
     *
     * MUTATION: make PaymentCapturer write its success columns with
     * `$order->update([...])` instead of the query builder AND move the status —
     * red. (The query-builder write alone stays green, correctly: no observer
     * fires and no status moves.)
     */
    Mail::fake();

    tacProvider(['auto_capture' => '1']);
    $order = tacOrder();
    tacFakeCapture($order);

    $before = (string) $order->status;

    expect(tacSweep()->run()['captured'])->toBe(1);

    Mail::assertNothingOutgoing();

    // And the status is exactly where the operator left it.
    expect((string) $order->fresh()->status)->toBe($before);
});

/* ════════════════════════════════════════════════ 7. the dry run is honest ══ */

it('lists what it would capture without asking tamara anything, switch or no switch', function () {
    /*
     * A SWITCH WHOSE CONSEQUENCES CAN ONLY BE DISCOVERED BY TURNING IT ON IS A
     * SWITCH NOBODY SHOULD BE ASKED TO TURN ON.
     *
     * `candidates()` is deliberately NOT gated on `auto_capture` — it is one
     * SELECT and no writes — so `payments:tamara-capture --dry` answers "what
     * would this do on my shop" for an owner who has not decided yet. The
     * command's dry branch calls this same method rather than a second copy of
     * the query written for printing: two copies drift, and the direction they
     * drift in is a dry run that reassures somebody about a list the real sweep
     * does not use.
     *
     * MUTATION: move the autoCapture() guard from run() into candidates() and
     * this is red — and the owner loses the only way to look before he leaps.
     */
    tacProvider();   // switch OFF
    $order = tacOrder();

    expect(tacSweep()->candidates()->pluck('id')->all())->toBe([$order->id])
        ->and(Http::recorded())->toHaveCount(0);

    $this->artisan('payments:tamara-capture --dry')
        ->expectsOutputToContain('1 order(s) would be captured')
        ->expectsOutputToContain((string) $order->order_number)
        ->assertExitCode(0);

    expect($order->fresh()->captured_at)->toBeNull()
        ->and(Http::recorded())->toHaveCount(0);
});

it('exits zero and says why when the switch is off', function () {
    /*
     * A cron entry that exited non-zero on the shipped default would mail the
     * owner an alert every hour about a feature he has not switched on. And the
     * reason has to name the screen: "it did nothing" with no reason is the
     * report that gets ignored, which is how a sweep that is silently broken
     * goes unnoticed for a month.
     */
    tacProvider();
    tacOrder();

    $this->artisan('payments:tamara-capture')
        ->expectsOutputToContain('Store → Payments → Tamara')
        ->assertExitCode(0);

    expect(Http::recorded())->toHaveCount(0);
});

it('exits non-zero when money it should have taken did not move', function () {
    /*
     * "I could not take the money" is not "all clear". This is the one thing on
     * this path worth waking somebody for: the goods have shipped.
     *
     * MUTATION: return SUCCESS unconditionally from the command and this is red.
     */
    tacProvider(['auto_capture' => '1']);
    $order = tacOrder();

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => (string) $order->transaction_id,
            'order_reference_id' => (string) $order->order_number,
            'status' => 'authorised',
        ]),
        '*/payments/capture' => Http::response(['error_code' => 'server_error'], 500),
    ]);

    $this->artisan('payments:tamara-capture')->assertExitCode(1);
});
