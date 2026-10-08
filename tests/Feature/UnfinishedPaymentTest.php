<?php

declare(strict_types=1);

/**
 * THE BASKET IS NEVER EMPTY AFTER A PAYMENT THAT DID NOT FINISH. (Lane BK)
 *
 * The owner's phone: the basket page after a payment he cancelled read
 * "Your Bag (0 items)" / "Your bag is empty" under a pink "Your payment was not
 * completed" notice and a "Put my basket back" button. His words: "the cart
 * should remain same, cart must not be empty in any case, so the user can
 * re-try ... for tabby also and for other payment methods also".
 *
 * CAUSE: CheckoutController::place() marks the basket `converted` before any
 * money moves, and CheckoutReturnController::pending() deliberately wrote
 * nothing on the way back — only the button put the basket back. Nothing at
 * all put it back for the Back button, a closed tab, a timed-out provider, or a
 * 3-D Secure that failed by redirect (that one even drew an order-received page).
 *
 * Every case below drives the real place() and the real return addresses, with
 * the providers faked at the HTTP boundary. The matrix at the end runs EVERY
 * gateway the registry knows through EVERY way a shopper comes back unpaid.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Checkout\UnfinishedPayment;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentConfirmer;
use App\Services\Payments\SettlesBeforeRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\SqlShape;

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

/* ═══════════════════════════════════ fixtures ══════════════════════════════ */

/** Credentials are obvious placeholders, built so nothing resembles a real key. */
function bkGatewayOn(string $id): void
{
    $config = match ($id) {
        'stripe' => [
            'publishable_key' => 'pk_'.'test_bk', 'secret_key' => 'sk_'.'test_bk',
            'webhook_signing_secret' => 'wh'.'sec_bk', 'webhook_secret' => 'bk-url-secret-0123456789',
        ],
        'tabby' => [
            'public_key' => 'pk_'.'test_bk', 'secret_key' => 'sk_'.'test_bk',
            'merchant_code' => 'AE', 'webhook_secret' => 'bk-tabby-url-secret-0123456789',
        ],
        'tamara' => [
            'api_token' => 'bk-tamara-token', 'notification_token' => 'bk-tamara-notify',
            'webhook_secret' => 'bk-tamara-url-secret-0123456789',
        ],
        default => [],
    };

    $row = PaymentProvider::create(['id' => $id, 'title' => ucfirst($id), 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = $config;
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

/**
 * Every provider, faked at the HTTP boundary, answering from one script the
 * test moves: what Stripe, Tabby and Tamara will say about the payment now.
 */
function bkProviders(): object
{
    $s = new class {
        public string $stripe = 'requires_payment_method';
        public string $tabby = 'CREATED';
        public string $tamara = 'new';
        public bool $down = false;
        public array $cancelled = [];
        public int $opened = 0;
    };

    Http::fake(function (Illuminate\Http\Client\Request $request) use ($s) {
        $host = (string) parse_url($request->url(), PHP_URL_HOST);
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $post = $request->method() === 'POST';

        // Opening a payment always works; "down" is the provider failing to
        // answer the shop's question on the way BACK.
        if ($post && $path === '/v1/payment_intents') {
            $id = 'pi_bk_'.(++$s->opened);

            return Http::response(['id' => $id, 'client_secret' => $id.'_secret', 'status' => 'requires_payment_method'], 200);
        }
        if ($post && $path === '/api/v2/checkout') {
            return Http::response([
                'id' => 'sess_bk', 'status' => 'created', 'payment' => ['id' => 'tabby-pay-'.(++$s->opened)],
                'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/bk']]]],
            ], 200);
        }
        if ($post && $path === '/checkout') {
            return Http::response(['order_id' => 'tam-'.(++$s->opened), 'checkout_url' => 'https://checkout.tamara.co/bk'], 200);
        }

        if ($s->down) {
            return Http::response([], 503);
        }

        if (preg_match('#^/v1/payment_intents/(pi_[a-z_0-9]+)/cancel$#', $path, $m)) {
            $s->cancelled[] = $m[1];

            return Http::response(['id' => $m[1], 'status' => 'canceled'], 200);
        }
        if (preg_match('#^/v1/payment_intents/(pi_[a-z_0-9]+)$#', $path, $m)) {
            $order = Order::where('transaction_id', $m[1])->first();
            $status = in_array($m[1], $s->cancelled, true) ? 'canceled' : $s->stripe;

            return Http::response([
                'id' => $m[1], 'status' => $status, 'currency' => 'aed', 'amount' => (int) ($order?->total ?? 0),
                'amount_received' => $status === 'succeeded' ? (int) ($order?->total ?? 0) : 0,
            ], 200);
        }
        if (preg_match('#^/api/v2/payments/([^/]+)$#', $path, $m)) {
            $order = Order::where('transaction_id', $m[1])->first();

            return Http::response([
                'id' => $m[1], 'status' => $s->tabby, 'currency' => 'AED',
                'amount' => number_format(((int) ($order?->total ?? 0)) / 100, 2, '.', ''),
                'order' => ['reference_id' => (string) $order?->order_number], 'captures' => [],
            ], 200);
        }
        if (preg_match('#^/merchants/orders/([^/]+)$#', $path, $m)) {
            $order = Order::where('transaction_id', $m[1])->first();

            return Http::response([
                'order_id' => $m[1], 'order_reference_id' => (string) $order?->order_number, 'status' => $s->tamara,
                'total_amount' => ['amount' => ((int) ($order?->total ?? 0)) / 100, 'currency' => 'AED'],
            ], 200);
        }
        if ($post && preg_match('#^/orders/([^/]+)/authorise$#', $path)) {
            return Http::response(['status' => 'authorised'], 200);
        }

        return Http::response([], 404);
    });

    return $s;
}

function bkProduct(string $name, int $stock = 5): Product
{
    return Product::create([
        'slug' => 'bk-'.Str::slug($name).'-'.Str::random(5), 'name' => $name, 'status' => 'publish',
        'is_visible' => true, 'price' => 10000, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => $stock,
    ]);
}

/** @param list<array{0: Product, 1: int}> $lines */
function bkCart(array $lines, ?Coupon $coupon = null): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(), 'coupon_id' => $coupon?->id,
    ]);

    foreach ($lines as [$product, $quantity]) {
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 10000]);
    }

    return $cart;
}

/** This browser: its own cart cookie. The session persists across requests in a test. */
function bkAs(Cart|string $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart instanceof Cart ? $cart->token : $cart);
}

function bkNext(): void
{
    app()->forgetScopedInstances();
    app()->forgetInstance(CartService::class);

    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
}

/** Place Order, for real, through CheckoutController::place(). */
function bkPlace(Cart $cart, string $gateway, array $extra = []): Order
{
    bkAs($cart)->postJson('/checkout/place', $extra + [
        'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
        'billing_country' => 'AE', 'payment_method' => $gateway,
    ])->assertOk();

    bkNext();

    return Order::latest('id')->firstOrFail();
}

/** The cart line exactly as the basket page draws it (not the drawer's copy). */
function bkLine(Product $product): string
{
    return '<div class="cn"><a href="/product/'.$product->slug.'/">'.$product->name.'</a></div>';
}

/* ═══════════════════ 1. the owner's screenshot, and its fix ════════════════ */

it('gives the basket back on Tabby\'s cancel return, with no button to press', function () {
    /*
     * THE SCREENSHOT. Before: 302 to /cart/, "Your bag is empty", order still
     * `pending` holding the stock and the coupon, and a button.
     *
     * MUTATION: in CheckoutReturnController::pending(), drop the recover() call
     * → the cart stays `converted`, the page says "Your bag is empty" and every
     * assertion below about the basket goes red.
     */
    bkGatewayOn('tabby');
    $tabby = bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $coupon = Coupon::create(['code' => 'BKTEN', 'type' => 'percent', 'amount' => 1000]);
    $cart = bkCart([[$serum, 2]], $coupon);

    $order = bkPlace($cart, 'tabby');

    expect($cart->fresh()->status)->toBe('converted')
        ->and($serum->fresh()->stock)->toBe(3)
        ->and((int) $coupon->fresh()->usage_count)->toBe(1, 'place() did not take the coupon, so this case proves nothing about giving it back');

    $tabby->tabby = 'CREATED'; // the shopper pressed Cancel on Tabby's page

    $back = bkAs($cart)->get('/checkout/pending?order='.$order->order_number);

    // On the return itself, before the basket page is even asked for.
    expect($back->headers->get('Location'))->toEndWith('/cart/?back=restored')
        ->and($cart->fresh()->status)->toBe('active');

    $html = bkAs($cart)->get('/cart/')->assertOk()->getContent();

    expect($cart->fresh()->status)->toBe('active')
        ->and($cart->fresh()->items)->toHaveCount(1)
        ->and((int) $cart->fresh()->items->first()->quantity)->toBe(2)
        ->and((int) $cart->fresh()->coupon_id)->toBe($coupon->id)
        ->and($serum->fresh()->stock)->toBe(5)
        ->and((int) $coupon->fresh()->usage_count)->toBe(0)
        ->and($order->fresh()->status)->toBe('failed')
        ->and($html)->toContain(bkLine($serum))
        ->and($html)->not->toContain('<b>Your bag is empty</b>')
        ->and($html)->toContain('Your payment wasn’t completed — nothing was charged. Your bag is just as you left it.')
        ->and($html)->toContain('class="co-retry" href="/checkout/">Try again</a>')
        ->and($html)->not->toContain('Put my basket back');
});

it('gives the basket back after a 3-D Secure that failed by redirect, instead of a receipt', function () {
    /*
     * Stripe's `return_url` is /checkout/success, with redirect_status appended.
     * Before: an order-received page for an unpaid order over an empty bag.
     *
     * MUTATION: delete the redirect_status block from CheckoutController::
     * success() → 200 receipt page, cart `converted`, intent never cancelled.
     */
    bkGatewayOn('stripe');
    $stripe = bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $cart = bkCart([[$serum, 2]]);

    $order = bkPlace($cart, 'stripe');

    $back = bkAs($cart)->get('/checkout/success?order='.$order->order_number
        .'&payment_intent='.$order->transaction_id.'&redirect_status=failed');

    expect($back->headers->get('Location'))->toEndWith('/cart/?back=restored')
        ->and($stripe->cancelled)->toBe([$order->transaction_id], 'the intent must be closed at Stripe before anything is released')
        ->and($cart->fresh()->status)->toBe('active')
        ->and($serum->fresh()->stock)->toBe(5)
        ->and($order->fresh()->status)->toBe('failed');

    expect(bkAs($cart)->get('/cart/')->getContent())->toContain(bkLine($serum));
});

it('gives the basket back on the checkout itself when the shopper presses Back from the provider', function () {
    /*
     * Back from Tabby's page lands on /checkout/. Before: the empty-basket
     * guard bounced them on to an empty /cart/.
     *
     * MUTATION: remove the due()/takeBackUnfinished() lines from
     * CheckoutController::page() → 302 to /cart/.
     */
    bkGatewayOn('tabby');
    bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $cart = bkCart([[$serum, 2]]);
    $order = bkPlace($cart, 'tabby');

    $page = bkAs($cart)->get('/checkout/');

    $page->assertOk();

    expect($page->getContent())->toContain('Glow Serum')
        ->and($page->getContent())->toContain('class="co-note ok" role="status">Your payment wasn’t completed')
        ->and($cart->fresh()->status)->toBe('active')
        ->and($order->fresh()->status)->toBe('failed');
});

/* ═════════════════════════ 2. paid always wins ═════════════════════════════ */

it('never gives the basket back for an order the provider says is paid', function () {
    /*
     * Tabby sends the shopper to cancel, but Tabby's own record says
     * AUTHORIZED (a double tab, a retry). The payment is applied and the
     * shopper goes to their receipt.
     *
     * MUTATION: make TabbyGateway::settleBeforeRelease() return true always →
     * the guard on the re-read still catches it here, so ALSO drop the
     * refresh() in UnfinishedPayment::recover() → basket released over a paid
     * order. Each alone is caught by this case or the race case below.
     */
    bkGatewayOn('tabby');
    $tabby = bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $cart = bkCart([[$serum, 2]]);
    $order = bkPlace($cart, 'tabby');

    $tabby->tabby = 'AUTHORIZED';

    $back = bkAs($cart)->get('/checkout/pending?order='.$order->order_number);

    expect($back->headers->get('Location'))->toContain('/checkout/success?order='.$order->order_number)
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and($cart->fresh()->status)->toBe('converted')
        ->and($serum->fresh()->stock)->toBe(3);

    // And coming back to the basket later does not undo it either.
    bkNext();
    bkAs($cart)->get('/cart/');
    expect($cart->fresh()->status)->toBe('converted');
});

it('never releases a card payment Stripe has already taken; it records it instead', function () {
    bkGatewayOn('stripe');
    $stripe = bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $cart = bkCart([[$serum, 1]]);
    $order = bkPlace($cart, 'stripe');

    $stripe->stripe = 'succeeded'; // 3-D Secure finished in the background

    bkAs($cart)->get('/cart/')->assertOk();

    expect($stripe->cancelled)->toBe([])
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and($cart->fresh()->status)->toBe('converted')
        ->and($serum->fresh()->stock)->toBe(4);
});

it('lets a webhook that lands mid-release win, writing nothing to the basket or the shelf', function () {
    /*
     * THE RACE. The decision to release was made on an unpaid order; the
     * confirmation lands inside BasketRelease::giveBack()'s transaction, on the
     * locked read moveTo() makes. The move must refuse and the basket must not
     * open.
     *
     * MUTATION: drop `'status' => $decidedOn` and `'paid_at' => null` from
     * giveBack()'s `only:` → the paid order is failed, its units go back on
     * the shelf, and the basket opens.
     */
    bkGatewayOn('tabby');
    $tabby = bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $cart = bkCart([[$serum, 2]]);
    $order = bkPlace($cart, 'tabby');

    $tabby->tabby = 'CREATED';
    $fired = false;

    DB::beforeExecuting(function (string $sql) use (&$fired, $order) {
        if ($fired || DB::transactionLevel() < 2 || ! str_contains(SqlShape::portable($sql), 'from "orders"')) {
            return;
        }
        $fired = true;
        DB::table('orders')->where('id', $order->id)->update(['paid_at' => now(), 'status' => 'processing']);
    });

    $back = bkAs($cart)->get('/checkout/pending?order='.$order->order_number);
    $fired = true;

    expect($order->fresh()->paid_at)->not->toBeNull('the webhook write did not land, so this proves nothing')
        ->and($order->fresh()->status)->toBe('processing')
        ->and($cart->fresh()->status)->toBe('converted')
        ->and($serum->fresh()->stock)->toBe(3)
        ->and($back->headers->get('Location'))->toContain('/checkout/success?order=');
});

/* ═══════════════════════ 3. once, and only for its owner ═══════════════════ */

it('gives a basket back once, however many times the shopper comes back', function () {
    /*
     * MUTATION: in BasketRelease::giveBack(), test cartBelongsTo() on the
     * caller's $basket instead of the locked fresh copy, and pass a stale
     * basket into a second merge → the lines are added twice. Here: two
     * returns, the reload of each, then the old button.
     */
    bkGatewayOn('tamara');
    bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $cart = bkCart([[$serum, 2]]);
    $order = bkPlace($cart, 'tamara');

    bkAs($cart)->get('/checkout/pending?order='.$order->order_number);
    bkNext();
    $again = bkAs($cart)->get('/checkout/pending?order='.$order->order_number);

    /*
     * And the second copy says the SAME thing as the first. The Site App's
     * service worker re-sends a redirected navigation (measured in Chromium:
     * two GETs for one cancel), so this is the ordinary case, not a reload.
     * MUTATION: delete the GIVEN_BACK_KEY branch in recover() → the second
     * copy goes to /checkout/ under "Your payment was not completed".
     */
    expect($again->headers->get('Location'))->toEndWith('/cart/?back=restored')
        ->and(session(UnfinishedPayment::BACK_KEY))->toBe('restored');

    bkNext();
    bkAs($cart)->get('/cart/');
    bkNext();
    bkAs($cart)->post('/checkout/restore-basket');

    expect(Cart::where('status', 'active')->count())->toBe(1)
        ->and($cart->fresh()->items)->toHaveCount(1)
        ->and((int) $cart->fresh()->items->first()->quantity)->toBe(2)
        ->and($serum->fresh()->stock)->toBe(5);
});

it('restores nothing and says nothing different for an order that is not this browser\'s', function () {
    /*
     * A guessed order number: the session's `kbb_last_order` is somebody
     * else's (or nothing). The answer must be byte-identical to a number that
     * does not exist at all.
     *
     * Two independent locks, each tested alone below:
     *   1. the SESSION: a browser holding the victim's basket cookie but not
     *      the session that placed the order gets nothing.
     *      MUTATION, run: in recover(), trust the request's number in place
     *      of `kbb_last_order` (`$mine = $number ?? $mine`, no hash_equals)
     *      → red: the victim's order is failed and their basket opened.
     *   2. the BASKET: the right session but another browser's cookie.
     *      MUTATION, run: drop `whereIn('token', $tokens)` from basketOf()
     *      → red.
     */
    bkGatewayOn('tabby');
    bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $victimCart = bkCart([[$serum, 2]]);
    $victim = bkPlace($victimCart, 'tabby');

    test()->flushSession();
    test()->withSession(['kbb_last_order' => 'NOT-MINE-1']);
    $mine = bkCart([[bkProduct('Other'), 1]]);

    // Even holding the victim's basket cookie.
    $guessed = bkAs($victimCart)->get('/checkout/pending?order='.$victim->order_number);
    $guessedError = session('errors')?->first();
    bkNext();
    $invented = bkAs($victimCart)->get('/checkout/pending?order=NO-SUCH-ORDER');
    $inventedError = session('errors')?->first();

    expect($victim->fresh()->status)->toBe('pending')
        ->and($victimCart->fresh()->status)->toBe('converted')
        ->and($serum->fresh()->stock)->toBe(3)
        ->and($guessed->headers->get('Location'))->toBe($invented->headers->get('Location'))
        ->and($guessedError)->toBe($inventedError);

    // The right number in the session but another browser's basket cookie
    // (the "Complete your order" email opened on another phone): nothing.
    test()->flushSession();
    test()->withSession(['kbb_last_order' => $victim->order_number]);
    bkAs($mine)->get('/cart/');

    expect($victim->fresh()->status)->toBe('pending')
        ->and($victimCart->fresh()->status)->toBe('converted');
});

it('merges into a basket the shopper started since, rather than replacing it, and says so', function () {
    /*
     * They came back from Tabby by another route, added a toner (a new cart,
     * a new cookie), and only then returned through the provider. Both sets of
     * lines end up in the basket they are holding; the serum they already had
     * one of is summed, not duplicated.
     *
     * MUTATION: pass null as $live to giveBack() in recover() → the old basket
     * is re-opened and the new one with the toner is orphaned behind the
     * cookie swap: the toner disappears from their bag.
     */
    bkGatewayOn('tabby');
    bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $toner = bkProduct('Rice Toner', 5);
    $old = bkCart([[$serum, 2]]);
    $order = bkPlace($old, 'tabby');

    $new = bkCart([[$toner, 1], [$serum, 1]]);

    $back = bkAs($new)->get('/checkout/pending?order='.$order->order_number);
    expect($back->headers->get('Location'))->toEndWith('/cart/?back=merged');

    $html = bkAs($new)->get('/cart/')->getContent();
    $lines = $new->fresh()->items->keyBy('product_id');

    expect($old->fresh()->status)->toBe('merged')
        ->and($old->fresh()->items)->toHaveCount(0)
        ->and($lines)->toHaveCount(2)
        ->and((int) $lines[$serum->id]->quantity)->toBe(3)
        ->and((int) $lines[$toner->id]->quantity)->toBe(1)
        ->and($order->fresh()->status)->toBe('failed')
        ->and($html)->toContain(bkLine($serum))
        ->and($html)->toContain(bkLine($toner))
        ->and($html)->toContain('We’ve put those items back in your bag, alongside what you added since.');
});

it('brings the gift-wrap choice back with the basket', function () {
    /*
     * place() clears the `kbb_gift` tick; a shopper sent back to try again
     * should not have to find it twice. MUTATION: delete the kbb_gift line in
     * UnfinishedPayment::recover() → the box comes back unticked.
     */
    bkGatewayOn('tabby');
    bkProviders();
    $cart = bkCart([[bkProduct('Glow Serum'), 1]]);
    $order = bkPlace($cart, 'tabby', ['is_gift' => '1']);

    expect((bool) $order->is_gift)->toBeTrue()
        ->and(session('kbb_gift'))->toBeNull();

    bkAs($cart)->get('/checkout/pending?order='.$order->order_number);

    expect(session('kbb_gift'))->toBeTrue();
});

it('draws the sentence from the address only for the session that was given its basket back', function () {
    /*
     * The outcome rides in ?back= because the Site App's service worker
     * re-sends a redirected navigation and the drawn copy arrives after the
     * flash has aged (measured: basket back, no sentence). A crafted ?back=
     * on anybody else's basket must draw nothing.
     *
     * MUTATION: in UnfinishedPayment::noticeFor(), drop the GIVEN_BACK_KEY
     * comparison → the crafted link draws "nothing was charged" on a stranger's bag.
     */
    $serum = bkProduct('Glow Serum');
    $cart = bkCart([[$serum, 1]]);

    $html = bkAs($cart)->get('/cart/?back=restored')->assertOk()->getContent();

    expect($html)->not->toContain('nothing was charged')
        ->and($html)->toContain(bkLine($serum));

    test()->withSession([UnfinishedPayment::GIVEN_BACK_KEY => ['10001', 'restored']]);

    expect(bkAs($cart)->get('/cart/?back=restored')->getContent())
        ->toContain('Your payment wasn’t completed — nothing was charged. Your bag is just as you left it.');
});

/* ══════════════════════ 4. what it costs, and what it does not ═════════════ */

it('runs no query for this on an ordinary basket or checkout view', function () {
    /*
     * The gate is the session alone. MUTATION: make UnfinishedPayment::due()
     * return true → the basket page asks `orders` for a number that is not
     * there and this goes red.
     */
    $cart = bkCart([[bkProduct('Glow Serum'), 1]]);

    DB::enableQueryLog();
    bkAs($cart)->get('/cart/')->assertOk();
    bkNext();
    bkAs($cart)->get('/checkout/')->assertOk();
    $sql = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => SqlShape::portable($q))->implode("\n");
    DB::disableQueryLog();

    expect($sql)->not->toContain('from "orders"')
        ->and($sql)->not->toContain('"status" = ? and "token" in');

    // And once looked at and found final, it is not asked again.
    test()->withSession(['kbb_last_order' => 'X1', UnfinishedPayment::CHECKED_KEY => 'X1']);
    DB::enableQueryLog();
    bkAs($cart)->get('/cart/')->assertOk();
    $again = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => SqlShape::portable($q))->implode("\n");
    DB::disableQueryLog();

    expect($again)->not->toContain('from "orders"');
});

it('leaves a cash-on-delivery order alone: it is placed, not unfinished', function () {
    bkGatewayOn('cod');
    bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $cart = bkCart([[$serum, 1]]);
    $order = bkPlace($cart, 'cod');

    bkAs($cart)->get('/cart/')->assertOk();

    expect($order->fresh()->status)->toBe('processing')
        ->and($cart->fresh()->status)->toBe('converted')
        ->and($serum->fresh()->stock)->toBe(4);
});

/* ═══════ 5. every gateway the shop has, through every way back unpaid ══════ */

it('knows every gateway the registry has, so a new one cannot be missed', function () {
    /*
     * The matrix below is keyed on these ids. A gateway added to the registry
     * without a row there turns this red; and any that is not a `placed`
     * journey must say how its unfinished payments are settled.
     */
    $ids = app(GatewayRegistry::class)->all()->map->id()->sort()->values()->all();

    expect($ids)->toBe(['cod', 'stripe', 'tabby', 'tamara']);

    foreach (app(GatewayRegistry::class)->all() as $gateway) {
        if ($gateway->journey() !== 'placed') {
            expect($gateway)->toBeInstanceOf(SettlesBeforeRelease::class, $gateway->id().' cannot be asked before release');
        }
    }
});

/**
 * gateway × how the payment failed → [what the provider says, how the shopper
 * comes back, is the basket given back]. COD has no payment step to fail.
 */
dataset('every gateway, every failure', function () {
    $ways = [
        //                provider state      way back             restored
        'cancel' => [['stripe' => 'requires_payment_method', 'tabby' => 'CREATED', 'tamara' => 'new'], 'provider-return', true],
        'decline' => [['stripe' => 'requires_payment_method', 'tabby' => 'REJECTED', 'tamara' => 'declined'], 'provider-return', true],
        'timeout' => [['stripe' => 'requires_action', 'tabby' => 'EXPIRED', 'tamara' => 'expired'], 'later-via-cart', true],
        'provider error' => [['down' => true], 'provider-return', true],
        'back button' => [['stripe' => 'requires_action', 'tabby' => 'CREATED', 'tamara' => 'new'], 'back-to-checkout', true],
        'closed tab' => [['stripe' => 'requires_payment_method', 'tabby' => 'CREATED', 'tamara' => 'new'], 'later-via-cart', true],
        'card bail link' => [['stripe' => 'requires_payment_method'], 'bail', true],
    ];

    $rows = [];

    foreach (['stripe', 'tabby', 'tamara', 'cod'] as $gateway) {
        foreach ($ways as $kind => [$state, $way, $restored]) {
            if ($way === 'bail' && $gateway !== 'stripe') {
                continue;
            }
            $rows["$gateway · $kind"] = [$gateway, $kind, $state, $way, $gateway === 'cod' ? false : $restored];
        }
    }

    return $rows;
});

it('gives the basket back', function (string $gateway, string $kind, array $state, string $way, bool $restored) {
    bkGatewayOn($gateway);
    $script = bkProviders();
    $serum = bkProduct('Glow Serum', 5);
    $coupon = Coupon::create(['code' => 'BK'.Str::random(5), 'type' => 'percent', 'amount' => 1000]);
    $cart = bkCart([[$serum, 2]], $coupon);
    $order = bkPlace($cart, $gateway);

    foreach ($state as $k => $v) {
        $script->{$k} = $v;
    }

    // A timeout reaches the shop as the provider's own failure notice first.
    if ($kind === 'timeout' && in_array($gateway, ['tabby', 'tamara'], true)) {
        app(PaymentConfirmer::class)->fail($order->fresh(), $gateway, (string) $order->transaction_id, 'expired', []);
    }

    $number = $order->order_number;

    match ($way) {
        'provider-return' => $gateway === 'stripe'
            ? bkAs($cart)->get('/checkout/success?order='.$number.'&redirect_status=failed')
            : bkAs($cart)->get('/checkout/pending?order='.$number),
        'later-via-cart' => bkAs($cart)->get('/cart/'),
        'back-to-checkout' => bkAs($cart)->get('/checkout/'),
        'bail' => bkAs($cart)->postJson('/checkout/card/abandon', ['order' => $number]),
    };

    // Stripe that cannot be reached keeps its intent open and so keeps the
    // order: nothing is released until Stripe confirms the intent is closed.
    // The next visit, with Stripe answering, gives the basket back.
    if ($gateway === 'stripe' && $kind === 'provider error') {
        expect($cart->fresh()->status)->toBe('converted', 'released while Stripe could not close the intent');
        $script->down = false;
        bkNext();
    }

    // Restored by the way back itself, not by a later visit to the basket.
    if ($restored && ! ($gateway === 'stripe' && $kind === 'provider error')) {
        expect($cart->fresh()->status)->toBe('active', "$gateway · $kind: not restored on the way back");
    }

    bkNext();
    $html = bkAs($cart)->get('/cart/')->assertOk()->getContent();

    if (! $restored) {
        expect($cart->fresh()->status)->toBe('converted')
            ->and($order->fresh()->status)->not->toBe('failed');

        return;
    }

    expect($cart->fresh()->status)->toBe('active', "$gateway · $kind left the bag empty")
        ->and((int) $cart->fresh()->items->first()->quantity)->toBe(2)
        ->and((int) $cart->fresh()->coupon_id)->toBe($coupon->id)
        ->and($serum->fresh()->stock)->toBe(5)
        ->and((int) $coupon->fresh()->usage_count)->toBe(0)
        ->and($order->fresh()->status)->toBe('failed')
        ->and($html)->toContain(bkLine($serum))
        ->and($html)->not->toContain('<b>Your bag is empty</b>');
})->with('every gateway, every failure');
