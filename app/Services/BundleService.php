<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;

/**
 * Quantity bundles: buy more of the same product for a further discount.
 *
 * These are generated for every product from a tier table rather than created
 * as variants, so adding a product needs no extra work and changing the offer
 * is one setting rather than an edit per product.
 *
 * The prices here are for display. The server recomputes the same figures when
 * the line is added to the cart, so a tampered form cannot buy at a price the
 * store never offered. (Rule 23)
 */
class BundleService
{
    /** Used when nothing has been configured yet. */
    public const DEFAULT_TIERS = [
        ['qty' => 1, 'discount' => 0,  'label' => '1 unit',        'tag' => ''],
        ['qty' => 2, 'discount' => 5,  'label' => '2-pack bundle', 'tag' => 'Save {n}%'],
        ['qty' => 3, 'discount' => 10, 'label' => '3-pack bundle', 'tag' => 'Best value'],
    ];

    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        return $this->settings->moduleEnabled('quantity_bundles', true)
            && (bool) $this->settings->get('bundles_enabled', true);
    }

    /**
     * The configured tiers, cleaned up.
     *
     * Anything malformed is dropped rather than allowed to render a broken row
     * or, worse, a zero price.
     */
    public function tiers(): array
    {
        $raw = $this->settings->get('bundle_tiers');
        $tiers = is_array($raw) && $raw !== [] ? $raw : self::DEFAULT_TIERS;

        $clean = [];

        foreach ($tiers as $t) {
            $qty = (int) ($t['qty'] ?? 0);
            $discount = (float) ($t['discount'] ?? 0);

            if ($qty < 1 || $discount < 0 || $discount > 90) {
                continue;
            }

            $clean[$qty] = [
                'qty' => $qty,
                'discount' => $discount,
                'label' => (string) ($t['label'] ?? ($qty . ' units')),
                'tag' => (string) ($t['tag'] ?? ''),
            ];
        }

        ksort($clean);

        return array_values($clean);
    }

    /**
     * Priced tiers for one product.
     *
     * `was` is the plain price for that quantity, so the saving is visible.
     * Returns an empty array when bundles are off or only a single tier exists —
     * one option is not a choice, and rendering it would just add noise.
     */
    public function forProduct(Product $product): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $unit = $product->effectivePrice();

        if ($unit <= 0) {
            return [];
        }

        $tiers = $this->tiers();

        if (count($tiers) < 2) {
            return [];
        }

        $out = [];

        foreach ($tiers as $t) {
            $qty = $t['qty'];
            $was = $unit * $qty;
            $total = $this->totalFor($unit, $qty);
            $saved = $was - $total;
            $pct = $was > 0 ? (int) round($saved / $was * 100) : 0;

            $out[] = [
                'qty' => $qty,
                'label' => $t['label'],
                'tag' => str_replace('{n}', (string) $pct, $t['tag']),
                'unit' => $this->unitFor($unit, $qty),
                'total' => $total,
                'was' => $was,
                'saved' => $saved,
                'percent' => $pct,
            ];
        }

        return $out;
    }

    /**
     * The discounted unit price at a given quantity, in whole fils.
     *
     * Integer arithmetic, deliberately. This was
     * `(int) round($unitPrice * (1 - $discount / 100))`, and floating point
     * made it ONE FIL WRONG on ordinary, whole-number tiers -- not only on
     * awkward ones. A third of a percent is not representable in binary, so
     * `1 - 30/100` is 0.69999999999999996, and a price whose exact discounted
     * value lands on a half fil falls the wrong side of round():
     *
     *     unit 45 fils at 30% off -> exact 31.5, rounds to 32
     *                                float 31.499999999999996, rounds to 31
     *
     * At a 30% tier that is wrong for 1,170 of the first 50,000 prices, from 45
     * fils upward; at 34% for 1,000 of them from 25 fils upward. totalFor()
     * then multiplies the error by the quantity, so a ten-unit bundle was out
     * by ten fils, and the storefront's figure disagreed with anything that
     * recomputed the same line exactly.
     *
     * The discount is held to hundredths of a percent -- the precision the
     * admin's tier editor offers -- so the whole sum is exact in integers, and
     * `+ 5000` before the division is round-half-up on a positive value, which
     * is what round() did.
     */
    public function unitFor(int $unitPrice, int $qty): int
    {
        // Discount in hundredths of a percent: 30% -> 3000, 12.5% -> 1250.
        $discount = (int) round($this->discountFor($qty) * 100);

        // tiers() already refuses anything outside 0-90%, but unitFor() is
        // public and a negative or >100% discount would invent money.
        $discount = max(0, min(10000, $discount));

        return intdiv($unitPrice * (10000 - $discount) + 5000, 10000);
    }

    /** The line total at a given quantity, rounded once at the end. */
    public function totalFor(int $unitPrice, int $qty): int
    {
        return $this->unitFor($unitPrice, $qty) * $qty;
    }

    /**
     * The discount that applies at a quantity — the best tier at or below it,
     * so buying 5 still gets the 3-pack rate rather than nothing.
     */
    public function discountFor(int $qty): float
    {
        if (! $this->enabled()) {
            return 0.0;
        }

        $best = 0.0;

        foreach ($this->tiers() as $t) {
            if ($qty >= $t['qty'] && $t['discount'] > $best) {
                $best = $t['discount'];
            }
        }

        return $best;
    }
}
