<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Support\RichText;

/**
 * App\Support\RichText is the only door, and every write has to go through it.
 *
 * `products.description`, `short_description`, `ingredients` and `how_to_use`
 * are all rendered with `{!! !!}` on the public product page —
 * Store\ProductController::tabs() builds the four tabs out of those columns and
 * partials/product-tabs.blade.php prints each `$tab['body']` raw, twice. So the
 * value that lands in the column is the value a browser executes.
 *
 * ProductEditorApiController::applyRichText() understood that and ran
 * RichText::clean() over all four fields. TWO OTHER WRITE PATHS REACHED THE
 * SAME COLUMNS WITHOUT IT:
 *
 *   - CatalogProductsApiController::update(), the Catalog → Products inline
 *     editor, copied `short_description` and `description` straight out of the
 *     validated payload into $changes. `string|max:200000` is a length check,
 *     not a sanitiser.
 *
 *   - ProductImporter::import(), which writes `description` and
 *     `short_description` from the CSV's `description` / `post_content`
 *     columns verbatim. This is the one with a threat model that does not need
 *     a hostile admin: a WooCommerce export is a THIRD-PARTY FILE. The store
 *     owner is importing a catalogue somebody else generated, and every byte of
 *     post_content in it is attacker-controlled the moment that somebody is not
 *     trustworthy. The owner does nothing wrong and ships stored XSS to every
 *     visitor of every imported product page.
 *
 * The assertions below are against the CONSEQUENCE — what the public product
 * page serves — not against which function was called. A test that asserts
 * `RichText::clean()` appears in the controller keeps passing if someone swaps
 * it for a no-op; a test that fetches the page and looks for the payload does
 * not.
 */

/* ------------------------------------------------------------------ fixtures */

function rtwAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'RTW Owner',
        'email' => 'rtw-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function rtwProduct(array $attributes = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'name' => 'RTW Product '.$n,
        'slug' => 'rtw-product-'.$n.'-'.uniqid(),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ], $attributes));
}

/** The product page as a visitor gets it. */
function rtwProductPage(Product $product): string
{
    return (string) test()->get('/product/'.$product->slug.'/')->getContent();
}

/**
 * A payload that is unambiguous in both directions: if RichText ran, the
 * <script> element is gone whole (it is in the hostile set, dropped with its
 * children), so neither the tag nor its body survives.
 */
const RTW_PAYLOAD = '<p>Real copy.</p><script>alert(String.fromCharCode(88,83,83))</script>';

const RTW_IMG_PAYLOAD = '<img src=x onerror="alert(1)">';

/* ------------------------------------------- the sanitiser itself still works */

it('strips script elements whole and drops event handlers', function () {
    $clean = RichText::clean(RTW_PAYLOAD);

    expect($clean)->not->toContain('<script')
        ->and($clean)->not->toContain('fromCharCode')
        ->and($clean)->toContain('Real copy.');

    $img = RichText::clean(RTW_IMG_PAYLOAD);

    expect($img)->not->toContain('onerror');
});

/* ------------------------------------- 1. the Catalog → Products inline editor */

it('sanitises description written through the catalog products save endpoint', function () {
    $product = rtwProduct();

    test()->actingAs(rtwAdmin(), 'admin')
        ->post('/admin-api/catalog-products-save/'.$product->id, [
            'description' => RTW_PAYLOAD,
        ])
        ->assertOk();

    $stored = (string) $product->fresh()->description;

    expect($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('fromCharCode')
        ->and($stored)->toContain('Real copy.');

    // And the consequence: nothing executable reaches the public page.
    expect(rtwProductPage($product->fresh()))->not->toContain('fromCharCode');
});

it('sanitises short_description written through the catalog products save endpoint', function () {
    $product = rtwProduct();

    test()->actingAs(rtwAdmin(), 'admin')
        ->post('/admin-api/catalog-products-save/'.$product->id, [
            'short_description' => RTW_IMG_PAYLOAD.'<p>Blurb.</p>',
        ])
        ->assertOk();

    $stored = (string) $product->fresh()->short_description;

    expect($stored)->not->toContain('onerror')
        ->and($stored)->toContain('Blurb.');
});

/* ------------------------------------------------ 2. the WooCommerce importer */

it('sanitises product descriptions arriving from a third-party CSV export', function () {
    $dir = sys_get_temp_dir().'/kbb-rtw-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    foreach (glob(base_path('tests/Fixtures/woo').'/*.csv') ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    // Plant the payload in the description column of the first data row, the
    // way a hostile or compromised exporter would.
    $path = $dir.'/products.csv';
    $rows = array_values(array_filter(explode("\n", (string) file_get_contents($path)), fn ($l) => trim($l) !== ''));

    $rows[1] = preg_replace('/,A serum\.$/', ',"'.str_replace('"', '""', RTW_PAYLOAD).'"', $rows[1]);

    file_put_contents($path, implode("\n", $rows)."\n");

    (new ImportRunner)->run(new ImportOptions(directory: $dir, adoptBySlug: true));

    $product = Product::where('wc_id', 4021)->first();

    expect($product)->not->toBeNull();

    $stored = (string) $product->description;

    expect($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('fromCharCode')
        ->and($stored)->toContain('Real copy.');
});
