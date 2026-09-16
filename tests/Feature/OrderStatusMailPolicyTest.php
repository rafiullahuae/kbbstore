<?php

/**
 * Which status changes email the customer — Lane BS.
 *
 * The owner asked to be able to "select to which status the auto emails should
 * be sent upon changing the status", and to be able to override that on the
 * order in front of them. Two different things, and this file keeps them apart:
 *
 *   THE STANDING RULE is per status, and it is the module switches that already
 *   existed (Store → Modules → Order emails). What is new is the vocabulary
 *   around them — every status the store has, which of them a customer message
 *   was ever written for, and why the rest are silent — so a screen can show
 *   the whole list instead of the two rows somebody happened to expose.
 *
 *   THE PER-ORDER DECISION applies to one save and is then gone. It can
 *   suppress an email the rule would have sent and restore one the rule has
 *   switched off. It cannot invent a message for a status nobody wrote one for.
 *
 * WHAT THESE TESTS ARE REALLY GUARDING is where the gate sits. `orders.status`
 * is written from five places and one of them is a query-builder `update()`
 * that fires no model events — which is how bulk status changes once emailed
 * nobody here, silently, the biggest batches being the most silent. A gate
 * bolted to the order-detail controller would reproduce that hole exactly. So
 * there are assertions below for the single-order path, the bulk path and the
 * observer path, and they assert the COUNT: one email per transition, never
 * zero and never two.
 *
 * Pest note: `toContain` and `toHaveKey` read a second argument as another
 * needle / an expected value, not as a message, so explanations are written
 * `expect(...)->toBeTrue('...')`.
 */

use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Services\Mail\OrderMailer;
use App\Services\Mail\OrderStatusMailPolicy;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/** A signed-in owner. The admin-api group is guarded and these routes are in it. */
function adminUserForPolicy(): \App\Models\AdminUser
{
    return \App\Models\AdminUser::create([
        'name' => 'BS Owner',
        'email' => 'bs-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function policyOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'KBB-POL-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner', 'quantity' => 1, 'unit_price' => 20000,
        'subtotal' => 20000, 'total' => 20000,
    ]);

    return $order->fresh('items');
}

/* ------------------------------------------------------- the status list -- */

it('offers every status the store will actually accept', function () {
    // AdminController::updateOrderStatus is the widest door in the application:
    // its validation rule is the vocabulary. A list that has drifted from it is
    // a screen missing a status the store uses, or offering one it does not.
    $rule = (string) file_get_contents(base_path('app/Http/Controllers/Admin/AdminController.php'));

    preg_match("/'status' => 'required\|string\|in:([a-z,]+)'/", $rule, $m);

    expect($m)->not->toBeEmpty('the status validation rule could not be found to compare against');

    $accepted = explode(',', $m[1]);

    sort($accepted);
    $listed = OrderStatusMailPolicy::STATUSES;
    sort($listed);

    expect($listed)->toBe($accepted);
});

it('says which statuses can email and why the rest cannot', function () {
    $rows = app(OrderStatusMailPolicy::class)->all();

    $byStatus = collect($rows)->keyBy('status');

    // Exactly the two OrderStatusChanged has wording for.
    expect($byStatus['shipped']['supported'])->toBeTrue()
        ->and($byStatus['cancelled']['supported'])->toBeTrue();

    foreach (['draft', 'pending', 'processing', 'onhold', 'completed', 'refunded', 'failed'] as $silent) {
        expect($byStatus[$silent]['supported'])
            ->toBeFalse($silent . ' is reported as emailing the customer, but no message exists for it')
            // A dead tick box is the fault CLAUDE.md records three times. A
            // status that cannot email has to say why instead.
            ->and($byStatus[$silent]['reason'])
            ->not->toBe('', 'no reason is given for why ' . $silent . ' sends nothing');
    }
});

it('defaults to exactly what the store did before any of this existed', function () {
    $policy = app(OrderStatusMailPolicy::class);

    // Nothing configured: dispatch and cancellation both email.
    expect($policy->enabled('shipped'))->toBeTrue()
        ->and($policy->enabled('cancelled'))->toBeTrue()
        ->and($policy->enabled('processing'))->toBeFalse();
});

/* --------------------------------------------------- the standing rule -- */

it('stops the dispatch email for every path when the owner switches it off', function () {
    Mail::fake();

    app(OrderStatusMailPolicy::class)->setEnabled('shipped', false);

    $order = policyOrder();
    $order->update(['status' => 'shipped']);

    Mail::assertNotSent(OrderStatusChanged::class);

    // And the cancellation switch is untouched by it.
    $other = policyOrder();
    $other->update(['status' => 'cancelled']);

    Mail::assertSent(OrderStatusChanged::class, 1);
});

it('refuses to store a toggle for a status that has no message', function () {
    $policy = app(OrderStatusMailPolicy::class);

    expect($policy->setEnabled('processing', true))
        ->toBeFalse('a toggle was accepted for a status with no customer message')
        ->and($policy->enabled('processing'))
        ->toBeFalse('processing reports as emailing after a refused toggle');
});

/* ------------------------------------------------- the per-order decision -- */

it('suppresses one order\'s email without touching the standing rule', function () {
    Mail::fake();

    $policy = app(OrderStatusMailPolicy::class);
    $order = policyOrder();

    // The operator unticks "email the customer about this change" and saves.
    $policy->decideFor($order, false);
    $order->update(['status' => 'shipped']);

    Mail::assertNotSent(OrderStatusChanged::class);

    // The rule itself is unchanged, and the next order proves it.
    expect($policy->enabled('shipped'))->toBeTrue();

    $next = policyOrder();
    $next->update(['status' => 'shipped']);

    Mail::assertSent(OrderStatusChanged::class, 1);
});

it('sends one order\'s email even though the standing rule is off', function () {
    Mail::fake();

    $policy = app(OrderStatusMailPolicy::class);
    $policy->setEnabled('shipped', false);

    $order = policyOrder();
    $policy->decideFor($order, true);
    $order->update(['status' => 'shipped']);

    Mail::assertSent(OrderStatusChanged::class, 1);
});

it('cannot conjure an email for a status nobody wrote a message for', function () {
    Mail::fake();

    $policy = app(OrderStatusMailPolicy::class);
    $order = policyOrder(['status' => 'pending']);

    // Ticked as hard as the screen allows. There is no wording for
    // `processing`, and a tick box that produces a blank email is worse than
    // one that is absent.
    $policy->decideFor($order, true);
    $policy->decideForRequest(true);
    $order->update(['status' => 'processing']);

    Mail::assertNotSent(OrderStatusChanged::class);
});

it('applies one order\'s decision to that order only', function () {
    Mail::fake();

    $policy = app(OrderStatusMailPolicy::class);

    $quiet = policyOrder();
    $loud = policyOrder();

    $policy->decideFor($quiet, false);

    $quiet->update(['status' => 'shipped']);
    $loud->update(['status' => 'shipped']);

    Mail::assertSent(OrderStatusChanged::class, 1);
    Mail::assertSent(
        OrderStatusChanged::class,
        fn ($mail) => $mail->orderNumber() === $loud->order_number,
    );
});

/* ------------------------------------------- one transition, one email -- */

it('sends exactly one email per transition, never zero and never two', function () {
    Mail::fake();

    $order = policyOrder();

    $order->update(['status' => 'shipped']);
    // Saves that are not a status change must not re-send.
    $order->update(['customer_note' => 'leave with the concierge']);
    // Nor must writing the same status again.
    $order->update(['status' => 'shipped']);

    Mail::assertSent(OrderStatusChanged::class, 1);

    // A second, different transition is a second event and does send.
    $order->update(['status' => 'cancelled']);

    Mail::assertSent(OrderStatusChanged::class, 2);
});

it('governs the bulk path, which fires no model events at all', function () {
    Mail::fake();

    $policy = app(OrderStatusMailPolicy::class);
    $policy->setEnabled('shipped', false);

    $order = policyOrder();

    // Exactly what OrdersApiController::bulkStatus does: a query-builder
    // update, which fires no Eloquent events, followed by the explicit call
    // that exists because of that. If the gate had been built into the
    // order-detail controller this switch would do nothing here — which is the
    // shape of the bug that once made a forty-order dispatch email nobody.
    Order::query()->whereKey($order->getKey())->update(['status' => 'shipped']);

    app(OrderMailer::class)->statusChanged($order->fresh(), 'shipped');

    Mail::assertNotSent(OrderStatusChanged::class);

    $policy->setEnabled('shipped', true);

    app(OrderMailer::class)->statusChanged($order->fresh(), 'shipped');

    Mail::assertSent(OrderStatusChanged::class, 1);
});

/* ------------------------------------------------------- the admin API -- */

it('serves the whole status list to an admin and refuses a stranger', function () {
    $this->get('/admin-api/mail/status-emails')->assertStatus(302);

    $response = $this->actingAs(adminUserForPolicy(), 'admin')
        ->getJson('/admin-api/mail/status-emails')
        ->assertOk();

    $statuses = collect($response->json('statuses'))->keyBy('status');

    expect($statuses)->toHaveCount(count(OrderStatusMailPolicy::STATUSES))
        ->and($statuses['shipped']['enabled'])->toBeTrue()
        ->and($statuses['processing']['supported'])->toBeFalse();
});

it('saves a toggle and the next status change obeys it', function () {
    Mail::fake();

    $this->actingAs(adminUserForPolicy(), 'admin')
        ->postJson('/admin-api/mail/status-emails', ['status' => 'shipped', 'enabled' => false])
        ->assertOk()
        ->assertJsonPath('ok', true);

    // Written through SettingsService, so every reader sees it — including this
    // one, in a fresh request, which is the whole point of not writing the row
    // directly past the forever-cache.
    $order = policyOrder();
    $order->update(['status' => 'shipped']);

    Mail::assertNotSent(OrderStatusChanged::class);
});

it('refuses a toggle for a status with no message, and says why', function () {
    $response = $this->actingAs(adminUserForPolicy(), 'admin')
        ->postJson('/admin-api/mail/status-emails', ['status' => 'processing', 'enabled' => true])
        ->assertStatus(422);

    expect(str_contains((string) $response->json('message'), 'no customer email'))
        ->toBeTrue('the refusal does not say there is no email for that status')
        ->and(trim((string) $response->json('message')))
        ->not->toBe('There is no customer email for "processing".', 'the refusal gives no reason');
});

it('honours the notify flag on the single-order status endpoint', function () {
    Mail::fake();

    $order = policyOrder();

    $this->actingAs(adminUserForPolicy(), 'admin')
        ->putJson('/admin-api/orders/' . $order->id . '/status', ['status' => 'shipped', 'notify' => false])
        ->assertOk();

    expect($order->fresh()->status)->toBe('shipped');

    Mail::assertNotSent(OrderStatusChanged::class);
});

it('emails as usual when the status endpoint sends no notify flag at all', function () {
    Mail::fake();

    $order = policyOrder();

    // Every caller written before the field existed. The standing rule decides,
    // which is what it did before any of this.
    $this->actingAs(adminUserForPolicy(), 'admin')
        ->putJson('/admin-api/orders/' . $order->id . '/status', ['status' => 'shipped'])
        ->assertOk();

    Mail::assertSent(OrderStatusChanged::class, 1);
});

/* ---------------------------------------------------------- the screens -- */

it('carries the status-email list on the order detail payload', function () {
    $order = policyOrder();

    $body = $this->actingAs(adminUserForPolicy(), 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/detail')
        ->assertOk()
        ->json();

    // The tick box beside the status dropdown needs this at the moment it
    // needs the order. A second round trip is a second answer that can differ.
    expect($body)->toHaveKey('status_emails');

    $rows = collect($body['status_emails'])->keyBy('status');

    expect($rows['shipped']['supported'])->toBeTrue()
        ->and($rows['processing']['supported'])->toBeFalse();
});

it('draws the tick box and the settings list in the admin console', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // The order screen's per-order override, next to the status dropdown.
    expect(str_contains($screen, 'Email the customer about this change'))
        ->toBeTrue('the per-order email override is not on the order screen')
        ->and(str_contains($screen, "id=\"odNotify\""))
        ->toBeTrue('the override has no checkbox to read')
        // It must re-evaluate as the dropdown moves, or it sits ticked for a
        // status that sends nothing.
        ->and(str_contains($screen, 'odNotifySync(o)'))
        ->toBeTrue('the override is never re-synced when the status dropdown changes');

    // The standing rule, on Store → Mail.
    expect(str_contains($screen, 'Order status emails'))
        ->toBeTrue('the per-status settings section is not on the Mail screen')
        ->and(str_contains($screen, "/status-emails"))
        ->toBeTrue('the settings section calls no endpoint');
});

it('pins the module cache key the clear-caches migration spells out by hand', function () {
    // The migration cannot import SettingsService::MODULES_KEY — it is private
    // — so it carries the literal. If the constant is ever renamed, the
    // migration would silently stop clearing the toggle map and the first
    // request after an update would read a stale forever-cache.
    $constant = new ReflectionClassConstant(SettingsService::class, 'MODULES_KEY');

    $migration = (string) file_get_contents(
        base_path('database/migrations/2026_10_13_000000_clear_caches_status_email_policy.php'),
    );

    expect($constant->getValue())->toBe('kbb.modules')
        ->and(str_contains($migration, "Cache::forget('kbb.modules')"))
        ->toBeTrue('the migration no longer clears the module toggle cache');
});

it('lets a bulk action decide for every order it touches', function () {
    Mail::fake();

    $policy = app(OrderStatusMailPolicy::class);
    $policy->decideForRequest(false);

    $orders = [policyOrder(), policyOrder(), policyOrder()];

    $ids = array_map(static fn (Order $o) => $o->getKey(), $orders);
    Order::query()->whereIn('id', $ids)->update(['status' => 'shipped']);

    foreach ($orders as $order) {
        app(OrderMailer::class)->statusChanged($order->fresh(), 'shipped');
    }

    Mail::assertNotSent(OrderStatusChanged::class);
});
