<?php

/**
 * A guest checks out with an email address that already belongs to a customer.
 *
 * TWO THINGS AT ONCE, and they pull in opposite directions.
 *
 * The order must be FILED under that customer, so it turns up in their history
 * the next time they sign in. That is the owner's request and it is what
 * `orders.customer_id` is for — Customer::orders() is a plain hasMany on it,
 * so writing the column is the whole of "it shows up in their account".
 *
 * And the guest must learn NOTHING. Anybody can type anybody's address into a
 * checkout form. So: no sign-in, no other orders, no saved anything, and above
 * all no answer to "does this address have an account here?" — not in the
 * status, not in the body. This project has already fixed one enumeration
 * oracle (Api\QuizController::expertRequest, where a forged token and an id
 * that was never issued were made to do the same work and return the same
 * 404); the same rule is what the last group of tests here pins.
 *
 * The three placement tests at the top were each a real 500 at Place Order
 * before Store\CheckoutController::customerForGuestOrder() existed. Its header
 * sets out what each one was.
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

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

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

    // COD, because it settles inside the request and talks to nobody.
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);
});

function guestEmailCart(): Cart
{
    $product = Product::create([
        'slug' => 'serum-' . uniqid(),
        'name' => 'Test Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 200,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);

    return $cart;
}

function guestEmailForm(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'guest@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ], $overrides);
}

/** @see CheckoutPlacementTest::asShopper() for why EncryptCookies is off. */
function guestEmailPlace(Cart $cart, array $form)
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', $form);
}

/** The same placement, asked for as JSON — the card form's door. */
function guestEmailPlaceJson(Cart $cart, array $form)
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson('/checkout/place', $form);
}

/* ------------------------------------------------ filed under the account */

it('files a guest order under the customer who already owns that email', function () {
    $customer = Customer::create([
        'email' => 'repeat@example.com',
        'name' => 'Real Person',
        'password' => 'a-real-password',
    ]);

    guestEmailPlace(guestEmailCart(), guestEmailForm(['billing_email' => 'repeat@example.com']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $order = Order::latest('id')->first();

    expect($order->customer_id)->toBe($customer->id)
        // Which is the same thing as saying it is in their history: the
        // account screens read exactly this relation.
        ->and($customer->orders()->pluck('id')->all())->toBe([$order->id]);
});

it('places the order when the matching customer has been soft-deleted', function () {
    // Customer soft-deletes, `customers.email` is a plain UNIQUE index that
    // knows nothing about deleted_at. The lookup could not see the row and the
    // INSERT could not get past it: HTTP 500, no order, address permanently
    // un-checkout-able.
    $customer = Customer::create(['email' => 'gone@example.com', 'name' => 'Gone']);
    $customer->delete();

    guestEmailPlace(guestEmailCart(), guestEmailForm(['billing_email' => 'gone@example.com']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $order = Order::latest('id')->first();

    expect($order)->not->toBeNull()
        ->and($order->customer_id)->toBe($customer->id)
        // Filed, not resurrected. An admin deleted this customer and an
        // unauthenticated form does not get to undo that.
        ->and(Customer::withTrashed()->find($customer->id)->trashed())->toBeTrue();
});

it('does not open a second account when the stored email differs only by case', function () {
    // The imported WooCommerce customers carry whatever case they typed. The
    // lookup lowercased, the stored value never was: on SQLite this wrote a
    // duplicate row and split the person's history in two; on MySQL's
    // case-insensitive collation the INSERT collided with the row the SELECT
    // had just missed, and 500'd.
    $customer = Customer::create(['email' => 'MiXeD@Example.com', 'name' => 'Mixed Case']);

    guestEmailPlace(guestEmailCart(), guestEmailForm(['billing_email' => 'mixed@example.com']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Customer::withTrashed()->count())->toBe(1)
        ->and(Order::latest('id')->first()->customer_id)->toBe($customer->id);
});

it('files the order under the older row when the table already holds a duplicate pair', function () {
    // The tie-break in customerForGuestOrder(). It only has work to do where
    // two rows can fold to the same address, which is the state SQLite was
    // left in by the case bug above — MySQL's case-insensitive unique index
    // will not hold such a pair, so the pair cannot be built there to test.
    if (Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('Only SQLite will hold two rows whose emails differ by case.');
    }

    $older = Customer::create(['email' => 'split@example.com', 'name' => 'First Row']);
    $newer = Customer::create(['email' => 'Split@Example.com', 'name' => 'Second Row']);

    guestEmailPlace(guestEmailCart(), guestEmailForm(['billing_email' => 'SPLIT@example.com']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Deterministic, and deterministically the same one every time: a shop
    // whose history is already split in two must not go on alternating
    // between the halves with each new order.
    expect(Order::latest('id')->first()->customer_id)->toBe($older->id)
        ->and($newer->orders()->count())->toBe(0);
});

it('files the order under the winner when two checkouts race for a new address', function () {
    // Two guests type the same never-seen address at the same moment: both
    // lookups miss, both INSERT, and the loser used to take the unique index
    // full in the face — a 500 at Place Order for a shopper who did nothing
    // wrong. Same shape as the order-number race that moved
    // nextOrderNumber() out of the transaction.
    //
    // The other request is played by a `creating` hook that writes the row
    // from underneath this one, which is exactly the interleaving that made
    // it fail. The dispatcher is cloned and put back so this listener cannot
    // leak into the rest of the suite.
    $original = Customer::getEventDispatcher();
    Customer::setEventDispatcher(clone $original);

    $rival = null;

    Customer::creating(function (Customer $customer) use (&$rival) {
        if ($rival !== null || $customer->email !== 'contested@example.com') {
            return;
        }

        $rival = Customer::withoutEvents(fn () => Customer::create([
            'email' => 'contested@example.com',
            'name' => 'Whoever Got There First',
        ]));
    });

    try {
        guestEmailPlace(guestEmailCart(), guestEmailForm(['billing_email' => 'contested@example.com']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    } finally {
        Customer::setEventDispatcher($original);
    }

    expect($rival)->not->toBeNull()
        ->and(Customer::withTrashed()->where('email', 'contested@example.com')->count())->toBe(1)
        ->and(Order::latest('id')->first()->customer_id)->toBe($rival->id);
});

it('still opens an account for an address nobody has used', function () {
    guestEmailPlace(guestEmailCart(), guestEmailForm(['billing_email' => 'Brand.New@Example.com']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $customer = Customer::firstWhere('email', 'brand.new@example.com');

    expect($customer)->not->toBeNull()
        ->and(Order::latest('id')->first()->customer_id)->toBe($customer->id);
});

/* ------------------------------------------- and told nothing in exchange */

it('does not sign the guest in as the customer whose email they typed', function () {
    Customer::create([
        'email' => 'repeat@example.com',
        'name' => 'Real Person',
        'password' => 'a-real-password',
    ]);

    guestEmailPlace(guestEmailCart(), guestEmailForm(['billing_email' => 'repeat@example.com']));

    test()->assertGuest('customer');
});

it('leaves every column of the existing customer row alone but the order link', function () {
    $customer = Customer::create([
        'email' => 'repeat@example.com',
        'name' => 'Real Person',
        'first_name' => 'Real',
        'last_name' => 'Person',
        'phone' => '+971500000001',
        'password' => 'a-real-password',
        'whatsapp_optin' => false,
    ]);

    $before = $customer->only(['name', 'first_name', 'last_name', 'phone', 'password', 'whatsapp_optin']);

    // Every field the form can carry is different, create_account is asked
    // for, and marketing consent is ticked. None of it is theirs to give.
    guestEmailPlace(guestEmailCart(), guestEmailForm([
        'billing_email' => 'repeat@example.com',
        'billing_first_name' => 'Imposter',
        'billing_last_name' => 'Smith',
        'billing_phone' => '+971509999999',
        'billing_kbb_whatsapp' => '1',
        'create_account' => '1',
        'account_password' => 'stolen-password',
    ]))->assertRedirect();

    expect($customer->fresh()->only(array_keys($before)))->toBe($before);
});

it('will not put a password on a soft-deleted customer row', function () {
    // customerForGuestOrder() has to be able to return a trashed row or the
    // address cannot check out at all, which makes a deleted account with a
    // blank password the one row a stranger could otherwise claim.
    $customer = Customer::create(['email' => 'gone@example.com', 'name' => 'Gone']);
    $customer->delete();

    guestEmailPlace(guestEmailCart(), guestEmailForm([
        'billing_email' => 'gone@example.com',
        'create_account' => '1',
        'account_password' => 'stolen-password',
    ]))->assertRedirect();

    expect(Customer::withTrashed()->find($customer->id)->password)->toBeNull();
});

it('shows the guest the order they just placed and none of the account\'s others', function () {
    $customer = Customer::create([
        'email' => 'repeat@example.com',
        'name' => 'Real Person',
        'password' => 'a-real-password',
    ]);

    // An older order of theirs, which this browser has no business seeing.
    $theirs = Order::create([
        'order_number' => 'KBB-OLD-1',
        'customer_id' => $customer->id,
        'email' => 'repeat@example.com',
        'status' => 'completed',
        'currency' => 'AED',
        'subtotal' => 50000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'fee_total' => 0,
        'tax_total' => 0,
        'total' => 50000,
    ]);

    $cart = guestEmailCart();

    $shopper = test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    $shopper->post('/checkout/place', guestEmailForm(['billing_email' => 'repeat@example.com']))
        ->assertRedirect();

    $mine = Order::latest('id')->first();

    // Their own order: reached through the handle this shop already uses —
    // the order number the placement put in the session, which mayView()
    // converts into a durable grant. Not a scheme invented here.
    $shopper->get('/checkout/success?order=' . $mine->order_number)
        ->assertOk()
        ->assertSee($mine->order_number);

    // The customer's other order, through the same door: the "we could not
    // find that order" panel, which is what a made-up number gets too.
    $shopper->get('/checkout/success?order=' . $theirs->order_number)
        ->assertOk()
        ->assertDontSee($theirs->order_number);

    // And the account itself stays shut.
    $shopper->get('/my-account/orders/')->assertRedirect();
});

/* ------------------------------------------------------ the enumeration line */

/**
 * The oracle test.
 *
 * Every placement below is the same basket, the same form and the same
 * gateway; the ONLY thing that differs is whether the address already belongs
 * to somebody. If any pair disagrees on status or body, the checkout answers
 * "that address has an account here" to anyone who cares to ask, which is a
 * list of the shop's customers available to a stranger with a cart.
 *
 * The order number is masked because it is allowed to differ — it is minted
 * per order and says nothing about the email. Everything else must match to
 * the byte.
 */
function guestEmailFingerprint(string $email, array $extra = []): array
{
    $response = guestEmailPlaceJson(guestEmailCart(), guestEmailForm(array_merge(['billing_email' => $email], $extra)));

    $number = (string) (Order::latest('id')->first()?->order_number ?? '');

    $body = $response->getContent();

    if ($number !== '') {
        $body = str_replace([$number, rawurlencode($number), urlencode($number)], '<ORDER>', $body);
    }

    return ['status' => $response->getStatusCode(), 'body' => $body];
}

it('answers a guest identically whether or not the email already has an account', function () {
    Customer::create([
        'email' => 'known@example.com',
        'name' => 'Known Person',
        'password' => 'a-real-password',
    ]);

    // A registered account, a guest-created row with no password, a row an
    // admin has deleted, a row stored in a different case — and an address
    // that has never been seen here. All five must be indistinguishable.
    Customer::create(['email' => 'passwordless@example.com', 'name' => 'No Password']);
    Customer::create(['email' => 'deleted@example.com', 'name' => 'Deleted'])->delete();
    Customer::create(['email' => 'CaSeD@Example.com', 'name' => 'Cased']);

    $unknown = guestEmailFingerprint('nobody@example.com');

    expect(guestEmailFingerprint('known@example.com'))->toBe($unknown)
        ->and(guestEmailFingerprint('passwordless@example.com'))->toBe($unknown)
        ->and(guestEmailFingerprint('deleted@example.com'))->toBe($unknown)
        ->and(guestEmailFingerprint('cased@example.com'))->toBe($unknown);
});

it('answers identically when the guest also asks for an account', function () {
    // The sharper form of the same question. "Create an account for me" is
    // where a checkout is most tempted to say "you already have one" — and
    // where canSetInitialPassword() silently declines instead.
    Customer::create([
        'email' => 'known@example.com',
        'name' => 'Known Person',
        'password' => 'a-real-password',
    ]);
    Customer::create(['email' => 'deleted@example.com', 'name' => 'Deleted'])->delete();

    $ask = ['create_account' => '1', 'account_password' => 'chosen-password'];

    $unknown = guestEmailFingerprint('nobody@example.com', $ask);

    expect(guestEmailFingerprint('known@example.com', $ask))->toBe($unknown)
        ->and(guestEmailFingerprint('deleted@example.com', $ask))->toBe($unknown);
});

it('shows the same order-received page whether or not the email had an account', function () {
    // The page AFTER the placement is the other half of the same oracle: a
    // "set a password" panel that appeared only for new addresses would say
    // exactly what the placement refused to.
    Customer::create([
        'email' => 'known@example.com',
        'name' => 'Known Person',
        'password' => 'a-real-password',
    ]);

    $page = function (string $email): string {
        $cart = guestEmailCart();

        $shopper = test()
            ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
            ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

        $shopper->post('/checkout/place', guestEmailForm(['billing_email' => $email]));

        $order = Order::latest('id')->first();

        $html = $shopper->get('/checkout/success?order=' . $order->order_number)->getContent();

        // The order number, the date and the address are this order's own and
        // are allowed to differ; the email is echoed back on the page and is
        // the visitor's own input. What must not differ is the structure.
        return str_replace(
            [$order->order_number, $email, e($email)],
            ['<ORDER>', '<EMAIL>', '<EMAIL>'],
            $html,
        );
    };

    expect($page('known@example.com'))->toBe($page('nobody@example.com'));
});
