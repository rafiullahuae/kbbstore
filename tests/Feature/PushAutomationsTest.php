<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\OwnerApp\VapidKeys;
use App\Services\Push\PushAutomations;
use App\Services\Push\PushRules;
use App\Services\Push\PushSender;
use App\Services\SiteAppPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PushAdminRoutes;

/*
 * Lane PN: the four automations on Growth & Marketing → Push Notifications →
 * Automations. The owner: "for the back in stock, they will receive
 * notification only once if in last sessions customer visit that out of stock
 * product! i want to minimal the notifications. orders updates will be auto."
 *
 * pnPhone()/pnRules()/pnFakePush(): tests/Support/PushTestHelpers.php.
 */

require_once __DIR__.'/../Support/PushTestHelpers.php';

function pnProduct(string $slug, array $attrs = []): Product
{
    return Product::create($attrs + ['slug' => $slug.'-'.uniqid(), 'name' => 'Snail Essence', 'status' => 'publish', 'is_visible' => true,
        'price' => 10000, 'stock_status' => 'outofstock']);
}

function pnOrder(?Customer $c, string $status = 'processing'): Order
{
    static $n = 0;
    $n++;

    return Order::create([
        'order_number' => 'PN'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'customer_id' => $c?->id,
        'email' => $c?->email ?? 'guest-pn@example.test', 'status' => $status, 'currency' => 'AED',
        'subtotal' => 100, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 100,
        'shipping_method' => 'Standard delivery', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        'shipping_address' => ['first_name' => 'A', 'last_name' => 'B', 'line1' => '1 Road', 'city' => 'Sharjah', 'state' => 'Sharjah', 'country' => 'AE', 'phone' => '+971500000000'],
    ]);
}

beforeEach(function () {
    PushAdminRoutes::wire($this->app);
    VapidKeys::forget();
    VapidKeys::pair();
    pnRules(['quiet_on' => false, 'cap_day' => 0, 'cap_week' => 0]);
});

it('tells only the phones that visited the sold-out product, and only once ever', function () {
    /* DEFECT the owner ruled out: back-in-stock to everybody, or every time it
       comes back. MUTATION: drop the interests join in PushAutomations::stock()
       -> the uninterested phone gets it; make the dedupe 's:{product}:{sub}:{date}'
       -> the second restock sends again. */
    pnFakePush();
    $p = pnProduct('pn-bis');
    $other = pnProduct('pn-other');
    $visited = pnPhone();
    $notVisited = pnPhone();
    $otherProduct = pnPhone();
    foreach ([[$visited, $p->id], [$otherProduct, $other->id]] as [$sid, $pid]) {
        DB::table('site_app_push_interests')->insert(['subscription_id' => $sid, 'product_id' => $pid, 'viewed_at' => now()]);
    }
    $auto = app(PushAutomations::class);

    expect($auto->stock())->toBe(0);              // still sold out: nothing
    $p->update(['stock_status' => 'instock']);
    expect($auto->stock())->toBe(1);
    app(PushSender::class)->runQueue();
    Http::assertSentCount(1);
    expect(DB::table('push_sends')->where('kind', 'stock')->pluck('subscription_id')->all())->toEqual([$visited])
        ->and(DB::table('site_app_push_interests')->where('subscription_id', $visited)->value('notified_at'))->not->toBeNull();

    // Sold out, visited again, back again: still the one message, ever.
    $p->update(['stock_status' => 'outofstock']);
    DB::table('site_app_push_interests')->where('subscription_id', $visited)->update(['viewed_at' => now()]);
    $p->update(['stock_status' => 'instock']);
    DB::table('site_app_push_interests')->where('subscription_id', $visited)->update(['notified_at' => null]);  // even if the flag were lost
    $auto->stock();
    app(PushSender::class)->runQueue();
    Http::assertSentCount(1);
    expect(DB::table('push_sends')->where('kind', 'stock')->count())->toBe(1)
        ->and($notVisited)->toBeInt();
});

it('pushes an order update automatically to the shopper\'s phones and the phone that placed it, and to nobody else', function () {
    /* MUTATION: drop the site_app_push_orders branch in orderStatus() -> the
       guest who ordered from the app hears nothing; drop the customer_id
       branch -> the signed-in shopper's tablet hears nothing. */
    pnFakePush();
    $c = Customer::create(['name' => 'Shopper', 'email' => 'pn-shopper-'.uniqid().'@example.test', 'password' => 'password123']);
    $phone = pnPhone(['customer_id' => $c->id, 'locale' => 'en']);
    $tablet = pnPhone(['customer_id' => $c->id, 'locale' => 'ar']);
    $stranger = pnPhone();
    $order = pnOrder($c);

    $guestPhone = pnPhone();
    $guestOrder = pnOrder(null);
    DB::table('site_app_push_orders')->insert(['order_id' => $guestOrder->id, 'subscription_id' => $guestPhone, 'created_at' => now()]);

    $order->update(['status' => 'shipped']);       // through the real observer and OrderMailer gate
    $guestOrder->update(['status' => 'shipped']);

    $sent = DB::table('push_sends')->where('kind', 'order')->orderBy('id')->get();
    expect($sent->pluck('subscription_id')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([$phone, $tablet, $guestPhone])
        ->and($sent->pluck('status')->unique()->all())->toBe(['delivered'])
        ->and($sent->firstWhere('subscription_id', $phone)->title)->toBe('Order '.$order->order_number.' is on its way')
        ->and($sent->firstWhere('subscription_id', $phone)->url)->toBe('/my-account/orders/'.$order->id)
        ->and($sent->firstWhere('subscription_id', $guestPhone)->url)->toBe('/track-my-order');
    expect(DB::table('push_sends')->where('subscription_id', $stranger)->count())->toBe(0);
    Http::assertSentCount(3);

    // The same status again sends nothing new; switched off sends nothing at all.
    app(PushAutomations::class)->orderStatus($order->fresh(), 'shipped');
    pnRules(['order_on' => false]);
    $order->update(['status' => 'completed']);
    Http::assertSentCount(3);
});

it('stays silent when the operator un-ticks "notify the customer", exactly as the email does', function () {
    pnFakePush();
    $c = Customer::create(['name' => 'Shopper', 'email' => 'pn-q-'.uniqid().'@example.test', 'password' => 'password123']);
    pnPhone(['customer_id' => $c->id]);
    $order = pnOrder($c);
    app(\App\Services\Mail\OrderStatusMailPolicy::class)->decideForRequest(false);
    $order->update(['status' => 'shipped']);
    app(\App\Services\Mail\OrderStatusMailPolicy::class)->decideForRequest(null);
    expect(DB::table('push_sends')->count())->toBe(0);
});

it('links the phone that places an order, and the cart it starts, by its cookie alone', function () {
    $sid = pnPhone(['cookie_hash' => hash('sha256', str_repeat('a', 32))]);
    $this->withCredentials()->withCookie(SiteAppPush::COOKIE, str_repeat('a', 32));
    request()->cookies->set(SiteAppPush::COOKIE, str_repeat('a', 32));

    $cart = \App\Models\Cart::create(['token' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'active', 'last_activity_at' => now()]);
    expect((int) DB::table('site_app_push_subscriptions')->where('id', $sid)->value('cart_id'))->toBe($cart->id);

    PushAutomations::linkOrder(77, hash('sha256', str_repeat('a', 32)));
    expect(DB::table('site_app_push_orders')->where('order_id', 77)->value('subscription_id'))->toEqual($sid);
});

it('reminds a basket once, after the delay, and never after an order', function () {
    /* MUTATION: drop the push_sends whereNotExists in cart() -> a second
       reminder for the same basket; drop the orders whereNotExists -> a
       reminder after the shopper bought. */
    pnFakePush();
    $p = pnProduct('pn-cart', ['stock_status' => 'instock']);
    $mkCart = function (?int $customer, int $hoursAgo) use ($p) {
        $id = (int) DB::table('carts')->insertGetId(['token' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $customer, 'status' => 'active',
            'last_activity_at' => now()->subHours($hoursAgo), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cart_items')->insert(['cart_id' => $id, 'product_id' => $p->id, 'quantity' => 1, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $due = $mkCart(null, 4);
    $tooSoon = $mkCart(null, 1);
    $c = Customer::create(['name' => 'B', 'email' => 'pn-b-'.uniqid().'@example.test', 'password' => 'password123']);
    $bought = $mkCart($c->id, 5);
    pnOrder($c);   // ordered after the basket was last touched

    $a = pnPhone(['cart_id' => $due]);
    pnPhone(['cart_id' => $tooSoon]);
    pnPhone(['customer_id' => $c->id]);

    $auto = app(PushAutomations::class);
    expect($auto->cart())->toBe(1)->and($auto->cart())->toBe(0);
    app(PushSender::class)->runQueue();
    expect(DB::table('push_sends')->where('kind', 'cart')->get(['subscription_id', 'ref', 'status'])->map(fn ($r) => [(int) $r->subscription_id, (int) $r->ref, $r->status])->all())
        ->toBe([[$a, $due, 'delivered']]);
    Http::assertSentCount(1);
    expect($bought)->toBeInt();
});

it('tells a watcher of a price drop above the threshold, once per product in 30 days', function () {
    /* MUTATION: compare against 100 - 1 instead of the threshold -> the 5 %
       drop sends; drop the 30-day whereNotExists -> the second drop sends again. */
    pnFakePush();
    $p = pnProduct('pn-price', ['stock_status' => 'instock', 'price' => 10000]);
    $viewer = pnPhone();
    $hearted = pnPhone();
    pnPhone();   // watches nothing
    DB::table('site_app_push_interests')->insert(['subscription_id' => $viewer, 'product_id' => $p->id, 'viewed_at' => now()]);
    DB::table('site_app_push_wishes')->insert(['subscription_id' => $hearted, 'product_id' => $p->id, 'wished_at' => now()]);
    $auto = app(PushAutomations::class);

    expect($auto->price(true))->toBe(0);                     // first sight: the baseline
    $p->update(['price' => 9500]);                             // 5 %: below the 10 % threshold
    expect($auto->price(true))->toBe(0);
    $p->update(['price' => 8500]);                             // 15 % off the 100.00 seen
    expect($auto->price(true))->toBe(2);
    app(PushSender::class)->runQueue();
    $rows = DB::table('push_sends')->where('kind', 'price')->get();
    expect($rows->pluck('subscription_id')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([$viewer, $hearted])
        ->and($rows->first()->body)->toContain('85');

    $p->update(['price' => 6000]);
    expect($auto->price(true))->toBe(0);                       // inside 30 days: no second message
    Http::assertSentCount(2);
});

it('records a heart pressed on a subscribed phone as a price watch, and forgets it when released', function () {
    $p = pnProduct('pn-wish', ['stock_status' => 'instock']);
    $sid = pnPhone(['cookie_hash' => hash('sha256', str_repeat('b', 32))]);
    $this->withCredentials()->withCookie(SiteAppPush::COOKIE, str_repeat('b', 32))->postJson('/wishlist/toggle', ['product_id' => $p->id])->assertOk();
    expect(DB::table('site_app_push_wishes')->where('subscription_id', $sid)->pluck('product_id')->all())->toEqual([$p->id]);
    // Without the cookie: nothing recorded for anybody.
    $this->postJson('/wishlist/toggle', ['product_id' => $p->id])->assertOk();
    expect(DB::table('site_app_push_wishes')->count())->toBe(1);
});

it('writes each automation\'s wording in English and Arabic through the shop\'s translations', function () {
    $owner = pnAdmin();
    $t = $this->actingAs($owner, 'admin')->getJson('/admin-api/push')->assertOk()->json('templates');
    expect($t['order_shipped_title']['en'])->toBe('Order :order is on its way')
        ->and(array_keys($t))->toHaveCount(20);

    $this->actingAs($owner, 'admin')->postJson('/admin-api/push/templates', ['templates' => [
        'stock_title' => ['en' => 'It is back!', 'ar' => 'عاد!'],
        'evil' => ['en' => 'x'],
    ]])->assertOk()->assertJsonPath('templates.stock_title.ar_status', 'published');
    expect(__('store.push.stock_title', [], 'en'))->toBe('It is back!')
        ->and(__('store.push.stock_title', [], 'ar'))->toBe('عاد!');
});
