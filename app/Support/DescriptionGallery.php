<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Picture galleries inside a description, article, page or product tab.
 *
 * The owner, 16 October 2026: "insert media functionality too from the media
 * box ... the insert images should be inserted together by selecting number of
 * columns." The admin editors insert (admin/partials/rte-media.blade.php):
 *
 *   <div class="kbb-gal kbb-gal-3"><img src="…" alt="" loading="lazy">…</div>
 *
 * The columns travel as a CLASS, never an inline style: RichText strips every
 * style attribute on purpose (a pasted position:fixed is a clickjacking
 * layer), and a class names a rule the shop's own stylesheet either has or
 * does not -- kbb.css styles kbb-gal-1 to -4 and nothing else.
 *
 * Here, at render: each gallery picture is offered its img-cache copies
 * (ImageVariants::srcsetFor, the same as every other shop photograph -- the
 * speed rule "no new <img> ships with a bare full-size src"), with `sizes`
 * worked out from the column count, and decoding=async. Only pictures inside a
 * gallery are touched; the rest of the HTML comes back byte for byte, and HTML
 * without a gallery costs one str_contains.
 *
 * Idempotent: a picture that already carries a srcset is left alone, so the
 * two render paths that can meet (RichText::forStorefront, Shortcodes::render)
 * never add one twice.
 */
final class DescriptionGallery
{
    public const CLASS_NAME = 'kbb-gal';

    /** The widest a description column is drawn, in CSS px (product page / journal). */
    private const COLUMN = 760;

    public static function expand(string $html): string
    {
        if (! str_contains($html, self::CLASS_NAME)) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#<div class="kbb-gal(?: kbb-gal-([1-4]))?">(.*?)</div>#s',
            static function (array $m): string {
                $cols = $m[1] !== '' ? (int) $m[1] : 3;
                $sizes = self::sizes($cols);
                $inner = (string) preg_replace_callback(
                    '#<img\b([^>]*)>#i',
                    static fn (array $i): string => self::picture($i[1], $sizes),
                    $m[2],
                );

                return '<div class="kbb-gal kbb-gal-' . $cols . '">' . $inner . '</div>';
            },
            $html,
        );
    }

    /** sizes for one picture in a gallery of $cols: at most 2 a row on a phone. */
    public static function sizes(int $cols): string
    {
        $phone = (int) round(100 / min($cols, 2));

        return '(max-width: 600px) ' . $phone . 'vw, ' . (int) round(self::COLUMN / $cols) . 'px';
    }

    private static function picture(string $attrs, string $sizes): string
    {
        if (stripos($attrs, 'srcset=') !== false
            || preg_match('/\bsrc="([^"]+)"/i', $attrs, $s) !== 1) {
            return '<img' . $attrs . '>';
        }

        $add = '';
        try {
            $srcset = ImageVariants::srcsetFor(html_entity_decode($s[1], ENT_QUOTES | ENT_HTML5));
        } catch (\Throwable) {
            $srcset = '';
        }

        if ($srcset !== '') {
            $add .= ' srcset="' . e($srcset) . '" sizes="' . e($sizes) . '"';
        }
        if (stripos($attrs, 'loading=') === false) {
            $add .= ' loading="lazy"';
        }
        if (stripos($attrs, 'decoding=') === false) {
            $add .= ' decoding="async"';
        }

        return '<img' . rtrim($attrs, " /") . $add . '>';
    }
}
