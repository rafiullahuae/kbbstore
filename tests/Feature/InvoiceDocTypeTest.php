<?php

declare(strict_types=1);

/**
 * What the invoice CALLS ITSELF — Lane DE.
 *
 * ── THE CLAIM, AND WHY IT ONLY BECAME A CLAIM NOW ───────────────────────────
 *
 * Both invoices — the printable page and the emailed copy — were headed with a
 * two-word phrase naming this document as a tax document, on every order, with
 * no condition of any kind. Until the tax engine landed that was harmless and
 * empty: `orders.tax_total` was written 0 by this application, and the VAT
 * figure was a note printed UNDER the Total rather than a row in the column
 * that sums to it. The heading described a tax that was not there, on a
 * document that did not pretend otherwise.
 *
 * App\Support\OrderTax and App\Support\TaxRule changed that. One invoice can now
 * be three genuinely different documents:
 *
 *   no tax record, or a `flat` ("printed only") basis  nothing was charged and
 *                                                      nothing is contained
 *   inclusive                                          the tax is INSIDE the
 *                                                      total
 *   exclusive                                          the tax was ADDED to it
 *
 * ── WHAT THIS FILE DECIDES, AND WHAT IT DELIBERATELY DOES NOT ───────────────
 *
 * It does NOT decide what a UAE shop's invoice ought to be called. That phrase
 * has a specific meaning in the GCC, it usually implies a tax registration
 * number, and `invoice_trn` ships blank — so the right wording is the owner's
 * question to put to his accountant and not a developer's to guess at. The
 * mechanism is a setting, `invoice_doctype`, printed verbatim when he fills it
 * in.
 *
 * What it DOES decide is the default, and the default is restraint: the
 * document makes the stronger claim only when both facts behind it are on the
 * page — tax that was really charged or really contained, AND a registration
 * number under the seller's name. Otherwise it is headed with the plain word,
 * which is true of every invoice ever issued. That is the same rule this
 * project has applied to the tracking sentence, the refund promise, the Gulf
 * delivery window and the invitation to reply.
 *
 * ── THE TWO INVARIANTS THIS MUST NOT DISTURB ────────────────────────────────
 *
 * The heading is the only thing that moves. The rows that sum to the Total
 * still contain no computed VAT, and the "of which" note is still rendered
 * under the Total and smaller than it. Both are re-asserted below, because a
 * change to a document is exactly when the properties of that document stop
 * being checked.
 */

use App\Models\Order;
use App\Models\Setting;
use App\Services\Invoices\InvoiceDocument;
use App\Services\SettingsService;
use App\Support\TaxRule;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/** A settings write, then the three-step flush this codebase needs to be believed. */
function dtSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/**
 * One order, with whatever tax record the case under test needs.
 *
 * AED 200 of goods, AED 20 delivery. The tax columns are written directly
 * rather than driven through the checkout, because what is under test is how a
 * DOCUMENT reads an order's own record — see OrderTax's header for why that
 * record is a snapshot and is never recomputed at print time.
 */
function dtOrder(array $overrides = []): Order
{
    // `orders.invoice_number` is unique, and several cases below build more
    // than one order. Counted rather than fixed, so a test that needs two does
    // not fail on a constraint that has nothing to do with what it is about.
    static $number = 1000;

    $order = Order::create(array_merge([
        'invoice_number' => $number++,
        'order_number' => 'KBB-DT-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'gift_fee' => 0,
        'tax_total' => 0,
        'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner', 'quantity' => 1, 'unit_price' => 20000, 'total' => 20000,
    ]);

    return $order->fresh('items');
}

function dtType(Order $order): string
{
    return (string) app(InvoiceDocument::class)->present($order)['docType'];
}

/* ------------------------------------------------- the three states, no TRN */

it('heads an invoice that charged no tax with the plain word', function () {
    /*
     * THE SHIPPED STATE OF THIS SHOP. `tax_mode` ships 'display', so
     * CheckoutController leaves `tax_rate` and `tax_basis` NULL and writes
     * `tax_total` 0 — OrderTax::recorded() answers null, and nothing on this
     * document is a tax that was charged. The VAT note VatDisplay computes is a
     * display line and says so in its own words.
     */
    expect(dtType(dtOrder()))->toBe('Invoice');
});

it('heads a printed-only invoice with the plain word, because nothing was charged', function () {
    // `flat` is the legacy basis, unchanged in meaning: a figure PRINTED beside
    // a total it is not part of. The owner picks it on the Tax screen as
    // "Printed only — charges nothing".
    expect(dtType(dtOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::FLAT])))->toBe('Invoice');
});

it('does not call an inclusive invoice a tax document while no TRN is recorded', function () {
    $order = dtOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::INCLUSIVE, 'tax_total' => 1048]);

    expect(app(InvoiceDocument::class)->seller()['trn'])->toBe('', 'the fixture already has a TRN');
    expect(dtType($order))->toBe('Invoice');
});

it('does not call an exclusive invoice a tax document while no TRN is recorded', function () {
    $order = dtOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::EXCLUSIVE, 'tax_total' => 1100, 'total' => 23100]);

    expect(dtType($order))->toBe('Invoice');
});

/* ------------------------------------------------------------- with the TRN */

it('calls an inclusive invoice a tax document once the TRN is entered', function () {
    dtSet('invoice_trn', '100123456700003');

    $order = dtOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::INCLUSIVE, 'tax_total' => 1048]);

    expect(dtType($order))->toBe('Tax Invoice');
});

it('calls an exclusive invoice a tax document once the TRN is entered', function () {
    dtSet('invoice_trn', '100123456700003');

    $order = dtOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::EXCLUSIVE, 'tax_total' => 1100, 'total' => 23100]);

    expect(dtType($order))->toBe('Tax Invoice');
});

it('keeps the plain word on a printed-only invoice even with a TRN entered', function () {
    // A registration number does not turn an uncharged tax into a charged one.
    dtSet('invoice_trn', '100123456700003');

    expect(dtType(dtOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::FLAT])))->toBe('Invoice');
});

it('calls an imported order carrying real tax a tax document once the TRN is entered', function () {
    /*
     * WooCommerce orders arrive through OrderImporter with a real `tax_total`
     * already inside `total` and no rate beside it. InvoiceDocument has always
     * printed that as an authoritative ROW. It is tax that was really charged,
     * so it earns the heading on the same terms as a native one.
     */
    dtSet('invoice_trn', '100123456700003');

    expect(dtType(dtOrder(['tax_total' => 1048])))->toBe('Tax Invoice');
});

/* ------------------------------------------------------------- the override */

it('prints whatever the owner and his accountant put in the setting, in every state', function () {
    /*
     * THE POINT OF THE WHOLE MECHANISM. The default above is a developer
     * declining to make a claim he cannot support; this is the owner making the
     * one his accountant tells him to make. It wins in every state, including
     * the untaxed one, because the person who has asked an accountant knows
     * something this code does not.
     */
    dtSet('invoice_doctype', 'Simplified Tax Invoice');

    expect(dtType(dtOrder()))->toBe('Simplified Tax Invoice');

    $inclusive = dtOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::INCLUSIVE, 'tax_total' => 1048]);

    expect(dtType($inclusive))->toBe('Simplified Tax Invoice');
});

it('falls back to the derived heading when the setting holds only whitespace', function () {
    dtSet('invoice_doctype', '   ');

    expect(dtType(dtOrder()))->toBe('Invoice');
});

/* -------------------------------------------------- it reaches all three documents */

it('prints the heading on the printable page, the HTML email and the text email', function () {
    dtSet('invoice_doctype', 'Fiscal Receipt');

    $order = dtOrder();

    $html = (string) (new App\Mail\OrderInvoice($order))->render();

    expect(str_contains($html, 'Fiscal Receipt'))
        ->toBeTrue('the emailed invoice ignores the heading the owner set');

    $doc = app(InvoiceDocument::class)->present($order);

    $text = (string) view('emails.order-invoice-text', ['doc' => $doc])->render();

    expect(str_contains($text, 'FISCAL RECEIPT'))
        ->toBeTrue('the text part of the emailed invoice ignores the heading the owner set');

    $page = (string) view('invoices.invoice', ['doc' => $doc, 'packingSlipUrl' => '#'])->render();

    expect(str_contains($page, 'Fiscal Receipt'))
        ->toBeTrue('the printable invoice ignores the heading the owner set');
});

it('escapes the heading rather than rendering it as markup', function () {
    // Operator input on a page. `invoice_doctype` has no screen of its own yet
    // and arrives from the settings table, which is exactly the class of value
    // this project has already had to patch twice.
    dtSet('invoice_doctype', '<script>alert(1)</script>Invoice');

    $html = (string) (new App\Mail\OrderInvoice(dtOrder()))->render();

    expect(str_contains($html, '<script>alert(1)</script>'))
        ->toBeFalse('the invoice heading is rendered as markup');
});

/* ------------------------------------------------ the invariants, re-asserted */

it('keeps every computed VAT figure out of the column that sums to the Total', function () {
    /*
     * PINNED BY THE AUDIT LANE AND NOT NEGOTIABLE. An inclusive tax added as a
     * summing row makes the invoice column stop adding up to what was charged.
     * Asserted as arithmetic over the printed rows, so it holds for any basket.
     */
    dtSet('invoice_trn', '100123456700003');

    foreach ([
        [TaxRule::INCLUSIVE, 1048, 22000],
        [TaxRule::FLAT, 0, 22000],
    ] as [$basis, $tax, $total]) {
        $doc = app(InvoiceDocument::class)->present(
            dtOrder(['tax_rate' => 5, 'tax_basis' => $basis, 'tax_total' => $tax, 'total' => $total])
        );

        $rows = $doc['totals'];
        $grand = array_values(array_filter($rows, fn (array $r) => $r['strong']));
        $summed = array_sum(array_column(array_filter($rows, fn (array $r) => ! $r['strong']), 'fils'));

        expect($grand[0]['fils'])->toBe($summed, "the {$basis} invoice's rows do not add up to its Total");

        foreach ($rows as $row) {
            expect(str_contains($row['label'], 'VAT'))
                ->toBeFalse("a {$basis} invoice put VAT in the column that sums to the Total");
        }
    }
});

it('renders the VAT note below the Total and smaller than it', function () {
    dtSet('invoice_trn', '100123456700003');

    $doc = app(InvoiceDocument::class)->present(
        dtOrder(['tax_rate' => 5, 'tax_basis' => TaxRule::INCLUSIVE, 'tax_total' => 1048])
    );

    expect($doc['vatNote'])->not->toBeNull('the inclusive invoice lost its "of which" note');

    $html = (string) view('invoices.invoice', ['doc' => $doc, 'packingSlipUrl' => '#'])->render();

    $totalsAt = strpos($html, 'class="totals-wrap"');
    $noteAt = strpos($html, 'class="vatnote"');

    expect($totalsAt)->not->toBeFalse('the totals table is gone from the invoice');
    expect($noteAt)->not->toBeFalse('the VAT note is gone from the invoice');
    expect($noteAt > $totalsAt)->toBeTrue('the VAT note is rendered above the Total it is a portion of');

    // And smaller than the Total, from the document's own stylesheet.
    $sheet = (string) file_get_contents(resource_path('views/invoices/document.blade.php'));

    expect(preg_match('/\.vatnote\s*\{[^}]*font-size:\s*([\d.]+)/', $sheet, $note))
        ->toBe(1, 'the VAT note has no font-size of its own any more');
    expect(preg_match('/table\.totals tr\.grand td\s*\{[^}]*font-size:\s*([\d.]+)/', $sheet, $grand))
        ->toBe(1, 'the Total row has no font-size of its own any more');
    expect((float) $note[1] < (float) $grand[1])
        ->toBeTrue('the VAT note is not smaller than the Total it sits under');
});
