<?php

declare(strict_types=1);

/**
 * AN AUDIT NOTE MAY NOT RECORD THAT NOTHING HAPPENED — Lane EZ.
 *
 * AdminOrderController::recalcTotals() re-prices an edited order's tax and,
 * when the figure MOVES, writes a private OrderNote saying so. The note is the
 * only record that the re-price happened at all, it is written into a row with
 * no edit screen behind it, and `order_notes` has no update path in this
 * application — so whatever it says is what it says forever.
 *
 * It said it with Money::plain(), which follows Money::displayDecimals(), and
 * displayDecimals() is 0 on this store. So any movement smaller than half a
 * dirham recorded itself as
 *
 *     Tax recalculated after the items changed: AED 4 → AED 4 at 2% exclusive…
 *
 * a permanent, uncorrectable audit entry stating that a figure changed to the
 * value it already had. The rate and the basis beside it are exact; only the
 * two figures the sentence is ABOUT were rounded away.
 *
 * The test below moves the tax by 8 fils — from 440 to 448 — which is a real
 * movement and a rounded no-op, and asserts the note names both figures at the
 * currency's own precision. It is stated against the ORDER'S OWN COLUMN rather
 * than against a formatted constant, so it cannot be satisfied by rendering
 * anything other than the amounts that actually moved.
 *
 * THE SWEEP THIS CAME FROM. Eight places in this application write an order
 * note. Seven were already right: the payment trail (PaymentCapturer,
 * PaymentRefunder, PaymentConfirmer, all through PaymentLedger::note) renders
 * with Money::amount($fils, 2) — full precision, explicitly — and
 * ManualOrderBuilder uses Fils::toDecimalString(), which is exact by
 * construction. The remaining three (AdminOrderController::addNote,
 * OrderStatus, PaymentConfirmer's two "ACTION NEEDED" notes) carry no money at
 * all. This was the one.
 */

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Product;
use App\Support\Money;
use App\Support\TaxRule;
use Tests\ManualOrders;

beforeEach(function () {
    $this->admin = ManualOrders::admin();
});

it('names both tax figures at full precision, so a sub-dirham move is not recorded as no move', function () {
    /*
     * 2% exclusive on a base of 22000 fils (subtotal 20000 + delivery 2000).
     * Adding a AED 4.00 line takes the base to 22400 and the tax from 440 to
     * 448 — eight fils, which at whole-dirham display is AED 4 both times.
     */
    $rule = new TaxRule(2.0, TaxRule::EXCLUSIVE);

    $order = Order::create([
        'order_number' => 'AUDIT-' . uniqid(),
        'email' => 'layla@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => $rule->taxOn(22000),
        'tax_rate' => 2.0,
        'tax_basis' => TaxRule::EXCLUSIVE,
        'total' => $rule->grossOf(22000),
    ]);

    $order->items()->create([
        'name' => 'Toner', 'quantity' => 1,
        'unit_price' => 20000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    $was = (int) $order->tax_total;

    $product = Product::create([
        'slug' => 'audit-line-' . uniqid(),
        'name' => 'Sample sachet',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 400,
        'stock_status' => 'instock',
    ]);

    test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/items', ['product_id' => $product->id, 'quantity' => 1])
        ->assertOk();

    $order->refresh();
    $now = (int) $order->tax_total;

    // The premise: the figure really did move, and it moved by less than half
    // a dirham. If either stops being true the test below proves nothing, so
    // both are asserted rather than assumed.
    expect($now)->not->toBe($was, 'the fixture did not actually move the tax figure');
    expect(abs($now - $was))->toBeLessThan(50, 'the movement is not a sub-dirham one');
    expect(Money::plain($was))->toBe(Money::plain($now), 'the rounded rendering does not collide, so there is nothing to catch');

    $note = OrderNote::query()->where('order_id', $order->id)->latest('id')->first();

    expect($note)->not->toBeNull('the tax moved and nothing was recorded');

    $content = (string) $note->content;

    // Both figures, at the currency's real precision, taken from the columns.
    expect($content)->toContain(Money::plain($was, Money::minorExponent()));
    expect($content)->toContain(Money::plain($now, Money::minorExponent()));

    // And the note must not be the sentence that records no change.
    expect($content)->not->toContain(
        Money::plain($was, 0) . ' → ' . Money::plain($now, 0),
        'the audit note records the figure changing to the value it already had'
    );
});
