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
 *
 * Google asks for names that are "short, but descriptive", so what describes
 * nothing goes first: the pack size, an "fl oz" conversion, a PA++++ rating
 * ("pa"), "NEW". A number that IS the name ("snail 96", "345 relief", "30
 * days", "toner 2.0") stays with its word, and a set's type is its contents
 * plus its set word ("cream-mist-spray-set"). Lane IN trained all of it on
 * tests/Fixtures/image-namer-titles.php; a title that comes out wrong belongs
 * there, where every rule then holds it.
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

    /** A count that only means something after its number: "6 pairs", "30 ea", "70 pads". */
    private const COUNT_WORDS = 'ea|pcs|pc|pieces|piece|pairs|pair|sheets|sheet|patches|pads|items|item|caps|capsules|ct|count|tablets|sticks|masks|steps|step';

    /** A measure: 50ml, 100 g, 5.5g. The unit may be glued on or spaced. */
    private const MEASURE = '\\d+(?:[.,]\\d+)?\\s*(?:ml|g|kg|mg|oz)(?![a-z])';

    /** Joining words: they carry no meaning in a filename. */
    private const STOP = ['a', 'an', 'the', 'and', 'or', 'with', 'for', 'of', 'in', 'on', 'to', 'by', 'from', 'at', 'x', 'plus', 'amp'];

    /** Words a filename says nothing with: "NEW" goes stale the day it is true, "(New Version)" with it. */
    private const NOISE = ['new', 'version'];

    /** What makes a title a set: the word that names the bundle. "2-pack" and "set of 3" are glued to one token first. */
    private const SET_WORDS = ['set', 'kit', 'duo', 'trio', 'bundle', 'collection'];

    /** Words that sit inside a set's own phrase: TRAVEL kit, HOLIDAY EDITION set, SKIN CARE bundle. */
    private const SET_MODIFIERS = ['gift', 'travel', 'trial', 'starter', 'mini', 'special', 'holiday', 'edition', 'routine', 'discovery', 'refill', 'skin', 'care', 'skincare', 'value', 'limited', 'sample'];

    /** A version or grade that stays with the type it follows: Booster PRO, Cream EX, Serum VI. */
    private const VERSION_WORDS = ['ex', 'pro', 'max', 'rich', 'lite', 'ii', 'iii', 'iv', 'vi', 'vii', 'viii', 'ix', '2x', '3x'];

    /** Nouns a number counts and is read with: "30 Days", "4 Step". */
    private const COUNTED = ['days', 'day', 'hours', 'hour', 'weeks', 'week', 'times', 'minutes', 'layers', 'layer', 'peptide', 'peptides'];

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
     * Every group is a list of plain words. Inside, a number and the word it
     * belongs to ("snail 96", "30 days", "345 relief"), a pack size ("6
     * pairs") and a multi-pack ("2 pack") are each read as one unit, so no
     * rule can part them; they are split into words only on the way out.
     *
     * @return array{brand: list<string>, key: list<string>, type: list<string>, ordered: list<string>}
     */
    public static function groups(?string $brand, string $name): array
    {
        $brandTokens = self::words((string) $brand, false);
        $rest = self::tokens($name, $brandTokens);

        [$key, $type, $anchor, $set, $protect] = self::split($rest);
        [$key, $type] = self::fit($brandTokens, $rest, $key, $type, $set, $protect);

        // Title order, with the type read whole where the title named it: a
        // set's contents ("sheet mask … set of 3") come together at the set.
        $before = $after = [];

        foreach ($key as $i) {
            if ($anchor === null || $i < $anchor) {
                $before[] = $rest[$i];
            } else {
                $after[] = $rest[$i];
            }
        }

        $type = array_map(static fn (int $i): string => $rest[$i], $type);

        return [
            'brand' => $brandTokens,
            'key' => self::flatten(array_merge($before, $after)),
            'type' => self::flatten($type),
            'ordered' => self::flatten(array_merge($before, $type, $after)),
        ];
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
        // "By Wishtrend", "Some By Mi": the brand already says it, and
        // "by-by-wishtrend" says it twice.
        $by = $b === [] || in_array('by', $b, true) ? $b : array_merge(['by'], $b);

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
     * A title as tokens: the brand gone wherever and however it is written,
     * joining words and noise gone, and every unit glued with "_" so nothing
     * downstream can tear it in two.
     *
     * @param  list<string>  $brand
     * @return list<string>
     */
    private static function tokens(string $title, array $brand): array
    {
        $t = (string) preg_replace('/[\p{Arabic}]+/u', ' ', $title);
        $t = (string) preg_replace("/['’‘`´]/u", '', $t);
        $t = (string) preg_replace('/[™®©℠]/u', ' ', $t);
        $t = strtolower(Str::ascii($t, 'en'));
        $m = self::MEASURE;
        $c = self::COUNT_WORDS;
        $stop = implode('|', self::STOP);

        foreach ([
            // R.E.D is one word, not three letters.
            ['/\b(?:[a-z]\.){2,}[a-z]?(?![a-z])/', static fn (array $x): string => ' '.str_replace('.', '', $x[0]).' '],
            // "50ml / 1.69 fl.oz": the conversion says the size twice.
            ['/[\/(]?\s*\d+(?:[.,]\d+)?\s*fl\.?\s*oz\.?\s*\)?/', ' '],
            // PA++++ is a rating a filename cannot spell; "pa" alone means nothing.
            ['/\bpa\s*\++/', ' '],
            ['/\bspf\s*(\d+)\s*\+*/', ' spf$1 '],
            // AXIS-Y 6+1+1 is a name. 1+1 is two of the product.
            ['/(?<![\d.+])\d+(?:\s*\+\s*\d+){2,}(?![\d.+])/', static fn (array $x): string => ' '.preg_replace('/\s*\+\s*/', '_', $x[0]).' '],
            ['/(?<![\d.+])(\d+)\s*\+\s*(\d+)(?![\d.+])/', static fn (array $x): string => ' '.((int) $x[1] + (int) $x[2]).'_pack '],
            ['/\bpack\s+of\s+(\d+)\b/', ' $1_pack '],
            ['/\bset\s+of\s+(\d+)\b/', ' set_of_$1 '],
            ['/\btwin\s*-?\s*pack\b/', ' twin_pack '],
            ['/\b(\d+)\s*-?\s*packs?\b/', ' $1_pack '],
            // 2 x 100ml, 30ml x 10ea, 100ml x2, a lone x3. "2X" in a name has no size after it.
            ['/\b(\d+)(?:\s+x\s+|x)('.$m.')/', ' $2 $1_pack '],
            ['/('.$m.')\s*x\s*(\d+)\s*('.$c.')\b/', ' $1 $2_$3 '],
            ['/('.$m.')\s*x\s*(\d+)\b/', ' $1 $2_pack '],
            ['/(?<![a-z0-9])x\s*(\d+)\b/', ' $1_pack '],
            // A size or a count is one unit.
            ['/\b(\d+)(?:[.,](\d+))?\s*(ml|g|kg|mg|oz)(?![a-z])/', static fn (array $x): string => ' '.$x[1].($x[2] !== '' ? '_'.$x[2] : '').$x[3].' '],
            ['/\b(\d+)\s*('.$c.')\b/', ' $1_$2 '],
            // No.5, 2.0, 9-Peptide, DIVE-IN, Go-To.
            ['/\bno\.?\s*(\d+)\b/', ' no_$1 '],
            ['/\b(\d+)[.,](\d+)\b/', '$1_$2'],
            ['/\b(\d+)-([a-z]+)\b/', '$1_$2'],
            ['/\b([a-z0-9]+)-('.$stop.')\b/', '$1_$2'],
            ['/\b('.$stop.')-([a-z0-9]+)\b/', '$1_$2'],
        ] as [$pattern, $with]) {
            $t = is_string($with) ? (string) preg_replace($pattern, $with, $t) : (string) preg_replace_callback($pattern, $with, $t);
        }

        $tokens = array_values(array_filter(array_map(
            static fn (string $w): string => trim($w, '_'),
            preg_split('/[^a-z0-9_]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ), static fn (string $w): bool => $w !== ''));

        $tokens = self::withoutBrand($tokens, $brand);
        $tokens = self::glueNumbers($tokens);

        $tokens = array_values(array_filter($tokens, static fn ($w): bool => is_string($w)
            && ! in_array($w, self::STOP, true) && ! in_array($w, self::NOISE, true)));

        return self::unique($tokens, $brand);
    }

    /**
     * Every run of title tokens that spells the brand becomes null, however it
     * is written: "Dr. Jart+" and "Dr.Jart+", "Skin 1004" and "SKIN1004",
     * "Manyo" and "ma:nyo", "Mary & May" and "Mary&May".
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $brand
     * @return list<string|null>
     */
    private static function withoutBrand(array $tokens, array $brand): array
    {
        $want = implode('', $brand);

        if ($want === '') {
            return $tokens;
        }

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $have = '';

            for ($j = $i; $j < $n && strlen($have) < strlen($want); $j++) {
                $have .= str_replace('_', '', (string) $tokens[$j]);

                if ($have === $want) {
                    for ($k = $i; $k <= $j; $k++) {
                        $tokens[$k] = null;
                    }

                    $i = $j;

                    break;
                }
            }
        }

        return $tokens;
    }

    /**
     * A number that is part of the name travels with its word: "snail 96",
     * "heartleaf 77", "345 relief" (the brand is on its left), "30 days".
     * A number after a type ("toner 2.0", "set 2026") is a version and stays
     * with the type instead, so it is left alone here.
     *
     * @param  list<string|null>  $t
     * @return list<string|null|false>
     */
    private static function glueNumbers(array $t): array
    {
        $plain = static fn ($w): bool => is_string($w) && preg_match('/^[a-z][a-z0-9]*$/', $w) === 1
            && preg_match('/^spf\d*$/', $w) !== 1 && ! in_array($w, self::STOP, true) && ! in_array($w, self::NOISE, true)
            && ! in_array($w, self::TYPE_HEADS, true) && ! in_array($w, self::SET_WORDS, true);

        for ($i = 0, $n = count($t); $i < $n; $i++) {
            if (! is_string($t[$i]) || ! self::isNumber($t[$i])) {
                continue;
            }

            $left = $t[$i - 1] ?? null;
            $right = $t[$i + 1] ?? null;

            if ($plain($right) && in_array($right, self::COUNTED, true)) {
                $t[$i + 1] = $t[$i].'_'.$right;
                $t[$i++] = false;
            } elseif ($plain($left)) {
                $t[$i - 1] = $left.'_'.$t[$i];
                $t[$i] = false;
            } elseif ($plain($right)) {
                $t[$i + 1] = $t[$i].'_'.$right;
                $t[$i++] = false;
            }
        }

        return $t;
    }

    /**
     * A word written twice appears once, a unit whose word is already there
     * goes ("pads … 70 pads"), and no word repeats the brand. Numbers may
     * repeat ("6+1+1").
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $brand
     * @return list<string>
     */
    private static function unique(array $tokens, array $brand): array
    {
        $seen = array_fill_keys(array_diff($brand, self::STOP), true);
        $out = [];

        foreach ($tokens as $token) {
            $words = array_values(array_filter(explode('_', $token), static fn (string $p): bool => ! ctype_digit($p)));

            // A number glued to its word ("no_3", "no_5", "c_23") is its own
            // thing; any other token goes when one of its words is already in.
            $glued = str_contains($token, '_') && ! self::isPack($token) && ! self::isSetWord($token);

            if ($glued ? isset($seen[$token]) : array_intersect_key(array_flip($words), $seen) !== []) {
                continue;
            }

            $seen[$token] = true;

            foreach ($words as $w) {
                $seen[$w] = true;
            }

            $out[] = $token;
        }

        return $out;
    }

    /** @param list<string> $tokens @return list<string> */
    private static function flatten(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $token) {
            array_push($out, ...explode('_', $token));
        }

        return $out;
    }

    private static function isNumber(string $w): bool
    {
        return preg_match('/^\d+(?:_\d+)*$/', $w) === 1;
    }

    /** A size or a count: 50ml, 5_5g, 6_pairs, 30ea. Never a word of the name. */
    private static function isPack(string $w): bool
    {
        return preg_match('/^\d+(?:_\d+)?(?:ml|g|kg|mg|oz|l)$|^\d+(?:ea|pcs?|ct)$|^\d+_(?:'.self::COUNT_WORDS.')$/', $w) === 1;
    }

    private static function isSetWord(string $w): bool
    {
        return in_array($w, self::SET_WORDS, true) || preg_match('/^(?:\d+_pack|set_of_\d+|twin_pack)$/', $w) === 1;
    }

    /**
     * Whether the token at $i names a product: a type head that is not a set
     * word. "Sun" is one only where nothing follows it but an SPF, a size or a
     * set word ("Centella Unscented Sun SPF50+"), not in "Relief Sun: Rice".
     *
     * @param  list<string>  $w
     */
    private static function isHead(array $w, int $i): bool
    {
        if ($w[$i] === 'sun') {
            $next = $w[$i + 1] ?? null;

            return $next === null || preg_match('/^spf\d+$/', $next) === 1 || self::isPack($next) || self::isSetWord($next);
        }

        return in_array($w[$i], self::TYPE_HEADS, true) && ! self::isSetWord($w[$i]);
    }

    /**
     * Key words and type phrase, as positions in $w.
     *
     * The type is the LAST product head in the title, the modifiers and other
     * heads directly in front of it ("cleansing oil FOAM", "cream mist SPRAY"),
     * and what qualifies it right after (a version "2.0", "EX", an SPF, a size).
     * Anything else after it is a key word: "eye patches WITH RETINOL" reads
     * retinol as what this product is, not as part of what kind it is.
     *
     * A SET's type is its contents plus its set word -- "cream mist spray set",
     * "sheet mask set of 3" -- so no variation ever reads "set-345-relief-…".
     *
     * @param  list<string>  $w
     * @return array{0: list<int>, 1: list<int>, 2: int|null, 3: bool, 4: list<int>}  key, type, anchor, is a set, protected
     */
    private static function split(array $w): array
    {
        $n = count($w);

        if ($n <= 1) {
            return [$n === 1 ? [0] : [], [], null, false, []];
        }

        $modifier = static fn (int $i): bool => in_array($w[$i], self::TYPE_MODIFIERS, true);
        $at = null;

        for ($i = $n - 1; $i >= 0 && $at === null; $i--) {
            $at = self::isSetWord($w[$i]) ? $i : null;
        }

        $set = $at !== null;

        if ($set) {
            // The set's own phrase: "lip sleeping mask trio gift SET", "cream 70ml special SET".
            $start = $at;

            while ($start > 0 && (self::isSetWord($w[$start - 1]) || in_array($w[$start - 1], self::SET_MODIFIERS, true)
                || self::isPack($w[$start - 1]) || self::isHead($w, $start - 1) || $modifier($start - 1))) {
                $start--;
            }

            $type = range($start, $at);
            $anchor = $start;
            $heads = array_values(array_filter($type, static fn (int $i): bool => self::isHead($w, $i)));

            if ($heads === [] && ! array_filter($type, $modifier)) {
                // The contents were named further back: "sheet mask heartleaf fit SET OF 3".
                for ($j = $start - 1; $j >= 0 && ! self::isHead($w, $j); $j--);

                if ($j >= 0) {
                    $from = $j;

                    while ($from > 0 && (self::isHead($w, $from - 1) || $modifier($from - 1))) {
                        $from--;
                    }

                    $type = array_merge(range($from, $j), $type);
                    $heads = [$j];
                } elseif (count($type) === 1 && $start > 0 && ! self::isNumber($w[$start - 1]) && ! self::isPack($w[$start - 1])) {
                    // No product word at all: the word before the set word says what it is ("centella kit").
                    array_unshift($type, $start - 1);
                    $anchor = $start - 1;
                }
            }

            $protect = [$at];

            if ($heads !== []) {
                $protect[] = end($heads);
            }

            $last = $at;
        } else {
            $head = null;

            for ($i = $n - 1; $i >= 0 && $head === null; $i--) {
                $head = self::isHead($w, $i) ? $i : null;
            }

            for ($i = $n - 1; $i >= 0 && $head === null; $i--) {
                $head = preg_match('/^spf\d+$/', $w[$i]) === 1 ? $i : null;
            }

            if ($head === null) {
                // No known type word: the last word that is not a size stands in for it.
                for ($head = $n - 1; $head > 0 && self::isPack($w[$head]); $head--);
            }

            $start = $head;

            while ($start > 0 && (self::isHead($w, $start - 1) || $modifier($start - 1))) {
                $start--;
            }

            // Never leave the key empty when the title has words in front of
            // the type at all: "Toner Pad" keeps a key word to vary.
            if ($start === 0 && $head > 0) {
                $start = 1;
            }

            $type = range($start, $head);
            $anchor = $start;
            $protect = [$head];
            $last = $head;
        }

        // What qualifies the type stays with it: "toner 2.0 150ml", "set 2026", "cream EX".
        for ($i = $last + 1; $i < $n && self::qualifies($w[$i], $set); $i++) {
            $type[] = $i;
        }

        $key = array_values(array_diff(range(0, $n - 1), $type));

        // A set that is all type still keeps a key word to vary ("lip | sleeping mask trio gift set").
        if ($set && $key === [] && $type[0] === 0 && ! in_array(0, $protect, true) && count($type) > 2) {
            $key = [array_shift($type)];
            $anchor = $type[0];
        }

        return [$key, array_values($type), $anchor, $set, $protect];
    }

    /** How many of a set's type words stand before its set word: it keeps at least one. @param list<int> $type @param list<int> $protect */
    private static function before(array $type, array $protect): int
    {
        return $protect === [] ? 0 : count(array_filter($type, static fn (int $i): bool => $i < $protect[0]));
    }

    private static function qualifies(string $w, bool $set): bool
    {
        return self::isNumber($w) || self::isPack($w) || preg_match('/^spf\d+$/', $w) === 1
            || in_array($w, self::VERSION_WORDS, true) || ($set && self::isSetWord($w));
    }

    /**
     * Shorten until the longest order fits MAX_WORDS and MAX_LENGTH, losing
     * what identifies the product last: the pack size first, then a set's
     * extras ("gift", a second set word), then key words from the end, then
     * the type from the front -- never its head, never a set's set word.
     *
     * @param  list<string>  $brand
     * @param  list<string>  $w
     * @param  list<int>  $key
     * @param  list<int>  $type
     * @param  list<int>  $protect
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function fit(array $brand, array $w, array $key, array $type, bool $set, array $protect = []): array
    {
        $too = static function () use (&$key, &$type, $brand, $w): bool {
            $words = $brand;

            foreach (array_merge($key, $type) as $i) {
                array_push($words, ...explode('_', $w[$i]));
            }

            return count($words) > self::MAX_WORDS || strlen(implode('-', $words)) + ($brand === [] ? 0 : 3) > self::MAX_LENGTH;
        };

        /*
         * The pack size goes before any word of the name. "medicube PDRN Pink
         * Collagen Jelly Eye Mask 6 pairs" is nine words; popping the key
         * first dropped "jelly" -- the word that says what the product is --
         * and kept "6 pairs", which nobody searches a picture by. "6 pairs"
         * is one unit, so it goes whole: a lone "6" is never left behind.
         */
        foreach (['type', 'key'] as $group) {
            for ($j = count($$group) - 1; $j >= 0 && $too(); $j--) {
                if (self::isPack($w[$$group[$j]])) {
                    array_splice($$group, $j, 1);
                }
            }
        }

        // A set's extras: "gift", "travel", "trio" in front of "set".
        if ($set) {
            for ($j = 0; $j < count($type) && $too();) {
                $token = $w[$type[$j]];
                $extra = ! in_array($type[$j], $protect, true)
                    && (in_array($token, self::SET_MODIFIERS, true) || self::isSetWord($token))
                    && self::before($type, $protect) > 1;

                if ($extra) {
                    array_splice($type, $j, 1);
                } else {
                    $j++;
                }
            }
        }

        while ($too() && $key !== []) {
            array_pop($key);
        }

        while ($too()) {
            $drop = null;

            foreach ($type as $j => $i) {
                if (! in_array($i, $protect, true) && (! $set || self::before($type, $protect) > 1 || $i > $protect[0])) {
                    $drop = $j;

                    break;
                }
            }

            if ($drop === null) {
                break;
            }

            array_splice($type, $drop, 1);
        }

        return [$key, $type];
    }

    /**
     * Words of a brand or of free text: ASCII, lower case, no Arabic script,
     * no apostrophes.
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

    private static function join(array $words): string
    {
        $stem = str_replace('_', '-', implode('-', array_values(array_filter($words, static fn ($w) => $w !== ''))));

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
