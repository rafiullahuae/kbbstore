<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE SHOP'S MONEY IS WHOLE DIRHAMS. This is where that is decided.
 *
 * ── WHERE THIS CAME FROM ───────────────────────────────────────────────────
 *
 * A basket of AED 90.40 carrying a 60-fil discount printed:
 *
 *     Subtotal   AED 90
 *     Discount − AED  1
 *     Total      AED 90
 *
 * which is arithmetic that does not work. Money::displayDecimals() is 0 on this
 * store, so each of the three figures was rounded on its own on the way to the
 * screen and the column stopped adding up. Offered three ways to fix the
 * DISPLAY, the owner answered:
 *
 *     "no decimals. if any decimals comes. adjust to the price."
 *
 * So this is not a display change. It is a pricing policy: money in this shop
 * is whole dirhams, and anything that would produce fils is adjusted so that it
 * does not. The display problem dissolves because the fils never exist.
 *
 * ── WHAT THIS CLASS IS, AND WHAT IT IS NOT ─────────────────────────────────
 *
 * It is one predicate and two roundings, in integers, with no float anywhere —
 * the same discipline App\Support\Fils and App\Support\MajorUnits already keep,
 * for the same reason (`(int) (1.15 * 100)` is 114).
 *
 * It is NOT a validator, a formatter or a setting. Every screen decides for
 * itself whether a value that fails isWhole() is REFUSED with message() or
 * ADJUSTED with nearest(); the two are genuinely different and one blanket
 * answer would be wrong on half the screens. The rule of thumb the lane
 * applied, and the reason for it:
 *
 *   REFUSE where an operator is typing a price. He should see what he set. A
 *   price that changed itself between the keystroke and the save, with nothing
 *   said, is worse than a rejection — he would find out from a customer.
 *
 *   ADJUST where the figure is DERIVED and no one typed it. A 10% coupon on
 *   AED 199 is AED 19.90 and there is nobody to refuse: the shopper typed a
 *   code, not an amount.
 *
 * ── NO SWITCH, AND WHY NOT ─────────────────────────────────────────────────
 *
 * There is deliberately no `whole_dirhams_enabled` setting. The policy is
 * expressed as "a stored amount is a multiple of the currency's own major
 * unit", which is already true and already free for a zero-decimal currency:
 * with Money::minorExponent() at 0 the unit is 1 and isWhole() is true of every
 * integer, so a shop repriced into JPY keeps working with nothing to configure
 * and nothing to forget. A switch would be a second place for the answer to
 * live, and the shop would eventually be running with it off by accident.
 *
 * ── THE UNIT FOLLOWS THE CURRENCY, NOT THE NAME ────────────────────────────
 *
 * "Whole dirhams" is what the owner said and what this store sells in. The
 * implementation reads Money::minorExponent(), so on a three-decimal currency
 * (KWD, OMR) the policy is whole dinars and 1000 minor units, not 100. Hard
 * coding 100 here would silently mean "hundredths of a dinar" the day the
 * currency row changed — a policy that quietly stops being the policy.
 */
final class WholeDirhams
{
    /**
     * How many minor units make one whole unit of the shop's money.
     *
     * 100 for AED. 1 for a zero-decimal currency, which makes every amount
     * whole and every method below a no-op — see the class header.
     */
    public static function unit(): int
    {
        return 10 ** Money::minorExponent();
    }

    /** Is this amount a whole number of dirhams? */
    public static function isWhole(int $minor): bool
    {
        $unit = self::unit();

        return $unit <= 1 || $minor % $unit === 0;
    }

    /**
     * The fils left over — 9,980 -> 80, and 0 when the amount is already whole.
     *
     * Signed like the amount itself, so a caller can say how far a negative
     * value is from whole without losing which way.
     */
    public static function remainder(int $minor): int
    {
        $unit = self::unit();

        return $unit <= 1 ? 0 : $minor % $unit;
    }

    /**
     * The nearest whole dirham, halves away from zero.
     *
     * AWAY FROM ZERO and not "up", so that -50 fils goes to -1 dirham rather
     * than to 0. Every rounding in this application's money paths is half-up on
     * a positive value (CouponService::discountFor, TaxRule::taxOn,
     * BundleService::unitFor all spell it `+ half` before an intdiv), and this
     * is the same rule extended over the sign rather than a second convention.
     *
     * Integer arithmetic only. `round($minor / 100) * 100` is a float route
     * through a value that may not be representable, which is the defect three
     * other classes in this directory exist to avoid.
     */
    public static function nearest(int $minor): int
    {
        $unit = self::unit();

        if ($unit <= 1) {
            return $minor;
        }

        $negative = $minor < 0;
        $abs = $negative ? -$minor : $minor;

        $rounded = intdiv($abs + intdiv($unit, 2), $unit) * $unit;

        return $negative ? -$rounded : $rounded;
    }

    /**
     * The whole dirham at or below this amount — 13,930 -> 13,900.
     *
     * TOWARD ZERO, which for the one caller that wants it (a discounted bundle
     * unit price) is the direction that favours the shopper. A negative amount
     * is truncated toward zero by intdiv, which is the same "smaller in
     * magnitude" reading and not a floor toward -infinity; no money path in
     * this application rounds a negative amount down, and inventing a
     * convention for a case nobody has would be a guess.
     */
    public static function toward(int $minor): int
    {
        $unit = self::unit();

        return $unit <= 1 ? $minor : intdiv($minor, $unit) * $unit;
    }

    /** The whole dirham at or above this amount — 1,990 -> 2,000. */
    public static function away(int $minor): int
    {
        $toward = self::toward($minor);

        if ($toward === $minor) {
            return $minor;
        }

        return $toward + ($minor < 0 ? -self::unit() : self::unit());
    }

    /**
     * The refusal an operator reads beside the box he just typed in.
     *
     * It names the rule, names his own figure back to him, and names the two
     * whole amounts on either side — because "must be a whole number" beside a
     * box holding 99.80 leaves him to work out that the shop means 99 or 100,
     * and a message that makes him do arithmetic is how a 9 gets typed where a
     * 10 was meant.
     *
     * Built through Money::decimalString() and Money::plain(), which are
     * integer routes, so the sentence that explains the policy does not itself
     * go through a float.
     */
    public static function message(string $label, int $minor): string
    {
        $currency = Money::currency();

        return "“{$label}” is " . $currency . ' ' . Money::decimalString($minor)
            . '. This shop prices in whole ' . self::plural()
            . ', so enter ' . $currency . ' ' . Money::plain(self::toward($minor))
            . ' or ' . $currency . ' ' . Money::plain(self::away($minor)) . '.';
    }

    /**
     * The sentence shown when a DERIVED figure was adjusted rather than
     * refused. Said out loud on the screen, never silent: a value that moved
     * between typing and saving with nothing said is the one outcome the owner
     * must never meet.
     */
    public static function adjusted(string $label, int $from, int $to): string
    {
        $currency = Money::currency();

        return "“{$label}” was " . $currency . ' ' . Money::decimalString($from)
            . ' and has been adjusted to ' . $currency . ' ' . Money::plain($to)
            . ' — this shop prices in whole ' . self::plural() . '.';
    }

    /**
     * The plural of the shop's own currency unit, for the sentences above.
     *
     * Only AED has a name here. Anything else gets its ISO code, which reads
     * as "whole SAR" — clumsy, and correct, which is the better half of that
     * trade. A table of currency names would be one more list to be wrong.
     */
    public static function plural(): string
    {
        return Money::currency() === 'AED' ? 'dirhams' : Money::currency();
    }

    /**
     * The validator shape for a money box that takes whole units only.
     *
     * The counterpart of MajorUnits::shape(), which allows the currency's own
     * decimals. This one allows none at all, so "99.80" is refused by the
     * regex before any parse happens. Nine integer digits for the same reason
     * MajorUnits gives: a value between the regex ceiling and the column
     * ceiling should be told what the real limit is, not told its format is
     * wrong.
     *
     * NOT USED AS THE ONLY GUARD ANYWHERE. Every screen that takes this also
     * checks isWhole() on the parsed integer, because a screen may accept fils
     * on the wire (Store settings do) or a value may arrive from a caller that
     * is not the screen at all.
     */
    public static function shape(): string
    {
        return 'regex:/^\d{1,9}$/';
    }
}
