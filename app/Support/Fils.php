<?php

declare(strict_types=1);

namespace App\Support;

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
 * Rejected:  ""  "abc"  "1.234"  "1.2.3"  "1e3"  "12,"  "٢٥" (non-ASCII digits)
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
            return $input * 100;
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

        return $negative ? -$fils : $fils;
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
