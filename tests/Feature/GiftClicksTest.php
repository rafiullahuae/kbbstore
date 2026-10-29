<?php

declare(strict_types=1);

/*
 * How often shoppers use the free gift box's controls (2.60.627).
 *
 * The owner, 29 October 2026: "I want to track the view all and Gift criteria
 * buttons ... to know what people like etc. i would also need a report page
 * day, weekly or monthly wise etc. and these tracking numbers will be shown
 * ... in each cart ... i don't want to compromise on speed."
 *
 * The taps ride Cart Tracking's own beacon (trail.js codes 60-74), so these
 * tests drive the same endpoint CartJourneyTest does.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CartTracking\GiftClicks;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\CartTrackingRoutes;
use Tests\Support\OwnerAppRoutes as OA;

const GC_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

beforeEach(function () {
    Route::middleware('web')->group(base_path('routes/instant-nav.php'));
    Route::middleware('web')->group(base_path('routes/cart-trail.php'));
    CartTrackingRoutes::wire($this->app);
    GiftClicks::forgetReady();
});

function gcCart(): Cart
{
    $p = Product::query()->create(['slug' => 'gc-serum-' . uniqid(), 'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 9700, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 9700]);
    DB::table('carts')->where('id', $cart->id)->update(['ct_first_at' => now()->subMinutes(5), 'ct_last_at' => now()->subMinutes(5), 'ct_value' => 9700, 'ct_ip' => '94.200.1.9', 'ct_country' => 'AE', 'ct_ua' => GC_UA]);

    return $cart->fresh();
}

function gcTaps(Cart $cart, array $events)
{
    app()->forgetScopedInstances();

    return test()->flushHeaders()->withHeaders(['User-Agent' => GC_UA])->withServerVariables(['REMOTE_ADDR' => '94.200.1.9'])
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/api/cart-trail', ['e' => json_encode($events)]);
}

/** One range, AED 500 - 999, compiled into the shop's ranges. */
function gcRange(): int
{
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 50000, 'max_fils' => 99900, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true,
        'need_mask' => 0, 'need_mini' => 0, 'need_full' => 0, 'created_at' => now(), 'updated_at' => now()]);
    SettingsService::forgetMemo();
    app(FreeGifts::class)->compile();

    return $id;
}

function gcOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'GC', 'email' => 'gc-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

it('counts each gift box control per place, names a range from the shop\'s own ranges, and keeps the cart\'s own counts', function () {
    /*
     * What it looked like before: nothing at all -- View all and Gifts
     * Criteria taps went nowhere, so nobody could say what shoppers used.
     * MUTATION: drop GiftClicks::record() from CartTrail::client() -> no
     * gift_clicks rows and ct_gifts stays null; name the range from the
     * browser's text -> the label below is not "AED 500 – 999".
     */
    $range = gcRange();
    $cart = gcCart();
    gcTaps($cart, [[62, '/', 0], [63, 'r' . $range, 0], [63, 'r' . $range, 0], [60, '/', 0], [64, '/', 0], [61, '/', 0], [67, '/cart/', 0], [72, '/checkout/', 0]])->assertNoContent();

    $trail = DB::table('cart_trail')->where('cart_id', $cart->id)->orderBy('id')->get(['code', 'path'])->map(fn ($r) => [(int) $r->code, $r->path])->all();
    expect($trail)->toContain([63, 'AED 500 – 999'])->toContain([62, '/'])->toContain([72, '/checkout/']);

    $day = StoreTime::today()->format('Y-m-d');
    $rows = DB::table('gift_clicks')->orderBy('code')->get()->map(fn ($r) => [substr((string) $r->day, 0, 10), (int) $r->code, $r->label, (int) $r->clicks, (int) $r->carts])->all();
    expect($rows)->toBe([
        [$day, 60, '', 1, 1], [$day, 61, '', 1, 1], [$day, 62, '', 1, 1], [$day, 63, 'AED 500 – 999', 2, 1], [$day, 64, '', 1, 1],
        [$day, 67, '', 1, 1], [$day, 72, '', 1, 1],
    ]);
    // View all, Hide, Gifts Criteria (bag + cart page + checkout), ranges, photos.
    expect(DB::table('carts')->where('id', $cart->id)->value('ct_gifts'))->toBe('1,1,3,2,1');

    // The same cart again today: more taps, but it is still ONE cart.
    // MUTATION: drop the "seen today" read -> carts reads 2.
    gcTaps($cart, [[62, '/', 0], [63, 'r' . $range, 0]])->assertNoContent();
    expect((int) DB::table('gift_clicks')->where('code', 62)->value('clicks'))->toBe(2)
        ->and((int) DB::table('gift_clicks')->where('code', 62)->value('carts'))->toBe(1)
        ->and((int) DB::table('gift_clicks')->where('code', 63)->value('clicks'))->toBe(3)
        ->and(DB::table('carts')->where('id', $cart->id)->value('ct_gifts'))->toBe('1,1,4,3,1');

    // A second shopper is a second cart.
    gcTaps(gcCart(), [[62, '/', 0]])->assertNoContent();
    expect((int) DB::table('gift_clicks')->where('code', 62)->value('carts'))->toBe(2);
});

it('never takes a range\'s name from the browser, and ignores a range that is not the shop\'s', function () {
    // MUTATION: accept any "r..." detail as the label -> the forged text lands in the report.
    gcRange();
    $cart = gcCart();
    gcTaps($cart, [[63, 'AED 1 – 2 <b>', 0], [63, 'r999999', 0], [63, '<script>', 0]])->assertNoContent();

    expect(DB::table('cart_trail')->where('cart_id', $cart->id)->pluck('path')->all())->toBe([null, null, null])
        ->and(DB::table('gift_clicks')->pluck('label')->all())->toBe([''])
        ->and((int) DB::table('gift_clicks')->value('clicks'))->toBe(3);
});

it('costs a batch with no gift tap nothing extra, and a gift batch two queries', function () {
    /*
     * The owner: "i don't want to compromise on speed". The taps ride the
     * beacon a basket-holder's page already sends; a batch without a gift
     * tap must not even look at gift_clicks.
     * MUTATION: call GiftClicks::record() for every batch -> red.
     */
    $range = gcRange();
    $cart = gcCart();
    $count = function (array $events) use ($cart): array {
        $q = [];
        DB::listen(function ($e) use (&$q) { $q[] = $e->sql; });
        gcTaps($cart, $events)->assertNoContent();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $q;
    };
    gcTaps($cart, [[10, '/', 0]])->assertNoContent(); // warm the settings memo
    $plain = $count([[10, '/', 0], [14, '/', 0]]);
    $gift = $count([[62, '/', 0], [63, 'r' . $range, 0], [60, '/', 0]]);

    expect(collect($plain)->filter(fn ($s) => str_contains($s, 'gift_clicks'))->count())->toBe(0)
        // One read of this cart's gift taps today, one upsert -- nothing per tap.
        ->and(count($gift) - count($plain))->toBe(2)
        ->and(collect($gift)->filter(fn ($s) => str_contains($s, 'gift_clicks'))->count())->toBe(1);
});

it('reports by day, week and month, with places, ranges and orders -- at the same cost for a week or a year', function () {
    $range = gcRange();
    $owner = gcOwner();
    $day = StoreTime::today();
    // Spread over three weeks, straight into the table.
    foreach ([[0, 62, '', 5, 3], [0, 60, '', 2, 2], [1, 63, 'AED 500 – 999', 4, 2], [8, 67, '', 3, 1], [15, 72, '', 1, 1], [15, 63, 'AED 1,000+', 9, 3]] as [$back, $code, $label, $n, $c]) {
        DB::table('gift_clicks')->insert(['day' => $day->subDays($back)->format('Y-m-d'), 'code' => $code, 'label' => $label, 'clicks' => $n, 'carts' => $c]);
    }
    $used = gcCart();
    DB::table('carts')->where('id', $used->id)->update(['ct_gifts' => '0,0,1,0,0', 'ct_last_at' => now()]);
    gcCart();

    $from = $day->subDays(20)->format('Y-m-d');
    $to = $day->format('Y-m-d');
    $r = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/clicks?from=' . $from . '&to=' . $to . '&by=day')->assertOk();
    expect($r->json('totals'))->toBe([2, 0, 9, 13, 0])
        ->and($r->json('carts'))->toBe([2, 0, 5, 5, 0])
        ->and($r->json('total'))->toBe(24)
        ->and(count($r->json('buckets')))->toBe(21)
        ->and($r->json('buckets.20.total'))->toBe(7)
        ->and($r->json('by_place'))->toBe([[2, 0, 5, 13, 0], [0, 0, 3, 0, 0], [0, 0, 1, 0, 0]])
        // The ranges looked at most, busiest first.
        ->and($r->json('ranges'))->toBe([['label' => 'AED 1,000+', 'clicks' => 9], ['label' => 'AED 500 – 999', 'clicks' => 4]])
        ->and($r->json('orders.used'))->toBe(1)
        ->and($r->json('orders.others'))->toBeGreaterThanOrEqual(1);

    $w = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/clicks?from=' . $from . '&to=' . $to . '&by=week')->assertOk();
    expect(array_sum(array_column($w->json('buckets'), 'total')))->toBe(24)
        ->and(count($w->json('buckets')))->toBeLessThanOrEqual(4);
    $m = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/clicks?from=' . $from . '&to=' . $to . '&by=month')->assertOk();
    expect(array_sum(array_column($m->json('buckets'), 'total')))->toBe(24);

    // A week and a year cost the same number of queries (MUTATION: one query per bucket -> red).
    $queries = function (string $qs) use ($owner): int {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/clicks?' . $qs)->assertOk();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $n;
    };
    $week = $queries('from=' . $day->subDays(6)->format('Y-m-d') . '&to=' . $to . '&by=day');
    $year = $queries('from=' . $day->subDays(364)->format('Y-m-d') . '&to=' . $to . '&by=day');
    expect($year)->toBe($week);
});

it('keeps the report behind freegifts.view, and refuses a date or grouping that is not one', function () {
    // MUTATION: drop the validation -> 200 on the bad input; drop the route from the capability map -> support reads it.
    $owner = gcOwner();
    test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/clicks?from=yesterday')->assertStatus(422);
    test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/clicks?by=hour')->assertStatus(422);
    test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/clicks')->assertOk()->assertJsonPath('by', 'day');
    test()->actingAs(gcOwner('support'), 'admin')->getJson('/admin-api/free-gifts/clicks')->assertForbidden();
    expect(test()->getJson('/admin-api/free-gifts/clicks')->status())->toBeIn([401, 403]);
});

it('shows each cart\'s gift box taps on its Cart Tracking row, its page and the owner app, from the cart row itself', function () {
    // MUTATION: drop 'gifts' from present() or from the owner app's row() -> red.
    $range = gcRange();
    $cart = gcCart();
    gcTaps($cart, [[62, '/', 0], [62, '/', 0], [60, '/', 0], [63, 'r' . $range, 0]])->assertNoContent();
    $quiet = gcCart();

    $owner = gcOwner();
    \Illuminate\Support\Facades\Cache::flush();
    $list = test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking?period=7d')->assertOk();
    $rows = collect($list->json('rows'));
    expect($rows->firstWhere('id', $cart->id)['gifts'])->toBe(['view_all' => 1, 'hide' => 0, 'criteria' => 2, 'ranges' => 1, 'photos' => 0, 'total' => 4])
        ->and($rows->firstWhere('id', $quiet->id)['gifts'])->toBeNull();

    $detail = test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking/carts/' . $cart->id)->assertOk();
    expect($detail->json('gifts.total'))->toBe(4)
        ->and(array_column($detail->json('trail'), 'title'))->toContain('Gift box: Gifts Criteria (bag panel)')
        ->and(collect($detail->json('trail'))->firstWhere('code', 63)['detail'])->toBe('AED 500 – 999');

    OA::wire($this->app);
    $o = OA::admin();
    OA::member($o);
    [$c] = OA::enrol($this);
    $row = collect(OA::get($this, 'carts?period=7d', $c)->assertOk()->json('rows'))->firstWhere('id', $cart->id);
    expect($row['gifts'])->toBe(['view_all' => 1, 'hide' => 0, 'criteria' => 2, 'ranges' => 1, 'photos' => 0, 'total' => 4]);
    $steps = OA::get($this, 'carts/' . $cart->id . '/journey', $c)->assertOk()->json('steps');
    expect(collect($steps)->firstWhere('title', 'Gift box: Range tapped (bag panel)')['detail'])->toBe('AED 500 – 999');
});

it('captures the taps in the shop\'s existing bundle: each control once, by place, with no layout measuring', function () {
    /*
     * MUTATION: count a range on any click inside .gbx-tabs (the label's tap
     * fires a second click on its radio) -> one tap counted twice; drop the
     * data-r on the radio -> every range reads r0.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/trail.js'));
    expect($js)->toContain("if (el.matches && el.matches('.gbx-tabs input')) return 3;")
        ->toContain("if (el.closest('.gbx-cr')) return 2;")
        ->toContain("if (el.closest('.fgb-th .fgb-tg')) return 0;")
        ->toContain("if (el.closest('.fgb-ls .fgb-tg')) return 1;")
        ->toContain("if (el.closest('.fgb-th button.fgb-t')) return 4;")
        ->toContain('export const GIFT_BASE = { bag: 60, cart: 65, checkout: 70 };')
        ->not->toMatch('/getBoundingClientRect|offsetWidth|offsetHeight|getComputedStyle|setInterval|fetch\(/');
    expect((string) file_get_contents(resource_path('views/partials/free-gifts/extras.blade.php')))
        ->toContain('data-r="{{ (int) $gbxB[\'range_id\'] }}"');
    // The server's codes and the browser's agree.
    foreach (GiftClicks::codes() as $code => $row) {
        expect(\App\Services\CartTracking\CartTrail::CODES[$code])->toBe($row);
    }
});

it('puts the Clicks tab in Free gifts and the gift chips in Cart Tracking, exactly once each', function () {
    $fg = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    $ct = (string) file_get_contents(resource_path('views/admin/partials/cart-tracking-screen.blade.php'));
    expect(substr_count($fg, "['clicks', 'Clicks']"))->toBe(1)
        ->and(substr_count($fg, 'clicks: renderClicks'))->toBe(1)
        ->and(substr_count($ct, '+ giftsHtml(r.gifts)'))->toBe(1)
        // Late answers never paint over another tab (CLAUDE.md: an admin screen's late answer).
        ->and($fg)->toContain("if (st.tab === 'clicks') render();")
        ->and($fg)->not->toMatch('/setInterval|getBoundingClientRect/');
});
