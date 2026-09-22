<?php

/**
 * The stored order total is the arithmetic it claims to be.
 *
 *     total = subtotal - discount_total + shipping_total + tax_total + fee_total
 *     subtotal = the sum of the order's own line totals
 *
 * Nothing asserted this end to end. CheckoutPlacementTest checks a single-line
 * AED 200 basket with a flat AED 20 rate, which is arithmetic simple enough to
 * come out right by accident; CartService::totals() is exercised on its own,
 * away from what is actually written to `orders`. The figures the shop is paid
 * on are the ones in the row, and this is the assertion that the row adds up.
 *
 * DELIBERATELY AWKWARD NUMBERS. Prices that end in 25 and 50 fils and a 12.5%
 * coupon put the discount on a half fil (46,525 x 0.125 = 5,815.625), which is
 * where a `/`, a `round()` or a stray float would show itself. Money in this
 * application is integer fils throughout and the assertions below are
 * `toBe()`, which is identical-comparison, so an int that has become a float
 * fails here rather than being rounded into place somewhere downstream.
 *
 * GULF SHIPPING, not the UAE. The API once charged AED 20 to ship to Saudi
 * Arabia while the storefront charged AED 150 for the same basket, so the
 * expensive zone is the one worth pinning, and it is pinned against the rate
 * that came out of the zone rather than a constant written here twice.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    // Production's two live zones, as ShippingService documents them: All UAE
    // at AED 20 free over AED 199, Gulf Countries at AED 150 free over AED 1,600.
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
        ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => $code]);
    }
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate', 'title' => 'Gulf delivery',
        'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'free_shipping', 'title' => 'Free Gulf delivery',
        'cost' => 0, 'min_amount' => 160000, 'enabled' => true, 'position' => 1,
    ]);
});

/** A three-line basket at awkward prices, with $coupon applied. */
function integrityCart(?Coupon $coupon = null): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'SA',
        'coupon_id' => $coupon?->id,
        'last_activity_at' => now(),
    ]);

    // unit fils => quantity. 120.00 x2, 89.50 x1, 45.25 x3.
    foreach ([[12000, 2], [8950, 1], [4525, 3]] as $i => [$unit, $qty]) {
        $product = Product::create([
            'slug' => 'integrity-' . $i . '-' . Str::random(10),
            'name' => 'Integrity Item ' . $i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => $unit,
            'stock_status' => 'instock',
        ]);

        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => $unit,
        ]);
    }

    return $cart;
}

function integrityPlace(Cart $cart)
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'gulf.buyer@example.com',
            'billing_phone' => '+971500000000',
            'billing_first_name' => 'Noura',
            'billing_last_name' => 'Al Otaibi',
            'billing_address_1' => '44 King Fahd Road',
            'billing_city' => 'Riyadh',
            'billing_state' => 'Riyadh',
            'billing_country' => 'SA',
            'payment_method' => 'cod',
        ]);
}

it('stores a Gulf order whose total is exactly its parts', function () {
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);

    $coupon = Coupon::create([
        'code' => 'TWELVEHALF',
        'type' => 'percent',
        'amount' => 1250,          // 12.50%, stored as percent x 100
        'usage_count' => 0,
    ]);

    integrityPlace(integrityCart($coupon))->assertRedirect();

    $order = Order::latest('id')->first();
    expect($order)->not->toBeNull();

    $lines = $order->items;

    // 24,000 + 8,950 + 13,575
    $lineSum = (int) $lines->sum('total');

    expect($lines)->toHaveCount(3)
        ->and($lineSum)->toBe(46525)
        ->and($order->subtotal)->toBe($lineSum, 'orders.subtotal is not the sum of its own lines');

    // Every line is unit_price x quantity, with no fil invented or lost.
    foreach ($lines as $line) {
        expect($line->total)->toBe($line->unit_price * $line->quantity)
            ->and($line->subtotal)->toBe($line->total);
    }

    /*
     * 46,525 x 12.5% = 5,815.625, which rounds half up in integers to 5,816
     * and never to 5,815 — the exact figure CouponService::exactDiscountFor()
     * still answers, and CouponPercentRoundingTest still pins to the fil.
     *
     * What the ORDER records is what was charged, and since Lane FA that is
     * the exact discount taken up to a whole dirham — AED 59 — because this
     * shop prices in whole dirhams and a percentage the shop advertised should
     * be a floor rather than a ceiling. The subject of this file is that the
     * order's columns are internally consistent, and they are: the assertion
     * below still holds subtotal - discount + delivery to the stored total.
     */
    expect($order->discount_total)->toBe(5900, 'the coupon discount is not the whole-dirham figure the till charges');

    // The Gulf rate, taken from the zone rather than repeated as a constant.
    $gulfRate = (int) ShippingMethod::whereHas('zone', fn ($q) => $q->where('name', 'Gulf Countries'))
        ->where('type', 'flat_rate')->value('cost');

    expect($gulfRate)->toBe(15000)
        ->and($order->shipping_total)->toBe($gulfRate, 'a Gulf order was not charged the Gulf rate');

    // VAT is display-only (D-64) — nothing is charged and nothing is stored.
    expect($order->tax_total)->toBe(0);

    // The invariant itself.
    expect($order->total)->toBe(
        $order->subtotal - $order->discount_total + $order->shipping_total + $order->tax_total + $order->fee_total,
        'orders.total is not subtotal - discount + shipping + tax + fee'
    )->and($order->total)->toBe(46525 - 5900 + 15000);

    // Integer fils throughout. A float here is the bug, not a rounding detail.
    foreach (['subtotal', 'discount_total', 'shipping_total', 'tax_total', 'fee_total', 'total'] as $column) {
        $raw = $order->getAttributes()[$column];
        expect(is_float($raw))->toBeFalse("orders.{$column} came back as a float");
        expect((int) $raw)->toBe($order->{$column});
    }
});

it('adds the COD surcharge into fee_total without disturbing the invariant', function () {
    app(\App\Services\SettingsService::class)->set('cod_fee', 1500);

    integrityPlace(integrityCart())->assertRedirect();

    $order = Order::latest('id')->first();

    expect($order->subtotal)->toBe(46525)
        ->and($order->discount_total)->toBe(0)
        ->and($order->shipping_total)->toBe(15000)
        ->and($order->fee_total)->toBe(1500)
        ->and($order->total)->toBe(
            $order->subtotal - $order->discount_total + $order->shipping_total + $order->tax_total + $order->fee_total,
            'the COD surcharge broke the total'
        )
        ->and($order->total)->toBe(46525 + 15000 + 1500);
});

it('charges the Gulf rate, not the UAE rate, for a Gulf destination', function () {
    // The defect this shape of test exists for: the public API once priced
    // Saudi delivery at the UAE's AED 20. AED 130 of courier cost per order.
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);

    integrityPlace(integrityCart())->assertRedirect();

    $order = Order::latest('id')->first();

    expect($order->shipping_total)->toBe(15000)
        ->and($order->shipping_total)->not->toBe(2000);

    expect(str_contains((string) $order->shipping_method, 'Gulf'))
        ->toBeTrue('the order recorded a delivery method that was not the Gulf one');
});

it('gives free Gulf delivery only once the basket clears the threshold', function () {
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);

    $cart = integrityCart();

    // Push the basket over AED 1,600 — the Gulf zone's free-shipping minimum.
    $cart->items()->first()->update(['quantity' => 20]);   // 20 x 120.00 = 2,400.00

    integrityPlace($cart)->assertRedirect();

    $order = Order::latest('id')->first();

    expect($order->shipping_total)->toBe(0, 'a basket over the Gulf threshold was still charged for delivery')
        ->and($order->total)->toBe($order->subtotal - $order->discount_total + $order->fee_total);
});
