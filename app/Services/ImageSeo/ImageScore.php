<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

/**
 * How well one product picture is named and described, 0–100. (Lane IR)
 *
 * PURE: the caller hands in the filename, the product's brand and name and the
 * alt the shop actually prints. Shown three ways from the one number: 0–100 on
 * the row, `round(score / 10)` as "6/10" in front of every image URL on the
 * Find tab (the owner's ask), and a green ✓ at 8/10 or better. The Media
 * Library's tick additionally needs the file to have been renamed by Catalog →
 * Image SEO — see ImageSeo::tick().
 *
 * THE RUBRIC (also printed in the screen's "How the score works" panel, from
 * RUBRIC below, so the words on the screen and the arithmetic cannot drift):
 *
 *   File name — 60
 *     15  lower case, words joined by hyphens, no spaces/underscores/capitals
 *     15  the brand is in it (awarded when the product has no brand)
 *     15  the product's own words are in it (7 for only one of them)
 *     10  3–8 words and at most 75 characters
 *      5  not a camera or random name (IMG_1234, 20261005-101010-ab12cd,
 *         a hash, a "-scaled" or "-1024x1024" copy). A random name also
 *         loses the format and length points: it is not descriptive at all.
 *   Alt text — 40
 *     10  written for this picture (5 when the shop falls back to its automatic one)
 *     10  5–125 characters
 *     15  names the product (7 for only the brand or one word of it)
 *      5  no "image of", no word stuffed three times, not the same as another
 *         picture of this product
 *
 * Every point lost comes back in `lost` with its reason, which is the one-line
 * "−2 no brand in name · −1 camera filename" under each badge.
 */
final class ImageScore
{
    /** The ✓, on the 0–100 scale (8/10). */
    public const TICK = 80;

    /** @var list<array{0: int, 1: string, 2: string}> points, group, rule */
    public const RUBRIC = [
        [15, 'File name', 'lower case, words joined by hyphens'],
        [15, 'File name', 'contains the brand'],
        [15, 'File name', 'contains the product\'s own words (7 for one word)'],
        [10, 'File name', '3–8 words, at most 75 characters'],
        [5, 'File name', 'not a camera, random or resized-copy name'],
        [10, 'Alt text', 'written for this picture (5 for the automatic one)'],
        [10, 'Alt text', '5–125 characters'],
        [15, 'Alt text', 'names the product (7 for only the brand or one word)'],
        [5, 'Alt text', 'no "image of", no stuffing, differs from the other pictures'],
    ];

    /**
     * @param  list<string>  $otherAlts  the alts of this product's other pictures
     * @return array{score: int, ten: int, tick: bool, lost: list<array{points: int, why: string}>}
     */
    public static function score(string $filename, ?string $brand, string $name, string $alt, bool $altWritten, array $otherAlts = []): array
    {
        $lost = [];
        $miss = static function (int $points, string $why) use (&$lost): void {
            if ($points > 0) {
                $lost[] = ['points' => $points, 'why' => $why];
            }
        };

        $stem = (string) pathinfo(basename($filename), PATHINFO_FILENAME);
        $g = ImageNamer::groups($brand, $name);
        $productWords = array_values(array_unique(array_merge($g['key'], $g['type'])));
        $stemWords = preg_split('/[^a-z0-9]+/', strtolower($stem), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $random = self::isRandom($stem);

        // ── File name ──────────────────────────────────────────────────────
        if ($random) {
            $miss(5, 'camera or random file name');
            $miss(15, 'random name is not descriptive');
            $miss(10, 'file name has no descriptive words');
        } else {
            if (preg_match(ImageNamer::SLUG_PATTERN, $stem) !== 1) {
                $miss(15, preg_match('/[A-Z]/', $stem) ? 'capitals in file name' : 'file name not hyphenated (spaces, _ or symbols)');
            }

            $count = count($stemWords);

            if ($count < 3) {
                $miss(10, 'file name too short');
            } elseif ($count > 8 || strlen($stem) > 75) {
                $miss(10, 'file name too long');
            }
        }

        if ($g['brand'] !== [] && ! self::containsRun($stemWords, $g['brand'])) {
            $miss(15, 'no brand in file name');
        }

        $found = count(array_intersect($productWords, $stemWords));
        $need = min(2, count($productWords));

        if ($need > 0 && $found < $need) {
            $miss($found >= 1 ? 8 : 15, $found >= 1 ? 'only part of the product name in file name' : 'product name not in file name');
        }

        // ── Alt text ──────────────────────────────────────────────────────
        $alt = trim($alt);
        $altWords = preg_split('/[^a-z0-9]+/', strtolower(\Illuminate\Support\Str::ascii($alt, 'en')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($alt === '') {
            $miss(40, 'alt text missing');
        } else {
            if (! $altWritten) {
                $miss(5, 'alt is the automatic one');
            }

            $length = mb_strlen($alt);

            if ($length < 5 || $length > 125) {
                $miss(10, $length < 5 ? 'alt too short' : 'alt longer than 125 characters');
            }

            $altFound = count(array_intersect($productWords, $altWords));
            $brandIn = $g['brand'] !== [] && self::containsRun($altWords, $g['brand']);
            $altNeed = max(1, (int) ceil(count($productWords) / 2));

            if ($altFound < $altNeed) {
                $miss(($brandIn || $altFound >= 1) ? 8 : 15, ($brandIn || $altFound >= 1) ? 'alt names only part of the product' : 'alt does not name the product');
            }

            $counts = array_count_values(array_filter($altWords, static fn ($w) => strlen($w) > 2));
            $stuffed = $counts !== [] && max($counts) >= 3;
            $prefix = preg_match('/^(an?\s+)?(image|picture|photo|pic)\s+(of|showing)\b/i', $alt) === 1;
            $dup = in_array(mb_strtolower($alt), array_map(static fn ($a) => mb_strtolower(trim((string) $a)), $otherAlts), true);

            if ($stuffed || $prefix || $dup) {
                $miss(5, $dup ? 'same alt as another picture' : ($prefix ? 'alt starts with "image of"' : 'keyword stuffing in alt'));
            }
        }

        $score = max(0, 100 - array_sum(array_column($lost, 'points')));

        return ['score' => $score, 'ten' => self::ten($score), 'tick' => $score >= self::TICK, 'lost' => $lost];
    }

    /** "6/10": the 0–100 score on the owner's ten-point scale, halves up. */
    public static function ten(int $score): int
    {
        return (int) floor(max(0, min(100, $score)) / 10 + 0.5);
    }

    /** The reason line under a badge: "−2 no brand in file name · −1 …", on the 10-point scale. */
    public static function reasons(array $lost): string
    {
        return implode(' · ', array_map(
            static fn (array $l): string => '−'.rtrim(rtrim(number_format($l['points'] / 10, 1), '0'), '.').' '.$l['why'],
            $lost,
        ));
    }

    /**
     * A name nobody chose: what a camera, a phone, an uploader or an image
     * resizer wrote.
     */
    public static function isRandom(string $stem): bool
    {
        $s = strtolower($stem);

        return $s === ''
            || preg_match('/^(img|dsc|dscn|dcim|pxl|mvimg|photo|image|picture|screenshot|screen-shot|whatsapp|untitled|copy|file|scan)([-_ ]|\d|$)/', $s) === 1
            || preg_match('/\d{8}/', $s) === 1                       // a date or a timestamp
            || preg_match('/[0-9a-f]{10,}/', $s) === 1               // a hash
            || preg_match('/^[\d\W_]+$/', $s) === 1                  // only numbers
            || preg_match('/-\d{2,4}x\d{2,4}$|-scaled$|-e\d{10,}$/', $s) === 1   // a resized WordPress copy
            || preg_match('/^[a-z0-9]{1,3}$/', $s) === 1;           // "a1", "x"
    }

    /** @param list<string> $words @param list<string> $run */
    private static function containsRun(array $words, array $run): bool
    {
        // The brand's own joining words are optional in a filename.
        $run = array_values(array_filter($run, static fn ($w) => ! in_array($w, ['of', 'the', 'and', 'by'], true)));
        $words = array_values(array_filter($words, static fn ($w) => ! in_array($w, ['of', 'the', 'and', 'by'], true)));
        $n = count($run);

        if ($n === 0) {
            return true;
        }

        for ($i = 0, $c = count($words) - $n; $i <= $c; $i++) {
            if (array_slice($words, $i, $n) === $run) {
                return true;
            }
        }

        // Brand written as one word ("dalba", "skin1004" vs "skin 1004").
        return in_array(implode('', $run), $words, true);
    }
}
