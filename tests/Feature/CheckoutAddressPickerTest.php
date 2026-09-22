<?php

declare(strict_types=1);

use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\SettingsService;

/**
 * =============================================================================
 * THE CHECKOUT'S SHIPPING ADDRESS SECTION IS A PICKER, OVER THE CART'S ADDRESSES
 * =============================================================================
 *
 * Five typed fields became one chosen address. The order still posts
 * `billing_address_1`, `billing_city`, `billing_state` and `billing_country`,
 * all four required in place()'s rules and place() untouched — the shopper no
 * longer types them, the address they picked fills them.
 *
 * ── WHAT THESE TESTS ARE REALLY FOR ─────────────────────────────────────────
 *
 * Not "the markup changed". The four hidden inputs are the money path: if they
 * are empty, or carry the wrong thing, the order is rejected or shipped to the
 * wrong place, and neither failure looks like a layout bug. So every test here
 * either places an order or reads what would be posted.
 *
 * CITY IS ALSO THE EMIRATE, at the owner's instruction — "whatever user fill
 * in the city, it will come in the emirate field auto". `billing_state` is
 * required AND it is what ShippingService prices on, so this is not cosmetic:
 * get it wrong and delivery is charged at the wrong rate. The popup's own
 * placeholder has always read "e.g. Sharjah", so the box was collecting an
 * emirate before anyone wired it to one.
 *
 * MUTATION: drop the `state` line from CartAddressState::shape()'s `form`.
 * Red — the order posts an empty emirate and place() refuses it.
 * MUTATION: put `squeezed()` back in CartAddressController's gate. Red — the
 * picker 404s on a shop running the classic cart page.
 */
beforeEach(function () {
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
});

function ckaBasket(): void
{
    $p = Product::create([
        'slug' => 'cka-' . uniqid(), 'name' => 'Picker Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 200, 'stock_status' => 'instock',
    ]);

    test()->postJson('/api/cart/add', ['product_id' => $p->id, 'quantity' => 1])->assertOk();
}

/** Added exactly as the cart page's popup adds it: signed out, no account. */
function ckaAddAddress(array $over = []): void
{
    test()->postJson('/cart/address', array_merge([
        'area' => 'Al Quoz Industrial Area 2',
        'apartment' => 'Building 1-10, G-04',
        'city' => 'Dubai',
        'country' => 'AE',
        'tag' => 'home',
    ], $over))->assertOk();
}

/** The value a hidden input would post, read out of the rendered page. */
function ckaHidden(string $html, string $id): ?string
{
    if (! preg_match('/<input[^>]*id="' . preg_quote($id, '/') . '"[^>]*>/', $html, $m)) {
        return null;
    }

    return preg_match('/value="([^"]*)"/', $m[0], $v) ? html_entity_decode($v[1]) : '';
}

it('carries an address added on the cart page through to the checkout', function () {
    /*
     * THE OWNER'S REQUIREMENT, end to end and in their words: an address added
     * on the cart page shows on the checkout, without signing in. It holds
     * because both pages read one state through one set of endpoints — there
     * is no syncing code to test, which is the point of testing it.
     */
    ckaBasket();
    ckaAddAddress();

    $html = test()->get('/checkout/')->assertOk()->getContent();

    expect(ckaHidden($html, 'billing_address_1'))->toBe('Building 1-10, G-04 - Al Quoz Industrial Area 2')
        ->and(ckaHidden($html, 'billing_city'))->toBe('Dubai')
        ->and(ckaHidden($html, 'billing_country'))->toBe('AE');

    // And the shopper can see which address it is.
    expect(str_contains($html, 'Al Quoz Industrial Area 2'))->toBeTrue();
});

it('puts the city in the emirate too, because that is what the shop is priced on', function () {
    ckaBasket();
    ckaAddAddress(['city' => 'Sharjah']);

    $html = test()->get('/checkout/')->getContent();

    expect(ckaHidden($html, 'billing_state'))->toBe('Sharjah',
        'the emirate is empty or wrong. It is required by place() AND it is what '
        .'ShippingService::zoneFor() matches on, so this decides the delivery charge'
    );
    expect(ckaHidden($html, 'billing_state'))->toBe(ckaHidden($html, 'billing_city'));
});

it('places a real order from a picked address', function () {
    /*
     * The one that matters. Everything above is a rehearsal for this: the
     * shopper types no address at all and the order still carries one.
     */
    ckaBasket();
    ckaAddAddress();

    $response = test()->post('/checkout/place', [
        'billing_email' => 'picker@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha Khan',
        'billing_address_1' => 'Building 1-10, G-04 - Al Quoz Industrial Area 2',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ]);

    expect($response->getStatusCode())->toBe(302);

    $order = \App\Models\Order::query()->latest('id')->first();

    expect($order)->not->toBeNull('no order was written');
    /*
     * The order keeps the address as one cast array on `shipping_address`,
     * not as columns. Read the way the shop reads it, so this asserts what a
     * courier is actually given rather than what a column is named.
     */
    $shipping = (array) $order->shipping_address;

    expect($shipping['line1'] ?? null)->toBe('Building 1-10, G-04 - Al Quoz Industrial Area 2')
        ->and($shipping['city'] ?? null)->toBe('Dubai')
        ->and($shipping['state'] ?? null)->toBe('Dubai')
        ->and($shipping['country'] ?? null)->toBe('AE');
});

it('refuses to place an order with no address chosen, and says so rather than breaking', function () {
    /*
     * The empty state posts four empty strings. place() must answer with its
     * own validation errors — a 302 back with errors — and NOT a 500. A
     * shopper who has not picked an address is an ordinary mistake, not a
     * crash.
     */
    ckaBasket();

    $response = test()->post('/checkout/place', [
        'billing_email' => 'noaddr@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha Khan',
        'billing_address_1' => '',
        'billing_city' => '',
        'billing_state' => '',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ]);

    expect($response->getStatusCode())->toBe(302);
    $response->assertSessionHasErrors(['billing_address_1', 'billing_city', 'billing_state']);
    expect(\App\Models\Order::query()->count())->toBe(0, 'an order was written without an address');
});

it('opens the address endpoints whichever layout the cart page is on', function () {
    /*
     * The gate used to be `squeezed()`, because the squeezed cart page was the
     * only thing that opened the sheet. The checkout opens it now and does not
     * care what the CART is set to, so on a shop running the classic cart page
     * every one of these answered 404 and the picker did nothing.
     */
    foreach (['classic', 'squeeze'] as $layout) {
        app(SettingsService::class)->set('cartpage_layout', $layout);
        SettingsService::forgetMemo();

        test()->getJson('/cart/address')->assertOk();

        ckaAddAddress(['city' => 'Ajman']);
    }
});

it('carries an address added at the checkout back to the cart page', function () {
    /*
     * The other direction, which is the half a shopper notices when it is
     * missing. Same endpoint, same session, so it is the same address.
     */
    ckaBasket();
    app(SettingsService::class)->set('cartpage_layout', 'squeeze');
    SettingsService::forgetMemo();

    ckaAddAddress(['city' => 'Fujairah', 'area' => 'Corniche Road']);

    $cart = test()->get('/cart/')->assertOk()->getContent();

    expect(str_contains($cart, 'Corniche Road'))->toBeTrue(
        'an address added at the checkout is not on the cart page'
    );
});

it('gives the checkout the sheet it opens', function () {
    ckaBasket();

    $html = test()->get('/checkout/')->getContent();

    expect(str_contains($html, 'id="cpgSheet"'))->toBeTrue('the checkout has no address sheet to open');
    expect(str_contains($html, 'id="cpgAddrBtn"'))->toBeTrue('nothing on the checkout opens it');
});
