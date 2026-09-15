<?php

declare(strict_types=1);

namespace App\Support;

/**
 * GTIN validation — the barcode under a product, checked properly.
 *
 * WHY THE CHECK DIGIT IS THE WHOLE POINT.
 *
 * Google's merchant listing requirements want a real product identifier, and
 * `sku` is not one: it is free text this shop invents for itself, so two
 * retailers selling the same toner have two different SKUs and Google cannot
 * tell it is the same product. A GTIN can — it is the number under the barcode,
 * issued once for the item worldwide.
 *
 * Which is exactly why a `nullable|digits_between:8,14` rule would be worse
 * than nothing. The last digit of every GTIN is a mod-10 checksum over the
 * others, and its only job is to catch the two mistakes a human makes when
 * copying fourteen digits off a box: a mistyped digit, and a transposed pair.
 * A field that accepts any fourteen digits accepts both, and the shop then
 * publishes a confident, structured, machine-readable claim that this product
 * is some other product entirely. A wrong GTIN is worse for a merchant listing
 * than an absent one.
 *
 * THE LENGTHS. GTIN-8 (EAN-8), GTIN-12 (UPC-A), GTIN-13 (EAN-13) and GTIN-14
 * (ITF-14, the case/carton code) are the four the standard defines and the four
 * Google accepts. Nothing else is a GTIN, including a 10-digit ISBN and the
 * 11-digit UPC-E that people read off small packages.
 */
final class Gtin
{
    /** The only lengths that are a GTIN at all. */
    public const LENGTHS = [8, 12, 13, 14];

    /**
     * Is this a well-formed GTIN whose check digit agrees with its body?
     *
     * Whitespace and hyphens are tolerated on the way in — a number read off a
     * box is often typed with the groups the barcode prints — but nothing else
     * is: a value with any other character in it is a refusal rather than
     * something to be salvaged, because salvaging it means guessing which
     * digits the operator meant.
     */
    public static function isValid(?string $value): bool
    {
        $digits = self::normalise($value);

        if ($digits === null) {
            return false;
        }

        $body = substr($digits, 0, -1);
        $check = (int) substr($digits, -1);

        return self::checkDigit($body) === $check;
    }

    /**
     * The digits alone, or null if this cannot be a GTIN.
     *
     * Also what should be STORED: the separators an operator typed are
     * presentation, and two rows holding "4006381333931" and "400-6381-33393-1"
     * are the same barcode that no query will ever match to each other.
     */
    public static function normalise(?string $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        // Spaces and hyphens are grouping; anything else means this is not a
        // barcode and must not be quietly reshaped into one.
        $stripped = str_replace([' ', '-'], '', $text);

        if (preg_match('/^\d+$/', $stripped) !== 1) {
            return null;
        }

        if (! in_array(strlen($stripped), self::LENGTHS, true)) {
            return null;
        }

        return $stripped;
    }

    /**
     * The mod-10 check digit for a GTIN body (everything but the last digit).
     *
     * Weights alternate 3 and 1, starting with 3 on the RIGHTMOST digit of the
     * body and running leftwards. Anchoring at the right rather than the left
     * is what makes one implementation work for all four lengths: an ITF-14 is
     * an EAN-13 with a packaging digit on the front, and the weighting has to
     * land on the same digits in both.
     */
    private static function checkDigit(string $body): int
    {
        $sum = 0;
        $weight = 3;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += ((int) $body[$i]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
