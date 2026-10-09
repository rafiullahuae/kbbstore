<?php

declare(strict_types=1);

/*
 * Lane CO — the owner's 9 October checkout list, one block per request:
 *
 *  1. "remove the icon + text Have a discount code? from the coupon box"
 *  2. "it's not changing the spacing between the Delivery block and Payment
 *     block ... please keep increase that as like space between others and
 *     give proper controls for these"
 *  3. "in checkout footer, move the privacy etc row to the end after payments
 *     icon with a grey line seperator. and give 30px space inside the top
 *     pading of the checkout footer"
 *  4. "bring the apple google pay row to above, right after the coupon box
 *     nicely with Express checkout grey same heading ... and bottom OR"
 *  5. "a Sign in text on the right side of the Contact block title ... a nice
 *     on screen popup with the quick login form, without create account ...
 *     forgot password can be there ... upon login successfully, all the fields
 *     ... must be filled auto"
 *
 * The browser half (the window opening, switching, the tick, a real sign-in
 * that fills the form and then places an order with no 419) is
 * tools/co3-polish/walk.mjs, with its pictures in docs/lane-co-shots/.
 */

use App\Http\Controllers\Store\PasswordResetController;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Wallets;
use App\Services\SettingsService;
use App\Services\SlimFooter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\CheckoutSignInRoutes;

beforeEach(function () {
    CheckoutSignInRoutes::wire(app());

    if (! Route::has('checkout.couponUpdate')) {
        Route::middleware('web')->group(base_path('routes/checkout-line.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }

    PaymentProvider::query()->delete();
    $stripe = new PaymentProvider(['id' => 'stripe', 'title' => 'Credit or debit card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $stripe->config = [
        'publishable_key' => 'pk_test_co_key', 'secret_key' => 'sk_test_co_key',
        'webhook_signing_secret' => 'whsec_co', 'webhook_secret' => 'whsec-url-co-0123456789abcdef',
        'wallet_apple_pay' => '1', 'wallet_google_pay' => '1',
    ];
    $stripe->save();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 1]);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0]);

    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();
    csoForget();
});

function csoForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function csoCart(?int $customerId = null): Cart
{
    $product = Product::create(['slug' => 'cso-'.Str::random(8), 'name' => 'Glow Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 20000, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(), 'customer_id' => $customerId]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20000]);

    return $cart;
}

function csoShopper(Cart $cart)
{
    return test()->withCredentials()
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function csoPage(Cart $cart): string
{
    app(CartService::class)->forget();

    return (string) csoShopper($cart)->get('/checkout')->assertOk()->getContent();
}

function csoCustomer(bool $withAddress = true, ?string $phone = '+971501234567'): Customer
{
    $customer = Customer::create(['name' => 'Aisha Rahman', 'email' => 'aisha@example.com',
        'password' => Hash::make('glow-secret-1'), 'phone' => $phone]);

    if ($withAddress) {
        $customer->addresses()->create(['type' => 'shipping', 'is_default' => true, 'first_name' => 'Aisha', 'last_name' => 'Rahman',
            'line1' => 'Villa 12, Al Wasl Road', 'line2' => 'Jumeirah 1', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE']);
    }

    return $customer;
}

/** The framework skips the CSRF check under the test runner; this puts it back (as CheckoutCouponSmoothTest does). */
function csoEnforceCsrf(): void
{
    app()->instance(ValidateCsrfToken::class, new class(app(), app('encrypter')) extends ValidateCsrfToken {
        protected function runningUnitTests()
        {
            return false;
        }
    });
}

function csoCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
}

function csoBuiltCss(): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    return (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-checkout.css']['file']));
}

/* ═══════════════════════ 1. the coupon box ═══════════════════════ */

it('draws the coupon box without "Have a discount code?" and still applies a code', function () {
    /*
     * DEFECT this guards: a gift icon and "Have a discount code?" over the code
     * box, which the owner asked to be gone. The switch brings it back exactly.
     * MUTATION: ship `coupon_head` true, or drop the @if round the line -- red.
     */
    expect(CheckoutPage::SCHEMA['coupon_head'][2])->toBeFalse();

    $cart = csoCart();
    $html = csoPage($cart);
    preg_match('#<div class="coupon">.*?</div>\s*</div>#s', $html, $box);

    expect($box[0] ?? '')->not->toContain('class="ch"')
        ->and($box[0] ?? '')->not->toContain(__('store.checkout.coupon_prompt'))
        ->and($html)->toContain('id="kbb_coupon_code"')
        ->and($html)->toContain('id="kbb_apply_coupon"');

    // The coupon itself is untouched: the same endpoint applies it.
    Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);
    csoShopper($cart)->postJson('/checkout/coupon', ['code' => 'glow10', 'country' => 'AE'])
        ->assertOk()->assertJson(['ok' => true]);
    expect($cart->fresh()->coupon_id)->not->toBeNull();

    app(CheckoutPage::class)->save(['coupon_head' => true]);
    csoForget();
    expect(csoPage(csoCart()))->toContain('<div class="ch"><span class="gift">🎁</span> '.__('store.checkout.coupon_prompt').'</div>');
});

/* ═══════════════════════ 2. the gaps between sections ═══════════════════════ */

it('makes the gap under every numbered section follow "Space between blocks"', function () {
    /*
     * DEFECT this guards, on the owner's phone: he opened "Space between
     * blocks" and every gap round the coupon box grew, while "Remember my
     * details -> 3 Delivery" and "the delivery option -> 4 Payment" stayed
     * where they were -- they were the section's bottom PADDING, which no
     * block-gap slider reached. Measured in Chromium at 28: all three section
     * gaps 29px (the last field's 1px border), coupon -> form 28.
     *
     * MUTATION: delete the `.sec:has(+ .sec)` rule (or point --cop-secgap at
     * --cop-secpad) and the source and built assertions are red; and in the
     * browser the section gaps go back to 17 while the coupon gap is 28.
     */
    $rule = '.kbb-checkout .sec:has(+ .sec){padding-bottom:var(--cop-secgap)}';
    expect(csoCss())->toContain($rule)
        ->and(csoCss())->toContain('--cop-secgap:var(--cop-d-secgap,var(--cop-block));')
        ->and(csoCss())->toContain('--cop-secgap:var(--cop-m-secgap,var(--cop-block));')
        ->and(csoBuiltCss())->toContain('.kbb-checkout .sec:has(+.sec){padding-bottom:var(--cop-secgap)}');

    // ON ("follow") is the default, so the shipped page prints no new byte.
    expect(CheckoutPage::SCHEMA['d_sec_same'][2])->toBeTrue()
        ->and(CheckoutPage::SCHEMA['m_sec_same'][2])->toBeTrue()
        ->and(app(CheckoutPage::class)->cssVariables())->not->toContain('secgap');

    // Moving the block gap moves the sections with it: nothing of their own is printed.
    app(CheckoutPage::class)->save(['m_block_gap' => 28]);
    csoForget();
    expect(app(CheckoutPage::class)->cssVariables())->toContain('--cop-m-block:28px')
        ->and(app(CheckoutPage::class)->cssVariables())->not->toContain('secgap');

    // Off: the slider's own number, printed even at 16, because then it is a choice.
    app(CheckoutPage::class)->save(['m_sec_same' => false, 'd_sec_same' => false, 'd_sec_gap' => 24]);
    csoForget();
    expect(app(CheckoutPage::class)->cssVariables())->toContain('--cop-d-secgap:24px')
        ->and(app(CheckoutPage::class)->cssVariables())->toContain('--cop-m-secgap:16px');
});

it('clamps the section-gap slider to its own range', function () {
    // A select or range stores one of its own values: 999 is not a gap.
    app(CheckoutPage::class)->save(['d_sec_same' => false, 'd_sec_gap' => 999, 'm_sec_gap' => -5]);
    csoForget();

    expect(app(CheckoutPage::class)->get('d_sec_gap'))->toBe(48)
        ->and(app(CheckoutPage::class)->get('m_sec_gap'))->toBe(6);
});

/* ═══════════════════════ 3. the checkout footer ═══════════════════════ */

it('puts the policy links last, after the payment marks, under a grey line, with 30px above the logo', function () {
    /*
     * DEFECT this guards: Shipping & Delivery / Privacy policy sat under the
     * wordmark, at the top of the bar, and the logo had 10-12px over it.
     * MUTATION: put the default back to 'brand' (or drop the migration's
     * pad_top rows) -- red on the order or on the 30.
     */
    expect(SlimFooter::SCHEMA['links_pos'][2])->toBe('end');

    // The package's migration stored the 30s; a fresh database has run it.
    $sf = app(SlimFooter::class)->all();
    expect($sf['pad_top'])->toBe(30)->and($sf['m_pad_top'])->toBe(30)->and($sf['links_pos'])->toBe('end');

    app(SlimFooter::class)->save(['pay_on' => true]);
    csoForget();
    $html = csoPage(csoCart());
    preg_match('#<footer class="kbb-slimfoot[^"]*"([^>]*)>(.*?)</footer>#s', $html, $f);

    expect($f[1] ?? '')->toContain('--sf-padt:30px')->and($f[1] ?? '')->toContain('--sf-m-padt:30px');
    $pay = strpos($f[2], '<div class="sf-pay"');
    $links = strpos($f[2], '<div class="sf-links sf-links-end">');
    expect($pay)->toBeInt()->and($links)->toBeInt()->and($links)->toBeGreaterThan($pay)
        ->and(substr_count($f[2], 'href="/privacy-policy/"'))->toBe(1)
        // Not under the wordmark as well.
        ->and(preg_match('#<div class="sf-brand">(.*?)</div>#s', $f[2], $brand))->toBe(1)
        ->and($brand[1])->not->toContain('sf-links');

    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));
    expect($partial)->toContain('.kbb-slimfoot .sf-links-end{width:100%;--sf-line:rgba(120,116,118,.28);');
});

/* ═══════════════════════ 4. Express checkout under the coupon ═══════════════════════ */

it('draws the wallet row right after the coupon box, its heading and OR hidden until a wallet is ready', function () {
    /*
     * DEFECT this guards: Apple Pay / Google Pay sat under "4 Payment", below
     * three sections of form. Now under the coupon box, with a grey "Express
     * checkout" heading and an OR line -- and all three hidden in the markup,
     * because only Stripe's `ready` can say this browser has a wallet. A
     * shopper without one must see no heading, no OR and no gap.
     * MUTATION: drop `hidden` from the heading, or move the @include back
     * under 4 Payment -- red.
     */
    $html = csoPage(csoCart());

    $coupon = strpos($html, '<div class="coupon">');
    $head = strpos($html, '<p class="co-xc-h" data-kbb-express-head hidden>Express checkout</p>');
    $row = strpos($html, 'data-kbb-express data-kbb-express-pending aria-hidden="true"');
    $or = strpos($html, '<div class="ordiv co-xc-or" data-kbb-express-divider hidden>OR</div>');
    $form = strpos($html, '<div class="formbox" id="customer_details">');

    expect($coupon)->toBeInt()->and($head)->toBeInt()->and($row)->toBeInt()->and($or)->toBeInt()
        ->and($coupon < $head && $head < $row && $row < $or && $or < $form)->toBeTrue()
        ->and(substr_count($html, 'data-kbb-express-head'))->toBe(2)   // the node and the script's selector
        ->and($html)->not->toContain(__('store.checkout.or_pay_with'));

    // Still inside the checkout form, and the script still finds it by id: the
    // same fields, amount and handlers as before the move.
    expect(strpos($html, 'id="kbbCheckoutForm"'))->toBeLessThan($head)
        ->and($html)->toContain("var FORM = document.getElementById('kbbCheckoutForm');")
        ->and($html)->toContain("if (HEAD && HEAD.parentNode) HEAD.parentNode.removeChild(HEAD);")
        ->and($html)->toContain('if (HEAD) HEAD.hidden = false;')
        ->and($html)->toMatch('/maxRows:\s*0\b/')
        ->and($html)->toContain('<div class="express" style="display:block;visibility:hidden;height:0;overflow:hidden;margin:0"');

    // Nothing wallet-shaped left in the Payment section.
    preg_match('#<div class="sec pay">(.*?)<div id="payment"#s', $html, $pay);
    expect($pay[1] ?? '')->not->toContain('data-kbb-express');
});

it('takes the heading from Appearance and escapes it, in English and Arabic', function () {
    // A setting never reaches the page unescaped.
    app(CheckoutPage::class)->save(['xc_head' => 'Pay <b>fast</b>']);
    csoForget();

    expect(csoPage(csoCart()))->toContain('data-kbb-express-head hidden>Pay &lt;b&gt;fast&lt;/b&gt;</p>')
        ->and(CheckoutPage::SCHEMA['xc_head_ar'][2])->toBe('الدفع السريع');
});

/* ═══════════════════════ 5. Sign in ═══════════════════════ */

it('offers "Sign in" and its window to a guest, and to nobody signed in', function () {
    /*
     * DEFECT this guards: a signed-in customer offered a sign-in, or a window
     * nested inside the checkout form (a form in a form is not a form, and its
     * submit would post the checkout). MUTATION: drop `! auth('customer')->
     * check()` from either @if -- red on the signed-in half.
     */
    $html = csoPage(csoCart());
    preg_match('#<h2><span class="n">1</span>.*?</h2>#s', $html, $h2);

    expect($h2[0] ?? '')->toContain('<a class="co-signin" href="/my-account/" data-kbb-signin>Sign in</a>')
        ->and(substr_count($html, '<dialog class="co-si" id="kbbSignIn"'))->toBe(1)
        ->and(strpos($html, '<dialog class="co-si"'))->toBeGreaterThan(strpos($html, '</form>'));

    preg_match('#<dialog class="co-si".*?</dialog>#s', $html, $d);
    expect($d[0])->not->toMatch('/create|register|sign up/i')
        ->and($d[0])->toContain('autocomplete="current-password"')
        ->and($d[0])->toContain('data-si-to="forgot"');

    $customer = csoCustomer();
    $signedIn = (string) csoShopper(csoCart($customer->id))->actingAs($customer, 'customer')->get('/checkout')->assertOk()->getContent();
    expect($signedIn)->not->toContain('data-kbb-signin')
        ->and($signedIn)->not->toContain('id="kbbSignIn"');

    // And the switch takes both away for a guest.
    app(CheckoutPage::class)->save(['signin_on' => false]);
    csoForget();
    $off = csoPage(csoCart());
    expect($off)->not->toContain('data-kbb-signin')->and($off)->not->toContain('id="kbbSignIn"');
});

it('signs in, keeps the basket, rotates the token and answers with an allowlist', function () {
    /*
     * DEFECTS this guards: (a) a JSON answer built from a model, which would
     * hand the page password hashes and Stripe ids; (b) the old CSRF token
     * left on the page -- regenerate() retires it, and Place order would 419;
     * (c) the basket lost or swapped at sign-in.
     * MUTATION: return `$customer` in the payload -- red on the key lists.
     * Skip regenerate() in attempt() -- red on the token.
     */
    $customer = csoCustomer();
    $cart = csoCart();
    csoPage($cart);
    $before = session()->token();

    $r = csoShopper($cart)->postJson('/checkout/sign-in', ['email' => 'aisha@example.com', 'password' => 'glow-secret-1', 'country' => 'AE']);

    $r->assertOk();
    expect(array_keys($r->json()))->toEqualCanonicalizing(['ok', 'csrf', 'fields', 'reload'])
        ->and($r->json('ok'))->toBeTrue()
        ->and($r->json('reload'))->toBeFalse()
        ->and($r->json('csrf'))->toBe(session()->token())
        ->and($r->json('csrf'))->not->toBe($before)
        ->and(auth('customer')->id())->toBe($customer->id);

    $fields = $r->json('fields');
    expect(array_diff(array_keys($fields), ['billing_email', 'billing_phone', 'billing_first_name', 'billing_last_name',
        'billing_country', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state']))->toBe([])
        ->and($fields['billing_email'])->toBe('aisha@example.com')
        ->and($fields['billing_first_name'])->toBe('Aisha Rahman')
        ->and($fields['billing_phone'])->toBe('+971501234567')
        ->and($fields['billing_country'])->toBe('AE')
        ->and($fields['billing_state'])->toBe('Dubai')
        ->and(json_encode($r->json()))->not->toContain('$2y$')
        ->and(json_encode($r->json()))->not->toContain('password');

    // The basket is the same basket, now the customer's.
    $kept = $cart->fresh();
    expect($kept->customer_id)->toBe($customer->id)
        ->and($kept->status)->toBe('active')
        ->and($kept->items()->sum('quantity'))->toBe(2);
});

it('leaves out what the account does not hold, so no box is blanked', function () {
    // A customer with no phone and no address: no key for either, never "".
    csoCustomer(withAddress: false, phone: null);

    $fields = csoShopper(csoCart())->postJson('/checkout/sign-in', ['email' => 'aisha@example.com', 'password' => 'glow-secret-1'])
        ->assertOk()->json('fields');

    expect($fields)->toBe(['billing_email' => 'aisha@example.com', 'billing_first_name' => 'Aisha Rahman'])
        ->and(in_array('', $fields, true))->toBeFalse();
});

it('asks for a reload when signing in merges an older account basket into this one', function () {
    /*
     * The totals on screen were drawn for the guest basket; after a merge the
     * order is bigger. Filling the boxes in place would leave a summary that
     * is not the order. MUTATION: answer reload:false always -- red.
     */
    $customer = csoCustomer();
    csoCart($customer->id);          // waiting in the account from a past visit
    $guest = csoCart();

    $r = csoShopper($guest)->postJson('/checkout/sign-in', ['email' => 'aisha@example.com', 'password' => 'glow-secret-1']);

    $r->assertOk();
    expect($r->json('reload'))->toBeTrue();
});

it('refuses a wrong password with the account page\'s one sentence, and signs nobody in', function () {
    csoCustomer();

    $wrong = csoShopper(csoCart())->postJson('/checkout/sign-in', ['email' => 'aisha@example.com', 'password' => 'nope']);
    $nobody = csoShopper(csoCart())->postJson('/checkout/sign-in', ['email' => 'nobody@example.com', 'password' => 'nope']);

    $wrong->assertStatus(422);
    $nobody->assertStatus(422);
    expect($wrong->json('errors.email.0'))->toBe('Those details did not match our records.')
        ->and($nobody->json('errors.email.0'))->toBe($wrong->json('errors.email.0'))
        ->and(auth('customer')->check())->toBeFalse();
});

it('shares the account page\'s lockout: five misses anywhere, then neither door opens', function () {
    /*
     * DEFECT this guards: a second, weaker sign-in path. The window must count
     * against the same limiter as /my-account/login, or it is a way round it.
     * MUTATION: give the controller its own RateLimiter key -- red: the sixth
     * try through the window would be judged on the password.
     */
    csoCustomer();

    for ($i = 0; $i < 5; $i++) {
        test()->post('/my-account/login', ['email' => 'aisha@example.com', 'password' => 'wrong-'.$i]);
    }

    $r = csoShopper(csoCart())->postJson('/checkout/sign-in', ['email' => 'aisha@example.com', 'password' => 'glow-secret-1']);

    $r->assertStatus(422);
    expect($r->json('errors.email.0'))->toStartWith('Too many attempts.')
        ->and(auth('customer')->check())->toBeFalse();
});

it('is behind CSRF, and the token it hands back is the one Place order needs', function () {
    /*
     * DEFECT this guards: the page keeping the token regenerate() retired.
     * Under real CSRF: the window's POST without a token is a 419; with the
     * page's token it signs in; after that the OLD token is refused by
     * /checkout/place and the NEW one gets past CSRF.
     * MUTATION: move the routes to api.php -- red on the first 419; return
     * the pre-login token -- red on the last line.
     */
    csoCustomer();
    $cart = csoCart();
    csoPage($cart);
    $old = session()->token();
    csoEnforceCsrf();

    csoShopper($cart)->postJson('/checkout/sign-in', ['email' => 'aisha@example.com', 'password' => 'glow-secret-1'])->assertStatus(419);

    $new = csoShopper($cart)->withHeader('X-CSRF-TOKEN', $old)
        ->postJson('/checkout/sign-in', ['email' => 'aisha@example.com', 'password' => 'glow-secret-1'])
        ->assertOk()->json('csrf');

    csoShopper($cart)->withHeader('X-CSRF-TOKEN', $old)->postJson('/checkout/place', ['payment_method' => 'cod'])->assertStatus(419);
    expect(csoShopper($cart)->withHeader('X-CSRF-TOKEN', $new)->postJson('/checkout/place', ['payment_method' => 'cod'])->getStatusCode())->not->toBe(419);

    app()->forgetInstance(ValidateCsrfToken::class);
});

it('is rate limited at the route as well: twenty a minute, then 429', function () {
    // MUTATION: drop ->middleware('throttle:20,1') -- the 21st is a 422 and this is red.
    $codes = [];
    for ($i = 0; $i < 21; $i++) {
        $codes[] = csoShopper(csoCart())->postJson('/checkout/sign-in', ['email' => 'x'.$i.'@example.com', 'password' => 'p'])->getStatusCode();
    }

    expect($codes[20])->toBe(429);
});

it('answers "forgot" the same way for an address with an account and one without', function () {
    /*
     * DEFECT this guards: an account oracle -- a window that said "sent" for a
     * customer and "no such account" for a stranger lets anybody test a list
     * of addresses. Same status, same body, both times.
     * MUTATION: return the broker's $status -- red.
     */
    csoCustomer();

    $known = csoShopper(csoCart())->postJson('/checkout/sign-in/forgot', ['email' => 'aisha@example.com']);
    $unknown = csoShopper(csoCart())->postJson('/checkout/sign-in/forgot', ['email' => 'stranger@example.com']);

    $known->assertOk();
    $unknown->assertOk();
    expect($known->json())->toBe($unknown->json())
        ->and($known->json())->toBe(['ok' => true, 'message' => PasswordResetController::SENT_MESSAGE]);

    // The reset itself is the account page's: a token row for the real one only.
    expect(\Illuminate\Support\Facades\DB::table(config('auth.passwords.customers.table', 'customer_password_reset_tokens'))->count())->toBe(1);
});

it('keeps the window\'s script inside the rules: no measuring, every token copy swapped, no blank written', function () {
    $partial = (string) file_get_contents(resource_path('views/partials/checkout/sign-in.blade.php'));

    expect($partial)->not->toMatch('/getBoundingClientRect|offsetHeight|offsetWidth|getComputedStyle|scrollHeight|clientHeight/')
        ->and($partial)->toContain("document.querySelectorAll('input[name=\"_token\"]').forEach(function (input) { input.value = token; });")
        ->and($partial)->toContain('window.KBB.csrf = token;')
        ->and($partial)->toContain("value === ''")
        ->and($partial)->toContain('typeof D.showModal !== \'function\'')
        ->and($partial)->not->toContain('setInterval');
});
