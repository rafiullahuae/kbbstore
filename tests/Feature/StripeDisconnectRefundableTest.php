<?php

declare(strict_types=1);

/**
 * Lane EW — what a disconnect costs, said before it is pressed.
 *
 * ---------------------------------------------------------------------------
 * WHAT WAS WRONG
 * ---------------------------------------------------------------------------
 *
 * StripeConnect::disconnect() shows a confirm dialog built from inFlight(),
 * which counts orders with `paid_at IS NULL` — shoppers who may be on Stripe's
 * payment page right now. It had no notion of REFUNDABLE money at all.
 *
 * A fully captured AED 250.00 Stripe order has `paid_at` set, so inFlight()
 * did not count it: the dialog said `in_flight.count = 0`, nothing at stake,
 * press the button. Afterwards the refund comes back `not_configured` — no
 * key, no Stripe refund — and AED 250.00 of the buyer's money is unreturnable
 * through the panel.
 *
 * The structural half cannot be fixed: a refund needs a key. The WARNING is
 * the fixable half, and it is what is pinned here. Nothing blocks the
 * disconnect — a compromised key is a reason to do it this second.
 *
 * ---------------------------------------------------------------------------
 * AND IT IS PaymentRefunder'S OWN ARITHMETIC
 * ---------------------------------------------------------------------------
 *
 * What "captured" means changed underneath this screen: it is the sum of the
 * `paid` payment rows the provider confirmed, not `orders.total`, which an
 * operator can edit. A second opinion about a refund ceiling on the dialog
 * that confirms a refund ceiling is how the two end up disagreeing, so the
 * cases below edit an order after payment and assert the warning does NOT move
 * with it.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Refund;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\PaymentRefunder;
use App\Services\Payments\StripeConnect;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();
});

function ewsConnected(): void
{
    $row = PaymentProvider::create([
        'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 3,
    ]);

    $row->config = [
        'publishable_key' => 'pk_test_ew',
        'secret_key' => 'sk_test_EWCANARY0001',
        'webhook_signing_secret' => 'whsec_ew',
        'webhook_secret' => 'url-secret-ew-0001',
        // No endpoint id, so disconnect() reaches Stripe for nothing at all
        // and the case stays about the figures rather than about HTTP.
    ];

    $row->save();

    app(GatewayCredentials::class)->forget();
}

/**
 * A Stripe order the provider confirmed, and captured through this lane.
 *
 * `paid_at` set is the whole point: it is precisely the shape inFlight()
 * cannot see, and precisely the shape that holds money.
 */
function ewsCapturedOrder(int $fils = 25000, array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'EWS-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => $fils,
        'total' => $fils,
        'payment_method' => 'stripe',
        'transaction_id' => 'pi_ews_' . uniqid(),
        'paid_at' => now(),
    ], $overrides));

    Payment::create([
        'order_id' => $order->id, 'provider' => 'stripe', 'provider_ref' => (string) $order->transaction_id,
        'amount' => $fils, 'currency' => 'AED', 'status' => 'paid',
    ]);

    return $order;
}

/*
|------------------------------------------------------------------------------
| 1. The bug: money the dialog could not see
|------------------------------------------------------------------------------
*/

it('counts a fully captured order that in_flight cannot see', function () {
    ewsConnected();

    $order = ewsCapturedOrder(25000);

    $status = app(StripeConnect::class)->status();

    // The old dialog's only number, and it is still zero — correctly. This is
    // the whole reason the warning was missing.
    expect($status['in_flight']['count'])->toBe(0)
        ->and($status['refundable']['count'])->toBe(1)
        ->and($status['refundable']['amount'])->toBe(25000)
        ->and($status['refundable']['amount_display'])->toBe(\App\Support\Money::plain(25000))
        ->and($status['refundable']['orders'][0]['order_number'])->toBe((string) $order->order_number);
});

it('says on the disconnect what has just become unrefundable', function () {
    ewsConnected();

    ewsCapturedOrder(25000);

    $result = app(StripeConnect::class)->disconnect();

    expect($result['ok'])->toBeTrue()
        ->and($result['refundable']['count'])->toBe(1)
        ->and($result['refundable']['amount'])->toBe(25000);

    // The payments screen alerts `warnings` verbatim, so this reaches the
    // owner today with no change to a view.
    $said = implode("\n", $result['warnings']);

    expect($said)->toContain(\App\Support\Money::plain(25000))
        ->and($said)->toContain('1 Stripe order');
});

it('does not block the disconnect over refundable money', function () {
    // A compromised key is a reason to disconnect this second. The owner is
    // told what it costs; he is not argued with.
    ewsConnected();

    ewsCapturedOrder(25000);

    $result = app(StripeConnect::class)->disconnect();

    expect($result['ok'])->toBeTrue()
        ->and($result['steps'])->toContain('credentials_cleared')
        ->and(app(GatewayCredentials::class)->get('stripe', 'secret_key'))->toBe('');
});

/*
|------------------------------------------------------------------------------
| 2. It is the refunder's arithmetic, not a second opinion
|------------------------------------------------------------------------------
*/

it('does not follow orders.total when an operator edits a paid order', function () {
    /*
     * The trap the adversarial lane has just closed inside PaymentRefunder,
     * arriving here from the other side. `processing` is editable, so a paid
     * order's `total` moves in both directions; the ceiling does not, and
     * neither may this warning.
     */
    ewsConnected();

    $order = ewsCapturedOrder(25000);

    // Edited UP: the shop still only took 250.00.
    $order->forceFill(['total' => 40000])->save();

    expect(app(StripeConnect::class)->status()['refundable']['amount'])->toBe(25000);

    // Edited DOWN: the shop still holds 250.00 of the buyer's money, and that
    // is what it can no longer give back.
    $order->forceFill(['total' => 10000])->save();

    expect(app(StripeConnect::class)->status()['refundable']['amount'])->toBe(25000)
        ->and(app(PaymentRefunder::class)->capturedFils($order->fresh()))->toBe(25000);
});

it('counts a refund in flight as money already spoken for', function () {
    ewsConnected();

    $order = ewsCapturedOrder(25000);

    // `pending` is in PaymentRefunder::COUNTED. A refund on its way to the
    // provider is not still refundable.
    Refund::create([
        'order_id' => $order->id, 'amount' => 10000, 'status' => 'pending',
        'reason' => 'partial', 'refunded_by' => 'Admin',
    ]);

    expect(app(StripeConnect::class)->status()['refundable']['amount'])->toBe(15000);
});

it('drops an order once everything taken has gone back', function () {
    ewsConnected();

    $order = ewsCapturedOrder(25000);

    Refund::create([
        'order_id' => $order->id, 'amount' => 25000, 'status' => 'succeeded',
        'reason' => 'full', 'refunded_by' => 'Admin',
    ]);

    $refundable = app(StripeConnect::class)->status()['refundable'];

    expect($refundable['count'])->toBe(0)
        ->and($refundable['amount'])->toBe(0)
        ->and($refundable['orders'])->toBe([]);
});

it('ignores a payment row the provider refused', function () {
    // An `amount_mismatch` row records money that never arrived. Summing it
    // would build the warning out of a payment the shop does not hold.
    ewsConnected();

    $order = Order::create([
        'order_number' => 'EWS-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 25000,
        'total' => 25000,
        'payment_method' => 'stripe',
        'transaction_id' => 'pi_refused',
    ]);

    Payment::create([
        'order_id' => $order->id, 'provider' => 'stripe', 'provider_ref' => 'pi_refused',
        'amount' => 25000, 'currency' => 'AED', 'status' => 'amount_mismatch',
    ]);

    expect(app(StripeConnect::class)->status()['refundable']['count'])->toBe(0);
});

/*
|------------------------------------------------------------------------------
| 3. What is counted, and what must not be
|------------------------------------------------------------------------------
*/

it('counts an old order, which is the one thing it must not copy from in_flight', function () {
    /*
     * inFlight() is bounded to three days and is right to be: a Checkout
     * session expires inside one, and older rows are abandoned carts rather
     * than pending money. Copying that bound here would make the warning a lie
     * in the direction that costs the owner money — an order from March the
     * shop still holds 250.00 for is exactly as unrefundable after a
     * disconnect as one from this morning.
     */
    ewsConnected();

    ewsCapturedOrder(25000, ['created_at' => now()->subMonths(6), 'paid_at' => now()->subMonths(6)]);

    expect(app(StripeConnect::class)->status()['refundable']['count'])->toBe(1);
});

it('leaves another gateway s money out of the Stripe warning', function () {
    ewsConnected();

    ewsCapturedOrder(25000);

    $tabby = ewsCapturedOrder(90000, ['payment_method' => 'tabby']);
    Payment::where('order_id', $tabby->id)->update(['provider' => 'tabby']);

    expect(app(StripeConnect::class)->status()['refundable']['amount'])->toBe(25000);
});

it('counts a trashed order, whose money is still the buyer s', function () {
    ewsConnected();

    $order = ewsCapturedOrder(25000);
    $order->delete();

    expect($order->fresh()->trashed())->toBeTrue()
        ->and(app(StripeConnect::class)->status()['refundable']['count'])->toBe(1);
});

it('sums across orders and names the oldest few', function () {
    ewsConnected();

    $made = [];

    for ($i = 0; $i < 12; $i++) {
        $made[] = ewsCapturedOrder(1000);
    }

    $refundable = app(StripeConnect::class)->status()['refundable'];

    expect($refundable['count'])->toBe(12, 'The count must be over everything; only the list is cut.')
        ->and($refundable['amount'])->toBe(12000)
        ->and($refundable['orders'])->toHaveCount(10)
        // Oldest first, and said out loud because the order is imposed by
        // chunkById rather than chosen in a clause somebody could delete. An
        // order the shop has stopped thinking about is the one this dialog
        // exists to put in front of the owner.
        ->and($refundable['orders'][0]['order_number'])->toBe((string) $made[0]->order_number);
});

it('says nothing when the shop is holding nothing', function () {
    ewsConnected();

    $result = app(StripeConnect::class)->disconnect();

    expect($result['refundable']['count'])->toBe(0)
        ->and(implode("\n", $result['warnings']))->not->toContain('refundable');
});

/*
|------------------------------------------------------------------------------
| 4. Through the endpoint the screen actually calls
|------------------------------------------------------------------------------
*/

it('puts the figure on the connect status endpoint', function () {
    ewsConnected();

    ewsCapturedOrder(25000);

    $admin = AdminUser::create([
        'name' => 'EW Admin', 'email' => 'ews-' . uniqid() . '@example.test',
        'password' => 'password-long-enough', 'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/payments/stripe/connect/status')
        ->assertOk()
        ->assertJsonPath('refundable.count', 1)
        ->assertJsonPath('refundable.amount', 25000)
        ->assertJsonPath('in_flight.count', 0);
});
