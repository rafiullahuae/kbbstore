<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Editing an order's lines re-prices its tax — from the order's OWN record
|------------------------------------------------------------------------------
|
| AdminOrderController::recalcTotals() rebuilt an edited order's subtotal from
| its lines and then wrote
|
|     total = subtotal + shipping + fee + tax_total - discount
|
| leaving `tax_total` exactly as the checkout wrote it. That was invisible for as
| long as `tax_total` was always 0. It is not invisible now: App\Support\OrderTax
| and the `tax_mode` switch mean an order can carry a real tax figure, and adding
| a line to such an order left the tax computed against a subtotal that no longer
| exists — an invoice whose own column of figures does not add up.
|
| DOES EDITING AN ORDER RE-PRICE IT AT ALL? That is the question the tax lane
| asked and it is answered here: yes, and it has to be. An operator who adds a
| bottle of toner has changed what was supplied, and a tax figure describing the
| supply before that bottle is simply wrong. Leaving it alone hands the customer
| a document that does not reconcile, which is worse than one that moved.
|
| BUT AT THE ORDER'S OWN RATE AND BASIS, NEVER AT TODAY'S SETTINGS. That is the
| principle App\Support\OrderTax exists to enforce, and the invoice lane already
| found and fixed a live case of the opposite. One test below proves it by moving
| the shop's tax settings to something else entirely between the order being
| placed and the order being edited.
|
| AND THE OPERATOR IS TOLD. A silent re-price is its own hazard, so a tax figure
| that moves writes a private order note into the history the order screen
| already draws.
|
| THE THREE BASES ARE NOT THE SAME SUM, and the old formula got two of them
| wrong rather than one:
|
|   exclusive   tax is ADDED on top.       total = base + tax
|   inclusive   tax is INSIDE the price.   total = base          (tax not added)
|   flat        printed, never charged.    total = base, tax_total 0
|
| `subtotal + shipping + fee + tax_total - discount` adds the tax unconditionally,
| so on an INCLUSIVE order the first edit inflated the total by the tax that was
| already inside it.
|
| NOTHING MOVES FOR AN ORDER WITH NO TAX RECORD. Every order placed before the
| tax engine, every order placed in the shipped `display` mode and every imported
| WooCommerce order has `tax_basis` NULL. OrderTax::recorded() answers null for
| all of them and the legacy sum is used unchanged — which matters most for the
| imported ones, because those carry a real Woo `tax_total` with no rate beside
| it and it has to keep being added exactly as it always was.
|
| Pest note: `toContain` reads a second argument as another needle rather than as
| a message, so explanations are written `expect(str_contains(...))->toBeTrue()`.
*/

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\TaxRule;
use Tests\ManualOrders;

beforeEach(function () {
    $this->admin = ManualOrders::admin();
});

/**
 * An order carrying its own tax record, priced the way the checkout prices one.
 *
 * subtotal 20000, discount 0, shipping 2000 — a taxable base of 22000.
 */
function taxedOrder(string $basis, float $rate = 15.0): Order
{
    $rule = new TaxRule($rate, $basis);
    $base = 22000;

    $order = Order::create([
        'order_number' => 'TAX-' . uniqid(),
        'email' => 'layla@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => $basis === TaxRule::FLAT ? 0 : $rule->taxOn($base),
        'tax_rate' => $rate,
        'tax_basis' => $basis,
        'total' => $rule->grossOf($base),
    ]);

    $order->items()->create([
        'name' => 'Toner', 'quantity' => 1,
        'unit_price' => 20000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    return $order;
}

/** A second AED 100.00 line, added through the screen the operator really uses. */
function addHundredDirhamLine(Order $order)
{
    $product = Product::create([
        'slug' => 'tax-line-' . uniqid(),
        'name' => 'Serum',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ]);

    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
}

/* ------------------------------------------------------------------ exclusive */

it('re-prices an exclusive order against the new subtotal', function () {
    $order = taxedOrder(TaxRule::EXCLUSIVE, 15.0);

    // 22000 base at 15% -> 3300 added, total 25300.
    expect((int) $order->tax_total)->toBe(3300)
        ->and((int) $order->total)->toBe(25300);

    addHundredDirhamLine($order)->assertOk();

    $order->refresh();

    // Base is now 30000 - 0 + 2000 = 32000; 15% of that is 4800.
    expect((int) $order->subtotal)->toBe(30000)
        ->and((int) $order->tax_total)->toBe(4800)
        ->and((int) $order->total)->toBe(36800);
});

it('re-prices at the rate the ORDER recorded, not the rate the shop holds today', function () {
    $order = taxedOrder(TaxRule::EXCLUSIVE, 15.0);

    // The shop changes its mind about tax entirely, after the order was placed.
    $settings = app(SettingsService::class);
    $settings->set('tax_mode', 'live');
    $settings->set('vat_rate', 30);
    $settings->set('vat_basis', 'exclusive');
    $settings->set('vat_country_rates', ['AE' => '30']);
    $settings->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    addHundredDirhamLine($order)->assertOk();

    $order->refresh();

    // 15% of 32000, not 30% of it. 9600 here would be the invoice-reprint
    // defect the tax lane already fixed, re-entering through the edit screen.
    expect((int) $order->tax_total)->toBe(4800)
        ->and((int) $order->total)->toBe(36800);
});

/* ------------------------------------------------------------------ inclusive */

it('keeps an inclusive order\'s tax inside the total rather than adding it', function () {
    $order = taxedOrder(TaxRule::INCLUSIVE, 5.0);

    // 22000 already contains 22000 x 5 / 105 = 1047.6 -> 1048. Total is the base.
    expect((int) $order->tax_total)->toBe(1048)
        ->and((int) $order->total)->toBe(22000);

    addHundredDirhamLine($order)->assertOk();

    $order->refresh();

    // Base 32000; contained tax 32000 x 5 / 105 = 1523.8 -> 1524; the total is
    // the base itself. The old sum wrote 33524 here.
    expect((int) $order->subtotal)->toBe(30000)
        ->and((int) $order->tax_total)->toBe(1524)
        ->and((int) $order->total)->toBe(32000);
});

/* ----------------------------------------------------------------------- flat */

it('leaves a printed-only order charging nothing', function () {
    $order = taxedOrder(TaxRule::FLAT, 5.0);

    expect((int) $order->tax_total)->toBe(0)
        ->and((int) $order->total)->toBe(22000);

    addHundredDirhamLine($order)->assertOk();

    $order->refresh();

    expect((int) $order->tax_total)->toBe(0)
        ->and((int) $order->total)->toBe(32000);
});

/* ------------------------------------------- orders with no tax record at all */

it('leaves an imported WooCommerce order\'s tax exactly where it found it', function () {
    // A real Woo tax figure with no rate and no basis beside it: recorded()
    // answers null, so the legacy sum has to be used, tax included, or every
    // imported order re-prices itself to a number Woo never charged.
    $order = Order::create([
        'order_number' => 'WOO-' . uniqid(),
        'email' => 'layla@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => 1111,
        'tax_rate' => null,
        'tax_basis' => null,
        'total' => 23111,
    ]);

    $order->items()->create([
        'name' => 'Toner', 'quantity' => 1,
        'unit_price' => 20000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    addHundredDirhamLine($order)->assertOk();

    $order->refresh();

    expect((int) $order->tax_total)->toBe(1111)
        ->and((int) $order->total)->toBe(33111);
});

/* ------------------------------------------------------- telling the operator */

it('writes an order note when the tax figure moves', function () {
    $order = taxedOrder(TaxRule::EXCLUSIVE, 15.0);

    addHundredDirhamLine($order)->assertOk();

    $note = OrderNote::query()->where('order_id', $order->id)->latest('id')->first();

    expect($note)->not->toBeNull('Editing the lines re-priced the tax and recorded nothing about it.');
    expect(str_contains((string) $note->content, 'Tax recalculated'))
        ->toBeTrue('The note does not say the tax was recalculated: ' . (string) $note->content);
    expect(str_contains((string) $note->content, '15%'))
        ->toBeTrue('The note does not name the rate it used: ' . (string) $note->content);
    expect((bool) $note->is_customer_note)
        ->toBeFalse('The tax note was written where the customer can read it.');
});

it('writes no note when there is no tax record to re-price', function () {
    $order = Order::create([
        'order_number' => 'QUIET-' . uniqid(),
        'email' => 'layla@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 22000,
    ]);

    $order->items()->create([
        'name' => 'Toner', 'quantity' => 1,
        'unit_price' => 20000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    addHundredDirhamLine($order)->assertOk();

    expect(OrderNote::query()->where('order_id', $order->id)->count())->toBe(0);
});

/* -------------------------------------------------------- removing and editing */

it('re-prices when a line is removed as well as when one is added', function () {
    $order = taxedOrder(TaxRule::EXCLUSIVE, 15.0);

    $order->items()->create([
        'name' => 'Serum', 'quantity' => 1,
        'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000,
    ]);

    // The ORIGINAL line goes, leaving the cheaper one — so the base falls
    // rather than returning to where it started, and a stale 3300 cannot pass
    // by coincidence.
    $toner = $order->items()->where('name', 'Toner')->firstOrFail();

    test()->actingAs($this->admin, 'admin')
        ->deleteJson('/admin-api/orders/' . $order->id . '/items/' . $toner->id)
        ->assertOk();

    $order->refresh();

    // Base 10000 - 0 + 2000 = 12000; 15% of that is 1800.
    expect((int) $order->subtotal)->toBe(10000)
        ->and((int) $order->tax_total)->toBe(1800)
        ->and((int) $order->total)->toBe(13800);
});
