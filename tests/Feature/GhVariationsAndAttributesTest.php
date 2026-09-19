<?php

/*
 * VARIATIONS, ATTRIBUTES AND TAGS: the three files nothing opened.
 *
 * ============================================================================
 * WHAT THIS FILE IS EVIDENCE OF, AND WHY IT IS SHAPED LIKE Ge WpExporterTest
 * ============================================================================
 *
 * docs/FV-IMPORT-AT-VOLUME.md §11 lists what a clean, complete, full-volume
 * import still leaves at zero. `product_variants` and
 * `product_variant_attribute_value` are named there as "the largest missing
 * entity by revenue": a variable product imported as its parent only, so the
 * shop sells "50ml or 100ml" as one price. `attributes`, `attribute_values`
 * and `product_attribute_value` are what a category filter is built from.
 * `tags` and `product_tag` are simply absent.
 *
 * Lane GE then built a WordPress plugin that writes exactly those three files
 * and proved the other ten import, by feeding the plugin's OWN OUTPUT to the
 * real App\Services\Import\ImportRunner with no test double in the path. That
 * is the strongest evidence available here, so this file extends it rather
 * than inventing a second kind: `tests/Fixtures/kbb-export/` is the same real
 * export, and every round-trip test below runs the same runner `kbb:import`
 * runs.
 *
 * The narrower cases -- a refusal, an axis this schema cannot express, a slug
 * collision -- use `tests/Fixtures/woo/`, which is the deliberately hostile
 * fixture the rest of the import suite is built on.
 *
 * ── ONE ASSERTION SHAPE IS BANNED IN HERE ───────────────────────────────────
 *
 * `expect(...)->not->toContain($needle, $message)`. `toContain` is variadic,
 * so the message is read as a SECOND NEEDLE and the assertion passes
 * vacuously whenever that string is absent -- which it always is. Absence is
 * asserted with array_diff or str_contains throughout.
 */

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Support\Facades\DB;

/** The export Lane GE's plugin wrote. */
function ghExportDir(): string
{
    return base_path('tests/Fixtures/kbb-export');
}

function ghManifest(): array
{
    return json_decode((string) file_get_contents(ghExportDir().'/manifest.json'), true);
}

/**
 * The import, run the way docs/IMPORT-RUNBOOK.md says to run this export:
 * `--timezone` read out of the manifest rather than guessed, because
 * App\Services\Import\DateParser refuses to default it.
 */
function ghImport(array $overrides = []): ImportReport
{
    $options = new ImportOptions(...array_merge([
        'directory' => ghExportDir(),
        'sourceTimezone' => ghManifest()['source']['timezone'],
        // The demo catalogue every install carries holds slugs this export
        // also holds; WooImportTest and GeWpExporterTest pass the same flag
        // for the same reason.
        'adoptBySlug' => true,
    ], $overrides));

    return (new ImportRunner)->run($options);
}

/** The hostile fixture the rest of the import suite runs on. */
function ghWooImport(array $overrides = []): ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => base_path('tests/Fixtures/woo'),
        'sourceTimezone' => 'Asia/Dubai',
        'adoptBySlug' => true,
    ], $overrides)));
}

/** Every rejection the run produced, entity by entity. */
function ghRejections(ImportReport $report): array
{
    $out = [];

    foreach (ImportRunner::entityNames() as $entity) {
        foreach ($report->for($entity)->rejections() as $rejection) {
            $out[] = $entity.' line '.$rejection['line'].' ('.$rejection['id'].'): '.$rejection['reason'];
        }
    }

    return $out;
}

/** The reasons one entity refused rows, as one string. */
function ghReasons(ImportReport $report, string $entity): string
{
    return implode(' || ', array_column($report->for($entity)->rejections(), 'reason'));
}

/** Everything an entity said it adjusted or discarded, as one string. */
function ghSaid(ImportReport $report, string $entity): string
{
    $out = [];

    foreach ([$report->for($entity)->adjustments(), $report->for($entity)->discards()] as $bucket) {
        foreach ($bucket as $kind => $entry) {
            $out[] = $kind;

            foreach ($entry['samples'] ?? [] as $sample) {
                $out[] = implode(' ', array_map('strval', $sample));
            }
        }
    }

    return implode(' || ', $out).' || '.implode(' || ', array_keys($report->for($entity)->notes()));
}

/* ------------------------------------------------- the entities are registered */

it('registers the three entities in an order their dependencies fix, and the screen agrees', function () {
    $names = ImportRunner::entityNames();

    expect($names)->toContain('tags')->toContain('attributes')->toContain('variations');

    $products = array_search('products', $names, true);

    // All three name WordPress product ids that only ProductImporter can
    // translate; a variation additionally cannot exist without its parent,
    // because product_variants.product_id is NOT NULL.
    foreach (['tags', 'attributes', 'variations'] as $entity) {
        expect(array_search($entity, $names, true))
            ->toBeGreaterThan($products, $entity.' is registered before products');
    }

    // And variations after attributes: a variation names the terms defining it
    // by SLUG, and those slugs have to already be attribute_values rows.
    expect(array_search('variations', $names, true))
        ->toBeGreaterThan(array_search('attributes', $names, true));

    // The screen's hand-maintained mirror still agrees with the runner, in
    // order. Registering an entity on one and not the other is what made every
    // upload 500 the last time these two drifted.
    expect(ImportWorkspace::entities())->toBe($names);
});

it('opens the three files that used to be named as opened by nothing', function () {
    $report = ghImport();

    /*
     * ImportRunner::reportUnreadFiles() names every file in the export folder
     * that no entity opens, and its own comment used `variations.csv` and
     * `tags.csv` as the examples of what it would keep doing that for. It must
     * not say it about these three any more.
     *
     * array_diff, not ->not->toContain(): see the header.
     */
    $unread = array_column(
        $report->for('export')->discards()[
            'a file in the export folder that no importer opens -- this application has no entity for it, '
            .'so nothing in it reaches the database and nothing else in this report mentions it'
        ]['samples'] ?? [],
        'field'
    );

    $mine = ['variations.csv', 'attributes.csv', 'tags.csv'];

    expect(array_intersect($mine, $unread))->toBe([], 'still reported as unread: '.implode(', ', $unread));
});

/* --------------------------------------------------------------- the round trip */

it('imports the plugin export cleanly, and lands every count the manifest declares', function () {
    $report = ghImport();

    $rejections = ghRejections($report);

    expect($rejections)->toBe([], 'the plugin export produced rejections: '.implode(' | ', $rejections));

    $manifest = ghManifest();

    expect(Tag::query()->whereNotNull('source_term_id')->count())->toBe($manifest['counts']['tags'])
        ->and(AttributeValue::query()->whereNotNull('source_term_id')->count())
        ->toBe($manifest['counts']['attributes'])
        ->and(ProductVariant::query()->whereNotNull('wc_id')->count())->toBe($manifest['counts']['variations']);
});

it('prices every option to the fil, and keeps the option its own SKU', function () {
    ghImport();

    $cleanser = Product::query()->where('wc_id', 4023)->firstOrFail();

    $variants = ProductVariant::query()
        ->where('product_id', $cleanser->id)
        ->orderBy('wc_id')
        ->get()
        ->keyBy('wc_id');

    // "60.00" and "100.00" in the CSV. Integer fils, and the thing the
    // previous dead importer in this repository got wrong by a factor of a
    // hundred.
    expect($variants[4101]->price)->toBe(6000)
        ->and($variants[4102]->price)->toBe(10000)
        ->and($variants[4101]->sku)->toBe('KBB-4023-50ml')
        ->and($variants[4102]->sku)->toBe('KBB-4023-100ml')
        ->and($variants[4101]->product_id)->toBe($cleanser->id);

    // And the option is labelled from the terms it is pinned to, which is what
    // ProductVariant::label() reads and what the page prints.
    expect($variants[4101]->label())->toBe('50ml')
        ->and($variants[4102]->label())->toBe('100ml');
});

/* ------------------------------------ the columns whose obvious reading is wrong */

it('names the attribute size and not pa_size, and Size and not 100ml', function () {
    ghImport();

    $attribute = Attribute::query()->where('source_attribute_id', 2)->firstOrFail();

    /*
     * 1. The slug is `attribute_name`, not `taxonomy`. This schema's own column
     *    comment says "color, size, shades" and Admin\AttributesApiController
     *    builds `filter_size` from it; importing `pa_size` would put
     *    `filter_pa_size` in front of the owner and in every URL built from it.
     *
     * 2. The name is `attribute_label`, not `name`. One row of this file is one
     *    TERM, so `name` is "50ml" -- taking it would name the attribute after
     *    whichever of its terms was read last.
     */
    expect($attribute->slug)->toBe('size')
        ->and($attribute->name)->toBe('Size');

    // And the file really does carry the wrong-looking values, so this is a
    // measurement rather than a restatement of the importer.
    $header = str_getcsv((string) strtok((string) file_get_contents(ghExportDir().'/attributes.csv'), "\n"));
    $first = str_getcsv((string) explode("\n", (string) file_get_contents(ghExportDir().'/attributes.csv'))[1]);
    $row = array_combine($header, $first);

    expect($row['taxonomy'])->toBe('pa_size')
        ->and($row['attribute_name'])->toBe('size')
        ->and($row['attribute_label'])->toBe('Size')
        ->and($row['name'])->toBe('50ml');
});

it('does not read attribute_public as is_filterable, or count as position', function () {
    ghImport();

    $header = str_getcsv((string) strtok((string) file_get_contents(ghExportDir().'/attributes.csv'), "\n"));
    $row = array_combine($header, str_getcsv(explode("\n", (string) file_get_contents(ghExportDir().'/attributes.csv'))[1]));

    /*
     * 3. `attribute_public` is WooCommerce's "enable archives" flag -- whether
     *    /pa_size/50ml/ was ever a page -- and the permalinks stage reads it for
     *    exactly that. `is_filterable` is this shop's "show it in the filter
     *    panel". Woo's layered-nav filter works on an attribute with no archive
     *    and 0 is Woo's DEFAULT, so mapping one onto the other would arrive at
     *    "nothing on this shop is filterable" for a shop whose filters worked.
     */
    expect($row['attribute_public'])->toBe('0');

    $attribute = Attribute::query()->where('slug', 'size')->firstOrFail();

    expect($attribute->is_filterable)->toBeTrue('attribute_public was read as is_filterable');

    /*
     * 4. `count` is WordPress's cached membership count, not an ordering.
     *    Ordering sizes by how many products use each one puts 100ml above 50ml
     *    on a shop that sells more of the large one. The export carries no term
     *    order at all, so position stays at the schema default.
     */
    expect($row['count'])->toBe('1');

    foreach (AttributeValue::query()->whereNotNull('source_term_id')->get() as $value) {
        expect($value->position)->toBe(0, $value->slug.' took its position from a column that is not one');
    }

    // query_var is not invented either: the export does not carry one, and
    // Attribute::queryVar() already computes the same answer from the slug.
    expect($attribute->query_var)->toBeNull()
        ->and($attribute->queryVar())->toBe('filter_size');
});

it('learns that an attribute is a variation axis from the variations file, which is the only place it is written', function () {
    // Attributes alone: nothing in attributes.csv says an attribute is used to
    // build variations, because in WooCommerce that is a per-product tick
    // stored on the product.
    ghImport(['only' => ['categories', 'brands', 'products', 'attributes']]);

    expect((bool) Attribute::query()->where('slug', 'size')->value('is_variation_axis'))
        ->toBeFalse('the attributes file claimed an axis it cannot know about');

    // The variations are the evidence, so importing them is what sets it.
    ghImport();

    expect((bool) Attribute::query()->where('slug', 'size')->value('is_variation_axis'))->toBeTrue();
});

/* ------------------------------------------- the size the owner had withdrawn */

it('puts a disabled size out of stock, because this schema has no status column for a variant', function () {
    $report = ghImport();

    $header = str_getcsv((string) strtok((string) file_get_contents(ghExportDir().'/variations.csv'), "\n"));
    $rows = array_map(
        static fn (string $line): array => array_combine($header, str_getcsv($line)),
        array_slice(array_filter(explode("\n", (string) file_get_contents(ghExportDir().'/variations.csv'))), 1),
    );

    $disabled = collect($rows)->firstWhere('id', '4102');

    // WooCommerce disables one size by setting its post_status to `private`,
    // and the row still says it is in stock.
    expect($disabled['status'])->toBe('private')
        ->and($disabled['stock_status'])->toBe('instock');

    $variant = ProductVariant::query()->where('wc_id', 4102)->firstOrFail();

    expect($variant->stock_status)->toBe('outofstock')
        ->and(ProductVariant::query()->where('wc_id', 4101)->value('stock_status'))->toBe('instock');

    // And the owner is told, in the channel they already read.
    expect(ghSaid($report, 'variations'))->toContain('a variation WooCommerce had disabled');
});

it('will not sell the withdrawn size through the door a shopper actually uses', function () {
    ghImport();

    $product = Product::query()->where('wc_id', 4023)->firstOrFail();
    $withdrawn = ProductVariant::query()->where('wc_id', 4102)->firstOrFail();
    $live = ProductVariant::query()->where('wc_id', 4101)->firstOrFail();

    /*
     * THE POINT OF MAPPING `private` ONTO `outofstock` RATHER THAN IGNORING IT.
     * Store\CartController::add() tests
     * `($variant?->stock_status ?? $product->stock_status) !== 'instock'`, and
     * App\Services\StockClaim::claim() tests it again inside the placing
     * transaction. Neither knows anything about WordPress statuses; both refuse
     * this value, so the withdrawn size cannot be bought.
     */
    $this->postJson('/api/cart/add', [
        'product_id' => $product->id,
        'variant_id' => $withdrawn->id,
        'quantity' => 1,
    ])->assertStatus(422)->assertJsonPath('ok', false);

    $this->postJson('/api/cart/add', [
        'product_id' => $product->id,
        'variant_id' => $live->id,
        'quantity' => 1,
    ])->assertOk();
});

/* ------------------------------------------------------------------ the pivots */

it('files a product under its tags and under the terms it offers, and takes one off again', function () {
    ghImport();

    $serum = Product::query()->where('wc_id', 4021)->firstOrFail();
    $cleanser = Product::query()->where('wc_id', 4023)->firstOrFail();

    $tag = Tag::query()->where('source_term_id', 601)->firstOrFail();

    expect($tag->slug)->toBe('k-beauty')
        ->and(DB::table('product_tag')->where('tag_id', $tag->id)->pluck('product_id')->all())
        ->toBe([$serum->id]);

    // product_attribute_value is "which terms a product OFFERS", which is what
    // a filter matches on -- separate from the variant pivot below.
    $offered = DB::table('product_attribute_value')
        ->join('attribute_values', 'attribute_values.id', '=', 'product_attribute_value.attribute_value_id')
        ->where('product_attribute_value.product_id', $cleanser->id)
        ->orderBy('attribute_values.source_term_id')
        ->pluck('attribute_values.slug')
        ->all();

    expect($offered)->toBe(['50ml', '100ml']);

    // And one variant is pinned to exactly one of them.
    $pinned = DB::table('product_variant_attribute_value')
        ->join('attribute_values', 'attribute_values.id', '=', 'product_variant_attribute_value.attribute_value_id')
        ->join('product_variants', 'product_variants.id', '=', 'product_variant_attribute_value.product_variant_id')
        ->where('product_variants.wc_id', 4101)
        ->pluck('attribute_values.slug')
        ->all();

    expect($pinned)->toBe(['50ml']);

    /*
     * THE MEMBERSHIP IS RECONCILED, NOT APPENDED. A product taken off a tag in
     * WooCommerce has to come off it here, and the row has to report as
     * `updated` when only its pivot moved -- otherwise "unchanged on the second
     * pass" stops being evidence of anything.
     */
    $emptied = ghTempExport(['tags.csv' => static function (string $csv): string {
        return str_replace('"1","4021"', '"1",""', $csv);
    }]);

    $report = (new ImportRunner)->run(new ImportOptions(
        directory: $emptied,
        only: ['tags'],
        sourceTimezone: ghManifest()['source']['timezone'],
        adoptBySlug: true,
    ));

    expect(DB::table('product_tag')->where('tag_id', $tag->id)->count())->toBe(0)
        ->and($report->for('tags')->updated)->toBe(1)
        ->and($report->for('tags')->unchanged)->toBe(0);
});

/**
 * A copy of the export with one file rewritten, for the cases that need a
 * different row rather than a different importer.
 *
 * @param  array<string, callable(string): string>  $rewrites
 */
function ghTempExport(array $rewrites): string
{
    $dir = sys_get_temp_dir().'/kbb-gh-'.bin2hex(random_bytes(6));

    mkdir($dir, 0775, true);

    foreach (glob(ghExportDir().'/*') ?: [] as $path) {
        $name = basename($path);
        $body = (string) file_get_contents($path);

        if (isset($rewrites[$name])) {
            $body = $rewrites[$name]($body);
        }

        file_put_contents($dir.'/'.$name, $body);
    }

    return $dir;
}

/* ------------------------------------------------------------- repeatability */

it('reports a variant whose defining terms moved as updated, not as unchanged', function () {
    /*
     * FOUND BY MUTATION, NOT BY READING. `it files a product under its tags…`
     * covers the same correction for the tag pivot, and the variant's own went
     * untested -- the mutation that deletes it left the suite GREEN.
     *
     * It matters for the same reason ProductImporter gives about category
     * membership: "unchanged on the second pass" is the only evidence this
     * import offers that it did the same thing twice, and a row whose pivot
     * moved while its columns did not is an update. A variant re-pinned from
     * 50ml to 100ml is a different purchasable option; reporting it as
     * unchanged would make the idempotency evidence a lie about the one table
     * §11 calls the largest missing entity by revenue.
     */
    ghImport();

    $repinned = ghTempExport(['variations.csv' => static fn (string $csv): string => str_replace(
        '"attribute_pa_size=50ml"',
        '"attribute_pa_size=100ml"',
        $csv,
    )]);

    $report = (new ImportRunner)->run(new ImportOptions(
        directory: $repinned,
        only: ['variations'],
        sourceTimezone: ghManifest()['source']['timezone'],
        runKey: 'gh-repin',
    ));

    expect($report->for('variations')->updated)->toBe(1)
        ->and($report->for('variations')->unchanged)->toBe(1);

    // And the pivot really moved: the 50ml variant is now the 100ml one.
    expect(ProductVariant::query()->where('wc_id', 4101)->firstOrFail()->label())->toBe('100ml');
});

it('is idempotent: a second pass changes nothing', function () {
    ghImport();
    $second = ghImport();

    foreach (['tags', 'attributes', 'variations'] as $entity) {
        $report = $second->for($entity);

        expect($report->created)->toBe(0, $entity.' created rows on the second pass')
            ->and($report->updated)->toBe(0, $entity.' rewrote rows on the second pass')
            ->and($report->unchanged)->toBeGreaterThan(0, $entity.' reported nothing unchanged');
    }

    // Including the attribute definition, which is written by a file whose rows
    // are terms and would churn on every pass if it were rewritten per row.
    expect(Attribute::query()->count())->toBe(1);
});

it('reaches the same rows whether the batch is one row or five hundred', function () {
    /*
     * The admin screen imports in slices and the runner commits per batch with
     * the checkpoint inside the same transaction. A batch size of one exercises
     * that boundary once per row, which is where an importer that carries state
     * between rows -- this one memoises attributes and axis flags -- would come
     * apart.
     */
    ghImport(['batchSize' => 1]);

    $small = [
        'variants' => DB::table('product_variants')->orderBy('wc_id')->get()->map(fn ($v) => (array) $v)->all(),
        'values' => DB::table('attribute_values')->orderBy('source_term_id')->pluck('slug')->all(),
        'pinned' => DB::table('product_variant_attribute_value')->orderBy('product_variant_id')->get()
            ->map(fn ($r) => (array) $r)->all(),
        'tagged' => DB::table('product_tag')->orderBy('product_id')->get()->map(fn ($r) => (array) $r)->all(),
    ];

    DB::table('product_variant_attribute_value')->delete();
    DB::table('product_attribute_value')->delete();
    DB::table('product_tag')->delete();
    DB::table('product_variants')->delete();
    DB::table('attribute_values')->delete();
    DB::table('attributes')->delete();
    DB::table('tags')->delete();
    DB::table('import_checkpoints')->delete();

    ghImport(['batchSize' => 500]);

    $large = [
        'variants' => DB::table('product_variants')->orderBy('wc_id')->get()->map(fn ($v) => (array) $v)->all(),
        'values' => DB::table('attribute_values')->orderBy('source_term_id')->pluck('slug')->all(),
        'pinned' => DB::table('product_variant_attribute_value')->orderBy('product_variant_id')->get()
            ->map(fn ($r) => (array) $r)->all(),
        'tagged' => DB::table('product_tag')->orderBy('product_id')->get()->map(fn ($r) => (array) $r)->all(),
    ];

    // Ids and timestamps differ between two runs against a re-emptied table, so
    // the comparison is on what the rows SAY.
    $say = static fn (array $rows): array => array_map(
        static fn (array $row): array => array_diff_key($row, array_flip(['id', 'created_at', 'updated_at'])),
        $rows,
    );

    $small['variants'] = $say($small['variants']);
    $large['variants'] = $say($large['variants']);

    expect($large['variants'])->toEqual($small['variants'])
        ->and($large['values'])->toBe($small['values'])
        ->and(count($large['pinned']))->toBe(count($small['pinned']))
        ->and(count($large['tagged']))->toBe(count($small['tagged']));
});

it('counts terms and not attributes in the verification line', function () {
    $report = ghImport();

    $attributes = $report->for('attributes');

    /*
     * docs/FV-IMPORT-AT-VOLUME.md §7: rows in against rows out, per bucket, and
     * the count is the one number in the report that comes from the database.
     * One row of attributes.csv is one TERM, so the count has to be terms --
     * counting `attributes` would report 1 against a 2-row file and read as a
     * disaster.
     */
    expect($attributes->rowsRead)->toBe(2)
        ->and($attributes->rowsAccounted)->toBe(2)
        ->and($attributes->inDatabase)->toBe(2)
        ->and($attributes->verification()['verdict'])->toBe('verified');

    foreach (['tags', 'variations'] as $entity) {
        expect($report->for($entity)->verification()['verdict'])
            ->toBe('verified', $entity.' did not verify');
    }
});

/* ------------------------------------- what the shopper and the crawler now see */

/** The product page as a shopper gets it, with the whitespace squeezed out. */
function ghProductPage(string $slug): string
{
    return (string) preg_replace(
        '#\s+#',
        ' ',
        (string) test()->get('/product/'.$slug.'/')->assertOk()->getContent(),
    );
}

/** Every JSON-LD document on a page, decoded. */
function ghJsonLd(string $slug): array
{
    $html = (string) test()->get('/product/'.$slug.'/')->assertOk()->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return array_values(array_filter(array_map(
        static fn (string $blob): mixed => json_decode(html_entity_decode($blob), true),
        $matches[1],
    )));
}

/** The Product node's `offers`, whatever shape it is in. */
function ghOffers(string $slug): ?array
{
    foreach (ghJsonLd($slug) as $document) {
        if (isset($document['offers'])) {
            return $document['offers'];
        }
    }

    return null;
}

it('shows the shopper a price per option, and greys the size the owner withdrew', function () {
    ghImport();

    $html = ghProductPage('cleanser-4023');

    /*
     * store/product.blade.php has printed a price on every `.variant` row all
     * along -- `Money::format($vsale, $vdp)` -- and had never had a variant to
     * print. This is that code path running for the first time on imported
     * data, read off the rendered page rather than inferred from the template.
     */
    $live = ProductVariant::query()->where('wc_id', 4101)->firstOrFail();
    $withdrawn = ProductVariant::query()->where('wc_id', 4102)->firstOrFail();

    expect($html)
        ->toContain('data-vid="'.$live->id.'"')
        ->toContain('data-vid="'.$withdrawn->id.'"')
        // Each row's own price, in the row.
        ->toContain('data-price="AED 60"')
        ->toContain('data-price="AED 100"')
        // The option's name comes from the term it is pinned to.
        ->toContain('<span class="vn">50ml</span>')
        ->toContain('<span class="vn">100ml</span>');

    // The live option is the selected one and the withdrawn one is tagged.
    expect($html)
        ->toContain('class="variant on" data-i="0" data-vid="'.$live->id.'"')
        ->toContain('class="variant oos" data-i="1" data-vid="'.$withdrawn->id.'"')
        ->toContain('<span class="vtag sold">Sold out</span>')
        // And the hidden field the form posts when the shopper touches nothing
        // points at the option that can be bought, which is what $buyable is
        // for.
        ->toContain('name="variation_id" id="kbbVarId" value="'.$live->id.'"');

    // The option count the page offers above the list.
    expect($html)->toContain('<span id="optNote">2 options</span>');
});

it('turns a variable product\'s structured data from one AED 0.00 offer into a real range', function () {
    /*
     * THE HEADLINE, AND IT IS WORSE THAN "A MISSING AGGREGATEOFFER".
     *
     * WooCommerce keeps no price on a variable product's parent post -- the
     * price is on the variations -- so `products.regular_price` is EMPTY in the
     * export and `products.price` imports as NULL. Store\ProductController then
     * hands Seo `price_minor => 0`, and with no variants to aggregate,
     * Seo::aggregateOffer() returns null and the single Offer stands.
     *
     * So before this lane, a variable product published `"price": "0.00"` to
     * Google: a shop telling a crawler its cleanser is free. Measured here
     * first, then measured again after the variants are in.
     */
    ghImport(['only' => ['categories', 'brands', 'products']]);

    $before = ghOffers('cleanser-4023');

    expect($before['@type'])->toBe('Offer')
        ->and($before['price'])->toBe('0.00')
        ->and($before['priceSpecification']['price'])->toBe('0.00');

    ghImport();

    $after = ghOffers('cleanser-4023');

    /*
     * App\Support\Seo has published an AggregateOffer over two or more
     * differently-priced variants since Lane FX and had never seen one. The
     * range is computed on the integer fils and formatted once, which is the
     * property that survives a thousands separator being added to the
     * formatter.
     */
    expect($after['@type'])->toBe('AggregateOffer')
        ->and($after['lowPrice'])->toBe('60.00')
        ->and($after['highPrice'])->toBe('100.00')
        ->and($after['offerCount'])->toBe(2)
        // The parent's single figure and its VAT statement are what the range
        // replaces, so the 0.00 is gone rather than sitting beside the range.
        ->and(array_key_exists('price', $after))->toBeFalse()
        ->and(array_key_exists('priceSpecification', $after))->toBeFalse();

    $children = collect($after['offers'])->keyBy('sku');

    expect($children['KBB-4023-50ml']['price'])->toBe('60.00')
        ->and($children['KBB-4023-50ml']['availability'])->toBe('https://schema.org/InStock')
        // The withdrawn size's own stock status, which is the whole reason a
        // per-variant offer is worth publishing: the page tags that row "Sold
        // out", and a document saying every size is in stock contradicts what
        // is on the screen.
        ->and($children['KBB-4023-100ml']['price'])->toBe('100.00')
        ->and($children['KBB-4023-100ml']['availability'])->toBe('https://schema.org/OutOfStock');
});

it('leaves a variable product\'s HEADLINE price at zero, which is the parent row and not the variants', function () {
    /*
     * MEASURED AND NOT FIXED, DELIBERATELY, and stated here so it is a known
     * gap rather than a surprise.
     *
     * Importing the variations corrects the structured data, because
     * Seo::aggregateOffer() replaces the parent's Offer entirely. It does NOT
     * correct `products.price`, which is what the page's own .now span, the
     * shop tile, the price sort and the price facet all read -- and WooCommerce
     * genuinely has no price on the parent to import.
     *
     * Backfilling it from the cheapest variant is one line here and would break
     * the one property this import is judged on: ProductImporter writes
     * `price => null` for this row out of the CSV on every pass, so the product
     * would be rewritten every run, for ever, and the products bucket would
     * never report "unchanged" again. The fix needs both halves and both lanes;
     * docs/GH-VARIATIONS-AND-ATTRIBUTES.md carries the shape of it.
     */
    ghImport();

    $html = ghProductPage('cleanser-4023');

    expect($html)->toContain('<span class="now">')
        // The .now span quotes the PARENT row, which is null -> 0.
        ->toContain('id="bbPrice"> <span class="now"><span class="woocommerce-Price-amount amount" dir="ltr"><span class="woocommerce-Price-currencySymbol" dir="auto">AED</span> 0</span></span>');

    expect(Product::query()->where('wc_id', 4023)->value('price'))->toBeNull();

    // And the same null is what the price sort orders on, so the variable
    // product sorts below every priced one.
    $cheapest = Product::query()->visible()->orderBy('price')->value('slug');

    expect($cheapest)->toBe('cleanser-4023');
});

/* ------------------------------ what it refuses, and what it cannot keep */

it('refuses a variation with no parent and one whose status it does not know, and keeps the rest', function () {
    $report = ghWooImport();

    $reasons = ghReasons($report, 'variations');

    expect($reasons)->toContain('parent product 9999 is not in this database')
        ->toContain("status 'archived' is not one this importer knows");

    // And a sale price with no regular price behind it, which this schema has
    // no way to express -- the same refusal ProductImporter makes one level up.
    expect($reasons)->toContain('sale_price is set but regular_price is empty');

    // 9 rows, 3 refused, 6 in the database — and the count-based verification
    // is the check that does not take the importer's word for it.
    expect($report->for('variations')->rowsRead)->toBe(9)
        ->and($report->for('variations')->rejectedCount())->toBe(3)
        ->and(ProductVariant::query()->whereNotNull('wc_id')->count())->toBe(6)
        ->and($report->for('variations')->verification()['verdict'])->toBe('verified');

    // A trashed variation is refused by name, the way a trashed product is.
    $trashed = ghTempWoo(['variations.csv' => static fn (string $csv): string => str_replace(
        '4103,4021,KBB-4021-rose,publish',
        '4103,4021,KBB-4021-rose,trash',
        $csv,
    )]);

    $second = (new ImportRunner)->run(new ImportOptions(
        directory: $trashed,
        only: ['variations'],
        sourceTimezone: 'Asia/Dubai',
        runKey: 'gh-trash',
    ));

    /*
     * THE WHOLE SENTENCE, NOT THE STATUS NAME -- and this assertion is here in
     * this shape because the shorter one SURVIVED its mutation.
     *
     * `->toContain("status 'trash'")` passes whether the trash branch runs or
     * not: with it removed, `trash` simply falls through to the unknown-status
     * refusal, whose message is
     * "status 'trash' is not one this importer knows (publish, private, ...)"
     * -- which contains that needle too. The two refusals are different
     * statements: one says "empty the trash", the other says "this importer
     * does not know what that is", and only the first is right for a row
     * WordPress has deleted.
     */
    expect(ghReasons($second, 'variations'))
        ->toContain('this variation is in the WordPress trash')
        ->toContain('empty the trash or filter the export');
});

it('splits a two-axis variation on the pipe, not on a comma', function () {
    /*
     * THE SEPARATOR IS THE EXPORTER'S STATED CONTRACT AND THIS IS THE ROW THAT
     * MAKES THE ASSERTION BITE.
     *
     * The plugin joins the pairs with `|` and replaces a `|` inside a value
     * with `/` first, so a split on the pipe cannot tear a value in half. Every
     * other variation in either fixture has ONE axis -- and a one-element cell
     * splits to the same one element whatever the separator is, so a test
     * without a two-axis row would pass just as happily with the comma and
     * prove nothing. That is the dead-filter shape this repository has already
     * paid for in Api\ProductController and in ProductImporter's gallery
     * separator.
     */
    ghWooImport();

    $pinned = DB::table('product_variant_attribute_value')
        ->join('attribute_values', 'attribute_values.id', '=', 'product_variant_attribute_value.attribute_value_id')
        ->join('product_variants', 'product_variants.id', '=', 'product_variant_attribute_value.product_variant_id')
        ->where('product_variants.wc_id', 4108)
        ->orderBy('attribute_values.slug')
        ->pluck('attribute_values.slug')
        ->all();

    expect($pinned)->toBe(['50ml', 'rose']);

    // And the option's label is both axes, which is what the product page and
    // the cart line print.
    expect(ProductVariant::query()->where('wc_id', 4108)->firstOrFail()->label())
        ->toBeIn(['Rose / 50ml', '50ml / Rose']);
});

it('names the three things a variation carries that this schema cannot hold', function () {
    $report = ghWooImport();

    $said = ghSaid($report, 'variations');

    // 1. A per-variation sale window. product_variants has no date columns, so
    //    ProductVariant::effectivePrice() applies the PARENT's window — and
    //    where the parent has none, the markdown never ends.
    expect($said)->toContain('a sale window of this variation\'s own')
        ->toContain('2021-01-01 00:00:00 — 2021-02-01 00:00:00');

    // 2. An axis set to "any". WooCommerce lets one variation stand for every
    //    value of an attribute; a variant here is defined by the exact terms it
    //    is pinned to.
    expect($said)->toContain('a variation axis set to "any"');

    // 3. A per-product ("custom") attribute, which attributes.csv does not
    //    carry because it carries global pa_* terms. Named rather than
    //    invented.
    expect($said)->toContain('a variation axis this export does not describe')
        ->toContain('scent = Unscented');

    // All three still imported the variant rather than refusing it.
    expect(ProductVariant::query()->where('wc_id', 4103)->exists())->toBeTrue()
        ->and(ProductVariant::query()->where('wc_id', 4105)->exists())->toBeTrue()
        ->and(ProductVariant::query()->where('wc_id', 4106)->exists())->toBeTrue()
        // The "any" variant is pinned to nothing, so its label falls back to
        // the page's "Option 1" rather than to a term it does not have.
        ->and(ProductVariant::query()->where('wc_id', 4105)->firstOrFail()->label())->toBe('');
});

it('names the tag columns it drops rather than leaving them out of the report', function () {
    $report = ghWooImport();

    /*
     * `tags` is four columns. The export's description, parent and count have
     * nowhere to go, and they are deliberately NOT read so that the runner's
     * consolidated discard line names all three with a sample value -- the
     * designed way for the owner to approve a loss rather than discover it.
     */
    $discards = $report->for('tags')->discards();

    $columns = '';

    foreach ($discards as $kind => $entry) {
        if (str_contains($kind, 'columns in this export that no field of this importer reads')) {
            // reportIgnoredColumns() puts the names in `before` and the count
            // in `field`; `after` is the default.
            $columns = implode(' ', array_column($entry['samples'] ?? [], 'before'));
        }
    }

    expect($columns)->toContain('description = Korean beauty staples')
        ->toContain('parent')
        ->toContain('count = 2');

    // And a tag naming a product that is not here is a note with the id in it,
    // not a silent omission and not a refusal.
    expect(implode(' || ', array_keys($report->for('tags')->notes())))
        ->toContain('not in this import')
        ->toContain('9999');

    expect(Tag::query()->where('source_term_id', 602)->exists())->toBeTrue();
});

it('refuses a tag and an attribute row it cannot identify', function () {
    $report = ghWooImport();

    expect(ghReasons($report, 'tags'))->toContain('name is required');

    // An attributes row with neither a taxonomy nor an attribute_name has
    // nothing to hang its term on: attribute_values.attribute_id is NOT NULL.
    expect(ghReasons($report, 'attributes'))->toContain('this row names no attribute');
});

/**
 * A copy of the woo fixture with one file rewritten.
 *
 * @param  array<string, callable(string): string>  $rewrites
 */
function ghTempWoo(array $rewrites): string
{
    $dir = sys_get_temp_dir().'/kbb-gh-woo-'.bin2hex(random_bytes(6));

    mkdir($dir, 0775, true);

    foreach (glob(base_path('tests/Fixtures/woo/*')) ?: [] as $path) {
        $name = basename($path);
        $body = (string) file_get_contents($path);

        file_put_contents($dir.'/'.$name, isset($rewrites[$name]) ? $rewrites[$name]($body) : $body);
    }

    return $dir;
}

/* ----------------------------------------------------- the slug collisions */

it('will not take an attribute slug held by something that did not come from WooCommerce', function () {
    // The owner typed "Size" into Catalog → Attributes before the import ran.
    // Without attributes.source_attribute_id there is nothing on the row to say
    // it did not come from WooCommerce, and the import would silently adopt it.
    Attribute::query()->create(['slug' => 'size', 'name' => 'Size (mine)']);

    $report = ghImport(['adoptBySlug' => false]);

    expect(ghReasons($report, 'attributes'))
        ->toContain("slug 'size' is already held by a row that did not come from WooCommerce");

    // Nothing was imported over it.
    expect(Attribute::query()->where('slug', 'size')->value('name'))->toBe('Size (mine)')
        ->and(AttributeValue::query()->whereNotNull('source_term_id')->count())->toBe(0);

    // And with the flag the owner is given the list rather than the refusal.
    $adopted = ghImport(['adoptBySlug' => true, 'runKey' => 'gh-adopt']);

    expect(implode(' || ', array_keys($adopted->for('attributes')->notes())))
        ->toContain('adopted the existing attributes row for slug "size"');

    expect(Attribute::query()->where('slug', 'size')->value('source_attribute_id'))->toBe(2);
});

it('will not take a term slug held by another term of the same attribute', function () {
    ghImport();

    /*
     * attribute_values is unique on the PAIR (attribute_id, slug), so this
     * cannot go through App\Services\Import\SlugGuard, which asks about a bare
     * `slug` column -- asking its question here would refuse "50ml" under Size
     * because "50ml" exists under some other attribute, which the database
     * permits.
     */
    $renamed = ghTempExport(['attributes.csv' => static fn (string $csv): string => str_replace(
        '"702","100ml","100ml"',
        '"702","100ml","50ml"',
        $csv,
    )]);

    $report = (new ImportRunner)->run(new ImportOptions(
        directory: $renamed,
        only: ['attributes'],
        sourceTimezone: ghManifest()['source']['timezone'],
        runKey: 'gh-collide',
    ));

    expect(ghReasons($report, 'attributes'))
        ->toContain("slug '50ml' already belongs to term 701 under the same attribute");

    // The term that already held it is untouched.
    expect(AttributeValue::query()->where('source_term_id', 701)->value('slug'))->toBe('50ml')
        ->and(AttributeValue::query()->where('source_term_id', 702)->value('slug'))->toBe('100ml');
});

it('names the two rows fighting over a slug instead of handing back the UPDATE statement', function () {
    /*
     * THE HOLE THIS CLOSED, WHICH WAS FOUND BY WRITING THE TEST AND NOT BY
     * READING THE CODE.
     *
     * BrandImporter and CategoryImporter reach SlugGuard only where the row is
     * NEW, and the first draft of these two importers copied that. The case it
     * misses is a row this import already owns whose slug the export has since
     * changed onto one something else holds: the guard never runs, the UPDATE
     * goes to the database, and `attributes.slug` / `tags.slug` are UNIQUE.
     *
     * The runner does catch the driver's refusal -- it is not silent -- but
     * what lands in the report is
     * "the database refused this row: SQLSTATE[23000] ... UNIQUE constraint
     * failed" with the whole statement and its bound values in it, and nothing
     * saying which two rows are fighting or what to do about it. Both
     * importers now check before they write.
     */
    ghImport();

    Attribute::query()->create(['slug' => 'volume', 'name' => 'Volume', 'source_attribute_id' => 9]);

    $renamed = ghTempExport(['attributes.csv' => static fn (string $csv): string => str_replace(
        '"pa_size","2","size"',
        '"pa_size","2","volume"',
        $csv,
    )]);

    $report = (new ImportRunner)->run(new ImportOptions(
        directory: $renamed,
        only: ['attributes'],
        sourceTimezone: ghManifest()['source']['timezone'],
        runKey: 'gh-attr-move',
    ));

    $reasons = ghReasons($report, 'attributes');

    expect($reasons)->toContain("slug 'volume' is already held by attribute")
        ->toContain('WooCommerce attribute 9')
        // And NOT the driver's own words, which is what it used to be.
        ->and(str_contains($reasons, 'SQLSTATE'))->toBeFalse($reasons);

    // Nothing moved.
    expect(Attribute::query()->where('source_attribute_id', 2)->value('slug'))->toBe('size');
});

it('names the two tags fighting over a slug, for the same reason', function () {
    ghImport();

    Tag::query()->create(['slug' => 'promo', 'name' => 'Promo', 'source_term_id' => 999]);

    $renamed = ghTempExport(['tags.csv' => static fn (string $csv): string => str_replace(
        '"K-Beauty","k-beauty"',
        '"K-Beauty","promo"',
        $csv,
    )]);

    $report = (new ImportRunner)->run(new ImportOptions(
        directory: $renamed,
        only: ['tags'],
        sourceTimezone: ghManifest()['source']['timezone'],
        runKey: 'gh-tag-move',
    ));

    $reasons = ghReasons($report, 'tags');

    expect($reasons)->toContain("slug 'promo' is already held by tag")
        ->toContain('term 999')
        ->and(str_contains($reasons, 'SQLSTATE'))->toBeFalse($reasons);

    expect(Tag::query()->where('source_term_id', 601)->value('slug'))->toBe('k-beauty');
});
