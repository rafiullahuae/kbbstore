<?php

declare(strict_types=1);

/*
 * Lane RJ — PROBES, NOT REGRESSION TESTS.
 *
 * Each case here passes BECAUSE a finding in docs/EMAILS-AUDIT.md is true of
 * the code today. They live outside tests/Feature on purpose, so the default
 * suite never runs them and nobody mistakes "green" for "correct". When Phase 2
 * fixes a finding, the matching probe goes red — delete it then and write the
 * real regression test the other way round (CLAUDE.md, rule 6).
 *
 *   KBB_WP_DB=kbb_wp_rj vendor/bin/pest tests/Support/rj-probes/RjEmailAuditProbesTest.php
 */

use App\Mail\OrderConfirmation;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Notifications\CustomerEmailVerification;
use App\Notifications\CustomerPasswordReset;
use App\Services\CartService;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Container::setInstance($this->app);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->app);
    Model::setConnectionResolver($this->app['db']);
    Tests\Support\StaticMemos::forgetAll();
});

function rjProbeOrder(array $address): Order
{
    return Order::create([
        'order_number' => 'KBB-PROBE', 'email' => 'a@example.com', 'status' => 'processing', 'currency' => 'AED',
        'billing_address' => $address, 'shipping_address' => $address, 'subtotal' => 1000, 'total' => 1000,
        'payment_method' => 'cod',
    ]);
}

/*
 * FINDING B1. A card order is receipted BEFORE it is paid.
 *
 * CheckoutController::place() calls OrderMailer::placed() as soon as the
 * gateway has STARTED (an intent opened / a hosted page issued) — while the
 * order is still `pending` and the shopper has not typed a card. The receipt
 * says "Your order is in and we are packing it with care". A shopper who then
 * abandons the card form, Tabby or Tamara has that email for an order that is
 * never paid.
 */
it('B1: sends the order confirmation while a card order is still pending and unpaid', function () {
    PaymentProvider::query()->delete();
    Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/checkout-card.php'));

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);

    $row = PaymentProvider::create(['id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $row->config = ['publishable_key' => 'pk_test_rj', 'secret_key' => 'sk_test_rj', 'webhook_signing_secret' => 'whsec_rj', 'webhook_secret' => 'whsec-url-rj-0123456789ab'];
    $row->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_rj', 'client_secret' => 'pi_rj_secret', 'status' => 'requires_payment_method'], 200)]);
    Mail::fake();

    $product = Product::create(['slug' => 'rj-serum', 'name' => 'Serum', 'status' => 'publish', 'is_visible' => true, 'price' => 200, 'stock_status' => 'instock']);
    $cart = Cart::create(['token' => (string) Illuminate\Support\Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);

    test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson('/checkout/place', [
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan', 'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai',
            'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'stripe',
        ])->assertOk()->assertJsonPath('action', 'confirm');

    $order = Order::latest('id')->first();

    expect($order->status)->toBe('pending')->and($order->paid_at)->toBeNull();
    Mail::assertSent(OrderConfirmation::class, fn ($m) => $m->hasTo('buyer@example.com'));
});

/*
 * FINDING B2. The two account emails carry no plain-text part.
 * MailMessage::view('x') with a string is HTML only; every Mailable in
 * app/Mail has a -text view, these two notifications do not.
 */
it('B2: password reset and email verification are HTML-only', function () {
    $c = Customer::create(['email' => 'p@example.com', 'name' => 'P']);

    foreach ([new CustomerPasswordReset('tok', $c->id), new CustomerEmailVerification($c)] as $n) {
        $message = $n->toMail($c);
        expect($message->view)->toBeString()          // not [html, text]
            ->and($message->textView ?? null)->toBeNull();
    }
});

/*
 * FINDING B3. The account emails do not name the store's mailer, so they
 * follow MAIL_MAILER in .env — unlike every order email, which goes through
 * the `kbb` mailer the Store → Mail screen configures and tests. With
 * MAIL_MAILER unset (config/mail.php's default) the two agree.
 */
it('B3: account notifications use the default mailer, not the kbb mailer', function () {
    $c = Customer::create(['email' => 'q@example.com', 'name' => 'Q']);

    expect((new CustomerPasswordReset('tok', $c->id))->toMail($c)->mailer)->toBeNull()
        ->and((new CustomerEmailVerification($c))->toMail($c)->mailer)->toBeNull();
});

/*
 * FINDING B4. The address block prints "Dubai Dubai" and "AE".
 * OrderEmailPresenter::address() joins city and state with a space whether or
 * not they are the same word, and prints the ISO code as the country.
 */
it('B4: prints a duplicated city/state and a bare country code', function () {
    $order = rjProbeOrder(['first_name' => 'A', 'last_name' => 'K', 'line1' => 'Marina', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE']);
    $presented = (new \App\Services\Mail\OrderEmailPresenter)->present($order->fresh('items'));

    expect($presented['address'])->toContain('Dubai Dubai')->toContain('AE');
});

/*
 * FINDING B5. No message carries List-Unsubscribe-Post (RFC 8058 one-click),
 * and the List-Unsubscribe URL is a page with a form, not a POST endpoint.
 * Harmless for today's volumes; required by Gmail/Yahoo for bulk marketing.
 */
it('B5: the unsubscribe header is not one-click', function () {
    $m = new \App\Mail\BackInStockAlert('s', 'b', 'p', 'https://x.test/p', 'https://x.test/mail-preferences/stock/1/');
    $text = $m->headers()->text;

    expect($text)->toHaveKey('List-Unsubscribe')->not->toHaveKey('List-Unsubscribe-Post');
});
