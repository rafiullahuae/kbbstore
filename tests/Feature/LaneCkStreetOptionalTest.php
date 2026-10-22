<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Payments\GatewayCredentials;
use App\Services\SettingsService;
use App\Services\ShippingService;
use Database\Seeders\ShippingSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "AREA / STREET" IS NOT MANDATORY, AND THE WORD "OPTIONAL" APPEARS NOWHERE
 * (Lane CK, the owner, 22 October 2026).
 *
 * His live mobile screenshot read "Area / Street *" as a lifted pink heading.
 * The field is billing_address_2 in the list mode the shop ships with (Lane
 * AD): Building / Apartment or Villa is line 1 and stays required; Area /
 * Street is line 2 and is now optional in the browser AND in place().
 *
 * What this pins, each half the defect it would catch:
 *   - the box is drawn without `required`, without a "*" and without
 *     "(optional)", in English and in Arabic;
 *   - an order with the box empty is placed for cash on delivery, Tabby,
 *     Tamara and the card, with the same gateway fakes the payment tests use;
 *   - with the box FILLED, what Tabby and Tamara are sent is byte for byte
 *     what they were sent before this lane (the expected bodies are the ones
 *     AddressRegionsCheckoutTest already pinned);
 *   - every OTHER required field is still refused by the server, in both
 *     languages: the street rule weakened nothing else.
 */
beforeEach(function () {
    app(\App\Services\CheckoutLayout::class)->restore();
    app(SettingsService::class)->set(\App\Services\Payments\GatewayCountries::SETTING, json_encode(['cod' => []]));
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
    Http::preventStrayRequests();
    (new ShippingSeeder())->run();
    ShippingService::flushZones();
});

function ckStreetProvider(string $id, array $config): void
{
    $row = PaymentProvider::create(['id' => $id, 'title' => ucfirst($id), 'enabled' => true, 'mode' => 'live', 'position' => 1]);
    $row->config = $config;
    $row->save();
    app(GatewayCredentials::class)->forget();
}

function ckStreetCart(): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $p = Product::create(['slug' => 'ck-st-'.Str::random(8), 'name' => 'Rice Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 250, 'stock_status' => 'instock']);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 25000]);

    return $cart;
}

/** @param array<string, mixed> $over */
function ckStreetPost(array $over = [], string $prefix = '', ?Cart $cart = null)
{
    $cart ??= ckStreetCart();
    if ($prefix === '/ar') {
        \Tests\Support\ArabicShop::on();
    }

    return test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post($prefix.'/checkout/place', array_merge([
            'billing_email' => 'mariam@example.com',
            'billing_phone' => '+971500000000',
            'billing_first_name' => 'Mariam Saeed',
            'billing_address_1' => 'Marina Heights, Apt 1203',
            'billing_address_2' => '',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ], $over));
}

function ckStreetPage(string $prefix = ''): string
{
    if ($prefix === '/ar') {
        \Tests\Support\ArabicShop::on();
    }

    return test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, ckStreetCart()->token)
        ->get($prefix.'/checkout')->assertOk()->getContent();
}

/** The whole Area / Street row. */
function ckStreetRow(string $html): string
{
    expect(preg_match('#<p[^>]*id="billing_address_2_field".*?</p>#s', $html, $m))->toBe(1, 'no Area / Street row');

    return $m[0];
}

it('draws Area / Street without required, without a star and without "optional", in English and Arabic', function (string $prefix) {
    $html = ckStreetPage($prefix);
    $row = ckStreetRow($html);

    // MUTATION: put `required` back on billing_address_2 in address-fields-list. RED.
    expect($row)->not->toMatch('/<input[^>]*name="billing_address_2"[^>]*\brequired\b/')
        ->and($row)->not->toContain('aria-required')
        ->and($row)->not->toContain('validate-required')
        ->and($row)->not->toContain('class="required"')
        ->and(mb_strtolower(strip_tags($row)))->not->toContain('optional')
        ->and(strip_tags($row))->not->toContain('اختياري')
        // Still the same box: name, id, autocomplete, the line-2 slot.
        ->and($row)->toMatch('/<input[^>]*name="billing_address_2"[^>]*autocomplete="section-billing billing address-line2"/');

    // Building is still required, and says so to a screen reader.
    expect($html)->toMatch('/<input[^>]*name="billing_address_1"[^>]*required aria-required="true"/');
})->with(['English' => [''], 'Arabic' => ['/ar']]);

it('places a cash-on-delivery order with Area / Street left empty', function () {
    // MUTATION: restore 'required_without:billing_city' on billing_address_2. RED.
    ckStreetPost()->assertSessionHasNoErrors();

    $order = Order::latest('id')->firstOrFail();

    expect($order->payment_method)->toBe('cod')
        ->and($order->shipping_address['line1'])->toBe('Marina Heights, Apt 1203')
        ->and((string) ($order->shipping_address['line2'] ?? ''))->toBe('')
        ->and($order->shipping_address['city'])->toBe('Dubai');

    // The email and the invoice print no empty line for it.
    $email = new ReflectionMethod(\App\Services\Mail\OrderEmailPresenter::class, 'address');
    $lines = $email->invoke(app(\App\Services\Mail\OrderEmailPresenter::class), $order->shipping_address);
    expect($lines)->not->toContain('')->and($lines)->toContain('Marina Heights, Apt 1203');
});

it('starts Tamara with Area / Street empty, sending no line2 and the building as line1', function () {
    ckStreetProvider('tamara', ['api_token' => 'ck-tamara-token', 'notification_token' => 'ck-n', 'webhook_secret' => 'ck-whsec-0123456789']);
    Http::fake(['*/checkout' => Http::response(['order_id' => 'tam_ck', 'checkout_url' => 'https://checkout.tamara.co/ck'])]);

    ckStreetPost(['payment_method' => 'tamara'])->assertSessionHasNoErrors()->assertRedirect('https://checkout.tamara.co/ck');

    Http::assertSent(function (HttpRequest $r) {
        if (! str_ends_with($r->url(), '/checkout')) {
            return false;
        }
        $ship = $r->data()['shipping_address'] ?? [];

        return ($ship['line1'] ?? null) === 'Marina Heights, Apt 1203' && ! array_key_exists('line2', $ship)
            && ($ship['city'] ?? null) === 'Dubai';
    });
    expect(Order::latest('id')->first()->transaction_id)->toBe('tam_ck');
});

it('starts Tabby with Area / Street empty, the address being the building alone', function () {
    ckStreetProvider('tabby', ['public_key' => 'pk_ck', 'secret_key' => 'sk_ck', 'merchant_code' => 'kbb']);
    Http::fake(['*/api/v2/checkout' => Http::response(['id' => 's_ck', 'status' => 'created', 'payment' => ['id' => 'p_ck'],
        'configuration' => ['available_products' => ['installments' => [['web_url' => 'https://checkout.tabby.ai/ck']]]]])]);

    ckStreetPost(['payment_method' => 'tabby'])->assertSessionHasNoErrors()->assertRedirect('https://checkout.tabby.ai/ck');

    Http::assertSent(function (HttpRequest $r) {
        $ship = $r->data()['payment']['shipping_address'] ?? [];

        return str_ends_with($r->url(), '/api/v2/checkout')
            && ($ship['address'] ?? null) === 'Marina Heights, Apt 1203' && ($ship['city'] ?? null) === 'Dubai';
    });
});

it('opens a card payment with Area / Street empty', function () {
    ckStreetProvider('stripe', ['secret_key' => 'sk_live_ck000', 'publishable_key' => 'pk_live_ck000']);
    Http::fake(['*/v1/payment_intents*' => Http::response(['id' => 'pi_ck', 'client_secret' => 'pi_ck_secret', 'status' => 'requires_payment_method'], 200)]);

    // The card is placed by the page's script (a JSON post), never by a plain form post.
    test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, ckStreetCart()->token)
        ->postJson('/checkout/place', [
            'billing_email' => 'mariam@example.com', 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Mariam Saeed', 'billing_address_1' => 'Marina Heights, Apt 1203',
            'billing_address_2' => '', 'billing_state' => 'Dubai', 'billing_country' => 'AE',
            'payment_method' => 'stripe',
        ])
        ->assertOk()
        ->assertJsonPath('client_secret', 'pi_ck_secret');

    $order = Order::latest('id')->firstOrFail();
    expect($order->payment_method)->toBe('stripe')->and($order->status)->toBe('pending');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/v1/payment_intents'));
});

it('sends Tabby and Tamara exactly what they were sent before when Area / Street is filled', function () {
    /*
     * The bodies AddressRegionsCheckoutTest pinned before this lane, for the
     * same address. No file under app/Services/Payments changed; this proves
     * the ORDER they are built from did not either.
     */
    ckStreetPost(['billing_address_2' => 'Dubai Marina, Al Marsa St', 'billing_state' => 'dubai'])->assertSessionHasNoErrors();
    $order = Order::latest('id')->firstOrFail();

    expect(array_keys($order->shipping_address))->toBe(['first_name', 'last_name', 'line1', 'line2', 'city', 'state', 'country', 'phone'])
        ->and($order->shipping_address['line2'])->toBe('Dubai Marina, Al Marsa St');

    $tabby = new ReflectionMethod(\App\Services\Payments\Gateways\TabbyGateway::class, 'shippingAddress');
    expect(json_encode($tabby->invoke(app(\App\Services\Payments\Gateways\TabbyGateway::class), $order)))
        ->toBe('{"address":"Marina Heights, Apt 1203, Dubai Marina, Al Marsa St","city":"Dubai"}');

    $tamara = new ReflectionMethod(\App\Services\Payments\Gateways\TamaraGateway::class, 'address');
    expect(json_encode($tamara->invoke(app(\App\Services\Payments\Gateways\TamaraGateway::class), $order->shipping_address)))
        ->toBe('{"first_name":"Mariam","last_name":"Saeed","line1":"Marina Heights, Apt 1203","line2":"Dubai Marina, Al Marsa St","city":"Dubai","region":"Dubai","country_code":"AE","phone_number":"+971500000000"}');
});

it('still refuses every other required field, in English and in Arabic', function (string $prefix, string $field) {
    // MUTATION: make any of these nullable in place(). RED for that field.
    ckStreetPost([$field => ''], $prefix)->assertSessionHasErrors([$field]);

    expect(Order::query()->count())->toBe(0);
})->with([
    'English' => [''], 'Arabic' => ['/ar'],
])->with([
    'email' => ['billing_email'], 'name' => ['billing_first_name'], 'phone' => ['billing_phone'],
    'building' => ['billing_address_1'], 'emirate' => ['billing_state'], 'country' => ['billing_country'],
    'payment' => ['payment_method'],
]);
