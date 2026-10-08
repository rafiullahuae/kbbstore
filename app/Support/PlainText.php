<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A line of text as a shopper reads it, out of a WordPress export. (Lane AMP)
 *
 * WordPress stores a title HTML-ENCODED whenever kses or _wp_specialchars ran on
 * it: "SKIN&LAB - Vitamin C Brightening Serum" is the post_title row
 * "SKIN&amp;LAB - Vitamin C Brightening Serum", an en dash is "&#8211;", a
 * curly apostrophe "&#8217;". The exporter hands post_title over as stored and
 * the importer copied it, so `products.name` held two dialects -- the encoded
 * one from the import and the plain one from every admin save. Every template
 * here escapes what it prints, once, as it should, so the owner's grid read
 * "SKIN&amp;LAB" on one card and "SKIN&LAB" on the next.
 *
 * The fix is the DATA, not the templates: a plain-text column holds the text a
 * shopper reads, and it is escaped exactly once, where it is printed. Escaping
 * stays on everywhere -- decoding `&lt;` to a real `<` here is safe precisely
 * because nothing prints these columns unescaped (EntityDecodeEscapesTest).
 *
 * ONCE, AND ONLY WELL-FORMED REFERENCES. `&amp;amp;` becomes `&amp;`, not `&`;
 * "AT&T" and "Fish &chips;" are not references and come back byte for byte.
 * ENT_HTML5 refuses the code points HTML forbids (`&#0;`, `&#1;`, surrogates),
 * so no control character can be minted from a reference.
 *
 * Same function as TermName::plain() (Lane FP), which stays where it is for the
 * category and brand importers that already call it.
 */
final class PlainText
{
    /** A complete character reference: named, decimal or hex, closed by `;`. */
    public const ENTITY = '/&(?:[A-Za-z][A-Za-z0-9]{1,31}|#[0-9]{1,7}|#[xX][0-9A-Fa-f]{1,6});/';

    /** Whether $text carries a character reference worth decoding. */
    public static function encoded(?string $text): bool
    {
        return $text !== null && str_contains($text, '&') && preg_match(self::ENTITY, $text) === 1;
    }

    /**
     * $text with its character references decoded, once. Null stays null, and a
     * string with no reference is returned as the same string.
     */
    public static function decode(?string $text): ?string
    {
        if (! self::encoded($text)) {
            return $text;
        }

        return html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
