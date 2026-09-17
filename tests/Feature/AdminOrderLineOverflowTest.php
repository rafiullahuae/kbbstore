<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Import\Money as ImportMoney;
use Tests\ManualOrders;

/*
|------------------------------------------------------------------------------
| Order lines cannot be written past what the column holds
|------------------------------------------------------------------------------
|
| order_items.unit_price, .subtotal and .total are `$t->integer` — signed
| 32-bit, so 2,147,483,647 fils (AED 21,474,836.47) is the ceiling. Both the
| Phase 0 schema and 2026_09_15_020000_repair_order_tables declare them that
| way, and orders.subtotal / .total are the same.
|
| Past that, the two engines disagree, and only one of them is loud. MySQL in
| strict mode raises, so the operator gets a 500 on a save they had no reason to
| expect would fail. SQLite stores the wrapped number in silence — an order that
| reads as placed, carrying a total that is negative or nonsense. The tests run
| on SQLite, so a bound that is not asserted here is a bound that does not exist
| as far as this suite is concerned; the assertions are on the REFUSAL, not on
| the stored value, for exactly that reason.
|
| The boundary itself is asserted, not a value comfortably below it: MAX_FILS
| accepted, MAX_FILS + 1 refused, and a quantity that pushes the PRODUCT over
| refused even though the unit price on its own is fine.
*/

beforeEach(function () {
    $this->admin = ManualOrders::admin();

    $this->order = Order::create([
        'order_number' => 'OVF-1',
        'email' => 'layla@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 0,
        'total' => 0,
    ]);

    $this->line = $this->order->items()->create([
        'name' => 'Existing line',
        'quantity' => 1,
        'unit_price' => 1000,
        'subtotal' => 1000,
        'total' => 1000,
    ]);
});

function editItem(array $payload)
{
    return test()->actingAs(test()->admin, 'admin')
        ->putJson('/admin-api/orders/' . test()->order->id . '/items/' . test()->line->id, $payload);
}

function addItem(array $payload)
{
    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/orders/' . test()->order->id . '/items', $payload);
}

/* ------------------------------------------------------------ the unit price */

it('accepts a unit price of exactly the column ceiling', function () {
    /*
     * THE CEILING A LINE CAN BE GIVEN IS THE TOP WHOLE DIRHAM — Lane FA.
     *
     * 2,147,483,647 fils is AED 21,474,836.47, the largest value the column
     * holds, and it carries 47 fils — so the whole-dirham policy refuses it.
     * The largest a line can be priced at is one whole dirham below that, and
     * the refusal is for the decimals rather than for the size, which is what
     * the two assertions here separate.
     *
     * The point of this test is untouched: a legitimate value at the very top
     * of the range is ACCEPTED, so the bound is the column's own rather than
     * something narrower that silently blocks a real price.
     */
    editItem(['unit_price_aed' => '21474836.47', 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    $topWhole = intdiv(ImportMoney::MAX_FILS, 100);

    editItem(['unit_price_aed' => (string) $topWhole, 'quantity' => 1])->assertOk();

    expect(OrderItem::find($this->line->id)->unit_price)->toBe($topWhole * 100);
});

it('refuses a unit price one fil past the ceiling', function () {
    editItem(['unit_price_aed' => '21474836.48', 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('unit_price_aed');

    expect(OrderItem::find($this->line->id)->unit_price)->toBe(1000);
});

it('refuses the absurd unit price that overflows on its own', function () {
    // The coordinator's example: 99,999,999 AED is 9,999,999,900 fils.
    editItem(['unit_price_aed' => '99999999'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('unit_price_aed');
});

it('parses the unit price digit by digit, not through a float', function () {
    /*
     * (int) round(1.15 * 100) happens to give 115, but 1.15 is not
     * representable and round() is rescuing it. The parse never builds the
     * float at all.
     *
     * Since Lane FA a line price is a whole number of dirhams, so the two fils
     * values this test was written around are refused. What it checks is the
     * PARSE — the digits an operator typed become the exact integer at every
     * magnitude — and that is unchanged; the refusals below pin the other half
     * so the accepted list cannot be read as "decimals were forgotten".
     */
    editItem(['unit_price_aed' => '1', 'quantity' => 1])->assertOk();
    expect(OrderItem::find($this->line->id)->unit_price)->toBe(100);

    editItem(['unit_price_aed' => '8', 'quantity' => 1])->assertOk();
    expect(OrderItem::find($this->line->id)->unit_price)->toBe(800);

    editItem(['unit_price_aed' => '1.15', 'quantity' => 1])->assertStatus(422);
    editItem(['unit_price_aed' => '8.20', 'quantity' => 1])->assertStatus(422);

    expect(OrderItem::find($this->line->id)->unit_price)->toBe(800);
});

it('refuses more precision than a fil can hold rather than rounding it away', function () {
    editItem(['unit_price_aed' => '0.145'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('unit_price_aed');
});

it('refuses the scientific notation that `numeric` would have accepted', function () {
    // 1e9 AED passes a `numeric` rule and is 100,000,000,000 fils.
    editItem(['unit_price_aed' => '1e9'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('unit_price_aed');
});

it('refuses a negative unit price', function () {
    editItem(['unit_price_aed' => '-5'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('unit_price_aed');
});

/* -------------------------------------------------------------- the quantity */

it('refuses an unbounded quantity on add', function () {
    $product = ManualOrders::product('Toner', 8900);

    addItem(['product_id' => $product->id, 'quantity' => 2147483647])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity');

    expect($this->order->items()->count())->toBe(1);
});

it('refuses an unbounded quantity on update', function () {
    editItem(['quantity' => 1000000])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity');
});

/* ------------------------------------------- the product, which is the real bug */

it('refuses a quantity that overflows the line even though the unit price is fine', function () {
    // Each factor on its own is perfectly sane. AED 21,474,836 is a real
    // ceiling under the whole-dirham policy (Lane FA), and 2 is a real
    // quantity; their product is not.
    $topWhole = intdiv(ImportMoney::MAX_FILS, 100);

    editItem(['unit_price_aed' => (string) $topWhole, 'quantity' => 1])->assertOk();

    editItem(['quantity' => 2])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    // Nothing was written. The quantity is still 1 and the line is intact.
    $line = OrderItem::find($this->line->id);
    expect($line->quantity)->toBe(1)
        ->and($line->total)->toBe($topWhole * 100);
});

it('refuses an add whose product overflows, with both factors in range', function () {
    // AED 1,000,000 a unit is in range; 99 of them is not.
    $product = ManualOrders::product('Gold bar', 100000000);

    addItem(['product_id' => $product->id, 'quantity' => 99])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect($this->order->items()->count())->toBe(1);
});

it('refuses a line that fits but takes the ORDER total past the column', function () {
    // The sum across lines is its own overflow, and no per-line check catches
    // it: this line is a third of the ceiling and so is each of the two
    // already there.
    $third = intdiv(ImportMoney::MAX_FILS, 3) + 1;

    $this->line->update(['unit_price' => $third, 'subtotal' => $third, 'total' => $third]);
    $this->order->items()->create([
        'name' => 'Second', 'quantity' => 1,
        'unit_price' => $third, 'subtotal' => $third, 'total' => $third,
    ]);

    $product = ManualOrders::product('Third', $third);

    addItem(['product_id' => $product->id, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect($this->order->items()->count())->toBe(2);
});

/* --------------------------------------------- and the manual-order path too */

it('refuses a manual order whose total would not fit', function () {
    ManualOrders::registerRoutes();
    ManualOrders::shop();

    // One line, at a price that fits, in a quantity that does not.
    $product = ManualOrders::product('Gold bar', 100000000);   // AED 1,000,000

    $this->actingAs($this->admin, 'admin')
        ->postJson('/admin-api/manual-orders', ManualOrders::payload([
            'new_customer' => ['name' => 'Big Spender', 'email' => 'big@example.ae'],
            'items' => [['product_id' => $product->id, 'quantity' => 99]],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    // The refusal happens before anything is written, so no half-order is left.
    expect(Order::where('order_number', '!=', 'OVF-1')->count())->toBe(0);
});

it('refuses a manual order whose delivery override is past the column', function () {
    ManualOrders::registerRoutes();
    ManualOrders::shop();

    $product = ManualOrders::product('Toner', 8900);

    $this->actingAs($this->admin, 'admin')
        ->postJson('/admin-api/manual-orders', ManualOrders::payload([
            'new_customer' => ['name' => 'Someone', 'email' => 'someone@example.ae'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_override' => '21474836.48',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('shipping_override');
});
