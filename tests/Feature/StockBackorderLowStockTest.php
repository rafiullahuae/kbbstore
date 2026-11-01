<?php

declare(strict_types=1);

/*
 * Lane C. The owner:
 *
 *   "We should be able to set low stock quantity for each product on backend
 *    also on owner app, AND the back stock option should not turn the product
 *    to out of stock."
 *   "and back order will give option for us on admin to write 'on back order'
 *    or show red minus quantity so we will know it's back order product."
 *
 * WHAT IT LOOKED LIKE BEFORE: one low-stock line for the whole shop (owner app
 * 5, admin 15), nothing per product. And "Allow backorders" in the owner app
 * saved stock_status = 'onbackorder', which every shop page reads as SOLD OUT:
 * the card said "Sold out", the buy button was greyed and POST /api/cart/add
 * answered 422 "That product is sold out." StockClaim also refused any order
 * the shelf could not cover, so a product could never go below zero.
 */

use App\Services\StockUnavailable;
use App\Models\AdminUser;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SettingsService;
use App\Services\StockClaim;
use App\Support\StockLevels;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogProductsAdminRoutes;
use Tests\Support\OwnerAppRoutes as OA;
use Tests\Support\ProductEditorRoutes;

function sblProduct(array $over = []): Product
{
    static $n = 0;
    $n++;

    return Product::query()->create(array_merge([
        'slug' => 'sc-p-'.$n.'-'.uniqid(), 'name' => 'Stock Case '.$n, 'sku' => 'SC-'.$n.'-'.uniqid(),
        'price' => 9900, 'status' => 'publish', 'is_visible' => true,
        'manage_stock' => true, 'stock' => 20, 'stock_status' => 'instock',
    ], $over));
}

function sblAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'SC '.$role, 'email' => 'sc-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function sblClaim(Product $p, int $qty, ?ProductVariant $v = null): void
{
    DB::transaction(fn () => app(StockClaim::class)->claim([[
        'product_id' => (int) $p->id, 'variant_id' => $v?->id, 'quantity' => $qty, 'label' => (string) $p->name,
    ]], null));
}

function sblOwnerApp($test): array
{
    OA::wire(app());
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    OA::member(OA::admin());
    [$c, $csrf] = OA::enrol($test);

    return [$c, $csrf];
}

/* ============================================================ A. low stock */

it('counts a product as low at its own level, and at the default when it has none, in the owner app', function () {
    // DEFECT: one line for every product -- a serum that sells 30 a week was
    // "fine" at 5 and a slow mask "low" at 5. MUTATION: drop the COALESCE in
    // StockLevels::lowSql() (compare to the default only) and A drops out of
    // the count while C comes in.
    $a = sblProduct(['stock' => 8, 'low_stock_amount' => 10]);   // own 10: low
    $b = sblProduct(['stock' => 4]);                              // default 5: low
    $c = sblProduct(['stock' => 4, 'low_stock_amount' => 2]);    // own 2: NOT low
    sblProduct(['stock' => 8]);                                   // default 5: not low
    [$ck] = sblOwnerApp($this);

    $list = OA::get($this, 'products?filter=low', $ck)->assertOk()->json();
    expect(collect($list['products'])->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($list['counts']['low'])->toBe(2);

    $all = collect(OA::get($this, 'products', $ck)->json('products'))->keyBy('id');
    expect($all[$a->id]['low'])->toBeTrue()->and($all[$c->id]['low'])->toBeFalse()
        ->and($all[$a->id]['low_stock_amount'])->toBe(10)->and($all[$b->id]['low_stock_amount'])->toBeNull();

    $needs = collect(OA::get($this, 'dashboard', $ck)->json('needs'))->firstWhere('icon', 'stack');
    expect($needs['title'])->toBe('2 products low on stock');
});

it('counts a product as low at its own level in the admin catalogue, else at 15', function () {
    // MUTATION: put `self::LOW_STOCK` back in place of LOW_SQL in
    // CatalogProductsApiController::applyFilter() and $own is missing.
    CatalogProductsAdminRoutes::wire(app());
    test()->actingAs(sblAdmin(), 'admin');
    $own = sblProduct(['stock' => 20, 'low_stock_amount' => 25]);   // own 25: low
    $dflt = sblProduct(['stock' => 10]);                             // default 15: low
    sblProduct(['stock' => 10, 'low_stock_amount' => 3]);            // own 3: NOT low

    $r = test()->getJson('/admin-api/catalog-products-list?filter=low')->assertOk()->json();
    expect(collect($r['data'] ?? $r['products'] ?? $r['rows'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$own->id, $dflt->id])->sort()->values()->all())
        ->and($r['counts']['low'])->toBe(2);
});

it('keeps the low-stock lists and Needs you at the same query count for 3 products and for 40', function (string $path) {
    // DEFECT: a per-product level read row by row. MUTATION: look the level up
    // with a query inside ProductsController::row() and this is red.
    [$ck] = sblOwnerApp($this);
    $count = function (int $n) use ($path, $ck) {
        Product::query()->where('slug', 'like', 'sc-p-%')->forceDelete();
        for ($i = 0; $i < $n; $i++) {
            sblProduct(['stock' => $i % 3 === 0 ? -2 : 3, 'low_stock_amount' => $i % 2 ? 4 : null, 'backorders' => $i % 3 === 0]);
        }
        OA::get($this, $path, $ck)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        OA::get($this, $path, $ck)->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(40))->toBe($count(3));
})->with(['products', 'products?filter=low', 'dashboard']);

it('keeps the admin catalogue list flat with levels and back orders', function () {
    CatalogProductsAdminRoutes::wire(app());
    test()->actingAs(sblAdmin(), 'admin');
    $count = function (int $n) {
        Product::query()->where('slug', 'like', 'sc-p-%')->forceDelete();
        for ($i = 0; $i < $n; $i++) {
            sblProduct(['stock' => $i % 3 === 0 ? -2 : 3, 'low_stock_amount' => $i % 2 ? 4 : null, 'backorders' => $i % 3 === 0]);
        }
        test()->getJson('/admin-api/catalog-products-list?per_page=50')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/admin-api/catalog-products-list?per_page=50')->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(40))->toBe($count(3));
});

it('saves a product\'s own low-stock level from the admin editor, and empties it back to the default', function () {
    ProductEditorRoutes::wire(app());
    test()->actingAs(sblAdmin(), 'admin');
    $p = sblProduct();

    test()->postJson('/admin-api/product-editor-save/'.$p->id, ['low_stock_amount' => 7])->assertOk()
        ->assertJsonPath('product.low_stock_amount', 7);
    expect(Product::query()->find($p->id)->low_stock_amount)->toBe(7);

    test()->postJson('/admin-api/product-editor-save/'.$p->id, ['low_stock_amount' => -1])->assertStatus(422);
    test()->postJson('/admin-api/product-editor-save/'.$p->id, ['low_stock_amount' => null])->assertOk();
    expect(Product::query()->find($p->id)->low_stock_amount)->toBeNull();
});

it('saves the level from the owner app, and refuses it behind edit_stock and the role', function () {
    // DEFECT: a new field outside the owner app's function switch. MUTATION:
    // drop 'low_stock_amount' from OwnerAppUiGate::PRODUCT_FIELDS and the
    // switched-off save goes through.
    $p = sblProduct();
    [$ck, $csrf] = sblOwnerApp($this);

    OA::post($this, 'products/'.$p->id, ['low_stock_amount' => 9], $ck, $csrf)->assertOk()->assertJsonPath('product.low_stock_amount', 9);
    expect(Product::query()->find($p->id)->low_stock_amount)->toBe(9);

    $ui = array_replace_recursive(\App\Services\OwnerApp\OwnerAppUi::defaults(), ['functions' => ['edit_stock' => false]]);
    expect(\App\Services\OwnerApp\OwnerAppUi::put($ui))->toBeNull();
    expect(OA::post($this, 'products/'.$p->id, ['low_stock_amount' => 1], $ck, $csrf)->json('code'))->toBe('off');
    expect(Product::query()->find($p->id)->low_stock_amount)->toBe(9);
    expect(\App\Http\Middleware\OwnerAppUiGate::PRODUCT_FIELDS['low_stock_amount'])->toBe('edit_stock')
        ->and(\App\Http\Controllers\OwnerApp\ProductsController::EDITABLE)->toContain('low_stock_amount');
});

it('refuses the new fields to an admin without catalog.manage', function () {
    ProductEditorRoutes::wire(app());
    test()->actingAs(sblAdmin('support'), 'admin');
    $p = sblProduct();

    test()->postJson('/admin-api/product-editor-save/'.$p->id, ['low_stock_amount' => 3, 'stock_status' => 'onbackorder'])->assertStatus(403);
    $fresh = Product::query()->find($p->id);
    expect($fresh->low_stock_amount)->toBeNull()->and($fresh->backorders)->toBeFalse();
});

/* ========================================================== B. back orders */

it('keeps a back-ordered product on sale when "Allow back orders" is saved at zero', function () {
    // DEFECT (the owner's words): "the back stock option should not turn the
    // product to out of stock." The owner app saved 'onbackorder' and the cart
    // refused it. MUTATION: store the posted stock_status as-is in
    // ProductEditorApiController::apply() and add-to-bag answers 422.
    $p = sblProduct(['stock' => 3]);
    [$ck, $csrf] = sblOwnerApp($this);

    OA::post($this, 'products/'.$p->id, ['manage_stock' => true, 'stock' => 0, 'stock_status' => 'onbackorder'], $ck, $csrf)->assertOk()
        ->assertJsonPath('product.stock_status', 'onbackorder')
        ->assertJsonPath('product.backorder', true);

    $fresh = Product::query()->find($p->id);
    expect($fresh->stock_status)->toBe('instock')->and($fresh->backorders)->toBeTrue()->and($fresh->stock)->toBe(0);

    test()->postJson('/api/cart/add', ['product_id' => $p->id, 'quantity' => 2])->assertOk()->assertJsonPath('added', true);
});

it('lets an order take a back-ordered product to 0 and below, and never marks it out of stock', function () {
    // MUTATION: drop the `if ($backorders)` branch in StockClaim::takeFromShelf()
    // and the second claim throws "is sold out".
    $p = sblProduct(['stock' => 1, 'backorders' => true]);

    sblClaim($p, 1);
    expect(Product::query()->find($p->id)->stock)->toBe(0)->and(Product::query()->find($p->id)->stock_status)->toBe('instock');

    sblClaim($p, 2);
    $fresh = Product::query()->find($p->id);
    expect($fresh->stock)->toBe(-2)->and($fresh->stock_status)->toBe('instock');

    // Still buyable at −2, and the probe the checkout runs agrees.
    test()->postJson('/api/cart/add', ['product_id' => $p->id, 'quantity' => 1])->assertOk()->assertJsonPath('added', true);
});

it('does the same for a variant that counts its own stock', function () {
    $p = sblProduct(['type' => 'variable', 'manage_stock' => false, 'stock' => null]);
    $v = ProductVariant::query()->create(['product_id' => $p->id, 'sku' => 'SC-V-'.uniqid(), 'price' => 9900,
        'manage_stock' => true, 'stock' => 0, 'stock_status' => 'instock', 'backorders' => true]);

    sblClaim($p, 3, $v);
    $fresh = ProductVariant::query()->find($v->id);
    expect($fresh->stock)->toBe(-3)->and($fresh->stock_status)->toBe('instock');
});

it('still takes a product WITHOUT back orders out of stock, and refuses what the shelf cannot cover', function () {
    // The half that must not move. MUTATION: default $backorders to true in
    // takeFromShelf() and this is red.
    $p = sblProduct(['stock' => 2]);

    expect(fn () => sblClaim($p, 3))->toThrow(StockUnavailable::class);
    expect(Product::query()->find($p->id)->stock)->toBe(2);

    sblClaim($p, 2);
    $fresh = Product::query()->find($p->id);
    expect($fresh->stock)->toBe(0)->and($fresh->stock_status)->toBe('outofstock')->and($fresh->backorders)->toBeFalse();
    test()->postJson('/api/cart/add', ['product_id' => $p->id, 'quantity' => 1])->assertStatus(422);
});

it('refuses a negative stock figure unless the product allows back orders', function () {
    ProductEditorRoutes::wire(app());
    test()->actingAs(sblAdmin(), 'admin');
    $p = sblProduct();

    test()->postJson('/admin-api/product-editor-save/'.$p->id, ['stock' => -3, 'stock_status' => 'instock'])->assertStatus(422);
    test()->postJson('/admin-api/product-editor-save/'.$p->id, ['stock' => -3, 'stock_status' => 'onbackorder', 'manage_stock' => true])->assertOk()
        ->assertJsonPath('product.stock', -3)->assertJsonPath('product.stock_status', 'onbackorder');
    // And switched back off, the product is sold out as chosen.
    test()->postJson('/admin-api/product-editor-save/'.$p->id, ['stock' => 0, 'stock_status' => 'outofstock'])->assertOk();
    $fresh = Product::query()->find($p->id);
    expect($fresh->backorders)->toBeFalse()->and($fresh->stock_status)->toBe('outofstock');
});

it('converts every row stored as onbackorder into a sellable back order on migrate', function () {
    // The DEFAULT that moved, because he asked: those products were sold out.
    $p = sblProduct(['stock' => 0]);
    DB::table('products')->where('id', $p->id)->update(['stock_status' => 'onbackorder', 'backorders' => false]);

    (require database_path('migrations/2028_01_02_100000_product_backorders_low_stock.php'))->up();

    $fresh = Product::query()->find($p->id);
    expect($fresh->stock_status)->toBe('instock')->and($fresh->backorders)->toBeTrue();
});

/* ================================================= C. back order is visible */

it('shows a back-ordered product as a red minus figure and/or "On back order", per the setting', function (string $how) {
    // DEFECT: "show red minus quantity so we will know it's back order
    // product" -- before, −3 read as a plain 0-ish number, or "Out of stock".
    // MUTATION: drop 'backorder_display' from the products response and the
    // app cannot honour the choice.
    if ($how !== '') {
        app(SettingsService::class)->set(StockLevels::DISPLAY_KEY, $how);
    }
    $p = sblProduct(['stock' => -3, 'backorders' => true]);
    [$ck] = sblOwnerApp($this);

    $list = OA::get($this, 'products', $ck)->json();
    $row = collect($list['products'])->firstWhere('id', $p->id);
    expect($row['backorder'])->toBeTrue()->and($row['stock_figure'])->toBe("\u{2212}3")
        ->and($row['stock_status'])->toBe('onbackorder')
        ->and($list['backorder_display'])->toBe($how === '' ? 'both' : $how);

    CatalogProductsAdminRoutes::wire(app());
    test()->actingAs(sblAdmin(), 'admin');
    $cat = test()->getJson('/admin-api/catalog-products-list')->assertOk()->json();
    $crow = collect($cat['data'] ?? $cat['products'] ?? $cat['rows'])->firstWhere('id', $p->id);
    expect($crow['backorder'])->toBeTrue()->and($crow['stock'])->toBe(-3)
        ->and($cat['backorder_display'])->toBe($how === '' ? 'both' : $how);
})->with(['default is both' => '', 'label' => 'label', 'minus' => 'minus']);

it('stores only one of the display choices', function () {
    app(SettingsService::class)->set(StockLevels::DISPLAY_KEY, '<script>');
    expect(StockLevels::display())->toBe('both')
        ->and(array_keys(StockLevels::DISPLAYS))->toBe(['both', 'label', 'minus']);
});

it('draws the red figure and the label in the admin list, the editor and the owner app', function () {
    // The renderers, pinned to the choice: label-only must not draw the
    // figure, minus-only must not draw the label.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $ed = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));
    $js = (string) file_get_contents(resource_path('js/owner-app/products.js'));

    expect($app)->toContain("(bo !== 'label' ? '<b class=\"cp-bo-n\" style=\"color:#C8102E\">'")
        ->toContain("(bo !== 'minus' ? ' <span class=\"pill red cp-bo\">On back order</span>'")
        ->and($ed)->toContain('data-bind="low_stock_amount"')->toContain("how !== 'minus' ? '<span class=\"pill red\">On back order</span> '")
        ->and($js)->toContain("export const stk = (p, how) => (p.backorder ? boTag(p, how || L.bo)")
        ->toContain("low_stock_amount: low === '' ? null");
});

it('does not tell the owner app a back-ordered product is out of stock', function () {
    // DEFECT: an order taking a back-order shelf below zero raised
    // "Out of stock: …" on the owner's phone for a product still on sale.
    $p = sblProduct(['stock' => 0, 'backorders' => true]);
    \App\Services\OwnerApp\OwnerAppEvents::stockChanged((int) $p->id, (string) $p->name, 1, -1, true, null, true);

    $types = DB::table('owner_app_events')->where('ref_id', $p->id)->pluck('title')->all();
    expect(implode(' | ', $types))->not->toContain('Out of stock')->toContain('On back order');
});
