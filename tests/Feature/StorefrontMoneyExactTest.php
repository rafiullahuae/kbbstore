<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\BundleService;
use App\Services\SettingsService;

/**
 * Money is integer fils (AED x 100) end to end, and this is where that is proved.
 *
 * BundleService::unitFor() was the one place in storefront code doing FLOAT
 * arithmetic on a price:
 *
 *     (int) round($unitPrice * (1 - $discount / 100))
 *
 * It looks harmless, and it is not. A percentage divided by 100 is not
 * representable in binary, so `1 - 30/100` is 0.69999999999999996 rather than
 * 0.7, and any price whose exact discounted value lands on a half fil falls the
 * wrong side of round(): 45 fils at 30% off is exactly 31.5 and must round to
 * 32, but the float is 31.499999999999996 and rounds to 31.
 *
 * This is not an edge case reachable only with a strange tier. At a plain 30%
 * tier it is wrong for 1,170 of the first 50,000 prices; at 34%, for 1,000 of
 * them. totalFor() multiplies the unit by the quantity, so the error multiplies
 * too, and the bundle price shown on the product page then disagrees with any
 * other code that works the same discount out exactly.
 *
 * The tests below are written against EXACT expected fils, computed by hand or
 * by integer arithmetic — never by repeating the implementation's own sum,
 * which would pass just as happily with the float back in place.
 *
 * ── WHY THESE CALL exactUnitFor() — Lane FA ─────────────────────────────────
 *
 * The whole-dirham policy rounds the CHARGED unit price down to a whole dirham
 * (BundleService::unitFor, and the direction is argued there). A fil of float
 * drift is invisible once the answer has been rounded to a dirham — 31 and 32
 * fils are both AED 0 — so testing the public method would leave this file
 * passing over the exact defect it was written for.
 *
 * So the sum itself is now a method of its own, exactUnitFor(), and these
 * assertions hold THAT to exact integer arithmetic, unchanged to the fil. What
 * is being tested has not moved; only the name of the seam it is tested
 * through. The last test in the file pins the other half: that the charged
 * price is the exact one taken down to a whole dirham, and that the line total
 * is still an exact multiple of it.
 */

/** Install bundle tiers and make the settings layer see them immediately. */
function moneyTiers(array $tiers): BundleService
{
    Setting::updateOrCreate(
        ['key' => 'bundle_tiers'],
        ['value' => json_encode($tiers), 'autoload' => true]
    );
    Setting::updateOrCreate(['key' => 'bundles_enabled'], ['value' => '1', 'autoload' => true]);

    // Setting::map() and SettingsService both memoise; clear both or the
    // service keeps answering from before this write. (CLAUDE.md)
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    return app(BundleService::class);
}

it('prices a whole-number discount tier to the exact fil', function () {
    $bundles = moneyTiers([
        ['qty' => 1, 'discount' => 0, 'label' => '1 unit'],
        ['qty' => 3, 'discount' => 30, 'label' => '3 units'],
    ]);

    /*
     * The case the float got wrong. 45 fils at 30% off is 45 * 70 / 100 = 31.5
     * exactly, and a half fil rounds up, so 32. The float said 31.
     */
    expect($bundles->exactUnitFor(45, 3))->toBe(32);

    // A few more half-fil landings at the same tier, all computed by hand:
    //   115 * 70 / 100 = 80.5  -> 81
    //   145 * 70 / 100 = 101.5 -> 102
    //   245 * 70 / 100 = 171.5 -> 172
    expect($bundles->exactUnitFor(115, 3))->toBe(81)
        ->and($bundles->exactUnitFor(145, 3))->toBe(102)
        ->and($bundles->exactUnitFor(245, 3))->toBe(172);

    // And an exact, unambiguous one, so the rounding rule is not the only
    // thing being tested: AED 100.00 less 30% is AED 70.00.
    expect($bundles->exactUnitFor(10000, 3))->toBe(7000);
});

it('agrees with exact integer arithmetic across the whole price range', function () {
    $bundles = moneyTiers([
        ['qty' => 1, 'discount' => 0, 'label' => '1'],
        ['qty' => 2, 'discount' => 30, 'label' => '2'],
    ]);

    /*
     * The independent reference: the same discount worked out in integers and
     * rounded half up, with no float anywhere. Every price from 1 fil to
     * AED 200 must match it exactly.
     *
     * This is the assertion the float implementation fails — 1,170 times.
     */
    $wrong = [];

    for ($fils = 1; $fils <= 20000; $fils++) {
        $expected = intdiv($fils * 70 + 50, 100);
        $actual = $bundles->exactUnitFor($fils, 2);

        if ($actual !== $expected) {
            $wrong[] = "{$fils} fils: got {$actual}, exact {$expected}";
        }
    }

    expect($wrong)->toBe([], 'Bundle unit price differs from exact integer arithmetic at ' . count($wrong) . ' prices, e.g. ' . implode('; ', array_slice($wrong, 0, 5)));
});

it('prices a fractional discount tier to the exact fil', function () {
    $bundles = moneyTiers([
        ['qty' => 1, 'discount' => 0, 'label' => '1'],
        ['qty' => 2, 'discount' => 12.5, 'label' => '2'],
    ]);

    // AED 100.00 less 12.5% is AED 87.50 — 8750 fils, exactly.
    expect($bundles->exactUnitFor(10000, 2))->toBe(8750);

    // 45 * 87.5 / 100 = 39.375 -> 39 (below the half, rounds down).
    // 44 * 87.5 / 100 = 38.5   -> 39 (on the half, rounds up).
    expect($bundles->exactUnitFor(45, 2))->toBe(39)
        ->and($bundles->exactUnitFor(44, 2))->toBe(39);

    // 12 * 87.5 / 100 = 10.5 -> 11
    expect($bundles->exactUnitFor(12, 2))->toBe(11);
});

it('keeps the line total an exact multiple of the discounted unit', function () {
    $bundles = moneyTiers([
        ['qty' => 1, 'discount' => 0, 'label' => '1'],
        ['qty' => 3, 'discount' => 30, 'label' => '3'],
    ]);

    /*
     * THE EXACT UNIT is 32 fils (see above), so three of them is 96 — and the
     * error the float made would have been multiplied by three here, giving 93.
     *
     * THE CHARGED UNIT is that taken down to a whole dirham, which on a 45-fil
     * product is AED 0. The invariant this test is named for survives the
     * policy and is the reason the rounding is applied to the UNIT rather than
     * to the line: `totalFor` is still exactly `unitFor x qty`, so a shopper
     * can multiply the printed unit price by the quantity and get the printed
     * line total, at every quantity.
     */
    expect($bundles->exactUnitFor(45, 3))->toBe(32)
        ->and($bundles->unitFor(45, 3))->toBe(0)
        ->and($bundles->totalFor(45, 3))->toBe(0);

    // And on a price big enough for the rounding to leave something: AED 199
    // at 30% off is AED 139.30 exactly, charged at AED 139, and two of them
    // are AED 278 — a whole-dirham line, from a whole-dirham unit.
    expect($bundles->exactUnitFor(19900, 3))->toBe(13930)
        ->and($bundles->unitFor(19900, 3))->toBe(13900)
        ->and($bundles->totalFor(19900, 3))->toBe(41700)
        ->and($bundles->totalFor(19900, 3))->toBe($bundles->unitFor(19900, 3) * 3);
});

it('returns whole integers, never floats, from every money method', function () {
    $bundles = moneyTiers([
        ['qty' => 1, 'discount' => 0, 'label' => '1'],
        ['qty' => 2, 'discount' => 33.33, 'label' => '2'],
        ['qty' => 6, 'discount' => 45, 'label' => '6'],
    ]);

    foreach ([1, 2, 3, 6, 12] as $qty) {
        foreach ([1, 45, 999, 12345, 987654] as $fils) {
            $unit = $bundles->exactUnitFor($fils, $qty);
            $total = $bundles->totalFor($fils, $qty);

            expect($unit)->toBeInt()
                ->and($total)->toBeInt()
                // A discount can never make a price negative or raise it.
                ->and($unit)->toBeGreaterThanOrEqual(0)
                ->and($unit)->toBeLessThanOrEqual($fils);
        }
    }
});

it('refuses to invent money when handed a discount outside the allowed range', function () {
    // tiers() drops anything outside 0-90%, so these never come from settings —
    // but unitFor() is public, and a negative discount would RAISE the price
    // while one over 100 would make it negative.
    $bundles = moneyTiers([
        ['qty' => 1, 'discount' => 0, 'label' => '1'],
        ['qty' => 2, 'discount' => 200, 'label' => 'rejected'],
        ['qty' => 3, 'discount' => -50, 'label' => 'rejected too'],
    ]);

    // Both malformed tiers are dropped, so the price is simply undiscounted.
    expect($bundles->exactUnitFor(10000, 2))->toBe(10000)
        ->and($bundles->exactUnitFor(10000, 3))->toBe(10000);
});
