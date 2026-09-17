<?php

/**
 * The order-received page states the order's tax — Lane DT.
 *
 * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────────
 *
 * Lane DQ traced tax through every document the shop produces and found one
 * that said nothing at all: the order-received page. The confirmation email,
 * the invoice and the admin order screen each print the tax line; the receipt
 * the customer is looking at the instant they have paid did not. On an
 * exclusive-tax order that meant a Total visibly larger than the charges listed
 * above it, with nothing on screen accounting for the difference.
 *
 * This is a DISCLOSURE gap, not an arithmetic one. The block deliberately omits
 * Subtotal (owner's request), so it is not a column that has to sum; the bug is
 * that a figure the customer paid was never named.
 *
 * ── WHAT IS PINNED, AND WHY EACH CASE EXISTS ────────────────────────────────
 *
 *   an ADDED tax is a row above the Total        — it was charged on top
 *   a CONTAINED tax is a note under the Total    — it is a portion of it, and
 *                                                  a row would read as a second
 *                                                  charge that never happened
 *   NO tax is NO row                             — "VAT AED 0.00" is noise that
 *                                                  states a charge of nothing
 *   the rate is the ORDER'S, forever             — the load-bearing one. The
 *                                                  owner may set a country to
 *                                                  5% today and 15% next year.
 *                                                  A page that recomputed from
 *                                                  today's settings would
 *                                                  reprint last year's receipt
 *                                                  at a rate nobody was charged.
 *
 * The wording is deliberately NOT new. The added row is
 * OrderEmailPresenter::totals()' and InvoiceDocument::totals()' shared "VAT at
 * 5%"; the note is OrderEmailPresenter::vatNote()'s `vat_label`, the shopper's
 * own second-person sentence that the checkout page showed a moment earlier.
 * The invoice's accountant phrasing ("Includes VAT at 5%") is not used here —
 * this is the shopper's copy. RECEIPT AND SCREEN MUST AGREE is asserted
 * directly below, so a future edit to either cannot quietly fork the wording.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\TaxRule;
use App\Support\VatDisplay;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
});

/** Every settings write goes through the service, then both memos are dropped. */
function rcvTaxSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

/**
 * An order carrying whatever tax record the case needs.
 *
 * Written straight onto the row rather than placed through the checkout: the
 * point of every test here is what the page does with a SNAPSHOT, and building
 * the snapshot directly is the only way to hold it still while the settings
 * around it are changed underneath.
 */
function rcvTaxOrder(array $attributes = []): Order
{
    static $seq = 0;
    $seq++;

    $order = Order::create(array_merge([
        'order_number' => 'RCVTAX' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 10000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => 0,
        'gift_fee' => 0,
        'total' => 12000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'billing_address' => rcvTaxAddress(),
        'shipping_address' => rcvTaxAddress(),
    ], $attributes));

    $product = Product::create([
        'slug' => 'rcvtax-' . $seq,
        'name' => 'Taxed Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 100,
        'stock_status' => 'instock',
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'name' => 'Taxed Serum',
        'quantity' => 1,
        'unit_price' => 10000,
        'subtotal' => 10000,
        'total' => 10000,
    ]);

    return $order->fresh('items');
}

function rcvTaxAddress(): array
{
    return [
        'first_name' => 'Aisha', 'last_name' => 'Khan',
        'line1' => '12 Marina Walk', 'city' => 'Dubai',
        'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
    ];
}

/** The page, fetched by the browser that just placed the order. */
function rcvTaxPage(Order $order)
{
    return test()
        ->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order=' . $order->order_number);
}

/**
 * The shopper's own sentence, as it actually reaches the page.
 *
 * `vat_label` ships as "You're paying VAT ({rate}%)" and Blade escapes the
 * apostrophe, so the literal string is NOT in the HTML — searching for it
 * unescaped fails on a page that is rendering it perfectly.
 */
function rcvTaxLabel(string $rate = '5'): string
{
    return e("You're paying VAT ({$rate}%)");
}

/**
 * A money figure as it appears in the markup.
 *
 * Money::format() returns nested spans (currency symbol in its own element), so
 * the rendered "AED 23" has tags between the two words and a stripped, plain
 * needle can never match it. The formatter's own output is the only safe needle.
 */
function rcvTaxMoney(int $fils): string
{
    return Money::format($fils);
}

/** Just the totals block, so an assertion cannot match a line item by accident. */
function rcvTaxTotals(Order $order): string
{
    $html = rcvTaxPage($order)->getContent();

    expect($html)->toContain('co-totals');

    $from = strpos($html, '<div class="co-totals">');
    $to = strpos($html, '</div>', strpos($html, 'sumrow tot'));

    return substr($html, $from, $to - $from);
}

/* ------------------------------------------------------- an ADDED tax: a row */

it('prints an exclusive order\'s VAT as a row, with the rate the order recorded', function () {
    // Base 10000 + 5000 delivery = 15000, 15% ADDED = 2250, total 17250.
    $order = rcvTaxOrder([
        'shipping_total' => 5000,
        'tax_total' => 2250,
        'tax_rate' => 15.0,
        'tax_basis' => TaxRule::EXCLUSIVE,
        'total' => 17250,
    ]);

    $page = rcvTaxPage($order);

    $page->assertOk()
        ->assertSee('VAT at 15%');

    expect($page->getContent())->toContain(rcvTaxMoney(2250));
});

it('puts the added VAT row ABOVE the total, where the charges are', function () {
    $order = rcvTaxOrder([
        'shipping_total' => 5000,
        'tax_total' => 2250,
        'tax_rate' => 15.0,
        'tax_basis' => TaxRule::EXCLUSIVE,
        'total' => 17250,
    ]);

    $block = rcvTaxTotals($order);

    $vatAt = strpos($block, 'VAT at 15%');
    $total = strpos($block, 'sumrow tot');

    expect($vatAt)->not->toBeFalse('the added VAT row must be inside the totals block')
        ->and($total)->not->toBeFalse()
        ->and($vatAt)->toBeLessThan(
            $total,
            'an exclusive tax was charged on top, so it belongs among the charges above the Total'
        );
});

/* ------------------------------ THE LOAD-BEARING ONE: the snapshot, not today */

it('keeps printing the order\'s own rate after the owner changes the rate', function () {
    $order = rcvTaxOrder([
        'shipping_total' => 5000,
        'tax_total' => 2250,
        'tax_rate' => 15.0,
        'tax_basis' => TaxRule::EXCLUSIVE,
        'total' => 17250,
    ]);

    // What the customer was actually charged, and what the page says today.
    rcvTaxPage($order)->assertOk()->assertSee('VAT at 15%');

    /*
     * Now the owner raises the rate — globally and per country, in live mode,
     * which is every lever the storefront has. An order already placed is a
     * closed document: none of this may reach it.
     */
    rcvTaxSet(VatDisplay::MODE_KEY, VatDisplay::MODE_LIVE);
    rcvTaxSet('vat_rate', 22);
    rcvTaxSet(VatDisplay::COUNTRY_RATES_KEY, json_encode(['AE' => '22', 'SA' => '22']));
    rcvTaxSet(VatDisplay::COUNTRY_BASES_KEY, json_encode(['AE' => TaxRule::EXCLUSIVE, 'SA' => TaxRule::EXCLUSIVE]));

    $page = rcvTaxPage($order->fresh());

    $page->assertOk()
        // Still the rate on the order.
        ->assertSee('VAT at 15%')
        // And never the rate the shop charges today.
        ->assertDontSee('VAT at 22%')
        ->assertDontSee('22%');

    // The FIGURE too, not only the words: the snapshot's fils, not a fresh sum.
    expect($page->getContent())
        ->toContain(rcvTaxMoney(2250))
        ->not->toContain(rcvTaxMoney(3300));
});

/* ------------------------------------------- a CONTAINED tax: a note, not a row */

it('prints an inclusive order\'s VAT as an "of which" note, never as an added row', function () {
    // 12000 total, 5% inclusive: 12000 x 500 / 10500 = 571 fils of it IS tax.
    $order = rcvTaxOrder([
        'tax_total' => 571,
        'tax_rate' => 5.0,
        'tax_basis' => TaxRule::INCLUSIVE,
        'total' => 12000,
    ]);

    $page = rcvTaxPage($order);

    $page->assertOk()
        // The shopper's own sentence, the one the checkout page showed.
        ->assertSee(rcvTaxLabel(), escape: false)
        // NOT the added-row wording: nothing was added.
        ->assertDontSee('VAT at 5%');

    expect($page->getContent())->toContain(rcvTaxMoney(571));
});

it('puts the contained VAT note UNDER the total, so it does not read as a charge', function () {
    $order = rcvTaxOrder([
        'tax_total' => 571,
        'tax_rate' => 5.0,
        'tax_basis' => TaxRule::INCLUSIVE,
        'total' => 12000,
    ]);

    $html = rcvTaxPage($order)->getContent();

    $total = strpos($html, 'sumrow tot');
    $note = strpos($html, rcvTaxLabel());

    expect($note)->not->toBeFalse()
        ->and($note)->toBeGreaterThan(
            $total,
            'an inclusive tax is a portion OF the total and must sit under it'
        );
});

it('honours the owner\'s wording for the note, because it is his sentence', function () {
    rcvTaxSet('vat_label', 'VAT included at {rate}%');

    $order = rcvTaxOrder([
        'tax_total' => 571,
        'tax_rate' => 5.0,
        'tax_basis' => TaxRule::INCLUSIVE,
        'total' => 12000,
    ]);

    rcvTaxPage($order)->assertOk()
        ->assertSee('VAT included at 5%')
        ->assertDontSee(e("You're paying VAT"), escape: false);
});

/* ----------------------------------------------- NO tax: nothing, not a zero */

it('prints no tax row at all on an order that was charged none', function () {
    // A recorded basis with a zero rate: the record exists, the figure is 0.
    $order = rcvTaxOrder([
        'tax_total' => 0,
        'tax_rate' => 0.0,
        'tax_basis' => TaxRule::EXCLUSIVE,
        'total' => 12000,
    ]);

    $block = rcvTaxTotals($order);

    expect($block)
        ->not->toContain('VAT')
        ->not->toContain('vat');

    // Specifically not the thing this test exists to forbid.
    expect($block)->not->toContain('VAT at 0%');
});

it('prints no tax row on an order with no tax record and the VAT line switched off', function () {
    // The other no-tax shape: nothing snapshotted, and the owner has turned the
    // display line off, so there is nothing true to say.
    rcvTaxSet('vat_enabled', '0');

    $order = rcvTaxOrder(['tax_total' => 0, 'total' => 12000]);

    expect($order->tax_basis)->toBeNull('this order deliberately has no tax record');

    $block = rcvTaxTotals($order);

    expect($block)->not->toContain('VAT');
});

it('counts exactly one tax element, and only when there is a tax', function () {
    rcvTaxSet('vat_enabled', '0');

    // `.sumrow vat` is a class name, and a class name search of rendered HTML
    // also matches the stylesheet that defines it — so these are COUNTED on the
    // opening tag rather than merely looked for.
    $none = rcvTaxTotals(rcvTaxOrder(['tax_total' => 0, 'total' => 12000]));

    expect(preg_match_all('/<div class="sumrow vat">/', $none))->toBe(0);

    $taxed = rcvTaxTotals(rcvTaxOrder([
        'shipping_total' => 5000,
        'tax_total' => 2250,
        'tax_rate' => 15.0,
        'tax_basis' => TaxRule::EXCLUSIVE,
        'total' => 17250,
    ]));

    expect(preg_match_all('/<div class="sumrow vat">/', $taxed))->toBe(
        1,
        'one tax, one element — an exclusive order gets the row and not the note as well'
    );
});

/* --------------------------------- an imported order keeps what it always had */

it('prints an imported order\'s own tax_total as the plain row it always printed', function () {
    // No basis, but real tax already inside the total: a WooCommerce import.
    $order = rcvTaxOrder(['tax_total' => 600, 'total' => 12000]);

    expect($order->tax_basis)->toBeNull();

    rcvTaxPage($order)->assertOk()
        ->assertSee('VAT')
        // No rate is claimed, because the imported row carries none.
        ->assertDontSee('VAT at');
});

/* -------------------------------------- the screen and the emailed receipt agree */

it('words the tax exactly as the receipt the customer is emailed', function () {
    $order = rcvTaxOrder([
        'shipping_total' => 5000,
        'tax_total' => 2250,
        'tax_rate' => 15.0,
        'tax_basis' => TaxRule::EXCLUSIVE,
        'total' => 17250,
    ]);

    /*
     * The same order, through the presenter that builds the confirmation email.
     * One customer, one order, minutes apart — if these two ever word the same
     * fact differently, one of them is wrong and nobody would notice.
     */
    $rows = (new ReflectionClass(\App\Services\Mail\OrderEmailPresenter::class))
        ->getMethod('totals');
    $rows->setAccessible(true);

    $labels = array_column($rows->invoke(app(\App\Services\Mail\OrderEmailPresenter::class), $order), 'label');

    expect($labels)->toContain('VAT at 15%');

    rcvTaxPage($order)->assertOk()->assertSee('VAT at 15%');
});
