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

/* ═══════════════ an order, end to end, in the 13 October layout ═══════════════ */

it('takes a coupon from the new field and places a Cash on delivery order with every detail on it', function () {
    /*
     * The owner: "make sure each and every single element is working fine and
     * data is transferring to the server too". The page is read in the new
     * layout, the coupon goes through the endpoint the moved field calls, and
     * the order is placed with the values the page itself carries (the chosen
     * delivery, the country's posted code) -- then read back off the order.
     *
     * MUTATION: rename billing_state in address-fields-list. RED (no emirate on
     * the order). Drop the coupon-box partial's id. RED at the first expect.
     */
    $cart = lo13Cart(25000);
    Coupon::create(['code' => 'GLOW', 'type' => 'percent', 'amount' => 1000]);

    $html = lo13Page('/checkout', $cart);
    expect($html)->toContain('id="kbb_coupon_code"')
        ->and(preg_match('~name="shipping_method"[^>]*value="([^"]+)"[^>]*checked|value="([^"]+)"[^>]*name="shipping_method"[^>]*checked~', $html, $ship))->toBe(1);
    $shipping = $ship[1] !== '' ? $ship[1] : $ship[2];

    $r = lo13Shopper($cart)->postJson('/checkout/coupon', ['code' => 'glow', 'country' => 'AE', 'payment_method' => 'cod']);
    $r->assertOk();
    expect((string) $r->json('couponHtml'))->toContain('GLOW')->toContain('co-tk');

    $before = App\Models\Order::count();

    lo13Shopper($cart->fresh())->post('/checkout/place', [
        'billing_email' => 'lo13@example.com',
        'billing_first_name' => 'Mariam Saeed',
        'billing_phone' => '+971500000000',
        'billing_address_1' => 'Villa 7',
        'billing_address_2' => 'Al Barsha 1',
        'billing_city' => 'Al Barsha',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'shipping_method' => $shipping,
        'payment_method' => 'cod',
        'coupon_code' => 'GLOW',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(App\Models\Order::count())->toBe($before + 1);
    $order = App\Models\Order::latest('id')->first();

    expect($order->payment_method)->toBe('cod')
        ->and((int) $order->discount_total)->toBe(2500)
        ->and((int) $order->total)->toBe(22500)
        ->and($order->email)->toBe('lo13@example.com')
        ->and($order->phone)->toBe('+971500000000')
        ->and($order->billing_address)->toMatchArray(['first_name' => 'Mariam', 'line1' => 'Villa 7', 'state' => 'Dubai', 'country' => 'AE']);
});

it('keeps the Express checkout row exactly as it was and adds Apple Pay to the list as its own button', function () {
    /*
     * The owner, 14 October, on the live shop after 2.60.495: "The Express
     * Checkout section is completely gone ... i have told you that do not touch
     * that", then: "express checkout will also show, and also the apple pay
     * only in the list too". 2.60.495 had MOVED the one Element into the list.
     *
     * Now: the Express row with its heading, OR line, Apple Pay AND Google Pay,
     * drawn and mounted as before; and a second copy of the script, Apple Pay
     * only, for the list row. MUTATION: put `$kbbGooglePay = false` back for
     * the row, or drop the second copy from $kbbEceModes. RED.
     */
    PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit or debit card', 'enabled' => true, 'mode' => 'test', 'position' => 0,
        'config' => ['publishable_key' => 'pk_test_lo13', 'secret_key' => 'sk_test_lo13', 'webhook_signing_secret' => 'whsec_lo13',
            'webhook_secret' => 'whsec-url-lo13-0123456789ab', 'wallet_apple_pay' => '1', 'wallet_google_pay' => '1']]);
    PaymentProvider::whereKey('cod')->update(['position' => 1]); // card first, as on the shop
    app(App\Services\Payments\GatewayCredentials::class)->forget();
    app(App\Services\Payments\Wallets::class)->forget();

    $src = (string) file_get_contents(resource_path('views/partials/checkout/express-wallets.blade.php'));
    $html = lo13Page('/checkout?kbbdiag=1');

    // Rendered: the list row, card first, Apple Pay second, never posted.
    $card = strpos($html, 'id="payment_method_stripe"');
    $apple = strpos($html, 'id="payment_method_applepay"');
    $cod = strpos($html, 'id="payment_method_cod"');
    expect($card)->toBeInt()->and($apple)->toBeGreaterThan($card)->and($cod)->toBeGreaterThan($apple)
        ->and($html)->toMatch('~<li class="wc_payment_method payment_method_applepay[^"]*" data-kbb-ap-li hidden>~')
        ->and($html)->toContain('<div class="co-apslot" data-kbb-ap-slot><div data-kbb-ap-mount></div>');

    // The Express row, as before 2.60.495 -- and both wallets offered there.
    if (str_contains($html, 'data-kbb-express ')) {
        expect($html)->toContain('<div class="express" style="display:block;visibility:hidden;height:0;overflow:hidden;margin:0" data-kbb-express data-kbb-express-pending aria-hidden="true">')
            ->and($html)->toContain('data-kbb-express-head hidden>')
            ->and($html)->toContain('data-kbb-express-divider hidden>')
            ->and($html)->toContain('"google_pay_offered":true')
            ->and(substr_count($html, 'var APAY        = '))->toBe(2)
            ->and($html)->toContain('var GOOGLE      = true')
            ->and($html)->toContain('var GOOGLE      = false');
    }

    // The template itself, whatever this test database draws: the Express row
    // is no longer inside any Apple-row condition, Google Pay is never
    // switched off for it, and the list row is a separate copy.
    expect($src)->not->toContain('@if (! $kbbApRow)')
        ->and($src)->not->toContain('$kbbGooglePay = false')
        ->and($src)->toContain("\$kbbEceModes = \$kbbApRow ? [false, true] : [false];")
        ->and($src)->toContain("var ROW = document.querySelector('[data-kbb-ap-row]');")
        ->and($src)->toContain('var GOOGLE      = @json($kbbGooglePay && ! $kbbEceApay);');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
    expect($css)->toContain('.kbb-checkout .co-apslot{visibility:hidden;height:0;overflow:hidden}')
        ->and($css)->not->toMatch('~\.kbb-checkout \.co-apslot\{[^}]*display:none~');
});

/* ═══════════════════ 14 October: the owner's three follow-ups ═══════════════════ */

it('lists the emirates in English only, still storing the English name, and keeps both languages on request', function () {
    /*
     * The owner, 14 October: "the countries list i would like in english only
     * for now". What the order stores (the option's value) is the English name
     * in both modes. MUTATION: drop the stateNamesEnglish() argument in
     * address-fields-list. RED on the first expectation.
     */
    $html = lo13Page('/checkout');

    expect($html)->toMatch('~<option value="Dubai"\s*>Dubai</option>~')
        ->and($html)->toMatch('~<option value="Ras Al Khaimah"\s*>Ras al Khaimah</option>~')
        ->and($html)->not->toContain("\u{2068}دبي\u{2069} — Dubai")
        // After a country change the shared switch draws "Arabic — English";
        // the follow-up keeps the English alone.
        ->and($html)->toContain('"Emirate list language" -> English only');

    app(CheckoutPage::class)->save(['lo_state_lang' => 'both']);

    $both = lo13Page('/checkout');
    expect($both)->toContain("\u{2068}دبي\u{2069} — Dubai</option>")
        ->and($both)->not->toContain('"Emirate list language" -> English only');
});

it('drops the Detected badge beside Country but still detects the country', function () {
    /*
     * The owner, 14 October: "remove the detected badge for now. as the
     * countrie is auto detecting the location, that's enough". MUTATION:
     * remove the lo('detected') condition. RED on the first expectation.
     */
    $page = fn () => (string) lo13Shopper(lo13Cart())->withHeader('CF-IPCountry', 'AE')->get('/checkout')->assertOk()->getContent();

    $html = $page();
    expect($html)->not->toContain('class="xd-detected"')
        ->and($html)->toMatch('~<option value="AE"\s+selected~');

    app(CheckoutPage::class)->save(['lo_detected' => true]);
    expect($page())->toContain('class="xd-detected"');
});

it('lines the open summary up with the form, and the Apple Pay mark with the other logos', function () {
    // MUTATION: delete either rule. RED. (Measured in Chromium at 390: items,
    // coupon and totals at 37-353px, the same as the fields and payment boxes.)
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    expect($css)->toContain('.kbb-checkout.cop-sumg .summary > .panels{padding-inline:calc(var(--cop-secpad) + 1px)}')
        ->and($css)->toMatch('~\.kbb-checkout \.pay-logo\.pay-logo-applepay\{[^}]*inline-size:auto~');
});

it('opens the phone summary almost white with rounded bottom corners, and greens the foot summary heading', function () {
    /*
     * The owner, 14 October: "the content inside the top summary box ... more
     * light towards almost white, but from downside ... the beautiful radius
     * from left bottom and right bottom corners", and the foot summary's
     * "heading row background only to the same little darker greenish ... and
     * give little space below payments block". Measured in Chromium at 390:
     * opened #FBFDFC with 18px corners, title row #DCEFE3; closed unchanged.
     * MUTATION: default lo_sum_open to 'classic'. RED on the class.
     */
    expect(app(CheckoutPage::class)->bodyClass())->toContain(' cop-sow');

    app(CheckoutPage::class)->save(['lo_sum_open' => 'green']);
    expect(app(CheckoutPage::class)->bodyClass())->toContain(' cop-sog')->not->toContain('cop-sow');

    app(CheckoutPage::class)->save(['lo_sum_open' => 'classic']);
    expect(app(CheckoutPage::class)->bodyClass())->not->toContain('cop-sow')->not->toContain('cop-sog');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
    expect($css)->toContain('.kbb-checkout.cop-sumg.cop-sow .summary.cosr-open{background:#FBFDFC}')
        ->and($css)->toContain('border-end-start-radius:18px;border-end-end-radius:18px')
        ->and($css)->toContain('.kbb-checkout.cop-sow .co-osh,.kbb-checkout.cop-sog .co-osh{background:#DCEFE3;');
});

it('draws the owner\'s own Apple Pay logo in the payment list, inline', function () {
    /*
     * The owner: "the y is still incorrect, can u place just the logo image
     * and make border" -- his own image of Apple's mark, where "a" and "y"
     * share one top line. Inline (no request), sized, never from a setting.
     * MUTATION: point 'applepay' back at the drawn SVG. RED.
     */
    $logo = App\Support\PaymentMarkArt::checkoutLogo('applepay');

    expect($logo)->toStartWith('<img src="data:image/png;base64,')
        ->and($logo)->toContain('width="34" height="14"')
        ->and($logo)->toContain('alt=""')
        ->and(strlen($logo))->toBeLessThan(4000);
});
