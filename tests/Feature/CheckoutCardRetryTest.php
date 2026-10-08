<?php

/**
 * "With demo card details, the order is not going through. It gives empty cart
 * error on normal and 3D secure card too." — the owner, 8 October, testing
 * Stripe in TEST mode with 4242 4242 4242 4242 and 4000 0025 0000 3155.
 * (Lane CO)
 *
 * WHAT HE SAW, IN ORDER. The checkout's Payment step, card chosen:
 *   1. "fwee - Lip&Cheek Glowy Jelly Pot - Compote is sold out. Please remove
 *      it from your basket to continue." — red text under the card fields;
 *   2. then, on every press with either test card, "Your bag is empty." —
 *      over a page that still listed his items. No order went through.
 *
 * THE CAUSE. place() marks the basket `converted` BEFORE it asks Stripe to
 * open the payment. When Stripe refused (the intent request failed), place()
 * failed the order and returned the sentence — and left the basket
 * `converted`. CartService only ever finds an `active` basket, so the very
 * next press found none: "Your bag is empty.", on every card, for good. Two
 * more doors led to the same converted-and-abandoned basket: placing-overlay
 * also answered card presses that stripe-elements turned away (stopPropagation
 * does not stop a listener on the same node), and a release still in flight
 * when Place order was pressed again.
 *
 * Every Stripe answer here is Http::fake(), as in CheckoutCardFormTest. The
 * card itself — 4242 / 3155 — is Stripe.js in the browser; what these pin is
 * every request the shop's server takes part in.
 *
 * ONE REQUEST, ONE PROCESS. On the host each request is its own PHP process;
 * the test client reuses one booted app, and both CartService's per-request
 * memo AND the controller instance the router caches would carry the first
 * request's basket into the second. ccrNextRequest() drops both, so a second
 * POST here sees exactly what a second POST on the shop sees. Without it the
 * owner's sequence passes for a reason that is not true — measured: the repro
 * of the bug went green with the bug still in place.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function ccrStripeOn(): void
{
    $row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = [
        'publishable_key' => 'pk_test_kbb_co', 'secret_key' => 'sk_test_kbb_co',
        'webhook_signing_secret' => 'whsec_co', 'webhook_secret' => 'whsec-url-co-0123456789ab',
    ];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function ccrProduct(string $name, array $attributes = []): Product
{
    return Product::create(array_merge([
        'slug' => 'co-' . uniqid(), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 100, 'stock_status' => 'instock',
    ], $attributes));
}

/** @param list<array{0: Product, 1: int}> $lines */
function ccrCart(array $lines): Cart
{
    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    foreach ($lines as [$product, $quantity]) {
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 10000]);
    }

    return $cart;
}

/** The browser: this basket's cookie, same-origin fetch (see formShopper()). */
function ccrShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** A fresh request, as on the host: no basket memo, no cached controller. */
function ccrNextRequest(): void
{
    app()->forgetScopedInstances();

    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
}

function ccrFields(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
        'billing_country' => 'AE', 'payment_method' => 'stripe',
    ], $overrides);
}

/**
 * Stripe, scripted: $script->open is what POST /v1/payment_intents answers
 * ('ok' or 'refuse'), and $script->status is what a read of an intent reports.
 * Every call is counted, so a second intent or a cancel can be asserted on.
 */
function ccrStripe(): object
{
    $script = new class {
        public string $open = 'ok';
        public string $status = 'requires_payment_method';
        public int $opened = 0;
        public array $cancelled = [];
    };

    Http::fake(function (Illuminate\Http\Client\Request $request) use ($script) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/v1/payment_intents') {
            if ($script->open === 'refuse') {
                return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']], 401);
            }

            $script->opened++;
            $id = 'pi_co_' . $script->opened;

            return Http::response(['id' => $id, 'client_secret' => $id . '_secret', 'status' => 'requires_payment_method'], 200);
        }

        if (preg_match('#^/v1/payment_intents/(pi_[a-z_0-9]+)/cancel$#', $path, $m)) {
            $script->cancelled[] = $m[1];

            return Http::response(['id' => $m[1], 'status' => 'canceled'], 200);
        }

        if (preg_match('#^/v1/payment_intents/(pi_[a-z_0-9]+)$#', $path, $m)) {
            $order = Order::where('transaction_id', $m[1])->first();
            $status = in_array($m[1], $script->cancelled, true) ? 'canceled' : $script->status;

            return Http::response([
                'id' => $m[1], 'status' => $status, 'currency' => 'aed',
                'amount' => (int) ($order?->total ?? 0), 'amount_received' => $status === 'succeeded' ? (int) ($order?->total ?? 0) : 0,
            ], 200);
        }

        return Http::response([], 404);
    });

    return $script;
}

/* =====================================================================
 | The cause
 ===================================================================== */

it('keeps the basket when Stripe refuses to open the payment, so the retry goes through', function () {
    /*
     * THE OWNER'S "YOUR BAG IS EMPTY", reproduced. Stripe refuses the intent
     * (here a 401, as a wrong test key gives; any refusal takes this branch),
     * the shopper reads why, presses Place order again — and before this fix
     * was told "Your bag is empty." over a page listing their items, on this
     * and every later press, whatever card they typed.
     *
     * MUTATION: in place()'s `! $start->ok()` branch, put back the bare
     * OrderStatus::moveTo(... 'failed') in place of BasketRelease::
     * failAndRestore() → the second POST answers 422 "Your bag is empty.".
     */
    ccrStripeOn();
    $stripe = ccrStripe();
    $serum = ccrProduct('Glow Serum', ['manage_stock' => true, 'stock' => 5]);
    $cart = ccrCart([[$serum, 1]]);

    $stripe->open = 'refuse';
    ccrShopper($cart)->postJson('/checkout/place', ccrFields())
        ->assertStatus(422)
        ->assertJsonPath('error', 'We could not reach our card processor. Please try another payment method.');

    $first = Order::latest('id')->first();

    expect($first->status)->toBe('failed')
        ->and($cart->fresh()->status)->toBe('active', 'a payment that never started took the basket with it')
        ->and($serum->fresh()->stock)->toBe(5, 'the refused attempt kept the unit off the shelf');

    ccrNextRequest();
    $stripe->open = 'ok';

    ccrShopper($cart)->postJson('/checkout/place', ccrFields())
        ->assertOk()
        ->assertJsonPath('action', 'confirm')
        ->assertJsonPath('client_secret', 'pi_co_1_secret');

    expect(Order::where('status', 'pending')->count())->toBe(1)
        ->and($serum->fresh()->stock)->toBe(4, 'one order, one unit');
});

it('runs the owner\'s sequence: sold out, remove it, then the card goes through', function () {
    /*
     * The whole report, end to end: a sold-out line refuses Place order and
     * opens the dialog; "Remove and continue" takes that line (and only that
     * line) out; the next press fails at Stripe once; the press after that
     * reaches the card confirmation instead of "Your bag is empty.".
     */
    ccrStripeOn();
    $stripe = ccrStripe();
    $serum = ccrProduct('Glow Serum', ['manage_stock' => true, 'stock' => 5]);
    $jelly = ccrProduct('fwee - Lip&Cheek Glowy Jelly Pot - Compote', ['stock_status' => 'outofstock']);
    $cart = ccrCart([[$serum, 1], [$jelly, 1]]);
    $jellyLine = $cart->items()->where('product_id', $jelly->id)->value('id');
    $serumLine = $cart->items()->where('product_id', $serum->id)->value('id');

    $refused = ccrShopper($cart)->postJson('/checkout/place', ccrFields());

    $refused->assertStatus(422)
        ->assertJsonPath('code', 'sold_out')
        ->assertJsonPath('error', 'fwee - Lip&Cheek Glowy Jelly Pot - Compote is sold out. Please remove it from your basket to continue.')
        ->assertJsonPath('lines.0.id', $jellyLine)
        ->assertJsonPath('lines.0.keep', 0)
        ->assertJsonPath('lines.0.text', 'fwee - Lip&Cheek Glowy Jelly Pot - Compote is sold out')
        ->assertJsonPath('dialog.remove', 'Remove and continue')
        ->assertJsonPath('dialog.cart', 'Go back to cart')
        ->assertJsonPath('dialog.url', '/checkout/sold-out');

    expect($refused->json('lines'))->toHaveCount(1, 'an in-stock line was listed as sold out')
        ->and(Order::count())->toBe(0)
        ->and($serum->fresh()->stock)->toBe(5, 'asking which lines are sold out took stock off the shelf');

    ccrNextRequest();

    // The dialog posts what it listed. The in-stock line is posted too, as a
    // stale or hand-made request would, and must be left alone.
    $removed = ccrShopper($cart)->postJson('/checkout/sold-out', [
        'item_ids' => [$jellyLine, $serumLine], 'country' => 'AE', 'payment_method' => 'stripe',
    ]);

    $removed->assertOk()->assertJsonPath('empty', false)->assertJsonPath('changed', [$jellyLine]);
    expect($removed->json('orderHtml'))->toBeString()
        ->and($removed->json('paymentHtml'))->toBeString()
        ->and($cart->items()->pluck('id')->all())->toBe([$serumLine]);

    ccrNextRequest();
    $stripe->open = 'refuse';
    ccrShopper($cart)->postJson('/checkout/place', ccrFields())->assertStatus(422);

    ccrNextRequest();
    $stripe->open = 'ok';
    $paying = ccrShopper($cart)->postJson('/checkout/place', ccrFields());

    $paying->assertOk()->assertJsonPath('action', 'confirm');

    $order = Order::where('order_number', $paying->json('order'))->first();

    expect($order->items()->pluck('product_id')->all())->toBe([$serum->id])
        ->and(Order::where('status', 'pending')->count())->toBe(1);
});

it('places again over a card attempt this browser left open, without a second live order', function () {
    /*
     * The other doors to the same "Your bag is empty.": the card form lost the
     * first attempt's handle (a release still in flight when Place order was
     * pressed, a release that failed, or placing-overlay posting a card order
     * stripe-elements had turned away). The basket is converted into an order
     * that is still `pending` with a live intent, and nothing on the page
     * knows it.
     *
     * The press releases that attempt the way "return to your basket" does —
     * the intent cancelled at Stripe FIRST, then the stock and the basket back
     * — and places a fresh order from the fields as posted.
     *
     * MUTATION: delete the resumeCardBasket() call in place() → 422 "Your bag
     * is empty." and the first order is left holding the stock.
     */
    ccrStripeOn();
    $stripe = ccrStripe();
    $serum = ccrProduct('Glow Serum', ['manage_stock' => true, 'stock' => 5]);
    $cart = ccrCart([[$serum, 2]]);

    $first = ccrShopper($cart)->postJson('/checkout/place', ccrFields())->assertOk();

    expect($cart->fresh()->status)->toBe('converted')
        ->and($serum->fresh()->stock)->toBe(3);

    ccrNextRequest();

    $second = ccrShopper($cart)->postJson('/checkout/place', ccrFields(['billing_address_1' => '14 Marina Walk']));

    $second->assertOk()->assertJsonPath('action', 'confirm')->assertJsonPath('client_secret', 'pi_co_2_secret');

    $old = Order::where('order_number', $first->json('order'))->first();
    $new = Order::where('order_number', $second->json('order'))->first();

    expect($stripe->cancelled)->toBe(['pi_co_1'], 'the first intent was left confirmable')
        ->and($old->status)->toBe('failed')
        ->and($new->status)->toBe('pending')
        ->and($new->billing_address['line1'] ?? null)->toBe('14 Marina Walk', 'the retry did not use the corrected fields')
        ->and(Order::where('status', 'pending')->count())->toBe(1, 'two live orders out of one basket')
        ->and($serum->fresh()->stock)->toBe(3, 'the stock was claimed twice, or not at all')
        ->and($cart->fresh()->converted_order_id)->toBe($new->id);
});

it('does not release an attempt whose money has already moved', function () {
    /*
     * A 3-D Secure window that finished in the background: Stripe says the
     * intent succeeded. Nothing is cancelled, released or re-opened — and the
     * shopper is not told "Your bag is empty." either: they are told the
     * basket has been ordered and given the order.
     *
     * MUTATION: in resumeCardBasket(), skip the abandonIntent() answer → the
     * paid-for order is failed and its units go back on the shelf.
     */
    ccrStripeOn();
    $stripe = ccrStripe();
    $serum = ccrProduct('Glow Serum', ['manage_stock' => true, 'stock' => 5]);
    $cart = ccrCart([[$serum, 1]]);

    $first = ccrShopper($cart)->postJson('/checkout/place', ccrFields())->assertOk();
    $number = $first->json('order');

    ccrNextRequest();
    $stripe->status = 'succeeded';

    ccrShopper($cart)->postJson('/checkout/place', ccrFields())
        ->assertStatus(422)
        ->assertJsonPath('code', 'bag_gone')
        ->assertJsonPath('error', 'This basket has already been ordered (order ' . $number . ').')
        ->assertJsonPath('url', '/checkout/success?order=' . $number);

    // (Lane BK) Stripe's `succeeded` is APPLIED on the spot now (paid wins,
    // UnfinishedPayment via StripeGateway::settleBeforeRelease()), rather than
    // left `pending` for the webhook. Still nothing cancelled or released.
    expect(Order::where('order_number', $number)->value('status'))->toBe('processing')
        ->and($cart->fresh()->status)->toBe('converted')
        ->and($stripe->cancelled)->toBe([])
        ->and($serum->fresh()->stock)->toBe(4)
        ->and(Order::count())->toBe(1);
});

it('says where a basket went when it is genuinely gone, instead of "Your bag is empty."', function () {
    /*
     * No basket and no attempt from this browser to resume: the honest answer,
     * with the way to the basket, rather than a sentence that contradicts the
     * page.
     */
    ccrStripeOn();
    ccrStripe();
    $cart = ccrCart([[ccrProduct('Glow Serum'), 1]]);
    $cart->forceFill(['status' => 'converted', 'converted_at' => now()])->save();

    ccrShopper($cart)->postJson('/checkout/place', ccrFields())
        ->assertStatus(422)
        ->assertJsonPath('code', 'bag_gone')
        ->assertJsonPath('url', '/cart/')
        ->assertJsonPath('link', 'View your basket');
});

it('leaves the second attempt\'s basket alone when a late abandon of the first lands', function () {
    /*
     * The release the card form sends when a field changes after a decline
     * can arrive AFTER the next press has already placed a second order out of
     * the same basket. Re-opening the cart then hands back a basket that is
     * being paid for again — a second order, a second claim.
     *
     * MUTATION: make BasketRelease::cartBelongsTo() return true → the cart is
     * `active` under a pending order.
     */
    ccrStripeOn();
    ccrStripe();
    $serum = ccrProduct('Glow Serum', ['manage_stock' => true, 'stock' => 5]);
    $cart = ccrCart([[$serum, 1]]);

    $first = ccrShopper($cart)->postJson('/checkout/place', ccrFields())->assertOk();
    ccrNextRequest();
    $second = ccrShopper($cart)->postJson('/checkout/place', ccrFields())->assertOk();
    ccrNextRequest();

    // The abandon request read its session before the second press wrote it.
    ccrShopper($cart)
        ->withSession(['kbb_last_order' => $first->json('order')])
        ->postJson('/checkout/card/abandon', ['order' => $first->json('order')]);

    expect($cart->fresh()->status)->toBe('converted', 'a basket under a live order was re-opened')
        ->and(Order::where('order_number', $second->json('order'))->value('status'))->toBe('pending')
        ->and($serum->fresh()->stock)->toBe(4);
});

it('keeps the basket through a declined card and its retry', function () {
    /*
     * A decline is the browser's: Stripe.js answers confirmCardPayment() with
     * an error and the same intent takes the next card — no request reaches
     * the shop. What reaches it is the release when the shopper corrects a
     * field, and the next press after that.
     */
    ccrStripeOn();
    $stripe = ccrStripe();
    $cart = ccrCart([[ccrProduct('Glow Serum'), 1]]);

    $first = ccrShopper($cart)->postJson('/checkout/place', ccrFields())->assertOk();
    ccrNextRequest();

    ccrShopper($cart)->postJson('/checkout/card/abandon', ['order' => $first->json('order')])
        ->assertOk()->assertJsonPath('ok', true);

    expect($cart->fresh()->status)->toBe('active');

    ccrNextRequest();
    ccrShopper($cart)->postJson('/checkout/place', ccrFields())->assertOk()->assertJsonPath('client_secret', 'pi_co_2_secret');

    expect($stripe->cancelled)->toBe(['pi_co_1'])
        ->and(Order::where('status', 'pending')->count())->toBe(1);
});

it('takes the 3-D Secure path to a paid order', function () {
    /*
     * 4000 0025 0000 3155: the intent goes to requires_action, the bank's
     * challenge runs in Stripe's modal, and only then is it succeeded. The
     * report made while it is still in the bank's hands is not believed; the
     * one after is.
     */
    ccrStripeOn();
    $stripe = ccrStripe();
    $cart = ccrCart([[ccrProduct('Glow Serum'), 1]]);

    $placed = ccrShopper($cart)->postJson('/checkout/place', ccrFields())->assertOk();
    $order = Order::where('order_number', $placed->json('order'))->first();

    ccrNextRequest();
    $stripe->status = 'requires_action';
    ccrShopper($cart)->postJson('/checkout/card/paid', ['order' => $order->order_number]);
    expect($order->fresh()->paid_at)->toBeNull('a payment still with the bank was marked paid')
        ->and($order->fresh()->status)->toBe('pending');

    ccrNextRequest();
    $stripe->status = 'succeeded';
    ccrShopper($cart)->postJson('/checkout/card/paid', ['order' => $order->order_number])->assertOk();

    expect($order->fresh()->paid_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe('processing')
        ->and($cart->fresh()->status)->toBe('converted');
});

it('gives the basket back to a card order posted without JavaScript', function () {
    /*
     * The no-script branch fails the order and tells the shopper to switch
     * JavaScript on and try again — and "again" has to find the basket.
     *
     * MUTATION: put the bare moveTo() back in that branch → `converted`.
     */
    ccrStripeOn();
    ccrStripe();
    $cart = ccrCart([[ccrProduct('Glow Serum'), 1]]);

    ccrShopper($cart)->post('/checkout/place', ccrFields())->assertRedirect();

    expect(Order::latest('id')->value('status'))->toBe('failed')
        ->and($cart->fresh()->status)->toBe('active');
});

/* =====================================================================
 | The dialog's endpoint
 ===================================================================== */

it('only ever touches this browser\'s own sold-out lines', function () {
    /*
     * The ids come from the page and are trusted for nothing. Someone else's
     * sold-out line, posted here, is not theirs to remove; an in-stock line of
     * this basket is not sold out; only a line of THIS basket that a fresh
     * probe still refuses moves.
     *
     * MUTATION: drop the unavailableLines() filter and remove every posted id
     * that is in this cart → the in-stock serum goes too.
     */
    $serum = ccrProduct('Glow Serum');
    $gone = ccrProduct('Gone Toner', ['stock_status' => 'outofstock']);
    $mine = ccrCart([[$serum, 1], [$gone, 1]]);
    $theirs = ccrCart([[$gone, 1]]);
    $theirLine = $theirs->items()->value('id');
    $mySerum = $mine->items()->where('product_id', $serum->id)->value('id');
    $myGone = $mine->items()->where('product_id', $gone->id)->value('id');

    ccrShopper($mine)->postJson('/checkout/sold-out', ['item_ids' => [$theirLine, $mySerum]])
        ->assertOk()->assertJsonPath('changed', []);

    expect($theirs->items()->count())->toBe(1, 'a line of somebody else\'s basket was removed')
        ->and($mine->items()->count())->toBe(2, 'an in-stock line was removed');

    ccrNextRequest();
    ccrShopper($mine)->postJson('/checkout/sold-out', ['item_ids' => [$myGone]])
        ->assertOk()->assertJsonPath('changed', [$myGone]);

    expect($mine->items()->pluck('id')->all())->toBe([$mySerum]);
});

it('cuts a line to what is left rather than removing it', function () {
    $serum = ccrProduct('Glow Serum', ['manage_stock' => true, 'stock' => 2]);
    $cart = ccrCart([[$serum, 3]]);
    $line = $cart->items()->value('id');

    ccrStripeOn();
    ccrStripe();
    ccrShopper($cart)->postJson('/checkout/place', ccrFields())
        ->assertStatus(422)
        ->assertJsonPath('lines.0.keep', 2)
        ->assertJsonPath('lines.0.text', 'Only 2 left of Glow Serum — your bag will keep 2');

    ccrNextRequest();
    ccrShopper($cart)->postJson('/checkout/sold-out', ['item_ids' => [$line]])->assertOk();

    expect($cart->items()->value('quantity'))->toBe(2)
        ->and($serum->fresh()->stock)->toBe(2);
});

it('says so when removing leaves the bag empty', function () {
    $cart = ccrCart([[ccrProduct('Gone Toner', ['stock_status' => 'outofstock']), 1]]);

    ccrShopper($cart)->postJson('/checkout/sold-out', ['item_ids' => [$cart->items()->value('id')]])
        ->assertOk()
        ->assertJsonPath('empty', true)
        ->assertJsonPath('message', 'Your bag is now empty.')
        ->assertJsonPath('redirect', '/shop/');
});

it('is a web route behind the session and CSRF, and refuses a browser with no basket', function () {
    $route = app('router')->getRoutes()->getByName('checkout.soldOutRemove');

    expect($route)->not->toBeNull('POST /checkout/sold-out is not registered')
        ->and($route->gatherMiddleware())->toContain('web')
        ->and($route->methods())->toBe(['POST']);

    // Not exempted from CSRF anywhere.
    expect(file_get_contents(base_path('bootstrap/app.php')))->not->toContain('sold-out');

    test()->postJson('/checkout/sold-out', ['item_ids' => [1]])
        ->assertStatus(422)->assertJsonPath('code', 'bag_gone');
});

/* =====================================================================
 | The other payment methods
 ===================================================================== */

it('opens the same dialog for cash on delivery, and the form post still says it in words', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $gone = ccrProduct('Gone Toner', ['stock_status' => 'outofstock']);
    $cart = ccrCart([[ccrProduct('Glow Serum'), 1], [$gone, 1]]);

    ccrShopper($cart)->postJson('/checkout/place', ccrFields(['payment_method' => 'cod']))
        ->assertStatus(422)->assertJsonPath('code', 'sold_out')->assertJsonPath('lines.0.text', 'Gone Toner is sold out');

    ccrNextRequest();
    ccrShopper($cart)->post('/checkout/place', ccrFields(['payment_method' => 'cod']))->assertRedirect();
    expect(session('errors')->first())->toBe('Gone Toner is sold out. Please remove it from your basket to continue.');

    ccrNextRequest();
    $cart->items()->where('product_id', $gone->id)->delete();
    $placed = ccrShopper($cart)->postJson('/checkout/place', ccrFields(['payment_method' => 'cod']));

    $placed->assertOk()->assertJsonPath('action', 'placed');
    expect(Order::latest('id')->value('status'))->toBe('processing')
        ->and($cart->fresh()->status)->toBe('converted');
});

/* =====================================================================
 | The page's half
 ===================================================================== */

it('claims a card press so the overlay cannot also place the order', function () {
    /*
     * placing-overlay listens for [data-place] on `document` in the capture
     * phase, exactly as stripe-elements does, and stopPropagation() does not
     * stop a second listener on the SAME node. So every press stripe-elements
     * turned away without raising the overlay — the card form still loading,
     * a payment already in progress, Enter in a field mid-payment — fell
     * through to the overlay, which posted the form, got `action: confirm`
     * back for a card order it cannot confirm, said "failed", and left the
     * basket converted: the next press said "Your bag is empty.".
     *
     * MUTATION: put event.stopPropagation() back in either handler → red.
     */
    $src = file_get_contents(resource_path('views/partials/checkout/stripe-elements.blade.php'));

    $click = substr($src, strpos($src, "if (!event.target.closest('[data-place]')) return;"), 1200);
    $submit = substr($src, strpos($src, "FORM.addEventListener('submit', function (event) {"), 300);

    foreach (['click' => $click, 'submit' => $submit] as $name => $handler) {
        $body = substr($handler, 0, strpos($handler, 'pay();'));

        expect(str_contains($body, 'event.stopImmediatePropagation();'))->toBeTrue("the {$name} handler lets the overlay run too")
            ->and(str_contains($body, 'event.stopPropagation();'))->toBeFalse("the {$name} handler only stops propagation");
    }
});

it('waits for a release still in flight before it places the next order', function () {
    /*
     * A field corrected after a decline releases the first attempt; that
     * request used to be fire-and-forget, so a press of Place order straight
     * after it raced it and found the basket still converted. pay() now
     * awaits it before posting.
     *
     * MUTATION: drop `releasing = ` from the change listener, or the await in
     * pay() → red.
     */
    $src = file_get_contents(resource_path('views/partials/checkout/stripe-elements.blade.php'));
    $pay = substr($src, strpos($src, 'async function pay() {'));

    $await = strpos($pay, 'await releasing;');

    expect($await)->not->toBeFalse('pay() does not wait for the release at all')
        ->and($await)->toBeLessThan(strpos($pay, 'post(PLACE_URL'))
        ->and($src)->toContain('releasing = post(ABANDON_URL, { order: order })');
});

it('ships the dialog in the built checkout bundle', function () {
    /*
     * The dialog lives in resources/js/kbb/checkout.js, and the shop runs the
     * BUILT bundle: a source change with a stale public/build is a fix that
     * never reaches a shopper. MUTATION: rebuild from the previous commit's
     * checkout.js → red.
     */
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $entry = $manifest['resources/js/kbb/app.js']['file'] ?? null;

    expect($entry)->not->toBeNull();

    $bundle = file_get_contents(public_path('build/' . $entry));

    expect($bundle)->toContain('kbbSoldOut')->and($bundle)->toContain('refused');
});
