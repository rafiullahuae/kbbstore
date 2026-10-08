<?php

/**
 * The checkout's coupon box, as the owner asked for it (Lane CP):
 *
 *   "if user input all the details, and later user use the coupon field, it
 *    should not delete all the data from input fields. neither refresh the
 *    page. coupon must be applied smoothly if valid, if not it will give a nice
 *    warning type text that coupon is not valid etc."
 *
 * What the page did before this lane, measured in Chromium at 390 and 1280 with
 * every field filled (tools/cp-shots/walk.mjs, docs/lane-cp-shots/):
 *
 *   - every answer, a refusal included, came back with ALL of the page's
 *     fragments and the page swapped them all in: #payment was rebuilt on every
 *     try, so the card element's three mount boxes were replaced (3 of 3) and
 *     Stripe re-mounted into new ones, and the "save this card" tick reset;
 *   - the only message was a toast, gone after 1.7 seconds, and in English on
 *     /ar ("That code has expired." on an Arabic checkout);
 *   - "Coupon applied" said nothing about what it saved, and there was no way
 *     to take a code off again from the checkout at all;
 *   - with the route unpublished, the script's fallback posted to the cart's
 *     endpoint and RELOADED the page, wiping every field (CP_DROP_ROUTE=1 in
 *     the walk reproduces it). That fallback is gone.
 *
 * These pin the server's half. The page's half -- no reload, every field kept,
 * #payment left alone, Enter/Go never placing the order -- is walked in
 * Chromium by tools/cp-shots/walk.mjs, which prints PASS/FAIL per step.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\Translation\InterfaceStrings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ArabicShop;

beforeEach(function () {
    if (! Route::has('checkout.couponUpdate')) {
        Route::middleware('web')->group(base_path('routes/checkout-line.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
});

function cpCart(int $priceFils = 20000, int $qty = 2): Cart
{
    $product = Product::create([
        'slug' => 'cp-serum-'.uniqid(), 'name' => 'CP Serum', 'status' => 'publish', 'is_visible' => true,
        'price' => $priceFils, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $priceFils]);

    return $cart;
}

function cpShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/* ═══════════════════════ applied, in place ═══════════════════════ */

it('applies a valid code and answers with the totals and the line to show -- and nothing else', function () {
    /*
     * THE ALLOWLIST. A coupon moves the order block (discount row, delivery,
     * total), the mobile bag strip's total and the sticky bar's total. It does
     * not move the lines or the Browsed list, and the payment options only
     * when the new total changes which are offered -- so with the page saying
     * it shows ["cod"] and the answer still offering exactly that, #payment
     * stays out of the answer and the card element in it stays mounted.
     *
     * MUTATION: drop `$samePayment ? [] :` in fragments() and paymentHtml comes
     * back -- red on the key list. Before this lane the key list was
     * count/itemsHtml/orderHtml/thumbsHtml/paymentHtml/browsedHtml/browsedCount/
     * total/payNotice/message: red.
     */
    Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);
    $cart = cpCart(20000, 2);

    $r = cpShopper($cart)->postJson('/checkout/coupon', ['code' => 'glow10', 'country' => 'AE', 'payment_method' => 'cod', 'offered' => ['cod']]);

    $r->assertOk()->assertJson(['ok' => true]);
    expect(array_keys($r->json()))->toEqualCanonicalizing(['ok', 'count', 'orderHtml', 'thumbsHtml', 'total', 'payNotice', 'couponHtml'])
        ->and($cart->fresh()->coupon_id)->not->toBeNull()
        ->and($r->json('orderHtml'))->toContain('370')   // 400 - 50 + 20 delivery
        // The line says what it saved, with the coupon's own code, and offers
        // the way back. MUTATION: return the old "Coupon applied" -- red.
        ->and($r->json('couponHtml'))->toContain("Coupon GLOW10 applied — you save AED\u{00A0}50")
        ->and($r->json('couponHtml'))->toContain('data-kbb-coupon-remove');
});

it('still redraws the payment options when the page does not say which it shows', function () {
    /*
     * An older script sends no `offered`, and an answer without #payment would
     * leave it showing a Cash-on-delivery choice the new total may have
     * withdrawn. So: no list, the whole answer, as before.
     *
     * MUTATION: make $samePayment true when $shown is empty and this is red.
     */
    Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);

    $r = cpShopper(cpCart())->postJson('/checkout/coupon', ['code' => 'GLOW10', 'country' => 'AE']);

    expect($r->json('paymentHtml'))->toBeString()->toContain('payment_method_cod');
});

it('redraws the payment options when the coupon changes which are offered', function () {
    /*
     * The case #payment has to move for: Cash on delivery is only offered from
     * AED 380 here (PayShipRules' window), the order is AED 420, and a AED 100
     * coupon takes it under. The page said it shows ["cod"], which the new
     * total no longer offers, so the options come back -- with the sentence
     * saying the method had to change.
     *
     * MUTATION: always omit paymentHtml in coupon mode -- red.
     */
    $settings = app(\App\Services\SettingsService::class);
    $settings->setModule('pay_ship_rules', true);
    $settings->setModuleSetting('pay_ship_rules', 'cod_min', 38000);
    Coupon::create(['code' => 'BIG', 'type' => 'fixed_cart', 'amount' => 10000]);

    $r = cpShopper(cpCart(20000, 2))->postJson('/checkout/coupon', ['code' => 'BIG', 'country' => 'AE', 'payment_method' => 'cod', 'offered' => ['cod']]);

    $r->assertOk()->assertJson(['ok' => true]);
    expect($r->json('paymentHtml'))->toBeString()->not->toContain('payment_method_cod')
        ->and($r->json('payNotice'))->toBeString();
});

/* ═══════════════════════ refused, in words ═══════════════════════ */

it('refuses a code with CouponService\'s own reason and redraws nothing', function (string $code, string $sentence) {
    /*
     * A refusal used to come back with every fragment, from totals that had not
     * moved, and the page swapped #payment for an identical copy -- tearing the
     * card fields down. Now the answer is the sentence and nothing else.
     *
     * MUTATION: put `+ $this->fragments(...)` back on the refusal and the key
     * list is red.
     */
    Coupon::create(['code' => 'OLD20', 'type' => 'percent', 'amount' => 2000, 'expires_at' => now()->subDay()]);
    Coupon::create(['code' => 'SOON', 'type' => 'percent', 'amount' => 2000, 'starts_at' => now()->addDay()]);
    Coupon::create(['code' => 'BIG900', 'type' => 'fixed_cart', 'amount' => 5000, 'minimum_amount' => 90000]);
    Coupon::create(['code' => 'GONE', 'type' => 'fixed_cart', 'amount' => 5000, 'usage_limit' => 3, 'usage_count' => 3]);
    $cart = cpCart();

    $r = cpShopper($cart)->postJson('/checkout/coupon', ['code' => $code, 'country' => 'AE']);

    $r->assertOk();
    expect($r->json())->toBe(['ok' => false, 'error' => $sentence])
        ->and($cart->fresh()->coupon_id)->toBeNull();
})->with([
    'not found' => ['NOPE-NOT-REAL', 'That code is not valid.'],
    'expired' => ['OLD20', 'That code has expired.'],
    'not started' => ['SOON', 'That code is not active yet.'],
    'minimum spend' => ['BIG900', 'Your basket does not meet the minimum for that code.'],
    'usage limit' => ['GONE', 'That code has been fully redeemed.'],
]);

it('says the reason in Arabic on an Arabic checkout', function () {
    /*
     * The toast said "That code has expired." on /ar -- CouponService's English,
     * sent as-is. It is now keyed by CouponService::REASONS and said through
     * __(), so the owner's approved Arabic is what an Arabic shopper reads.
     *
     * MUTATION: return $result['error'] instead of the keyed __() and this is
     * red with the English sentence.
     */
    ArabicShop::on();
    ArabicShop::string('store.checkout.coupon_err_expired', 'انتهت صلاحية هذا الرمز.');
    Coupon::create(['code' => 'OLD20', 'type' => 'percent', 'amount' => 2000, 'expires_at' => now()->subDay()]);

    $r = cpShopper(cpCart())->postJson('/ar/checkout/coupon', ['code' => 'OLD20', 'country' => 'AE']);

    expect($r->json('error'))->toBe('انتهت صلاحية هذا الرمز.');
});

it('gives every refusal a reason with an English string that is the service\'s own sentence', function () {
    /*
     * One table for the nine refusals, so a tenth added to CouponService
     * without its string is red here rather than an untranslated key printed
     * on the checkout ("store.checkout.coupon_err_whatever").
     *
     * MUTATION: delete 'checkout.coupon_err_account' from InterfaceStrings --
     * red naming it.
     */
    $source = file_get_contents(app_path('Services/CouponService.php'));
    preg_match_all("/->fail\\('([^']+)', '([a-z_]+)'\\)/", $source, $m, PREG_SET_ORDER);

    expect($m)->toHaveCount(count(CouponService::REASONS));

    foreach ($m as [, $sentence, $reason]) {
        expect(CouponService::REASONS)->toContain($reason)
            ->and(InterfaceStrings::english('store.checkout.coupon_err_'.$reason))->toBe($sentence, $reason);
    }
});

/* ═══════════════════════ removed, in place ═══════════════════════ */

it('removes the code in place and says so', function () {
    /*
     * There was no Remove on the checkout at all. MUTATION: drop the
     * data-kbb-coupon-remove button from coupon-applied.blade.php and the
     * apply test above is red; break the remove branch and this is.
     */
    $coupon = Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);
    $cart = cpCart();
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    $r = cpShopper($cart)->postJson('/checkout/coupon', ['remove' => true, 'country' => 'AE', 'offered' => ['cod']]);

    $r->assertOk()->assertJson(['ok' => true, 'message' => 'Coupon removed', 'couponHtml' => '']);
    expect($cart->fresh()->coupon_id)->toBeNull()
        ->and($r->json('orderHtml'))->toContain('420')
        ->and($r->json())->not->toHaveKey('paymentHtml');
});

/* ═══════════════════════ the page ═══════════════════════ */

it('draws the applied line on load, and not one byte of it without a coupon', function () {
    /*
     * A reload with a code on the basket shows the same line the in-place
     * answer did, so the Remove is still there. Without one, nothing: the
     * element is built by the script on first use.
     *
     * MUTATION: drop the @include of coupon-line from checkout.blade.php and
     * the first half is red; make it unconditional and the second is.
     */
    $coupon = Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);
    $with = cpCart();
    $with->forceFill(['coupon_id' => $coupon->id])->save();

    $html = cpShopper($with)->get('/checkout')->assertOk()->getContent();
    expect($html)->toContain('id="kbbCouponMsg"')
        ->and($html)->toContain("Coupon GLOW10 applied — you save AED\u{00A0}50")
        ->and(substr_count($html, 'data-kbb-coupon-remove'))->toBe(1);
});

it('adds not one byte to a checkout with no coupon on it', function () {
    // Separate from the test above: one test's requests share a session, and
    // the cart a session has seen is the cart it keeps resolving.
    $plain = cpShopper(cpCart())->get('/checkout')->assertOk()->getContent();
    expect($plain)->not->toContain('kbbCouponMsg')->and($plain)->not->toContain('co-cmsg');
});

it('answers a plain form post (no script) with a redirect back to the checkout and the sentence on it', function () {
    /*
     * The no-JS path. The endpoint used to hand a form post raw JSON as the
     * page. Now: back to /checkout/ with the sentence flashed for one request,
     * the coupon applied when valid, and the form's own field name accepted.
     *
     * MUTATION: return response()->json() unconditionally in couponAnswer() --
     * red on assertRedirect.
     */
    Coupon::create(['code' => 'GLOW10', 'type' => 'fixed_cart', 'amount' => 5000]);
    $cart = cpCart();

    cpShopper($cart)->post('/checkout/coupon', ['coupon_code' => 'NOPE'])
        ->assertRedirect(\App\Support\Url::to('/checkout/'))
        ->assertSessionHas('kbb_coupon_notice', ['ok' => false, 'text' => 'That code is not valid.']);

    $html = cpShopper($cart)->withSession(['kbb_coupon_notice' => ['ok' => false, 'text' => 'That code is not valid.']])
        ->get('/checkout')->getContent();
    expect($html)->toContain('class="co-cmsg is-err" id="kbbCouponMsg"')->and($html)->toContain('That code is not valid.');

    cpShopper($cart)->post('/checkout/coupon', ['coupon_code' => 'GLOW10'])->assertRedirect(\App\Support\Url::to('/checkout/'));
    expect($cart->fresh()->coupon_id)->not->toBeNull();
});

it('never lets Enter, or a Go key, in the coupon box place the order', function () {
    /*
     * The box is inside #kbbCheckoutForm, and placing-overlay and
     * stripe-elements answer ANY `submit` of that form by placing the order.
     * Two things keep a coupon gesture from reaching them, both pinned here:
     *
     *   1. the form has no submit button, so implicit submission (Enter in a
     *      text field) has nothing to fire -- a type=submit anywhere inside it
     *      would make Enter in the coupon box a submit;
     *   2. checkout.js catches a submit on the DOCUMENT, in capture -- ahead of
     *      the form's own listeners -- when no button submitted it and the
     *      coupon box has focus, and applies the code instead. In Chromium,
     *      form.requestSubmit() with the box focused posted /checkout/place
     *      before this; now it posts /checkout/coupon (walk.mjs, "goKey").
     *
     * MUTATION: remove the `document.addEventListener('submit'` block from
     * checkout.js -- red; change Place order to type="submit" -- red.
     */
    $html = cpShopper(cpCart())->get('/checkout')->assertOk()->getContent();
    $start = strpos($html, 'id="kbbCheckoutForm"');
    $form = substr($html, $start, strpos($html, '</form>', $start) - $start);

    expect($form)->toContain('id="kbb_coupon_code"')
        ->and(preg_match('/<(button|input)\b[^>]*type="?(submit|image)"?/i', $form))->toBe(0);

    $js = file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->toMatch("/document\\.addEventListener\\('submit',[\\s\\S]{0,400}kbb_coupon_code[\\s\\S]{0,200}stopImmediatePropagation\\(\\)[\\s\\S]{0,40}applyTyped\\(\\)/")
        // And the old reload-on-coupon fallback is gone for good.
        ->and($js)->not->toContain('window.location.reload()');
});

/* ═══════════════════════ security ═══════════════════════ */

it('checks the CSRF token', function () {
    /*
     * The framework skips the check under the test runner; it is put back for
     * this request, as BuyTogetherTest does. MUTATION: move the route out of
     * the web group (routes/api.php has no CSRF) -- red.
     */
    app()->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        new class(app(), app('encrypter')) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });

    cpShopper(cpCart())->postJson('/checkout/coupon', ['code' => 'NOPE'])->assertStatus(419);

    app()->forgetInstance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

it('is rate limited: twenty tries a minute, then 429', function () {
    /*
     * CouponService answers differently for a code that does not exist, is not
     * active yet, has expired or is used up -- an enumeration oracle without a
     * limit. MUTATION: drop ->middleware('throttle:20,1') from
     * routes/checkout-line.php -- the 21st is a 200 and this is red.
     */
    $cart = cpCart();
    $codes = [];

    for ($i = 0; $i < 21; $i++) {
        $codes[] = cpShopper($cart)->postJson('/checkout/coupon', ['code' => 'GUESS'.$i])->getStatusCode();
    }

    expect(array_slice($codes, 0, 20))->each->toBe(200)
        ->and($codes[20])->toBe(429);
});
