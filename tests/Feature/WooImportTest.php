<?php

/*
 * The WooCommerce importer.
 *
 * Every test here is a defect that only shows up after the data is in, when the
 * cheapest fix has already expired. The fixture in tests/Fixtures/woo is
 * deliberately nasty — guests, missing fields, historical dates, two emails
 * differing only in case, malformed money, an order whose customer does not
 * exist, statuses this schema has never heard of — because a fixture of clean
 * rows proves only that the happy path works, and the happy path was never the
 * risk.
 *
 * THESE RUN ON BOTH ENGINES and several of them exist because of the gap
 * between them. SQLite's default collation is case-sensitive and MySQL's is
 * not, so `A@x.com` and `a@x.com` COLLIDE on customers.email in production and
 * insert happily under the suite. The assertions below are written against the
 * importer's own behaviour rather than against the index, so they mean the same
 * thing on both: run with `vendor/bin/pest -c phpunit-mysql.xml`.
 */

use App\Models\Address;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Import\DateParser;
use App\Services\Import\Emails;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\Import\Money;
use App\Services\Import\RowRejected;
use Illuminate\Support\Facades\DB;

/** The nasty fixture. */
function wooFixtureDir(): string
{
    return base_path('tests/Fixtures/woo');
}

/**
 * A copy of the fixture in a temp directory, so a test can edit one file
 * (to simulate a re-export) without touching the checked-in fixture.
 */
function wooFixtureCopy(): string
{
    $dir = sys_get_temp_dir().'/kbb-woo-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    foreach (glob(wooFixtureDir().'/*.csv') ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    return $dir;
}

function wooImport(array $overrides = []): ImportReport
{
    $options = new ImportOptions(...array_merge([
        'directory' => wooFixtureDir(),
        // The demo catalogue that 2026_08_27_100000_seed_demo_catalogue puts on
        // EVERY install holds the slugs `cosrx` and `beauty-of-joseon`, which
        // are real brands this store really sells. Without this the genuine
        // terms are refused; with it they claim the placeholder rows. One of
        // the tests below pins both halves of that.
        'adoptBySlug' => true,
    ], $overrides));

    return (new ImportRunner)->run($options);
}

/** A stable picture of everything the import writes, for comparing two runs. */
function wooSnapshot(): array
{
    return [
        'products' => Product::query()->orderBy('id')
            ->get(['wc_id', 'slug', 'name', 'price', 'sale_price', 'status', 'stock_status', 'brand_id', 'category_id'])
            ->map->toArray()->all(),
        'orders' => Order::query()->orderBy('id')
            ->get(['wc_order_id', 'order_number', 'email', 'status', 'total', 'subtotal', 'customer_id', 'created_at', 'paid_at'])
            ->map->toArray()->all(),
        'items' => OrderItem::query()->orderBy('id')
            ->get(['wc_item_id', 'order_id', 'product_id', 'name', 'quantity', 'unit_price', 'subtotal', 'total'])
            ->map->toArray()->all(),
        'customers' => Customer::query()->orderBy('id')
            ->get(['wp_user_id', 'email', 'name', 'created_at'])->map->toArray()->all(),
        'addresses' => Address::query()->orderBy('id')
            ->get(['source_key', 'customer_id', 'type', 'city', 'country', 'is_default'])->map->toArray()->all(),
        'categories' => Category::query()->orderBy('id')
            ->get(['source_term_id', 'slug', 'parent_id', 'depth', 'path'])->map->toArray()->all(),
        'pivot' => DB::table('category_product')->orderBy('category_id')->orderBy('product_id')->get()
            ->map(fn ($r): array => (array) $r)->all(),
    ];
}

/* --------------------------------------------------------------- money */

it('turns a decimal string into exact fils without ever constructing a float', function () {
    // The one that matters most: the previous dead importer in this repository
    // would have written AED 99.50 as 99 fils, a factor of a hundred out, on
    // every product, silently.
    expect(Money::fils('99.50', 'price'))->toBe(9950)
        ->and(Money::fils('99.5', 'price'))->toBe(9950)
        ->and(Money::fils('99', 'price'))->toBe(9900)
        ->and(Money::fils('0.29', 'price'))->toBe(29)      // 0.29 * 100 is 28.999999999999996 in binary floating point
        ->and(Money::fils('19.99', 'price'))->toBe(1999)   // and 19.99 * 100 is 1998.9999999999998
        ->and(Money::fils('0.00', 'price'))->toBe(0)
        ->and(Money::fils('-12.34', 'price'))->toBe(-1234)
        ->and(Money::fils('1,234.50', 'price'))->toBe(123450)
        ->and(Money::fils('AED 99.50', 'price'))->toBe(9950)
        ->and(Money::fils('99.5000', 'price'))->toBe(9950) // trailing zeros carry no information
        ->and(Money::fils('', 'price'))->toBeNull()        // empty is not zero
        ->and(Money::fils(null, 'price'))->toBeNull();
});

it('refuses money it cannot represent rather than rounding it', function () {
    $cases = [
        '12.345' => 'precision',     // a third decimal is not fils
        '99,50' => 'ambiguous',      // decimal comma or thousands comma?
        '1,2345.6' => 'ambiguous',   // grouping that is not three digits
        'abc' => 'not a decimal',
        '12.50.30' => 'not a decimal',
        '99999999.99' => 'range',    // past the 32-bit column
    ];

    foreach ($cases as $value => $because) {
        expect(fn () => Money::fils($value, 'total'))
            ->toThrow(RowRejected::class, '', "'{$value}' should have been refused ({$because})");
    }

    // And a float must never reach it at all — that is the bug, not the input.
    expect(fn () => Money::fils(99.5, 'total'))->toThrow(RowRejected::class);
});

/* --------------------------------------------------------------- dates */

it('reads a naked timestamp in the store timezone and stores it as UTC', function () {
    // Woo writes post_date in the SITE's timezone. Reading it as UTC shifts
    // every order in the store by four hours, which silently moves orders
    // placed after 20:00 onto the next day.
    $parsed = DateParser::utc('2019-03-04 11:22:33', 'date_created', 'Asia/Dubai');

    expect($parsed?->toDateTimeString())->toBe('2019-03-04 07:22:33');

    // An explicit offset is honoured rather than re-interpreted.
    expect(DateParser::utc('2019-03-04T11:22:33+04:00', 'd', 'Asia/Dubai')?->toDateTimeString())
        ->toBe('2019-03-04 07:22:33')
        ->and(DateParser::utc('2019-03-04T07:22:33Z', 'd', 'Asia/Dubai')?->toDateTimeString())
        ->toBe('2019-03-04 07:22:33');

    // A date-only value is midnight, not "whenever the import happened to run" —
    // otherwise the same source row would import differently every time.
    expect(DateParser::utc('2019-03-04', 'd', 'UTC')?->toDateTimeString())->toBe('2019-03-04 00:00:00');
});

it('reads WooCommerce\'s several spellings of "no date" as null, not as 1970', function () {
    // This store has already shipped a "last active: 1 Jan 1970" bug once.
    foreach (['', '0000-00-00 00:00:00', '0000-00-00', '0'] as $empty) {
        expect(DateParser::utc($empty, 'd', 'Asia/Dubai'))->toBeNull();
    }
});

it('refuses a date it only partly understands instead of guessing at it', function () {
    foreach (['not-a-date', '2019-03-04 garbage', '04/03/2019', '2019-13-45'] as $bad) {
        expect(fn () => DateParser::utc($bad, 'date_created', 'Asia/Dubai'))
            ->toThrow(RowRejected::class, '', "'{$bad}' should have been refused");
    }
});

/* ------------------------------------------------------- the whole import */

it('imports the catalogue, the customers and the orders from the fixture', function () {
    wooImport();

    // 99.50 -> 9950, not 99. The one that would otherwise be wrong on every row.
    $serum = Product::query()->where('wc_id', 4021)->firstOrFail();

    expect($serum->price)->toBe(9950)
        ->and($serum->sale_price)->toBe(8900)
        ->and($serum->status)->toBe('publish')
        ->and($serum->slug)->toBe('serum-4021');

    expect(Product::query()->where('wc_id', 4022)->value('price'))->toBe(123450);

    // Nesting: the URL path is cached, not computed, so four-level nesting
    // simply does not render without it — and the child arrives in the file
    // before its parent does.
    $deep = Category::query()->where('source_term_id', 31)->firstOrFail();

    expect($deep->path)->toBe('skincare/face-cleansers/makeup-removers')
        ->and($deep->depth)->toBe(2);

    // A parent that is not in the export lands at the root rather than nowhere.
    $orphan = Category::query()->where('source_term_id', 40)->firstOrFail();

    expect($orphan->parent_id)->toBeNull()->and($orphan->path)->toBe('orphaned-branch');

    $order = Order::query()->where('wc_order_id', 10233)->firstOrFail();

    expect($order->total)->toBe(29850)
        ->and($order->status)->toBe('completed')          // the Woo `wc-` prefix is stripped
        ->and($order->order_number)->toBe('KBB-1001')
        ->and($order->customer_id)->toBe(Customer::query()->where('wp_user_id', 412)->value('id'));

    $item = OrderItem::query()->where('wc_item_id', 5501)->firstOrFail();

    expect($item->quantity)->toBe(3)
        ->and($item->unit_price)->toBe(9950)
        ->and($item->total)->toBe(29850);
});

it('keeps the historical order date, because it is what "last order" reads', function () {
    wooImport();

    // 2019-03-04 11:22:33 in Asia/Dubai. Store -> Customers derives "last
    // order" from MAX(created_at), so an import that let Eloquent stamp this
    // would make every customer in the store look like they bought today.
    $order = Order::query()->where('wc_order_id', 10233)->firstOrFail();

    expect($order->created_at->toDateTimeString())->toBe('2019-03-04 07:22:33')
        ->and($order->paid_at->toDateTimeString())->toBe('2019-03-04 07:25:00')
        ->and($order->updated_at->toDateTimeString())->toBe('2019-03-06 05:00:00')
        ->and($order->completed_at->toDateTimeString())->toBe('2019-03-08 10:00:00');

    // And it survives the aggregate the Customers screen actually runs.
    $last = DB::table('orders')
        ->where('customer_id', $order->customer_id)
        ->whereIn('status', Order::REAL_STATUSES)
        ->max('created_at');

    expect((string) $last)->toStartWith('2022-02-02');

    // Customers too: "registered" is a column the Customers screen sorts on.
    // (string) because `value()` hands back a Carbon on one driver and a plain
    // string on another; the assertion is about the instant, not the type.
    expect((string) Customer::query()->where('wp_user_id', 412)->firstOrFail()->created_at->toDateTimeString())
        ->toBe('2019-01-05 04:00:00');
});

it('gives an order with no email a reserved address that can never be delivered to', function () {
    wooImport();

    // orders.email is NOT NULL, so something has to go there. RFC 2606 reserves
    // .invalid precisely so that a stray receipt re-send or newsletter cannot
    // reach a real person who happens to own the address someone made up.
    $order = Order::query()->where('wc_order_id', 10235)->firstOrFail();

    expect($order->email)->toBe('wc-order-10235@import.invalid')
        ->and(Emails::isPlaceholder($order->email))->toBeTrue()
        // And it is NOT attached to a customer: clustering every emailless
        // order onto one synthetic row would invent a shopper who bought them all.
        ->and($order->customer_id)->toBeNull()
        ->and(Customer::query()->where('email', 'like', '%@import.invalid')->count())->toBe(0);
});

it('synthesises a customer for a guest order, dated when they actually bought', function () {
    wooImport();

    // Decision D3. A guest order with customer_id NULL sits outside every
    // per-customer figure, because each of those is a join through `customers`.
    $guest = Customer::query()->where('email', 'guest@example.test')->firstOrFail();

    expect($guest->wp_user_id)->toBeNull()
        ->and($guest->notes)->toContain('10234')
        ->and($guest->created_at->toDateTimeString())->toBe('2021-07-14 15:45:00')
        ->and(Order::query()->where('wc_order_id', 10234)->value('customer_id'))->toBe($guest->id);
});

it('leaves a guest order unlinked when asked to', function () {
    wooImport(['synthesiseGuests' => false]);

    expect(Customer::query()->where('email', 'guest@example.test')->exists())->toBeFalse()
        ->and(Order::query()->where('wc_order_id', 10234)->value('customer_id'))->toBeNull();

    // The documented consequence: the order is still real money in the
    // store-wide total, and Store -> Customers still reports it as unlinked.
    expect(Order::query()->whereNull('customer_id')->whereIn('status', Order::REAL_STATUSES)->count())
        ->toBeGreaterThan(0);
});

it('never writes the three customer columns nothing maintains', function () {
    wooImport();

    // Customer::UNMAINTAINED_COLUMNS. Filling them would create a second source
    // of truth for a figure the Customers screen already derives correctly from
    // `orders` — one that agrees most of the time, which is worse than none
    // because it gets believed.
    foreach (Customer::query()->get() as $customer) {
        expect((int) $customer->orders_count)->toBe(0)
            ->and((int) $customer->total_spent)->toBe(0)
            ->and($customer->last_order_at)->toBeNull();
    }
});

it('keeps the WordPress password hash where sign-in can use it', function () {
    wooImport();

    $customer = Customer::query()->where('wp_user_id', 412)->firstOrFail();

    // legacy_password, and `password` left NULL. The reverse would be worse
    // than useless: `password` is cast `hashed`, so assigning a hash to it
    // hashes the hash and nobody can ever sign in.
    expect($customer->legacy_password)->toStartWith('$P$B')
        ->and($customer->password)->toBeNull();
});

it('keeps a WooCommerce status this application has never defined', function () {
    wooImport();

    // The column is free-form on purpose: production carries `shipped` and
    // `tamara-p-failed`. Coercing them would be inventing history.
    expect(Order::query()->where('wc_order_id', 10237)->value('status'))->toBe('tamara-p-failed')
        // ...and it is correctly NOT revenue.
        ->and(Order::query()->where('wc_order_id', 10237)->whereIn('status', Order::REAL_STATUSES)->exists())
        ->toBeFalse();
});

it('composes the documented address key and keeps the country to two letters', function () {
    wooImport();

    expect(Address::query()->where('source_key', 'user:412:billing')->value('city'))->toBe('Dubai')
        ->and(Address::query()->where('source_key', 'order:10233:shipping')->value('country'))->toBe('AE');

    // A three-letter code would be SILENTLY truncated to 'AR' on a Phase 0
    // server, where addresses.country is varchar(2) — so it is refused, and the
    // customer that carried it is imported anyway.
    expect(Address::query()->where('source_key', 'user:416:billing')->exists())->toBeFalse()
        ->and(Customer::query()->where('wp_user_id', 416)->exists())->toBeTrue();

    // One default per type, so the account area has something to show.
    expect(Address::query()->where('customer_id', Customer::query()->where('wp_user_id', 412)->value('id'))
        ->where('type', 'billing')->where('is_default', true)->count())->toBe(1);
});

/* ------------------------------------------------------------ idempotency */

it('produces exactly the same database when it is run twice', function () {
    // "Runs twice" is not an edge case for this import, it is the normal case:
    // a full import, a delta, a cutover delta on the night, and as many re-runs
    // as it takes to get a mapping right.
    wooImport();

    $first = wooSnapshot();
    $counts = [
        Product::count(), Order::count(), OrderItem::count(),
        Customer::count(), Address::count(), Category::count(), Brand::count(),
        DB::table('category_product')->count(),
    ];

    $second = wooImport();

    expect(wooSnapshot())->toEqual($first)
        ->and([
            Product::count(), Order::count(), OrderItem::count(),
            Customer::count(), Address::count(), Category::count(), Brand::count(),
            DB::table('category_product')->count(),
        ])->toBe($counts);

    // And the second pass says so: every row it re-presented was already
    // identical. A pass that rewrote rows would report them as updated, which
    // is the evidence the importer cannot fake — "unchanged" comes from
    // Eloquent's dirty comparison against the row as the database has it.
    foreach (['categories', 'brands', 'products', 'orders', 'order-items'] as $entity) {
        $report = $second->for($entity);

        expect($report->created)->toBe(0, $entity.' created rows on the second pass')
            ->and($report->updated)->toBe(0, $entity.' rewrote rows on the second pass')
            ->and($report->unchanged)->toBeGreaterThan(0, $entity.' re-presented nothing at all');
    }
});

it('does not rewrite an order whose JSON address MySQL stores in a different key order', function () {
    /*
     * A MySQL `json` column is not text. The server parses it and stores a
     * binary form with object keys REORDERED — by key length, then
     * alphabetically — so the billing snapshot written as
     * {first_name, last_name, company, line1, ...} reads back as
     * {city, line1, phone, state, ..., first_name}. SQLite stores the bytes it
     * was given.
     *
     * Laravel then compares the two decoded arrays with `===`, which for arrays
     * is order-sensitive, so the attribute is dirty on EVERY pass forever.
     * Nothing is corrupted — the value written is identical — but every delta
     * rewrites every order that has an address, and the report says "updated"
     * when nothing changed. That report is the evidence the import was
     * idempotent, so an idempotency proof that can never say "unchanged" is not
     * a proof of anything.
     *
     * This test was green on SQLite and failed on MySQL the first time the
     * parity job ran, which is exactly what that job is for.
     */
    wooImport();

    $before = Order::query()->where('wc_order_id', 10233)->firstOrFail();

    $second = wooImport();

    $after = Order::query()->where('wc_order_id', 10233)->firstOrFail();

    expect($second->for('orders')->updated)->toBe(0)
        ->and($after->billing_address)->toEqual($before->billing_address)
        // The row really was left alone, not merely reported as such.
        ->and($after->updated_at->toDateTimeString())->toBe($before->updated_at->toDateTimeString());
});

it('updates in place rather than duplicating when a value has changed', function () {
    wooImport();

    $dir = wooFixtureCopy();
    $products = (string) file_get_contents($dir.'/products.csv');
    file_put_contents($dir.'/products.csv', str_replace('Ginseng Serum,serum-4021', 'Ginseng Serum Renamed,serum-4021', $products));

    wooImport(['directory' => $dir]);

    // One row, with the new value. Not two rows, and not the old value.
    expect(Product::query()->where('wc_id', 4021)->count())->toBe(1)
        ->and(Product::query()->where('wc_id', 4021)->value('name'))->toBe('Ginseng Serum Renamed');
});

/* ---------------------------------------------------------------- dry run */

it('writes absolutely nothing in a dry run, and reports what it would have done', function () {
    $before = [Product::count(), Order::count(), Customer::count(), Address::count(), OrderItem::count()];

    $report = wooImport(['dryRun' => true]);

    expect([Product::count(), Order::count(), Customer::count(), Address::count(), OrderItem::count()])
        ->toBe($before)
        // Not even the progress it would have recorded.
        ->and(DB::table('import_checkpoints')->count())->toBe(0);

    // But it is a real answer, not an empty one: the preview resolves every
    // foreign key by writing and rolling back, so it can tell the truth about
    // an order whose customer is created three rows earlier in the same run.
    expect($report->for('orders')->created)->toBeGreaterThan(0)
        ->and($report->for('customers')->created)->toBeGreaterThan(0)
        ->and($report->for('order-items')->created)->toBeGreaterThan(0)
        ->and($report->isDryRun())->toBeTrue();
});

it('predicts in a dry run exactly what the real run then does', function () {
    $predicted = wooImport(['dryRun' => true]);
    $actual = wooImport();

    foreach (ImportRunner::entityNames() as $entity) {
        expect($actual->for($entity)->created)->toBe($predicted->for($entity)->created, $entity.' created')
            ->and($actual->for($entity)->rejectedCount())
            ->toBe($predicted->for($entity)->rejectedCount(), $entity.' rejected');
    }
});

/* ------------------------------------------------------------ resumability */

it('resumes from the last committed batch instead of starting over', function () {
    // The shared host will kill this run. --limit stops it at a row boundary,
    // which is the same thing from the checkpoint's point of view.
    $partial = wooImport(['only' => ['orders'], 'limit' => 3, 'batchSize' => 1]);

    expect($partial->for('orders')->touched() + $partial->for('orders')->rejectedCount())->toBe(3)
        ->and(Order::count())->toBe(3);

    $checkpoint = DB::table('import_checkpoints')->where('entity', 'orders')->first();

    expect((int) $checkpoint->processed)->toBe(3)
        // Not finished, so the next run continues rather than restarting.
        ->and($checkpoint->finished_at)->toBeNull();

    $resumed = wooImport(['only' => ['orders']]);

    // The first three rows were not read again...
    expect($resumed->for('orders')->skipped)->toBe(3)
        // ...and the rest were.
        ->and($resumed->for('orders')->created)->toBe(3);

    // Six orders, not nine, and not three.
    expect(Order::count())->toBe(6)
        ->and(Order::query()->distinct()->count('wc_order_id'))->toBe(6);
});

it('refuses to resume into a source that has changed underneath it', function () {
    // Rows are resumed by POSITION, so an offset only means anything against
    // the exact file it was recorded for. A re-export with new rows at the top
    // would make the importer skip rows that are no longer the ones it did.
    $dir = wooFixtureCopy();

    wooImport(['directory' => $dir, 'only' => ['orders'], 'limit' => 2, 'batchSize' => 1]);

    file_put_contents($dir.'/orders.csv', "\n", FILE_APPEND);

    expect(fn () => wooImport(['directory' => $dir, 'only' => ['orders']]))
        ->toThrow(RuntimeException::class, 'has changed since this run last stopped');

    // And --restart is the documented way out: re-importing rows that are
    // already in reports them unchanged, so starting over is cheap.
    $restarted = wooImport(['directory' => $dir, 'only' => ['orders'], 'restart' => true]);

    expect($restarted->for('orders')->skipped)->toBe(0)
        ->and(Order::count())->toBe(6);
});

it('rolls a half-written row back rather than leaving part of it behind', function () {
    // Order 10238 has no date_created and is refused. It is refused AFTER the
    // importer has already looked up its customer, so the savepoint around each
    // row is what stops a partial write surviving in a batch that then commits.
    wooImport();

    expect(Order::query()->where('wc_order_id', 10238)->exists())->toBeFalse()
        ->and(Address::query()->where('source_key', 'like', 'order:10238:%')->exists())->toBeFalse();
});

/* --------------------------------------------------------------- rejection */

it('names every refused row with a reason, and refuses nothing silently', function () {
    $report = wooImport();

    expect($report->totalRejected())->toBeGreaterThan(0);

    foreach ($report->entities() as $entity) {
        foreach ($entity->rejections() as $rejection) {
            // A reason, not a code. "malformed money" is not actionable;
            // naming the field and quoting the value is.
            expect($rejection['reason'])->not->toBe('')
                ->and(strlen($rejection['reason']))->toBeGreaterThan(20)
                ->and($rejection['line'])->not->toBeNull();
        }
    }

    $reasons = implode(' | ', array_column($report->allRejections(), 'reason'));

    expect($reasons)
        ->toContain('more precision than fils')          // 12.345
        ->toContain('ambiguous')                          // 99,50
        ->toContain('WordPress trash')                    // a trashed product
        ->toContain('date_created is empty')              // an order with no date
        ->toContain('not an ISO 3166-1 alpha-2 code')     // ARE
        ->toContain('is required');                       // a row with no external id
});

it('refuses two WordPress users who share an email, on MySQL and on SQLite alike', function () {
    // THE sharpest engine divergence in this import. MySQL's default
    // utf8mb4_..._ci collation is case-insensitive, so `Buyer@Example.TEST` and
    // `buyer@example.test` collide on the unique index there and the insert is
    // refused; SQLite's BINARY collation is case-sensitive and both insert
    // happily. The importer lowercases on the way in and does the check in its
    // own bookkeeping, so this assertion means the same thing on both engines.
    $report = wooImport();

    expect(Customer::query()->where('wp_user_id', 412)->value('email'))->toBe('buyer@example.test')
        ->and(Customer::query()->where('wp_user_id', 413)->exists())->toBeFalse()
        ->and(Customer::query()->where('email', 'buyer@example.test')->count())->toBe(1);

    $reasons = implode(' | ', array_column($report->allRejections(), 'reason'));

    // And the refusal explains itself, including that merging is the owner's
    // call and not the importer's. An importer that merges two people's
    // accounts on its own initiative is not a thing anyone should build.
    expect($reasons)->toContain('already belongs to WordPress user 412')
        ->and($reasons)->toContain('D2');
});

it('links an order to a real customer by email when the named WordPress user is missing', function () {
    wooImport();

    // Order 10236 names customer_id 999, who is not in the customers export —
    // a shopper deleted in WordPress. Refusing it would remove real money from
    // the store's revenue, so it is linked by its billing address instead.
    $expected = Customer::query()->where('wp_user_id', 412)->value('id');

    expect(Order::query()->where('wc_order_id', 10236)->value('customer_id'))->toBe($expected);
});

it('imports a line whose product is gone, and refuses one whose order is', function () {
    wooImport();

    // product_id is a nullable FK with nullOnDelete precisely because a
    // five-year-old order references products that no longer exist. The line is
    // still real money.
    $orphanLine = OrderItem::query()->where('wc_item_id', 5502)->firstOrFail();

    expect($orphanLine->product_id)->toBeNull()->and($orphanLine->name)->toBe('Discontinued Sheet Mask');

    // order_id, by contrast, is NOT NULL — there is nothing to attach it to.
    expect(OrderItem::query()->where('wc_item_id', 5503)->exists())->toBeFalse();
});

/* -------------------------------------------------------------- slug clash */

it('refuses to claim a demo-catalogue row by slug unless it is told to', function () {
    // 2026_08_27_100000_seed_demo_catalogue seeds `cosrx` and
    // `beauty-of-joseon` on every install, production included, and they are
    // real brands this store really sells. brands.slug is unique, so the
    // genuine terms collide with the placeholders.
    $refused = wooImport(['only' => ['brands'], 'adoptBySlug' => false]);

    expect($refused->for('brands')->created)->toBe(0)
        ->and($refused->for('brands')->rejectedCount())->toBe(3)
        ->and(Brand::query()->whereNotNull('source_term_id')->count())->toBe(0);

    expect(implode(' ', array_column($refused->allRejections(), 'reason')))
        ->toContain('--adopt-by-slug');

    // With the flag, the placeholder row is claimed — matched from then on by
    // the external id like everything else, not by its slug.
    $adopted = wooImport(['only' => ['brands'], 'adoptBySlug' => true, 'restart' => true]);

    expect(Brand::query()->where('source_term_id', 501)->value('slug'))->toBe('cosrx')
        ->and(Brand::query()->where('slug', 'cosrx')->count())->toBe(1)
        ->and(implode(' ', array_keys($adopted->for('brands')->notes())))->toContain('adopted');
});

/* ------------------------------------------------------------------- CSV */

it('reads a header with a BOM, mixed case and spaces', function () {
    // Excel and the WooCommerce exporter both write a BOM. Left in place it
    // becomes part of the first header NAME, so `id` arrives as "\u{feff}id",
    // every lookup misses, and every row is refused for a missing id that is
    // plainly there in the file.
    $dir = sys_get_temp_dir().'/kbb-woo-bom-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    file_put_contents(
        $dir.'/brands.csv',
        "\u{FEFF}Term ID,Name,Slug\n701,Torriden Import,torriden-import\n",
    );

    wooImport(['directory' => $dir, 'only' => ['brands']]);

    expect(Brand::query()->where('source_term_id', 701)->value('name'))->toBe('Torriden Import');
});

it('refuses a misaligned row rather than shifting its cells into the wrong columns', function () {
    // A short row is usually an unescaped quote earlier in the file that has
    // swallowed a line break. Padding it quietly is how a phone number ends up
    // in the country column.
    $dir = sys_get_temp_dir().'/kbb-woo-short-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    file_put_contents(
        $dir.'/brands.csv',
        "term_id,name,slug\n702,Good Brand,good-brand\n703,Short Row\n",
    );

    $report = wooImport(['directory' => $dir, 'only' => ['brands']]);

    expect(Brand::query()->where('source_term_id', 702)->exists())->toBeTrue()
        ->and(Brand::query()->where('source_term_id', 703)->exists())->toBeFalse()
        ->and(implode(' ', array_column($report->allRejections(), 'reason')))->toContain('misaligned');
});
