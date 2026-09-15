<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Joining a brand to a product name so the brand appears exactly once.
 *
 * This catalogue is a WooCommerce import and its product names are not written
 * to one rule. Some carry the brand already ("Anua Azelaic Acid 10 Serum"),
 * some do not ("1025 Dokdo Toner", whose brand is Round Lab). Templates that
 * simply concatenated brand and name produced "Anua Anua Heartleaf ...", and
 * templates that simply stripped a leading brand would eat the "1025" from a
 * name that never carried one.
 *
 * So the test is neither `str_starts_with` nor a blind prepend: the two strings
 * are reduced to token lists and the name is only left alone when its leading
 * tokens *are* the brand's tokens. Comparing tokens rather than characters is
 * what keeps three real cases in this catalogue correct:
 *
 *   - Case and punctuation vary. The store writes "celimax" lower case and
 *     "Dr.Althea" with no space; the brand row is "Celimax" and "Dr. Althea".
 *   - Letters and digits run together inconsistently. The brand row says
 *     "SKIN1004" while the storefront's own trending-words list says
 *     "SKIN 1004", so a letter/digit boundary is treated as a word boundary
 *     and both reduce to ["skin", "1004"].
 *   - A prefix is not a word. "Anua" must not be considered already present in
 *     a name beginning "Anuaa", which a `str_starts_with` check would get
 *     wrong.
 *
 * Nothing here mutates the stored name: a name that does not lead with its
 * brand gets the brand prepended for display only.
 */
final class ProductTitle
{
    /**
     * Brand and name, with the brand present exactly once.
     *
     * Returns whichever of the two is non-empty when the other is not, so a
     * product with no brand is still titled by its own name.
     */
    public static function full(?string $brand, ?string $name): string
    {
        $brand = trim((string) $brand);
        $name = trim((string) $name);

        if ('' === $brand) {
            return $name;
        }

        if ('' === $name) {
            return $brand;
        }

        return self::leadsWithBrand($brand, $name) ? $name : $brand . ' ' . $name;
    }

    /** Whether the name's opening words already are the brand. */
    public static function leadsWithBrand(string $brand, string $name): bool
    {
        $brandTokens = self::tokens($brand);
        $nameTokens = self::tokens($name);

        if ([] === $brandTokens || count($brandTokens) > count($nameTokens)) {
            return false;
        }

        return array_slice($nameTokens, 0, count($brandTokens)) === $brandTokens;
    }

    /**
     * Alt text for one gallery shot.
     *
     * The main shot is described by the product it shows. Later shots add their
     * position and no more: `ProductController::gallery()` labels shots from a
     * fixed list by index ("Front", "Texture", "Ingredients", ...), so beyond
     * the first slot those labels are a guess about position, not a fact about
     * the photograph. Writing "Texture" into the alt of whatever happens to be
     * uploaded second would state something the application does not know.
     * "view 2 of 5" is less descriptive and always true.
     */
    public static function alt(?string $brand, ?string $name, int $index, int $total): string
    {
        $base = self::full($brand, $name);

        if ($index <= 0 || $total < 2) {
            return $base;
        }

        return $base . ', view ' . ($index + 1) . ' of ' . $total;
    }

    /**
     * Lower-cased word tokens, splitting on punctuation, whitespace and any
     * letter/digit boundary.
     *
     * @return list<string>
     */
    private static function tokens(string $value): array
    {
        $value = mb_strtolower($value, 'UTF-8');

        // "skin1004" and "skin 1004" have to reduce to the same two tokens.
        $value = (string) preg_replace('/(\p{L})(\p{N})/u', '$1 $2', $value);
        $value = (string) preg_replace('/(\p{N})(\p{L})/u', '$1 $2', $value);

        preg_match_all('/[\p{L}\p{N}]+/u', $value, $matches);

        return $matches[0];
    }
}
