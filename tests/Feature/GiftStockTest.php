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

function gsFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

function gsProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge(['slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false], $o));
}

/** [[Product, qty, role], ...] in one band AED 100-299 */
function gsRange(array $gifts): int
{
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => 29900, 'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($gifts as $i => $g) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $g[0]->id, 'quantity' => $g[1], 'role' => $g[2] ?? 'primary', 'sort' => $i]);
    }
    app(FreeGifts::class)->compile();
    gsFresh();

    return $id;
}

function gsOn(): void
{
    app(SettingsService::class)->set(FreeGifts::KEY, ['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
    gsFresh();
}

function gsGiftStock(Product $p, ?int $n): void
{
    if ($n === null) {
        DB::table('product_gift_stocks')->where('product_id', $p->id)->delete();

        return;
    }
    DB::table('product_gift_stocks')->updateOrInsert(['product_id' => $p->id], ['stock' => $n, 'stock_status' => $n > 0 ? 'instock' : 'outofstock', 'backorders' => false, 'created_at' => now(), 'updated_at' => now()]);
}

function gsGiftStockOf(Product $p): ?int
{
    $v = DB::table('product_gift_stocks')->where('product_id', $p->id)->value('stock');

    return $v === null ? null : (int) $v;
}

function gsPlace(array $lines)
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

function gsAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'GS ' . $role, 'email' => 'gs-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

it('gives a gift from its own gift stock and leaves the shop stock alone, and a cancel puts it back there', function () {
    /*
     * The defect: giving 3 masks away took 3 off the stock the SHOP sells from.
     * MUTATION: drop `'gift' => true` from the claim in CheckoutController::place(). RED (shop 10 → 7, gift 5 stays).
     * MUTATION: drop the claimGiftShelves() call in StockClaim::claim(). RED (the same).
     */
    $mask = gsProduct('Sheet Mask', ['manage_stock' => true, 'stock' => 10]);
    gsGiftStock($mask, 5);
    gsRange([[$mask, 3]]);
    gsOn();
    $serum = gsProduct('Serum', ['price' => 15000]);

    gsPlace([[$serum, 1]])->assertRedirect();
    $order = Order::latest('id')->first();

    expect($order->gifts)->toHaveCount(1)
        ->and((int) $order->gifts->first()->quantity)->toBe(3)
        ->and((int) $mask->fresh()->stock)->toBe(10)
        ->and(gsGiftStockOf($mask))->toBe(2)
        ->and(DB::table('order_stock_claims')->where('order_id', $order->id)->where('product_id', $mask->id)->value('shelf_table'))->toBe('product_gift_stocks');

    // a cancel / failed payment returns the gift to the gift stock, never to the shop's
    expect(app(StockClaim::class)->release((int) $order->id, 'cancelled'))->toBe(3)
        ->and(gsGiftStockOf($mask))->toBe(5)
        ->and((int) $mask->fresh()->stock)->toBe(10);
});

it('never gives a gift whose gift stock is 0, however much the shop has: the backup gives instead', function () {
    /*
     * MUTATION: read the shop stock in FreeGifts::linesFor() even when a gift stock is set. RED (the 50 on the
     * shelf would give the main mask).
     */
    $main = gsProduct('Main Mask', ['manage_stock' => true, 'stock' => 50, 'gift_kind' => 'mask']);
    $backup = gsProduct('Backup Mask', ['manage_stock' => true, 'stock' => 9, 'gift_kind' => 'mask']);
    gsGiftStock($main, 0);
    DB::table('free_gift_ranges')->delete();
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => 29900, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true, 'need_mask' => 2, 'need_mini' => 0, 'need_full' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert([['range_id' => $id, 'product_id' => $main->id, 'quantity' => 2, 'kind' => 'mask', 'role' => 'primary', 'sort' => 0], ['range_id' => $id, 'product_id' => $backup->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'backup', 'sort' => 1]]);
    app(FreeGifts::class)->compile();
    gsOn();
    $serum = gsProduct('Serum', ['price' => 15000]);

    gsPlace([[$serum, 1]])->assertRedirect();
    $gifts = Order::latest('id')->first()->gifts;

    expect($gifts->pluck('quantity', 'name')->map(fn ($q) => (int) $q)->all())->toBe(['Backup Mask' => 2])
        ->and((int) $main->fresh()->stock)->toBe(50)
        ->and((int) $backup->fresh()->stock)->toBe(7);
});

it('gives from the gift stock even when the shop is sold out, and a shop sale never touches the gift stock', function () {
    $serumGift = gsProduct('Mini Serum', ['manage_stock' => true, 'stock' => 0, 'stock_status' => 'outofstock']);
    gsGiftStock($serumGift, 4);
    gsRange([[$serumGift, 1]]);
    gsOn();
    $toner = gsProduct('Toner', ['price' => 15000, 'manage_stock' => true, 'stock' => 8]);
    gsGiftStock($toner, 6);   // the toner has a gift stock too, but here it is SOLD

    gsPlace([[$toner, 1]])->assertRedirect();
    $order = Order::latest('id')->first();

    expect($order->gifts->pluck('name')->all())->toBe(['Mini Serum'])
        ->and(gsGiftStockOf($serumGift))->toBe(3)
        ->and((int) $toner->fresh()->stock)->toBe(7)
        ->and(gsGiftStockOf($toner))->toBe(6);
});

it('keeps a product with no gift stock exactly as before: its gifts come off the shop stock', function () {
    $mask = gsProduct('Plain Mask', ['manage_stock' => true, 'stock' => 10]);
    gsRange([[$mask, 2]]);
    gsOn();
    gsPlace([[gsProduct('Serum', ['price' => 15000]), 1]])->assertRedirect();

    expect((int) $mask->fresh()->stock)->toBe(8)->and(gsGiftStockOf($mask))->toBeNull();
});

it('sets, changes and clears a gift stock from the Gifts page in one request each, for the manage capability only', function () {
    $mask = gsProduct('Gift Mask', ['manage_stock' => true, 'stock' => 12, 'gift_only' => true]);
    gsRange([[$mask, 1]]);
    $owner = gsAdmin();
    $row = fn ($r) => collect($r->json('gift_products'))->firstWhere('id', $mask->id);

    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => 7])->assertOk();
    expect($row($r)['gift_stock'])->toBe(7)
        ->and($row($r)['stock'])->toBe(12)
        ->and(collect($r->json('ranges'))->first()['gifts'][0]['product']['gift_stock'])->toBe(7)
        ->and(gsGiftStockOf($mask))->toBe(7);

    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => 0])->assertOk();
    expect($row($r)['gift_stock'])->toBe(0)->and(DB::table('product_gift_stocks')->where('product_id', $mask->id)->value('stock_status'))->toBe('outofstock');

    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => null])->assertOk();
    expect($row($r)['gift_stock'])->toBeNull()->and(gsGiftStockOf($mask))->toBeNull();

    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => -2])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products/999999/gift-stock', ['stock' => 3])->assertNotFound();

    // fails closed: a write here is freegifts.manage, and a role without it is refused
    expect(AdminCapabilities::forPath('POST', 'admin-api/free-gifts/products/5/gift-stock'))->toBe('freegifts.manage');
    test()->actingAs(gsAdmin('support'), 'admin')->postJson('/admin-api/free-gifts/products/' . $mask->id . '/gift-stock', ['stock' => 3])->assertForbidden();
    expect(gsGiftStockOf($mask))->toBeNull();
});

it('reads the gift stock in the same single query the shop already makes for the gifts', function () {
    /*
     * MUTATION: load the gift stock with a second query per product in FreeGifts::products(). RED (3 ≠ 40 counts).
     */
    $count = function (int $n): int {
        DB::table('free_gift_ranges')->delete();
        $gifts = [];
        for ($i = 0; $i < $n; $i++) {
            $p = gsProduct('Gift ' . $n . '-' . $i, ['manage_stock' => true, 'stock' => 5]);
            gsGiftStock($p, 3);
            $gifts[] = [$p, 1];
        }
        gsRange($gifts);
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
