<?php

declare(strict_types=1);

/**
 * Apple Pay and Google Pay — the whole of what this lane built (Lane WAL).
 *
 * ── THE DEFECT, STATED ONCE ────────────────────────────────────────────────
 *
 * This shop advertised Apple Pay and Google Pay in six places and could take
 * neither. `GatewayRegistry::CLASSES` knew four gateways — cod, tabby, tamara,
 * stripe — and `app/Services/Payments/Gateways/` held no wallet file of any
 * kind. The checkout carried two buttons, `aria-hidden="true"` and
 * `tabindex="-1"`, with no listener behind them; the footer, the basket and
 * the product page each printed the words "Apple Pay" unconditionally; and the
 * cart's trust row and the slim footer drew the marks off `pay_apple` and
 * `pay_google` switches that gated nothing real. It was a picture of a feature.
 *
 * ── WHAT REPLACED IT, AND WHY THERE IS NO NEW GATEWAY ──────────────────────
 *
 * Apple Pay and Google Pay are CARD wallets. What Stripe returns from either
 * sheet is a PaymentMethod of `type: card` carrying `card.wallet.type`, charged
 * against the same PaymentIntent a typed card is charged against. So the
 * wallets ride StripeGateway exactly as it stands — `payment_method_types:
 * ['card']` already admits both and is deliberately unchanged — and every order
 * they place is an ordinary `stripe` order that the existing capturer,
 * refunder, voider, ledger and reconciler handle without knowing a wallet was
 * involved.
 *
 * ── WHAT THESE TESTS CAN AND CANNOT PROVE ──────────────────────────────────
 *
 * Every Stripe host is unreachable from CI and from this sandbox, so
 * everything here runs against Http::fake() the way StripeCardFieldsTest and
 * CheckoutCardFormTest do. What that proves is the shape of what we send, the
 * shape of what we do with what comes back, what the page contains, and every
 * branch between. What it CANNOT prove is that an Apple device draws a sheet:
 * that needs the owner's domain registered with Apple through Stripe and a real
 * iPhone. docs/WALLETS-APPLE-GOOGLE-PAY.md says exactly which claims are
 * waiting on it.
 */

use App\Http\Controllers\Store\AppleDomainController;
use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\Wallets;
use App\Services\Security\ContentSecurityPolicy;
use App\Support\PaymentChips;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\WalletRoutes;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();

    WalletRoutes::wire(app());

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

/**
 * The card gateway, keyed and switched on, with the two wallet switches set.
 *
 * @param  array<string, mixed>  $extra  anything else to put in the config blob
 */
function walStripe(bool $apple = true, bool $google = true, bool $enabled = true, array $extra = []): void
{
    $row = PaymentProvider::firstOrNew(['id' => 'stripe']);

    $row->fill([
        'title' => 'Credit or debit card',
        'enabled' => $enabled,
        'mode' => 'test',
        'position' => 0,
    ]);

    $row->config = array_merge([
        'publishable_key' => 'pk_test_wallet_key',
        'secret_key' => 'sk_test_wallet_key',
        'webhook_signing_secret' => 'whsec_wallet_signing',
        'webhook_secret' => 'whsec-url-wallet-0123456789ab',
        'wallet_apple_pay' => $apple ? '1' : '',
        'wallet_google_pay' => $google ? '1' : '',
    ], $extra);

    $row->save();

    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();
}

function walCart(int $unitPriceFils = 20000): Cart
{
    $product = Product::create([
        'slug' => 'wal-serum-' . uniqid(),
        'name' => 'Wallet Test Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $unitPriceFils / 100,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

/** A browser carrying this basket's cookie — see CheckoutCardFormTest. */
function walShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function walFields(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'stripe',
    ], $overrides);
}

/* =====================================================================
 | 1. The intent — unchanged, and that is the finding
 ===================================================================== */

it('opens the wallet payment on the same card intent, with payment_method_types still card', function () {
    /*
     * THE ONE DESIGN DECISION THIS ROUND TURNED ON.
     *
     * The obvious edit for "add the wallets" is to widen `payment_method_types`
     * or move to `automatic_payment_methods`. Both are wrong. Apple Pay and
     * Google Pay are not payment method TYPES at Stripe — they are card
     * wallets, and the PaymentMethod either sheet produces has `type: card`.
     * `['card']` already admits them.
     *
     * So the comment at StripeGateway::openIntent() stands: the box on the
     * checkout says "Credit or debit card" and a dashboard-driven list would
     * put redirect methods in it, which this build is not allowed to do. The
     * wallets ride the intent as written.
     */
    walStripe();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_wallet_intent',
            'client_secret' => 'pi_wallet_intent_secret',
            'status' => 'requires_payment_method',
        ], 200),
    ]);

    $cart = walCart(20000);

    walShopper($cart)->postJson('/checkout/place', walFields())
        ->assertOk()
        ->assertJsonPath('action', 'confirm');

    $body = null;

    Http::assertSent(function ($request) use (&$body) {
        if (! str_contains($request->url(), '/v1/payment_intents')) {
            return false;
        }

        // The RAW form body. Stripe takes application/x-www-form-urlencoded and
        // StripeGateway::flatten() turns nested arrays into `a[b]` keys, so the
        // wire is the honest place to read this — the decoded array would be
        // this test's own idea of what was sent.
        $body = $request->body();

        return true;
    });

    expect($body)->toBeString();

    // Card, named explicitly, exactly as before this round. Not widened, not
    // replaced by automatic_payment_methods.
    expect($body)->toContain('payment_method_types%5B0%5D=card')
        ->and($body)->not->toContain('payment_method_types%5B1%5D')
        ->and($body)->not->toContain('automatic_payment_methods');

    // And the money is still integer minor units with no conversion.
    expect($body)->toContain('amount=22000')
        ->and($body)->toContain('currency=aed');
});
// MUTATION, run: change 'payment_method_types' => ['card'] to
// ['card', 'apple_pay']. RED on the first expectation AND at Stripe, which
// rejects apple_pay as an unknown payment method type — which is the whole
// reason this test names the value rather than merely asserting it is a list.

it('tells the browser what the order really costs, so a wallet cannot charge a different figure', function () {
    /*
     * The wallet sheet shows a total BEFORE /checkout/place is called, and a
     * basket can move in between — a quantity tap in another tab, a coupon
     * that expired, the gift box ticked. The express row compares the two and
     * refuses to confirm when they differ. It can only do that if place() says
     * what the order came to, in integer fils, rather than as a formatted
     * string the browser would have to parse back into a number.
     */
    walStripe();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_wallet_amount',
            'client_secret' => 'pi_wallet_amount_secret',
            'status' => 'requires_payment_method',
        ], 200),
    ]);

    $cart = walCart(20000);

    $response = walShopper($cart)->postJson('/checkout/place', walFields())->assertOk();

    // 20000 basket + 2000 delivery. The same figure the intent was opened for.
    $response->assertJsonPath('amount', 22000);

    expect($response->json('amount'))->toBeInt();
});
// MUTATION, run: drop the `'amount' => (int) $order->total` line from
// CheckoutController::place(). RED on the path assertion — and on the shop,
// the express row would have nothing to compare the sheet's figure against.

/* =====================================================================
 | 2. What the sheet is allowed to say
 ===================================================================== */

it('answers the wallet amount from the server, in integer fils, exactly as place() computes it', function () {
    walStripe();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_wallet_agree',
            'client_secret' => 'pi_wallet_agree_secret',
            'status' => 'requires_payment_method',
        ], 200),
    ]);

    $cart = walCart(20000);

    $quoted = walShopper($cart)
        ->postJson('/checkout/wallet/amount', ['country' => 'AE', 'state' => 'Dubai'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('currency', 'aed')
        ->json('amount');

    expect($quoted)->toBeInt();

    // THE POINT OF THE TEST: the figure the sheet opens with and the figure the
    // order is written for are the same number, produced by the same
    // arithmetic. A test that only checked "the endpoint answers a number"
    // would have passed while the two drifted apart by the delivery charge.
    $placed = walShopper($cart)->postJson('/checkout/place', walFields())->assertOk();

    expect($placed->json('amount'))->toBe($quoted);
});
// MUTATION, run: in CheckoutController::walletAmount() drop the giftFee() and
// feeFils() terms and answer $totals['total'] alone. Still GREEN on this
// basket, because both are zero here — and RED in the gift case below, which is
// why that one exists as well as this one.

it('puts the gift-wrap fee in the wallet figure, because place() puts it on the order', function () {
    walStripe();

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_wallet_gift',
            'client_secret' => 'pi_wallet_gift_secret',
            'status' => 'requires_payment_method',
        ], 200),
    ]);

    $cart = walCart(20000);

    // The gift tick lives in the SESSION, which is where place() reads it from
    // too — the form posts whether they want wrapping and settings hold what it
    // costs. A price from the request would be a price the browser chose.
    $shopper = walShopper($cart)->withSession(['kbb_gift' => true]);

    $quoted = $shopper->postJson('/checkout/wallet/amount', ['country' => 'AE'])
        ->assertOk()
        ->json('amount');

    // 20000 + 2000 delivery + 1500 gift wrap.
    expect($quoted)->toBe(23500);

    $placed = walShopper($cart)
        ->withSession(['kbb_gift' => true])
        ->postJson('/checkout/place', walFields(['is_gift' => '1']))
        ->assertOk();

    expect($placed->json('amount'))->toBe($quoted);
});
// MUTATION, run: remove `+ $this->giftFee($request)` from walletAmount(). RED
// on 23500 — and on the shop, a shopper who ticked gift wrapping would have
// authorised AED 220.00 in the sheet and been charged AED 235.00.

it('refuses to quote a figure for an empty basket rather than quoting zero', function () {
    walStripe();

    // No cart cookie at all.
    $this->postJson('/checkout/wallet/amount', ['country' => 'AE'])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);
});
// MUTATION, run: answer ['ok' => true, 'amount' => 0] for an empty basket.
// RED on the status — and on the shop, Stripe refuses an Element with a
// zero amount and the express row would fail to load rather than simply not
// appear.

/* =====================================================================
 | 3. Who may see the button
 ===================================================================== */

it('draws no express row at all when no wallet is switched on', function () {
    walStripe(apple: false, google: false);

    $cart = walCart(20000);

    $html = walShopper($cart)->get('/checkout')->assertOk()->getContent();

    expect($html)->not->toContain('data-kbb-express')
        ->and($html)->not->toContain('expressCheckout');

    // And the divider went with it. A divider is a claim that there is
    // something above it.
    expect($html)->not->toContain('data-kbb-express-divider');
});
// MUTATION, run: change the partial's @if to `@if (true)`. RED on all three —
// and on the shop, an empty grey box above the payment options on every
// checkout of a shop that has not switched a wallet on.

it('draws the express row, hidden, once a wallet is switched on', function () {
    walStripe(apple: true, google: false);

    $cart = walCart(20000);

    $html = walShopper($cart)->get('/checkout')->assertOk()->getContent();

    expect($html)->toContain('data-kbb-express')
        ->and($html)->toContain('expressCheckout');

    /*
     * HIDDEN IN THE MARKUP. The row is revealed by Stripe's `ready` event and
     * by nothing else — a shopper on a browser with no wallet must never see a
     * button that cannot work, and the server cannot know which browser it is
     * talking to. This is the half of that promise the server can keep.
     */
    expect($html)->toContain('data-kbb-express data-kbb-express-pending aria-hidden="true"');
    expect($html)->toContain('data-kbb-express-divider hidden');

    /*
     * INVISIBLE, NOT display:none (Lane WL). The row carried `hidden`, and
     * `.kbb-checkout [hidden]{display:none!important}` beat its inline
     * display:block, so Stripe's Express Checkout Element was mounted into a
     * box with no layout and the owner saw no Google Pay on Android Chrome
     * with the domain registered and a card in Google Wallet.
     */
    expect($html)->toMatch('/<div class="express" style="display:block;visibility:hidden;height:0;overflow:hidden;margin:0" data-kbb-express data-kbb-express-pending aria-hidden="true">/')
        ->and($html)->not->toMatch('/<div class="express"[^>]*\shidden[\s>]/');

    // Switched off at the shop means 'never' at Stripe, not merely a missing
    // logo: the button is not offered even where the browser has the wallet.
    expect($html)->toContain("applePay: APPLE ? 'auto' : 'never'")
        ->and($html)->toMatch('/var APPLE\s*=\s*true/')
        ->and($html)->toMatch('/var GOOGLE\s*=\s*false/');
});
// MUTATION, run: put `hidden` back on the .express div (the pre-Lane-WL
// markup): RED on the display:none expectation. Drop the visibility:hidden
// from its style: RED on the same line — and on the shop, an empty row that
// Stripe may never fill, which is the dead button this whole file exists to
// prevent.

it('draws no express row when the card gateway is switched off, however the wallets are set', function () {
    walStripe(apple: true, google: true, enabled: false);

    $cart = walCart(20000);

    $html = walShopper($cart)->get('/checkout')->assertOk()->getContent();

    expect($html)->not->toContain('data-kbb-express');
});
// MUTATION, run: in Wallets::compute() drop the `! $row->enabled` guard. RED —
// and on the shop, a wallet button on a checkout whose own payment list does
// not offer the card gateway the button pays through.

it('never draws the two dead buttons this round removed', function () {
    walStripe();

    $cart = walCart(20000);

    $html = walShopper($cart)->get('/checkout')->assertOk()->getContent();

    /*
     * The exact markup that shipped before this round. Two buttons on every
     * checkout, on every browser, with no listener behind them and no gateway
     * behind that.
     */
    expect($html)->not->toContain('xbtn xapple')
        ->and($html)->not->toContain('xbtn xgoogle')
        ->and($html)->not->toContain('class="express" aria-hidden="true"');
});
// MUTATION, run: paste the old <div class="express" aria-hidden="true"> block
// back into store/checkout.blade.php above the @include. RED on all three.

/* =====================================================================
 | 4. The marks and the chips stop lying
 ===================================================================== */

it('keeps Apple Pay out of the footer, the basket and the product chips until the shop can take it', function () {
    walStripe(apple: false, google: false);

    expect(PaymentChips::row('footer'))->not->toContain('Apple Pay')
        ->and(PaymentChips::row('cart'))->not->toContain('Apple Pay')
        ->and(PaymentChips::row('product'))->not->toContain('Apple Pay');

    // The four scheme chips are untouched. This gate is about the two that
    // were lying, not about re-deciding card acceptance.
    expect(PaymentChips::row('footer'))->toBe(['Tabby', 'Tamara', 'Visa', 'Mastercard']);
});
// MUTATION, run: in PaymentChips::row() return self::ROWS[$name] unfiltered.
// RED on all four — and on the shop, three pages going on claiming a payment
// there is no code to take.

it('lets the wallet chips back in the moment the shop can take them', function () {
    walStripe(apple: true, google: true);

    expect(PaymentChips::row('footer'))
        ->toBe(['Tabby', 'Tamara', 'Visa', 'Mastercard', 'Apple Pay', 'Google Pay']);

    // The basket's own order, which is not the footer's.
    expect(PaymentChips::row('cart'))
        ->toBe(['Visa', 'Mastercard', 'Tabby', 'Tamara', 'Apple Pay', 'Google Pay']);
});
// MUTATION, run: make Wallets::offered() return false unconditionally. RED on
// both — the gate would then be a permanent off switch rather than a gate.

it('renders the footer of a real page without the Apple Pay chip on a shop that cannot take it', function () {
    walStripe(apple: false, google: false);

    $html = $this->get('/')->assertOk()->getContent();

    // The whole rendered page, not the helper in isolation: a gate that the
    // template does not call is a gate that does nothing.
    // (Lane HB) `kft-pay` is the new site footer's chip row; it reads the
    // same PaymentChips::row('footer') the previous design's `fpay` did.
    expect($html)->toContain('class="kft-pay"')
        ->and($html)->not->toContain('<span>Apple Pay</span>');
});
// MUTATION, run: put the literal <span>Apple Pay</span> back into
// partials/footer.blade.php. RED on the second expectation.

/* =====================================================================
 | 5. Apple's domain association file
 ===================================================================== */

it('404s the association path until somebody has something to serve there', function () {
    walStripe();

    $this->get('/.well-known/apple-developer-merchantid-domain-association')->assertNotFound();
});
// MUTATION, run: answer 200 with an empty body when nothing is stored. Still
// green here on the status alone, which is why the case below asserts the BODY
// as well as the code.

it('serves exactly the blob that was pasted in, as plain text a browser may not read as markup', function () {
    $blob = str_repeat('7b2270737022', 120);

    walStripe(extra: [AppleDomainController::CONFIG_KEY => $blob]);

    $response = $this->get('/.well-known/apple-developer-merchantid-domain-association')->assertOk();

    // BYTE FOR BYTE. Apple compares this against what Stripe issued; a stray
    // newline or a trimmed character fails the verification with no message
    // anybody can act on.
    expect($response->getContent())->toBe($blob);

    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // The `.txt` spelling answers identically, because a download on some
    // machines lands with that extension and some panels will not serve one
    // without it.
    $this->get('/.well-known/apple-developer-merchantid-domain-association.txt')
        ->assertOk();
});
// MUTATION, run: drop the X-Content-Type-Options header. RED — and on the
// shop, a browser would be free to sniff a pasted value as HTML.

it('refuses a stored value that could be markup, and says nothing about why', function () {
    walStripe(extra: [AppleDomainController::CONFIG_KEY => '<script>alert(1)</script>']);

    /*
     * A 404 and NOT a 500 or a 422. The response must be identical to the one
     * an unconfigured shop gives: "there is a value here but it is malformed"
     * is a fact about this shop's configuration handed to anybody who asks.
     */
    $this->get('/.well-known/apple-developer-merchantid-domain-association')
        ->assertNotFound();
});
// MUTATION, run: remove the `[<>]` guard from AppleDomainController::clean().
// RED — and on the shop, a value from a settings screen printed unescaped at a
// public URL, which is the exact shape CLAUDE.md's rule 5 forbids.

it('refuses a stored value far larger than Apple ever sends', function () {
    walStripe(extra: [AppleDomainController::CONFIG_KEY => str_repeat('a', AppleDomainController::MAX_BYTES + 1)]);

    $this->get('/.well-known/apple-developer-merchantid-domain-association')
        ->assertNotFound();
});
// MUTATION, run: remove the length cap. RED — and on the shop, an unbounded
// public response body built from a settings row.

it('prefers a real file on disk to the pasted value, because a server that has one meant it', function () {
    // Both Apple-shaped (hex, long enough): since Lane WL nothing else is served.
    $pasted = str_repeat('7b2270617374656422', 30);
    $onDisk = str_repeat('7b226469736b22', 40);

    walStripe(extra: [AppleDomainController::CONFIG_KEY => $pasted]);

    $path = storage_path(AppleDomainController::FILE_PATH);

    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, $onDisk . "\n");

    try {
        $response = $this->get('/.well-known/apple-developer-merchantid-domain-association')->assertOk();

        // The file wins, and its trailing newline is trimmed — an editor and a
        // paste both add one and neither changes the token.
        expect($response->getContent())->toBe($onDisk);
    } finally {
        @unlink($path);
    }
});
// MUTATION, run: swap the two halves of the `??` in __invoke(). RED — and on
// the shop, an owner who had placed the file by hand would be served a stale
// pasted value with nothing to explain it.

it('serves the association file without asking whether Apple Pay is switched on', function () {
    /*
     * ORDERING, AND IT IS THE CLASSIC BUG. Registering the domain is the step
     * BEFORE Apple Pay can honestly be switched on, so gating this endpoint on
     * the switch would make the feature impossible to enable: Stripe cannot
     * verify the domain, so the owner cannot turn the wallet on, so the
     * endpoint stays closed.
     */
    walStripe(apple: false, google: false, extra: [
        AppleDomainController::CONFIG_KEY => str_repeat('7B2273657276656422', 30),
    ]);

    $this->get('/.well-known/apple-developer-merchantid-domain-association')
        ->assertOk()
        ->assertSee(str_repeat('7B2273657276656422', 30), escape: false);
});
// MUTATION, run: add `if (! app(Wallets::class)->applePay()) abort(404);` to
// __invoke(). RED — and in the world, a domain that can never be verified.

/* =====================================================================
 | 6. The policy, and the admin
 ===================================================================== */

it('lets the Google Pay sheet through the policy by name, and adds no wildcard', function () {
    $directives = ContentSecurityPolicy::DIRECTIVES;

    expect($directives['script-src'])->toContain('https://pay.google.com')
        ->and($directives['frame-src'])->toContain('https://pay.google.com');

    /*
     * NO APPLE HOST, and that is a finding rather than an omission: Apple Pay
     * on the web through Stripe uses Safari's own ApplePaySession, whose sheet
     * is native browser chrome rather than a document. It fetches nothing this
     * policy governs.
     */
    $apple = array_filter(
        array_merge($directives['script-src'], $directives['frame-src'], $directives['connect-src']),
        fn (string $src): bool => str_contains($src, 'apple'),
    );

    expect($apple)->toBe([]);

    // And nothing gained a wildcard host. A too-wide policy on a shop whose
    // /api/* is unauthenticated is a security regression, not a convenience.
    foreach (['script-src', 'frame-src'] as $directive) {
        foreach ($directives[$directive] as $source) {
            expect($source)->not->toContain('*');
        }
    }
});
// MUTATION, run: write 'https://*.google.com' instead. RED on the last loop.

it('offers both wallets on the payments screen as switches, off until somebody says otherwise', function () {
    $schema = app(GatewayRegistry::class)->find('stripe')->configSchema();

    foreach (Wallets::CONFIG_KEYS as $wallet => $key) {
        expect($schema)->toHaveKey($key);

        // A switch, in the right column, drawn by the console's existing `bool`
        // renderer — so this needed no edit to resources/views/admin/app.blade.php.
        expect($schema[$key][0])->toBe('bool');
        expect($schema[$key][3])->toBe('settings');
    }

    /*
     * AND OFF IS WHAT A SHOP THAT HAS NEVER SEEN THE SCREEN HAS. CLAUDE.md
     * rule 1: a new setting ships at the value the page already has, so
     * applying the package moves nothing. An absent config key reads as off,
     * which is the whole of the default.
     */
    walStripe(apple: false, google: false);

    PaymentProvider::find('stripe')->forceFill(['config' => [
        'publishable_key' => 'pk_test_wallet_key',
        'secret_key' => 'sk_test_wallet_key',
    ]])->save();

    app(GatewayCredentials::class)->forget();
    app(Wallets::class)->forget();

    expect(app(Wallets::class)->applePay())->toBeFalse()
        ->and(app(Wallets::class)->googlePay())->toBeFalse()
        ->and(app(Wallets::class)->any())->toBeFalse();
});
// MUTATION, run: make Wallets::compute() default a missing key to true. RED on
// the last block — and on the shop, applying this package would have switched
// two payment methods on for a merchant who had not asked for either.

it('does not report the optional Apple Pay domain box as a credential still to be pasted in', function () {
    /*
     * GatewayPreflight lists every empty schema field as a credential the
     * merchant still has to find. The domain file is only needed for Apple Pay,
     * so on a shop that does not want Apple Pay an empty box would put a
     * permanent amber line on the one screen whose job is to remove them —
     * exactly the argument the `bool` skip beside it already makes.
     */
    walStripe();

    $report = app(\App\Services\Payments\GatewayPreflight::class)->inspect('stripe');

    $keys = array_column($report['missing_fields'] ?? [], 'key');

    expect($keys)->not->toContain('apple_domain_association')
        ->and($keys)->not->toContain('wallet_apple_pay')
        ->and($keys)->not->toContain('wallet_google_pay');
});
// MUTATION, run: remove the `optional` skip from GatewayPreflight. RED on the
// first expectation.

/* =====================================================================
 | 7. The wiring the integrator has to do
 ===================================================================== */

it('has both route files required exactly once from routes/web.php', function () {
    /*
     * THE FINISHED STATE, pinned, and this is deliberate — CLAUDE.md is
     * explicit that a lane must not assert that its own work is NOT wired up,
     * because that assertion goes red the moment the integrator does the one
     * thing the lane asked for.
     *
     * Zero is the "built, never wired up" shape this repository keeps finding:
     * Apple can never verify the domain and the express row can never learn
     * what the basket costs. Two serves Apple's file from two routes and
     * registers the amount endpoint twice. One is the answer.
     */
    $web = file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/wallet-domain.php';"))->toBe(
        1,
        'routes/wallet-domain.php must be required exactly once from routes/web.php — see its header.'
    );

    expect(substr_count($web, "require __DIR__.'/wallet-checkout.php';"))->toBe(
        1,
        'routes/wallet-checkout.php must be required exactly once from routes/web.php — see its header.'
    );
});

it('asks Stripe for a wallet layout Stripe accepts', function () {
    /*
     * THE DEFECT ON THE LIVE SHOP (9 October). The Express Checkout Element was
     * created with { maxColumns: 2, maxRows: 1, overflow: 'never' }, and
     * Stripe.js throws on exactly that: "options.layout.overflow: 'never' is
     * only supported when options.layout.maxRows is 0". The Element was never
     * drawn, so neither Apple Pay nor Google Pay showed on any phone, however
     * well the domain, the file and the wallets were set up. Measured with
     * real Stripe.js on kbeautybliss.com; with maxRows 0 the error is gone.
     *
     * MUTATION: put `maxRows: 1` back beside `overflow: 'never'` -> red.
     */
    $view = (string) file_get_contents(resource_path('views/partials/checkout/express-wallets.blade.php'));
    preg_match_all('/layout:\s*\{([^}]*)\}/', $view, $m);

    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $layout) {
        if (str_contains($layout, "overflow: 'never'")) {
            expect($layout)->toMatch('/maxRows:\s*0\b/');
        }
    }
});
