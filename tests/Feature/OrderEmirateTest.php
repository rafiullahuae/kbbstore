<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Support\OrderEmirate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OrdersAdminRoutes;
use Tests\Support\OwnerAppRoutes as OA;

/*
 * Orders by emirate (Lane EM).
 *
 * The owner, on Store -> Orders: "i need here the Emirates / City column, so i
 * can see the orders are coming more from which city etc, and i can adjust my
 * campaigns ... also need some how in owner app too." Then "The City = Emirate,
 * so count the emirate data", with his codes "DXB, AUH, FUJ, ALAIN, UMQ, SHJ"
 * -- Al Ain counted on its own, not inside Abu Dhabi.
 *
 * What each defect would have looked like on the shop is said beside the test
 * that catches it.
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

/* ------------------------------------------------------------------ fixtures */

function emOrder(array $attributes = []): Order
{
    static $n = 0;
    $n++;

    return Order::create(array_merge([
        'order_number' => 'EM-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'email' => 'em-' . $n . '@example.test',
        'status' => 'processing',
        'total' => 10000,
        'subtotal' => 10000,
        'currency' => 'AED',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $attributes));
}

/** An order shipped to $state / $city in $country, emirate not read yet. */
function emShip(?string $state, ?string $city = null, string $country = 'AE', array $attributes = []): Order
{
    return emOrder(array_merge([
        'shipping_address' => array_filter(['first_name' => 'Em', 'line1' => 'Street 1', 'city' => $city, 'state' => $state, 'country' => $country], fn ($v) => $v !== null),
    ], $attributes));
}

function emAdmin(): void
{
    OrdersAdminRoutes::wire(app());
    test()->actingAs(AdminUser::create([
        'name' => 'Em Owner', 'email' => 'em-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]), 'admin');
}

function emList(string $query = ''): array
{
    return test()->getJson('/admin-api/orders-list' . ($query === '' ? '' : '?' . $query))->assertOk()->json();
}

/** @return array<string,int> code => orders, from the strip */
function emStrip(array $body): array
{
    $out = [];
    foreach ($body['emirates']['rows'] as $r) {
        $out[$r['code']] = $r['orders'];
    }
    $out['Other'] = $body['emirates']['other'];

    return $out;
}

function emMigration(): object
{
    return require database_path('migrations/2027_11_02_200000_add_ship_emirate_to_orders.php');
}

/** Every address shape the shop has stored, and the answer the owner expects. */
dataset('addresses', [
    'checkout: emirate in state' => [['state' => 'Dubai', 'country' => 'AE'], null, 'Dubai'],
    'cart popup: emirate in city, lower case' => [['city' => 'dubai', 'country' => 'AE'], null, 'Dubai'],
    'WooCommerce ISO code' => [['state' => 'AE-DU', 'country' => 'AE'], null, 'Dubai'],
    'Arabic' => [['state' => 'دبي', 'country' => 'AE'], null, 'Dubai'],
    'WooCommerce area as state, emirate as city' => [['state' => 'Al Barsha', 'city' => 'Dubai', 'country' => 'AE'], null, 'Dubai'],
    'airport code' => [['state' => 'SHJ', 'country' => 'AE'], null, 'Sharjah'],
    'Umm Al Quwain spelt the old way' => [['state' => 'Umm al Qaiwain', 'country' => 'AE'], null, 'Umm Al Quwain'],
    'Abu Dhabi' => [['state' => 'Abu Dhabi', 'city' => 'Khalifa City', 'country' => 'AE'], null, 'Abu Dhabi'],
    'Al Ain in the city' => [['state' => 'Abu Dhabi', 'city' => 'Al Ain', 'country' => 'AE'], null, 'Al Ain'],
    'Al Ain in the state' => [['state' => 'Al-Ain', 'country' => 'AE'], null, 'Al Ain'],
    'Al Ain in Arabic' => [['city' => 'العين', 'country' => 'AE'], null, 'Al Ain'],
    'Al Ain in the area line' => [['state' => 'Abu Dhabi', 'line2' => 'Al Jimi, alain', 'country' => 'AE'], null, 'Al Ain'],
    'Al Ain Road in Dubai stays Dubai' => [['state' => 'Dubai', 'line1' => 'Dubai - Al Ain Road', 'country' => 'AE'], null, 'Dubai'],
    'no country, emirate readable' => [['city' => 'Ajman'], null, 'Ajman'],
    'shipping blank, billing has it' => [null, ['state' => 'Fujairah', 'country' => 'AE'], 'Fujairah'],
    'shipping unreadable, billing has it' => [['city' => 'Somewhere', 'country' => 'AE'], ['state' => 'RAK', 'country' => 'AE'], 'Ras Al Khaimah'],
    'abroad' => [['state' => 'Riyadh', 'city' => 'Riyadh', 'country' => 'SA'], ['state' => 'Dubai', 'country' => 'AE'], ''],
    'UAE but unreadable' => [['city' => 'Somewhere', 'country' => 'AE'], null, ''],
    'no address at all' => [null, null, ''],
    'a JSON string, as a raw select hands it back' => ['{"state":"Ajman","country":"AE"}', null, 'Ajman'],
    'odd blob' => ['null', '[]', ''],
]);

/* ---------------------------------------------------------------- the rule */

it('reads the emirate every way the shop has stored it, Al Ain on its own', function (mixed $ship, mixed $bill, string $expect) {
    // DEFECT: "dubai" typed in the cart popup's city box, or a WooCommerce row
    // with the area as the state, counted under Other -- or Al Ain folded into
    // Abu Dhabi, which is what AddressRegions does for the checkout and the
    // owner said not to. MUTATION: drop the Al Ain branch in read() and the
    // three Al Ain rows go to 'Abu Dhabi'.
    expect(OrderEmirate::read($ship, $bill))->toBe($expect);
})->with('addresses');

it('keeps the migration\'s literal copy of the rule identical to the class', function (mixed $ship, mixed $bill, string $expect) {
    // DEFECT: the backfill and the live fill disagreeing, so an old order and
    // a new one to the same street land in different buckets. MUTATION: change
    // a spelling in either copy's Al Ain pattern and this is red.
    expect(emMigration()->emirateFor($ship, $bill))->toBe($expect)
        ->and(OrderEmirate::read($ship, $bill))->toBe($expect);
})->with('addresses');

it('gives the owner\'s own short codes', function () {
    // DEFECT: UAQ where he wrote UMQ, or no ALAIN at all.
    expect(OrderEmirate::CODES)->toBe([
        'Dubai' => 'DXB', 'Abu Dhabi' => 'AUH', 'Al Ain' => 'ALAIN', 'Sharjah' => 'SHJ',
        'Ajman' => 'AJM', 'Umm Al Quwain' => 'UMQ', 'Ras Al Khaimah' => 'RAK', 'Fujairah' => 'FUJ',
    ])
        ->and(OrderEmirate::filterValue('dxb'))->toBe('Dubai')
        ->and(OrderEmirate::filterValue('Al Ain'))->toBe('Al Ain')
        ->and(OrderEmirate::filterValue('ALAIN'))->toBe('Al Ain')
        ->and(OrderEmirate::filterValue('other'))->toBe('')
        ->and(OrderEmirate::filterValue('Dubai\' OR 1=1'))->toBeNull()
        ->and(OrderEmirate::filterValue(['Dubai']))->toBeNull()
        ->and(OrderEmirate::filterValue(''))->toBeNull();
});

it('leaves the checkout\'s own emirate list exactly as it was', function () {
    // The owner asked for Al Ain as a bucket in reports, not as an eighth
    // emirate at checkout. MUTATION: add Al Ain to AddressRegions and red.
    expect(\App\Support\AddressRegions::values('AE'))->toBe(['Abu Dhabi', 'Sharjah', 'Fujairah', 'Umm Al Quwain', 'Dubai', 'Ras Al Khaimah', 'Ajman'])
        ->and(\App\Support\AddressRegions::canonical('AE', 'Al Ain'))->toBe('Abu Dhabi');
});

/* -------------------------------------------------------- the migration */

it('backfills every existing order, a handful of statements per chunk, and is safe to run twice', function () {
    // DEFECT: the column added and left NULL on the live shop's 2,500 orders,
    // so the strip read "Reading 2,500" -- or one UPDATE per order.
    $dubai = emShip('Dubai');
    $alain = emShip(null, 'Al Ain');
    $abroad = emShip('Riyadh', null, 'SA');
    $none = emOrder();
    $many = collect(range(1, 30))->map(fn ($i) => emShip(['Sharjah', 'AE-AJ', 'دبي'][$i % 3])->id);

    DB::table('orders')->update(['ship_emirate' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    emMigration()->up();
    $statements = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_starts_with(strtolower($q), 'update'))->count();
    DB::disableQueryLog();

    $stored = DB::table('orders')->pluck('ship_emirate', 'id');

    expect($stored[$dubai->id])->toBe('Dubai')
        ->and($stored[$alain->id])->toBe('Al Ain')
        ->and($stored[$abroad->id])->toBe('')
        ->and($stored[$none->id])->toBe('')
        ->and($stored->filter(fn ($v) => $v === null)->count())->toBe(0)
        ->and(DB::table('orders')->whereIn('id', $many)->distinct()->pluck('ship_emirate')->sort()->values()->all())->toBe(['Ajman', 'Dubai', 'Sharjah'])
        // One UPDATE per distinct answer (Dubai, Al Ain, '', Sharjah, Ajman),
        // not one per order. MUTATION: update row by row and this is 34.
        ->and($statements)->toBe(5);

    // Again: nothing to read, nothing written, no error.
    DB::table('orders')->where('id', $dubai->id)->update(['ship_emirate' => 'Sharjah']);
    emMigration()->up();
    expect(DB::table('orders')->where('id', $dubai->id)->value('ship_emirate'))->toBe('Sharjah');
});

it('lands even when the server still runs the old classes: no migration names a class this lane adds', function () {
    /*
     * What happened on 2.60.512: the updater runs migrations in the request
     * that copied the files, opcache served the OLD class, and a migration that
     * read a constant only the new class had failed with "Undefined constant";
     * the update rolled back. App\Support\OrderEmirate and the command do not
     * exist on the server until this package lands, so no migration may name
     * them. Comments are stripped first, so the explanation above each is no
     * false alarm. MUTATION: call OrderEmirate::read() from the backfill and
     * this is red.
     */
    $strip = function (string $file): string {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        return $code;
    };
    $offenders = [];

    foreach (glob(database_path('migrations/*.php')) as $file) {
        $code = $strip($file);

        foreach (['OrderEmirate', 'FillOrderEmirates'] as $class) {
            if (str_contains($code, $class)) {
                $offenders[] = basename($file) . ' names ' . $class;
            }
        }
    }

    expect($offenders)->toBe([]);

    // And the one class it does call was on the server before this package.
    $code = $strip(database_path('migrations/2027_11_02_200000_add_ship_emirate_to_orders.php'));
    preg_match_all('/App\\\\[A-Za-z\\\\]+/', $code, $m);
    expect(array_values(array_unique($m[0])))->toBe(['App\Support\AddressRegions']);
});

/* -------------------------------------------------------- the admin list */

it('shows the emirate on every row: short code, full name, and the order a second old', function () {
    // DEFECT: an order placed a second ago (the checkout does not write the
    // column) shown with no emirate, or counted under "Reading" until the
    // cron came round. MUTATION: drop the unread refill in listing() and the
    // strip's DXB count is 1, not 2.
    emAdmin();
    $old = emShip('Dubai');
    OrderEmirate::fillMissing();
    $new = emShip('Al Barsha', 'Dubai');
    $al = emShip(null, 'العين');
    $sa = emShip('Riyadh', 'Riyadh', 'SA');

    expect(DB::table('orders')->where('id', $new->id)->value('ship_emirate'))->toBeNull();

    $body = emList();
    $rows = collect($body['orders'])->keyBy('id');

    expect($rows[$old->id]['emirate'])->toBe('Dubai')->and($rows[$old->id]['emirate_code'])->toBe('DXB')
        ->and($rows[$new->id]['emirate'])->toBe('Dubai')->and($rows[$new->id]['emirate_code'])->toBe('DXB')
        ->and($rows[$al->id]['emirate_code'])->toBe('ALAIN')
        ->and($rows[$sa->id]['emirate'])->toBeNull()->and($rows[$sa->id]['emirate_code'])->toBeNull()
        ->and($rows[$sa->id]['country'])->toBe('SA')
        ->and(emStrip($body))->toMatchArray(['DXB' => 2, 'ALAIN' => 1, 'AUH' => 0, 'Other' => 1])
        ->and($body['emirates']['unread'])->toBe(0)
        ->and(DB::table('orders')->where('id', $new->id)->value('ship_emirate'))->toBe('Dubai');
});

it('counts the strip for the view on screen: search, status chip and filters, like the money tiles', function () {
    // DEFECT: a strip that counted the whole table while the list showed one
    // status or one payment method -- two numbers on one screen that disagree.
    emAdmin();
    emShip('Dubai', null, 'AE', ['status' => 'completed', 'payment_method' => 'tabby']);
    emShip('Dubai', null, 'AE', ['status' => 'cancelled', 'payment_method' => 'cod']);
    emShip('Sharjah', null, 'AE', ['status' => 'completed', 'payment_method' => 'cod', 'email' => 'findme@example.test']);
    emShip('Abu Dhabi', 'Al Ain', 'AE', ['status' => 'processing', 'payment_method' => 'cod']);
    $trashed = emShip('Ajman', null, 'AE', ['status' => 'completed']);
    $trashed->delete();

    expect(emStrip(emList()))->toMatchArray(['DXB' => 2, 'SHJ' => 1, 'ALAIN' => 1, 'AJM' => 0])
        ->and(emStrip(emList('filter=completed')))->toMatchArray(['DXB' => 1, 'SHJ' => 1, 'ALAIN' => 0])
        ->and(emStrip(emList('filter=paid')))->toMatchArray(['DXB' => 1, 'SHJ' => 1, 'ALAIN' => 1])
        ->and(emStrip(emList('payment=cod')))->toMatchArray(['DXB' => 1, 'SHJ' => 1, 'ALAIN' => 1])
        ->and(emStrip(emList('search=findme')))->toMatchArray(['DXB' => 0, 'SHJ' => 1])
        // Busiest first.
        ->and(emList()['emirates']['rows'][0]['code'])->toBe('DXB')
        // Not in the trash: a bin is not where campaigns are planned.
        ->and(emList('filter=trashed')['emirates'])->toBeNull();
});

it('filters the list by emirate and ignores junk, while the strip keeps every emirate', function () {
    // DEFECT: clicking DXB emptied every other chip on the strip (no way back
    // but Clear), or a junk value matched nothing and showed an empty shop.
    emAdmin();
    $d1 = emShip('Dubai', null, 'AE', ['status' => 'completed']);
    $d2 = emShip('DXB', null, 'AE', ['status' => 'cancelled']);
    $s = emShip('Sharjah');
    $a = emShip(null, 'Al Ain');
    $x = emShip('Riyadh', null, 'SA');

    $dxb = emList('emirate=DXB');
    expect(collect($dxb['orders'])->pluck('id')->sort()->values()->all())->toBe([$d1->id, $d2->id])
        ->and($dxb['emirate'])->toBe('Dubai')
        ->and($dxb['total'])->toBe(2)
        // The status chips describe Dubai...
        ->and($dxb['counts']['completed'])->toBe(1)->and($dxb['counts']['processing'])->toBe(0)->and($dxb['counts']['all'])->toBe(2)
        // ...the strip still shows Sharjah, so the next click is one click.
        ->and(emStrip($dxb))->toMatchArray(['DXB' => 2, 'SHJ' => 1, 'ALAIN' => 1, 'Other' => 1])
        // And the money tiles are Dubai's.
        ->and($dxb['summary']['orders'])->toBe(2);

    expect(collect(emList('emirate=Al%20Ain')['orders'])->pluck('id')->all())->toBe([$a->id])
        ->and(collect(emList('emirate=other')['orders'])->pluck('id')->all())->toBe([$x->id])
        ->and(emList('emirate=other')['emirate'])->toBe('other');

    $junk = emList('emirate=' . urlencode("Dubai' OR '1'='1"));
    expect($junk['total'])->toBe(5)->and($junk['emirate'])->toBeNull();
    expect(emList('emirate[]=Dubai')['total'])->toBe(5);
});

it('appends Emirate as the LAST column of the CSV and moves no other column', function () {
    // DEFECT: a column inserted mid-row shifts every column after it, and the
    // owner's spreadsheet reads totals out of the phone column.
    emAdmin();
    emShip(null, 'Al Ain');

    $csv = test()->get('/admin-api/orders-export')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv))));
    $head = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);

    expect(array_slice($head, 0, 26))->toBe([
        'id', 'wc_order_id', 'order_number', 'status', 'counts_as_revenue',
        'customer_id', 'customer_name', 'email', 'phone', 'city', 'country',
        'units', 'lines', 'total_fils', 'total', 'refunded_fils', 'refunded',
        'net_fils', 'net', 'payment', 'placed_at', 'paid_at', 'completed_at',
        'trashed', 'currency', 'is_demo',
    ])->and(end($head))->toBe('emirate')->and(count($head))->toBe(27)
        ->and(end($row))->toBe('Al Ain');
});

it('keeps the list at the same number of queries for 3 orders and for 40, and inside its budget', function () {
    // DEFECT: a query per order, or per emirate. MUTATION: count the strip
    // with its own statement per emirate and both numbers grow.
    emAdmin();

    $count = function (int $n): int {
        Order::query()->withTrashed()->forceDelete();
        foreach (range(1, $n) as $i) {
            emShip(['Dubai', 'Sharjah', 'Abu Dhabi', 'Riyadh'][$i % 4], $i % 5 === 0 ? 'Al Ain' : null, $i % 4 === 3 ? 'SA' : 'AE');
        }
        emList('per_page=50');   // warm: reads the new orders' emirates once
        DB::flushQueryLog();
        DB::enableQueryLog();
        emList('per_page=50');
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    $three = $count(3);
    $forty = $count(40);

    // AdminOrdersTest holds the page to 8; the strip rides in the chip query.
    expect($forty)->toBe($three)->and($forty)->toBeLessThanOrEqual(8);
});

it('reads a new order\'s emirate in a fixed number of statements, however many are waiting', function () {
    emShip('Dubai');
    emShip('Sharjah');
    foreach (range(1, 20) as $i) {
        emShip($i % 2 ? 'Dubai' : 'Ajman');
    }
    DB::table('orders')->update(['ship_emirate' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $n = OrderEmirate::fillMissing();
    $q = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One SELECT, one UPDATE per answer (Dubai, Sharjah, Ajman).
    expect($n)->toBe(22)->and($q)->toBe(4)
        ->and(DB::table('orders')->whereNull('ship_emirate')->count())->toBe(0)
        // Nothing left: one indexed query.
        ->and(OrderEmirate::fillMissing())->toBe(0);

    // The limit is honoured, newest first.
    DB::table('orders')->update(['ship_emirate' => null]);
    expect(OrderEmirate::fillMissing(5))->toBe(5)
        ->and(DB::table('orders')->whereNotNull('ship_emirate')->min('id'))->toBe(DB::table('orders')->orderByDesc('id')->skip(4)->value('id'));
});

it('is filled by the scheduler every minute, through a command that exists', function () {
    // DEFECT: the strip only right while somebody had the Orders list open.
    $o = emShip('Fujairah');
    DB::table('orders')->update(['ship_emirate' => null]);

    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'kbb:order-emirates'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');

    $this->artisan('kbb:order-emirates')->assertSuccessful();
    expect(DB::table('orders')->where('id', $o->id)->value('ship_emirate'))->toBe('Fujairah');
});

it('stores the new emirate when the owner edits an order\'s address', function () {
    // DEFECT: an address corrected from Sharjah to Dubai on the order screen
    // went on counting under SHJ for ever, because nothing reads a row twice.
    emAdmin();
    $o = emShip('Sharjah');
    OrderEmirate::fillMissing();

    test()->putJson('/admin-api/orders/' . $o->id . '/address', [
        'type' => 'shipping',
        'address' => ['state' => 'Dubai', 'country' => 'AE'],
    ])->assertOk();

    expect(DB::table('orders')->where('id', $o->id)->value('ship_emirate'))->toBe('Dubai');

    test()->putJson('/admin-api/orders/' . $o->id . '/address', [
        'type' => 'shipping',
        'address' => ['city' => 'Al Ain', 'state' => 'Abu Dhabi', 'country' => 'AE'],
    ])->assertOk();

    expect(DB::table('orders')->where('id', $o->id)->value('ship_emirate'))->toBe('Al Ain');
});

it('never 500s while the column is not there yet: new code against the old schema', function () {
    /*
     * The updater copies the files first and runs the migrations after, so
     * for a few seconds this code runs against an `orders` table with no
     * ship_emirate. A list that selected it then answered 500 -- the Orders
     * screen, the owner app and the export all at once. MUTATION: remove the
     * isMissingColumn() retry in OrdersApiController::index() and this is a
     * 500 with "no such column: orders.ship_emirate".
     */
    emAdmin();
    $o = emShip(null, 'Al Ain');
    emShip('Dubai', null, 'AE', ['status' => 'completed']);

    Schema::table('orders', function ($t) {
        $t->dropIndex('orders_ship_emirate_index');
        $t->dropColumn('ship_emirate');
    });

    $body = emList();
    expect($body['total'])->toBe(2)
        ->and($body['emirates'])->toBeNull()
        ->and(collect($body['orders'])->keyBy('id')[$o->id]['emirate_code'])->toBe('ALAIN');

    // A filter that cannot be applied is ignored, not a 500.
    expect(emList('emirate=DXB')['total'])->toBe(2);

    OrderEmirate::reset();
    $csv = test()->get('/admin-api/orders-export')->assertOk()->streamedContent();
    expect(substr_count($csv, 'Al Ain'))->toBeGreaterThanOrEqual(1);

    OrderEmirate::reset();
    test()->putJson('/admin-api/orders/' . $o->id . '/address', ['type' => 'shipping', 'address' => ['city' => 'Dubai', 'country' => 'AE']])->assertOk();

    OrderEmirate::reset();
    expect(OrderEmirate::fillMissing())->toBe(0)->and(OrderEmirate::ready())->toBeFalse();

    OrderEmirate::reset();
    $this->artisan('kbb:order-emirates')->assertSuccessful();
});

/* -------------------------------------------------------- the screen */

it('draws the Emirate column straight after Customer, on by default, with the strip that filters', function () {
    // Pins the FINISHED state. DEFECT: a new column added OFF by default
    // (nobody finds it), or at the end of the row, far from Customer where
    // the owner drew it.
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($src)->toMatch("/var OL_COLDEF = \\[\\s*(?:\\/\\*.*?\\*\\/\\s*)?\\['emirate', 'Emirate'\\],/s")
        ->and($src)->toMatch('/var OL_COLS_DEFAULT = \\{\\s*emirate: true,/')
        ->and(substr_count($src, "case 'emirate':"))->toBe(1)
        ->and(substr_count($src, 'function olEmirates(d)'))->toBe(1)
        ->and(substr_count($src, 'olEmirates(d) +'))->toBe(1)
        ->and($src)->toContain("if(OL.emirate) p.set('emirate', OL.emirate);")
        ->and($src)->toContain("\$\$\$('#content .chip[data-ole]')")
        // The old City column is untouched and still off by default.
        ->and($src)->toContain("['placed', 'Placed'], ['location', 'City'],")
        ->and($src)->toContain('payment: true, source: true, placed: true, location: false, contact: false, wc: false');
});

/* -------------------------------------------------------- the owner app */

it('shows the emirate code on the owner app\'s rows and order, with a breakdown that filters', function () {
    // DEFECT: the owner app with no idea where an order went, which is the
    // half of the request he made "for the owner app too".
    $d = emShip('Dubai', null, 'AE', ['status' => 'processing']);
    $d2 = emShip('Al Barsha', 'Dubai', 'AE', ['status' => 'completed']);
    $a = emShip(null, 'Al Ain', 'AE', ['status' => 'processing']);
    $x = emShip('Riyadh', null, 'SA', ['status' => 'processing']);
    [$c] = OA::enrol($this);

    $body = OA::get($this, 'orders', $c)->assertOk()->json();
    $rows = collect($body['orders'])->keyBy('id');
    $strip = collect($body['emirates']['rows'])->pluck('orders', 'code');

    expect($rows[$d->id]['emirate_code'])->toBe('DXB')
        ->and($rows[$a->id]['emirate_code'])->toBe('ALAIN')
        ->and($rows[$x->id]['emirate_code'])->toBeNull()
        ->and($strip['DXB'])->toBe(2)->and($strip['ALAIN'])->toBe(1)->and($body['emirates']['other'])->toBe(1)
        ->and($body['counts']['processing'])->toBe(3);

    // Filtered to Dubai: its rows, its status counts; the breakdown keeps all.
    $dxb = OA::get($this, 'orders?emirate=DXB', $c)->assertOk()->json();
    expect(collect($dxb['orders'])->pluck('id')->sort()->values()->all())->toBe([$d->id, $d2->id])
        ->and($dxb['emirate'])->toBe('Dubai')
        ->and($dxb['counts']['processing'])->toBe(1)->and($dxb['counts']['completed'])->toBe(1)
        ->and(collect($dxb['emirates']['rows'])->pluck('orders', 'code')['ALAIN'])->toBe(1);

    // The status chip narrows the breakdown.
    $done = OA::get($this, 'orders?status=completed', $c)->assertOk()->json();
    expect(collect($done['emirates']['rows'])->pluck('orders', 'code')->all())->toMatchArray(['DXB' => 1, 'ALAIN' => 0]);

    // Junk is ignored.
    expect(OA::get($this, 'orders?emirate=nowhere', $c)->assertOk()->json('orders'))->toHaveCount(4);

    // The order itself.
    $o = OA::get($this, 'orders/' . $a->id, $c)->assertOk()->json('order');
    expect($o['emirate'])->toBe('Al Ain')->and($o['emirate_code'])->toBe('ALAIN');
    expect(OA::get($this, 'orders/' . $x->id, $c)->assertOk()->json('order.emirate'))->toBeNull();
});

it('keeps the owner app orders list flat for 3 orders and for 40', function () {
    $count = function (int $n): int {
        Order::query()->withTrashed()->forceDelete();
        foreach (range(1, $n) as $i) {
            emShip(['Dubai', 'Sharjah', 'Abu Dhabi'][$i % 3], $i % 4 === 0 ? 'Al Ain' : null);
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

it('answers the owner app without the column too', function () {
    $a = emShip(null, 'Al Ain');
    [$c] = OA::enrol($this);

    Schema::table('orders', function ($t) {
        $t->dropIndex('orders_ship_emirate_index');
        $t->dropColumn('ship_emirate');
    });

    $body = OA::get($this, 'orders', $c)->assertOk()->json();
    expect($body['orders'])->toHaveCount(1)->and($body['emirates'] ?? null)->toBeNull();

    OrderEmirate::reset();
    expect(OA::get($this, 'orders/' . $a->id, $c)->assertOk()->json('order.emirate_code'))->toBe('ALAIN');
});

it('draws the owner app\'s emirate chips once, from the built bundle the shell loads', function () {
    $js = (string) file_get_contents(resource_path('js/owner-app/orders.js'));

    expect(substr_count($js, 'function paintEmirates(view)'))->toBe(1)
        ->and(substr_count($js, 'paintEmirates(view);'))->toBe(1)
        ->and($js)->toContain("['emirate', L.emirate]")
        ->and($js)->toContain("b.hasAttribute('data-em')");

    // The shell serves the BUILT file; a source change that was never built
    // ships nothing. MUTATION: skip `npx vite build` and this is red.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/' . $manifest['resources/js/owner-app/owner-app.js']['file']));
    expect($built)->toContain('data-emchips')->and($built)->toContain('data-em=');
});
