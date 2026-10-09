<?php

/**
 * ONE SWITCH FOR THE FREE-DELIVERY BAR ON THE CART PAGE AND THE CHECKOUT — Lane QK7.
 *
 * The owner, with two iPhone screenshots of the live checkout (2.60.453): "the
 * unlocked / free delivery green bar still showing in summary section and also
 * above the place order button on checkout, turn off from the cart and
 * checkout page. please." He had asked for it "complete OFF" once already.
 *
 * WHAT IT LOOKED LIKE ON THE SHOP. "🎉 Congratulations! You've unlocked free
 * delivery" and a green striped bar in the expanded Order summary AND again in
 * the "Your bag" block above Place order. The only switch for it was Store →
 * Modules → "Free-shipping progress bar", whose row said "Appearance → Cart
 * panel" and pointed at the drawer (which never read it); it gated the
 * checkout's partial and nothing else, so the cart page's two bars ignored it.
 *
 * Every render point now reads CheckoutPage::freeDeliveryBar():
 *   checkout  partials/checkout/freeship-bar, included by order-block, which is
 *             drawn twice (summary + "Your bag") and returned as orderHtml by
 *             /checkout/line and /checkout/coupon; the same partial is
 *             freeshipHtml in /api/checkout/rates, which checkout.js writes
 *             into .kbb-freeship-slot on a country change.
 *   cart      store/cart-inner's band over the items (classic layout) and its
 *             summary bar (both layouts), the same view /api/cart/update hands
 *             back as `page` after a quantity change.
 * The cart PANEL (partials/cart-drawer) is not one of them, deliberately.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    // routes/checkout-line.php (the line and coupon endpoints checkout.js
    // calls) is required by routes/web.php; register it only if it is not.
    if (! Route::has('checkout.lineUpdate')) {
        Route::middleware('web')->group(base_path('routes/checkout-line.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    // Free over AED 300, the way the live zone does it; bundles off so a
    // quantity of 3 is 3 x price.
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'title' => 'Free delivery',
        'cost' => 0, 'min_amount' => 30000, 'enabled' => true, 'position' => 1,
    ]);
    app(SettingsService::class)->set('bundles_enabled', false);
});

function fsbCart(int $priceFils, int $qty = 1): Cart
{
    $product = Product::create([
        'slug' => 'fsb-'.Str::random(8), 'name' => 'Snail Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => $priceFils, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $priceFils]);

    return $cart;
}

function fsbShopper(Cart $cart)
{
    // One test here walks several baskets, and CartService memoises the
    // basket it resolved -- in the instance a cached controller also holds.
    app(CartService::class)->forget();

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function fsbOn(bool $on): void
{
    SettingsService::forgetMemo();
    app(CheckoutPage::class)->save(['fs_bar_on' => $on]);
    SettingsService::forgetMemo();
}

/** The checkout bar's own markup, and the two sentences it can say. */
function fsbCheckoutBars(string $html): int
{
    return substr_count($html, '<div class="freebar ');
}

/**
 * The page without the cart panel's own bar. The layout carries the drawer
 * (#kcCart) on every page, the checkout included, and its `.kc-ship` says the
 * same sentences -- it is not one of the render points, and stays.
 */
function fsbWithoutDrawerBar(string $html): string
{
    return (string) preg_replace('#<div class="kc-ship">.*?<div class="kc-bar"><div class="kc-fill"[^>]*></div></div>#s', '', $html);
}

/** The cart page's bar: `.ship > .t` + `.bar > .fill` (the drawer's is `.kc-ship`). */
function fsbCartBars(string $html): int
{
    return substr_count($html, '<div class="ship">');
}

const FSB_WORDS = ['unlocked free delivery', 'away from'];

/* ───────────────────────── off by default, everywhere ───────────────────── */

it('ships off: the data migration has stored the switch off and the screen shows it', function () {
    // Mutation: delete the migration's save() and the row is absent.
    $row = Illuminate\Support\Facades\DB::table('settings')->where('key', 'checkoutpage_fs_bar_on')->first();
    expect($row)->not->toBeNull('the migration did not write the switch off')
        ->and(app(CheckoutPage::class)->freeDeliveryBar())->toBeFalse()
        ->and(CheckoutPage::SCHEMA['fs_bar_on'][2])->toBeFalse()
        ->and(CheckoutPage::TABS['delivery'][2])->toContain('fs_bar_on');
});

it('draws no bar in either checkout order block, over or under the threshold', function () {
    // Defect: two green bars on the live checkout, summary and "Your bag".
    // Mutation: put `$settings->moduleEnabled('freeship_bar', true)` back as the
    // partial's only gate and this is red with 2 bars.
    foreach ([40000, 15000] as $price) {
        $html = fsbShopper(fsbCart($price))->get('/checkout')->assertOk()->getContent();

        expect(fsbCheckoutBars($html))->toBe(0, "a bar on the checkout at {$price} fils")
            ->and($html)->not->toContain('fs-done')
            ->and($html)->not->toContain('class="ftrack"')
            // The slot JS writes into stays, empty, so a re-render has a target.
            ->and(substr_count($html, '<div class="kbb-freeship-slot">'))->toBe(2);

        foreach (FSB_WORDS as $w) {
            expect(str_contains(fsbWithoutDrawerBar($html), $w))->toBeFalse("“{$w}” on the checkout at {$price} fils");
        }
    }
});

it('keeps the Delivery row in the checkout totals, free over the threshold', function () {
    $html = fsbShopper(fsbCart(40000))->get('/checkout')->assertOk()->getContent();

    expect($html)->toContain('js-shipping');
});

it('keeps it off after a quantity change, a coupon and a country change on the checkout', function () {
    // The three endpoints checkout.js re-renders from. Mutation: render the
    // partial without the switch in CheckoutController::rates() and the
    // country change brings the bar straight back.
    $cart = fsbCart(15000);
    $line = $cart->items()->firstOrFail();

    $qty = fsbShopper($cart)->postJson('/checkout/line', ['item_id' => $line->id, 'quantity' => 3, 'country' => 'AE'])
        ->assertOk()->json();
    expect($qty['orderHtml'])->toContain('kbb-freeship-slot')
        ->and(fsbCheckoutBars($qty['orderHtml']))->toBe(0)
        ->and($qty['orderHtml'])->not->toContain('unlocked free delivery');

    $coupon = fsbShopper($cart)->postJson('/checkout/coupon', ['code' => 'NOSUCHCODE', 'country' => 'AE'])->json();
    expect(fsbCheckoutBars(json_encode($coupon)))->toBe(0);

    $rates = fsbShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE'])->assertOk()->json();
    expect(array_key_exists('freeshipHtml', $rates))->toBeTrue()
        ->and(trim($rates['freeshipHtml']))->toBe('');
});

it('draws no bar on the cart page, in either layout, over or under the threshold', function () {
    // Defect: the cart page's band and summary bar never read any switch.
    // Mutation: drop `&& $kbbFsBar` from cart-inner's band and this is red on
    // the classic layout; drop the summary's `! $kbbFsBar` branch and it is
    // red on the squeezed one.
    foreach (['classic', 'squeeze'] as $layout) {
        app(CartPage::class)->save(['layout' => $layout]);
        SettingsService::forgetMemo();

        foreach ([40000, 15000] as $price) {
            $html = fsbShopper(fsbCart($price))->get('/cart/')->assertOk()->getContent();
            expect(fsbCartBars($html))->toBe(0, "a bar on the {$layout} cart page at {$price} fils");
        }
    }
});

it('keeps it off after a quantity change on the cart page', function () {
    // /api/cart/update hands cart.js the re-rendered store/cart-inner as `page`.
    foreach (['classic', 'squeeze'] as $layout) {
        app(CartPage::class)->save(['layout' => $layout]);
        SettingsService::forgetMemo();

        $cart = fsbCart(15000);
        $line = $cart->items()->firstOrFail();

        // 1 x 150 -> 3 x 150: crosses the AED 300 threshold.
        $page = fsbShopper($cart)->postJson('/api/cart/update', ['item_id' => $line->id, 'quantity' => 3, 'with_page' => 1])
            ->assertOk()->json('page');

        expect($page)->toBeString()
            ->and(fsbCartBars($page))->toBe(0, "a bar in the {$layout} cart page re-render");

        foreach (FSB_WORDS as $w) {
            expect(str_contains($page, $w))->toBeFalse("“{$w}” in the {$layout} cart page re-render");
        }
    }
});

/* ─────────────────────────── on when switched on ────────────────────────── */

it('puts every bar back when the switch is turned on', function () {
    // Mutation: hard-code freeDeliveryBar() to false and every count is 0.
    fsbOn(true);

    $html = fsbShopper(fsbCart(40000))->get('/checkout')->assertOk()->getContent();
    expect(fsbCheckoutBars($html))->toBe(2)->and($html)->toContain('fs-done');

    $cart = fsbCart(15000);
    $rates = fsbShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE'])->assertOk()->json();
    expect(fsbCheckoutBars($rates['freeshipHtml']))->toBe(1);

    app(CartPage::class)->save(['layout' => 'classic']);
    SettingsService::forgetMemo();
    expect(fsbCartBars(fsbShopper(fsbCart(15000))->get('/cart/')->getContent()))->toBeGreaterThanOrEqual(1);

    app(CartPage::class)->save(['layout' => 'squeeze']);
    SettingsService::forgetMemo();
    $line = ($c = fsbCart(15000))->items()->firstOrFail();
    $page = fsbShopper($c)->postJson('/api/cart/update', ['item_id' => $line->id, 'quantity' => 1, 'with_page' => 1])->json('page');
    expect(fsbCartBars($page))->toBe(1)->and($page)->toContain('away from');
});

/* ─────────────────────────── the drawer is not this ─────────────────────── */

it('leaves the cart panel bar exactly as it was, with the switch off and on', function () {
    // Mutation: gate partials/cart-drawer's `.kc-ship` on freeDeliveryBar()
    // and the off half is red.
    foreach ([false, true] as $on) {
        fsbOn($on);

        foreach ([40000 => '100', 15000 => '50'] as $price => $width) {
            $drawer = fsbShopper(fsbCart($price))->getJson('/api/cart/drawer')->assertOk()->json('html');
            preg_match('#<div class="kc-fill" style="width:([0-9.]+)%"#', (string) $drawer, $m);

            expect($drawer)->toContain('<div class="kc-ship">')
                ->and($m[1] ?? null)->toBe($width);
        }
    }
});

/* ───────────────────────────── the admin side ───────────────────────────── */

it('stops calling Store → Modules the switch, and says where the real one is', function () {
    // Defect: the row read "Appearance → Cart panel" for a bar the drawer
    // never consulted, while gating only the checkout. Mutation: set it back
    // to 'live' and ModuleFrameworkGuardTest needs a moduleEnabled() reader,
    // which this test forbids below.
    $row = ModuleRegistry::REGISTRY['freeship_bar'];

    expect($row[9])->toBe('elsewhere')
        ->and($row[4])->toBe('Appearance → Checkout page → Delivery labels')
        ->and($row[5])->toBe('checkoutpage')
        ->and($row[8])->toContain('cart page')
        ->and($row[8])->toContain('cart panel');

    $partial = file_get_contents(resource_path('views/partials/checkout/freeship-bar.blade.php'));
    expect($partial)->not->toContain("moduleEnabled('freeship_bar'")
        ->and($partial)->toContain('freeDeliveryBar()');

    // The inert On/Off on Store → Ecommerce → Cart (it saved a key nothing read).
    $ecom = file_get_contents(app_path('Http/Controllers/Admin/EcommerceApiController.php'));
    expect($ecom)->not->toContain("'freeship_bar'        => ['bool'");
});

it('has the cart page screen preview follow the switch, not draw a bar regardless', function () {
    // The only JS template string that draws this bar is the cart page
    // screen's preview; the shop's checkout.js only writes server HTML
    // (data.freeshipHtml) into the slot. Mutation: drop the `!fsBar` branch
    // and the preview advertises a bar the shop no longer draws.
    $screen = file_get_contents(resource_path('views/admin/partials/cart-page-screen.blade.php'));
    $gate = strpos($screen, '} else if (!fsBar) {');
    $bar = strpos($screen, 'You have unlocked free delivery!');
    expect($gate)->not->toBeFalse()->and($bar)->toBeGreaterThan($gate);

    $js = file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->not->toContain('freebar')->and($js)->toContain('el.innerHTML = data.freeshipHtml');

    if (! app('router')->getRoutes()->hasNamedRoute('admin.cart-page')) {
        Route::prefix('admin-api')->middleware(['web', 'auth:admin'])->group(base_path('routes/cart-page-admin.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'fsb-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => 'owner']);

    expect(test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-page')->assertOk()->json('fsBar'))->toBeFalse();
    fsbOn(true);
    expect(test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-page')->assertOk()->json('fsBar'))->toBeTrue();
});

