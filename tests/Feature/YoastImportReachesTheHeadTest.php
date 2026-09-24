<?php

declare(strict_types=1);

/**
 * Lane E — the seam between the Yoast importer and the rendered <head>.
 *
 * ── WHY THIS FILE EXISTS, AND WHY THE TWO EXISTING FILES DO NOT COVER IT ────
 *
 * Per-product SEO is three parts that have to agree about two things — a column
 * name and a key vocabulary — and this shop has already shipped a version where
 * they did not, twice, in the same feature:
 *
 *   1. `Admin\AdminController` wrote `products.seo_json` while every reader in
 *      the application read `products.seo`. The editor round-tripped the value
 *      back to the operator, so the screen looked like it worked, and Google
 *      was never told. Fixed by 2026_10_05_000001_migrate_product_seo_json_into_seo.
 *
 *   2. Even pointed at the right column the payload published nothing, because
 *      the admin collected Yoast-shaped key names (`seo_title`,
 *      `meta_description`) and the storefront reads `title` and `desc`. That is
 *      what `App\Support\ProductSeo::RENAME` exists to reconcile.
 *
 * Both defects were live while BOTH halves had passing tests, and that is the
 * point. `YoastSeoImportTest` asserts what the importer writes to the model and
 * never issues an HTTP request — grep it for `->get(` and there is not one.
 * `ProductSeoTest` asserts the rendered <head> and never runs the importer —
 * grep it for `SeoImporter` and there is not one. Each half is green over a
 * join that is broken, which is precisely the shape of both defects above.
 *
 * So every assertion below drives the REAL importer over a REAL export row and
 * then fetches the product page through the full kernel and reads the bytes a
 * crawler is served. Nothing here asserts against `$product->seo`; that is
 * `YoastSeoImportTest`'s job and it does it well.
 *
 * ── MUTATION NOTES ──────────────────────────────────────────────────────────
 *
 * Each test carries its own. The one that covers the whole file: in
 * `App\Support\YoastSeo::MAPPED`, change `'_yoast_wpseo_title' => 'title'` to
 * `=> 'seo_title'` — the pre-rename spelling, which is a perfectly plausible
 * edit and which every test in `YoastSeoImportTest` that checks the stored blob
 * would have to be updated to match — and the first test here goes red while
 * the import itself still "succeeds". Likewise change `'seo'` to `'seo_json'`
 * in `SeoImporter::import()`'s `$context->apply()` call: the importer reports
 * the row imported, the column is written, and this file goes red.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Import\Entities\SeoImporter;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\Row;

const YRH_BASE = 'https://kbeautybliss.test';

function yrhSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => YRH_BASE,
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_separator' => '|',
        'seo_title_template' => '{title} {sep} {sitename}',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

beforeEach(function () {
    yrhSettings();
});

function yrhProduct(int $wcId, string $slug): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'yrh-anua'], ['name' => 'Anua']);

    return Product::create([
        'wc_id' => $wcId,
        'slug' => $slug,
        'name' => 'Heartleaf Quercetinol Toner',
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        // A real short description, so a product with NO imported meta still
        // has a fallback to build one from. Test 4 depends on this existing.
        'short_description' => 'A daily toner for calm, even-looking skin that never feels tight.',
    ]);
}

/** One export row through the real importer, on the real runner's entity. */
function yrhImport(int $wcId, array $yoast): void
{
    $context = new ImportContext(
        new ImportOptions(directory: sys_get_temp_dir()),
        new ImportReport(false),
    );

    (new SeoImporter)->import(new Row(2, array_merge(['id' => (string) $wcId], $yoast)), $context);
}

/** The <head> the crawler is served, so no match can come from body copy. */
function yrhHead(string $slug): string
{
    $html = test()->get('/product/'.$slug.'/')->assertOk()->getContent();

    expect((bool) preg_match('#<head>(.*?)</head>#s', $html, $m))
        ->toBeTrue('the product page rendered no <head> at all — the parse is wrong, not the assertion');

    return $m[1];
}

/*
|------------------------------------------------------------------------------
| 1. An imported title and description reach the page
|------------------------------------------------------------------------------
*/

it('publishes an imported title and description in the rendered head', function () {
    yrhProduct(5101, 'yrh-toner');

    yrhImport(5101, [
        '_yoast_wpseo_title' => 'Anua Heartleaf Toner for Calm Skin',
        '_yoast_wpseo_metadesc' => 'A daily toner for calm, even-looking skin.',
    ]);

    $head = yrhHead('yrh-toner');

    /*
     * MUTATION: in App\Support\YoastSeo::MAPPED, rename the target of
     * `_yoast_wpseo_title` from 'title' to anything else — this goes red and
     * the import still reports the row imported.
     *
     * The title is published VERBATIM with no template applied: an operator who
     * wrote a title in Yoast wrote the whole title, and appending " | K-Beauty
     * Bliss" to it would silently lengthen every imported title past the point
     * Google truncates. Store\ProductController sets title_is_final for exactly
     * this reason, and asserting the site name is ABSENT is what pins it —
     * asserting only that the title contains the imported string would pass
     * just as happily with the template applied on top.
     */
    expect($head)->toContain('<title>Anua Heartleaf Toner for Calm Skin</title>')
        ->not->toContain('<title>Anua Heartleaf Toner for Calm Skin | K-Beauty Bliss</title>');

    expect($head)->toContain('content="A daily toner for calm, even-looking skin."');
});

/*
|------------------------------------------------------------------------------
| 2. The canonical, which is the one that can deindex a shop by itself
|------------------------------------------------------------------------------
*/

it('publishes an imported canonical over the computed one', function () {
    yrhProduct(5102, 'yrh-canon');

    yrhImport(5102, [
        '_yoast_wpseo_canonical' => 'https://kbeautybliss.com/product/anua-heartleaf-toner/',
    ]);

    $head = yrhHead('yrh-canon');

    /*
     * MUTATION: drop the `canonical` branch from Store\ProductController's
     * override block and this goes red.
     *
     * Both halves are asserted, and the second is the one that matters: a page
     * carrying TWO canonicals — the imported one and the computed one — is not
     * a page that honours the import, it is a page Google ignores the canonical
     * on entirely. Asserting only ->toContain() would pass on that.
     */
    expect($head)->toContain('href="https://kbeautybliss.com/product/anua-heartleaf-toner/"')
        ->not->toContain(YRH_BASE.'/product/yrh-canon/');

    expect(substr_count($head, 'rel="canonical"'))->toBe(1);
});

/*
|------------------------------------------------------------------------------
| 3. noindex is TRISTATE, and reading it as a boolean deindexes a catalogue
|------------------------------------------------------------------------------
*/

it('noindexes the page for Yoast 1 and leaves it indexable for Yoast 2 and blank', function () {
    yrhProduct(5103, 'yrh-hidden');
    yrhProduct(5104, 'yrh-shown');
    yrhProduct(5105, 'yrh-default');

    yrhImport(5103, ['_yoast_wpseo_meta-robots-noindex' => '1']);
    yrhImport(5104, ['_yoast_wpseo_meta-robots-noindex' => '2']);
    yrhImport(5105, ['_yoast_wpseo_meta-robots-noindex' => '', '_yoast_wpseo_metadesc' => 'Something.']);

    /*
     * MUTATION: in App\Support\YoastSeo, read the field with a plain cast —
     * `(bool) $value` — instead of the tristate. '2' becomes true and
     * `yrh-shown` goes red.
     *
     * '1' is noindex, '2' is Yoast's explicit "index", '' is "use the site
     * default". A boolean read turns '2' into noindex and takes every product
     * whose operator deliberately ticked INDEX out of Google — the most
     * expensive possible direction for this bug, and silent on the shop because
     * the page renders perfectly either way.
     */
    expect(yrhHead('yrh-hidden'))->toContain('noindex');
    expect(yrhHead('yrh-shown'))->not->toContain('noindex');
    expect(yrhHead('yrh-default'))->not->toContain('noindex');
});

/*
|------------------------------------------------------------------------------
| 4. A blank export field must not strip the description off the catalogue
|------------------------------------------------------------------------------
*/

it('leaves the built description standing when the export carries a blank one', function () {
    yrhProduct(5106, 'yrh-blank');

    // The common row in a real export: the operator never opened the SEO tab.
    yrhImport(5106, [
        '_yoast_wpseo_title' => '',
        '_yoast_wpseo_metadesc' => '',
    ]);

    $head = yrhHead('yrh-blank');

    /*
     * MUTATION: in App\Support\YoastSeo, treat '' as a value rather than as
     * absent — store `desc => ''` — and this goes red.
     *
     * Stored as '', App\Support\Seo emits no description tag at all; it does
     * NOT fall back to the sitewide default. So importing the blanks strips the
     * search snippet off most of a 671-product catalogue in one run, and the
     * import reports every row imported successfully while doing it. Asserted
     * on the rendered page because that is the only place the damage is
     * visible: the column looks fine either way.
     */
    expect($head)->toContain('name="description"')
        ->toContain('calm, even-looking skin');

    /*
     * And the title falls back to the one the page builds for itself —
     * ProductTitle::head(), brand + name — rather than to an empty tag. Not
     * asserted as a literal: the exact separator and the {sitename} handling
     * are `seo_title_template`'s business and are pinned by SeoRenderTest. What
     * matters here is that a blank import did not blank the title.
     */
    preg_match('#<title>(.*?)</title>#s', $head, $t);

    expect($t[1] ?? '')->toContain('Heartleaf Quercetinol Toner')->not->toBe('');
});

/*
|------------------------------------------------------------------------------
| 5. Yoast's template tokens, resolved and deleted, on the real page
|------------------------------------------------------------------------------
*/

it('resolves the four Yoast tokens this storefront supports', function () {
    yrhProduct(5107, 'yrh-tokens');

    yrhImport(5107, [
        '_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%',
    ]);

    /*
     * MUTATION: remove %%sitename%% from the token table TitleTemplate renders
     * and this goes red.
     *
     * A Yoast title is a TEMPLATE, not a string, and the overwhelming majority
     * of a real export is this exact one. If the tokens did not resolve, every
     * product on the shop would publish the literal text "%%title%% %%sep%%
     * %%sitename%%" in its browser tab and its search result — which is the
     * single most visible way this import can go wrong, and is invisible in a
     * test that only reads the stored column, where the template is correct.
     */
    expect(yrhHead('yrh-tokens'))
        ->toContain('<title>Heartleaf Quercetinol Toner | K-Beauty Bliss</title>')
        ->not->toContain('%%');
});

it('deletes a token this storefront cannot resolve, as the import report warns', function () {
    yrhProduct(5108, 'yrh-unresolvable');

    yrhImport(5108, [
        '_yoast_wpseo_title' => 'Buy %%title%% in %%currentyear%%',
    ]);

    /*
     * MUTATION: make TitleTemplate leave an unknown token in place and this
     * goes red on the `%%` assertion.
     *
     * SeoImporter reports this row as an ADJUSTMENT — imported, and not what
     * the export said — and says the value "is imported as written and DELETED
     * from the page when the tag is rendered". This is that claim checked
     * against the actual page rather than trusted: the report is only worth
     * reading if what it predicts is what the crawler gets. The literal braces
     * must never reach the browser tab, which is the failure the old renderer
     * shipped once already (SeoRenderTest's third bullet).
     */
    $head = yrhHead('yrh-unresolvable');

    expect($head)->toContain('Buy Heartleaf Quercetinol Toner in')
        ->not->toContain('%%')
        ->not->toContain('currentyear');
});

/*
|------------------------------------------------------------------------------
| 6. The owner's typing wins, all the way to the page
|------------------------------------------------------------------------------
*/

it('does not let the export overwrite what an operator typed in this admin', function () {
    $product = yrhProduct(5109, 'yrh-typed');

    // What the product editor's Search appearance panel writes.
    $product->update(['seo' => ['title' => 'The Title Rafi Typed', 'desc' => 'The description Rafi typed.']]);

    yrhImport(5109, [
        '_yoast_wpseo_title' => 'The Five-Year-Old Yoast Title',
        '_yoast_wpseo_metadesc' => 'The five-year-old Yoast description.',
    ]);

    /*
     * MUTATION: flip `overwrite: false` to `true` in SeoImporter::import()'s
     * call to YoastSeo::merge() and this goes red.
     *
     * The export predates this admin by five years. An import that overwrites
     * is one that silently undoes every correction the owner has made since,
     * across the whole catalogue, in a single run — and because the import
     * reports success and the page renders fine, nothing anywhere says it
     * happened. Asserted on the page rather than the column so it also covers
     * the case where the merge is right and the reader picks the wrong key.
     */
    $head = yrhHead('yrh-typed');

    expect($head)->toContain('<title>The Title Rafi Typed</title>')
        ->toContain('The description Rafi typed.')
        ->not->toContain('Five-Year-Old')
        ->not->toContain('five-year-old');
});

/*
|------------------------------------------------------------------------------
| 7. Re-running the import changes nothing on the page. The import is idempotent
|------------------------------------------------------------------------------
*/

it('renders byte-identical markup when the same export is imported twice', function () {
    yrhProduct(5110, 'yrh-twice');

    $row = [
        '_yoast_wpseo_title' => 'Anua Heartleaf Toner',
        '_yoast_wpseo_metadesc' => 'A daily toner for calm, even-looking skin.',
        '_yoast_wpseo_canonical' => 'https://kbeautybliss.com/product/anua-heartleaf-toner/',
    ];

    yrhImport(5110, $row);
    $first = yrhHead('yrh-twice');

    yrhImport(5110, $row);
    $second = yrhHead('yrh-twice');

    /*
     * MUTATION: make YoastSeo::merge() append rather than replace on a key it
     * already holds, and the second head differs from the first.
     *
     * Phase 13's standing property for every importer in this application is
     * that a second pass reports created 0, updated 0 and leaves a
     * byte-identical database. This is the same property asserted one layer
     * further out, where the owner would actually notice it: the page a crawler
     * is served must not move because somebody ran the import twice.
     */
    expect($second)->toBe($first);
});

/*
|------------------------------------------------------------------------------
| 8. A new column on `products` does not join the public API
|------------------------------------------------------------------------------
*/

it('keeps imported SEO out of the unauthenticated API response', function () {
    yrhProduct(5111, 'yrh-api');

    yrhImport(5111, [
        '_yoast_wpseo_title' => 'Anua Heartleaf Toner',
        '_yoast_wpseo_metadesc' => 'A daily toner for calm, even-looking skin.',
        '_yoast_wpseo_canonical' => 'https://kbeautybliss.com/product/anua-heartleaf-toner/',
    ]);

    /*
     * MUTATION: add 'seo' to Product::toApi()'s returned array and this goes
     * red.
     *
     * `/api/*` is unauthenticated and `Product::toApi()` is an allowlist, which
     * is why tests/Feature/ApiSecurityTest.php exists: every case in it leaked
     * in production first. The `seo` blob is not shopper-facing data — it
     * carries the operator's canonical targets and noindex decisions, and a
     * focus keyphrase the editor collects and nothing publishes — so it stays
     * out until the owner asks for it. This is the guard on THIS lane's work
     * specifically: the whole point of the feature is to put more into that
     * column, and the allowlist is what stops the column becoming public as a
     * side effect.
     */
    $payload = test()->getJson('/api/products')->assertOk()->json();

    expect(json_encode($payload))
        ->not->toContain('kbeautybliss.com/product/anua-heartleaf-toner')
        ->not->toContain('_yoast')
        ->not->toContain('"seo"');
});
