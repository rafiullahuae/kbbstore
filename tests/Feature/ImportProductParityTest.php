<?php

/*
 * ── THE WOOCOMMERCE PRODUCT ROUND-TRIP, FIELD BY FIELD — Lane PX ────────────
 *
 * The owner is moving his live shop and said: "everything must be compatible
 * without anything skipping or losing... don't assume anything."
 *
 * The exporter's product row carries 45 columns. ProductImporter writes 24 of
 * them onto `products`, one (`tag_term_ids`) is redundant because the same
 * pivot arrives from tags.csv wherever that file is part of the export, and
 * TWENTY reach no column at all. This file is about the twenty.
 *
 * Two of them -- `sold_individually` and `reviews_enabled` -- were on the
 * owner's edit page and in NO FILE AT ALL until this lane, which is the one kind
 * of loss no report here could have found: the census classifies columns the
 * export writes, and the discard list names columns that arrive.
 *
 * It does NOT assert that they are carried -- the decision about which of them
 * earn a column is the owner's and has not been made. It asserts the thing that
 * is true whatever he decides and that costs nothing either way: that a field
 * the import does not carry is NAMED, with the value it held, and COUNTED, so
 * "nothing was lost" stops being a hope and becomes a list. A field dropped in
 * silence is indistinguishable from a field that was never there, and that is
 * the failure with no row-count symptom -- the tally reads 671 of 671 and the
 * parcel weights are gone.
 *
 * SEE ALSO docs/PRODUCT-FIELD-PARITY.md for the whole table, and
 * GqMigrationCensusTest, which pins the same facts from the census side.
 */

use App\Models\Product;
use App\Services\Import\EntityReport;
use App\Services\Import\Entities\ProductImporter;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\ImportConsole\ImportChain;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The 45 columns the exporter's product stage writes, in its own order. */
function pxHeader(): array
{
    return [
        'id', 'name', 'slug', 'sku', 'status', 'type', 'regular_price', 'sale_price', 'sale_starts_at',
        'sale_ends_at', 'stock_status', 'stock', 'manage_stock', 'featured', 'is_visible', 'brand_term_id',
        'category_term_ids', 'position', 'date_created', 'image', 'images', 'short_description',
        'description', 'total_sales', 'date_modified', 'product_visibility', 'backorders',
        'low_stock_amount', 'weight', 'length', 'width', 'height', 'tax_status', 'tax_class',
        'shipping_class', 'virtual', 'downloadable', 'purchase_note', 'upsell_ids', 'cross_sell_ids',
        'grouped_ids', 'tag_term_ids', 'attribute_summary', 'sold_individually', 'reviews_enabled',
    ];
}

/**
 * The owner's own product page, as a row: Medicube Kojic Acid Glow Full Routine
 * Set. Every one of the twenty carries a value, because a fixture where they are
 * blank proves nothing about a shop where they are not -- which is exactly how
 * the truncation defect below survived for as long as it did.
 *
 * @param  array<string, string>  $overrides
 */
function pxRow(array $overrides = []): array
{
    $row = [
        /*
         * 4021 AND NOT AN ID OF ITS OWN, deliberately. tags.csv in this fixture
         * carries `product_ids` of 4021, and the claim that `tag_term_ids` is
         * redundant rests entirely on that pivot really arriving from the tag
         * side. A product id nothing else in the export mentions would make the
         * tag test pass for the wrong reason -- no pivot, and no tags either.
         */
        'id' => '4021',
        'name' => 'Medicube Kojic Acid Glow Full Routine Set',
        'slug' => 'medicube-kojic-acid-glow-full-routine-set',
        'sku' => 'MED-KOJIC-SET',
        'status' => 'publish',
        'type' => 'simple',
        'regular_price' => '349.00',
        'sale_price' => '299.00',
        'sale_starts_at' => '',
        'sale_ends_at' => '',
        'stock_status' => 'instock',
        'stock' => '8',
        'manage_stock' => 'yes',
        'featured' => 'yes',
        'is_visible' => 'yes',
        'brand_term_id' => '502',
        'category_term_ids' => '22,15',
        'position' => '1',
        'date_created' => '2024-05-06 10:00:00',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2024/05/medicube-set.jpg',
        'images' => '',
        'short_description' => 'A four-step routine.',
        'description' => '<p>A routine set.</p>',
        'total_sales' => '12',
        'date_modified' => '2024-06-07 11:22:33',
        'product_visibility' => 'visible',
        'backorders' => 'notify',
        'low_stock_amount' => '3',
        'weight' => '0.85',
        'length' => '18',
        'width' => '12',
        'height' => '9',
        'tax_status' => 'taxable',
        'tax_class' => 'reduced-rate',
        'shipping_class' => 'bulky-parcel',
        'virtual' => 'no',
        'downloadable' => 'no',
        'purchase_note' => 'Thank you for your order. Please patch test the toner pad before full use.',
        'upsell_ids' => '4102,4103,4104',
        'cross_sell_ids' => '4210,4211',
        'grouped_ids' => '4301,4302',
        'tag_term_ids' => '601',
        'attribute_summary' => 'Skin Type=Combination, Oily | Concern=Hyperpigmentation, Dullness',
        'sold_individually' => 'yes',
        'reviews_enabled' => 'no',
    ];

    return array_merge($row, $overrides);
}

/**
 * A copy of the real export fixture with products.csv replaced by the given
 * rows. The other files come along so categories, brands and tags resolve --
 * the drop report is only honest if the rest of the import really works.
 *
 * @param  list<array<string, string>>  $rows
 * @param  list<string>  $extraColumns
 */
function pxExport(array $rows, array $extraColumns = []): string
{
    $dir = sys_get_temp_dir().'/kbb-px-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    foreach (glob(base_path('tests/Fixtures/kbb-export').'/*') ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    $header = [...pxHeader(), ...array_keys($extraColumns)];

    $handle = fopen($dir.'/products.csv', 'w');
    fputcsv($handle, $header);

    foreach ($rows as $row) {
        $cells = [];

        foreach (pxHeader() as $column) {
            $cells[] = $row[$column] ?? '';
        }

        foreach ($extraColumns as $value) {
            $cells[] = $value;
        }

        fputcsv($handle, $cells);
    }

    fclose($handle);

    /*
     * AND THE MANIFEST HAS TO AGREE WITH THE FILE WE JUST WROTE.
     *
     * ImportDriver::denominator() refuses to trust a manifest row count for a
     * file whose bytes and sha256 do not match, and answers null rather than
     * guessing -- the same rule ImportChain applies to the overall bar, that "a
     * total that is wrong in the flattering direction is worse than no total".
     * A test fixture that rewrites products.csv and leaves the manifest alone is
     * therefore not a smaller export, it is an INCONSISTENT one, and the
     * progress page correctly declines to reconcile it.
     *
     * That cost this lane a confusing red. Rewriting the manifest entry keeps
     * the fixture the shape a real export actually has.
     */
    $manifestPath = $dir.'/manifest.json';

    if (is_file($manifestPath)) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $bytes = (string) file_get_contents($dir.'/products.csv');

        $manifest['files']['products.csv'] = [
            'rows' => count($rows),
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ];

        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $dir;
}

function pxImport(string $dir, array $overrides = []): ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => $dir,
        'adoptBySlug' => true,
    ], $overrides)));
}

/** The discard kinds that named one particular field, out of the report. */
function pxDiscardFor(ImportReport $report, string $field): array
{
    $out = [];

    foreach ($report->for('products')->discards() as $kind => $discard) {
        foreach ($discard['samples'] as $sample) {
            if ($sample['field'] === $field) {
                $out[] = ['kind' => $kind, 'count' => $discard['count'], 'samples' => $discard['samples']];

                continue 2;
            }
        }
    }

    return $out;
}

/** The runner's one consolidated "no field reads this" line for an entity. */
function pxConsolidated(ImportReport $report, string $entity): ?array
{
    foreach ($report->for($entity)->discards() as $kind => $discard) {
        if (str_contains($kind, 'columns in this export that no field of this importer reads')) {
            return $discard['samples'][0];
        }
    }

    return null;
}

/* ═══════════════════════════════════════ every dropped field, named and valued */

it('names every field it does not carry, with the value that field held', function () {
    /*
     * THE HEART OF IT. Before this, all eighteen were anonymous names inside one
     * consolidated line with a count of 1 -- so `weight` dropped on 400 products
     * and `weight` dropped on one read identically, and the only example of any
     * of them was whichever value the first row happened to hold.
     *
     * MUTATION: remove any entry from ProductImporter::NOT_CARRIED -- say
     * 'weight' -- and this is red on that name, because the field stops having a
     * discard of its own AND reappears in the consolidated line below.
     */
    $report = pxImport(pxExport([pxRow()]));

    $expected = [
        'weight' => '0.85',
        'length' => '18',
        'width' => '12',
        'height' => '9',
        'shipping_class' => 'bulky-parcel',
        'tax_status' => 'taxable',
        'tax_class' => 'reduced-rate',
        'virtual' => 'no',
        'downloadable' => 'no',
        'backorders' => 'notify',
        'low_stock_amount' => '3',
        'upsell_ids' => '4102,4103,4104',
        'cross_sell_ids' => '4210,4211',
        'grouped_ids' => '4301,4302',
        'purchase_note' => 'Thank you for your order. Please patch test the toner pad before full use.',
        'product_visibility' => 'visible',
        'attribute_summary' => 'Skin Type=Combination, Oily | Concern=Hyperpigmentation, Dullness',
        'date_modified' => '2024-06-07 11:22:33',
        // The two this lane ADDED to the export, because they were on his edit
        // page and in no file. They are asserted here exactly like the rest --
        // the point of carrying them is that they become reportable.
        'sold_individually' => 'yes',
        'reviews_enabled' => 'no',
    ];

    foreach ($expected as $field => $value) {
        $discards = pxDiscardFor($report, $field);

        expect($discards)->toHaveCount(
            1,
            $field.' is in the export and reaches no column, and the report does not name it. A field '
            .'dropped in silence is indistinguishable from one that was never there.'
        );

        expect($discards[0]['samples'][0]['before'])->toBe(
            $value,
            $field.': the report names the field and not what was in it. "'.$field.' was dropped" is a fact '
            .'the owner can do nothing with; "'.$field.' held '.$value.' and was dropped" is one he can act on.'
        );

        /*
         * str_contains() AND NOT ->toContain($needle, $message): toContain is
         * VARIADIC, so a message passed as a second argument is read as a second
         * NEEDLE and the assertion fails on its own explanation. This repository
         * has been bitten by the same idiom from the other end -- see the note
         * on array_diff in GqMigrationCensusTest.
         */
        expect(str_contains($discards[0]['kind'], $field))->toBeTrue(
            $field.': the discard kind does not say which field it is about'
        );
    }
});

it('counts the fields it skipped, once each, however many rows carried them', function () {
    /*
     * The number the reconciliation sentence prints. It is DISTINCT FIELDS and
     * not rows, because `weight` dropped on 671 products is one thing the owner
     * has lost and 671 places it happened -- and a number that grows with the
     * size of the catalogue is one nobody can sanity-check.
     *
     * MUTATION: change EntityReport::droppedField() to keep a counter instead of
     * a set keyed by the field name, and this reads 36 instead of 18.
     */
    $report = pxImport(pxExport([
        pxRow(),
        pxRow(['id' => '4022', 'slug' => 'second-set', 'sku' => 'MED-KOJIC-SET-2']),
    ]));

    $products = $report->for('products');

    expect($products->droppedFieldCount())->toBe(20)
        ->and($products->droppedFieldNames())->toBe([
            'attribute_summary', 'backorders', 'cross_sell_ids', 'date_modified', 'downloadable',
            'grouped_ids', 'height', 'length', 'low_stock_amount', 'product_visibility', 'purchase_note',
            'reviews_enabled', 'shipping_class', 'sold_individually', 'tax_class', 'tax_status',
            'upsell_ids', 'virtual', 'weight', 'width',
        ]);

    // And the per-row count is still there, beside it, on each field's own kind.
    expect(pxDiscardFor($report, 'weight')[0]['count'])->toBe(
        2,
        'the per-field discard should count the rows it happened on -- that is the number the distinct '
        .'count deliberately does not carry'
    );
});

it('does not count a column that was empty on every row as something lost', function () {
    /*
     * An always-empty `weight` cost the owner nothing, and counting it would
     * turn the one number he is meant to read into an alarm about data he never
     * had. The column must still be ACCOUNTED FOR though -- see the next test.
     *
     * MUTATION: drop the `if ($value === null) continue;` guard in
     * reportNotCarried() and this reads 18 instead of 2.
     */
    $blank = array_fill_keys([
        'date_modified', 'product_visibility', 'backorders', 'low_stock_amount', 'weight', 'length',
        'width', 'height', 'tax_status', 'tax_class', 'shipping_class', 'purchase_note', 'upsell_ids',
        'cross_sell_ids', 'grouped_ids', 'attribute_summary', 'sold_individually', 'reviews_enabled',
    ], '');

    $report = pxImport(pxExport([pxRow($blank)]));

    // `virtual` and `downloadable` are the two the exporter always writes as
    // yes/no rather than leaving empty, so they are the two real drops here.
    expect($report->for('products')->droppedFieldNames())->toBe(['downloadable', 'virtual']);
});

it('leaves no product column in the consolidated "nothing reads this" line', function () {
    /*
     * The runner's backstop names every column no importer field asked for. Once
     * ProductImporter declares all nineteen of the ones it does not write, that
     * line should have nothing left to say about products -- otherwise the owner
     * is told about the same column twice, once vaguely.
     *
     * MUTATION: remove the reportCarriedElsewhere() call and the line comes back
     * naming `tag_term_ids`.
     */
    $report = pxImport(pxExport([pxRow()]));

    expect(pxConsolidated($report, 'products'))->toBeNull(
        'a product column is still unaccounted for. Every column of products.csv is either written to a '
        .'table, declared in ProductImporter::NOT_CARRIED, or read and noted as arriving elsewhere.'
    );
});

it('does not report the tag column as a loss, because the tags arrive from tags.csv', function () {
    /*
     * THE FALSE ALARM THIS FIXES. `tag_term_ids` sat in the consolidated discard
     * list beside `weight`, and they are not the same kind of fact: TagImporter
     * writes product_tag from the TAG side out of tags.csv `product_ids`, so the
     * membership arrives in full whether or not this column is ever read. The
     * owner was being shown "you are losing your tags" and he was not.
     *
     * A discard list with false alarms in it is one nobody finishes reading,
     * which is the whole reason the channel exists.
     *
     * MUTATION: move 'tag_term_ids' into NOT_CARRIED and this is red twice --
     * the count goes to 19 and the field gets a discard of its own.
     */
    $report = pxImport(pxExport([pxRow()]));
    $products = $report->for('products');

    /*
     * in_array() rather than ->not->toContain() on the array, for the reason
     * GqMigrationCensusTest gives about the same family of matchers: they are
     * variadic, and a negated variadic matcher is the idiom this repository has
     * already been bitten by. An explicit boolean cannot be read two ways.
     */
    expect(pxDiscardFor($report, 'tag_term_ids'))->toBe([])
        ->and(in_array('tag_term_ids', $products->droppedFieldNames(), true))->toBeFalse();

    $notes = implode("\n", array_keys($products->notes()));

    expect($notes)->toContain('tag_term_ids is not read from the product row and nothing is lost by that');

    // And the pivot really did arrive, which is what makes the note true rather
    // than merely reassuring.
    $product = Product::query()->where('wc_id', 4021)->firstOrFail();

    expect(DB::table('product_tag')->where('product_id', $product->id)->count())->toBeGreaterThan(
        0,
        'the note says the tags arrive from tags.csv. They did not, so the note is a lie and '
        .'tag_term_ids IS a loss after all.'
    );
});

/* ══════════════════════════════════════ the list that truncated itself */

it('keeps the whole consolidated column list, however wide the export is', function () {
    /*
     * ── A REAL DEFECT, AND IT ATE `weight` ──────────────────────────────────
     *
     * EntityReport::collect() put every before/after value through excerpt(),
     * which cuts at EXCERPT_LENGTH (600) and appends "...". The consolidated
     * ignored-column line is the ONE sample in the whole report that is a LIST
     * rather than a value, and EXCERPT_LENGTH's own docblock claimed 600 was
     * "long enough to hold the whole ignored-column list for a wide WooCommerce
     * export ... the names at the end are exactly the ones nothing else in this
     * report mentions".
     *
     * It was long enough for the FIXTURE, where most of those columns are empty
     * and the line comes out at 511 characters. On one realistic product row it
     * came out at exactly 600 with "..." on the end, and what fell off was
     * `virtual`, `weight` and `width` -- the list whose entire job is to name
     * what the migration loses was losing `weight` from itself, alphabetically,
     * with no indication that the missing names were the expensive ones.
     *
     * A length limit cannot be the mechanism that keeps a list whole, so the
     * list no longer goes through one.
     *
     * MUTATION: change discardedList() back to discarded() in
     * ImportRunner::reportIgnoredColumns() and this is red -- the line ends in
     * "..." and the last names are gone.
     */
    $meta = [];

    // A Woo export is mostly plugin meta, and this is the shape of it: names
    // nothing in this application has ever heard of, each with a real value.
    foreach ([
        'gift_message' => 'Happy birthday, from all of us at the office',
        'delivery_instructions' => 'Ring the bell twice and leave it with the concierge',
        'wcj_product_input_field' => 'Engrave: Ayesha',
        'yith_wcwl_count' => '38',
        'subscription_period' => 'month',
        'subscription_price' => '129.00',
        'min_quantity' => '2',
        'max_quantity' => '12',
        'wc_points_earned' => '340',
        'wc_deposit_amount' => '75.00',
        'woodmart_total_stock_quantity' => '120',
        'wpcf_country_of_manufacture' => 'Republic of Korea',
        'wpcf_expiry_period_months' => '36',
        'rank_math_pillar_content' => 'off',
        'brand_warranty_note' => 'Twelve months against manufacturing defects only',
        'bundle_contents_summary' => 'Toner pad 70ea, ampoule 30ml, cream 50ml, cleanser 120ml',
    ] as $name => $value) {
        $meta['meta_'.$name] = $value;
    }

    $report = pxImport(pxExport([pxRow()], $meta));
    $line = pxConsolidated($report, 'products');

    expect($line)->not->toBeNull('the unknown meta columns should have produced a consolidated line');

    expect(mb_strlen($line['before']))->toBeGreaterThan(
        EntityReport::EXCERPT_LENGTH,
        'this fixture is meant to be wider than the excerpt limit, or it is not testing anything'
    );

    expect(str_ends_with($line['before'], '...'))->toBeFalse(
        'the column list was truncated. "..." reads as "and some more of the same" when it means "and the '
        .'ones you most need" -- the names at the end are the only mention those columns get anywhere.'
    );

    foreach (array_keys($meta) as $column) {
        expect(str_contains($line['before'], $column))->toBeTrue(
            $column.' fell off the end of the list that exists to name it'
        );
    }
});

/* ══════════════════════════════════════════ the reconciliation sentence */

it('ends with a sentence that counts the rows AND the fields', function () {
    /*
     * "WooCommerce said N products, N arrived, 0 refused, 18 fields skipped."
     * Every number before the field clause counts ROWS, and the identity they
     * rest on is `accounted + refused = read`. The field count does not belong
     * to that identity, so it says so in its own words rather than sitting in
     * the list looking as though it adds up with them.
     *
     * MUTATION: delete the droppedFieldCount() clause in verification() and the
     * second expectation is red -- the sentence reports a flawless import of a
     * catalogue that lost eighteen fields out of every product.
     */
    $report = pxImport(pxExport([pxRow()]));
    $sentence = $report->for('products')->verification()['sentence'];

    expect($sentence)->toContain('1 read, 1 accounted for, 0 refused')
        ->and($sentence)->toContain('20 fields skipped')
        ->and($sentence)->toContain('not rows');
});

it('says nothing about skipped fields when nothing was skipped', function () {
    /*
     * Rule 1: nothing that already works may change. An entity that carries
     * every column it was given must read exactly as it read before the clause
     * existed, byte for byte -- categories.csv is such a file.
     */
    $report = pxImport(pxExport([pxRow()]));
    $sentence = $report->for('categories')->verification()['sentence'];

    expect($report->for('categories')->droppedFieldCount())->toBe(0)
        ->and($sentence)->not->toContain('skipped');
});

/* ══════════════════════════════════════════════ running it twice */

it('imports the same export twice and leaves every row and every pivot count identical', function () {
    /*
     * `wc_id` is the identity and every write is an upsert on it. The pivots are
     * DIFFED -- read the existing set, insert the additions, delete the removals
     * -- and not blindly re-inserted, which is what makes a second pass report
     * "unchanged" instead of churning `updated_at` across the catalogue.
     *
     * A duplicate on re-run is the most expensive failure mode in a migration
     * the owner will legitimately run more than once: he will re-export after
     * fixing something, and a second copy of 671 products is not something a row
     * count makes obvious afterwards.
     *
     * MUTATION: replace syncCategories()'s diff with a plain insert and the
     * pivot count doubles; drop the `where('wc_id', $wcId)` lookup and the
     * product count does.
     */
    $dir = pxExport([
        pxRow(),
        pxRow(['id' => '4022', 'slug' => 'second-set', 'sku' => 'MED-KOJIC-SET-2', 'category_term_ids' => '15']),
    ]);

    $first = pxImport($dir);

    $snapshot = static fn (): array => [
        'products' => Product::query()->withTrashed()->count(),
        'category_product' => DB::table('category_product')->count(),
        'product_tag' => DB::table('product_tag')->count(),
        'product_attribute_value' => DB::table('product_attribute_value')->count(),
        'variants' => DB::table('product_variants')->count(),
        'rows' => Product::query()->withTrashed()->orderBy('wc_id')
            ->get(['wc_id', 'slug', 'sku', 'price', 'sale_price', 'status', 'category_id', 'brand_id'])
            ->map->toArray()->all(),
    ];

    $after = $snapshot();

    // A fresh runner, the way a second pass really happens: restart so the
    // checkpoint does not simply skip every row it already committed, which
    // would prove nothing about the writes being idempotent.
    $second = pxImport($dir, ['restart' => true]);

    expect($snapshot())->toBe(
        $after,
        'the second pass changed the database. Every write is an upsert on wc_id and every pivot is '
        .'diffed, so running the same export twice has to be a no-op.'
    );

    // And it must SAY it did nothing, or the evidence is only that the numbers
    // happen to agree.
    expect($second->for('products')->created)->toBe(
        0,
        'the second pass created rows. The first pass already had them; they were matched on something '
        .'other than wc_id, or not matched at all.'
    );

    /*
     * ── AND IT MUST REFUSE NOTHING AND REPORT EVERY ROW UNCHANGED ───────────
     *
     * A COUNT COMPARISON ALONE IS NOT ENOUGH, and this lane found that out by
     * mutating the thing the test was supposedly about. Replace syncCategories()'
     * diff with a plain insert and the row counts still match on the second pass
     * -- because `category_product` is a composite primary key, so re-inserting a
     * pair RAISES rather than duplicating. The runner catches that as "the
     * database refused this row", the product is rejected, and a snapshot of
     * counts plus `created === 0` is perfectly happy.
     *
     * That is the worse failure of the two. A duplicate is visible; a second
     * pass that silently refuses all 671 rows looks like a clean no-op from
     * every number above, and the owner's re-export after fixing something
     * quietly does nothing at all.
     *
     * "Unchanged on the second pass" is the only evidence this importer offers
     * that it did the same thing twice -- ProductImporter says so itself, in the
     * note on why the report has to be idempotent -- so it is asserted, not
     * inferred.
     */
    expect($second->for('products')->rejectedCount())->toBe(
        0,
        'the second pass REFUSED rows. Re-running an import has to be a no-op, not a run that reports '
        .'nothing created because it could not write anything: '
        .implode(' | ', array_column($second->for('products')->rejections(), 'reason'))
    );

    expect([
        'unchanged' => $second->for('products')->unchanged,
        'updated' => $second->for('products')->updated,
    ])->toBe(
        ['unchanged' => 2, 'updated' => 0],
        'the second pass did not report both products as unchanged. Either a write is not an upsert on '
        .'wc_id, or a pivot was churned -- ProductImporter promotes an unchanged row to "updated" when its '
        .'category membership moved, which is exactly the churn a diffed pivot exists to prevent.'
    );

    expect($first->for('products')->created)->toBe(2);

    // The drop report is idempotent too, for the same reason the writes are: a
    // count that depends on how many times the import was run is a count the
    // owner cannot act on.
    expect($second->for('products')->droppedFieldCount())
        ->toBe($first->for('products')->droppedFieldCount());
});

/* ══════════════════════════════════════════════ what this lane did NOT add */

it('adds no column to products, so the public API allowlist is unchanged', function () {
    /*
     * /api/* IS UNAUTHENTICATED and Product::toApi() is the allowlist that keeps
     * `wc_id`, `sku` and `total_sales` off it. This lane reports what is dropped
     * and carries nothing new, so the allowlist has nothing to review -- and
     * this test is what makes that claim checkable rather than asserted.
     *
     * It is also the guard for the NEXT person: whoever carries `weight` or
     * `tax_class` after the owner decides has to come here, which is where they
     * will read that the decision includes what the public endpoint may publish.
     * `tax_class` and a supplier's parcel dimensions are not shopper business.
     */
    $columns = Schema::getColumnListing('products');
    sort($columns);

    expect($columns)->toBe([
        'brand_id', 'category_id', 'created_at', 'custom_tabs', 'deleted_at', 'description', 'featured',
        'gtin', 'how_to_use', 'id', 'image', 'image_alts', 'images', 'ingredients', 'is_visible',
        'manage_stock', 'meta_feed', 'name', 'position', 'price', 'published_at', 'rating',
        'review_count', 'routine_concerns', 'routine_role', 'sale_ends_at', 'sale_price',
        'sale_starts_at', 'seo', 'seo_json', 'short_description', 'sku', 'slug', 'status', 'stock',
        'stock_status', 'total_sales', 'type', 'updated_at', 'wc_id',
    ], 'a column was added to `products`. Decide what Product::toApi() publishes BEFORE adding it, and '
        .'extend tests/Feature/ApiSecurityTest.php -- every case pinned there leaked in production first.');

    // Every field this lane reports as dropped is a field with no column, which
    // is the reason it is reported rather than carried. Said out loud so the two
    // halves cannot drift: an entry added to NOT_CARRIED for a field that DOES
    // land would report a loss that is not one.
    $reflection = new ReflectionClass(ProductImporter::class);
    $notCarried = array_keys($reflection->getConstant('NOT_CARRIED'));

    expect(array_values(array_intersect($notCarried, $columns)))->toBe(
        [],
        'a field declared as not carried has a column of the same name on `products`. Either it lands and '
        .'the declaration is a false alarm, or the column is unused and the name is a coincidence -- both '
        .'need looking at.'
    );

    expect($notCarried)->toHaveCount(20);
});

/* ═════════════════════════════════════ live progress, and the last sentence */

/**
 * Put a real export into the import workspace, so the driver and the progress
 * page see it exactly as they would after an upload.
 */
function pxWorkspace(string $from): ImportWorkspace
{
    $workspace = new ImportWorkspace;
    $directory = $workspace->directory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    foreach (glob($from.'/*') ?: [] as $file) {
        copy($file, $directory.'/'.basename($file));
    }

    return $workspace;
}

/**
 * Step the driver until the run says it is over.
 *
 * step() answers `['ok' => true, 'entity' => ...]` and has no "done" key -- the
 * run's own status is the authority, and a loop written against a key that is
 * not there exits after one step and then asserts against a progress page that
 * has imported one file. That is how the first version of these two tests
 * failed, and it is worth a helper rather than a copied loop.
 */
function pxRunToCompletion(ImportDriver $driver, int $cap = 200): void
{
    $driver->start('live', ['adopt_by_slug' => true, 'force' => true]);

    for ($i = 0; $i < $cap; $i++) {
        $run = $driver->run();

        if ($run === null || $run->status !== 'running') {
            return;
        }

        $driver->step(500);
    }

    throw new RuntimeException('the import did not finish within '.$cap.' steps');
}

it('shows the fields it dropped on the live progress page, per file', function () {
    /*
     * ── THE COLUMN THE PROGRESS PAGE DID NOT HAVE ──────────────────────────
     *
     * Store -> Store Import / Export -> Import progress shows, per file: rows
     * done, of how many, percent, new, changed, same, refused. Every one of
     * those counts ROWS, and an import can finish every row and lose a column
     * out of each of them -- the bar reaches 100%, nothing is refused, and the
     * parcel weights are gone.
     *
     * NAMES AND NOT A COUNT IN THE CHECKPOINT, because a background import is
     * many requests over one file and each reports the fields it saw: adding
     * them would count `weight` once per batch. Unioned, so it is idempotent
     * the same way every other number in that table is.
     *
     * MUTATION: pass [] instead of $report->droppedFieldNames() from
     * ImportRunner's advance() call and this is red -- the page shows a dash.
     */
    $driver = new ImportDriver;
    pxWorkspace(pxExport([pxRow()]));

    pxRunToCompletion($driver);

    $progress = (new ImportChain)->progress();

    $products = collect($progress['entities'])->firstWhere('entity', 'products');

    expect($products)->not->toBeNull()
        ->and($products['dropped_fields'])->toContain('weight')
        ->and($products['dropped_fields'])->toContain('tax_class')
        ->and($products['dropped_fields'])->toContain('sold_individually')
        ->and($products['dropped_fields'])->toHaveCount(20);

    // And the entities that lose nothing say so by having nothing, rather than
    // by being absent -- a dash on the page, not a blank.
    $categories = collect($progress['entities'])->firstWhere('entity', 'categories');

    expect($categories['dropped_fields'])->toBe([]);
});

it('ends the progress page with a reconciliation the owner can read', function () {
    /*
     * "WooCommerce said N rows across 18 files, N arrived, 0 refused, and 20
     * fields skipped (...). Every row is accounted for."
     *
     * The brief calls this "the cheapest possible proof of the whole migration"
     * and it is: one sentence, assembled from the checkpoints and the row counts
     * in the files, with no importer's account of its own work anywhere in it.
     *
     * MUTATION: return `['ready' => false, ...]` unconditionally from
     * ImportChain::reconciliation() and the `ready` expectation is red.
     */
    $driver = new ImportDriver;
    pxWorkspace(pxExport([pxRow()]));

    pxRunToCompletion($driver);

    $reconciliation = (new ImportChain)->progress()['reconciliation'];

    expect($reconciliation['ready'])->toBeTrue(
        'the run finished and the page still will not conclude: '.$reconciliation['sentence']
    );

    expect($reconciliation['sentence'])
        ->toContain('WooCommerce said')
        ->and($reconciliation['sentence'])->toContain('arrived')
        ->and($reconciliation['sentence'])->toContain('refused')
        ->and($reconciliation['sentence'])->toContain('20 fields skipped');

    expect($reconciliation['fields'])->toContain('weight');

    // It must not say "everything arrived" when something did not.
    expect($reconciliation['sentence'])->toContain('Every row is accounted for.');
});

it('refuses to conclude while the import is still going', function () {
    /*
     * A green "everything arrived" printed over a half-finished import is the
     * most expensive sentence this screen could produce, and it is the exact
     * shape of the silent half-success the whole background page was designed
     * against ("a bar frozen at a stale number rendered identically to a live
     * one"). So the sentence exists mid-run, says what it knows, and says it is
     * a slice.
     *
     * MUTATION: drop the `! $allFinished` branch and this reads ready => true
     * one step into a multi-step run.
     */
    $driver = new ImportDriver;
    pxWorkspace(pxExport([pxRow(), pxRow(['id' => '4022', 'slug' => 's2', 'sku' => 'S2'])]));

    $driver->start('live', ['adopt_by_slug' => true, 'force' => true]);
    $driver->step(1);   // one row, so nothing can possibly be finished

    $reconciliation = (new ImportChain)->progress()['reconciliation'];

    expect($reconciliation['ready'])->toBeFalse()
        ->and($reconciliation['sentence'])->toContain('still going')
        ->and($reconciliation['sentence'])->toContain('a slice and not the answer');

    expect($reconciliation['sentence'])->not->toContain('Every row is accounted for');
});

it('remembers a dropped field a LATER slice of the same file did not see', function () {
    /*
     * ── WHY THE CHECKPOINT UNIONS INSTEAD OF OVERWRITING ────────────────────
     *
     * A background import is many HTTP requests over one file, and each one
     * builds a fresh ImportReport that knows only about the rows IT read. So the
     * set of fields a slice reports is not a fact about the file -- it is a fact
     * about that slice.
     *
     * Two products, and the fields are deliberately DISJOINT: the first carries
     * a weight and no purchase note, the second a purchase note and no weight.
     * Imported one row per slice, exactly as the server does it. If the column
     * were overwritten by the last slice's set, the finished page would say the
     * import skipped `purchase_note` and say nothing about `weight` -- and
     * `weight` is the one the owner most needs.
     *
     * THIS IS THE MUTATION THE PREVIOUS TEST COULD NOT CATCH, and it is worth
     * saying why: ImportRunner hands advance() the CUMULATIVE set rather than a
     * delta, so within one process overwriting and unioning are the same thing.
     * The difference only appears across processes, which is the case that
     * actually runs on the owner's shop.
     *
     * MUTATION: replace the union in Checkpoint::mergeDroppedFields() with
     * `array_unique($fields)` and this is red on `weight`.
     */
    $blankWeight = ['weight' => '', 'length' => '', 'width' => '', 'height' => ''];

    pxWorkspace(pxExport([
        pxRow(['purchase_note' => '']),
        pxRow(['id' => '4022', 'slug' => 's2', 'sku' => 'S2'] + $blankWeight),
    ]));

    $driver = new ImportDriver;
    $driver->start('live', ['adopt_by_slug' => true, 'force' => true]);

    // One row per slice, so the two products are read by two separate runs.
    for ($i = 0; $i < 200; $i++) {
        $run = $driver->run();

        if ($run === null || $run->status !== 'running') {
            break;
        }

        $driver->step(1);
    }

    $products = collect((new ImportChain)->progress()['entities'])->firstWhere('entity', 'products');

    /*
     * in_array() AND NOT ->toContain($needle, $message), for the reason spelled
     * out further up this file -- toContain is variadic and reads the message as
     * a second needle. Written the wrong way once here too, which is the best
     * argument for the note: the idiom looks right.
     */
    expect(in_array('weight', $products['dropped_fields'], true))->toBeTrue(
        'weight was reported by the FIRST slice and is not on the finished page. A later slice whose rows '
        .'happened to have an empty weight overwrote the record of it.'
    );

    expect(in_array('purchase_note', $products['dropped_fields'], true))->toBeTrue(
        'purchase_note was only in the second product, so the union has to pick it up as well as keep '
        .'what the first slice found'
    );
});

it('calls the tag column a loss when this run has no tags.csv to carry it', function () {
    /*
     * ── REASSURANCE THAT CHECKS ITS OWN PREMISE ─────────────────────────────
     *
     * "Nothing is lost, the tags arrive from tags.csv" is a statement about a
     * DIFFERENT FILE, and it is false for an export folder that has no tags.csv
     * in it at all. There the membership arrives from nowhere and
     * `tag_term_ids` really is a loss.
     *
     * The premise is deliberately the FILE and not "is this slice about to read
     * it": ImportDriver steps one entity at a time, so a check on the run's
     * `only` list answers false during the products step of every ordinary
     * background run and would report the whole catalogue as losing its tags.
     *
     * A reassurance that does not check its premise is worse than no
     * reassurance, because it is the sentence that stops the owner looking.
     *
     * MUTATION: drop the `$tagsAreComing` check and report the note
     * unconditionally. This goes red -- the run says nothing was lost while the
     * product has no tags at all.
     */
    $dir = pxExport([pxRow()]);
    unlink($dir.'/tags.csv');

    $report = pxImport($dir);
    $products = $report->for('products');

    expect(in_array('tag_term_ids', $products->droppedFieldNames(), true))->toBeTrue(
        'this run had no tags.csv, so the product tags reached no table and the report should say so'
    );

    $discards = pxDiscardFor($report, 'tag_term_ids');

    expect($discards)->toHaveCount(1)
        ->and($discards[0]['samples'][0]['before'])->toBe('601');

    expect(str_contains($discards[0]['kind'], 'this run has no tags.csv'))->toBeTrue(
        'the discard should say WHY it is a loss in this run when it is not one in general'
    );

    // And the false reassurance must not also be present.
    expect(implode("\n", array_keys($products->notes())))
        ->not->toContain('nothing is lost by that');

    // The pivot really is empty, which is what makes the report true.
    $product = Product::query()->where('wc_id', 4021)->firstOrFail();

    expect(DB::table('product_tag')->where('product_id', $product->id)->count())->toBe(0);
});
