<?php

declare(strict_types=1);

use App\Models\Order;

/*
 * "TOTAL TO PAY" OVER A CARD ORDER THE SHOPPER HAD JUST PAID. (Lane SW, found
 * by Lane TY.)
 *
 * The card form opens the received page when its report to the shop
 * (POST /checkout/card/paid, which reads the intent back from Stripe) has
 * answered OR when a cap runs out, and Stripe's full-redirect 3-D Secure return
 * lands on it before the webhook. With a slow read-back the page rendered while
 * the order was still `pending`, so its facts read "TOTAL TO PAY AED 851" to a
 * shopper whose card had been charged -- reproduced in Chromium with the
 * read-back held 5 s (docs/lane-sw-shots/before-slow5000-390.png).
 *
 * Now the browser passes on what Stripe told it, in Stripe's own
 * `redirect_status`, and a pending card order with an intent reads
 * "Confirming payment" over its total. It is a word only: nothing is marked paid.
 *
 * MUTATION: in checkout-success.blade.php replace
 * `($confirmingPayment ?? false)` with `false` -> the first case reads
 * "Total to pay" again and goes red. In CheckoutController::success() drop the
 * `payment_method === 'stripe'` term -> the cash-on-delivery case goes red.
 */

function swOrder(array $attributes = []): Order
{
    static $seq = 0;
    $seq++;

    $address = ['first_name' => 'Aisha', 'last_name' => 'Khan', 'address_1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000'];

    return Order::create(array_merge([
        'order_number' => 'SWCARD'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 85100,
        'discount_total' => 0,
        'shipping_total' => 0,
        'fee_total' => 0,
        'tax_total' => 0,
        'gift_fee' => 0,
        'total' => 85100,
        'shipping_method' => 'Free delivery',
        'payment_method' => 'stripe',
        'payment_method_title' => 'Credit / Debit Card',
        'transaction_id' => 'pi_sw_'.$seq,
        'billing_address' => $address,
        'shipping_address' => $address,
    ], $attributes));
}

/** The label over the total the received page draws for this order. */
function swTotalLabel(Order $order, string $query = ''): string
{
    $html = (string) test()->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order='.$order->order_number.$query)
        ->assertOk()
        ->getContent();

    // The second fact is the total: its label, whichever of the three it is.
    expect(preg_match_all('#<div class="co-fact"><dt>([^<]*)</dt>#', $html, $m))->toBeGreaterThanOrEqual(2, 'no Total fact on the received page');

    return html_entity_decode($m[1][1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

it('says the card payment is being confirmed, not "to pay", while the report is still running', function (string $status) {
    $order = swOrder();

    expect(swTotalLabel($order, '&redirect_status='.$status))->toBe('Confirming payment');

    // A word, never a write: the order is still waiting for Stripe's answer.
    expect($order->fresh()->paid_at)->toBeNull()
        ->and($order->fresh()->status)->toBe('pending');
})->with(['succeeded', 'processing', 'requires_capture']);

it('says "Total paid" once the shop has recorded the payment, whatever the address says', function () {
    $order = swOrder(['status' => 'processing', 'paid_at' => now()]);

    expect(swTotalLabel($order, '&redirect_status=succeeded'))->toBe('Total paid');
});

it('keeps "Total to pay" wherever the money has not been reported as moving', function (array $attributes, string $query) {
    expect(swTotalLabel(swOrder($attributes), $query))->toBe('Total to pay');
})->with([
    'a plain visit, no status' => [[], ''],
    'cash on delivery, status forged' => [['payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery'], '&redirect_status=succeeded'],
    'a card order with no intent' => [['transaction_id' => null], '&redirect_status=succeeded'],
    'a status that is not a payment' => [[], '&redirect_status=requires_action_x'],
]);

it('opens the received page with Stripe\'s status, after the report or a 1.5 s cap', function () {
    /*
     * The browser half, pinned at its source; the walk in tools/sw-shots
     * (card.mjs) is the proof it reaches the page. MUTATION: put back
     * `ov.confirmed(handle.success_url, report)` -> red.
     */
    $card = (string) file_get_contents(resource_path('views/partials/checkout/stripe-elements.blade.php'));
    $wallets = (string) file_get_contents(resource_path('views/partials/checkout/express-wallets.blade.php'));
    $overlay = (string) file_get_contents(resource_path('views/partials/checkout/placing-overlay.blade.php'));

    expect($card)->toContain('var landing = withStatus(handle.success_url, intent.status);')
        ->and($card)->toContain('ov.confirmed(landing, report)')
        ->and($card)->toContain('window.location.assign(landing);')
        ->and($card)->not->toContain('ov.confirmed(handle.success_url, report)')
        ->and($wallets)->toContain("lu.searchParams.set('redirect_status', String(intent.status || ''));")
        ->and($wallets)->toContain('ov.confirmed(landing, report)')
        ->and($overlay)->toContain('var REPORT_MS = 1500;');
});
