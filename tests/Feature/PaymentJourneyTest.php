<?php

declare(strict_types=1);

/*
 * PAYMENT JOURNEY — Lane TM, from Order #56187 (10 Oct 2026).
 *
 * WHAT IT LOOKED LIKE ON THE SHOP: a Tamara order for AED 590 went to Failed
 * with one note, "Status changed from pending to failed. The payment could not
 * be started." The owner could not tell whether the shop's request was refused,
 * the shopper walked away, or Tamara said no. Nothing recorded the answer:
 * TamaraGateway::start() threw Tamara's reply away (call() returns null for any
 * non-2xx), and the one line it wrote was Log::info, which the live .env
 * (LOG_LEVEL=error, public-web-root/install.php) drops.
 *
 * Each case names the mutation that turns it red.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentLog;
use App\Support\PaymentJourney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\OwnerAppRoutes as OA;

const PJ_API_TOKEN = 'TM_TAMARA_API_TOKEN_CANARY_eyJhbGciOi';
const PJ_NOTIFY = 'tm-tamara-notification-token';
const PJ_URL_SECRET = 'whsec-tm-0123456789abcdef';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'title' => 'Free delivery',
        'cost' => 0, 'enabled' => true, 'position' => 0]);
});

function pjProvider(string $id, array $config, string $mode = 'live'): void
{
    $row = PaymentProvider::create(['id' => $id, 'title' => ucfirst($id), 'enabled' => true, 'mode' => $mode, 'position' => 1]);
    $row->config = $config;
    $row->save();
    app(GatewayCredentials::class)->forget();
}

function pjTamara(): void
{
    pjProvider('tamara', ['api_token' => PJ_API_TOKEN, 'notification_token' => PJ_NOTIFY, 'webhook_secret' => PJ_URL_SECRET]);
}

/** Order #56187's basket: two sets, AED 215 + AED 375, free delivery. */
function pjCart(): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);

    foreach ([['Dr. Althea 345 Relief Cream + Mist Spray Set', 21500], ['Anua PDRN Glass Skin Set', 37500]] as [$name, $fils]) {
        $p = Product::create(['slug' => 'tm-' . Str::random(10), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
            'price' => $fils / 100, 'stock_status' => 'instock']);
        $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => $fils]);
    }

    return $cart;
}

/** Places the order the way the shopper did, through POST /checkout/place. */
function pjPlace(string $method): Order
{
    $cart = pjCart();

    test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'alia@example.com',
            'billing_phone' => '0508883841',
            'billing_first_name' => 'Alia',
            'billing_last_name' => 'Abdulla',
            'billing_address_1' => 'Villa 34',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => $method,
        ]);

    return Order::latest('id')->firstOrFail();
}

function pjNote(Order $order): string
{
    return (string) $order->notes()->latest('id')->value('content');
}

/* ───────────────────────────────────────── the reason reaches the order note */

it('writes Tamara\'s refusal, status and reason into the failed order\'s note', function () {
    pjTamara();
    // A refusal that echoes the buyer's phone and email back, as providers do.
    Http::fake(['*/checkout' => Http::response([
        'message' => 'Invalid request for 0508883841 / alia@example.com',
        'errors' => [['error_code' => 'invalid_phone_number', 'field' => 'consumer.phone_number']],
    ], 400)]);

    $order = pjPlace('tamara');
    $note = pjNote($order);

    // MUTATION: put 'The payment could not be started.' back as a fixed string
    // in Store\CheckoutController and this reads exactly the 56187 note again.
    expect($order->status)->toBe('failed')
        ->and($note)->toContain('The payment could not be started. Tamara refused the checkout: HTTP 400')
        ->and($note)->toContain('consumer.phone_number: invalid_phone_number')
        // Sanitised: the echoed phone and email never reach the note.
        ->and($note)->not->toContain('0508883841')
        ->and($note)->not->toContain('alia@example.com')
        ->and($note)->not->toContain(PJ_API_TOKEN);

    $log = DB::table(PaymentLog::TABLE)->where('gateway', 'tamara')->where('event', 'checkout_refused')->first();
    expect($log)->not->toBeNull()
        ->and(json_decode($log->context, true))->toMatchArray(['order' => (string) $order->order_number, 'http_status' => 400])
        ->and($log->message . $log->context)->not->toContain(PJ_API_TOKEN)->not->toContain('0508883841');
});

it('puts the refusal in laravel.log at ERROR, which the live LOG_LEVEL=error keeps', function () {
    // The live .env (install.php) is LOG_CHANNEL=stack, LOG_LEVEL=error, and the
    // owner's `grep "payments:"` on it found nothing: every line the gateways
    // wrote was INFO. MUTATION: change Log::error in RemoteGateway::notStarted()
    // to info and this file stays empty.
    $file = storage_path('logs/pj-' . getmypid() . '.log');
    @unlink($file);
    config(['logging.default' => 'pjtest', 'logging.channels.pjtest' => ['driver' => 'single', 'path' => $file, 'level' => 'error']]);
    app('log')->forgetChannel('pjtest');
    pjTamara();
    Http::fake(['*/checkout' => Http::response(['message' => 'Invalid request', 'errors' => [['error_code' => 'invalid_phone_number']]], 400)]);

    $order = pjPlace('tamara');
    $log = (string) @file_get_contents($file);
    @unlink($file);

    expect($log)->toContain('ERROR: payments: checkout not started')
        ->toContain('"order":"' . $order->order_number . '"')->toContain('"status":400')->toContain('invalid_phone_number')
        ->not->toContain(PJ_API_TOKEN)->not->toContain('0508883841');
});

it('says a 401 is the token, and that a transport failure never reached Tamara', function () {
    pjTamara();
    Http::fake(['*/checkout' => Http::response(['message' => 'Unauthorized'], 401)]);
    expect(pjNote(pjPlace('tamara')))->toContain('HTTP 401 — Unauthorized')->toContain('API token was not accepted');

    Http::fake(['*/checkout' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out')]);
    expect(pjNote(pjPlace('tamara')))->toContain('Tamara could not be reached: no answer within 20 seconds (timed out)');
});

it('records the shopper being sent to Tamara when the session opens', function () {
    pjTamara();
    Http::fake(['*/checkout' => Http::response(['order_id' => 'tam_1', 'checkout_url' => 'https://checkout.tamara.co/x'])]);

    $order = pjPlace('tamara');

    // The success path is unchanged: still pending, still the Tamara id.
    expect($order->status)->toBe('pending')->and($order->transaction_id)->toBe('tam_1');
    $j = PaymentJourney::for($order);
    expect(collect($j['steps'])->pluck('title')->all())->toContain('Payment session opened — shopper sent to Tamara');
});

it('names the guard that stopped Tamara before any request was sent', function () {
    pjProvider('tamara', ['api_token' => PJ_API_TOKEN, 'notification_token' => PJ_NOTIFY, 'excluded_products' => 'EXCL-1']);
    $order = Order::create(['order_number' => 'TM-' . uniqid(), 'email' => 'a@example.com', 'phone' => '0508883841',
        'status' => 'pending', 'currency' => 'AED', 'subtotal' => 100, 'total' => 100, 'payment_method' => 'tamara']);
    $order->items()->create(['name' => 'X', 'sku' => 'EXCL-1', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'total' => 100]);
    Http::fake();

    $start = app(GatewayRegistry::class)->find('tamara')->start($order->fresh());

    Http::assertNothingSent();
    expect($start->ok())->toBeFalse()
        ->and($start->message)->toBe('Tamara cannot be used for one of the items in this order. Please choose another payment method.')
        ->and($start->detail)->toContain('Tamara was not asked');
});

/* ───────────────────────────────────────────────────────────── Tabby, Stripe */

it('records Tabby\'s pre-check rejection with its reason', function () {
    pjProvider('tabby', ['public_key' => 'pk_x', 'secret_key' => 'sk_tabby_canary', 'merchant_code' => 'kbb']);
    Http::fake(['*/api/v2/checkout' => Http::response([
        'status' => 'rejected',
        'configuration' => ['products' => ['installments' => ['rejection_reason' => 'order_amount_too_high']]],
    ])]);

    $note = pjNote(pjPlace('tabby'));

    // MUTATION: drop the `default =>` arm in TabbyGateway::start() and the
    // reason is gone; restore call() and there is no detail at all.
    expect($note)->toContain('Tabby declined at its pre-check: status rejected, reason order_amount_too_high')
        ->and($note)->not->toContain('sk_tabby_canary');
});

it('records why a card payment could not be opened at Stripe', function () {
    pjProvider('stripe', ['secret_key' => 'sk_live_TMcanary000', 'publishable_key' => 'pk_live_TM000']);
    Http::fake(['*/v1/payment_intents*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: sk_live_****0000']], 401)]);

    $note = pjNote(pjPlace('stripe'));

    // MUTATION: drop the second argument of the PaymentStart::failed() after
    // intent.failed in StripeGateway and the note is the bare 56187 sentence.
    expect($note)->toContain('The payment could not be started. Stripe refused the secret key for this Mode')
        ->and($note)->not->toContain('sk_live_TMcanary000');
});

/* ─────────────────────────────────────────────────── webhooks and the return */

it('logs a verified Tamara notification to the order\'s journey, and an unverified one not at all', function () {
    pjTamara();
    $order = Order::create(['order_number' => 'TM-' . uniqid(), 'email' => 'a@example.com', 'status' => 'pending',
        'currency' => 'AED', 'subtotal' => 59000, 'total' => 59000, 'payment_method' => 'tamara']);
    Http::fake();

    $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $jwt = function (string $key) use ($b64) {
        $h = $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $p = $b64(json_encode(['iat' => time()]));

        return $h . '.' . $p . '.' . $b64(hash_hmac('sha256', $h . '.' . $p, $key, true));
    };
    $send = function (string $key) use ($order) {
        $r = Request::create('/api/payments/webhook/tamara/' . PJ_URL_SECRET, 'POST', [], [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . ($key === '' ? 'x' : $key), 'CONTENT_TYPE' => 'application/json'],
            json_encode(['order_reference_id' => $order->order_number, 'order_id' => 'tam_9', 'event_type' => 'order_declined', 'order_status' => 'declined']));
        $route = new \Illuminate\Routing\Route(['POST'], '/api/payments/webhook/{gateway}/{secret}', fn () => null);
        $route->bind($r);
        $r->setRouteResolver(fn () => $route);

        return app(GatewayRegistry::class)->find('tamara')->handleWebhook($r);
    };

    $send($jwt('wrong-key'));
    expect(DB::table(PaymentLog::TABLE)->where('event', 'webhook')->count())->toBe(0);

    $send($jwt(PJ_NOTIFY));
    $j = PaymentJourney::for($order->fresh());

    expect(collect($j['steps'])->pluck('detail')->implode(' | '))->toContain('Tamara notification: order_declined (declined).')
        ->and($j['verdict']['text'])->toContain('reported the payment as order declined');
});

it('records the shopper coming back once, and only for the session that placed the order', function () {
    $order = Order::create(['order_number' => 'TM-' . uniqid(), 'email' => 'a@example.com', 'status' => 'failed',
        'currency' => 'AED', 'subtotal' => 100, 'total' => 100, 'payment_method' => 'tamara']);

    // A stranger with the URL: nothing written.
    $this->get('/checkout/pending?order=' . $order->order_number . '&paymentStatus=canceled');
    expect(DB::table(PaymentLog::TABLE)->where('event', 'returned')->count())->toBe(0);

    $this->withSession(['kbb_last_order' => $order->order_number]);
    $this->get('/checkout/pending?order=' . $order->order_number . '&paymentStatus=canceled');
    $this->get('/checkout/pending?order=' . $order->order_number . '&paymentStatus=canceled');

    $rows = DB::table(PaymentLog::TABLE)->where('event', 'returned')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->message)->toContain('came back from Tamara to the not-finished page (the return link said "canceled"');
});

/* ──────────────────────────────────────────────────── the verdict, by elimination */

it('reads Order #56187\'s note, written before any of this, as "never reached Tamara"', function () {
    $order = Order::create(['order_number' => '56187', 'email' => 'a@example.com', 'status' => 'failed',
        'currency' => 'AED', 'subtotal' => 59000, 'total' => 59000, 'payment_method' => 'tamara']);
    $order->notes()->create(['author' => 'system', 'is_customer_note' => false,
        'content' => 'Status changed from pending to failed. The payment could not be started.']);

    $j = PaymentJourney::for($order->fresh());

    expect($j['verdict']['tone'])->toBe('w')
        ->and($j['verdict']['text'])->toStartWith('Never reached Tamara.')
        ->and($j['verdict']['text'])->toContain('The reason was not recorded for this order.');
});

it('tells an abandoned Tamara payment apart from one that never started', function () {
    $order = Order::create(['order_number' => 'TM-' . uniqid(), 'email' => 'a@example.com', 'status' => 'failed',
        'currency' => 'AED', 'subtotal' => 100, 'total' => 100, 'payment_method' => 'tamara']);
    $order->notes()->create(['author' => 'system', 'is_customer_note' => false,
        'content' => 'Status changed from pending to failed. The shopper came back without finishing the payment; nothing was charged and their basket was given back.']);

    expect(PaymentJourney::for($order->fresh())['verdict']['text'])->toStartWith('The shopper reached Tamara and came back without finishing.');
});

it('builds the journey in the same number of queries for 3 log rows and for 40', function () {
    $count = function (int $n) {
        $order = Order::create(['order_number' => 'TM-' . uniqid(), 'email' => 'a@example.com', 'status' => 'pending',
            'currency' => 'AED', 'subtotal' => 100, 'total' => 100, 'payment_method' => 'tamara']);
        for ($i = 0; $i < $n; $i++) {
            PaymentLog::record('tamara', 'info', 'webhook', 'Tamara notification: x.', ['order' => $order->order_number]);
            $order->notes()->create(['author' => 'system', 'is_customer_note' => false, 'content' => 'note ' . $i]);
        }
        $order = Order::with('notes')->find($order->id);
        PaymentJourney::for($order); // warm the per-process settings map, read once per request in production
        DB::flushQueryLog();
        DB::enableQueryLog();
        PaymentJourney::for($order);
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(3))->toBe($count(40));
});

it('costs a cash-on-delivery order nothing: no journey and no query', function () {
    $order = Order::create(['order_number' => 'TM-' . uniqid(), 'email' => 'a@example.com', 'status' => 'processing',
        'currency' => 'AED', 'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod']);
    DB::flushQueryLog();
    DB::enableQueryLog();

    // MUTATION: drop the NAMES guard at the top of for() and this is three
    // queries and a journey — and OrderMoneyAuditTest's ceiling goes red.
    expect(PaymentJourney::for($order))->toBeNull()->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

/* ───────────────────────────────────── customer journey and emails (Lane TM) */

it('shows where the shopper came from and what went in the bag, from what the shop recorded', function () {
    $order = Order::create(['order_number' => 'TM-' . uniqid(), 'email' => 'alia@example.com', 'status' => 'failed',
        'currency' => 'AED', 'subtotal' => 59000, 'total' => 59000, 'payment_method' => 'tamara',
        'src_channel' => 'instagram_ads', 'src_campaign' => 'oct-sets',
        'src_attr' => json_encode(['first' => ['ch' => 'instagram_ads', 's' => 'instagram', 'm' => 'cpc', 'c' => 'oct-sets', 'k' => 'f', 'p' => '/collections/skincare-sets/', 'd' => 1], 'last' => null, 'days' => 0])]);
    $p = Product::create(['slug' => 'tm-' . Str::random(8), 'name' => 'Anua PDRN Glass Skin Set', 'status' => 'publish', 'price' => 375, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now()]);
    DB::table('carts')->where('id', $cart->id)->update(['ct_order_id' => $order->id, 'ct_first_at' => now()->subMinutes(9)]);
    DB::table('cart_events')->insert(['cart_id' => $cart->id, 'type' => \App\Services\CartTracking\CartTracker::ADD, 'product_id' => $p->id, 'qty' => 1, 'qty_after' => 1, 'unit_price' => 37500, 'created_at' => now()->subMinutes(8)]);

    $j = \App\Support\CustomerJourney::for($order->fresh());
    $text = collect($j['steps'])->map(fn ($s) => $s['title'] . ': ' . $s['detail'])->implode(' | ');

    // MUTATION: drop the cart query and "Added to bag" is gone; drop the
    // Attribution panel and "Arrived from" is gone.
    expect($text)->toContain('source instagram')->toContain('campaign oct-sets')->toContain('first page /collections/skincare-sets/')
        ->toContain('Basket started: Cart Tracking basket #' . $cart->id)
        ->toContain('Added to bag: Anua PDRN Glass Skin Set')
        ->toContain('Order placed')
        ->and($j['cart_id'])->toBe($cart->id)
        ->and($j['not_tracked'])->toContain('not recorded per shopper');
});

it('lists the emails sent for the order with their result, and not another order\'s', function () {
    $order = Order::create(['order_number' => '56187', 'email' => 'alia@example.com', 'status' => 'failed',
        'currency' => 'AED', 'subtotal' => 100, 'total' => 100, 'payment_method' => 'tamara']);
    $row = fn (array $r) => DB::table('mail_deliveries')->insert($r + ['transport' => 'smtp', 'created_at' => now(), 'updated_at' => now()]);
    $row(['kind' => 'order.reminder_first', 'recipient' => 'alia@example.com', 'subject' => 'Your order #56187 is waiting', 'status' => 'sent']);
    $row(['kind' => 'order.merchant_alert', 'recipient' => 'shop@example.com', 'subject' => 'New order #56187', 'status' => 'failed', 'error' => 'Connection refused']);
    $row(['kind' => 'order.confirmation', 'recipient' => 'someone@example.com', 'subject' => 'Order #561870 received', 'status' => 'sent']);
    $row(['kind' => 'order.confirmation', 'recipient' => 'xalia@example.com', 'subject' => 'Order #1 received', 'status' => 'sent']);

    $m = \App\Support\OrderEmails::for($order->fresh());

    expect(array_column($m['rows'], 'type'))->toBe(['Payment reminder (first)', 'New-order alert (to the shop)'])
        ->and(array_column($m['rows'], 'status'))->toBe(['sent', 'failed'])
        ->and($m['rows'][1]['error'])->toBe('Connection refused')
        ->and($m['note'])->toContain('not stored');
});

it('builds the customer journey and the email list in the same queries for 3 events and for 40', function () {
    $count = function (int $n) {
        $order = Order::create(['order_number' => 'TM-' . uniqid(), 'email' => 'b' . $n . '@example.com', 'status' => 'pending',
            'currency' => 'AED', 'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod']);
        $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'last_activity_at' => now()]);
        DB::table('carts')->where('id', $cart->id)->update(['ct_order_id' => $order->id]);
        for ($i = 0; $i < $n; $i++) {
            DB::table('cart_events')->insert(['cart_id' => $cart->id, 'type' => 1, 'qty' => 1, 'qty_after' => 1, 'unit_price' => 1, 'created_at' => now()]);
            DB::table('mail_deliveries')->insert(['kind' => 'order.status_x', 'recipient' => 'b' . $n . '@example.com', 'subject' => 's', 'transport' => 'smtp', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        }
        $order = $order->fresh();
        \App\Support\CustomerJourney::for($order);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $j = \App\Support\CustomerJourney::for($order);
        $e = \App\Support\OrderEmails::for($order);
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect(count($e['rows']))->toBe(min($n, 40));

        return $q;
    };

    expect($count(3))->toBe($count(40))->toBe(2);
});

/* ─────────────────────────────────────────────────────────── the owner app */

it('gives the owner app the order\'s phone as stored, and the journey, behind its sign-in', function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    OA::member(OA::admin());

    $addr = ['first_name' => 'Alia', 'last_name' => 'Abdulla', 'line1' => 'Villa 34', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'];
    $order = Order::create(['order_number' => '56187', 'email' => 'a@example.com', 'phone' => '+971 50 000 0000', 'status' => 'failed',
        'currency' => 'AED', 'subtotal' => 59000, 'total' => 59000, 'payment_method' => 'tamara',
        'billing_address' => $addr + ['phone' => '0501111111'], 'shipping_address' => $addr + ['phone' => '0508883841']]);

    // Signed out: refused, and no phone in the answer.
    $anon = OA::get($this, 'orders/' . $order->id, []);
    expect($anon->status())->toBeGreaterThanOrEqual(400)->and($anon->getContent())->not->toContain('0508883841');

    [$c] = OA::enrol($this);
    $o = OA::get($this, 'orders/' . $order->id, $c)->assertOk()->json('order');

    // MUTATION: drop 'phone' from the customer block and this is null.
    expect($o['customer']['phone'])->toBe('0508883841')
        ->and($o['payment_journey']['verdict']['text'])->toBeString()
        ->and($o['customer_journey']['steps'])->toBeArray()
        ->and($o['emails']['rows'])->toBe([]);

    // Billing when shipping has none.
    $order->forceFill(['shipping_address' => $addr])->save();
    expect(OA::get($this, 'orders/' . $order->id, $c)->json('order.customer.phone'))->toBe('0501111111');
});

it('draws the phone as a tel: link and the journey card, escaped, in the owner app', function () {
    $js = file_get_contents(resource_path('js/owner-app/orders.js'));

    expect(substr_count($js, "+ journeyCard(o)"))->toBe(1)
        ->and(substr_count($js, "+ storyCards(o)"))->toBe(1)
        ->and($js)->toContain("'<div class=\"kv cu-ph\"><span>Phone</span><a href=\"tel:' + esc(tel) + '\">' + esc(phone) + '</a></div>'")
        ->and($js)->toContain("const tel = phone.replace(/[^\\d+]/g, '');");

    $admin = file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($admin, 'odJourney(o.payment_journey)'))->toBe(1)
        ->and(substr_count($admin, '+odJourneyCard(o)+odEmailsCard(o)+'))->toBe(1);
});
