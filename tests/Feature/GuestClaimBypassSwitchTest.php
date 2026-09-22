<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CheckoutController;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * "TAKE A GUEST'S WORD FOR THEIR EMAIL ADDRESS" — BOTH POSITIONS
 * =============================================================================
 *
 * A customer row with no password is not always the person standing at the
 * till. Guest checkout creates such rows itself, so: Alice orders as a guest;
 * Bob later checks out with Alice's address and "create an account" ticked;
 * Bob's password lands on Alice's row; login gates on the password alone. Bob
 * is Alice, with her order history.
 *
 * The owner weighed it — few customers, addresses not public — and chose to
 * carry the risk for now with a switch to hand, to be looked at properly later.
 * So this file's job is that the switch is REAL in both positions, because a
 * security control that does nothing is worse than none: it is believed.
 *
 * ON (the default, and what this shop has always done): any blank row may be
 * claimed. OFF: only on the shopper's FIRST order.
 *
 * And in BOTH positions the shopper is told nothing. A refusal that announced
 * itself would answer "does this address have an account here" — the question
 * the rest of the checkout spends three tests refusing to answer. That is the
 * assertion that matters most here, because it is the one a future change
 * would break while making the feature "friendlier".
 *
 * MUTATION: make canSetInitialPassword() ignore $firstOrder. Red.
 * MUTATION: default the setting to false. Red — the shop's behaviour changed
 * under everyone without anyone asking for it.
 */
function claimBypass(bool $on): void
{
    app(SettingsService::class)->set('guest_claim_bypass_email', $on ? '1' : '0');
    SettingsService::forgetMemo();
}

/** A row exactly as guest checkout leaves one: real, and with no password. */
function blankCustomer(string $email = 'alice@example.com'): Customer
{
    return Customer::create(['email' => $email, 'name' => 'Alice']);
}

it('takes the guest at their word by default, because that is what this shop has always done', function () {
    /*
     * Read from the schema's own default rather than from a literal here. If
     * somebody flips the shipped default, this is the test that says so — and
     * it says so in the language of the consequence, not of the value.
     */
    expect(CheckoutController::guestClaimBypassesEmail())->toBeTrue(
        'the bypass now ships OFF, which changes how every shop that never touched this setting '
        .'behaves at the till: returning guests stop being able to claim an account'
    );
});

it('lets a stranger claim a row that is not theirs while the bypass is on', function () {
    /*
     * Pinning the HOLE, deliberately. This is the behaviour the owner chose to
     * keep, and a test that only covered the safe position would let the risky
     * one drift without anybody noticing which one they were running.
     */
    claimBypass(true);

    $alice = blankCustomer();

    expect(CheckoutController::canSetInitialPassword($alice, firstOrder: false))->toBeTrue();
});

it('refuses that same claim once the bypass is off', function () {
    claimBypass(false);

    $alice = blankCustomer();

    expect(CheckoutController::canSetInitialPassword($alice, firstOrder: false))->toBeFalse(
        'with the bypass off, a password may still be written onto a row that already has history '
        .'behind it, which is the takeover the switch exists to stop'
    );
});

it('still gives a genuinely new shopper their account with the bypass off', function () {
    /*
     * The half that must NOT break. Turning the switch off is meant to cost a
     * returning guest a trip through Forgot Password, not to stop the shop
     * making accounts at all.
     */
    claimBypass(false);

    expect(CheckoutController::canSetInitialPassword(blankCustomer('new@example.com'), firstOrder: true))
        ->toBeTrue('the bypass being off stopped a first-time shopper getting an account at the till');
});

it('never overwrites a password that is already there, whichever way the switch is set', function () {
    /*
     * The older rule, which the switch must not be able to relax. Both
     * positions, because "the switch is on" must not become a way in.
     */
    $withPassword = Customer::create([
        'email' => 'has-one@example.com', 'name' => 'Has One', 'password' => 'a-real-password',
    ]);

    foreach ([true, false] as $on) {
        claimBypass($on);

        expect(CheckoutController::canSetInitialPassword($withPassword, firstOrder: true))->toBeFalse(
            'a password was overwritten with the bypass ' . ($on ? 'on' : 'off')
        );
    }
});

it('never gives a deleted account a password, whichever way the switch is set', function () {
    $trashed = blankCustomer('gone@example.com');
    $trashed->delete();
    $trashed->refresh();

    foreach ([true, false] as $on) {
        claimBypass($on);

        expect(CheckoutController::canSetInitialPassword($trashed, firstOrder: true))->toBeFalse(
            'a soft-deleted row could be claimed with the bypass ' . ($on ? 'on' : 'off')
        );
    }
});

it('offers the switch on the screen that owns checkout', function () {
    /*
     * A setting the code reads and no screen shows is one the owner cannot
     * use, and they asked for it precisely so they could reach it.
     */
    $src = (string) file_get_contents(base_path('app/Http/Controllers/Admin/EcommerceApiController.php'));

    $checkout = substr($src, (int) strpos($src, "'checkout' => ["));

    expect(str_contains($checkout, "'guest_claim_bypass_email'"))->toBeTrue(
        'the switch is read by the checkout but appears on no admin screen'
    );

    // Declared as a field AND placed in a section — a field in neither is drawn nowhere.
    expect(substr_count($checkout, "'guest_claim_bypass_email'"))->toBeGreaterThanOrEqual(
        2,
        'the switch is declared but sits in no section, so the screen never draws it'
    );
});


/* ======================================================================
   THROUGH THE REAL DOOR
   ======================================================================
   The unit tests above ask canSetInitialPassword() directly, and every one
   of them stayed GREEN when place()'s own call site was mutated to claim
   every order is a first order. That is the whole switch defeated with the
   unit tests none the wiser, so the switch has to be exercised where a
   shopper actually flips it: a real basket, posted to /checkout/place.

   MUTATION: hardcode `true` for the $firstOrder argument in place(). Red.
   ====================================================================== */

function bypassShop(): void
{
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    // COD: it settles inside the request and talks to nobody.
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
}

function bypassCart(): Cart
{
    $product = Product::create([
        'slug' => 'serum-' . uniqid(), 'name' => 'Test Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 200, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);

    return $cart;
}

/** Place one guest order that asks for an account, against $email. */
function bypassPlace(string $email)
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, bypassCart()->token)
        ->post('/checkout/place', [
            'billing_email' => $email,
            'billing_first_name' => 'Bob', 'billing_last_name' => 'Stranger',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai',
            'billing_state' => 'Dubai', 'billing_country' => 'AE',
            'payment_method' => 'cod',
            'create_account' => '1', 'account_password' => 'bobs-chosen-password',
        ]);
}

/** Alice: a guest-created row that already carries one order. */
function bypassAlice(): Customer
{
    bypassShop();

    $alice = Customer::create(['email' => 'alice@example.com', 'name' => 'Alice']);

    bypassPlace('alice@example.com');
    $alice->refresh();

    expect($alice->orders()->count())->toBe(1, 'the setup order did not file under Alice');

    return $alice;
}

it('hands Alice\'s account to a stranger at the till while the bypass is on', function () {
    claimBypass(true);

    $alice = bypassAlice();
    // The setup order already claimed it, which is the hole in one line.
    expect($alice->password)->not->toBeNull();

    $before = $alice->password;
    bypassPlace('ALICE@example.com');

    expect($alice->refresh()->password)->toBe($before, 'the second claim overwrote the first');
});

it('refuses the stranger at the till once the bypass is off', function () {
    claimBypass(false);

    $alice = bypassAlice();

    // The setup order WAS her first, so it may claim; that is the safe half.
    expect($alice->password)->not->toBeNull('a first order stopped being able to claim');

    // Now a second, different shopper on the same address. Alice already has a
    // password, so strip it to model the case the switch is really for: a row
    // left blank by an earlier guest order.
    $alice->forceFill(['password' => null])->save();
    $orders = $alice->orders()->count();

    $response = bypassPlace('alice@example.com');

    $alice->refresh();

    expect($alice->password)->toBeNull(
        'with the bypass off, a stranger still set a password on a row that already had orders behind it'
    );

    // The order is still placed and still filed under her. Refusing the claim
    // must not refuse the sale.
    expect($alice->orders()->count())->toBe($orders + 1, 'the order was lost along with the claim');
    expect($response->getStatusCode())->toBe(302);
});

it('tells the shopper nothing about which of the two just happened', function () {
    /*
     * The assertion that matters most. A refusal that announced itself would
     * answer "does this address have an account here", which is the question
     * the checkout spends three other tests refusing to answer.
     */
    claimBypass(false);
    bypassShop();

    $alice = Customer::create(['email' => 'alice@example.com', 'name' => 'Alice']);
    bypassPlace('alice@example.com');
    $alice->refresh()->forceFill(['password' => null])->save();

    $refused = bypassPlace('alice@example.com');
    $granted = bypassPlace('brand-new@example.com');

    expect($refused->getStatusCode())->toBe($granted->getStatusCode());

    $mask = fn (string $html) => preg_replace(
        ['/KBB-?\d+/', '/order=[\w-]+/', '/[\w.+-]+@[\w.-]+/'],
        ['ORDER', 'order=ORDER', 'EMAIL'],
        $html
    );

    expect($mask($refused->headers->get('Location') ?? ''))
        ->toBe($mask($granted->headers->get('Location') ?? ''));
});

/* ======================================================================
   AND THROUGH THE SECOND DOOR
   ======================================================================
   /checkout/claim-account is the "set a password" panel on the
   order-received page, and it asks the SAME rule. Mutating ITS call site
   to claim every order is a first order left every test above green — two
   doors, one lock, and only one of them was being rattled.

   MUTATION: hardcode `true` for $firstOrder in claimAccount(). Red.
   ====================================================================== */

/** Place an order for $email and return it, with the session grant kept. */
function bypassOrderFor(string $email): Order
{
    test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, bypassCart()->token)
        ->post('/checkout/place', [
            'billing_email' => $email,
            'billing_first_name' => 'Bob', 'billing_last_name' => 'Stranger',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai',
            'billing_state' => 'Dubai', 'billing_country' => 'AE',
            'payment_method' => 'cod',
        ]);

    return Order::query()->latest('id')->firstOrFail();
}

it('refuses the order-received claim panel on a row with history, once the bypass is off', function () {
    claimBypass(false);
    bypassShop();

    $alice = Customer::create(['email' => 'alice@example.com', 'name' => 'Alice']);

    // Her own first order. Then a second one, which is the stranger's.
    bypassOrderFor('alice@example.com');
    $second = bypassOrderFor('alice@example.com');

    $alice->refresh();
    expect($alice->password)->toBeNull('neither placement asked for an account');
    expect($alice->orders()->count())->toBe(2);

    // The grant for $second is in the session from placing it, so the panel is
    // reachable exactly as it is for a real shopper.
    $response = test()->post('/checkout/claim-account', [
        'order' => $second->order_number,
        'account_password' => 'strangers-password',
    ]);

    expect($response->getStatusCode())->toBe(302);
    expect($alice->refresh()->password)->toBeNull(
        'the order-received panel set a password on a row that already had an older order behind it'
    );
});

it('still lets the panel claim an account on a first order with the bypass off', function () {
    claimBypass(false);
    bypassShop();

    $order = bypassOrderFor('first-timer@example.com');

    test()->post('/checkout/claim-account', [
        'order' => $order->order_number,
        'account_password' => 'a-good-password',
    ])->assertStatus(302);

    expect(Customer::where('email', 'first-timer@example.com')->first()?->password)
        ->not->toBeNull('a first-time shopper lost the panel along with the takeover');
});
