<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Http\Controllers\Admin\PaymentVoidController;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Gateways\TabbyGateway;
use App\Services\Payments\Gateways\TamaraGateway;
use App\Services\Payments\PaymentVoider;
use App\Services\Payments\VoidsAuthorisation;
use Illuminate\Support\Facades\Route as RouteFacade;

/*
|------------------------------------------------------------------------------
| The three decisions the Tamara/Tabby merge had to make, pinned
|------------------------------------------------------------------------------
|
| Two payments lanes ran in the same round and both built "release an
| authorisation nobody captured" from scratch: their own interface, their own
| PaymentVoider, their own PaymentVoidController, their own migration, and their
| own copy of the two routes inside their own gateway's route file. Neither did
| anything wrong — CLAUDE.md tells a lane to stay inside the files it owns, and
| neither could see the other.
|
| The merge had to pick one of each, and the three choices below are the ones
| that a later reader, or a third payments lane, would most plausibly undo
| without realising what it cost. Each is a live check against the running
| router and container, not a source scan: a source scan would have passed
| happily on the two-route-files version, because both files were correct.
*/

/*
 * ── 1 ── ONE REGISTRATION, NOT TWO ─────────────────────────────────────────
 *
 * routes/payments-tamara.php and routes/payments-tabby.php each carried
 * `POST /admin-api/orders/{id}/void`. Mounted together, Laravel keeps the LAST
 * one registered for a method+URI pair, so the order of two require lines in
 * web.php would have decided which controller method served every release in
 * the shop — and the loser's test suite would have gone on passing, green,
 * against a method nothing called. The routes now live in
 * routes/payments-void.php, once, and neither gateway file registers them.
 *
 * ── AND WHY THIS COUNTS FILES AND NOT ROUTES ───────────────────────────────
 *
 * The first version of this test walked RouteFacade::getRoutes() and asserted
 * one route per verb. It was GREEN under its own mutation: RouteCollection is
 * keyed by method+URI, so registering the same pair twice REPLACES the first
 * entry and the collection can never hold two. The duplicate is invisible at
 * exactly the layer where the damage happens — which is the reason the damage is
 * silent in the first place.
 *
 * So the count is over the mounted route FILES, which is the only place two
 * registrations are distinguishable, and the router check below is kept as the
 * other half: that the one registration is really served, by the methods this
 * merge settled on. Counting the finished state (exactly one file) rather than
 * the absence of a line is CLAUDE.md's rule for this — zero is the "built, never
 * wired" shape and two is this one.
 *
 * MUTATION: add either route back to payments-tamara.php or payments-tabby.php
 * and this is red. Run.
 */
it('registers the release endpoint from exactly one mounted route file', function () {
    $wiring = file_get_contents(base_path('routes/web.php'))
        . file_get_contents(base_path('routes/api.php'));

    $registering = [];

    foreach (glob(base_path('routes/*.php')) as $file) {
        $name = basename($file);

        // Only files web.php or api.php actually require: an unmounted file
        // registers nothing, so it cannot collide with anything.
        if (! str_contains($wiring, "/{$name}'")) {
            continue;
        }

        if (preg_match("#Route::(get|post)\(\s*'/orders/\{id\}/void'#", (string) file_get_contents($file)) === 1) {
            $registering[] = $name;
        }
    }

    expect($registering)->toBe(
        ['payments-void.php'],
        'the release endpoint must be registered from one mounted route file and no other — two files '
        . 'registering it means the require order in web.php silently decides which controller method '
        . 'serves every release in the shop, and Laravel\'s route collection cannot show you the second. '
        . 'Found: ' . implode(', ', $registering)
    );

    /*
     * And the one registration is really served, by the methods this merge
     * settled on. `show` for the read (PG2's, which PG1 did not have) and `void`
     * for the write (PG1's name, over PG2's `store`).
     */
    $served = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if ($route->uri() !== 'admin-api/orders/{id}/void') {
            continue;
        }

        foreach (['GET', 'POST'] as $verb) {
            if (in_array($verb, $route->methods(), true)) {
                $served[$verb] = $route->getActionName();
            }
        }
    }

    expect($served)->toBe([
        'GET' => PaymentVoidController::class.'@show',
        'POST' => PaymentVoidController::class.'@void',
    ]);
});

/*
 * ── 2 ── ONE INTERFACE, AND BOTH BNPL GATEWAYS ON IT ───────────────────────
 *
 * There were two: VoidsAuthorisation (Tamara's, `void(Order, int $amountFils)`)
 * and VoidsAuthorisations (Tabby's, `voidAuthorisation(Order)`). With both in
 * the tree PaymentVoider could only ask about one of them, so the other
 * gateway's release silently answered `unsupported_gateway` on a shop where the
 * button was drawn and the code was written and tested.
 *
 * The singular one survived, because the amount belongs on the interface: Tamara
 * wants the order's figures echoed back on a cancel and they have to balance,
 * and an interface written to Tabby's narrower shape would have forced the
 * Tamara side to reach back into the order for a number the caller already had.
 *
 * MUTATION: drop `VoidsAuthorisation` from either gateway's `implements` list
 * and this is red.
 */
it('has one void interface, implemented by both BNPL gateways', function () {
    expect(interface_exists(\App\Services\Payments\VoidsAuthorisations::class))->toBeFalse(
        'the plural interface was the duplicate; two of these means PaymentVoider can only see one gateway'
    );

    expect(is_subclass_of(TamaraGateway::class, VoidsAuthorisation::class))->toBeTrue();
    expect(is_subclass_of(TabbyGateway::class, VoidsAuthorisation::class))->toBeTrue();

    // The signature is the half that actually collided. `void(Order, int)` on
    // both, so PaymentVoider can call either without knowing which it has.
    $method = new ReflectionMethod(VoidsAuthorisation::class, 'void');

    expect($method->getNumberOfParameters())->toBe(2);
    expect($method->getParameters()[1]->getName())->toBe('amountFils');
    expect((string) $method->getParameters()[1]->getType())->toBe('int');
});

/*
 * ── 3 ── A TRASHED ORDER'S HOLD CAN STILL BE RELEASED ──────────────────────
 *
 * The one place the two designs actually disagreed about behaviour. The Tamara
 * side refused outright (`order_trashed`); the Tabby side resolved with
 * withTrashed() on purpose, because a trashed order is the order MOST likely to
 * be sitting on a hold nobody meant to leave open.
 *
 * The Tabby reading won: refusing had a cost and allowing it has none. Releasing
 * money is safe on an order in any state — it is TAKING it that is not, which is
 * why PaymentCapturer does not do this — so the refusal's only effect was to
 * leave the customer's credit committed, with no button anywhere that could free
 * it, until the provider's own 180-day timer.
 *
 * Nothing was given up either: the RELEASABLE check still refuses a trashed
 * order that is `processing` as `order_still_live`, which is the guard that was
 * doing the work.
 *
 * MUTATION: put `if ($order->trashed())` back in PaymentVoider::void() and the
 * first expectation is red; take `withTrashed()` out of the controller and the
 * second is red with a 404.
 */
it('still offers the release on an order that has been trashed', function () {
    $order = Order::create([
        'order_number' => 'VOID-TRASH-'.uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'cancelled',
        'currency' => 'AED',
        'subtotal' => 25000,
        'shipping_total' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 25000,
        'payment_method' => 'tabby',
        'transaction_id' => 'pay_trashed',
        'paid_at' => now(),
    ]);

    $order->delete();

    $trashed = Order::withTrashed()->findOrFail($order->id);

    expect($trashed->trashed())->toBeTrue();

    /*
     * A CONFIGURED GATEWAY, deliberately, and it is what makes this assertion
     * worth writing. Without one `supported` is false, `voidable` is false for
     * that reason alone, and the test would pass whether the trashed refusal was
     * there or not — green, asserting nothing, which is the shape CLAUDE.md's
     * rule 6 is about. With Tabby configured, `voidable` turns on exactly the
     * conditions void() accepts, so restoring the refusal moves it.
     *
     * `status()` still reads columns only and calls no provider — no Http::fake()
     * is needed here and none is set up, which is itself the proof.
     */
    $provider = PaymentProvider::create([
        'id' => 'tabby',
        'title' => 'Tabby',
        'enabled' => true,
        'mode' => 'test',
        'position' => 1,
    ]);

    $provider->config = [
        'public_key' => 'pk_test_11111111-2222-3333-4444-555555555555',
        'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555',
        'merchant_code' => 'AE',
        'webhook_secret' => 'whsec-tabby-abcdefghijklmnopqrstuvwxyz012345',
    ];

    $provider->save();

    app(GatewayCredentials::class)->forget();

    $status = app(PaymentVoider::class)->status($trashed);

    expect($status['supported'])->toBeTrue('the fixture must configure Tabby, or `voidable` is false for the wrong reason');
    expect($status['voidable'])->toBeTrue('a trashed, cancelled, authorised, uncaptured order is the case this endpoint exists for');
    expect((string) ($status['why_not'] ?? ''))->toBe('');

    /*
     * And the controller can reach the row at all. Order::find() answers null on
     * a trashed order, which the endpoint turns into 404 — "no such order" for an
     * order that exists and is holding money.
     */
    expect(Order::find($order->id))->toBeNull();
    expect(Order::withTrashed()->find($order->id))->not->toBeNull();

    $source = (string) file_get_contents(base_path('app/Http/Controllers/Admin/PaymentVoidController.php'));

    expect(substr_count($source, 'Order::withTrashed()->find($id)'))->toBe(
        2,
        'both verbs on the release endpoint must resolve a trashed order, or the read and the write disagree'
    );
    expect($source)->not->toContain('Order::find($id)');
});
