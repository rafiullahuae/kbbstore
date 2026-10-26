<?php

declare(strict_types=1);

/**
 * Tabby auto-capture — the authorisation that never turns into money.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * TamaraCaptureSweep's sibling, and this file is TamaraAutoCaptureTest's. Tabby
 * AUTHORISES at checkout and pays nothing; PaymentConfirmer writes `paid_at`, so
 * the order reads PAID while no fil has moved, and Tabby voids an uncaptured
 * authorisation after about 30 days — until the capture the order does not count
 * in the payout, which is the loss the owner reported. TabbyCaptureSweep closes
 * it, and this file proves it does nothing worse on the way:
 *
 *   1. It is ASLEEP while the switch is off — captures nothing, calls Tabby not
 *      once (cases 1–2). The switch is a bool in the settings column (case 3).
 *   2. It captures on AUTHORISATION, not fulfilment — a `processing` order, the
 *      moment it is live, which is the owner's policy and the one real
 *      difference from Tamara (cases 4, 8).
 *   3. It cannot double-capture, because PaymentCapturer claims `captured_at`
 *      first (cases 6–7), and records a refusal without marking the order
 *      captured (case 5).
 *   4. It only ever captures what it is allowed to — authorised, live, not
 *      released, inside the window, Tabby only, not trashed (cases 9–13).
 *   5. It hands PaymentCapturer a whole model (case 14), tells no customer
 *      anything (case 15), and `--dry` lists without asking Tabby (case 16).
 *
 * Http::preventStrayRequests() is on throughout, which is what makes "this path
 * makes NO call to Tabby" a real assertion.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Gateways\TabbyGateway;
use App\Services\Payments\TabbyCaptureSweep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();
});

/* ───────────────────────────────────────────────────────────────── fixtures */

/** Tabby configured. `$config` is merged LAST so a case can set `auto_capture`. */
function tbcProvider(array $config = []): void
{
    $row = PaymentProvider::create([
        'id' => 'tabby',
        'title' => 'Tabby',
        'enabled' => true,
        'mode' => 'test',
        'position' => 1,
    ]);

    $row->config = array_merge([
        'public_key' => 'pk_test_tbc',
        'secret_key' => 'sk_test_tbc',
        'webhook_secret' => 'whsec-tabby-tbc-0123456789ab',
    ], $config);

    $row->save();

    app(GatewayCredentials::class)->forget();
}

function tbcGateway(): TabbyGateway
{
    /** @var TabbyGateway $gateway */
    $gateway = app(GatewayRegistry::class)->find('tabby');

    return $gateway;
}

function tbcSweep(): TabbyCaptureSweep
{
    return app(TabbyCaptureSweep::class);
}

/**
 * An authorised, live, uncaptured Tabby order — the row this sweep exists for.
 * `status` defaults to `processing`, which is where PaymentConfirmer leaves a
 * confirmed Tabby order: capture here follows authorisation, not shipping.
 */
function tbcOrder(array $attributes = [], int $unit = 5000, int $qty = 2): Order
{
    $itemsFils = $unit * $qty;

    $order = Order::create(array_merge([
        'order_number' => 'TBC-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => $itemsFils,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'fee_total' => 0,
        'total' => $itemsFils,
        'payment_method' => 'tabby',
        'paid_at' => now()->subDays(3),
        'transaction_id' => 'pay_' . uniqid(),
    ], $attributes));

    $order->items()->create([
        'name' => 'Line 0',
        'sku' => 'SKU-0',
        'quantity' => $qty,
        'unit_price' => $unit,
        'subtotal' => $itemsFils,
        'total' => $itemsFils,
    ]);

    return $order->fresh();
}

/** Tabby answering AUTHORIZED on the v2 read, then accepting the v1 capture. */
function tbcFakeCapture(string $captureId = 'cap_tbc'): void
{
    Http::fake([
        '*/api/v1/payments/*/captures' => Http::response(['captures' => [['id' => $captureId, 'amount' => '100.00']]]),
        '*/api/v2/payments/*' => Http::response(['status' => 'AUTHORIZED']),
    ]);
}

/** How many times a path containing $needle was requested. */
function tbcCalls(string $needle): int
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), $needle))
        ->count();
}

/* ══════════════════════════════════════════ 1. off until the owner says yes ═ */

it('captures nothing and calls tabby not once while the switch is off', function () {
    tbcProvider(['auto_capture' => '']);
    tbcOrder();

    // No Http::fake: preventStrayRequests turns any call into a failure, so the
    // assertion that nothing was captured is also the assertion that Tabby was
    // never reached.
    $report = tbcSweep()->run();

    expect($report['ran'])->toBeFalse()
        ->and($report['captured'])->toBe(0)
        ->and($report['examined'])->toBe(0)
        ->and(tbcCalls('/payments/'))->toBe(0);
});

it('reads a missing, empty or not-quite-yes switch as off', function () {
    foreach (['', '0', 'yes', 'true', 'on'] as $value) {
        PaymentProvider::query()->delete();
        app(GatewayCredentials::class)->forget();
        tbcProvider(['auto_capture' => $value]);

        expect(tbcGateway()->autoCapture())->toBeFalse("'{$value}' was read as on");
    }

    // And the absent key.
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    tbcProvider();

    expect(tbcGateway()->autoCapture())->toBeFalse('an absent key was read as on');
});

/* ══════════════════════════════════════════════ 3. the control is reachable ═ */

it('offers the switch on the payments screen as a bool in the settings column', function () {
    /*
     * The control must be reachable without editing app.blade.php: the screen
     * paints a `bool` as the On/Off select that stores '1'/''. `settings`, not
     * `keys` — this is the shop's decision, not a value Tabby issues.
     *
     * MUTATION: change the type to 'text' and this is red; so is moving it to
     * 'keys', or opening the help with anything but what the default is.
     */
    $schema = tbcGateway()->configSchema();

    expect($schema)->toHaveKey('auto_capture');

    [$type, $label, $help, $group] = $schema['auto_capture'];

    expect($type)->toBe('bool')
        ->and($group)->toBe('settings')
        ->and($label)->not->toBe('');

    $lower = strtolower($help);

    expect(str_starts_with($lower, 'on.'))->toBeTrue('the help no longer opens by saying what the default is');
    expect(str_contains($lower, 'set it off'))->toBeTrue('the help does not tell the owner how to stop it');
});

/* ══════════════════════════════════════════════ 2. switched on, it captures ═ */

it('captures a live (processing) tabby order once the owner turns it on', function () {
    tbcProvider(['auto_capture' => '1']);
    $order = tbcOrder(['status' => 'processing']);
    tbcFakeCapture();

    $report = tbcSweep()->run(by: 'scheduled capture');

    expect($report['ran'])->toBeTrue()
        ->and($report['captured'])->toBe(1)
        ->and($report['captured_fils'])->toBe((int) $order->total)
        ->and($report['failed'])->toBe(0);

    expect($order->fresh()->captured_at)->not->toBeNull();
    expect(tbcCalls('/captures'))->toBe(1);
});

it('captures every live status, not only the shipped ones', function () {
    /*
     * The one real difference from Tamara, pinned. Tamara captures on ship
     * (FULFILLED = shipped, completed); Tabby captures on authorisation, so a
     * `processing` and an `onhold` order are both candidates. This is the
     * owner's policy — WooCommerce captured Tabby at order placement.
     *
     * MUTATION: narrow CAPTURABLE to ['shipped', 'completed'] and this is red;
     * the first two orders drop out of candidates().
     */
    expect(TabbyCaptureSweep::CAPTURABLE)->toBe(['processing', 'onhold', 'shipped', 'completed']);

    tbcProvider(['auto_capture' => '1']);
    $ids = collect(['processing', 'onhold', 'shipped', 'completed'])
        ->map(fn ($s) => tbcOrder(['status' => $s])->id)
        ->sort()->values()->all();

    expect(tbcSweep()->candidates()->pluck('id')->sort()->values()->all())->toBe($ids);
});

/* ══════════════════════════════════════════ 5. a refusal is not a capture ══ */

it('reports a capture tabby refused without marking the order captured', function () {
    tbcProvider(['auto_capture' => '1']);
    $order = tbcOrder();

    // Tabby says the payment is no longer authorised: nothing to take.
    Http::fake([
        '*/api/v2/payments/*' => Http::response(['status' => 'REJECTED']),
    ]);

    $report = tbcSweep()->run();

    expect($report['captured'])->toBe(0)
        ->and($report['failed'])->toBe(1);

    expect($order->fresh()->captured_at)->toBeNull();
});

/* ══════════════════════════════════════════ 6–7. it cannot double-capture ══ */

it('captures once when the sweep runs twice', function () {
    tbcProvider(['auto_capture' => '1']);
    tbcOrder();
    tbcFakeCapture();

    $first = tbcSweep()->run();
    $second = tbcSweep()->run();

    expect($first['captured'])->toBe(1)
        ->and($second['captured'])->toBe(0)
        ->and($second['examined'])->toBe(0, 'the captured order was fetched a second time');
});

it('treats an order tabby already closed as already-captured, not a failure', function () {
    tbcProvider(['auto_capture' => '1']);
    $order = tbcOrder();

    // CLOSED with a real capture on it is how a retry after a timeout reads;
    // the gateway returns ok('already_captured') and the sweep buckets it apart
    // from a fresh capture.
    Http::fake([
        '*/api/v2/payments/*' => Http::response(['status' => 'CLOSED', 'captures' => [['id' => 'cap_old', 'amount' => '100.00']]]),
    ]);

    $report = tbcSweep()->run();

    expect($report['already'])->toBe(1)
        ->and($report['captured'])->toBe(0)
        ->and($report['failed'])->toBe(0);
    expect(tbcCalls('/captures'))->toBe(0);
});

/* ══════════════════════════════════════ 9–13. only what it is allowed to ══ */

it('leaves a released authorisation alone even after a revive', function () {
    /*
     * MUTATION: drop `->whereNull('voided_at')` from candidates() and this is
     * red — a released hold cannot be captured, whatever the order's status.
     */
    tbcProvider(['auto_capture' => '1']);
    tbcOrder(['status' => 'processing', 'voided_at' => now()->subDay()]);

    expect(tbcSweep()->candidates())->toHaveCount(0);
});

it('leaves an order that was never authorised alone', function () {
    tbcProvider(['auto_capture' => '1']);
    tbcOrder(['paid_at' => null]);

    expect(tbcSweep()->candidates())->toHaveCount(0);
});

it('leaves a cancelled, refunded, failed or draft order alone', function () {
    tbcProvider(['auto_capture' => '1']);
    foreach (['cancelled', 'refunded', 'failed', 'draft'] as $s) {
        tbcOrder(['status' => $s]);
    }

    expect(tbcSweep()->candidates())->toHaveCount(0);
});

it('does not let long-expired authorisations starve the ones it can still capture', function () {
    /*
     * MUTATION: delete the `where('paid_at', '>=', ...)` bound from candidates()
     * and the limit-1 run below returns the expired order instead of the live
     * one.
     */
    tbcProvider(['auto_capture' => '1']);
    $expired = tbcOrder(['paid_at' => now()->subDays(90)]);
    $live = tbcOrder(['paid_at' => now()->subDays(1)]);

    // Default 30-day window: the expired one is out entirely.
    expect(tbcSweep()->candidates()->pluck('id')->all())->toBe([$live->id]);
});

it('leaves every other gateway out of a tabby sweep', function () {
    /*
     * MUTATION: drop `->where('payment_method', 'tabby')` from candidates(). Red.
     */
    tbcProvider(['auto_capture' => '1']);
    tbcOrder(['payment_method' => 'tamara']);
    tbcOrder(['payment_method' => 'stripe']);

    expect(tbcSweep()->candidates())->toHaveCount(0);
});

it('leaves a trashed order out of the batch', function () {
    tbcProvider(['auto_capture' => '1']);
    $order = tbcOrder();
    $order->delete();

    expect(tbcSweep()->candidates())->toHaveCount(0);
});

/* ══════════════════════════════════════ 14–16. shape, silence, dry run ════ */

it('hands the capturer a whole order and not a short select list', function () {
    /*
     * PaymentCapturer and TabbyGateway::capture() read voided_at, paid_at,
     * captured_at, status, total, payment_method, currency, transaction_id and
     * the items off the model. An attribute never selected reads null with no
     * error — how a correct rule goes silently inert.
     */
    tbcProvider(['auto_capture' => '1']);
    tbcOrder();

    $candidate = tbcSweep()->candidates()->first();

    foreach (['voided_at', 'paid_at', 'captured_at', 'status', 'total', 'payment_method', 'currency', 'transaction_id'] as $attr) {
        expect(array_key_exists($attr, $candidate->getAttributes()))
            ->toBeTrue("candidates() dropped {$attr} from the select list");
    }
    expect($candidate->relationLoaded('items'))->toBeTrue('items were not eager-loaded');
});

it('tells the customer nothing when it captures', function () {
    Mail::fake();
    tbcProvider(['auto_capture' => '1']);
    tbcOrder();
    tbcFakeCapture();

    expect(tbcSweep()->run()['captured'])->toBe(1);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('lists what it would capture without asking tabby anything, switch or no switch', function () {
    /*
     * candidates() is deliberately NOT gated on auto_capture — it is one SELECT
     * and no writes, and `--dry` is how the owner sees what would be captured
     * before turning anything on.
     *
     * MUTATION: move the autoCapture() guard from run() into candidates() and
     * this is red (and so is --dry on a shop that has not decided).
     */
    tbcProvider(['auto_capture' => '']); // off
    tbcOrder();

    // No Http::fake: preventStrayRequests makes "asked Tabby nothing" real.
    expect(tbcSweep()->candidates())->toHaveCount(1)
        ->and(tbcCalls('/payments/'))->toBe(0);
});

it('holds a just-authorised order back only when minutes asks it to', function () {
    tbcProvider(['auto_capture' => '1']);
    $order = tbcOrder();
    // Make it look authorised a moment ago.
    Order::query()->whereKey($order->getKey())->update(['updated_at' => now()]);

    // Default (0 minutes) captures immediately — the owner's "automatically".
    expect(tbcSweep()->candidates()->pluck('id')->all())->toBe([$order->id]);

    // A caller that wants a grace period gets one.
    expect(tbcSweep()->candidates(minutes: 5))->toHaveCount(0);
});
