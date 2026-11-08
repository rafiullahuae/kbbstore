<?php

declare(strict_types=1);

/**
 * Growth & Marketing -> Free gifts -> Ranges & gifts -> Gift-only products
 * (Lane GP, the owner 8 Nov 2026): a quick product just for gifts -- name,
 * photo, cut price, actual price -- with a list he can edit and remove from.
 *
 * Each case says what the defect would look like on the live shop and carries
 * a mutation note. Routes are mounted the way the integrator is told to
 * (Tests\Support\FreeGiftsQuickRoutes), and stay correct once web.php has them.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Media;
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
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FreeGiftsQuickRoutes;
use Tests\Support\MerchantFeedRoutes;

beforeEach(function () {
    FreeGiftsQuickRoutes::wire(app());
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
});

function fgqAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'GP ' . $role, 'email' => 'gp-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

function fgqMedia(string $path = 'uploads/products/gp-mask.webp'): string
{
    Media::create(['filename' => basename($path), 'original_name' => basename($path), 'path' => $path, 'mime' => 'image/webp', 'size' => 1000, 'width' => 600, 'height' => 600]);

    // What window.kbbPickMedia hands the screen: the library's own URL.
    return Media::urlFor($path);
}

function fgqPost(array $body, ?AdminUser $as = null, string $path = '/admin-api/free-gifts/quick')
{
    return test()->actingAs($as ?? fgqAdmin(), 'admin')->postJson($path, $body);
}

function fgqCreate(string $name, string $cut = '45', ?string $now = '29.90', ?string $image = null): Product
{
    $r = fgqPost(['name' => $name, 'cut' => $cut, 'now' => $now, 'image' => $image])->assertCreated();

    return Product::query()->findOrFail((int) $r->json('id'));
}

function fgqProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'type' => 'simple', 'price' => 15000, 'stock_status' => 'instock', 'manage_stock' => false,
    ], $o));
}

function fgqRange(int $min, ?int $max, array $gifts): int
{
    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100, 'label_en' => 'Gifts', 'label_ar' => 'هدايا',
        'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($gifts as $i => [$product, $qty]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $product->id, 'quantity' => $qty, 'sort' => $i]);
    }
    app(FreeGifts::class)->compile();
    SettingsService::forgetMemo();
    Setting::flushMap();

    return $id;
}

function fgqGiftsOn(): void
{
    app(SettingsService::class)->set(FreeGifts::KEY, ['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

/*
|------------------------------------------------------------------------------
| ADD
|------------------------------------------------------------------------------
*/

it('adds a gift-only product with its photo and both prices, hidden, in stock, and in the picker at once', function () {
    /*
     * The defect: the quick product is created visible (it shows on /shop at
     * AED 29.90 and can be bought), or with the prices the wrong way round (the
     * gift box strikes through the lower one), or out of stock so FreeGifts
     * skips it, or not in "Add gift" until the page is reloaded.
     * MUTATION: drop 'gift_only' => true in FreeGiftsQuickController::store(). RED.
     */
    $owner = fgqAdmin();
    $url = fgqMedia();
    $r = fgqPost(['name' => '  Centella   Mini Mask ', 'image' => $url, 'cut' => '45', 'now' => '29.90'], $owner)->assertCreated();
    $p = Product::query()->findOrFail((int) $r->json('id'));

    expect($p->gift_only)->toBeTrue()
        ->and($p->is_visible)->toBeFalse()
        ->and($p->name)->toBe('Centella Mini Mask')
        ->and($p->status)->toBe('publish')
        ->and($p->stock_status)->toBe('instock')
        ->and((bool) $p->manage_stock)->toBeFalse()
        ->and((int) $p->price)->toBe(4500)
        ->and((int) $p->sale_price)->toBe(2990)
        ->and((int) $p->effectivePrice())->toBe(2990)
        ->and($p->image)->toBe($url);

    $row = collect($r->json('products'))->firstWhere('id', $p->id);
    expect($row['cut'])->toBe('45.00')->and($row['now'])->toBe('29.90')->and($row['gift_only'])->toBeTrue();

    // The same screen's picker finds it straight away, gift-only first.
    $pick = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/products?q=Centella')->assertOk()->json('products');
    expect(array_column($pick, 'id'))->toContain($p->id);

    // An actual price equal to the cut price (or left empty) is no sale at all.
    $same = fgqCreate('Toner Mini', '20', '');
    expect((int) $same->price)->toBe(2000)->and($same->sale_price)->toBeNull();
});

it('validates the name, both prices and the photo, and writes nothing on a refusal', function () {
    /*
     * The defect: a negative price, an actual price above the cut price (the
     * box shows "worth AED 10, now AED 40"), three decimals silently cut, or a
     * photo that is any address at all -- a javascript: URL, another host, a
     * file outside the upload folders -- becoming an <img src> on the shop.
     * MUTATION: drop the Media::where('path') exists check in image(). RED
     * (the unregistered uploads/ file is accepted).
     */
    $owner = fgqAdmin();
    $before = Product::withTrashed()->count();
    $bad = [
        [['name' => 'A', 'cut' => '10'], 'name'],
        [['name' => 'Mask', 'cut' => ''], 'cut'],
        [['name' => 'Mask', 'cut' => '-5'], 'cut'],
        [['name' => 'Mask', 'cut' => '10.555'], 'cut'],
        [['name' => 'Mask', 'cut' => 'ten'], 'cut'],
        [['name' => 'Mask', 'cut' => '10', 'now' => '-1'], 'now'],
        [['name' => 'Mask', 'cut' => '10', 'now' => '40'], 'now'],
        [['name' => 'Mask', 'cut' => '999999999'], 'cut'],
        [['name' => 'Mask', 'cut' => '10', 'image' => 'javascript:alert(1)'], 'image'],
        [['name' => 'Mask', 'cut' => '10', 'image' => 'data:image/png;base64,AAAA'], 'image'],
        [['name' => 'Mask', 'cut' => '10', 'image' => 'https://evil.example/x.png'], 'image'],
        [['name' => 'Mask', 'cut' => '10', 'image' => '/etc/passwd'], 'image'],
        [['name' => 'Mask', 'cut' => '10', 'image' => 'uploads/../../.env'], 'image'],
        [['name' => 'Mask', 'cut' => '10', 'image' => 'etc/wp-content/uploads/x.jpg'], 'image'],
        // Under uploads/ but never put in the library.
        [['name' => 'Mask', 'cut' => '10', 'image' => 'https://kbeautybliss.com/uploads/products/stray.webp'], 'image'],
    ];
    foreach ($bad as [$body, $field]) {
        fgqPost($body, $owner)->assertStatus(422)->assertJsonValidationErrors($field);
    }
    expect(Product::withTrashed()->count())->toBe($before);

    // The library's file is accepted whichever host the picker's URL names,
    // and what is stored is the library's own URL for it.
    fgqMedia('wp-content/uploads/2024/01/gp-old.jpg');
    $r = fgqPost(['name' => 'Old Mask', 'cut' => '10', 'image' => 'https://cdn.other.example/wp-content/uploads/2024/01/gp-old.jpg'], $owner)->assertCreated();
    expect(Product::query()->find($r->json('id'))->image)->toBe(Media::urlFor('wp-content/uploads/2024/01/gp-old.jpg'));
});

/*
|------------------------------------------------------------------------------
| THE SHOP NEVER SHOWS IT
|------------------------------------------------------------------------------
*/

it('keeps a quick gift-only product off every shop listing, search, feed, api and the bag', function () {
    /*
     * The defect: the gift a shopper gets free is for sale -- on /shop, its
     * category and brand, the search box, the homepage, "also bought" under a
     * product, the sitemap, Google Shopping, /api/products, the wishlist,
     * quick view or Recently viewed -- or can be put in the bag at AED 29.90.
     * Every page below also lists the NORMAL product beside it, so a page that
     * lists nothing cannot pass for one that hides the gift.
     * MUTATION: write 'is_visible' => true in FreeGiftsQuickController::store()
     * AND drop the gift_only line in Product::booted() saving (either one alone
     * still hides it -- two locks). RED: "/shop shows the gift-only product".
     */
    MerchantFeedRoutes::wire();
    $brand = Brand::create(['slug' => 'gp-glowbrand', 'name' => 'Glowbrand']);
    $cat = Category::create(['slug' => 'gp-serums', 'name' => 'Serums']);
    $normal = fgqProduct('Glowbrand Radiance Serum', ['brand_id' => $brand->id, 'category_id' => $cat->id, 'sku' => 'GP-N', 'image' => '/wp-content/uploads/gp-n.jpg']);
    $normal->categories()->attach($cat->id);
    $gift = fgqCreate('Glowbrand Radiance Gift Mini');
    // Even a gift-only product with a brand and a category (one switched to
    // gift only from the catalogue) stays off their pages.
    $gift->forceFill(['brand_id' => $brand->id, 'category_id' => $cat->id, 'sku' => 'GP-G', 'image' => '/wp-content/uploads/gp-g.jpg'])->save();
    $gift->categories()->attach($cat->id);
    \Illuminate\Support\Facades\Cache::flush();

    $absent = function (string $what, string $body) use ($normal, $gift) {
        expect(str_contains($body, $gift->slug))->toBeFalse($what . ' shows the gift-only product')
            ->and(str_contains($body, 'Radiance Gift Mini'))->toBeFalse($what . ' names the gift-only product');
    };
    $both = function (string $what, string $body) use ($absent, $normal) {
        expect(str_contains($body, $normal->slug) || str_contains($body, 'Radiance Serum'))->toBeTrue($what . ' does not list the normal product, so it proves nothing');
        $absent($what, $body);
    };

    $both('/shop', test()->get('/shop/')->assertOk()->getContent());
    $both('category', test()->followingRedirects()->get('/product-category/gp-serums/')->assertOk()->getContent());
    $both('brand page', test()->followingRedirects()->get('/brands/gp-glowbrand/')->assertOk()->getContent());
    $both('search page', test()->get('/shop/?s=Radiance')->assertOk()->getContent());
    $both('/api/search', test()->getJson('/api/search?q=Radiance')->assertOk()->getContent());
    $absent('/api/search/starter', test()->getJson('/api/search/starter')->getContent());
    $both('/api/products', test()->getJson('/api/products?search=Radiance')->assertOk()->getContent());
    $both('sitemap', test()->get('/sitemap.xml')->assertOk()->getContent());
    $both('merchant feed', test()->get('/feeds/google-merchant.xml')->assertOk()->getContent());
    $absent('homepage', test()->get('/')->assertOk()->getContent());
    $absent('normal product page (related, also bought)', test()->get('/product/' . $normal->slug . '/')->assertOk()->getContent());

    // Its own page, its API record and quick view are not there at all.
    test()->get('/product/' . $gift->slug . '/')->assertNotFound();
    test()->getJson('/api/products/' . $gift->slug)->assertNotFound();
    test()->get('/quick-view/' . $gift->id)->assertNotFound();

    // Recently / most viewed never record it.
    $views = DB::table(\App\Support\ProductViews::TABLE)->count();
    test()->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->post('/api/viewed', ['id' => $gift->id, 'pv' => $gift->id]);
    expect(DB::table(\App\Support\ProductViews::TABLE)->count())->toBe($views);

    // Never into the bag as a product, by either add route.
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $shopper = test()->withoutMiddleware([Illuminate\Cookie\Middleware\EncryptCookies::class, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
    $shopper->postJson('/api/cart/add', ['product_id' => $gift->id, 'quantity' => 1]);
    $shopper->postJson('/api/cart/add-together', ['main_id' => $gift->id, 'items' => [['product_id' => $gift->id]]]);
    expect($cart->fresh()->items()->where('product_id', $gift->id)->count())->toBe(0);

    // The occasion banner's product search (an admin picker for shop cards).
    $admin = fgqAdmin();
    $both('occasion banner search', test()->actingAs($admin, 'admin')->getJson('/admin-api/occasion-banner/products?q=Radiance')->assertOk()->getContent());
});

/*
|------------------------------------------------------------------------------
| GIVEN AS A GIFT, AT AED 0
|------------------------------------------------------------------------------
*/

it('is given as a gift and lands on the order at AED 0, with its name on the line', function () {
    /*
     * The defect: the quick product is in the range but never in the parcel
     * (FreeGifts skips it as unavailable), or it is charged at its price.
     * MUTATION: set 'stock_status' => 'outofstock' in store(). RED.
     */
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $gift = fgqCreate('Snail Gift Mini', '35', '19');
    fgqRange(100, null, [[$gift, 2]]);
    fgqGiftsOn();
    $serum = fgqProduct('Snail Essence', ['price' => 15000]);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $serum->id, 'quantity' => 1, 'unit_price' => 15000]);

    test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
            'billing_country' => 'AE', 'payment_method' => 'cod',
        ])->assertRedirect();

    $order = Order::latest('id')->first();
    $line = $order->gifts->first();
    expect($order->gifts)->toHaveCount(1)
        ->and((int) $line->product_id)->toBe($gift->id)
        ->and($line->name)->toBe('Snail Gift Mini')
        ->and((int) $line->unit_price)->toBe(0)
        ->and((int) $line->total)->toBe(0)
        ->and((int) $line->quantity)->toBe(2)
        ->and((int) $order->total)->toBe(15000 + 2000);
});

/*
|------------------------------------------------------------------------------
| EDIT AND REMOVE
|------------------------------------------------------------------------------
*/

it('edits the name, photo and both prices inline, and only ever a gift-only product', function () {
    /*
     * The defect: an edit that leaves an old sale schedule in force (the new
     * actual price never shows), that can be pointed at a normal product's id
     * and reprice the shop, or that refuses to save a name because the photo
     * the product already had came from the old shop.
     * MUTATION: drop where('gift_only', true) in ownedOrFail(). RED.
     */
    $owner = fgqAdmin();
    $gift = fgqCreate('Rice Mini', '30', '20');
    $gift->forceFill(['sale_starts_at' => now()->addYear(), 'image' => 'https://old.example/wp-content/uploads/rice.jpg'])->save();
    $url = fgqMedia('uploads/products/gp-rice2.webp');

    // Name only: the old photo is kept as it was, not re-checked.
    fgqPost(['name' => 'Rice Mini Mask', 'image' => 'https://old.example/wp-content/uploads/rice.jpg', 'cut' => '30', 'now' => '20'], $owner, '/admin-api/free-gifts/quick/' . $gift->id)->assertOk();
    $r = fgqPost(['name' => 'Rice Mini Mask', 'image' => $url, 'cut' => '50', 'now' => '35.50'], $owner, '/admin-api/free-gifts/quick/' . $gift->id)->assertOk();
    $gift->refresh();

    expect($gift->name)->toBe('Rice Mini Mask')
        ->and($gift->image)->toBe($url)
        ->and((int) $gift->price)->toBe(5000)
        ->and((int) $gift->sale_price)->toBe(3550)
        ->and($gift->sale_starts_at)->toBeNull()
        ->and((int) $gift->effectivePrice())->toBe(3550)
        ->and($gift->is_visible)->toBeFalse()
        ->and(collect($r->json('products'))->firstWhere('id', $gift->id)['now'])->toBe('35.50');

    // A normal product is not this screen's to change.
    $normal = fgqProduct('Shop Serum');
    fgqPost(['name' => 'Hacked', 'cut' => '1'], $owner, '/admin-api/free-gifts/quick/' . $normal->id)->assertNotFound();
    expect($normal->fresh()->name)->toBe('Shop Serum')->and((int) $normal->fresh()->price)->toBe(15000);

    // Bad prices on edit are refused too, and change nothing.
    fgqPost(['name' => 'Rice Mini Mask', 'cut' => '10', 'now' => '11'], $owner, '/admin-api/free-gifts/quick/' . $gift->id)->assertStatus(422);
    expect((int) $gift->fresh()->price)->toBe(5000);
});

it('removes by soft delete, keeps past orders whole, and refuses while the product is a gift on a range', function () {
    /*
     * The defect: removing a gift that was given last week breaks that order
     * (its line points at a row that is gone) or silently empties a live range.
     * MUTATION: replace $product->delete() with forceDelete() in destroy(). RED.
     */
    $owner = fgqAdmin();
    $gift = fgqCreate('Mugwort Mini');
    $range = fgqRange(100, 299, [[$gift, 1]]);

    $refused = test()->actingAs($owner, 'admin')->deleteJson('/admin-api/free-gifts/quick/' . $gift->id)->assertStatus(409);
    expect($refused->json('error'))->toContain('AED 100 – 299')
        ->and(Product::query()->find($gift->id))->not->toBeNull();

    DB::table('free_gift_range_items')->where('range_id', $range)->delete();
    $order = Order::create(['order_number' => 'GP-' . Str::random(6), 'email' => 'b@example.com', 'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 30000, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'fee_total' => 0, 'total' => 30000, 'payment_method' => 'cod', 'free_gift_count' => 1]);
    $order->items()->create(['product_id' => $gift->id, 'name' => 'Mugwort Mini', 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);

    $list = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/quick')->assertOk()->json('products');
    expect(collect($list)->firstWhere('id', $gift->id)['orders'])->toBe(1);

    $r = test()->actingAs($owner, 'admin')->deleteJson('/admin-api/free-gifts/quick/' . $gift->id)->assertOk();
    expect(collect($r->json('products'))->pluck('id'))->not->toContain($gift->id)
        ->and(Product::query()->find($gift->id))->toBeNull()
        ->and(Product::withTrashed()->find($gift->id))->not->toBeNull()
        ->and($order->fresh()->gifts->first()->name)->toBe('Mugwort Mini')
        ->and((int) $order->fresh()->gifts->first()->product_id)->toBe($gift->id);

    // Gone from the picker too.
    $pick = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/products?q=Mugwort')->json('products');
    expect(array_column($pick, 'id'))->not->toContain($gift->id);

    // A normal product cannot be deleted through this door.
    $normal = fgqProduct('Shop Toner');
    test()->actingAs($owner, 'admin')->deleteJson('/admin-api/free-gifts/quick/' . $normal->id)->assertNotFound();
    expect(Product::query()->find($normal->id))->not->toBeNull();
});

/*
|------------------------------------------------------------------------------
| CAPABILITY AND COST
|------------------------------------------------------------------------------
*/

it('reads with freegifts.view, writes with freegifts.manage, and refuses everyone else', function () {
    /*
     * The defect: a support login (or a guest) creating products, or an
     * endpoint left off the capability map answering anyone signed in.
     * MUTATION: move the routes out of free-gifts/ (off the mapped prefix). RED.
     */
    test()->getJson('/admin-api/free-gifts/quick')->assertUnauthorized();
    test()->postJson('/admin-api/free-gifts/quick', ['name' => 'Guest Gift', 'cut' => '5'])->assertUnauthorized();

    $support = fgqAdmin('support');
    test()->actingAs($support, 'admin')->getJson('/admin-api/free-gifts/quick')->assertForbidden();
    fgqPost(['name' => 'Support Gift', 'cut' => '5'], $support)->assertForbidden();

    expect(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/free-gifts/quick'))->toBe('freegifts.view')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/free-gifts/quick'))->toBe('freegifts.manage')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/free-gifts/quick/5'))->toBe('freegifts.manage')
        ->and(\App\Support\AdminCapabilities::forPath('DELETE', 'admin-api/free-gifts/quick/5'))->toBe('freegifts.manage')
        ->and(Product::query()->whereIn('name', ['Guest Gift', 'Support Gift'])->exists())->toBeFalse();

    test()->actingAs(fgqAdmin('manager'), 'admin')->getJson('/admin-api/free-gifts/quick')->assertOk();
});

it('lists 3 or 40 gift-only products in the same number of queries', function () {
    /*
     * The defect: one query per row (its ranges, its orders, its brand) -- the
     * panel slows down every time the owner adds a gift.
     * MUTATION: read each row's orders inside the map() in rows(). RED.
     */
    $owner = fgqAdmin();
    $count = function () use ($owner): int {
        test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/quick')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/quick')->assertOk();
        $n = count(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/\b(products|free_gift_range_items|order_items|brands)\b/', $q['query']) === 1));
        DB::disableQueryLog();

        return $n;
    };
    $brand = Brand::create(['slug' => 'gp-qb', 'name' => 'QB']);
    $order = Order::create(['order_number' => 'GPQ-' . Str::random(6), 'email' => 'b@example.com', 'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 0, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'fee_total' => 0, 'total' => 0, 'payment_method' => 'cod']);
    $range = fgqRange(100, null, []);
    $make = function (int $n) use ($brand, $order, $range) {
        for ($i = 0; $i < $n; $i++) {
            $p = fgqProduct('Quick ' . Str::random(5), ['gift_only' => true, 'brand_id' => $brand->id]);
            $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
            DB::table('free_gift_range_items')->insert(['range_id' => $range, 'product_id' => $p->id, 'quantity' => 1, 'sort' => $i]);
        }
    };
    $make(3);
    $three = $count();
    $make(37);
    $forty = $count();

    expect(count(test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/quick')->json('products')))->toBeGreaterThanOrEqual(40)
        ->and($forty)->toBe($three)
        ->and($three)->toBeLessThanOrEqual(4);
});

it('draws the panel from the Media Library, with no file input, no confirm() and no timer', function () {
    /*
     * The defect: a raw file picker beside the library (AdminMediaPickerEverywhere),
     * window.confirm on remove, or a polling loop -- "super light" broken.
     * MUTATION: replace kbbPickMedia in pickQuickPhoto() with a file input. RED.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    $start = strpos($src, 'gift-only products (Lane GP)');
    $end = strpos($src, '/* --------------------------------------------------------------- display */');
    expect($start)->not->toBeFalse()->and($end)->toBeGreaterThan($start);
    $block = substr($src, $start, $end - $start);

    expect($block)->toContain('window.kbbPickMedia(')
        ->not->toContain("type: 'file'")
        ->not->toContain('confirm(')
        ->not->toContain('setInterval')
        ->not->toContain('setTimeout')
        ->not->toContain('getBoundingClientRect')
        ->and(substr_count($src, "renderRanges(p); p.appendChild(quickPanel());"))->toBe(1)
        ->and(substr_count($src, 'function quickPanel('))->toBe(1);
});
