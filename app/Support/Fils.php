<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Import\Money as ImportMoney;

/**
 * Turning something a person typed into integer fils, without a float in the
 * middle of it.
 *
 * Money is integer fils (AED x 100) everywhere in this app. The conversion
 * looks trivial and is not:
 *
 *     (int) (1.15 * 100)   === 114
 *     (int) (8.20 * 100)   === 819
 *     (int) (0.29 * 100)   ===  28
 *
 * because 1.15 is not representable in binary floating point, and the cast
 * truncates rather than rounds. Money::fromAed() dodges it with round(), which
 * is right for most values but still routes an operator's keystrokes through a
 * double: round() cannot recover a digit the float never held, and the failure
 * is silent and one fil wide, which is exactly the kind that reaches a customer
 * invoice before anyone notices.
 *
 * So this never multiplies. It reads the string one character at a time and
 * builds the integer with integer arithmetic only.
 *
 * Accepted:  "12"  "12.5"  "12.50"  ".5"  "1,299.99"  " 12.50 "  "-5"  "AED 12.50"
 * Rejected:  ""  "abc"  "1.234"  "1.2.3"  "1e3"  "1 2"  "٢٥" (non-ASCII digits),
 *            and anything outside a 32-bit money column.
 *
 * WHY THIS EXISTS ALONGSIDE THE TWO PARSERS ALREADY IN THE REPOSITORY.
 * App\Services\Import\Money::fils() is the importer's: it throws RowRejected,
 * whose message talks about the export, and it is the right thing for a 40,000
 * row CSV. CatalogProductsApiController::filsFromMajor() is private to that
 * screen and TRUNCATES excess precision rather than refusing it, which is the
 * correct call for a bulk price adjustment and the wrong one for a single
 * amount an operator typed. This one answers null so a validation rule can turn
 * it into a message beside the field, and refuses rather than truncates.
 *
 * What it does NOT do is invent a third idea of how big a money value may be:
 * the bounds are Import\Money's constants, which are in turn the real width of
 * the `integer` money columns.
 */
final class Fils
{
    /** How many decimal places a fils amount can hold. */
    private const SCALE = 2;

    /**
     * Parse an operator-typed AED amount into fils.
     *
     * Returns null when the input is not a number this can represent exactly —
     * the caller decides whether that is a validation error or a default. It
     * never guesses, and never silently drops precision: "1.234" is rejected
     * rather than rounded, because an operator who typed a third decimal meant
     * something, and quietly choosing for them is how a wrong total gets a
     * confident-looking receipt.
     */
    public static function parse(string|int|float|null $input): ?int
    {
        if ($input === null) {
            return null;
        }

        // An int is already exact; a float argument is a bug at the call site,
        // so it is refused rather than silently given the (int)(x*100) result
        // this class exists to avoid.
        if (is_int($input)) {
            // Bounded like every other path — an integer major amount can be
            // past the column just as easily as a typed one.
            return self::withinColumn($input * 100);
        }

        if (is_float($input)) {
            return null;
        }

        // Currency decoration an operator may paste in along with the number.
        $s = trim(str_ireplace(['AED', Money::SYMBOL, 'د.إ'], '', trim($input)));

        // Thousands separators. The two non-breaking spaces are here because
        // they are genuine group separators in several locales and arrive by
        // copy-paste; an ordinary space is NOT, and is dealt with below.
        $s = str_replace([',', "\u{00A0}", "\u{202F}", "'"], '', $s);

        if ($s === '') {
            return null;
        }

        // An ordinary space left inside the number is a typo, not a separator:
        // stripping it would read "1 2" as 1,200. Two amounts typed into one
        // box should fail loudly rather than silently become a third amount.
        if (str_contains($s, ' ')) {
            return null;
        }

        $negative = false;

        if ($s[0] === '-' || $s[0] === '+') {
            $negative = $s[0] === '-';
            $s = substr($s, 1);
        }

        if ($s === '') {
            return null;
        }

        // Split on the one allowed decimal point. Two points, or anything that
        // is not an ASCII digit, fails here rather than being coerced.
        $parts = explode('.', $s);

        if (count($parts) > 2) {
            return null;
        }

        [$whole, $fraction] = [$parts[0], $parts[1] ?? ''];

        if ($whole === '' && $fraction === '') {
            return null;
        }

        if (! self::isAsciiDigits($whole) || ! self::isAsciiDigits($fraction)) {
            return null;
        }

        // More precision than a fil can hold. Not rounded — see the docblock.
        if (strlen($fraction) > self::SCALE) {
            return null;
        }

        $fraction = str_pad($fraction, self::SCALE, '0');

        // Integer arithmetic only, one digit at a time. No multiplication by
        // 100, no float, nothing for a binary fraction to lose.
        $fils = 0;

        foreach (str_split($whole . $fraction) as $digit) {
            $next = $fils * 10 + (ord($digit) - 48);

            // A typed amount long enough to overflow is a typo, not an order.
            if ($next > PHP_INT_MAX / 10) {
                return null;
            }

            $fils = $next;
        }

        return self::withinColumn($negative ? -$fils : $fils);
    }

    /**
     * The value, or null when it will not fit a money column.
     *
     * Every money column in this schema is a signed 32-bit `integer` —
     * $t->integer('total') in the Phase 0 schema and in
     * 2026_09_15_020000_repair_order_tables both. Past that, MySQL in strict
     * mode errors on the insert and SQLite stores the wrong number without a
     * word. The second half is the dangerous one: the order looks placed and
     * its total is nonsense, and the two engines disagree about a value the
     * tests would have called fine.
     */
    private static function withinColumn(int $fils): ?int
    {
        return ($fils > ImportMoney::MAX_FILS || $fils < ImportMoney::MIN_FILS) ? null : $fils;
    }

    /**
     * Does a product of two integers fit in a money column?
     *
     * The multiply is where an overflow actually happens: a unit price and a
     * quantity can each be perfectly sane and their product still be past the
     * column. Checked by division rather than by multiplying and looking at the
     * result, because the multiplication is the thing that would already have
     * wrapped.
     */
    public static function productFits(int $unitFils, int $quantity): bool
    {
        if ($unitFils === 0 || $quantity === 0) {
            return true;
        }

        if ($unitFils < 0 || $quantity < 0) {
            return false;
        }

        return $quantity <= intdiv(ImportMoney::MAX_FILS, $unitFils);
    }

    /** Does a sum of money values fit in a money column? */
    public static function sumFits(int ...$parts): bool
    {
        $total = 0;

        foreach ($parts as $part) {
            if ($part > ImportMoney::MAX_FILS - $total) {
                return false;
            }

            $total += $part;
        }

        return true;
    }

    /** The ceiling, so a message can name it. */
    public static function max(): int
    {
        return ImportMoney::MAX_FILS;
    }

    /**
     * Parse, or fall back — for optional fields where a blank box means
     * "leave it as it was".
     */
    public static function parseOr(string|int|float|null $input, int $default): int
    {
        return self::parse($input) ?? $default;
    }

    /** True when $input is an AED amount this can hold exactly. */
    public static function isValid(string|int|float|null $input): bool
    {
        return self::parse($input) !== null;
    }

    /**
     * Fils back to the plain decimal string an operator would recognise.
     * Integer arithmetic again, so it round-trips with parse() exactly.
     */
    public static function toDecimalString(int $fils): string
    {
        $sign = $fils < 0 ? '-' : '';
        $abs = abs($fils);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), self::SCALE, '0', STR_PAD_LEFT);
    }

    /** '' counts as digits — an empty whole or fraction part is allowed. */
    private static function isAsciiDigits(string $s): bool
    {
        if ($s === '') {
            return true;
        }

        // Deliberately not ctype_digit(): it accepts the locale's idea of a
        // digit and, on some builds, an integer argument. strspn over the
        // literal ASCII range cannot be talked into anything else.
        return strspn($s, '0123456789') === strlen($s);
    }
}
