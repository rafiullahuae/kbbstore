<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\ManualOrders;

/**
 * The product picker, on both order screens.
 *
 * WHAT THE OWNER REPORTED. "On Add order page, when search for products, it
 * appears and disappears instantly, it doesn't allow me to choose anything",
 * and "the product images should must show along with the name, and also show
 * product images in view order page".
 *
 * The first half is a browser fact and is proved in a browser — see
 * AdminProductPickerBrowserTest, which types into the real screen. What can be
 * pinned from here is the half a server answers for:
 *
 *   THE SHAPE. Each suggestion carries an image field, and it is null rather
 *   than absent for a product that has none — a screen cannot draw a
 *   placeholder for a key that is not in the payload.
 *
 *   THE ALLOWLIST. This is an admin endpoint, not /api/*, but the argument in
 *   CLAUDE.md is the same one: a payload built from a whole model row publishes
 *   every column added to the table afterwards. `products` carries wc_id,
 *   total_sales, cost_price and a page of description HTML; a type-ahead firing
 *   on every keystroke may ship none of it. Pinned as the exact key set AND as
 *   the SQL: the select names its columns.
 *
 *   THE LINE ITEMS. order_items snapshots a name, a brand, a SKU and a price
 *   but never a picture, so the image on a line item is the live product's —
 *   null when that product has none, and null again when it has been deleted
 *   out from under the order. Both are the case that drew an empty grey square.
 */
beforeEach(function () {
    ManualOrders::registerRoutes();
    ManualOrders::shop();
    $this->admin = ManualOrders::admin();
});

function pickerAdmin()
{
    return test()->actingAs(test()->admin, 'admin');
}

/* ------------------------------------------------- the suggestion payload */

it('gives every suggestion an image field, null when the product has none', function () {
    ManualOrders::product('Heartleaf Soothing Toner', 8900, [
        'image' => '/media/2026/heartleaf-toner.jpg',
    ]);
    ManualOrders::product('Heartleaf Cleansing Foam', 6900);   // no image at all

    $products = pickerAdmin()->getJson('/admin-api/manual-orders/products?q=heartleaf')
        ->assertOk()
        ->json('products');

    expect($products)->toHaveCount(2);

    $byName = collect($products)->keyBy('name');

    // Present and correct for the one that has a picture …
    expect($byName['Heartleaf Soothing Toner']['image'])->toBe('/media/2026/heartleaf-toner.jpg');

    // … and PRESENT AND NULL for the one that does not. A missing key and a
    // null one read the same in PHP and not at all the same in JavaScript:
    // `p.image` on an absent key is undefined, which is falsy, so this would
    // pass by accident while the row that needs a placeholder is the one the
    // test never checked.
    expect($byName['Heartleaf Cleansing Foam'])->toHaveKey('image')
        ->and($byName['Heartleaf Cleansing Foam']['image'])->toBeNull();
});

it('publishes only the fields the picker draws, never the product row', function () {
    ManualOrders::product('Snail Mucin Essence', 7900, [
        'image' => '/media/2026/snail.jpg',
        'wc_id' => 4821,
        'total_sales' => 913,
        'description' => str_repeat('<p>A page of marketing copy.</p>', 40),
    ]);

    $row = pickerAdmin()->getJson('/admin-api/manual-orders/products?q=snail')
        ->assertOk()
        ->json('products.0');

    // The whole allowlist, spelled out. A column added to `products` later is
    // private until someone comes here and says otherwise.
    expect(array_keys($row))->toBe([
        'id', 'name', 'sku', 'brand', 'image', 'price_fils', 'stock', 'stock_status', 'variants',
    ]);

    foreach (['wc_id', 'total_sales', 'description', 'cost_price', 'seo'] as $private) {
        $leaked = array_key_exists($private, $row);
        expect($leaked)->toBeFalse("the suggestion payload carries `{$private}`, which nothing on the screen draws");
    }
});

it('selects the columns it needs rather than hydrating the whole row', function () {
    ManualOrders::product('Rice Toner', 5900, [
        'description' => str_repeat('<p>Long.</p>', 50),
    ]);

    $statements = [];

    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    pickerAdmin()->getJson('/admin-api/manual-orders/products?q=rice')->assertOk();

    DB::flushQueryLog();

    /*
     * EVERY read of the products table, not the first one.
     *
     * The first is `select count(*) as aggregate from products` — the paging
     * total — which is not a wildcard by any regex and would let this test pass
     * against `select *` on the row query right behind it. That is not a
     * hypothetical: this assertion was written that way first and stayed green
     * with the allowlist removed.
     */
    $reads = collect($statements)
        ->filter(fn (string $sql) => str_contains($sql, 'from "products"') || str_contains($sql, 'from `products`'))
        ->reject(fn (string $sql) => str_contains($sql, 'count(*)'))
        ->values();

    expect($reads)->not->toBeEmpty('no row-level select against products was issued at all');

    foreach ($reads as $sql) {
        $wildcard = (bool) preg_match('/select\s+(distinct\s+)?([`"]?products[`"]?\.)?\*/i', $sql);
        expect($wildcard)->toBeFalse("the type-ahead reads the whole product row: {$sql}");

        $columns = trim((string) preg_replace('/^select\s+(.*?)\s+from\s+.*$/is', '$1', $sql));
        $heavy = str_contains($columns, 'description');
        expect($heavy)->toBeFalse("the type-ahead reads `description`, a page of HTML per suggestion: {$sql}");
    }
});

it('prices a suggestion from the sale window, in fils', function () {
    ManualOrders::product('Sale Serum', 12000, [
        'sale_price' => 9000,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);

    expect(pickerAdmin()->getJson('/admin-api/manual-orders/products?q=sale%20serum')
        ->assertOk()->json('products.0.price_fils'))->toBe(9000);
});

/* ------------------------------------------------------ the line items */

it('carries the live product image on each line of an order', function () {
    $withImage = ManualOrders::product('Barrier Cream', 7900, ['image' => '/media/2026/barrier.jpg']);
    $without = ManualOrders::product('Plain Balm', 4900);

    $customer = Customer::create(['name' => 'Layla', 'email' => 'layla-picker@example.ae']);

    $order = Order::create([
        'order_number' => 'KBB-PICKER-1',
        'customer_id' => $customer->id,
        'status' => 'processing',
        'currency' => 'AED',
        'email' => $customer->email,
        'subtotal' => 12800, 'total' => 12800,
    ]);

    foreach ([$withImage, $without] as $product) {
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1,
            'unit_price' => $product->price, 'subtotal' => $product->price, 'total' => $product->price,
        ]);
    }

    $items = collect(pickerAdmin()->getJson("/admin-api/orders/{$order->id}/detail")
        ->assertOk()->json('items'))->keyBy('name');

    expect($items['Barrier Cream']['image'])->toBe('/media/2026/barrier.jpg')
        ->and($items['Plain Balm'])->toHaveKey('image')
        ->and($items['Plain Balm']['image'])->toBeNull();

    /*
     * And the line survives its product being deleted — order_items.product_id
     * is nullOnDelete, so this is a real state for any order old enough. The
     * name is the order's own snapshot; only the picture goes.
     */
    $withImage->forceDelete();

    $after = collect(pickerAdmin()->getJson("/admin-api/orders/{$order->id}/detail")
        ->assertOk()->json('items'))->keyBy('name');

    expect($after['Barrier Cream']['name'])->toBe('Barrier Cream')
        ->and($after['Barrier Cream']['image'])->toBeNull();
});

it('reads the line item images in one statement rather than one per line', function () {
    $customer = Customer::create(['name' => 'Layla', 'email' => 'layla-n1@example.ae']);

    $order = Order::create([
        'order_number' => 'KBB-PICKER-2',
        'customer_id' => $customer->id,
        'status' => 'processing',
        'currency' => 'AED',
        'email' => $customer->email,
        'subtotal' => 0, 'total' => 0,
    ]);

    // Eight lines: an N+1 is invisible at two.
    for ($i = 0; $i < 8; $i++) {
        $product = ManualOrders::product('N Plus One '.$i, 1000 + $i, ['image' => "/media/n{$i}.jpg"]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1,
            'unit_price' => 1000, 'subtotal' => 1000, 'total' => 1000,
        ]);
    }

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    pickerAdmin()->getJson("/admin-api/orders/{$order->id}/detail")->assertOk();

    $productReads = collect($statements)
        ->filter(fn (string $sql) => str_contains($sql, 'from "products"') || str_contains($sql, 'from `products`'))
        ->count();

    expect($productReads)->toBeLessThanOrEqual(2, 'the order detail reads products once per line item');
});
