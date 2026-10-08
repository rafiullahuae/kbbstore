<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use App\Support\ProductTitle;

/**
 * Alt text proposals for Catalog → Image SEO → ALT text. (Lane IR)
 *
 * NATURAL ENGLISH, NEVER A CLAIM THE SHOP CANNOT MAKE. App\Support\ProductTitle
 * refuses to label the second photograph "Texture" because nothing knows it
 * is; the templates here follow the same rule. They vary the WORDING the way
 * the filenames vary their word order — "Medicube PDRN Eye Patches", "PDRN Eye
 * Patches by Medicube", "Medicube PDRN Eye Patches – Eye Care" — and describe a
 * picture's content only when the owner types it himself on the row.
 *
 * ENGLISH ONLY, AND SAID SO ON THE SCREEN: products.image_alts is one map of
 * URL → alt with no language in it (it is not in Product::$translatable), and
 * Product::altFor() prints a stored alt on /ar as well. An Arabic page with no
 * stored alt keeps its automatic Arabic one.
 */
final class AltText
{
    public const TEMPLATES = ['variations', 'numbered', 'custom'];

    public const MIN = 5;

    public const MAX = 125;

    /**
     * One alt per picture, all different.
     *
     * @return list<string>
     */
    public static function propose(?string $brand, string $name, ?string $category, int $count, string $template = 'variations', string $first = '', string $rest = ''): array
    {
        $brand = trim((string) $brand);
        $full = ProductTitle::full($brand, $name);
        $bare = self::withoutBrand($brand, $name);
        $category = trim((string) $category);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $n = $i + 1;

            $alt = match ($template) {
                'numbered' => $i === 0 ? $full : $full.' – view '.$n.' of '.$count,
                'custom' => self::fill($i === 0 ? $first : ($rest !== '' ? $rest : $first), [
                    'name' => $bare, 'brand' => $brand, 'full' => $full, 'category' => $category, 'index' => (string) $n, 'total' => (string) $count,
                ]),
                default => match (true) {
                    $i === 0 => $full,
                    $i === 1 && $brand !== '' => $bare.' by '.$brand,
                    $i === 2 && $category !== '' => $full.' – '.$category,
                    $brand !== '' => $bare.' by '.$brand.' – view '.$n,
                    default => $full.' – view '.$n,
                },
            };

            $alt = self::clean($alt);

            // Never the same sentence twice on one product.
            if ($alt === '' || in_array(mb_strtolower($alt), array_map('mb_strtolower', $out), true)) {
                $alt = self::clean($full.' – view '.$n);
            }

            $out[] = $alt;
        }

        return $out;
    }

    /**
     * What may be stored: tags and control characters gone, "image of" gone,
     * spaces collapsed, at most MAX characters cut at a word.
     */
    public static function clean(string $alt): string
    {
        $alt = strip_tags($alt);
        $alt = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $alt);
        $alt = (string) preg_replace('/^\s*(an?\s+)?(image|picture|photo|pic)\s+(of|showing)\s+/iu', '', $alt);
        $alt = trim((string) preg_replace('/\s+/u', ' ', $alt));
        $alt = trim($alt, " \t-–,");

        if (mb_strlen($alt) > self::MAX) {
            $cut = mb_substr($alt, 0, self::MAX);
            $space = mb_strrpos($cut, ' ');
            $alt = rtrim($space !== false && $space > 60 ? mb_substr($cut, 0, $space) : $cut, " -–,");
        }

        return $alt;
    }

    /** Whether a typed alt is one this module will store. */
    public static function acceptable(string $alt): bool
    {
        $length = mb_strlen($alt);

        return $length >= self::MIN && $length <= self::MAX;
    }

    private static function withoutBrand(string $brand, string $name): string
    {
        $name = trim($name);

        if ($brand === '') {
            return $name;
        }

        $quoted = preg_quote($brand, '/');
        $bare = (string) preg_replace('/^\s*'.$quoted.'[\s:,\-–]*/iu', '', $name);
        $bare = (string) preg_replace('/\s+(by|from)\s+'.$quoted.'\s*$/iu', '', $bare);

        return trim($bare) !== '' ? trim($bare) : $name;
    }

    /** @param array<string, string> $values */
    private static function fill(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{(name|brand|full|category|index|total)\}/', static fn ($m) => $values[$m[1]] ?? '', $template);
    }
}
