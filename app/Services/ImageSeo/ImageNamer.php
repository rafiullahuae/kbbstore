<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use Illuminate\Support\Str;

/**
 * The filenames Catalog → Image SEO gives a product's pictures. (Lane IR)
 *
 * PURE: no database, no disk. The engine hands in a `$taken` callable that
 * knows the directory, the extension and every other file; this class only
 * decides words and their order, so every rule here is testable in isolation.
 *
 * ── THE OWNER'S SCHEME ─────────────────────────────────────────────────────
 *
 *   "if a product is 'Medicube PDRN eye patches' and such product has 5
 *    images, so the variation from 2nd image should 'PDRN Medicube eye
 *    patches' and third 'Eye patches PDRN by Medicube' and so on."
 *
 * The title is read as three groups — the BRAND, the KEY words that make this
 * product this product (PDRN), and the product TYPE (eye patches) — and each
 * later picture gets the next ORDER of those groups:
 *
 *   1  title order, brand once        medicube-pdrn-eye-patches
 *   2  key  brand  type               pdrn-medicube-eye-patches
 *   3  type key  "by" brand           eye-patches-pdrn-by-medicube
 *   4  key  type  brand               pdrn-eye-patches-medicube
 *   5  brand type key                 medicube-eye-patches-pdrn
 *   6  key  type  "by" brand          pdrn-eye-patches-by-medicube
 *   7  type brand key                 eye-patches-medicube-pdrn
 *   8  type key  brand                eye-patches-pdrn-medicube
 *
 * An order that spells a name already used for this product is skipped, and
 * once the orders run out a picture is named `<first name>-<its position>`.
 * The second strategy, NUMBERED, is that from picture two on.
 *
 * ── GOOGLE'S RULES, AS APPLIED ─────────────────────────────────────────────
 *
 * Lower case, ASCII, words joined by hyphens (Google reads a hyphen as a word
 * break and an underscore as a joiner). Accents are transliterated, apostrophes
 * dropped ("d'Alba" → "dalba"), Arabic script removed rather than spelled in
 * Latin letters (the English name is the source; a product whose name has no
 * Latin words at all gets no proposal). Function words go ("by" is the one the
 * scheme itself adds), a word repeated in the title appears once, and the name
 * is capped at MAX_WORDS words and MAX_LENGTH characters by dropping key words
 * from the end — never the brand, never the type's head word.
 */
final class ImageNamer
{
    public const STRATEGY_VARIATIONS = 'variations';

    public const STRATEGY_NUMBERED = 'numbered';

    public const STRATEGIES = [self::STRATEGY_VARIATIONS, self::STRATEGY_NUMBERED];

    /** Characters, extension excluded. */
    public const MAX_LENGTH = 70;

    public const MAX_WORDS = 8;

    /** What a generated stem must look like before it is allowed near a disk. */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** A number, alone or with its unit glued on: 6, 50ml, 100g, 30ea. */
    private const AMOUNT = '/^\d+(?:ml|g|kg|mg|oz|l|ea|pcs?|pairs?|ct|sheets?|caps?)?$/';

    /** Count words that only mean something after a number: "6 pairs", "30 ea". */
    private const COUNT_WORDS = ['pair', 'pairs', 'pcs', 'pc', 'ea', 'ml', 'g', 'kg', 'mg', 'oz', 'ct', 'count', 'pieces', 'piece'];

    /** Joining words: they carry no meaning in a filename. */
    private const STOP = ['a', 'an', 'the', 'and', 'or', 'with', 'for', 'of', 'in', 'on', 'to', 'by', 'from', 'at', 'x', 'plus', 'amp'];

    /** The head word of a product type. */
    private const TYPE_HEADS = [
        'patch', 'patches', 'mask', 'masks', 'pad', 'pads', 'serum', 'serums', 'toner', 'toners', 'essence', 'essences',
        'ampoule', 'ampoules', 'ampule', 'cream', 'creams', 'creme', 'gel', 'gels', 'lotion', 'lotions', 'cleanser', 'cleansers',
        'foam', 'oil', 'oils', 'balm', 'balms', 'sunscreen', 'sunscreens', 'sunblock', 'mist', 'emulsion', 'moisturizer',
        'moisturiser', 'tint', 'cushion', 'foundation', 'primer', 'shampoo', 'conditioner', 'scrub', 'exfoliator', 'peel',
        'peeling', 'powder', 'stick', 'spray', 'set', 'kit', 'duo', 'trio', 'wash', 'water', 'milk', 'soap', 'sheet',
        'sheets', 'device', 'booster', 'concealer', 'lipstick', 'gloss', 'mascara', 'liner', 'palette', 'brush',
        'sponge', 'treatment', 'solution', 'softener', 'fluid', 'jelly', 'butter', 'bar', 'capsules', 'tablets',
        'lip', 'eyeliner', 'blush', 'bb', 'cc', 'remover', 'pack', 'packs', 'spf',
    ];

    /** Words that belong to the type in front of its head: EYE patches, SLEEPING mask. */
    private const TYPE_MODIFIERS = [
        'eye', 'eyes', 'lip', 'lips', 'face', 'facial', 'sheet', 'sleeping', 'cleansing', 'sun', 'hand', 'body', 'foot',
        'hair', 'night', 'day', 'under', 'pore', 'spot', 'acne', 'nose', 'peeling', 'toner', 'essence', 'gel', 'cream',
        'foam', 'oil', 'water', 'clay', 'wash', 'off', 'tone', 'up', 'bb', 'cc', 'lip', 'neck', 'scalp', 'toning',
    ];

    /**
     * The three groups a title is read as, plus the title-order words.
     *
     * @return array{brand: list<string>, key: list<string>, type: list<string>, ordered: list<string>}
     */
    public static function groups(?string $brand, string $name): array
    {
        $brandTokens = self::words((string) $brand, false);
        $rest = self::words($name, true);

        // The brand is its own group: every run of its words in the title goes.
        // Matched with ITS joining words dropped too, the way the title's
        // were: "Beauty of Joseon" is found in "beauty joseon relief…".
        $brandRun = self::words((string) $brand, true);

        if ($brandRun !== []) {
            $rest = self::withoutRun($rest, $brandRun);
        }

        $rest = self::unique($rest);

        [$key, $type] = self::split($rest);
        [$key, $type] = self::fit($brandTokens, $key, $type);

        // Title order with the dropped words gone (fit() may have removed some).
        $keep = array_merge($key, $type);
        $ordered = [];

        foreach ($rest as $word) {
            $at = array_search($word, $keep, true);

            if ($at !== false) {
                $ordered[] = $word;
                unset($keep[$at]);
            }
        }

        return ['brand' => $brandTokens, 'key' => $key, 'type' => $type, 'ordered' => $ordered];
    }

    /**
     * One stem per picture, unique within the product and free on disk.
     *
     * @param  callable(string $stem, int $index): bool|null  $taken  true when the stem may not be used
     * @return list<string|null> null where the title gives nothing to name it by
     */
    public static function names(?string $brand, string $name, int $count, string $strategy = self::STRATEGY_VARIATIONS, ?callable $taken = null): array
    {
        if ($count < 1) {
            return [];
        }

        $g = self::groups($brand, $name);

        if (count($g['brand']) + count($g['ordered']) < 2) {
            return array_fill(0, $count, null);
        }

        $taken ??= static fn (): bool => false;
        $orders = $strategy === self::STRATEGY_NUMBERED ? [self::join(array_merge($g['brand'], $g['ordered']))] : self::orders($g);
        $first = $orders[0];
        $used = [];
        $out = [];
        $next = 0;

        for ($i = 0; $i < $count; $i++) {
            // The next order not already spelled for this product, else the
            // first name plus this picture's position.
            $candidate = null;

            while ($next < count($orders) && $candidate === null) {
                $maybe = $orders[$next++];

                if (! isset($used[$maybe])) {
                    $candidate = $maybe;
                }
            }

            if ($candidate === null || ($strategy === self::STRATEGY_NUMBERED && $i > 0)) {
                $candidate = self::suffixed($first, (string) ($i + 1));
            }

            // A collision with somebody else's file: a short suffix, the only
            // place one is ever added.
            $stem = $candidate;

            for ($n = 2; isset($used[$stem]) || $taken($stem, $i); $n++) {
                $stem = self::suffixed($candidate, (string) $n);

                if ($n > 999) {
                    $stem = null;

                    break;
                }
            }

            if ($stem !== null) {
                $used[$stem] = true;
            }

            $out[] = $stem;
        }

        return $out;
    }

    /**
     * Every distinct order of the groups, the owner's sequence first.
     *
     * @param  array{brand: list<string>, key: list<string>, type: list<string>, ordered: list<string>}  $g
     * @return list<string>
     */
    public static function orders(array $g): array
    {
        [$b, $k, $t] = [$g['brand'], $g['key'], $g['type']];
        $by = $b === [] ? [] : array_merge(['by'], $b);

        $all = [
            array_merge($b, $g['ordered']),
            array_merge($k, $b, $t),
            array_merge($t, $k, $by),
            array_merge($k, $t, $b),
            array_merge($b, $t, $k),
            array_merge($k, $t, $by),
            array_merge($t, $b, $k),
            array_merge($t, $k, $b),
        ];

        $out = [];

        foreach ($all as $words) {
            $stem = self::join($words);

            if ($stem !== '' && ! in_array($stem, $out, true)) {
                $out[] = $stem;
            }
        }

        return $out;
    }

    /**
     * Free text to a stem: transliterated, lower case, hyphenated. Not used
     * for product names (groups() is) — for the score and for tests.
     */
    public static function slug(string $text): string
    {
        return self::join(self::words($text, false));
    }

    /** Whether a stem is one this class could have produced. */
    public static function valid(string $stem): bool
    {
        return strlen($stem) <= self::MAX_LENGTH + 4 && preg_match(self::SLUG_PATTERN, $stem) === 1;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Words of a title: ASCII, lower case, no Arabic script, no apostrophes.
     *
     * @return list<string>
     */
    private static function words(string $text, bool $dropStop): array
    {
        $text = (string) preg_replace('/[\p{Arabic}]+/u', ' ', $text);
        $text = (string) preg_replace("/['’‘`´]/u", '', $text);
        $text = Str::ascii($text, 'en');
        $text = strtolower($text);
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($dropStop) {
            $words = array_values(array_filter($words, static fn (string $w): bool => ! in_array($w, self::STOP, true)));
        }

        return array_values($words);
    }

    /** @param list<string> $words @param list<string> $run @return list<string> */
    private static function withoutRun(array $words, array $run): array
    {
        $n = count($run);
        $out = [];

        for ($i = 0, $c = count($words); $i < $c; $i++) {
            if (array_slice($words, $i, $n) === $run) {
                $i += $n - 1;

                continue;
            }

            $out[] = $words[$i];
        }

        // A one-word brand also leaves no stray copy of itself behind.
        return $n === 1 ? array_values(array_filter($out, static fn ($w) => $w !== $run[0])) : $out;
    }

    /** A word written twice appears once; numbers may repeat ("1+1"). */
    private static function unique(array $words): array
    {
        $seen = [];
        $out = [];

        foreach ($words as $w) {
            if (! ctype_digit($w) && isset($seen[$w])) {
                continue;
            }

            $seen[$w] = true;
            $out[] = $w;
        }

        return $out;
    }

    /**
     * Key words and type phrase. The type is the LAST type head in the title,
     * the modifiers directly in front of it, and anything after it ("Booster
     * PRO", "Pad 2 0"); everything before is the key.
     *
     * @param  list<string>  $words
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function split(array $words): array
    {
        $count = count($words);

        if ($count <= 1) {
            return [$words, []];
        }

        $head = null;

        for ($i = $count - 1; $i >= 0; $i--) {
            if (in_array($words[$i], self::TYPE_HEADS, true) || preg_match('/^spf\d+$/', $words[$i]) === 1) {
                $head = $i;

                break;
            }
        }

        if ($head === null) {
            // No known type word: the last word stands in for it.
            return [array_slice($words, 0, $count - 1), [$words[$count - 1]]];
        }

        $start = $head;

        while ($start > 0 && in_array($words[$start - 1], self::TYPE_MODIFIERS, true)) {
            $start--;
        }

        // Never leave the key empty when the title has words in front of the
        // type at all: "Toner Pad" keeps a key word to vary.
        if ($start === 0 && $head > 0) {
            $start = 1;
        }

        return [array_slice($words, 0, $start), array_slice($words, $start)];
    }

    /**
     * Drop key words from the end, then type modifiers, until the longest
     * order fits MAX_WORDS and MAX_LENGTH.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function fit(array $brand, array $key, array $type): array
    {
        $too = static function () use (&$brand, &$key, &$type): bool {
            $words = array_merge($brand, $key, $type);
            $length = strlen(implode('-', $words)) + ($brand === [] ? 0 : 3);

            return count($words) > self::MAX_WORDS || $length > self::MAX_LENGTH;
        };

        /*
         * The pack size goes before any word of the name. "medicube PDRN Pink
         * Collagen Jelly Eye Mask 6 pairs" is nine words; popping the key
         * first dropped "jelly" -- the word that says what the product is --
         * and kept "6 pairs", which nobody searches a picture by. Only a bare
         * number ("6", "50ml") and a count word right after a number ("pairs",
         * "ea") go here; a type head ("60 patches") keeps its head.
         */
        foreach (['type', 'key'] as $group) {
            for ($i = count($$group) - 1; $i >= 0 && $too(); $i--) {
                $word = $$group[$i];
                $afterNumber = $i > 0 && preg_match('/^\d+$/', $$group[$i - 1]) === 1;

                if ($afterNumber && in_array($word, self::COUNT_WORDS, true)) {
                    // "6 pairs" goes as one: a lone "6" left behind means nothing.
                    array_splice($$group, $i - 1, 2);
                    $i--;
                } elseif (preg_match(self::AMOUNT, $word) === 1) {
                    array_splice($$group, $i, 1);
                }
            }
        }

        while ($too() && $key !== []) {
            array_pop($key);
        }

        while ($too() && count($type) > 1) {
            array_shift($type);
        }

        return [$key, $type];
    }

    private static function join(array $words): string
    {
        $stem = implode('-', array_values(array_filter($words, static fn ($w) => $w !== '')));

        if (strlen($stem) > self::MAX_LENGTH) {
            $stem = rtrim(substr($stem, 0, self::MAX_LENGTH), '-');
            // Never end on half a word.
            $cut = strrpos($stem, '-');
            $stem = $cut !== false && $cut > 20 ? substr($stem, 0, $cut) : $stem;
        }

        return $stem;
    }

    private static function suffixed(string $stem, string $suffix): string
    {
        $max = self::MAX_LENGTH - strlen($suffix) - 1;

        if (strlen($stem) > $max) {
            $stem = rtrim(substr($stem, 0, $max), '-');
        }

        return $stem.'-'.$suffix;
    }
}
