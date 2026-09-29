<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\PaymentVoider;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Lane OD — the release path finally has a control, and the control is guarded
|------------------------------------------------------------------------------
|
| THE DEFECT, WHICH WAS AN ABSENCE. routes/payments-void.php is mounted and
| registers GET and POST /admin-api/orders/{id}/void behind PaymentVoidController.
| Both are capability-mapped, both are tested, and NOTHING IN THE ADMIN CONSOLE
| CALLED EITHER:
|
|     grep -rn -F "'/void'" resources/views/admin/ resources/js/   ->  nothing
|
| while its sibling POST /admin-api/orders/{id}/capture is called from
| app.blade.php:13778. One missing control sitting directly beside a working one.
|
| ON THE SHOP that reads: an operator cancels a Tamara order, restocks it,
| releases the coupon and emails the customer — and the customer's Tamara
| account still carries a live instalment plan for it, for up to 180 days,
| because nothing this shop can click releases the authorisation. They are asked
| for the first instalment on an order that no longer exists.
|
| Every case below goes red without some specific part of this lane's work, and
| each says which. The mutation notes were all run in the lane's worktree.
*/

/* ───────────────────────────────────────────────────────────────── fixtures */

const RH_TOKEN = 'rh-api-token';

function rhProvider(): void
{
    $row = PaymentProvider::create([
        'id' => 'tamara',
        'title' => 'Tamara',
        'enabled' => true,
        'mode' => 'test',
        'position' => 2,
    ]);

    $row->config = [
        'api_token' => RH_TOKEN,
        'notification_token' => 'rh-notify',
        'webhook_secret' => 'rh-secret',
    ];

    $row->save();

    app(GatewayCredentials::class)->forget();
}

/** An order a hold can legitimately be released on: cancelled, authorised, never captured. */
function rhOrder(array $attributes = [], int $totalFils = 25000): Order
{
    return Order::create(array_merge([
        'order_number' => 'RH-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'cancelled',
        'currency' => 'AED',
        'subtotal' => $totalFils,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'fee_total' => 0,
        'total' => $totalFils,
        'payment_method' => 'tamara',
        'transaction_id' => 'tam_rh',
        'paid_at' => now(),
    ], $attributes))->fresh();
}

function rhAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'RH ' . $role,
        'email' => 'rh-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

/**
 * Tamara as it answers a cancel, and nothing else.
 *
 * Stubbed the way TamaraGatewayParityTest and WalletMoneyPathTest do: no test
 * in this file reaches a real gateway, and the two cases that must not call one
 * at all assert Http::assertNothingSent() rather than trusting that they did
 * not.
 */
function rhFakeCancel(Order $order, string $cancelId = 'cancel_rh'): void
{
    Http::fake([
        // TamaraGateway::void() reads the order back from Tamara before it
        // cancels anything and refuses `reference_mismatch` unless the
        // reference matches this shop's own order number -- which is the check
        // that catches a transaction_id pointing at somebody else's order.
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_rh',
            'order_reference_id' => $order->order_number,
            'status' => 'authorised',
        ]),
        '*/cancel' => Http::response(['order_id' => 'tam_rh', 'cancel_id' => $cancelId]),
    ]);
}

function rhPartial(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/order-release-hold.blade.php')
    );
}

/* ═══════════════════════════════ 1 · the control exists and is wired ═══════ */

/*
 * THE FINISHED-STATE PIN, not the absence of one.
 *
 * CLAUDE.md records three days lost to lanes asserting `->not->toContain(...)`
 * to prove they had not wired themselves up — an assertion that goes red the
 * moment the integrator does the one thing the lane asked for. This counts the
 * include instead: 0 is "built, never wired up", which is the shape this repo
 * keeps finding and is exactly what this whole lane is about; 2 draws the panel
 * twice and gives two elements the same id.
 *
 * ▲ RED UNTIL THE INTEGRATOR APPLIES THE ONE BLOCK in
 *   docs/OD-RELEASE-THE-HOLD.md, and green from then on. That document is the
 *   handover and it names the exact anchor.
 */
it('includes the release panel on the admin console exactly once', function () {
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($console, "@include('admin.partials.order-release-hold')"))
        ->toBe(1, 'the release panel is not mounted on the console exactly once');
});

/*
 * The other half of the same question, and the one AdminConsoleControlsAreLive
 * generalises: the path the panel calls must be a path the REAL router answers.
 * A console that calls a path no route serves is the routes/import-history-admin
 * shape — a screen that looks finished and a 404 with nothing in any log.
 *
 * MUTATION, run: change the literal in the partial to '/admin-api/orders/1/voids'
 * and this is red on both halves.
 */
it('calls a release path the real router answers, in both verbs', function () {
    $partial = rhPartial();

    expect($partial)->toContain("'/admin-api/orders/1/void'");

    $verbs = [];

    foreach (Route::getRoutes() as $route) {
        if ($route->uri() === 'admin-api/orders/{id}/void') {
            $verbs = array_merge($verbs, array_intersect($route->methods(), ['GET', 'POST']));
        }
    }

    expect(array_values(array_unique($verbs)))->toEqualCanonicalizing(['GET', 'POST']);
});

/*
 * THE PANEL ASKS BEFORE IT OFFERS.
 *
 * The GET exists so a screen can ask "can this hold be released, and why not"
 * before it draws anything, which is the only way the button can never appear
 * where releasing is impossible. Pinned at the three decisions that make that
 * true, because each is a line somebody could reasonably "simplify" away:
 * the read happens, the button markup is behind `voidable`, and the refusal
 * reason is printed when it is not.
 *
 * MUTATION, run: delete `if (!v.voidable) {` block's early return and the
 * button is drawn on every order — red here.
 */
it('draws the release button only behind the server\'s own voidable answer', function () {
    $partial = rhPartial();

    expect($partial)->toContain("call('GET', voidUrl(id))")          // it asks
        ->and($partial)->toContain('if (!v.voidable) {')             // and refuses
        ->and($partial)->toContain("esc(v.why_not")                  // and says why
        ->and(substr_count($partial, 'id="orh-go"'))->toBe(1);       // one confirm button

    // The button is drawn after the `!v.voidable` branch has already returned.
    expect(strpos($partial, 'if (!v.voidable) {'))
        ->toBeLessThan((int) strpos($partial, 'id="orh-open"'));
});

/*
 * NO AMOUNT, NO ORDER ID AND NO REFERENCE CROSSES THE REQUEST.
 *
 * PaymentVoidController ignores the body entirely and reads the amount off the
 * order's own column inside the service, so a body carrying one would be a body
 * somebody could put a different figure in. The panel posts the empty object and
 * nothing else — pinned, because "send a bit more context" is the most natural
 * change in the world to make to a fetch call.
 */
it('posts an empty body from the release panel', function () {
    $partial = rhPartial();

    expect($partial)->toContain("opts.body = '{}';")
        ->and(substr_count($partial, 'opts.body'))->toBe(1);

    // And no JSON.stringify anywhere, which is how an amount would get in.
    expect($partial)->not->toContain('JSON.stringify');
});

/*
 * No JavaScript that measures layout. CLAUDE.md rule 4, and two tests in this
 * suite already forbid these by name across the console; this keeps the new
 * file inside that net on the day it lands rather than the day somebody widens
 * a glob.
 */
it('measures no layout in the release panel', function () {
    $partial = rhPartial();

    foreach ([
        'getBoundingClientRect',
        'offsetWidth',
        'offsetHeight',
        'clientWidth',
        'clientHeight',
        'scrollWidth',
        'getComputedStyle',
    ] as $api) {
        expect($partial)->not->toContain($api);
    }
});

/* ═══════════════════════════════════ 2 · what the GET actually answers ═════ */

/*
 * THE CONFIRMATION FACTS, AND WHY THEY COME FROM THE SERVER.
 *
 * The panel is appended to a screen it does not own and holds an order id it
 * read off a click. `confirm.order_number` is what lets it check that id against
 * the heading the operator can see before it draws a button that spends
 * somebody's credit, and `confirm.amount` is why the browser never assembles a
 * figure of its own.
 *
 * MUTATION, run: drop the `'confirm' => ...` line from
 * PaymentVoidController::show() and this is red — and on the shop the panel
 * stops drawing at all, because it refuses to render without the check.
 */
it('answers the facts a confirmation needs, on the read that decides whether to offer one', function () {
    rhProvider();

    $order = rhOrder(['order_number' => 'RH-CONFIRM-1'], 25000);

    $body = test()->actingAs(rhAdmin(), 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->json();

    expect($body['supported'])->toBeTrue()
        ->and($body['voidable'])->toBeTrue()
        ->and($body['why_not'])->toBeNull();

    expect($body['confirm']['order_id'])->toBe($order->id)
        ->and($body['confirm']['order_number'])->toBe('RH-CONFIRM-1')
        ->and($body['confirm']['amount_fils'])->toBe(25000)
        ->and($body['confirm']['amount'])->toBe('250.00')
        ->and($body['confirm']['currency'])->toBe('AED')
        ->and($body['confirm']['holder'])->toBe('Tamara')
        ->and($body['confirm']['consequence'])->toContain('Tamara');

    // Integer fils, never a float. A float here is how 250.00 becomes 249.99
    // on some other machine.
    expect($body['confirm']['amount_fils'])->toBeInt();

    // It is a read. It called no provider to answer any of that.
    Http::assertNothingSent();
});

/*
 * The refusal, with the reason, on an order that is still live. This is the
 * case the panel exists to explain: the operator is looking at an order that
 * plainly holds an authorisation and there is no button, and "why not" is the
 * commonest support question about a screen like this.
 */
it('refuses with a reason on an order that is still live, and offers nothing', function () {
    rhProvider();

    $order = rhOrder(['status' => 'processing']);

    $body = test()->actingAs(rhAdmin(), 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->json();

    expect($body['supported'])->toBeTrue()
        ->and($body['voidable'])->toBeFalse()
        ->and($body['why_not'])->toContain('Cancel it first');

    // The consequence sentence is still there — it describes the gateway, not
    // this order — but nothing offers to act on it.
    expect($body['confirm']['holder'])->toBe('Tamara');

    Http::assertNothingSent();
});

/*
 * A payment method that holds no authorisation gets `supported: false`, and the
 * panel draws nothing at all for it. Cash on delivery, Stripe and every
 * imported WooCommerce order are in this set, which is most of the shop: a box
 * on all of them saying "nothing is held here" is noise on the majority of
 * orders.
 */
it('says nothing is held when the payment method holds no authorisation', function () {
    $order = rhOrder(['payment_method' => 'cod', 'transaction_id' => null]);

    $body = test()->actingAs(rhAdmin(), 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->json();

    expect($body['supported'])->toBeFalse()
        ->and($body['voidable'])->toBeFalse()
        ->and($body['confirm']['holder'])->toBeNull()
        ->and($body['confirm']['consequence'])->toBeNull();
});

/* ══════════════════════════════════════════════ 3 · guarded, and fails closed */

/*
 * THIS LANE ADDS NO ENDPOINT, SO IT ADDS NO CAPABILITY — and that claim is
 * worth nothing unless it is checked. Both verbs are mapped to `orders.money`,
 * the same capability as capture and refund, and the map is first-match-wins so
 * the pair has to sit before the broad `admin-api/orders/*` read rule or the
 * GET would quietly become `orders.view`.
 */
it('maps both release verbs to orders.money, ahead of the broad orders read', function () {
    $rules = AdminCapabilities::RULES;

    $positions = [];

    foreach ($rules as $i => [$method, $pattern, $capability]) {
        if ($pattern === 'admin-api/orders/*/void') {
            expect($capability)->toBe('orders.money');
            $positions[$method] = $i;
        }

        if ($pattern === 'admin-api/orders/*' && $method === 'GET') {
            $positions['broad'] = $i;
        }
    }

    expect($positions)->toHaveKeys(['GET', 'POST', 'broad']);
    expect($positions['GET'])->toBeLessThan($positions['broad']);
    expect($positions['POST'])->toBeLessThan($positions['broad']);
});

/*
 * FAILS CLOSED, OVER HTTP. `support` is the role that reads orders and moves
 * their status and touches no money — precisely the account most likely to be
 * looking at a cancelled order. Both verbs refuse it.
 *
 * The GET matters as much as the POST: unguarded it is a way to enumerate which
 * orders in the shop are holding money, which routes/payments-void.php's header
 * says in as many words.
 *
 * MUTATION, run: change 'orders.money' to 'orders.view' on either void rule in
 * AdminCapabilities and that verb answers 200 here.
 */
it('refuses both release verbs to an admin who may not move money', function () {
    rhProvider();

    $order = rhOrder();
    $support = rhAdmin('support');

    test()->actingAs($support, 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/void')
        ->assertStatus(403);

    test()->actingAs($support, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertStatus(403);

    // It can still read the order it is entitled to read — the refusal is about
    // the money, not about the order.
    test()->actingAs($support, 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/detail')
        ->assertOk();

    expect($order->fresh()->voided_at)->toBeNull();

    Http::assertNothingSent();
});

/*
 * And nothing about a hold, a gateway or a buyer reaches the unauthenticated
 * surface. /api/* is public on this shop and every endpoint under it has leaked
 * something once already — see tests/Feature/ApiSecurityTest.php.
 */
it('exposes no release path on the unauthenticated api surface', function () {
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/')) {
            expect($route->uri())->not->toContain('void');
            expect($route->uri())->not->toContain('settlement');
        }
    }
});

/* ═══════════════════════════════════════════ 4 · releasing, and only once ══ */

/*
 * The whole path, over HTTP, as the panel drives it: the POST releases, the
 * response carries the server's own state for the panel to redraw from, and the
 * order carries the record.
 */
it('releases the hold over HTTP and answers with the state the panel redraws from', function () {
    rhProvider();

    $order = rhOrder(['order_number' => 'RH-HTTP-1']);
    rhFakeCancel($order, 'cancel_http');

    $body = test()->actingAs(rhAdmin(), 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->json();

    expect($body['ok'])->toBeTrue()
        ->and($body['code'])->toBe('voided')
        ->and($body['void']['voided'])->toBeTrue()
        ->and($body['void']['voidable'])->toBeFalse()
        ->and($body['void']['void_ref'])->toBe('cancel_http');

    $order->refresh();

    expect($order->voided_at)->not->toBeNull()
        ->and($order->void_ref)->toBe('cancel_http');

    // The trail a customer asking why they are still being billed is answered
    // from, without SQL.
    expect($order->notes()->get()->contains(
        fn ($n) => str_contains((string) $n->content, 'Released the tamara authorisation')
    ))->toBeTrue();
});

/*
 * CANNOT BE DOUBLE-FIRED. The panel disables its button and holds an in-flight
 * flag, and neither of those stops a second tab, a retried request or an
 * operator who did not see the first one work. What does is PaymentVoider
 * claiming `voided_at` with one conditional UPDATE before it calls the
 * provider — so the second POST is answered `already_voided` and the provider
 * is called ONCE.
 *
 * MUTATION, run: drop the whereNull('voided_at') from PaymentVoider's claim and
 * the cancel is sent twice.
 */
it('releases once when the release is fired twice', function () {
    rhProvider();

    $order = rhOrder();
    rhFakeCancel($order, 'cancel_twice');

    $admin = rhAdmin();

    $first = test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->json();

    $second = test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/void')
        ->assertOk()
        ->json();

    expect($first['code'])->toBe('voided')
        ->and($second['code'])->toBe('already_voided')
        ->and($second['ok'])->toBeTrue();

    Http::assertSentCount(2);   // one status read + one cancel, for the first POST only
});

/* ══════════════════════════════════════════════════════ 5 · the escape hatch */

/*
 * --dry-run is a report and writes nothing. The day this command is needed is
 * the day the console is broken, and the first thing anybody sensible does with
 * a command that releases money is ask it what it would do.
 */
it('reports without releasing on a dry run', function () {
    rhProvider();
    Http::fake();

    $order = rhOrder(['order_number' => 'RH-DRY-1']);

    test()->artisan('payments:release-hold', ['order' => $order->id, '--dry-run' => true])
        ->expectsOutputToContain('RH-DRY-1')
        ->expectsOutputToContain('Dry run. Nothing was released')
        ->assertExitCode(0);

    expect($order->fresh()->voided_at)->toBeNull();

    Http::assertNothingSent();
});

/*
 * ── THE ONE THAT MATTERS ───────────────────────────────────────────────────
 *
 * A NON-INTERACTIVE RUN IS REFUSED OUTRIGHT, and it is refused by an explicit
 * check rather than by Symfony's confirm() happening to default to false. That
 * default works and is invisible: the next person to change it turns every
 * scripted run of this command — a cron line, a deploy step, a CI job — into a
 * release of somebody's authorisation.
 *
 * MUTATION, run: delete the `! $this->input->isInteractive()` block from
 * ReleaseHold::handle() and this case releases the hold instead of refusing,
 * because expectsQuestion is not supplied here and the question is never asked.
 */
it('refuses to release a hold when there is nobody to ask', function () {
    rhProvider();
    Http::fake();

    $order = rhOrder();

    test()->artisan('payments:release-hold', [
        'order' => $order->id,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Refusing to release a hold in a non-interactive run')
        ->assertExitCode(1);

    expect($order->fresh()->voided_at)->toBeNull();

    Http::assertNothingSent();
});

/*
 * The typed confirmation is the confirmation. Anything that is not the order
 * number leaves the hold alone — including the empty answer, which is what
 * pressing enter gives.
 */
it('leaves the hold alone when the typed confirmation is not the order number', function () {
    rhProvider();
    Http::fake();

    $order = rhOrder(['order_number' => 'RH-TYPED-1']);

    test()->artisan('payments:release-hold', ['order' => $order->id])
        ->expectsQuestion(
            'Type the order number to release AED 250.00, or press enter to leave it alone',
            'RH-TYPED-2'
        )
        ->expectsOutputToContain('Left alone. Nothing was released.')
        ->assertExitCode(1);

    expect($order->fresh()->voided_at)->toBeNull();

    Http::assertNothingSent();
});

/* And the whole of it, from a shell, with the answer typed back. */
it('releases the hold from a shell when the order number is typed back', function () {
    rhProvider();

    $order = rhOrder(['order_number' => 'RH-SHELL-1']);
    rhFakeCancel($order, 'cancel_shell');

    test()->artisan('payments:release-hold', ['order' => 'RH-SHELL-1'])
        ->expectsQuestion(
            'Type the order number to release AED 250.00, or press enter to leave it alone',
            'RH-SHELL-1'
        )
        ->assertExitCode(0);

    $order->refresh();

    expect($order->voided_at)->not->toBeNull()
        ->and($order->void_ref)->toBe('cancel_shell');

    // The note says where it came from, so the trail reads without SQL.
    expect($order->notes()->get()->contains(
        fn ($n) => str_contains((string) $n->content, 'Released the tamara authorisation')
    ))->toBeTrue();
});

/*
 * An order already released is the state the caller asked for: a success, and
 * the provider is not called again.
 */
it('does nothing and succeeds when the hold has already been released', function () {
    rhProvider();
    Http::fake();

    $order = rhOrder();
    $order->forceFill(['voided_at' => now(), 'void_ref' => 'cancel_old'])->save();

    test()->artisan('payments:release-hold', ['order' => $order->id, '--no-interaction' => true])
        ->expectsOutputToContain('already been released')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

/*
 * AMBIGUITY IS REFUSED, NOT RESOLVED. Imported WooCommerce orders carry NUMERIC
 * order numbers, so "1042" can legitimately be the id of one order and the
 * number of another. Guessing here means releasing the hold on an order nobody
 * named.
 */
it('refuses a numeric argument that names two different orders', function () {
    rhProvider();
    Http::fake();

    $first = rhOrder(['order_number' => 'RH-AMBIG-1']);
    $second = rhOrder(['order_number' => (string) $first->id]);

    expect($second->id)->not->toBe($first->id);

    test()->artisan('payments:release-hold', [
        'order' => (string) $first->id,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('is both the id of order')
        ->assertExitCode(1);

    expect($first->fresh()->voided_at)->toBeNull()
        ->and($second->fresh()->voided_at)->toBeNull();

    Http::assertNothingSent();
});

/*
 * The command and the panel are the SAME code, not two implementations. The one
 * that drifts is the one nobody looks at, and the direction it drifts in is a
 * command reporting a release the screen would have refused. Pinned at the one
 * place that can drift: the sentence the operator is shown.
 */
it('shows the operator the same consequence sentence the panel shows', function () {
    rhProvider();
    Http::fake();

    $order = rhOrder(['order_number' => 'RH-SAME-1']);

    $fromEndpoint = (string) test()->actingAs(rhAdmin(), 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/void')
        ->json('confirm.consequence');

    expect($fromEndpoint)->not->toBe('');

    test()->artisan('payments:release-hold', ['order' => $order->id, '--dry-run' => true])
        ->expectsOutputToContain('payment plan for this order')
        ->assertExitCode(0);

    expect(app(PaymentVoider::class)->confirmation($order)['consequence'])->toBe($fromEndpoint);
});

/* ══════════════════════════════════ 6 · the settlement read, and its double ═ */

/*
 * ── TASK 4, DECIDED: THE ROUTE STAYS, AND THE DRIFT GETS A GUARD ───────────
 *
 * GET /admin-api/orders/{id}/settlement has no caller in the console, and the
 * same `settlement` block already arrives inside the order-detail payload
 * (AdminOrderController:295). Two implementations of one answer is the pair that
 * drifts — and on this pair it ALREADY HAS: `void` was added to
 * PaymentSettlementController::state() when the release endpoint shipped and was
 * missing from the detail payload alone for a whole round, so the one screen the
 * release button lives on was the one screen that could not see a release.
 *
 * It is NOT wired to anything by this lane and it is NOT deleted, for three
 * reasons, written down here because "leave it" is only an answer with the
 * reason attached:
 *
 *   1. UNIFYING THE COMPOSITION WOULD UNDO A MEASURED OPTIMISATION.
 *      AdminOrderController reads `refundedFils()` and `capturedFils()` exactly
 *      once each and passes the results into both the order-level keys and the
 *      settlement block — deliberately, and with the numbers in its own comment:
 *      five reads down to two, 11 statements down to 8 on the one page in this
 *      admin that has money on it. A shared builder would re-derive them and put
 *      two aggregates back on every order-detail load. CLAUDE.md rule 4.
 *
 *   2. THEY ANSWER DIFFERENT QUESTIONS. `/detail` is the whole order — items,
 *      notes, refunds, customer history, attribution. `/settlement` is the money
 *      state of one order and nothing else, which is what a caller wants after
 *      an action, and it is already the exact shape POST /capture returns under
 *      its own `settlement` key. Deleting it means deleting a route, a
 *      controller method, a capability row and its tests to remove an
 *      authenticated read that costs nothing while it is not called.
 *
 *   3. THE DRIFT IS THE ACTUAL HAZARD, AND IT CAN BE PINNED DIRECTLY.
 *      Which is what this case does. Every key the two payloads share must hold
 *      the same value for the same order — so the next key added to one and
 *      forgotten on the other is caught here rather than on the shop.
 *
 * MUTATION, run: delete the `'void' => app(PaymentVoider::class)->status($order)`
 * line from AdminOrderController's settlement block — which is precisely the
 * defect that already happened — and this is red with `void` named.
 */
it('keeps the settlement endpoint and the order-detail payload in step', function () {
    rhProvider();

    $order = rhOrder(['order_number' => 'RH-STEP-1']);
    $admin = rhAdmin();

    $fromSettlement = test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/settlement')
        ->assertOk()
        ->json();

    $fromDetail = test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/detail')
        ->assertOk()
        ->json('settlement');

    $shared = array_intersect_key($fromSettlement, $fromDetail);

    // The guard is blind if the two ever stop overlapping.
    expect(count($shared))->toBeGreaterThan(5);
    expect(array_key_exists('void', $shared))->toBeTrue(
        'the order-detail payload no longer carries the release state, which is the drift that already happened once'
    );

    foreach ($shared as $key => $value) {
        expect($fromDetail[$key])->toEqual($value, "settlement.{$key} disagrees between the two endpoints");
    }

    Http::assertNothingSent();
});
