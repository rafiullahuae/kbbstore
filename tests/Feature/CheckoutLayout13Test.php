<?php

/**
 * 2.60.495 — the 13 October checkout and cart layout.
 *
 * The owner's brief (docs/CHECKOUT-BRIEF-2026-10-13.md), confirmed item by item
 * in a live preview of his own pages, and then: "do super extra care by not
 * disturbing any order process ... make sure each and every single element is
 * working fine and data is transfering to the server too." And: "map the old
 * stuff in case we messed something, so it can be recovered with one click."
 *
 * So these tests pin three things: the layout he chose is what ships; every
 * field the server reads is still on the page, with its name, in BOTH layouts;
 * and one press puts the page back exactly as it was.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\CheckoutLayout;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    if (! Route::has('checkout.couponUpdate')) {
        Route::middleware('web')->group(base_path('routes/checkout-line.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'title' => 'Free shipping', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 0]);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Delivery', 'cost' => 2000, 'enabled' => true, 'position' => 1]);

    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    app(SettingsService::class)->set('cartpage_layout', 'squeeze');
    SettingsService::forgetMemo();
});

function lo13Cart(int $priceFils = 25000): Cart
{
    $product = Product::create([
        'slug' => 'lo13-'.uniqid(), 'name' => 'Lo13 Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => $priceFils, 'stock_status' => 'instock',
    ]);
    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'status' => 'active', 'currency' => 'AED',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $priceFils]);

    return $cart;
}

function lo13Shopper(Cart $cart)
{
    test()->flushSession();
    app('auth')->forgetGuards();
    app(CartService::class)->forget();

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function lo13Page(string $path, ?Cart $cart = null): string
{
    SettingsService::forgetMemo();

    return (string) lo13Shopper($cart ?? lo13Cart())->get($path)->assertOk()->getContent();
}

/** Every field place() reads, by name: the "data is transferring" half of his ask. */
const LO13_POSTED = ['billing_email', 'billing_first_name', 'billing_phone', 'billing_address_1',
    'billing_address_2', 'billing_state', 'billing_country', 'create_account', 'account_password',
    'payment_method', 'shipping_method', 'coupon_code'];

/* ═══════════════════════ what ships: his choices ═══════════════════════ */

it('draws the checkout he chose: bag icon, no title, one Delivery Information step, email first', function () {
    /*
     * On the shop before this package: "🔒 Secure checkout" beside the logo, a
     * "Checkout / Almost glowing" title row, "1 Contact" holding the email and
     * "2 Shipping Details" holding the rest.
     * MUTATION: ship lo_one_step or lo_head_cart false and this is red.
     */
    $html = lo13Page('/checkout');

    expect($html)->toContain('class="co-cart"')
        ->and($html)->not->toContain('class="secure"')
        ->and($html)->not->toContain('class="co-titlebar"')
        ->and($html)->toContain(__('store.checkout.step_delivery_info'))
        ->and($html)->not->toContain('<span class="n">4</span>')
        ->and($html)->toContain('class="co-si-stack"');

    // Email is the first field of the first step, above Full name.
    expect(strpos($html, 'id="billing_email_field"'))->toBeLessThan(strpos($html, 'id="billing_first_name_field"'))
        // "Create an account" sits under Country, as asked.
        ->and(strpos($html, 'id="create_account_field"'))->toBeGreaterThan(strpos($html, 'id="billing_country_field"'))
        ->and($html)->toContain(CheckoutPage::LO_EMAIL_HINT);
});

it('keeps every field the order is posted with, by name, exactly once, in both layouts', function () {
    /*
     * The fields MOVE; nothing the server reads may be renamed, dropped or
     * doubled. A doubled id is how a coupon field stops working (checkout.js
     * finds it by id) and a dropped name is an order that cannot be placed.
     * MUTATION: rename any field in a moved partial, or render the coupon box
     * in both places, and this is red.
     */
    foreach (['new', 'old'] as $layout) {
        $layout === 'old' ? app(CheckoutLayout::class)->restore() : app(CheckoutLayout::class)->apply();
        $html = lo13Page('/checkout');

        foreach (LO13_POSTED as $name) {
            expect($html)->toContain('name="'.$name.'"');
        }
        foreach (['kbb_coupon_code', 'kbb_apply_coupon', 'billing_email', 'create_account', 'kbb_remember', 'kbbSummary'] as $id) {
            expect(substr_count($html, 'id="'.$id.'"'))->toBe(1, $layout.': #'.$id);
        }
        expect($html)->toContain('class="coupon"');
    }
});

it('keeps remembering details silently: the tick is in the page, ticked, and hidden', function () {
    /*
     * checkout.js rememberDetails(): "if (!tick) { forgetRemembered(); return; }"
     * -- removing the tick would ERASE every shopper's saved details rather
     * than keep them silently. MUTATION: drop the remember row instead of
     * hiding it and the first expectation is red.
     */
    $html = lo13Page('/checkout');

    expect($html)->toMatch('~<p class="form-row form-row-wide kbb-acct kbb-rmb" id="kbb_remember_field"\s*hidden\s*>~')
        ->and($html)->toMatch('~<input type="checkbox" id="kbb_remember" data-kbb-local checked>~');
});

it('moves the secure line to the Payment heading and words the pay button by method', function () {
    $html = lo13Page('/checkout');

    expect($html)->toContain('class="co-psec"')
        ->and($html)->toContain(e(CheckoutPage::LO_PAY_SECURE))
        ->and($html)->not->toContain(__('store.checkout.place_order').'</button>')
        // Cash on delivery is the only method here, so its words are printed.
        ->and($html)->toContain('data-place="1" autocomplete="off">'.CheckoutPage::LO_COMPLETE.'</button>')
        ->and($html)->toContain(CheckoutPage::LO_TRUST)
        ->and($html)->not->toContain(__('store.checkout.ssl_secure'));
});

it('shows U.A.E in the box and still posts AE', function () {
    $html = lo13Page('/checkout');

    expect($html)->toContain('<option value="AE" selected>U.A.E</option>')
        ->and($html)->toContain('class="row2 co-r2sc"');
});

it('hides the free-delivery line only while delivery is free', function () {
    /*
     * "if the user is getting free delivery, then this line should not show".
     * MUTATION: drop the shipping check in deliveryNoteHtml() and the first
     * expectation is red.
     */
    $page = app(CheckoutPage::class);

    expect($page->deliveryNoteHtml('AE', 19900, 0))->toBe('')
        ->and($page->deliveryNoteHtml('AE', 19900, 2000))->toContain('199')
        ->and($page->deliveryNoteHtml('AE', 19900))->toContain('199');

    $page->save(['lo_dl_note_free' => false]);
    SettingsService::forgetMemo();
    expect(app(CheckoutPage::class)->deliveryNoteHtml('AE', 19900, 0))->toContain('199');

    // On the page: a free basket draws the words, hidden; a paid one shows them.
    app(CheckoutPage::class)->save(['lo_dl_note_free' => true]);
    expect(lo13Page('/checkout', lo13Cart(25000)))->toMatch('~id="kbbDeliveryNote" hidden>~')
        ->and(lo13Page('/checkout', lo13Cart(9000)))->toMatch('~id="kbbDeliveryNote">~');
});

it('draws the applied coupon as a ticket whose x is the remove checkout.js answers', function () {
    $cart = lo13Cart(25000);
    $coupon = Coupon::create(['code' => 'GLOW', 'type' => 'percent', 'amount' => 1000]);
    $cart->update(['coupon_id' => $coupon->id]);

    $html = lo13Page('/checkout', $cart);

    expect($html)->toContain('class="co-tk co-tk-ticket"')
        ->and($html)->toMatch('~<button type="button" class="co-crm co-tk-x" data-kbb-coupon-remove~')
        ->and($html)->toContain(__('store.checkout.order_discount'))
        ->and($html)->toContain(__('store.checkout.total_savings'))
        // The old total, small, above the new one in the summary row.
        ->and($html)->toContain('<s class="co-was">');
});

/* ═══════════════════════════════ the cart ═══════════════════════════════ */

it('puts Order Value in the green box with the grey line, and the black SECURE CHECKOUT button', function () {
    $html = lo13Page('/cart');

    expect($html)->toContain('class="cpg-totband cpg-tb2"')
        ->and($html)->toContain('Shipping calculated at checkout')
        ->and($html)->toContain('cobtn cpg-cob2 cpg-cobcaps')
        ->and($html)->toContain('<span>Secure checkout</span>')
        // "turn off the Continue Shopping on the cart page"
        ->and($html)->not->toContain('class="backlink"');
});

it('shows a coupon on the cart once, as a ticket the cart can take off', function () {
    $cart = lo13Cart(25000);
    $coupon = Coupon::create(['code' => 'GLOW', 'type' => 'percent', 'amount' => 1000]);
    $cart->update(['coupon_id' => $coupon->id]);

    $html = lo13Page('/cart', $cart);

    expect($html)->toContain('data-kcpremovecoupon="GLOW"')
        ->and($html)->not->toContain('class="appliedcoupon"')
        ->and($html)->not->toContain('<div class="srow disc">');
});

it('sends Continue shopping only to an address on this shop', function () {
    /*
     * The setting becomes an href (rule 5): another site, a script or a
     * protocol-relative address is refused and the homepage used.
     * MUTATION: drop the shopPath rule and "//evil.example" is stored.
     */
    $cart = app(CartPage::class);

    foreach (['//evil.example', 'https://evil.example', 'javascript:alert(1)', '/a"b', '/a b', '\\evil'] as $bad) {
        $cart->save(['lo_cont_url' => $bad]);
        SettingsService::forgetMemo();
        expect(app(CartPage::class)->get('lo_cont_url'))->toBe('/', $bad);
    }

    $cart->save(['lo_cont' => true, 'lo_cont_url' => '/new-arrivals/']);
    expect(lo13Page('/cart'))->toContain('href="'.\App\Support\Url::to('/new-arrivals/').'">');
});

/* ═════════════════════════ one press back, and forward ═════════════════════════ */

it('puts the cart and checkout back exactly as before 2.60.495, and forward again', function () {
    /*
     * "map the old stuff in case we messed something, so it can be recovered
     * with one click from the backend."
     * MUTATION: drop any switch from CheckoutLayout::CHECKOUT and the old page
     * keeps a piece of the new one (red below).
     */
    // As on the live shop the day the package lands: the owner's own words,
    // and no snapshot yet (the test database's migrations took one already).
    \Illuminate\Support\Facades\DB::table('settings')->where('key', CheckoutLayout::SNAPSHOT)->delete();
    app(SettingsService::class)->set('cartpage_co_label', 'Go to checkout');
    SettingsService::forgetMemo();
    $layout = app(CheckoutLayout::class);
    $layout->snapshot();
    app(CartPage::class)->save(CheckoutLayout::CART_CHANGED);

    $layout->restore();
    SettingsService::forgetMemo();
    expect(app(CheckoutLayout::class)->state())->toBe('old');

    $html = lo13Page('/checkout');
    expect($html)->toContain('class="secure"')
        ->and($html)->toContain('class="co-titlebar"')
        ->and($html)->toContain(__('store.checkout.step_contact'))
        ->and($html)->toContain('<span class="n">4</span>')
        ->and($html)->toContain('United Arab Emirates</option>')
        ->and($html)->toContain(__('store.checkout.place_order').'</button>')
        ->and($html)->toContain(__('store.checkout.ssl_secure'))
        ->and($html)->not->toContain('co-tk')
        ->and($html)->toMatch('~id="kbb_remember_field">~');

    $cartHtml = lo13Page('/cart');
    expect($cartHtml)->toContain('>Go to checkout</a>')
        ->and($cartHtml)->not->toContain('class="cpg-totband cpg-tb2"')
        ->and($cartHtml)->toContain('class="backlink" href="'.\App\Support\Url::to('/shop/').'"');

    $layout->apply();
    SettingsService::forgetMemo();
    expect(app(CheckoutLayout::class)->state())->toBe('new')
        ->and(lo13Page('/checkout'))->toContain('class="co-cart"')
        ->and(lo13Page('/cart'))->toContain('<span>Secure checkout</span>');
});

it('keeps the owner\'s own button wording in the snapshot, even when run twice', function () {
    \Illuminate\Support\Facades\DB::table('settings')->where('key', CheckoutLayout::SNAPSHOT)->delete();
    app(SettingsService::class)->set('cartpage_co_label', 'My words');
    SettingsService::forgetMemo();
    $layout = app(CheckoutLayout::class);
    $layout->snapshot();
    app(CartPage::class)->save(CheckoutLayout::CART_CHANGED);
    $layout->snapshot(); // a package applied twice must not snapshot its own value

    $layout->restore();
    SettingsService::forgetMemo();
    expect(app(CartPage::class)->get('co_label'))->toBe('My words');
});

it('lets an owner or manager press the restore, nobody else, and only with an allowed word', function () {
    $mk = fn (string $role) => AdminUser::create(['name' => 'L13 '.$role, 'email' => 'l13-'.$role.'-'.Str::random(6).'@example.test', 'password' => bcrypt('x'), 'role' => $role]);

    test()->actingAs($mk('editor'), 'admin')->postJson('/admin-api/checkout-page/layout', ['action' => 'restore'])->assertForbidden();
    test()->actingAs($mk('owner'), 'admin')->postJson('/admin-api/checkout-page/layout', ['action' => 'drop tables'])->assertStatus(422);
    test()->actingAs($mk('owner'), 'admin')->postJson('/admin-api/checkout-page/layout', ['action' => 'restore'])->assertOk()->assertJson(['layout' => 'old']);
    test()->actingAs($mk('manager'), 'admin')->postJson('/admin-api/checkout-page/layout', ['action' => 'apply'])->assertOk()->assertJson(['layout' => 'new']);
});

/* ═══════════════════════════════ Apple Pay ═══════════════════════════════ */

it('never offers the Apple Pay row as a posted payment method', function () {
    /*
     * CheckoutController compares the page's `payment_method` radios with the
     * gateways STRICTLY. The Apple Pay row's radio has no name, so the list
     * the page reports is still exactly the list of gateways, and nothing it
     * could post is a method the server does not know. MUTATION: give the
     * radio name="payment_method" and the first expectation is red.
     */
    $html = view('partials.checkout.payment-methods', ['gateways' => [['id' => 'stripe', 'title' => 'Card', 'description' => null], ['id' => 'cod', 'title' => 'Cash', 'description' => null]], 'codHidden' => null])->render();
    $src = file_get_contents(resource_path('views/partials/checkout/payment-methods.blade.php'));

    expect($src)->toMatch('~<input id="payment_method_applepay" type="radio" class="input-radio" data-kbb-ap-pick autocomplete="off" />~')
        ->and(substr_count($html, 'name="payment_method"'))->toBe(2);
});
