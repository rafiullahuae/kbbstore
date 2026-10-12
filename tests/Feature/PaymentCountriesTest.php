<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Payments\GatewayCountries;
use App\Services\Payments\GatewayRegistry;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * STORE → PAYMENTS → <METHOD> → COUNTRIES.                       (Lane PCY)
 *
 * The owner, 12 October: "Cash on delivery we offer only in UAE. please on the
 * payment method COD, we should have option to choose the country we offer
 * for. The same we need for all other payment gateways ... full control, on
 * which country to offer which payment gateway ... super secure ... must not
 * touch or disturbed anything else."
 *
 * What each case would have caught, on the shop:
 *
 *   - a Saudi shopper offered cash on delivery the shop does not do;
 *   - a rule that only hid the radio: a posted `payment_method=cod` from Riyadh
 *     placing anyway, on the shop's Place order or on the public API;
 *   - the UAE losing COD, or Tabby/Tamara/card moving anywhere he did not ask;
 *   - a typo ("UAE", "XX", "<b>") saved as a country -- or, worse, a damaged
 *     list read back as "every country";
 *   - a screen open from before the update resetting the countries on Save;
 *   - anyone but a payments manager changing it;
 *   - a new query on the checkout.
 */
function pcyZones(): void
{
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => 'SA']);
    ShippingMethod::create(['shipping_zone_id' => $gulf->id, 'type' => 'flat_rate', 'title' => 'Gulf delivery', 'cost' => 15000, 'enabled' => true, 'position' => 0]);
}

function pcyCountries(): GatewayCountries
{
    SettingsService::forgetMemo();
    app()->forgetInstance(GatewayCountries::class);

    return app(GatewayCountries::class);
}

function pcyCart(string $country): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => $country, 'last_activity_at' => now()]);
    $product = Product::create(['slug' => 'pcy-'.Str::random(8), 'name' => 'Snail Essence', 'status' => 'publish', 'is_visible' => true, 'price' => 12000, 'stock_status' => 'instock']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 12000]);

    return $cart;
}

function pcyPlace(string $country)
{
    app(CartService::class)->forget();

    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, pcyCart($country)->token)
        ->post('/checkout/place', [
            'billing_email' => 'pcy-'.strtolower($country).'@example.com', 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Noura', 'billing_last_name' => 'A', 'billing_address_1' => '44 Road',
            'billing_city' => $country === 'AE' ? 'Dubai' : 'Riyadh', 'billing_state' => $country === 'AE' ? 'Dubai' : 'Riyadh',
            'billing_country' => $country, 'payment_method' => 'cod',
        ]);
}

function pcyOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'PCY '.$role, 'email' => 'pcy-'.$role.'-'.Str::random(6).'@example.com', 'password' => bcrypt('secret'), 'role' => $role]);
}

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
    pcyZones();
});

it('ships cash on delivery as UAE only, because he asked, and moves nothing else', function () {
    // MUTATION: empty GatewayCountries::DEFAULTS and the SA assertion is red.
    $c = pcyCountries();

    expect($c->for('cod'))->toBe(['AE'])
        ->and($c->allows('cod', 'AE'))->toBeTrue()
        ->and($c->allows('cod', 'ae'))->toBeTrue()
        ->and($c->allows('cod', 'SA'))->toBeFalse();

    foreach (['tabby', 'tamara', 'stripe'] as $other) {
        expect($c->for($other))->toBe([])
            ->and($c->allows($other, 'SA'))->toBeTrue()
            ->and($c->allows($other, 'AE'))->toBeTrue();
    }
});

it('lists cash on delivery at checkout for the UAE and not for Saudi Arabia', function () {
    // MUTATION: drop the GatewayCountries filter from GatewayRegistry::availableFor()
    // and the Saudi list contains cod.
    $registry = app(GatewayRegistry::class);

    expect(collect($registry->checkoutList(12000, 'AE'))->pluck('id')->all())->toContain('cod')
        ->and(collect($registry->checkoutList(12000, 'SA'))->pluck('id')->all())->not->toContain('cod');
});

it('refuses a posted cash on delivery from Saudi Arabia on Place order, and still places the UAE one', function () {
    $before = Order::count();

    // MUTATION: drop the GatewayCountries filter from GatewayRegistry and the
    // Riyadh order is placed.
    pcyPlace('SA')->assertRedirect();
    expect(session('errors')?->first())->toBe('That payment method is not available.')
        ->and(Order::count())->toBe($before);

    pcyPlace('AE')->assertRedirect();
    expect(Order::count())->toBe($before + 1)
        ->and(Order::latest('id')->first()->payment_method)->toBe('cod');

    // The control: Riyadh places once COD is offered there, so the refusal was
    // the country rule and not delivery, stock or the form.
    app(SettingsService::class)->set(GatewayCountries::SETTING, json_encode(['cod' => ['AE', 'SA']]));
    SettingsService::forgetMemo();
    app()->forgetInstance(GatewayCountries::class);

    pcyPlace('SA')->assertRedirect();
    expect(Order::count())->toBe($before + 2)
        ->and(Order::latest('id')->first()->billing_address['country'] ?? null)->toBe('SA');
});

it('refuses cash on delivery to Saudi Arabia on the public order endpoint too', function () {
    // MUTATION: drop the allows() line from Api\CheckoutController and this
    // places a Riyadh COD order.
    $product = Product::create(['slug' => 'pcy-api-'.Str::random(6), 'name' => 'Toner', 'status' => 'publish', 'is_visible' => true, 'price' => 9000, 'stock_status' => 'instock']);
    $before = Order::count();

    $post = fn () => test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $product->slug, 'qty' => 1]],
        'customer' => ['name' => 'Noura A', 'email' => 'pcy-api@example.com', 'phone' => '+966500000000', 'address' => '44 Road', 'city' => 'Riyadh', 'country' => 'SA'],
        'method' => 'cod',
    ]);

    $post()->assertStatus(422)->assertJsonPath('error', 'That payment method is not available.');
    expect(Order::count())->toBe($before);

    // The control: the same request goes through once COD is offered in every
    // country, so the refusal above was the country rule and nothing else.
    app(SettingsService::class)->set(GatewayCountries::SETTING, json_encode(['cod' => []]));
    SettingsService::forgetMemo();
    app()->forgetInstance(GatewayCountries::class);

    $post()->assertCreated();
    expect(Order::count())->toBe($before + 1);
});

it('saves a list from Store → Payments, and Tick none means every country again', function () {
    $owner = pcyOwner();

    test()->actingAs($owner, 'admin')->postJson('/admin-api/payments', ['id' => 'cod', 'countries' => ['ae', 'SA']])->assertOk();
    expect(pcyCountries()->for('cod'))->toBe(['AE', 'SA']);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/payments', ['id' => 'cod', 'countries' => []])->assertOk();
    expect(pcyCountries()->for('cod'))->toBe([])
        ->and(pcyCountries()->allows('cod', 'SA'))->toBeTrue();

    $payload = test()->actingAs($owner, 'admin')->getJson('/admin-api/payments')->assertOk()->json();
    expect($payload['country_options'])->toHaveKeys(['AE', 'SA'])
        ->and(collect($payload['gateways'])->firstWhere('id', 'cod')['countries'])->toBe([]);
});

it('refuses a code that is not a country, saving nothing at all', function (mixed $bad) {
    $owner = pcyOwner();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/payments', ['id' => 'cod', 'countries' => ['AE'], 'title' => 'Cash on delivery'])->assertOk();

    test()->actingAs($owner, 'admin')->postJson('/admin-api/payments', ['id' => 'cod', 'countries' => [$bad], 'title' => 'CHANGED'])->assertStatus(422);

    expect(pcyCountries()->for('cod'))->toBe(['AE'])
        ->and(PaymentProvider::find('cod')->title)->toBe('Cash on delivery');
})->with(['XX', 'UAE', '<b', 'a1', 12]);

it('keeps the countries when a save does not send them (a screen open from before)', function () {
    $owner = pcyOwner();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/payments', ['id' => 'cod', 'countries' => ['AE', 'SA']])->assertOk();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/payments', ['id' => 'cod', 'title' => 'COD'])->assertOk();

    expect(pcyCountries()->for('cod'))->toBe(['AE', 'SA']);
});

it('reads a damaged list as no country at all, never as every country', function () {
    app(SettingsService::class)->set(GatewayCountries::SETTING, json_encode(['cod' => ['ZZZ', 'xx'], 'tabby' => 'nonsense']));

    $c = pcyCountries();

    expect($c->allows('cod', 'AE'))->toBeFalse()
        ->and($c->allows('cod', 'SA'))->toBeFalse()
        ->and($c->allows('tabby', 'SA'))->toBeTrue();
});

it('keeps a support account and a guest out', function () {
    test()->postJson('/admin-api/payments', ['id' => 'cod', 'countries' => []])->assertStatus(401);

    $support = pcyOwner('support');
    $status = test()->actingAs($support, 'admin')->postJson('/admin-api/payments', ['id' => 'cod', 'countries' => []])->status();

    expect($status)->toBeIn([401, 403])
        ->and(pcyCountries()->for('cod'))->toBe(['AE']);
});

it('costs the checkout list no query of its own', function () {
    $registry = app(GatewayRegistry::class);
    $registry->checkoutList(12000, 'AE'); // warm

    $count = function () use ($registry): int {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $registry->checkoutList(12000, 'SA');

        return $n;
    };

    $without = $count();
    app(SettingsService::class)->set(GatewayCountries::SETTING, json_encode(['cod' => ['AE'], 'stripe' => ['AE', 'SA']]));
    SettingsService::forgetMemo();
    app(SettingsService::class)->all();
    $registry->checkoutList(12000, 'AE');

    expect($count())->toBe($without);
});

it('draws the Countries box on every payment method in Store → Payments', function () {
    $html = file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($html, 'payEnabledRow(g)+payTitleRow(g)+payCountriesRow(g)+'))->toBe(1)
        ->and($html)->toContain('data-paycountry=')
        ->and($html)->toContain("payload.countries=payCountryList(id)");
});

it('treats a basket with no country yet as the shop’s own country', function () {
    // The checkout's own default before an address exists; without this a UAE
    // shop's COD vanished for every caller that had no country in hand.
    $c = pcyCountries();

    expect($c->allows('cod', null))->toBeTrue()
        ->and($c->allows('cod', ''))->toBeTrue()
        ->and(collect(app(GatewayRegistry::class)->checkoutList(12000))->pluck('id')->all())->toContain('cod');

    app(SettingsService::class)->set('store_country', 'SA');
    expect(pcyCountries()->allows('cod', null))->toBeFalse();
});

it('re-draws the payment list when the shopper changes country, and only then', function () {
    // The defect, measured in Chromium at 390: switching the checkout's country
    // to Saudi Arabia left "Cash on delivery" on screen until Place order
    // refused it, because the country endpoint never re-drew the list.
    // MUTATION: drop the `$payment` block from CheckoutController::rates() and
    // the Saudi answer has no paymentHtml.
    $cart = pcyCart('AE');
    $shopper = fn () => test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    app(CartService::class)->forget();
    $sa = $shopper()->postJson('/api/checkout/rates', ['country' => 'SA', 'offered' => ['cod'], 'payment_method' => 'cod'])->assertOk()->json();

    expect($sa)->toHaveKey('paymentHtml')
        ->and($sa['paymentHtml'])->not->toContain('value="cod"');

    app(CartService::class)->forget();
    $ae = $shopper()->postJson('/api/checkout/rates', ['country' => 'AE', 'offered' => ['cod'], 'payment_method' => 'cod'])->assertOk()->json();

    // Unchanged list: left alone, so a card being typed into is not redrawn.
    expect($ae)->not->toHaveKey('paymentHtml');

    // An older page that does not send `offered` gets exactly the old answer.
    app(CartService::class)->forget();
    expect($shopper()->postJson('/api/checkout/rates', ['country' => 'SA'])->assertOk()->json())->not->toHaveKey('paymentHtml');
});
