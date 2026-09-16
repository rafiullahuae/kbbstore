<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Is this `posts.cover` value a photograph, and if so where does it live?
 *
 * WHY THIS EXISTS.
 *
 * `posts.cover` is not a URL column. It is a CSS *background* value, inherited
 * from the static theme the storefront was ported from, and it holds three
 * different kinds of thing:
 *
 *   linear-gradient(135deg,#FFF0F4,#FCE0E8)   a decorative placeholder
 *   #FCE0E8                                    a flat colour
 *   https://kbeautybliss.com/…/heartleaf.jpg   an actual photograph
 *   #fff url('/storage/posts/hero.jpg') …      a photograph inside a shorthand
 *
 * Every view that showed a cover pasted the same three-way `str_contains`
 * expression inline — `gradient` or `#` or `url` — and each one got a slightly
 * different answer, because the test for "should the emoji show?" and the test
 * for "is this a background?" were written separately and do not agree. A bare
 * `https://…` cover hit that disagreement: it is not matched by the background
 * test, so the card drew the default gradient, but it IS matched by the emoji
 * test (`str_contains($cover, 'http')`), so the emoji was suppressed too. The
 * card rendered an empty pink rectangle — no photograph, no placeholder.
 *
 * So the question is asked once, here, and both halves read the same answer:
 * src() returns the photograph's URL or null, and null is the whole condition
 * for "fall back to the gradient and the emoji".
 *
 * This is also what makes the photograph indexable. Google Images cannot see a
 * CSS background, so a cover only reaches the index once a view can turn it
 * into an <img src>, and that requires separating the covers that ARE images
 * from the covers that are decoration.
 */
final class CoverImage
{
    /**
     * The photograph's URL, or null when the value is decoration.
     *
     * Order matters: url(...) is tested first because the shorthand form can
     * carry a colour too ("#fff url('…') center/cover"), and that leading
     * colour must not be mistaken for a flat-colour cover.
     */
    public static function src(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('#\burl\(\s*([\'"]?)(.*?)\1\s*\)#i', $value, $m) === 1) {
            $url = trim($m[2]);

            return $url === '' ? null : $url;
        }

        // A gradient of any kind, and a flat hex colour, are decoration.
        if (preg_match('#\b(?:linear|radial|conic|repeating-linear|repeating-radial)-gradient\s*\(#i', $value) === 1) {
            return null;
        }

        if (str_starts_with($value, '#')) {
            return null;
        }

        /*
         * Anything left holding whitespace or a semicolon is a CSS value this
         * helper does not recognise — a multi-layer background, a
         * `center/cover` fragment. Treating it as a URL would put unescaped CSS
         * into an src attribute, so it stays decoration.
         */
        if (preg_match('#[\s;]#', $value) === 1) {
            return null;
        }

        /*
         * What is left is a single unbroken token, and "not a gradient and not
         * a hex colour" is NOT enough to call it a URL: `rebeccapurple` and
         * `transparent` are single tokens too, and answering with those would
         * render <img src="rebeccapurple"> — a guaranteed broken image where
         * the card used to draw a clean placeholder.
         *
         * So the token has to look like somewhere a file lives: an absolute
         * URL, a protocol-relative one, a rooted path, or something carrying an
         * image extension (which is what a relative "posts/hero.jpg" has). A
         * bare word matches none of these and stays decoration.
         */
        $looksLikeUrl = preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $value) === 1
            || str_starts_with($value, '/')
            || preg_match('#\.(?:jpe?g|png|gif|webp|avif|svg)(?:$|[?\#])#i', $value) === 1;

        return $looksLikeUrl ? $value : null;
    }

    /**
     * The CSS background to paint behind the cover.
     *
     * For a photograph this is a plain surface: the <img> covers it, and the
     * only time it shows is the moment before the image paints.
     *
     * For decoration the rule below is the views' original three-way test,
     * reproduced deliberately rather than simplified. "Use the value whenever
     * it is not empty" would read better and would repaint every cover whose
     * column holds something this file does not recognise — a bare colour name,
     * a half-written value — from the theme's blush gradient to whatever that
     * string happens to mean to a browser. Nothing in the display-parity rule
     * allows a card to change colour, so the old test is kept verbatim.
     */
    public static function background(?string $value): string
    {
        $value = trim((string) $value);
        $default = 'linear-gradient(135deg,#FFF0F4,#FCE0E8)';

        if ($value === '') {
            return $default;
        }

        if (self::src($value) !== null) {
            return '#fff';
        }

        return (str_contains($value, 'gradient') || str_contains($value, '#')) ? $value : $default;
    }
}
