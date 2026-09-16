<?php

/**
 * Does the email say something TRUE about the order it describes? — Lane BS.
 *
 * OrderEmailsTest already proves the emails are SENT, that the switches switch,
 * and that the figures are the order's own integers. This file is about a
 * different failure: an email that is delivered, well formed, correctly
 * addressed, and wrong about a fact.
 *
 * Three of those were found and are pinned here. Each one is a sentence a
 * customer would have read and believed:
 *
 *   1. THE RECEIPT PRINTED NO VAT AT ALL. The checkout page shows an
 *      inclusive-VAT line, and the formal tax invoice carries VAT and the TRN,
 *      but the confirmation email — the document the customer actually keeps —
 *      showed neither. It could not: the row was gated on `tax_total`, which
 *      this store deliberately always writes 0 (decision D-64, VAT is a display
 *      line and is never charged or stored). So the gate was permanently shut.
 *
 *   2. EVERY DISPATCH EMAIL QUOTED A UAE DELIVERY WINDOW. The store ships to
 *      five other Gulf countries (see ShippingSeeder), and a customer in Riyadh
 *      was told in writing that "delivery in the UAE normally takes one to three
 *      working days" about a parcel that was never going to the UAE.
 *
 *   3. EVERY REFUND EMAIL PROMISED A CARD REFUND. Cash on delivery is this
 *      store's ordinary payment method, it has no refund API, and
 *      PaymentRefunder settles those refunds `recorded_only` — meaning a human
 *      has to hand the money back. The email told the customer it was already
 *      "sent back to the payment method you used" and would "appear on a card
 *      statement within five to ten working days". There is no card and nothing
 *      had been sent.
 *
 * All three are pinned in BOTH parts, HTML and text, because the two render
 * separate templates and a fix applied to one of them is half a fix.
 *
 * Pest note: `toContain` reads a second argument as another needle, not as a
 * failure message, so every assertion that wants to explain itself is written
 * `expect(str_contains(...))->toBeTrue('...')`.
 */

use App\Mail\OrderConfirmation;
use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\SettingsService;
use App\Support\VatDisplay;
use Illuminate\Support\Facades\Mail;

/**
 * Settings are memoised in PROCESS-LEVEL STATICS as well as in the cache, which
 * CLAUDE.md names as a trap for exactly this situation: RefreshDatabase rolls
 * the row back at the end of a test, and the static remembers it anyway. One
 * test here switches `vat_enabled` off, and without the afterEach below that
 * `false` would outlive its own test and be read by whatever ran next — in this
 * suite or in another file entirely.
 */
beforeEach(function () {
    PaymentProvider::query()->delete();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/* ------------------------------------------------------------- fixtures -- */

/**
 * An order on the books, without going through checkout.
 *
 * The default is the shape this store sells most: one line, UAE delivery, cash
 * on delivery with the surcharge. AED 235.00 in fils, and the rows add up to it.
 */
function truthOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'KBB-TRUTH-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 1500,
        'gift_fee' => 0,
        'tax_total' => 0,
        'total' => 23500,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner',
        'brand' => 'Haruharu',
        'sku' => 'HH-RT-150',
        'quantity' => 1,
        'unit_price' => 20000,
        'subtotal' => 20000,
        'total' => 20000,
    ]);

    return $order->fresh('items');
}

/** The HTML part of the one message of this type that was sent. */
function truthHtml(string $class): string
{
    $body = '';

    Mail::assertSent($class, function ($mail) use (&$body) {
        $body = (string) $mail->render();

        return true;
    });

    return $body;
}

/**
 * The plain-text part of the one message of this type that was sent.
 *
 * Rendered directly from the mailable's own view data, because
 * Mailable::render() returns the HTML part and these defects live in both.
 */
function truthText(string $class, string $view): string
{
    $text = '';

    Mail::assertSent($class, function ($mail) use (&$text, $view) {
        $text = (string) view($view, $mail->buildViewData())->render();

        return true;
    });

    return $text;
}

/* ------------------------------------------------------------------ VAT -- */

it('prints on the receipt the same inclusive-VAT line the checkout printed', function () {
    Mail::fake();

    $order = truthOrder();

    Mail::to((string) $order->email)->send(new OrderConfirmation($order));

    $vat = app(VatDisplay::class);
    $amount = $vat->amount((int) $order->total);

    // The fixture has to be one where VAT is actually visible, or this test
    // would pass by saying nothing.
    expect($amount)->toBeGreaterThan(0);

    $html = truthHtml(OrderConfirmation::class);
    $text = truthText(OrderConfirmation::class, 'emails.order-confirmation-text');

    // e(), because the default label is "You're paying VAT (5%)" and Blade's
    // {{ }} escapes the apostrophe — which is exactly what an HTML body should
    // do to it. The text part below wants the unescaped original.
    expect(str_contains($html, e($vat->label())))
        ->toBeTrue('the confirmation email prints no VAT line, though the checkout page showed one');

    expect(str_contains($html, OrderEmailPresenter::html($amount)))
        ->toBeTrue('the confirmation email does not print the VAT figure the checkout computed');

    expect(str_contains($text, $vat->label()))
        ->toBeTrue('the plain-text receipt prints no VAT line');

    expect(str_contains($text, OrderEmailPresenter::plain($amount)))
        ->toBeTrue('the plain-text receipt does not print the VAT figure');
});

it('leaves tax_total at zero and the total unchanged when it prints VAT', function () {
    Mail::fake();

    $order = truthOrder();

    Mail::to((string) $order->email)->send(new OrderConfirmation($order));

    // The whole point of D-64: the line is INFORMATION about the total, never an
    // addition to it and never a stored figure. A VAT row that changed either
    // would change what every other total in this application means.
    $order->refresh();

    expect((int) $order->tax_total)->toBe(0)
        ->and((int) $order->total)->toBe(23500);

    $presented = (new OrderEmailPresenter)->present($order);

    $sum = 0;
    foreach ($presented['totals'] as $row) {
        if ($row['strong']) {
            continue;
        }
        $sum += (int) $row['fils'];
    }

    expect($sum)->toBe(23500);
});

it('prints the VAT figure the emailed invoice prints, to the fil', function () {
    Mail::fake();

    $order = truthOrder();

    // The invoice is the formal tax document and it already carried VAT. If the
    // receipt in the customer's inbox and the invoice they can ask for disagree
    // about the tax on one order, one of them is wrong and the customer cannot
    // tell which.
    $doc = app(\App\Services\Invoices\InvoiceDocument::class)->present($order);
    $presented = (new OrderEmailPresenter)->present($order);

    expect($doc['vatNote'])->not->toBeNull()
        ->and($presented['vatNote'])->not->toBeNull()
        ->and((int) $presented['vatNote']['fils'])->toBe((int) $doc['vatNote']['fils']);
});

it('says nothing about VAT when the owner has switched the line off', function () {
    Mail::fake();

    app(SettingsService::class)->set('vat_enabled', false);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $order = truthOrder();

    Mail::to((string) $order->email)->send(new OrderConfirmation($order));

    $html = truthHtml(OrderConfirmation::class);

    // Searched for as a word rather than as a class name: a rendered email
    // inlines its own CSS, and a class-name search matches that too.
    expect(str_contains($html, 'VAT'))
        ->toBeFalse('the receipt still talks about VAT after the owner switched the line off');
});

it('restates an imported order\'s real tax rather than computing a second figure', function () {
    Mail::fake();

    // An order imported from WooCommerce carrying tax that WAS charged and IS
    // inside the total. That is a real row, and a computed "of which VAT" note
    // beside it would be a second, different tax number on one receipt.
    $order = truthOrder(['tax_total' => 1000]);

    $presented = (new OrderEmailPresenter)->present($order);

    $labels = array_column($presented['totals'], 'label');

    expect(in_array('VAT', $labels, true))->toBeTrue('the real tax row is missing')
        ->and($presented['vatNote'])->toBeNull();
});

/* -------------------------------------------------- Gulf dispatch note -- */

it('does not promise a UAE delivery time to a customer in another Gulf country', function () {
    Mail::fake();

    // Saudi Arabia is in the Gulf Countries zone this store has shipped to from
    // the start — see database/seeders/ShippingSeeder.php.
    $order = truthOrder([
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '9 King Fahd Rd', 'city' => 'Riyadh', 'country' => 'SA'],
        'shipping_method' => 'Shipping Charges',
        'shipping_total' => 15000,
        'fee_total' => 0,
        'total' => 35000,
    ]);

    $order->update(['status' => 'shipped']);

    $html = truthHtml(OrderStatusChanged::class);
    $text = truthText(OrderStatusChanged::class, 'emails.order-status-text');

    // The promise itself, not a phrase that happens to sit near it: the UAE
    // window is the thing that cannot be said about a parcel going to Riyadh.
    expect(str_contains($html, 'one to three working days'))
        ->toBeFalse('a parcel going to Riyadh was promised the UAE delivery window');

    expect(str_contains($html, 'Delivery in the UAE'))
        ->toBeFalse('a parcel going to Riyadh was described with a UAE delivery time');

    expect(str_contains($text, 'one to three working days'))
        ->toBeFalse('the plain-text dispatch note promises a Gulf order the UAE delivery window');

    // It still has to say the parcel has gone, or the email says nothing.
    expect(str_contains($html, 'with the courier'))
        ->toBeTrue('the dispatch email no longer says the order has left');
});

it('still gives a UAE customer the UAE delivery time', function () {
    Mail::fake();

    $order = truthOrder();
    $order->update(['status' => 'shipped']);

    $html = truthHtml(OrderStatusChanged::class);

    expect(str_contains($html, 'one to three working days'))
        ->toBeTrue('the UAE delivery window was dropped from a UAE order as well');
});

it('gives no delivery estimate at all when the order does not say where it is going', function () {
    Mail::fake();

    // An import or a half-filled manual order. Saying nothing about timing is
    // the only sentence that is certainly true.
    $order = truthOrder([
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai'],
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai'],
    ]);

    $order->update(['status' => 'shipped']);

    $html = truthHtml(OrderStatusChanged::class);

    expect(str_contains($html, 'one to three working days'))
        ->toBeFalse('an order with no country on it was given the UAE delivery window')
        ->and(str_contains($html, 'outside the UAE'))
        ->toBeFalse('an order with no country on it was declared an export');
});

/* ----------------------------------------------- cash-on-delivery refund -- */

it('does not tell a cash customer their refund is on its way to a card', function () {
    Mail::fake();

    // The ordinary shape: cash on delivery, money collected at the door, then
    // part of it returned. PaymentRefunder settles this `recorded_only` — the
    // gateway has no refund API, so a person has to hand the money back.
    $order = truthOrder(['paid_at' => now()]);

    $refund = $order->refunds()->create([
        'amount' => 5000,
        'status' => 'pending',
        'provider' => 'cod',
        'refunded_by' => 'Admin',
    ]);

    // Exactly what PaymentRefunder::settle() writes for a `recorded_only`
    // result: succeeded, and no provider reference, because no provider was
    // asked. CashOnDelivery::refund() returns null for the reference.
    $refund->forceFill(['status' => 'succeeded', 'provider_ref' => null])->save();

    $html = truthHtml(OrderRefunded::class);
    $text = truthText(OrderRefunded::class, 'emails.order-refunded-text');

    expect(str_contains($html, 'card statement'))
        ->toBeFalse('a cash-on-delivery customer was told to watch their card statement');

    expect(str_contains($html, 'back to the payment method you used'))
        ->toBeFalse('a cash-on-delivery refund was described as already sent back to a payment method');

    expect(str_contains($text, 'card statement'))
        ->toBeFalse('the plain-text refund note tells a cash customer to watch their card statement');

    // And it still says the two things that ARE true: how much, and which order.
    expect(str_contains($html, OrderEmailPresenter::html(5000)))->toBeTrue('the refunded amount is missing')
        ->and(str_contains($html, (string) $order->order_number))->toBeTrue('the order number is missing');
});

/* ------------------------------------------------- one event, one email -- */

it('sends one refund email when the provider reference arrives afterwards', function () {
    Mail::fake();

    // THE PRODUCTION DUPLICATE, PINNED. OrderMailObserver's header records it:
    // a single `Refund::saved` listener guarded by `! $refund->wasRecentlyCreated
    // && ! $refund->wasChanged('status')` could not work, because Eloquent never
    // clears wasRecentlyCreated for the life of the instance. Any later save of
    // an already-succeeded refund — the provider reference landing, a retry
    // stamping a field — mailed the customer a second "we have sent your money
    // back" for one refund. The split listeners fixed it; nothing was holding
    // the fix down, so this is that nail.
    $order = truthOrder(['paid_at' => now()]);

    $refund = $order->refunds()->create([
        'amount' => 5000,
        'status' => 'pending',
        'provider' => 'stripe',
        'refunded_by' => 'Admin',
    ]);

    $refund->forceFill(['status' => 'succeeded', 'provider_ref' => 're_3Qtest'])->save();

    // The second save PaymentRefunder's callers make, touching everything but
    // the status.
    $refund->forceFill(['reason' => 'Damaged on arrival'])->save();
    $refund->forceFill(['refunded_by' => 'Aisha'])->save();

    Mail::assertSent(OrderRefunded::class, 1);
});

it('sends one dispatch email when the same status is written twice', function () {
    Mail::fake();

    $order = truthOrder();

    $order->update(['status' => 'shipped']);
    // Re-saving the same value: wasChanged('status') is false, so nothing fires.
    // An operator pressing "mark as dispatched" twice must not tell the customer
    // their order left twice.
    $order->update(['status' => 'shipped']);

    Mail::assertSent(OrderStatusChanged::class, 1);
});

it('still promises a card refund when the gateway really did send one', function () {
    Mail::fake();

    // A gateway that settles through an API. The sentence about a card
    // statement is true here and must survive.
    $order = truthOrder([
        'payment_method' => 'stripe',
        'payment_method_title' => 'Card',
        'paid_at' => now(),
    ]);

    $refund = $order->refunds()->create([
        'amount' => 5000,
        'status' => 'pending',
        'provider' => 'stripe',
        'refunded_by' => 'Admin',
    ]);

    // Stripe refuses to report success without its own refund id (see
    // StripeGateway::refund), and PaymentRefunder::settle() writes that id to
    // the row. That reference IS the acknowledgement that money moved.
    $refund->forceFill(['status' => 'succeeded', 'provider_ref' => 're_3Qtest'])->save();

    $html = truthHtml(OrderRefunded::class);

    expect(str_contains($html, 'card statement'))
        ->toBeTrue('a real card refund stopped saying when the money would appear')
        ->and(str_contains($html, 'back to the payment method you used'))
        ->toBeTrue('a real card refund stopped saying where the money went');
});
