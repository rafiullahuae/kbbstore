<?php

/**
 * Lane QK6 — three checkout requests from the owner, each on an iPhone
 * screenshot of /checkout/:
 *
 *  1. "on very top, above summary row, i want the nice coupon line: For
 *     Discount, Apply coupon {coupon-code} and upon click the coupon should
 *     auto apply on the order total without refreshing the page etc."
 *  2. "turn off the back to shop link and back to cart button on checkout
 *     completely!"
 *  3. "Change Shipping address section heading to Shipping Details, remove the
 *     phone and Full name fields from the contact section and bring these two
 *     fields to Shipping Details section."
 *
 * Before this lane the checkout had no line at the top (the only coupon cue
 * was the box half a screen down), drew "← Back to shop" and "Go back to
 * cart" on every load, and asked for name and phone under Contact.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartPanel;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
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

    // Lane QK8: the line ships OFF now ("and the top coupon line, turned
    // off."). Everything below is how it behaves once he switches it back on,
    // so it is switched on here; the shipped default has a test of its own.
    app(CheckoutPage::class)->save(['cline_on' => true]);

    SettingsService::forgetMemo();
});

function q6Owner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Q6 '.$role, 'email' => 'q6-'.$role.'-'.Str::random(8).'@example.test',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

function q6Coupon(array $attrs = []): Coupon
{
    return Coupon::create(array_merge(['code' => 'GLOW', 'type' => 'percent', 'amount' => 1000], $attrs));
}

function q6Cart(int $priceFils = 20000): Cart
{
    $product = Product::create([
        'slug' => 'q6-'.uniqid(), 'name' => 'Q6 Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => $priceFils, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'status' => 'active', 'currency' => 'AED',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $priceFils]);

    return $cart;
}

function q6Shopper(Cart $cart)
{
    // A new visitor per basket (see CheckoutDeliveryLabelsTest).
    test()->flushSession();
    app('auth')->forgetGuards();
    app(CartService::class)->forget();

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function q6Checkout(?Cart $cart = null, string $path = '/checkout'): string
{
    SettingsService::forgetMemo();

    return (string) q6Shopper($cart ?? q6Cart())->get($path)->assertOk()->getContent();
}

/** The cart panel's coupon — the line's default source (Lane QK3's snapshot). */
function q6PanelChooses(Coupon $coupon): void
{
    app(CartPanel::class)->save(['coupon_id' => (string) $coupon->id]);
    SettingsService::forgetMemo();
}

function q6Set(array $values): void
{
    app(CheckoutPage::class)->save($values);
    SettingsService::forgetMemo();
}

/* ══════════════════════════ 1. the coupon line ══════════════════════════ */

it('ships OFF, as asked, and the live shop is written off by the package (Lane QK8)', function () {
    /*
     * The owner: "and the top coupon line, turned off." Before this the line
     * shipped on and QK6 left it on; the top of /checkout/ read the GLOW line
     * above the Order summary.
     *
     * MUTATION: ship `cline_on` true again, or drop the cline_on write from
     * the migration, and this is red.
     */
    expect(CheckoutPage::SCHEMA['cline_on'][2])->toBeFalse();

    q6PanelChooses(q6Coupon());
    DB::table('settings')->where('key', 'checkoutpage_cline_on')->delete();
    SettingsService::forgetMemo();
    expect(q6Checkout())->not->toContain('kbbCline')->and(q6Checkout())->not->toContain('co-cline');

    // A shop that had it ON (QK6 shipped it so) is switched off by the package.
    q6Set(['cline_on' => true]);
    expect(q6Checkout())->toContain('id="kbbCline"');
    (require base_path('database/migrations/2027_10_16_120000_checkout_plain_payment_boxes_back_arrow_no_whatsapp.php'))->up();
    SettingsService::forgetMemo();
    expect(app(CheckoutPage::class)->get('cline_on'))->toBeFalse()
        ->and(q6Checkout())->not->toContain('kbbCline');
});

it('switched on, says his sentence, the code as a button, first in the grid so it sits above the summary row', function () {
    /*
     * MUTATION: drop the @include of coupon-top from checkout.blade.php and
     * every expectation here is red.
     */
    expect(CheckoutPage::SCHEMA['cline_coupon'][2])->toBe('cart');

    q6PanelChooses(q6Coupon());
    $html = q6Checkout();

    expect(substr_count($html, 'id="kbbCline"'))->toBe(1)
        ->and($html)->toContain('<div class="co-cline" id="kbbCline">')
        ->and($html)->toContain('For Discount, Apply coupon <button type="button" class="co-cl-code" data-kbb-cline="GLOW" aria-label="Apply coupon GLOW">GLOW</button>')
        // Both states are in the render, so a tap moves nothing.
        ->and($html)->toContain('Coupon GLOW applied')
        // The grid's first child; the summary (order:-1 on a phone) after it.
        ->and(strpos($html, 'id="kbbCline"'))->toBeLessThan(strpos($html, 'id="kbbSummary"'))
        ->and($html)->toMatch('#<div class="co-grid">\n<div class="co-cline"#');
});

it('draws nothing at all when switched off, with no coupon, or with an unusable one', function () {
    /*
     * MUTATION: drop the `flag('cline_on')` check in couponLineCode() and the
     * first case is red; drop the usableCode() call (return the snapshot's
     * code as-is) and the expired case is.
     */
    $plain = q6Checkout();
    expect($plain)->not->toContain('kbbCline')->and($plain)->not->toContain('co-cline');

    $glow = q6Coupon();
    q6PanelChooses($glow);

    q6Set(['cline_on' => false]);
    expect(q6Checkout())->not->toContain('kbbCline');
    q6Set(['cline_on' => true]);
    expect(q6Checkout())->toContain('kbbCline');

    // The coupon changes under the snapshot: Coupon::booted re-takes it.
    $glow->update(['expires_at' => now()->subDay()]);
    SettingsService::forgetMemo();
    expect(q6Checkout())->not->toContain('kbbCline');

    $glow->update(['expires_at' => null, 'starts_at' => now()->addDay()]);
    SettingsService::forgetMemo();
    expect(q6Checkout())->not->toContain('kbbCline');

    $glow->update(['starts_at' => null, 'usage_limit' => 1, 'usage_count' => 1]);
    SettingsService::forgetMemo();
    expect(q6Checkout())->not->toContain('kbbCline');
});

it('advertises a coupon picked on its own select, and follows that coupon when it changes', function () {
    q6PanelChooses(q6Coupon());
    $own = q6Coupon(['code' => 'SAVE5', 'type' => 'fixed_cart', 'amount' => 500]);

    q6Set(['cline_coupon' => (string) $own->id]);
    expect(q6Checkout())->toContain('data-kbb-cline="SAVE5"')->not->toContain('data-kbb-cline="GLOW"');

    // MUTATION: drop CheckoutPage::couponChanged() from CartPanel::couponChanged()
    // and the stale snapshot keeps advertising an expired SAVE5 — red.
    $own->update(['expires_at' => now()->subMinute()]);
    SettingsService::forgetMemo();
    expect(q6Checkout())->not->toContain('kbbCline');
});

it('shows the applied state, not the invitation, when that coupon is already on the order', function () {
    /*
     * MUTATION: drop the `is-done` class from coupon-top.blade.php and a
     * shopper who has applied GLOW is invited to apply it again — red.
     */
    $glow = q6Coupon();
    q6PanelChooses($glow);
    $cart = q6Cart();
    $cart->forceFill(['coupon_id' => $glow->id])->save();

    $html = q6Checkout($cart);
    expect($html)->toContain('<div class="co-cline is-done" id="kbbCline">')
        ->and($html)->toContain('<span class="co-cl-donetxt">Coupon GLOW applied</span>');
});

it('escapes the wording and puts the code where the token is, or at the end', function () {
    /*
     * MUTATION: print the wording with {!! !!} unescaped in couponLineHtml()
     * (drop the e()) and the <img> goes out live — red.
     */
    q6PanelChooses(q6Coupon());
    q6Set(['cline_text' => '<img src=x onerror=alert(1)> Save & {coupon-code} now']);

    $html = q6Checkout();
    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt; Save &amp; <button type="button" class="co-cl-code" data-kbb-cline="GLOW"')
        ->and($html)->not->toContain('<img src=x');

    q6Set(['cline_text' => 'Use this one']);
    expect(q6Checkout())->toContain('Use this one <button type="button" class="co-cl-code"');
});

it('says it in Arabic on /ar/, and a cleared Arabic box goes back to the shipped Arabic', function () {
    // MUTATION: drop the `$ar ?` branch in couponLineHtml() and /ar/ prints English.
    ArabicShop::on();
    q6PanelChooses(q6Coupon());

    $html = q6Checkout(null, '/ar/checkout');
    expect($html)->toContain('للحصول على خصم، طبّق القسيمة <button type="button" class="co-cl-code" data-kbb-cline="GLOW"')
        ->and($html)->not->toContain('For Discount');

    q6Set(['cline_text_ar' => 'خصم {coupon-code} الآن']);
    expect(q6Checkout(null, '/ar/checkout'))->toContain('خصم <button type="button" class="co-cl-code"');

    q6Set(['cline_text_ar' => '']);
    expect(app(CheckoutPage::class)->get('cline_text_ar'))->toBe(CheckoutPage::CLINE_TEXT_AR);
});

it('applies through the checkout\'s own in-place endpoint, one request at a time, never reloading', function () {
    /*
     * The page half: one tap hands the pill's code to changeCoupon(), the
     * function the "Have a discount code?" box's Apply uses (POST
     * window.KBB.routes.checkoutCoupon), guarded by the same couponBusy flag.
     * MUTATION: call fetch() from applyFromLine() or drop its couponBusy guard
     * and the string checks are red.
     */
    $js = file_get_contents(resource_path('js/kbb/checkout.js'));
    $fn = substr($js, strpos($js, 'async function applyFromLine('), 1400);

    expect($js)->toContain("const cline = event.target.closest('[data-kbb-cline]');")
        ->and($fn)->toContain('if (couponBusy) return;')
        ->and($fn)->toContain("const code = pill.dataset.kbbCline || '';")
        ->and($fn)->toContain('if (field) field.value = code;')
        ->and($fn)->toContain('await changeCoupon(code, false);')
        ->and($fn)->toContain("pill.setAttribute('aria-busy', 'true');")
        ->and($fn)->not->toContain('fetch(')
        ->and($js)->not->toContain('location.reload')
        ->and($js)->toContain('syncLine(remove ? \'\' : code);');

    // The server half: that code, posted the way changeCoupon() posts it.
    $glow = q6Coupon();
    q6PanelChooses($glow);
    $cart = q6Cart(20000);
    $html = q6Checkout($cart);
    preg_match('#data-kbb-cline="([^"]+)"#', $html, $m);
    expect($m[1] ?? null)->toBe('GLOW');

    $r = q6Shopper($cart)->postJson('/checkout/coupon', ['code' => $m[1], 'remove' => false, 'country' => 'AE', 'offered' => ['cod']]);
    $r->assertOk()->assertJson(['ok' => true]);
    expect($cart->fresh()->coupon_id)->toBe($glow->id)
        ->and($r->json('couponHtml'))->toContain('Coupon GLOW applied');
});

it('gives the checkout\'s own reason when the code is refused', function () {
    // The line only advertises a usable coupon, but a basket rule can still
    // refuse it; the answer is the box's sentence, which the line repeats.
    $glow = q6Coupon(['minimum_amount' => 900000]);
    q6PanelChooses($glow);
    $cart = q6Cart(20000);

    $r = q6Shopper($cart)->postJson('/checkout/coupon', ['code' => 'GLOW', 'country' => 'AE']);
    expect($r->json('ok'))->toBeFalse()
        ->and($r->json('error'))->toBe('Your basket does not meet the minimum for that code.');

    $js = file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->toContain("err.textContent = document.getElementById('kbbCouponMsg')?.textContent.trim() || '';");
});

it('stores only "same as the cart panel" or a real coupon id, and lists the shop coupons on the screen', function () {
    /*
     * MUTATION: drop the exists() check in CheckoutPage::save() and the bogus
     * id is stored; drop the cline_coupon arm of cast() and the script tag is.
     */
    $old = q6Coupon(['code' => 'AAOLD', 'expires_at' => now()->subMonth()]);
    $glow = q6Coupon();

    test()->actingAs(q6Owner(), 'admin')
        ->postJson('/admin-api/checkout-page', ['settings' => ['cline_coupon' => '999999']])->assertOk();
    SettingsService::forgetMemo();
    expect(app(CheckoutPage::class)->get('cline_coupon'))->toBe('cart');

    test()->postJson('/admin-api/checkout-page', ['settings' => ['cline_coupon' => '"><script>']])->assertOk();
    SettingsService::forgetMemo();
    expect(app(CheckoutPage::class)->get('cline_coupon'))->toBe('cart');

    test()->postJson('/admin-api/checkout-page', ['settings' => ['cline_coupon' => (string) $glow->id]])->assertOk();
    SettingsService::forgetMemo();
    expect(app(CheckoutPage::class)->get('cline_coupon'))->toBe((string) $glow->id);

    $body = test()->getJson('/admin-api/checkout-page')->assertOk()->json();
    expect(array_column($body['coupons'], 'code'))->toBe(['GLOW', 'AAOLD'])
        ->and(array_keys($body['coupons'][0]))->toBe(['id', 'code', 'label', 'usable']);

    $tab = collect($body['tabs'])->firstWhere('key', 'cline');
    expect($tab['label'])->toBe('Coupon line')
        ->and(array_column($tab['fields'], 'key'))->toBe(['cline_on', 'cline_coupon', 'cline_text', 'cline_text_ar']);

    // Behind the screen's own capability, failing closed.
    test()->actingAs(q6Owner('support'), 'admin')
        ->postJson('/admin-api/checkout-page', ['settings' => ['cline_coupon' => 'cart']])->assertForbidden();
});

it('costs no query: /checkout/ with the line and without it runs the same queries, none on coupons', function () {
    /*
     * MUTATION: read Coupon::find() in couponLineCode() instead of the
     * snapshot and the page with the line is one query dearer — red.
     */
    $cart = q6Cart();
    $glow = q6Coupon();
    q6Checkout($cart);

    $queries = [];
    DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

    $count = function () use ($cart, &$queries) {
        q6Checkout($cart);
        $queries = [];
        $html = q6Checkout($cart);

        return [count($queries), str_contains($html, 'id="kbbCline"'), $queries];
    };

    [$without, $shown] = $count();
    expect($shown)->toBeFalse();

    q6PanelChooses($glow);
    [$with, $shown, $after] = $count();
    expect($shown)->toBeTrue()->and($with)->toBe($without)
        ->and(collect($after)->filter(fn ($sql) => str_contains($sql, 'coupons'))->all())->toBe([]);
});

/* ═══════════════════ 2. no way back but the logo ═══════════════════════ */

it('draws neither "Back to shop" nor "Go back to cart" by default, and leaves no empty wrapper', function () {
    /*
     * MUTATION: default either switch to true and its element is back — red.
     */
    expect(CheckoutPage::SCHEMA['shop_link'][2])->toBeFalse()
        ->and(CheckoutPage::SCHEMA['cart_link'][2])->toBeFalse();

    $html = q6Checkout();
    expect($html)->not->toContain('class="backlink"')
        ->and($html)->not->toContain('co-tocart')
        // The heading opens the title block; nothing empty is left before it
        // or after the lead.
        // (Lane QK8: the round back arrow opens it now, then the heading.)
        ->and($html)->toMatch('#<div class="co-titlebar-main">\n\s*<a class="co-back" [^>]*>.*?</a>\n\s*<h1 class="co-h">#')
        ->and($html)->toMatch('#<p class="co-lead">[^<]*</p>\n\s*</div>\n\s*</div>#')
        // The logo still goes home.
        ->and($html)->toMatch('#<header class="co-head">.*?href="[^"]*/"#s');
});

it('draws both again, as they were, when switched on', function () {
    q6Set(['shop_link' => true, 'cart_link' => true]);
    $html = q6Checkout();

    expect(substr_count($html, 'class="backlink"'))->toBe(1)
        ->and($html)->toContain('>← Back to shop</a>')
        ->and(substr_count($html, 'class="co-tocart'))->toBe(1);

    // The tab that holds them.
    $tab = collect(\App\Services\ModuleSchema::tabs(CheckoutPage::SCHEMA, CheckoutPage::TABS, app(CheckoutPage::class)->all(), CheckoutPage::POLICY))->firstWhere('key', 'tocart');
    // (Lane QK8: the heading's back arrow first.)
    expect(array_slice(array_column($tab['fields'], 'key'), 0, 3))->toBe(['head_back', 'shop_link', 'cart_link']);
});

/* ═════════════════════ 3. Shipping Details ═════════════════════════════ */

it('heads section 2 "Shipping Details" and opens it with Full name then Phone; Contact keeps email only', function () {
    /*
     * MUTATION: move the billing_phone field back under Contact and the
     * Contact slice contains it — red; put it after the address and the order
     * check is.
     */
    $html = q6Checkout();

    $one = strpos($html, '<span class="n">1</span>');
    $two = strpos($html, '<span class="n">2</span>');
    $three = strpos($html, '<span class="n">3</span>');
    $contact = substr($html, $one, $two - $one);
    $ship = substr($html, $two, $three - $two);

    expect($ship)->toStartWith('<span class="n">2</span> Shipping Details</h2>')
        ->and($contact)->toContain('id="billing_email"')
        ->and($contact)->toContain('id="create_account"')
        ->and($contact)->not->toContain('id="billing_first_name"')
        ->and($contact)->not->toContain('id="billing_phone"');

    $name = strpos($ship, 'id="billing_first_name"');
    $phone = strpos($ship, 'id="billing_phone"');
    $addr = strpos($ship, 'id="billing_address_1"');
    expect($name)->not->toBeFalse()->and($phone)->toBeGreaterThan($name)->and($addr)->toBeGreaterThan($phone);

    // Each field exactly as before: name, id, autocomplete, inputmode, required.
    expect($ship)->toMatch('#<input[^>]*name="billing_first_name"[^>]*autocomplete="section-billing billing name"#')
        ->and($ship)->toMatch('#<input[^>]*type="tel"[^>]*name="billing_phone"[^>]*inputmode="tel"#')
        ->and($ship)->toMatch('#<input[^>]*name="billing_phone"[^>]*autocomplete="section-billing billing tel"#')
        ->and(substr_count($html, 'name="billing_first_name"'))->toBe(1)
        ->and(substr_count($html, 'name="billing_phone"'))->toBe(1);
});

it('says Shipping Details in Arabic from the shipped draft wording', function () {
    expect(\App\Services\Translation\ArabicInterfaceDrafts::all()['store.checkout.step_shipping'])->toBe('تفاصيل الشحن');
});
