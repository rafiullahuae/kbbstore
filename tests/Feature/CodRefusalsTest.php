<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\CodRefusals;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * CASH ON DELIVERY HIDDEN FROM SHOPPERS WHO REFUSED A DISPATCHED ORDER.
 *                                                                (Lane CODR)
 *
 * The owner, 1 November 2026: "hide the COD payment option for all the
 * cancelled orders, because they didn't accept their order after dispatch ...
 * ignore if system found any 1 order completed ... the real customer must not
 * be affected by this at all."
 *
 * What each case would have caught, on the shop, is written beside it, with a
 * MUTATION note: the change that turns it red.
 */
function codrForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    app()->forgetInstance(CodRefusals::class);
}

function codrZones(): void
{
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
}

function codrOrder(string $status, string $email = 'refuser@example.com', ?string $phone = '+971 50 123 4567'): Order
{
    return Order::create([
        'order_number' => 'CR' . Str::upper(Str::random(8)),
        'email' => $email,
        'phone' => $phone,
        'status' => $status,
        'total' => 12000,
        'payment_method' => 'cod',
    ]);
}

/** An order taken through real transitions, ending shipped -> cancelled. */
function codrRefused(string $email = 'refuser@example.com', ?string $phone = '+971 50 123 4567'): Order
{
    $o = codrOrder('processing', $email, $phone);
    app(OrderStatus::class)->moveTo($o, 'shipped', 'test');
    app(OrderStatus::class)->moveTo($o, 'cancelled', 'test');

    return $o->fresh();
}

function codrSwitch(bool $on): void
{
    app(SettingsService::class)->set(CodRefusals::SETTING, $on ? '1' : '0');
    codrForget();
}

function codrCart(?int $customerId = null): Cart
{
    $p = Product::create(['slug' => 'codr-' . Str::random(8), 'name' => 'Snail Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 12000, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(), 'customer_id' => $customerId]);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 12000]);

    return $cart;
}

function codrShopper(Cart $cart)
{
    app(CartService::class)->forget();
    codrForget();

    return test()->withCredentials()
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function codrPlace(Cart $cart, string $email, string $phone, string $method = 'cod')
{
    return codrShopper($cart)->post('/checkout/place', [
        'billing_email' => $email, 'billing_phone' => $phone,
        'billing_first_name' => 'Noura', 'billing_last_name' => 'A', 'billing_address_1' => '44 Road',
        'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => $method,
    ]);
}

function codrOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'CODR ' . $role, 'email' => 'codr-' . $role . '-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'), 'role' => $role]);
}

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
    codrZones();
    codrForget();
});

// ------------------------------------------------------------------ who

it('records a refusal when a shipped order is cancelled', function () {
    // On the shop: a customer who turned the courier away is offered COD again.
    // MUTATION: drop the transitioned() call from OrderStatus::moveTo() -> red.
    $o = codrRefused();

    $row = DB::table('cod_refusals')->where('order_id', $o->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->email)->toBe('refuser@example.com')
        ->and($row->phone)->toBe('971501234567')
        ->and($row->order_number)->toBe($o->order_number)
        ->and(app(CodRefusals::class)->blocks('refuser@example.com', null))->toBeTrue();
});

it('does not count an order cancelled before it was shipped', function (string $before) {
    // On the shop: a shopper who changed their mind before dispatch losing COD.
    // MUTATION: record on any `-> cancelled` in transitioned() -> red.
    $o = codrOrder($before);
    app(OrderStatus::class)->moveTo($o, 'cancelled', 'test');

    expect(DB::table('cod_refusals')->count())->toBe(0)
        ->and(app(CodRefusals::class)->blocks('refuser@example.com', '0501234567'))->toBeFalse();
})->with(['processing', 'onhold', 'pending']);

it('never blocks a customer who has a completed order, before or after', function () {
    // On the shop: a loyal customer with one refused parcel losing COD.
    // MUTATION: drop hasCompleted() from transitioned() -> the first half is
    // red; drop the $lift branch -> the second half is red.
    codrOrder('completed', 'REFUSER@example.com', '050 123 4567');
    codrRefused();
    expect(DB::table('cod_refusals')->count())->toBe(0);

    // After: refused first, completed later (matched on the phone alone).
    $r = codrRefused('other@example.com', '+971 55 000 1111');
    expect(app(CodRefusals::class)->blocks('other@example.com', null))->toBeTrue();

    $later = codrOrder('processing', 'different@example.com', '0550001111');
    app(OrderStatus::class)->moveTo($later, 'completed', 'test');

    $row = DB::table('cod_refusals')->where('order_id', $r->id)->first();
    expect($row->lifted_at)->not->toBeNull()
        ->and($row->lifted_by)->toBe('completed order #' . $later->order_number)
        ->and(app(CodRefusals::class)->blocks('other@example.com', null))->toBeFalse();
});

it('removes the row when a cancelled order is revived', function () {
    // On the shop: an order cancelled by mistake and put back to Shipped still
    // hiding COD from its customer.
    // MUTATION: drop the $revive delete in transitioned() -> red.
    $o = codrRefused();
    expect(DB::table('cod_refusals')->count())->toBe(1);

    app(OrderStatus::class)->moveTo($o, 'shipped', 'test');
    expect(DB::table('cod_refusals')->count())->toBe(0);
});

it('backfills past refusers from both note wordings, and only them', function () {
    // On the shop: every refuser from before this release still offered COD,
    // including the WooCommerce years; or a pre-dispatch cancel blocked.
    // MUTATION: drop LOWER() from the backfill's LIKE on MySQL's binary
    // collation, or the WooCommerce "Shipped to Cancelled" wording -> red.
    $woo = codrOrder('cancelled', 'Woo@Example.com', '00971501112222');
    OrderNote::create(['order_id' => $woo->id, 'author' => 'WooCommerce', 'is_customer_note' => false,
        'content' => 'Order status changed from Shipped to Cancelled.']);
    $ours = codrOrder('cancelled', 'ours@example.com', '0503334444');
    OrderNote::create(['order_id' => $ours->id, 'author' => 'Admin', 'is_customer_note' => false,
        'content' => 'Status changed from shipped to cancelled.']);
    $early = codrOrder('cancelled', 'early@example.com', '0505556666');
    OrderNote::create(['order_id' => $early->id, 'author' => 'Admin', 'is_customer_note' => false,
        'content' => 'Status changed from processing to cancelled.']);
    $loyal = codrOrder('cancelled', 'loyal@example.com', '0507778888');
    OrderNote::create(['order_id' => $loyal->id, 'author' => 'Admin', 'is_customer_note' => false,
        'content' => 'Status changed from shipped to cancelled.']);
    codrOrder('completed', 'LOYAL@example.com', null);
    // Shipped -> cancelled once, but revived since: current status decides.
    $revived = codrOrder('shipped', 'revived@example.com', '0509990000');
    OrderNote::create(['order_id' => $revived->id, 'author' => 'Admin', 'is_customer_note' => false,
        'content' => 'Status changed from shipped to cancelled.']);

    $migration = require database_path('migrations/2028_01_03_100000_create_cod_refusals.php');
    $migration->up();
    $migration->up(); // idempotent

    expect(DB::table('cod_refusals')->orderBy('order_id')->pluck('order_id')->all())->toBe([$woo->id, $ours->id])
        ->and(DB::table('cod_refusals')->where('order_id', $woo->id)->value('email'))->toBe('woo@example.com')
        ->and(DB::table('cod_refusals')->where('order_id', $woo->id)->value('phone'))->toBe('971501112222');
});

// ------------------------------------------------------------------ matching

it('matches the email in any letter case and the phone in any UAE format', function (string $typed) {
    // On the shop: a refuser getting COD back by typing 050… instead of +971….
    // MUTATION: drop the 10-digit-0 rule from CodRefusals::phone() -> the
    // '050 123 4567' case is red.
    codrRefused('Refuser@Example.com', '+971501234567');
    $c = app(CodRefusals::class);

    expect($c->blocks('  REFUSER@example.COM ', null))->toBeTrue()
        ->and($c->blocks(null, $typed))->toBeTrue();
})->with(['050 123 4567', '+971 50 123 4567', '00971501234567', '501234567', '(050) 123-4567']);

it('never matches a short phone', function () {
    // On the shop: everyone typing a junk "12345" sharing one block.
    // MUTATION: drop the 9-digit floor in CodRefusals::phone() -> red.
    codrRefused('a@example.com', '12345');

    expect(DB::table('cod_refusals')->value('phone'))->toBeNull()
        ->and(CodRefusals::phone('12345'))->toBeNull()
        ->and(app(CodRefusals::class)->blocks(null, '12345'))->toBeFalse()
        ->and(app(CodRefusals::class)->blocks(null, null))->toBeFalse();
});

// ------------------------------------------------------------------ checkout

it('refuses a forced COD on Place order SILENTLY, and still places the same customer’s Tabby order', function () {
    // On the shop: a refuser posting payment_method=cod anyway and getting it;
    // or the block wrongly taking every other method from them; or (2.60.636,
    // "i want this feature silently") a sentence telling her why.
    // MUTATION: drop refusesAtPlace() from CheckoutController::place() -> the
    // first half is red; make it run for every method -> the second half is red.
    codrRefused('refuser@example.com', '0501234567');
    $tabby = PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    $tabby->config = ['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'merchant_code' => 'AE'];
    $tabby->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    \Illuminate\Support\Facades\Http::fake(['api.tabby.ai/api/v2/checkout' => \Illuminate\Support\Facades\Http::response([
        'id' => 'sess_codr', 'status' => 'created', 'payment' => ['id' => 'pay_codr'],
        'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/codr']]]],
    ], 200), '*' => \Illuminate\Support\Facades\Http::response([], 200)]);
    $before = Order::count();

    codrPlace(codrCart(), 'REFUSER@example.com', '+971 50 123 4567')->assertRedirect();
    expect(session('errors'))->toBeNull()
        ->and(Order::count())->toBe($before)
        ->and(session(CodRefusals::SESSION))->toBeTrue();

    codrPlace(codrCart(), 'refuser@example.com', '0501234567', 'tabby');
    expect(Order::where('email', 'refuser@example.com')->where('payment_method', 'tabby')->exists())->toBeTrue();
});

it('hides COD from the rest of a guest’s session once refused', function () {
    // On the shop: the guest refused at Place order still shown COD on reload.
    // MUTATION: drop the session check from hiddenForShopper() -> red.
    codrRefused('refuser@example.com', '0501234567');
    $cart = codrCart();

    $first = (string) codrShopper($cart)->get('/checkout')->assertOk()->getContent();
    expect($first)->toContain('value="cod"');

    codrPlace($cart, 'refuser@example.com', '0501234567')->assertRedirect();

    $after = (string) codrShopper($cart)->get('/checkout')->assertOk()->getContent();
    expect($after)->not->toContain('value="cod"');
});

it('never shows COD to a signed-in refuser', function () {
    // On the shop: a signed-in refuser offered COD on the page.
    // MUTATION: drop the filterCheckoutList() call from gateways() -> red.
    $customer = Customer::create(['name' => 'Refuser', 'email' => 'refuser@example.com',
        'password' => Hash::make('glow-secret-1'), 'phone' => '0501234567']);
    codrRefused('someone-else@example.com', '+971501234567'); // matched on the saved phone

    $html = (string) codrShopper(codrCart($customer->id))->actingAs($customer, 'customer')->get('/checkout')->assertOk()->getContent();
    expect($html)->not->toContain('value="cod"');
});

it('leaves an unrelated customer’s checkout byte-for-byte as it was, and places their COD order', function () {
    // On the shop: a real customer losing COD, or the page changing at all
    // for someone who never refused anything -- the owner's one hard rule.
    // MUTATION: make filterCheckoutList() drop cod unconditionally -> red.
    $customer = Customer::create(['name' => 'Aisha', 'email' => 'aisha@example.com',
        'password' => Hash::make('glow-secret-1'), 'phone' => '0559998877']);
    $cart = codrCart($customer->id);

    codrSwitch(false);
    $off = (string) codrShopper($cart)->actingAs($customer, 'customer')->get('/checkout')->assertOk()->getContent();

    codrSwitch(true);
    codrRefused('refuser@example.com', '0501234567');
    $on = (string) codrShopper($cart)->actingAs($customer, 'customer')->get('/checkout')->assertOk()->getContent();

    expect($on)->toBe($off)->and($on)->toContain('value="cod"');

    $before = Order::count();
    codrPlace(codrCart(), 'aisha@example.com', '0559998877')->assertRedirect();
    expect(Order::count())->toBe($before + 1)
        ->and(Order::latest('id')->first()->payment_method)->toBe('cod');
});

it('changes nothing when switched off', function () {
    // On the shop: the owner turns it off and refusers are still refused.
    // MUTATION: drop the enabled() check from blocksAny() -> red.
    codrRefused('refuser@example.com', '0501234567');
    codrSwitch(false);

    expect(app(CodRefusals::class)->blocks('refuser@example.com', '0501234567'))->toBeFalse();

    $before = Order::count();
    codrPlace(codrCart(), 'refuser@example.com', '0501234567')->assertRedirect();
    expect(Order::count())->toBe($before + 1)
        ->and(Order::latest('id')->first()->payment_method)->toBe('cod');
});

it('refuses COD on the public order endpoint for a refuser too', function () {
    // On the shop: the API used as a way round the Place-order check.
    // MUTATION: drop the CodRefusals check from Api\CheckoutController -> red.
    codrRefused('refuser@example.com', '0501234567');
    $p = Product::create(['slug' => 'codr-api-' . Str::random(6), 'name' => 'Toner', 'status' => 'publish', 'is_visible' => true, 'price' => 9000, 'stock_status' => 'instock']);
    $post = fn (string $email) => test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $p->slug, 'qty' => 1]],
        'customer' => ['name' => 'Noura A', 'email' => $email, 'phone' => '+971 50 123 4567', 'address' => '44 Road', 'city' => 'Dubai', 'country' => 'AE'],
        'method' => 'cod',
    ]);

    // 2.60.636: the same plain sentence as COD switched off -- nothing says why.
    $post('refuser@example.com')->assertStatus(422)
        ->assertJsonPath('error', __('store.wording.msg_cod_na'));
});

it('answers the checkout contact endpoint identically for a blocked and an unblocked email', function () {
    // On the shop: the email box used as an oracle for "is this person blocked".
    // MUTATION: make CheckoutEmailController answer differently -> red.
    codrRefused('refuser@example.com', '0501234567');
    $cart = codrCart();

    $a = codrShopper($cart)->post('/api/checkout-contact', ['email' => 'refuser@example.com']);
    $b = codrShopper($cart)->post('/api/checkout-contact', ['email' => 'nobody@example.com']);

    expect($a->status())->toBe($b->status())
        ->and($a->getContent())->toBe($b->getContent());
});

// ------------------------------------------------------------------ admin

it('keeps the admin endpoints to their own capability, failing closed', function () {
    // On the shop's back office: a support account reading customers' phones,
    // or a guest switching the rule off.
    // MUTATION: remove the cod-refusals RULES -> still owner-only (null fails
    // closed); widen codrefusals.* to support -> red.
    $r = codrRefused();
    $id = DB::table('cod_refusals')->value('id');

    test()->getJson('/admin-api/cod-refusals')->assertStatus(401);
    foreach (['support', 'editor', 'manager'] as $role) {
        $who = codrOwner($role);
        expect(test()->actingAs($who, 'admin')->getJson('/admin-api/cod-refusals')->status())->toBe(403)
            ->and(test()->actingAs($who, 'admin')->postJson('/admin-api/cod-refusals/settings', ['enabled' => false])->status())->toBe(403)
            ->and(test()->actingAs($who, 'admin')->postJson('/admin-api/cod-refusals/' . $id . '/allow')->status())->toBe(403);
    }

    expect(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/cod-refusals'))->toBe('codrefusals.view')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/cod-refusals/1/allow'))->toBe('codrefusals.manage')
        ->and(app(CodRefusals::class)->enabled())->toBeTrue()
        ->and(DB::table('cod_refusals')->whereNull('lifted_at')->count())->toBe(1);
});

it('lists, searches, switches and allows COD again from Store → Payments', function () {
    // On the back office: "Allow COD again" not lifting the block, or the
    // switch not reaching the checkout.
    // MUTATION: drop whereNull('lifted_at') from CodRefusals::blocksAny() ->
    // the allowed customer is still refused and the last expectation is red.
    $owner = codrOwner();
    $o = codrRefused('refuser@example.com', '0501234567');
    codrRefused('second@example.com', '0507654321');

    $list = test()->actingAs($owner, 'admin')->getJson('/admin-api/cod-refusals')->assertOk()->json();
    expect($list['enabled'])->toBeTrue()->and($list['total'])->toBe(2)
        ->and(array_keys($list['rows'][0]))->toBe(['id', 'email', 'phone', 'order_id', 'order_number', 'refused_at', 'source']);

    $found = test()->actingAs($owner, 'admin')->getJson('/admin-api/cod-refusals?q=050+123+4567')->assertOk()->json();
    expect($found['total'])->toBe(1)->and($found['rows'][0]['order_id'])->toBe($o->id);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/cod-refusals/settings', ['enabled' => false])->assertOk();
    codrForget();
    expect(app(CodRefusals::class)->enabled())->toBeFalse();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/cod-refusals/settings', ['enabled' => true])->assertOk();
    codrForget();

    $id = $found['rows'][0]['id'];
    test()->actingAs($owner, 'admin')->postJson('/admin-api/cod-refusals/' . $id . '/allow')->assertOk();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/cod-refusals/' . $id . '/allow')->assertNotFound();

    expect(DB::table('cod_refusals')->where('id', $id)->value('lifted_by'))->toBe('CODR owner')
        ->and(app(CodRefusals::class)->blocks('refuser@example.com', '0501234567'))->toBeFalse()
        ->and(app(CodRefusals::class)->blocks('second@example.com', null))->toBeTrue();
});

it('costs the list the same queries for 3 rows as for 40', function () {
    // On the back office: the list slowing down as refusals pile up (N+1).
    // MUTATION: look each row's order up inside the map() -> red.
    $owner = codrOwner();
    $count = function () use ($owner): int {
        test()->actingAs($owner, 'admin')->getJson('/admin-api/cod-refusals')->assertOk(); // warm
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        test()->actingAs($owner, 'admin')->getJson('/admin-api/cod-refusals')->assertOk();

        return $n;
    };

    $seed = function (int $n): void {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['email' => "r{$i}-" . Str::random(4) . '@example.com', 'phone' => '9715' . str_pad((string) random_int(0, 99999999), 8, '0'),
                'order_id' => random_int(1, PHP_INT_MAX >> 8), 'order_number' => 'N' . $i . Str::random(4), 'refused_at' => now(),
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('cod_refusals')->insert($rows);
    };

    $seed(3);
    $three = $count();
    $seed(37);
    $forty = $count();

    expect($forty)->toBe($three);
});

it('draws the Refused deliveries section on the Cash on delivery tab exactly once', function () {
    $html = file_get_contents(resource_path('views/admin/app.blade.php'));
    $web = file_get_contents(base_path('routes/web.php'));

    expect(substr_count($html, "@include('admin.partials.cod-refusals-panel')"))->toBe(1)
        ->and(substr_count($html, 'if(window.kbbCodRefusals) window.kbbCodRefusals({'))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/cod-refusals-admin.php';"))->toBe(1);
});

it('ships its migrations as literals, spelled exactly as the live code spells them', function () {
    // On the shop: a backfilled refuser stored as 0501234567 while checkout
    // looks for 971501234567, so every past refuser keeps COD; or the updater
    // 500ing because a migration called a class still old in opcache (2.60.512).
    // MUTATION: change the 10-digit rule in either copy of phone() -> red.
    $file = database_path('migrations/2028_01_03_100000_create_cod_refusals.php');
    $m = require $file;
    $code = '';
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        $code .= (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) ? '' : (is_array($t) ? $t[1] : $t);
    }
    expect(str_contains($code, 'App\\'))->toBeFalse();

    foreach (['050 123 4567', '+971 50 123 4567', '00971501234567', '501234567', '12345', '', null, '+44 20 7946 0958', '0412345678'] as $p) {
        expect($m::phone($p))->toBe(CodRefusals::phone($p));
    }
    foreach (['  A@B.com ', 'no-at', '', null, 'X@Example.COM'] as $e) {
        expect($m::email($e))->toBe(CodRefusals::email($e));
    }

    $ar = require database_path('migrations/2028_01_03_100050_arabic_cod_unavailable.php');
    expect((new ReflectionClassConstant($ar, 'ENGLISH'))->getValue())->toBe(\App\Support\ArabicWording::ENGLISH['wording.msg_cod_unavailable'])
        ->and((new ReflectionClassConstant($ar, 'ARABIC'))->getValue())->toBe(\App\Support\ArabicWording::ARABIC['wording.msg_cod_unavailable'])
        ->and(__('store.wording.msg_cod_unavailable'))->toBe("Cash on delivery isn't available for this order. Please choose another payment method.");
});

// ------------------------------------------------------------------ 2.60.636

/** The checkout's rates call as checkout.js makes it when a field is left. */
function codrRates(Cart $cart, array $extra)
{
    return codrShopper($cart)->postJson('/api/checkout/rates', $extra + [
        'country' => 'AE', 'state' => 'Dubai', 'offered' => ['cod'], 'payment_method' => 'cod',
    ]);
}

it('takes COD off the list silently when a blocked email or phone is entered', function () {
    // On the shop (2.60.636): the owner asked for no message at all -- a
    // refuser still shown COD until Place order, or shown a sentence.
    // MUTATION: drop noteShopper() from CheckoutController::rates() -> red.
    codrRefused('refuser@example.com', '0501234567');
    $card = PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    $card->config = ['public_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'merchant_code' => 'AE'];
    $card->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $r = codrRates(codrCart(), ['offered' => ['cod', 'tabby'], 'billing_phone' => '+971 50 123 4567'])->assertOk()->json();
    expect($r['paymentHtml'] ?? '')->not->toContain('value="cod"')
        ->and($r['paymentHtml'] ?? '')->toContain('value="tabby"')
        ->and($r['paymentHtml'] ?? '')->not->toContain('not available')
        ->and(session(CodRefusals::SESSION))->toBeTrue();
});

it('leaves an unrelated shopper’s rates answer exactly as it was when her email is sent', function () {
    // On the shop: a real customer's payment list redrawn, or a card being
    // typed into torn down, because her email was checked.
    // MUTATION: make blocksAny() return true for everyone -> red.
    codrRefused('refuser@example.com', '0501234567');
    $cart = codrCart();
    $plain = codrRates($cart, [])->assertOk()->json();
    $sent = codrRates($cart, ['billing_email' => 'happy@example.com', 'billing_phone' => '0509998888'])->assertOk()->json();

    expect($sent)->toBe($plain)->and($sent)->not->toHaveKey('paymentHtml')
        ->and(session(CodRefusals::SESSION))->toBeNull();
});

it('answers a forced COD from the placing overlay with the list and no sentence', function () {
    // On the shop: the overlay printing "Something went wrong" over a refuser.
    // MUTATION: give codQuietlyGone() an error string -> red.
    codrRefused('refuser@example.com', '0501234567');
    $res = codrShopper(codrCart())->postJson('/checkout/place', [
        'billing_email' => 'refuser@example.com', 'billing_phone' => '0501234567',
        'billing_first_name' => 'Noura', 'billing_last_name' => 'A', 'billing_address_1' => '44 Road',
        'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ])->assertStatus(422)->json();

    expect($res['code'])->toBe('pay_list')->and($res['error'])->toBe('')
        ->and($res['paymentHtml'])->not->toContain('value="cod"');

    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));
    $overlay = (string) file_get_contents(resource_path('views/partials/checkout/placing-overlay.blade.php'));
    expect($js)->toContain("body.code === 'pay_list'")
        ->and(substr_count($overlay, "data.code === 'pay_list'"))->toBe(1);
});

it('records COD orders only: a Tabby, Tamara or card order shipped then cancelled never counts', function (string $method) {
    // On the shop (the owner, 2 November: "ignore if any customer's order
    // failed with tabby, tamara or card payment"): a prepaid shopper losing COD.
    // MUTATION: drop the payment_method check from transitioned() -> red.
    $o = Order::create(['order_number' => 'CRP' . Str::upper(Str::random(6)), 'email' => 'paid@example.com',
        'phone' => '0501112233', 'status' => 'processing', 'total' => 12000, 'payment_method' => $method]);
    app(OrderStatus::class)->moveTo($o, 'shipped', 'test');
    app(OrderStatus::class)->moveTo($o, 'cancelled', 'test');

    expect(DB::table('cod_refusals')->count())->toBe(0)
        ->and(app(CodRefusals::class)->blocks('paid@example.com', null))->toBeFalse();
})->with(['tabby', 'tamara', 'stripe']);

it('backfills every past cancelled COD order as its own switchable group, and nothing else', function () {
    // On the shop: a customer whose only order was a cancelled COD (before it
    // ever shipped) still offered COD at launch; or a failed Tabby / card
    // order, or a customer with a completed order, losing it.
    // MUTATION: drop the payment_method filter from the history migration ->
    // the Tabby row appears; drop the completed check -> the loyal row does.
    DB::table('cod_refusals')->delete();
    $early = codrOrder('cancelled', 'early@example.com', '0505556666');
    $tabby = Order::create(['order_number' => 'CRT1', 'email' => 'tabby@example.com', 'phone' => '0504440000',
        'status' => 'cancelled', 'total' => 9000, 'payment_method' => 'tabby']);
    codrOrder('cancelled', 'loyal@example.com', '0507778888');
    codrOrder('completed', 'loyal@example.com', null);
    // A non-COD row 2.60.635 may already hold goes on install.
    DB::table('cod_refusals')->insert(['email' => 'tabby@example.com', 'phone' => '971504440000', 'order_id' => $tabby->id,
        'order_number' => 'CRT1', 'refused_at' => now(), 'source' => 'refused', 'created_at' => now(), 'updated_at' => now()]);

    $m = require database_path('migrations/2028_01_04_100000_cod_refusals_past_cancelled.php');
    $m->up();
    $m->up(); // idempotent

    expect(DB::table('cod_refusals')->pluck('order_id')->all())->toBe([$early->id])
        ->and(DB::table('cod_refusals')->value('source'))->toBe('history');

    $svc = fn () => app(CodRefusals::class);
    expect($svc()->blocks('early@example.com', null))->toBeTrue();

    // Its own switch: off frees the past-cancelled group, never the refusers.
    $refuser = codrRefused('refuser@example.com', '0501234567');
    app(SettingsService::class)->set(CodRefusals::HISTORY_SETTING, '0');
    codrForget();
    expect($svc()->blocks('early@example.com', null))->toBeFalse()
        ->and($svc()->blocks('refuser@example.com', null))->toBeTrue();

    codrSwitch(false);
    app(SettingsService::class)->set(CodRefusals::HISTORY_SETTING, '1');
    codrForget();
    expect($svc()->blocks('early@example.com', null))->toBeTrue()
        ->and($svc()->blocks('refuser@example.com', null))->toBeFalse();

    // And the second switch saves from the admin.
    $owner = codrOwner();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/cod-refusals/settings', ['history_enabled' => false])
        ->assertOk()->assertJsonPath('history_enabled', false)->assertJsonPath('enabled', false);
});

it('ships the history migration as literals too', function () {
    // MUTATION: change the 10-digit rule in the migration's phone() -> red.
    $file = database_path('migrations/2028_01_04_100000_cod_refusals_past_cancelled.php');
    $m = require $file;
    $code = '';
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        $code .= (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) ? '' : (is_array($t) ? $t[1] : $t);
    }
    expect(str_contains($code, 'App\\'))->toBeFalse();
    foreach (['050 123 4567', '+971 50 123 4567', '00971501234567', '501234567', '12345', '', null, '0412345678'] as $p) {
        expect($m::phone($p))->toBe(CodRefusals::phone($p));
    }
    foreach (['  A@B.com ', 'no-at', '', null] as $e) {
        expect($m::email($e))->toBe(CodRefusals::email($e));
    }
});

// ------------------------------------------------------------------ 2.60.637 whitelist

it('keeps only real emails, phones and IPs in the whitelist, normalised', function () {
    // On the back office: a typo like "all" or "*" widening the whitelist, or
    // "050 123 4567" stored in a shape checkout never compares against.
    // MUTATION: accept any line in parseAllow() -> red.
    $p = CodRefusals::parseAllow("Orders@KBeautyBliss.com\n050 123 4567\n94.200.10.25\n10.0.0.7/24\nall\n*\n\n12345\nnot an email@");
    expect(array_keys($p['emails']))->toBe(['orders@kbeautybliss.com'])
        ->and(array_map('strval', array_keys($p['phones'])))->toBe(['971501234567'])
        ->and($p['ips'])->toBe(['94.200.10.25', '10.0.0.0/24'])
        ->and(CodRefusals::allowText($p))->toBe("orders@kbeautybliss.com\n+971501234567\n94.200.10.25\n10.0.0.0/24")
        ->and(CodRefusals::parseAllow(CodRefusals::allowText($p)))->toBe($p);
});

it('always offers COD to a whitelisted email, phone or IP, even on the list', function () {
    // On the shop: the owner's own address, or a customer he agreed with,
    // still losing COD after he whitelisted them.
    // MUTATION: drop the whitelisted() check from blocksAny() -> the email and
    // phone halves are red; from hiddenForShopper() -> the IP half is red.
    codrRefused('refuser@example.com', '0501234567');
    $svc = fn () => app(CodRefusals::class);
    expect($svc()->blocks('refuser@example.com', null))->toBeTrue();

    $owner = codrOwner();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/cod-refusals/whitelist', ['text' => "REFUSER@example.com\nrubbish"])
        ->assertOk()->assertJsonPath('whitelist', 'refuser@example.com')->assertJsonPath('count', 1);
    codrForget();
    expect($svc()->blocks('refuser@example.com', null))->toBeFalse()
        ->and($svc()->blocks(null, '0501234567'))->toBeTrue();

    app(SettingsService::class)->set(CodRefusals::ALLOW_SETTING, '+971 50 123 4567');
    codrForget();
    expect($svc()->blocks(null, '050 123 4567'))->toBeFalse();

    // By IP: the guest's session flag no longer hides COD, and Place order passes.
    app(SettingsService::class)->set(CodRefusals::ALLOW_SETTING, '127.0.0.0/8');
    codrForget();
    $before = Order::count();
    codrShopper(codrCart())->withSession([CodRefusals::SESSION => true])->post('/checkout/place', [
        'billing_email' => 'refuser@example.com', 'billing_phone' => '0501234567',
        'billing_first_name' => 'Noura', 'billing_last_name' => 'A', 'billing_address_1' => '44 Road',
        'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ]);
    expect(Order::count())->toBe($before + 1)
        ->and(Order::latest('id')->first()->payment_method)->toBe('cod');
});

it('keeps the whitelist endpoint to codrefusals.manage', function () {
    // On the back office: a manager or editor whitelisting anyone.
    foreach (['manager', 'editor'] as $role) {
        expect(test()->actingAs(codrOwner($role), 'admin')->postJson('/admin-api/cod-refusals/whitelist', ['text' => 'x@y.com'])->status())->toBe(403);
    }
    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/cod-refusals/whitelist'))->toBe('codrefusals.manage');
});
