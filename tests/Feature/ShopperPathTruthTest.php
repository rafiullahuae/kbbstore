<?php

/**
 * Lane CF — the shopper's path from product page to paid order, told honestly.
 *
 * Every case here was found by walking the real storefront in Chromium at 390px
 * and 1280px with a seeded catalogue, not by reading the code. Each one either
 * loses the sale or tells the shopper something that is not true, and each is
 * pinned here so it cannot come back.
 *
 * THE CLASS-NAME TRAP. Searching rendered HTML for a bare class name also
 * matches the page's own inlined CSS — kbb-checkout.css is pushed into the
 * document, so `js-total-row-fee` appears in a stylesheet rule whether or not
 * any element carries it. Every assertion below therefore matches an ELEMENT,
 * with a regex anchored on the opening tag.
 */

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    // Production's two zones, in miniature: the UAE at AED 20 (free over 199)
    // and the Gulf at AED 150. The Gulf half is what makes the delivery-promise
    // case below mean anything.
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'free_shipping',
        'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
    ]);

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
        ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => $code]);
    }
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate',
        'title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);
});

/** Settings only ever through the service — it holds a forever-cache AND a per-process memo. */
function cfSet(string $key, $value): void
{
    app(SettingsService::class)->set($key, $value);
}

function cfProduct(array $attributes = []): Product
{
    return Product::create(array_merge([
        'slug' => 'cf-' . Str::random(8),
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ], $attributes));
}

function cfCart(int $unitPriceFils = 13000, int $qty = 1): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => cfProduct()->id,
        'quantity' => $qty,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

/** A browser carrying this cart's cookie. */
function cfShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** Does the markup carry an ELEMENT with this class? Never a bare string search. */
function cfHasElement(string $html, string $class): bool
{
    return (bool) preg_match('/<[a-z]+[^>]*class="[^"]*\b' . preg_quote($class, '/') . '\b/i', $html);
}

/*
|------------------------------------------------------------------------------
| 1. The Total row, at the moment of payment
|------------------------------------------------------------------------------
*/

it('still shows a Total when Cash on delivery is chosen and carries no fee', function () {
    /*
     * kbb-checkout.css hides `.js-total-row` and shows `.js-total-row-fee`
     * whenever #payment_method_cod is checked. order-block.blade.php only
     * rendered `.js-total-row-fee` when the COD fee was above zero — and zero
     * is the admin's own default for that field (EcommerceApiController's
     * `cod_fee` schema row). So a shop that takes cash on delivery and charges
     * nothing extra for it showed the shopper a checkout with NO TOTAL AT ALL:
     * subtotal, delivery, VAT, and then straight to Place order.
     *
     * Measured in Chromium at 390 and 1280 against a seeded catalogue — the
     * order block read "Subtotal AED 130 | Delivery AED 20 | VAT AED 7" and
     * stopped there.
     */
    cfSet('cod_fee', 0);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $html = cfShopper(cfCart())->get('/checkout/')->assertOk()->getContent();

    expect(cfHasElement($html, 'js-total-row-fee'))
        ->toBeTrue('The COD-inclusive Total row is missing, and the CSS hides the plain one whenever COD is selected — the shopper sees no total at all.');
});

/*
|------------------------------------------------------------------------------
| 2. Gift wrapping — charged, and said out loud
|------------------------------------------------------------------------------
*/

it('itemises gift wrapping even when there is no Cash-on-delivery fee', function () {
    /*
     * The Gift wrapping row lived inside the same `@if ($codFeeFils > 0)` guard
     * as the COD fee row, while the Total below it has always been
     * `total + giftFee`. With no COD fee configured the shopper ticked "This
     * order is a gift", the Total silently rose by AED 15, and nothing on the
     * page said why.
     */
    cfSet('cod_fee', 0);
    cfSet('gift_enabled', '1');
    cfSet('gift_fee', 1500);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $cart = cfCart();

    $html = cfShopper($cart)
        ->withSession(['kbb_gift' => true])
        ->get('/checkout/')->assertOk()->getContent();

    expect(cfHasElement($html, 'js-gift-row'))
        ->toBeTrue('Gift wrapping is in the Total but has no line of its own — the shopper is charged AED 15 with no explanation.');

    // And the row must be showing, not merely present-and-hidden.
    expect((bool) preg_match('/<div[^>]*class="[^"]*\bjs-gift-row\b[^"]*"[^>]*hidden/i', $html))
        ->toBeFalse('The Gift wrapping row is rendered hidden while the fee is being charged.');
});

/*
|------------------------------------------------------------------------------
| 3. The delivery promise belongs to the destination
|------------------------------------------------------------------------------
*/

it('does not promise a UAE delivery time to a Gulf customer', function () {
    /*
     * CheckoutController::deliveryText() fell back to `delivery_default_text`
     * for every country, and that default is "1-3 days fast delivery all over
     * UAE". A shopper in Saudi Arabia, being charged the AED 150 Gulf rate on
     * the same screen, was told their parcel arrives in one to three days
     * anywhere in the UAE.
     *
     * This is the same defect App\Mail\OrderStatusChanged already repaired for
     * the dispatch email, and for the same reason: it is a delivery promise,
     * and it was the wrong one. Nothing is invented to replace it — no Gulf
     * window has been measured — so the line is simply not shown. The partial
     * already hides itself on an empty string.
     */
    $rendered = cfShopper(cfCart())
        ->withSession(['_old_input' => ['billing_country' => 'SA', 'billing_state' => 'Riyadh']])
        ->get('/checkout/')->assertOk()->getContent();

    // The Gulf rate is what is being charged on this same page.
    expect(str_contains($rendered, 'Shipping Charges'))->toBeTrue('fixture did not reach the Gulf zone');

    $line = [];
    preg_match('/<div class="kbb-delivery-line">.*?<span>(.*?)<\/span>/s', $rendered, $line);

    expect(str_contains($line[1] ?? '', 'UAE'))
        ->toBeFalse('The checkout promises a UAE delivery time to a customer paying the Gulf rate: ' . ($line[1] ?? ''));
});

it('still shows the UAE delivery line to a UAE customer', function () {
    // The other half: dropping the promise everywhere would be its own defect.
    $rendered = cfShopper(cfCart())->get('/checkout/')->assertOk()->getContent();

    $line = [];
    preg_match('/<div class="kbb-delivery-line">.*?<span>(.*?)<\/span>/s', $rendered, $line);

    expect(str_contains($line[1] ?? '', 'UAE'))
        ->toBeTrue('The UAE delivery line disappeared for the country it actually describes.');
});

it('moves the delivery promise with the country when the selector changes', function () {
    /*
     * The country selector does not reload — it fetches /api/checkout/rates and
     * swaps in the delivery options, the free-delivery bar and the totals. The
     * line under Place order was not in that set, so switching from the UAE to
     * Saudi Arabia updated the charge to AED 150 and left the UAE promise
     * sitting underneath it. Reproduced in Chromium: the server-rendered page
     * for SA was correct and the AJAX path was not.
     */
    $cart = cfCart();

    $ae = cfShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE', 'state' => 'Dubai'])
        ->assertOk()->json();
    $sa = cfShopper($cart)->postJson('/api/checkout/rates', ['country' => 'SA', 'state' => 'Riyadh'])
        ->assertOk()->json();

    expect($ae)->toHaveKey('deliveryText');
    expect(str_contains((string) $ae['deliveryText'], 'UAE'))->toBeTrue();
    expect(str_contains((string) $sa['deliveryText'], 'UAE'))
        ->toBeFalse('The rate refresh still hands the browser a UAE delivery promise for a Gulf destination.');
});

it('uses a delivery text the owner has written for a country over saying nothing', function () {
    // An explicit row always wins; this is the escape hatch for the Gulf.
    cfSet('delivery_texts', [['country' => 'SA', 'text' => '5-8 days to Saudi Arabia']]);

    $rendered = cfShopper(cfCart())
        ->withSession(['_old_input' => ['billing_country' => 'SA', 'billing_state' => 'Riyadh']])
        ->get('/checkout/')->assertOk()->getContent();

    expect(str_contains($rendered, '5-8 days to Saudi Arabia'))
        ->toBeTrue('An explicit delivery_texts row for the destination was not used.');
});

/*
|------------------------------------------------------------------------------
| 4. A checkout with nothing to pay with says so
|------------------------------------------------------------------------------
*/

it('says so when no payment method is available instead of rendering an empty section', function () {
    /*
     * PaymentProviderSeeder seeds all four gateways `enabled => false`, so a
     * freshly installed shop renders the Payment step with an empty list and a
     * live Place order button. Pressing it answers "The payment method field is
     * required." — a validation message about a field that was never on the
     * page. Reproduced end to end in Chromium: zero radios, and that sentence.
     */
    // Exactly what PaymentProviderSeeder installs: the rows exist and are all
    // switched off. gateways() only falls back to a synthetic Cash-on-delivery
    // when there is NO cod row at all, so this is the shape a real shop has
    // before anyone opens Store → Payments.
    foreach ([['cod', 'Cash on delivery'], ['stripe', 'Credit / Debit Card']] as $i => [$id, $title]) {
        PaymentProvider::create(['id' => $id, 'title' => $title, 'enabled' => false, 'mode' => 'test', 'position' => $i]);
    }

    $html = cfShopper(cfCart())->get('/checkout/')->assertOk()->getContent();

    expect((bool) preg_match('/<input[^>]*name="payment_method"/i', $html))
        ->toBeFalse('fixture unexpectedly offered a gateway');

    expect(cfHasElement($html, 'pay-empty'))
        ->toBeTrue('The payment step is empty and silent — the shopper is left pressing Place order for an answer.');
});

/*
|------------------------------------------------------------------------------
| 5. The product page's scarcity line
|------------------------------------------------------------------------------
*/

it('shows how many are left when stock is low', function () {
    /*
     * store/product.blade.php read `$product->stock_quantity`. There is no such
     * column and no such accessor — the column is `stock` — so `$left` was
     * always null, `is_numeric(null)` always false, and "Only N left - order
     * soon" had never rendered for any product in the shop's history. The
     * owner's own `low_stock_at` setting drove nothing at all.
     */
    cfSet('low_stock_at', 5);
    $product = cfProduct(['manage_stock' => true, 'stock' => 3]);

    $html = test()->get($product->url())->assertOk()->getContent();

    expect(str_contains($html, 'Only 3 left'))
        ->toBeTrue('The low-stock line never renders: the view reads a column that does not exist.');
});

it('does not invent a scarcity line when stock is not being counted', function () {
    cfSet('low_stock_at', 5);
    $product = cfProduct(['manage_stock' => false, 'stock' => null]);

    $html = test()->get($product->url())->assertOk()->getContent();

    expect(str_contains($html, 'order soon'))->toBeFalse('A scarcity line appeared for a product with no counted stock.');
    expect(str_contains($html, 'In stock'))->toBeTrue();
});

/*
|------------------------------------------------------------------------------
| 6. A variable product whose first option is sold out
|------------------------------------------------------------------------------
*/

it('preselects an option that can actually be bought', function () {
    /*
     * The hidden variation_id defaulted to `$variants->first()?->id` regardless
     * of stock, and only variant 0 was ever given the `on` class — and only
     * when it was in stock. So a product whose first size is sold out rendered
     * with NO option highlighted, "In stock - ready to ship" above it, an
     * enabled Add to cart, and a hidden field pointing at the sold-out size.
     * Verified in Chromium: `.variant.on` count 0, #kbbVarId = the sold-out id.
     *
     * Tapping the sold-out row does nothing at all (pdp.js declines `.oos`), so
     * the only way the shopper learns is by pressing Add and being refused.
     */
    $product = cfProduct(['type' => 'variable']);

    $soldOut = ProductVariant::create([
        'product_id' => $product->id, 'sku' => 'S-50', 'price' => 13000,
        'stock_status' => 'outofstock', 'manage_stock' => false, 'position' => 0,
    ]);
    $inStock = ProductVariant::create([
        'product_id' => $product->id, 'sku' => 'S-100', 'price' => 22000,
        'stock_status' => 'instock', 'manage_stock' => false, 'position' => 1,
    ]);

    $html = test()->get($product->url())->assertOk()->getContent();

    $hidden = [];
    preg_match('/<input[^>]*id="kbbVarId"[^>]*value="(\d+)"/', $html, $hidden);

    expect((int) ($hidden[1] ?? 0))
        ->toBe($inStock->id, 'Add to cart is armed with the sold-out option, so the first press is always refused.');

    expect((bool) preg_match('/<div class="variant on"[^>]*data-vid="' . $inStock->id . '"/', $html))
        ->toBeTrue('No buyable option is highlighted, so the page shows a price nobody selected.');
});

it('says sold out rather than ready to ship when nothing can be bought', function () {
    // Every option gone is the same fact as a simple product being out of
    // stock, and the line above the button has to agree with the button.
    $product = cfProduct(['type' => 'variable']);

    foreach ([0, 1] as $i) {
        ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'S-' . $i, 'price' => 13000,
            'stock_status' => 'outofstock', 'manage_stock' => false, 'position' => $i,
        ]);
    }

    $html = test()->get($product->url())->assertOk()->getContent();

    expect(str_contains($html, 'In stock · ready to ship'))
        ->toBeFalse('Every option is sold out and the page still says the product is ready to ship.');
});

/*
|------------------------------------------------------------------------------
| 7. The cart page's "Total"
|------------------------------------------------------------------------------
*/

/*
|------------------------------------------------------------------------------
| 8. Enter, in the two fields a shopper types a discount code into
|------------------------------------------------------------------------------
|
| Both were silent. Neither field is inside a form that can be submitted by
| pressing Enter — the cart's is in no form at all, and the checkout's sits in a
| form with no submit button and more than one field, which is the case the HTML
| spec says must do nothing — so the gesture had no fallback either.
|
| Asserted against BOTH the source and the committed bundle, for the reason
| BuildAssetsTest sets out: public/build is what the server serves, and a fix
| that lives only in resources/js ships as no fix at all. `git show`, not the
| working tree, because running this suite deletes public/build.
*/

/** Every committed JS bundle, by path. Named for this file so it cannot collide. */
function cfBundles(): array
{
    $files = preg_split('/\R/', (string) shell_exec(
        'git -C ' . escapeshellarg(base_path()) . ' ls-files public/build/assets 2>/dev/null'
    )) ?: [];

    $out = [];
    foreach ($files as $file) {
        if ($file !== '' && str_ends_with($file, '.js')) {
            $out[$file] = (string) shell_exec(
                'git -C ' . escapeshellarg(base_path()) . ' show HEAD:' . escapeshellarg($file) . ' 2>/dev/null'
            );
        }
    }

    return $out;
}

/** Is $needle within $window characters of an occurrence of $anchor? */
function cfNear(string $haystack, string $anchor, string $needle, int $window = 200): bool
{
    $from = 0;

    while (($at = strpos($haystack, $anchor, $from)) !== false) {
        if (str_contains(substr($haystack, $at, $window), $needle)) {
            return true;
        }

        $from = $at + 1;
    }

    return false;
}

it('applies a discount code when Enter is pressed at checkout', function () {
    $js = (string) file_get_contents(base_path('resources/js/kbb/checkout.js'));
    $blade = (string) file_get_contents(base_path('resources/views/store/checkout.blade.php'));

    expect(str_contains($blade, 'id="kbb_coupon_code"'))
        ->toBeTrue('The checkout coupon field is no longer #kbb_coupon_code — the handler has to move with it.');

    expect(cfNear($js, "'kbb_coupon_code'", 'changeCoupon'))
        ->toBeTrue('Enter in the checkout discount field does nothing: there is no keydown handler for #kbb_coupon_code.');

    $shipped = false;
    foreach (cfBundles() as $source) {
        if (cfNear($source, 'kbb_coupon_code', 'Enter', 400)) {
            $shipped = true;
            break;
        }
    }

    expect($shipped)->toBeTrue('No committed bundle handles Enter on the checkout coupon field — resources/js changed without rebuilding public/build.');
});

it('applies a discount code when Enter is pressed on the cart page', function () {
    $js = (string) file_get_contents(base_path('resources/js/kbb/cart.js'));
    $blade = (string) file_get_contents(base_path('resources/views/store/cart-inner.blade.php'));

    expect(str_contains($blade, 'id="kbbCartCoupon"'))
        ->toBeTrue('The cart coupon field is no longer #kbbCartCoupon.');

    // The id it used to watch, `cartCoupon`, is rendered nowhere in this
    // application — so the listener matched nothing, on every page, always.
    expect((bool) preg_match("/id === 'cartCoupon'/", $js))
        ->toBeFalse('cart.js still watches #cartCoupon, an element this application never renders.');

    expect(cfNear($js, "'kbbCartCoupon'", "'/coupon'", 300))
        ->toBeTrue('Enter in the cart discount field does not apply the code.');
});

it('answers when a sold-out option is tapped', function () {
    /*
     * pdp.js declined `.variant.oos` outright — no highlight, no price change,
     * no message. On a phone that is indistinguishable from a page that has
     * stopped responding, and it is the only control on the buy box that can
     * legitimately refuse.
     */
    $js = (string) file_get_contents(base_path('resources/js/kbb/pdp.js'));

    expect(cfNear($js, '.variant.oos', 'kbbToast', 400))
        ->toBeTrue('Tapping a sold-out option still does nothing at all.');

    $shipped = false;
    foreach (cfBundles() as $source) {
        if (cfNear($source, '.variant.oos', 'sold out', 400)) {
            $shipped = true;
            break;
        }
    }

    expect($shipped)->toBeTrue('No committed bundle answers a tap on a sold-out option — public/build was not rebuilt.');
});

/*
|------------------------------------------------------------------------------
| 9. The order-received page
|------------------------------------------------------------------------------
*/

/** An order this browser session is allowed to see, the way place() grants it. */
function cfReceived(array $attributes = [])
{
    $order = \App\Models\Order::create(array_merge([
        'order_number' => '90001',
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Layla', 'last_name' => 'Hassan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Layla', 'last_name' => 'Hassan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 13000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 15000,
        'shipping_method' => 'Delivery Charges',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $attributes));

    return test()
        ->withSession(['kbb_orders_viewable' => [$order->order_number]])
        ->get('/checkout/success?order=' . $order->order_number);
}

it('does not tell a cash-on-delivery customer they have paid', function () {
    /*
     * The confirmation printed "TOTAL PAID" over the order total for every
     * order, including a cash-on-delivery one where not a dirham has moved.
     * The application's own record disagrees with the page: CashOnDelivery
     * deliberately leaves `paid_at` null, and says why in its class comment —
     * "No money has moved; the courier collects it."
     *
     * Seen on a real order placed through the preview: "TOTAL PAID AED 63 /
     * PAYMENT Cash on delivery".
     */
    $html = cfReceived(['paid_at' => null])->assertOk()->getContent();

    expect(str_contains($html, 'Total paid'))
        ->toBeFalse('The order-received page tells a customer who has paid nothing yet that they have paid.');

    expect(str_contains($html, 'Total to pay'))
        ->toBeTrue('An unpaid order needs a label of its own, not silence where the total used to be.');
});

it('still says paid when the money has actually arrived', function () {
    $html = cfReceived(['order_number' => '90002', 'payment_method' => 'stripe', 'paid_at' => now()])
        ->assertOk()->getContent();

    expect(str_contains($html, 'Total paid'))
        ->toBeTrue('A settled card order no longer says the money arrived.');
});

it('prints the delivery country by name, not by code', function () {
    // "AE" on the last line of an address is a database value, not an address.
    $html = cfReceived(['order_number' => '90003'])->assertOk()->getContent();

    expect(str_contains($html, 'United Arab Emirates'))
        ->toBeTrue('The delivery address ends in a two-letter code rather than the country.');
});

it('does not call the pre-delivery figure a Total without saying so', function () {
    /*
     * CartController::payload() calls totals() with no shipping cost, so the
     * cart page's "Total" is the subtotal less any discount and nothing else.
     * A basket of AED 130 read "Total AED 130" and then became AED 150 on the
     * next screen. The number is right; the word beside it was not.
     */
    $html = cfShopper(cfCart())->get('/cart/')->assertOk()->getContent();

    expect(str_contains($html, 'Delivery calculated at checkout'))
        ->toBeTrue('The cart calls a pre-delivery figure the Total with nothing to say delivery is still to come.');
});
