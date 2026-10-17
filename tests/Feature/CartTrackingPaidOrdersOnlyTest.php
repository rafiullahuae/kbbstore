<?php

declare(strict_types=1);

/*
 * Cart tracking counts only REAL orders as "became an order".      (Lane CT)
 *
 * The owner, 17 October: "the owner app carts tracking is counting pending
 * payment and failed payment, to orders counts. and also adding in the
 * revenue too." And: "today paid i have received 6, but u can see it's
 * showing 7 and counting the revenue also."
 *
 * DEFECT: CartTracker stamps carts.ct_order_id when checkout CREATES the
 * order, before Tamara/Tabby/a card has taken anything, and every tile read
 * "ct_order_id IS NOT NULL". So an unpaid Tamara order (#56251, pending,
 * AED 563) counted as Became orders (7 instead of 6) and its AED in Bought
 * value (AED 2,992 instead of 2,429), and the Ordered step read 7.
 *
 * The rule now is the reporting one, Order::REAL_STATUSES (processing, onhold,
 * shipped, completed) on an order not in the bin -- what /admin-api/stats
 * revenue reads. One source, CartTrail::boughtSql(), feeds the console and
 * the owner app alike.
 *
 * MUTATION: make CartTrail::boughtSql() return '(c.ct_order_id IS NOT NULL)'
 * and every case below is red (7 / AED 2,992 / Ordered 7; 10 instead of 4 in the second).
 */

use App\Models\Cart;
use App\Models\Order;
use App\Services\CartTracking\CartTrackingReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    $this->owner = OA::admin();
    OA::member($this->owner);
});

/** One tracked cart today, optionally with an order of $status. */
function ctpCart(int $value, string $cartStatus = 'active', ?string $orderStatus = null, bool $trashed = false): void
{
    static $n = 0;
    $n++;
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => $cartStatus, 'last_activity_at' => now()]);
    $set = ['ct_ip' => '94.200.1.'.($n % 250), 'ct_country' => 'AE', 'ct_ua' => 'Mozilla/5.0', 'ct_bot_score' => 0,
        'ct_bot_flags' => 0, 'ct_value' => $value, 'ct_added' => 1,
        'ct_first_at' => now()->subMinutes(20), 'ct_last_at' => now()->subMinutes(5)];

    if ($orderStatus !== null) {
        $o = Order::query()->create(['order_number' => (string) (56200 + $n), 'email' => 'ctp'.$n.'@example.com',
            'status' => $orderStatus, 'total' => $value, 'subtotal' => $value,
            'payment_method' => $orderStatus === 'pending' ? 'tamara' : 'cod']);
        if ($trashed) {
            $o->delete();
        }
        $set['ct_order_id'] = $o->id;
    }

    DB::table('carts')->where('id', $cart->id)->update($set);
}

/** The owner's Today: 22 carts, 6 paid, 1 unpaid Tamara (AED 563), 15 still in bags (AED 3,836). */
function ctpOwnersToday(): void
{
    foreach ([40000, 40000, 40000, 40000, 40000, 42900] as $v) {   // AED 2,429 paid
        ctpCart($v, 'converted', 'processing');
    }
    ctpCart(56300, 'converted', 'pending');                        // #56251, Tamara, not paid
    for ($i = 0; $i < 14; $i++) {
        ctpCart(25000);
    }
    ctpCart(33600);                                                // AED 3,836 left in bags
}

it('does not count an unpaid Tamara order as Became orders, nor its AED in Bought value', function () {
    ctpOwnersToday();
    Cache::flush();

    [$c] = OA::enrol($this);
    $s = OA::get($this, 'carts?period=today', $c)->assertOk()->json('summary');
    $ordered = collect($s['funnel'])->firstWhere('label', 'Ordered');

    // Before the fix: bought 7, 31.8%, AED 2,992, Ordered 7.
    expect($s['carts'])->toBe(22)
        ->and($s['bought'])->toBe(6)
        ->and($s['conversion'])->toEqual(27.3)
        ->and($s['bought_value'])->toBe(\App\Support\Money::plain(242900))
        ->and($s['open_value'])->toBe(\App\Support\Money::plain(383600))
        ->and($ordered['carts'])->toBe(6);

    // The console reads the same summary: the two screens agree.
    $console = app(CartTrackingReport::class)->carts(['period' => 'today'])['summary'];
    expect($console['bought'])->toBe(6)->and($console['bought_value'])->toBe($s['bought_value']);

    // "Became orders" chip lists the six; the unpaid one is under "Not bought",
    // and its row is not drawn as reaching the Ordered step.
    expect(OA::get($this, 'carts?period=today&bought=yes', $c)->json('total'))->toBe(6);
    $no = OA::get($this, 'carts?period=today&bought=no', $c)->json();
    expect($no['total'])->toBe(16);
    $pending = collect($no['rows'])->first(fn ($r) => ($r['order']['status'] ?? null) === 'pending');
    expect($pending)->not->toBeNull()->and($pending['journey']['stage'])->not->toBe(8);
});

it('treats failed, cancelled, refunded, draft and binned orders the way revenue does', function () {
    ctpCart(10000, 'converted', 'processing');
    ctpCart(10000, 'converted', 'onhold');
    ctpCart(10000, 'converted', 'shipped');
    ctpCart(10000, 'converted', 'completed');
    ctpCart(20000, 'active', 'failed');            // payment refused, BasketRelease gave the bag back
    ctpCart(20000, 'converted', 'pending');        // still at Tamara: basket held, neither bought nor left
    ctpCart(20000, 'converted', 'cancelled');
    ctpCart(20000, 'converted', 'refunded');
    ctpCart(20000, 'converted', 'draft');
    ctpCart(20000, 'converted', 'processing', trashed: true);
    ctpCart(5000);
    Cache::flush();

    $s = app(CartTrackingReport::class)->carts(['period' => 'today'])['summary'];

    expect($s['bought'])->toBe(4)
        ->and($s['bought_value'])->toBe(\App\Support\Money::plain(40000))
        // The live baskets with no real order: the refused one and the plain one.
        ->and($s['open_value'])->toBe(\App\Support\Money::plain(25000));
});

it('costs the same number of queries for 3 carts as for 40', function () {
    $count = function (int $n): int {
        DB::table('carts')->delete();
        Order::query()->withTrashed()->forceDelete();
        for ($i = 0; $i < $n; $i++) {
            ctpCart(10000, 'converted', ['processing', 'pending', 'failed'][$i % 3]);
        }
        [$c] = OA::enrol($this);
        OA::get($this, 'carts?period=all', $c)->assertOk();
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        OA::get($this, 'carts?period=all', $c)->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(40))->toBe($count(3));
});
