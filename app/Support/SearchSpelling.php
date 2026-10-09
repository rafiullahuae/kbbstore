<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Medicob" → Medicube. Typo-tolerant search, Lane SR (9 October 2026).
 *
 * The owner: "if user search anything with wrong spell, like Medicube >
 * Medicob or Medicobe, the system should auto detect the small miss-spellings
 * and display the results for the corrected ones. our mostly ladies are arabic
 * non-technicals". Store → Site Search → Spelling mistakes → "Fix spelling
 * mistakes", ON as shipped because he asked for it.
 *
 * ── WHEN IT RUNS ─────────────────────────────────────────────────────────────
 *
 * ONLY after the ordinary search has come back empty -- SearchController::
 * suggest() and ShopController::index() call correct() on that branch and on
 * no other. A search that finds something costs exactly what it cost before:
 * no dictionary read, no query, no PHP loop. With the search's every-word AND,
 * one misspelt word ("medicob serum") is enough to empty it, which is the
 * "nothing for one of the words" case.
 *
 * ── WHAT IT MATCHES AGAINST ──────────────────────────────────────────────────
 *
 * A small dictionary built from the catalogue (build()): brand and category
 * names, their published Arabic names, and the distinctive words of visible
 * product names. Every entry maps a FOLDED key to the ENGLISH text the
 * ordinary search can find, so an Arabic-script "ميديكيوب" corrects to
 * "Medicube" -- the products table is English, and that is what the LIKE
 * matches. Built once, cached (Laravel cache + a per-process static), dropped
 * by flush() when a product, brand, category or an Arabic name is saved.
 *
 * ── HOW ───────────────────────────────────────────────────────────────────────
 *
 * fold() reduces both sides to the confusions an Arabic-speaking typist makes
 * in English (c/k/q, ph/f, e/i, o/u, y/i, s/z, p/b, v/f, g/j, doubled letters,
 * a silent final e, spaces and hyphens), and translit() reads Arabic letters
 * into that same alphabet. A typed word whose fold is a dictionary key is a
 * match at distance 0 ("mediqube" and "medicobe" both fold to "midikub", which
 * is Medicube's own fold); otherwise Damerau-Levenshtein (a swap counts as 1)
 * within a length-scaled limit: nothing under 4 letters, 1 edit for 4-5, 2 for
 * 6-9, 3 for 10+. Entries are bucketed by first folded letter, so a lookup reads
 * one bucket, and PHP's native levenshtein() -- never less than half the
 * Damerau distance -- discards almost all of it before the PHP loop runs.
 *
 * Nothing here builds a regex from what the shopper typed: input is split and
 * mapped with fixed patterns, strtr() and byte comparisons only.
 */
final class SearchSpelling
{
    public const CACHE_KEY = 'kbb.search.spelling.v1';

    /** A correction is tried on at most this many characters, and this many words. */
    public const MAX_QUERY = 64;
    public const MAX_WORDS = 8;

    /** A safety net only; flush() is what keeps it current. */
    private const TTL = 21600;

    /** Product-name words kept, most frequent first. ~3,000 products give ~2,600. */
    private const MAX_WORDS_KEPT = 6000;

    /** Separates alternatives that fold alike ("pore" and "pure"). */
    private const ALT = '|';

    private const STOP = [
        'with' => 1, 'from' => 1, 'pack' => 1, 'free' => 1, 'plus' => 1, 'your' => 1,
        'this' => 1, 'that' => 1, 'each' => 1, 'size' => 1, 'pcs' => 1, 'pieces' => 1,
        'edition' => 1, 'version' => 1, 'type' => 1,
    ];

    /** @var array{b: array<string, array<string, string>>, w: array<string, array<string, string>>}|null */
    private static ?array $dict = null;

    /**
     * The per-process half of flush() alone, without touching the cache.
     * Tests\Support\StaticMemos calls it between tests: a dictionary built
     * from one test's products must not answer the next test's search.
     */
    public static function forget(): void
    {
        self::$dict = null;
    }

    public static function flush(): void
    {
        self::$dict = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // A cache that cannot forget must not fail the save that called this.
        }
    }

    /** @return array{b: array<string, array<string, string>>, w: array<string, array<string, string>>} */
    public static function dictionary(): array
    {
        return self::$dict ??= Cache::remember(self::CACHE_KEY, self::TTL, static fn (): array => self::build());
    }

    /**
     * The query with its misspelt words replaced, or null when there is
     * nothing to correct (every word known, too short, or no close match).
     */
    public static function correct(string $q): ?string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));

        if ($q === '' || mb_strlen($q) > self::MAX_QUERY) {
            return null;
        }

        $tokens = explode(' ', $q);

        if (count($tokens) > self::MAX_WORDS) {
            return null;
        }

        $dict = self::dictionary();
        $info = array_map(static fn (string $t): array => self::token($t, $dict), $tokens);
        $out = [];
        $changed = false;
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            /*
             * Two to four words read as one brand or category, longest first:
             * "beautyofjoseon" is one token and is handled below, but "buety of
             * josion", "cos rx", "skin 1004" and "some by mi" are several. Only
             * a run holding an unknown word is tried, so two correct words are
             * never merged into a third thing.
             */
            for ($len = min(4, $n - $i); $len >= 2; $len--) {
                $run = array_slice($info, $i, $len);

                // A short word counts as unsure here ("cos rx", "some by mi"):
                // too short to correct alone, but part of a name that is not;
                // so does a word that is only right once folded ("seuol").
                if (! array_filter($run, static fn ($t) => ! $t['known'] || $t['short'] || $t['fix'] !== null)) {
                    continue;
                }

                $joined = implode(' ', array_slice($tokens, $i, $len));

                // A LIKE metacharacter is part of what was typed; folding it
                // away would search a wildcard by another route (see token()).
                if (self::hasLikeMeta($joined)) {
                    continue;
                }
                $hit = self::best($dict, self::fold($joined), self::limit(self::letters($joined)), ['b'], self::isArabic($joined), false);

                if ($hit !== null) {
                    $out[] = $hit;
                    $changed = true;
                    $i += $len - 1;

                    continue 2;
                }
            }

            $t = $info[$i];

            if ($t['fix'] !== null) {
                $out[] = $t['fix'];
                $changed = true;
            } else {
                $out[] = $tokens[$i];
            }
        }

        $fixed = implode(' ', $out);

        return $changed && mb_strtolower($fixed) !== mb_strtolower($q) ? $fixed : null;
    }

    /**
     * One typed word: is it known, and if not, what does it correct to?
     *
     * @return array{known: bool, short: bool, fix: string|null}
     */
    private static function token(string $raw, array $dict): array
    {
        /*
         * '%Power%' is not a misspelling of "power". The search escapes % and _
         * so a typed wildcard is literal text (StorefrontSqlShapeTest); fold()
         * drops punctuation, so "correcting" such a word would hand the search
         * the bare word and turn the wildcard back into one by another route --
         * '%Power%' found "Niacinamide 30% Power Serum". Taken as typed instead.
         */
        if (self::hasLikeMeta($raw)) {
            return ['known' => true, 'short' => false, 'fix' => null];
        }

        $letters = self::letters($raw);
        $fold = self::fold($raw);
        $arabic = self::isArabic($raw);

        // Too short to correct, or no letters at all: "oil", "50ml", "1025".
        if ($fold === '' || $letters < 4 || ! preg_match('/[a-z]/', $fold)) {
            // "Short" also means a bare number: "skin 1004" is one brand.
            return ['known' => true, 'short' => true, 'fix' => null];
        }

        $lower = mb_strtolower($raw);
        $c = $fold[0];

        foreach (['b', 'w'] as $kind) {
            $display = $dict[$kind][$c][$fold] ?? null;

            if ($display !== null) {
                $alts = explode(self::ALT, $display);

                return in_array($lower, array_map('mb_strtolower', $alts), true)
                    ? ['known' => true, 'short' => false, 'fix' => null]
                    : ['known' => true, 'short' => false, 'fix' => $alts[0]];
            }
        }

        if (preg_match('/\d/', $fold)) {
            // "spf50", "10ml": a code, not a word.
            return ['known' => true, 'short' => false, 'fix' => null];
        }

        // No prefix matching for Arabic letters: it is for a brand still being
        // typed in English, and on Arabic words it guesses ("واقي", "sun
        // protection", read as the start of "water").
        return ['known' => false, 'short' => false, 'fix' => self::best($dict, $fold, self::limit($letters), ['b', 'w'], $arabic, ! $arabic)];
    }

    /** Holds a LIKE metacharacter (%, _ or the escape backslash). */
    private static function hasLikeMeta(string $s): bool
    {
        return strpbrk($s, '%_\\') !== false;
    }

    /** Edits allowed for a word this many letters long. */
    private static function limit(int $letters): int
    {
        return match (true) {
            $letters < 4 => 0,
            $letters <= 5 => 1,
            $letters <= 9 => 2,
            default => 3,
        };
    }

    /**
     * The closest entry within $max edits, or null.
     *
     * Lower score wins: distance first (x4), then a brand or category over a
     * product word (+2), then a whole match over a prefix of a longer name
     * (+3, the dropdown case: "medico" on the way to "medicube").
     *
     * @param list<string> $kinds
     */
    private static function best(array $dict, string $fold, int $max, array $kinds, bool $arabic, bool $prefix): ?string
    {
        if ($max === 0 || $fold === '') {
            return null;
        }

        $folds = [$fold];

        // An Arabic word opening on a bare alef before a vowel ("ايزنتري")
        // writes the vowel twice; the brand's own fold starts on the vowel.
        if ($arabic && strlen($fold) > 3 && $fold[0] === 'a' && strpbrk($fold[1], 'iu') !== false) {
            $folds[] = substr($fold, 1);
        }

        $bestScore = PHP_INT_MAX;
        $bestText = null;

        foreach ($folds as $f) {
            $lf = strlen($f);
            $skel = $arabic ? self::skeleton($f) : '';

            foreach ($kinds as $kind) {
                $penalty = $kind === 'b' ? 0 : 2;

                foreach ($dict[$kind][$f[0]] ?? [] as $key => $text) {
                    $key = (string) $key;
                    $lk = strlen($key);
                    $d = $max + 1;
                    $score = PHP_INT_MAX;

                    if (abs($lk - $lf) <= $max && levenshtein($f, $key) <= 2 * $max) {
                        $d = self::distance($f, $key, $max);
                    }

                    // Arabic is written without its short vowels, so two words
                    // with the same consonants in the same order are close.
                    if ($d > $max && $arabic && strlen($skel) >= 3 && self::skeleton($key) === $skel) {
                        $d = $max;
                    }

                    if ($d <= $max) {
                        $score = $d * 4 + $penalty;
                    } elseif ($prefix && $lf >= 4 && $lk > $lf) {
                        $head = substr($key, 0, $lf);

                        if (levenshtein($f, $head) <= 2 * $max && ($p = self::distance($f, $head, $max)) <= $max) {
                            $score = $p * 4 + $penalty + 3;
                        }
                    }

                    if ($score < $bestScore || ($score === $bestScore && $bestText !== null && strlen((string) $text) < strlen($bestText))) {
                        $bestScore = $score;
                        $bestText = explode(self::ALT, (string) $text)[0];
                    }
                }
            }
        }

        return $bestText;
    }

    /**
     * Optimal-string-alignment Damerau-Levenshtein on two folded (ASCII)
     * strings, giving up once every cell of a row is past $max.
     */
    public static function distance(string $a, string $b, int $max = PHP_INT_MAX): int
    {
        $la = strlen($a);
        $lb = strlen($b);

        if ($la === 0 || $lb === 0) {
            return max($la, $lb);
        }

        $prev2 = [];
        $prev = range(0, $lb);

        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            $rowMin = $i;
            $ai = $a[$i - 1];

            for ($j = 1; $j <= $lb; $j++) {
                $v = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($ai === $b[$j - 1] ? 0 : 1));

                if ($i > 1 && $j > 1 && $ai === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $v = min($v, $prev2[$j - 2] + 1);
                }

                $cur[$j] = $v;

                if ($v < $rowMin) {
                    $rowMin = $v;
                }
            }

            if ($rowMin > $max) {
                return $max + 1;
            }

            $prev2 = $prev;
            $prev = $cur;
        }

        return $prev[$lb];
    }

    /**
     * The matching form: Arabic read into Latin, lowercase, no accents, no
     * spaces or punctuation, the common confusions folded, doubled letters
     * collapsed. "Beauty of Joseon" and "beautyofjoseon" are the same string;
     * so are "Medicube", "medicobe" and "mediqube".
     */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s);

        if (self::isArabic($s)) {
            $s = self::translit($s);
        }

        if (class_exists(\Normalizer::class)) {
            $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_D);
        }

        // Accents go with every other non-ASCII mark; what is left is a-z0-9.
        $s = (string) preg_replace('/[^a-z0-9]+/', '', $s);

        // A silent final e ("medicube", "toner" untouched): the Arabic
        // spelling has no letter for it.
        if (strlen($s) > 4 && str_ends_with($s, 'e')) {
            $s = substr($s, 0, -1);
        }

        $s = strtr($s, ['ph' => 'f', 'ck' => 'k', 'kh' => 'k', 'gh' => 'g', 'th' => 't', 'x' => 'ks']);
        $s = strtr($s, 'cqzyeowgpv', 'kksiiuujbf');

        return (string) preg_replace('/([a-z])\1+/', '$1', $s);
    }

    /** The fold without its vowels, first letter kept. */
    private static function skeleton(string $fold): string
    {
        return $fold === '' ? '' : $fold[0] . strtr(substr($fold, 1), ['a' => '', 'i' => '', 'u' => '']);
    }

    public static function isArabic(string $s): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $s);
    }

    /**
     * Arabic spelling normalised: alef forms to bare alef, taa marbuta to haa,
     * alef maqsura to yaa, no tatweel, no diacritics, Arabic-Indic digits.
     */
    public static function normaliseArabic(string $s): string
    {
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);

        return strtr($s, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** Arabic letters read into Latin, for matching only -- never shown. */
    public static function translit(string $s): string
    {
        $s = self::normaliseArabic($s);

        // A word-final haa is usually a vowel ending, not an h.
        $s = (string) preg_replace('/ه(?=\s|$)/u', 'a', $s);

        return strtr($s, [
            'ا' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 's', 'ج' => 'j', 'ح' => 'h', 'خ' => 'k',
            'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
            'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'g', 'ف' => 'f', 'ق' => 'k',
            'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'و' => 'u', 'ي' => 'i',
            'ء' => '', 'ئ' => 'i', 'ؤ' => 'u', 'پ' => 'b', 'چ' => 'j', 'ڤ' => 'f', 'گ' => 'g',
            'ک' => 'k', 'ی' => 'i',
        ]);
    }

    /** Letters and digits in a typed word, in any script. */
    private static function letters(string $s): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}]/u', $s);
    }

    /**
     * The dictionary, from five narrow reads.
     *
     * 'b' holds brands and categories (and their Arabic names, folded through
     * translit()), 'w' the product-name words; each is bucketed by the first
     * letter of the fold, and each entry is fold => the English to search for.
     *
     * @return array{b: array<string, array<string, string>>, w: array<string, array<string, string>>}
     */
    public static function build(): array
    {
        $dict = ['b' => [], 'w' => []];

        $brands = DB::table('brands')->pluck('name', 'id')->all();
        $cats = DB::table('categories')->pluck('name', 'id')->all();

        $add = static function (string $kind, string $source, string $display) use (&$dict): void {
            $f = self::fold($source);

            if (strlen($f) < 2) {
                return;
            }

            $have = $dict[$kind][$f[0]][$f] ?? null;

            if ($have === null) {
                $dict[$kind][$f[0]][$f] = $display;
            } elseif (! in_array($display, explode(self::ALT, $have), true)) {
                $dict[$kind][$f[0]][$f] = $have . self::ALT . $display;
            }
        };

        foreach ($brands as $name) {
            $add('b', (string) $name, (string) $name);
        }

        foreach ($cats as $name) {
            $add('b', (string) $name, (string) $name);
        }

        try {
            $arabic = DB::table('translations')
                ->where('locale', 'ar')
                ->where('status', 'published')
                ->whereIn('group', ['brands', 'categories'])
                ->where('field', 'name')
                ->get(['group', 'item_id', 'value']);
        } catch (\Throwable) {
            $arabic = collect();
        }

        foreach ($arabic as $row) {
            $english = $row->group === 'brands' ? ($brands[$row->item_id] ?? null) : ($cats[$row->item_id] ?? null);

            if ($english !== null && trim((string) $row->value) !== '') {
                $add('b', (string) $row->value, (string) $english);
            }
        }

        // Product words, most frequent first, so a fold shared by two words
        // offers the commoner one first.
        $count = [];
        $skip = [];

        foreach (array_merge($brands, $cats) as $name) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $name), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                $count[$w] = ($count[$w] ?? 0) + 1;
            }

            $skip[mb_strtolower((string) $name)] = true;
        }

        $names = DB::table('products')
            ->where('status', 'publish')
            ->where('is_visible', true)
            ->pluck('name');

        foreach ($names as $name) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $name), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                $count[$w] = ($count[$w] ?? 0) + 1;
            }
        }

        arsort($count);
        $kept = 0;

        foreach ($count as $w => $n) {
            $w = (string) $w;

            if (mb_strlen($w) < 4 || isset(self::STOP[$w]) || isset($skip[$w]) || preg_match('/\d/', $w)
                || self::isArabic($w)) {
                continue;
            }

            $add('w', $w, $w);

            if (++$kept >= self::MAX_WORDS_KEPT) {
                break;
            }
        }

        return $dict;
    }
}
