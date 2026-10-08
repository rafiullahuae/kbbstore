<?php

declare(strict_types=1);

/**
 * Place order: ONE box, a tick in it, sooner -- and the details kept on this
 * device. Lane PO.
 *
 * The owner, on a card order: "upon click on Place order, but placing your order
 * box appeared ... and disappear the box, but suddenly the box appears again and
 * then comes tick box and goes to thank u page. but the two times box opening
 * gives confusion to user, user may think that we are placing order twice ...
 * also the placing order takes little more time, i want it super quick ...
 * ALSO the address fields etc should keep the data in user browser, so user
 * should not enter everything again n again. even wihout login."
 *
 * WHAT IT WAS, measured frame by frame in Chromium (docs/lane-po-shots/):
 * stripe-elements pay() raised the overlay with begin(), took it DOWN with
 * dismiss() before stripe.confirmCardPayment() (so a 3-D Secure frame could
 * never land in something `inert`), and put it back UP with begin() when Stripe
 * answered -- then waited on POST /checkout/card/paid and held the tick 900 ms
 * before asking for the received page. Between the two boxes the page showed
 * the Place order button relabelled "Confirming your payment…", disabled.
 *
 * Four groups:
 *   1. the card leg's shape (runs everywhere);
 *   2. what moved off the shopper's request: order mail after the response,
 *      the card confirmed by the received page itself;
 *   3. remember-me's server half: the tick, the link, the switch, the words;
 *   4. Chromium (KBB_BROWSER_TESTS=1): the box opens once and ticks in place,
 *      a failure closes it once, no toast, 3-D Secure on top and answerable,
 *      and the remembered details saved, restored, never overwriting, erased.
 */

use App\Mail\OrderConfirmation;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\Mail\OrderMailer;
use App\Services\SettingsService;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
});

afterEach(function () {
    // A test that failed half way must not leave the next one deferring.
    OrderMailer::flushDeferred();
});

function poStripeOn(): void
{
    $row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Credit / Debit Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = [
        'publishable_key' => 'pk_test_po', 'secret_key' => 'sk_test_po',
        'webhook_signing_secret' => 'whsec_po', 'webhook_secret' => 'whsec-url-po-0123456789abcd',
    ];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function poCodOn(): void
{
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
}

function poCart(): Cart
{
    $product = Product::create(['slug' => 'po-serum-' . uniqid(), 'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 120, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 12000]);

    return $cart;
}

function poShopper(Cart $cart)
{
    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function poCardOrder(array $over = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'PO' . random_int(10000, 99999),
        'email' => 'buyer@example.com', 'status' => 'pending', 'currency' => 'AED',
        'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 2000, 'fee_total' => 0, 'gift_fee' => 0,
        'tax_total' => 0, 'total' => 22000, 'shipping_method' => 'Standard delivery',
        'payment_method' => 'stripe', 'transaction_id' => 'pi_po_arrival',
        'billing_address' => ['first_name' => 'Aisha', 'line1' => 'Villa 12', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'line1' => 'Villa 12', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
    ], $over));
}

function poCode(string $partial): string
{
    $src = (string) file_get_contents(resource_path('views/partials/checkout/' . $partial . '.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/* ═══════════════════════════ 1. the card leg's shape ═══════════════════════ */

it('keeps the one box up from the press to the tick on the card leg', function () {
    /*
     * The double box on the shop was two lines: `ov.dismiss()` before
     * confirmCardPayment and `ov.begin()` after it. Between a successful place()
     * and the tick there is now one overlay call before Stripe -- hold() -- and
     * none after it but confirmed().
     *
     * MUTATION, run: put `if (ov) { ov.dismiss(); }` back above
     * `await stripe.confirmCardPayment` -> red ("closes the box before Stripe").
     * Put `if (ov) { ov.begin(); }` back after the status check -> red (two begins).
     */
    $card = poCode('stripe-elements');

    $from = strpos($card, 'handle = openOrder = placed.body;');
    $confirm = strpos($card, 'await stripe.confirmCardPayment');
    $success = strpos($card, 'if (ov) { ov.confirmed(handle.success_url, report); return; }');

    expect($from)->not->toBeFalse()->and($confirm)->toBeGreaterThan($from)->and($success)->toBeGreaterThan($confirm);

    $beforeStripe = substr($card, $from, $confirm - $from);
    $afterStripe = substr($card, $confirm, $success - $confirm);

    expect($beforeStripe)->toContain('if (ov) { ov.hold(); }')
        ->and(str_contains($beforeStripe, 'ov.dismiss()'))->toBeFalse('closes the box before Stripe')
        ->and($beforeStripe)->not->toContain('ov.begin()')
        ->and(substr_count($card, 'ov.begin()'))->toBe(1, 'the card leg raises the box more than once')
        // Every failure after Stripe closes it, once, before the reason is shown.
        ->and(substr_count($afterStripe, 'if (ov) { ov.dismiss(); }'))->toBe(2)
        ->and($afterStripe)->not->toContain('ov.begin()');
});

it('holds the box through 3-D Secure without trapping the bank\'s frame', function () {
    /*
     * The reason the box used to come down was real: an `inert` page and a
     * focus trap would make Stripe's challenge unanswerable. hold() removes both
     * and keeps the card on screen; Stripe's frame is appended to <body> at the
     * top z-index, so it opens above it (the Chromium case below shows it).
     *
     * MUTATION, run: drop `bank ||` from the focusin guard -> red here, and in
     * Chromium the one-time code cannot be typed (focus is pulled back).
     */
    $ov = poCode('placing-overlay');

    expect($ov)->toContain("function hold() {\n    if (!busy) { begin(); }\n\n    bank = true;\n    freeze(false);\n  }")
        ->and($ov)->toContain('if (!busy || bank || !box || box.contains(event.target)) { return; }')
        ->and($ov)->toContain('hold: hold,')
        // down() puts the trap back for the next attempt.
        ->and($ov)->toMatch('/function down\(\) \{\s*clearTimers\(\);\s*busy = false;\s*bank = false;/');
});

it('starts the received page while the tick draws, and draws it once', function () {
    /*
     * 900 ms of tick before the received page was even asked for. The
     * navigation now starts at TICK_MS and the browser keeps the tick painted
     * until the received page paints. confirmed() is idempotent: a second call
     * neither redraws nor schedules a second navigation.
     *
     * MUTATION, run: set TICK_MS back to 900 -> red. Delete the is-done guard
     * in confirmed() -> red.
     */
    $ov = poCode('placing-overlay');

    expect(preg_match('/var TICK_MS = (\d+);/', $ov, $m))->toBe(1)
        ->and((int) $m[1])->toBeLessThanOrEqual(400)
        ->and($ov)->toContain("if (box.classList.contains('is-done')) { return; }");
});

it('shows no toast anywhere on the way to the received page', function () {
    // MUTATION, run: add window.kbbToast('Order placed') to confirmed() -> red.
    foreach (['placing-overlay', 'stripe-elements'] as $partial) {
        expect(poCode($partial))->not->toContain('kbbToast');
    }
});

it('reports the payment to the shop under the tick, not before it', function () {
    /*
     * The card leg awaited POST /checkout/card/paid and only then drew the tick,
     * which then held the page 900 ms. The report now starts as the tick is
     * drawn and confirmed() waits for whichever is later -- the tick's moment
     * or the report's answer, capped -- before opening the received page.
     *
     * MUTATION, run: put back `try { await post(PAID_URL, ...) } catch (e) {}`
     * before ov.confirmed() -> red.
     */
    foreach (['stripe-elements' => 'handle.order', 'express-wallets' => 'order'] as $partial => $order) {
        $code = poCode($partial);

        expect($code)->not->toContain('await post(PAID_URL')
            ->and($code)->toContain("var report = post(PAID_URL, { order: {$order} })");
    }

    $ov = poCode('placing-overlay');
    expect($ov)->toContain('function confirmed(url, report) {')
        ->and(preg_match('/var REPORT_MS = (\d+);/', $ov, $m))->toBe(1)
        ->and((int) $m[1])->toBeLessThanOrEqual(5000)
        ->and(substr_count($ov, 'window.location.assign(url);'))->toBe(1, 'more than one way to navigate after the tick');
});

/* ═════════════════ 2. off the shopper's request ════════════════════════════ */

it('sends the cash-on-delivery receipt after the response, not before it', function () {
    /*
     * On the preview, with a 600 ms stand-in per message, place() was 1,313 ms
     * of which 1,200 was mail. The receipt still goes -- once -- but from
     * app()->terminating(), after Symfony's send() has finished the request.
     *
     * RequestHandled fires at the end of the kernel's handle(), before
     * terminate(): what has been sent by then was sent INSIDE the request.
     *
     * MUTATION, run: delete OrderMailer::deferUntilResponse() from place() -> red:
     * 1 sent before the response.
     */
    poCodOn();
    Mail::fake();
    $atResponse = null;
    Event::listen(RequestHandled::class, function () use (&$atResponse) {
        $atResponse ??= Mail::sent(OrderConfirmation::class)->count();
    });

    poShopper(poCart())->postJson('/checkout/place', [
        'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'Aisha Khan',
        'billing_address_1' => 'Villa 12', 'billing_address_2' => 'Marina Walk', 'billing_city' => 'Dubai',
        'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ])->assertOk()->assertJsonPath('action', 'placed');

    expect($atResponse)->toBe(0, 'order mail was sent inside the request')
        ->and(Mail::sent(OrderConfirmation::class)->count())->toBe(1)
        ->and(OrderMailer::deferring())->toBeFalse('deferral outlived its request');
});

it('still sends in line where the owner is waiting for the answer', function () {
    // Deferral is per request and opt-in: nothing outside the checkout turns it on.
    expect(OrderMailer::deferring())->toBeFalse();
});

it('sends the card receipt after the report is answered, not before', function () {
    /*
     * POST /checkout/card/paid confirms the order and the confirmation sets
     * off the receipt. That send used to happen inside the request the
     * received page was waiting on.
     *
     * MUTATION, run: delete OrderMailer::deferUntilResponse() from
     * cardConfirmed() -> red: 1 sent before the response.
     */
    poStripeOn();
    Mail::fake();
    $order = poCardOrder();
    Http::fake(['api.stripe.com/v1/payment_intents/pi_po_arrival' => Http::response([
        'id' => 'pi_po_arrival', 'status' => 'succeeded', 'amount' => 22000, 'amount_received' => 22000, 'currency' => 'aed',
    ], 200)]);

    $atResponse = null;
    Event::listen(RequestHandled::class, function () use (&$atResponse) {
        $atResponse ??= Mail::sent(OrderConfirmation::class)->count();
    });

    test()->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/paid', ['order' => $order->order_number])->assertOk();

    expect($order->fresh()->paid_at)->not->toBeNull()
        ->and($atResponse)->toBe(0, 'the receipt held the report up')
        ->and(Mail::sent(OrderConfirmation::class)->count())->toBe(1);
});

it('leaves the received page a plain read: it never calls Stripe', function () {
    /*
     * A GET that confirms a payment would be asked twice for every returning
     * shopper: the shop's service worker steps around /checkout/* without
     * answering, after navigation preload has already sent one request, so
     * the browser sends a second. The confirmation stays on the POST.
     */
    poStripeOn();
    $order = poCardOrder();
    Http::fake();

    test()->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order=' . $order->order_number)->assertOk();

    Http::assertNothingSent();
    expect($order->fresh()->paid_at)->toBeNull();
});

it('walks the order table once per Place order, not twice, and keeps the query count flat', function () {
    /*
     * OrderNumbers::resync() asked seedValue() for the guard AND for the value,
     * and seedValue() reads every order number to find the highest -- so each
     * Place order scanned the whole order table twice, hydrating an Order model
     * per row. Measured on the preview with 2,400 orders: place() 321 ms of our
     * own time; 123 ms after.
     *
     * MUTATION, run: put `self::seedValue()` back in both places in resync() ->
     * red (2 scans). Drop ->toBase() -> no count changes, but the time does.
     */
    poCodOn();

    $place = function () {
        $sql = [];
        Illuminate\Support\Facades\DB::listen(function ($q) use (&$sql) { $sql[] = $q->sql; });
        poShopper(poCart())->postJson('/checkout/place', [
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'Aisha Khan',
            'billing_address_1' => 'Villa 12', 'billing_address_2' => 'Marina Walk', 'billing_city' => 'Dubai',
            'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
        ])->assertOk();

        return $sql;
    };

    $place(); // the first order also creates the sequence row and the customer
    $first = $place();
    foreach (range(1, 40) as $i) { poCardOrder(['order_number' => (string) (30000 + $i), 'payment_method' => 'cod', 'transaction_id' => null]); }
    $later = $place();

    $scans = fn (array $sql) => count(array_filter($sql, fn ($s) => str_starts_with(Tests\Support\SqlShape::portable($s), 'select "order_number" from "orders"')));

    expect($scans($first))->toBe(1)->and($scans($later))->toBe(1)
        ->and(count($later))->toBe(count($first), 'place() costs more queries with more orders on file');
});

/* ═════════════════ 3. remember-me, the server's half ═══════════════════════ */

it('ships "Remember my details on this device" on, where the owner can switch it', function () {
    // MUTATION, run: default `remember_on` to false -> red.
    expect(CheckoutPage::SCHEMA['remember_on'][0])->toBe('bool')
        ->and(CheckoutPage::SCHEMA['remember_on'][1])->toBe('Remember shopper details on this device')
        ->and(CheckoutPage::SCHEMA['remember_on'][2])->toBeTrue()
        ->and(CheckoutPage::TABS['cues'][0])->toBe('Fields & attention')
        ->and(CheckoutPage::TABS['cues'][2])->toContain('remember_on');
});

it('draws the tick ticked, once, posting nothing, and the clear link hidden until used', function () {
    /*
     * No name attribute: the choice never reaches the server, so place() is
     * byte for byte what it was. The link is in the layout from the first paint
     * and only made visible (a class), so showing it moves nothing.
     *
     * MUTATION, run: give the box name="remember" -> red.
     */
    poCodOn();
    $html = poShopper(poCart())->get('/checkout/')->assertOk()->getContent();

    expect(substr_count($html, 'id="kbb_remember"'))->toBe(1)
        ->and(preg_match('#<input type="checkbox" id="kbb_remember" data-kbb-local checked>#', $html))->toBe(1)
        ->and(preg_match('#<input[^>]*id="kbb_remember"[^>]*name=#', $html))->toBe(0)
        ->and($html)->toContain('Remember my details on this device')
        ->and(substr_count($html, 'id="kbbRememberClear"'))->toBe(1)
        ->and($html)->toContain('<button type="button" class="kbb-rmb-clear" id="kbbRememberClear" aria-hidden="true" tabindex="-1">Not you? Clear details</button>')
        ->and($html)->toContain('.kbb-checkout .sec > h2 .kbb-rmb-clear:not(.on){visibility:hidden}');
});

it('draws neither when the owner switches it off', function () {
    poCodOn();
    app(CheckoutPage::class)->save(['remember_on' => false]);
    SettingsService::forgetMemo();

    $html = poShopper(poCart())->get('/checkout/')->assertOk()->getContent();

    expect($html)->not->toContain('kbb_remember')->and($html)->not->toContain('kbbRememberClear');
});

it('keeps only the named boxes, in one versioned key, every touch guarded', function () {
    /*
     * The script reads a fixed list of ids -- so a card, a coupon, a password,
     * the payment method, notes or a gift message cannot be kept by accident --
     * and every storage call sits in a try/catch (Safari private mode throws).
     *
     * MUTATION, run: add 'kbb_coupon_code' to REMEMBER_CONTACT -> red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));
    $block = substr($js, strpos($js, "const REMEMBER_KEY = 'kbb.checkout.details.v1';"));

    expect($block)->toContain("const REMEMBER_CONTACT = ['billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email'];")
        ->and($block)->toContain("const REMEMBER_ADDRESS = ['billing_address_1', 'billing_address_2', 'billing_city', 'billing_state'];");

    foreach (['kbb_coupon_code', 'account_password', 'payment_method', 'save_card', 'customer_note', 'gift_note', 'card'] as $never) {
        expect(preg_match("/['\"]{$never}['\"]/", $block))->toBe(0, "{$never} could be kept");
    }

    // Every localStorage call is inside a try that has not closed yet.
    preg_match_all('/(?:getItem|setItem|removeItem)\(/', $block, $calls, PREG_OFFSET_CAPTURE);
    expect($calls[0])->not->toBeEmpty();
    foreach ($calls[0] as [$call, $at]) {
        $lead = substr($block, max(0, $at - 400), min($at, 400));
        $try = strrpos($lead, 'try {');
        expect($try)->not->toBeFalse("{$call} outside a try")
            ->and(strpos(substr($lead, (int) $try), '} catch'))->toBeFalse("{$call} outside a try");
    }
});

it('never reads or fills a password, a hidden input or a box that is not shown', function () {
    /*
     * The owner's Firefox autofilled a saved password into the HIDDEN account
     * box and Place order stopped (2.60.432). Remember-me must never be a
     * second way into that box, nor into anything else the shopper cannot see.
     *
     * MUTATION, run: drop `el.closest('[hidden]')` from field() -> red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));
    $field = substr($js, (int) strpos($js, 'const field = (id) => {'), 500);

    expect($field)->toContain("el.type === 'hidden' || el.type === 'password' || el.disabled")
        ->and($field)->toContain("if (el.closest('[hidden]')) return null;")
        ->and($field)->toContain("n.style.display === 'none'");
});

it('has the two sentences in English, and their Arabic as drafts with a seed', function () {
    $drafts = \App\Services\Translation\ArabicInterfaceDrafts::all();
    $seed = (string) file_get_contents(database_path('migrations/2027_10_08_140000_seed_checkout_remember_arabic_drafts.php'));

    expect(__('store.checkout.remember_me'))->toBe('Remember my details on this device')
        ->and(__('store.checkout.remember_clear'))->toBe('Not you? Clear details')
        ->and($drafts['store.checkout.remember_me'])->toBe('تذكّر بياناتي على هذا الجهاز')
        ->and($drafts['store.checkout.remember_clear'])->toBe('لست أنت؟ امسح البيانات')
        ->and($seed)->toContain("'store.checkout.remember_me',")
        ->and($seed)->toContain("'store.checkout.remember_clear',");
});

/* ═════════════════════════ 4. in Chromium ══════════════════════════════════ */

it('opens one box, ticks in place, closes once on failure, and remembers -- in Chromium', function () {
    $chrome = env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
    $missing = [];
    if (! env('KBB_BROWSER_TESTS')) { $missing[] = 'KBB_BROWSER_TESTS is not set'; }
    if (! is_file($chrome)) { $missing[] = "no Chromium at {$chrome}"; }
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') { $missing[] = 'node is not on PATH'; }
    if ($missing !== []) {
        test()->markTestSkipped('Needs real Chromium: ' . implode('; ', $missing) . '.');
    }

    poStripeOn();
    poCodOn();
    $html = poShopper(poCart())->get('/checkout/')->assertOk()->getContent();
    $html = preg_replace('#(href|src)="https?://[^"]*/build/#', '$1="/build/', $html) ?? $html;

    $file = storage_path('framework/testing/po-checkout-' . getmypid() . '.html');
    @mkdir(dirname($file), 0o777, true);
    file_put_contents($file, $html);

    $env = 'KBB_PO_HTML=' . escapeshellarg($file)
        . ' KBB_PO_BUILD=' . escapeshellarg(base_path('public/build'))
        . ' KBB_BROWSER_CHROME=' . escapeshellarg($chrome)
        . ' NODE_PATH=' . escapeshellarg((string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'));
    $raw = (string) shell_exec($env . ' node ' . escapeshellarg(base_path('tests/browser/po-place-order.mjs')) . ' 2>&1');
    @unlink($file);

    $r = json_decode($raw, true);
    expect($r)->toBeArray('no report: ' . substr($raw, 0, 600))
        ->and($r['ok'] ?? false)->toBeTrue($r['error'] ?? '');
    $c = $r['cases'];

    // Cash on delivery: one box, the tick in it, the received page, no toast.
    expect($c['cod']['added'])->toBe(1)->and($c['cod']['removed'])->toBe(0)
        ->and($c['cod']['done'])->toBeTrue()->and($c['cod']['toast'])->toBe([])
        ->and($c['cod']['url'])->toBe('/checkout/success')->and($c['cod']['errors'])->toBe([]);

    // Card: never closed and reopened (the owner's report was added 2, removed 1).
    expect($c['card']['added'])->toBe(1)->and($c['card']['removed'])->toBe(0)
        ->and($c['card']['during']['box'])->toBeTrue()->and($c['card']['during']['inert'])->toBe(0)
        ->and($c['card']['done'])->toBeTrue()->and($c['card']['toast'])->toBe([])
        ->and($c['card']['posts'])->toContain('/checkout/card/paid')
        // The tick is drawn before the report is answered, and the received
        // page is not asked for until it has been.
        ->and($c['card']['tickAt'])->toBeLessThan($c['card']['paidAnsweredAt'])
        ->and($c['card']['navAt'])->toBeGreaterThanOrEqual($c['card']['paidAnsweredAt'])
        ->and($c['card']['url'])->toBe('/checkout/success')->and($c['card']['errors'])->toBe([]);

    // 3-D Secure: the bank on top, answerable; our box underneath, still one.
    expect($c['threeDS']['during']['box'])->toBeTrue()
        ->and($c['threeDS']['during']['bankOnTop'])->toBeTrue()
        ->and($c['threeDS']['during']['focusInBank'])->toBeTrue()
        ->and($c['threeDS']['during']['typed'])->toBe('123456')
        ->and($c['threeDS']['added'])->toBe(1)->and($c['threeDS']['removed'])->toBe(0)
        ->and($c['threeDS']['url'])->toBe('/checkout/success');

    // Failures: closed once, the reason where it always was.
    expect($c['refused']['added'])->toBe(1)->and($c['refused']['removed'])->toBe(1)
        ->and(trim($c['refused']['notice']))->toBe('Nope, not today.')->and($c['refused']['done'])->toBeFalse();
    expect($c['declined']['added'])->toBe(1)->and($c['declined']['removed'])->toBe(1)
        ->and($c['declined']['error'])->toBe('Your card was declined.')->and($c['declined']['done'])->toBeFalse();

    // Remember: what was kept, and what never is.
    $kept = json_decode((string) $c['remember']['first'], true);
    expect($kept['v'])->toBe(1)
        ->and($kept['f']['billing_first_name'])->toBe('Aisha Khan')
        ->and($kept['f']['billing_email'])->toBe('buyer@example.com')
        ->and($kept['f']['billing_address_1'])->toBe('Villa 12')
        ->and($kept['f']['billing_state'])->toBe('Dubai')
        ->and(array_keys($kept['f']))->each->toBeIn(['billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_country', 'shipping_method'])
        ->and((string) $c['remember']['first'])->not->toContain('SAVE10')
        ->and((string) $c['remember']['first'])->not->toContain('hunter2')
        ->and((string) $c['remember']['first'])->not->toContain('cod');

    $back = $c['remember']['restored'];
    expect($back['name'])->toBe('Aisha Khan')->and($back['email'])->toBe('buyer@example.com')
        ->and($back['building'])->toBe('Villa 12')->and($back['area'])->toBe('Marina Walk')->and($back['state'])->toBe('Dubai')
        ->and($back['coupon'])->toBe('')->and($back['password'])->toBe('')
        ->and($back['clearShown'])->toBeTrue()->and($back['tick'])->toBeTrue()
        ->and($back['cls'])->toBeLessThan(0.01);

    expect($c['remember']['afterClear']['stored'])->toBeNull()
        ->and($c['remember']['afterClear']['name'])->toBe('')
        ->and($c['remember']['afterClear']['clearShown'])->toBeFalse();

    // The server's value stands; the empty box beside it is still filled.
    expect($c['remember']['server']['email'])->toBe('server@example.com')
        ->and($c['remember']['server']['name'])->toBe('Aisha Khan');

    expect($c['remember']['unticked'])->toBeNull();

    // Storage that throws: no error, the order still goes through.
    expect($c['blocked']['errors'])->toBe([])->and($c['blocked']['url'])->toBe('/checkout/success');
});
