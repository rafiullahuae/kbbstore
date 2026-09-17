<?php

/**
 * "the checkout page should read and input auto the customer details, if the
 * customer is already login" — Lane FY.
 *
 * The page has had a $prefill array since 2.60.41. What it did not have was an
 * answer for the shop's own data: WooCommerce has no addresses table, so
 * Import\AddressWriter writes a `billing` row and a `shipping` row only where
 * the export carried each, and most imported customers have billing alone —
 * against which Customer::defaultAddress(), which asks for shipping and stops,
 * returned null and the checkout filled in nothing at all.
 *
 * The other half of this file is the rule that governs every value in that
 * array: AN EMPTY BOX BEATS A WRONG GUESS. A prefilled field is a field that
 * gets skipped, so anything put in one has to be something the account actually
 * holds — which displayName(), falling back to the email address, is not.
 */

use App\Models\Cart;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Tests\Support\EnglishRenderWalk;

beforeEach(function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);
});

function prefillCart(): Cart
{
    $product = Product::create([
        'slug' => 'cream-' . uniqid(),
        'name' => 'Test Cream',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 150,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create(['token' => bin2hex(random_bytes(16)), 'status' => 'active', 'currency' => 'AED']);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 15000]);

    return $cart->fresh('items');
}

function prefillBrowser(Cart $cart, ?Customer $customer = null)
{
    $browser = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    // Through the session, the way a browser is signed in, rather than
    // actingAs() — see the header of AccountAreaTest for what actingAs() hides
    // on a page that carries no auth middleware, which /checkout/ does not.
    return $customer === null
        ? $browser
        : $browser->withSession([EnglishRenderWalk::customerSessionKey() => $customer->id]);
}

/** The `value="…"` one named input on the checkout was rendered with. */
function prefillValueOf(string $html, string $name): ?string
{
    if (! preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $tag)) {
        return null;
    }

    return preg_match('/value="([^"]*)"/', $tag[0], $value) ? html_entity_decode($value[1]) : null;
}

it('fills the checkout in from the account the shopper is signed into', function () {
    $customer = Customer::create([
        'email' => 'aisha@example.com',
        'name' => 'Aisha Khan',
        'first_name' => 'Aisha',
        'last_name' => 'Khan',
        'phone' => '0501234567',
        'password' => 'secret-secret',
    ]);

    $customer->addresses()->create([
        'type' => 'shipping',
        'is_default' => true,
        'line1' => '12 Marina Walk',
        'city' => 'Dubai Marina',
        'state' => 'Dubai',
        'country' => 'AE',
    ]);

    $html = prefillBrowser(prefillCart(), $customer)->get('/checkout')->assertOk()->getContent();

    expect(prefillValueOf($html, 'billing_email'))->toBe('aisha@example.com');
    expect(prefillValueOf($html, 'billing_phone'))->toBe('0501234567');
    expect(prefillValueOf($html, 'billing_first_name'))->toBe('Aisha Khan');
    expect(prefillValueOf($html, 'billing_address_1'))->toBe('12 Marina Walk');
    expect(prefillValueOf($html, 'billing_city'))->toBe('Dubai Marina');
    expect(prefillValueOf($html, 'billing_state'))->toBe('Dubai');
});

it('reads a billing address when that is the only one the customer has', function () {
    /*
     * THE CASE MOST OF THIS SHOP'S CUSTOMERS ARE IN.
     *
     * WooCommerce keeps billing and shipping as loose usermeta, and
     * Import\AddressWriter skips whichever of the two came through empty — "an
     * address with nothing in it is not an address". A shop that delivers to
     * the billing address never asks for a second one, so the imported customer
     * has exactly one row and its type is `billing`. Asked only for `shipping`,
     * the checkout filled in nothing for them and the owner's complaint was
     * about a feature that already existed.
     */
    $customer = Customer::create(['email' => 'imported@example.com', 'name' => 'Imported Shopper', 'password' => 'secret-secret']);

    $customer->addresses()->create([
        'type' => 'billing',
        'is_default' => true,
        'line1' => '8 Al Wasl Road',
        'city' => 'Jumeirah',
        'state' => 'Dubai',
        'country' => 'AE',
    ]);

    $html = prefillBrowser(prefillCart(), $customer)->get('/checkout')->assertOk()->getContent();

    expect(prefillValueOf($html, 'billing_address_1'))->toBe('8 Al Wasl Road');
    expect(prefillValueOf($html, 'billing_city'))->toBe('Jumeirah');
});

it('prefers the shipping address when the customer has both', function () {
    $customer = Customer::create(['email' => 'both@example.com', 'name' => 'Both Shopper', 'password' => 'secret-secret']);

    $customer->addresses()->create([
        'type' => 'billing', 'is_default' => true,
        'line1' => 'The billing one', 'city' => 'Sharjah', 'state' => 'Sharjah', 'country' => 'AE',
    ]);
    $customer->addresses()->create([
        'type' => 'shipping', 'is_default' => true,
        'line1' => 'The shipping one', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE',
    ]);

    $html = prefillBrowser(prefillCart(), $customer)->get('/checkout')->assertOk()->getContent();

    // Step 2 of this page is headed "Shipping address", and that is what it is.
    expect(prefillValueOf($html, 'billing_address_1'))->toBe('The shipping one');
});

it('takes the phone number off the saved address when the account has none', function () {
    $customer = Customer::create(['email' => 'nophone@example.com', 'name' => 'No Phone', 'password' => 'secret-secret']);

    $customer->addresses()->create([
        'type' => 'shipping', 'is_default' => true,
        'line1' => '3 Beach Road', 'city' => 'Abu Dhabi', 'state' => 'Abu Dhabi', 'country' => 'AE',
        'phone' => '0559876543',
    ]);

    $html = prefillBrowser(prefillCart(), $customer)->get('/checkout')->assertOk()->getContent();

    // The same fact, read from the other place this shop writes it down. Not a
    // guess: it is the number they gave when they saved the address.
    expect(prefillValueOf($html, 'billing_phone'))->toBe('0559876543');
});

it('leaves the name box empty rather than putting an email address in it', function () {
    /*
     * displayName() ends `?: $this->email`, which is right for "who is this" on
     * an order screen and quite wrong for a box labelled Full name. An imported
     * customer with no name would have found buyer@example.com sitting in it —
     * and a shopper who did not look would have had it printed on the parcel.
     */
    $customer = Customer::create(['email' => 'nameless@example.com', 'password' => 'secret-secret']);

    $html = prefillBrowser(prefillCart(), $customer)->get('/checkout')->assertOk()->getContent();

    expect(prefillValueOf($html, 'billing_first_name'))->toBe('');
    expect(prefillValueOf($html, 'billing_email'))->toBe('nameless@example.com');
});

it('leaves every address box empty for a customer with no saved address', function () {
    $customer = Customer::create(['email' => 'new@example.com', 'name' => 'Newcomer', 'password' => 'secret-secret']);

    $html = prefillBrowser(prefillCart(), $customer)->get('/checkout')->assertOk()->getContent();

    expect(prefillValueOf($html, 'billing_first_name'))->toBe('Newcomer');
    expect(prefillValueOf($html, 'billing_address_1'))->toBe('');
    expect(prefillValueOf($html, 'billing_city'))->toBe('');
    expect(prefillValueOf($html, 'billing_state'))->toBe('');
});

it('fills in nothing at all for a guest', function () {
    $html = prefillBrowser(prefillCart())->get('/checkout')->assertOk()->getContent();

    foreach (['billing_email', 'billing_phone', 'billing_first_name', 'billing_address_1', 'billing_city', 'billing_state'] as $field) {
        expect(prefillValueOf($html, $field))->toBe('');
    }
});

it('lets the shopper change a prefilled field, and uses what they changed', function () {
    $customer = Customer::create([
        'email' => 'aisha@example.com', 'name' => 'Aisha Khan', 'phone' => '0501234567', 'password' => 'secret-secret',
    ]);

    $customer->addresses()->create([
        'type' => 'shipping', 'is_default' => true,
        'line1' => '12 Marina Walk', 'city' => 'Dubai Marina', 'state' => 'Dubai', 'country' => 'AE',
    ]);

    $cart = prefillCart();

    /*
     * A placement that is refused — no payment method — comes back to this page
     * with what was typed flashed as old input. That is the only path on which
     * the account and the shopper can disagree about a field, and the shopper
     * has to win. A checkout that quietly restored the saved address over a
     * correction would be the same shape of defect as an order shipped to the
     * address a declined card was first placed against, which 2.60.215 fixed
     * and which this lane was told not to reintroduce.
     */
    prefillBrowser($cart, $customer)
        ->post('/checkout/place', [
            'billing_email' => 'aisha@example.com',
            'billing_first_name' => 'Aisha Khan',
            'billing_address_1' => '99 Corrected Road',
            'billing_city' => 'Abu Dhabi',
            'billing_state' => 'Abu Dhabi',
            'billing_country' => 'AE',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('payment_method');

    /*
     * The flash is seeded rather than carried over, because this suite runs on
     * SESSION_DRIVER=array (phpunit.xml): nothing written by one request in a
     * test is read by the next, which is why every signed-in test here seeds the
     * guard's key the same way. `_old_input` is the key Laravel's own old()
     * reads, so what is asserted below is the template's precedence and not a
     * fixture.
     */
    $html = prefillBrowser($cart, $customer)
        ->withSession(['_old_input' => [
            'billing_address_1' => '99 Corrected Road',
            'billing_city' => 'Abu Dhabi',
        ]])
        ->get('/checkout')
        ->assertOk()
        ->getContent();

    expect(prefillValueOf($html, 'billing_address_1'))->toBe('99 Corrected Road');
    expect(prefillValueOf($html, 'billing_city'))->toBe('Abu Dhabi');
    // And the account's own value is not sitting in the box underneath it.
    expect($html)->not->toContain('value="12 Marina Walk"');
});
