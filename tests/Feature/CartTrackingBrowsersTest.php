<?php

declare(strict_types=1);

use App\Services\CartTracking\CartTrackingReport;
use App\Support\AdminNav;
use App\Support\UserAgentLabel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * STORE → CART TRACKING SAYS WHICH BROWSER AND DEVICE. (Lane OR)
 *
 * The owner, 10 October: "i need to get the user browser etc information under
 * the country, so i can be clear which browser users use more." Every cart
 * already carries its user agent (carts.ct_ua); the screen showed only the
 * country and the address. Now each row says "Safari · iPhone" under them, and
 * two tiles count the period's carts by browser and by device.
 *
 * And Orders is the fourth top-level sidebar row, under Cart Tracking: "bring
 * the Orders to quick access on in the panel top, under Cart Tracking, i mean
 * at no # 4 position".
 */
const OR_SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const OR_INSTAGRAM = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 339.0.3.12.91 (iPhone15,2; iOS 17_5; en_AE; en; scale=3.00; 1179x2556; 620373893)';
const OR_CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
const OR_EDGE_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0';

function orCart(?string $ua, int $minutesAgo = 5): int
{
    return (int) DB::table('carts')->insertGetId([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'ct_ua' => $ua, 'ct_country' => 'AE', 'ct_ip' => '94.200.10.'.random_int(1, 250),
        'ct_first_at' => now()->subMinutes($minutesAgo + 1), 'ct_last_at' => now()->subMinutes($minutesAgo),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('names the browser and the device from the agent, in-app browsers before the engines they wrap', function () {
    /*
     * MUTATION, RUN: move the Chrome pattern above Instagram/Edge, or Safari
     * above Chrome, and the in-app and Chromium rows are mislabelled -- every
     * Instagram shopper reported as Safari, which is exactly the answer the
     * owner asked this screen for.
     */
    $cases = [
        [OR_SAFARI_IPHONE, 'Safari', 'iPhone'],
        [OR_INSTAGRAM, 'Instagram', 'iPhone'],
        [OR_CHROME_ANDROID, 'Chrome', 'Android phone'],
        [OR_EDGE_WIN, 'Edge', 'Windows'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0 Mobile/15E148 Safari/604.1', 'Chrome', 'iPhone'],
        ['Mozilla/5.0 (Linux; Android 13; SM-A536B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Samsung Internet', 'Android phone'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/480.0]', 'Facebook', 'iPhone'],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox', 'Mac'],
        ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/129.0 Safari/537.36', UserAgentLabel::BOT, UserAgentLabel::BOT],
        ['curl/8.5.0', UserAgentLabel::BOT, UserAgentLabel::BOT],
        [null, UserAgentLabel::UNKNOWN, UserAgentLabel::UNKNOWN],
    ];

    foreach ($cases as [$ua, $browser, $device]) {
        expect(UserAgentLabel::parse($ua))->toBe(['browser' => $browser, 'device' => $device], (string) $ua);
    }
});

it('puts the browser and device on every cart row and counts them in the tiles', function () {
    Cache::flush();
    orCart(OR_SAFARI_IPHONE);
    orCart(OR_SAFARI_IPHONE);
    orCart(OR_INSTAGRAM);
    orCart(OR_CHROME_ANDROID);

    $out = app(CartTrackingReport::class)->carts(['period' => '7d']);

    $rows = collect($out['rows']);
    expect($rows)->toHaveCount(4)
        ->and($rows->every(fn ($r) => isset($r['browser'], $r['device'])))->toBeTrue()
        ->and($rows->pluck('browser')->sort()->values()->all())->toBe(['Chrome', 'Instagram', 'Safari', 'Safari'])
        // The raw agent stays off the list rows, as it was.
        ->and($rows->every(fn ($r) => ! array_key_exists('ua', $r)))->toBeTrue();

    expect($out['summary']['browsers'])->toBe([
        ['name' => 'Safari', 'n' => 2], ['name' => 'Chrome', 'n' => 1], ['name' => 'Instagram', 'n' => 1],
    ])->and($out['summary']['devices'])->toBe([
        ['name' => 'iPhone', 'n' => 3], ['name' => 'Android phone', 'n' => 1],
    ]);
});

it('costs the same statements for 3 carts as for 40', function () {
    $count = function (int $n): int {
        Cache::flush();
        DB::table('carts')->delete();
        $agents = [OR_SAFARI_IPHONE, OR_INSTAGRAM, OR_CHROME_ANDROID, OR_EDGE_WIN];
        foreach (range(1, $n) as $i) {
            orCart($agents[$i % 4].' v'.$i);   // every agent string distinct: the worst case
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CartTrackingReport::class)->carts(['period' => '7d']);
        $c = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $c;
    };

    // One warm-up first: the settings map is read once per process, and
    // whichever size ran first would otherwise pay for it alone.
    $count(3);

    expect($count(40))->toBe($count(3));
});

it('draws the browser line under the country and the two tiles, once each', function () {
    $src = file_get_contents(resource_path('views/admin/partials/cart-tracking-screen.blade.php'));

    expect(substr_count($src, "agentTile('Top browsers', s.browsers"))->toBe(1)
        ->and(substr_count($src, "agentTile('Top devices', s.devices"))->toBe(1)
        ->and(substr_count($src, '<span class="ctk-agent">'))->toBe(1)
        // Escaped like every other value on the row.
        ->and($src)->toContain("'<span class=\"ctk-agent\">' + esc(");
});

it('lists Orders fourth at the top of the sidebar, under Cart Tracking, and not under Store', function () {
    /*
     * MUTATION, RUN: put the orders row back in Store's list and this is red.
     */
    $groups = AdminNav::GROUPS;
    $top = collect($groups)->firstWhere('sec', 'Overview');
    $store = collect($groups)->firstWhere('sec', 'Store');

    expect(array_column($top['rows'], 'id'))->toBe(['dash', 'site-analytics', 'carttracking', 'orders'])
        ->and(array_column($store['rows'], 'id'))->not->toContain('orders')
        ->and(array_column($store['rows'], 'id'))->toContain('order-new');

    // The same row, read and capability: nothing about who may open it moved.
    $orders = collect($top['rows'])->firstWhere('id', 'orders');
    expect($orders['read'])->toBe('admin-api/orders-list');
});
