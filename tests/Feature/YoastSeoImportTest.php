<?php

declare(strict_types=1);

/**
 * Lane EI — Yoast's `_yoast_wpseo_*` post-meta into `products.seo`.
 *
 * ── WHAT WAS THERE BEFORE ───────────────────────────────────────────────────
 *
 * Nothing. The `seo` column has carried the comment "Yoast import target" since
 * the Phase 0 schema (0001_01_01_000000_create_kbb_schema) and
 * Store\ProductController says in as many words that the importer was "not
 * built yet". The column has since acquired a reader (the storefront), a writer
 * (the product editor) and a shape (App\Support\ProductSeo). This is the third
 * thing it was always going to need.
 *
 * ── THE POSTURE, MATCHED TO THE WOOCOMMERCE IMPORT ──────────────────────────
 *
 * It is an entity on the EXISTING runner, not an importer of its own, so it
 * inherits batching, one transaction per batch, the checkpoint, the dry run and
 * the rejects CSV rather than growing second versions of them. The field table
 * — five keys mapped, nineteen deliberately not, and why for each — is in
 * App\Support\YoastSeo and is not repeated here or anywhere else.
 *
 * ── WHAT THIS PINS ──────────────────────────────────────────────────────────
 *
 * The three rules a Yoast import gets wrong, each of which is silent and each
 * of which damages a live catalogue:
 *
 *   1. `meta-robots-noindex` is TRISTATE. '1' is noindex, '2' is "index", '' is
 *      "use the default". Reading it as a boolean deindexes a shop.
 *   2. A BLANK FIELD IS NOT A VALUE. Most products in a real Woo export have
 *      never had their SEO tab opened, so `_yoast_wpseo_metadesc` is ''. Stored
 *      as '', App\Support\Seo emits NO description tag — it does not fall back
 *      to the sitewide default — so importing blanks strips the search snippet
 *      off the catalogue.
 *   3. THE OWNER'S TYPING WINS. The export predates this admin.
 *
 * Plus the two properties the import lane holds everything to: idempotent, and
 * dry-runnable.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Services\Import\Entities\SeoImporter;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Support\ProductSeo;
use App\Support\YoastSeo;
use Illuminate\Support\Str;

function ysContext(array $options = []): ImportContext
{
    return new ImportContext(
        new ImportOptions(...array_merge(['directory' => sys_get_temp_dir()], $options)),
        new ImportReport((bool) ($options['dryRun'] ?? false)),
    );
}

function ysProduct(int $wcId, array $attributes = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'ys-anua'], ['name' => 'Anua']);

    return Product::create(array_merge([
        'wc_id' => $wcId,
        'slug' => 'ys-'.Str::random(10),
        'name' => 'Heartleaf Quercetinol Toner',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ], $attributes));
}

/** One export row through the importer, returning the outcome tally. */
function ysImport(array $cells, ?ImportContext $context = null, int $line = 2): ImportContext
{
    $context ??= ysContext();

    (new SeoImporter)->import(new Row($line, $cells), $context);

    return $context;
}

/** A realistic export row: the id column plus whatever Yoast columns are given. */
function ysRow(int $wcId, array $yoast = []): array
{
    return array_merge(['id' => (string) $wcId], $yoast);
}

/*
|------------------------------------------------------------------------------
| 1. The five mapped fields land where the storefront reads them
|------------------------------------------------------------------------------
*/

it('maps every field it claims to map', function () {
    $product = ysProduct(4101);

    ysImport(ysRow(4101, [
        '_yoast_wpseo_title' => 'Anua Heartleaf Toner %%sep%% %%sitename%%',
        '_yoast_wpseo_metadesc' => 'A daily toner for calm, even-looking skin.',
        '_yoast_wpseo_canonical' => 'https://kbeautybliss.com/product/anua-heartleaf-toner/',
        '_yoast_wpseo_opengraph-image' => 'https://kbeautybliss.com/wp-content/uploads/toner.jpg',
        '_yoast_wpseo_meta-robots-noindex' => '1',
    ]));

    $seo = $product->fresh()->seo;

    $expected = [
        'title' => 'Anua Heartleaf Toner %%sep%% %%sitename%%',
        'desc' => 'A daily toner for calm, even-looking skin.',
        'canonical' => 'https://kbeautybliss.com/product/anua-heartleaf-toner/',
        'og_image' => 'https://kbeautybliss.com/wp-content/uploads/toner.jpg',
        'noindex' => true,
    ];

    /*
     * NOT toBe(). `seo` is a json column and MySQL does not store the bytes it
     * was given: it parses the object and REORDERS the keys, by key length
     * first and then alphabetically — so this row reads back as
     * desc, title, noindex, og_image, canonical. toBe() is `===`, which on
     * arrays is order-sensitive, so a literal written in any order at all is
     * green on SQLite and red on MySQL. This exact divergence is already
     * written up in ImportContext::withoutEquivalentJson, which exists because
     * of it.
     *
     * The keys and the values are the assertion; their order is the database's
     * business and is not something this importer controls or should pin.
     */
    expect(array_keys($seo))->toEqualCanonicalizing(array_keys($expected));

    foreach ($expected as $key => $value) {
        expect($seo[$key])->toBe($value);
    }
});

it('writes only keys the storefront actually reads', function () {
    // The other half of "every setting has a reader". A key this importer
    // invented would sit in the column forever, doing nothing, and look like a
    // feature to the next person who found it.
    expect(array_values(YoastSeo::WRITES))
        ->toEqualCanonicalizing(ProductSeo::PUBLISHED_KEYS);

    expect(array_values(YoastSeo::MAPPED))
        ->toEqualCanonicalizing(ProductSeo::PUBLISHED_KEYS);
});

it('carries a Yoast token across rather than expanding it', function () {
    /*
     * `%%sitename%%` resolves at RENDER time through
     * App\Services\Seo\TitleTemplate, which handles that syntax precisely
     * because the per-product panel emits it. Expanding it here would freeze
     * this store's current name into every row it touched.
     */
    $product = ysProduct(4102);

    ysImport(ysRow(4102, ['_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%']));

    expect($product->fresh()->seo['title'])->toBe('%%title%% %%sep%% %%sitename%%');
});

it('accepts the three column spellings real exports use', function () {
    // A `wp db export` of postmeta keeps `_yoast_wpseo_metadesc`; the CSV
    // exporters most stores use drop the underscore or the prefix entirely.
    // Matching one spelling is the difference between an import that works on
    // the owner's file and one that reports 671 rows and writes nothing.
    foreach ([
        '_yoast_wpseo_metadesc',
        'yoast_wpseo_metadesc',
        'metadesc',
    ] as $i => $column) {
        $wcId = 4200 + $i;
        $product = ysProduct($wcId);

        ysImport(ysRow($wcId, [$column => 'Spelling '.$i]));

        expect($product->fresh()->seo['desc'])->toBe('Spelling '.$i, 'column: '.$column);
    }
});

/*
|------------------------------------------------------------------------------
| 2. Rule 1 — noindex is tristate
|------------------------------------------------------------------------------
*/

it('treats only an explicit 1 as noindex', function () {
    $product = ysProduct(4301);

    ysImport(ysRow(4301, ['_yoast_wpseo_meta-robots-noindex' => '1']));

    expect($product->fresh()->seo['noindex'])->toBeTrue();
});

it('does not deindex a product Yoast marked "index"', function () {
    /*
     * '2' is Yoast's "index this, overriding the site default". This schema has
     * no way to say that and does not need one — indexing is the default — so
     * it writes NOTHING. A boolean reading of this column would have set
     * noindex on every product whose author had ticked "index".
     */
    $product = ysProduct(4302);

    ysImport(ysRow(4302, ['_yoast_wpseo_meta-robots-noindex' => '2']));

    expect($product->fresh()->seo)->toBeNull();
});

it('does not deindex a product whose noindex field is blank', function () {
    // '' is what Yoast writes for "use the site default", which is most rows.
    // '0' turns up in exports from older versions and means the same.
    foreach ([
        ['_yoast_wpseo_meta-robots-noindex' => ''],
        ['_yoast_wpseo_meta-robots-noindex' => '0'],
        ['_yoast_wpseo_metadesc' => 'A toner.', '_yoast_wpseo_meta-robots-noindex' => ''],
    ] as $i => $yoast) {
        $wcId = 4310 + $i;
        $product = ysProduct($wcId);

        ysImport(ysRow($wcId, $yoast));

        // No message argument: toHaveKey()'s second parameter is an expected
        // VALUE, not a message, so passing one here would quietly assert
        // something else entirely. array_key_exists says it plainly instead.
        expect(array_key_exists('noindex', $product->fresh()->seo ?? []))
            ->toBeFalse('case '.$i.' set noindex');
    }
});

/*
|------------------------------------------------------------------------------
| 3. Rule 2 — a blank field is not a value
|------------------------------------------------------------------------------
*/

it('never stores a blank Yoast field as a value', function () {
    /*
     * The damaging one. `''` in `seo.desc` is not "no override" on this
     * storefront: ProductSeo::rawDescription returns it, App\Support\Seo emits
     * no description tag for an empty string, and the product loses its search
     * snippet. Most rows in a real export have blank Yoast fields.
     */
    $product = ysProduct(4401, ['short_description' => 'A daily toner.']);

    ysImport(ysRow(4401, [
        '_yoast_wpseo_title' => '',
        '_yoast_wpseo_metadesc' => '   ',
        '_yoast_wpseo_canonical' => '',
        '_yoast_wpseo_opengraph-image' => '',
    ]));

    expect($product->fresh()->seo)->toBeNull();
});

it('leaves a product with an all-blank row genuinely untouched', function () {
    // And counts it, so the report is honest about how much of the file carried
    // anything at all rather than reporting a write per line.
    $product = ysProduct(4402);

    $context = ysImport(ysRow(4402, ['_yoast_wpseo_metadesc' => '']));

    expect($product->fresh()->seo)->toBeNull()
        ->and($context->report->for('seo')->unchanged)->toBe(1)
        ->and($context->report->for('seo')->created + $context->report->for('seo')->updated)->toBe(0);
});

/*
|------------------------------------------------------------------------------
| 4. Rule 3 — the owner's typing wins
|------------------------------------------------------------------------------
*/

it('does not overwrite a description the owner has typed here', function () {
    $product = ysProduct(4501, ['seo' => ['desc' => 'Written in this admin, after the export.']]);

    ysImport(ysRow(4501, ['_yoast_wpseo_metadesc' => 'The older WordPress text.']));

    expect($product->fresh()->seo['desc'])->toBe('Written in this admin, after the export.');
});

it('still fills the keys the owner has not touched', function () {
    // Per KEY, not per row: a product with a hand-written description still
    // gets its canonical and its title from the export.
    $product = ysProduct(4502, ['seo' => ['desc' => 'Mine.']]);

    ysImport(ysRow(4502, [
        '_yoast_wpseo_metadesc' => 'Theirs.',
        '_yoast_wpseo_title' => 'Anua Heartleaf Toner',
    ]));

    // Order-insensitive, for the reason spelled out in the mapping test above:
    // MySQL reorders a json object's keys on the way in.
    $seo = $product->fresh()->seo;

    expect(array_keys($seo))->toEqualCanonicalizing(['desc', 'title'])
        ->and($seo['desc'])->toBe('Mine.')
        ->and($seo['title'])->toBe('Anua Heartleaf Toner');
});

it('can be asked to let the export win, and says so in the method that does it', function () {
    // Built and not wired: turning it on needs a field on ImportOptions, which
    // is the import lane's file. Pinned here so the behaviour exists and is
    // correct the moment the integrator adds it.
    expect(YoastSeo::merge(['desc' => 'Mine.'], ['desc' => 'Theirs.'], overwrite: true))
        ->toBe(['desc' => 'Theirs.']);

    expect(YoastSeo::merge(['desc' => 'Mine.'], ['desc' => 'Theirs.'], overwrite: false))
        ->toBe(['desc' => 'Mine.']);
});

/*
|------------------------------------------------------------------------------
| 5. Idempotent, and it refuses what it cannot do
|------------------------------------------------------------------------------
*/

it('reports every row unchanged on a second pass', function () {
    /*
     * The only evidence an import is idempotent that the importer cannot fake:
     * "unchanged" comes from Eloquent's dirty check against the row as the
     * database has it. It matters more here than elsewhere because `seo` is a
     * json column and MySQL reorders object keys on the way in — which is
     * exactly what the parity run is for.
     */
    $product = ysProduct(4601);

    $row = ysRow(4601, [
        '_yoast_wpseo_title' => 'Anua Heartleaf Toner',
        '_yoast_wpseo_metadesc' => 'A daily toner for calm, even-looking skin.',
        '_yoast_wpseo_meta-robots-noindex' => '1',
    ]);

    $first = ysImport($row);

    expect($first->report->for('seo')->updated)->toBe(1);

    $before = $product->fresh()->seo;

    $second = ysImport($row);

    expect($second->report->for('seo')->unchanged)->toBe(1)
        ->and($second->report->for('seo')->updated)->toBe(0)
        ->and($product->fresh()->seo)->toBe($before);
});

it('refuses a row naming a product this catalogue does not have', function () {
    // Not a create. A product conjured out of its own meta data would have no
    // name, no price and no images.
    expect(fn () => ysImport(ysRow(999001, ['_yoast_wpseo_metadesc' => 'Orphan.'])))
        ->toThrow(RowRejected::class);

    expect(Product::where('wc_id', 999001)->exists())->toBeFalse();
});

it('names the missing id in the refusal', function () {
    // "The message IS the report line" — RowRejected's own header. An owner
    // reading the rejects CSV has to be able to act on it.
    try {
        ysImport(ysRow(999002, ['_yoast_wpseo_metadesc' => 'Orphan.']));
        expect(false)->toBeTrue('the row was accepted');
    } catch (RowRejected $e) {
        expect($e->getMessage())->toContain('999002');
    }
});

it('refuses a file that carries no Yoast column at all', function () {
    // Most likely the products export handed to --files=seo by mistake, which
    // would otherwise run to completion and write nothing.
    ysProduct(4701);

    expect(fn () => ysImport(ysRow(4701, ['name' => 'Heartleaf Toner', 'price' => '99'])))
        ->toThrow(RowRejected::class);
});

it('refuses a row with no id it can match on', function () {
    expect(fn () => ysImport(['_yoast_wpseo_metadesc' => 'Nobody in particular.']))
        ->toThrow(RowRejected::class);
});

/*
|------------------------------------------------------------------------------
| 6. The fields it does not keep, it says it did not keep
|------------------------------------------------------------------------------
*/

it('reports an unmapped field it saw rather than losing it silently', function () {
    $product = ysProduct(4801);

    $context = ysImport(ysRow(4801, [
        '_yoast_wpseo_focuskw' => 'korean toner',
        '_yoast_wpseo_metadesc' => 'A daily toner.',
    ]));

    // EntityReport keys notes by their text, so this is note => count.
    expect(implode("\n", array_keys($context->report->for('seo')->notes())))
        ->toContain('_yoast_wpseo_focuskw');

    // And it really did not keep it. A focus keyphrase nothing analyses is the
    // defect this codebase has spent twenty packages removing — so it is
    // reported and dropped, not stored where it would look like a feature.
    $seo = $product->fresh()->seo ?? [];

    expect(array_key_exists('focuskw', $seo))->toBeFalse()
        ->and(array_key_exists('focus_keyphrase', $seo))->toBeFalse()
        ->and($seo['desc'])->toBe('A daily toner.');
});

it('says it as one line with a count, not as one line per row', function () {
    // 671 identical note lines is a report nobody reads. EntityReport already
    // solves this by keying on the note text and counting — its own header says
    // these are the cases that recur across thousands of rows — so the importer
    // must NOT de-duplicate first, or the count would always be 1 and the owner
    // would not learn how many products had a keyphrase.
    $importer = new SeoImporter;
    $context = ysContext();

    foreach ([4901, 4902, 4903] as $wcId) {
        ysProduct($wcId);
        $importer->import(new Row(2, ysRow($wcId, ['_yoast_wpseo_focuskw' => 'korean toner'])), $context);
    }

    $notes = $context->report->for('seo')->notes();

    $keyphrase = array_filter(
        $notes,
        static fn (string $n): bool => str_contains($n, '_yoast_wpseo_focuskw'),
        ARRAY_FILTER_USE_KEY
    );

    expect($keyphrase)->toHaveCount(1)
        ->and(array_values($keyphrase)[0])->toBe(3);
});

it('lists no field in both the mapped and the unmapped table', function () {
    // The two tables are the whole documentation of this importer. A key in
    // both would make them contradict each other.
    expect(array_intersect(array_keys(YoastSeo::MAPPED), YoastSeo::UNMAPPED))->toBe([]);
});

/*
|------------------------------------------------------------------------------
| 7. Dry run, and the wiring that is still the integrator's
|------------------------------------------------------------------------------
*/

it('writes nothing on a dry run', function () {
    /*
     * The runner puts a dry run inside a transaction and throws DryRunComplete
     * to roll it back, so this asserts through the runner rather than through
     * the importer — the importer has no dry-run branch of its own and must not
     * grow one.
     */
    $product = ysProduct(5001);

    $directory = sys_get_temp_dir().'/kbb-yoast-'.bin2hex(random_bytes(6));
    mkdir($directory, 0777, true);

    file_put_contents(
        $directory.'/seo.csv',
        "id,_yoast_wpseo_metadesc\n5001,\"A daily toner for calm skin.\"\n"
    );

    (new ImportRunner)->run(new ImportOptions(
        directory: $directory,
        only: ['seo'],
        dryRun: true,
    ));

    expect($product->fresh()->seo)->toBeNull('a dry run wrote to the database');

    @unlink($directory.'/seo.csv');
    @rmdir($directory);
})->skip(fn (): bool => ! in_array('seo', ImportRunner::entityNames(), true),
    'pending the one-line ImportRunner::entities() edit — see this file\'s footer');

/**
 * THE INTEGRATOR'S EDIT, AND WHERE IT GOES.
 *
 * File: app/Services/Import/ImportRunner.php, in entities().
 *
 * ADD, as the LAST entry in the returned array:
 *
 *     new SeoImporter,
 *
 * and the matching `use App\Services\Import\Entities\SeoImporter;` at the top.
 *
 * LAST IS NOT COSMETIC. Every row is matched on `wc_id`, which ProductImporter
 * is what writes; ordered before it, this entity rejects the entire file. The
 * runner's own header already says the order is a dependency order and not a
 * user choice.
 *
 * WHY THIS LANE DID NOT MAKE THAT EDIT. App\Services\Import belongs to the
 * WooCommerce import lane, which is working in it this cycle, and CLAUDE.md
 * says to say so rather than reach across. Everything else this entity needs
 * already exists on the runner unchanged.
 *
 * The guard below turns itself on the moment the edit lands, and the dry-run
 * case above un-skips with it.
 */
it('is registered on the runner, in an order that lets it find its products', function () {
    $names = ImportRunner::entityNames();

    expect($names)->toContain('seo');

    expect(array_search('seo', $names, true))
        ->toBeGreaterThan(array_search('products', $names, true),
            'seo runs before products, so every row will be rejected for an unknown wc_id');
})->skip(fn (): bool => ! in_array('seo', ImportRunner::entityNames(), true),
    'pending the one-line ImportRunner::entities() edit — see this file\'s footer');
