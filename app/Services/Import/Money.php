<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Decimal currency text in, integer fils out, and no float in between.
 *
 * Money in this schema is integer fils (AED x 100). Every money column is a
 * signed 32-bit `integer` — `$t->integer('total')` in the Phase 0 schema and in
 * the repair migrations both — so the representable range is fixed and small
 * enough to be worth checking here rather than discovering from MySQL.
 *
 * WHY NOT (float). The previous dead importer in this repository assigned
 * `$p['price']` straight through from a JSON export into an integer column.
 * AED 99.50 landed as 99 fils: a factor of a hundred out, on every product,
 * with nothing in the output to say so. The obvious "fix" — `(int) round($v *
 * 100)` — is only better by luck. Binary floating point cannot represent 0.29
 * or 19.99 exactly, so `$v * 100` produces 28.999999999999996 and 1998.9999...,
 * and whether `round()` saves you depends on values you do not control. The
 * parse below never constructs a float at all: it splits the decimal string on
 * the point and does integer arithmetic on the two halves, so 99.50 is
 * 99 * 100 + 50 by construction.
 *
 * WHAT IT REFUSES, AND WHY EACH REFUSAL IS BETTER THAN A GUESS:
 *
 *  - "1.234,50" / "99,50" — a lone comma is ambiguous. Woo exports under a
 *    European locale write a decimal comma; exports under an English locale
 *    write a thousands comma. Reading "99,50" as a thousands separator gives
 *    9950 currency units, i.e. AED 9,950.00 for a AED 99.50 line, and reading
 *    "1,234" as a decimal comma gives AED 1.23. Both are wrong quietly. So a
 *    comma is only accepted where a decimal point also appears and the grouping
 *    is unambiguous; otherwise the row is rejected and the owner re-exports.
 *  - "99.999" — three decimals is more precision than fils can hold. Trailing
 *    zeros are fine ("99.5000"), anything else is rejected rather than rounded,
 *    because a rounding rule applied silently to 40,000 line items is a number
 *    the owner cannot reconcile against WooCommerce afterwards.
 *  - anything outside +/- 2,147,483,647 fils (AED 21,474,836.47) — the column
 *    is a 32-bit integer. MySQL in strict mode errors on the insert; SQLite
 *    stores it happily and the parity breaks in production. Caught here so both
 *    behave the same and the reason is legible.
 */
final class Money
{
    /**
     * The inclusive bounds of a signed 32-bit integer column.
     *
     * Both Phase 0 (`$t->integer('total')`) and 2026_09_15_020000_repair_order_tables
     * declare every order money column this way, so this is the real ceiling,
     * not a guess.
     */
    public const MAX_FILS = 2147483647;

    public const MIN_FILS = -2147483648;

    /**
     * Parse one money field into fils.
     *
     * @param  string  $field  the column name, for the rejection message
     * @return int|null null when the source cell is empty, which is not the
     *                  same as zero: a product with no sale price and a product
     *                  on sale at AED 0.00 are different rows.
     *
     * @throws RowRejected
     */
    public static function fils(mixed $raw, string $field): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (is_float($raw)) {
            // Nothing in this pipeline should ever hand a float to the money
            // parser; if something does, that is the bug, and swallowing it
            // here would hide it behind a value that looks fine.
            throw RowRejected::because(
                $field.': a float reached the money parser — money must stay a decimal string all the way from the export'
            );
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        // Currency decoration the exporter may have written around the number.
        // Stripped before the grammar check so "AED 99.50" is accepted, while
        // anything else non-numeric still falls through to the rejection.
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $value = preg_replace('/^(?:AED|aed|د\.إ|Dhs\.?)/iu', '', $value) ?? $value;

        $value = self::resolveGrouping($value, $field);

        if (preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<frac>\d+))?$/', $value, $m) !== 1) {
            throw RowRejected::because($field.": '".$raw."' is not a decimal number");
        }

        $whole = $m['whole'];
        $frac = $m['frac'] ?? '';

        // 15 digits of AED is already far past the 32-bit column limit checked
        // below; the guard is here so the intdiv/multiply cannot overflow a
        // PHP int on the way to that check and wrap into a plausible value.
        if (strlen(ltrim($whole, '0')) > 15) {
            throw RowRejected::because($field.": '".$raw."' has more digits than a money column can hold");
        }

        if (strlen($frac) > 2) {
            $extra = substr($frac, 2);

            if (ltrim($extra, '0') !== '') {
                throw RowRejected::because(
                    $field.": '".$raw."' carries more precision than fils can represent — "
                    .'rounding it here would be a silent adjustment to every row like it'
                );
            }

            $frac = substr($frac, 0, 2);
        }

        $frac = str_pad($frac, 2, '0');

        $fils = ((int) $whole) * 100 + (int) $frac;

        if ($m['sign'] === '-') {
            $fils = -$fils;
        }

        if ($fils > self::MAX_FILS || $fils < self::MIN_FILS) {
            throw RowRejected::because(
                $field.": '".$raw."' is outside the range a 32-bit money column can store "
                .'(AED -21,474,836.48 to 21,474,836.47)'
            );
        }

        return $fils;
    }

    /**
     * Like fils(), but an empty cell means zero.
     *
     * For the order money columns, which are NOT NULL with a default of 0: an
     * order with a blank shipping cell had no shipping, and writing null there
     * would be rejected by MySQL in strict mode.
     *
     * @throws RowRejected
     */
    public static function filsOrZero(mixed $raw, string $field): int
    {
        return self::fils($raw, $field) ?? 0;
    }

    /**
     * Decide what a comma in the number means, or refuse to.
     *
     * The only unambiguous case is a comma used for grouping alongside a
     * decimal point ("1,234.50"), where the groups are three digits wide. Every
     * other shape is a coin flip between two readings that differ by a factor
     * of a thousand, so it is refused by returning the string unchanged and
     * letting the grammar check below reject it with the value quoted.
     */
    private static function resolveGrouping(string $value, string $field): string
    {
        if (! str_contains($value, ',')) {
            return $value;
        }

        if (str_contains($value, '.') && preg_match('/^[+-]?\d{1,3}(?:,\d{3})*(?:\.\d+)?$/', $value) === 1) {
            return str_replace(',', '', $value);
        }

        throw RowRejected::because(
            $field.": '".$value."' is ambiguous — is the comma a decimal point or a thousands separator? "
            .'Re-export with an unambiguous decimal point.'
        );
    }
}
