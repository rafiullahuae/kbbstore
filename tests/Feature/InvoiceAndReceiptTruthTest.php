<?php

declare(strict_types=1);

/**
 * The invoice and the receipt state only what was actually done — Lane CX.
 *
 * InvoiceDocumentTest already covers the shape of the document and
 * InvoiceMoneyTest that its figures are rendered at the currency's real
 * precision. This file is about a different failure: a document that is well
 * formed, correctly addressed, and wrong about a fact its reader will act on.
 *
 * ── 1. "PAID BY …" ON AN INVOICE THAT HAS NOT BEEN PAID ─────────────────────
 *
 * emails/order-invoice.blade.php closed with "Paid by {payment method}", for
 * every invoice, unconditionally. An invoice is emailed by the operator from the
 * order screen at whatever moment they choose, and this store's ordinary payment
 * method is cash on delivery, where `paid_at` is deliberately never set until
 * the courier actually collects (CashOnDelivery::start). So the commonest
 * invoice this shop sends — cash on delivery, not yet delivered — told the
 * customer in writing that they had already paid for it.
 *
 * The printable invoice never had this wrong: it prints its PAID stamp behind
 * `$doc['paid']`. Both documents render the same InvoiceDocument array, and
 * they now agree about the one fact an invoice is most often read for.
 *
 * ── 2. THE FIGURES ADD UP TO WHAT WAS CHARGED ───────────────────────────────
 *
 * Gift wrapping and the cash-on-delivery surcharge are both inside `fee_total`
 * (CheckoutController::place writes `fee_total = giftFee + gateway fee` and
 * `total = totals.total + fee`), and both documents split that column back into
 * two named rows. A split that lost a fil, or double-counted the gift fee, would
 * print a column of figures that does not reach the total the customer paid —
 * on a document filed against a bank statement. Asserted as arithmetic over the
 * printed rows rather than against a constant, so it holds for any basket.
 *
 * ── 3. WHAT IS DELIBERATELY NOT TOUCHED HERE ────────────────────────────────
 *
 * TAX. The invoice prints an "Includes VAT at 5%" note and a TRN when the owner
 * has entered one, while `tax_total` is written 0 by this application. Lane CU
 * then built the real tax engine and Lane DE made the HEADING conditional on it
 * — see InvoiceDocument::docType() and InvoiceDocTypeTest — so the document no
 * longer calls itself a tax document on an order that was charged no tax. The
 * assertions below are unchanged and are about the note being a PORTION of the
 * total and never an addition to it, which is the property that had to survive
 * both of those changes and did.
 *
 * ── 4. TWO CLAIMS REMOVED FROM EVERY ORDER EMAIL ────────────────────────────
 *
 * They are pinned at the end of this file rather than in a fourth one, because
 * they are claims about the same transaction and each is a single fact:
 *
 *   TRACKING. OrderStatusChanged told an order with no recorded destination
 *   that "your tracking will update as it moves". This application has no
 *   tracking number, no carrier reference and no courier integration — no
 *   column, no setting, no service. /track-my-order/ shows the order's own
 *   status pill, which changes when an operator types a new status.
 *
 *   REPLYING. The footer of every customer-facing order email invited the
 *   customer to reply "— it reaches us". Nothing in this application set a
 *   Reply-To header, and MailSettings::fromAddress() derives `no-reply@<domain>`
 *   whenever the owner has left the From box blank, which is the shipped
 *   default. The reply reached a mailbox named for not being read.
 *
 *   Lane DE built the missing half: a Reply-To box on Store → Mail, written
 *   into `mail.reply_to` by MailConfigurator, and the invitation restored in
 *   the footer ONLY while an address is configured. The case pinned here is
 *   still the shipped one — no address, so no sentence. MailReplyToTest carries
 *   the other side.
 */

use App\Mail\OrderInvoice;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Services\Invoices\InvoiceDocument;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;

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

/**
 * An order carrying every money row an invoice can print.
 *
 * AED 200 of goods, a 10% coupon, AED 20 delivery, AED 15 gift wrapping and an
 * AED 10 cash-on-delivery surcharge — so `fee_total` is 2500 and has to be split
 * back into two rows that do not overlap.
 *
 *   20000 - 2000 + 2000 + 2500 = 22500
 */
function invoiceOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'KBB-INV-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000,
        'discount_total' => 2000,
        'coupon_code' => 'GLOW10',
        'shipping_total' => 2000,
        'gift_fee' => 1500,
        'fee_total' => 2500,
        'tax_total' => 0,
        'total' => 22500,
        'is_gift' => true,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner', 'brand' => 'Haruharu', 'sku' => 'HH-RT-150',
        'quantity' => 2, 'unit_price' => 10000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    return $order->fresh('items');
}

/** The HTML part of the invoice email for this order. */
function invoiceEmailHtml(Order $order): string
{
    Mail::fake();

    Mail::mailer(\App\Services\Mail\MailConfigurator::MAILER)
        ->to((string) $order->email)
        ->send(new OrderInvoice($order));

    $html = '';

    Mail::assertSent(OrderInvoice::class, function ($mail) use (&$html) {
        $html = (string) $mail->render();

        return true;
    });

    return $html;
}

/* --------------------------------------------------- 1. paid, or not paid -- */

it('does not tell a cash-on-delivery customer they have already paid', function () {
    $html = invoiceEmailHtml(invoiceOrder());

    expect(str_contains($html, 'Paid by'))
        ->toBeFalse('An invoice for an order nobody has paid says it was paid.');

    // The method is still stated — the customer needs to know how they will be
    // asked for the money. It is stated as a method, not as a receipt.
    expect(str_contains($html, 'Payment method Cash on delivery'))
        ->toBeTrue('The invoice no longer says how the order is to be paid.');
});

it('says "Paid by" once the order really has been paid', function () {
    $html = invoiceEmailHtml(invoiceOrder(['paid_at' => now()->subDay()]));

    expect(str_contains($html, 'Paid by Cash on delivery'))
        ->toBeTrue('A paid order is not described as paid.');
});

it('agrees with the printable invoice about whether it was paid', function () {
    $unpaid = app(InvoiceDocument::class)->present(invoiceOrder());
    $paid = app(InvoiceDocument::class)->present(invoiceOrder(['paid_at' => now()]));

    // The flag the PAID stamp on invoices/invoice.blade.php is gated on, and now
    // the flag the emailed line is gated on too. One fact, one source.
    expect($unpaid['paid'])->toBeFalse('an unpaid order reports itself paid')
        ->and($paid['paid'])->toBeTrue('a paid order reports itself unpaid');
});

/* --------------------------- 1b. a COD order whose cash was collected --------- */

/*
 * THE INVOICE ASKS "WAS THIS ORDER'S MONEY COLLECTED", NOT "DID A PROVIDER
 * CONFIRM IT" — Lane EM, 2.60.199.
 *
 * `$doc['paid']` read `paid_at !== null`. PaymentCapturer settles a cash-on-
 * delivery order by writing `captured_at` and `captured_total` and deliberately
 * NOT `paid_at`, because on COD there is no provider and nothing was ever
 * authorised — CashOnDelivery's class note says exactly that, and five other
 * things read `paid_at` as the provider claim it is (PaymentConfirmer's replay
 * guard, PaymentCapturer's not-authorised check, PaymentRefunder's refundable
 * ceiling, the emailed "Paid ... on <date>" line and the order screen's payment
 * note).
 *
 * So a COD order whose cash the courier had handed over, which the operator had
 * captured, and which the refund engine would let you refund in full, printed an
 * invoice with no PAID stamp on it. The shop handed the customer a document
 * saying it had not been paid, after being paid.
 *
 * Fixed in InvoiceDocument alone. `paid_at` is untouched, so nothing else moves.
 */

it('stamps a cash-on-delivery order paid once its cash has been captured', function () {
    $collected = app(InvoiceDocument::class)->present(invoiceOrder([
        'captured_at' => now(),
        'captured_total' => 22500,
    ]));

    expect($collected['paid'])->toBeTrue('a COD order whose cash was collected still prints unpaid');
});

it('still reports a cash-on-delivery order unpaid before anyone captures it', function () {
    // The other side of the same switch: COD is the ordinary method here, and
    // an invoice emailed the moment the order is placed must not claim the cash
    // has been taken. This is the case Lane CX's assertions above cover, and it
    // has to keep answering the same way.
    $placed = invoiceOrder();

    expect($placed->paid_at)->toBeNull();
    expect($placed->captured_at)->toBeNull();
    expect(app(InvoiceDocument::class)->present($placed)['paid'])->toBeFalse();
});

it('leaves a confirmed-but-uncaptured card order stamped exactly as it was', function () {
    /*
     * NOTHING THAT USED TO STAMP PAID STOPS DOING SO. A card order that a
     * provider has authorised carries `paid_at` and no `captured_at`; its
     * invoice said Paid before this change and still does. The two columns are
     * not redundant, which is why the test is an OR rather than a replacement.
     */
    $authorised = app(InvoiceDocument::class)->present(invoiceOrder([
        'payment_method' => 'tabby',
        'payment_method_title' => 'Tabby',
        'paid_at' => now(),
    ]));

    expect($authorised['paid'])->toBeTrue();
});

it('does not borrow the capture date for the "Paid on" line', function () {
    /*
     * `paidAt` is deliberately NOT widened alongside `paid`. It is printed as
     * "Paid by <method> on <date>" in the invoice email, and the date a COD
     * order was captured is the date the shop marked the cash received, which
     * is not always the day the courier took it. An empty date is less wrong
     * than a confident wrong one; printing the collection date is an owner's
     * call, not this method's.
     */
    $doc = app(InvoiceDocument::class)->present(invoiceOrder([
        'captured_at' => now(),
        'captured_total' => 22500,
    ]));

    expect($doc['paid'])->toBeTrue();
    expect($doc['paidAt'])->toBe('');
});

/* ----------------------------------------------- 2. the column reaches the total */

it('prints a column of figures that adds up to the total charged', function () {
    $doc = app(InvoiceDocument::class)->present(invoiceOrder());

    $rows = $doc['totals'];
    $total = array_pop($rows);

    expect($total['label'])->toBe('Total', 'the last row of an invoice is not the total');

    // Every row above the Total, added as the reader would add them. The
    // discount is already carried negative by the document, which is why this is
    // a sum and not a sum-with-a-special-case.
    expect(array_sum(array_column($rows, 'fils')))
        ->toBe($total['fils'], 'The invoice rows do not add up to the total it prints.');

    expect($total['fils'])->toBe(22500, 'The invoice total is not the amount on the order.');
});

it('names the gift wrapping and the surcharge separately and counts neither twice', function () {
    $doc = app(InvoiceDocument::class)->present(invoiceOrder());

    $byLabel = [];

    foreach ($doc['totals'] as $row) {
        $byLabel[$row['label']] = $row['fils'];
    }

    expect($byLabel)->toHaveKey('Gift wrapping')
        ->and($byLabel['Gift wrapping'])->toBe(1500, 'the gift-wrapping row is not what was charged for it');

    expect($byLabel)->toHaveKey('Cash on delivery fee')
        ->and($byLabel['Cash on delivery fee'])->toBe(1000, 'the surcharge row is fee_total again rather than what the gateway added');

    expect($byLabel)->toHaveKey('Discount (GLOW10)')
        ->and($byLabel['Discount (GLOW10)'])->toBe(-2000, 'the discount is printed as an addition to the bill');
});

it('makes the emailed invoice and the emailed receipt agree about every figure', function () {
    $order = invoiceOrder();

    $invoice = app(InvoiceDocument::class)->present($order);
    $receipt = (new \App\Services\Mail\OrderEmailPresenter)->present($order);

    // The customer has the receipt in their inbox already. An invoice that
    // disagrees with it about a single row is the document they will ask about.
    expect(array_map(fn ($r) => [$r['label'], $r['fils'], $r['html']], $invoice['totals']))
        ->toBe(
            array_map(fn ($r) => [$r['label'], $r['fils'], $r['html']], $receipt['totals']),
            'The invoice and the confirmation email print different money.'
        );
});

/* ------------------------------------------------------ 3. tax, stated only -- */

it('keeps VAT a portion of the total and never an addition to it', function () {
    // D-64, and the property that has to survive whatever tax engine replaces
    // VatDisplay: the figures that add up are the figures that were charged, and
    // the VAT line sits underneath them saying how much of that was tax.
    /*
     * The order needs a tax record of its own, or there is no note here to
     * check at all — Lane DU removed the live recomputation that used to give
     * one to every order, so the skip below became unconditional and this test
     * stopped asserting anything. 5% inclusive of the taxable base
     * 20000 - 2000 + 2000 = 20000 is 952.
     */
    $doc = app(InvoiceDocument::class)->present(invoiceOrder([
        'tax_rate' => 5,
        'tax_basis' => \App\Support\TaxRule::INCLUSIVE,
        'tax_total' => 952,
    ]));

    if ($doc['vatNote'] === null) {
        test()->markTestSkipped('VAT display is off in this configuration, so there is no note to check.');
    }

    $rows = $doc['totals'];
    $total = array_pop($rows);

    // in_array, not toContain: Pest reads toContain's second argument as
    // another needle rather than as a message, so `toContain('VAT', '...')`
    // quietly asserts that the explanation is in the array too.
    expect(in_array('VAT', array_column($rows, 'label'), true))
        ->toBeFalse('a computed VAT figure was added to the rows that sum to the total');

    expect(array_sum(array_column($rows, 'fils')))
        ->toBe($total['fils'], 'the VAT note has moved the total');

    expect($doc['vatNote']['fils'])
        ->toBeLessThan($total['fils'], 'the VAT note claims more tax than the order was worth');
});

it('restates an imported order\'s own tax instead of computing a second figure', function () {
    // A WooCommerce order arrives with a real `tax_total` already inside its
    // total. Two different VAT numbers on one invoice is worse than none.
    $doc = app(InvoiceDocument::class)->present(invoiceOrder([
        'tax_total' => 1071,
        'order_number' => 'KBB-INV-IMPORTED-' . uniqid(),
    ]));

    expect($doc['vatNote'])->toBeNull('an imported order carrying its own tax also got a computed VAT note');

    expect(in_array('VAT', array_column($doc['totals'], 'label'), true))
        ->toBeTrue("the imported order's own tax is not printed at all");
});

/* ----------------------------------------------------- 4. no invented claims -- */

/**
 * No returns window, no dispatch promise, no tracking capability.
 *
 * None of the three is recorded anywhere in this application: there is no
 * returns setting, no dispatch SLA, and no tracking number, carrier reference or
 * courier integration — /track-my-order/ shows the order's own status pill and
 * nothing else. The owner has `invoice_footer` for anything he wants to say on
 * an invoice, and it ships blank.
 *
 * The needles are the words a customer would read, not class names: the
 * documents inline their own stylesheet, so a bare class search matches CSS
 * whether or not any element carries it.
 */
it('promises no returns window, dispatch time or tracking the shop has not got', function () {
    $order = invoiceOrder();

    $documents = [
        'emailed invoice' => invoiceEmailHtml($order),
        'emailed invoice (text)' => view('emails.order-invoice-text', ['doc' => app(InvoiceDocument::class)->present($order)])->render(),
    ];

    $forbidden = [
        'day returns',
        'days to return',
        'return it within',
        'returns policy',
        'dispatched within',
        'ships within',
        'tracking number',
        'track your parcel',
    ];

    foreach ($documents as $name => $body) {
        foreach ($forbidden as $needle) {
            expect(stripos($body, $needle))
                ->toBeFalse("The {$name} claims \"{$needle}\", which nothing in this application records.");
        }
    }
});

/* ------------------------------- 4. the claims removed from every order email */

it('offers no parcel tracking, because this shop has none to offer', function () {
    Mail::fake();

    // An order with no country on either address — an import, or a manual order
    // half filled in. It gets no delivery estimate, and it used to be offered
    // tracking instead.
    $order = invoiceOrder([
        'order_number' => 'KBB-INV-NOWHERE-' . uniqid(),
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk'],
    ]);

    $order->update(['status' => 'shipped']);

    $html = '';

    Mail::assertSent(\App\Mail\OrderStatusChanged::class, function ($mail) use (&$html) {
        $html = (string) $mail->render();

        return true;
    });

    expect(str_contains($html, 'tracking will update'))
        ->toBeFalse('The dispatch email offers tracking this application does not have.');

    // The sentence that IS true survives: the parcel has gone.
    expect(str_contains($html, 'Your order has left us and is with the courier.'))
        ->toBeTrue('The dispatch email lost the one thing it knows.');
});

it('does not invite a reply to a mailbox nobody reads', function () {
    /*
     * THE CLAIM HAS A SWITCH BEHIND IT NOW — Lane DE.
     *
     * This test used to prove the invitation could not be true by walking app/
     * for a `replyTo(` call and finding none. That walk has been removed rather
     * than kept, because it no longer proves what it says: Store → Mail has a
     * Reply-To box, MailConfigurator writes it into `mail.reply_to`, and a
     * global address is applied by MailManager when it builds a mailer rather
     * than by a call in this codebase — so counting call sites would report
     * "nothing sets a Reply-To" about an application that does.
     *
     * What is asserted instead is the state this fixture is actually in, which
     * is the shipped one: no From address and NO REPLY-TO ADDRESS, so a reply
     * would go to the `no-reply@` mailbox MailSettings derives from APP_URL —
     * and, in that state, the footer says nothing about replying. The other
     * half, that the sentence comes back once an address is configured and that
     * the header really is on the message, is MailReplyToTest's.
     */
    $mail = app(\App\Services\Mail\MailSettings::class);

    // The shipped state of Store → Mail: the server transport needs nothing
    // filled in, which is why it is the default.
    expect($mail->get('mail_from_address'))
        ->toBe('', 'the fixture no longer reproduces an unconfigured mail screen');
    expect($mail->replyToAddress())
        ->toBe('', 'the fixture has a Reply-To, so this is no longer the unconfigured case');

    $derived = $mail->fromAddress();

    expect($derived === '' || str_starts_with($derived, 'no-reply@'))
        ->toBeTrue('the From address is now a mailbox somebody might actually read: ' . $derived);

    $html = invoiceEmailHtml(invoiceOrder());

    expect(str_contains($html, 'it reaches us'))
        ->toBeFalse('An order email still invites a reply to an address nothing reads.');

    // The sentence that is true about why they got it stays.
    expect(str_contains($html, 'You are receiving this because an order was placed'))
        ->toBeTrue('The footer lost the one thing it could say.');
});

it('leaves the owner a blank invoice footer to write his own terms in', function () {
    // Where a returns policy or payment terms BELONG: a setting the owner fills
    // in, printed verbatim, and empty until he does.
    expect(app(InvoiceDocument::class)->seller()['footer'])
        ->toBe('', 'the invoice footer ships with wording nobody wrote');

    app(SettingsService::class)->set('invoice_footer', 'Returns accepted within 14 days, unopened.');
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(str_contains(invoiceEmailHtml(invoiceOrder()), 'Returns accepted within 14 days, unopened.'))
        ->toBeTrue('the owner wrote his terms and the invoice did not print them');
});
