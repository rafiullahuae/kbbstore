<?php

declare(strict_types=1);

/**
 * PUTTING THE BASKET BACK AFTER A PAYMENT THAT DID NOT COMPLETE. (Lane PLC, round 2)
 *
 * ── THE DEFECT, WHICH THIS LANE NAMED AND THEN HAD TO LIVE WITH FOR A ROUND ─
 *
 * CheckoutController::place() marks the basket `converted` inside the same
 * transaction that writes the order, and until now nothing on the return leg
 * put it back. So a shopper who pressed the back arrow at Tamara, or whose
 * instalment plan was declined, came home to an EMPTY BASKET and an order
 * sitting `pending` — holding their stock and their coupon — with no way from
 * the shop to undo either. The last round made the page honest about what had
 * happened; this one gives them the way out.
 *
 * ── AND IT IS A BUTTON, NOT A SCRIPT THAT FIRES ON ARRIVAL ─────────────────
 *
 * The write does more than restore a basket: it moves the order to `failed`,
 * and OrderStatus hands the stock and the coupon back with it. Two things make
 * that the shopper's press rather than the page's:
 *
 *   - a shopper who backed out at the provider can still go back and finish,
 *     and a plan they have not cancelled is still theirs to approve;
 *   - abandoning a payment is a decision, and restoring a basket over it is
 *     the shop arguing with them.
 *
 * The cases below drive the button, not a function.
 */

use App\Http\Controllers\Store\CheckoutReturnController;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\CartService;
use App\Services\Orders\OrderTransitionStock;
use Illuminate\Support\Facades\DB;
use Tests\Support\SqlShape;
use Illuminate\Support\Str;

/* ═══════════════════════════════════ fixtures ══════════════════════════════ */

function rbProduct(string $name, int $stock = 5, bool $manage = true): Product
{
    return Product::create([
        'slug' => 'rb-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9000,
        'stock_status' => 'instock',
        'manage_stock' => $manage,
        'stock' => $stock,
    ]);
}

/** @param list<array{0: Product, 1: int}> $members */
function rbSet(string $name, array $members): Product
{
    $set = Product::create([
        'slug' => 'rb-set-'.Str::random(8),
        'name' => $name,
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 19900,
        'stock_status' => 'instock',
        'manage_stock' => false,
    ]);

    $position = 0;

    foreach ($members as [$member, $quantity]) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $member->id,
            'quantity' => $quantity,
            'position' => $position++,
        ]);
    }

    return $set->fresh();
}

/**
 * Exactly the state a shopper is in while they are away at Tamara: a basket
 * marked `converted` and an order sitting `pending` against it.
 *
 * @param  list<array{0: Product, 1: int}>  $lines
 * @return array{0: Cart, 1: Order}
 */
function rbAwayAtTheProvider(array $lines, array $orderOverrides = []): array
{
    $cart = Cart::create([
        'token' => Str::random(32),
        'currency' => 'AED',
        'status' => 'converted',
        'converted_at' => now(),
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    foreach ($lines as [$product, $quantity]) {
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => (int) $product->price,
        ]);
    }

    $order = Order::create(array_merge([
        'order_number' => 'RB'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 9000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 11000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'tamara',
        'payment_method_title' => 'Pay later with Tamara',
        'paid_at' => null,
        'shipping_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk',
            'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ],
    ], $orderOverrides));

    return [$cart->fresh(['items']), $order];
}

/** A request as the shopper's own browser makes it, carrying their cart cookie. */
function rbAs(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/**
 * An error bag sitting in the session, exactly as `redirect()->withErrors('...')`
 * leaves one: `parseErrors()` wraps a bare string in a MessageBag under the
 * `default` key, and `$errors->first()` is what the page then prints.
 *
 * Used by the stale-offer case below, which needs A flashed error on the basket
 * page and does not care which of the shop's redirects put it there.
 */
function rbFlashedError(string $message): Illuminate\Support\ViewErrorBag
{
    return (new Illuminate\Support\ViewErrorBag)
        ->put('default', new Illuminate\Support\MessageBag([$message]));
}

/** The whole return leg: the provider sends them back, and the page they land on. */
function rbComeHome(Cart $cart, Order $order)
{
    test()->withSession(['kbb_last_order' => $order->order_number]);

    return rbAs($cart)->get('/checkout/pending?order='.$order->order_number);
}

/* ══════════════════════ 1. the button is there, and it works ═══════════════ */

it('offers the button on the page a declined shopper lands on', function () {
    /*
     * THE WHOLE POINT OF THE ROUND, in one walk: the provider sends them to
     * /checkout/pending, that lands them on a basket page which is empty
     * because place() converted their cart, and the way back is on it.
     *
     * MUTATION, run: delete the rememberRestorable() call from pending() →
     * the button is not there and the shopper has no way back at all, which is
     * exactly the state this lane shipped last round.
     */
    PaymentProvider::create(['id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order)->assertRedirect();

    expect(session(CheckoutReturnController::RESTORABLE_KEY))->toBe($order->order_number);

    $html = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    expect($html)->toContain('Put my basket back')
        ->and($html)->toContain('/checkout/restore-basket')
        ->and($html)->toContain('Your payment was not completed')
        // A POST, said in the markup and not only in the routes file.
        ->and($html)->toContain('<form method="post"');
});

it('puts the basket back and lets the order go, in one press', function () {
    /*
     * MUTATION, run: drop the cart write out of the transaction in restore()
     * and throw after the moveTo → the order is `failed` and the basket is
     * still `converted`, which is a shopper with no basket and no order.
     */
    $product = rbProduct('Rice Toner', 5);

    [$cart, $order] = rbAwayAtTheProvider([[$product, 2]]);

    rbComeHome($cart, $order);

    $response = rbAs($cart)->post('/checkout/restore-basket');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/cart');

    expect($cart->fresh()->status)->toBe('active')
        ->and($cart->fresh()->converted_at)->toBeNull()
        ->and($order->fresh()->status)->toBe('failed');

    // And the basket really is theirs again: the cart page draws the line.
    $html = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    /*
     * ▲ THE CART LINE'S OWN MARKUP, NOT THE BARE NAME. Measured on this page:
     * 'Rice Toner' occurs TWICE, once in the cart line
     *
     *     <div class="cn"><a href="/product/…">Rice Toner</a></div>
     *
     * and once in the CART DRAWER's `.kc-nm`, which every page of this shop
     * renders. So `toContain('Rice Toner')` was satisfied by the drawer alone
     * and would have stayed green with the basket table gone — the assertion
     * could not see the thing it was written about.
     */
    expect($html)->toContain('<div class="cn"><a href="/product/'.$product->slug.'/">Rice Toner</a></div>')
        ->and($html)->toContain('Your basket is back')
        ->and($html)->not->toContain('Put my basket back');
});

it('hands the stock back, through the funnel that owns it', function () {
    /*
     * The order was holding units. `failed` is in
     * OrderTransitionStock::RETURNS_STOCK, so moving it there releases them —
     * this lane does not decrement anything itself and must not.
     *
     * MUTATION, run: change the target status in restore() from 'failed' to
     * 'onhold' → the units stay held and this goes red on both assertions.
     */
    $product = rbProduct('Rice Toner', 3);

    [$cart, $order] = rbAwayAtTheProvider([[$product, 2]]);

    // The claim the placing transaction made, made here the same way.
    app(CartService::class)->claimStock($cart, $order);

    expect($product->fresh()->stock)->toBe(1, 'the fixture did not actually claim the stock');

    rbComeHome($cart, $order);
    rbAs($cart)->post('/checkout/restore-basket');

    expect($product->fresh()->stock)->toBe(3)
        ->and(in_array('failed', OrderTransitionStock::RETURNS_STOCK, true))->toBeTrue();
});

it('finds the basket after the cookie has moved on, which it always has', function () {
    /*
     * ▲ THE ONE THE SCREENSHOT FOUND, AND NO OTHER CASE IN THIS FILE COULD.
     *
     * Every case above hands the same cart cookie to every request, which is
     * not what a browser does. Measured in Chromium against the preview:
     *
     *     after add            cookie eyJpdiI6IlVF…
     *     after the failed place   cookie eyJpdiI6IkRX…
     *     after /checkout/pending -> /cart/   cookie eyJpdiI6Imxn…   NEW, and
     *                              a fresh empty `active` cart row with it
     *
     * The basket page asks CartService for a cart with create:true, finds none
     * active for the `converted` token, mints an empty one and re-cookies the
     * browser on the way in. So the offer is written on a request where the
     * cookie is still right, and the PRESS arrives one request later carrying a
     * token that names an empty cart. Looking the basket up by the live cookie
     * at that point answers "There is nothing to put back" — which is exactly
     * what the button did, over a basket sitting one row away.
     *
     * The token is therefore remembered WITH the offer, and CartService::adopt()
     * hands it back to the browser afterwards.
     *
     * MUTATION, run: read the token from $request->cookie(CartService::COOKIE)
     * in restore() instead of from the session → this goes red with the
     * "nothing to put back" sentence, which is the defect verbatim.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);

    // The empty cart the basket page gives them on the way in, and its cookie.
    $fresh = Cart::create([
        'token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    $response = rbAs($fresh)->post('/checkout/restore-basket');

    expect($cart->fresh()->status)->toBe('active', 'the basket the offer was made for was not found')
        ->and($order->fresh()->status)->toBe('failed');

    /* AND THE BROWSER IS POINTED BACK AT IT. Without the adopt() the row is
       live and the shopper is still carrying the empty cart's token, so they
       land on /cart/ and see nothing — a restore that restored nothing they
       can see. The cookie on the response is the assertion. */
    /* assertPlainCookie, not assertCookie: these requests run with
       EncryptCookies removed — see rbAs() — so the queued value is the raw
       token, and assertCookie would try to decrypt it and throw. */
    $response->assertPlainCookie(CartService::COOKIE, $cart->token);
});

/* ══════════════ 2. the set case the coordinator asked for by name ══════════ */

it('does not throw a good offer away when the shopper comes back to the return address', function () {
    /*
     * ▲ FOUND BY THE SHOT RUN, IN CHROMIUM, AND IT IS THE THIRD FACE OF THE
     * SAME COOKIE. The shot script re-armed the offer by visiting
     * /checkout/pending a second time and the run printed "(no restore button
     * on the page — the offer was not written)" — which is the shop, not the
     * script.
     *
     * rememberRestorable() FORGOT BOTH KEYS FIRST and then re-derived the
     * basket from the LIVE cookie. By a second visit that cookie has moved on:
     * the basket page mints an empty `active` cart for the `converted` token
     * and re-cookies the browser (see the case above). So the second visit
     * found nothing to offer, having just discarded an offer that was still
     * perfectly good — and the remembered token with it, which is the only
     * handle on that basket there is. The button was gone for good, on a
     * basket sitting one row away, for a shopper whose only mistake was the
     * Back button.
     *
     * REACHABLE THREE WAYS: the browser's Back button onto the return address,
     * a provider that sends the shopper twice, and a reload of
     * /checkout/pending itself.
     *
     * An offer that still names THIS order and whose remembered basket is
     * still `converted` with rows in it now SURVIVES the visit, and nothing is
     * re-derived over it. Same one query either way.
     *
     * MUTATION, run: forget the two keys unconditionally at the top of
     * rememberRestorable() again → both session assertions below go red and
     * the press answers "there is nothing to put back".
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);

    // The empty cart the basket page minted on the way in, and its cookie.
    $fresh = Cart::create([
        'token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    // The provider's return address a second time, carrying the NEW cookie.
    rbAs($fresh)->get('/checkout/pending?order='.$order->order_number)->assertRedirect();

    expect(session(CheckoutReturnController::RESTORABLE_KEY))->toBe($order->order_number)
        ->and(session(CheckoutReturnController::RESTORABLE_CART_KEY))->toBe($cart->token);

    // And the way back still works, which is the whole point of keeping it.
    rbAs($fresh)->post('/checkout/restore-basket');

    expect($cart->fresh()->status)->toBe('active')
        ->and($order->fresh()->status)->toBe('failed');
});

it('drops a held offer once its basket has gone, rather than drawing a dead button', function () {
    /*
     * THE OTHER HALF OF KEEPING AN OFFER, and it is what stops the fix above
     * from becoming the defect it repaired. An offer is kept only while its
     * REMEMBERED basket is still `converted` with rows in it — so a basket that
     * has already been put back in another tab, or one the abandoned-cart sweep
     * has taken, drops the offer instead of leaving a button whose only
     * possible answer is "There is nothing to put back".
     *
     * MUTATION, run: keep the offer on the order number alone — drop
     * `&& $this->stillRestorable($held)` from rememberRestorable() → the offer
     * survives a basket that is no longer there and the button is drawn over
     * it, red on both assertions below.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);

    expect(session(CheckoutReturnController::RESTORABLE_KEY))->toBe($order->order_number);

    // Another tab got there first: the basket is live again and is not the
    // `converted` row the offer was written against.
    $cart->forceFill(['status' => 'active', 'converted_at' => null])->save();

    $fresh = Cart::create([
        'token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    rbAs($fresh)->get('/checkout/pending?order='.$order->order_number)->assertRedirect();

    expect(session(CheckoutReturnController::RESTORABLE_KEY))->toBeNull()
        ->and(session(CheckoutReturnController::RESTORABLE_CART_KEY))->toBeNull();

    // And the page says the reason without offering a way back.
    $html = rbAs($fresh)->get('/cart/')->assertOk()->getContent();

    expect($html)->not->toContain('Put my basket back');
});

it('brings a set-and-loose basket back THROUGH the reconciler, not around it', function () {
    /*
     * ▲ THE ONE THAT WOULD HAVE RE-CREATED THE OWNER'S OWN BUG.
     *
     * Lane SEC fixed a basket holding a set AND one of its members as its own
     * line: with one jar on the shelf, both claims wanted it and the order could
     * not be placed at all. App\Services\SetStockReconciler settles it — the
     * loose line goes, the set stays — and it runs when the CART PAGE and the
     * CHECKOUT are RENDERED, never inside place().
     *
     * A basket that has been away at Tamara can come back to a shelf that moved
     * under it: somebody else bought the last jar while they were gone. So a
     * restore that handed the basket back and sent the shopper anywhere that
     * does not render it would hand back precisely the unplaceable basket the
     * owner reported. restore() redirects to /cart/ for exactly this reason.
     *
     * MUTATION, run: redirect from restore() to a page that does not
     * reconcile — anything but /cart/ or /checkout/ — and the loose line is
     * still there, the set is still there, and place() refuses the order.
     */
    $toner = rbProduct('1025 Dokdo Toner', 1);
    $set = rbSet('Medicube booster set', [[$toner, 1]]);

    [$cart, $order] = rbAwayAtTheProvider([[$set, 1], [$toner, 1]]);

    expect($cart->items()->count())->toBe(2);

    rbComeHome($cart, $order);

    $response = rbAs($cart)->post('/checkout/restore-basket');

    // Where they are sent is the load-bearing half.
    expect($response->headers->get('Location'))->toContain('/cart');

    $html = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    $lines = $cart->fresh(['items'])->items;

    expect($lines)->toHaveCount(1, 'the loose line survived the round trip')
        ->and((int) $lines->first()->product_id)->toBe($set->id, 'the set was taken and the loose line kept')
        /*
         * AND THE SHOPPER IS TOLD, IN THE RECONCILER'S OWN BAND.
         *
         * The two bare names were worth nothing here. Measured on this page
         * they occur FOUR times each — the notice, the set-contents list under
         * the basket line, Lane SEC's `.kc-note` in the drawer, and the
         * drawer's own set list — so both assertions stayed green whether or
         * not the shopper was told anything at all. The band and the sentence
         * together are drawn by one thing.
         */
        ->and($html)->toContain('<div class="woocommerce-info" role="status">1025 Dokdo Toner has been taken out of your bag: the last of it is inside the Medicube booster set you are buying');
});

/* ═════════════════════ 3. everything it has to refuse ══════════════════════ */

it('leaves the basket alone when the payment confirms in the middle of the press', function () {
    /*
     * ▲ THE ROW MOVED UNDER A SHOPPER WHO WAS STANDING ON IT, and this is the
     * one arrangement of that where the shop got it wrong.
     *
     * restore() asks `paid_at` twice on purpose — once for a good message, and
     * again as `only: ['paid_at' => null]` inside moveTo(), which re-reads the
     * row under `lockForUpdate` and writes NOTHING if a webhook has confirmed
     * the payment since. That half was right.
     *
     * The other half was not. moveTo() returns null when its precondition does
     * not hold, and restore() assigned that to `$moved` AND NEVER READ IT: the
     * cart write sat in the same transaction, unconditional, so the basket went
     * back to `active` over an order that had just been PAID. The shopper ends
     * up holding a live basket of the same goods they have been charged for,
     * the stock is never released because the order never moved, and the next
     * thing they do is buy it all again.
     *
     * A guarded write that leaves state behind does not contain a failure, it
     * seeds one — CLAUDE.md, about the updater, and the same sentence fits here.
     *
     * THE RACE IS PRODUCED, NOT WAITED FOR. The two reads are distinguishable
     * in SQL: restore()'s guard looks the order up by `order_number`, moveTo()
     * by key. A beforeExecuting hook on the second one is exactly the instant
     * the webhook has to land in for this to happen at all.
     *
     * MUTATION, run: drop the `$moved === null` check from restore() → the
     * basket assertion goes red and the shopper has both.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 2]]);

    rbComeHome($cart, $order);

    $fired = false;

    DB::beforeExecuting(function (string $query) use (&$fired, $order): void {
        /* THROUGH SqlShape::portable(), because MySQL spells these identifiers
           with backticks and the default lane spells them with double quotes.
           Matched raw, this hook would never fire under -c phpunit-mysql.xml,
           the webhook write would never land, and the case would pass while
           proving nothing at all — which SqlNeedleDialectGuardTest caught in
           this very file before it was committed. */
        $sql = SqlShape::portable($query);

        if ($fired || ! str_contains($sql, 'from "orders"') || ! str_contains($sql, '"orders"."id"')) {
            return;
        }

        // Once, and before moveTo()'s locked read returns.
        $fired = true;

        DB::table('orders')->where('id', $order->id)->update([
            'paid_at' => now(),
            'status' => 'processing',
        ]);
    });

    $response = rbAs($cart)->post('/checkout/restore-basket');

    /* Disarmed by hand: beforeExecuting callbacks live on the CONNECTION, not
       on the test, so one left armed would fire inside somebody else's case.

       NOT ASSERTED HERE, because `expect($fired)->toBeTrue()` one line after
       assigning it true is exactly the shape this round is about — an
       assertion that cannot fail. That the hook fired is proved below by
       `paid_at`, which only the hook could have written. */
    $fired = true;

    $after = $order->fresh();

    expect($after->paid_at)->not->toBeNull('the webhook write did not land, so this case proves nothing')
        ->and($after->status)->toBe('processing', 'moveTo let the order go despite paid_at')
        // THE ASSERTION THIS CASE EXISTS FOR.
        ->and($cart->fresh()->status)->toBe('converted', 'the basket was handed back over a paid order')
        ->and($cart->fresh()->converted_at)->not->toBeNull();

    // And they are told where their order is rather than shown a restored bag.
    expect($response->headers->get('Location'))->toContain('/checkout/success');
});

it('still hands the basket back when the provider failed the order first', function () {
    /*
     * ▲ THE CASE THE FIX ABOVE COULD HAVE BROKEN, AND THE REASON moveTo()'s
     * RETURN IS NOT THE TEST.
     *
     * Tamara's failure webhook can land before the shopper gets home, so the
     * order is ALREADY `failed` when they press the button. moveTo() then
     * writes nothing and returns null — "already there with nothing else to
     * record" — which is the very same null the confirmed-mid-press race
     * returns. Refusing on null would have left this shopper, who is owed
     * their basket and has been charged nothing, holding an empty one.
     *
     * The row answers both: over, and took no money.
     *
     * MUTATION, run: make restore() refuse on `moveTo() === null` instead of on
     * the locked row → this goes red while the race case above stays green,
     * which is the pair that makes either assertion mean anything.
     */
    $product = rbProduct('Rice Toner', 3);

    [$cart, $order] = rbAwayAtTheProvider([[$product, 2]], ['status' => 'failed']);

    rbComeHome($cart, $order);

    rbAs($cart)->post('/checkout/restore-basket');

    expect($cart->fresh()->status)->toBe('active', 'a shopper the provider failed got no basket back')
        ->and($cart->fresh()->converted_at)->toBeNull()
        ->and($order->fresh()->status)->toBe('failed');
});

it('offers no way back for an order whose payment was reversed', function () {
    /*
     * THE OTHER ORDER OF THE SAME TWO EVENTS, and here the shop was already
     * right — pinned so it stays that way.
     *
     * A reversal leaves `failed` sitting on a row that still carries `paid_at`;
     * PlacementState says so in its own header and tests the refused statuses
     * FIRST for exactly this reason. So pending() does not forward this shopper
     * to a receipt (the order is REFUSED, not CONFIRMED) — and
     * rememberRestorable() offers no button either, because `paid_at` is set
     * and money moved. Releasing the stock and the coupon off a button, for an
     * order that took a payment, is the one thing this page must never do.
     *
     * MUTATION, run: delete the `$order->paid_at !== null` clause from
     * rememberRestorable()'s first guard → the button is offered over a
     * reversed payment and this goes red.
     */
    PaymentProvider::create(['id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]], [
        'status' => 'failed',
        'paid_at' => now(),
    ]);

    rbComeHome($cart, $order)->assertRedirect();

    expect(session(CheckoutReturnController::RESTORABLE_KEY))->toBeNull()
        ->and(session(CheckoutReturnController::RESTORABLE_CART_KEY))->toBeNull();

    $html = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    expect($html)->not->toContain('Put my basket back');

    // And a press finds nothing, whatever the page drew.
    rbAs($cart)->post('/checkout/restore-basket');

    expect($cart->fresh()->status)->toBe('converted')
        ->and($order->fresh()->status)->toBe('failed');
});

it('refuses an order that has been paid since, and sends them to the receipt', function () {
    /*
     * A Tamara webhook can land while the shopper is still reading the page.
     * The button must not then cancel an order somebody has paid for.
     *
     * MUTATION, run: delete the paid_at / CONFIRMED guard at the top of
     * restore() → the order goes to `failed` and the money is orphaned.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);

    // The webhook lands.
    $order->forceFill(['paid_at' => now(), 'status' => 'processing'])->save();

    $response = rbAs($cart)->post('/checkout/restore-basket');

    expect($response->headers->get('Location'))->toContain('/checkout/success')
        ->and($order->fresh()->status)->toBe('processing')
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and($cart->fresh()->status)->toBe('converted');
});

it('checks paid_at a second time, under a lock, inside the move itself', function () {
    /*
     * The guard above is a courtesy that produces a good message. The one that
     * cannot be raced is `only: ['paid_at' => null]`, which OrderStatus::moveTo()
     * re-reads under lockForUpdate and refuses on — so a webhook landing between
     * this controller's read and its write cannot be overtaken.
     *
     * Asserted on the call, because the race itself cannot be staged in a
     * single-process suite. moveTo()'s own tests cover what `only` does.
     *
     * MUTATION, run: delete the `only:` argument → this goes red, and the
     * second guard is gone with no other test noticing.
     */
    $src = file_get_contents(app_path('Http/Controllers/Store/CheckoutReturnController.php'));
    $restore = substr($src, strpos($src, 'public function restore('));

    expect($restore)->toContain("only: ['paid_at' => null],")
        ->and($restore)->toContain("'failed',");
});

it('will not follow the shopper onto a later order', function () {
    /*
     * ▲ THE DANGEROUS ONE, and the reason the offer carries an order NUMBER
     * rather than a flag.
     *
     * `kbb_last_order` MOVES: place() overwrites it with every order this
     * browser makes. A shopper who abandons at Tamara and then places a second
     * order successfully still has the button on their basket page — and with
     * a boolean offer, pressing it would fail the NEW order, release its stock
     * and its coupon, and hand back a basket for goods they have just bought.
     *
     * ▲ AND THE SECOND ORDER HERE IS `pending`, NOT `processing`, WHICH IS THE
     * WHOLE OF WHY THIS CASE MEANS ANYTHING.
     *
     * It was written with a `processing` second order first, and the mutation
     * below did not bite: PlacementState calls `processing` CONFIRMED, so the
     * paid-guard two lines further down caught it and the order survived by
     * accident, through a check this case is not about. A shopper who abandons
     * one Tamara payment and immediately starts another has a SECOND order
     * sitting `pending` — nothing else in this method protects it, and
     * hash_equals is the only thing standing between the button and an order
     * they are in the middle of paying for.
     *
     * MUTATION, run: replace the hash_equals against $offered with a truthiness
     * test on the session key and look the order up by `kbb_last_order` → the
     * second order below is moved to `failed` while the shopper is at Tamara.
     */
    [$cart, $abandoned] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $abandoned);

    expect(session(CheckoutReturnController::RESTORABLE_KEY))->toBe($abandoned->order_number);

    // A second payment, started and not yet finished, the way place() records it.
    [, $second] = rbAwayAtTheProvider([[rbProduct('Snail Essence'), 1]]);
    test()->withSession([
        'kbb_last_order' => $second->order_number,
        CheckoutReturnController::RESTORABLE_KEY => $abandoned->order_number,
    ]);

    $response = rbAs($cart)->post('/checkout/restore-basket');

    expect($second->fresh()->status)->toBe('pending', 'the button cancelled a later order')
        ->and($abandoned->fresh()->status)->toBe('pending')
        ->and($cart->fresh()->status)->toBe('converted')
        ->and($response->headers->get('Location'))->toContain('/cart');
});

it('keeps the way back on a reload, which is the whole reason the offer is not a flash', function () {
    /*
     * ▲ MEASURED, AND IT CONTRADICTED THE PARTIAL'S OWN DOCBLOCK. The offer is
     * kept in a session value rather than a flash, and rememberRestorable()
     * says why in as many words: "a flash survives exactly one request, so
     * reloading the basket page would take the button away while the basket is
     * still perfectly restorable."
     *
     * It took it away anyway. The partial's outer gate was
     * `@if ($errors->any() || $kbbReturnRestored)`, and the flashed reason is
     * consumed by the first render — so the second GET of /cart/ drew NO band
     * and NO button while `kbb_restorable` was still sitting in the session,
     * naming a `converted` basket with rows in it. Probed in this suite before
     * the fix:
     *
     *     offer still set            RB77263
     *     first render has button    true
     *     reload has button          FALSE
     *     reload has band            FALSE
     *
     * A shopper who reloaded, or wandered off to /shop/ and came back, had no
     * way back to their basket at all — the exact state this round exists to
     * end, one request later.
     *
     * MUTATION, run: drop `|| $kbbReturnRestorable` from the partial's outer
     * @if and the reload assertions below go red while the first render stays
     * green, which is the shape of the defect.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);

    $first = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    expect($first)->toContain('Put my basket back');

    // The same page again, with the flash long consumed.
    $again = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    expect($again)->toContain('Put my basket back')
        ->and($again)->toContain('/checkout/restore-basket')
        // The provider's name came off the flash, so the reload says the
        // sentence that does not claim one.
        ->and($again)->toContain('Your payment was not completed, so your order has not been placed.');

    // And it still works after the reload, which is the point of drawing it.
    rbAs($cart)->post('/checkout/restore-basket');

    expect($cart->fresh()->status)->toBe('active')
        ->and($order->fresh()->status)->toBe('failed');
});

it('draws the basket page\'s own flashed reasons, which nothing drew before', function () {
    /*
     * ▲ A NAMED CONSEQUENCE OF THIS PARTIAL, NOT AN ACCIDENT, and the owner
     * should hear it as a change rather than find it.
     *
     * /cart/ never printed $errors at all before this lane — measured: no
     * `errors` anywhere in store/cart.blade.php at the base commit. One
     * pre-existing redirect already flashed one there and nothing drew it:
     * CheckoutController's empty-basket guard answers a Place order press on a
     * basket that is gone with `redirect('/cart/')->withErrors('Your bag is
     * empty.')`. That is the SAME defect class this whole round is about — a
     * sentence the shop wrote for the shopper and then swallowed — so it is
     * drawn now, in the band beside it.
     *
     * It matters more than tidiness: that redirect is exactly where a declined
     * shopper ends up when they do what round one predicted and press Place
     * order again on the unchanged form. Drawing the band there is what puts
     * "Put my basket back" in front of them on the page they actually reach.
     *
     * MUTATION, run: print the standing sentence unconditionally in the
     * partial — `{{ __('store.checkout.return_not_completed') }}` in place of
     * `{{ $errors->first() ?: __(...) }}` → the flashed reason is swallowed
     * again and this goes red.
     *
     * Dropping `$errors->any()` from the outer @if does NOT bite here and is
     * not the mutation for this case: `kbb_restorable` is set on this walk, so
     * the band still draws through the restorable branch and still prints
     * $errors->first(). It bites on a basket page with a flash and no offer,
     * which is every OTHER redirect that flashes an error to /cart/.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);

    // The empty-basket guard's own flash, in the shape withErrors() leaves it.
    test()->withSession(['errors' => rbFlashedError('Your bag is empty.')]);

    $html = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    /*
     * ▲ THE NEEDLE IS THE BAND AND THE SENTENCE TOGETHER, and it has to be.
     * A bare `toContain('Your bag is empty.')` PASSED THE MUTATION — measured,
     * 2 occurrences on this page — because the cart drawer renders that exact
     * string, period and all, in its own `.empty-d` line on every page of the
     * shop:
     *
     *     <div class="empty-d">Your bag is empty.<br>Add something glowy ✨</div>
     *
     * So the assertion was green whether or not the band drew anything. This
     * needle is produced by nothing but the band.
     */
    expect($html)->toContain('class="co-note err" role="alert">Your bag is empty.')
        // The offer names THIS order, so the way back is offered with it.
        ->and($html)->toContain('Put my basket back');
});

it('does not draw a button that cannot work, once kbb_last_order has moved on', function () {
    /*
     * ▲ A CONTROL THAT DOES NOTHING IS ITS OWN DEFECT, and this lane has paid
     * for that lesson once already: last commit's button answered "There is
     * nothing to put back" over a basket sitting one row away. This is the
     * other half of the same shape, and it was found by reading the partial's
     * gate against restore()'s rather than by a screenshot.
     *
     * restore() refuses an offer that no longer names `kbb_last_order` — the
     * case directly above, which protects a later order from the button. But
     * the PARTIAL drew the button on the strength of `kbb_restorable` being
     * set at all. So the shop offered a way back that its own endpoint was
     * about to refuse, and the shopper's press earned them the generic
     * "There is nothing to put back" over a basket that really was there.
     *
     * REACHABLE, not theoretical. place() overwrites `kbb_last_order` on
     * every order and never clears the offer, so a shopper who abandons at
     * Tamara and then places a second order carries a stale one. Its own
     * empty-basket guard — CheckoutController, 'Your bag is empty.' — then
     * redirects to /cart/ WITH an error, which is the flash used here, in the
     * shape redirect()->withErrors() leaves it.
     *
     * MUTATION, run: gate the partial on the session key alone again
     * (`trim((string) session(RESTORABLE_KEY, '')) !== ''`) and the button is
     * back on a page where pressing it is refused — red on both needles.
     */
    [$cart, $abandoned] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $abandoned);

    // The second order, which is the one `kbb_last_order` now names.
    [, $second] = rbAwayAtTheProvider([[rbProduct('Snail Essence'), 1]]);

    test()->withSession([
        'kbb_last_order' => $second->order_number,
        CheckoutReturnController::RESTORABLE_KEY => $abandoned->order_number,
        CheckoutReturnController::RESTORABLE_CART_KEY => $cart->token,
        'errors' => rbFlashedError('Your bag is empty.'),
    ]);

    $html = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    /*
     * The sentence is still drawn — this case is about the button, not the
     * band, and a stale offer must not swallow the shopper's reason. The needle
     * is the band together with the sentence because the cart drawer renders
     * 'Your bag is empty.' on its own account; see the case above.
     *
     * It also pins the band for a flash with NO usable offer, which is what
     * every other redirect that flashes an error to /cart/ produces, and which
     * nothing else in this file reaches.
     */
    expect($html)->toContain('class="co-note err" role="alert">Your bag is empty.')
        ->and($html)->not->toContain('Put my basket back')
        ->and($html)->not->toContain('/checkout/restore-basket');
});

it('says the same thing for an order number that is not this session\'s', function () {
    /*
     * The endpoint takes no authentication, and order numbers are sequential.
     * A press with no offer, a press with somebody else's order and a press
     * after the basket has already come back all get one sentence and one
     * destination.
     *
     * MUTATION, run: return a different message when the order exists but is
     * not this session's → the two answers below stop matching.
     */
    [$cart, $someoneElses] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    $noOffer = rbAs($cart)->post('/checkout/restore-basket');
    $noOfferMessage = session('errors')->getBag('default')->first();

    test()->flushSession();

    test()->withSession([
        'kbb_last_order' => 'RB-NOT-A-REAL-ORDER',
        CheckoutReturnController::RESTORABLE_KEY => 'RB-NOT-A-REAL-ORDER',
    ]);

    $invented = rbAs($cart)->post('/checkout/restore-basket');
    $inventedMessage = session('errors')->getBag('default')->first();

    expect($noOfferMessage)->toBe($inventedMessage)
        ->and($noOfferMessage)->toContain('nothing to put back')
        ->and($noOffer->headers->get('Location'))->toBe($invented->headers->get('Location'))
        ->and($someoneElses->fresh()->status)->toBe('pending')
        ->and($cart->fresh()->status)->toBe('converted');
});

it('cannot be pressed twice', function () {
    /*
     * The offer is forgotten the moment it is used, so the second press finds
     * nothing — which matters because the first press has already put the cart
     * back to `active`, and a second run would find no `converted` cart and
     * could only do half of the pair.
     *
     * MUTATION, run: delete the forget(RESTORABLE_KEY) after the write → the
     * button is still on the page and the second press re-runs the move.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);
    rbAs($cart)->post('/checkout/restore-basket');

    expect(session(CheckoutReturnController::RESTORABLE_KEY))->toBeNull();

    $again = rbAs($cart)->post('/checkout/restore-basket');

    expect(session('errors')->getBag('default')->first())->toContain('nothing to put back')
        ->and($again->headers->get('Location'))->toContain('/cart');
});

it('releases the stock exactly once when both presses get past the gate', function () {
    /*
     * ▲ THE DOUBLE SUBMIT, DRIVEN ALL THE WAY THROUGH RATHER THAN STOPPED AT
     * THE DOOR.
     *
     * The case above pins the ordinary second press: the offer is forgotten
     * when it is used, so a shopper hammering the button finds nothing. That is
     * the gate, and it is not the interesting half, because a RACE does not
     * meet the gate. Two requests in flight together both read the session
     * before either writes it, and both read the basket before either commits —
     * this shop has no session lock — so both arrive holding a live offer and a
     * `converted` cart.
     *
     * That is the state reconstructed here, exactly as the second request saw
     * it: the offer put back in the session and the cart put back to
     * `converted`. It is not a contrivance to make the test pass, it is what
     * the loser of the race actually had in its hands, and it drives the second
     * press through the cart gate and into the transaction.
     *
     * ▲ AND THE MUTATION FOR THE SHELF DOES NOT EXIST, WHICH IS THE FINDING.
     *
     * Nothing here releases stock itself: `failed` is in
     * OrderTransitionStock::RETURNS_STOCK and the move is what hands the units
     * over. I tried to make the shelf read 7 and could not, one guard at a
     * time — each of these was run, and this case stayed GREEN through every
     * one of them:
     *
     *   · restore() moves to 'cancelled' rather than 'failed', so the second
     *     press is a real transition instead of a no-op. Red — but on the
     *     STATUS assertion below, not on the shelf. Still 5.
     *   · StockClaim::release()'s sweep stops excluding rows that already carry
     *     `released_at`. Still 5: the per-row claim refuses each one.
     *   · that per-row claim's `$claimed !== 1` check neutralised. Still 5:
     *     the sweep never selected the rows in the first place.
     *   · the first and second together. Still 5.
     *
     * THREE INDEPENDENT MECHANISMS, and only all three at once could credit
     * the shelf twice — at which point it is not a mutation, it is deleting the
     * defence. So the shelf line below pins an OUTCOME rather than any one
     * guard, and it is worth having for that: it is the sentence the owner
     * cares about, and it would catch a fourth path to the shelf that came in
     * around all three.
     *
     * What actually holds it on the shop is the FIRST of the three: moveTo() on
     * an order already `failed` writes nothing and transitions nothing, so no
     * release is even attempted. The ledger is why a REAL race — two presses
     * that both transition — could not double-credit either.
     */
    $product = rbProduct('Rice Toner', 5);

    [$cart, $order] = rbAwayAtTheProvider([[$product, 2]]);

    // The claim the placing transaction made.
    app(CartService::class)->claimStock($cart, $order);

    expect($product->fresh()->stock)->toBe(3, 'the fixture did not actually claim the stock');

    rbComeHome($cart, $order);
    rbAs($cart)->post('/checkout/restore-basket');

    expect($product->fresh()->stock)->toBe(5, 'the first press did not hand the units back');

    /* The loser of the race, holding what it read before the winner committed. */
    $cart->forceFill(['status' => 'converted', 'converted_at' => now()])->save();

    test()->withSession([
        'kbb_last_order' => $order->order_number,
        CheckoutReturnController::RESTORABLE_KEY => $order->order_number,
        CheckoutReturnController::RESTORABLE_CART_KEY => $cart->token,
    ]);

    $second = rbAs($cart)->post('/checkout/restore-basket');

    expect($product->fresh()->stock)->toBe(5, 'the units went back a second time')
        ->and($order->fresh()->status)->toBe('failed')
        ->and($cart->fresh()->status)->toBe('active')
        ->and($second->headers->get('Location'))->toContain('/cart');
});

it('says something sensible to a stale tab, rather than an accusation', function () {
    /*
     * The other tab, pressed after the basket is already back. The offer is
     * gone from the session, so this takes nothingToPutBack() — and the
     * sentence it gets is the one that happens to be TRUE for this shopper
     * rather than merely generic: "There is nothing to put back. Your basket is
     * as you left it." Their basket is, in fact, as they left it: the winning
     * tab put it there.
     *
     * It is worth pinning because the same sentence is what a stranger poking
     * the endpoint gets, and the reason it can be shared is that it accuses
     * nobody of anything. A message naming the order, or saying the button had
     * already been used, would be a better sentence here and an order-number
     * oracle there.
     *
     * MUTATION, run: return the restore_done sentence from nothingToPutBack()
     * → the stale tab claims to have restored a basket it did not touch, and
     * this goes red on both needles.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);
    rbAs($cart)->post('/checkout/restore-basket');

    // The stale tab, whose page was drawn before any of that happened.
    $stale = rbAs($cart)->post('/checkout/restore-basket');

    expect(session('errors')->getBag('default')->first())
        ->toBe('There is nothing to put back. Your basket is as you left it.');

    $html = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    expect($html)->toContain('class="co-note err" role="alert">There is nothing to put back.')
        ->and($html)->not->toContain('Put my basket back')
        ->and($stale->headers->get('Location'))->toContain('/cart');
});

it('does not offer the button for a cash-on-delivery order', function () {
    /*
     * PlacementState calls a COD order CONFIRMED the moment it exists — the
     * money moves at the door and it will never carry `paid_at`. Offering to
     * put the basket back would be offering to cancel a live sale.
     *
     * TWO GUARDS STAND HERE AND THIS NAMES THE ONE THAT ACTUALLY FIRES.
     * pending() returns at its first line for any CONFIRMED order and sends the
     * shopper to their receipt, so rememberRestorable() is never reached; its
     * own CONFIRMED test is defence in depth for a later caller, and deleting
     * it changes nothing today. Written out because the first version of this
     * case named the redundant one, and its mutation passed — a mutation note
     * that does not bite is a case that is not testing what it says.
     *
     * MUTATION, run: delete the CONFIRMED early return at the top of pending()
     * → the offer is written and the button appears beside a placed cash order.
     */
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]], [
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery', 'status' => 'pending',
    ]);

    $landed = rbComeHome($cart, $order);

    expect(session(CheckoutReturnController::RESTORABLE_KEY))->toBeNull()
        ->and($landed->headers->get('Location'))->toContain('/checkout/success');

    expect(rbAs($cart)->get('/cart/')->getContent())->not->toContain('Put my basket back');
});

/* ═══════════════════ 4. what it must not do to the shop ════════════════════ */

it('draws nothing on an ordinary basket page', function () {
    /*
     * The partial is included from store/cart.blade.php on EVERY visit, so a
     * basket page with nothing to say has to be byte for byte what it was —
     * which StorefrontEnglishUnchangedTest checks across the whole walk and
     * this checks by name, including the stylesheet that travels with the
     * message.
     *
     * MUTATION, run: move the <style> block outside the @if → the last two
     * assertions go red and every basket page on the shop gains 300 bytes.
     */
    $html = test()->get('/cart/')->assertOk()->getContent();

    expect($html)->not->toContain('Put my basket back')
        ->and($html)->not->toContain('co-restore')
        ->and($html)->not->toContain('kbb-cartpage .co-notices');
});

it('never calls the provider from the shopper\'s press', function () {
    /*
     * cardAbandoned() cancels the Stripe intent before it releases anything,
     * because a card intent left confirmable is a payment a stale tab can still
     * take. This leg has nothing to cancel: `cancel` and `failure` both mean the
     * plan was never approved.
     *
     * Reaching for Tabby or Tamara anyway would put a third party's latency in
     * front of a button a shopper is waiting on, and would make this endpoint
     * able to HANG — on the one page whose whole job is that nobody is stuck.
     *
     * MUTATION, run: add a $gateway->void($order) call to restore() → this
     * goes red on the name.
     */
    $src = file_get_contents(app_path('Http/Controllers/Store/CheckoutReturnController.php'));
    $restore = substr($src, strpos($src, 'public function restore('), strpos($src, 'private function nothingToPutBack') - strpos($src, 'public function restore('));

    foreach (['Http::', 'void(', 'abandonIntent', 'refund', 'capture'] as $call) {
        expect(str_contains($restore, $call))->toBeFalse('restore() reaches for '.$call);
    }
});

it('is a POST, and the GET that lands them still writes nothing', function () {
    /*
     * A GET that changes an order is a GET a link prefetcher, a mail scanner or
     * an antivirus extension can fire for the shopper. pending() stays
     * read-only; the only thing it touches is the session, which is this
     * browser's own and is how the button knows to appear.
     *
     * MUTATION, run: move the transaction from restore() into pending() → the
     * order below is `failed` after a plain GET.
     */
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 1]]);

    rbComeHome($cart, $order);

    expect($order->fresh()->status)->toBe('pending')
        ->and($cart->fresh()->status)->toBe('converted');

    /* And the address does not answer a GET at all. 404 rather than 405: this
       shop's routes/web.php ends in a GET-only Route::fallback, so a GET to a
       POST-only path is caught by that before the router reports the method —
       which is the better of the two answers anyway. */
    rbAs($cart)->get('/checkout/restore-basket')->assertNotFound();
});
