<?php

/**
 * Lane RL — the order half of package E2: the receipt only once an order is
 * really placed (audit B1), the two "Complete your order" reminders, every
 * status email with its switch, the tick on the order screen, the manual
 * on-hold email, and the signed links that open on any device.
 *
 * Each test names the defect it guards in the shop's own terms, and the
 * mutation notes say which line, reverted, turns it red.
 */

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderPaymentReminder;
use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Mail\OrderReminders;
use App\Services\Mail\OrderReminderTick;
use App\Services\Payments\GatewayRegistry;
use App\Services\SettingsService;
use App\Support\OrderLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

const RL_STRIPE_SIGNING = 'whsec_rl_signing_secret_value';
const RL_STRIPE_URL = 'whsec-url-rl-0123456789abcdef';
const RL_TABBY_URL = 'whsec-tabby-rl-abcdefghijklmnopqrstuvwxyz0123';
const RL_TAMARA_URL = 'whsec-tamara-rl-abcdefghijklmnopqrstuvwxyz01';
const RL_TAMARA_KEY = 'tamara-rl-notification-key-0123456789';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    // Not wired into routes/web.php by this lane (the integrator does that);
    // registered here so the page can be exercised. See the last test for the pin.
    if (! Route::has('checkout.order-pay')) {
        Route::middleware('web')->group(base_path('routes/order-pay.php'));
    }

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);

    @unlink(OrderReminderTick::markerPath());
});

afterEach(function () {
    Carbon::setTestNow();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/* ------------------------------------------------------------- fixtures -- */

function rlOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'KBB-RL-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'pending',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'stripe',
        'payment_method_title' => 'Card',
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner', 'brand' => 'Haruharu', 'quantity' => 1, 'unit_price' => 20000,
        'subtotal' => 20000, 'total' => 20000,
    ]);

    return $order->fresh('items');
}

function rlAdmin(): \App\Models\AdminUser
{
    return \App\Models\AdminUser::create([
        'name' => 'RL Owner',
        'email' => 'rl-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function rlStripe(): void
{
    $row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = ['publishable_key' => 'pk_test_rl', 'secret_key' => 'sk_test_rl', 'webhook_signing_secret' => RL_STRIPE_SIGNING, 'webhook_secret' => RL_STRIPE_URL];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function rlTabby(): void
{
    $row = PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    $row->config = [
        'public_key' => 'pk_test_11111111-2222-3333-4444-555555555555',
        'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555',
        'merchant_code' => 'AE',
        'webhook_secret' => RL_TABBY_URL,
    ];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function rlTamara(): void
{
    $row = PaymentProvider::create(['id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 2]);
    $row->config = ['api_token' => 'tamara-api-token', 'notification_token' => RL_TAMARA_KEY, 'webhook_secret' => RL_TAMARA_URL];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function rlCod(int $fee = 0): void
{
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 3]);
    app(SettingsService::class)->set('cod_fee', $fee);
}

function rlWebhookRequest(string $gateway, string $secret, string $raw, array $server = []): Request
{
    $request = Request::create("/api/payments/webhook/{$gateway}/{$secret}", 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $raw);
    $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function rlStripeSucceeded(Order $order, string $intent): Request
{
    $raw = json_encode([
        'id' => 'evt_' . uniqid(),
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => $intent, 'status' => 'succeeded', 'amount' => (int) $order->total, 'amount_received' => (int) $order->total,
            'currency' => 'aed', 'metadata' => ['order_number' => $order->order_number],
        ]],
    ]);
    $ts = time();
    $mac = hash_hmac('sha256', $ts . '.' . $raw, RL_STRIPE_SIGNING);

    return rlWebhookRequest('stripe', RL_STRIPE_URL, $raw, ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$mac}"]);
}

function rlJwt(array $claims, string $key): string
{
    $b = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $head = $b(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $body = $b(json_encode($claims));

    return $head . '.' . $body . '.' . $b(hash_hmac('sha256', $head . '.' . $body, $key, true));
}

function rlCart(): Cart
{
    $product = Product::create(['slug' => 'rl-serum-' . uniqid(), 'name' => 'Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 200, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);

    return $cart;
}

function rlPlace(Cart $cart, string $method)
{
    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson('/checkout/place', [
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan', 'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai',
            'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => $method,
        ]);
}

function rlSwitch(string $key, bool $on): void
{
    app(SettingsService::class)->setModule($key, $on);
}

/* ================================================================= B1 === */

it('B1: receipts a cash-on-delivery order at placement, exactly once', function () {
    // COD is placed, not unpaid: the courier collects. The order reaches
    // `processing` inside checkout, and the receipt goes then — once, although
    // both the observer and CheckoutController ask for it. Mutation: delete the
    // claim() in OrderMailer::receipt() and this sends two.
    rlCod();
    Mail::fake();

    rlPlace(rlCart(), 'cod')->assertOk();

    $order = Order::latest('id')->first();
    expect($order->status)->toBe('processing');
    Mail::assertSent(OrderConfirmation::class, 1);
    Mail::assertSent(NewOrderAlert::class, 0); // no merchant address configured in this test
    // "We are preparing your order" would be a second email about one event.
    Mail::assertNotSent(OrderStatusChanged::class);
});

it('B1: sends no receipt for a card order while it is unpaid, and one when Stripe confirms', function () {
    // The shop: a shopper who abandoned the card form kept "we are packing it
    // with care" for an order never paid. Mutation: in OrderMailer::sendPlaced()
    // replace `if ($this->isPlaced($order))` with `if (true)` and the first
    // assertion is red.
    rlStripe();
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_rl', 'client_secret' => 'pi_rl_secret', 'status' => 'requires_payment_method'], 200)]);
    Mail::fake();

    rlPlace(rlCart(), 'stripe')->assertOk()->assertJsonPath('action', 'confirm');

    $order = Order::latest('id')->first();
    expect($order->status)->toBe('pending');
    Mail::assertNotSent(OrderConfirmation::class);

    // The webhook confirms the payment...
    $outcome = app(GatewayRegistry::class)->find('stripe')->handleWebhook(rlStripeSucceeded($order, 'pi_rl'));
    expect($outcome->accepted)->toBeTrue();
    Mail::assertSent(OrderConfirmation::class, 1);

    // ...and the browser's own confirmation arriving after it sends nothing more.
    Http::fake(['api.stripe.com/v1/payment_intents/*' => Http::response(['id' => 'pi_rl', 'status' => 'succeeded', 'amount' => (int) $order->total, 'amount_received' => (int) $order->total, 'currency' => 'aed'], 200)]);
    app(GatewayRegistry::class)->find('stripe')->confirmFromBrowser($order->fresh());
    app(GatewayRegistry::class)->find('stripe')->handleWebhook(rlStripeSucceeded($order, 'pi_rl'));

    Mail::assertSent(OrderConfirmation::class, 1);
    Mail::assertNotSent(OrderStatusChanged::class);
});

it('B1: receipts a card order once when the browser confirms first and the webhook follows', function () {
    rlStripe();
    Mail::fake();
    $order = rlOrder(['transaction_id' => 'pi_rl_b']);

    Http::fake(['api.stripe.com/v1/payment_intents/*' => Http::response(['id' => 'pi_rl_b', 'status' => 'succeeded', 'amount' => 22000, 'amount_received' => 22000, 'currency' => 'aed'], 200)]);

    app(GatewayRegistry::class)->find('stripe')->confirmFromBrowser($order);
    app(GatewayRegistry::class)->find('stripe')->handleWebhook(rlStripeSucceeded($order, 'pi_rl_b'));

    expect($order->fresh()->paid_at)->not->toBeNull();
    Mail::assertSent(OrderConfirmation::class, 1);
});

it('B1: receipts a Tabby order only when Tabby authorises it, once for two webhooks', function () {
    // Mutation: in OrderMailer::transition() drop the receipt() call and the
    // count is 0.
    rlTabby();
    Mail::fake();
    $order = rlOrder(['payment_method' => 'tabby', 'total' => 22000]);

    Http::fake(['api.tabby.ai/api/v2/payments/*' => Http::response([
        'id' => 'pay_rl', 'status' => 'AUTHORIZED', 'amount' => '220.00', 'currency' => 'AED',
        'order' => ['reference_id' => $order->order_number],
    ], 200), 'api.tabby.ai/*' => Http::response([], 200)]);

    $gateway = app(GatewayRegistry::class)->find('tabby');
    $hook = fn () => rlWebhookRequest('tabby', RL_TABBY_URL, json_encode(['id' => 'pay_rl']));

    Mail::assertNotSent(OrderConfirmation::class);
    expect($gateway->handleWebhook($hook())->accepted)->toBeTrue();
    $gateway->handleWebhook($hook());

    expect($order->fresh()->paid_at)->not->toBeNull();
    Mail::assertSent(OrderConfirmation::class, 1);
});

it('B1: receipts a Tamara order only when Tamara approves it, once for two notifications', function () {
    rlTamara();
    Mail::fake();
    $order = rlOrder(['payment_method' => 'tamara', 'total' => 22000]);

    Http::fake([
        '*/merchants/orders/*' => Http::response([
            'order_id' => 'tam_rl', 'order_reference_id' => $order->order_number, 'status' => 'approved',
            'total_amount' => ['amount' => 220.00, 'currency' => 'AED'],
        ]),
        '*/authorise' => Http::response(['status' => 'authorised']),
        '*' => Http::response([], 200),
    ]);

    $gateway = app(GatewayRegistry::class)->find('tamara');
    $token = rlJwt(['sub' => 'notification'], RL_TAMARA_KEY);
    $hook = fn () => rlWebhookRequest('tamara', RL_TAMARA_URL, json_encode([
        'order_id' => 'tam_rl', 'order_reference_id' => $order->order_number, 'order_status' => 'approved',
    ]), ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

    expect($gateway->handleWebhook($hook())->accepted)->toBeTrue();
    $gateway->handleWebhook($hook());

    expect($order->fresh()->paid_at)->not->toBeNull();
    Mail::assertSent(OrderConfirmation::class, 1);
});

it('B1: receipts an order the owner marks paid by hand, and honours the unticked box', function () {
    Mail::fake();
    $paid = rlOrder();
    $quiet = rlOrder(['email' => 'quiet@example.com']);
    $admin = rlAdmin();

    $this->actingAs($admin, 'admin')->putJson('/admin-api/orders/' . $paid->id . '/status', ['status' => 'processing'])->assertOk();
    // Mutation: in OrderStatusMailPolicy::receiptDecision() return null instead
    // of the per-order decision and the quiet order is receipted.
    $this->actingAs($admin, 'admin')->putJson('/admin-api/orders/' . $quiet->id . '/status', ['status' => 'processing', 'notify' => false])->assertOk();

    Mail::assertSent(OrderConfirmation::class, 1);
    Mail::assertSent(OrderConfirmation::class, fn ($m) => $m->hasTo('buyer@example.com'));
});

it('B1: never receipts an imported WooCommerce order or a pre-package pending order a second time', function () {
    Mail::fake();
    $imported = rlOrder(['wc_order_id' => 991]);
    $imported->update(['status' => 'processing']);

    // An order still pending when this package lands already got its receipt
    // under the old code; the migration records that, so its payment later
    // sends no second one. Mutation: delete the backfill in the migration.
    $before = rlOrder(['email' => 'before@example.com']);
    DB::table('order_emails')->delete();
    (require database_path('migrations/2027_07_25_000100_create_order_emails.php'))->up();
    $before->update(['status' => 'processing']);

    Mail::assertNotSent(OrderConfirmation::class);
});

/* ============================================================ reminders === */

it('sends reminder 1 at 30 minutes and reminder 2 at 24 hours, each once', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-03 10:00:00');
    $order = rlOrder();
    $sweep = fn () => app(OrderReminders::class)->sweep(10);

    Carbon::setTestNow('2026-10-03 10:29:00');
    expect($sweep())->toBe(0);

    Carbon::setTestNow('2026-10-03 10:30:00');
    expect($sweep())->toBe(1);
    expect($sweep())->toBe(0); // claimed: a second sweep in the same minute sends nothing

    Carbon::setTestNow('2026-10-04 09:59:00');
    expect($sweep())->toBe(0);

    Carbon::setTestNow('2026-10-04 10:00:00');
    expect($sweep())->toBe(1);

    Carbon::setTestNow('2026-10-05 10:00:00');
    expect($sweep())->toBe(0);

    Mail::assertSent(OrderPaymentReminder::class, 2);
    Mail::assertSent(OrderPaymentReminder::class, fn ($m) => $m->stage === 1 && $m->hasTo('buyer@example.com'));
    Mail::assertSent(OrderPaymentReminder::class, fn ($m) => $m->stage === 2);
    expect(DB::table('order_emails')->where('order_id', $order->id)->pluck('kind')->sort()->values()->all())
        ->toBe(['reminder_1', 'reminder_2']);
    expect($order->notes()->where('content', 'like', '%Complete your order%')->count())->toBe(2);
});

it('stops reminding as soon as the order is paid, cancelled or replaced', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-03 10:00:00');
    $paid = rlOrder(['email' => 'a@example.com']);
    $cancelled = rlOrder(['email' => 'b@example.com']);
    $replaced = rlOrder(['email' => 'c@example.com']);

    Carbon::setTestNow('2026-10-03 10:05:00');
    rlOrder(['email' => 'c@example.com', 'status' => 'processing', 'paid_at' => now()]); // c bought again

    $paid->forceFill(['paid_at' => now(), 'status' => 'processing'])->save();
    $cancelled->update(['status' => 'cancelled']);

    Carbon::setTestNow('2026-10-03 10:31:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);
    Mail::assertNotSent(OrderPaymentReminder::class);
});

it('never reminds a cash-on-delivery order', function () {
    // Even one stuck in pending: COD is placed, not unpaid. Mutation: remove the
    // payment_method != 'cod' clause in OrderReminders::unfinished().
    Mail::fake();
    Carbon::setTestNow('2026-10-03 10:00:00');
    rlOrder(['payment_method' => 'cod']);
    rlOrder(['payment_method' => 'cod', 'status' => 'processing', 'email' => 'p@example.com']);

    Carbon::setTestNow('2026-10-03 10:40:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);
    Mail::assertNotSent(OrderPaymentReminder::class);
});

it('reminds a failed payment too, skips a reminder whose window has passed, and gives up at 72 hours', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-03 10:00:00');
    $failed = rlOrder(['status' => 'failed', 'email' => 'f@example.com']);
    $late = rlOrder(['email' => 'late@example.com']);
    Carbon::setTestNow('2026-09-29 10:00:00');
    rlOrder(['email' => 'ancient@example.com']);

    Carbon::setTestNow('2026-10-04 12:00:00'); // 26 h after the first two
    expect(app(OrderReminders::class)->sweep(10))->toBe(2);

    Mail::assertSent(OrderPaymentReminder::class, 2);
    Mail::assertNotSent(OrderPaymentReminder::class, fn ($m) => $m->stage === 1);
    Mail::assertNotSent(OrderPaymentReminder::class, fn ($m) => $m->hasTo('ancient@example.com'));
    expect(DB::table('order_emails')->whereIn('order_id', [$failed->id, $late->id])->where('kind', 'reminder_2')->count())->toBe(2);
});

it('sends no reminder when its switch is off', function () {
    Mail::fake();
    rlSwitch('email_order_reminder_1', false);
    Carbon::setTestNow('2026-10-03 10:00:00');
    rlOrder();

    Carbon::setTestNow('2026-10-03 10:31:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);

    rlSwitch('email_order_reminder_2', false);
    rlSwitch('email_order_reminder_1', true);
    Carbon::setTestNow('2026-10-04 10:01:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);
    Mail::assertNotSent(OrderPaymentReminder::class);
});

it('runs the sweep after an ordinary page request, at most once a minute, with no cron', function () {
    // The heartbeat. Mutation: return early at the top of
    // OrderReminderTick::onRequest() and nothing is sent.
    Mail::fake();
    Carbon::setTestNow(now()->subMinutes(40));
    rlOrder();
    Carbon::setTestNow();
    rlOrder(['email' => 'second@example.com', 'created_at' => now()->subMinutes(35)]);

    // Budget is per sweep; this checks a request triggers one and a second
    // request in the same minute does not.
    $this->get('/track-my-order/')->assertOk();
    Mail::assertSent(OrderPaymentReminder::class, 2);

    rlOrder(['email' => 'third@example.com', 'created_at' => now()->subMinutes(31)]);
    $this->get('/track-my-order/')->assertOk();
    Mail::assertSent(OrderPaymentReminder::class, 2);
    expect(is_file(OrderReminderTick::markerPath()))->toBeTrue();
});

it('costs an ordinary page no query when the heartbeat has run within the minute', function () {
    // The per-page half of the heartbeat's cost; StorefrontQueryBudgetTest
    // holds the sweep itself off for this reason. Mutation: drop the marker
    // check in OrderReminderTick::onRequest() and the sweep's queries appear.
    rlOrder(['created_at' => now()->subMinutes(45)]);
    @touch(OrderReminderTick::markerPath());

    $seen = [];
    DB::listen(function ($q) use (&$seen) {
        if (str_contains($q->sql, 'order_emails')) {
            $seen[] = $q->sql;
        }
    });

    $this->get('/track-my-order/')->assertOk();

    expect($seen)->toBe([]);
});

it('is scheduled every minute for the cron line, and the command sends what is due', function () {
    Mail::fake();
    rlOrder(['created_at' => now()->subMinutes(45)]);

    $this->artisan('kbb:order-reminders')->expectsOutputToContain('Sent 1 reminder')->assertSuccessful();

    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'kbb:order-reminders'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

/* ======================================================== status emails === */

it('emails each status the owner asked for, once, and none when its switch is off', function () {
    Mail::fake();
    $cases = [
        // status => [from, module key]
        'processing' => ['onhold', 'email_order_processing'],
        'completed' => ['shipped', 'email_order_completed'],
        'refunded' => ['processing', 'email_order_marked_refunded'],
        'failed' => ['pending', 'email_order_failed'],
        'cancelled' => ['processing', 'email_order_cancelled'],
        'shipped' => ['processing', 'email_order_shipped'],
    ];

    foreach ($cases as $status => [$from, $key]) {
        $on = rlOrder(['status' => $from, 'email' => "on-{$status}@example.com"]);
        $on->update(['status' => $status]);

        rlSwitch($key, false);
        $off = rlOrder(['status' => $from, 'email' => "off-{$status}@example.com"]);
        $off->update(['status' => $status]);
        rlSwitch($key, true);

        Mail::assertSent(OrderStatusChanged::class, fn ($m) => $m->status === $status && $m->hasTo("on-{$status}@example.com"));
        Mail::assertNotSent(OrderStatusChanged::class, fn ($m) => $m->hasTo("off-{$status}@example.com"));
    }

    Mail::assertSent(OrderStatusChanged::class, count($cases));
});

it('keeps the on-hold email off by default, and the tick sends it anyway', function () {
    Mail::fake();
    $quiet = rlOrder(['status' => 'processing']);
    $quiet->update(['status' => 'onhold']);
    Mail::assertNotSent(OrderStatusChanged::class);

    $ticked = rlOrder(['status' => 'processing', 'email' => 'ticked@example.com']);
    $this->actingAs(rlAdmin(), 'admin')
        ->putJson('/admin-api/orders/' . $ticked->id . '/status', ['status' => 'onhold', 'notify' => true])->assertOk();

    Mail::assertSent(OrderStatusChanged::class, fn ($m) => $m->status === 'onhold' && $m->hasTo('ticked@example.com'));
    Mail::assertSent(OrderStatusChanged::class, 1);
});

it('honours an unticked box for a status that is on', function () {
    Mail::fake();
    $order = rlOrder(['status' => 'shipped']);

    $this->actingAs(rlAdmin(), 'admin')
        ->putJson('/admin-api/orders/' . $order->id . '/status', ['status' => 'completed', 'notify' => false])->assertOk();

    expect($order->fresh()->status)->toBe('completed');
    Mail::assertNotSent(OrderStatusChanged::class);
});

it('serves the tick its default per status: on for the asked-for ones, off for on-hold', function () {
    $rows = collect(app(\App\Services\Mail\OrderStatusMailPolicy::class)->all())->keyBy('status');

    foreach (['processing', 'shipped', 'completed', 'cancelled', 'refunded', 'failed'] as $status) {
        expect($rows[$status]['supported'])->toBeTrue()->and($rows[$status]['enabled'])->toBeTrue("{$status} should ship ON");
    }

    expect($rows['onhold']['supported'])->toBeTrue()->and($rows['onhold']['enabled'])->toBeFalse()
        ->and($rows['pending']['supported'])->toBeFalse()
        ->and($rows['completed']['label'])->toBe('Completed (delivered)');
});

it('sends one email for a refund made on the payment screen, not a status email as well', function () {
    Mail::fake();
    $order = rlOrder(['status' => 'processing', 'paid_at' => now(), 'payment_method' => 'cod']);

    $order->refunds()->create(['amount' => 22000, 'status' => 'succeeded', 'provider' => 'cod', 'refunded_by' => 'Admin']);
    $order->update(['status' => 'refunded']);

    Mail::assertSent(OrderRefunded::class, 1);
    Mail::assertNotSent(OrderStatusChanged::class);
});

it('stays quiet when checkout fails a payment in front of the shopper, and emails when a webhook does', function () {
    // The shopper restoring their basket is looking at the page; the reminder
    // follows up. A provider's late decline is not seen, so it is emailed.
    rlStripe();
    Mail::fake();
    $order = rlOrder(['transaction_id' => 'pi_rl_fail']);

    app(\App\Services\Mail\OrderStatusMailPolicy::class)->decideFor($order, false);
    app(\App\Services\Orders\OrderStatus::class)->moveTo($order, 'failed', by: 'system', reason: 'test');
    Mail::assertNotSent(OrderStatusChanged::class);

    $hooked = rlOrder(['transaction_id' => 'pi_rl_fail2', 'email' => 'hook@example.com']);
    $raw = json_encode(['id' => 'evt_f', 'type' => 'payment_intent.canceled', 'data' => ['object' => [
        'id' => 'pi_rl_fail2', 'status' => 'canceled', 'metadata' => ['order_number' => $hooked->order_number],
    ]]]);
    $ts = time();
    $mac = hash_hmac('sha256', $ts . '.' . $raw, RL_STRIPE_SIGNING);
    app(GatewayRegistry::class)->find('stripe')->handleWebhook(rlWebhookRequest('stripe', RL_STRIPE_URL, $raw, ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$mac}"]));

    expect($hooked->fresh()->status)->toBe('failed');
    Mail::assertSent(OrderStatusChanged::class, fn ($m) => $m->status === 'failed' && $m->hasTo('hook@example.com'));
    Mail::assertSent(OrderStatusChanged::class, fn ($m) => str_contains($m->ctaUrl, '/checkout/order-pay?'));
});

/* ======================================================== on-hold manual === */

it('sends the on-hold email by hand with the owner\'s own message, only for an order on hold', function () {
    Mail::fake();
    $admin = rlAdmin();
    $order = rlOrder(['status' => 'onhold']);
    $other = rlOrder(['status' => 'processing', 'email' => 'other@example.com']);

    $detail = $this->actingAs($admin, 'admin')->getJson('/admin-api/orders/' . $order->id . '/detail')->assertOk()->json();
    expect($detail['actions']['real'])->toContain('email_onhold');
    $otherDetail = $this->actingAs($admin, 'admin')->getJson('/admin-api/orders/' . $other->id . '/detail')->json();
    expect($otherDetail['actions']['real'])->not->toContain('email_onhold');

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/action', ['action' => 'email_onhold', 'message' => "Please confirm your building <b>name</b>.\nThanks"])
        ->assertOk()->assertJsonPath('ok', true);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $other->id . '/action', ['action' => 'email_onhold'])
        ->assertStatus(422);

    Mail::assertSent(OrderStatusChanged::class, 1);
    $html = orderRlBody();
    expect($html)->toContain('Please confirm your building &lt;b&gt;name&lt;/b&gt;.<br />')
        ->and($html)->not->toContain('<b>name</b>');
    expect($order->notes()->where('content', 'like', 'Emailed the customer that the order is on hold.%')->count())->toBe(1);
});

it('refuses the manual on-hold email to an editor login', function () {
    $order = rlOrder(['status' => 'onhold']);
    $support = \App\Models\AdminUser::create(['name' => 'S', 'email' => 's-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => 'editor']);

    $this->actingAs($support, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/action', ['action' => 'email_onhold'])
        ->assertForbidden();
});

function orderRlBody(): string
{
    $body = '';
    Mail::assertSent(OrderStatusChanged::class, function ($m) use (&$body) {
        $body = (string) $m->render();

        return true;
    });

    return $body;
}

/* ========================================================= signed links === */

it('opens the status page from the email link on any device, for that order only, for 30 days', function () {
    Carbon::setTestNow('2026-10-03 10:00:00');
    $mine = rlOrder(['status' => 'shipped']);
    $theirs = rlOrder(['status' => 'processing', 'email' => 'someone@example.com']);
    $url = OrderLinks::trackUrl($mine);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    // A fresh browser: no session, no email typed.
    $this->get('/track-my-order/?' . http_build_query($q))
        ->assertOk()->assertSee($mine->order_number)->assertSee('Shipped');

    // The same token cannot open another order (the id is in the MAC).
    $this->get('/track-my-order/?' . http_build_query(['order' => $theirs->order_number, 't' => $q['t']]))
        ->assertNotFound()->assertDontSee($theirs->order_number . '</b>', false);

    // Forged.
    $this->get('/track-my-order/?' . http_build_query(['order' => $mine->order_number, 't' => explode('.', $q['t'])[0] . '.' . str_repeat('a', 64)]))
        ->assertNotFound();

    // Expired after 30 days. Mutation: drop the expiry check in
    // CustomerLinkSigner::verify() and this is a 200.
    Carbon::setTestNow('2026-11-03 00:00:01'); // 30 days after the next midnight
    $this->get('/track-my-order/?' . http_build_query($q))->assertNotFound();
});

it('puts the tracking number line and the signed link in the shipped email', function () {
    $order = rlOrder(['status' => 'shipped']);
    $mail = new OrderStatusChanged($order, 'shipped');
    $html = (string) $mail->render();

    expect($html)->toContain('Your tracking number is your order number: ' . $order->order_number)
        ->and($html)->toContain('Track your order')
        ->and($mail->ctaUrl)->toContain('/track-my-order/?order=' . $order->order_number . '&t=');

    parse_str((string) parse_url($mail->ctaUrl, PHP_URL_QUERY), $q);
    $this->get('/track-my-order/?' . http_build_query($q))->assertOk()->assertSee($order->order_number);
});

/* ==================================================== complete-your-order === */

it('opens the pay page for that unpaid order, lists the checkout\'s methods, and creates no second order', function () {
    rlStripe();
    rlTabby();
    $order = rlOrder();
    $count = Order::count();
    parse_str((string) parse_url(OrderLinks::payUrl($order), PHP_URL_QUERY), $q);

    $this->get('/checkout/order-pay?' . http_build_query($q))
        ->assertOk()->assertSee($order->order_number)->assertSee('Rice Toner')
        ->assertSee('value="stripe"', false)->assertSee('value="tabby"', false)
        ->assertSessionHas('kbb_last_order', $order->order_number);

    $intentPaid = false;
    Http::fake(['api.stripe.com/v1/payment_intents*' => function () use (&$intentPaid) {
        return Http::response($intentPaid
            ? ['id' => 'pi_rl_pay', 'status' => 'succeeded', 'amount' => 22000, 'amount_received' => 22000, 'currency' => 'aed']
            : ['id' => 'pi_rl_pay', 'client_secret' => 'pi_rl_pay_secret', 'status' => 'requires_payment_method'], 200);
    }]);

    $this->postJson('/checkout/order-pay', $q + ['method' => 'stripe'])
        ->assertOk()->assertJsonPath('action', 'confirm')->assertJsonPath('client_secret', 'pi_rl_pay_secret')
        ->assertJsonPath('order', $order->order_number);

    expect(Order::count())->toBe($count)
        ->and($order->fresh()->transaction_id)->toBe('pi_rl_pay');

    // The existing card confirmation endpoint now recognises this browser.
    $intentPaid = true;
    Mail::fake();
    $this->postJson('/checkout/card/paid', ['order' => $order->order_number])->assertOk();
    expect($order->fresh()->paid_at)->not->toBeNull();
    Mail::assertSent(OrderConfirmation::class, 1);
});

it('sends a Tabby payment from the pay page to Tabby, for the same order', function () {
    rlTabby();
    $order = rlOrder(['payment_method' => 'stripe']);
    parse_str((string) parse_url(OrderLinks::payUrl($order), PHP_URL_QUERY), $q);

    Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response([
        'id' => 'sess_rl', 'status' => 'created',
        'payment' => ['id' => 'pay_rl_2'],
        'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/rl']]]],
    ], 200), '*' => Http::response([], 200)]);

    $this->postJson('/checkout/order-pay', $q + ['method' => 'tabby'])
        ->assertOk()->assertJsonPath('action', 'redirect')->assertJsonPath('url', 'https://checkout.tabby.ai/rl');

    expect($order->fresh()->payment_method)->toBe('tabby');
});

it('reopens a failed order to pay it, and shows the status of one already paid', function () {
    rlStripe();
    $failed = rlOrder(['status' => 'failed']);
    $paid = rlOrder(['status' => 'processing', 'paid_at' => now(), 'email' => 'paid@example.com']);

    parse_str((string) parse_url(OrderLinks::payUrl($failed), PHP_URL_QUERY), $q);
    Http::fake(['api.stripe.com/v1/payment_intents*' => Http::response(['id' => 'pi_rl_re', 'client_secret' => 'pi_rl_re_s', 'status' => 'requires_payment_method'], 200)]);
    $this->postJson('/checkout/order-pay', $q + ['method' => 'stripe'])->assertOk()->assertJsonPath('action', 'confirm');
    expect($failed->fresh()->status)->toBe('pending');

    $paidUrl = OrderLinks::payUrl($paid);
    $this->get(substr($paidUrl, strpos($paidUrl, '/checkout/order-pay')))
        ->assertRedirect()->assertRedirectContains('/track-my-order/?order=' . $paid->order_number);
});

it('answers a forged, expired or other-order pay link with the same 404', function () {
    Carbon::setTestNow('2026-10-03 10:00:00');
    $mine = rlOrder();
    $theirs = rlOrder(['email' => 'x@example.com']);
    parse_str((string) parse_url(OrderLinks::payUrl($mine), PHP_URL_QUERY), $q);

    $this->get('/checkout/order-pay?' . http_build_query(['order' => $theirs->order_number, 't' => $q['t']]))
        ->assertNotFound()->assertDontSee('Rice Toner');
    $this->get('/checkout/order-pay?' . http_build_query(['order' => 'NO-SUCH', 't' => $q['t']]))->assertNotFound();
    $this->get('/checkout/order-pay?order=' . $mine->order_number . '&t=1.zz')->assertNotFound();
    $this->postJson('/checkout/order-pay', ['order' => $theirs->order_number, 't' => $q['t'], 'method' => 'stripe'])->assertNotFound();

    Carbon::setTestNow('2026-10-11 00:00:01'); // seven days after the next midnight, and a second
    $this->get('/checkout/order-pay?' . http_build_query($q))->assertNotFound();
});

it('does not offer a method whose surcharge would change the order total', function () {
    rlStripe();
    rlCod(1500);
    $order = rlOrder(); // card, no surcharge in its total

    $methods = app(\App\Http\Controllers\Store\OrderPayController::class)->methods($order);
    expect(array_column($methods, 'id'))->toBe(['stripe']);
});

/* ======================================================= rendering/wiring === */

it('renders every new email in HTML and text with no missing piece', function () {
    $order = rlOrder(['status' => 'pending']);

    foreach (['processing', 'onhold', 'completed', 'refunded', 'failed', 'shipped', 'cancelled'] as $status) {
        $mail = new OrderStatusChanged($order, $status);
        $html = (string) $mail->render();
        $text = view($mail->content()->text, $mail->buildViewData() + $mail->content()->with)->render();
        expect($html)->toContain($order->order_number)->and(trim($text))->not->toBe('')
            ->and(trim((string) $mail->envelope()->subject))->not->toBe('');
    }

    foreach ([1, 2] as $stage) {
        $mail = new OrderPaymentReminder($order, $stage);
        expect((string) $mail->render())->toContain('Complete your order')->toContain('/checkout/order-pay?');
    }
});

it('keeps the status wording in one place: the constant and the keyed English agree', function () {
    foreach (['processing', 'onhold', 'completed', 'refunded', 'failed'] as $status) {
        [$subject, $heading, $body] = OrderStatusChanged::WORDING[$status];
        expect(__("email.order_status.{$status}_subject"))->toBe(str_replace(['%1$s', '%2$s'], [':store', ':number'], $subject))
            ->and(__("email.order_status.{$status}_heading"))->toBe($heading)
            ->and(__("email.order_status.{$status}_body"))->toBe($body);
    }
});

it('mounts the pay page at most once, and nothing about orders under /api', function () {
    // The integrator adds `require __DIR__.'/order-pay.php';` to routes/web.php.
    // Two would be a double mount; tighten to exactly 1 once it is wired.
    $web = file_get_contents(base_path('routes/web.php'));
    expect(substr_count($web, "require __DIR__.'/order-pay.php';"))->toBeLessThanOrEqual(1);

    expect(file_get_contents(base_path('routes/order-pay.php')))->not->toContain("'/api/");
});

/* ======================================================= feedback request === */

function rlDelivered(array $overrides = []): Order
{
    $product = Product::create(['slug' => 'rl-glow-' . uniqid(), 'name' => 'Glow Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 200, 'stock_status' => 'instock']);
    $order = rlOrder(array_merge(['status' => 'shipped', 'paid_at' => now()], $overrides));
    $order->items()->update(['product_id' => $product->id]);

    return $order->fresh('items');
}

it('asks for feedback 3 hours after the Delivered email went, once, with a link to each product\'s reviews', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-03 10:00:00');
    $order = rlDelivered();
    $order->update(['status' => 'completed']);
    Mail::assertSent(OrderStatusChanged::class, fn ($m) => $m->status === 'completed');

    Carbon::setTestNow('2026-10-03 12:59:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);

    // Mutation: change FEEDBACK_AFTER_HOURS to 0 and the sweep above sends.
    Carbon::setTestNow('2026-10-03 13:00:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(1);
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);

    Mail::assertSent(\App\Mail\OrderFeedbackRequest::class, 1);
    Mail::assertSent(\App\Mail\OrderFeedbackRequest::class, function ($m) {
        $html = (string) $m->render();

        return count($m->products) === 1
            && str_contains($m->products[0]['url'], '/product/rl-glow-')
            && str_ends_with($m->products[0]['url'], '/#sr')
            && ! str_contains($m->products[0]['url'], 'rating=')
            && str_contains($html, 'Write a review');
    });
});

it('asks for no feedback when the Delivered email was unticked, switched off, or the order was refunded since', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-03 10:00:00');

    // Mutation: in OrderMailer::statusChanged() record `status_completed`
    // before the policy check and the unticked order is asked.
    $unticked = rlDelivered(['email' => 'u@example.com']);
    $this->actingAs(rlAdmin(), 'admin')
        ->putJson('/admin-api/orders/' . $unticked->id . '/status', ['status' => 'completed', 'notify' => false])->assertOk();

    rlSwitch('email_order_completed', false);
    $off = rlDelivered(['email' => 'o@example.com']);
    $off->update(['status' => 'completed']);
    rlSwitch('email_order_completed', true);

    $refunded = rlDelivered(['email' => 'r@example.com']);
    $refunded->update(['status' => 'completed']);
    $refunded->update(['status' => 'refunded']);

    Carbon::setTestNow('2026-10-03 14:00:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);
    Mail::assertNotSent(\App\Mail\OrderFeedbackRequest::class);

    rlSwitch('email_order_feedback', false);
    $later = rlDelivered(['email' => 'l@example.com']);
    $later->update(['status' => 'completed']);
    Carbon::setTestNow('2026-10-03 18:00:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);
});

/* =============================================================== emoji === */

it('carries the owner\'s emoji in subjects, encoded as UTF-8 in the header, and readable in the text part', function () {
    $order = rlOrder(['status' => 'completed']);
    config(['mail.mailers.rltest' => ['transport' => 'array']]);

    $cases = [
        '🎉' => new OrderConfirmation($order),
        '🛍️' => new OrderPaymentReminder($order, 1),
        '⏳' => new OrderPaymentReminder($order, 2),
        '🚚💨' => new OrderStatusChanged($order, 'shipped'),
        '✨' => new OrderStatusChanged($order, 'completed'),
    ];

    foreach ($cases as $emoji => $mailable) {
        Mail::mailer('rltest')->to('buyer@example.com')->send($mailable);
        $sent = app('mail.manager')->mailer('rltest')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        expect($sent->getSubject())->toEndWith($emoji);
        // RFC 2047: the subject travels as encoded-words, never raw 8-bit.
        // Unfolded first: a long subject is folded onto a continuation line.
        expect(preg_replace("/\r\n[ \t]/", ' ', $sent->toString()))->toMatch('/^Subject: [^\r\n]*=\?utf-8\?Q\?/mi');
        // The text part is UTF-8 and keeps the heading readable; the receipt's
        // text greeting has never carried a symbol and still does not.
        $text = (string) $sent->getTextBody();
        expect(mb_check_encoding($text, 'UTF-8'))->toBeTrue()
            ->and($text)->toContain($emoji === '🎉' ? 'Thank you, Aisha' : $emoji);
    }

    // Payment failed: the approved preview's title carries no emoji; its
    // heading does ("The payment did not go through 😔").
    $failed = new OrderStatusChanged($order, 'failed');
    expect($failed->envelope()->subject)->toBe('Payment did not go through')
        ->and((string) $failed->render())->toContain('The payment did not go through 😔');
});

it('uses the owner-approved preview wording, word for word', function () {
    // docs/rj-email-previews/after at 9d6dea4, approved 3 October:
    // "i want 100% same stuff as in previews".
    $order = rlOrder(['paid_at' => now(), 'status' => 'processing']);
    $render = fn ($m) => html_entity_decode(strip_tags((string) $m->render()));

    $r1 = new OrderPaymentReminder($order, 1);
    expect($r1->envelope()->subject)->toBe('Complete your order 🛍️');
    expect($render($r1))->toContain('You are one step away 🛍️')
        ->toContain('We saved your order, but the payment was not completed, so it is not confirmed yet. Everything is below — finish in one tap.')
        ->toContain('Fast delivery · 1–3 days, all over the UAE')
        ->toContain('100% original · straight from the brand')
        ->toContain('Free samples · random K-beauty samples in every order')
        ->toContain('Already paid? Ignore this — your confirmation is on its way.');

    $r2 = new OrderPaymentReminder($order, 2);
    expect($r2->envelope()->subject)->toBe('Your order is still waiting ⏳');
    expect($render($r2))->toContain('Your order is still waiting for you ⏳')
        ->toContain('This is the last reminder about this order.');

    $hold = new OrderStatusChanged($order, 'onhold', 'Your building name');
    expect($hold->envelope()->subject)->toBe('Order on hold');
    expect($render($hold))->toContain('We have paused your order')
        ->toContain('Nothing is wrong with your items — we just need to confirm one detail before we can send it.')
        ->toContain('What we need: Your building name Reply to this email or message us on WhatsApp and we will carry on right away.');

    $done = new OrderStatusChanged($order, 'completed');
    expect($done->envelope()->subject)->toBe('Delivered ✨');
    expect($render($done))->toContain('Enjoy your new routine ✨')
        ->toContain('Your order is complete. Open it, try it, and enjoy the little extras we tucked in.');

    $failed = new OrderStatusChanged($order, 'failed');
    expect($render($failed))->toContain('Nothing was charged and the order is not confirmed. Your order is saved — try again or choose another way to pay.');

    $receipt = new OrderConfirmation($order);
    // The first name, as the owner's approved preview greets her (Lane EM).
    expect($render($receipt))->toContain('Thank you, Aisha! 🎉')
        ->toContain('Your payment is in and your order is confirmed. We are packing it with care — keep this email, it is your receipt.');
    // Cash on delivery has paid nothing, so it is not told it has.
    expect($render(new OrderConfirmation(rlOrder(['payment_method' => 'cod', 'status' => 'processing']))))
        ->not->toContain('Your payment is in');
});

it('puts the three reasons to shop in both reminders', function () {
    $order = rlOrder();

    foreach ([1, 2] as $stage) {
        $mail = new OrderPaymentReminder($order, $stage);
        $html = (string) $mail->render();
        $text = view($mail->content()->text, $mail->buildViewData() + $mail->content()->with)->render();

        foreach (['Fast delivery', '1–3 days, all over the UAE', '100% original', 'straight from the brand', 'Free samples', 'random K-beauty samples in every order'] as $line) {
            expect($html)->toContain($line)->and($text)->toContain($line);
        }
    }
});
