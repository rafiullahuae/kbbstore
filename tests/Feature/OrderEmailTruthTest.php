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
use App\Support\Money;
use App\Services\SettingsService;
use App\Support\OrderTax;
use App\Support\TaxRule;
use App\Support\VatDisplay;
use Illuminate\Support\Facades\Mail;

/*
 * RECEIPT WIDTH IS THE ORDER'S, NOT THE CURRENCY'S MAXIMUM — Lane FA.
 *
 * These assertions have always meant "printed the way this shop prints a
 * receipt figure", and they expressed it by calling the presenter's own
 * helper. That helper's default is still Money::minorExponent(); what changed
 * is that the receipt no longer uses the default. Under the whole-dirham
 * policy a receipt prints at the narrowest width that states ALL of its
 * figures exactly (Money::receiptDecimals), so a whole-dirham order reads
 * "AED 235" and an order carrying fils still reads "AED 89.80".
 *
 * So the assertions pass the width the receipt actually used. What is asserted
 * is unchanged — the email prints this exact amount — and it still fails if the
 * email prints a rounded or a widened figure that is not the money charged.
 */

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

it('prints on the receipt the inclusive-VAT line the order recorded', function () {
    /*
     * ── WHAT THIS TEST USED TO SAY, AND WHY IT CHANGED — LANE DU ───────────
     *
     * It used to take a fixture with NO tax record and assert that the receipt
     * printed VatDisplay's LIVE line anyway, on the grounds that the checkout
     * page had shown one. That is the behaviour Lane DU removed: a receipt is
     * re-rendered every time it is resent, so a live figure on it is a figure
     * that changes after the fact. See OrderEmailPresenter::vatNote().
     *
     * The property worth keeping is the one underneath it — the receipt does
     * not go silent about a tax the customer was charged — so the fixture now
     * carries the record that entitles it to print one. The silence case has
     * its own test below.
     */
    Mail::fake();

    // 20000 - 0 + 2000 = 22000 taxable base; 5% inclusive of that is 1048.
    $order = truthOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::INCLUSIVE, 'tax_total' => 1048]);

    Mail::to((string) $order->email)->send(new OrderConfirmation($order));

    $recorded = OrderTax::recorded($order);

    // The fixture has to be one where VAT is actually visible, or this test
    // would pass by saying nothing.
    expect($recorded)->not->toBeNull()
        ->and($recorded['fils'])->toBe(1048)
        ->and($recorded['added'])->toBeFalse();

    $html = truthHtml(OrderConfirmation::class);
    $text = truthText(OrderConfirmation::class, 'emails.order-confirmation-text');

    // The WORDING is still the shop's `vat_label`; only the {rate} inside it
    // comes off the order. With the shop at its default 5% the two agree, so
    // this string is also a check that the snapshot rate is what was
    // substituted. e(), because Blade's {{ }} escapes the apostrophe in
    // "You're" — which is exactly what an HTML body should do to it. The text
    // part below wants the unescaped original.
    $label = app(VatDisplay::class)->label();

    expect(str_contains($html, e($label)))
        ->toBeTrue('the confirmation email prints no VAT line for an order that recorded tax');

    expect(str_contains($html, OrderEmailPresenter::html(1048, Money::receiptDecimals(1048))))
        ->toBeTrue('the confirmation email does not print the VAT figure the order recorded');

    expect(str_contains($text, $label))
        ->toBeTrue('the plain-text receipt prints no VAT line');

    expect(str_contains($text, OrderEmailPresenter::plain(1048, Money::receiptDecimals(1048))))
        ->toBeTrue('the plain-text receipt does not print the VAT figure');
});

it('says nothing about VAT on a receipt for an order that recorded none', function () {
    /*
     * The other half of the decision above, and the one a reader will want to
     * find: an order with no tax record — placed before the engine, or placed
     * while the shop sits in the shipped `display` mode — gets no VAT figure on
     * its receipt at all, rather than one recomputed from today's settings.
     *
     * Stated as a consequence the owner should know about: while the shop is in
     * `display` mode the CHECKOUT PAGE still shows a VAT line, because that is
     * a live quote about a cart and is allowed to be, and the receipt that
     * follows it now says nothing. Setting tax_mode to `live` with an
     * `inclusive` basis makes all three documents agree again and moves no
     * totals — see App\Support\VatDisplay::quote().
     */
    Mail::fake();

    $order = truthOrder();

    expect(OrderTax::recorded($order))->toBeNull();

    Mail::to((string) $order->email)->send(new OrderConfirmation($order));

    // 'VAT' as a word, not as a class name: a rendered email inlines its own
    // CSS and a class-name search would match that too.
    expect(str_contains(truthHtml(OrderConfirmation::class), 'VAT'))
        ->toBeFalse('a receipt for an order with no tax record still states a VAT figure');

    expect((new OrderEmailPresenter)->present($order)['vatNote'])->toBeNull();
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

    $order = truthOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::INCLUSIVE, 'tax_total' => 1048]);

    // The invoice is the formal tax document and it already carried VAT. If the
    // receipt in the customer's inbox and the invoice they can ask for disagree
    // about the tax on one order, one of them is wrong and the customer cannot
    // tell which.
    $doc = app(\App\Services\Invoices\InvoiceDocument::class)->present($order);
    $presented = (new OrderEmailPresenter)->present($order);

    expect($doc['vatNote'])->not->toBeNull()
        ->and($presented['vatNote'])->not->toBeNull()
        ->and((int) $presented['vatNote']['fils'])->toBe((int) $doc['vatNote']['fils']);

    // And they agree about SILENCE too, which is the case Lane DU created and
    // the one where two readers of the same record could most easily drift:
    // one of them keeping a live fallback the other dropped would put a VAT
    // figure on the invoice and none on the receipt for the same order.
    $bare = truthOrder();

    expect(app(\App\Services\Invoices\InvoiceDocument::class)->present($bare)['vatNote'])->toBeNull()
        ->and((new OrderEmailPresenter)->present($bare)['vatNote'])->toBeNull();
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
    expect(str_contains($html, OrderEmailPresenter::html(5000, Money::receiptDecimals(5000))))->toBeTrue('the refunded amount is missing')
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
