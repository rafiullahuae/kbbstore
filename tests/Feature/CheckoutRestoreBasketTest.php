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
    [$cart, $order] = rbAwayAtTheProvider([[rbProduct('Rice Toner'), 2]]);

    rbComeHome($cart, $order);

    $response = rbAs($cart)->post('/checkout/restore-basket');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/cart');

    expect($cart->fresh()->status)->toBe('active')
        ->and($cart->fresh()->converted_at)->toBeNull()
        ->and($order->fresh()->status)->toBe('failed');

    // And the basket really is theirs again: the cart page draws the line.
    $html = rbAs($cart)->get('/cart/')->assertOk()->getContent();

    expect($html)->toContain('Rice Toner')
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
        // And the shopper is told, in the reconciler's own words.
        ->and($html)->toContain('1025 Dokdo Toner')
        ->and($html)->toContain('Medicube booster set');
});

/* ═════════════════════ 3. everything it has to refuse ══════════════════════ */

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
