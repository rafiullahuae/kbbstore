<?php

/*
 * THE DRY RUN AS SOMETHING THE OWNER CAN ACT ON.
 *
 * The importer already answered two of Phase 13's three buckets. It could name
 * a row it REFUSED, in full, with a reason — tests/Feature/WooImportTest.php
 * pins that — and it counted a row it TOOK. What it could not answer was the
 * third: what goes in CHANGED, and what does not go in at all. The plan's line
 * is "three-bucket classification: migrate / discard / ask — Rafi approves any
 * discard list", and an owner cannot approve a list that is not produced.
 *
 * Every case below was found by running the importer at the shop's real volume
 * — 671 products, 4,159 orders, 3,712 customers — against a WooCommerce-shaped
 * export, and reading what came back. In every one of them the row imported,
 * the report said "created", and the difference between what the export held
 * and what the database now holds was invisible:
 *
 *   a refund line whose quantity of -1 became 0 while its money stayed at -50
 *   a unit price truncated by integer division, so the line no longer adds up
 *   a price carrying fils on a shop that prints whole dirhams
 *   an order in USD, added to AED revenue at face value by every SUM there is
 *   a slug invented from the product name, moving an address Google has
 *   a description with its <script> removed, which is right, and unannounced
 *   fourteen columns of the export that nothing reads
 *   two whole files — variations.csv, tags.csv — that nothing opens
 *
 * The fixture is BUILT rather than checked in, because the point of most of
 * these is a count over many rows, and a 4,000-row CSV in the repository is a
 * thing nobody can read a diff of. It is deterministic: same input every run.
 */

use App\Models\Order;
use App\Models\Product;
use App\Services\Import\EntityReport;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\Import\RedirectMap;
use App\Support\Money;

/* --------------------------------------------------------------- fixture */

function fdWrite(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    fputcsv($handle, $header);

    foreach ($rows as $row) {
        fputcsv($handle, array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $header));
    }

    fclose($handle);
}

/**
 * A WooCommerce-shaped export with one of each hazard in it.
 *
 * Small on purpose: every assertion here is about whether a thing is NAMED,
 * and one instance names it exactly as well as four thousand do. The volume
 * run that found them lives in fd-make-fixture.php and in the lane's report;
 * what belongs in the suite is the guard, not the load test.
 *
 * @return string the directory
 */
function fdExport(array $options = []): string
{
    $dir = sys_get_temp_dir().'/kbb-fd-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    fdWrite($dir.'/products.csv', [
        'id', 'name', 'slug', 'sku', 'status', 'regular_price', 'sale_price', 'stock_status',
        'description',
        // Columns a real WooCommerce export carries and this schema has no home for.
        'tax_class', 'weight', 'meta:_wcj_gift_message',
    ], [
        // Ordinary, so the counts below are differences and not totals.
        ['id' => '11001', 'name' => 'Plain Serum', 'slug' => 'plain-serum', 'sku' => 'KBB-1',
            'status' => 'publish', 'regular_price' => '80', 'stock_status' => 'instock'],
        // No SKU at all.
        ['id' => '11002', 'name' => 'No SKU Balm', 'slug' => 'no-sku-balm', 'sku' => '',
            'status' => 'publish', 'regular_price' => '85', 'stock_status' => 'instock'],
        // Two products, one SKU. products.sku has no unique index, so both go in.
        ['id' => '11003', 'name' => 'Dup One', 'slug' => 'dup-one', 'sku' => 'KBB-DUP',
            'status' => 'publish', 'regular_price' => '60', 'stock_status' => 'instock'],
        ['id' => '11004', 'name' => 'Dup Two', 'slug' => 'dup-two', 'sku' => 'KBB-DUP',
            'status' => 'publish', 'regular_price' => '65', 'stock_status' => 'instock'],
        // A price carrying fils, on a shop that prints whole dirhams.
        ['id' => '11005', 'name' => 'Fils Serum', 'slug' => 'fils-serum', 'sku' => 'KBB-F',
            'status' => 'publish', 'regular_price' => '99.50', 'sale_price' => '79.99',
            'stock_status' => 'instock'],
        // No slug column value: the importer invents one, and the address moves.
        ['id' => '11006', 'name' => 'مرطب الوجه — Crème Hydratante 保湿', 'slug' => '', 'sku' => 'KBB-I',
            'status' => 'publish', 'regular_price' => '120', 'stock_status' => 'instock'],
        // HTML the allowlist will cut down.
        ['id' => '11007', 'name' => 'Scripted Toner', 'slug' => 'scripted-toner', 'sku' => 'KBB-X',
            'status' => 'publish', 'regular_price' => '55', 'stock_status' => 'instock',
            'description' => '<p>Good copy.</p><script>alert(1)</script><p>More.</p>',
            'meta:_wcj_gift_message' => 'Happy birthday'],
    ]);

    fdWrite($dir.'/orders.csv', [
        'order_id', 'order_number', 'customer_id', 'status', 'currency',
        'date_created', 'date_created_gmt', 'billing_email', 'subtotal', 'shipping_total', 'total',
        'meta:_delivery_instructions',
    ], [
        ['order_id' => '21001', 'order_number' => '9001', 'status' => 'wc-completed', 'currency' => 'AED',
            'date_created' => '2023-05-05 21:40:00', 'date_created_gmt' => '2023-05-05 17:40:00',
            'billing_email' => 'a@example.com', 'subtotal' => '150', 'total' => '150',
            'meta:_delivery_instructions' => 'Ring the bell twice'],
        // Fils on the money columns.
        ['order_id' => '21002', 'order_number' => '9002', 'status' => 'wc-completed', 'currency' => 'AED',
            'date_created' => '2023-08-08 10:00:00', 'date_created_gmt' => '2023-08-08 06:00:00',
            'billing_email' => 'b@example.com', 'subtotal' => '99.50', 'shipping_total' => '25.25',
            'total' => '124.75'],
        // A currency this shop's revenue figures do not distinguish.
        ['order_id' => '21003', 'order_number' => '9003', 'status' => 'wc-completed', 'currency' => 'USD',
            'date_created' => '2023-10-10 10:00:00', 'date_created_gmt' => '2023-10-10 06:00:00',
            'billing_email' => 'c@example.com', 'subtotal' => '100', 'total' => '100'],
    ]);

    fdWrite($dir.'/order_items.csv', [
        'item_id', 'order_id', 'product_id', 'name', 'sku', 'quantity', 'subtotal', 'total',
    ], [
        ['item_id' => '31001', 'order_id' => '21001', 'product_id' => '11001', 'name' => 'Plain Serum',
            'sku' => 'KBB-1', 'quantity' => '1', 'subtotal' => '80', 'total' => '80'],
        // A WooCommerce refund: negative quantity, negative money.
        ['item_id' => '31002', 'order_id' => '21001', 'product_id' => '11001', 'name' => 'Refund',
            'sku' => 'KBB-1', 'quantity' => '-1', 'subtotal' => '-50', 'total' => '-50'],
        // Three for AED 100: the unit price does not divide evenly.
        ['item_id' => '31003', 'order_id' => '21002', 'product_id' => '11001', 'name' => 'Bundle',
            'sku' => 'KBB-1', 'quantity' => '3', 'subtotal' => '100', 'total' => '100'],
    ]);

    if ($options['files'] ?? true) {
        /*
         * Two exports this application has no entity for at all.
         *
         * THESE USED TO BE coupons.csv AND reviews.csv, then refunds.csv AND
         * order_notes.csv, and that is the whole history of this channel: each
         * pair was the worst file on the list, this test is what made them
         * visible, and each pair is now an entity of its own (CouponImporter
         * and ReviewImporter -- tests/Feature/CouponReviewImportTest.php;
         * RefundImporter and OrderNoteImporter --
         * tests/Feature/GiRefundsAndNotesTest.php). The guard is not about any
         * of those four names. It is about a file being ignored in silence,
         * which is still true of everything a WooCommerce export carries that
         * nothing here opens -- so it is pinned on two files that really are
         * unread, and it will have to move again when they are not.
         */
        fdWrite($dir.'/variations.csv', ['variation_id', 'parent_id', 'sku', 'price'], [
            ['variation_id' => '61001', 'parent_id' => '21001', 'sku' => 'VAR-1', 'price' => '50'],
            ['variation_id' => '61002', 'parent_id' => '21002', 'sku' => 'VAR-2', 'price' => '25'],
        ]);

        fdWrite($dir.'/tags.csv', ['term_id', 'name', 'slug', 'count'], [
            ['term_id' => '71001', 'name' => 'Hydrating', 'slug' => 'hydrating', 'count' => '4'],
            ['term_id' => '71002', 'name' => 'Sensitive skin', 'slug' => 'sensitive-skin', 'count' => '9'],
        ]);
    }

    return $dir;
}

function fdRun(string $dir, array $overrides = []): ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => $dir,
        'dryRun' => true,
        'runKey' => 'fd-'.bin2hex(random_bytes(4)),
    ], $overrides)));
}

/**
 * The headline of every adjustment or discard an entity reported, with its
 * count — which is the whole of what the owner reads.
 *
 * @return array<string, int>
 */
function fdChanges(ImportReport $report, string $entity, string $bucket = 'adjustments'): array
{
    $groups = $bucket === 'adjustments'
        ? $report->for($entity)->adjustments()
        : $report->for($entity)->discards();

    return array_map(static fn (array $group): int => $group['count'], $groups);
}

function fdMatch(array $changes, string $needle): ?string
{
    foreach (array_keys($changes) as $headline) {
        if (str_contains($headline, $needle)) {
            return $headline;
        }
    }

    return null;
}

/* ------------------------------------------- what goes in changed (bucket 2) */

it('names the product that arrives with no SKU, and the two that share one', function () {
    $report = fdRun(fdExport());
    $changes = fdChanges($report, 'products');

    $noSku = fdMatch($changes, 'no SKU in the export');
    $duplicate = fdMatch($changes, 'two products share one SKU');

    expect($noSku)->not->toBeNull('a product with no SKU imported without a word being said about it')
        ->and($changes[$noSku])->toBe(1)
        ->and($duplicate)->not->toBeNull('two products took one SKU and the report called both of them created')
        ->and($changes[$duplicate])->toBe(1);

    // And it is a REPORT, not a refusal: both products are still imported.
    expect($report->for('products')->created)->toBe(7);
});

it('names a price carrying fils, because this shop prints whole dirhams and rounds', function () {
    // The premise. If the shop is ever configured to print fils this check is
    // pointless and must not fire, so it is asserted rather than assumed.
    expect(Money::displayDecimals())->toBe(0);

    $changes = fdChanges(fdRun(fdExport()), 'products');
    $headline = fdMatch($changes, 'a price carrying fils');

    expect($headline)->not->toBeNull('AED 99.50 imported and printed as AED 100 with nothing said')
        // regular_price 99.50 and sale_price 79.99, on the one product.
        ->and($changes[$headline])->toBe(2);

    $orders = fdChanges(fdRun(fdExport()), 'orders');

    expect(fdMatch($orders, 'an order amount carrying fils'))->not->toBeNull();
});

it('names an order in a currency every revenue figure will add up as dirhams', function () {
    $changes = fdChanges(fdRun(fdExport()), 'orders');
    $headline = fdMatch($changes, 'not the store currency');

    expect($headline)->not->toBeNull(
        'a USD order imported and is summed into AED revenue by every figure in the admin'
    )->and($changes[$headline])->toBe(1);
});

it('names the slug it invented, because that is an address Google already has', function () {
    $report = fdRun(fdExport());
    $changes = fdChanges($report, 'products');
    $headline = fdMatch($changes, 'slug invented from the product name');

    expect($headline)->not->toBeNull(
        'the importer transliterated a name into a new address and reported the product as created'
    )->and($changes[$headline])->toBe(1);

    // The before and the after are both there, which is what makes the line
    // actionable: the owner writes a redirect from one to the other.
    $sample = $report->for('products')->adjustments()[$headline]['samples'][0];

    expect($sample['before'])->toContain('مرطب')
        ->and($sample['after'])->toBe('mrtb-alogh-creme-hydratante')
        // The Arabic was romanised and the CJK dropped outright, which is the
        // point: this is not a copy of the old address.
        ->and($sample['after'])->not->toContain('保湿');
});

it('names the refund line whose quantity it changed and whose money it did not', function () {
    $report = fdRun(fdExport());
    $changes = fdChanges($report, 'order-items');
    $headline = fdMatch($changes, 'negative quantity clamped to zero');

    expect($headline)->not->toBeNull(
        'a refund line imported as quantity 0 for minus fifty dirhams, reported as created'
    );

    $sample = $report->for('order-items')->adjustments()[$headline]['samples'][0];

    expect($sample['before'])->toBe('-1')->and($sample['after'])->toBe('0');
});

it('names the unit price it truncated, so the order page does not add up unannounced', function () {
    $changes = fdChanges(fdRun(fdExport()), 'order-items');
    $headline = fdMatch($changes, 'unit price truncated');

    expect($headline)->not->toBeNull(
        'AED 100 over three units imported as 33.33 each, which multiplies back to 99.99'
    )->and($changes[$headline])->toBe(1);
});

/* ------------------------------------------------- what is discarded (bucket 3) */

it('names the HTML the allowlist removed, because a discard is the owner\'s to approve', function () {
    $report = fdRun(fdExport());
    $discards = fdChanges($report, 'products', 'discards');
    $headline = fdMatch($discards, 'HTML the allowlist removed');

    expect($headline)->not->toBeNull(
        'the script tag was stripped — correctly — and the owner had no way to learn that the '
        .'description is not what WooCommerce held'
    );

    $sample = $report->for('products')->discards()[$headline]['samples'][0];

    expect($sample['before'])->toContain('<script>')
        ->and($sample['after'])->toContain('characters kept');
});

it('names the columns of the export that nothing reads', function () {
    $report = fdRun(fdExport());

    foreach (['products' => 'meta_wcj_gift_message', 'orders' => 'meta_delivery_instructions'] as $entity => $column) {
        $discards = $report->for($entity)->discards();
        $headline = fdMatch(fdChanges($report, $entity, 'discards'), 'columns in this export');

        expect($headline)->not->toBeNull($entity.': ignored columns were invisible in the report');

        $sample = $discards[$headline]['samples'][0];

        // The NAME and the first real VALUE, because the name alone is not a
        // decision and "meta_delivery_instructions = Ring the bell twice" is.
        expect($sample['before'])->toContain($column)
            ->and($sample['before'])->toContain($entity === 'products' ? 'Happy birthday' : 'Ring the bell twice');
    }

    // A column the importer DOES read is not in the list, whatever its value.
    $orders = fdMatch(fdChanges($report, 'orders', 'discards'), 'columns in this export');

    expect($report->for('orders')->discards()[$orders]['samples'][0]['before'])
        ->not->toContain('billing_email');
});

it('names the export files no importer opens at all', function () {
    $report = fdRun(fdExport());
    $discards = $report->for('export')->discards();
    $headline = fdMatch(fdChanges($report, 'export', 'discards'), 'no importer opens');

    expect($headline)->not->toBeNull(
        'a file sat in the folder unread and nothing in the report said it was never opened'
    )->and($discards[$headline]['count'])->toBe(2);

    $named = implode(' ', array_column($discards[$headline]['samples'], 'field'));

    expect($named)->toContain('variations.csv')->and($named)->toContain('tags.csv');

    /*
     * AND THE FOUR FILES THIS CHANNEL WAS WRITTEN FOR ARE NOT ON IT ANY MORE,
     * because they are entities now. Asserted rather than assumed: a channel
     * that still named them would mean the importers were registered and the
     * runner had not noticed, and the owner would be told his coupons -- or his
     * refunds -- were dropped on a run that imported them.
     *
     * str_contains, NOT expect()->not->toContain(). `toContain` is variadic, so
     * a `not->toContain($a)->not->toContain($b)` chain is fine but the message
     * form is not, and the four asserted here are worth spelling out one way.
     */
    foreach (['coupons.csv', 'reviews.csv', 'refunds.csv', 'order_notes.csv'] as $imported) {
        expect(str_contains($named, $imported))->toBeFalse(
            $imported.' was named as a file no importer opens, and one does'
        );
    }
});

it('does not call a file unread when the run was deliberately narrowed', function () {
    // --only=products is SUPPOSED to ignore orders.csv. Saying so would train
    // the owner to skim past the one message that matters.
    $report = fdRun(fdExport(), ['only' => ['products']]);

    expect($report->for('export')->discards())->toBe([]);
});

it('says nothing at all about an export that has nothing to discard', function () {
    // The guard against the opposite failure: a report that always has
    // something in the third bucket is a report nobody reads.
    $dir = fdExport(['files' => false]);

    foreach (glob($dir.'/*.csv') ?: [] as $file) {
        $rows = array_map('str_getcsv', file($file));
        $header = array_shift($rows);
        $keep = array_values(array_filter(
            array_keys($header),
            static fn (int $i): bool => ! str_starts_with((string) $header[$i], 'meta:'),
        ));

        $handle = fopen($file, 'wb');
        fputcsv($handle, array_map(static fn (int $i): string => $header[$i], $keep));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (int $i): string => $row[$i] ?? '', $keep));
        }

        fclose($handle);
    }

    $report = fdRun($dir);

    expect($report->for('export')->discards())->toBe([])
        ->and(fdMatch(fdChanges($report, 'orders', 'discards'), 'columns in this export'))->toBeNull();
});

/* ------------------------------------------------------ the declared timezone */

it('checks --timezone against the export\'s own GMT column instead of trusting it', function () {
    $dir = fdExport();

    // Asia/Dubai is what these rows were really written in: the local column
    // and the GMT column are four hours apart.
    expect(fdChanges(fdRun($dir), 'orders'))
        ->not->toHaveKey(fdMatch(fdChanges(fdRun($dir), 'orders'), 'GMT column disagrees') ?? '_none_');

    $wrong = fdChanges(fdRun($dir, ['sourceTimezone' => 'UTC']), 'orders');
    $headline = fdMatch($wrong, 'GMT column disagrees');

    expect($headline)->not->toBeNull(
        'every order in the file was read four hours out and the rows were perfectly well-formed'
    )->and($wrong[$headline])->toBe(3);
});

it('makes no timezone claim it cannot check', function () {
    // Most exporters write only the local column. The check must not fire
    // there, and must not imply a verification that did not happen.
    $dir = fdExport();
    $path = $dir.'/orders.csv';
    $rows = array_map('str_getcsv', file($path));
    $header = array_shift($rows);
    $gmt = array_search('date_created_gmt', $header, true);

    $handle = fopen($path, 'wb');
    fputcsv($handle, array_values(array_diff_key($header, [$gmt => true])));

    foreach ($rows as $row) {
        fputcsv($handle, array_values(array_diff_key($row, [$gmt => true])));
    }

    fclose($handle);

    expect(fdMatch(fdChanges(fdRun($dir, ['sourceTimezone' => 'UTC']), 'orders'), 'GMT column disagrees'))
        ->toBeNull();
});

/* ----------------------------------------------------------- the dry run itself */

it('reports all of that while writing absolutely nothing', function () {
    $dir = fdExport();

    $before = [Product::query()->count(), Order::query()->count()];
    $report = fdRun($dir);

    expect([Product::query()->count(), Order::query()->count()])->toBe($before)
        ->and($report->isDryRun())->toBeTrue()
        // And it is not an empty answer: the whole point is that it saw all of
        // this with nothing left behind.
        ->and($report->totalAdjusted())->toBeGreaterThan(0)
        ->and($report->totalDiscarded())->toBeGreaterThan(0);
});

it('predicts changes and discards that the real run then makes and reports identically', function () {
    $dir = fdExport();

    $predicted = fdRun($dir);
    $actual = (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        runKey: 'fd-real-'.bin2hex(random_bytes(4)),
    ));

    foreach (['products', 'orders', 'order-items', 'export'] as $entity) {
        expect(fdChanges($actual, $entity))->toBe(fdChanges($predicted, $entity), $entity.': adjustments')
            ->and(fdChanges($actual, $entity, 'discards'))
            ->toBe(fdChanges($predicted, $entity, 'discards'), $entity.': discards');
    }
});

it('holds a bounded number of examples, so a real export cannot exhaust memory', function () {
    // "A unit price truncated by integer division" is true of tens of thousands
    // of line items in a perfectly good import. The COUNT has to be exact and
    // the examples have to be bounded, or the report is the thing that kills
    // the run it was meant to describe.
    $dir = fdExport();
    $path = $dir.'/order_items.csv';
    $handle = fopen($path, 'ab');

    for ($i = 0; $i < 60; $i++) {
        fputcsv($handle, [900000 + $i, '21001', '11001', 'Bundle', 'KBB-1', '3', '100', '100']);
    }

    fclose($handle);

    $report = fdRun($dir);
    $headline = fdMatch(fdChanges($report, 'order-items'), 'unit price truncated');
    $group = $report->for('order-items')->adjustments()[$headline];

    expect($group['count'])->toBe(61)
        ->and($group['samples'])->toHaveCount(EntityReport::SAMPLES_PER_KIND);
});

/* --------------------------------------------------------------- the URL map */

it('does not ask the owner about two rules that propose the same redirect', function () {
    // Both rules describe the same category from two directions: the nesting
    // rule derives the move from the imported tree, the permalink file records
    // it from the old site. On a real export that is EVERY nested category, and
    // every one of them was arriving as a question whose reason read "points it
    // somewhere else, at <the same address>".
    $parent = App\Models\Category::query()->create([
        'name' => 'Skincare FD', 'slug' => 'skincare-fd', 'source_term_id' => 611001,
    ]);

    $leaf = App\Models\Category::query()->create([
        'name' => 'Serums FD', 'slug' => 'serums-fd', 'parent_id' => $parent->id, 'source_term_id' => 611002,
    ]);

    $leaf->forceFill(['depth' => 1, 'path' => 'skincare-fd/serums-fd'])->save();
    $parent->forceFill(['depth' => 0, 'path' => 'skincare-fd'])->save();

    /*
     * MOVED ONTO THE ROOT-FLAT ADDRESS BY LANE GB. The collapse rule this test
     * owns is unchanged; the address had to move because
     * `/product-category/serums-fd/` no longer reaches the migrate bucket at
     * all — the archive controller 301s it itself, so a stored row could never
     * fire and both claims on it are discarded before anybody reads them. On
     * `/serums-fd/`, which does 404, the two rules genuinely agree about a row
     * that WILL be written, which is the case this test is about.
     */
    $proposals = (new RedirectMap)->propose([
        ['type' => 'category', 'wc_id' => '611002', 'url' => '/serums-fd/'],
    ]);

    $mine = array_values(array_filter(
        $proposals,
        static fn (array $p): bool => $p['source'] === '/serums-fd/',
    ));

    expect($mine)->toHaveCount(2, 'both rules should still be visible in the map');

    $decisions = array_column($mine, 'decision');
    sort($decisions);

    expect($decisions)->toBe([RedirectMap::DISCARD, RedirectMap::MIGRATE])
        ->and(array_unique(array_column($mine, 'target')))->toHaveCount(1);

    // The surviving one still points where it should.
    $migrate = array_values(array_filter($mine, static fn (array $p): bool => $p['decision'] === RedirectMap::MIGRATE));

    expect($migrate[0]['target'])->toBe('/product-category/skincare-fd/serums-fd/');

    unset($leaf);
});

it('still asks when two rules send one address to two different places', function () {
    // The guard for the guard. Collapsing agreement must not collapse an actual
    // disagreement, which is the case the ASK bucket exists for.
    $parent = App\Models\Category::query()->create([
        'name' => 'Skincare FD2', 'slug' => 'skincare-fd2', 'source_term_id' => 612001,
    ]);

    $leaf = App\Models\Category::query()->create([
        'name' => 'Toners FD2', 'slug' => 'toners-fd2', 'parent_id' => $parent->id, 'source_term_id' => 612002,
    ]);

    $leaf->forceFill(['depth' => 1, 'path' => 'skincare-fd2/toners-fd2'])->save();
    $parent->forceFill(['depth' => 0, 'path' => 'skincare-fd2'])->save();

    $proposals = (new RedirectMap)->propose([
        // The old site served this leaf address at something else entirely.
        // Root-flat, for the reason the previous test gives.
        ['type' => 'category', 'wc_id' => '612001', 'url' => '/toners-fd2/'],
    ]);

    $mine = array_values(array_filter(
        $proposals,
        static fn (array $p): bool => $p['source'] === '/toners-fd2/',
    ));

    expect(array_column($mine, 'decision'))->toContain(RedirectMap::ASK);
});

/* ------------------------------------------------- interrupted and resumed */

it('reports the same changes after being interrupted as it does in one pass', function () {
    /*
     * THE HOST KILLS LONG REQUESTS AND HAS NO QUEUE WORKER, so the real
     * migration is many short runs, each continuing from the checkpoint the
     * last one committed. Killing one and restarting it was already proved to
     * produce an identical DATABASE — WooImportTest pins that, and a hard
     * SIGKILL at 400 of 3,712 customers on the full-volume fixture reproduced
     * it exactly: same row counts, no duplicate wp_user_id, email or
     * wc_order_id.
     *
     * What was not covered, because it did not exist, is the REPORT. An
     * adjustment counted per batch is a count that an interruption can split,
     * double or lose, and "173 unit prices were truncated" is a number the
     * owner acts on. If a resumed run cannot reproduce it, the discard list is
     * not something anybody can approve.
     */
    $dir = fdExport();
    $run = 'fd-resume-'.bin2hex(random_bytes(4));

    $whole = (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        runKey: 'fd-whole-'.bin2hex(random_bytes(4)),
    ));

    // The same import in slices of two rows, exactly as the stepped runner
    // drives it. --limit is a resume point, not a truncation.
    for ($step = 0; $step < 12; $step++) {
        (new ImportRunner)->run(new ImportOptions(
            directory: $dir,
            batchSize: 1,
            limit: 2,
            runKey: $run,
        ));
    }

    $resumed = (new ImportRunner)->run(new ImportOptions(directory: $dir, runKey: $run));

    // Every row is in, once.
    expect(Product::query()->whereNotNull('wc_id')->count())->toBe($whole->for('products')->created)
        ->and(Order::query()->whereNotNull('wc_order_id')->count())->toBe($whole->for('orders')->created);

    // And the last pass over a finished run re-reads every row and finds
    // nothing to do, which is the cheapest idempotency proof there is.
    foreach (['products', 'orders', 'order-items'] as $entity) {
        expect($resumed->for($entity)->created)->toBe(0, $entity.' created rows on a re-run')
            ->and($resumed->for($entity)->updated)->toBe(0, $entity.' updated rows on a re-run')
            ->and($resumed->for($entity)->unchanged)->toBe($whole->for($entity)->created, $entity.' unchanged');

        // The counts the owner reads are the same ones, whole run or resumed.
        expect(fdChanges($resumed, $entity))->toBe(fdChanges($whole, $entity), $entity.': adjustments')
            ->and(fdChanges($resumed, $entity, 'discards'))
            ->toBe(fdChanges($whole, $entity, 'discards'), $entity.': discards');
    }
});
