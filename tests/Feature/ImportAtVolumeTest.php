<?php

/*
 * THE IMPORT AT THE SHOP'S OWN SHAPE — run twice, and killed in the middle.
 *
 * WHAT THE OTHER IMPORT TESTS CANNOT SHOW. tests/Fixtures/woo is nine rows of
 * deliberately nasty data, and it proves every mapping and every refusal. It
 * cannot prove anything that is a property of VOLUME: that the second pass
 * really rewrites nothing, that a run stopped in the middle of an entity
 * resumes onto exactly the rows it had not done, that no line item is left
 * pointing at an order that is not there, that the money that went in is the
 * money that came out. Each of those needs more rows than one batch, an entity
 * that stops part-way, and totals big enough that an error of one row shows.
 *
 * So this file drives tools/woo-volume-fixture/generate.php — the SAME
 * generator the full-volume rehearsal uses, at the same proportions and with
 * the same deliberate defects, with smaller numbers. The rehearsal itself is
 * 671 products, 4,159 orders and 3,712 customers and is recorded in
 * docs/FV-IMPORT-AT-VOLUME.md; what is here is the part that can afford to run
 * on every commit.
 *
 * IDEMPOTENCY IS ASSERTED AS ROW COUNTS AND CHECKSUMS, not as "no errors". An
 * import that ran twice without complaining and doubled every order would pass
 * a test that only looked for exceptions.
 */

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Support\Facades\DB;

/** The export, generated once per process. */
function volDir(): string
{
    static $dir = null;

    if ($dir !== null) {
        return $dir;
    }

    $dir = sys_get_temp_dir().'/kbb-vol-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    require_once base_path('tools/woo-volume-fixture/generate.php');

    (new VolumeFixture($dir, 4159, [
        'products' => 45,
        'orders' => 140,
        'customers' => 80,
        'reviews' => 70,
        'coupons' => 9,
        'brands' => 14,
        'categories' => 14,
    ]))->write();

    return $dir;
}

/** @return array<string, int> */
function volManifest(): array
{
    /*
     * `counts` — the generator now writes manifest.json in the shape
     * docs/WP-EXPORT-CONTRACT.md defines, because the importer reads that
     * name and refuses a format it does not speak. The flat map this has
     * always asserted against is under `counts`, which is where the
     * contract puts it, `unread.*` keys and all. Lane GF.
     */
    return json_decode((string) file_get_contents(volDir().'/manifest.json'), true)['counts'];
}

function volImport(array $overrides = []): ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => volDir(),
        'adoptBySlug' => true,
        // Smaller than the file, so every entity commits several batches and a
        // stopped run stops INSIDE one rather than tidily between two.
        'batchSize' => 17,
    ], $overrides)));
}

/**
 * Everything the import wrote, as counts and as a digest of the values.
 *
 * The counts answer "did a second pass add rows". The digests answer the
 * harder question — "did a second pass CHANGE any" — which a count cannot see
 * and which is the way an import that is not idempotent usually fails: the
 * same number of rows, rewritten every time.
 *
 * @return array<string, int|string>
 */
function volSnapshot(): array
{
    $snapshot = [
        'products' => Product::query()->whereNotNull('wc_id')->count(),
        'orders' => Order::query()->count(),
        'order_items' => OrderItem::query()->count(),
        'customers' => Customer::query()->count(),
        'customers_from_file' => Customer::query()->whereNotNull('wp_user_id')->count(),
        'addresses' => DB::table('addresses')->count(),
        'reviews' => DB::table('reviews')->count(),
        'coupons' => DB::table('coupons')->count(),
        'categories' => DB::table('categories')->count(),
        'category_product' => DB::table('category_product')->count(),
        'orders_total_fils' => (int) Order::query()->sum('total'),
        'items_total_fils' => (int) OrderItem::query()->sum('total'),
    ];

    /*
     * The line-to-order and line-to-product links, expressed in the ids the
     * EXPORT used rather than the ids this database happened to allocate. This
     * is the assertion that a resumed import filed every line under the right
     * order — a digest of local foreign keys cannot make it, because they move.
     */
    $snapshot['digest.item_links'] = md5((string) json_encode(
        DB::table('order_items')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
            ->orderBy('order_items.wc_item_id')
            ->get(['order_items.wc_item_id', 'orders.wc_order_id', 'products.wc_id'])
    ));

    foreach ([
        ['orders', 'wc_order_id', ['order_number', 'email', 'status', 'subtotal', 'discount_total', 'total']],
        // NOT order_id / product_id: those are LOCAL primary keys, and a
        // database that was emptied and re-imported carries on from wherever
        // the auto-increment had got to. The line's identity across two
        // separate imports is its WooCommerce ids, so the digest uses those.
        ['order_items', 'wc_item_id', ['quantity', 'unit_price', 'subtotal', 'total', 'name']],
        ['products', 'wc_id', ['sku', 'slug', 'price', 'sale_price', 'status']],
        ['customers', 'id', ['wp_user_id', 'email', 'name']],
        ['coupons', 'wc_id', ['code', 'type', 'amount', 'expires_at']],
    ] as [$table, $key, $columns]) {
        $snapshot['digest.'.$table] = md5((string) json_encode(
            DB::table($table)->orderBy($key)->get(array_merge([$key], $columns))
        ));
    }

    return $snapshot;
}

/** Fils out of a decimal string, by integer arithmetic — no float, ever. */
function volFils(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $negative = str_starts_with($value, '-');
    [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');
    $fils = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

    return $negative ? -$fils : $fils;
}

/** @return list<array<string, string>> */
function volRows(string $file): array
{
    $handle = fopen(volDir().'/'.$file, 'rb');
    $header = fgetcsv($handle, 0, ',', '"', '');
    $rows = [];

    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = array_combine($header, $cells);
    }

    fclose($handle);

    return $rows;
}

/* ------------------------------------------------------------------ tests */

it('imports the whole export and then changes nothing at all on the second pass', function () {
    $manifest = volManifest();

    $first = volImport();
    $after = volSnapshot();

    expect($after['products'])->toBe($manifest['products'] - $manifest['products.trashed_refused'])
        ->and($after['orders'])->toBe($manifest['orders'])
        ->and($after['order_items'])->toBe($manifest['order_items'])
        ->and($after['customers_from_file'])->toBe($manifest['customers'] - $manifest['customers.email_collision_refused']);

    $second = volImport();

    /*
     * THE EVIDENCE IS THE SHAPE OF THE SECOND REPORT, not the absence of an
     * exception. Every row presented a second time must come back `unchanged`:
     * a `created` means a duplicate went in, an `updated` means something is
     * rewritten on every pass and the idempotency claim is a guess.
     */
    foreach (['categories', 'brands', 'products', 'coupons', 'customers', 'orders', 'order-items', 'reviews', 'seo'] as $entity) {
        $e = $second->for($entity);

        expect($e->created)->toBe(0, $entity.' created rows on a second pass over an unchanged export')
            ->and($e->updated)->toBe(0, $entity.' rewrote rows on a second pass over an unchanged export')
            ->and($e->unchanged)->toBeGreaterThan(0, $entity.' reported nothing at all on the second pass');
    }

    // And the database itself: same rows, same values, byte for byte.
    expect(volSnapshot())->toBe($after);

    expect($first->totalUnaccounted())->toBe(0)
        ->and($second->totalUnaccounted())->toBe(0);
});

it('resumes onto exactly the rows it had not done when it is stopped mid-entity', function () {
    $manifest = volManifest();

    $uninterrupted = 'vol-clean-'.bin2hex(random_bytes(4));
    volImport(['runKey' => $uninterrupted]);
    $reference = volSnapshot();

    // Wipe and do it again, this time stopping inside every entity that has
    // more rows than the limit — which is what a shared host's timeout does.
    foreach (['order_items', 'orders', 'addresses', 'reviews', 'category_product'] as $table) {
        DB::table($table)->delete();
    }

    DB::table('customers')->delete();
    DB::table('products')->whereNotNull('wc_id')->delete();
    DB::table('coupons')->delete();
    DB::table('import_checkpoints')->delete();

    /*
     * ONE ENTITY AT A TIME, IN THE RUNNER'S OWN ORDER, which is the sequence
     * the admin screen drives and the only safe one — see the hazard pinned in
     * the test below for what happens when a limited run is allowed to reach
     * `orders` before customers.csv has been read to the end.
     */
    $key = 'vol-killed-'.bin2hex(random_bytes(4));
    $steps = 0;

    foreach (ImportRunner::entityNames() as $entity) {
        do {
            $report = volImport(['runKey' => $key, 'only' => [$entity], 'limit' => 23]);
            $steps++;

            $done = DB::table('import_checkpoints')
                ->where('run_key', $key)
                ->where('entity', $entity)
                ->whereNotNull('finished_at')
                ->exists();
        } while (! $done && $steps < 200);
    }

    expect($steps)->toBeLessThan(200, 'the stop-and-resume loop never finished');
    expect($steps)->toBeGreaterThan(count(ImportRunner::entityNames()), 'nothing was actually interrupted');

    $resumed = volSnapshot();

    /*
     * Every count, every digest. Not "roughly the same": a resumed import that
     * produces a different database from an uninterrupted one is an import
     * whose answer depends on when the host happened to kill it.
     */
    expect($resumed['orders'])->toBe($reference['orders'])
        ->and($resumed['order_items'])->toBe($reference['order_items'])
        ->and($resumed['customers_from_file'])->toBe($reference['customers_from_file'])
        ->and($resumed['products'])->toBe($reference['products'])
        ->and($resumed['orders_total_fils'])->toBe($reference['orders_total_fils'])
        ->and($resumed['items_total_fils'])->toBe($reference['items_total_fils'])
        ->and($resumed['digest.orders'])->toBe($reference['digest.orders'])
        ->and($resumed['digest.order_items'])->toBe($reference['digest.order_items'])
        ->and($resumed['digest.item_links'])->toBe($reference['digest.item_links'])
        ->and($resumed['digest.coupons'])->toBe($reference['digest.coupons'])
        ->and($resumed['digest.products'])->toBe($reference['digest.products']);

    // No duplicates, and no half-orders.
    expect(DB::table('orders')->distinct()->count('wc_order_id'))->toBe($manifest['orders'])
        ->and(DB::table('order_items')->distinct()->count('wc_item_id'))->toBe($manifest['order_items']);

    $orphans = DB::table('order_items')
        ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
        ->whereNull('orders.id')
        ->count();

    expect($orphans)->toBe(0, 'a line item survived without the order it belongs to');
});

it('loses customers when --limit is allowed to reach orders before customers.csv is finished', function () {
    /*
     * A HAZARD PINNED BY MEASUREMENT, not a behaviour anybody wants.
     *
     * docs/IMPORT-RUNBOOK.md §2 warns that running `--only=orders` before the
     * customers are in leaves a mess: the order cannot find its customer, links
     * by billing email, synthesises a guest row with a NULL wp_user_id, and the
     * genuine WordPress user is then refused as an email collision when they
     * arrive. What nothing said is that `--limit` PRODUCES THE SAME SEQUENCE by
     * a different route — it is a budget per entity, so one invocation does N
     * customers and then N orders, and every order in that slice whose customer
     * is further down the file does exactly this.
     *
     * The command now warns, and this is the measurement behind the warning.
     * Delete the warning and this test still passes; it is not testing the
     * message, it is testing that the loss is real and how big it is.
     */
    $manifest = volManifest();
    $key = 'vol-interleaved-'.bin2hex(random_bytes(4));

    for ($run = 0; $run < 12; $run++) {
        $report = volImport(['runKey' => $key, 'limit' => 23]);
    }

    $imported = Customer::query()->whereNotNull('wp_user_id')->count();
    $expected = $manifest['customers'] - $manifest['customers.email_collision_refused'];

    expect($imported)->toBeLessThan(
        $expected,
        'the interleaving hazard has stopped reproducing — if --limit was made entity-at-a-time, '
        .'delete this test and the warning in ImportWooCommerce::warnAboutInterleaving()'
    );

    // Every loss is NAMED, and named as what it is: a collision with a row this
    // import made itself, not with another WordPress user.
    $reasons = array_map(
        static fn (array $rejection): string => $rejection['reason'],
        $report->for('customers')->rejections(),
    );

    $selfInflicted = array_filter(
        $reasons,
        static fn (string $reason): bool => str_contains($reason, 'already belongs to a customer with no WordPress id'),
    );

    expect($selfInflicted)->not->toBeEmpty();

    // And the guest rows that took their addresses are there to prove it.
    expect(Customer::query()->whereNull('wp_user_id')->count())->toBeGreaterThan(0);
});

it('never lets the admin screen start an entity before the one it depends on is finished', function () {
    /*
     * THE OWNER'S OWN PATH, and the reason the hazard above cannot reach them.
     * ImportDriver walks ImportRunner::entityNames() and moves to the next
     * entity only when the current one's checkpoint is finished, so customers
     * is always complete before the first order is read — whatever the step
     * size, and however many steps get killed.
     */
    $manifest = volManifest();
    $workspace = new ImportWorkspace;

    foreach (glob(volDir().'/*.csv') as $file) {
        copy($file, $workspace->directory().'/'.basename($file));
    }

    $driver = new ImportDriver;
    $driver->start('live', ['adopt_by_slug' => true]);

    $steps = 0;
    $ordersSeenBeforeCustomersFinished = 0;

    do {
        $result = $driver->step(20);
        $steps++;

        expect($result['ok'])->toBeTrue(json_encode($result));

        if (($result['entity'] ?? null) === 'orders') {
            $customersDone = DB::table('import_checkpoints')
                ->where('run_key', ImportDriver::RUN_KEY)
                ->where('entity', 'customers')
                ->whereNotNull('finished_at')
                ->exists();

            if (! $customersDone) {
                $ordersSeenBeforeCustomersFinished++;
            }
        }
    } while (empty($result['complete']) && $steps < 400);

    expect($result['complete'] ?? false)->toBeTrue('the screen never reached the end')
        ->and($ordersSeenBeforeCustomersFinished)->toBe(0, 'an order was imported before customers.csv finished');

    // And therefore every customer in the file is in the shop.
    expect(Customer::query()->whereNotNull('wp_user_id')->count())
        ->toBe($manifest['customers'] - $manifest['customers.email_collision_refused'])
        ->and(Order::query()->count())->toBe($manifest['orders'])
        ->and(OrderItem::query()->count())->toBe($manifest['order_items']);

    /*
     * ONE VERIFICATION LINE PER ENTITY ON THE SCREEN, not one per slice.
     * Every other note is a fact about rows and accumulates correctly; the
     * count check is a fact about the whole bucket restated with bigger
     * numbers each step, so twenty of them stacked up would leave nineteen
     * stale sentences on screen with nothing saying which one is true.
     */
    foreach ($driver->status()['entities'] as $entity) {
        $lines = array_filter(
            array_keys($entity['notes']),
            static fn (string $note): bool => str_starts_with($note, ImportDriver::VERIFICATION_PREFIX),
        );

        expect(count($lines))->toBeLessThanOrEqual(
            1,
            $entity['entity'].' shows '.count($lines).' verification lines, of which at most one is current'
        );

        if ($entity['rows_total'] > 0) {
            expect($lines)->toHaveCount(1, $entity['entity'].' shows no verification line at all');
        }
    }
});

it('puts in exactly the money the export carried, to the fil', function () {
    volImport();

    $expectedOrders = 0;
    $checked = 0;

    foreach (volRows('orders.csv') as $row) {
        $order = Order::query()->where('wc_order_id', $row['order_id'])->first();

        expect($order)->not->toBeNull();

        $expectedOrders += volFils($row['total']);
        $checked++;

        expect($order->total)->toBe(volFils($row['total']), 'order '.$row['order_id'].' total')
            ->and($order->subtotal)->toBe(volFils($row['subtotal']))
            ->and($order->discount_total)->toBe(volFils($row['discount_total']))
            ->and($order->shipping_total)->toBe(volFils($row['shipping_total']));
    }

    expect($checked)->toBe(volManifest()['orders'])
        ->and((int) Order::query()->sum('total'))->toBe($expectedOrders);

    $expectedItems = 0;

    foreach (volRows('order_items.csv') as $row) {
        $item = OrderItem::query()->where('wc_item_id', $row['item_id'])->first();

        expect($item)->not->toBeNull();

        $expectedItems += volFils($row['total']);

        expect($item->total)->toBe(volFils($row['total']), 'item '.$row['item_id'].' total')
            ->and($item->subtotal)->toBe(volFils($row['subtotal']));
    }

    expect((int) OrderItem::query()->sum('total'))->toBe($expectedItems);

    /*
     * `coupons.amount` is the one money column whose UNIT DEPENDS ON ANOTHER
     * COLUMN: hundredths of a percent for `percent`, fils for everything else.
     * WooCommerce writes one decimal string for both, so getting this wrong is
     * a factor of a hundred on every discount the shop gives — the same shape
     * as the schema defect this project has already paid for once.
     */
    foreach (volRows('coupons.csv') as $row) {
        $coupon = DB::table('coupons')->where('wc_id', $row['id'])->first();

        expect($coupon)->not->toBeNull();
        expect((int) $coupon->amount)->toBe(
            volFils($row['coupon_amount']),
            'coupon '.$row['code'].' ('.$row['discount_type'].') amount'
        );

        if ($row['discount_type'] === 'percent') {
            expect((int) $coupon->amount)->toBeLessThanOrEqual(10000);
        }
    }
});

it('names every file in the export folder that no importer opens, with its row count', function () {
    $manifest = volManifest();
    $discards = volImport()->for('export')->discards();

    $named = [];

    foreach ($discards as $group) {
        foreach ($group['samples'] as $sample) {
            $named[$sample['line']] = $sample['before'];
        }
    }

    foreach (['refunds.csv', 'order_notes.csv', 'variations.csv', 'tags.csv'] as $file) {
        expect(array_key_exists($file, $named))->toBeTrue($file.' was in the folder and nothing said it was ignored');
        expect($named[$file])->toContain((string) $manifest['unread.'.$file]);
    }
});
