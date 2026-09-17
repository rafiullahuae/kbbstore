<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\WholeDirhams;

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
    /**
     * The unit price a bundle actually charges, in whole dirhams.
     *
     * The percentage arithmetic lives in exactUnitFor() and is unchanged and
     * still exact to the fil; this is the whole-dirham step on top of it.
     *
     * TWO METHODS AND NOT ONE WITH A ROUNDING AT THE END, on purpose.
     * StorefrontMoneyExactTest pins the property that this sum equals exact
     * integer arithmetic for every price and tier, and a fil of float drift is
     * invisible once the answer has been rounded to a dirham — 31 and 32 fils
     * are both AED 0. Folding the two together would leave that test passing
     * over the defect it was written for. Same arrangement as
     * CouponService::exactDiscountFor().
     */
    public function unitFor(int $unitPrice, int $qty): int
    {
        $unit = $this->exactUnitFor($unitPrice, $qty);

        /*
         * NO DISCOUNT, NO ROUNDING. THIS GUARD IS LOAD-BEARING — Lane FA.
         *
         * Every path that prices a cart line goes through here, bundles on or
         * off: with no tier matching, exactUnitFor() returns the product's own
         * price unchanged and this method is the identity. Rounding on that
         * branch would mean a product priced at 6,125 fils before this policy
         * existed being CHARGED at AED 61 — a price the owner never set,
         * applied silently, on every order, with nothing on any screen saying
         * so. That is the one outcome this lane exists to prevent, and it is
         * reachable here precisely because this method is on the path for
         * products it has no opinion about.
         *
         * The rounding below is the whole-dirham policy applied to a DISCOUNT
         * this service itself computed. Where it computed nothing, it changes
         * nothing. Legacy prices carrying fils are the audit command's
         * business (kbb:whole-dirhams), where the owner deals with them
         * deliberately and can see what it costs.
         */
        if ($unit === $unitPrice) {
            return $unitPrice;
        }

        /*
         * WHOLE DIRHAMS, ROUNDED DOWN — Lane FA.
         *
         * THE ARITHMETIC. A 30% tier on a AED 199 product is AED 139.30 a
         * unit. The price is whole and the tier is whole and the result is
         * not, for the same reason a percentage coupon's is not: a percentage
         * of a whole number is not one. See App\Support\WholeDirhams.
         *
         * ADJUSTED, NOT REFUSED, because nobody typed AED 139.30. The owner
         * typed "30%" against a quantity; the price it lands on depends on
         * which product a shopper is looking at, and there is no screen on
         * which to refuse it.
         *
         * WHY THE UNIT AND NOT THE LINE TOTAL. totalFor() is unitFor() x qty
         * and the cart stores a UNIT price per line, so rounding the line
         * would leave a unit price that does not multiply up to it — the
         * product page would advertise one figure per unit and the basket
         * would charge another. Rounding the unit keeps `unit x qty` exactly
         * the line total, at every quantity, with no second rounding anywhere.
         * That is what makes the whole ledger add up rather than only its
         * bottom line.
         *
         * WHY DOWN. This is a DISCOUNTED price, so toward zero is toward the
         * shopper: AED 139.30 becomes AED 139 and the advertised saving grows
         * by 30 fils rather than shrinking by 70. Same instinct as the coupon
         * rounding next door — where a figure the shop has advertised cannot
         * be made exact, the shop pays the difference — and it also guarantees
         * the bundle price stays strictly below the undiscounted one, which
         * the product page's "was/now" pair and its Save badge both depend on.
         *
         * A 0% TIER IS UNAFFECTED, and by the guard above rather than by luck:
         * a shop that has never touched its tiers sees no movement at all, and
         * neither does a product this service declined to discount.
         */
        return WholeDirhams::toward($unit);
    }

    /**
     * The discounted unit price to the exact fil, BEFORE the whole-dirham
     * policy is applied.
     *
     * The integer arithmetic in here is the point — see the note below. Public
     * so the property test can hold it to exact arithmetic. Not what the shop
     * charges; unitFor() is.
     */
    public function exactUnitFor(int $unitPrice, int $qty): int
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
