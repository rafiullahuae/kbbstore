<?php

declare(strict_types=1);

/*
 * =============================================================================
 * THE OLD PRODUCT PAGE'S EXTRA TABS, BROUGHT ACROSS            (Lane PI-A, item 5)
 * =============================================================================
 *
 * ON THE SHOP: the owner's WordPress product page showed "Description" AND
 * "Major Ingredients" (other products other tabs). The imported product showed
 * Description alone.
 *
 * ROOT CAUSE, END TO END. WooCommerce has no extra tabs of its own; a tab
 * plugin keeps them in its own post meta (`yikes_woo_products_tabs` for Custom
 * Product Tabs for WooCommerce, a title/content pair for WoodMart, Flatsome,
 * Porto). The exporter's META_KEYS read none of them and no column carried
 * them, so they were in no file; and nothing on this side read a tab from an
 * import either -- `products.custom_tabs` is a json column nothing writes. A
 * loss in no file is one no report can name, which is why it surfaced on the
 * product page rather than in the import report.
 *
 * THE FIX, both halves:
 *   exporter 1.9.0   products.csv `custom_tabs`, a JSON list of {title,
 *                    content} in the old page's order, empty tabs left out;
 *                    an unrecognised tab-like meta key is NAMED in manifest.json.
 *   ProductImporter  each becomes a tab of the product's own in product_tabs,
 *                    content through RichText::forDisplay() (the old shop's
 *                    paragraph rules, then the allowlist), keyed by
 *                    import_key so a re-import updates rather than duplicates
 *                    and never touches a tab the owner wrote himself.
 *
 * THE DATA ALREADY ON HIS SHOP CANNOT BE RECOVERED WITHOUT A RE-EXPORT: the
 * export he imported never carried the meta, and nothing kept it. He has to
 * install exporter 1.9.0, export Products again and import that.
 *
 * The fixture is the plugin's own output (wordpress-plugin/harness/shop.php,
 * regenerated); GeWpExporterTest's regeneration check proves the exporter half
 * byte for byte, and these prove the import half.
 */

use App\Models\Product;
use App\Models\ProductTab;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;

/** A private copy of the plugin's fixture export, with products.csv edits applied. */
function ptExport(array $customTabsById = [], bool $dropColumn = false): string
{
    $dir = sys_get_temp_dir().'/kbb-pt-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    foreach (glob(base_path('tests/Fixtures/kbb-export').'/*') ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    $in = fopen(base_path('tests/Fixtures/kbb-export/products.csv'), 'r');
    $out = fopen($dir.'/products.csv', 'w');
    $header = fgetcsv($in, null, ',', '"', '');
    $at = array_search('custom_tabs', $header, true);

    expect($at)->not->toBeFalse('the fixture has no custom_tabs column -- regenerate it with exporter 1.9.0');

    $write = static function (array $cells) use ($out, $at, $dropColumn): void {
        if ($dropColumn) {
            array_splice($cells, $at, 1);
        }

        fputcsv($out, $cells, ',', '"', '');
    };

    $write($header);

    while (($cells = fgetcsv($in, null, ',', '"', '')) !== false) {
        if (array_key_exists((int) $cells[0], $customTabsById)) {
            $value = $customTabsById[(int) $cells[0]];
            $cells[$at] = is_string($value) ? $value : json_encode($value);
        }

        $write($cells);
    }

    fclose($in);
    fclose($out);

    return $dir;
}

function ptImport(?string $dir = null): ImportReport
{
    $dir ??= base_path('tests/Fixtures/kbb-export');
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    return (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
    ));
}

/** The imported serum's tabs, as the page would list them. */
function ptTabs(): array
{
    $id = Product::query()->where('wc_id', 4021)->value('id');

    return ProductTab::query()->where('product_id', $id)->orderBy('position')->orderBy('id')
        ->get(['title', 'body', 'position', 'import_key', 'is_enabled'])->toArray();
}

it('brings the old product page\'s extra tabs across, in order, with no empty one', function () {
    /*
     * ON THE SHOP: "Description" only, where WordPress showed "Description"
     * and "Major Ingredients".
     *
     * MUTATION, RUN: the `syncTabs()` call removed from ProductImporter::import()
     * -- red, the serum had 0 tabs. And the exporter half: `custom_tabs`
     * removed from the stage's row -- GeWpExporterTest's regeneration check is
     * red on products.csv.
     */
    ptImport();

    $tabs = ptTabs();

    expect(array_column($tabs, 'title'))->toBe(['Major Ingredients', 'How to Use'])
        ->and(array_column($tabs, 'import_key'))->toBe(['wc:1', 'wc:2'])
        ->and(array_column($tabs, 'position'))->toBe([50, 51])
        // Laid out the way the old page laid it out, not as one run-on line.
        ->and($tabs[0]['body'])->toBe("<p><strong>Ginseng Root Extract</strong><br>\nNourishes and firms.</p>\n<ul>\n<li>Niacinamide</li>\n<li>Adenosine</li>\n</ul>")
        ->and($tabs[1]['body'])->toBe("<p>Two drops, morning and night.<br>\nPat in.</p>");

    // And Flatsome's single-pair storage, on the toner.
    $toner = Product::query()->where('wc_id', 4022)->value('id');
    expect(ProductTab::query()->where('product_id', $toner)->pluck('title')->all())->toBe(['Shipping']);
});

it('draws them on the product page after Description', function () {
    ptImport();
    $slug = Product::query()->where('wc_id', 4021)->value('slug');

    $html = $this->get('/product/'.$slug.'/')->assertOk()->getContent();
    preg_match_all('#<button class="dtab[^"]*" type="button" data-i="\d+">([^<]+)</button>#', $html, $m);

    expect($m[1])->toBe(['Description', 'Major Ingredients', 'How to Use'])
        ->and($html)->toContain('<li>Niacinamide</li>');
});

it('strips what a browser would run from tab content and titles', function () {
    /*
     * A tab plugin's content is third-party HTML printed with {!! !!}. The
     * fixture's own Major Ingredients tab carries a <script>; this adds the
     * attribute, URL and style forms, and a title with markup in it.
     *
     * MUTATION, RUN: RichText::forDisplay($tab['content']) replaced by the raw
     * content in tabsFrom() -- red, "<script" reached product_tabs.body.
     */
    ptImport(ptExport([4021 => [
        ['title' => '<b onclick="x()">Major</b> &amp; Minor', 'content' => "Line\n<img src=\"/a.jpg\" onerror=\"alert(1)\">"
            .'<a href="javascript:alert(2)">x</a><p style="position:fixed">y</p><script>alert(3)</script><iframe src="//evil"></iframe>'],
    ]]));

    $tabs = ptTabs();
    $slug = Product::query()->where('wc_id', 4021)->value('slug');
    $html = $this->get('/product/'.$slug.'/')->assertOk()->getContent();

    expect($tabs)->toHaveCount(1)
        ->and($tabs[0]['title'])->toBe('Major & Minor')
        ->and($tabs[0]['body'])->not->toContain('<script')->not->toContain('onerror')
            ->not->toContain('javascript')->not->toContain('style=')->not->toContain('<iframe')
        ->and($html)->toContain('>Major &amp; Minor</button>')
        ->and($html)->not->toContain('alert(');
});

it('re-imports without duplicating, and reports the second pass unchanged', function () {
    ptImport();
    $first = ptTabs();

    $report = ptImport();

    expect(ptTabs())->toBe($first)
        ->and($report->for('products')->created)->toBe(0)
        ->and($report->for('products')->updated)->toBe(0);
});

it('never touches a tab the owner wrote, and keeps his on/off and order on an imported one', function () {
    ptImport();
    $id = (int) Product::query()->where('wc_id', 4021)->value('id');

    $own = ProductTab::query()->forceCreate([
        'product_id' => $id, 'title' => 'Owner tab', 'body' => '<p>Mine.</p>', 'position' => 500, 'is_enabled' => true,
    ]);
    ProductTab::query()->where('product_id', $id)->where('import_key', 'wc:2')->update(['is_enabled' => false, 'position' => 70]);

    // The old shop renamed its first tab and dropped the second.
    ptImport(ptExport([4021 => [['title' => 'Key Ingredients', 'content' => 'Ginseng.']]]));

    $rows = ProductTab::query()->where('product_id', $id)->orderBy('id')->get();

    expect($rows->pluck('title')->all())->toBe(['Key Ingredients', 'Owner tab'])
        ->and($rows->firstWhere('id', $own->id)->body)->toBe('<p>Mine.</p>');
});

it('leaves imported tabs alone when the export predates the column, and clears them when it says none', function () {
    /*
     * An export from a build before 1.9.0 has no `custom_tabs` column at all.
     * Re-running one must not wipe the tabs a newer export brought: absent is
     * "not carried", not "none".
     *
     * MUTATION, RUN: tabsFrom() returning [] instead of null for an absent
     * column -- red, the serum's two tabs were deleted.
     */
    ptImport();
    ptImport(ptExport([], dropColumn: true));
    expect(ptTabs())->toHaveCount(2);

    ptImport(ptExport([4021 => '']));
    expect(ptTabs())->toBe([]);
});

it('names a tab plugin the exporter does not read, in the manifest', function () {
    $notes = json_decode((string) file_get_contents(base_path('tests/Fixtures/kbb-export/manifest.json')), true)['notes'];

    expect(collect($notes)->first(fn ($n) => str_contains($n, '`_kbb_unknown_tab_plugin`')))->not->toBeNull();
});
