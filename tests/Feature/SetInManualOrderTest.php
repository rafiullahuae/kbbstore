<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductSetItem;
use Illuminate\Support\Str;

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE MANUAL ORDER RECEIPT. (Lane SE)
 *
 * An operator can build an order for a customer over the phone, and
 * ManualOrderBuilder already writes `order_items.set_contents` on a Set line it
 * creates. The screen that reads the finished order back to them was the one
 * place that snapshot was written and never shown — on the very screen whose
 * job is to let somebody check what they just created before acting on it.
 * ═══════════════════════════════════════════════════════════════════════════
 */
it('reads a manually built Set order back with its members listed', function () {
    /*
     * MUTATION NOTE — RUN, both directions, 2026-09-28. Delete the
     * `'set_contents' => ...` line from AdminOrderController's manual-order
     * response and the first expectation fails; the ordinary line's empty list
     * is asserted beside it, because that empty list is what keeps every manual
     * order this shop has already taken unchanged.
     */
    \Tests\ManualOrders::registerRoutes();
    \Tests\ManualOrders::shop();

    $admin = \Tests\ManualOrders::admin();
    $customer = \Tests\ManualOrders::customer();

    $toner = \Tests\ManualOrders::product('Heartleaf Toner', 9000);
    $serum = \Tests\ManualOrders::product('Azelaic Serum', 5000);
    $plain = \Tests\ManualOrders::product('Rice Cleanser', 7000);

    $set = Product::create([
        'slug' => 'manual-set-'.Str::random(6),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $toner->id, 'quantity' => 1, 'position' => 0]);
    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $serum->id, 'quantity' => 2, 'position' => 1]);

    $response = test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/manual-orders', \Tests\ManualOrders::payload([
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $set->id, 'quantity' => 1],
                ['product_id' => $plain->id, 'quantity' => 1],
            ],
        ]))
        ->assertCreated();

    $items = collect($response->json('order.items'))->keyBy('name');

    expect($items['Glow Set']['set_contents'])->toBe(['1 × Heartleaf Toner', '2 × Azelaic Serum'])
        ->and($items['Rice Cleanser']['set_contents'])->toBe([]);
});
