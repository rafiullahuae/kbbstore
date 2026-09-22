<?php

/**
 * Cash on delivery, end to end.
 *
 * COD is the one method this shop can actually take money with today: it needs
 * no merchant account, no keys and no webhook, so it is the only one of the
 * four that works on a fresh install. That makes it the one worth tracing in
 * full rather than at the two ends — the fee and how it is taxed, the stock,
 * the status path, what the shopper is told, what the owner sees, what happens
 * when the courier comes back with the parcel, and how the money goes out
 * again.
 *
 * Nothing here touches the network, because COD has nothing to touch:
 * Http::preventStrayRequests() below turns "no HTTP" from a claim into an
 * assertion.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\PaymentRefunder;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\TaxRule;
use App\Support\VatDisplay;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();

    Http::preventStrayRequests();

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

    codSet('cod_fee', 0);
});

function codSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

/** The `cod` provider row, enabled. No credentials, because it has none. */
function codProvider(): PaymentProvider
{
    return PaymentProvider::create([
        'id' => 'cod',
        'title' => 'Cash on delivery',
        'enabled' => true,
        'mode' => 'test',
        'position' => 0,
    ]);
}

function codCart(int $unitPriceFils = 20000, ?int $stock = null): Cart
{
    $product = Product::create([
        'slug' => 'cod-' . Str::random(10),
        'name' => 'Test Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $unitPriceFils / 100,
        'stock_status' => 'instock',
        'manage_stock' => $stock !== null,
        'stock' => $stock,
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

function codPlace(Cart $cart, array $overrides = [])
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', array_merge([
            'billing_email' => 'buyer@example.com',
            'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ], $overrides));
}

/*
|------------------------------------------------------------------------------
| THE FEE
|------------------------------------------------------------------------------
*/

it('takes the cod fee from settings and never from the shopper', function () {
    codProvider();
    codSet('cod_fee', 1500);

    // The browser posts a fee of its own choosing. It is a price, so it is the
    // merchant's to set and the request has no say in it.
    $response = codPlace(codCart(20000), ['cod_fee' => '1', 'fee_total' => '1', 'total' => '1']);

    $order = Order::latest('id')->first();

    $response->assertRedirect();

    expect((int) $order->fee_total)->toBe(1500)
        ->and((int) $order->total)->toBe(20000 + 2000 + 1500);
});

it('keeps the cod fee in integer fils all the way to the order', function () {
    codProvider();

    // A fee that is a whole number of fils but not of dirhams -- 10.55 -- is
    // where a float in the money path shows up as an off-by-one.
    codSet('cod_fee', 1055);

    codPlace(codCart(20000));

    $order = Order::latest('id')->first();

    expect($order->fee_total)->toBeInt()->toBe(1055)
        ->and((int) $order->total)->toBe(23055);
});

/**
 * THE ANSWER ANOTHER LANE ESTABLISHED, CONFIRMED HERE RATHER THAN ASSUMED.
 *
 * The COD surcharge is NOT part of the taxable base. Store\CheckoutController
 * ::place() computes `tax_total` from the cart totals and then writes
 * `total => $totals['total'] + $fee`, so the fee is added AFTER the tax has
 * been worked out and no tax is charged on it. TaxEngineTest states the same
 * arithmetic from the engine's side ("200.00 + 20.00 delivery = 220.00
 * taxable, +5% = 11.00, +15.00 COD fee") and TaxEndToEndTest prints it as a
 * row below the VAT line on the invoice.
 *
 * This test exists so that the answer is pinned from the ORDER's side too. It
 * is a policy decision as much as an arithmetic one — whether a payment
 * handling charge is a taxable supply is the owner's question for his
 * accountant, not a detail of the code — and if it is ever answered the other
 * way, this is the test that must be changed deliberately rather than the
 * behaviour drifting.
 */
it('does not charge tax on the cod surcharge', function () {
    codProvider();
    codSet('tax_mode', VatDisplay::MODE_LIVE);
    codSet(VatDisplay::COUNTRY_RATES_KEY, json_encode(['AE' => '5']));
    codSet(VatDisplay::COUNTRY_BASES_KEY, json_encode(['AE' => TaxRule::EXCLUSIVE]));
    codSet('cod_fee', 1500);

    codPlace(codCart(20000));

    $order = Order::latest('id')->first();

    // Goods 200.00 + delivery 20.00 = 220.00 taxable. 5% of that is 11.00.
    expect((int) $order->tax_total)->toBe(1100)
        // NOT 5% of 235.00 (11.75), which is what taxing the surcharge would
        // give. One fil of difference here is a VAT return that does not
        // reconcile.
        ->and((int) $order->tax_total)->not->toBe(1175)
        ->and((int) $order->fee_total)->toBe(1500)
        // And the order adds up: 220.00 + 11.00 tax + 15.00 fee.
        ->and((int) $order->total)->toBe(22000 + 1100 + 1500);

    /*
     * The rate and basis are snapshotted beside the figure, so a change to the
     * Tax tab next year does not reprint this order at the new rate.
     *
     * Compared as a NUMBER, not as a string, and that is not fussiness.
     * `orders.tax_rate` is a DECIMAL column: SQLite hands it back as '5' and
     * MySQL as '5.000', so `toBe('5')` passes on the engine the suite usually
     * runs on and fails on the engine production actually uses. That is the
     * shape of defect this project has shipped before — green here, red there —
     * and it is worth a line of comment wherever a decimal column is asserted.
     */
    expect((float) $order->tax_rate)->toBe(5.0)
        ->and($order->tax_basis)->toBe(TaxRule::EXCLUSIVE);
});

/*
|------------------------------------------------------------------------------
| THE STOCK, AND WHEN IT IS TAKEN
|------------------------------------------------------------------------------
|
| ALREADY HELD. Store\CheckoutController::place() calls claimStock() inside the
| placing transaction and BEFORE PaymentGateway::start(), so the units are off
| the shelf under a row lock before any gateway is asked to do anything. For
| COD that ordering is easy to get wrong precisely because there is no gateway
| call to come after it.
*/

it('takes the units off the shelf as the cod order is written', function () {
    codProvider();

    $cart = codCart(20000, stock: 3);
    $productId = $cart->items->first()->product_id;

    codPlace($cart);

    expect((int) Product::find($productId)->stock)->toBe(2);
});

it('refuses a cod order for a line that has sold out, and places nothing', function () {
    codProvider();

    $cart = codCart(20000, stock: 0);
    $before = Order::count();

    $response = codPlace($cart);

    // Refused at the shelf, not at the payment step -- and no half-written
    // order left behind, because the claim is inside the transaction.
    $response->assertRedirect();

    expect(Order::count())->toBe($before);
});

/*
|------------------------------------------------------------------------------
| THE STATUS PATH
|------------------------------------------------------------------------------
*/

it('moves a cod order straight to processing and deliberately does not mark it paid', function () {
    codProvider();

    codPlace(codCart(20000));

    $order = Order::latest('id')->first();

    expect($order->status)->toBe('processing')
        // No money has moved. The courier has the parcel and the customer
        // still has the cash, so an order that said `paid` here would be
        // wrong in every report that reads the column.
        ->and($order->paid_at)->toBeNull()
        ->and($order->payment_method)->toBe('cod')
        // The merchant's own wording is snapshotted, so renaming the method
        // tomorrow does not rewrite today's orders.
        ->and($order->payment_method_title)->toBe('Cash on delivery');
});

it('records the collection when the courier hands the cash over, without any HTTP call', function () {
    codProvider();
    codPlace(codCart(20000));

    $order = Order::latest('id')->first();

    $result = app(PaymentCapturer::class)->capture($order, 'Aisha');

    expect($result->ok)->toBeTrue()
        ->and($result->code)->toBe('captured')
        ->and((int) $order->fresh()->captured_total)->toBe(22000)
        ->and($order->fresh()->captured_at)->not->toBeNull();

    // The order note says it in words the owner can read.
    expect((string) $order->notes()->latest('id')->first()?->content)->toContain('Captured');

    /*
     * COD has nobody to call, and preventStrayRequests() in beforeEach() is
     * what enforces it -- a stray request throws before this line is reached.
     *
     * This used to read `expect(true)->toBeTrue();`, which is an expectation
     * that cannot fail: it stated the conclusion instead of asking anything,
     * and it counted as coverage of the claim the test's own NAME makes. The
     * enforcing mechanism is asked directly instead, so this line goes red if
     * the fake is ever removed from beforeEach() and the claim stops being
     * enforced at all.
     */
    Http::assertNothingSent();
});

/**
 * A DECISION FOR THE OWNER, PINNED AS IT STANDS.
 *
 * Capturing a COD order records `captured_at` and `captured_total` and does
 * NOT set `paid_at`. That is deliberate and documented in CashOnDelivery's
 * class note — `paid_at` means "a provider confirmed this payment", and COD
 * has no provider.
 *
 * The consequence the owner should know about is that the invoice reads its
 * `paid` flag off `paid_at` (App\Services\Invoices\InvoiceDocument), so a COD
 * order whose cash the courier has already handed over still prints as unpaid.
 * Changing that is a one-line change here, but `paid_at` is read by the
 * revenue and customer-spend reports that other lanes own, so it is the
 * owner's call rather than this lane's. Pinned so the current answer is a
 * choice rather than an accident.
 */
it('leaves paid_at unset on a captured cod order, which is what the invoice reads', function () {
    codProvider();
    codPlace(codCart(20000));

    $order = Order::latest('id')->first();
    app(PaymentCapturer::class)->capture($order, 'Aisha');

    expect($order->fresh()->captured_at)->not->toBeNull()
        ->and($order->fresh()->paid_at)->toBeNull();
});

/*
|------------------------------------------------------------------------------
| WHAT THE SHOPPER IS TOLD, AND WHAT THE OWNER SEES
|------------------------------------------------------------------------------
*/

it('names the surcharge on the checkout radio rather than surprising the shopper with it', function () {
    codProvider();
    codSet('cod_fee', 1500);

    $offered = collect(app(GatewayRegistry::class)->checkoutList(22000, 'AE'))->firstWhere('id', 'cod');

    // The needle is built by the same formatter the row is, so this pins that
    // the shopper is shown THIS fee rather than pinning how Money::format
    // happens to render a round figure today.
    $formatted = Money::format(1500);

    expect($offered)->not->toBeNull()
        ->and($offered['description'])->toContain($formatted)
        ->and($offered['fee_html'])->toContain($formatted)
        // The figure itself, in fils, is the part that must be exact.
        ->and($offered['fee_fils'])->toBe(1500);

    // With no surcharge the sentence does not mention one.
    codSet('cod_fee', 0);
    app(GatewayCredentials::class)->forget();

    $free = collect(app(GatewayRegistry::class)->checkoutList(22000, 'AE'))->firstWhere('id', 'cod');

    expect($free['fee_fils'])->toBe(0)
        ->and($free['description'])->not->toContain('handling fee');
});

it('shows the owner a capture state with no window and nothing outstanding to lose', function () {
    codProvider();
    codPlace(codCart(20000));

    $order = Order::latest('id')->first();
    $state = app(PaymentCapturer::class)->status($order);

    expect($state['supported'])->toBeTrue()
        ->and($state['captured'])->toBeFalse()
        ->and($state['capturable'])->toBeTrue()
        // No authorisation is being held, so there is nothing to expire and
        // nothing to warn about. Every other gateway has a real deadline here.
        ->and($state['window_days'])->toBeNull()
        ->and($state['expiring'])->toBeFalse()
        ->and($state['window'])->toContain('No window');
});

it('is offered with no credentials at all, and has no webhook door', function () {
    codProvider();

    $cod = app(GatewayRegistry::class)->find('cod');

    expect($cod->configured())->toBeTrue()
        ->and($cod->configSchema())->toBe([])
        // The webhook controller 404s anything that is not a verifier, and COD
        // verifies nothing because it receives nothing.
        ->and($cod instanceof \App\Services\Payments\HandlesWebhooks)->toBeFalse();
});

/*
|------------------------------------------------------------------------------
| THE DELIVERY THAT FAILS
|------------------------------------------------------------------------------
*/

it('puts the units back and refuses the cash when a delivery fails', function () {
    codProvider();

    $cart = codCart(20000, stock: 3);
    $productId = $cart->items->first()->product_id;

    codPlace($cart);
    $order = Order::latest('id')->first();

    expect((int) Product::find($productId)->stock)->toBe(2);

    // The courier comes back with the parcel. The owner cancels the order.
    app(\App\Services\Orders\OrderStatus::class)->moveTo($order, 'cancelled', by: 'Aisha', reason: 'Customer refused delivery.');

    // The unit is back on the shelf...
    expect((int) Product::find($productId)->stock)->toBe(3);

    // ...and the cash can no longer be recorded as collected, which until this
    // lane it could: capture wrote `captured_total` on a cancelled order, and
    // `captured_total` is the ceiling a refund is measured against, so a
    // cancelled order that never saw a fil became refundable in full.
    $result = app(PaymentCapturer::class)->capture($order->fresh(), 'Aisha');

    expect($result->ok)->toBeFalse()
        ->and($result->code)->toBe('order_not_live')
        ->and((int) $order->fresh()->captured_total)->toBe(0)
        ->and((int) app(PaymentRefunder::class)->capturedFils($order->fresh()))->toBe(0);
});

/*
|------------------------------------------------------------------------------
| THE MONEY GOING BACK
|------------------------------------------------------------------------------
*/

it('records a cod refund as a ledger entry and says plainly that nothing was called', function () {
    codProvider();
    codPlace(codCart(20000));

    $order = Order::latest('id')->first();
    app(PaymentCapturer::class)->capture($order, 'Aisha');

    $outcome = app(PaymentRefunder::class)->refund($order->fresh(), 5000, 'One item damaged', 'Aisha', 'cod-' . uniqid());

    expect($outcome->ok)->toBeTrue()
        // Not a pretence that money moved. The green tick is the ledger entry
        // a manual bank transfer needs, and the words say so.
        ->and($outcome->code)->toBe('recorded_only')
        ->and((int) app(PaymentRefunder::class)->refundedFils($order->fresh()))->toBe(5000);

    $note = collect($order->fresh()->notes)->map(fn ($n) => (string) $n->content)->implode(' | ');

    expect($note)->toContain('by hand');
});

it('refuses to hand back more cash than the courier collected', function () {
    codProvider();
    codPlace(codCart(20000));

    $order = Order::latest('id')->first();
    app(PaymentCapturer::class)->capture($order, 'Aisha');

    // 220.00 was collected. 220.01 may not go back, and the ceiling comes from
    // the database rather than from anything the screen sent.
    $outcome = app(PaymentRefunder::class)->refund($order->fresh(), 22001, null, 'Aisha', 'over-' . uniqid());

    expect($outcome->ok)->toBeFalse()
        ->and($outcome->code)->toBe('over_captured')
        ->and((int) app(PaymentRefunder::class)->refundedFils($order->fresh()))->toBe(0);
});

it('marks the order refunded once the whole collected amount has gone back', function () {
    codProvider();
    codPlace(codCart(20000));

    $order = Order::latest('id')->first();
    app(PaymentCapturer::class)->capture($order, 'Aisha');

    app(PaymentRefunder::class)->refund($order->fresh(), 10000, 'part one', 'Aisha', 'p1-' . uniqid());

    // Part way is not refunded: the customer keeps the goods and the order
    // still shipped.
    expect((string) $order->fresh()->status)->toBe('processing');

    app(PaymentRefunder::class)->refund($order->fresh(), 12000, 'part two', 'Aisha', 'p2-' . uniqid());

    expect((string) $order->fresh()->status)->toBe('refunded')
        ->and((int) app(PaymentRefunder::class)->refundedFils($order->fresh()))->toBe(22000);
});
