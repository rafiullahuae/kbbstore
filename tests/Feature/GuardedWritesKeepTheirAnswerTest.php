<?php

declare(strict_types=1);

/**
 * A GUARD WHOSE ANSWER IS THROWN AWAY. (Lane PLC, round 4)
 *
 * ── THE SHAPE ───────────────────────────────────────────────────────────────
 *
 * A write is made conditional — OrderStatus::moveTo() with `only:`, which
 * re-reads the row under `lockForUpdate` and writes NOTHING if the
 * precondition no longer holds. Then the caller ignores what it said and makes
 * a SECOND write underneath that only makes sense if the first one happened:
 *
 *     DB::transaction(function () use ($order, $cart) {
 *         $moved = moveTo($order, 'failed', only: ['paid_at' => null]);  // may refuse
 *         $cart->forceFill(['status' => 'active'])->save();              // runs anyway
 *     });
 *
 * Passing `only:` is an admission that the row can change under you. Discarding
 * the answer while writing underneath turns that admission into the bug it was
 * guarding against: the order stays PAID and the basket goes live, so the
 * shopper holds a bag of goods they have been charged for and the units are
 * never released because the order never moved.
 *
 * CLAUDE.md already carries this family — UpdateRunner::recordManifest(), where
 * a guarded write left a dirty attribute behind and seeded three later throws.
 * "A guarded write that leaves state behind does not contain a failure, it
 * seeds one."
 *
 * ── AND moveTo()'s null CANNOT BE THE TEST ──────────────────────────────────
 *
 * It means two different things: "the precondition did not hold" and "the order
 * was already in that status with nothing else to record". The second is an
 * ordinary shopper whose order the provider had already failed, and they are
 * OWED their basket — so refusing on null trades one defect for another. Both
 * legs therefore ask the LOCKED ROW instead: is this order over, and did it
 * take no money?
 *
 * The two cases in each pair are each other's proof. Refuse on null and the
 * already-failed case goes red; restore unconditionally and the race goes red.
 *
 * ── WHERE THE SHOP HAS THIS SHAPE ───────────────────────────────────────────
 *
 * Twelve moveTo() call sites; three pass `only:`. Both legs that put a basket
 * back are here. PaymentConfirmer is the third and it reads its answer into
 * `$moved` and branches on it, which is why it is not in this file.
 */

use App\Http\Controllers\Store\CheckoutReturnController;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SqlShape;

/* ═══════════════════════════════════ fixtures ══════════════════════════════ */

function gwProduct(string $name = 'Guarded Serum', int $stock = 5): Product
{
    return Product::create([
        'slug' => 'gw-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9000,
        'stock_status' => 'instock',
        'manage_stock' => true,
        'stock' => $stock,
    ]);
}

/**
 * A basket away at the gateway and the order written against it.
 *
 * @return array{0: Cart, 1: Order}
 */
function gwAwayPaying(Product $product, string $method): array
{
    $cart = Cart::create([
        'token' => Str::random(32), 'currency' => 'AED', 'status' => 'converted',
        'converted_at' => now(), 'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => (int) $product->price]);

    $order = Order::create([
        'order_number' => 'GW'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 18000, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 18000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => $method,
        'payment_method_title' => $method,
        'paid_at' => null,
        // Empty on purpose: StripeGateway::abandonIntent() returns true without
        // reaching Stripe when there is no `pi_` id to cancel, so the race below
        // is about the transaction and not about a faked HTTP call.
        'transaction_id' => '',
        'shipping_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk',
            'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ],
    ]);

    return [$cart->fresh(['items']), $order];
}

/**
 * Confirm the payment in the instant between the caller's stale `paid_at` read
 * and moveTo()'s own locked re-read.
 *
 * The two reads are distinguishable in SQL: the caller looks the order up by
 * `order_number`, moveTo() by key. THROUGH SqlShape::portable(), because MySQL
 * spells these identifiers with backticks and the default lane spells them with
 * double quotes — matched raw this hook would never fire under
 * phpunit-mysql.xml and every case below would pass proving nothing.
 * SqlNeedleDialectGuardTest caught exactly that in this lane's round-3 work.
 *
 * @return Closure a disarm, to be called once the request is done: the callback
 *                 lives on the CONNECTION, not on the test.
 */
function gwWebhookLandsMidWrite(Order $order): Closure
{
    $fired = false;

    DB::beforeExecuting(function (string $query) use (&$fired, $order): void {
        $sql = SqlShape::portable($query);

        if ($fired || ! str_contains($sql, 'from "orders"') || ! str_contains($sql, '"orders"."id"')) {
            return;
        }

        $fired = true;

        DB::table('orders')->where('id', $order->id)->update([
            'paid_at' => now(),
            'status' => 'processing',
        ]);
    });

    return function () use (&$fired): void {
        $fired = true;
    };
}

/* ══════════════ 1. the card leg — POST /checkout/card/abandon ══════════════ */

it('does not hand the basket back when the card confirms mid-abandon', function () {
    /*
     * ▲ THE SIBLING, FOUND BY LOOKING FOR THE SHAPE RATHER THAN THE SYMPTOM.
     *
     * CheckoutController::cardAbandoned() is the method restore() was modelled
     * on, and it carried the same defect the whole time: the moveTo() result
     * was not even assigned, and `$cart?->forceFill(['status' => 'active'])`
     * sat under it unconditionally.
     *
     * The window is real and it is what `only:` exists for. The `paid_at` check
     * at the top of cardAbandoned() is made on a row read before the Stripe
     * intent is cancelled; a confirmation landing after that read and before
     * moveTo()'s locked one makes the move refuse — correctly — and the basket
     * went back anyway, over an order that had just taken money.
     *
     * MUTATION, run: delete the `$released` check from cardAbandoned() and let
     * the cart write run unconditionally → the basket assertion goes red and
     * the shopper has both a live bag and a paid order for the same goods.
     */
    $product = gwProduct();

    [$cart, $order] = gwAwayPaying($product, 'stripe');

    app(CartService::class)->claimStock($cart, $order);

    expect($product->fresh()->stock)->toBe(3, 'the fixture did not actually claim the stock');

    $disarm = gwWebhookLandsMidWrite($order);

    $response = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/abandon', ['order' => $order->order_number]);

    $disarm();

    $after = $order->fresh();

    expect($after->paid_at)->not->toBeNull('the webhook write did not land, so this case proves nothing')
        ->and($after->status)->toBe('processing', 'moveTo let a paid order go')
        // THE ASSERTIONS THIS CASE EXISTS FOR.
        ->and($cart->fresh()->status)->toBe('converted', 'the basket was handed back over a paid order')
        ->and($product->fresh()->stock)->toBe(3, 'the units were released for an order that was paid');

    // And the shopper is told the same thing the stale read tells anyone whose
    // money has already moved, rather than being shown a restored bag.
    $response->assertStatus(409);
});

it('still hands the basket back on an ordinary card abandon', function () {
    /*
     * THE CASE THE FIX ABOVE COULD HAVE BROKEN, and the reason the locked row
     * is the test rather than moveTo()'s null. Nothing races here: the shopper
     * gave up on a declined card and is owed their basket and their stock.
     *
     * MUTATION, run: make cardAbandoned() refuse whenever moveTo() returns null
     * → an order the gateway had already failed stops giving the basket back,
     * which is the defect this pair exists to keep out.
     */
    $product = gwProduct();

    [$cart, $order] = gwAwayPaying($product, 'stripe');

    app(CartService::class)->claimStock($cart, $order);

    test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/abandon', ['order' => $order->order_number])
        ->assertOk();

    expect($cart->fresh()->status)->toBe('active')
        ->and($cart->fresh()->converted_at)->toBeNull()
        ->and($order->fresh()->status)->toBe('failed')
        ->and($product->fresh()->stock)->toBe(5, 'the units did not come back');
});

it('still hands the basket back when the gateway failed the card order first', function () {
    /*
     * The other half of "null means two things": the order is ALREADY `failed`
     * when the shopper presses the control, so moveTo() writes nothing and
     * returns null for a reason that has nothing to do with a race.
     *
     * MUTATION, run: the same one as above — refuse on null → red here while
     * the race case stays green, which is the pair that makes either mean
     * anything.
     */
    $product = gwProduct();

    [$cart, $order] = gwAwayPaying($product, 'stripe');

    $order->forceFill(['status' => 'failed'])->save();

    test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->withSession(['kbb_last_order' => $order->order_number])
        ->postJson('/checkout/card/abandon', ['order' => $order->order_number])
        ->assertOk();

    expect($cart->fresh()->status)->toBe('active', 'a shopper the gateway failed got no basket back')
        ->and($order->fresh()->status)->toBe('failed');
});

it('does not report a cash-on-delivery order placed when the move was refused', function () {
    /*
     * ▲ THE THIRD SITE, AND THE ONE WHERE THE DISCARDED ANSWER WAS A CLAIM
     * RATHER THAN A WRITE.
     *
     * CashOnDelivery::start() moves `pending` to `processing` under
     * `only: ['status' => 'pending']` — its own comment says the rule is "only
     * an order still waiting to be paid for is moved on" — and then returned
     * PaymentStart::placed() whatever happened. So for an order somebody had
     * cancelled, the move correctly declined and the checkout was told the
     * order was PLACED: receipt shown, confirmation sent, units left claimed
     * against an order the shop had given up on. COD is most of this shop's
     * orders, so this is the busiest of the three legs.
     *
     * Its null is unambiguous, unlike the basket legs': the precondition is
     * `pending` and the target is `processing`, so "already there with nothing
     * else to record" cannot arise and the return can be tested directly.
     *
     * MUTATION, run: return PaymentStart::placed() unconditionally again →
     * this goes red on ok(), and on the shop a cancelled order gets a receipt.
     */
    $product = gwProduct();

    [, $order] = gwAwayPaying($product, 'cod');

    // Somebody cancelled it between the checkout reading the row and this call.
    $order->forceFill(['status' => 'cancelled'])->save();

    $start = app(App\Services\Payments\Gateways\CashOnDelivery::class)->start($order->fresh());

    expect($start->ok())->toBeFalse('a cancelled order was reported as placed')
        ->and($start->result)->toBe('failed')
        ->and($order->fresh()->status)->toBe('cancelled', 'the guarded move wrote anyway');
});

it('still places an ordinary cash-on-delivery order', function () {
    /*
     * The other direction, because a start() that refused everything would
     * make the case above green and the shop unusable. This is the ordinary
     * COD checkout.
     *
     * MUTATION, run: invert the `$moved === null` test in start() → red here,
     * and no COD order can be placed at all.
     */
    $product = gwProduct();

    [, $order] = gwAwayPaying($product, 'cod');

    $start = app(App\Services\Payments\Gateways\CashOnDelivery::class)->start($order->fresh());

    expect($start->ok())->toBeTrue()
        ->and($start->result)->toBe('placed')
        ->and($order->fresh()->status)->toBe('processing');
});

/* ═════════ 2. the instalment leg, already fixed — pinned as a pair ═════════ */

it('does not hand the basket back when an instalment plan confirms mid-return', function () {
    /*
     * The same shape on Store\CheckoutReturnController::restore(), kept here
     * beside its sibling so that a future change to one is visibly a change to
     * both. The detailed story is on that method and in
     * CheckoutRestoreBasketTest; this is the family pinned in one place.
     */
    $product = gwProduct();

    [$cart, $order] = gwAwayPaying($product, 'tamara');

    app(CartService::class)->claimStock($cart, $order);

    $request = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    // (Lane BK) The return address itself now gives the basket back, so the
    // race is on that GET rather than on a later button press.
    test()->withSession(['kbb_last_order' => $order->order_number]);

    $disarm = gwWebhookLandsMidWrite($order);

    $request->get('/checkout/pending?order='.$order->order_number);

    $disarm();

    expect($order->fresh()->paid_at)->not->toBeNull('the webhook write did not land')
        ->and($cart->fresh()->status)->toBe('converted', 'the basket was handed back over a paid order')
        ->and($product->fresh()->stock)->toBe(3, 'the units were released for an order that was paid');
});

/* ══════════════ 3. the shape itself, so a fourth leg cannot skip it ════════ */

/**
 * Every PHP file under app/, walked properly.
 *
 * ▲ THIS WAS glob(app_path('**‍/**‍/*.php')) AND IT WAS WORTHLESS. PHP's glob
 * has no recursive wildcard: `**` is just `*`, so that pattern reached exactly
 * two directory levels and MISSED app/Http/Controllers/Store/ — which is where
 * both defects this file is about actually live. The sweep was green because it
 * never looked at the files that had the bug.
 *
 * @return list<string>
 */
function gwAppFiles(): array
{
    $files = [];

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($walk as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * The marker a guarded write carries when its underneath-write is deliberate.
 *
 * ▲ IT LIVES AT THE SITE, AND THAT IS THE WHOLE POINT. This was an array in
 * this file mapping a FILE PATH to a reason, which is where the next defect
 * hides twice over: the exemption was per-file, so one consulted site excused
 * every other guarded write in the same file; and the argument sat here, three
 * hundred lines from the code it excuses, where nobody editing that code would
 * ever read it.
 *
 * Now the reason is a comment beside the write, the sweep is per SITE, and
 * widening the exemption means editing the application file — which is the one
 * place a reviewer is already looking.
 */
const GW_MARKER = 'KBB-GUARDED-WRITE-DELIBERATE:';

/** How much argument a marker has to carry before it counts as one. */
const GW_REASON_CHARS = 120;

/**
 * How many deliberate sites the shop is expected to have.
 *
 * Pinned so the exemption cannot grow quietly: a sixth guarded write that
 * claims to be a log needs BOTH its reason at the site AND this number moved,
 * which is two deliberate acts and a diff a reviewer can see.
 */
const GW_DELIBERATE_SITES = 1;

it('consults the answer at every guarded write, one site at a time', function () {
    /*
     * ▲ THE SURVEY, PINNED PER SITE. `only:` is how a caller says "this row may
     * have changed under me"; a caller that ignores what came back has written
     * the bug the argument was added to prevent.
     *
     * Each `only: [` in app/ must, within its own window, do one of three
     * things: read moveTo()'s return into a variable it branches on; re-read
     * the row under `lockForUpdate` and branch on that; or carry GW_MARKER with
     * a real argument for why its underneath-write is a log rather than a
     * consequence.
     *
     * A WINDOW RATHER THAN A PARSE, and named as crude: forty lines either side
     * of the guarded call. A parser for "the enclosing transaction closure"
     * would be a second thing to get wrong, and the window has the property
     * that matters — it is per SITE, so a file with one consulted write and one
     * unconsulted write is no longer excused by the first. That is exactly what
     * the earlier per-file version did, and it is why PaymentConfirmer's second
     * site was invisible to it.
     *
     * MUTATION, run: delete the `lockForUpdate` re-read from
     * CheckoutReturnController::restore() → this names that file and line.
     */
    $offences = [];
    $guarded = 0;
    $deliberate = 0;

    foreach (gwAppFiles() as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $relative = str_replace(base_path().'/', '', $file);

        foreach ($lines as $index => $line) {
            if (! str_contains($line, 'only: [')) {
                continue;
            }

            /*
             * Comments quote `only: [...]` while explaining it — the two basket
             * legs do, at length — so a line inside a comment is not a site.
             */
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $guarded++;

            $window = implode("\n", array_slice($lines, max(0, $index - 40), 81));

            if (str_contains($window, GW_MARKER)) {
                $deliberate++;

                /*
                 * A MARKER WITHOUT AN ARGUMENT IS NOT AN EXEMPTION. Everything
                 * after the marker up to the end of its comment has to be a
                 * reason somebody can disagree with, not the word "deliberate".
                 */
                $after = substr($window, strpos($window, GW_MARKER) + strlen(GW_MARKER));
                $reason = trim(str_replace(['*', '/'], ' ', substr($after, 0, strpos($after.'*/', '*/'))));

                if (strlen($reason) < GW_REASON_CHARS) {
                    $offences[] = $relative.':'.($index + 1).' carries '.GW_MARKER
                        .' with only '.strlen($reason).' characters of argument. An exemption '
                        .'with no reason at the site is how this sweep gets widened quietly; '
                        .'say why the write underneath is a log rather than a consequence.';
                }

                continue;
            }

            $consulted = str_contains($window, 'lockForUpdate()')
                || (bool) preg_match('/\$\w+\s*=\s*app\([^;]*OrderStatus[^;]*\)->moveTo\(/s', $window);

            if (! $consulted) {
                $offences[] = $relative.':'.($index + 1).' passes `only:` to moveTo() and never reads '
                    .'what it said. Branch on the return, re-read the row under lockForUpdate and '
                    .'branch on that, or say at the site why the write underneath is a log.';
            }
        }
    }

    /*
     * THE ANTI-VACUITY HALF, and it is the one that failed first: the original
     * sweep used a glob that could not reach three directories deep, so it
     * examined none of the files it was written about and passed.
     */
    expect($guarded)->toBeGreaterThan(3,
        'fewer than four guarded writes found in app/ — the sweep has stopped matching '
        .'rather than the shop having stopped using preconditions');

    expect(count(gwAppFiles()))->toBeGreaterThan(250,
        'the recursive walk found fewer than 250 files under app/, which is not this application');

    /*
     * THE SITE MESSAGE FIRST, and the order is not cosmetic. Removing a marker
     * makes BOTH of these true, and the count says only "a number moved" while
     * the offence names the file, the line and what to do about it. A lane
     * reading the count first would go and edit GW_DELIBERATE_SITES, which is
     * the one wrong response available.
     */
    expect($offences)->toBe([]);

    expect($deliberate)->toBe(GW_DELIBERATE_SITES,
        'the number of guarded writes claiming to be a log has changed. That is allowed and it '
        .'is not allowed to be quiet: move GW_DELIBERATE_SITES in the same commit as the reason');
});
