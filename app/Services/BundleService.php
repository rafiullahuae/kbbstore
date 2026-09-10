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

    /** The discounted unit price at a given quantity. */
    public function unitFor(int $unitPrice, int $qty): int
    {
        $discount = $this->discountFor($qty);

        return (int) round($unitPrice * (1 - $discount / 100));
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
