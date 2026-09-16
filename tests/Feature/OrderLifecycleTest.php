<?php

declare(strict_types=1);

/**
 * The order lifecycle, walked end to end — Lane BK.
 *
 * Placement has three doors into this shop (the storefront checkout, the
 * back-office builder and the public /api/checkout/session), status has two
 * (one order at a time, and the bulk selection on the orders list), and money
 * comes back through one. Every one of them writes the same `orders` table, so
 * where two of them disagree about what an order costs or who gets told about
 * it, the disagreement IS the bug — a shop cannot have two answers to "what did
 * this customer pay for delivery".
 *
 * What is pinned here, in the order the risks cost the owner:
 *
 *   1. DELIVERY IS PRICED FROM THE SHIPPING ZONES ON EVERY PATH. The public API
 *      endpoint priced it from a flat setting, so a Gulf order that the zones
 *      charge AED 150 to ship was taken for AED 20 — the shop paying the
 *      difference, silently, on every one.
 *   2. A STATUS CHANGE REACHES THE CUSTOMER WHICHEVER SCREEN MADE IT. Marking
 *      one order shipped emailed the customer; marking forty shipped from the
 *      list emailed nobody, because the bulk path writes through the query
 *      builder and fires no model events.
 *   3. NOTHING FIRES TWICE. Re-saving an order at the status it already holds,
 *      and repeating a bulk transition, must each send one email and no more.
 *
 * The emails are asserted on their RENDERED output — the fils integer and the
 * string rendered from it — never on the fact that a mailable was queued. This
 * store sent nothing at all for months while "an email was sent" would have
 * passed.
 */

use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OrdersAdminRoutes;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    // The two zones the live store runs, with the real figures: AED 20 flat in
    // the UAE free over AED 199, AED 150 flat across the Gulf free over 1,600.
    // The API endpoint's flat default happens to match the UAE one, which is
    // exactly why the divergence never showed up on a UAE order.
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'free_shipping', 'title' => 'Free delivery',
        'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
    ]);

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => 'SA']);
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate', 'title' => 'Gulf delivery',
        'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'free_shipping', 'title' => 'Free Gulf delivery',
        'cost' => 0, 'min_amount' => 160000, 'enabled' => true, 'position' => 1,
    ]);
});

function lifecycleProduct(int $priceFils = 20000): Product
{
    return Product::create([
        'slug' => 'lc-serum-' . uniqid(),
        'name' => 'Rice Toner',
        'status' => 'publish',
        'is_visible' => true,
        // products.price is integer fils, like every money column in this
        // schema — never a major-unit figure divided down.
        'price' => $priceFils,
        'stock_status' => 'instock',
    ]);
}

function lifecycleCod(int $fee = 0): void
{
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', $fee);
}

function lifecycleOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'KBB-LC-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => 0,
        'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner', 'brand' => 'Haruharu', 'sku' => 'HH-RT-150',
        'quantity' => 1, 'unit_price' => 20000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    return $order->fresh('items');
}

function lifecycleAdmin(): void
{
    OrdersAdminRoutes::wire(app());
    test()->actingAs(AdminUser::create([
        'name' => 'LC Owner',
        'email' => 'lc-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
}

/* ================================================================
 | 1. Delivery is priced from the zones on every placement path
 |================================================================ */

it('prices delivery from the shipping zone on the public api path, not a flat setting', function () {
    lifecycleCod();
    $p = lifecycleProduct(20000);

    // Saudi Arabia: the Gulf zone charges 15000 fils. The flat setting the
    // endpoint used to read is 2000 — and defaulting to it means the shop
    // eats AED 130 of courier cost on every Gulf order it takes this way.
    $response = test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $p->slug, 'qty' => 1]],
        'customer' => ['name' => 'Aisha Khan', 'email' => 'buyer@example.com', 'country' => 'SA'],
        'method' => 'cod',
    ]);

    $response->assertCreated();

    $order = Order::latest('id')->first();

    expect((int) $order->shipping_total)->toBe(15000)
        ->and((int) $order->total)->toBe(35000);
});

it('gives the zone free-shipping threshold on the public api path', function () {
    lifecycleCod();
    $p = lifecycleProduct(20000);

    // 20000 fils is over the UAE zone's 19900 threshold, so delivery is free.
    // The flat path used `free_ship` default 20000 with a >= test, which agreed
    // here by luck; at 19900 exactly it would not have.
    test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $p->slug, 'qty' => 1]],
        'customer' => ['name' => 'Aisha Khan', 'email' => 'buyer@example.com', 'country' => 'AE'],
        'method' => 'cod',
    ])->assertCreated();

    $order = Order::latest('id')->first();

    expect((int) $order->shipping_total)->toBe(0)
        ->and((int) $order->total)->toBe(20000);
});

it('refuses an api order for a country no zone delivers to', function () {
    lifecycleCod();
    $p = lifecycleProduct(20000);

    // The storefront says "We do not deliver to that country yet." The API used
    // to take the order at the flat rate and leave someone to explain it.
    test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $p->slug, 'qty' => 1]],
        'customer' => ['name' => 'Aisha Khan', 'email' => 'buyer@example.com', 'country' => 'JP'],
        'method' => 'cod',
    ])->assertStatus(422);

    expect(Order::count())->toBe(0);
});

/* ================================================================
 | 2. A status change reaches the customer from either screen
 |================================================================ */

it('emails the customer when one order is marked shipped from the bulk list', function () {
    Mail::fake();
    lifecycleAdmin();

    $order = lifecycleOrder();

    test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => [$order->id],
        'status' => 'shipped',
    ])->assertOk();

    expect((string) $order->fresh()->status)->toBe('shipped');

    // RENDERED, not merely sent. The months this store sent nothing would have
    // passed an assertion that only counted mailables.
    $body = '';
    Mail::assertSent(OrderStatusChanged::class, function ($mail) use (&$body) {
        $body = (string) $mail->render();

        return true;
    });

    expect($body)->toContain($order->order_number)
        ->and($body)->toContain('Rice Toner');
});

it('emails every customer when a selection is marked shipped in bulk', function () {
    Mail::fake();
    lifecycleAdmin();

    $a = lifecycleOrder(['email' => 'one@example.com']);
    $b = lifecycleOrder(['email' => 'two@example.com']);

    test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => [$a->id, $b->id],
        'status' => 'shipped',
    ])->assertOk();

    Mail::assertSent(OrderStatusChanged::class, 2);

    foreach (['one@example.com', 'two@example.com'] as $to) {
        Mail::assertSent(OrderStatusChanged::class, fn ($mail) => $mail->hasTo($to));
    }
});

it('honours the cancelled switch on the bulk path too', function () {
    Mail::fake();
    lifecycleAdmin();
    app(SettingsService::class)->setModule('email_order_cancelled', false);

    $order = lifecycleOrder();

    test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => [$order->id],
        'status' => 'cancelled',
        'force' => true,
    ])->assertOk();

    expect((string) $order->fresh()->status)->toBe('cancelled');

    Mail::assertNotSent(OrderStatusChanged::class);
});

it('sends nothing for a bulk transition to a status with no wording', function () {
    Mail::fake();
    lifecycleAdmin();

    $order = lifecycleOrder(['status' => 'pending']);

    test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => [$order->id],
        'status' => 'processing',
    ])->assertOk();

    Mail::assertNotSent(OrderStatusChanged::class);
});

it('does not read the orders back for a status that emails nobody', function () {
    Mail::fake();
    lifecycleAdmin();

    $orders = collect(range(1, 3))->map(fn () => lifecycleOrder(['status' => 'pending']));

    // The guard in notifyStatus() is a COST guard — OrderMailer would refuse
    // `processing` anyway, so removing it breaks no assertion about email. This
    // is what it actually buys: a silent status does not select the selection
    // back out of the database and hydrate every line item to be told so.
    $selects = 0;
    DB::listen(function ($q) use (&$selects) {
        if (str_starts_with(strtolower(trim($q->sql)), 'select') && str_contains($q->sql, 'order_items')) {
            $selects++;
        }
    });

    test()->postJson('/admin-api/orders-bulk-status', [
        'ids' => $orders->pluck('id')->all(),
        'status' => 'processing',
    ])->assertOk();

    expect($selects)->toBe(0);
});

/* ================================================================
 | 3. Nothing fires twice
 |================================================================ */

it('does not email twice when the same bulk transition is repeated', function () {
    Mail::fake();
    lifecycleAdmin();

    $order = lifecycleOrder();

    foreach ([1, 2] as $_) {
        test()->postJson('/admin-api/orders-bulk-status', [
            'ids' => [$order->id],
            'status' => 'shipped',
        ])->assertOk();
    }

    // The second pass finds the order already shipped and skips it, so the
    // customer is told once that their parcel is on its way.
    Mail::assertSent(OrderStatusChanged::class, 1);
});

it('does not email when a save leaves the status untouched', function () {
    Mail::fake();

    $order = lifecycleOrder(['status' => 'shipped']);

    $order->forceFill(['phone' => '+971500000001'])->save();

    Mail::assertNotSent(OrderStatusChanged::class);
});

/* ================================================================
 | 4. Refunds say which kind of refund they are
 |================================================================ */

/** A settled refund on an order, the way PaymentRefunder leaves one. */
function lifecycleRefund(Order $order, int $amountFils): Refund
{
    $refund = $order->refunds()->create([
        'amount' => $amountFils,
        'reason' => 'Customer changed their mind',
        'refunded_by' => 'Admin',
        'status' => 'pending',
        'provider' => 'cod',
    ]);

    // pending → succeeded is the transition the observer watches, and it is
    // what PaymentRefunder::settle() writes once the money has actually gone.
    $refund->forceFill(['status' => 'succeeded'])->save();

    return $refund;
}

it('calls a genuinely partial refund partial', function () {
    Mail::fake();

    // 22000-fils order, 5000 back. The rest really is unaffected.
    $order = lifecycleOrder(['paid_at' => now(), 'captured_total' => 22000, 'captured_at' => now()]);

    lifecycleRefund($order, 5000);

    $body = '';
    Mail::assertSent(OrderRefunded::class, function ($mail) use (&$body) {
        $body = (string) $mail->render();

        return true;
    });

    expect($body)->toContain('This is a partial refund');
});

it('does not call the last instalment of a full refund partial', function () {
    Mail::fake();

    $order = lifecycleOrder(['paid_at' => now(), 'captured_total' => 22000, 'captured_at' => now()]);

    // Refunded in two halves, which is ordinary: a goodwill amount first, the
    // balance once the parcel is back. After the second one NOTHING is left
    // unrefunded — but isPartial compared this ONE refund's amount against the
    // order total, so the customer was told "the rest of the order is
    // unaffected" about an order that had just been refunded in full.
    lifecycleRefund($order, 11000);
    lifecycleRefund($order->fresh(), 11000);

    $bodies = [];
    Mail::assertSent(OrderRefunded::class, function ($mail) use (&$bodies) {
        $bodies[] = (string) $mail->render();

        return true;
    });

    expect($bodies)->toHaveCount(2)
        ->and($bodies[0])->toContain('This is a partial refund')
        ->and($bodies[1])->not->toContain('This is a partial refund');
});

it('sends one refund email per settled refund and none for a failed one', function () {
    Mail::fake();

    $order = lifecycleOrder(['paid_at' => now(), 'captured_total' => 22000, 'captured_at' => now()]);

    $refund = $order->refunds()->create([
        'amount' => 5000,
        'reason' => 'Test',
        'refunded_by' => 'Admin',
        'status' => 'pending',
        'provider' => 'cod',
    ]);

    // The gateway refused. Telling a customer their money is on its way when it
    // is not is worse than telling them nothing.
    $refund->forceFill(['status' => 'failed', 'failure_code' => 'card_declined'])->save();

    Mail::assertNotSent(OrderRefunded::class);

    // And a later save that does not touch status must not send either.
    $refund->forceFill(['status' => 'succeeded'])->save();
    Mail::assertSent(OrderRefunded::class, 1);

    $refund->forceFill(['provider_ref' => 'ref_123'])->save();
    Mail::assertSent(OrderRefunded::class, 1);
});

it('still calls a partial refund partial on a cash-on-delivery order', function () {
    Mail::fake();

    // No paid_at and no captured_total: nothing was ever taken through a
    // gateway, because the cash arrived at the door. PaymentRefunder's
    // capturedFils() answers 0 for such an order, and a ceiling of zero would
    // make every refund look like a full one.
    $order = lifecycleOrder();

    expect((int) $order->total)->toBe(22000)
        ->and(app(\App\Services\Payments\PaymentRefunder::class)->capturedFils($order))->toBe(0);

    lifecycleRefund($order, 5000);

    Mail::assertSent(OrderRefunded::class, function ($mail) {
        expect($mail->isPartial)->toBeTrue();

        return true;
    });
});

it('states the refund amount in fils, not the order total', function () {
    Mail::fake();

    $order = lifecycleOrder(['paid_at' => now(), 'captured_total' => 22000, 'captured_at' => now()]);

    lifecycleRefund($order, 5055);

    // 5055 fils is AED 50.55. At the storefront's display precision (whole
    // dirhams) this would round to AED 51 — fifty-five fils the customer was
    // never sent. Receipts render at the currency's real precision.
    Mail::assertSent(OrderRefunded::class, function ($mail) {
        expect($mail->amountFils)->toBe(5055)
            ->and((string) $mail->render())->toContain('50.55');

        return true;
    });
});
