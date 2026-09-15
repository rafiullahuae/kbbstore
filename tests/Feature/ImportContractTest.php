<?php

/**
 * The contract the WooCommerce importer has to be able to rely on.
 *
 * The migration will not be a single run. It will be a full import, a delta, a
 * cutover delta on the night, and as many re-runs as it takes to get a mapping
 * right — every one of them re-presenting rows the pass before already
 * inserted. So "runs twice" is not an edge case here, it is the normal case,
 * and these tests pin the three properties that make it survivable:
 *
 *   1. every imported row can be matched back to its WordPress original, and
 *      the database REFUSES a second copy rather than trusting the importer to
 *      remember;
 *   2. an order that belongs to nobody still counts as money;
 *   3. an order placed in 2019 still says 2019 afterwards.
 *
 * Each one is a defect that only shows up after the data is in, when the
 * cheapest fix has already expired.
 */

use App\Models\Address;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* ------------------------------------------------------------------ schema */

it('gives every table the import writes to a column that identifies the WordPress row', function () {
    // Phase 0 supplied most of these. The last five are what
    // 2026_09_22_000000_add_import_external_ids added; they are listed
    // alongside the originals rather than on their own because the property
    // that matters is the set being complete, not the migration having run.
    $keys = [
        'categories' => 'source_term_id',
        'brands' => 'source_term_id',
        'tags' => 'source_term_id',
        'products' => 'wc_id',
        'product_variants' => 'wc_id',
        'coupons' => 'wc_id',
        'customers' => 'wp_user_id',
        'orders' => 'wc_order_id',
        'order_notes' => 'source_comment_id',
        'refunds' => 'wc_refund_id',
        'media' => 'source_attachment_id',
        // Added by this lane.
        'addresses' => 'source_key',
        'order_items' => 'wc_item_id',
        'users' => 'wp_user_id',
        'attribute_values' => 'source_term_id',
        'reviews' => 'source_id',
    ];

    foreach ($keys as $table => $column) {
        expect(Schema::hasColumn($table, $column))
            ->toBeTrue("{$table}.{$column} is missing — a re-run of the import would duplicate this table");
    }
});

it('refuses a second row carrying an external id it already holds', function () {
    // The point of the constraint is that it does not depend on the importer
    // being careful. Every insert here goes through the query builder rather
    // than a model, so nothing in PHP can be doing the work the database is
    // supposed to be doing.
    $now = now();

    $customer = Customer::create(['email' => 'dupe@example.test', 'wp_user_id' => 900]);

    // Both parents are real rows. order_items.order_id and addresses.customer_id
    // are NOT NULL foreign keys, and an insert that tripped one of those would
    // throw the same exception this test is looking for — passing while proving
    // nothing about the unique index.
    $order = Order::create([
        'wc_order_id' => 900, 'order_number' => 'D-900', 'customer_id' => $customer->id,
        'email' => 'dupe@example.test', 'status' => 'completed', 'total' => 1000,
    ]);

    $cases = [
        'addresses' => [
            'row' => ['customer_id' => $customer->id, 'type' => 'billing', 'source_key' => 'user:900:billing', 'created_at' => $now, 'updated_at' => $now],
        ],
        'order_items' => [
            'row' => ['order_id' => $order->id, 'name' => 'Line', 'wc_item_id' => 5501, 'created_at' => $now, 'updated_at' => $now],
        ],
        'users' => [
            'row' => ['name' => 'Staff', 'password' => 'x', 'wp_user_id' => 7, 'created_at' => $now, 'updated_at' => $now],
        ],
    ];

    // users.email is itself unique, so each attempt needs its own address;
    // otherwise the test would pass on the wrong constraint.
    $emails = ['first@example.test', 'second@example.test'];

    foreach ($cases as $table => $case) {
        $first = $case['row'];
        $second = $case['row'];

        if ($table === 'users') {
            $first['email'] = $emails[0];
            $second['email'] = $emails[1];
        }

        DB::table($table)->insert($first);

        expect(fn () => DB::table($table)->insert($second))
            ->toThrow(QueryException::class);
    }
});

it('treats a review id as unique only within its own source', function () {
    // This table deliberately holds two origins whose id spaces are unrelated:
    // 'sorina' (the live review plugin) and 'wp_comment'. A unique index on
    // source_id alone would have made the second import of either one fail on
    // rows that are not duplicates at all.
    Review::create(['source' => 'sorina', 'source_id' => 41, 'author_name' => 'A']);
    Review::create(['source' => 'wp_comment', 'source_id' => 41, 'author_name' => 'B']);

    expect(Review::query()->where('source_id', 41)->count())->toBe(2);

    expect(fn () => Review::create(['source' => 'sorina', 'source_id' => 41, 'author_name' => 'C']))
        ->toThrow(QueryException::class);
});

/* ------------------------------------------------------- re-running the import */

it('updates rather than duplicates when the same WooCommerce ids arrive twice', function () {
    /*
     * A miniature of the real importer: everything it touches is matched on
     * its external id and nothing on a name, a slug or an email. Running it
     * twice with changed values is the delta pass, and is the whole test.
     */
    $import = function (string $productName, int $quantity, string $city): void {
        $product = Product::updateOrCreate(
            ['wc_id' => 4021],
            ['slug' => 'serum-4021', 'name' => $productName, 'price' => 9900],
        );

        $customer = Customer::updateOrCreate(
            ['wp_user_id' => 412],
            ['email' => 'buyer@example.test', 'name' => 'Buyer'],
        );

        /*
         * The address is the row that had no key at all before this lane, and
         * so the row that duplicated on every pass.
         *
         * Written through the relation rather than as one updateOrCreate()
         * because Address deliberately guards `customer_id` — a model that
         * will mass-assign it is one careless $request->all() away from
         * letting a shopper file an address under someone else's account. Pass
         * it in the attributes array and Eloquent silently drops it, which on
         * a NOT NULL column surfaces as an integrity error rather than a wrong
         * owner. That is the right failure, and it is the importer's job to
         * associate() instead.
         */
        $address = Address::firstOrNew(['source_key' => 'user:412:billing']);
        $address->fill(['type' => 'billing', 'city' => $city]);
        $address->customer()->associate($customer);
        $address->save();

        $order = Order::updateOrCreate(
            ['wc_order_id' => 10233],
            [
                'order_number' => '10233',
                'customer_id' => $customer->id,
                'email' => 'buyer@example.test',
                'status' => 'completed',
                'total' => 9900 * $quantity,
            ],
        );

        // Line items are the quiet one: without wc_item_id a second pass gives
        // the order a second set of lines, so item revenue doubles while the
        // order total stays right — inconsistent rather than obviously broken.
        OrderItem::updateOrCreate(
            ['wc_item_id' => 5501],
            [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $productName,
                'quantity' => $quantity,
                'unit_price' => 9900,
                'total' => 9900 * $quantity,
            ],
        );

        $attribute = Attribute::firstOrCreate(['slug' => 'size'], ['name' => 'Size']);

        AttributeValue::updateOrCreate(
            ['source_term_id' => 771],
            ['attribute_id' => $attribute->id, 'slug' => '50ml', 'name' => '50ml'],
        );
    };

    $import('Ginseng Serum', 1, 'Dubai');
    $import('Ginseng Serum (renamed)', 3, 'Sharjah');

    // One of everything, not two.
    expect(Product::query()->where('wc_id', 4021)->count())->toBe(1)
        ->and(Customer::query()->where('wp_user_id', 412)->count())->toBe(1)
        ->and(Address::query()->where('source_key', 'user:412:billing')->count())->toBe(1)
        ->and(Order::query()->where('wc_order_id', 10233)->count())->toBe(1)
        ->and(OrderItem::query()->where('wc_item_id', 5501)->count())->toBe(1)
        ->and(AttributeValue::query()->where('source_term_id', 771)->count())->toBe(1);

    // And the second pass actually landed: an import that silently skipped
    // every existing row would pass every count above and still be broken.
    expect(Product::query()->where('wc_id', 4021)->value('name'))->toBe('Ginseng Serum (renamed)')
        ->and(Address::query()->where('source_key', 'user:412:billing')->value('city'))->toBe('Sharjah')
        ->and((int) OrderItem::query()->where('wc_item_id', 5501)->value('quantity'))->toBe(3);

    // Money stays an integer number of fils the whole way through — no float
    // ever constructed, so no 29699.999999999996.
    expect(OrderItem::query()->where('wc_item_id', 5501)->value('total'))->toBe(29700)
        ->and(Order::query()->where('wc_order_id', 10233)->value('total'))->toBe(29700);
});

/* ------------------------------------------------------------------- guests */

it('counts a guest order with no customer row toward revenue', function () {
    /*
     * WooCommerce guest orders arrive with no wp_user_id, so they import with
     * customer_id NULL and belong to nobody. Every per-customer figure is a
     * join through customers and therefore cannot see them. Store revenue must
     * not have the same hole: on a store where most checkouts are guest
     * checkouts, that is most of the money.
     */
    $customer = Customer::create(['email' => 'known@example.test']);

    Order::create([
        'wc_order_id' => 1, 'order_number' => 'A-1', 'customer_id' => $customer->id,
        'email' => 'known@example.test', 'status' => 'completed', 'total' => 15000,
    ]);

    Order::create([
        'wc_order_id' => 2, 'order_number' => 'A-2', 'customer_id' => null,
        'email' => 'guest@example.test', 'status' => 'completed', 'total' => 24000,
    ]);

    // A cancelled order is not revenue whoever it belongs to.
    Order::create([
        'wc_order_id' => 3, 'order_number' => 'A-3', 'customer_id' => null,
        'email' => 'guest@example.test', 'status' => 'cancelled', 'total' => 99900,
    ]);

    $revenue = (int) Order::query()->whereIn('status', Order::REAL_STATUSES)->sum('total');

    expect($revenue)->toBe(39000);

    // The per-customer view legitimately sees only half of it. That is not a
    // bug in the join, it is the definition of a customer total — but it means
    // the two numbers disagree by design, and anything reporting the second as
    // "revenue" is under-reporting by however many guest orders there are.
    $perCustomer = (int) Order::query()
        ->whereIn('status', Order::REAL_STATUSES)
        ->whereNotNull('customer_id')
        ->sum('total');

    expect($perCustomer)->toBe(15000);

    // So the count of orders belonging to nobody has to be visible somewhere,
    // which is what Store → Customers reports as `unlinked_orders`.
    $unlinked = Order::query()
        ->whereNull('customer_id')
        ->whereIn('status', Order::REAL_STATUSES)
        ->count();

    expect($unlinked)->toBe(1);
});

/* --------------------------------------------------------------- order dates */

it('keeps the date a historical order was actually placed', function () {
    /*
     * "Last order" is MAX(orders.created_at), not paid_at. An importer that
     * lets Eloquent stamp created_at with now() therefore does not just lose
     * the order date — it makes every customer in the store look like they
     * bought something today, which reverses the meaning of every re-engagement
     * segment on the Customers screen at once.
     */
    $customer = Customer::create(['email' => 'old@example.test']);

    Order::create([
        'wc_order_id' => 77, 'order_number' => 'H-77', 'customer_id' => $customer->id,
        'email' => 'old@example.test', 'status' => 'completed', 'total' => 5000,
        'created_at' => '2019-03-04 10:15:00',
        'updated_at' => '2019-03-04 10:15:00',
    ]);

    $stored = (string) Order::query()->where('wc_order_id', 77)->value('created_at');

    expect($stored)->toStartWith('2019-03-04');

    // Re-read through the model, because a cast or a touch on retrieval would
    // undo it just as thoroughly as never writing it.
    expect(Order::query()->where('wc_order_id', 77)->first()->created_at->format('Y-m-d'))
        ->toBe('2019-03-04');

    // And the aggregate the Customers screen actually runs reports 2019, not
    // today — the assertion that would have caught the defect from the outside.
    $lastOrderAt = (string) Order::query()
        ->where('customer_id', $customer->id)
        ->whereIn('status', Order::REAL_STATUSES)
        ->max('created_at');

    expect($lastOrderAt)->toStartWith('2019-03-04');
});

/* -------------------------------------------------------------- the migration */

it('covers every external id with a unique constraint, whatever the index is called', function () {
    /*
     * Asserted by column set rather than by index name on purpose. Phase 0
     * created orders.wc_order_id unique under Laravel's generated name, while
     * the repair migrations re-add that same column on the live server as a
     * bare nullable with no constraint at all. Only one of those two databases
     * is in front of the test suite. What has to be true of both is that the
     * column cannot hold the same WordPress id twice.
     */
    $required = [
        'addresses' => ['source_key'],
        'order_items' => ['wc_item_id'],
        'users' => ['wp_user_id'],
        'attribute_values' => ['source_term_id'],
        'reviews' => ['source', 'source_id'],
        'orders' => ['wc_order_id'],
        'customers' => ['wp_user_id'],
        'products' => ['wc_id'],
        'product_variants' => ['wc_id'],
        'categories' => ['source_term_id'],
        'brands' => ['source_term_id'],
    ];

    foreach ($required as $table => $columns) {
        $wanted = $columns;
        sort($wanted);

        $covered = array_filter(
            Schema::getIndexes($table),
            static function (array $index) use ($wanted): bool {
                if (($index['unique'] ?? false) !== true) {
                    return false;
                }

                $have = array_map('strval', (array) ($index['columns'] ?? []));
                sort($have);

                return $have === $wanted;
            },
        );

        expect($covered)->not->toBeEmpty(
            $table.' ('.implode(', ', $columns).') has no unique constraint — a re-run of the import would duplicate it',
        );
    }
});

it('leaves an already-migrated database alone when the external-id migration runs again', function () {
    $tables = ['addresses', 'order_items', 'users', 'attribute_values', 'reviews', 'orders', 'customers', 'products', 'product_variants', 'categories', 'brands'];

    $before = [];

    foreach ($tables as $table) {
        $columns = Schema::getColumnListing($table);
        sort($columns);

        $names = array_map(static fn (array $i): string => strtolower((string) ($i['name'] ?? '')), Schema::getIndexes($table));
        sort($names);

        $before[$table] = ['columns' => $columns, 'indexes' => $names];
    }

    // The suite has already migrated, so this IS the "runs twice" case — the
    // one the live server will actually be in.
    $migration = require database_path('migrations/2026_09_22_000000_add_import_external_ids.php');
    $migration->up();

    foreach ($tables as $table) {
        $columns = Schema::getColumnListing($table);
        sort($columns);

        $names = array_map(static fn (array $i): string => strtolower((string) ($i['name'] ?? '')), Schema::getIndexes($table));
        sort($names);

        // Not one extra column and not one extra index. A second unique index
        // stacked on a column that already had one is the failure a name-only
        // guard would have produced, and it would never have shown up as an
        // error — just as a slower write on every row of the import.
        expect($columns)->toBe($before[$table]['columns'], "{$table} gained or lost a column on a second run")
            ->and($names)->toBe($before[$table]['indexes'], "{$table} gained or lost an index on a second run");
    }
});
