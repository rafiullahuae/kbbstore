<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use Illuminate\Support\Facades\Mail;
use Tests\ManualOrders;

/*
|------------------------------------------------------------------------------
| Creating an order on a customer's behalf
|------------------------------------------------------------------------------
|
| Every money assertion below is an exact integer in fils. Not "about", not a
| formatted string: the whole schema stores money as integer fils and a test
| that asserts "AED 218" would pass on 21,800 and on 21,799.
*/

beforeEach(function () {
    ManualOrders::registerRoutes();
    ManualOrders::shop();
    $this->admin = ManualOrders::admin();
});

function moPost(array $payload)
{
    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/manual-orders', $payload);
}

function moQuote(array $payload)
{
    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/manual-orders/quote', $payload);
}

/* ---------------------------------------------------------------- the basics */

it('creates an order for an existing customer, in exact fils', function () {
    $customer = ManualOrders::customer();
    $toner = ManualOrders::product('Heartleaf 77% Soothing Toner', 8900);   // AED 89
    $serum = ManualOrders::product('Glow Deep Serum', 7500);                // AED 75

    $response = moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $toner->id, 'quantity' => 2],
            ['product_id' => $serum->id, 'quantity' => 1],
        ],
    ]))->assertCreated();

    // 8900*2 + 7500 = 25300 fils, which clears the 19900 free-delivery
    // threshold, so delivery is 0 and the total is the subtotal.
    $response->assertJsonPath('order.subtotal_fils', 25300)
        ->assertJsonPath('order.discount_fils', 0)
        ->assertJsonPath('order.shipping_fils', 0)
        ->assertJsonPath('order.fee_fils', 0)
        ->assertJsonPath('order.total_fils', 25300)
        ->assertJsonPath('order.status', 'processing')
        ->assertJsonPath('order.origin', 'whatsapp');

    $order = Order::latest('id')->first();

    expect($order->subtotal)->toBe(25300)
        ->and($order->total)->toBe(25300)
        ->and($order->tax_total)->toBe(0)
        ->and($order->currency)->toBe('AED')
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->email)->toBe('layla@example.ae');

    expect($order->items)->toHaveCount(2);
    expect($order->items->firstWhere('name', 'Heartleaf 77% Soothing Toner'))
        ->quantity->toBe(2)
        ->unit_price->toBe(8900)
        ->subtotal->toBe(17800)
        ->total->toBe(17800);
});

it('charges delivery when the basket is under the free-shipping threshold', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Sheet mask', 1500);   // AED 15

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 2]],
    ]))->assertCreated()
        ->assertJsonPath('order.subtotal_fils', 3000)
        // The zone's flat rate: AED 20.
        ->assertJsonPath('order.shipping_fils', 2000)
        ->assertJsonPath('order.total_fils', 5000)
        ->assertJsonPath('order.shipping_method', 'Flat rate');
});

it('adds the cash-on-delivery fee exactly as the checkout does', function () {
    ManualOrders::setting('cod_fee', 500);   // AED 5

    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Cleanser', 4900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'payment_method' => 'cod',
    ]))->assertCreated()
        ->assertJsonPath('order.subtotal_fils', 4900)
        ->assertJsonPath('order.shipping_fils', 2000)
        ->assertJsonPath('order.fee_fils', 500)
        ->assertJsonPath('order.total_fils', 7400);
});

it('does not add the cash-on-delivery fee to a card order', function () {
    ManualOrders::setting('cod_fee', 500);

    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Cleanser', 4900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'payment_method' => 'stripe',
    ]))->assertCreated()
        ->assertJsonPath('order.fee_fils', 0)
        ->assertJsonPath('order.total_fils', 6900)
        ->assertJsonPath('order.payment_method', 'stripe')
        ->assertJsonPath('order.payment_method_title', 'Card');
});

/* ---------------------------------------------------- the new-customer path */

it('creates the customer record when one is entered inline', function () {
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'new_customer' => [
            'name' => 'Noura Al Suwaidi',
            'email' => 'Noura@Example.AE',
            'phone' => '+971509999999',
        ],
        'items' => [['product_id' => $item->id, 'quantity' => 3]],
    ]))->assertCreated();

    $customer = Customer::firstWhere('email', 'noura@example.ae');

    expect($customer)->not->toBeNull()
        ->and($customer->first_name)->toBe('Noura')
        ->and($customer->last_name)->toBe('Al Suwaidi')
        ->and($customer->phone)->toBe('+971509999999');

    // The typed address is saved to a brand-new customer's book, so the next
    // manual order for them prefills instead of being retyped.
    expect(Address::where('customer_id', $customer->id)->count())->toBe(2);

    $order = Order::latest('id')->first();
    expect($order->customer_id)->toBe($customer->id)
        ->and($order->email)->toBe('noura@example.ae');
});

it('attaches to the existing record when the typed email already belongs to a customer', function () {
    // A shopper who once ordered on the website and now orders on WhatsApp
    // must not end up with two records, or their history splits in half.
    $existing = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'new_customer' => ['name' => 'Layla A M', 'email' => 'layla@example.ae'],
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
    ]))->assertCreated();

    expect(Customer::where('email', 'layla@example.ae')->count())->toBe(1);
    expect(Order::latest('id')->first()->customer_id)->toBe($existing->id);
});

it('refuses an order with neither a customer nor the details to make one', function () {
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
    ]))->assertStatus(422)
        ->assertJsonValidationErrors(['customer_id', 'new_customer']);
});

/* -------------------------------------------------------------- the coupons */

it('discounts through CouponService, not through arithmetic of its own', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);
    // percent coupons store the percentage x 100, so 1000 is 10%.
    ManualOrders::coupon('WELCOME10', 'percent', 1000);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 3]],
        'coupon_code' => 'WELCOME10',
    ]))->assertCreated()
        /*
         * 26700 subtotal; 10% of it is 2670 fils exactly — AED 26.70. Since
         * Lane FA the shop charges whole dirhams, so CouponService rounds that
         * UP to AED 27, in the shopper's favour. The point of this test is
         * unchanged and is the delegation: the manual order takes whatever
         * CouponService says rather than working a discount out itself, which
         * is exactly why its figure moved when the service's policy did.
         */
        ->assertJsonPath('order.subtotal_fils', 26700)
        ->assertJsonPath('order.discount_fils', 2700)
        ->assertJsonPath('order.coupon_code', 'WELCOME10')
        // 26700 - 2700 = 24000, still over the 19900 threshold.
        ->assertJsonPath('order.shipping_fils', 0)
        ->assertJsonPath('order.total_fils', 24000);
});

it('rounds a percentage discount the way the storefront rounds it', function () {
    /*
     * 8900 x 3 = 26700; 12.5% of that is 3337.5 fils exactly. CouponService
     * rounds half up in integers to 3338 — not 3337 from truncation, and not a
     * float anywhere — and then takes that up to the whole dirham this shop
     * prices in, AED 34. The name of this test is still the whole of it: the
     * manual order rounds the way the storefront rounds, whatever that is, and
     * these two figures move together or the screens disagree.
     */
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);
    ManualOrders::coupon('HALFPC', 'percent', 1250);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 3]],
        'coupon_code' => 'HALFPC',
    ]))->assertCreated()
        ->assertJsonPath('order.discount_fils', 3400)
        ->assertJsonPath('order.total_fils', 23300);
});

it('refuses an expired coupon in the storefront’s own words', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);
    ManualOrders::coupon('OLDCODE', 'percent', 1000, ['expires_at' => now()->subDay()]);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'coupon_code' => 'OLDCODE',
    ]))->assertStatus(422)
        ->assertJsonPath('error', 'That code has expired.');

    expect(Order::count())->toBe(0);
});

it('refuses a coupon that does not exist', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'coupon_code' => 'NOPE',
    ]))->assertStatus(422);

    expect(Order::count())->toBe(0);
});

/* ----------------------------------------------------- the shipping override */

it('takes the operator’s delivery charge digit by digit', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        /*
         * The value that breaks the naive conversion — (int)(1.15*100) is 114
         * — is now refused outright, because a delivery charge is money the
         * operator types and this shop prices in whole dirhams (Lane FA). Both
         * halves are asserted: the fils value is refused, and a whole one is
         * parsed digit by digit at a magnitude where a float would show.
         */
        'shipping_override' => '1.15',
    ]))->assertStatus(422);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'shipping_override' => '1150',
    ]))->assertCreated()
        ->assertJsonPath('order.shipping_fils', 115000)
        ->assertJsonPath('order.total_fils', 123900);
});

it('accepts a typed delivery charge of zero as a real answer, not a blank', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'shipping_override' => '0',
    ]))->assertCreated()
        ->assertJsonPath('order.shipping_fils', 0)
        ->assertJsonPath('order.total_fils', 8900);
});

it('refuses a delivery charge it cannot hold exactly', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'shipping_override' => '1.234',
    ]))->assertStatus(422)
        ->assertJsonValidationErrors('shipping_override');
});

/* ---------------------------------------------------------- the vocabularies */

it('accepts only the order statuses this schema actually uses', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    // The class of mistake this pins: AdminController::updateProduct validated
    // products.status as in:active,draft,archived when the column's vocabulary
    // is publish|draft|private, and a save through it hid the product from the
    // whole storefront. orders.status is a free-form string, so the check has
    // to be against the vocabulary the console actually uses.
    //
    // 'refunded' and 'failed' are refused here on purpose and not by oversight:
    // PaymentRefunder writes the first when money actually moved and a gateway
    // decides the second, so neither is a thing an operator types. 'draft' is
    // refused because this screen places orders.
    foreach (['active', 'archived', 'draft', 'wc-shipped', 'paid', 'refunded', 'failed', ''] as $invented) {
        moPost(ManualOrders::payload([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $item->id, 'quantity' => 1]],
            'status' => $invented,
        ]))->assertStatus(422);
    }

    foreach (['pending', 'processing', 'onhold', 'shipped', 'completed', 'cancelled'] as $real) {
        moPost(ManualOrders::payload([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $item->id, 'quantity' => 1]],
            'status' => $real,
        ]))->assertCreated()->assertJsonPath('order.status', $real);
    }
});

it('accepts only payment methods that exist in payment_providers', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    foreach (['paypal', 'bank', 'cash', ''] as $invented) {
        moPost(ManualOrders::payload([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $item->id, 'quantity' => 1]],
            'payment_method' => $invented,
        ]))->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }
});

it('offers a disabled provider too, because staff still take those orders', function () {
    \App\Models\PaymentProvider::whereKey('stripe')->update(['enabled' => false]);

    $this->actingAs($this->admin, 'admin')
        ->getJson('/admin-api/manual-orders/bootstrap')
        ->assertOk()
        ->assertJsonPath('payment_methods.1.id', 'stripe')
        ->assertJsonPath('payment_methods.1.enabled', false);
});

it('refuses a country nothing delivers to', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'address' => ['country' => 'JP'],
    ]))->assertStatus(422)
        ->assertJsonPath('error', 'We do not deliver to that country yet.');
});

/* ------------------------------------------------------------ the guarantees */

it('never lets a client-sent price reach the order', function () {
    // Rule 23 in this codebase: the form carries choices, never amounts. The
    // endpoint takes no price field at all, so a smuggled one is ignored
    // rather than trusted.
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [[
            'product_id' => $item->id,
            'quantity' => 1,
            'unit_price' => 1,
            'price' => 1,
            'total' => 1,
        ]],
        'subtotal' => 1,
        'total' => 1,
    ]))->assertCreated()
        ->assertJsonPath('order.subtotal_fils', 8900);

    expect(Order::latest('id')->first()->total)->toBe(10900);
});

it('honours a sale price without being told about it', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900, ['sale_price' => 6900]);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
    ]))->assertCreated()
        ->assertJsonPath('order.subtotal_fils', 6900);

    expect(OrderItem::latest('id')->first()->unit_price)->toBe(6900);
});

it('applies the quantity-bundle tier, because CartService is what prices a line', function () {
    // Not a rule reimplemented here: CartService::add() calls unitPriceFor(),
    // which calls BundleService. Turning the module on and watching the unit
    // price move is what proves the manual order goes through that path rather
    // than multiplying price by quantity itself.
    ManualOrders::setting('bundles_enabled', true);

    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    /*
     * The default tiers are 5% at two units, 10% at three. 8900 x 0.95 is 8455
     * fils exactly — AED 84.55 — and since Lane FA a bundle unit is charged at
     * the whole dirham below it, AED 84, so two of them is AED 168. The line
     * total is still exactly `unit x qty`, which is why the rounding is
     * applied to the unit; see BundleService::unitFor().
     *
     * The subject of this test is unchanged: the manual order goes through
     * BundleService rather than multiplying price by quantity itself, and the
     * unit price moving when the module is turned on is what proves it.
     */
    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 2]],
    ]))->assertCreated()
        ->assertJsonPath('order.subtotal_fils', 16800)
        ->assertJsonPath('order.items.0.unit_price_fils', 8400)
        ->assertJsonPath('order.items.0.line_total_fils', 16800);
});

it('ignores a sale price whose window has not opened', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900, [
        'sale_price' => 6900,
        'sale_starts_at' => now()->addWeek(),
    ]);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
    ]))->assertCreated()
        ->assertJsonPath('order.subtotal_fils', 8900);
});

it('snapshots the line so the order still reads after the product is deleted', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Heartleaf Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
    ]))->assertCreated();

    $line = OrderItem::latest('id')->first();

    expect($line->name)->toBe('Heartleaf Toner')
        ->and($line->sku)->toBe($item->sku)
        ->and($line->brand)->toBe('K-Beauty Bliss');

    $item->forceDelete();

    expect(OrderItem::find($line->id)->name)->toBe('Heartleaf Toner');
});

it('gives each order a unique, non-colliding order number', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    // An imported WooCommerce order already sitting on the number the next
    // one would otherwise take.
    Order::create([
        'order_number' => '10002',
        'email' => 'imported@example.ae',
        'status' => 'completed',
        'total' => 100,
    ]);

    $numbers = [];

    for ($i = 0; $i < 3; $i++) {
        $numbers[] = moPost(ManualOrders::payload([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $item->id, 'quantity' => 1]],
        ]))->assertCreated()->json('order.order_number');
    }

    expect($numbers)->toHaveCount(3)
        ->and(array_unique($numbers))->toHaveCount(3)
        ->and($numbers)->not->toContain('10002');
});

it('records who created it, and the two things that did not happen', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'channel' => 'instagram',
    ]))->assertCreated();

    $note = Order::latest('id')->first()->notes()->first();

    expect($note->author)->toBe('Owner')
        ->and($note->is_customer_note)->toBeFalse()
        ->and($note->content)->toContain('instagram')
        ->and($note->content)->toContain('No confirmation email requested')
        ->and($note->content)->toContain('Stock was not adjusted');
});

it('leaves stock alone, exactly as a website order does', function () {
    // Not an oversight: Store\CheckoutController::place() moves no stock
    // either. Doing it here only would make a back-office order and a web
    // order mean different things to the inventory count.
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);
    $before = $item->stock;

    $response = moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 4]],
    ]))->assertCreated();

    expect(Product::find($item->id)->stock)->toBe($before);

    // And the response says so, rather than leaving the packer to find out.
    $response->assertJsonPath('stock.adjusted', false);
    expect($response->json('stock.reason'))->toContain('does not decrement it either');
});

it('marks the draft cart converted instead of leaving an active one behind', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
    ]))->assertCreated();

    expect(Cart::where('status', 'active')->count())->toBe(0)
        ->and(Cart::where('status', 'converted')->count())->toBe(1);
});

/* --------------------------------------------------------------- the quote */

it('prices a basket without writing anything', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moQuote(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 2]],
    ]))->assertOk()
        ->assertJsonPath('totals.subtotal_fils', 17800)
        ->assertJsonPath('totals.shipping_fils', 2000)
        ->assertJsonPath('totals.total_fils', 19800);

    // Nothing persisted: no order, no cart, no line.
    expect(Order::count())->toBe(0)
        ->and(Cart::count())->toBe(0)
        ->and(\App\Models\CartItem::count())->toBe(0);
});

it('quotes the same numbers the create call then writes', function () {
    // A quote the operator is shown and a total the customer is charged that
    // differ is the worst thing this screen could do, so the two are compared
    // directly rather than each being checked against a hand-written figure.
    ManualOrders::setting('cod_fee', 500);

    $customer = ManualOrders::customer();
    $a = ManualOrders::product('Toner', 8900);
    $b = ManualOrders::product('Serum', 7350, ['sale_price' => 6125]);
    ManualOrders::coupon('SAVE7', 'percent', 700);

    $payload = ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [
            ['product_id' => $a->id, 'quantity' => 2],
            ['product_id' => $b->id, 'quantity' => 3],
        ],
        'coupon_code' => 'SAVE7',
        'payment_method' => 'cod',
        // Whole dirhams — Lane FA. The subject here is that the quote and the
        // created order agree, not what a delivery charge may hold.
        'shipping_override' => '1785',
    ]);

    $quoted = moQuote($payload)->assertOk()->json('totals');
    $created = moPost($payload)->assertCreated()->json('order');

    expect($created['subtotal_fils'])->toBe($quoted['subtotal_fils'])
        ->and($created['discount_fils'])->toBe($quoted['discount_fils'])
        ->and($created['shipping_fils'])->toBe($quoted['shipping_fils'])
        ->and($created['fee_fils'])->toBe($quoted['fee_fils'])
        ->and($created['total_fils'])->toBe($quoted['total_fils']);

    /*
     * And the numbers themselves, so this cannot pass by both being wrong.
     * 8900*2 + 6125*3 = 17800 + 18375 = 36175. 7% of that is 2532.25, which
     * rounds half up in integers to 2532 and then UP to the whole dirham the
     * shop charges, AED 26 (Lane FA). Delivery is the operator's AED 1,785 and
     * the COD fee is AED 5.
     *
     * 36175 - 2600 = 33575, + 178500 delivery + 500 COD = 212575.
     */
    expect($created['subtotal_fils'])->toBe(36175)
        ->and($created['discount_fils'])->toBe(2600)
        ->and($created['shipping_fils'])->toBe(178500)
        ->and($created['fee_fils'])->toBe(500)
        ->and($created['total_fils'])->toBe(212575);
});

/* ----------------------------------------------------- the email decision */

it('defaults the confirmation email to off and never sends one silently', function () {
    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    $response = moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
    ]))->assertCreated();

    $response->assertJsonPath('email.requested', false)
        ->assertJsonPath('email.sent', false);
});

it('sends the confirmation when the operator asks for it, and reports the outcome', function () {
    // There is a real order-confirmation email now (Lane AG), and the website
    // checkout fires one on placement. So the box does something — but only
    // when it is ticked.
    Mail::fake();

    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    $response = moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
        'send_confirmation' => true,
    ]))->assertCreated();

    $response->assertJsonPath('email.requested', true)
        ->assertJsonPath('email.sent', true);

    Mail::assertSent(OrderConfirmation::class, fn ($mail) => $mail->hasTo('layla@example.ae'));

    // resendConfirmation(), not placed(): placed() also fires the merchant
    // alert, and a "new order" landing in the owner's inbox for an order the
    // owner just keyed in by hand is a lie about what happened.
    Mail::assertNotSent(NewOrderAlert::class);

    // The operator's intent is on the order either way, for whoever picks it up.
    expect(Order::latest('id')->first()->notes()->first()->content)
        ->toContain('Operator asked for a confirmation email');
});

it('sends nothing at all when the box is left alone', function () {
    Mail::fake();

    $customer = ManualOrders::customer();
    $item = ManualOrders::product('Toner', 8900);

    moPost(ManualOrders::payload([
        'customer_id' => $customer->id,
        'items' => [['product_id' => $item->id, 'quantity' => 1]],
    ]))->assertCreated();

    // Not "no confirmation": nothing. An order created here must not quietly
    // become an email the operator did not ask for.
    Mail::assertNothingSent();
});

it('tells the form whether the confirmation email is switched on at all', function () {
    $this->actingAs($this->admin, 'admin')
        ->getJson('/admin-api/manual-orders/bootstrap')
        ->assertOk()
        ->assertJsonPath('email.available', true);

    // Turned off in Store -> Modules -> Order emails, the checkbox is rendered
    // disabled with the reason beside it rather than being a control that
    // would be quietly overruled.
    app(\App\Services\SettingsService::class)->setModule('email_order_confirmation', false);

    $body = $this->actingAs($this->admin, 'admin')
        ->getJson('/admin-api/manual-orders/bootstrap')
        ->assertOk();

    $body->assertJsonPath('email.available', false);
    expect($body->json('email.reason'))->toContain('switched off');
});
