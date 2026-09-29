<?php

declare(strict_types=1);

/**
 * THE PLACE-ORDER OVERLAY. (Lane PLC)
 *
 * The owner asked for a frozen, blurred checkout with "Placing your order…" on
 * it, an animated star-burst tick once the order is placed, and then the
 * order-received page — and, after being shown how Tabby and Tamara work, for
 * the tick to be waiting for him when he comes BACK from one of them.
 *
 * ── WHAT THESE CASES ARE ACTUALLY FOR ───────────────────────────────────────
 *
 * The happy path is the easy half. This overlay sits on top of the one action
 * in the shop that takes money, and every case below is a way it can go wrong:
 *
 *   · a tick drawn for an order that was never approved
 *   · an overlay left up after a failure, with the reason behind the blur
 *   · a "confirming your payment" spinner with no end to it
 *   · a second tick on the order-received page for an order that already
 *     showed one on the checkout
 *   · the whole feature keyed off a list of gateway names that goes stale
 *   · JavaScript that measures layout, which two other tests in this suite
 *     forbid by name and which an overlay and a progress ring are the two most
 *     tempting places to reach for
 *
 * Each case says in its own comment what the defect would look like on the
 * shop, and carries a MUTATION note that was actually run.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Checkout\PlacementState;
use App\Services\Payments\GatewayRegistry;
use Tests\Support\CheckoutReturnRoutes;

/* ═══════════════════════════════════ fixtures ═══════════════════════════════ */

function plcOrder(array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'PLC' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'email' => 'buyer@example.com',
        'status' => 'pending',
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
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => '12 Marina Walk', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ],
    ], $overrides));
}

/** The order-received page, as the browser that just placed this order sees it. */
function plcReceived(Order $order, string $query = '')
{
    return test()
        ->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order=' . $order->order_number . $query);
}

function plcSource(string $partial): string
{
    return file_get_contents(resource_path('views/partials/checkout/' . $partial . '.blade.php'));
}

/**
 * The same file with every comment taken out of it.
 *
 * ── WHY THIS EXISTS, AND IT IS NOT TIDINESS ─────────────────────────────────
 *
 * The bans below are on what the CODE does. partials/checkout/placing-overlay's
 * own header names the forbidden APIs out loud — "Not one getBoundingClientRect,
 * offsetWidth, offsetHeight…" and "There is also no innerHTML" — because a rule
 * that is not written down beside the code is a rule the next lane breaks. A
 * guard reading the raw file finds those words and calls the file an offender
 * for explaining itself, so the only way to keep it green would be to delete
 * the explanation. That is the wrong artefact to lose.
 *
 * Both comment forms are removed: Blade's {{-- --}} and JavaScript's /* * / and
 * //. What is left is the code, the CSS and the markup.
 */
function plcCode(string $partial): string
{
    $src = plcSource($partial);

    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    $src = (string) preg_replace('#^\s*//.*$#m', '', $src);

    return $src;
}

/* ══════════════════ 1. the overlay is on the checkout, once ═════════════════ */

it('draws the overlay on the checkout exactly once, style, markup and script', function () {
    /*
     * THE DEFECT THIS CATCHES IS THE ONE CLAUDE.md CALLS "built, never wired
     * up": a partial that exists, is perfect, and is included by nothing —
     * which on this page means the Place order button goes on doing the full
     * page post it always did and the owner reports that the package changed
     * nothing.
     *
     * TWO is the other real failure. Both partials that use this stylesheet are
     * included from one page in one round-trip, and two copies of
     * `#kbbPlacingTpl` would give document.getElementById a choice to make.
     *
     * MUTATION, run: delete the @include from the scripts push in
     * store/checkout.blade.php → "the overlay is included 0 times".
     */
    $product = Product::create([
        'slug' => 'plc-serum', 'name' => 'PLC Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 200, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED',
        'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);

    $html = test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout')
        ->assertOk()
        ->getContent();

    expect(substr_count($html, '<!--kbb-placing-->'))->toBe(1, 'the overlay is included the wrong number of times')
        ->and(substr_count($html, '<template id="kbbPlacingTpl">'))->toBe(1, 'the overlay markup is included the wrong number of times')
        ->and(substr_count($html, '.kbb-placing{position:fixed'))->toBe(1, 'the overlay stylesheet is included the wrong number of times')
        /* The visible words live in the script's TEXT table, where @json has
           escaped the ellipsis to \u2026 — so the assertion is on the part that
           survives that, which is also the part a translator would change. */
        ->and($html)->toContain('Placing your order');
});

it('registers its click listener AFTER the card, or the card is placed twice', function () {
    /*
     * BOTH partials listen for [data-place] in the CAPTURE phase on `document`,
     * and capture listeners on one node run in the order they were added. If
     * this partial were included FIRST it would claim every press — including
     * the card's — post the form, get `action: confirm` back for a confirmation
     * it cannot perform, and refuse. The card fields would stop working
     * entirely, and stripe-elements' own listener would never see a click.
     *
     * The order is a property of ONE file, so it is asserted on that file.
     *
     * MUTATION, run: swap the two @include lines in store/checkout.blade.php →
     * "the overlay must be included after the card fields".
     */
    $view = file_get_contents(resource_path('views/store/checkout.blade.php'));

    $card = strpos($view, "@include('partials.checkout.stripe-elements')");
    $overlay = strpos($view, "@include('partials.checkout.placing-overlay')");

    expect($card)->not->toBeFalse()
        ->and($overlay)->not->toBeFalse()
        ->and($overlay)->toBeGreaterThan($card, 'the overlay must be included after the card fields');
});

/* ═══════════════════ 2. no layout measuring, no gateway names ═══════════════ */

it('measures no layout and names no gateway', function () {
    /*
     * RULE 4 AND THE COORDINATOR'S INSTRUCTION, IN ONE PLACE.
     *
     * A full-screen overlay and a progress ring are the two most tempting
     * places on this shop to reach for getBoundingClientRect. Neither needs it:
     * position:fixed + inset:0 is the overlay, and the ring is an SVG circle
     * with pathLength="100", so the dash geometry is in percent and the browser
     * does the arithmetic at paint time.
     *
     * And a `['tabby','tamara']` in a template is a list that is wrong the
     * first time a gateway is added and silent about being wrong. The overlay
     * branches on place()'s `action` — placed / redirect / confirm — and the
     * pages that need to know the shape of a journey ask
     * PaymentGateway::journey().
     *
     * MUTATION, run: add `el.getBoundingClientRect()` to placing-overlay →
     * "placing-overlay reaches for getBoundingClientRect". Add
     * `if (id === 'tabby')` → "placing-overlay names a gateway".
     */
    $measuring = ['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth',
        'clientHeight', 'getComputedStyle', 'scrollHeight', 'scrollWidth', 'innerHeight', 'innerWidth'];

    foreach (['placing-overlay', 'placing-card', 'placing-style', 'placed-tick', 'return-notice'] as $partial) {
        // The CODE, with the comments stripped — see plcCode() for why.
        $src = plcCode($partial);

        /*
         * str_contains() AND toBeFalse(), NOT ->not->toContain($needle, $msg).
         *
         * Pest's toContain() is VARIADIC: every argument after the first is
         * another needle, so a "message" passed there is silently asserted as a
         * second string that must also be absent — and `->not->toContain($api,
         * "placing-overlay reaches for getBoundingClientRect")` can therefore
         * never fail, because that sentence is not in the file either.
         * ExpectationsThatCannotFailTest caught all four of them in this file
         * on the first full run, which is exactly what it is for.
         */
        foreach ($measuring as $api) {
            expect(str_contains($src, $api))->toBeFalse($partial . ' reaches for ' . $api);
        }

        foreach (["'tabby'", '"tabby"', "'tamara'", '"tamara"', "'cod'", '"cod"'] as $name) {
            expect(str_contains($src, $name))->toBeFalse($partial . ' names the gateway ' . $name);
        }
    }

    /* And the prose is checked too, in the one direction that matters: the
       overlay must still SAY that it does not measure, because the ban is only
       as durable as the sentence explaining it. */
    expect(plcSource('placing-overlay'))->toContain('getBoundingClientRect');
});

it('builds no markup from a string, so nothing it draws can come from a setting', function () {
    /*
     * RULE 5. The star burst and the tick are inline SVG constants, and they
     * are constants because they are SERVER-RENDERED into a <template> by
     * partials/checkout/placing-card and cloned — not assembled in JavaScript
     * out of a string that a later edit could interpolate a setting into.
     *
     * innerHTML is therefore absent from the overlay's script entirely, which
     * is a much easier thing to keep true than "innerHTML, but only ever with a
     * literal".
     *
     * MUTATION, run: replace the cloneNode with `box.innerHTML = MARKUP` →
     * "placing-overlay assembles markup in JavaScript".
     */
    foreach (['placing-overlay', 'placed-tick'] as $partial) {
        $src = plcCode($partial);

        // str_contains(), for the reason spelled out in the case above.
        expect(str_contains($src, 'innerHTML'))->toBeFalse($partial . ' assembles markup in JavaScript')
            ->and(str_contains($src, 'insertAdjacentHTML'))->toBeFalse($partial . ' assembles markup in JavaScript');
    }
});

it('never navigates to an address it has not checked the scheme of', function () {
    /*
     * The redirect URL comes back from Tabby's or Tamara's API and becomes both
     * a window.location.assign and, when the navigation does not happen, an
     * href on a link the shopper presses. `javascript:` in either is a script
     * this shop ran on behalf of a payment provider.
     *
     * MUTATION, run: delete the safeUrl() guard from leaving() → this goes red
     * on the missing regular expression.
     */
    $src = plcSource('placing-overlay');

    expect($src)->toContain("/^https:\\/\\//i.test(url)")
        ->and($src)->toContain('var safe = safeUrl(url);')
        ->and($src)->toContain("if (!safe) { fail(TEXT.failed); return; }");
});

/* ═════════════════════════ 3. the gateway's own answer ══════════════════════ */

it('asks every gateway which of the three journeys it sends a shopper on', function () {
    /*
     * The vocabulary is PaymentStart::$result's, deliberately, so the answer a
     * page gets at RENDER time and the answer the browser gets at PLACE time
     * are the same three words and cannot disagree.
     *
     * MUTATION, run: return 'confirm' from TabbyGateway::journey() → the
     * order-received case below stops drawing anything for a Tamara return,
     * because leftTheShopFor() is what gates it.
     */
    $registry = app(GatewayRegistry::class);

    $expected = [
        'cod' => 'placed',
        'stripe' => 'confirm',
        'tabby' => 'redirect',
        'tamara' => 'redirect',
    ];

    foreach ($expected as $id => $journey) {
        $gateway = $registry->find($id);

        expect($gateway)->not->toBeNull($id . ' is not in the registry any more')
            ->and($gateway->journey())->toBe($journey, $id . ' answers the wrong journey');
    }
});

it('keeps cash on delivery journey() and start() saying the same thing', function () {
    /*
     * THE DRIFT THIS CATCHES. journey() is a second statement of something
     * start() already knows, which is the price of being able to ask the
     * question without opening a payment. Two statements of one fact drift.
     *
     * Cash on delivery is the one gateway whose start() can be run in a suite —
     * the other three call out to a provider — so it is the one that can be
     * checked rather than described.
     *
     * MUTATION, run: return 'redirect' from CashOnDelivery::journey() →
     * "cash on delivery says redirect and does placed".
     */
    $gateway = app(GatewayRegistry::class)->find('cod');

    $start = $gateway->start(plcOrder());

    expect($start->result)->toBe(
        $gateway->journey(),
        'cash on delivery says ' . $gateway->journey() . ' and does ' . $start->result
    );
});

/* ═══════════════════════════ 4. is it actually placed ══════════════════════ */

it('calls a cash-on-delivery order confirmed although it will never carry paid_at', function () {
    /*
     * THE DEFECT: testing `paid_at` alone. Cash on delivery takes the money at
     * the door and `paid_at` stays null for the life of the order, so a
     * paid_at-only rule would leave every COD order — most of this shop's —
     * showing "Confirming your payment…" for ever instead of a tick.
     *
     * The order below is left at `pending` on purpose. Its real status after
     * place() is `processing`, which the accepted-status branch would answer
     * on its own; pinning it at `pending` is what proves journey() is doing
     * the work.
     *
     * MUTATION, run: delete the journey() === 'placed' branch from
     * PlacementState::forOrder() → this reads `awaiting`.
     */
    $order = plcOrder(['payment_method' => 'cod', 'status' => 'pending', 'paid_at' => null]);

    expect(app(PlacementState::class)->forOrder($order))->toBe(PlacementState::CONFIRMED);
});

it('will not call a Tamara order confirmed until the shop says so', function () {
    /*
     * THE WORST OUTCOME AVAILABLE ON THIS PAGE, pinned.
     *
     * Tamara notifies by webhook and a shopper on a fast connection beats it
     * home, so an order that is genuinely unconfirmed is the ORDINARY state for
     * the first seconds of the return. A tick there is a celebration for a
     * payment that may yet be declined.
     *
     * MUTATION, run: make forOrder() return CONFIRMED whenever an order exists
     * → this reads `confirmed` for the pending one.
     */
    $state = app(PlacementState::class);

    $pending = plcOrder(['payment_method' => 'tamara', 'status' => 'pending', 'paid_at' => null]);
    $paid = plcOrder(['payment_method' => 'tamara', 'status' => 'pending', 'paid_at' => now()]);

    expect($state->forOrder($pending))->toBe(PlacementState::AWAITING)
        ->and($state->forOrder($paid))->toBe(PlacementState::CONFIRMED);
});

it('calls a failed order refused even when it carries a paid_at', function () {
    /*
     * ORDER OF TESTS, PINNED. A payment that went through and was then reversed
     * leaves `paid_at` set on an order the shop has given up on. Checking
     * paid_at first would draw a tick over it.
     *
     * MUTATION, run: move the REFUSED_STATUSES check below the paid_at check
     * in forOrder() → this reads `confirmed`.
     */
    $order = plcOrder(['payment_method' => 'tamara', 'status' => 'failed', 'paid_at' => now()]);

    expect(app(PlacementState::class)->forOrder($order))->toBe(PlacementState::REFUSED);
});

it('is conservative about a gateway it has never heard of', function () {
    /*
     * An order imported from the old WooCommerce shop, or one placed with a
     * gateway the owner has since removed, resolves to no gateway at all. The
     * answer then falls through to the status and paid_at, and the worst it can
     * do is withhold an animation rather than invent a confirmation.
     */
    $order = plcOrder(['payment_method' => 'paypal_legacy', 'status' => 'pending', 'paid_at' => null]);

    $state = app(PlacementState::class);

    expect($state->forOrder($order))->toBe(PlacementState::AWAITING)
        ->and($state->leftTheShopFor($order))->toBeFalse()
        ->and($state->forOrder(null))->toBeNull();
});

/* ══════════════════════ 5. the return leg, on the receipt ══════════════════ */

it('has the tick waiting when a confirmed Tamara order comes home', function () {
    /*
     * "will come back with a succesfull tick star, and show the order details
     * page" — and the tick is `is-done`, which is the class the star burst and
     * the check stroke are keyed off in placing-style.
     *
     * MUTATION, run: change the CONFIRMED branch in placed-tick to render the
     * awaiting card → "Order placed" and `is-done` both vanish.
     */
    $order = plcOrder(['payment_method' => 'tamara', 'status' => 'processing', 'paid_at' => now()]);

    $html = plcReceived($order)->assertOk()->getContent();

    expect($html)->toContain('<!--kbb-placed-->')
        ->and($html)->toContain('is-up is-done is-selfclosing')
        ->and($html)->toContain('Order placed')
        /* The MARKUP, not the stylesheet: `.kbb-placing-check` appears in
           placing-style too, once in the ordinary rules and once under
           prefers-reduced-motion. */
        ->and(substr_count($html, 'class="kbb-placing-check"'))->toBe(1);
});

it('shows no tick, and one bounded refresh, while the payment is still unconfirmed', function () {
    /*
     * THE SPINNER WITH NO END IS THE FAILURE MODE HERE, and there are two
     * separate bounds on it.
     *
     * The card takes itself down in CSS after --kbbp-hold whatever happens, so
     * a browser whose script never runs is looking at the receipt a few seconds
     * later rather than at a covered page for ever. And the page refreshes
     * itself exactly ONCE, with the marker in the address it reloads to, so a
     * second pass cannot schedule a third.
     *
     * MUTATION, run: drop the `@if (! $kbbConfirmingPass)` guard round the
     * reload script → the second pass below ships a second setTimeout, which is
     * a page that reloads for ever.
     */
    $order = plcOrder(['payment_method' => 'tamara', 'status' => 'pending', 'paid_at' => null]);

    $first = plcReceived($order)->assertOk()->getContent();

    expect($first)->toContain('Confirming your payment…')
        ->and($first)->not->toContain('is-up is-done is-selfclosing')
        ->and($first)->toContain('is-up is-selfclosing')
        ->and(substr_count($first, 'window.location.replace'))->toBe(1, 'the receipt does not reload itself exactly once')
        ->and($first)->toContain('confirming=1');

    // The second pass. `kbb_last_order` has been consumed by the first, and the
    // marker in the query string is what keeps this page in the arrival state.
    $second = test()->get('/checkout/success?order=' . $order->order_number . '&confirming=1')
        ->assertOk()->getContent();

    expect($second)->toContain('Confirming your payment…')
        ->and($second)->toContain('still being confirmed')
        ->and(substr_count($second, 'window.location.replace'))->toBe(0, 'the receipt schedules a second reload');
});

it('draws nothing at all for an order the shopper never left the shop to pay for', function () {
    /*
     * TWO TICKS FOR ONE ORDER is the defect. Cash on delivery and the card
     * fields show their tick on the CHECKOUT, in the document that placed the
     * order, a moment before navigating here. A second one on arrival would be
     * a celebration played twice, and it would also mean this page moved for
     * every order on the shop rather than for the two gateways that need it.
     *
     * MUTATION, run: delete the leftTheShopFor() gate from placed-tick → the
     * cash-on-delivery receipt grows an overlay.
     */
    $order = plcOrder(['payment_method' => 'cod', 'status' => 'processing']);

    expect(plcReceived($order)->assertOk()->getContent())->not->toContain('<!--kbb-placed-->');
});

it('draws nothing when the receipt is opened again later', function () {
    /*
     * From the confirmation email, or a bookmark, a week afterwards. Without
     * the arrival gate the shopper would be congratulated again for something
     * that happened last Tuesday, every time they looked up their order.
     *
     * No `kbb_last_order` in the session and no `confirming` in the address:
     * access is still granted, by the durable view grant mayView() writes, so
     * this is a 200 with the receipt on it and no overlay.
     *
     * MUTATION, run: drop $kbbArrival from the gate → this grows an overlay.
     */
    $order = plcOrder(['payment_method' => 'tamara', 'status' => 'processing', 'paid_at' => now()]);

    $html = test()
        ->withSession(['kbb_viewable_orders' => [$order->order_number]])
        ->get('/checkout/success?order=' . $order->order_number)
        ->getContent();

    expect($html)->not->toContain('<!--kbb-placed-->');
});

it('draws no tick over an order the shop has given up on', function () {
    /*
     * Belt as well as braces: RemoteGateway::returnUrl() only sends an approved
     * shopper to this address, so a `failed` order arriving here is already
     * something going wrong. It must not be met with a celebration.
     *
     * MUTATION, run: treat REFUSED as CONFIRMED in placed-tick → this grows a
     * tick over a dead order.
     */
    $order = plcOrder(['payment_method' => 'tamara', 'status' => 'failed', 'paid_at' => null]);

    expect(plcReceived($order)->assertOk()->getContent())->not->toContain('<!--kbb-placed-->');
});

/* ═════════════════ 6. the return leg, after a decline ══════════════════════ */

it('sends a declined shopper somewhere usable, with the reason on it', function () {
    /*
     * WHAT THIS REPLACES. routes/web.php answered GET /checkout/pending with
     *
     *     fn () => redirect(Url::redirect('/checkout/'))
     *
     * — a closure that takes no request, so the order number Tabby and Tamara
     * carefully hand back was thrown away, and a shopper who had just been
     * refused credit landed on an unchanged form with nothing said. The next
     * thing such a shopper does is press Place order again.
     *
     * The basket was marked `converted` inside place()'s transaction and
     * nothing on this leg puts it back, so there is no basket here — which is
     * why /cart/ rather than /checkout/, and why partials/checkout/
     * return-notice exists at all. A flash aimed at /checkout/ would be eaten
     * by that page's own empty-basket redirect without ever being rendered.
     *
     * MUTATION, run: return `redirect('/checkout/')` unconditionally from
     * pending() → the message is consumed by the hop to /cart/ and the shopper
     * sees an empty basket with no explanation, which is the defect.
     */
    CheckoutReturnRoutes::wire(app());

    PaymentProvider::create(['id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $order = plcOrder(['payment_method' => 'tamara', 'status' => 'pending', 'paid_at' => null]);

    $response = test()
        ->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/pending?order=' . $order->order_number);

    $response->assertRedirect()
        ->assertSessionHasErrors();

    expect($response->headers->get('Location'))->toContain('/cart');

    $errors = session('errors')->getBag('default')->first();

    /* The GATEWAY's own title, which is what the checkout printed beside its
       radio button — not the `payment_providers` row's, which the owner may
       have renamed for the admin's own lists. */
    expect($errors)->toContain(app(GatewayRegistry::class)->find('tamara')->title())
        ->and($errors)->toContain('Nothing has been charged.');
});

it('never tells a shopper whose payment went through that it failed', function () {
    /*
     * A provider that sends an APPROVED order to its cancel address — a
     * mis-click on their page, a retry, a webhook that landed first — must not
     * produce "your payment was not completed" over an order that is paid for.
     * That shopper is forwarded to their receipt, which is where the success
     * leg would have put them.
     *
     * MUTATION, run: delete the CONFIRMED branch from pending() → this
     * redirects to /cart with an error over a paid order.
     */
    CheckoutReturnRoutes::wire(app());

    $order = plcOrder(['payment_method' => 'tamara', 'status' => 'processing', 'paid_at' => now()]);

    $response = test()
        ->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/pending?order=' . $order->order_number);

    $response->assertRedirect()->assertSessionHasNoErrors();

    expect($response->headers->get('Location'))
        ->toContain('/checkout/success')
        ->toContain('order=' . $order->order_number);
});

it('writes nothing at all, because it is a GET', function () {
    /*
     * A GET that changes the state of an order is a GET that a link prefetcher,
     * a corporate mail scanner or an antivirus extension can fire on the
     * shopper's behalf. The obvious thing to do here — mark the order `failed`,
     * hand the stock and the coupon back, restore the basket the way
     * cardAbandoned() does — belongs behind a POST, and the stock path is
     * another lane's this round.
     *
     * MUTATION, run: add an OrderStatus::moveTo($order, 'failed') to pending()
     * → the status assertion below goes red.
     */
    CheckoutReturnRoutes::wire(app());

    $order = plcOrder(['payment_method' => 'tabby', 'status' => 'pending', 'paid_at' => null]);

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED',
        'status' => 'converted', 'converted_at' => now(), 'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    test()->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/pending?order=' . $order->order_number);

    expect($order->fresh()->status)->toBe('pending')
        ->and($order->fresh()->paid_at)->toBeNull()
        ->and($cart->fresh()->status)->toBe('converted');
});

it('says the same thing for an order number that is not this session\'s', function () {
    /*
     * ORDER NUMBERS ARE SEQUENTIAL — see CheckoutController::nextOrderNumber()
     * — so a page that answered differently for a real number than for a
     * made-up one would say which numbers exist. Both get the generic sentence
     * and the same destination, and neither names a gateway, because naming one
     * would itself be an answer about a real order.
     *
     * MUTATION, run: look the order up by number alone, without the
     * hash_equals against kbb_last_order → this reads "Tamara" for the real
     * one and the two answers stop matching.
     */
    CheckoutReturnRoutes::wire(app());

    PaymentProvider::create(['id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $someoneElses = plcOrder(['payment_method' => 'tamara', 'status' => 'pending']);

    $real = test()->get('/checkout/pending?order=' . $someoneElses->order_number);
    $realMessage = session('errors')->getBag('default')->first();

    test()->flushSession();

    $invented = test()->get('/checkout/pending?order=KBB-NOT-A-REAL-ORDER');
    $inventedMessage = session('errors')->getBag('default')->first();

    expect($realMessage)->toBe($inventedMessage)
        ->and($realMessage)->not->toContain('Tamara')
        ->and($real->headers->get('Location'))->toBe($invented->headers->get('Location'));
});

it('prints the reason on the basket page, and nothing there without one', function () {
    /*
     * The other half of the case above: a message flashed at a page that does
     * not render it is a message nobody reads. /cart/ had no notice band at
     * all, and adding one that draws on EVERY visit would have moved a page
     * this suite compares byte for byte.
     *
     * MUTATION, run: delete the @include from store/cart.blade.php → the first
     * assertion goes red. Remove the @if from return-notice → the second does.
     */
    $withError = test()
        ->withSession(['errors' => (new Illuminate\Support\ViewErrorBag)->put(
            'default',
            new Illuminate\Support\MessageBag(['payment' => ['Your payment was not completed at Tamara.']])
        )])
        ->get('/cart/')
        ->assertOk()
        ->getContent();

    expect($withError)->toContain('Your payment was not completed at Tamara.')
        ->and($withError)->toContain('<div class="co-note err" role="alert">');

    /*
     * A FRESH SESSION, and it is not tidiness. Laravel's test client keeps the
     * session between calls inside one test, so without this the second request
     * carries the error bag the first one was given and the assertion passes
     * for the wrong reason — which is the lesson StorefrontEnglishUnchanged-
     * Test's englishFreshSession() already records at length.
     */
    test()->flushSession();

    expect(test()->get('/cart/')->assertOk()->getContent())
        ->not->toContain('<div class="co-note err" role="alert">');
});

/* ══════════════════ 7. the card and the wallets drive it too ═══════════════ */

it('is driven by the card and the wallet legs, and every call is guarded', function () {
    /*
     * The owner named four methods: "COD or stripe, apple pay or google pay".
     * COD and the redirect gateways go through this partial's own listener; the
     * other three are stripe-elements' and express-wallets', which own their
     * payments and say out loud where they have got to.
     *
     * EVERY CALL IS GUARDED, and that is the assertion that matters. A payment
     * path may not depend on a decoration: a shop whose overlay is missing — an
     * older package, a view cache that did not clear — must still take a card.
     *
     * MUTATION, run: call `window.KBB.placing.begin()` unguarded in
     * stripe-elements → "an unguarded window.KBB.placing call".
     */
    $card = plcSource('stripe-elements');
    $wallets = plcSource('express-wallets');

    expect($card)->toContain('function overlay()')
        ->and($card)->toContain('(window.KBB && window.KBB.placing) || null')
        ->and($card)->toContain('if (ov) { ov.begin(); }')
        ->and($card)->toContain('if (ov) { ov.dismiss(); }')
        ->and($card)->toContain('if (ov) { ov.confirmed(handle.success_url); return; }')
        ->and($card)->toContain('if (ov) { ov.leaving(placed.body.url); return; }');

    expect($wallets)->toContain('(window.KBB && window.KBB.placing) || null')
        ->and($wallets)->toContain('if (ov) { ov.confirmed(placed.body.success_url); return; }');

    foreach ([$card, $wallets] as $src) {
        expect(preg_match('/(?<!&& )window\.KBB\.placing\./', $src))
            ->toBe(0, 'an unguarded window.KBB.placing call');
    }
});

it('takes the overlay down before the bank\'s own 3-D Secure step', function () {
    /*
     * THE ONE THAT WOULD HAVE COST A PAYMENT.
     *
     * The overlay marks every other child of <body> `inert` while it is up.
     * Stripe answers confirmCardPayment by injecting its 3-D Secure challenge
     * into this document, and `inert` is exactly the property that would make a
     * challenge injected into one of those subtrees unanswerable — a shopper
     * who cannot answer their bank is a shopper whose payment cannot complete.
     *
     * So it comes down for that step, and the buttons stay disabled because the
     * overlay restores each one to the value it FOUND rather than enabling it.
     *
     * MUTATION, run: delete the `if (ov) { ov.dismiss(); }` above
     * confirmCardPayment → this goes red on the ordering assertion.
     */
    $card = plcSource('stripe-elements');

    $dismiss = strrpos(substr($card, 0, strpos($card, 'await stripe.confirmCardPayment')), 'if (ov) { ov.dismiss(); }');

    expect($dismiss)->not->toBeFalse('nothing takes the overlay down before 3-D Secure');

    // And the restore-what-you-found rule the above depends on.
    expect(plcSource('placing-overlay'))
        ->toContain('wasDisabled.forEach(function (row) { row[0].disabled = row[1]; });');
});

it('shows no overlay behind a native payment sheet', function () {
    /*
     * Apple Pay and Google Pay open the BROWSER'S OWN sheet, which is not our
     * user interface: we cannot layer with it, blur it or compete with it. A
     * "Placing your order…" card frozen underneath it, or left up while the
     * shopper is picking a card in it, is exactly the mess this feature exists
     * to prevent.
     *
     * So express-wallets raises the overlay at ONE point — after Stripe has
     * said `succeeded`, with the sheet finished and a real wait ahead while the
     * shop is told — and at no point before it. This pins that: the only
     * begin() in that file comes after the intent check.
     *
     * MUTATION, run: move `ov.begin()` up to the top of the confirm handler →
     * this goes red on the ordering assertion.
     */
    $wallets = plcSource('express-wallets');

    $begin = strpos($wallets, 'if (ov) { ov.begin(); }');
    $intentCheck = strpos($wallets, "intent.status !== 'succeeded'");

    expect($begin)->not->toBeFalse()
        ->and($intentCheck)->not->toBeFalse()
        ->and($begin)->toBeGreaterThan($intentCheck, 'the overlay goes up before the payment sheet is finished')
        ->and(substr_count($wallets, 'ov.begin()'))->toBe(1, 'the wallet leg raises the overlay more than once');
});

/* ════════════════ 7b. the double submission, and the tick's one door ═══════ */

it('freezes the page before the request leaves, not after it comes back', function () {
    /*
     * THE WHOLE POINT OF FREEZING, and it is an ORDERING property rather than a
     * feature: the overlay and the disabled buttons have to be in place in the
     * SAME TICK as the press, before anything crosses the network. Raised after
     * the answer came back, they would be decoration over a double submission
     * that had already happened.
     *
     * begin() is what does both — disable(true) then freeze(true) — and place()
     * calls it first and bails if it returns false, which is what makes a second
     * press a no-op rather than a second order.
     *
     * MUTATION, run: move `if (!begin()) { return; }` below the post() call →
     * this goes red on the ordering assertion. And with it moved, two presses
     * 50ms apart post twice.
     */
    $src = plcSource('placing-overlay');

    $begin = strpos($src, 'function place() {');
    $guard = strpos($src, 'if (!begin()) { return; }', $begin);
    $post = strpos($src, 'post().then(', $begin);

    expect($guard)->not->toBeFalse('place() does not take the lock at all')
        ->and($post)->not->toBeFalse()
        ->and($guard)->toBeLessThan($post, 'the request leaves before the page is frozen');

    // And begin() locks before it paints, so there is no frame in which the
    // overlay is up and the buttons are still live.
    $body = substr($src, strpos($src, 'function begin(opts) {'));

    expect(strpos($body, 'disable(true);'))->toBeLessThan(strpos($body, "box.classList.add('is-up');"))
        ->and(strpos($body, 'freeze(true);'))->toBeLessThan(strpos($body, "box.classList.add('is-up');"));
});

it('stops the old full-page post from also running', function () {
    /*
     * resources/js/kbb/checkout.js answers [data-place] with a delegated BUBBLE
     * listener that ends in form.submit(). If both ran, a shopper would get a
     * fetch() AND a native form post for the same press — two orders, and the
     * second one arriving to find its own cart already `converted`.
     *
     * stopPropagation() in the capture phase is what prevents it, and it is
     * called before any branch can return early: an invalid form must stop the
     * old path just as firmly as a valid one.
     *
     * MUTATION, run: delete the stopPropagation() → the ordering assertion goes
     * red, and pressing the button places the order twice.
     */
    $src = plcSource('placing-overlay');
    $handler = substr($src, strpos($src, "if (!event.target.closest || !event.target.closest('[data-place]')) { return; }"));

    $stop = strpos($handler, 'event.stopPropagation();');
    $firstBranch = strpos($handler, 'if (busy) { return; }');

    expect($stop)->not->toBeFalse('nothing stops checkout.js from also submitting')
        ->and($stop)->toBeLessThan($firstBranch, 'an early return can leave the old form post running');

    // Capture phase, which is what makes it run before the bubble listener.
    expect($src)->toContain("  }, true);");
});

it('draws the tick in exactly one place, and that place is the server\'s answer', function () {
    /*
     * "Never show the tick before the server has confirmed." The mark is keyed
     * entirely off the `is-done` class, so the question is how many places can
     * add it — and there is one, confirmed(), which is reached from the
     * `action === "placed"` branch and from the API the card and wallet legs
     * call after Stripe has said `succeeded`.
     *
     * A second `classList.add('is-done')` anywhere — a "show it optimistically
     * and correct it later" — is the defect this counts.
     *
     * MUTATION, run: add `box.classList.add('is-done')` inside begin() → the
     * count is 2, and every shopper sees a tick the instant they press.
     */
    $src = plcSource('placing-overlay');

    expect(substr_count($src, "classList.add('is-done')"))->toBe(1, 'the tick can be drawn from more than one place');

    $confirmed = strpos($src, 'function confirmed(url) {');
    $add = strpos($src, "classList.add('is-done')");

    expect($add)->toBeGreaterThan($confirmed, 'the tick is drawn outside confirmed()');

    /* And the return leg's tick is the server's word too: `is-done` appears in
     * placed-tick ONLY in the branch PlacementState called CONFIRMED. */
    expect(substr_count(plcSource('placed-tick'), 'is-done'))->toBe(1);
});

/* ═══════════════════════════ 8. the wiring pin ═════════════════════════════ */

it('requires routes/checkout-return.php from routes/web.php exactly once', function () {
    /*
     * ▲ THIS IS RED UNTIL THE INTEGRATOR MAKES THE EDIT, and that is the
     * FINISHED state being pinned rather than an absence — CLAUDE.md records
     * three rounds lost to lanes asserting `->not->toContain(...)` instead,
     * which goes red the moment the integrator does the one thing the lane
     * asked for.
     *
     * THE EDIT. routes/web.php line 185 currently reads, verbatim:
     *
     *     Route::get('/checkout/pending', fn () => redirect(\App\Support\Url::redirect('/checkout/')))->name('checkout.pending');
     *
     * Delete that line and put this in its place:
     *
     *     require __DIR__.'/checkout-return.php';
     *
     * 0 is "built, never wired up", which is what routes/checkout-card.php was
     * for twelve days. 2 registers the route twice, and Laravel keeps the LAST
     * registration, so the require order would silently decide which controller
     * serves the shop.
     */
    $web = file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/checkout-return.php';"))->toBe(
        1,
        'routes/checkout-return.php must be required exactly once from routes/web.php — see its header for the exact edit.'
    );
});
