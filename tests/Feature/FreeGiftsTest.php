<?php

declare(strict_types=1);

/**
 * Free gifts (Lane FG) -- Marketing -> Free gifts.
 *
 * The owner's bands: AED 100-299 "2 Masks" up to AED 2,000+ "12 Masks + 5
 * Minis + 3 Full Size Products", one band at a time, measured on the products'
 * subtotal after the coupon. Each case below says what the defect would look
 * like on the shop and carries a mutation note: change the named line back and
 * the case goes red.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Coupon;
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
use App\Support\AdminCapabilities;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    // The seeded bands carry no gifts in a test database (no product has the
    // owner's slugs); every case builds its own.
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
});

function lfgSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

function lfgFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function lfgProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 2500,
        'stock_status' => 'instock',
        'manage_stock' => false,
    ], $o));
}

/** A band from AED $min to AED $max (null = and above) giving [[Product, qty], ...]. */
function lfgRange(int $min, ?int $max, array $gifts, string $en = 'Gifts', array $o = []): int
{
    $id = DB::table('free_gift_ranges')->insertGetId(array_merge([
        'min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100,
        'label_en' => $en, 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ], $o));

    foreach ($gifts as $i => [$product, $qty]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $product->id, 'quantity' => $qty, 'sort' => $i]);
    }

    app(FreeGifts::class)->compile();
    lfgFresh();

    return $id;
}

function lfgOn(array $extra = []): void
{
    lfgSet(FreeGifts::KEY, array_merge(['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts'], $extra));
}

/** The owner's five bands, each with its own gift product. @return array<int, Product> by band min */
function lfgOwnerBands(): array
{
    $out = [];
    foreach ([[100, 299], [300, 499], [500, 999], [1000, 1999], [2000, null]] as [$min, $max]) {
        $out[$min] = lfgProduct('Gift for ' . $min, ['gift_only' => true]);
        lfgRange($min, $max, [[$out[$min], 1]], 'Band ' . $min);
    }

    return $out;
}

function lfgCart(array $lines, array $o = []): Cart
{
    $cart = Cart::create(array_merge(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()], $o));

    foreach ($lines as [$product, $qty]) {
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => (int) $product->price]);
    }

    return $cart->fresh();
}

function lfgTotals(Cart $cart): array
{
    return app(CartService::class)->totals($cart->fresh(), 'AE');
}

function lfgPlace(Cart $cart, array $overrides = [])
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', array_merge([
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
            'billing_country' => 'AE', 'payment_method' => 'cod',
        ], $overrides));
}

function lfgShopper(Cart $cart)
{
    app(CartService::class)->forget();

    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function lfgAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'FG ' . $role, 'email' => 'fg-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

/*
|------------------------------------------------------------------------------
| THE ENGINE
|------------------------------------------------------------------------------
*/

it('puts a basket in exactly one band at every boundary, including the fils a coupon leaves', function (int $fils, ?int $band) {
    /*
     * The defect: "100 - 299" and "300 - 499" read as touching, but a basket of
     * AED 299.50 (a percentage coupon leaves fils) fell between them and got
     * nothing, or a basket of AED 300 got both bands' gifts.
     * MUTATION: in FreeGifts::covers() use `$fils <= $max` -- 299.99 goes red.
     */
    $p = lfgOwnerBands();
    lfgOn();

    $range = app(FreeGifts::class)->rangeFor($fils);

    expect($range === null ? null : intdiv($range['min'], 100))->toBe($band);
})->with([
    'AED 99.99' => [9999, null],
    'AED 100' => [10000, 100],
    'AED 299.99' => [29999, 100],
    'AED 300' => [30000, 300],
    'AED 499.99' => [49999, 300],
    'AED 500' => [50000, 500],
    'AED 999.99' => [99999, 500],
    'AED 1,000' => [100000, 1000],
    'AED 1,999.99' => [199999, 1000],
    'AED 2,000' => [200000, 2000],
    'AED 25,000' => [2500000, 2000],
]);

it('gives nothing at all while the switch is off, without asking the database', function () {
    /*
     * The defect: the master switch ships OFF (the owner has not named the mask
     * product); a shop that drew a box anyway, or paid a query for it on every
     * page, would break rule 1 and the speed freeze.
     * MUTATION: drop the `! $this->on()` test in forTotals(). RED.
     */
    $gift = lfgProduct('Mask');
    lfgRange(100, null, [[$gift, 2]]);
    $cart = lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]);
    $totals = lfgTotals($cart);

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(FreeGifts::class)->forTotals($totals))->toBe([]);
    expect(DB::getQueryLog())->toBe([]);
});

it('drops the gift the moment a coupon takes the basket under the band', function () {
    /*
     * The defect: the owner's rule is "after the coupon". A AED 120 basket with
     * a AED 30 coupon is AED 90 and earns nothing; showing the gift (or writing
     * it on the order) gives away stock the rule does not give.
     * MUTATION: make basis() return the subtotal alone. RED.
     */
    $gift = lfgProduct('Mask');
    lfgRange(100, 299, [[$gift, 2]]);
    lfgOn();
    $cart = lfgCart([[lfgProduct('Serum', ['price' => 12000]), 1]]);

    expect(app(FreeGifts::class)->forTotals(lfgTotals($cart)))->toHaveCount(1);

    $coupon = Coupon::create(['code' => 'FG30', 'type' => 'fixed_cart', 'amount' => 3000]);
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    expect(app(FreeGifts::class)->forTotals(lfgTotals($cart)))->toBe([]);

    // And "before the coupon" is the owner's to choose, in Display.
    lfgOn(['basis' => 'before_discounts']);
    expect(app(FreeGifts::class)->forTotals(lfgTotals($cart)))->toHaveCount(1);
});

it('skips a sold-out or unpublished gift silently, and gives what is left of a short one', function () {
    /*
     * The defect: a sold-out mask shown as a gift, then missing from the
     * parcel -- or the whole order refused because a free item ran out.
     * MUTATION: drop the stock_status test in linesFor(). RED.
     */
    $soldOut = lfgProduct('Sold out mask', ['stock_status' => 'outofstock']);
    $draft = lfgProduct('Draft mini', ['status' => 'draft']);
    $short = lfgProduct('Last two masks', ['manage_stock' => true, 'stock' => 2]);
    $fine = lfgProduct('Toner mini');
    lfgRange(100, null, [[$soldOut, 1], [$draft, 1], [$short, 5], [$fine, 1]]);
    lfgOn();

    $lines = app(FreeGifts::class)->forTotals(lfgTotals(lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]])));

    expect(array_column($lines, 'quantity', 'product_id'))->toBe([$short->id => 2, $fine->id => 1]);
});

it('honours the band dates and the UAE-only option', function () {
    $gift = lfgProduct('Mask');
    lfgRange(100, null, [[$gift, 1]], 'Gifts', ['starts_at' => now()->addDays(3)->format('Y-m-d')]);
    lfgOn();
    $totals = lfgTotals(lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]));

    expect(app(FreeGifts::class)->forTotals($totals))->toBe([]);

    DB::table('free_gift_ranges')->update(['starts_at' => null]);
    app(FreeGifts::class)->compile();
    lfgOn(['countries' => 'AE']);

    expect(app(FreeGifts::class)->forTotals($totals, 'AE'))->toHaveCount(1)
        ->and(app(FreeGifts::class)->forTotals($totals, 'SA'))->toBe([]);
});

/*
|------------------------------------------------------------------------------
| THE SHOP
|------------------------------------------------------------------------------
*/

it('draws the 02 Mint outline box in the cart panel and the cart page, closed by default', function () {
    /*
     * The owner's final design (docs/freebies-options/box-02-final.html): the
     * green outline, the title on the border, the band's wording with
     * " · N gifts", a row of photos and "View all"; opened, a dashed divider,
     * the small list with a green FREE on every row, and "Hide". No value
     * badge, no total row, no nudge line.
     * MUTATION: remove the include from partials/cart-drawer.blade.php. RED.
     */
    $gift = lfgProduct('Tea Tree Sheet Mask', ['image' => '/storage/media/mask.jpg']);
    lfgRange(100, 299, [[$gift, 3]]);
    lfgOn();
    $cart = lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]);

    $drawer = lfgShopper($cart)->getJson('/api/cart/drawer')->json('html');

    expect($drawer)->toContain('<fieldset class="fgb fgb-clamp2 fgb-k1" style="--fg-ts:12px;--fg-pl:28px;--fg-pr:36px;--fg-fg:16px;--fg-rg:3px" data-fgs="')
        ->toContain('Free gifts for you')
        ->toContain('<b>×3</b>')
        ->toContain('aria-expanded="false"')
        ->toContain('<div class="fgb-ls" hidden>')
        ->toContain('<em>3× </em>Tea Tree Sheet Mask')
        ->toContain('Gifts <span class="fgb-cnt">· 3 gifts</span>')
        ->toContain('<div class="fgb-list">')
        ->toContain('FREE')
        ->toContain('View all')
        ->toContain('Hide')
        ->not->toContain('Worth')
        ->not->toContain('more to');

    $page = lfgShopper($cart)->get('/cart/')->getContent();
    expect($page)->toContain('<fieldset class="fgb fgb-page fgb-clamp2 fgb-k1" style="--fg-ts:12px;--fg-pl:28px;--fg-pr:36px;--fg-fg:16px;--fg-rg:3px" data-fgs="');

    // Show gift prices, when the owner turns it on: the struck price before FREE.
    lfgOn(['show_value' => true]);
    $drawer = lfgShopper($cart)->getJson('/api/cart/drawer')->json('html');
    expect($drawer)->toContain('<s>AED 75</s>FREE')->not->toContain('Worth');
});

it('draws the box and the "Free gifts (N)" line in the checkout summary', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $gift = lfgProduct('Sheet Mask');
    lfgRange(100, 299, [[$gift, 2]]);
    lfgOn();
    $cart = lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]);

    $html = lfgShopper($cart)->get('/checkout/')->getContent();

    expect($html)->toContain('<fieldset class="fgb fgb-clamp2 fgb-k1" style="--fg-ts:12px;--fg-pl:28px;--fg-pr:36px;--fg-fg:16px;--fg-rg:3px" data-fgs="')
        ->toContain('Free gifts (2)');
});

it('escapes the owner\'s wording and the product names in the box', function () {
    /*
     * The defect: the title is a setting and a name came from the WordPress
     * import; printed raw, either one is script on the checkout.
     * MUTATION: print the title with {!! !!} in partials/free-gifts/box. RED.
     */
    $gift = lfgProduct('<img src=x onerror=alert(1)>');
    lfgRange(100, null, [[$gift, 1]]);
    lfgOn(['texts' => ['title_en' => '<script>bad()</script>']]);
    $html = view('partials.free-gifts.box', ['kbbFgRows' => FreeGifts::rows(app(FreeGifts::class)->forTotals(lfgTotals(lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]))))])->render();

    expect($html)->not->toContain('<script>bad()')->not->toContain('<img src=x')
        ->toContain('&lt;script&gt;bad()');
});

it('renders nothing at all with the switch off', function () {
    expect(view('partials.free-gifts.box', ['kbbFgRows' => []])->render())->toBe('');
});

it('speaks Arabic in the box on an Arabic page', function () {
    $gift = lfgProduct('Sheet Mask');
    lfgRange(100, null, [[$gift, 2]]);
    lfgOn();
    app()->setLocale('ar');

    $html = view('partials.free-gifts.box', ['kbbFgRows' => FreeGifts::rows(app(FreeGifts::class)->forTotals(lfgTotals(lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]))))])->render();
    app()->setLocale('en');

    expect($html)->toContain('هدايا مجانية لكِ')->toContain('هديتان')->toContain('مجاناً')->toContain('عرض الكل')->toContain('إخفاء')
        ->not->toContain('Free gifts for you');
});

it('costs the same queries for 3 gifts as for 40, and none for a basket under the first band', function () {
    /*
     * The defect: one query per gift (a brand per row, a stock read per row) --
     * the 12-mask band would cost a dozen queries on every page with a basket.
     * MUTATION: drop the setRelation('brand', …) in products() -- each brand loads lazily. RED.
     */
    lfgOn();
    $count = function (int $n): int {
        DB::table('free_gift_ranges')->delete();
        $gifts = [];
        for ($i = 0; $i < $n; $i++) {
            $brand = \App\Models\Brand::create(['name' => 'B' . $i . Str::random(4), 'slug' => 'b-' . $i . '-' . Str::random(6)]);
            $gifts[] = [lfgProduct('Gift ' . $i, ['brand_id' => $brand->id]), 1];
        }
        lfgRange(100, null, $gifts);
        $totals = lfgTotals(lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]));
        request()->attributes->replace([]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = FreeGifts::rows(app(FreeGifts::class)->forTotals($totals));
        expect($rows)->toHaveCount($n);

        return count(DB::getQueryLog());
    };

    expect($count(3))->toBe($count(40))->toBe(1);

    $under = lfgTotals(lfgCart([[lfgProduct('Cheap', ['price' => 5000]), 1]]));
    DB::flushQueryLog();
    expect(app(FreeGifts::class)->forTotals($under))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

/*
|------------------------------------------------------------------------------
| GIFT ONLY (NOT FOR SALE)
|------------------------------------------------------------------------------
*/

it('hides a gift-only product from the shop, search, sitemap, the API and the bag, and still gives it', function () {
    /*
     * The defect: the mini masks are not for sale; one reachable by URL, search
     * or /api can be bought for AED 0 or shows the shop's freebies as stock.
     * MUTATION: drop the gift_only line in Product::booted() saving. RED.
     */
    $mini = lfgProduct('Clay Mask Mini Gift', ['gift_only' => true]);

    expect($mini->fresh()->is_visible)->toBeFalse();

    test()->get('/product/' . $mini->slug . '/')->assertNotFound();
    expect(test()->getJson('/api/products?search=Clay Mask Mini Gift')->getContent())->not->toContain('Clay Mask Mini Gift');
    expect(test()->get('/sitemap.xml')->getContent())->not->toContain($mini->slug);
    expect(test()->get('/search?q=Clay+Mask+Mini')->getContent())->not->toContain('/product/' . $mini->slug);

    $cart = lfgCart([]);
    $add = test()->withoutMiddleware([Illuminate\Cookie\Middleware\EncryptCookies::class, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson('/api/cart/add', ['product_id' => $mini->id, 'quantity' => 1]);
    expect($cart->fresh()->items)->toHaveCount(0);

    // Still a gift.
    lfgRange(100, null, [[$mini, 1]]);
    lfgOn();
    expect(app(FreeGifts::class)->forTotals(lfgTotals(lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]))))->toHaveCount(1);

    // A product editor save that ticks "visible" cannot put it on sale.
    $mini->is_visible = true;
    $mini->save();
    expect($mini->fresh()->is_visible)->toBeFalse();
});

/*
|------------------------------------------------------------------------------
| THE ORDER
|------------------------------------------------------------------------------
*/

it('stores the gifts on the order at AED 0, from their band, and takes them off the shelf', function () {
    /*
     * The defect: gifts promised on the checkout and missing from the order
     * (so from the packing slip), or written at a price, or never taken off
     * stock so the next shopper is promised a mask that is not there.
     * MUTATION: remove the FreeGifts block from CheckoutController::place(). RED.
     */
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $mask = lfgProduct('Sheet Mask', ['manage_stock' => true, 'stock' => 10, 'gift_only' => true]);
    lfgRange(100, 299, [[$mask, 3]], '2 Masks');
    lfgOn();
    $serum = lfgProduct('Serum', ['price' => 15000]);

    lfgPlace(lfgCart([[$serum, 1]]))->assertRedirect();

    $order = Order::latest('id')->first();

    expect($order->items)->toHaveCount(1)
        ->and($order->gifts)->toHaveCount(1)
        ->and((int) $order->free_gift_count)->toBe(3)
        ->and((int) $order->subtotal)->toBe(15000)
        ->and((int) $order->total)->toBe(15000 + 2000);

    $g = $order->gifts->first();
    expect((bool) $g->is_gift)->toBeTrue()
        ->and((int) $g->unit_price)->toBe(0)->and((int) $g->total)->toBe(0)
        ->and((int) $g->quantity)->toBe(3)
        ->and($g->gift_range_label)->toBe('AED 100 – 299')
        ->and((int) $mask->fresh()->stock)->toBe(7);

    // A gift whose shelf ran dry between the summary and Place order is left
    // out; the order still goes through.
    $mask->forceFill(['stock' => 0, 'stock_status' => 'instock'])->save();
    lfgPlace(lfgCart([[$serum, 1]]))->assertRedirect();
    $second = Order::latest('id')->first();
    expect($second->gifts)->toHaveCount(0)->and($second->items)->toHaveCount(1);
});

it('lists the gifts on the confirmation email, the invoice and the packing slip', function () {
    /*
     * The defect: the packer works from the packing slip; a gift not on it is a
     * gift not in the parcel, and the customer's email says nothing of it.
     * MUTATION: drop the gift loop from InvoiceDocument::items(). RED.
     */
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $mask = lfgProduct('Tea Tree Sheet Mask');
    lfgRange(100, 299, [[$mask, 2]], '2 Masks');
    lfgOn();
    lfgPlace(lfgCart([[lfgProduct('Serum', ['price' => 15000]), 1]]))->assertRedirect();
    $order = Order::latest('id')->first();

    $mail = (new \App\Mail\OrderConfirmation($order))->render();
    expect($mail)->toContain('Tea Tree Sheet Mask')->toContain('Free gift');

    $doc = app(\App\Services\Invoices\InvoiceDocument::class)->present($order);
    $gifts = array_values(array_filter($doc['items'], fn ($i) => ! empty($i['gift'])));
    expect($gifts)->toHaveCount(1)->and($gifts[0]['name'])->toBe('Tea Tree Sheet Mask')->and($gifts[0]['lineFils'])->toBe(0);

    foreach (['sheet-invoice', 'sheet-packing-slip', 'sheet-delivery-note'] as $sheet) {
        $html = view('invoices.partials.' . $sheet, ['doc' => $doc, 'order' => $order])->render();
        expect($html)->toContain('Tea Tree Sheet Mask')->toContain('Free gift');
    }
})->skip(fn () => ! class_exists(\App\Mail\OrderConfirmation::class), 'no confirmation mailable');

it('sends every payment gateway exactly the same request with or without gifts', function (string $gateway) {
    /*
     * PAYMENTS ARE FROZEN. The defect: a gift line in Tabby's or Tamara's items
     * at AED 0 -- a payload the gateway has never seen, a different hash, and a
     * refusal the owner cannot read. Order::items() leaves gifts out, so every
     * gateway reads what it read before.
     * MUTATION: remove the is_gift constraint from Order::items(). RED for
     * tabby and tamara.
     */
    Http::preventStrayRequests();
    Http::fake([
        'api.tabby.ai/*' => Http::response(['id' => 's1', 'status' => 'created', 'payment' => ['id' => 'p1'], 'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/x']]]]]),
        '*/checkout' => Http::response(['order_id' => 'tam_1', 'checkout_url' => 'https://checkout.tamara.co/x']),
        'api.stripe.com/*' => Http::response(['id' => 'pi_1', 'client_secret' => 'pi_1_secret', 'status' => 'requires_payment_method']),
    ]);

    $configs = [
        'tabby' => ['public_key' => 'pk_test_11111111-2222-3333-4444-555555555555', 'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555', 'merchant_code' => 'AE', 'webhook_secret' => 'whsec-tabby-abcdefghijklmnopqrstuvwxyz012345'],
        'tamara' => ['api_token' => 'FG_TAMARA_TOKEN', 'notification_token' => 'fg-tamara-notify', 'webhook_secret' => 'whsec-tamara-fg-0123456789abcd'],
        'stripe' => ['publishable_key' => 'pk_test_fg', 'secret_key' => 'sk_test_fg', 'webhook_signing_secret' => 'whsec_fg', 'webhook_secret' => 'whsec-url-fg-0123456789ab'],
    ];
    $row = PaymentProvider::create(['id' => $gateway, 'title' => ucfirst($gateway), 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = $configs[$gateway];
    $row->save();
    app(GatewayCredentials::class)->forget();

    $make = function (bool $withGifts) use ($gateway): Order {
        $order = Order::create([
            'order_number' => 'FG-SAME', 'email' => 'buyer@example.com', 'phone' => '+971500000000', 'status' => 'pending', 'currency' => 'AED',
            'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000'],
            'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000'],
            'subtotal' => 30000, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'fee_total' => 0, 'total' => 30000,
            'payment_method' => $gateway, 'free_gift_count' => $withGifts ? 5 : 0,
        ]);
        $order->items()->create(['name' => 'Snail Mucin Essence', 'sku' => 'COS-1', 'quantity' => 2, 'unit_price' => 15000, 'subtotal' => 30000, 'total' => 30000]);
        if ($withGifts) {
            $order->items()->create(['name' => 'Sheet Mask', 'brand' => 'Mediheal', 'quantity' => 3, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true, 'gift_range_label' => 'AED 300 – 499']);
            $order->items()->create(['name' => 'Toner Mini', 'quantity' => 2, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
        }

        return $order->fresh();
    };

    // Each run inside a rolled-back savepoint, so both orders get the SAME ids
    // and the two requests can be compared byte for byte with nothing masked.
    $sent = function (bool $withGifts) use ($gateway, $make): string {
        $before = count(Http::recorded());
        DB::beginTransaction();
        try {
            app(\App\Services\Payments\GatewayRegistry::class)->find($gateway)->start($make($withGifts));
        } finally {
            DB::rollBack();
        }

        return implode("\n", array_map(fn ($pair) => $pair[0]->method() . ' ' . $pair[0]->url() . ' ' . $pair[0]->body(), Http::recorded()->slice($before)->all()));
    };

    $plain = $sent(false);
    $gifted = $sent(true);

    expect($plain)->not->toBe('')->and($gifted)->toBe($plain);
})->with(['tabby', 'tamara', 'stripe']);

/*
|------------------------------------------------------------------------------
| THE ADMIN
|------------------------------------------------------------------------------
*/

it('gives the screen its own capability and fails closed', function () {
    /*
     * The defect: a support login switching the shop's gifts on, or an unknown
     * verb under the prefix falling through to "allowed".
     * MUTATION: map ['*', 'admin-api/free-gifts/**'] to freegifts.view. RED.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/free-gifts'))->toBe('freegifts.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/free-gifts/products'))->toBe('freegifts.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/free-gifts/switch'))->toBe('freegifts.manage')
        ->and(AdminCapabilities::forPath('PATCH', 'admin-api/free-gifts/anything'))->toBe('freegifts.manage')
        ->and(AdminCapabilities::roleCan('support', 'freegifts.manage'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('owner', 'freegifts.manage'))->toBeTrue();

    test()->getJson('/admin-api/free-gifts')->assertStatus(401);
    test()->actingAs(lfgAdmin('support'), 'admin')->postJson('/admin-api/free-gifts/switch', ['on' => true])->assertForbidden();
    test()->actingAs(lfgAdmin('owner'), 'admin')->getJson('/admin-api/free-gifts')->assertOk()->assertJsonPath('config.on', false);
});

it('lets ranges overlap and gives a basket in two of them the higher range, and still refuses a bad quantity or product', function () {
    /*
     * The defect (the owner, 22 Oct): "the server should not restrict the
     * ranges. it's up to us how we set and what gap we set. server should also
     * calculate the range." Overlaps used to answer 422; a top amount below the
     * start too.
     * MUTATION: return the FIRST matching range in FreeGifts::rangeFor() -- the
     * AED 300 basket gets the 100 – 300 gifts. RED.
     * MUTATION: put the overlaps() refusal back in saveRange(). RED.
     */
    $owner = lfgAdmin('owner');
    $mask = lfgProduct('Mask');
    $mini = lfgProduct('Mini');
    $post = fn (array $b) => test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/ranges', array_merge([
        'min' => 100, 'max' => 300, 'label_en' => '2 Masks', 'label_ar' => 'قناعان', 'enabled' => true, 'gifts' => [['product_id' => $mask->id, 'quantity' => 2]],
    ], $b));

    $post([])->assertOk();
    $post(['min' => 300, 'max' => 499, 'gifts' => [['product_id' => $mini->id, 'quantity' => 1]]])->assertOk();   // shares AED 300
    $post(['min' => 900, 'max' => 700])->assertOk();                                                             // read as 700 – 900
    $post(['min' => 1000, 'max' => null, 'gifts' => [['product_id' => $mask->id, 'quantity' => 51]]])->assertStatus(422);
    $post(['min' => 1000, 'max' => null, 'gifts' => [['product_id' => 999999, 'quantity' => 1]]])->assertStatus(422);
    $post(['min' => 1000, 'max' => null, 'gifts' => [['product_id' => $mask->id, 'quantity' => 0]]])->assertStatus(422);

    expect(DB::table('free_gift_ranges')->count())->toBe(3)
        ->and((int) DB::table('free_gift_ranges')->where('min_fils', 70000)->value('max_fils'))->toBe(90000);

    lfgFresh();
    $engine = app(\App\Services\FreeGifts::class);
    expect($engine->rangeFor(30000)['min'])->toBe(30000)      // in both: the higher
        ->and($engine->rangeFor(29999)['min'])->toBe(10000)   // only the first
        ->and($engine->rangeFor(45000)['min'])->toBe(30000);
});

it('switches to Testing and Live whatever the checklist says, and lists each problem as a warning', function () {
    /*
     * The defect (the owner, 22 Oct): "allow me to enable the gifts and test,
     * it should not be restricted by non-available products". Testing and
     * Live answered 422 "finish the items marked Needed" while one range had
     * no gift, or a picked gift was unpublished -- so the owner could not
     * even test the ranges that DID work.
     * MUTATION: put back `if ($mode !== 'off' && $this->gifts->blocked())
     * { return ... 422; }` in FreeGiftsController::switch(). RED.
     */
    $owner = lfgAdmin('owner');
    $switch = fn (string $mode) => test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => $mode]);

    // No range at all.
    $switch('live')->assertOk()->assertJsonPath('config.mode', 'live');
    $switch('off')->assertOk();

    $sold = lfgProduct('Sold Out Mask', ['stock_status' => 'outofstock']);
    $draft = lfgProduct('Draft Mask', ['status' => 'draft']);
    lfgRange(100, 299, [], '2 Masks');                       // no gifts
    lfgRange(300, 499, [[$sold, 1], [$draft, 1]], 'Masks');  // nothing it can give
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/testers', ['testers' => [['ip' => '203.0.113.7', 'label' => 'Phone']]])->assertOk();

    foreach (['testing', 'live', 'testing', 'off', 'live'] as $mode) {
        $r = $switch($mode);
        $r->assertOk()->assertJsonPath('config.mode', $mode);
        expect($r->status())->not->toBe(422);
    }

    $levels = collect(app(FreeGifts::class)->checklist())->pluck('level')->unique()->sort()->values()->all();
    expect($levels)->toBe(['alert', 'warn'])
        ->and(method_exists(FreeGifts::class, 'blocked'))->toBeFalse();

    // Deleting a range no longer flips the owner's mode to Off behind his back.
    test()->actingAs($owner, 'admin')->deleteJson('/admin-api/free-gifts/ranges/' . DB::table('free_gift_ranges')->where('min_fils', 30000)->value('id'))->assertOk();
    expect(app(FreeGifts::class)->mode())->toBe('live');
});

it('puts the owner\'s own IP on an empty Testing list instead of refusing Testing', function () {
    /*
     * The one case that used to be a 422 and still can never work as asked:
     * Testing with nobody on the list shows the gifts to nobody. Lane FG3 chose
     * the friendlier answer -- the address the click came from goes on the
     * list, the answer names it (added_ip) and the screen toasts it.
     * MUTATION: drop the `$config['testers'] = [[...$added...]]` line in
     * FreeGiftsController::switch(). RED (testers stays empty).
     */
    $owner = lfgAdmin('owner');

    test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
        ->postJson('/admin-api/free-gifts/switch', ['mode' => 'testing'])
        ->assertOk()
        ->assertJsonPath('config.mode', 'testing')
        ->assertJsonPath('added_ip', '198.51.100.23')
        ->assertJsonPath('config.testers.0.ip', '198.51.100.23');

    // A list that already has someone is left exactly as it is.
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => 'off'])->assertOk();
    test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/admin-api/free-gifts/switch', ['mode' => 'testing'])
        ->assertOk()
        ->assertJsonMissingPath('added_ip')
        ->assertJsonPath('config.testers', [['ip' => '198.51.100.23', 'label' => 'My device (added when Testing was switched on)']]);

    // The screen says so in a toast, through say() (escaped).
    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect($screen)->toContain("res && res.added_ip ? 'Free gifts are in Testing. Your IP ' + res.added_ip");
});

it('places the order in every stock case: all gifts, the ones in stock, none -- and a restock brings a gift back', function () {
    /*
     * The owner: "That range will still work while placing the order by the
     * customer, unless we add back stock." Switched on while one gift is out
     * of stock, the range must still give the others; with every gift out it
     * gives nothing and the order still goes through; a restocked gift comes
     * back by itself, with no admin step.
     * MUTATION: in FreeGifts::linesFor() change `continue;` after the
     * stock_status check to `return [];`. RED at "one out of stock".
     */
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $mask = lfgProduct('Sheet Mask', ['manage_stock' => true, 'stock' => 50, 'gift_only' => true]);
    $mini = lfgProduct('Toner Mini', ['stock_status' => 'outofstock', 'gift_only' => true]);
    lfgRange(100, 299, [[$mask, 2], [$mini, 1]], '2 Masks + 1 Mini');
    $serum = lfgProduct('Serum', ['price' => 15000]);

    // Switched on through the screen's own endpoint while $mini is out of stock.
    test()->actingAs(lfgAdmin('owner'), 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => 'live'])->assertOk();
    lfgFresh();
    $place = function () use ($serum): Order {
        lfgPlace(lfgCart([[$serum, 1]]))->assertRedirect();

        return Order::latest('id')->first();
    };
    $gifts = fn (Order $o) => $o->gifts->mapWithKeys(fn ($g) => [$g->name => (int) $g->quantity])->all();

    // One out of stock: the rest of the range is still given.
    $o = $place();
    expect($gifts($o))->toBe(['Sheet Mask' => 2])->and((int) $o->free_gift_count)->toBe(2)->and($o->items)->toHaveCount(1);

    // Restocked: it comes back by itself. All available: everything is given.
    $mini->forceFill(['stock_status' => 'instock'])->save();
    $o = $place();
    expect($gifts($o))->toBe(['Sheet Mask' => 2, 'Toner Mini' => 1])->and((int) $o->free_gift_count)->toBe(3);

    // All out of stock: no gift, no error, the order is placed as usual.
    $mask->forceFill(['stock' => 0])->save();
    $mini->forceFill(['stock_status' => 'outofstock'])->save();
    $o = $place();
    expect($o->gifts)->toHaveCount(0)->and((int) $o->free_gift_count)->toBe(0)
        ->and($o->items)->toHaveCount(1)->and((int) $o->total)->toBe(15000 + 2000);
});

it('ships the owner\'s five bands with the switch off', function () {
    /*
     * The seed migration, read back from a fresh database (beforeEach above
     * cleared the bands, so they are re-read from the migration's own list).
     */
    $migration = require database_path('migrations/2027_11_22_100100_free_gifts_seed.php');
    $migration->up();
    lfgFresh();

    $ranges = DB::table('free_gift_ranges')->orderBy('min_fils')->get();
    expect($ranges->pluck('min_fils')->all())->toBe([10000, 30000, 50000, 100000, 200000])
        ->and($ranges->pluck('max_fils')->all())->toBe([29900, 49900, 99900, 199900, null])
        ->and($ranges->pluck('label_en')->all())->toBe(['2 Masks', '3 Masks + 1 Mini Product', '3 Masks + 2 Mini Products + 1 Full Size Product', '5 Masks + 2 Minis + 2 Full Size Products', '12 Masks + 5 Minis + 3 Full Size Products'])
        ->and(app(FreeGifts::class)->on())->toBeFalse();
});

/*
|------------------------------------------------------------------------------
| THE CHECKOUT SUMMARY BACKGROUND
|------------------------------------------------------------------------------
*/

it('paints the checkout summary content #fbf7f8, as the owner asked, and keeps the old choices', function () {
    /*
     * The owner: the summary's content was "super light greenish"; he chose the
     * free-gifts preview's #fbf7f8. Default and the migration both move to it;
     * Almost white and Very light green stay selectable.
     * MUTATION: default lo_sum_open back to 'white'. RED.
     */
    $page = app(\App\Services\CheckoutPage::class);
    expect($page->bodyClass())->toContain(' cop-sow')->toContain(' cop-sob');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
    expect($css)->toContain('.kbb-checkout.cop-sumg.cop-sob .summary.cosr-open{background:#fbf7f8}')
        ->toContain('.kbb-checkout.cop-sumg.cop-sob .summary{background:#fbf7f8}');

    $page->save(['lo_sum_open' => 'white']);
    expect(app(\App\Services\CheckoutPage::class)->bodyClass())->toContain(' cop-sow')->not->toContain('cop-sob');
});

/*
|------------------------------------------------------------------------------
| MODE: OFF / TESTING / LIVE  (Lane FG2; the owner: "make sure it does not go
| live without our checking completely. Add an IP list which will see this
| feature, so we can test.")
|------------------------------------------------------------------------------
*/

/** Every page bit a shopper gets, with the per-request tokens taken out. */
function lfgShopBytes(Cart $cart, string $ip): array
{
    $strip = fn (string $h) => preg_replace(['/(name="_token"[^>]*value=|"csrf-token" content=|csrf["\']?\s*[:=]\s*)["\'][^"\']*["\']/i', '/nonce="[^"]*"/'], ['$1""', 'nonce=""'], $h);

    return [
        'drawer' => $strip((string) lfgShopper($cart)->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/api/cart/drawer')->json('html')),
        'cart' => $strip(lfgShopper($cart)->withServerVariables(['REMOTE_ADDR' => $ip])->get('/cart/')->getContent()),
        'checkout' => $strip(lfgShopper($cart)->withServerVariables(['REMOTE_ADDR' => $ip])->get('/checkout/')->getContent()),
    ];
}

function lfgMode(string $mode, array $testers = []): void
{
    lfgOn(['mode' => $mode, 'on' => $mode !== 'off', 'testers' => $testers]);
}

it('in Testing shows a shopper whose IP is not listed exactly the Off shop, and saves no gifts on the order', function () {
    /*
     * The defect: the owner tests on his phone and every customer sees the
     * half-checked gifts too -- or sees the box and then gets an order without
     * them because placement asked a different question.
     * MUTATION: make visibleTo() return true for 'testing'. RED.
     */
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $mask = lfgProduct('Sheet Mask', ['manage_stock' => true, 'stock' => 10, 'gift_only' => true]);
    lfgRange(100, 299, [[$mask, 2]], '2 Masks');
    $serum = lfgProduct('Serum', ['price' => 15000]);
    $cart = lfgCart([[$serum, 1]]);

    lfgMode('off');
    $off = lfgShopBytes($cart, '198.51.100.9');
    lfgMode('testing', [['ip' => '203.0.113.7', 'label' => 'Owner']]);
    $testing = lfgShopBytes($cart, '198.51.100.9');

    expect($off['drawer'])->not->toContain('fgb')
        ->and($testing['drawer'])->toBe($off['drawer'])
        ->and($testing['cart'])->toBe($off['cart'])
        ->and($testing['checkout'])->toBe($off['checkout']);

    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);
    lfgPlace(lfgCart([[$serum, 1]]))->assertRedirect();
    $order = Order::latest('id')->first();
    expect($order->gifts)->toHaveCount(0)->and((int) $order->free_gift_count)->toBe(0)->and((int) $mask->fresh()->stock)->toBe(10);
});

it('in Testing gives a listed IP the box and the gifts on its order', function () {
    /*
     * MUTATION: make visibleTo() return false for 'testing'. RED.
     */
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $mask = lfgProduct('Sheet Mask', ['manage_stock' => true, 'stock' => 10, 'gift_only' => true]);
    lfgRange(100, 299, [[$mask, 2]], '2 Masks');
    $serum = lfgProduct('Serum', ['price' => 15000]);
    lfgMode('testing', [['ip' => '203.0.113.7', 'label' => 'Owner'], ['ip' => '2001:db8::1', 'label' => '']]);

    $bytes = lfgShopBytes(lfgCart([[$serum, 1]]), '203.0.113.7');
    expect($bytes['drawer'])->toContain('<fieldset class="fgb fgb-clamp2 fgb-k1" style="--fg-ts:12px;--fg-pl:28px;--fg-pr:36px;--fg-fg:16px;--fg-rg:3px" data-fgs="')
        ->and($bytes['checkout'])->toContain('Free gifts (2)');

    // The same address written another way is the same address.
    expect(lfgShopBytes(lfgCart([[$serum, 1]]), '2001:0db8:0:0:0:0:0:0001')['drawer'])->toContain('<fieldset class="fgb fgb-clamp2 fgb-k1" style="--fg-ts:12px;--fg-pl:28px;--fg-pr:36px;--fg-fg:16px;--fg-rg:3px" data-fgs="');

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
    lfgPlace(lfgCart([[$serum, 1]]))->assertRedirect();
    $order = Order::latest('id')->first();
    expect($order->gifts)->toHaveCount(1)->and((int) $order->free_gift_count)->toBe(2);
});

it('in Live gives every shopper the gifts, whatever their IP', function () {
    /*
     * MUTATION: make visibleTo() return false for 'live'. RED.
     */
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $mask = lfgProduct('Sheet Mask', ['gift_only' => true]);
    lfgRange(100, 299, [[$mask, 2]], '2 Masks');
    $serum = lfgProduct('Serum', ['price' => 15000]);
    lfgMode('live', [['ip' => '203.0.113.7', 'label' => '']]);

    expect(lfgShopBytes(lfgCart([[$serum, 1]]), '198.51.100.9')['drawer'])->toContain('<fieldset class="fgb fgb-clamp2 fgb-k1" style="--fg-ts:12px;--fg-pl:28px;--fg-pr:36px;--fg-fg:16px;--fg-rg:3px" data-fgs="');
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);
    lfgPlace(lfgCart([[$serum, 1]]))->assertRedirect();
    expect(Order::latest('id')->first()->gifts)->toHaveCount(1);

    // The old saved switch reads as a mode: on is live, anything else off.
    expect(FreeGifts::modeOf(['on' => true]))->toBe('live')
        ->and(FreeGifts::modeOf(['on' => false]))->toBe('off')
        ->and(FreeGifts::modeOf([]))->toBe('off')
        ->and(FreeGifts::modeOf(['mode' => 'nonsense', 'on' => false]))->toBe('off');
});

it('decides by the firewall\'s address, never by X-Forwarded-For, and with no query', function () {
    /*
     * The defect: a customer sends "X-Forwarded-For: <the owner's IP>" and
     * sees -- and gets -- the gifts under test. The firewall (BlockGate,
     * Firewall::before()) uses $request->ip() with no trusted proxy; so does
     * visibleTo().
     * MUTATION: read $request->header('X-Forwarded-For') in visibleTo(). RED.
     */
    lfgMode('testing', [['ip' => '203.0.113.7', 'label' => '']]);
    lfgFresh();
    $gifts = app(FreeGifts::class);

    $spoof = Illuminate\Http\Request::create('/cart', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_REAL_IP' => '203.0.113.7']);
    $real = Illuminate\Http\Request::create('/cart', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']);

    $gifts->visibleTo($real); // warm the settings memo
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($gifts->visibleTo($spoof))->toBeFalse()
        ->and($gifts->visibleTo($real))->toBeTrue()
        ->and($gifts->visibleTo(null))->toBeFalse()
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();

    $source = (string) file_get_contents(app_path('Services/FreeGifts.php'));
    expect($source)->toContain('IpRange::pack((string) $request->ip())')
        ->not->toContain("header('X-Forwarded-For')")
        ->not->toContain('getClientIps');
});

it('keeps only real IPs on the Testing list, canonical, de-duplicated, at most 50, and will not empty it while Testing', function () {
    /*
     * The defect: "203.0.113.300" or a hostname saved, matching nobody, and the
     * owner concluding the feature is broken; or the list growing without end.
     * MUTATION: drop the filter_var() check in FreeGiftsController::testers(). RED.
     */
    $owner = lfgAdmin('owner');
    $mask = lfgProduct('Sheet Mask');
    lfgRange(100, 299, [[$mask, 2]], '2 Masks');
    $post = fn (array $t) => test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/testers', ['testers' => $t]);

    foreach (['203.0.113.300', 'example.com', '10.0.0.0/8', '1.2.3', 'javascript:alert(1)'] as $bad) {
        $post([['ip' => $bad, 'label' => '']])->assertStatus(422);
    }
    $post(array_map(fn ($i) => ['ip' => '10.0.' . intdiv($i, 250) . '.' . ($i % 250), 'label' => ''], range(1, 51)))->assertStatus(422);

    // Testing with nobody on the list is no longer refused: the caller's own
    // address goes on it (Lane FG3; its own case below). Back to Off for the rest.
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => 'testing'])->assertOk()->assertJsonPath('added_ip', '127.0.0.1');
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => 'off'])->assertOk();

    $post([['ip' => '2001:0DB8:0:0:0:0:0:1', 'label' => 'Phone'], ['ip' => '2001:db8::1', 'label' => 'dup'], ['ip' => ' 203.0.113.7 ', 'label' => 'Office']])
        ->assertOk()
        ->assertJsonPath('config.testers', [['ip' => '2001:db8::1', 'label' => 'Phone'], ['ip' => '203.0.113.7', 'label' => 'Office']]);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => 'testing'])->assertOk()->assertJsonPath('config.mode', 'testing');
    $post([])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => 'banana'])->assertStatus(422);

    // The screen gets the address the server sees, for "Add my current IP".
    test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->getJson('/admin-api/free-gifts')->assertJsonPath('my_ip', '198.51.100.23');

    test()->actingAs(lfgAdmin('support'), 'admin')->postJson('/admin-api/free-gifts/testers', ['testers' => []])->assertForbidden();
});

it('turns a saved switch into a mode on migrating: on becomes live, anything else off', function () {
    /*
     * MUTATION: write 'testing' for a saved on in 2027_11_23_100100_free_gifts_mode. RED.
     */
    $migration = require database_path('migrations/2027_11_23_100100_free_gifts_mode.php');
    $read = fn () => json_decode((string) DB::table('settings')->where('key', 'free_gifts')->value('value'), true);

    DB::table('settings')->updateOrInsert(['key' => 'free_gifts'], ['value' => json_encode(['on' => true, 'countries' => 'all']), 'autoload' => true]);
    $migration->up();
    expect($read())->toMatchArray(['mode' => 'live', 'on' => true, 'testers' => [], 'countries' => 'all']);

    DB::table('settings')->updateOrInsert(['key' => 'free_gifts'], ['value' => json_encode(['on' => false]), 'autoload' => true]);
    $migration->up();
    expect($read()['mode'])->toBe('off');

    // A row that already has a mode is left alone.
    DB::table('settings')->updateOrInsert(['key' => 'free_gifts'], ['value' => json_encode(['mode' => 'testing', 'on' => true, 'testers' => [['ip' => '203.0.113.7', 'label' => '']]]), 'autoload' => true]);
    $migration->up();
    expect($read()['mode'])->toBe('testing');
});
