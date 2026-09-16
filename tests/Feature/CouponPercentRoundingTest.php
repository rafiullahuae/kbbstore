<?php

/**
 * A percentage coupon is computed in integers, and lands on the exact fil.
 *
 * THE DEFECT THIS PINS. CouponService::discountFor() computed a percentage as:
 *
 *     (int) round($eligibleSubtotal * ($coupon->amount / 10000))
 *
 * `$coupon->amount / 10000` is floating-point division, and most percentages
 * are not representable in binary. 35% becomes 0.34999999999999997779…, so a
 * discount whose exact value lands on a half fil falls the wrong side of
 * round():
 *
 *     35% of 20,490 fils -> exact 7,171.5, rounds to 7,172
 *                           float 7,171.499999…, rounds to 7,171
 *
 * Always downward, so the shopper is short-changed by a fil and the stored
 * discount disagrees with anything that recomputes the same coupon exactly.
 * Across 0–2,000 AED there are 2,340 such baskets at 35% and 1,170 at 17.5%.
 *
 * THIS IS A DEFECT THE REPO HAS ALREADY PAID FOR ONCE. BundleService::unitFor()
 * carries a long note about the identical mistake — "floating point made it ONE
 * FIL WRONG on ordinary, whole-number tiers" — and was rewritten to integer
 * arithmetic with a `+ 5000` half-up bias before an `intdiv` by 10,000.
 * discountFor() is the same sum, on the same scale, and was still doing it in
 * floats. It now uses the same idiom, so the two agree by construction.
 *
 * The percentages that happen to be exact in binary (10%, 12.5%, 20%, 25%, 50%)
 * were never wrong, which is why this survived: every round number anyone would
 * test with is in that set.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Services\CouponService;
use Illuminate\Support\Str;

/** A cart whose eligible subtotal is exactly $subtotalFils. */
function roundingCart(int $unitFils, int $qty): Cart
{
    $product = Product::create([
        'slug' => 'rounding-' . Str::random(12),
        'name' => 'Rounding Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $unitFils,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $product->id,
        'quantity' => $qty,
        'unit_price' => $unitFils,
    ]);

    return $cart->fresh(['items.product']);
}

it('rounds a half-fil percentage discount up, not down', function () {
    // 2 x AED 102.45 = AED 204.90. 35% of that is exactly AED 71.715.
    $cart = roundingCart(10245, 2);

    $coupon = Coupon::create([
        'code' => 'THIRTYFIVE',
        'type' => 'percent',
        'amount' => 3500,          // 35.00%, stored as percent x 100
        'usage_count' => 0,
    ]);

    expect((int) $cart->items->sum(fn ($i) => $i->lineTotal()))->toBe(20490);

    expect(app(CouponService::class)->discountFor($coupon, $cart))
        ->toBe(7172, 'the percentage discount lost a fil to floating point');
});

it('agrees with exact integer arithmetic across every percentage and basket', function () {
    // The property, rather than one example: for every percentage the admin's
    // editor can store (hundredths of a percent) the discount must equal the
    // exact rational value rounded half up. Checked directly against the
    // arithmetic rather than against a second copy of the implementation.
    $service = app(CouponService::class);

    $mismatches = [];

    foreach ([1000, 1750, 2000, 3300, 3500, 4000, 6667] as $amount) {
        foreach ([90, 180, 20490, 20510, 33333, 45671, 129999] as $subtotal) {
            $cart = roundingCart($subtotal, 1);

            $coupon = Coupon::create([
                'code' => 'P' . $amount . 'S' . $subtotal,
                'type' => 'percent',
                'amount' => $amount,
                'usage_count' => 0,
            ]);

            $got = $service->discountFor($coupon, $cart);
            $want = intdiv($subtotal * $amount + 5000, 10000);

            if ($got !== $want) {
                $mismatches[] = sprintf('%s%% of %d fils: got %d, exact %d', $amount / 100, $subtotal, $got, $want);
            }
        }
    }

    expect($mismatches)->toBe([], 'percentage discounts disagreed with exact integer arithmetic');
});

it('still caps the discount at the eligible subtotal', function () {
    // 100% off must take the basket to zero and no further — the cap is what
    // stops a percentage coupon inventing money, and it must survive the
    // change of arithmetic.
    $cart = roundingCart(12345, 1);

    $coupon = Coupon::create([
        'code' => 'ALLOFIT',
        'type' => 'percent',
        'amount' => 10000,
        'usage_count' => 0,
    ]);

    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(12345);
});

it('leaves the exactly-representable percentages exactly where they were', function () {
    // 10%, 12.5%, 20%, 25% and 50% were never wrong, and this change must not
    // move them by a fil in either direction.
    $cart = roundingCart(20490, 1);

    foreach ([1000 => 2049, 1250 => 2561, 2000 => 4098, 2500 => 5123, 5000 => 10245] as $amount => $expected) {
        $coupon = Coupon::create([
            'code' => 'EXACT' . $amount,
            'type' => 'percent',
            'amount' => $amount,
            'usage_count' => 0,
        ]);

        expect(app(CouponService::class)->discountFor($coupon, $cart))
            ->toBe($expected, "the {$amount} hundredths-of-a-percent coupon moved");
    }
});
