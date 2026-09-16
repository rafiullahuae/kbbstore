<?php

declare(strict_types=1);

/*
 * THE CUSTOMERS SCREEN, AND THE THREE WAYS IT WAS STATING THINGS THAT WERE NOT SO.
 *
 * 1. A DATE FILTER ON A DIFFERENT CLOCK FROM THE COLUMN BESIDE IT.
 *
 * `customers.created_at` holds a UTC instant and the owner types, and reads,
 * Dubai. The "registered between" filter compared the typed date against the
 * UTC CALENDAR DAY, so a customer who signed up at 01:30 Dubai -- stored as
 * 21:30 UTC the day before -- was filed by the filter under the previous day
 * while the row next to it printed the Dubai date StoreTime::iso() renders.
 * Two numbers on one screen describing one customer, and they disagreed. The
 * Orders list carried the identical defect on "placed between".
 *
 * These tests are written AT THE SECOND either side of each boundary, not in
 * the comfortable middle of a range, because the change is also a change in how
 * the bound COMPILES -- a whole-day comparison became a half-open instant
 * range -- and a test inside the range passes under both.
 *
 * BOUNDS ARE HANDED TO THE BUILDER AS CarbonImmutable, NEVER AS A STRING, and
 * these run on BOTH engines for that reason: SQLite compares TEXT and 'T'
 * (0x54) sorts above ' ' (0x20), so an ISO-8601 string bound drops the whole
 * boundary day there while MySQL coerces it and does not. A SQLite-only suite
 * would go green on a bound that is broken in production, and a MySQL-only one
 * would miss the reverse.
 *
 * 2. DEMO ROWS ON A LIST THAT DID NOT SAY SO.
 *
 * The rule this store follows is: FIGURES EXCLUDE DEMO; LISTS SHOW IT AND MARK
 * IT. The Customers list showed demo-seeded customers with AED 0 lifetime spend
 * -- correct, since spend counts real orders only -- and nothing at all saying
 * why, so they were indistinguishable from real shoppers who had never bought.
 * The Orders list was no better off: it has DRAWN a `demo` badge from
 * `o.is_demo` since Demo Content shipped, but the serialiser behind the
 * endpoint the screen actually calls never sent the field, so that badge had
 * never once appeared.
 *
 * 3. AN EDITOR THAT NEVER SAVED. See the blade assertions at the end.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Services\SettingsService;
use App\Support\DemoSeed;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CustomersAdminRoutes;
use Tests\Support\OrdersAdminRoutes;

/* ------------------------------------------------------------------ fixtures */

/**
 * Dubai is UTC+04:00 and has no DST, so every boundary below is arithmetic
 * anyone can check by eye: shop-local midnight on the 16th is 20:00 UTC on the
 * 15th. Named rather than repeated so a reader can see the four instants that
 * matter next to each other.
 */
const CST_ZONE = 'Asia/Dubai';

/** Shop-local 2026-09-16 00:00:00 exactly. */
const CST_DAY_OPENS = '2026-09-15 20:00:00';

/** Shop-local 2026-09-15 23:59:59 — one second before the range opens. */
const CST_SECOND_BEFORE = '2026-09-15 19:59:59';

/** Shop-local 2026-09-16 23:59:59 — the last second the range may include. */
const CST_DAY_CLOSES = '2026-09-16 19:59:59';

/** Shop-local 2026-09-17 00:00:00 — the first second it must not. */
const CST_SECOND_AFTER = '2026-09-16 20:00:00';

/** The day the owner types into both boxes. */
const CST_DAY = '2026-09-16';

function cstZone(string $zone = CST_ZONE): void
{
    app(SettingsService::class)->set(StoreTime::SETTING_KEY, $zone);
    SettingsService::forgetMemo();
    Cache::flush();
}

function cstAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'CS Owner',
        'email' => 'cs-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * A customer registered at an exact UTC instant.
 *
 * Written straight to the column rather than through the model, because
 * Laravel's `Model::fromDateTime()` formats a DateTime with `->format()`
 * WITHOUT converting its zone first: handing a Dubai-zoned Carbon to create()
 * would store the Dubai WALL CLOCK into a UTC column and the fixture itself
 * would be the bug under test.
 */
function cstCustomer(string $utc, string $label = 'row'): Customer
{
    static $n = 0;
    $n++;

    $customer = Customer::create([
        'name' => 'CS ' . $label . ' ' . $n,
        'email' => 'cs-' . $n . '-' . uniqid() . '@example.test',
    ]);

    DB::table('customers')->where('id', $customer->id)->update(['created_at' => $utc]);

    return $customer->refresh();
}

/** An order placed at an exact UTC instant. */
function cstOrder(string $utc, ?Customer $customer = null): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'customer_id' => $customer?->id,
        'order_number' => 'CS-' . $n . '-' . uniqid(),
        'email' => 'cs-order-' . $n . '@example.test',
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => 10000,
        'total' => 10000,
    ]);

    DB::table('orders')->where('id', $order->id)->update(['created_at' => $utc]);

    return $order->refresh();
}

/** Mark a record as demo exactly the way DemoContentController::log() does. */
function cstMarkDemo(string $type, string $model, int $id): void
{
    DB::table(DemoSeed::TABLE)->insert([
        'type' => $type,
        'model' => $model,
        'record_id' => $id,
        'created_at' => now(),
    ]);
}

/** The ids the customers list returns for a given query string. */
function cstCustomerIds(string $query): array
{
    $response = test()->getJson('/admin-api/customers/list?' . $query);
    $response->assertOk();

    return array_map(static fn ($row) => (int) $row['id'], $response->json('customers'));
}

/** The ids the orders list returns for a given query string. */
function cstOrderIds(string $query): array
{
    $response = test()->getJson('/admin-api/orders-list?' . $query);
    $response->assertOk();

    return array_map(static fn ($row) => (int) $row['id'], $response->json('orders'));
}

function cstBlade(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

beforeEach(function () {
    Cache::flush();
    SettingsService::forgetMemo();
    cstZone();
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/* ------------------------------------------- 1. the filter bound, at the second */

it('opens the registered-from range at shop-local midnight, to the second', function () {
    CustomersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    // Shop-local 2026-09-16 00:00:00 — the first instant the owner's day holds.
    $inside = cstCustomer(CST_DAY_OPENS, 'opens');

    // One second earlier: shop-local 2026-09-15 23:59:59. A different day on
    // the owner's clock, and the SAME UTC day as the row above it — which is
    // exactly why a UTC-calendar-day comparison cannot tell them apart.
    $outside = cstCustomer(CST_SECOND_BEFORE, 'before');

    $ids = cstCustomerIds('from=' . CST_DAY . '&per_page=100');

    expect(in_array($inside->id, $ids, true))
        ->toBeTrue('the customer registered at shop-local midnight was dropped by "registered from"');
    expect(in_array($outside->id, $ids, true))
        ->toBeFalse('the customer registered one second before the range was included by "registered from"');
});

it('closes the registered-to range at the end of the shop-local day, to the second', function () {
    CustomersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    // Shop-local 2026-09-16 23:59:59 — the last instant the owner's day holds.
    $inside = cstCustomer(CST_DAY_CLOSES, 'closes');

    // One second later: shop-local 2026-09-17 00:00:00. The next day to the
    // owner, the same UTC day to the column.
    $outside = cstCustomer(CST_SECOND_AFTER, 'after');

    $ids = cstCustomerIds('to=' . CST_DAY . '&per_page=100');

    expect(in_array($inside->id, $ids, true))
        ->toBeTrue('the customer registered in the last second of the day was dropped by "registered to"');
    expect(in_array($outside->id, $ids, true))
        ->toBeFalse('the customer registered after the day ended was included by "registered to"');
});

/*
 * THE BUG AS THE OWNER MEETS IT.
 *
 * One customer, one screen, two statements about them. The row says 16
 * September because StoreTime::iso() renders the shop's clock; the filter for
 * 16 September must therefore find that row. When it did not, neither number
 * could be trusted, and the screen was not merely wrong -- it was arguing with
 * itself in front of the person who has to act on it.
 */
it('agrees with the date it prints on the row', function () {
    CustomersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    // 01:30 on 16 September in Dubai. Stored, correctly, as 21:30 on the 15th.
    $customer = cstCustomer('2026-09-15 21:30:00', 'small hours');

    $row = collect(test()->getJson('/admin-api/customers/list?per_page=100')->json('customers'))
        ->firstWhere('id', $customer->id);

    expect($row)->not->toBeNull('the customer is not on the unfiltered list at all');

    $printed = substr((string) $row['registered_at'], 0, 10);
    expect($printed)->toBe(CST_DAY, 'the row does not print the shop-local day');

    // And the filter for the day the row prints finds the row.
    $ids = cstCustomerIds('from=' . CST_DAY . '&to=' . CST_DAY . '&per_page=100');

    expect(in_array($customer->id, $ids, true))
        ->toBeTrue('the row prints ' . $printed . ' but the filter for ' . $printed . ' does not return it');
});

/*
 * The zone is a SETTING, not a constant, so the bound has to follow it. London
 * is the interesting second case because it is the legal value with a DST
 * transition -- the reason the day step is taken on the shop's clock rather
 * than by adding 24 hours to a UTC instant.
 */
it('follows the store timezone setting rather than a hard-coded offset', function () {
    CustomersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    // Shop-local 2026-09-16 00:00:00 in Dubai; still the 15th in London.
    $customer = cstCustomer(CST_DAY_OPENS, 'zone');

    expect(in_array($customer->id, cstCustomerIds('from=' . CST_DAY . '&per_page=100'), true))
        ->toBeTrue('Dubai: the customer at shop-local midnight was dropped');

    cstZone('Europe/London');

    // 20:00 UTC on the 15th is 21:00 on the 15th in London — the day before.
    expect(in_array($customer->id, cstCustomerIds('from=' . CST_DAY . '&per_page=100'), true))
        ->toBeFalse('London: a bound computed for Dubai was used for a London shop');
    expect(in_array($customer->id, cstCustomerIds('to=2026-09-15&per_page=100'), true))
        ->toBeTrue('London: the customer is not in the London day it actually falls in');
});

it('ignores a malformed date bound instead of returning an empty list or a 500', function () {
    CustomersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    $customer = cstCustomer(CST_DAY_OPENS, 'garbage');

    // The screen's inputs are type="date" and cannot send this; a hand-made
    // request used to get a silently empty list, which is its own quiet lie.
    $ids = cstCustomerIds('from=not-a-date&per_page=100');

    expect(in_array($customer->id, $ids, true))
        ->toBeTrue('a malformed bound emptied the list rather than being ignored');
});

/* ---------------------------------------- the same defect on the orders list */

it('opens and closes the orders placed-between range on the shop clock, to the second', function () {
    OrdersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    $opens = cstOrder(CST_DAY_OPENS);
    $before = cstOrder(CST_SECOND_BEFORE);
    $closes = cstOrder(CST_DAY_CLOSES);
    $after = cstOrder(CST_SECOND_AFTER);

    $ids = cstOrderIds('from=' . CST_DAY . '&to=' . CST_DAY . '&per_page=100');

    expect(in_array($opens->id, $ids, true))
        ->toBeTrue('the order placed at shop-local midnight was dropped');
    expect(in_array($closes->id, $ids, true))
        ->toBeTrue('the order placed in the last second of the day was dropped');
    expect(in_array($before->id, $ids, true))
        ->toBeFalse('the order placed one second before the day was included');
    expect(in_array($after->id, $ids, true))
        ->toBeFalse('the order placed one second after the day was included');
});

/*
 * A GUARD, NOT A STYLE RULE.
 *
 * The whole-day builder helper is the shape of this bug: it compares calendar
 * days, and the two sides of the comparison are on different clocks. Reaching
 * for it again on a UTC column is the regression, so it is named here rather
 * than in the controllers -- a guard that quoted the token inside the file it
 * guards would match its own comment, a mistake this repo has made twice.
 */
it('leaves no whole-day date comparison behind on either list controller', function () {
    foreach ([
        'CustomersApiController',
        'OrdersApiController',
    ] as $class) {
        $source = (string) file_get_contents(app_path('Http/Controllers/Admin/' . $class . '.php'));

        expect(str_contains($source, 'whereDate('))
            ->toBeFalse($class . ' compares a typed date against a UTC column as a calendar day again');

        expect(str_contains($source, 'StoreTime::startOfDayUtc('))
            ->toBeTrue($class . ' no longer converts its date bounds onto the shop clock');
    }
});

/* ------------------------------------------------- 2. demo rows, marked as such */

it('marks demo customers on the list and leaves them on it', function () {
    CustomersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    $real = cstCustomer(CST_DAY_OPENS, 'real');
    $demo = cstCustomer(CST_DAY_OPENS, 'demo');

    cstMarkDemo('customers', Customer::class, $demo->id);

    $rows = collect(test()->getJson('/admin-api/customers/list?per_page=100')->json('customers'));

    $demoRow = $rows->firstWhere('id', $demo->id);
    $realRow = $rows->firstWhere('id', $real->id);

    // Shown, not hidden — putting rows on screens is what Demo Content is for.
    expect($demoRow)->not->toBeNull('the demo customer was hidden from the list');
    expect($realRow)->not->toBeNull('the real customer went missing');

    expect(array_key_exists('is_demo', $demoRow))
        ->toBeTrue('the customers list row does not carry is_demo at all');
    expect($demoRow['is_demo'])->toBeTrue('the demo customer is not marked');
    expect($realRow['is_demo'])->toBeFalse('a real customer was marked as demo');

    // And the reason the badge matters: the spend column beside it reads zero
    // because spend counts real orders only, so without the flag this row is
    // indistinguishable from a shopper who has never bought anything.
    expect((int) $demoRow['spend_fils'])->toBe(0);
});

it('marks demo orders on the list the badge was already drawing', function () {
    OrdersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    $real = cstOrder(CST_DAY_OPENS);
    $demo = cstOrder(CST_DAY_OPENS);

    cstMarkDemo('orders', Order::class, $demo->id);

    $rows = collect(test()->getJson('/admin-api/orders-list?per_page=100')->json('orders'));

    $demoRow = $rows->firstWhere('id', $demo->id);
    $realRow = $rows->firstWhere('id', $real->id);

    expect($demoRow)->not->toBeNull('the demo order was hidden from the list');
    expect(array_key_exists('is_demo', $demoRow))
        ->toBeTrue('the orders list row does not carry is_demo, so the badge stays dead');
    expect($demoRow['is_demo'])->toBeTrue('the demo order is not marked');
    expect($realRow['is_demo'])->toBeFalse('a real order was marked as demo');
});

/*
 * `demo_seed_log` is created lazily by DemoContentController::ensureTable(), so
 * a build whose migration step was skipped has no such table until Demo Content
 * is first opened. The truthful answer then is "nothing is demo" — not a 500 on
 * the screen that lists every customer the store has.
 */
it('still lists customers when the demo log table does not exist', function () {
    CustomersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    $customer = cstCustomer(CST_DAY_OPENS, 'no log');

    Schema::dropIfExists(DemoSeed::TABLE);
    app()->forgetInstance('kbb.demo_seed_log.exists');

    $rows = collect(test()->getJson('/admin-api/customers/list?per_page=100')->json('customers'));
    $row = $rows->firstWhere('id', $customer->id);

    expect($row)->not->toBeNull('the list died when the demo log table was absent');
    expect($row['is_demo'])->toBeFalse('nothing has been seeded, so nothing is demo');
});

/*
 * The allowlist this screen's serialiser is built on. `customers` carries a
 * bcrypt hash, a WordPress phpass hash and a remember token, and adding a field
 * to a list row is exactly the moment somebody reaches for the whole model.
 */
it('still returns no credential columns now that the row carries is_demo', function () {
    CustomersAdminRoutes::wire(app());
    test()->actingAs(cstAdmin(), 'admin');

    cstCustomer(CST_DAY_OPENS, 'secrets');

    $row = test()->getJson('/admin-api/customers/list?per_page=100')->json('customers.0');

    foreach (['password', 'legacy_password', 'remember_token'] as $secret) {
        expect(array_key_exists($secret, $row))
            ->toBeFalse('the customers list row now carries ' . $secret);
    }
});

/* ---------------------------------------------- 3. the editor that never saved */

/*
 * "Date created" on the order detail screen rendered a date box and two time
 * boxes, pre-filled from the order, and NOTHING read them back — no handler, no
 * request, no endpoint, no route. Typing in them lost the edit in silence while
 * the screen gave every impression of having saved it.
 *
 * The decision was to print the value rather than wire the save: an order's
 * created_at is the bucket key for the dashboard, the 14-day chart, Analytics
 * and the placed-between filter directly above, so making it editable moves
 * money between reporting periods and is a write path needing validation, an
 * audit trail and a permission — none of which anyone has asked for. The honest
 * small change is to stop offering it.
 *
 * Asserted on the source rather than on rendered HTML: a class-name search of
 * the rendered console also matches the page's own inlined CSS.
 */
it('no longer renders an order date editor that nothing saves', function () {
    $blade = cstBlade();

    foreach (['odDateCreated', 'odTimeH', 'odTimeM'] as $id) {
        expect(str_contains($blade, $id))
            ->toBeFalse('the order date input ' . $id . ' is back, and nothing posts it');
    }

    // The dead layout class went with them rather than being left as cruft.
    expect(str_contains($blade, 'odtimegrid'))
        ->toBeFalse('the three-box date grid is still styled');
});

it('still shows the order date, as a printed value rather than an input', function () {
    $blade = cstBlade();

    expect(str_contains($blade, 'Date created'))
        ->toBeTrue('the order date stopped being shown at all');

    expect(str_contains($blade, 'odShopDateTime(o.created_at)'))
        ->toBeTrue('the order date is not rendered through the read-only formatter');

    // Read-only means it must not wear the input chrome. .odinp carries the
    // border, the inset shadow and the focus ring; a field with those on it
    // reads as editable whatever it is made of.
    preg_match('/\.odreadonly\{([^}]*)\}/', $blade, $m);
    $rule = $m[1] ?? '';

    expect($rule)->not->toBe('', 'there is no .odreadonly rule for the printed date');
    expect(str_contains($rule, 'border'))
        ->toBeFalse('the printed date has a border and still looks like an input');
    expect(str_contains($rule, 'box-shadow'))
        ->toBeFalse('the printed date has an inset shadow and still looks like an input');
});

/*
 * The formatter reads the shop's wall clock out of the string StoreTime::iso()
 * produced, rather than handing it to the browser's Date — which would
 * re-render the instant in the VIEWER's timezone and put the detail screen back
 * on a different clock from the list that linked to it.
 */
it('formats the printed order date without reinterpreting it in the browser zone', function () {
    $blade = cstBlade();

    preg_match('/function odShopDateTime\(iso\)\{(.*?)\n  \}/s', $blade, $m);
    $body = $m[1] ?? '';

    expect($body)->not->toBe('', 'odShopDateTime is not defined');
    expect(str_contains($body, 'new Date('))
        ->toBeFalse('the printed date is parsed with new Date(), which re-renders it in the viewer timezone');
});

/* ------------------------------------------------ the demo badge on the screen */

it('badges the demo customer row on the customers table', function () {
    $blade = cstBlade();

    expect(str_contains($blade, 'c.is_demo'))
        ->toBeTrue('the customers table does not read is_demo, so the flag is never shown');

    // Same wording and same marker as the Orders table, so one screen cannot
    // start calling it something else.
    expect(substr_count($blade, '>demo</span>'))
        ->toBe(2, 'the demo badge is not drawn identically on both lists');
});
