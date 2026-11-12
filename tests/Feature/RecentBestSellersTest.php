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

/* ═══ 2.60.661: Catalog → Best Sellers — drag to order, the cross to remove ═══ */

function rbsAdmin(string $role = 'owner'): \App\Models\AdminUser
{
    return \App\Models\AdminUser::create(['name' => 'RBS', 'email' => 'rbs-'.$role.'-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => $role]);
}

function rbsPage(): string
{
    \App\Services\SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();

    return (string) test()->get('/best-sellers/')->assertOk()->getContent();
}

it('shows the owner\'s order and leaves out what he crossed, and Reset gives the ranking back', function () {
    /*
     * The owner, 12 Nov 2026: "allow us to adjust the position of any product
     * on this page manually with drag n drop function, and with cross icon if
     * any product we want to remove." MUTATION: ignore KEY_ORDER in ids() and
     * the page keeps rank order -- red; ignore KEY_REMOVED and the crossed
     * product is still drawn -- red.
     */
    $a = rbsProduct('Rank one');
    $b = rbsProduct('Rank two');
    $c = rbsProduct('Rank three');
    rbsOrder([[$a, 9], [$b, 5], [$c, 2]], 3);

    $owner = rbsAdmin();
    $grid = $this->actingAs($owner, 'admin')->getJson('/admin-api/best-sellers')->assertOk()->json('items');
    expect(array_column($grid, 'id'))->toBe([$a->id, $b->id, $c->id])
        ->and(array_column($grid, 'units'))->toBe([9, 5, 2]);

    // Drag c to the top, cross out a, Save.
    $saved = $this->actingAs($owner, 'admin')->postJson('/admin-api/best-sellers/save', [
        'order' => [$c->id, $a->id, $b->id], 'excluded' => [$a->id],
    ])->assertOk()->json('items');
    // The grid answers with the crossed one greyed at the end.
    expect(array_column($saved, 'id'))->toBe([$c->id, $b->id, $a->id])
        ->and(array_column($saved, 'excluded'))->toBe([false, false, true]);

    $html = rbsPage();
    expect(strpos($html, 'Rank three'))->toBeLessThan(strpos($html, 'Rank two'))
        ->and($html)->not->toContain('Rank one');

    // A product that starts selling later joins after his arrangement.
    $d = rbsProduct('Newcomer');
    rbsOrder([[$d, 50]], 1);
    RecentBestSellers::forget();
    expect(RecentBestSellers::ids())->toBe([$c->id, $b->id, $d->id]);

    $this->actingAs($owner, 'admin')->postJson('/admin-api/best-sellers/reset')->assertOk();
    \App\Services\SettingsService::forgetMemo();
    expect(RecentBestSellers::ids())->toBe([$d->id, $a->id, $b->id, $c->id]);
});

it('has its own capability, fails closed for other roles, and refuses junk', function () {
    // MUTATION: delete the admin-api/best-sellers rules in AdminCapabilities -- red.
    $calls = [['GET', '/admin-api/best-sellers'], ['POST', '/admin-api/best-sellers/save'], ['POST', '/admin-api/best-sellers/reset']];
    foreach ($calls as [$m, $uri]) {
        expect(\App\Support\AdminCapabilities::forPath($m, ltrim($uri, '/')))->toBe('catalog.best_sellers');
    }
    foreach (['support', 'editor'] as $role) {
        foreach ($calls as [$m, $uri]) {
            $this->actingAs(rbsAdmin($role), 'admin')->json($m, $uri)->assertForbidden();
        }
    }
    $this->actingAs(rbsAdmin('manager'), 'admin')->getJson('/admin-api/best-sellers')->assertOk();
    $this->actingAs(rbsAdmin(), 'admin')->postJson('/admin-api/best-sellers/save', ['order' => ['x'], 'excluded' => []])->assertStatus(422);
});

it('costs the shop page no extra query for the arrangement', function () {
    // MUTATION: read the two settings with a query of their own -- red.
    $p = rbsProduct('Budget');
    rbsOrder([[$p, 2]], 1);
    RecentBestSellers::ranked();
    $count = function () {
        rbsPage();
        DB::flushQueryLog();
        DB::enableQueryLog();
        rbsPage();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $before = $count();
    app(\App\Services\SettingsService::class)->set(RecentBestSellers::KEY_ORDER, [$p->id]);
    app(\App\Services\SettingsService::class)->set(RecentBestSellers::KEY_REMOVED, [999999]);
    expect($count())->toBe($before);
});
