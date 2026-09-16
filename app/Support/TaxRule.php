<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One destination's tax rule: a rate, and what that rate DOES.
 *
 * ── THE THING THIS CLASS EXISTS TO MAKE POSSIBLE ────────────────────────────
 *
 * The owner asked for it in three messages, on 2026-09-16:
 *
 *   "Can we set the Tax rate accros each country? we need it. please make a
 *    reliable system for it. and create a seperate tab for 'Tax'"
 *
 *   "also i will need control to inclusive VAT or exclusive."
 *
 *   "across each country, for example for uae the vat i can set inclusive, for
 *    Saudi i can set exclusive and so on as per my requirements."
 *
 * EXCLUSIVE VAT HAS EXACTLY ONE MEANING: the tax is added on top of the price.
 * There is no version of an inclusive/exclusive switch that leaves the line
 * display-only, because in inclusive mode the customer pays 100 and in
 * exclusive mode they pay 105. Decision D-64 — "VAT is a display line only, it
 * never alters a total, nothing is charged" — is therefore OVERTURNED HERE, BY
 * THE OWNER, DELIBERATELY, and not by a developer passing through. The reasons
 * D-64 was recorded are still good reasons; they are simply no longer the
 * shop's policy. App\Support\VatDisplay carries the full note. Do not "fix"
 * this back.
 *
 * ── THE THREE BASES, AND WHICH OF THEM MOVE MONEY ───────────────────────────
 *
 *   inclusive   The shelf price already contains the tax. The customer pays
 *               the shelf price; the tax is the portion of it that is tax.
 *               base x rate / (100 + rate).   AED 100 at 5% -> 4.76 contained.
 *
 *   exclusive   The tax is added on top. The customer pays MORE.
 *               base x rate / 100.            AED 100 at 5% -> 5.00 added,
 *                                             AED 105.00 charged.
 *
 *   flat        A figure PRINTED and never charged: rate x total, shown beside
 *               a total it is not part of. This is the legacy `vat_basis` value
 *               of the same name and it keeps its old meaning exactly, because
 *               a shop set to `flat` today must not start charging anybody more
 *               on the day this ships. It is offered on the screen as "Printed
 *               only — charges nothing", which is what it always was.
 *
 * `flat` and `inclusive` both leave the total alone; only `exclusive` raises
 * it. addsToTotal() is the one question every money path asks.
 *
 * ── ROUNDING: ONCE, ON THE ORDER, IN INTEGERS ───────────────────────────────
 *
 * Money is integer fils throughout this codebase. Tax is computed ONCE on the
 * order's whole taxable base and rounded once — never per line and summed,
 * because n roundings do not add up to one rounding and the invoice would then
 * disagree by a fil with what the card was charged. There is deliberately no
 * per-line tax figure anywhere in this design, and `order_items.tax_total` is
 * left at 0 rather than filled with numbers that do not sum to the order's.
 *
 * NO FLOAT ARITHMETIC, for the reason CouponService::discountFor() sets out
 * about the identical sum: most percentages are not representable in binary,
 * 35% is 0.34999999999999997779…, and a value landing exactly on a half fil
 * falls the wrong side of round(). The rate is carried here in HUNDREDTHS OF A
 * PERCENT (15% -> 1500), which is exactly the precision the screen offers, so
 * every sum below is exact. Round-half-up on a positive value is
 * intdiv(2x + y, 2y) for x/y.
 *
 * The products below reach at most 2,147,483,647 x 10,000 ~ 2.1e13, inside a
 * 64-bit PHP int with room to spare. This application already requires 64-bit
 * ints elsewhere (Fils, CouponService) and the host is 64-bit.
 */
final class TaxRule
{
    public const INCLUSIVE = 'inclusive';

    public const EXCLUSIVE = 'exclusive';

    /** Printed, never charged — the legacy basis, unchanged in meaning. */
    public const FLAT = 'flat';

    /** @var list<string> */
    public const BASES = [self::INCLUSIVE, self::EXCLUSIVE, self::FLAT];

    /** Rate in hundredths of a percent: 5% -> 500, 15% -> 1500, 7.5% -> 750. */
    public readonly int $bp;

    public function __construct(
        public readonly float $rate,
        public readonly string $basis,
    ) {
        $this->bp = (int) round($rate * 100);
    }

    /**
     * A rule from whatever the settings table happens to hold.
     *
     * An unrecognised basis becomes `inclusive` rather than `exclusive`: a row
     * that arrives from an older build, a hand-edited database or a
     * half-applied package must never be the reason a customer is charged
     * more. The safe reading of a value we do not understand is the one that
     * takes no extra money.
     */
    public static function make(mixed $rate, mixed $basis): self
    {
        $basis = is_string($basis) ? strtolower(trim($basis)) : '';

        return new self(
            is_numeric($rate) ? (float) $rate : 0.0,
            in_array($basis, self::BASES, true) ? $basis : self::INCLUSIVE,
        );
    }

    /**
     * Does this rule change what the customer pays?
     *
     * `inclusive` and `flat` both answer false — one because the tax is
     * already inside the price, one because it is only printed — so a shop on
     * either of them cannot start charging more unless somebody changes the
     * basis on purpose.
     */
    public function addsToTotal(): bool
    {
        return $this->basis === self::EXCLUSIVE;
    }

    /**
     * The tax figure for a taxable base, in fils.
     *
     * For `exclusive` this is what is ADDED to the base. For `inclusive` it is
     * the portion of the base that ALREADY IS tax. For `flat` it is a number
     * to print and nothing more.
     */
    public function taxOn(int $baseFils): int
    {
        if ($this->bp <= 0 || $baseFils <= 0) {
            return 0;
        }

        if ($this->basis === self::INCLUSIVE) {
            // base x bp / (10000 + bp), rounded half up, in integers.
            $denominator = 10000 + $this->bp;

            return intdiv(2 * $baseFils * $this->bp + $denominator, 2 * $denominator);
        }

        // exclusive and flat are the same sum; they differ only in what is
        // done with the answer, which is addsToTotal()'s business.
        return intdiv($baseFils * $this->bp + 5000, 10000);
    }

    /** What the customer pays for a taxable base of this size. */
    public function grossOf(int $baseFils): int
    {
        return $this->addsToTotal() ? $baseFils + $this->taxOn($baseFils) : $baseFils;
    }

    /** 5.00 -> "5", 7.50 -> "7.5", 15.00 -> "15". */
    public function printableRate(): string
    {
        return rtrim(rtrim(number_format($this->rate, 2, '.', ''), '0'), '.');
    }
}
