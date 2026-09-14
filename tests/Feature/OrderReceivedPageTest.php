<?php

/**
 * The order-received page — Lane G.
 *
 * The page now carries the line items, the delivery address and the gift
 * message, so two things are pinned here rather than trusted:
 *
 *   - it renders for every shape of order the storefront can produce (guest,
 *     signed in, gift, discount, and a line whose product has since been
 *     deleted);
 *   - it is not a way to read somebody else's order. What stood in success()
 *     before this was `where('order_number', $request->query('order'))` and
 *     nothing else, and order numbers are sequential.
 */

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    /*
     * routes/order-received.php is not required from routes/web.php yet —
     * CLAUDE.md forbids this lane from editing that file, so the integrator
     * wires it up (the route file says exactly where). Mounted here so the
     * endpoint is actually exercised rather than shipped untested.
     */
    Route::middleware('web')->group(base_path('routes/order-received.php'));
});

/** An order with $lines lines, owned by nobody in particular. */
function receivedOrder(array $attributes = [], int $lines = 1): Order
{
    static $seq = 0;
    $seq++;

    $order = Order::create(array_merge([
        'order_number' => 'RCV' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000 * $lines,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => 0,
        'gift_fee' => 0,
        'total' => 20000 * $lines + 2000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'billing_address' => receivedAddress(),
        'shipping_address' => receivedAddress(),
    ], $attributes));

    for ($i = 1; $i <= $lines; $i++) {
        $product = Product::create([
            'slug' => 'rcv-' . $seq . '-' . $i,
            'name' => 'Line Product ' . $i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 200,
            'stock_status' => 'instock',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'name' => 'Line Product ' . $i,
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal' => 20000,
            'total' => 20000,
        ]);
    }

    return $order->fresh('items');
}

function receivedAddress(): array
{
    return [
        'first_name' => 'Aisha', 'last_name' => 'Khan',
        'line1' => '12 Marina Walk', 'city' => 'Dubai',
        'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
    ];
}

/** The browser that just placed $order, as far as the session is concerned. */
function asPlacer(Order $order)
{
    return test()->withSession(['kbb_last_order' => $order->order_number]);
}

function receivedPage(Order $order)
{
    return asPlacer($order)->get('/checkout/success?order=' . $order->order_number);
}

/* ------------------------------------------------------------------ render */

it('renders after a real order placed through the checkout', function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $product = Product::create([
        'slug' => 'real-serum', 'name' => 'Real Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 200, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED',
        'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20000]);

    $response = test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ]);

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull();

    // Follow the redirect the way the browser does: same session, so the
    // kbb_last_order marker place() just wrote is still there.
    $response->assertRedirect();

    $page = test()->get($response->headers->get('Location'));

    $page->assertOk()
        ->assertSee('Thank you')
        ->assertSee('#' . $order->order_number)
        ->assertSee('Real Serum')
        ->assertSee('12 Marina Walk');
});

it('shows every line item, and the discount, delivery and total', function () {
    $order = receivedOrder([
        'discount_total' => 5000,
        'coupon_code' => 'GLOW10',
        'total' => 40000 + 2000 - 5000,
    ], lines: 2);

    $page = receivedPage($order);

    $page->assertOk()
        ->assertSee('Line Product 1')
        ->assertSee('Line Product 2')
        ->assertSee('GLOW10')
        // Subtotal was removed at the owner's request: the line items above
        // already show it, and it pushed the total down the phone screen.
        ->assertDontSee('Subtotal')
        ->assertSee('Delivery')
        ->assertSee('Total');

    // Every total is rendered from the integer fils on the order, so the
    // rendered figures must be exactly what Money produces for them.
    $html = $page->getContent();

    foreach ([$order->subtotal, $order->discount_total, $order->shipping_total, $order->total] as $fils) {
        expect($html)->toContain(\App\Support\Money::amount((int) $fils));
    }
});

it('leaves a short order fully open and collapses a long one', function () {
    $short = receivedOrder(lines: 2);
    receivedPage($short)->assertOk()->assertDontSee('<details', false);

    $long = receivedOrder(lines: 7);
    $page = receivedPage($long);

    // Collapsed, and collapsed WITHOUT script: a <details> the browser opens
    // on its own, so a page whose JavaScript never arrived is still usable.
    $page->assertOk()
        ->assertSee('<details', false)
        ->assertSee('Show 4 more items');

    // Every line is nonetheless in the document, so nothing is lost if the
    // toggle is never touched.
    for ($i = 1; $i <= 7; $i++) {
        $page->assertSee('Line Product ' . $i);
    }
});

it('shows the gift fee and the gift note on a gift order', function () {
    $order = receivedOrder([
        'is_gift' => true,
        'gift_note' => 'Happy birthday Layla',
        'gift_fee' => 1500,
        'fee_total' => 1500,
        'total' => 20000 + 2000 + 1500,
    ]);

    receivedPage($order)->assertOk()
        ->assertSee('Gift wrapping')
        ->assertSee('Happy birthday Layla')
        ->assertSee(\App\Support\Money::amount(1500));
});

it('renders an order whose product has since been deleted', function () {
    $order = receivedOrder();

    // Exactly the two shapes the schema allows: the product row soft-deleted,
    // and the line orphaned outright. The line keeps its own name and price.
    $order->items()->first()->product()->withTrashed()->first()->delete();
    receivedPage($order)->assertOk()->assertSee('Line Product 1');

    $order->items()->first()->forceFill(['product_id' => null])->save();

    receivedPage($order->fresh('items'))->assertOk()
        ->assertSee('Line Product 1')
        ->assertSee(\App\Support\Money::amount(20000));
});

it('sends a signed-in customer to their account rather than a sign-in link', function () {
    $customer = Customer::create(['email' => 'signed@example.com', 'name' => 'Signed In', 'password' => Hash::make('secret123')]);
    $order = receivedOrder(['customer_id' => $customer->id, 'email' => 'signed@example.com']);

    // Signed in: no session marker needed at all, the order is theirs.
    test()->actingAs($customer, 'customer')
        ->get('/checkout/success?order=' . $order->order_number)
        ->assertOk()
        ->assertSee('Your orders')
        ->assertDontSee('Sign in to your account')
        // The finish-your-account offer is for guests only.
        ->assertDontSee('Finish your account');
});

/*
 * A guest who has not chosen a password cannot sign in, so offering the link
 * sends them to a form they cannot complete. They get the password step, and
 * the sign-in card appears the moment they finish it -- driven by the session,
 * never by whether the email already has an account, because that lookup is the
 * enumeration oracle the silent decline exists to avoid.
 */
it('offers a guest the password step, not a sign-in link they cannot use', function () {
    receivedPage(receivedOrder())->assertOk()
        ->assertSee('Finish your account')
        ->assertDontSee('Sign in to your account');
});

it('swaps the password step for the sign-in card once the account is claimed', function () {
    $order = receivedOrder();

    // Both keys together: the access grant the placer's browser carries, plus
    // the marker claim-account leaves behind. Replacing the session with only
    // the second one loses access and renders the not-found panel instead.
    $response = $this->withSession([
        'kbb_last_order' => $order->order_number,
        'kbb_account_done' => true,
    ])->get('/checkout/success?order='.$order->order_number);

    $response->assertOk()
        ->assertSee('Sign in to your account')
        ->assertDontSee('Finish your account');
});

/* ----------------------------------------------------------------- actions */

it('offers sign in, home and track, each pointing at a route that exists', function () {
    $order = receivedOrder();
    $html = receivedPage($order)->assertOk()->getContent();

    expect($html)
        ->toContain('href="' . \App\Support\Url::to('/my-account/') . '"')
        ->toContain('href="' . \App\Support\Url::to('/') . '"')
        ->toContain(\App\Support\Url::to('/track-my-order/') . '?order=' . $order->order_number);

    // "Points somewhere real" means the router serves it, not that the string
    // looks right.
    foreach (['/my-account', '/track-my-order', '/'] as $path) {
        expect(Route::getRoutes()->getRoutesByMethod()['GET'])->toHaveKey(ltrim($path, '/') ?: '/');
    }

    // The track link is the one that carries state, so it is followed for real.
    test()->get('/track-my-order?order=' . $order->order_number)
        ->assertOk()
        ->assertSee('Track my order');
});

/* ------------------------------------------------------------------ access */

it('will not show an order to a browser that did not place it', function () {
    $order = receivedOrder();

    // No session marker, not signed in: the same answer a wholly invented
    // order number gets, so the page cannot be used to probe which numbers
    // exist.
    $stranger = test()->get('/checkout/success?order=' . $order->order_number);

    $stranger->assertOk()
        ->assertSee('Order not found')
        ->assertDontSee('Line Product 1')
        ->assertDontSee('12 Marina Walk');

    test()->get('/checkout/success?order=NO-SUCH-ORDER')
        ->assertOk()
        ->assertSee('Order not found');
});

it('will not show a signed-in customer somebody else order', function () {
    $mine = Customer::create(['email' => 'mine@example.com', 'name' => 'Mine', 'password' => Hash::make('secret123')]);
    $theirs = Customer::create(['email' => 'theirs@example.com', 'name' => 'Theirs', 'password' => Hash::make('secret123')]);

    $order = receivedOrder(['customer_id' => $theirs->id, 'email' => 'theirs@example.com']);

    test()->actingAs($mine, 'customer')
        ->get('/checkout/success?order=' . $order->order_number)
        ->assertOk()
        ->assertSee('Order not found')
        ->assertDontSee('Line Product 1');
});

it('keeps showing the order on a reload, after the purchase pixel is consumed', function () {
    $order = receivedOrder();

    // One session across both requests, exactly like a browser reloading.
    $browser = asPlacer($order);

    $browser->get('/checkout/success?order=' . $order->order_number)->assertOk()->assertSee('Line Product 1');

    // kbb_last_order is spent by the first render (the Purchase event must
    // fire once). The order must still be readable anyway.
    expect(session()->has('kbb_last_order'))->toBeFalse();

    test()->get('/checkout/success?order=' . $order->order_number)
        ->assertOk()
        ->assertSee('Line Product 1');
});

/* ----------------------------------------------------- finish your account */

it('sets a password on the blank account the guest checkout created', function () {
    $customer = Customer::create(['email' => 'blank@example.com', 'name' => 'Blank']);
    $order = receivedOrder(['customer_id' => $customer->id, 'email' => 'blank@example.com']);

    asPlacer($order)->post('/checkout/claim-account', [
        'order' => $order->order_number,
        'account_password' => 'brandnew-password',
    ])->assertRedirect();

    expect(Hash::check('brandnew-password', $customer->fresh()->password))->toBeTrue();
});

it('never overwrites a password that is already set, and says the same thing either way', function () {
    $existing = Customer::create(['email' => 'has@example.com', 'name' => 'Has', 'password' => Hash::make('original-password')]);
    $order = receivedOrder(['customer_id' => $existing->id, 'email' => 'has@example.com']);

    $taken = asPlacer($order)->post('/checkout/claim-account', [
        'order' => $order->order_number,
        'account_password' => 'attacker-password',
    ]);

    expect(Hash::check('original-password', $existing->fresh()->password))->toBeTrue();

    // An imported customer counts as having a password even though `password`
    // is null — legacy_password is a real WordPress hash waiting to be
    // upgraded on first login.
    $legacy = Customer::create(['email' => 'legacy@example.com', 'name' => 'Legacy', 'legacy_password' => '$P$Bsomethingoldandreal']);
    $legacyOrder = receivedOrder(['customer_id' => $legacy->id, 'email' => 'legacy@example.com']);

    $declined = asPlacer($legacyOrder)->post('/checkout/claim-account', [
        'order' => $legacyOrder->order_number,
        'account_password' => 'attacker-password',
    ]);

    expect($legacy->fresh()->password)->toBeNull()
        ->and($legacy->fresh()->legacy_password)->toBe('$P$Bsomethingoldandreal');

    // The decline is silent: same status, same destination, no error. Anything
    // that differed would say whether that email already has an account.
    $blank = Customer::create(['email' => 'blank2@example.com', 'name' => 'Blank']);
    $blankOrder = receivedOrder(['customer_id' => $blank->id, 'email' => 'blank2@example.com']);

    $accepted = asPlacer($blankOrder)->post('/checkout/claim-account', [
        'order' => $blankOrder->order_number,
        'account_password' => 'a-fresh-password',
    ]);

    expect($taken->status())->toBe($accepted->status())
        ->and($declined->status())->toBe($accepted->status());

    $taken->assertSessionHasNoErrors();
    $declined->assertSessionHasNoErrors();
});

it('refuses to set a password on an order the session cannot see', function () {
    $customer = Customer::create(['email' => 'victim@example.com', 'name' => 'Victim']);
    $order = receivedOrder(['customer_id' => $customer->id, 'email' => 'victim@example.com']);

    // No session marker: guessing the order number must not be enough to take
    // the account that came with it.
    test()->post('/checkout/claim-account', [
        'order' => $order->order_number,
        'account_password' => 'attacker-password',
    ])->assertNotFound();

    expect($customer->fresh()->password)->toBeNull();
});

it('escapes a gift note and an address rather than rendering them', function () {
    $order = receivedOrder([
        'is_gift' => true,
        'gift_note' => '<script>alert(1)</script>',
        'gift_fee' => 1500,
        'shipping_address' => array_merge(receivedAddress(), ['line1' => '<img src=x onerror=alert(2)>']),
    ]);

    $html = receivedPage($order)->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x onerror=alert(2)>')
        ->toContain('&lt;script&gt;');
});

/*
 * The account row is one row. It previously had min-width:180px on the field
 * and flex-wrap on the row, so on a phone the button dropped underneath and
 * read as a separate step. Asserted as CSS rather than pixels because that is
 * what actually decides it, and the same class of bug -- a rule that does not
 * match what the markup does -- has already cost this project two releases.
 */
it('keeps the password field and its button on one row', function () {
    $page = file_get_contents(resource_path('views/store/checkout-success.blade.php'));

    expect($page)->toContain('.co-acct .crow{display:flex')
        ->toContain('flex-wrap:nowrap')
        ->toContain('.co-acct input{flex:1 1 auto;min-width:0')
        ->toContain('.co-acct button{flex:0 0 auto;white-space:nowrap');
});

it('names the username before asking for a password', function () {
    $order = receivedOrder();

    $html = receivedPage($order)->assertOk()->getContent();

    // The shopper is told what they will sign in with, rather than left to
    // guess after the fact.
    expect($html)->toContain($order->email.' is your username')
        ->toContain('Set a password to finish');

    // And it is the green confirmation styling, not a warning.
    expect($html)->toContain('class="co-user"');
});
