<?php

declare(strict_types=1);

/*
 * The free gifts each cart gets, on Cart Tracking (Lane B).
 *
 * The owner: "on the Cart tracking, we also need to see the freebies list to
 * keep eyes on what people are getting (in desktop & owner app both)".
 *
 * WHAT IT LOOKED LIKE BEFORE: a cart row showed its products, value, journey
 * and gift-box taps, and nothing about the gifts. A basket at AED 600 that the
 * shop was about to hand two sheet masks, and an order that went out with a
 * free cleanser, read exactly like baskets with no gifts at all -- the owner
 * had to open each order, or work the ranges out in his head.
 *
 * MUTATION NOTES: drop 'freebies' from CartTrackingReport::present() -> every
 * test here is red; drop it from OwnerApp\CartsController::row() -> the owner
 * app test is red; read the gift names inside the per-cart loop -> the flat
 * query test is red (3 carts and 40 no longer cost the same).
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CartTrackingRoutes;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    CartTrackingRoutes::wire($this->app);
});

function ctfSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/** Free gifts in $mode, one range AED 500+ giving 2 masks and 1 cleanser. Returns [mask, cleanser]. */
function ctfGifts(string $mode = 'live', string $maskName = 'Tea Tree Mask', array $testers = []): array
{
    $mask = Product::query()->create(['slug' => 'ctf-mask-' . uniqid(), 'name' => $maskName, 'status' => 'publish', 'is_visible' => true, 'price' => 1500, 'stock_status' => 'instock']);
    $wash = Product::query()->create(['slug' => 'ctf-wash-' . uniqid(), 'name' => 'Senka Whip', 'status' => 'publish', 'is_visible' => true, 'price' => 3200, 'stock_status' => 'instock']);
    $range = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 50000, 'max_fils' => null, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true,
        'need_mask' => 0, 'need_mini' => 0, 'need_full' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert([
        ['range_id' => $range, 'product_id' => $mask->id, 'quantity' => 2, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['range_id' => $range, 'product_id' => $wash->id, 'quantity' => 1, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    ctfSet(FreeGifts::KEY, ['mode' => $mode, 'on' => $mode !== 'off', 'countries' => 'all', 'basis' => 'after_discounts', 'show_value' => false, 'testers' => $testers]);
    app(FreeGifts::class)->compile();
    SettingsService::forgetMemo();
    Setting::flushMap();

    return [$mask, $wash];
}

function ctfCart(int $value, ?string $ip = '94.200.1.9', ?array $giftLines = null): Cart
{
    static $n = 0;
    $n++;
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => $giftLines === null ? 'active' : 'converted', 'last_activity_at' => now()]);
    $set = ['ct_ip' => $ip, 'ct_country' => 'AE', 'ct_ua' => 'Mozilla/5.0', 'ct_bot_score' => 0, 'ct_bot_flags' => 0,
        'ct_value' => $value, 'ct_added' => 1, 'ct_first_at' => now()->subMinutes(20), 'ct_last_at' => now()->subMinutes(5)];

    if ($giftLines !== null) {
        $o = Order::query()->create(['order_number' => (string) (57100 + $n), 'email' => 'ctf' . $n . '@example.com',
            'status' => 'processing', 'total' => $value, 'subtotal' => $value, 'payment_method' => 'cod']);
        $o->items()->create(['name' => 'Glow Serum', 'quantity' => 1, 'unit_price' => $value, 'subtotal' => $value, 'total' => $value]);
        foreach ($giftLines as [$name, $qty]) {
            $o->items()->create(['name' => $name, 'quantity' => $qty, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
        }
        $set['ct_order_id'] = $o->id;
    }

    DB::table('carts')->where('id', $cart->id)->update($set);

    return $cart;
}

function ctfOwner(): AdminUser
{
    return AdminUser::create(['name' => 'CTF', 'email' => 'ctf-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
}

function ctfRows(): \Illuminate\Support\Collection
{
    Cache::flush();

    return collect(test()->actingAs(ctfOwner(), 'admin')->getJson('/admin-api/cart-tracking?period=7d')->assertOk()->json('rows'));
}

it('shows what an ordered cart got and what an open cart would get, on its row and its page', function () {
    ctfGifts();
    $ordered = ctfCart(70000, '94.200.1.9', [['Tea Tree Mask – Pink', 2], ['Senka Whip', 1]]);
    $open = ctfCart(60000);
    $below = ctfCart(42000);

    $rows = ctfRows();

    expect($rows->firstWhere('id', $ordered->id)['freebies'])->toBe(['state' => 'got', 'range' => null,
        'items' => [['name' => 'Tea Tree Mask – Pink', 'qty' => 2], ['name' => 'Senka Whip', 'qty' => 1]], 'hint' => null])
        ->and($rows->firstWhere('id', $open->id)['freebies'])->toBe(['state' => 'would', 'range' => 'AED 500+',
            'items' => [['name' => 'Tea Tree Mask', 'qty' => 2], ['name' => 'Senka Whip', 'qty' => 1]], 'hint' => null])
        // Below the first range: no gifts, only how far it has to go.
        ->and($rows->firstWhere('id', $below->id)['freebies'])->toBe(['state' => 'none', 'range' => 'AED 500+', 'items' => [], 'hint' => 'AED 80 more for AED 500+']);

    $detail = test()->actingAs(ctfOwner(), 'admin')->getJson('/admin-api/cart-tracking/carts/' . $open->id)->assertOk();
    expect($detail->json('freebies.state'))->toBe('would')
        ->and(array_column($detail->json('freebies.items'), 'name'))->toBe(['Tea Tree Mask', 'Senka Whip']);

    // The screen draws a line on the row and a "Free gifts" block on the page, every name escaped.
    $blade = (string) file_get_contents(resource_path('views/admin/partials/cart-tracking-screen.blade.php'));
    expect(substr_count($blade, 'freebiesHtml(r.freebies)'))->toBe(1)
        ->and(substr_count($blade, 'freebiesCard(c.freebies)'))->toBe(1)
        ->and($blade)->toContain("num(l.qty) + '× ' + esc(l.name)")
        ->and($blade)->toContain("'<li><span>' + esc(l.name) + '</span>");
});

it('shows nothing for open carts when free gifts are Off, and only a tester\'s basket in Testing -- an order keeps what it got', function () {
    ctfGifts('off');
    $ordered = ctfCart(70000, '94.200.1.9', [['Senka Whip', 1]]);
    $open = ctfCart(60000);

    $rows = ctfRows();
    expect($rows->firstWhere('id', $open->id)['freebies'])->toBeNull()
        ->and($rows->firstWhere('id', $ordered->id)['freebies']['items'])->toBe([['name' => 'Senka Whip', 'qty' => 1]]);

    ctfSet(FreeGifts::KEY, ['mode' => 'testing', 'countries' => 'all', 'basis' => 'after_discounts', 'testers' => [['ip' => '10.1.2.3', 'label' => 'Rafi']]]);
    $tester = ctfCart(60000, '10.1.2.3');

    $rows = ctfRows();
    expect($rows->firstWhere('id', $open->id)['freebies'])->toBeNull()
        ->and($rows->firstWhere('id', $tester->id)['freebies']['state'])->toBe('would');
});

it('keeps the Cart Tracking list flat in queries: 3 carts and 40 cost the same', function () {
    /*
     * What the defect would look like: a page of 50 carts reading gift names
     * or order gift lines once per cart -- 100 extra queries on every open of
     * Cart Tracking, growing with the shop. MUTATION: move the order_items
     * read or the Product read into the per-cart loop -> $big > $small.
     */
    ctfGifts();
    $owner = ctfOwner();
    $make = function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            $i % 2 === 0 ? ctfCart(55000 + $i * 100) : ctfCart(80000, '94.200.1.9', [['Tea Tree Mask', 2], ['Senka Whip', 1]]);
        }
    };
    $count = function () use ($owner) {
        Cache::flush();
        SettingsService::forgetMemo();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking?period=7d')->assertOk()->json('rows');
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$q, $rows];
    };

    // One warm-up: the schema probes (journey, capture, gift-click columns) are memoised per process.
    $count();

    $make(3);
    [$small, $rows] = $count();
    expect(collect($rows)->whereNotNull('freebies')->count())->toBe(3);

    $make(37);
    [$big, $rows] = $count();
    expect(collect($rows)->whereNotNull('freebies')->count())->toBe(40)
        ->and($big)->toBe($small);
});

it('allowlists the freebies on the owner app row, and the phone draws them escaped with classes, not inline style', function () {
    ctfGifts('live', '<script>alert(1)</script> Mask');
    $open = ctfCart(60000);

    expect(\App\Http\Controllers\OwnerApp\CartsController::ROW_KEYS)->toContain('freebies');

    OA::wire($this->app);
    $o = OA::admin();
    OA::member($o);
    [$c] = OA::enrol($this);
    Cache::flush();
    $res = OA::get($this, 'carts?period=7d', $c)->assertOk();
    $row = collect($res->json('rows'))->firstWhere('id', $open->id);
    expect(array_keys($row))->toBe(\App\Http\Controllers\OwnerApp\CartsController::ROW_KEYS)
        ->and($row['freebies'])->toBe(['state' => 'would', 'range' => 'AED 500+',
            'items' => [['name' => '<script>alert(1)</script> Mask', 'qty' => 2], ['name' => 'Senka Whip', 'qty' => 1]], 'hint' => null]);
    // The name travels as data; the screens escape it (below, and in Chromium: no script ran).

    $js = (string) file_get_contents(resource_path('js/owner-app/carts.js'));
    expect(substr_count($js, 'freebies(r.freebies)'))->toBe(1)
        ->and(substr_count($js, 'freebiesCard(r.freebies)'))->toBe(1)
        ->and($js)->toContain("fmt(l.qty) + '× ' + esc(l.name)")
        ->and($js)->toContain("<b>' + esc(l.name) + '</b></div><div class=\"re\"><b>× ' + fmt(l.qty) + '</b></div></div>').join('') + '</section>'")
        ->and(preg_match('/const freebies[\s\S]*?const PERIODS/', $js, $m))->toBe(1)
        ->and($m[0])->not->toContain('style=');
});
