<?php

declare(strict_types=1);

/*
 * SOLD OUT, SAID ON THE GRID CARD.                                   (Lane PX)
 *
 * The owner, with a phone screenshot of a sold-out CeraVe card — the button
 * circled, the photo's bottom-right corner boxed: "the sold out product should
 * have proper sold out label somewhere on the grid."
 *
 * THE DEFECT, AS IT LOOKED ON THE SHOP: a product that was out of stock drew a
 * pink "View product" button and nothing on its photograph — exactly what
 * every in-stock VARIABLE product draws — so a shopper could not tell "pick a
 * size" from "gone" without opening it.
 *
 * Appearance → Product styles → Card content → Sold-out label (also on
 * Appearance → Product grid → What each card shows), ON by default because he
 * asked (CLAUDE.md rule 1, 30 September). Off is the card that shipped before,
 * byte for byte.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ProductStyles;
use App\Services\SettingsService;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function pxSoFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    app()->forgetScopedInstances();
}

function pxSoProduct(string $slug, array $over = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'px-brand'], ['name' => 'PX Brand']);

    return Product::create(array_merge([
        'slug' => $slug,
        'name' => 'PX '.$slug,
        'status' => 'publish', 'is_visible' => true, 'type' => 'simple',
        'brand_id' => $brand->id, 'price' => 8900, 'stock_status' => 'instock',
    ], $over));
}

/** The tile that carries this product's name, cut out of the page. */
function pxSoTile(string $html, string $name): string
{
    $at = strpos($html, '<span class="kbb-card-nm">'.$name.'</span>');
    expect($at)->not->toBeFalse("no card for {$name}");
    $start = strrpos(substr($html, 0, $at), '<div class="kbb-card kbb-tile">');
    $end = strpos($html, '<div class="kbb-card kbb-tile">', $at);

    return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
}

function pxSoShop(): string
{
    pxSoFresh();

    return (string) test()->get('/shop/')->assertOk()->getContent();
}

it('labels a sold-out card on the photo and on its button, and nothing else', function () {
    $gone = pxSoProduct('px-gone', ['stock_status' => 'outofstock']);
    pxSoProduct('px-here');

    $html = pxSoShop();
    $tile = pxSoTile($html, 'PX px-gone');

    // The pill, in the photo frame, once.
    expect(substr_count($tile, '<span class="kbb-soldout">Sold out</span>'))->toBe(1);
    $thumb = substr($tile, 0, strpos($tile, '<div class="cb">'));
    expect($thumb)->toContain('kbb-soldout');

    // The button says it, keeps its link to the product, binds no add.
    expect($tile)->toContain('<a class="kbb-card-cart kbb-card-soldout" href="'.e($gone->url()).'">Sold out</a>')
        ->and($tile)->not->toContain('View product')
        ->and($tile)->not->toContain('data-kbb-add');

    // The in-stock card beside it is untouched.
    $here = pxSoTile($html, 'PX px-here');
    expect($here)->not->toContain('kbb-soldout')
        ->and($here)->toContain('>Add to cart</a>');

    // One of each on the sold-out tile; the catalogue's other cards are
    // judged by their own stock, not by this one.
    expect(substr_count($tile, 'kbb-card-soldout'))->toBe(1);
    // MUTATION NOTE — RUN: drop `$kbbSoldPill` from the thumb line in
    // components/product-card.blade.php and the first expectation is red.
});

it('does not call a variable product in stock sold out', function () {
    /*
     * A variable product draws "View product" because it is bought by its
     * size, not because it is gone. Its parent row says `instock`, and that is
     * what this card reads — the sizes are the product page's business.
     *
     * MUTATION NOTE — RUN: make $kbbSoldOut `! $kbbCanAdd && $kbbShows[...]`
     * (drop the stock test) and this goes red: the in-stock variable is
     * labelled Sold out.
     */
    pxSoProduct('px-var', ['type' => 'variable', 'price' => null]);
    pxSoProduct('px-var-gone', ['type' => 'variable', 'price' => null, 'stock_status' => 'outofstock']);

    $html = pxSoShop();

    $var = pxSoTile($html, 'PX px-var');
    expect($var)->toContain('>View product</a>')
        ->and($var)->not->toContain('kbb-soldout');

    // Every size gone is the parent `outofstock`, and that IS sold out.
    $gone = pxSoTile($html, 'PX px-var-gone');
    expect($gone)->toContain('kbb-card-soldout')
        ->and($gone)->toContain('<span class="kbb-soldout">Sold out</span>');
});

it('ships on, and off puts back the card that shipped before, byte for byte', function () {
    expect(ProductStyles::SCHEMA['show_soldout'][0])->toBe('bool')
        ->and(ProductStyles::SCHEMA['show_soldout'][2])->toBeTrue()
        ->and(ProductStyles::TABS['content'][2])->toContain('show_soldout');

    $gone = pxSoProduct('px-gone', ['stock_status' => 'outofstock']);
    app(ProductStyles::class)->save(['show_soldout' => false]);

    $tile = pxSoTile(pxSoShop(), 'PX px-gone');

    // The old card: "View product", no class, no pill — and no whitespace left
    // behind where the pill would go (the line still opens on its 8 spaces and
    // runs straight into the quick-view button or the frame's close).
    expect($tile)->toContain('<a class="kbb-card-cart" href="'.e($gone->url()).'">View product</a>')
        ->and($tile)->not->toContain('kbb-soldout')
        ->and($tile)->not->toContain('Sold out');
});

it('costs no query: a sold-out card reads the row it already has', function () {
    /*
     * Rule 4. `stock_status` is a column of the product row, so the label adds
     * nothing to the page's query count — measured, not asserted: the same
     * /shop/ with one card sold out and with none.
     */
    foreach (range(1, 3) as $i) {
        pxSoProduct('px-q'.$i);
    }

    $count = function (): int {
        pxSoFresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/shop/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(); // warm the caches both runs then share
    $none = $count();

    Product::where('slug', 'px-q2')->update(['stock_status' => 'outofstock']);
    $one = $count();

    expect(pxSoTile(pxSoShop(), 'PX px-q2'))->toContain('class="kbb-soldout"')
        ->and($one)->toBe($none);
});

it('is readable on every skin and mirrors in Arabic (the stylesheet)', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    // Bottom-END, so /ar puts it bottom-left; a dark pill with white type.
    expect($css)->toContain('.kbb-tile .kbb-soldout{position:absolute;bottom:9px;inset-inline-end:9px;')
        // Showcase dissolves the thumb (display:contents): the pill must name
        // the photograph's grid area, or it falls to the foot of the card —
        // which is where the first build of this drew it, under the button.
        ->and($css)->toContain('.kbb-pgrid[data-skin^="showcase"] .kbb-soldout{grid-area:scphoto}')
        // The muted button beats every skin's own button colour.
        ->and($css)->toContain('.kbb-tile .kbb-card-cart.kbb-card-soldout{background:#EFE7E9!important;color:#6E6166!important;');

    // And it is in the BUILT sheet the shop actually loads.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb.css']['file']));
    expect($built)->toContain('kbb-soldout')->and($built)->toContain('grid-area:scphoto');
});

it('carries its words in both languages, the Arabic as a draft to approve', function () {
    expect(InterfaceStrings::english('store.product_card.sold_out'))->toBe('Sold out')
        ->and(ArabicInterfaceDrafts::all())->toHaveKey('store.product_card.sold_out');

    $field = \App\Services\Translation\TranslationStore::normaliseKey('store.product_card.sold_out');
    $row = DB::table('translations')->where('locale', 'ar')->where('field', $field)->first();

    expect($row)->not->toBeNull('the seed migration did not put the Arabic on the review list')
        ->and($row->status)->toBe(\App\Models\Translation::STATUS_DRAFT);
});

it('surfaces the one switch on Appearance → Product grid, once, saving the same key', function () {
    $admin = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($admin, "['show_soldout',   'Sold-out label',"))->toBe(1)
        ->and(substr_count($admin, "show_soldout: 'pc-nosoldout',"))->toBe(1)
        ->and(\App\Support\AdminSearchIndex::CURATED['layout'][''] ?? [])->toContain('Sold-out label');

    // The endpoint that owns the key stores it.
    $owner = AdminUser::create([
        'name' => 'PX Owner', 'email' => 'px-owner@example.test',
        'password' => Hash::make('secret-secret'), 'role' => 'owner',
    ]);
    test()->actingAs($owner, 'admin')
        ->postJson('/admin-api/product-styles', ['settings' => ['show_soldout' => false]])
        ->assertOk();
    pxSoFresh();

    expect(app(ProductStyles::class)->all()['show_soldout'])->toBeFalse();
});
