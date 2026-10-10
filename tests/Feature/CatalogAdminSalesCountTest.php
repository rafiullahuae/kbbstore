<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogProductsAdminRoutes;

/**
 * CATALOG → REORDER AND CATALOG → PRODUCTS COUNT A PAGE'S SALES ONCE. (Lane RO)
 *
 * THE DEFECT ON THE SHOP, in the owner's words: "the products, specially in
 * re-order tab, taking much more time to load and upon selection any quantity
 * to show the products, it stucks and keep loading."
 *
 * Two causes, both measured on MySQL 8.0 at 60,000 orders / 270,000 lines:
 *
 *  - Reorder counted each product's orders with a correlated subquery in the
 *    select list. MySQL runs it for every product in the scope before it sorts
 *    and cuts the page, and on an `order_items` with no product_id index (a
 *    table the 2026_09_15 repair rebuilt column by column) each run reads the
 *    whole table: 11.9 s for a 205-product category, at ANY page size. Now one
 *    grouped statement over the page's ids: 0.13 s without the index.
 *
 *  - The screen parsed whatever came back as a list. An error or a timeout
 *    page threw on `.products` outside the try, so "Loading…" never ended.
 *
 * The Products list joined a whole-shop sales aggregate to every page (0.46 -
 * 0.76 s); now it counts the page it shows, except for the two sorts that rank
 * BY sales and genuinely need all of it. Every response was compared byte for
 * byte, old code against new, on that dataset: identical in all 11 cases.
 */
function rosAdmin(): AdminUser
{
    return AdminUser::create(['name' => 'O', 'email' => 'ro-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => 'owner']);
}

function rosProduct(string $slug, ?Brand $brand = null): Product
{
    return Product::create([
        'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock', 'brand_id' => $brand?->id,
    ]);
}

/** One order holding a line for each product given. */
function rosOrder(array $lines, string $status = 'completed'): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'order_number' => 'RO-'.$n, 'email' => "ro-{$n}@x.test", 'status' => $status,
        'currency' => 'AED', 'subtotal' => 1000, 'total' => 1000,
    ]);

    foreach ($lines as [$product, $qty, $total]) {
        $order->items()->create([
            'product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty,
            'unit_price' => intdiv($total, max(1, $qty)), 'subtotal' => $total, 'total' => $total,
        ]);
    }

    return $order;
}

/** Every statement a request ran. */
function rosStatements(callable $request): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $log = array_map(fn ($q) => strtolower($q['query']), DB::getQueryLog());
    DB::disableQueryLog();

    return $log;
}

it('counts each product\'s real orders on the Reorder screen exactly as before', function () {
    $cat = Category::create(['name' => 'Pre Order', 'slug' => 'pre-order-ro']);
    $a = rosProduct('ro-a');
    $b = rosProduct('ro-b');
    $c = rosProduct('ro-c');
    $cat->products()->attach([$a->id, $b->id, $c->id]);

    rosOrder([[$a, 1, 1000], [$b, 2, 2000]]);          // a, b
    rosOrder([[$a, 1, 1000], [$a, 3, 3000]], 'processing'); // a again: TWO lines, ONE order
    rosOrder([[$a, 1, 1000]], 'pending');              // not a real order
    rosOrder([[$b, 1, 1000]], 'cancelled');            // not a real order
    rosOrder([[$b, 1, 1000]])->delete();               // trashed: not counted

    $rows = collect($this->actingAs(rosAdmin(), 'admin')
        ->getJson("/admin-api/catalog/reorder/category/{$cat->id}/products?per_page=50")
        ->assertOk()->json('products'))->keyBy('id');

    expect($rows[$a->id]['orders_count'])->toBe(2)
        ->and($rows[$b->id]['orders_count'])->toBe(1)
        ->and($rows[$c->id]['orders_count'])->toBe(0);
});

it('reads a Reorder page\'s orders in ONE statement over its ids, never one per product', function () {
    /*
     * MUTATION, RUN: put the correlated subquery back in the select list
     * (`->addSelect(['orders_count' => OrderItem::selectRaw(...)
     * ->whereColumn('product_id', 'products.id')...])`). The page statement
     * then names order_items, and this is red. On the live order history that
     * statement was the twelve seconds.
     */
    $cat = Category::create(['name' => 'Big', 'slug' => 'big-ro']);
    $brand = Brand::create(['name' => 'Ro Brand', 'slug' => 'ro-brand']);
    $ids = [];
    foreach (range(1, 12) as $i) {
        $p = rosProduct("ro-many-{$i}", $brand);
        $ids[] = $p->id;
        rosOrder([[$p, 1, 1000]]);
    }
    $cat->products()->attach($ids);
    $admin = rosAdmin();

    foreach (["category/{$cat->id}", "brand/{$brand->id}"] as $scope) {
        $log = rosStatements(fn () => $this->actingAs($admin, 'admin')
            ->getJson("/admin-api/catalog/reorder/{$scope}/products?per_page=50")->assertOk());

        $sales = array_values(array_filter($log, fn ($q) => str_contains($q, 'order_items')));

        expect($sales)->toHaveCount(1)
            ->and($sales[0])->toContain('"order_items"."product_id" in')
            ->and($sales[0])->not->toContain('"products"."id"');
    }
});

it('costs a Reorder page the same statements for 3 products as for 40', function () {
    $count = function (int $n): int {
        $cat = Category::create(['name' => "Flat {$n}", 'slug' => "flat-ro-{$n}"]);
        foreach (range(1, $n) as $i) {
            $p = rosProduct("ro-flat-{$n}-{$i}");
            $cat->products()->attach($p->id);
            rosOrder([[$p, 1, 1000]]);
        }
        $admin = rosAdmin();

        return count(rosStatements(fn () => $this->actingAs($admin, 'admin')
            ->getJson("/admin-api/catalog/reorder/category/{$cat->id}/products?per_page=50")->assertOk()));
    };

    expect($count(40))->toBe($count(3));
});

it('counts only the shown page\'s sales on the Products list, and the same figures as before', function () {
    /*
     * MUTATION, RUN: in listing(), call withSalesTotals() whatever the sort.
     * The page statement then carries the whole-shop aggregate (a derived
     * table grouped over every order line) and the first expectation is red.
     */
    CatalogProductsAdminRoutes::wire(app());
    DB::table('category_product')->delete();
    DB::table('order_items')->delete();
    DB::table('orders')->delete();
    DB::table('products')->delete();
    $this->actingAs(rosAdmin(), 'admin');

    $a = rosProduct('rl-a');
    $b = rosProduct('rl-b');
    $c = rosProduct('rl-c');
    rosOrder([[$a, 2, 2000], [$b, 1, 500]]);
    rosOrder([[$a, 1, 1000], [$a, 1, 1000]], 'processing');
    rosOrder([[$a, 5, 5000]], 'pending');
    rosOrder([[$b, 4, 4000]])->delete();

    $log = rosStatements(fn () => $this->getJson('/admin-api/catalog-products-list?per_page=100')->assertOk());
    $sales = array_values(array_filter($log, fn ($q) => str_contains($q, 'order_items')));

    expect($sales)->toHaveCount(1)
        ->and($sales[0])->toContain('"order_items"."product_id" in')
        ->and($sales[0])->not->toContain('left join');

    $rows = collect($this->getJson('/admin-api/catalog-products-list?per_page=100')->assertOk()->json('products'))->keyBy('id');

    expect([$rows[$a->id]['orders_count'], $rows[$a->id]['units_sold'], $rows[$a->id]['revenue_fils']])->toBe([2, 4, 4000])
        ->and([$rows[$b->id]['orders_count'], $rows[$b->id]['units_sold'], $rows[$b->id]['revenue_fils']])->toBe([1, 1, 500])
        ->and([$rows[$c->id]['orders_count'], $rows[$c->id]['units_sold'], $rows[$c->id]['revenue_fils']])->toBe([0, 0, 0]);

    // A sort BY sales still ranks the whole catalogue, and agrees.
    $ranked = $this->getJson('/admin-api/catalog-products-list?per_page=100&sort=orders_desc')->assertOk()->json('products');
    expect(array_column($ranked, 'id'))->toBe([$a->id, $b->id, $c->id])
        ->and(collect($ranked)->keyBy('id')->map(fn ($r) => [$r['orders_count'], $r['units_sold'], $r['revenue_fils']])->all())
        ->toEqual($rows->map(fn ($r) => [$r['orders_count'], $r['units_sold'], $r['revenue_fils']])->all());
});

it('gives order_items its two lookup indexes, once, and nothing where one exists', function () {
    $leading = fn () => collect(Schema::getIndexes('order_items'))->map(fn ($i) => $i['columns'][0] ?? null)->all();

    expect($leading())->toContain('product_id')->toContain('order_id');

    // Applied again on a server that already has them: nothing more.
    $before = count(Schema::getIndexes('order_items'));
    $migration = require database_path('migrations/2027_10_24_100000_index_order_items_lookups_and_clear_caches.php');
    ob_start();
    $migration->up();
    ob_end_clean();

    expect(count(Schema::getIndexes('order_items')))->toBe($before);
});

it('ends "Loading…" on every answer, and lets only the latest page-size change paint', function () {
    /*
     * MUTATION, RUN: restore `reorderData=await r.json();` with no r.ok check.
     * A 500 or a timeout page then threw on `.products` outside the try and
     * the screen stayed on "Loading…" for good -- the owner's "it stucks".
     */
    $src = file_get_contents(resource_path('views/admin/app.blade.php'));
    $from = strpos($src, 'async function reorderLoadProducts(){');
    $fn = substr($src, $from, strpos($src, "\nfunction reorderPaint(){", $from) - $from);

    expect($fn)->toContain('const seq=++reorderSeq;')
        ->toContain('if(!r.ok || !d || !Array.isArray(d.products))')
        ->toContain('if(seq!==reorderSeq) return;')
        ->not->toContain('reorderData=await r.json();')
        ->and(substr_count($src, 'reorderSeq=0'))->toBe(1);
});
