<?php

declare(strict_types=1);

/*
 * "Send order link" (Lane OL). The owner: "if any order failed, i need a proper
 * button (send order link) so the app will generate a link and the customer can
 * continue with the same cart etc and select the payment to complete the order."
 *
 * Order #56187 (Tamara, AED 590, from an Instagram ad) is the shape: a real
 * customer ready to buy, whose payment never started. Each case below names the
 * defect it guards.
 */

use App\Http\Controllers\Admin\OrderPayLinkController;
use App\Mail\OrderPayLink as OrderPayLinkMail;
use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Orders\OrderPayLink;
use App\Services\Orders\OrderStatus;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\OrderLinks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);

    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 3]);
    app(SettingsService::class)->set('cod_fee', 0);
});

afterEach(function () {
    Carbon::setTestNow();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/* ------------------------------------------------------------- fixtures -- */

function olAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'Rafi ' . ucfirst($role), 'email' => 'ol-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function olProduct(array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => 'ol-' . Str::random(8), 'name' => 'Rice Toner', 'status' => 'publish', 'is_visible' => true,
        'price' => 20000, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 5,
    ], $o));
}

function olOrder(array $o = [], ?Product $product = null): Order
{
    $order = Order::create(array_merge([
        'order_number' => (string) random_int(56000, 59999) . random_int(10, 99),
        'email' => 'alia@example.com',
        'phone' => '050 888 3841',
        'status' => 'failed',
        'currency' => 'AED',
        'locale' => 'en',
        'billing_address' => ['first_name' => 'Alia', 'last_name' => 'Saeed', 'line1' => 'Villa 9', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '050 888 3841'],
        'shipping_address' => ['first_name' => 'Alia', 'last_name' => 'Saeed', 'line1' => 'Villa 9', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '050 888 3841'],
        'subtotal' => 57000, 'discount_total' => 0, 'shipping_total' => 2000, 'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 59000,
        'shipping_method' => 'Standard delivery', 'payment_method' => 'tamara', 'payment_method_title' => 'Tamara',
    ], $o));

    $product ??= olProduct();
    $order->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit_price' => 57000, 'subtotal' => 57000, 'total' => 57000]);

    return $order->fresh('items');
}

/** A real placement through the checkout (COD), so stock and the coupon are really taken. */
function olPlace(Product $product, ?Coupon $coupon = null): Order
{
    app(CartService::class)->forget();
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'coupon_id' => $coupon?->id, 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);
    $before = (int) Order::max('id');

    test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'alia@example.com', 'billing_phone' => '0508883841', 'billing_first_name' => 'Alia', 'billing_last_name' => 'Saeed',
            'billing_address_1' => 'Villa 9', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
        ]);

    return Order::where('id', '>', $before)->orderBy('id')->firstOrFail();
}

/** @return array{order: string, t: string} */
function olQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return $q;
}

function olStripe(): void
{
    $row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = ['publishable_key' => 'pk_test_ol', 'secret_key' => 'sk_test_ol', 'webhook_signing_secret' => 'whsec_ol', 'webhook_secret' => 'whsec-url-ol-0123456789abcdef'];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function olTabby(): void
{
    $row = PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    $row->config = ['public_key' => 'pk_test_11111111-2222-3333-4444-555555555555', 'secret_key' => 'sk_test_11111111-2222-3333-4444-555555555555', 'merchant_code' => 'AE', 'webhook_secret' => 'whsec-tabby-ol-abcdefghijklmnopqrstuvwxyz0123'];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function olTamara(): void
{
    $row = PaymentProvider::create(['id' => 'tamara', 'title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 2]);
    $row->config = ['api_token' => 'tamara-api-token', 'notification_token' => 'tamara-ol-notification-key-0123456789', 'webhook_secret' => 'whsec-tamara-ol-abcdefghijklmnopqrstuvwxyz01'];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

function olShare(Order $order, string $via = '', string $role = 'owner')
{
    return test()->actingAs(olAdmin($role), 'admin')->postJson('/admin-api/orders/' . $order->id . '/pay-link', ['via' => $via]);
}

/* =============================================================== the link == */

it('answers a tampered, other-order or expired link with the same 404, on the owner\'s own expiry', function () {
    // DEFECT: a link that could be edited into another order's (sequential
    // numbers), or that kept working past the term the owner set. MUTATION:
    // drop the `$ttl` argument in OrderPayLink::url() and the 3-day link lives
    // 7 days, so the last request is a 200.
    Carbon::setTestNow('2026-10-10 09:00:00');
    app(SettingsService::class)->set(OrderPayLink::SETTING_DAYS, '3');
    SettingsService::forgetMemo();
    $mine = olOrder();
    $theirs = olOrder(['email' => 'someone@example.com']);

    $url = olShare($mine)->assertOk()->json('url');
    $q = olQuery($url);

    $this->get('/checkout/order-pay?' . http_build_query($q))->assertOk()->assertSee($mine->order_number);
    $this->get('/checkout/order-pay?' . http_build_query(['order' => $theirs->order_number, 't' => $q['t']]))->assertNotFound()->assertDontSee('Villa 9');
    $this->get('/checkout/order-pay?' . http_build_query(['order' => $mine->order_number, 't' => explode('.', $q['t'])[0] . '.' . str_repeat('0', 64)]))->assertNotFound();
    $this->get('/checkout/order-pay?' . http_build_query(['order' => $mine->order_number, 't' => (explode('.', $q['t'])[0] + 86400 * 30) . '.' . explode('.', $q['t'])[1]]))->assertNotFound();

    Carbon::setTestNow('2026-10-14 00:00:01'); // three days after the next midnight, and a second
    $this->get('/checkout/order-pay?' . http_build_query($q))->assertNotFound();
});

it('shows the order exactly as placed, and never the street, phone or email', function () {
    // DEFECT: a pay page that re-priced the order, or printed the customer's
    // address to whoever the link was forwarded to.
    $order = olOrder(['discount_total' => 5000, 'coupon_code' => 'GLOW10', 'subtotal' => 57000, 'total' => 54000]);
    $q = olQuery(app(OrderPayLink::class)->url($order));

    $this->get('/checkout/order-pay?' . http_build_query($q))->assertOk()
        ->assertSee('Rice Toner')->assertSee('GLOW10')->assertSee('Standard delivery')->assertSee('Delivering to')->assertSee('Dubai')
        ->assertSee('value="cod"', false)
        ->assertDontSee('Villa 9')->assertDontSee('888 3841')->assertDontSee('alia@example.com');
});

it('says a paid order is already paid and starts no second payment', function () {
    // DEFECT: a customer opening the link twice, or after paying on the
    // checkout, being charged again. MUTATION: drop `&& $order->paid_at ===
    // null` from OrderPayController::payable() and the POST starts a payment.
    olStripe();
    Http::fake();
    $order = olOrder(['status' => 'pending', 'payment_method' => 'stripe']);
    $q = olQuery(app(OrderPayLink::class)->url($order));
    $order->forceFill(['status' => 'processing', 'paid_at' => now()])->save();

    $to = $this->get('/checkout/order-pay?' . http_build_query($q))->assertRedirect()->headers->get('Location');
    expect($to)->toContain('/track-my-order/?order=' . $order->order_number)->toContain('from=pay');
    $this->get(substr($to, strpos($to, '/track-my-order')))->assertOk()->assertSee('This order is already paid');

    $this->postJson('/checkout/order-pay', $q + ['method' => 'stripe'])->assertOk()->assertJsonPath('action', 'redirect');
    Http::assertNothingSent();
    expect($order->fresh()->transaction_id)->toBeNull();
});

it('refuses a cancelled order politely, and a hand-typed from=pay says nothing on an unpaid one', function () {
    $order = olOrder(['status' => 'pending']);
    $q = olQuery(app(OrderPayLink::class)->url($order));
    $order->forceFill(['status' => 'cancelled'])->save();

    $to = $this->get('/checkout/order-pay?' . http_build_query($q))->headers->get('Location');
    $this->get(substr($to, strpos($to, '/track-my-order')))->assertOk()->assertSee('can no longer be paid')->assertDontSee('already paid');

    $other = olOrder(['status' => 'onhold']);
    $track = OrderLinks::trackUrl($other) . '&from=pay';
    $this->get(substr($track, strpos($track, '/track-my-order')))->assertOk()->assertDontSee('already paid')->assertDontSee('can no longer be paid');
});

/* ======================================================== same order paid == */

it('completes the SAME failed order by cash on delivery, with stock and the coupon taken once', function () {
    // DEFECT: paying from the link creating a second order, or the revive and
    // the payment each taking the unit and the coupon use. MUTATION: replace
    // the OrderStatus revive in OrderPayController::start() with a plain
    // status write and the stock reads 5 (nothing re-taken) — an oversell.
    $product = olProduct(['stock' => 5]);
    $coupon = Coupon::create(['code' => 'OL' . strtoupper(Str::random(5)), 'type' => 'fixed_cart', 'amount' => 1000, 'usage_limit' => 5, 'usage_count' => 0]);
    $order = olPlace($product, $coupon);
    expect((int) $product->fresh()->stock)->toBe(4)->and((int) $coupon->fresh()->usage_count)->toBe(1);

    // The payment "never started": the order fails and gives both back.
    app(OrderStatus::class)->moveTo($order->fresh(), 'failed', by: 'system', reason: 'The payment could not be started.');
    expect((int) $product->fresh()->stock)->toBe(5)->and((int) $coupon->fresh()->usage_count)->toBe(0);

    $count = Order::count();
    $q = olQuery(olShare($order->fresh(), 'whatsapp')->assertOk()->json('url'));

    $this->postJson('/checkout/order-pay', $q + ['method' => 'cod'])->assertOk()->assertJsonPath('action', 'redirect');

    $fresh = $order->fresh();
    expect(Order::count())->toBe($count)
        ->and($fresh->order_number)->toBe($order->order_number)
        ->and($fresh->status)->toBe('processing')
        ->and($fresh->payment_method)->toBe('cod')
        ->and((int) $product->fresh()->stock)->toBe(4)
        ->and((int) $coupon->fresh()->usage_count)->toBe(1)
        ->and(app(\App\Services\StockClaim::class)->outstandingFor((int) $order->id))->toBe(1);

    // Pressing pay again (back button, second tab) takes nothing more.
    $this->postJson('/checkout/order-pay', $q + ['method' => 'cod'])->assertOk();
    expect((int) $product->fresh()->stock)->toBe(4)->and((int) $coupon->fresh()->usage_count)->toBe(1)->and(Order::count())->toBe($count);
});

it('starts the chosen gateway for the SAME order, with no new order row', function (string $gateway) {
    // DEFECT: a forked start path that wrote a new order (new number) for the
    // retry. Each gateway's real start() runs against a faked provider.
    match ($gateway) { 'stripe' => olStripe(), 'tabby' => olTabby(), 'tamara' => olTamara() };
    Http::fake([
        'api.stripe.com/*' => Http::response(['id' => 'pi_ol', 'client_secret' => 'pi_ol_secret', 'status' => 'requires_payment_method'], 200),
        'api.tabby.ai/*' => Http::response(['id' => 'sess_ol', 'status' => 'created', 'payment' => ['id' => 'pay_ol'],
            'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/ol']]]]], 200),
        '*tamara*/checkout' => Http::response(['order_id' => 'tam_ol', 'checkout_id' => 'chk_ol', 'checkout_url' => 'https://checkout.tamara.co/ol'], 200),
        '*' => Http::response([], 200),
    ]);

    $order = olOrder(['status' => 'failed', 'payment_method' => 'tamara']);
    $count = Order::count();
    $q = olQuery(app(OrderPayLink::class)->url($order));

    $r = $this->postJson('/checkout/order-pay', $q + ['method' => $gateway])->assertOk();

    expect(Order::count())->toBe($count)
        ->and($order->fresh()->status)->toBe('pending')
        ->and($order->fresh()->payment_method)->toBe($gateway)
        ->and($r->json('action'))->toBe($gateway === 'stripe' ? 'confirm' : 'redirect');
    if ($gateway === 'stripe') {
        expect($r->json('order'))->toBe($order->order_number);
    }
})->with(['stripe', 'tabby', 'tamara']);

it('blocks payment for a line that has sold out since the order failed, and says so', function () {
    // DEFECT: charging the customer for a jar the shop sold to somebody else
    // after this order gave it back. MUTATION: delete the problems() check in
    // OrderPayController::start() — the revive still refuses, but a product
    // binned since (no ledger row) is charged.
    $product = olProduct(['stock' => 1]);
    $order = olPlace($product);
    app(OrderStatus::class)->moveTo($order->fresh(), 'failed', by: 'system', reason: 'x');
    $product->forceFill(['stock' => 0, 'stock_status' => 'outofstock'])->save(); // sold to someone else

    $q = olQuery(app(OrderPayLink::class)->url($order->fresh()));
    $this->get('/checkout/order-pay?' . http_build_query($q))->assertOk()
        ->assertSee('Out of stock')->assertSee('cannot be paid here')->assertDontSee('id="kbbopGo"', false);
    $this->postJson('/checkout/order-pay', $q + ['method' => 'cod'])->assertStatus(422);
    expect($order->fresh()->status)->toBe('failed');

    $binned = olOrder(['status' => 'pending']);
    Product::whereKey($binned->items->first()->product_id)->update(['status' => 'draft']);
    $q2 = olQuery(app(OrderPayLink::class)->url($binned));
    $this->get('/checkout/order-pay?' . http_build_query($q2))->assertSee('No longer available');
    $this->postJson('/checkout/order-pay', $q2 + ['method' => 'cod'])->assertStatus(422);
    expect($binned->fresh()->status)->toBe('pending');
});

it('checks availability at a flat cost, however many lines the order has', function () {
    $count = function (int $lines): int {
        $order = olOrder(['status' => 'failed']);
        for ($i = 1; $i < $lines; $i++) {
            $p = olProduct();
            $order->items()->create(['product_id' => $p->id, 'name' => 'Line ' . $i, 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'total' => 100]);
        }
        $order = $order->fresh('items');
        DB::enableQueryLog();
        DB::flushQueryLog();
        OrderPayLink::problems($order);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    expect($count(3))->toBe($count(40));
});

/* ============================================================== the button == */

it('offers the button only on a failed order or a pending one with no payment', function (string $status, bool $paid, bool $offered) {
    // DEFECT: a "pay again" link on an order that is already paid, shipped or
    // cancelled. The server refuses it too, not only the screen.
    $order = olOrder(['status' => $status, 'paid_at' => $paid ? now() : null]);

    $this->actingAs(olAdmin(), 'admin')->getJson('/admin-api/orders/' . $order->id . '/detail')->assertOk()
        ->assertJsonPath('pay_link.offered', $offered)->assertJsonPath('pay_link.can', true);

    olShare($order)->assertStatus($offered ? 200 : 422);
})->with([
    'failed' => ['failed', false, true],
    'pending, unpaid' => ['pending', false, true],
    'pending, paid' => ['pending', true, false],
    'processing' => ['processing', true, false],
    'onhold' => ['onhold', false, false],
    'completed' => ['completed', true, false],
    'cancelled' => ['cancelled', false, false],
    'refunded' => ['refunded', true, false],
]);

it('builds a wa.me link with the number in international form and the message encoded', function () {
    // DEFECT: wa.me/0508883841 (local form) opens "number not on WhatsApp";
    // an unencoded & or # in the message cuts the link off mid-way.
    $order = olOrder(['order_number' => '56187']);
    $j = olShare($order)->assertOk()->json();

    expect($j['whatsapp_url'])->toStartWith('https://wa.me/971508883841?text=')
        ->and(rawurldecode(substr($j['whatsapp_url'], strpos($j['whatsapp_url'], '=') + 1)))
        ->toBe('Hi Alia, your ' . \App\Support\BrandName::appName() . ' order #56187 is saved. Complete it here: ' . $j['url'])
        ->and($j['whatsapp_url'])->not->toContain('#')->not->toContain(' ')->not->toContain('&t=');

    $sa = olOrder(['phone' => '', 'locale' => 'ar', 'shipping_address' => ['first_name' => 'Noura', 'city' => 'Riyadh', 'country' => 'SA', 'phone' => '05 1234 5678']]);
    $wa = olShare($sa)->json('whatsapp_url');
    expect($wa)->toStartWith('https://wa.me/966512345678?text=')
        ->and(rawurldecode(substr($wa, strpos($wa, '=') + 1)))->toContain('Hi Noura')->toContain('مرحباً Noura');

    expect(OrderPayLink::whatsappDigits(olOrder(['phone' => '+971 50 888 3841', 'shipping_address' => [], 'billing_address' => []])))->toBe('971508883841')
        ->and(OrderPayLink::whatsappDigits(olOrder(['phone' => '00971508883841', 'shipping_address' => [], 'billing_address' => []])))->toBe('971508883841')
        ->and(OrderPayLink::whatsappDigits(olOrder(['phone' => 'n/a', 'shipping_address' => [], 'billing_address' => []])))->toBeNull();
});

it('logs each send on the order and shows when and how it was last sent', function () {
    // DEFECT: two people sending the same customer three links because nobody
    // could see it had already gone.
    $order = olOrder();
    olShare($order)->assertOk()->assertJsonPath('last', null);
    expect($order->notes()->count())->toBe(0); // minting alone is not a send

    olShare($order, 'whatsapp')->assertOk()->assertJsonPath('last.via', 'whatsapp')->assertJsonPath('last.label', 'WhatsApp');
    $note = (string) $order->notes()->latest('id')->value('content');
    expect($note)->toStartWith('Payment link sent by WhatsApp to +97150***3841')->toContain('works until');

    $detail = $this->actingAs(olAdmin(), 'admin')->getJson('/admin-api/orders/' . $order->id . '/detail')->json();
    expect($detail['pay_link']['last']['via'])->toBe('whatsapp')
        ->and(collect($detail['payment_journey']['steps'] ?? [])->pluck('title'))->toContain('Order link sent to the customer');
});

it('emails the link as an order email, not marketing, and stops a stuck finger', function () {
    // DEFECT: the send going through the campaign sender (and so being
    // swallowed by marketing suppression), or one press sending ten emails.
    Mail::fake();
    RateLimiter::clear('kbb-paylink-mail:' . ($order = olOrder())->id);

    $r = olShare($order, 'email')->assertOk();
    Mail::assertSent(OrderPayLinkMail::class, fn ($m) => $m->hasTo('alia@example.com') && $m->payUrl === $r->json('url'));
    expect((string) $order->notes()->latest('id')->value('content'))->toStartWith('Payment link emailed to a***@example.com');

    $html = (string) (new OrderPayLinkMail($order, $r->json('url')))->render();
    expect($html)->toContain('/checkout/order-pay?')->toContain($order->order_number);

    olShare($order, 'email')->assertOk();
    olShare($order, 'email')->assertOk();
    olShare($order, 'email')->assertStatus(429);
    Mail::assertSent(OrderPayLinkMail::class, OrderPayLinkController::EMAILS_PER_HOUR);
});

/* =========================================================== capabilities == */

it('puts each endpoint behind its own capability and fails closed', function () {
    // DEFECT: a new admin endpoint reachable by a role nobody granted it to.
    expect(AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/pay-link'))->toBe('orders.paylink')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/order-pay-link-settings'))->toBe('orders.paylink.settings')
        ->and(AdminCapabilities::forPath('PUT', 'admin-api/order-pay-link-settings'))->toBe('orders.paylink.settings')
        ->and(AdminCapabilities::CAPABILITIES['orders.paylink'])->toBe(['owner', 'manager', 'support'])
        ->and(AdminCapabilities::CAPABILITIES['orders.paylink.settings'])->toBe(['owner', 'manager']);

    $order = olOrder();
    olShare($order, '', 'support')->assertOk();
    olShare($order, '', 'editor')->assertStatus(403);
    $this->actingAs(olAdmin('support'), 'admin')->getJson('/admin-api/order-pay-link-settings')->assertStatus(403);
    $this->actingAs(olAdmin('support'), 'admin')->putJson('/admin-api/order-pay-link-settings', ['days' => 30])->assertStatus(403);

    // Signed out: refused at the door.
    auth('admin')->logout();
    $this->app['auth']->forgetGuards();
    $this->postJson('/admin-api/orders/' . $order->id . '/pay-link')->assertStatus(401);

    // The controller checks too: called directly (as the owner app does) with no admin, it refuses.
    $res = app(OrderPayLinkController::class)->share(\Illuminate\Http\Request::create('/x', 'POST'), $order->id);
    expect($res->getStatusCode())->toBe(403);
});

it('saves the settings it was given and refuses the ones that would break a link', function () {
    $owner = olAdmin();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/order-pay-link-settings', ['days' => 31])->assertStatus(422);
    $this->actingAs($owner, 'admin')->putJson('/admin-api/order-pay-link-settings', ['days' => 5, 'whatsapp_en' => 'Hi {name}, no link here'])->assertStatus(422);
    $this->actingAs($owner, 'admin')->putJson('/admin-api/order-pay-link-settings', [
        'days' => 5, 'whatsapp_en' => "Salaam {name}! Order {order}: {link}\u{0007}", 'whatsapp_ar' => '', 'email_subject' => 'Finish order {order}',
    ])->assertOk()->assertJsonPath('settings.days', 5)->assertJsonPath('settings.whatsapp_en', 'Salaam {name}! Order {order}: {link}')
        ->assertJsonPath('settings.whatsapp_ar', OrderPayLink::DEFAULT_WA_AR);

    SettingsService::forgetMemo();
    Mail::fake();
    $order = olOrder();
    $j = olShare($order, 'email')->json();
    expect(rawurldecode($j['whatsapp_url']))->toContain('Salaam Alia! Order ' . $order->order_number . ': ');
    Mail::assertSent(OrderPayLinkMail::class, fn ($m) => $m->envelope()->subject === 'Finish order ' . $order->order_number);
});

/* ============================================================== owner app == */

it('serves the owner app the button, and only allowlisted fields', function () {
    // DEFECT: the app endpoint passing the admin payload straight through.
    OA::wire($this->app);
    RateLimiter::clear('owner-app-pin:127.0.0.1');
    RateLimiter::clear('owner-app-enrol:127.0.0.1');
    $owner = OA::admin();
    OA::member($owner);
    [$c, $csrf] = OA::enrol($this);
    $order = olOrder();

    OA::get($this, 'orders/' . $order->id, $c)->assertOk()
        ->assertJsonPath('order.pay_link.can', true)->assertJsonPath('order.pay_link.offered', true);
    expect(array_keys(OA::get($this, 'orders/' . $order->id, $c)->json('order.pay_link')))->toBe(['offered', 'has_phone', 'has_email', 'last', 'can']);

    $r = OA::post($this, 'orders/' . $order->id . '/pay-link', ['via' => 'whatsapp'], $c, $csrf)->assertOk();
    expect(array_keys($r->json()))->toBe(['ok', 'url', 'expires_label', 'whatsapp_url', 'has_phone', 'email', 'last', 'problems', 'message'])
        ->and(array_keys($r->json('last')))->toBe(['via', 'label', 'at_label', 'by'])
        ->and($r->json('url'))->toContain('/checkout/order-pay?order=' . $order->order_number);

    $paid = olOrder(['status' => 'processing', 'paid_at' => now()]);
    OA::get($this, 'orders/' . $paid->id, $c)->assertJsonPath('order.pay_link.offered', false);
    OA::post($this, 'orders/' . $paid->id . '/pay-link', [], $c, $csrf)->assertStatus(422);

    $ed = OA::admin('editor', 'ed@example.com', 'Ed Editor');
    OA::member($ed);
    [$e, $ecsrf] = OA::enrol($this, 'ed@example.com');
    OA::post($this, 'orders/' . $order->id . '/pay-link', [], $e, $ecsrf)->assertStatus(403);
});
