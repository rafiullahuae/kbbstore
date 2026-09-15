<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Import\Money as ImportMoney;

/**
 * Operator-typed decimal text in, integer fils out, and no float in between.
 *
 * WHY THIS FILE EXISTS RATHER THAN A SECOND COPY OF THE PARSE.
 *
 * There were two money parsers in this application already — the import one
 * (App\Services\Import\Money, which reads a WooCommerce export cell and is
 * deliberately strict about ambiguous commas) and a private pair of methods on
 * Admin\CatalogProductsApiController, which reads a number an operator typed
 * into a box on the Products screen. Catalog → Products' create form is a
 * third caller of the second one, and a third hand-rolled copy of
 *
 *     ((int) $whole) * (10 ** $exponent) + (int) $fraction
 *
 * is a third place for the same defect to come back. So the controller's
 * implementation moved here unchanged and the controller now calls it, which
 * is the arrangement routes/brands-admin.php and routes/catalog-admin.php both
 * describe for image uploads: one path, one place where the rules live.
 *
 * NEVER `(int) round($major * 100)`. A binary float cannot hold 1.15, so
 * `(int) (1.15 * 100)` is 114 and `0.29 * 100` is 28.999999999999996. Whether
 * round() rescues you depends on values nobody controls. The parse below never
 * constructs a float at all: it splits the decimal STRING on the point and does
 * integer arithmetic on the two halves, so 99.50 is 99 * 100 + 50 by
 * construction. The same family of defect was found in bundle pricing, where
 * `1 - 30 / 100` is 0.69999999999999995559.
 *
 * NEVER `numeric` AS THE VALIDATION RULE EITHER. `numeric` accepts "1e3",
 * " 1.5 " and "0.145", and none of those survive a digit-by-digit parse:
 * "1e3" reads as 1 dirham, and "0.145" is a price that is not expressible in
 * fils being silently truncated. shape() below is the anchored, digits-only
 * regex that goes in the validator, and it is the reason this parser is allowed
 * to be as simple as it is.
 *
 * BOUNDED. `products.price`, `products.sale_price` and every order money column
 * are declared `$t->integer(...)` — signed 32-bit. MySQL in strict mode errors
 * on an insert past that; SQLite stores it happily and the two engines part
 * company in production. exceedsColumn() is checked by the callers so the
 * refusal is a 422 naming the ceiling rather than a 500 naming a driver.
 */
final class MajorUnits
{
    /** The inclusive ceiling of the signed 32-bit columns money lives in. */
    public const MAX_FILS = ImportMoney::MAX_FILS;

    /**
     * The validator rule for a money field on the wire, in major units.
     *
     * Anchored and digits only: a leading '+' or '-', a thousands comma, a
     * currency symbol, an exponent and any surrounding space are all refused
     * rather than interpreted, because every one of them means something
     * different to the digit-by-digit parse below than it looks like it means.
     *
     * AT MOST AS MANY DECIMALS AS THE CURRENCY HAS, which is the part worth
     * explaining. Fils are hundredths, so "0.145" is a price this schema cannot
     * hold; signedFils() would truncate it to 14 fils and store a number the
     * operator did not type. Truncation is the right behaviour once a value has
     * been accepted — rounding a price UP is a price nobody asked for — but the
     * right moment to refuse extra precision is before that, where it can be
     * said out loud. App\Services\Import\Money takes exactly this line for the
     * same reason ("99.999 ... rejected rather than rounded, because a rounding
     * rule applied silently to 40,000 line items is a number the owner cannot
     * reconcile"), and a form an owner types into deserves it more, not less.
     *
     * Built from the currency's own exponent rather than hard-coded at 2, so a
     * zero-decimal currency refuses "10.5" instead of quietly making it 10.
     *
     * Nine integer digits is deliberately wider than the column: a value
     * between the regex ceiling and MAX_FILS is caught by exceedsColumn()
     * below, which can say what the real limit is in the operator's own
     * currency. A regex that stopped at the column width would report "invalid
     * format" for a number whose only problem is that it is too large.
     */
    public static function shape(): string
    {
        $exponent = Money::minorExponent();

        if ($exponent < 1) {
            return 'regex:/^\d{1,9}$/';
        }

        return 'regex:/^\d{1,9}(\.\d{1,'.$exponent.'})?$/';
    }

    /**
     * A decimal string in major units -> exact fils, by integer arithmetic.
     *
     * Null and the empty string both mean "no value", which is not the same as
     * zero: a product with no sale price and a product on sale at AED 0.00 are
     * different rows.
     */
    public static function fils(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return self::signedFils($text);
    }

    /**
     * The same parse, for a signed value (a bulk amount adjustment).
     *
     * The integer and fractional halves are split on the decimal point, the
     * fraction is padded or truncated to the currency's exponent, and the two
     * are combined with multiplication and addition on integers only.
     */
    public static function signedFils(string $text): int
    {
        $text = trim($text);
        $negative = str_starts_with($text, '-');

        if ($negative || str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }

        $parts = explode('.', $text, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = $parts[1] ?? '';

        $exponent = Money::minorExponent();

        // Pad to the currency's exponent, then truncate anything beyond it.
        // Truncation rather than rounding: the operator typed more precision
        // than the currency has, and inventing the last digit up is a price
        // they did not ask for.
        $fraction = substr(str_pad($fraction, $exponent, '0'), 0, max(0, $exponent));

        $fils = ((int) $whole) * (10 ** $exponent) + ($fraction === '' ? 0 : (int) $fraction);

        return $negative ? -$fils : $fils;
    }

    /** Would this many fils overflow the signed 32-bit column it is stored in? */
    public static function exceedsColumn(?int $fils): bool
    {
        return $fils !== null && ($fils > self::MAX_FILS || $fils < ImportMoney::MIN_FILS);
    }

    /**
     * The ceiling in major units, for a refusal message.
     *
     * Built by integer division so the sentence that tells an operator what the
     * limit is does not itself go through a float.
     */
    public static function maxMajor(): string
    {
        return Money::plain(self::MAX_FILS);
    }
}
