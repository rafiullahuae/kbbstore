<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

/**
 * The one place a keyword is cleaned. Every phrase — from Google, from Search
 * Console, from a shopper's search box, from the owner's own edit box — passes
 * through clean() before it is stored, and nothing is ever stored that did not.
 *
 * What it guarantees about a stored keyword:
 *   - no markup (strip_tags), no control or zero-width characters, no
 *     characters that would end an attribute or a JSON-LD string early;
 *   - no comma, because both <meta name="keywords"> and schema.org `keywords`
 *     are comma-separated lists, so a comma inside one keyword is two keywords;
 *   - lower case, single-spaced, 3–60 characters and at most 8 words.
 *
 * The output is still escaped at every print site. This is about what the
 * DATA is, not a substitute for escaping where it is printed.
 */
final class KeywordText
{
    public const MAX_CHARS = 60;

    public const MAX_WORDS = 8;

    public static function clean(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $s = strip_tags((string) $raw);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Control characters, zero-width and bidi overrides.
        $s = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $s);
        // Anything that is punctuation for a list, a tag or a string literal.
        $s = (string) preg_replace('/[<>"\'`{}\[\]\\\\|,;=*^~]/u', ' ', $s);
        $s = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
        $s = trim($s, " .-_/:!?#@&+");

        if ($s === '' || mb_strlen($s, 'UTF-8') < 3 || mb_strlen($s, 'UTF-8') > self::MAX_CHARS) {
            return null;
        }

        $words = explode(' ', $s);
        if (count($words) > self::MAX_WORDS) {
            return null;
        }

        // A 30-letter "word" is a name with its spaces lost, not a search.
        foreach ($words as $w) {
            if (mb_strlen($w) > 30) {
                return null;
            }
        }

        // A keyword is words. A bare number or a code is not one.
        if (! preg_match('/\p{L}{2,}/u', $s)) {
            return null;
        }

        return $s;
    }

    /** The comparison form: k-beauty == k beauty == kbeauty is NOT assumed; only spacing and dashes fold. */
    public static function norm(string $s): string
    {
        return trim((string) preg_replace('/[\s\-]+/u', ' ', mb_strtolower($s, 'UTF-8')));
    }

    /** @return list<string> */
    public static function tokens(string $s): array
    {
        $parts = preg_split('/[^\p{L}\p{N}+]+/u', self::norm($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($parts));
    }

    /** The same set of words, in any order — "snail mucin cosrx" is "cosrx snail mucin". */
    public static function signature(string $s): string
    {
        $t = self::tokens($s);
        sort($t);

        return implode(' ', $t);
    }

    /** Title case for a suggestion, keeping words that are already upper case (COSRX, SPF50+). */
    public static function display(string $s): string
    {
        return implode(' ', array_map(
            static fn (string $w): string => preg_match('/\p{Lu}/u', $w) ? $w : mb_convert_case($w, MB_CASE_TITLE, 'UTF-8'),
            explode(' ', $s)
        ));
    }
}
