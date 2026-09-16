<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One sentence, written once, printed with the right country's name in it.
 *
 * THE HOUSE CONVENTION, and this class is only its second half.
 * VatDisplay::label() already reads a sentence out of settings and substitutes
 * a brace-delimited placeholder into it — `You're paying VAT ({rate}%)`. The
 * owner asked for the same idea on the delivery line: "make the full sentence
 * and the country name will auto change according". So the placeholder here is
 * spelled the same way, `{country}`, and is substituted at print time rather
 * than at save time.
 *
 * WHY A CLASS RATHER THAN A str_replace AT THE CALL SITE. There are two callers
 * already — DeliveryLine, which prints the stored sentence to a shopper, and
 * CountryPresets, which expands the same sentence into the admin form so the
 * owner can read it before he saves it. If those two spelled the placeholder
 * differently, or disagreed about what an unknown code renders as, the screen
 * would show him one sentence and the shop would print another. That is the
 * defect this class exists to make impossible, and it is why the preview in
 * the console is built from the same method the storefront calls.
 *
 * WHAT AN UNKNOWN CODE DOES. Countries::NAMES is a curated ninety-odd, not the
 * full ISO list, so a code can legitimately be missing from it — a row written
 * by an older build, or a country added to the picker later. The code itself is
 * substituted in that case, exactly as ShopperCountry::name() already does, so
 * the sentence still reads as a sentence and no caller has to handle a null.
 *
 * NOTHING IS INVENTED HERE. This class carries no wording of its own: hand it
 * an empty string and it returns one. A sentence exists only because somebody
 * typed it or clicked a preset that showed it to them first.
 */
final class CountryTemplate
{
    /** The placeholder, spelled once. */
    public const PLACEHOLDER = '{country}';

    /**
     * The sentence with the country's name in place of the placeholder.
     *
     * A sentence with no placeholder in it comes back untouched — which is the
     * ordinary case for every line already stored, and the reason adding this
     * step changes nothing that any shopper is being told today.
     */
    public static function fill(string $template, string $code): string
    {
        if (! str_contains($template, self::PLACEHOLDER)) {
            return $template;
        }

        return str_replace(self::PLACEHOLDER, self::name($code), $template);
    }

    /** Does this sentence follow whichever country it is attached to? */
    public static function isDynamic(string $template): bool
    {
        return str_contains($template, self::PLACEHOLDER);
    }

    /** The country's name, falling back to its code when it is not on the list. */
    public static function name(string $code): string
    {
        $code = strtoupper(trim($code));

        return Countries::NAMES[$code] ?? $code;
    }
}
