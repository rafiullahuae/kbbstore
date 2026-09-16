<?php

declare(strict_types=1);

/*
 * THE SHOP IS IN DUBAI AND THE SOFTWARE THOUGHT IT WAS IN LONDON.
 *
 * `config/app.php` is `UTC` and no env file sets `APP_TIMEZONE`, so every date
 * the owner read was a UTC day. An order placed between midnight and 04:00
 * Dubai time landed in the PREVIOUS day's bar on the 14-day chart, carried
 * yesterday's date on its invoice and its confirmation email, and counted
 * towards yesterday on the dashboard. The owner's "today" and the software's
 * "today" were four hours apart, every single day.
 *
 * WHAT IS ACTUALLY STORED, established by experiment before anything was
 * changed: `orders.created_at` holds a naive `Y-m-d H:i:s` string that is a UTC
 * instant. An order placed at 01:30 on 16 September Dubai time is stored as
 * `2026-09-15 21:30:00`. That is CORRECT and internally consistent — the
 * WooCommerce importer converts from Asia/Dubai to UTC on the way in
 * (app/Services/Import/DateParser.php:28) and the application writes `now()`
 * under an app timezone of UTC. So this is a PRESENTATION problem.
 *
 * WHICH MAKES ONE PARTICULAR WRONG FIX EXPENSIVE, and there is a test below
 * that exists only to block it: setting `APP_TIMEZONE=Asia/Dubai` does not
 * CONVERT stored timestamps, it REINTERPRETS them. Eloquent would read
 * `2026-09-15 21:30:00` as 21:30 Dubai — a different instant, four hours later
 * than the one that was written — moving every historical order in the store
 * and changing every bounded total. See 'storage stays in UTC' below.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function tzAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'TZ Owner',
        'email' => 'tz-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * An order at an exact UTC instant.
 *
 * The instant is passed as a UTC Carbon deliberately. Laravel's
 * `Model::fromDateTime()` formats a DateTime with `->format()` and does NOT
 * convert its zone first, so handing it a Dubai-zoned Carbon would store the
 * Dubai WALL CLOCK into a UTC column — 01:30, not 21:30 — which is a different
 * bug from the one under test and would make these fixtures lie.
 */
function tzOrder(string $utc, int $totalFils = 10000, string $status = 'completed'): Order
{
    return Order::create([
        'order_number' => 'TZ-' . uniqid(),
        'email' => 'tz@example.test',
        'status' => $status,
        'currency' => 'AED',
        'subtotal' => $totalFils,
        'shipping_total' => 0,
        'discount_total' => 0,
        'fee_total' => 0,
        'tax_total' => 0,
        'total' => $totalFils,
        'created_at' => CarbonImmutable::parse($utc, 'UTC'),
    ]);
}

function setZone(string $zone): void
{
    app(SettingsService::class)->set(StoreTime::SETTING_KEY, $zone);
}

beforeEach(function () {
    Cache::flush();
    SettingsService::forgetMemo();
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/* ------------------------------------------------------------------ the zone */

it('defaults to the shop is own timezone and takes it from a setting', function () {
    expect(StoreTime::zone())->toBe('Asia/Dubai');

    setZone('Europe/London');
    expect(StoreTime::zone())->toBe('Europe/London');

    // A setting that is not a timezone must not take the dashboard down; the
    // only honest fallback for this store is its own zone.
    app(SettingsService::class)->set(StoreTime::SETTING_KEY, 'Mars/Olympus_Mons');
    expect(StoreTime::zone())->toBe('Asia/Dubai');
});

it('accepts a timezone through the settings endpoint and refuses a fake one', function () {
    $admin = tzAdmin();

    $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/settings', ['settings' => [StoreTime::SETTING_KEY => 'Asia/Riyadh']])
        ->assertOk();

    SettingsService::forgetMemo();
    Cache::flush();
    expect(StoreTime::zone())->toBe('Asia/Riyadh');

    $bad = $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/settings', ['settings' => [StoreTime::SETTING_KEY => 'Dubai']])
        ->assertStatus(422);

    expect(str_contains((string) $bad->json('message'), 'Time zone'))
        ->toBeTrue('the refusal does not name the field: ' . (string) $bad->json('message'));
});

/* --------------------------------------------- the expensive mistake, blocked */

/*
 * THIS TEST EXISTS TO FAIL IF SOMEONE REINTERPRETS STORED TIMESTAMPS.
 *
 * The cheap-looking fix for all of this is `APP_TIMEZONE=Asia/Dubai`, or
 * `config(['app.timezone' => StoreTime::zone()])` in a service provider. Either
 * one moves every historical order in the store by four hours without touching
 * a single row, because Eloquent's datetime cast would start reading the stored
 * UTC string as a Dubai wall clock. Nothing would error. Every total in a
 * bounded window would quietly change, and the next save of any order would
 * write Dubai local time into a UTC column and corrupt the row for real.
 *
 * So: the stored bytes, the instant Eloquent reads back, and the app timezone
 * are all pinned here — under a non-UTC shop timezone — and only the DISPLAYED
 * value is allowed to move.
 */
it('converts for display and never reinterprets what is stored', function () {
    // 01:30 on 16 September, Dubai. The same instant is 21:30 on the 15th, UTC.
    $order = tzOrder('2026-09-15 21:30:00');

    $rawBefore = DB::table('orders')->where('id', $order->id)->value('created_at');

    setZone('Asia/Dubai');

    // 1. The app timezone is UTC and stays UTC. Storage is UTC.
    expect(config('app.timezone'))->toBe('UTC', 'app.timezone was changed — that reinterprets every stored timestamp');
    expect(StoreTime::STORAGE_ZONE)->toBe('UTC');

    // 2. The bytes in the column have not moved.
    $rawAfter = DB::table('orders')->where('id', $order->id)->value('created_at');
    expect((string) $rawAfter)->toBe((string) $rawBefore)
        ->and(substr((string) $rawAfter, 0, 16))->toBe('2026-09-15 21:30');

    // 3. The instant Eloquent reads back is the same instant it always was.
    $fresh = Order::find($order->id);
    expect($fresh->created_at->getTimezone()->getName())->toBe('UTC')
        ->and($fresh->created_at->toDateTimeString())->toBe('2026-09-15 21:30:00')
        ->and($fresh->created_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-09-15 21:30:00', 'UTC')->getTimestamp());

    // 4. Only the DISPLAY moves — and it moves by conversion, so the instant
    //    behind it is identical.
    $shown = StoreTime::display($fresh->created_at);
    expect($shown->format('Y-m-d H:i'))->toBe('2026-09-16 01:30')
        ->and($shown->getTimestamp())->toBe($fresh->created_at->getTimestamp())
        ->and(StoreTime::dayKey($fresh->created_at))->toBe('2026-09-16');

    // 5. And a re-save under a non-UTC shop timezone still writes UTC.
    $fresh->touch();
    $rawAfterSave = DB::table('orders')->where('id', $order->id)->value('created_at');
    expect((string) $rawAfterSave)->toBe((string) $rawBefore, 'saving the model rewrote created_at in the wrong zone');
});

it('does not move the all-time revenue total when the shop timezone changes', function () {
    $admin = tzAdmin();

    tzOrder('2026-09-15 21:30:00', 10000);
    tzOrder('2026-09-10 12:00:00', 20000);

    setZone('UTC');
    $utcTotal = $this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json('revenue_total_aed');

    setZone('Pacific/Kiritimati');   // UTC+14, the furthest a zone goes
    $farTotal = $this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json('revenue_total_aed');

    setZone('Pacific/Midway');       // UTC-11, the other direction
    $nearTotal = $this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json('revenue_total_aed');

    expect($utcTotal)->toBe(300)
        ->and($farTotal)->toBe(300, 'the unbounded revenue total moved with the display timezone — the stored instants are being reinterpreted')
        ->and($nearTotal)->toBe(300, 'the unbounded revenue total moved with the display timezone — the stored instants are being reinterpreted');
});

/* ---------------------------------------------------- the chart is day buckets */

it('buckets the 14-day chart on the shop is calendar day, not the UTC one', function () {
    $admin = tzAdmin();

    // Frozen so "today" is a fixed thing on both clocks. 2026-09-16 10:00 UTC
    // is 14:00 the same day in Dubai, so today is the 16th on both.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-16 10:00:00', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-16 10:00:00', 'UTC'));

    // 01:30 Dubai on the 16th. Stored as the 15th in UTC. The owner calls this
    // "an order this morning".
    tzOrder('2026-09-15 21:30:00', 10000);

    setZone('Asia/Dubai');

    $daily = collect($this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json('daily'))
        ->keyBy('date');

    expect($daily->has('2026-09-16'))->toBeTrue('the chart has no bucket for today on the shop clock')
        ->and($daily['2026-09-16']['revenue_aed'])->toBe(100, 'an order placed at 01:30 Dubai landed in the previous day is bar')
        ->and($daily['2026-09-15']['revenue_aed'])->toBe(0);

    // The last bucket is today on the shop's clock, and there are 14 of them.
    $dates = $daily->keys()->all();
    expect(count($dates))->toBe(14)
        ->and(end($dates))->toBe('2026-09-16');

    // Under a UTC shop the very same order belongs to the 15th, which is what
    // makes this a display concern and not a stored one: the same row, two
    // truthful answers, each correct for the clock it is read on.
    setZone('UTC');
    Cache::flush();
    SettingsService::forgetMemo();

    $utcDaily = collect($this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json('daily'))
        ->keyBy('date');

    expect($utcDaily['2026-09-15']['revenue_aed'])->toBe(100)
        ->and($utcDaily['2026-09-16']['revenue_aed'])->toBe(0);
});

/*
 * THE BOUNDARY, WHICH IS THE WHOLE POINT.
 *
 * A day bucket computed in one zone and compared against a timestamp in another
 * is exactly the bug being fixed, so the window's own edge is pinned: the
 * oldest bucket must include an order placed one minute after shop-local
 * midnight fourteen days ago, and must not swallow one placed one minute
 * before it.
 */
it('opens the chart window at shop-local midnight, not UTC midnight', function () {
    $admin = tzAdmin();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-16 10:00:00', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-16 10:00:00', 'UTC'));
    setZone('Asia/Dubai');

    // The oldest bucket is shop-local 2026-09-03, which begins at
    // 2026-09-02 20:00 UTC.
    tzOrder('2026-09-02 20:01:00', 10000);   // 00:01 on the 3rd, Dubai -> in
    tzOrder('2026-09-02 19:59:00', 50000);   // 23:59 on the 2nd, Dubai -> out

    $daily = collect($this->actingAs($admin, 'admin')->getJson('/admin-api/analytics')->assertOk()->json('daily'))
        ->keyBy('date');

    expect($daily->keys()->first())->toBe('2026-09-03')
        ->and($daily['2026-09-03']['revenue_aed'])->toBe(100, 'the oldest bucket missed an order placed just after shop-local midnight');

    $charted = collect($daily)->sum('revenue_aed');
    expect($charted)->toBe(100, 'an order from before the window leaked into the chart');
});

/* ------------------------------------------------------- the 30-day dashboard */

/*
 * The 30-day window was built as `now()->subDays(30)->toISOString()` and handed
 * to the query as a STRING. SQLite compares that as TEXT, and
 * '2026-08-17T12:00:00.000000Z' sorts AFTER '2026-08-17 13:00:00' because 'T'
 * (0x54) is greater than ' ' (0x20) — so the entire boundary day was dropped.
 * MySQL coerces the same string to a DATETIME and does not, which is why the
 * SQLite suite and production disagreed about this figure and neither said so.
 */
it('includes the boundary day in the 30-day revenue figure on both engines', function () {
    $admin = tzAdmin();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-16 10:00:00', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-16 10:00:00', 'UTC'));
    setZone('Asia/Dubai');

    // Shop-local today is 2026-09-16, so the window opens at shop-local
    // midnight on 2026-08-17 = 2026-08-16 20:00 UTC.
    tzOrder('2026-08-16 20:01:00', 10000);   // 00:01 on the 17th, Dubai -> in
    tzOrder('2026-08-16 19:59:00', 70000);   // 23:59 on the 16th, Dubai -> out
    tzOrder('2026-09-16 09:00:00', 20000);   // today -> in

    $stats = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();

    expect($stats->json('revenue_30d_aed'))
        ->toBe(300, 'the 30-day window dropped its boundary day or swallowed a day before it');
});

it('reports today on the shop clock', function () {
    $admin = tzAdmin();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 21:00:00', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 21:00:00', 'UTC'));
    setZone('Asia/Dubai');

    // 21:00 UTC is 01:00 on the 16th in Dubai: the software's "today" and the
    // owner's differ right now, which is the whole complaint.
    tzOrder('2026-09-15 21:30:00', 10000);   // 01:30 on the 16th, Dubai
    tzOrder('2026-09-15 19:00:00', 90000);   // 23:00 on the 15th, Dubai

    $stats = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();

    expect($stats->json('today.date'))->toBe('2026-09-16')
        ->and($stats->json('today.revenue_aed'))->toBe(100, 'today is still a UTC day')
        ->and($stats->json('today.orders'))->toBe(1)
        ->and($stats->json('timezone'))->toBe('Asia/Dubai');
});

/* ------------------------------------------- dates the owner and customer read */

it('dates an invoice and a confirmation email on the shop clock', function () {
    $product = Product::create([
        'name' => 'Invoice Cream', 'slug' => 'invoice-cream-' . uniqid(), 'sku' => 'IC-' . uniqid(),
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'price' => 10000,
    ]);

    $order = tzOrder('2026-09-15 21:30:00');
    $order->items()->create([
        'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
        'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000,
    ]);
    $order->forceFill(['invoiced_at' => CarbonImmutable::parse('2026-09-15 21:45:00', 'UTC')])->save();

    setZone('Asia/Dubai');

    $doc = app(\App\Services\Invoices\InvoiceDocument::class)->present($order->fresh());

    expect($doc['placedAt'])->toBe('16 September 2026', 'the invoice is dated a day early')
        ->and($doc['invoicedAt'])->toBe('16 September 2026');

    $mail = app(\App\Services\Mail\OrderEmailPresenter::class)->present($order->fresh());
    expect($mail['placedAt'])->toBe('16 September 2026', 'the confirmation email is dated a day early');

    // Under a UTC shop the same order reads as the 15th — the stored instant is
    // untouched, only the clock it is read on changed.
    setZone('UTC');
    Cache::flush();
    SettingsService::forgetMemo();

    expect(app(\App\Services\Invoices\InvoiceDocument::class)->present($order->fresh())['placedAt'])
        ->toBe('15 September 2026');
});

it('dates admin order rows on the shop clock', function () {
    $admin = tzAdmin();
    $order = tzOrder('2026-09-15 21:30:00');

    setZone('Asia/Dubai');

    $stats = $this->actingAs($admin, 'admin')->getJson('/admin-api/stats')->assertOk();
    $recent = $stats->json('recent');

    expect($recent)->not->toBeEmpty();

    /*
     * The admin's Recent activity feed does `(o.created_at || '').slice(0, 10)`
     * on this value. The default Carbon serialisation is
     * '2026-09-15T21:30:00.000000Z', so the first ten characters were the UTC
     * day and the feed showed the 15th for an order placed on the 16th. The
     * value now carries the shop's offset, so both slicing it and handing it to
     * `new Date()` give the shop's day.
     */
    expect(substr((string) $recent[0]['created_at'], 0, 10))
        ->toBe('2026-09-16', 'the admin order date is still a UTC day');

    expect(str_contains((string) $recent[0]['created_at'], '+04:00'))
        ->toBeTrue('the serialised order date carries no offset: ' . (string) $recent[0]['created_at']);

    $orders = $this->actingAs($admin, 'admin')->getJson('/admin-api/orders')->assertOk()->json('orders');
    expect(substr((string) $orders[0]['created_at'], 0, 10))
        ->toBe('2026-09-16', 'the Orders screen date is still a UTC day');
});

it('dates customer rows on the shop clock', function () {
    $admin = tzAdmin();

    $customer = Customer::create([
        'name' => 'Night Owl', 'first_name' => 'Night', 'last_name' => 'Owl',
        'email' => 'owl-' . uniqid() . '@example.test', 'password' => 'secret-secret',
        'created_at' => CarbonImmutable::parse('2026-09-15 21:30:00', 'UTC'),
    ]);

    setZone('Asia/Dubai');

    $rows = $this->actingAs($admin, 'admin')->getJson('/admin-api/customers')->assertOk()->json('customers');
    $row = collect($rows)->firstWhere('email', $customer->email);

    expect($row)->not->toBeNull()
        ->and(substr((string) $row['created_at'], 0, 10))->toBe('2026-09-16');
});

/* --------------------------------------------------------- the helper's edges */

it('turns a shop-local day boundary into the right UTC instant', function () {
    setZone('Asia/Dubai');

    expect(StoreTime::startOfDayUtc('2026-09-16')->toDateTimeString())->toBe('2026-09-15 20:00:00');

    setZone('UTC');
    expect(StoreTime::startOfDayUtc('2026-09-16')->toDateTimeString())->toBe('2026-09-16 00:00:00');
});

it('walks day keys on the shop clock across a DST change', function () {
    // Dubai has no DST, but the zone is a setting and Europe/London is a legal
    // value. Stepping the shop-local clock keeps one bucket per calendar day
    // across the transition; subtracting 86400 seconds from a UTC instant would
    // drop or double one.
    setZone('Europe/London');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-27 12:00:00', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27 12:00:00', 'UTC'));

    $keys = StoreTime::recentDayKeys(5);

    expect($keys)->toBe(['2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27'])
        ->and(count(array_unique($keys)))->toBe(5);
});

/*
 * The Orders list and the drawer it opens must not date the same order two
 * different days — which they would the moment one of them is converted and
 * the other is not.
 */
it('dates the order drawer the same way as the order list', function () {
    $admin = tzAdmin();
    $order = tzOrder('2026-09-15 21:30:00');

    setZone('Asia/Dubai');

    $list = collect($this->actingAs($admin, 'admin')->getJson('/admin-api/orders')->assertOk()->json('orders'))
        ->firstWhere('id', $order->id);

    $drawer = $this->actingAs($admin, 'admin')->getJson('/admin-api/orders/' . $order->id)->assertOk();

    expect($drawer->json('created_at'))->toBe($list['created_at'])
        ->and(substr((string) $drawer->json('created_at'), 0, 10))->toBe('2026-09-16');
});
