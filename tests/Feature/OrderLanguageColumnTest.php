<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Support\OrderEmirate;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersAdminRoutes;
use Tests\Support\OwnerAppRoutes as OA;

/*
 * The order's language on Store -> Orders, the order screen and the owner app
 * (Lane OLG).
 *
 * The owner: "i want here also another column, EN/AR if user placed order
 * through arabic version, it should AR, and if english, it should display EN.
 * same in the owner app too. and also on the order detailed page too." And:
 * "make sure no any process should be disturbed, just do it silently."
 *
 * Display only. `orders.locale` is written by App\Support\OrderLocale on
 * Order::creating and nothing here writes it.
 */

beforeEach(function () {
    OrderEmirate::reset();
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    OA::member(OA::admin());
});

afterEach(function () {
    OrderEmirate::reset();
});

function olgOrder(string $locale, array $attributes = []): Order
{
    static $n = 0;
    $n++;

    return Order::create(array_merge([
        'order_number' => 'OLG-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'email' => 'olg-' . $n . '@example.test',
        'status' => 'processing',
        'total' => 10000,
        'subtotal' => 10000,
        'currency' => 'AED',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'locale' => $locale,
        'shipping_address' => ['first_name' => 'Olg', 'line1' => 'Street 1', 'state' => 'Dubai', 'country' => 'AE'],
    ], $attributes));
}

function olgAdmin(): void
{
    OrdersAdminRoutes::wire(app());
    test()->actingAs(AdminUser::create([
        'name' => 'Olg Owner', 'email' => 'olg-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]), 'admin');
}

function olgAdminSrc(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/* ------------------------------------------------------------ admin list */

it('carries each order\'s language on the admin list, EN for anything that is not Arabic', function () {
    // DEFECT: every row reading EN (the field missing from the allowlisted
    // payload, so the screen's `o.locale` is undefined) -- an Arabic order
    // indistinguishable from an English one. MUTATION: drop 'orders.locale'
    // from rowQuery() and the AR row reads 'en'.
    olgAdmin();
    $ar = olgOrder('ar');
    $en = olgOrder('en');
    $odd = olgOrder('en');
    DB::table('orders')->where('id', $odd->id)->update(['locale' => 'fr']);

    $rows = collect(test()->getJson('/admin-api/orders-list')->assertOk()->json('orders'))->keyBy('id');

    expect($rows[$ar->id]['locale'])->toBe('ar')
        ->and($rows[$en->id]['locale'])->toBe('en')
        // Never the raw value: whatever is not 'ar' is shown as EN.
        ->and($rows[$odd->id]['locale'])->toBe('en');
});

it('draws a Lang column straight after Emirate, on by default, EN grey and AR pink', function () {
    // DEFECT: the column added OFF by default (nobody finds it), somewhere
    // other than after Emirate where the owner drew it, or drawn twice.
    // MUTATION: set `lang: false` in OL_COLS_DEFAULT and this is red.
    $src = olgAdminSrc();

    expect($src)->toMatch("/\\['emirate', 'Emirate'\\],\\s*(?:\\/\\*.*?\\*\\/\\s*)?\\['lang', 'Lang'\\],\\s*\\['status', 'Status'\\],/s")
        ->and($src)->toMatch('/var OL_COLS_DEFAULT = \\{\\s*emirate: true,\\s*lang: true,/')
        ->and(substr_count($src, "case 'lang':"))->toBe(1)
        ->and(substr_count($src, 'function olLangPill(locale)'))->toBe(1)
        ->and($src)->toContain("(ar ? 'ollang-ar' : 'grey')")
        ->and($src)->toContain("(ar ? 'AR' : 'EN')")
        ->and($src)->toContain('.pill.ollang-ar{background:#fce6ec;color:#a8475c}')
        // Every column that was there is still there, in its order, and the
        // ones that were off by default are still off.
        ->and($src)->toContain("['placed', 'Placed'], ['location', 'City'],")
        ->and($src)->toContain('payment: true, source: true, placed: true, location: false, contact: false, wc: false');
});

it('appends the language as the LAST column of the CSV, EN or AR', function () {
    // DEFECT: the export without the column the screen shows, or a column
    // inserted mid-row that shifts every one after it. MUTATION: drop
    // 'language' from the header and both expectations go red.
    olgAdmin();
    olgOrder('ar');

    $csv = test()->get('/admin-api/orders-export')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv))));
    $head = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);

    // Lane FG appended `free_gifts` after it, by the same rule: language is
    // now the second-last column and keeps its position.
    expect($head[27])->toBe('language')->and($head[26])->toBe('emirate')->and(count($head))->toBe(29)
        ->and($row[27])->toBe('AR')->and(count($row))->toBe(29)->and(end($head))->toBe('free_gifts')->and(end($row))->toBe('0');
});

it('keeps the admin list at the same number of queries for 3 orders and for 40 of mixed language', function () {
    // DEFECT: the language looked up per row. MUTATION: read
    // Order::find($o->id)->locale in rowToApi() and the 40 grows.
    olgAdmin();

    $count = function (int $n): int {
        Order::query()->withTrashed()->forceDelete();
        foreach (range(1, $n) as $i) {
            olgOrder($i % 2 ? 'ar' : 'en');
        }
        test()->getJson('/admin-api/orders-list?per_page=50')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/admin-api/orders-list?per_page=50')->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(40))->toBe($count(3));
});

/* ---------------------------------------------------------- order screen */

it('says the language on the admin order screen, with the Arabic email hint', function () {
    // DEFECT: the order screen with no idea which language the customer
    // shopped in. MUTATION: drop 'locale' from AdminOrderController::show()
    // and the payload expectation is red.
    olgAdmin();
    $ar = olgOrder('ar');
    $en = olgOrder('en');

    expect(test()->getJson('/admin-api/orders/' . $ar->id . '/detail')->assertOk()->json('locale'))->toBe('ar')
        ->and(test()->getJson('/admin-api/orders/' . $en->id . '/detail')->assertOk()->json('locale'))->toBe('en');

    $src = olgAdminSrc();
    expect(substr_count($src, 'function odLanguageLine(o)'))->toBe(1)
        ->and(substr_count($src, 'odLanguageLine(o)+'))->toBe(1)
        ->and($src)->toContain(">Language: '+(ar ? 'Arabic' : 'English')+' '+olLangPill(o.locale)")
        ->and($src)->toContain('Emails to this customer go out in Arabic');
});

/* ------------------------------------------------------------- owner app */

it('carries the language on the owner app\'s list and order, and draws the badge from the built bundle', function () {
    // DEFECT: "same in the owner app too" left undone -- the field missing
    // from the allowlisted owner-app payload, or the source changed and never
    // built. MUTATION: drop 'o.locale' from the list's select and the row
    // reads null; skip `npx vite build` and the bundle expectation is red.
    $ar = olgOrder('ar');
    $en = olgOrder('en');
    [$c] = OA::enrol($this);

    $rows = collect(OA::get($this, 'orders', $c)->assertOk()->json('orders'))->keyBy('id');
    expect($rows[$ar->id]['locale'])->toBe('ar')->and($rows[$en->id]['locale'])->toBe('en')
        ->and(OA::get($this, 'orders/' . $ar->id, $c)->assertOk()->json('order.locale'))->toBe('ar')
        ->and(OA::get($this, 'orders/' . $en->id, $c)->assertOk()->json('order.locale'))->toBe('en');

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $js = (string) file_get_contents(public_path('build/' . $manifest['resources/js/owner-app/owner-app.js']['file']));
    $css = (string) file_get_contents(public_path('build/' . $manifest['resources/css/owner-app/owner-app.css']['file']));
    expect($js)->toContain('Placed in ')->and($js)->toContain('<span class="lg')
        ->and($css)->toContain('.lg.ar{');
});

it('keeps the owner app orders list flat for 3 orders and for 40 of mixed language', function () {
    // DEFECT: a query per row for the badge. MUTATION: look the locale up per
    // order in OrdersController::row() and the 40 grows.
    $count = function (int $n): int {
        Order::query()->withTrashed()->forceDelete();
        foreach (range(1, $n) as $i) {
            olgOrder($i % 2 ? 'ar' : 'en');
        }
        [$c] = OA::enrol($this);
        OA::get($this, 'orders', $c)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        OA::get($this, 'orders', $c)->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(40))->toBe($count(3));
});
