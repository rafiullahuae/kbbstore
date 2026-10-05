<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A category or brand name as plain text, the way this shop stores it. (Lane FP)
 *
 * WordPress keeps a term's name HTML-ENCODED in `wp_terms.name` -- "Hydration &
 * Glow" is the row "Hydration &amp; Glow" -- and the importer copied that row
 * as it came. Every template here escapes what it prints, once, as it should,
 * so the shop drew "&amp;amp;" and the owner's Filters drawer read "Hydration
 * &amp; Glow" (docs/fp-owner/filters-mobile.png). A name typed in the admin
 * was stored plain, so the column held two dialects and no template could be
 * right for both.
 *
 * The fix is the data, not the templates: a name is stored as the text a
 * shopper reads, and escaped exactly once, where it is printed. Decoding here
 * makes `<` a real `<` in the column, which is safe precisely because nothing
 * prints a term name unescaped (TermNameEscapedOnceTest).
 */
final class TermName
{
    public static function plain(string $name): string
    {
        if (! str_contains($name, '&')) {
            return $name;
        }

        return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
