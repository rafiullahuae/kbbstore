<?php

/**
 * The account area — Lane M.
 *
 * Four screens that already existed as views and are now actually served:
 * order list, order detail, address book and track-my-order.
 *
 * WHY THESE TESTS SIGN IN THROUGH THE SESSION AND NOT THROUGH actingAs().
 *
 * `actingAs($customer, 'customer')` calls Auth::shouldUse('customer'), which
 * changes the application's DEFAULT guard for the rest of the request. The
 * controller used to read `auth()->guard()` — the default guard, which in
 * config/auth.php is `web`, the admin-side users table. Inside the
 * `auth:customer` middleware group that resolved correctly only because the
 * middleware calls shouldUse() itself; on `/my-account`, which carries no
 * middleware, it resolved to `web` and served the LOGIN FORM to a customer who
 * was already signed in.
 *
 * actingAs() hides exactly that bug, because it performs the same shouldUse()
 * the middleware does. Writing the session key the session guard actually reads
 * is what a browser does, so that is what is done here.
 *
 * What is pinned below, and why each one is here:
 *
 *   - a customer sees their own orders and only their own;
 *   - somebody else's order id is a 404, never a 403 — order numbers here are
 *     sequential, and a 403 confirms the row exists;
 *   - order detail renders when the product behind a line is gone, because
 *     order_items snapshots name and price for precisely that;
 *   - every address action checks ownership, not just the list;
 *   - track-my-order answers identically for a wrong email and an order that
 *     does not exist, and is throttled — without both it is an oracle that
 *     turns a sequential number into a customer list.
 */

use App\Models\Address;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;

/** The session key Illuminate's session guard reads for the `customer` guard. */
function customerSessionKey(): string
{
    return 'login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class);
}

/** Sign in the way a browser does — no shouldUse(), no default-guard change. */
function asCustomer(Customer $customer)
{
    return test()->withSession([customerSessionKey() => $customer->id]);
}

function accountCustomer(string $email = 'shopper@example.com'): Customer
{
    return Customer::create([
        'name' => 'Ada Shopper',
        'email' => $email,
        'password' => 'password123',
    ]);
}

function accountOrder(Customer $customer, array $attributes = []): Order
{
    static $seq = 0;
    $seq++;

    return Order::create(array_merge([
        'order_number' => 'ACC' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'gift_fee' => 0,
        'tax_total' => 0,
        'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'shipping_address' => [
            'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'line1' => '12 Marina Walk', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ],
    ], $attributes));
}

function accountLine(Order $order, array $attributes = []): void
{
    $order->items()->create(array_merge([
        'name' => 'Rice Toner',
        'brand' => 'Beauty of Joseon',
        'quantity' => 2,
        'unit_price' => 10000,
        'subtotal' => 20000,
        'total' => 20000,
    ], $attributes));
}

/* ------------------------------------------------------- the account landing */

it('shows a signed-in customer their dashboard, not the sign-in form', function () {
    $customer = accountCustomer();
    accountOrder($customer);

    // No actingAs(): see the file header. This is the regression the whole
    // account area turned on — a real session on the `customer` guard, and a
    // route with no auth middleware to set the default guard for the controller.
    asCustomer($customer)->get('/my-account')
        ->assertOk()
        ->assertSee('Sign out')
        ->assertSee('Recent orders');
});

it('still shows a guest the sign-in form', function () {
    test()->get('/my-account')->assertOk()->assertDontSee('Sign out');
});

/* ------------------------------------------------------------- order listing */

it('lists a customer their own orders and nobody else\'s', function () {
    $mine = accountCustomer('mine@example.com');
    $theirs = accountCustomer('theirs@example.com');

    $ownA = accountOrder($mine, ['order_number' => 'OWN-A']);
    $ownB = accountOrder($mine, ['order_number' => 'OWN-B']);
    accountOrder($theirs, ['order_number' => 'THEIRS-1']);

    asCustomer($mine)->get('/my-account/orders')
        ->assertOk()
        ->assertSee($ownA->order_number)
        ->assertSee($ownB->order_number)
        ->assertDontSee('THEIRS-1');
});

it('lists orders newest first and pages them', function () {
    $customer = accountCustomer();

    // Twelve orders, created oldest-first, so "newest first" is a real
    // assertion about ordering rather than about insertion order.
    $numbers = [];
    for ($i = 1; $i <= 12; $i++) {
        $numbers[] = accountOrder($customer, ['order_number' => 'PAGE-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)])->order_number;
    }

    $first = asCustomer($customer)->get('/my-account/orders')->assertOk();

    // Newest (PAGE-12) above the tenth-newest (PAGE-03), and the two oldest
    // pushed onto page two.
    $body = $first->getContent();
    expect(strpos($body, 'PAGE-12'))->toBeLessThan(strpos($body, 'PAGE-03'));
    $first->assertSee('PAGE-12')->assertDontSee('PAGE-02')->assertDontSee('PAGE-01');

    asCustomer($customer)->get('/my-account/orders?page=2')
        ->assertOk()
        ->assertSee('PAGE-02')
        ->assertSee('PAGE-01')
        ->assertDontSee('PAGE-12');
});

it('does not list an order the admin has trashed', function () {
    $customer = accountCustomer();
    $live = accountOrder($customer, ['order_number' => 'LIVE-1']);
    $binned = accountOrder($customer, ['order_number' => 'BINNED-1']);
    $binned->delete();

    asCustomer($customer)->get('/my-account/orders')
        ->assertOk()
        ->assertSee($live->order_number)
        ->assertDontSee('BINNED-1');
});

/* -------------------------------------------------------------- order detail */

it('renders a customer their own order in full', function () {
    $customer = accountCustomer();
    $order = accountOrder($customer, ['is_gift' => true, 'gift_note' => 'Happy birthday Mum', 'customer_note' => 'Leave with reception']);

    $product = Product::create([
        'slug' => 'detail-toner', 'name' => 'Rice Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 100, 'stock_status' => 'instock',
        'image' => '/uploads/rice-toner.jpg',
    ]);
    accountLine($order, ['product_id' => $product->id]);

    asCustomer($customer)->get('/my-account/orders/' . $order->id)
        ->assertOk()
        ->assertSee('Order #' . $order->order_number)
        ->assertSee('Rice Toner')
        ->assertSee('Beauty of Joseon')
        ->assertSee('/uploads/rice-toner.jpg')          // the thumbnail
        ->assertSee('12 Marina Walk')                   // the delivery address
        ->assertSee('United Arab Emirates')             // country, not the code
        ->assertSee('Ada Lovelace')                     // the recipient's name
        ->assertSee('Processing')                       // the status
        ->assertSee('Happy birthday Mum')               // the gift note
        ->assertSee('Leave with reception');            // the order note
});

it('renders an order whose product has since been deleted', function () {
    $customer = accountCustomer();
    $order = accountOrder($customer);

    // Two ways a product goes away, and the page has to survive both.
    $trashed = Product::create([
        'slug' => 'gone-soft', 'name' => 'Discontinued Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 100, 'stock_status' => 'instock',
        'image' => '/uploads/gone.jpg',
    ]);
    accountLine($order, ['product_id' => $trashed->id, 'name' => 'Discontinued Essence', 'brand' => 'Some Brand']);
    $trashed->delete();

    // product_id null, the shape a hard delete leaves behind (nullOnDelete).
    accountLine($order, ['product_id' => null, 'name' => 'Vanished Cream', 'brand' => null]);

    asCustomer($customer)->get('/my-account/orders/' . $order->id)
        ->assertOk()
        // The snapshots on order_items are what the page reads, so both lines
        // print their name and their price with no catalogue row behind them.
        ->assertSee('Discontinued Essence')
        ->assertSee('Vanished Cream')
        ->assertDontSee('/uploads/gone.jpg');   // only the thumbnail degrades
});

it('404s an order belonging to another customer, and never 403s it', function () {
    $mine = accountCustomer('mine2@example.com');
    $theirs = accountCustomer('theirs2@example.com');
    $order = accountOrder($theirs);

    $response = asCustomer($mine)->get('/my-account/orders/' . $order->id);

    // 403 would confirm the row exists. Order numbers are sequential, so that
    // is the whole game.
    $response->assertNotFound();
    expect($response->status())->not->toBe(403);
});

it('sends a guest to sign in rather than serving an order', function () {
    $customer = accountCustomer();
    $order = accountOrder($customer);

    test()->get('/my-account/orders/' . $order->id)->assertRedirect();
    test()->get('/my-account/orders')->assertRedirect();
});

it('escapes an address and a gift note rather than rendering them', function () {
    $customer = accountCustomer();
    $order = accountOrder($customer, [
        'is_gift' => true,
        'gift_note' => '<script>alert(1)</script>',
        'shipping_address' => [
            'first_name' => '<img src=x onerror=alert(2)>', 'last_name' => 'Tester',
            'line1' => '1 Test Street', 'city' => 'Dubai', 'country' => 'AE',
        ],
    ]);
    accountLine($order, ['name' => '<b>Bold Serum</b>']);

    $html = asCustomer($customer)->get('/my-account/orders/' . $order->id)->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x onerror=alert(2)>')
        ->not->toContain('<b>Bold Serum</b>')
        ->toContain('&lt;script&gt;');
});

/* --------------------------------------------------------------- addressbook */

function accountAddress(Customer $customer, array $attributes = []): Address
{
    return $customer->addresses()->create(array_merge([
        'type' => 'shipping',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'line1' => '12 Marina Walk',
        'city' => 'Dubai',
        'country' => 'AE',
        'is_default' => false,
    ], $attributes));
}

/** The fields the form posts. */
function addressPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'shipping',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'line1' => '12 Marina Walk',
        'city' => 'Dubai',
        'country' => 'AE',
    ], $overrides);
}

it('lists a customer only their own addresses', function () {
    $mine = accountCustomer('ab1@example.com');
    $theirs = accountCustomer('ab2@example.com');

    accountAddress($mine, ['line1' => '12 Marina Walk']);
    accountAddress($theirs, ['line1' => '99 Somebody Else Road']);

    asCustomer($mine)->get('/my-account/edit-address')
        ->assertOk()
        ->assertSee('12 Marina Walk')
        ->assertDontSee('99 Somebody Else Road');
});

it('adds an address, and makes the first of its type the default', function () {
    $customer = accountCustomer();

    asCustomer($customer)
        ->post('/my-account/addresses', addressPayload(['line1' => '7 New Street']))
        ->assertRedirect();

    $address = $customer->addresses()->firstOrFail();

    expect($address->line1)->toBe('7 New Street')
        ->and($address->customer_id)->toBe($customer->id)
        // A customer with exactly one shipping address and no default reads as
        // a bug at checkout, so the first of a type is promoted regardless.
        ->and($address->is_default)->toBeTrue();
});

it('will not let a posted customer_id file an address under someone else', function () {
    $mine = accountCustomer('ab3@example.com');
    $theirs = accountCustomer('ab4@example.com');

    asCustomer($mine)
        ->post('/my-account/addresses', addressPayload(['customer_id' => $theirs->id]))
        ->assertRedirect();

    expect($theirs->addresses()->count())->toBe(0)
        ->and($mine->addresses()->count())->toBe(1);
});

it('edits an address', function () {
    $customer = accountCustomer();
    $address = accountAddress($customer);

    asCustomer($customer)->get('/my-account/edit-address/' . $address->id)->assertOk();

    asCustomer($customer)
        ->post('/my-account/addresses/' . $address->id, addressPayload(['city' => 'Abu Dhabi']))
        ->assertRedirect();

    expect($address->fresh()->city)->toBe('Abu Dhabi');
});

it('deletes an address and hands the default flag on', function () {
    $customer = accountCustomer();
    $first = accountAddress($customer, ['line1' => 'First Street', 'is_default' => true]);
    $second = accountAddress($customer, ['line1' => 'Second Street']);

    asCustomer($customer)
        ->post('/my-account/addresses/' . $first->id . '/delete')
        ->assertRedirect();

    expect(Address::find($first->id))->toBeNull()
        // Deleting the default must not leave the type with none.
        ->and($second->fresh()->is_default)->toBeTrue();
});

it('sets a default and leaves exactly one per type', function () {
    $customer = accountCustomer();
    $first = accountAddress($customer, ['is_default' => true]);
    $second = accountAddress($customer);

    asCustomer($customer)
        ->post('/my-account/addresses/' . $second->id . '/default')
        ->assertRedirect();

    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse()
        ->and($customer->addresses()->where('type', 'shipping')->where('is_default', true)->count())->toBe(1);
});

it('404s every address action against somebody else\'s address', function () {
    $mine = accountCustomer('ab5@example.com');
    $theirs = accountCustomer('ab6@example.com');
    $address = accountAddress($theirs, ['line1' => 'Not Yours Road', 'is_default' => true]);

    // Ownership is checked on each action, not only on the list — an edit form
    // that 404s while the POST behind it succeeds is no check at all.
    asCustomer($mine)->get('/my-account/edit-address/' . $address->id)->assertNotFound();
    asCustomer($mine)->post('/my-account/addresses/' . $address->id, addressPayload(['city' => 'Hijacked']))->assertNotFound();
    asCustomer($mine)->post('/my-account/addresses/' . $address->id . '/default')->assertNotFound();
    asCustomer($mine)->post('/my-account/addresses/' . $address->id . '/delete')->assertNotFound();

    // And nothing moved.
    $address->refresh();
    expect($address->city)->toBe('Dubai')
        ->and($address->line1)->toBe('Not Yours Road')
        ->and($address->is_default)->toBeTrue();
});

it('sends a guest to sign in rather than to the address book', function () {
    test()->get('/my-account/edit-address')->assertRedirect();
    test()->post('/my-account/addresses', addressPayload())->assertRedirect();
});

/* ------------------------------------------------------------- track my order */

it('shows the order to somebody with both the number and the email', function () {
    $customer = accountCustomer('tracker@example.com');
    $order = accountOrder($customer, ['order_number' => 'TRK-001', 'status' => 'shipped']);

    test()->get('/track-my-order?order=TRK-001&email=tracker@example.com')
        ->assertOk()
        ->assertSee('Order #TRK-001')
        ->assertSee('Shipped');
});

it('accepts the email however the shopper capitalises it', function () {
    $customer = accountCustomer('case@example.com');
    accountOrder($customer, ['order_number' => 'TRK-CASE']);

    test()->get('/track-my-order?order=TRK-CASE&email=CASE@Example.com')
        ->assertOk()
        ->assertSee('Order #TRK-CASE');
});

it('shows nothing for a number on its own', function () {
    $customer = accountCustomer('bare@example.com');
    accountOrder($customer, ['order_number' => 'TRK-BARE', 'total' => 44400]);

    // The order-received page links here with the number only, on purpose: the
    // email is the half that proves identity and a URL gets shared. The number
    // alone must therefore reveal nothing at all.
    test()->get('/track-my-order?order=TRK-BARE')
        ->assertOk()
        ->assertDontSee('Order #TRK-BARE')
        ->assertDontSee('444');
});

it('never puts the email back in the URL through a prefilled field', function () {
    $html = test()->get('/track-my-order?order=TRK-X&email=leak@example.com')->getContent();

    // The order number is prefilled because the confirmation link carries it.
    // The email is not, because a prefilled value would be re-submitted into a
    // shareable URL on every retry.
    expect($html)->toContain('value="TRK-X"')->not->toContain('leak@example.com');
});

it('answers a wrong email and an order that does not exist identically', function () {
    $customer = accountCustomer('same@example.com');
    accountOrder($customer, ['order_number' => 'TRK-REAL']);

    $wrongEmail = test()->get('/track-my-order?order=TRK-REAL&email=guess@example.com');
    $noSuchOrder = test()->get('/track-my-order?order=TRK-FAKE&email=guess@example.com');

    $wrongEmail->assertOk();
    $noSuchOrder->assertOk();

    // The pages differ only where they echo back the number that was typed
    // in. Normalise that away and they are the same bytes — which is the point:
    // a reply that distinguished the two would turn a sequential order number
    // into a lookup oracle.
    expect(str_replace('TRK-REAL', 'TRK-FAKE', $wrongEmail->getContent()))
        ->toBe($noSuchOrder->getContent());
});

it('throttles the track form so the numbers cannot be walked', function () {
    $customer = accountCustomer('walked@example.com');
    accountOrder($customer, ['order_number' => 'TRK-WALK']);

    // Keyed on IP + email, so one attacker cannot spend somebody else's budget.
    $email = 'walker-' . uniqid() . '@example.com';

    for ($i = 1; $i <= 10; $i++) {
        test()->get('/track-my-order?order=TRK-' . $i . '&email=' . urlencode($email))->assertOk();
    }

    test()->get('/track-my-order?order=TRK-11&email=' . urlencode($email))
        ->assertStatus(429)
        ->assertSee('Too many tries');

    // And a throttled attempt performs no lookup at all: the real order stays
    // invisible even when the right number is finally guessed.
    test()->get('/track-my-order?order=TRK-WALK&email=' . urlencode($email))
        ->assertStatus(429)
        ->assertDontSee('Order #TRK-WALK');
});

it('does not hold a successful lookup against the shopper', function () {
    $customer = accountCustomer('clean@example.com');
    accountOrder($customer, ['order_number' => 'TRK-CLEAN']);

    // Nine misses, then their own order: the counter resets, so a shopper who
    // fumbled their number is not locked out of the parcel they did find.
    for ($i = 1; $i <= 9; $i++) {
        test()->get('/track-my-order?order=NOPE-' . $i . '&email=clean@example.com')->assertOk();
    }

    test()->get('/track-my-order?order=TRK-CLEAN&email=clean@example.com')
        ->assertOk()
        ->assertSee('Order #TRK-CLEAN');

    test()->get('/track-my-order?order=NOPE-10&email=clean@example.com')->assertOk();
});

it('does not show a trashed order to the track form', function () {
    $customer = accountCustomer('binned@example.com');
    $order = accountOrder($customer, ['order_number' => 'TRK-BIN']);
    $order->delete();

    test()->get('/track-my-order?order=TRK-BIN&email=binned@example.com')
        ->assertOk()
        ->assertDontSee('Order #TRK-BIN');
});
