<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogProductsAdminRoutes;

/**
 * Catalog -> Products: 100 / 200 / 300 per page, and pages 2 and 3. (Lane QK13)
 *
 * The owner, 10 October: "the pagination on the catalog page, page 2-3 is not
 * working, and also if we select the number of products to show, it's not
 * working at all. keep 100 by default and fix the 200 to display, and also add
 * 300 selection too."
 *
 * What the screen offered was 25 / 50 / 100 / 200 / 500 with 50 as the
 * default, against a server that clamped anything into 10..500. Now the select
 * offers exactly 100, 200 and 300, the server answers exactly those, and
 * anything else -- including the 50 a browser remembered from the old select --
 * is 100.
 *
 * 650 products: seven pages at 100, four at 200, three at 300 (the last one 50
 * rows), so page 3 is a real page at every size.
 */
function qk13Catalogue(int $n = 650): array
{
    CatalogProductsAdminRoutes::wire(app());
    DB::table('category_product')->delete();
    DB::table('order_items')->delete();
    DB::table('orders')->delete();
    DB::table('products')->delete();

    $brand = Brand::create(['name' => 'QK13 Brand', 'slug' => 'qk13-brand-'.uniqid()]);
    $category = Category::create(['name' => 'QK13 Category', 'slug' => 'qk13-cat-'.uniqid()]);
    $base = now()->subDay();

    $rows = [];
    foreach (range(1, $n) as $i) {
        $rows[] = [
            'name' => sprintf('QK13 Product %03d', $i), 'slug' => sprintf('qk13-p-%03d', $i), 'sku' => sprintf('QK13-%03d', $i),
            'brand_id' => $i % 4 === 0 ? null : $brand->id, 'type' => 'simple', 'status' => 'publish', 'is_visible' => 1,
            'price' => 1000 + $i, 'stock_status' => 'instock', 'manage_stock' => $i % 2, 'stock' => $i,
            'image' => $i % 5 === 0 ? null : 'https://cdn.test/qk13-'.$i.'.jpg',
            // The list's default sort is newest first: 001 is the newest row,
            // so page p at size s starts with product (p-1)*s+1.
            'created_at' => $base->copy()->subMinutes($i), 'updated_at' => $base,
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('products')->insert($chunk);
    }

    $ids = DB::table('products')->pluck('id', 'slug');
    DB::table('category_product')->insert($ids->values()->filter(fn ($id, $k) => $k % 3 !== 0)
        ->map(fn ($id) => ['product_id' => $id, 'category_id' => $category->id])->values()->all());

    // Sales on rows spread over all three pages, so the sales aggregate has
    // something to join on every page measured below.
    foreach (array_filter([5, 150, 260, 420, 610], fn ($i) => $i <= $n) as $k => $i) {
        $order = Order::create(['order_number' => 'QK13-'.$k, 'email' => 'qk13-'.$k.'@example.test', 'status' => 'completed',
            'currency' => 'AED', 'subtotal' => 5000, 'total' => 5000]);
        $order->items()->create(['product_id' => $ids[sprintf('qk13-p-%03d', $i)], 'name' => 'x', 'quantity' => 2,
            'unit_price' => 2500, 'subtotal' => 5000, 'total' => 5000]);
    }

    test()->actingAs(AdminUser::create(['name' => 'QK13 Owner', 'email' => 'qk13-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    return ['brand' => $brand, 'category' => $category];
}

function qk13List(string $query): array
{
    return test()->getJson('/admin-api/catalog-products-list?'.$query)->assertOk()->json();
}

it('honours 100, 200 and 300 per page, and defaults to 100', function () {
    // The defect: the owner picked 200 and the screen did not show 200. The
    // server must answer exactly the size asked for, when it is one of the three.
    qk13Catalogue();

    foreach ([100, 200, 300] as $size) {
        $body = qk13List('per_page='.$size);

        expect($body['per_page'])->toBe($size)
            ->and($body['products'])->toHaveCount($size)
            ->and($body['total'])->toBe(650)
            ->and($body['last_page'])->toBe((int) ceil(650 / $size));
    }

    $default = qk13List('page=1');

    expect($default['per_page'])->toBe(100)->and($default['products'])->toHaveCount(100);
});

it('answers 100 for any page size the screen does not offer', function () {
    // 50 is the old default a browser may still remember; 25 and 500 were the
    // old select's other ends; the rest are what a hand-typed URL can carry.
    qk13Catalogue(120);

    foreach (['50', '25', '500', '10', '99999', '0', '-5', 'abc', '', '150', '1e3'] as $bogus) {
        expect(qk13List('per_page='.urlencode($bogus))['per_page'])->toBe(100, 'per_page='.$bogus);
    }
});

it('returns the right rows on pages 2 and 3 at every size', function () {
    // The defect: page 2 and page 3 "not working". Each page must be the next
    // slice of the same ordering -- no repeats, no gaps -- and the last page
    // must hold the remainder.
    qk13Catalogue();

    foreach ([100, 200, 300] as $size) {
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $body = qk13List('per_page='.$size.'&page='.$page);
            $names = array_column($body['products'], 'name');
            $first = ($page - 1) * $size + 1;

            expect($body['page'])->toBe($page)
                ->and($names[0])->toBe(sprintf('QK13 Product %03d', $first), "size {$size} page {$page}")
                ->and(count($names))->toBe(min($size, 650 - ($page - 1) * $size));

            $seen = array_merge($seen, $names);
        }
        expect(count(array_unique($seen)))->toBe(count($seen));
    }

    // 300 per page, page 3: products 601..650.
    $last = qk13List('per_page=300&page=3');
    expect($last['products'])->toHaveCount(50)
        ->and(end($last['products'])['name'])->toBe('QK13 Product 650');
});

it('runs the same number of queries on page 1 and page 3, at 100 and at 300', function () {
    // BrandPageOwnerAsksTest's method: the cost of a page is flat, whatever
    // page and whatever size. A per-row query would make 300 cost more than
    // 100; an offset-sensitive aggregate would make page 3 differ from page 1.
    qk13Catalogue();
    qk13List('per_page=100');   // warm the once-per-process settings read

    $count = function (string $q): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        qk13List($q);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $p1 = $count('per_page=100&page=1');
    $p3 = $count('per_page=100&page=3');
    $big = $count('per_page=300&page=1');
    $big3 = $count('per_page=300&page=3');

    expect($p3)->toBe($p1)->and($big)->toBe($p1)->and($big3)->toBe($p1)
        ->and($p1)->toBeLessThanOrEqual(10);
});

it('offers exactly 100 / 200 / 300 on the screen, starts at 100, and lets only the latest answer paint', function () {
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // The select's options, and the default when nothing (or a retired size)
    // is remembered in kbb_cp_pp.
    expect(str_contains($src, 'var CP_PER_PAGE = [100, 200, 300];'))->toBeTrue('select offers 100/200/300')
        ->and(str_contains($src, "perPage: (function(){ var n = 0; try{ n = +localStorage.getItem('kbb_cp_pp'); }catch(e){} return [100, 200, 300].indexOf(n) >= 0 ? n : 100; })(),"))
        ->toBeTrue('default 100, remembered size only if still offered')
        ->and(str_contains($src, '[25, 50, 100, 200, 500]'))->toBeFalse('the old five sizes are gone');

    // A slower, older list answer must not overwrite a newer one (page 3
    // replaced by a late page 1, the size select snapping back). MUTATION
    // NOTE: delete the `if(seq !== CP.seq) return;` lines and this is red.
    $at = strpos($src, 'async function cpLoad(){');
    $body = substr($src, $at, 4000);

    expect(str_contains($body, 'var seq = ++CP.seq;'))->toBeTrue('each load takes a number')
        ->and(substr_count($body, 'if(seq !== CP.seq) return;'))->toBe(3);

    // The server's allowlist is the same three numbers.
    expect(\App\Http\Controllers\Admin\CatalogProductsApiController::PER_PAGE_CHOICES)->toBe([100, 200, 300]);
});
