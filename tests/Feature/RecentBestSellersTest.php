<?php

declare(strict_types=1);

/**
 * 2.60.660 -- /best-sellers/ is the units sold in the last three months, top
 * 150, highest first (the owner, 11 Nov 2026: "we want only the best sellers
 * from last 3 months on this page, around 150 products, from top to low").
 *
 * The defect on the shop: the page was ordered by the lifetime total_sales
 * carried over from WooCommerce, so last year's sellers sat above this month's.
 */

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\RecentBestSellers;
use App\Support\RepeatPurchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SqlShape;

beforeEach(function () {
    RecentBestSellers::forget();
    RepeatPurchase::forget();
});

afterEach(function () {
    RecentBestSellers::forget();
    RepeatPurchase::forget();
});

function rbsProduct(string $name, int $totalSales = 0, array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => 'rbs-' . Str::random(10), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'total_sales' => $totalSales,
    ], $extra));
}

/** @param array<int, array{0: Product, 1: int}> $lines */
function rbsOrder(array $lines, int $daysAgo = 5, string $status = 'completed', bool $gift = false): Order
{
    $order = Order::create([
        'order_number' => 'RBS-' . Str::random(10), 'email' => Str::random(6) . '@example.test',
        'status' => $status, 'currency' => 'AED', 'total' => 9900,
    ]);
    DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subDays($daysAgo)]);

    foreach ($lines as [$product, $qty]) {
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name,
            'quantity' => $qty, 'unit_price' => 9900, 'subtotal' => 9900 * $qty, 'total' => 9900 * $qty,
            'is_gift' => $gift,
        ]);
    }

    return $order;
}

it('ranks /best-sellers/ by units sold in the last three months, highest first, and leaves out the rest', function () {
    /*
     * MUTATION: drop the 90-day window and the product that sold 500 units a
     * year ago leads the page -- red. Order by lifetime total_sales instead
     * and `$old` (total_sales 900) leads -- red. Count gift lines or cancelled
     * orders and `$gifted` / `$cancelled` appear -- red.
     */
    $old = rbsProduct('Last year hit', 900);
    $top = rbsProduct('Now top', 1);
    $second = rbsProduct('Now second', 2);
    $gifted = rbsProduct('Only ever gifted');
    $cancelled = rbsProduct('Only cancelled');
    $never = rbsProduct('Never sold', 50);

    rbsOrder([[$old, 500]], 120);
    rbsOrder([[$top, 3]], 2);
    rbsOrder([[$top, 4], [$second, 2]], 80);
    rbsOrder([[$second, 3]], 30);
    rbsOrder([[$gifted, 9]], 3, 'completed', true);
    rbsOrder([[$cancelled, 9]], 3, 'cancelled');

    expect(RecentBestSellers::ranked())->toBe([$top->id => 7, $second->id => 5]);

    $html = $this->get('/best-sellers/')->assertOk()->getContent();
    $at = fn (Product $p) => strpos($html, e($p->name));

    expect($at($top))->not->toBeFalse()
        ->and($at($second))->not->toBeFalse()
        ->and($at($top))->toBeLessThan($at($second));
    foreach ([$old, $gifted, $cancelled, $never] as $p) {
        expect($at($p))->toBeFalse($p->name . ' is on the page');
    }
});

it('caps the list at 150 and counts only products the shop still shows', function () {
    /* MUTATION: drop the limit and 155 are ranked -- red; drop the visibility join and the hidden one is ranked -- red. */
    $hidden = rbsProduct('Hidden seller', 0, ['is_visible' => false]);
    $lines = [[$hidden, 999]];
    for ($i = 0; $i < 155; $i++) {
        $lines[] = [rbsProduct('Seller ' . $i), $i + 1];
    }
    rbsOrder($lines, 10);

    $ranked = RecentBestSellers::ranked();
    expect($ranked)->toHaveCount(RecentBestSellers::MAX)
        ->and(RecentBestSellers::MAX)->toBe(150)
        ->and(RecentBestSellers::DAYS)->toBe(90)
        ->and(array_key_exists($hidden->id, $ranked))->toBeFalse()
        ->and(array_values($ranked)[0])->toBe(155);
});

it('keeps the old ordering on a shop with no sale in the window, so the page is never empty', function () {
    $a = rbsProduct('Lifetime A', 999999);
    $b = rbsProduct('Lifetime B', 999998);
    rbsOrder([[$a, 1]], 200);

    expect(RecentBestSellers::ranked())->toBe([])
        ->and(RecentBestSellers::applyTo(Product::query()->visible()))->toBeNull();

    $html = $this->get('/best-sellers/')->assertOk()->getContent();
    expect(strpos($html, 'Lifetime A'))->toBeLessThan(strpos($html, 'Lifetime B'));
});

it('measures once per hour and issues SQL MySQL accepts', function () {
    $p = rbsProduct('Shape');
    rbsOrder([[$p, 2]], 1);

    $sql = SqlShape::capture(function () use ($p) {
        expect(RecentBestSellers::ranked()[$p->id] ?? null)->toBe(2);
        RecentBestSellers::applyTo(Product::query()->visible())->get();
    });
    expect(SqlShape::violations($sql))->toBe([]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    RecentBestSellers::ranked();
    $hits = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'order_items'))->count();
    DB::disableQueryLog();
    expect($hits)->toBe(0);
});
