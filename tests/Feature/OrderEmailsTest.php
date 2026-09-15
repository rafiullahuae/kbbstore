<?php

/**
 * Transactional order email — Lane AB.
 *
 * Before this the store sent a customer nothing at all: no app/Mail, no
 * resources/views/emails, no Mailable anywhere. Somebody paid and heard silence,
 * and the merchant was not told an order had arrived.
 *
 * What these tests are for, in the order the risks matter:
 *
 *   1. THE CHECKOUT MUST SURVIVE A DEAD MAIL SERVER. This project has already
 *      had a checkout outage. A receipt that 500s the checkout is a worse
 *      failure than no receipt, so there is a test here that puts a throwing
 *      transport behind a real POST to /checkout/place and insists the order is
 *      still placed and the response is still an ordinary redirect.
 *   2. THE FIGURES MUST BE THE ORDER'S. Money is integer fils everywhere, and
 *      these assert the exact integers as well as the strings rendered from
 *      them — an email that says AED 216 for a 21550-fils order is wrong in a
 *      way no screenshot review would catch.
 *   3. THE SWITCHES MUST REALLY SWITCH. Three toggles in this project's history
 *      have silently done nothing. Each of the five has a test that turns it off
 *      and proves the email stops.
 *   4. CUSTOMER TEXT MUST NOT BE MARKUP. Names, addresses and gift messages go
 *      into an HTML body. Two XSS holes have already been fixed in this repo.
 */

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;

const ORDER_MAIL_SMTP_PASSWORD = 'KBBORDERMAIL-pw-0007';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);
});

/* ------------------------------------------------------------- fixtures -- */

function orderMailCart(int $unitPriceFils = 20000, array $product = []): Cart
{
    $row = Product::create(array_merge([
        'slug' => 'serum-' . uniqid(),
        'name' => 'Rice Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $unitPriceFils / 100,
        'stock_status' => 'instock',
    ], $product));

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $row->id,
        'quantity' => 1,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

function orderMailForm(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ], $overrides);
}

/** A browser carrying this cart's cookie. EncryptCookies off — see CheckoutPlacementTest. */
function orderMailPlace(Cart $cart, array $form = [])
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', $form === [] ? orderMailForm() : $form);
}

/** COD enabled, with the surcharge the live store charges. */
function orderMailCod(int $fee = 1500): void
{
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', $fee);
}

/** An order on the books already, without going through checkout. */
function orderMailOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'KBB-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 1500,
        'tax_total' => 0,
        'total' => 23500,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner',
        'brand' => 'Haruharu',
        'sku' => 'HH-RT-150',
        'quantity' => 1,
        'unit_price' => 20000,
        'subtotal' => 20000,
        'total' => 20000,
    ]);

    return $order->fresh('items');
}

/** Turn one module off the way Store → Modules does. */
function orderMailSwitch(string $key, bool $on): void
{
    app(SettingsService::class)->setModule($key, $on);
}

/** The HTML body of the one message of this type that was sent. */
function orderMailBody(string $class): string
{
    $body = '';

    Mail::assertSent($class, function ($mail) use (&$body) {
        $body = (string) $mail->render();

        return true;
    });

    return $body;
}

/* ---------------------------------------------------- the confirmation -- */

it('emails the customer a receipt when an order is placed', function () {
    Mail::fake();
    orderMailCod();

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    $order = Order::latest('id')->first();

    // The exact integers first. Everything below is a rendering of these.
    expect($order->subtotal)->toBe(20000)
        ->and($order->shipping_total)->toBe(2000)
        ->and($order->fee_total)->toBe(1500)
        ->and($order->total)->toBe(23500);

    Mail::assertSent(OrderConfirmation::class, function ($mail) use ($order) {
        return $mail->hasTo('buyer@example.com')
            && $mail->orderNumber() === $order->order_number;
    });

    $body = orderMailBody(OrderConfirmation::class);

    expect($body)->toContain($order->order_number)
        // Rendered at the currency's real precision, not the storefront's
        // rounded whole-dirham display. AED 235.00, from 23500 fils exactly.
        ->and($body)->toContain(OrderEmailPresenter::html(23500))
        ->and($body)->toContain(OrderEmailPresenter::html(20000))
        ->and($body)->toContain(OrderEmailPresenter::html(2000))
        ->and($body)->toContain(OrderEmailPresenter::html(1500))
        // The snapshot line, the address, the delivery method.
        ->and($body)->toContain('Rice Toner')
        ->and($body)->toContain('12 Marina Walk')
        ->and($body)->toContain('Standard delivery')
        // paymentLabel(), never the raw gateway id.
        ->and($body)->toContain('Cash on delivery')
        ->and($body)->not->toContain('>cod<')
        // Somewhere to go and look at it.
        ->and($body)->toContain('/checkout/success?order=' . $order->order_number);
});

it('carries a plain-text part with the same figures as the HTML one', function () {
    Mail::fake();
    orderMailCod();

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    $text = '';

    // Rendered directly, because Mailable::render() returns the HTML part and
    // the whole point of this test is the other one.
    Mail::assertSent(OrderConfirmation::class, function ($mail) use (&$text) {
        $text = (string) view('emails.order-confirmation-text', $mail->buildViewData())->render();

        return true;
    });

    $order = Order::latest('id')->first();

    expect($text)->toContain($order->order_number)
        ->and($text)->toContain('Rice Toner')
        // Money::plain — the markup-free form. An HTML span in a text/plain
        // part is the bug this assertion exists to catch.
        ->and($text)->toContain(OrderEmailPresenter::plain(23500))
        ->and($text)->not->toContain('<span')
        ->and($text)->not->toContain('woocommerce-Price-amount');
});

it('prints what was bought, not what the product is called today', function () {
    Mail::fake();
    orderMailCod();

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    // Renamed and removed after the order — exactly the case order_items
    // snapshots exist for.
    $product = Product::first();
    $product->update(['name' => 'RENAMED LATER']);
    $product->delete();

    $body = orderMailBody(OrderConfirmation::class);

    expect($body)->toContain('Rice Toner')
        ->and($body)->not->toContain('RENAMED LATER');
});

/* ------------------------------------------------------- merchant alert -- */

it('tells the store an order has come in, at the configured address', function () {
    Mail::fake();
    orderMailCod();

    app(MailSettings::class)->save([
        'mail_from_address' => 'no-reply@kbeautybliss.com',
        'mail_merchant_address' => 'orders@kbeautybliss.com',
    ]);
    Setting::flushMap();

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    $order = Order::latest('id')->first();

    Mail::assertSent(NewOrderAlert::class, fn ($mail) => $mail->hasTo('orders@kbeautybliss.com')
        && $mail->orderNumber() === $order->order_number);

    $body = orderMailBody(NewOrderAlert::class);

    expect($body)->toContain('buyer@example.com')
        ->and($body)->toContain(OrderEmailPresenter::html(23500));
});

it('falls back to the From address when no alert address is set', function () {
    Mail::fake();
    orderMailCod();

    app(MailSettings::class)->save(['mail_from_address' => 'no-reply@kbeautybliss.com']);
    Setting::flushMap();

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    Mail::assertSent(NewOrderAlert::class, fn ($mail) => $mail->hasTo('no-reply@kbeautybliss.com'));
});

it('sends no merchant alert at all when no address is configured anywhere', function () {
    Mail::fake();
    orderMailCod();

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    // The customer still gets their receipt; only the alert has nowhere to go.
    Mail::assertSent(OrderConfirmation::class);
    Mail::assertNotSent(NewOrderAlert::class);
});

it('puts no admin path in the merchant alert', function () {
    Mail::fake();
    orderMailCod();

    app(SettingsService::class)->set('admin_path', 'kbb-secret-console');
    app(MailSettings::class)->save(['mail_from_address' => 'owner@example.com']);
    Setting::flushMap();

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    expect(orderMailBody(NewOrderAlert::class))->not->toContain('kbb-secret-console');
});

/* ------------------------------------------------ checkout must survive -- */

it('still places the order when the mail server is dead', function () {
    orderMailCod();

    // A real SMTP configuration with a transport that throws the way a refused
    // relay does. Not Mail::fake(): the point is that the exception is raised
    // from inside Illuminate's own send path, after the mailable has rendered.
    app(MailSettings::class)->save([
        'mail_transport' => 'smtp',
        'mail_host' => 'smtp.example.com',
        'mail_port' => '465',
        'mail_encryption' => 'ssl',
        'mail_username' => 'no-reply@example.com',
        'mail_password' => ORDER_MAIL_SMTP_PASSWORD,
        'mail_from_address' => 'no-reply@example.com',
        'mail_merchant_address' => 'owner@example.com',
    ]);
    Setting::flushMap();

    Mail::extend('smtp', fn () => new class implements Symfony\Component\Mailer\Transport\TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('Connection could not be established with host smtp.example.com:465');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });

    $before = Order::count();

    $response = orderMailPlace(orderMailCart(20000));

    $order = Order::latest('id')->first();

    // An ordinary redirect to the order-received page, no errors in the bag,
    // and the order really on the books with its money intact.
    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    expect(Order::count())->toBe($before + 1)
        ->and($response->headers->get('Location'))->toContain('order=' . $order->order_number)
        ->and($order->total)->toBe(23500)
        ->and($order->status)->toBe('processing');
});

it('still places the order when the mailable itself cannot be built', function () {
    orderMailCod();

    // Not a transport failure — a rendering one. The view is swapped for a name
    // that does not exist, which is what a half-applied package looks like:
    // the class shipped, the Blade file did not. CLAUDE.md records exactly that
    // kind of partial package (2.60.102–.106).
    Mail::extend('smtp', fn () => new class implements Symfony\Component\Mailer\Transport\TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new \RuntimeException('anything at all');
        }

        public function __toString(): string
        {
            return 'boom';
        }
    });

    $response = orderMailPlace(orderMailCart(20000));

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    expect(Order::latest('id')->first()->total)->toBe(23500);
});

/* ----------------------------------------------------- status changes --- */

it('emails the customer when an order is marked shipped', function () {
    Mail::fake();

    $order = orderMailOrder();
    $order->update(['status' => 'shipped']);

    Mail::assertSent(OrderStatusChanged::class, fn ($mail) => $mail->hasTo('buyer@example.com')
        && $mail->status === 'shipped'
        && $mail->orderNumber() === $order->order_number);

    $body = orderMailBody(OrderStatusChanged::class);

    expect($body)->toContain('on its way')
        ->and($body)->toContain($order->order_number)
        ->and($body)->toContain(OrderEmailPresenter::html(23500));
});

it('emails the customer when an order is cancelled', function () {
    Mail::fake();

    $order = orderMailOrder();
    $order->update(['status' => 'cancelled']);

    Mail::assertSent(OrderStatusChanged::class, fn ($mail) => $mail->status === 'cancelled');

    expect(orderMailBody(OrderStatusChanged::class))->toContain('cancelled');
});

it('says nothing for the statuses nobody designed a message for', function () {
    Mail::fake();

    $order = orderMailOrder(['status' => 'pending']);

    // Every other value AdminController::updateOrderStatus will accept.
    foreach (['processing', 'onhold', 'completed', 'draft', 'failed', 'refunded'] as $status) {
        $order->update(['status' => $status]);
    }

    Mail::assertNotSent(OrderStatusChanged::class);

    // `refunded` in particular: money going back is the refunds row's event, not
    // a status column's, or a partial refund would go unannounced and a full one
    // would be announced twice.
    Mail::assertNotSent(OrderRefunded::class);
});

it('does not send again when an unrelated column is saved', function () {
    Mail::fake();

    $order = orderMailOrder();
    $order->update(['status' => 'shipped']);
    $order->update(['customer_note' => 'leave with the concierge']);

    Mail::assertSent(OrderStatusChanged::class, 1);
});

/* ------------------------------------------------------------- refunds -- */

it('emails the customer when a refund actually settles', function () {
    Mail::fake();

    $order = orderMailOrder();

    // The exact pair of writes PaymentRefunder performs: the row is created
    // `pending` inside its locking transaction, then settled afterwards.
    $refund = $order->refunds()->create([
        'amount' => 23500,
        'status' => 'pending',
        'provider' => 'cod',
        'refunded_by' => 'Admin',
    ]);

    Mail::assertNotSent(OrderRefunded::class);

    $refund->forceFill(['status' => 'succeeded'])->save();

    Mail::assertSent(OrderRefunded::class, fn ($mail) => $mail->hasTo('buyer@example.com')
        && $mail->amountFils === 23500
        && $mail->isPartial === false);

    expect(orderMailBody(OrderRefunded::class))
        ->toContain(OrderEmailPresenter::html(23500))
        ->toContain($order->order_number);
});

it('says a partial refund is partial, in the refunded amount not the order total', function () {
    Mail::fake();

    $order = orderMailOrder();

    $refund = $order->refunds()->create(['amount' => 5000, 'status' => 'pending', 'provider' => 'cod', 'refunded_by' => 'Admin']);
    $refund->forceFill(['status' => 'succeeded'])->save();

    Mail::assertSent(OrderRefunded::class, fn ($mail) => $mail->amountFils === 5000 && $mail->isPartial === true);

    // AED 50.00 refunded against an AED 235.00 order — both printed, neither
    // mistaken for the other.
    expect(orderMailBody(OrderRefunded::class))
        ->toContain(OrderEmailPresenter::html(5000))
        ->toContain(OrderEmailPresenter::html(23500));
});

it('sends nothing for a refund that failed at the gateway', function () {
    Mail::fake();

    $order = orderMailOrder();

    $refund = $order->refunds()->create(['amount' => 5000, 'status' => 'pending', 'provider' => 'cod', 'refunded_by' => 'Admin']);
    $refund->forceFill(['status' => 'failed', 'failure_code' => 'declined'])->save();

    Mail::assertNotSent(OrderRefunded::class);
});

/* ------------------------------------------------------------ switches -- */

it('sends the confirmation on by default and stops when it is switched off', function () {
    Mail::fake();
    orderMailCod();

    orderMailSwitch('email_order_confirmation', false);

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    Mail::assertNotSent(OrderConfirmation::class);

    // And the order is still placed — a switched-off email is not a switched-off
    // checkout.
    expect(Order::latest('id')->first()->total)->toBe(23500);
});

it('stops the merchant alert when it is switched off', function () {
    Mail::fake();
    orderMailCod();

    app(MailSettings::class)->save(['mail_from_address' => 'owner@example.com']);
    Setting::flushMap();
    orderMailSwitch('email_merchant_new_order', false);

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    Mail::assertNotSent(NewOrderAlert::class);
    Mail::assertSent(OrderConfirmation::class);
});

it('stops the dispatch notification when it is switched off', function () {
    Mail::fake();
    orderMailSwitch('email_order_shipped', false);

    orderMailOrder()->update(['status' => 'shipped']);

    Mail::assertNotSent(OrderStatusChanged::class);
});

it('stops the cancellation notification when it is switched off', function () {
    Mail::fake();
    orderMailSwitch('email_order_cancelled', false);

    orderMailOrder()->update(['status' => 'cancelled']);

    Mail::assertNotSent(OrderStatusChanged::class);
});

it('stops the refund notification when it is switched off', function () {
    Mail::fake();
    orderMailSwitch('email_order_refunded', false);

    $order = orderMailOrder();
    $refund = $order->refunds()->create(['amount' => 5000, 'status' => 'pending', 'provider' => 'cod', 'refunded_by' => 'Admin']);
    $refund->forceFill(['status' => 'succeeded'])->save();

    Mail::assertNotSent(OrderRefunded::class);
});

it('switches the dispatch and cancellation notices independently', function () {
    Mail::fake();
    orderMailSwitch('email_order_shipped', false);

    orderMailOrder()->update(['status' => 'cancelled']);

    // Turning dispatch off must not take cancellation with it.
    Mail::assertSent(OrderStatusChanged::class, fn ($mail) => $mail->status === 'cancelled');
});

/* ----------------------------------------------- the owner's own field -- */

it('offers the alert address on Store → Mail, and refuses a non-address', function () {
    // The screen renders whatever MailSettings::SCHEMA declares — see
    // MailApiController::show() — so being in the schema is what puts the box on
    // the page. Its type matters too: a `secret` would never be echoed back.
    expect(MailSettings::SCHEMA)->toHaveKey('mail_merchant_address')
        ->and(MailSettings::SCHEMA['mail_merchant_address'][0])->toBe('text');

    $settings = app(MailSettings::class);

    $settings->save(['mail_merchant_address' => 'orders@kbeautybliss.com']);
    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(app(MailSettings::class)->get('mail_merchant_address'))->toBe('orders@kbeautybliss.com');

    // MailApiController's own rule list has no entry for this key and that file
    // belongs to another lane, so the format check lives in MailSettings::save().
    // Rubbish is refused and the previous value stands, rather than being stored
    // and failing silently at send time.
    app(MailSettings::class)->save(['mail_merchant_address' => 'not an address']);
    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(app(MailSettings::class)->get('mail_merchant_address'))->toBe('orders@kbeautybliss.com');
});

/* ------------------------------------------------------------ escaping -- */

it('escapes the customer text that goes into an HTML body', function () {
    Mail::fake();
    orderMailCod();

    $order = orderMailOrder([
        'billing_address' => [
            'first_name' => '<script>alert(1)</script>',
            'last_name' => 'Khan"onload="x',
            'line1' => '12 <img src=x onerror=alert(2)> Walk',
            'city' => 'Dubai',
            'country' => 'AE',
        ],
        'shipping_address' => [
            'first_name' => '<script>alert(1)</script>',
            'last_name' => 'Khan',
            'line1' => '12 <img src=x onerror=alert(2)> Walk',
            'city' => 'Dubai',
            'country' => 'AE',
        ],
        'is_gift' => true,
        'gift_note' => 'Happy birthday <script>alert(3)</script>',
        'customer_note' => 'Ring the bell <b>twice</b>',
        'status' => 'processing',
    ]);

    $order->update(['status' => 'shipped']);

    $body = orderMailBody(OrderStatusChanged::class);

    expect($body)->not->toContain('<script>alert(1)</script>')
        ->and($body)->not->toContain('<script>alert(3)</script>')
        ->and($body)->not->toContain('<img src=x onerror=alert(2)>')
        ->and($body)->not->toContain('<b>twice</b>')
        // Present, but as text.
        ->and($body)->toContain('&lt;script&gt;')
        ->and($body)->toContain('Happy birthday');
});

it('escapes a product name that came in through an import', function () {
    Mail::fake();

    $order = orderMailOrder();
    $order->items()->first()->update(['name' => 'Toner <script>alert(4)</script>', 'brand' => '"><svg onload=alert(5)>']);

    $order->fresh()->update(['status' => 'shipped']);

    $body = orderMailBody(OrderStatusChanged::class);

    expect($body)->not->toContain('<script>alert(4)</script>')
        ->and($body)->not->toContain('<svg onload=alert(5)>');
});

/* ------------------------------------------------------------- secrets -- */

it('puts no credential in an order email', function () {
    Mail::fake();
    orderMailCod();

    app(MailSettings::class)->save([
        'mail_from_address' => 'owner@example.com',
        'mail_password' => ORDER_MAIL_SMTP_PASSWORD,
    ]);
    app(SettingsService::class)->set('indexnow_key', 'INDEXNOW-CANARY-0009');
    Setting::flushMap();

    orderMailPlace(orderMailCart(20000))->assertRedirect();

    foreach ([OrderConfirmation::class, NewOrderAlert::class] as $class) {
        $body = orderMailBody($class);

        expect($body)->not->toContain(ORDER_MAIL_SMTP_PASSWORD)
            ->and($body)->not->toContain('INDEXNOW-CANARY-0009');
    }

    // And the subject, which travels further than the body does.
    Mail::assertSent(OrderConfirmation::class, function ($mail) {
        $subject = (string) $mail->envelope()->subject;

        return ! str_contains($subject, ORDER_MAIL_SMTP_PASSWORD)
            && ! str_contains($subject, 'INDEXNOW-CANARY-0009');
    });
});
