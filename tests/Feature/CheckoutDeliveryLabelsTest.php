<?php

/**
 * Lane QK6 — the "3 Delivery" section. The owner, on a screenshot showing one
 * option "◉ Delivery Charges: AED 20":
 *
 *   "i need Express Delivery or Free Express Delivery upon free delivery
 *    eligibility or with 20 aed charges. need to change the text. and beside
 *    the Delivery section heading, i need a small one line Free delivery over
 *    AED 199. This line will show only in UAE."
 *
 * "Delivery Charges" is the UAE flat-rate method's stored title — orders,
 * emails and invoices print it — so it is not rewritten. Appearance →
 * Checkout page → Delivery labels says what the CHECKOUT calls the option on
 * a UAE order, and the one-liner reads the zone's own free-delivery minimum.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ArabicShop;

beforeEach(function () {
    if (! Route::has('checkout.couponUpdate')) {
        Route::middleware('web')->group(base_path('routes/checkout-line.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }

    $uae = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    $this->free = ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'free_shipping', 'title' => 'Free Delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1]);

    $om = ShippingZone::create(['name' => 'Oman', 'position' => 1]);
    ShippingZoneLocation::create(['shipping_zone_id' => $om->id, 'type' => 'country', 'code' => 'OM']);
    ShippingMethod::create(['shipping_zone_id' => $om->id, 'type' => 'flat_rate', 'title' => 'GCC Delivery', 'cost' => 5000, 'enabled' => true, 'position' => 0]);

    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    SettingsService::forgetMemo();
});

function dlCart(int $priceFils, int $qty = 1): Cart
{
    $product = Product::create([
        'slug' => 'dl-'.uniqid(), 'name' => 'DL Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => $priceFils, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'status' => 'active', 'currency' => 'AED',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $priceFils]);

    return $cart;
}

function dlShopper(Cart $cart)
{
    // A new visitor per basket: one test's requests otherwise share a session
    // and CartService's memo, and keep resolving the first cart.
    test()->flushSession();
    app('auth')->forgetGuards();
    app(CartService::class)->forget();

    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function dlPage(Cart $cart, string $path = '/checkout'): string
{
    SettingsService::forgetMemo();

    return (string) dlShopper($cart)->get($path)->assertOk()->getContent();
}

/** The delivery option labels and the note, out of a page or a fragment. */
function dlLabels(string $html): array
{
    preg_match_all('#<label for="shipping_method_\d+">(.*?)</label>#s', $html, $m);

    // The label's two halves, text | price, joined so a test can read both.
    return array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('</span><span class="co-dl-p">', ' | ', $l))))), $m[1]);
}

function dlNote(string $html): ?string
{
    return preg_match('#<span class="co-dnote" id="kbbDeliveryNote"( hidden)?>(.*?)</span></h2>#s', $html, $m)
        ? ($m[1] !== '' ? 'HIDDEN' : trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[2])))))
        : null;
}

it('calls the paid UAE option "Express Delivery" with its price, and the qualifying one "Free Express Delivery" with Free', function () {
    /*
     * On the shop: "◉ Delivery Charges: AED 20" under the AED 199 line, and
     * "◉ Free Delivery" over it. MUTATION: return null from deliveryLabel()
     * and both are back to the stored titles — red.
     */
    $under = dlPage(dlCart(19899));
    expect(dlLabels($under))->toBe(['Express Delivery | AED 20'])
        ->and($under)->toMatch('#<label for="shipping_method_0"><span class="co-dl-t">Express Delivery</span><span class="co-dl-p"><span class="woocommerce-Price-amount amount"#');

    // Exactly at the threshold it qualifies (ratesFor: subtotal < min skips).
    $at = dlPage(dlCart(19900));
    expect(dlLabels($at))->toBe(['Free Express Delivery | Free'])
        ->and($at)->toContain('<span class="co-dl-t">Free Express Delivery</span><span class="co-dl-p">Free</span>');
});

it('prints the stored title exactly as before when switched off', function () {
    // MUTATION: drop the `flag('dl_on')` check and the switch does nothing — red.
    app(CheckoutPage::class)->save(['dl_on' => false]);

    $html = dlPage(dlCart(10000));
    expect($html)->toMatch('#<label for="shipping_method_0">Delivery Charges: <span class="woocommerce-Price-amount amount"[^>]*>.*?</span></label>#s')
        ->and($html)->not->toContain('co-dl-t');
});

it('switches the label live when a quantity step crosses the line, through the checkout\'s own line endpoint', function () {
    /*
     * Before this lane the line answer carried no delivery list: the order
     * block said "Free" and the option above still said "Delivery Charges: AED
     * 20". MUTATION: drop `deliveryHtml` from fragments() — red.
     */
    $cart = dlCart(12000);
    $item = $cart->items()->first();
    expect(dlLabels(dlPage($cart)))->toBe(['Express Delivery | AED 20']);

    $r = dlShopper($cart)->postJson('/checkout/line', ['item_id' => $item->id, 'quantity' => 2, 'country' => 'AE']);
    $r->assertOk();
    expect(dlLabels((string) $r->json('deliveryHtml')))->toBe(['Free Express Delivery | Free']);

    $r = dlShopper($cart)->postJson('/checkout/line', ['item_id' => $item->id, 'quantity' => 1, 'country' => 'AE']);
    expect(dlLabels((string) $r->json('deliveryHtml')))->toBe(['Express Delivery | AED 20']);

    $js = file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->toContain("if (slot && typeof data.deliveryHtml === 'string') slot.innerHTML = data.deliveryHtml;");
});

it('keeps Free when a coupon takes the total under the line — the rate qualifies on the subtotal before the coupon', function () {
    /*
     * CartService's own rule, unchanged: a discount never pushes a basket
     * back under a threshold it had reached. So a coupon does not flip the
     * label, and the coupon answer does not carry a delivery list to flip it.
     * MUTATION: qualify ratesFor() on the after-coupon total and the re-quote
     * below says "Express Delivery AED 20" — red.
     */
    Coupon::create(['code' => 'HALF', 'type' => 'percent', 'amount' => 5000]);
    $cart = dlCart(20000);

    $r = dlShopper($cart)->postJson('/checkout/coupon', ['code' => 'HALF', 'country' => 'AE', 'offered' => ['cod']]);
    $r->assertOk()->assertJson(['ok' => true]);
    expect($r->json())->not->toHaveKey('deliveryHtml');

    $q = dlShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE']);
    expect(dlLabels((string) $q->json('deliveryHtml')))->toBe(['Free Express Delivery | Free']);
});

it('shows "Free delivery over AED 199" beside Delivery for the UAE only, and follows the country', function () {
    /*
     * MUTATION: drop the DL_COUNTRY check in deliveryNoteHtml() and Oman's
     * re-quote carries the UAE line — red.
     */
    $cart = dlCart(10000);
    $html = dlPage($cart);
    expect(dlNote($html))->toBe('Free delivery over AED 199')
        ->and($html)->toMatch('#<h2><span class="n">3</span> Delivery<span class="co-dnote" id="kbbDeliveryNote">#');

    $om = dlShopper($cart)->postJson('/api/checkout/rates', ['country' => 'OM']);
    expect($om->json('deliveryNote'))->toBe('')
        // Not the UAE: the method's own title, as before.
        ->and(dlLabels((string) $om->json('deliveryHtml')))->toBe(['GCC Delivery: AED 50']);

    $ae = dlShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE']);
    expect(trim(html_entity_decode(strip_tags((string) $ae->json('deliveryNote')))))->toBe('Free delivery over AED 199');

    $js = file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->toContain("note.hidden = data.deliveryNote === '';");

    // Still said when the order already qualifies; the option says Free.
    expect(dlNote(dlPage(dlCart(30000))))->toBe('Free delivery over AED 199');
});

it('reads the amount from the UAE zone\'s free-delivery minimum, and draws nothing without one', function () {
    // MUTATION: type 199 into the wording instead of {amount} and this is red.
    $this->free->update(['min_amount' => 25000]);
    expect(dlNote(dlPage(dlCart(10000))))->toBe('Free delivery over AED 250');

    $this->free->delete();
    expect(dlNote(dlPage(dlCart(10000))))->toBe('HIDDEN');

    app(CheckoutPage::class)->save(['dl_note_on' => false]);
    expect(dlPage(dlCart(10000)))->not->toContain('kbbDeliveryNote');
});

it('says both in Arabic on /ar/', function () {
    // MUTATION: drop the `_ar` branch of localised() and /ar/ prints English.
    ArabicShop::on();
    $html = dlPage(dlCart(10000), '/ar/checkout');
    expect(dlLabels($html)[0])->toStartWith('توصيل سريع | ')
        ->and(dlNote($html))->toStartWith('توصيل مجاني للطلبات فوق ');

    $free = dlPage(dlCart(30000), '/ar/checkout');
    expect($free)->toContain('<span class="co-dl-t">توصيل سريع مجاني</span>');
});

it('escapes the owner\'s wording; only the amount is markup, built from an integer', function () {
    // MUTATION: drop the e() in deliveryNoteHtml() and the <img> goes out live.
    app(CheckoutPage::class)->save([
        'dl_note' => '<img src=x onerror=alert(1)> over {amount}',
        'dl_paid' => '<b>Fast</b> & cheap',
    ]);
    $html = dlPage(dlCart(10000));

    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt; over <span class="woocommerce-Price-amount amount"')
        ->and($html)->toContain('<span class="co-dl-t">&lt;b&gt;Fast&lt;/b&gt; &amp; cheap</span>')
        ->and($html)->not->toContain('<img src=x');
});

it('sits on its own tab, Appearance → Checkout page → Delivery labels, shipped on', function () {
    $tabs = \App\Services\ModuleSchema::tabs(CheckoutPage::SCHEMA, CheckoutPage::TABS, app(CheckoutPage::class)->all(), CheckoutPage::POLICY);
    $tab = collect($tabs)->firstWhere('key', 'delivery');

    expect($tab['label'])->toBe('Delivery labels')
        ->and(array_column($tab['fields'], 'key'))->toBe(['dl_on', 'dl_paid', 'dl_paid_ar', 'dl_free', 'dl_free_ar', 'dl_note_on', 'dl_note', 'dl_note_ar'])
        ->and(CheckoutPage::SCHEMA['dl_on'][2])->toBeTrue()
        ->and(CheckoutPage::SCHEMA['dl_note_on'][2])->toBeTrue();
});
