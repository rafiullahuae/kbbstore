<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which RANGE of its brand a product belongs to, read from its name.
 *                                                    (Lane FBT, 14 Nov 2026)
 *
 * The owner: "if it is from a same range of brand then those range products
 * should be shown". The catalogue has no range field -- no attribute, tag or
 * category carries it (a WooCommerce import) -- but the names do, because the
 * shop writes them "Brand - Range Product size":
 *
 *   Anua - Heartleaf 77% Soothing Toner          → heartleaf
 *   Anua - Heartleaf Pore Control Cleansing Oil  → heartleaf
 *   beplain - Mung Bean Cleansing Oil Jumbo      → mung
 *   Round Lab 1025 Dokdo Toner (brand not named) → 1025 dokdo
 *   numbuzin - No.9 NAD+ PDRN Glow Boosting Toner → no 9
 *   VT - PDRN Essence 100 (brand "VT Cosmetics") → pdrn
 *
 * THE RULE: the name's words, less the brand's own leading words (all of them,
 * or a leading part -- "VT" of "VT Cosmetics"), less a few qualifiers that are
 * not a range ("The", "New", "Real", "Advanced", "Deep" …); the first word
 * left is the range. A number takes the next word with it ("1025 Dokdo"), and
 * so does a word of one or two letters before a number ("No.9"). A first word that is a product TYPE ("Toner",
 * "Cream", "Sun") is no range at all -- "Anua - Toner" names nothing.
 *
 * A range only means anything INSIDE one brand: two products are in the same
 * range when they have the same brand AND the same key. Callers compare keys
 * only between products of one brand_id.
 *
 * RELIABILITY, measured against the 408 real names in
 * docs/ingredients-research (14 Nov 2026): 63 same-brand groups of two or more,
 * 251 products. The large majority are true lines (Heartleaf, Mung Bean,
 * Carrotene, Kojic Acid, Rosemary, Tsubaki, Madagascar Centella). The misses
 * are ingredient-led names that are a line in some brands and not in others
 * ("Vitamin", "Collagen", "Hyaluronic") -- still the same brand and the same
 * hero ingredient, so a miss offers a near neighbour, never a stranger.
 *
 * Pure string work: no query, nothing cached.
 */
final class ProductRange
{
    /** Leading qualifiers that are not a range: skipped. */
    private const SKIP = [
        'the', 'new', 'all', 'a', 'an', 'real', 'advanced', 'deep', 'super', 'original',
        'mini', 'travel', 'trial', 'special', 'jumbo', 'big', 'renewed', 'best',
    ];

    /** A first word that is a product type names no range. */
    private const TYPES = [
        'toner', 'toners', 'serum', 'serums', 'cream', 'creams', 'cleanser', 'cleansers', 'mask', 'masks',
        'sunscreen', 'sun', 'oil', 'ampoule', 'essence', 'gel', 'foam', 'lotion', 'pad', 'pads', 'set', 'kit',
        'eye', 'lip', 'lips', 'balm', 'mist', 'spf', 'cleansing', 'sheet', 'face', 'facial', 'moisturizer',
        'moisturiser', 'moisturizing', 'moisturising', 'water', 'body', 'hair', 'shampoo', 'conditioner',
        'patch', 'patches', 'stick', 'spray', 'scrub', 'peel', 'powder', 'soap', 'wash', 'emulsion',
    ];

    /**
     * The range key for one product, or null when its name names none.
     */
    public static function key(?string $brand, ?string $name): ?string
    {
        $words = self::tokens((string) $name);
        $brandWords = self::tokens((string) $brand);

        // The brand's own leading words, or a leading part of them.
        $n = 0;
        while ($n < count($brandWords) && $n < count($words) && $words[$n] === $brandWords[$n]) {
            $n++;
        }
        $words = array_slice($words, $n);

        while ($words !== [] && in_array($words[0], self::SKIP, true)) {
            array_shift($words);
        }

        if ($words === [] || in_array($words[0], self::TYPES, true)) {
            return null;
        }

        $first = $words[0];

        $next = $words[1] ?? null;

        // "1025 Dokdo", "345 Relief", "No.9": a number carries the next word.
        if ($next !== null && (ctype_digit($first) || (mb_strlen($first) <= 2 && ctype_digit($next)))) {
            return $first.' '.$next;
        }

        return mb_strlen($first) >= 2 && ! ctype_digit($first) ? $first : null;
    }

    /**
     * The one word of a key a LIKE can look for: the longest. Letters and
     * digits only, so it carries no wildcard.
     */
    public static function needle(string $key): string
    {
        $best = '';

        foreach (explode(' ', $key) as $w) {
            if (mb_strlen($w) > mb_strlen($best)) {
                $best = $w;
            }
        }

        return $best;
    }

    /**
     * Lower-cased word tokens, split on punctuation, whitespace and any
     * letter/digit boundary -- App\Support\ProductTitle's tokens.
     *
     * @return list<string>
     */
    private static function tokens(string $value): array
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = (string) preg_replace('/(\p{L})(\p{N})/u', '$1 $2', $value);
        $value = (string) preg_replace('/(\p{N})(\p{L})/u', '$1 $2', $value);

        preg_match_all('/[\p{L}\p{N}]+/u', $value, $matches);

        return $matches[0];
    }
}
