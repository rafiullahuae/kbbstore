<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use Illuminate\Support\Str;

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
     * The product is always written the way the shop writes it: the brand
     * record's own spelling once, in front ("medicube PDRN …", "Dr. Althea
     * 345 …"), never a second copy of it from the title ("[COSRX] … by
     * COSRX"), and no Arabic inside an English sentence. A suffix ("– view
     * 4", "– Eye Care") is measured BEFORE the name is shortened, so a long
     * title loses its own last words and never the suffix that tells two
     * pictures apart -- and a set keeps the word that says it is a set.
     *
     * @return list<string>
     */
    public static function propose(?string $brand, string $name, ?string $category, int $count, string $template = 'variations', string $first = '', string $rest = ''): array
    {
        $brand = trim((string) $brand);
        $title = self::english($name);
        $bare = self::withoutBrand($brand, $title);
        $full = $brand === '' ? $title : trim($brand.' '.$bare);
        // "Pure Vitamin C Serum Kit by By Wishtrend" says "by" twice.
        $by = preg_match('/^by\s/i', $brand) === 1 ? ' from ' : ' by ';
        $category = trim((string) $category);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $n = $i + 1;

            $alt = match ($template) {
                'numbered' => $i === 0 ? self::fit($full) : self::fit($full, ' – view '.$n.' of '.$count),
                'custom' => self::fill($i === 0 ? $first : ($rest !== '' ? $rest : $first), [
                    'name' => $bare, 'brand' => $brand, 'full' => $full, 'category' => $category, 'index' => (string) $n, 'total' => (string) $count,
                ]),
                default => match (true) {
                    $i === 0 => self::fit($full),
                    $i === 1 && $brand !== '' => self::fit($bare, $by.$brand),
                    $i === 2 && $category !== '' => self::fit($full, ' – '.$category),
                    $brand !== '' => self::fit($bare, $by.$brand.' – view '.$n),
                    default => self::fit($full, ' – view '.$n),
                },
            };

            $alt = self::clean($alt);

            // Never the same sentence twice on one product.
            if ($alt === '' || in_array(mb_strtolower($alt), array_map('mb_strtolower', $out), true)) {
                $alt = self::clean(self::fit($full, ' – view '.$n));
            }

            $out[] = $alt;
        }

        return $out;
    }

    /**
     * What may be stored: tags and control characters gone, "image of" gone,
     * spaces collapsed, no doubled or dangling separators, at most MAX
     * characters cut at a word.
     */
    public static function clean(string $alt): string
    {
        $alt = strip_tags($alt);
        $alt = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $alt);
        $alt = (string) preg_replace('/^\s*(an?\s+)?(image|picture|photo|pic)\s+(of|showing)\s+/iu', '', $alt);
        $alt = self::tidy($alt);

        if (mb_strlen($alt) > self::MAX) {
            $alt = self::cut($alt, self::MAX);
        }

        return $alt;
    }

    /** Whether a typed alt is one this module will store. */
    public static function acceptable(string $alt): bool
    {
        $length = mb_strlen($alt);

        return $length >= self::MIN && $length <= self::MAX;
    }

    /**
     * The title with every copy of the brand gone, however the title spells
     * it ("Dr.Jart+" for "Dr. Jart+", "Manyo" for "ma:nyo", "[COSRX]", "by
     * Beauty of Joseon"), and the separators it leaves behind tidied.
     */
    private static function withoutBrand(string $brand, string $name): string
    {
        $want = self::compact($brand);

        if ($want === '' || preg_match_all('/[\p{L}\p{N}]+/u', $name, $m, PREG_OFFSET_CAPTURE) === 0) {
            return $name;
        }

        $words = $m[0];
        $cuts = [];

        for ($i = 0, $n = count($words); $i < $n; $i++) {
            $have = '';

            for ($j = $i; $j < $n && strlen($have) < strlen($want); $j++) {
                $have .= self::compact($words[$j][0]);

                if ($have === $want) {
                    $from = $words[$i][1];
                    // "… by Laneige", "… from COSRX": the joining word goes with it.
                    if ($i > 0 && preg_match('/^(by|from)$/i', $words[$i - 1][0]) === 1
                        && trim(substr($name, $words[$i - 1][1] + strlen($words[$i - 1][0]), $from - $words[$i - 1][1] - strlen($words[$i - 1][0]))) === '') {
                        $from = $words[$i - 1][1];
                    }

                    // "Dr.Jart+", "COSRX™": a sign glued to the brand goes with it.
                    $to = $words[$j][1] + strlen($words[$j][0]);
                    $to += strlen((string) (preg_match('/^[+&™®]+/u', substr($name, $to), $sign) === 1 ? $sign[0] : ''));
                    $cuts[] = [$from, $to];
                    $i = $j;

                    break;
                }
            }
        }

        foreach (array_reverse($cuts) as [$from, $to]) {
            $name = substr($name, 0, $from).' '.substr($name, $to);
        }

        $bare = self::tidy($name);

        return $bare !== '' ? $bare : self::tidy(str_replace(['(', ')', '[', ']'], ' ', $name));
    }

    /** Lower-case ASCII letters and digits only: how a brand is recognised in a title. */
    private static function compact(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($text, 'en')));
    }

    /** An English title: Arabic script goes when the title has Latin words to say it with. */
    private static function english(string $name): string
    {
        if (preg_match('/\p{Latin}/u', $name) === 1) {
            $name = (string) preg_replace('/\p{Arabic}[\p{Arabic}\p{Mn}\s]*/u', ' ', $name);
        }

        return self::tidy($name);
    }

    /** One space between words, no empty brackets, no doubled or dangling separators. */
    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/[\(\[]\s*[\)\]]/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = (string) preg_replace('/\s*([-–—|:,\/])\s*(?:[-–—|:,\/]\s*)+/u', ' $1 ', $text);

        // A sign that ends a name stays ("Dr.Jart+", "SPF50+"); a separator does not.
        return trim((string) preg_replace('/^[\s\-–—|:,\/]+|[\s\-–—|:,\/]+$|\s+[+&]$/u', '', $text));
    }

    /**
     * $text shortened so that $text.$suffix fits MAX, cut at a word. A set's
     * own word ("Gift Set", "Kit", "1+1") survives the cut: "Torriden … Gift
     * Set with Pouch" loses "with Pouch" and its middle, never "Set".
     */
    private static function fit(string $text, string $suffix = ''): string
    {
        $text = self::tidy($text);
        $room = self::MAX - mb_strlen($suffix);

        if (mb_strlen($text) <= $room) {
            return $text.$suffix;
        }

        $cut = self::cut($text, $room);
        $marker = '/\b(?:(?:gift|travel|trial|starter|mini|special|holiday)\s+)?(?:set|kit|duo|trio|bundle|collection)\b(?:\s+of\s+\d+)?|\b\d+-?pack\b|\b\d+\s*\+\s*\d+\b|(?<![a-z])x\s?\d+\b/iu';

        if (preg_match_all($marker, $text, $all) > 0 && preg_match($marker, $cut) !== 1) {
            $set = end($all[0]);
            $cut = self::cut(mb_substr($text, 0, (int) mb_strpos($text, $set)), $room - mb_strlen($set) - 1).' '.$set;
        }

        return $cut.$suffix;
    }

    /** At most $max characters, cut at a word, with nothing dangling at the end. */
    private static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max + 1);
        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space > (int) ($max / 2) ? mb_substr($cut, 0, $space) : mb_substr($cut, 0, $max);
        // "… Sensitive Skin for", "… Cream +": a joining word or sign does not end a sentence.
        $cut = (string) preg_replace('/(?:\s+(?:for|with|and|by|from|of|in|the|a|an|&|\+))+$/iu', '', $cut);

        return self::tidy($cut);
    }

    /** @param array<string, string> $values */
    private static function fill(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{(name|brand|full|category|index|total)\}/', static fn ($m) => $values[$m[1]] ?? '', $template);
    }
}
