<?php

declare(strict_types=1);

/*
 * A PRODUCT'S OWN GIFT STOCK (Lane GST; the owner, 25 Nov 2026):
 *
 *   "On the backend Gifts product page, when we try to edit any product quantity, it takes us to the shop etc. i need
 *    those products to edit instantly on the same page with one click and also it should also count as seperate
 *    quantity when we set on gifts page."
 *
 * Asked which "separate" he meant, he chose: "Own gift stock: gifts use only that number; shop sales use only the
 * shop stock."
 *
 * What it looked like before: a free gift and a shop sale took from ONE number -- giving 3 masks away took 3 off the
 * stock the shop sells from, and the only way to change the count was the full product editor, off the Gifts page.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\Payments\GatewayCredentials;
use App\Services\SettingsService;
use App\Services\StockClaim;
use App\Support\AdminCapabilities;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
});

function gstkFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

function gstkProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge(['slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false], $o));
}

/** [[Product, qty, role], ...] in one band AED 100-299 */
function gstkRange(array $gifts): int
{
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => 29900, 'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($gifts as $i => $g) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $g[0]->id, 'quantity' => $g[1], 'role' => $g[2] ?? 'primary', 'sort' => $i]);
    }
    app(FreeGifts::class)->compile();
    gstkFresh();

    return $id;
}

function gstkOn(): void
{
    app(SettingsService::class)->set(FreeGifts::KEY, ['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
    gstkFresh();
}

function gstkGiftStock(Product $p, ?int $n): void
{
    if ($n === null) {
        DB::table('product_gift_stocks')->where('product_id', $p->id)->delete();

        return;
    }
    DB::table('product_gift_stocks')->updateOrInsert(['product_id' => $p->id], ['stock' => $n, 'stock_status' => $n > 0 ? 'instock' : 'outofstock', 'backorders' => false, 'created_at' => now(), 'updated_at' => now()]);
}

function gstkGiftStockOf(Product $p): ?int
{
    $v = DB::table('product_gift_stocks')->where('product_id', $p->id)->value('stock');

    return $v === null ? null : (int) $v;
}

function gstkPlace(array $lines)
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    foreach ($lines as [$product, $qty]) {
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => (int) $product->price]);
    }
    app(CartService::class)->forget();

    return test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', ['billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan', 'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod']);
}

function gstkAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'GS ' . $role, 'email' => 'gs-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

it('gives a gift from its own gift stock and leaves the shop stock alone, and a cancel puts it back there', function () {
    /*
     * The defect: giving 3 masks away took 3 off the stock the SHOP sells from.
     * MUTATION: drop `'gift' => true` from the claim in CheckoutController::place(). RED (shop 10 → 7, gift 5 stays).
     * MUTATION: drop the claimGiftShelves() call in StockClaim::claim(). RED (the same).
     */
    $mask = gstkProduct('Sheet Mask', ['manage_stock' => true, 'stock' => 10]);
    gstkGiftStock($mask, 5);
    gstkRange([[$mask, 3]]);
    gstkOn();
    $serum = gstkProduct('Serum', ['price' => 15000]);

    gstkPlace([[$serum, 1]])->assertRedirect();
    $order = Order::latest('id')->first();

    expect($order->gifts)->toHaveCount(1)
        ->and((int) $order->gifts->first()->quantity)->toBe(3)
        ->and((int) $mask->fresh()->stock)->toBe(10)
        ->and(gstkGiftStockOf($mask))->toBe(2)
        ->and(DB::table('order_stock_claims')->where('order_id', $order->id)->where('product_id', $mask->id)->value('shelf_table'))->toBe('product_gift_stocks');

    // a cancel / failed payment returns the gift to the gift stock, never to the shop's
    expect(app(StockClaim::class)->release((int) $order->id, 'cancelled'))->toBe(3)
        ->and(gstkGiftStockOf($mask))->toBe(5)
        ->and((int) $mask->fresh()->stock)->toBe(10);
});

it('never gives a gift whose gift stock is 0, however much the shop has: the backup gives instead', function () {
    /*
     * MUTATION: read the shop stock in FreeGifts::linesFor() even when a gift stock is set. RED (the 50 on the
     * shelf would give the main mask).
     */
    $main = gstkProduct('Main Mask', ['manage_stock' => true, 'stock' => 50, 'gift_kind' => 'mask']);
    $backup = gstkProduct('Backup Mask', ['manage_stock' => true, 'stock' => 9, 'gift_kind' => 'mask']);
    gstkGiftStock($main, 0);
    DB::table('free_gift_ranges')->delete();
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => 29900, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true, 'need_mask' => 2, 'need_mini' => 0, 'need_full' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert([['range_id' => $id, 'product_id' => $main->id, 'quantity' => 2, 'kind' => 'mask', 'role' => 'primary', 'sort' => 0], ['range_id' => $id, 'product_id' => $backup->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'backup', 'sort' => 1]]);
    app(FreeGifts::class)->compile();
    gstkOn();
    $serum = gstkProduct('Serum', ['price' => 15000]);

    gstkPlace([[$serum, 1]])->assertRedirect();
    $gifts = Order::latest('id')->first()->gifts;

    expect($gifts->pluck('quantity', 'name')->map(fn ($q) => (int) $q)->all())->toBe(['Backup Mask' => 2])
        ->and((int) $main->fresh()->stock)->toBe(50)
        ->and((int) $backup->fresh()->stock)->toBe(7);
});

it('gives from the gift stock even when the shop is sold out, and a shop sale never touches the gift stock', function () {
    $serumGift = gstkProduct('Mini Serum', ['manage_stock' => true, 'stock' => 0, 'stock_status' => 'outofstock']);
    gstkGiftStock($serumGift, 4);
    gstkRange([[$serumGift, 1]]);
    gstkOn();
    $toner = gstkProduct('Toner', ['price' => 15000, 'manage_stock' => true, 'stock' => 8]);
    gstkGiftStock($toner, 6);   // the toner has a gift stock too, but here it is SOLD

    gstkPlace([[$toner, 1]])->assertRedirect();
    $order = Order::latest('id')->first();

    expect($order->gifts->pluck('name')->all())->toBe(['Mini Serum'])
        ->and(gstkGiftStockOf($serumGift))->toBe(3)
        ->and((int) $toner->fresh()->stock)->toBe(7)
        ->and(gstkGiftStockOf($toner))->toBe(6);
});

it('keeps a product with no gift stock exactly as before: its gifts come off the shop stock', function () {
    $mask = gstkProduct('Plain Mask', ['manage_stock' => true, 'stock' => 10]);
    gstkRange([[$mask, 2]]);
    gstkOn();
    gstkPlace([[gstkProduct('Serum', ['price' => 15000]), 1]])->assertRedirect();

    expect((int) $mask->fresh()->stock)->toBe(8)->and(gstkGiftStockOf($mask))->toBeNull();
});

it('sets, changes and clears a gift stock from the Gifts page in one request each, for the manage capability only', function () {
    $mask = gstkProduct('Gift Mask', ['manage_stock' => true, 'stock' => 12, 'gift_only' => true]);
    gstkRange([[$mask, 1]]);
    $owner = gstkAdmin();
    $row = fn ($r) => collect($r->json('gift_products'))->firstWhere('id', $mask->id);

    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => 7])->assertOk();
    expect($row($r)['gift_stock'])->toBe(7)
        ->and($row($r)['stock'])->toBe(12)
        ->and(collect($r->json('ranges'))->first()['gifts'][0]['product']['gift_stock'])->toBe(7)
        ->and(gstkGiftStockOf($mask))->toBe(7);

    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => 0])->assertOk();
    expect($row($r)['gift_stock'])->toBe(0)->and(DB::table('product_gift_stocks')->where('product_id', $mask->id)->value('stock_status'))->toBe('outofstock');

    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => null])->assertOk();
    expect($row($r)['gift_stock'])->toBeNull()->and(gstkGiftStockOf($mask))->toBeNull();

    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => -2])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/999999/gift-stock', ['stock' => 3])->assertNotFound();

    // fails closed: a write here is freegifts.manage, and a role without it is refused
    expect(AdminCapabilities::forPath('POST', 'admin-api/free-gifts/products/5/gift-stock'))->toBe('freegifts.manage');
    test()->actingAs(gstkAdmin('support'), 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => 3])->assertForbidden();
    expect(gstkGiftStockOf($mask))->toBeNull();
});

it('reads the gift stock in the same single query the shop already makes for the gifts', function () {
    /*
     * MUTATION: load the gift stock with a second query per product in FreeGifts::products(). RED (3 ≠ 40 counts).
     */
    $count = function (int $n): int {
        DB::table('free_gift_ranges')->delete();
        $gifts = [];
        for ($i = 0; $i < $n; $i++) {
            $p = gstkProduct('Gift ' . $n . '-' . $i, ['manage_stock' => true, 'stock' => 5]);
            gstkGiftStock($p, 3);
            $gifts[] = [$p, 1];
        }
        gstkRange($gifts);
        app()->instance('request', Illuminate\Http\Request::create('/'));
        $fg = app(FreeGifts::class);
        $fg->compile();
        $range = $fg->ranges()[0];
        $q = 0;
        DB::listen(function () use (&$q) { $q++; });
        $fg->linesFor($range);

        return $q;
    };

    expect($count(40))->toBe($count(3));
});

it('puts a one-click gift stock control on every Gift products row, and a variation keeps its own shelf', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect($screen)->toContain("'/products/' + p.id + '/gift-stock'")
        ->toContain('giftStockCell(p),')
        ->toContain("'Use shop stock'")
        ->toContain("el('span', { text: 'Gift stock' })")
        // the shopper-facing simulation reads the gift stock too
        ->toContain('if (hasGs(p)) return Math.max(0, p.gift_stock);');
});

/*
 * (Lane GSX) THE OWNER, 27 Nov 2026, with a screenshot of the range editor: "I already added one in stock for maybelline
 * new york lash mascara 4.5ml but when i go to add it as gift in any range. It still showing out of stock and isnt
 * adding as gift in live shop." The product editor posts its stock dropdown with every save; typing 1 into Stock
 * while the dropdown still read "Out of stock" stored stock 1 / outofstock -- counted, yet sold out to the ranges, the
 * shop's gift walk and the checkout alike. Mutation: take StockLevels::followStock() out of the editor's apply() (or
 * the catalogue's quick save) and the first two expectations go red.
 */
it('puts a product back in stock when its count is raised, whatever the stock dropdown still says, and the shop gives it', function () {
    $mascara = gstkProduct('Maybelline Sky High Mascara 4.5ml', ['manage_stock' => true, 'stock' => 0, 'stock_status' => 'outofstock', 'gift_only' => true]);
    $other = gstkProduct('Glow Serum', ['manage_stock' => true, 'stock' => 5]);
    gstkRange([[$mascara, 1]]);
    gstkOn();
    $owner = gstkAdmin();

    // the editor's save: the count typed, the dropdown untouched ("Out of stock")
    test()->actingAs($owner, 'admin')->postJson('/admin-api/product-editor-save/' . $mascara->id, ['manage_stock' => true, 'stock' => 1, 'stock_status' => 'outofstock'])->assertOk();
    expect($mascara->fresh()->stock_status)->toBe('instock');

    // the range editor's row reads it as in stock, and the shop's own walk gives it
    $r = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts')->assertOk();
    expect(collect($r->json('ranges'))->first()['gifts'][0]['product']['in_stock'])->toBeTrue();
    gstkFresh();
    $lines = app(FreeGifts::class)->forTotals(['subtotal' => 15000, 'discount' => 0], 'AE');
    expect(collect($lines)->pluck('product_id')->all())->toContain($mascara->id);

    // choosing "Out of stock" by hand with the count UNCHANGED is still respected
    test()->actingAs($owner, 'admin')->postJson('/admin-api/product-editor-save/' . $mascara->id, ['manage_stock' => true, 'stock' => 1, 'stock_status' => 'outofstock'])->assertOk();
    expect($mascara->fresh()->stock_status)->toBe('outofstock');

    // the catalogue's quick save follows the same rule: counted down to 0 reads out, back up reads in
    test()->actingAs($owner, 'admin')->postJson('/admin-api/catalog-products-save/' . $other->id, ['stock' => 0])->assertOk();
    expect($other->fresh()->stock_status)->toBe('outofstock');
    test()->actingAs($owner, 'admin')->postJson('/admin-api/catalog-products-save/' . $other->id, ['stock' => 3])->assertOk();
    expect($other->fresh()->stock_status)->toBe('instock');
    // and the older write path the same
    test()->actingAs($owner, 'admin')->putJson('/admin-api/products/' . $other->id, ['stock' => 0])->assertOk();
    expect($other->fresh()->stock_status)->toBe('outofstock');
});

it('repairs the gift-only products already stored counted-but-out, and touches nothing on sale', function () {
    $gift = gstkProduct('Mascara Mini', ['manage_stock' => true, 'stock' => 1, 'stock_status' => 'outofstock', 'gift_only' => true]);
    $shop = gstkProduct('Shop Cream', ['manage_stock' => true, 'stock' => 4, 'stock_status' => 'outofstock']);
    $empty = gstkProduct('Empty Mini', ['manage_stock' => true, 'stock' => 0, 'stock_status' => 'outofstock', 'gift_only' => true]);

    (require database_path('migrations/2028_02_27_100000_repair_gift_only_stock_status.php'))->up();

    expect($gift->fresh()->stock_status)->toBe('instock')
        ->and($shop->fresh()->stock_status)->toBe('outofstock')
        ->and($empty->fresh()->stock_status)->toBe('outofstock');
});

/*
 * (Lane GSX) "also i need option to delete the gifts only product." Only a gift-only product, out of every range that
 * gives it, its gift stock row gone, soft-deleted so an order that gave it keeps it; a product on sale is refused here.
 */
it('deletes a gift-only product from the Gifts page and the phone, takes it out of its ranges, and refuses one on sale', function () {
    $mini = gstkProduct('Mini Toner', ['manage_stock' => true, 'stock' => 3, 'gift_only' => true]);
    $serum = gstkProduct('Serum On Sale', ['manage_stock' => true, 'stock' => 9]);
    $keep = gstkProduct('Kept Mask', ['manage_stock' => true, 'stock' => 9, 'gift_only' => true]);
    gstkRange([[$mini, 1], [$serum, 1], [$keep, 1]]);
    gstkGiftStock($mini, 4);
    gstkOn();
    $owner = gstkAdmin();

    $r = test()->actingAs($owner, 'admin')->deleteJson('/admin-api/free-gifts/products/' . $mini->id)->assertOk();
    expect(Product::find($mini->id))->toBeNull()
        ->and(Product::withTrashed()->find($mini->id))->not->toBeNull()
        ->and(DB::table('free_gift_range_items')->where('product_id', $mini->id)->count())->toBe(0)
        ->and(gstkGiftStockOf($mini))->toBeNull()
        ->and(collect($r->json('ranges'))->first()['gifts'])->toHaveCount(2)
        ->and(collect($r->json('gift_products'))->pluck('id')->all())->not->toContain($mini->id);
    gstkFresh();
    expect(collect(app(FreeGifts::class)->forTotals(['subtotal' => 15000, 'discount' => 0], 'AE'))->pluck('product_id')->all())->not->toContain($mini->id);

    // a product on sale is never deleted from here
    test()->actingAs($owner, 'admin')->deleteJson('/admin-api/free-gifts/products/' . $serum->id)->assertStatus(422);
    expect(Product::find($serum->id))->not->toBeNull();
    test()->actingAs($owner, 'admin')->deleteJson('/admin-api/free-gifts/products/999999')->assertNotFound();

    // manage only, fails closed
    expect(AdminCapabilities::forPath('DELETE', 'admin-api/free-gifts/products/5'))->toBe('freegifts.manage');
    test()->actingAs(gstkAdmin('support'), 'admin')->deleteJson('/admin-api/free-gifts/products/' . $keep->id)->assertForbidden();
    expect(Product::find($keep->id))->not->toBeNull();

    // the routes are wired exactly once
    expect(substr_count(file_get_contents(base_path('routes/free-gifts-admin.php')), "Route::delete('/products/{id}', [FreeGiftsController::class, 'deleteProduct'])"))->toBe(1)
        ->and(substr_count(file_get_contents(base_path('routes/owner-app.php')), "->name('gifts.giftdelete')"))->toBe(1);
});
