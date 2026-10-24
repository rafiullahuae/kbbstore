<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Services\OwnerApp\OwnerAppSales;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * DASHBOARD AND OWNER APP: REVENUE BY PERIOD. (Lane REV)
 *
 * The owner, 24 October: "in the dashboard, and in the owner app dashboard, i
 * need the total revenue as This month, last 30 days, Month wise selection and
 * custom date, and also year wise. so we can track our revenue." Before: the
 * dashboard showed one "Revenue (30d)" figure and the owner app's hero offered
 * today, yesterday, 7 days, this month and last month.
 *
 * Mutation notes: let rangeKey() admit a future or malformed key and the
 * first case is red; drop the m:/y:/c: branches from range() and the totals
 * case is red; drop the capability rule and the guard case is red.
 */
function revOrder(string $at, int $fils): void
{
    $o = Order::create(['order_number' => 'REV-' . Str::random(6), 'email' => 'rev@example.test', 'status' => 'completed', 'total' => $fils, 'subtotal' => $fils, 'currency' => 'AED', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery']);
    $o->forceFill(['created_at' => CarbonImmutable::parse($at, 'Asia/Dubai')->utc(), 'updated_at' => now()])->saveQuietly();
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Dubai'));
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-24 12:00:00', 'Asia/Dubai'));
    Order::query()->forceDelete();
    Cache::flush();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    \Illuminate\Support\Carbon::setTestNow();
});

it('admits a picked month, year or two dates only in their exact shape, never in the future', function () {
    foreach (['month', '30d', 'year', 'm:2026-02', 'y:2025', 'c:2026-03-01:2026-04-15', 'm:2026-10', 'y:2026'] as $ok) {
        expect(OwnerAppSales::rangeKey($ok))->toBe($ok, $ok);
    }
    foreach (['m:2026-13', 'm:2026-11', 'y:2027', 'y:2014', 'c:2026-04-15:2026-03-01', 'c:2026-02-30:2026-03-01', 'c:2010-01-01:2026-01-01', "m:2026-02'", 'm:2026-02/../x', 'q:2026', '', null, ['m:2026-02']] as $bad) {
        expect(OwnerAppSales::rangeKey($bad))->toBe('today', json_encode($bad));
    }

    expect(OwnerAppSales::label('m:2026-02'))->toBe('February 2026')
        ->and(OwnerAppSales::label('y:2025'))->toBe('2025')
        ->and(OwnerAppSales::label('c:2026-03-01:2026-04-15'))->toBe('1 Mar – 15 Apr 2026')
        ->and(OwnerAppSales::label('30d'))->toBe('Last 30 days');
});

it('totals exactly the orders in the period chosen, on the dashboard', function () {
    revOrder('2026-02-03 10:00', 10000);
    revOrder('2026-02-28 23:30', 5000);   // Dubai's 28 Feb, UTC's 28 Feb 19:30
    revOrder('2026-03-01 00:30', 20000);  // Dubai's 1 Mar, UTC's 28 Feb 20:30 -- must count as March
    revOrder('2025-12-31 22:00', 7000);
    revOrder('2026-10-20 09:00', 3000);
    $this->actingAs(AdminUser::create(['name' => 'Rev', 'email' => 'rev-owner@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    $get = fn (string $r) => $this->getJson('/admin-api/revenue-report?range=' . urlencode($r))->assertOk()->json();

    expect($get('m:2026-02'))->toMatchArray(['total' => '150', 'orders' => 2, 'label' => 'February 2026'])
        ->and($get('m:2026-03'))->toMatchArray(['total' => '200', 'orders' => 1])
        ->and($get('y:2025'))->toMatchArray(['total' => '70', 'orders' => 1, 'label' => '2025'])
        ->and($get('y:2026'))->toMatchArray(['total' => '380', 'orders' => 4])
        ->and($get('c:2026-02-28:2026-03-01'))->toMatchArray(['total' => '250', 'orders' => 2])
        ->and($get('month'))->toMatchArray(['total' => '30', 'orders' => 1])
        ->and($get('30d'))->toMatchArray(['total' => '30', 'orders' => 1]);

    // A year is weekly bars, about 53 of them, never 365.
    expect(count($get('y:2025')['bars']))->toBeLessThan(60);
});

it('is behind the revenue permission', function () {
    foreach (['editor' => 403, 'support' => 403, 'manager' => 200] as $role => $status) {
        $this->actingAs(AdminUser::create(['name' => 'R ' . $role, 'email' => 'rev-' . $role . '@example.test', 'password' => 'secret-secret', 'role' => $role]), 'admin');
        $this->getJson('/admin-api/revenue-report?range=month')->assertStatus($status);
    }
});

it('draws the card on the dashboard and offers the same periods in the owner app', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $js = (string) file_get_contents(resource_path('js/owner-app/store.js'));

    expect(substr_count($app, "@include('admin.partials.revenue-card')"))->toBe(1)
        ->and($app)->toContain("window.kbbRevenueCard(document.getElementById('kbbRevCard'))")
        ->and($js)->toContain("['30d', 'Last 30 days']")->and($js)->toContain("['year', 'This year']")
        ->and($js)->toContain("key: 'm:' + v('[data-pmy]') + '-' + v('[data-pmm]')")
        ->and($js)->toContain("'dashboard?part=sales&range=' + D.range");
});
