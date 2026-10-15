<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid search: an ARABIC query finds the shop's products (Lane AR round 3).
 *
 * The owner: "in arabic if searched in english or arabic, it should give
 * results. i want a hybrid system which works from both languages side ...
 * it should not messed or disturb anything in english version as the site is
 * live."
 *
 * ── WHEN IT RUNS ─────────────────────────────────────────────────────────────
 *
 * Only for a query that has Arabic letters in it (isArabic()), on either
 * language's pages. A query in Latin letters never reaches this class: the
 * English search runs exactly the statements it ran before, on the English
 * shop and on /ar alike, which is how "an English query on /ar still finds
 * everything" stays true without being touched.
 *
 * ── WHAT AN ARABIC QUERY MATCHES ─────────────────────────────────────────────
 *
 * The query is normalised (normalise()) and split into words; runs of words
 * that are a known phrase are kept together (plan()). Each word or phrase then
 * matches a product in ANY of four ways, and every one of them must match
 * (the same "every word" rule the English box uses):
 *
 *   1. its published Arabic NAME contains the word -- through the precomputed,
 *      normalised copy in `search_texts_ar` (TABLE), never by normalising rows
 *      at query time;
 *   2. its BRAND is the one the word names: the brand's published Arabic name,
 *      or one of the owner's spellings in BRANDS ("أنوا" → Anua);
 *   3. it is in a CATEGORY the word names (the category's published Arabic
 *      name, or the start of one of its words: "واقي" → واقيات الشمس);
 *   4. its English name contains the English for the word (TERMS, then the
 *      English synonym groups of SearchTerms::expand(): "سيروم" → serum,
 *      ampoule, essence).
 *
 * ── WHAT IT COSTS ────────────────────────────────────────────────────────────
 *
 * The four arms are sub-selects of the ONE product statement the box already
 * runs, so a page of 3 products and a page of 40 cost the same number of
 * queries (ArabicSearchTest proves it). The phrase map (map()) is four small
 * queries built once and cached for 15 minutes, dropped whenever a brand,
 * category or Arabic name is saved (SearchSpelling::flush() calls flush()).
 *
 * ── SAFE ─────────────────────────────────────────────────────────────────────
 *
 * Every value reaches SQL bound; every LIKE goes through SearchTerms::like()
 * (wildcards made literal, explicit ESCAPE); nothing builds a pattern or a
 * regex from what was typed. A table that is not there yet (a request during
 * an update, before its migration has run) turns arm 1 off and nothing else.
 */
final class ArabicSearch
{
    /** product_id → the normalised published Arabic name. Filled by Translation's hook and the migration. */
    public const TABLE = 'search_texts_ar';

    public const CACHE_KEY = 'kbb.search.ar.v1';

    /** Words of a query looked at, and the longest phrase tried. */
    public const MAX_WORDS = 8;

    private const PHRASE = 4;

    /**
     * Shop words in Arabic → the English the catalogue is written in. Written
     * as a shopper types them; normalised on the way into the map, so "واقٍ"
     * and "واقي", "مرطّب" and "مرطب" are one entry.
     *
     * @var array<string, string>
     */
    public const TERMS = [
        'سيروم' => 'serum', 'سيرم' => 'serum', 'أمبول' => 'ampoule', 'أمبولة' => 'ampoule', 'إيسنس' => 'essence', 'ايسنس' => 'essence', 'جوهر' => 'essence',
        'تونر' => 'toner', 'تونك' => 'tonic', 'مرطب' => 'moisturiser', 'مرطبات' => 'moisturiser', 'كريم' => 'cream', 'كريمات' => 'cream', 'لوشن' => 'lotion',
        'واقي شمس' => 'sunscreen', 'واقي الشمس' => 'sunscreen', 'واقي' => 'sunscreen', 'صن بلوك' => 'sunscreen', 'حماية من الشمس' => 'sunscreen',
        'غسول' => 'cleanser', 'منظف' => 'cleanser', 'منظفات' => 'cleanser', 'رغوة' => 'cleansing foam', 'زيت منظف' => 'cleansing oil', 'بلسم منظف' => 'cleansing balm',
        'قناع' => 'mask', 'أقنعة' => 'mask', 'ماسك' => 'mask', 'ماسكات' => 'mask', 'قناع نوم' => 'sleeping mask', 'باد' => 'pad', 'وسادات' => 'pad', 'وسادة' => 'pad',
        'بلسم شفاه' => 'lip balm', 'شفاه' => 'lip', 'كريم عين' => 'eye cream', 'كريم العين' => 'eye cream', 'عين' => 'eye', 'العين' => 'eye', 'باتش' => 'patch', 'لصقات' => 'patch',
        'مقشر' => 'exfoliator', 'تقشير' => 'peeling', 'حب الشباب' => 'acne', 'بثور' => 'blemish', 'تفتيح' => 'brightening', 'نضارة' => 'radiance',
        'ترطيب' => 'hydrating', 'تجاعيد' => 'wrinkle', 'مكافحة الشيخوخة' => 'anti aging', 'حساسة' => 'sensitive', 'مهدئ' => 'soothing', 'تهدئة' => 'soothing',
        'كوشن' => 'cushion', 'حلزون' => 'snail', 'فيتامين سي' => 'vitamin c', 'هيالورونيك' => 'hyaluronic', 'هيالورون' => 'hyaluron', 'نياسيناميد' => 'niacinamide',
        'سنتيلا' => 'centella', 'سيكا' => 'cica', 'ماديكا' => 'madeca', 'ريتينول' => 'retinol', 'سيراميد' => 'ceramide', 'كولاجين' => 'collagen', 'ببتيد' => 'peptide',
        'أرز' => 'rice', 'شاي أخضر' => 'green tea', 'حمض الأزيليك' => 'azelaic', 'أزيليك' => 'azelaic', 'بروبيوتيك' => 'probiotic', 'كبسولات' => 'capsule', 'كبسولة' => 'capsule',
        'بقع داكنة' => 'dark spot', 'البقع الداكنة' => 'dark spot', 'مسام' => 'pore', 'رذاذ' => 'mist', 'بخاخ' => 'mist', 'جل' => 'gel', 'زيت' => 'oil', 'شامبو' => 'shampoo',
        'مجموعة' => 'set', 'طقم' => 'set', 'عصا' => 'stick', 'ستيك' => 'stick', 'جهاز' => 'device', 'بي دي آر إن' => 'pdrn', 'بوستر' => 'booster',
    ];

    /**
     * The owner's Arabic spellings of the brands (kbeautyarabia list, his
     * decision of 15 October), by the brand's English name in lower case --
     * matched to the shop's own brand rows at run time, so a brand the shop
     * does not stock is simply not in the map. A brand's PUBLISHED Arabic name
     * is added on top of these, from the translations table.
     *
     * @var array<string, list<string>>
     */
    public const BRANDS = [
        'anua' => ['أنوا', 'انوا', 'أنوع'], 'medicube' => ['ميديكيوب', 'ميديكوب', 'ميدي كيوب'], 'cosrx' => ['كوسركس', 'كوزركس'],
        'beauty of joseon' => ['بيوتي أوف جوسون', 'بيوتي اوف جوسيون', 'جوسون'], 'skin1004' => ['سكين 1004', 'سكين1004'], 'skin 1004' => ['سكين 1004', 'سكين1004'],
        'round lab' => ['مختبر الجولة', 'راوند لاب'], 'roundlab' => ['مختبر الجولة', 'راوند لاب'], 'torriden' => ['توريدن'], 'some by mi' => ['سم باي مي', 'سوم باي مي'],
        'isntree' => ['إيزنتري', 'ايزنتري'], 'axis-y' => ['أكسيس واي', 'اكسيس واي'], 'mixsoon' => ['ميكسسون', 'مكسون'], 'haruharu wonder' => ['هاروهارو', 'هارو هارو'],
        'haruharu' => ['هاروهارو', 'هارو هارو'], "d'alba" => ['دالبا'], 'dalba' => ['دالبا'], 'dr.althea' => ['دكتور ألثيا', 'د. ألثيا', 'ألثيا'], 'dr. althea' => ['دكتور ألثيا', 'ألثيا'],
        'aplb' => ['إيه بي إل بي', 'ايه بي ال بي'], 'abib' => ['أبيب', 'ابيب'], 'aromatica' => ['أروماتيكا'], 'aprilskin' => ['أبريل سكين'],
        "a'pieu" => ['أبيو'], 'klairs' => ['كليرز'], 'dear, klairs' => ['كليرز'], 'innisfree' => ['إنيسفري', 'انيسفري'], 'laneige' => ['لانيج'], 'missha' => ['ميشا'],
        'etude' => ['إتود', 'اتود'], 'heimish' => ['هيميش'], 'purito' => ['بوريتو'], 'pyunkang yul' => ['بيونكانغ يول'], 'tocobo' => ['توكوبو'], 'vt cosmetics' => ['في تي', 'مستحضرات التجميل في تي'],
        'medi-peel' => ['ميدي بيل'], 'numbuzin' => ['نمبوزين'], 'by wishtrend' => ['باي ويش ترند'], 'benton' => ['بينتون'], 'neogen' => ['نيوجين'], 'iunik' => ['آي يونيك', 'ايونيك'],
        'centellian24' => ['سنتليان 24'], 'skinfood' => ['سكين فود'], 'banila co' => ['بانيلا كو'], 'holika holika' => ['هوليكا هوليكا'], 'tirtir' => ['تيرتير'], 'arencia' => ['أرينسيا'],
    ];

    /** Does this text have Arabic letters in it? Same range SearchSpelling uses. */
    public static function isArabic(string $s): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $s);
    }

    /**
     * The one spelling both sides are compared in: no tashkeel or tatweel;
     * أ إ آ ٱ → ا, ة → ه, ى → ي, ؤ → و, ئ → ي; Arabic-Indic digits → 0-9; Latin
     * lower case; punctuation to spaces; single spaces. The migration that
     * fills TABLE carries a copy of these lines (it may not call this class),
     * and ArabicSearchTest holds the two together.
     */
    public static function normalise(string $s): string
    {
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $s = mb_strtolower($s);
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * The query's words, normalised, with the definite article taken off a
     * word long enough to keep a stem ("الشمس" → "شمس"), at most MAX_WORDS.
     *
     * @return list<string>
     */
    public static function words(string $s): array
    {
        $out = [];

        foreach (explode(' ', self::normalise($s)) as $w) {
            if ($w === '') {
                continue;
            }

            if (mb_strlen($w) > 3 && str_starts_with($w, 'ال')) {
                $w = mb_substr($w, 2);
            }

            // A single letter names nothing and would match most of the shop.
            if (mb_strlen($w) < 2) {
                continue;
            }

            $out[] = $w;
        }

        return array_slice($out, 0, self::MAX_WORDS);
    }

    public static function phraseKey(string $s): string
    {
        return implode(' ', self::words($s));
    }

    /**
     * The phrase map: normalised phrase → the brands, categories and English
     * terms it names; and each category's and brand's words for "starts with".
     *
     * @return array{p: array<string, array{b: list<int>, c: list<int>, t: list<string>}>, cw: array<int, list<string>>, bw: array<int, list<string>>, ar: array<string, string>}
     */
    public static function map(): array
    {
        return Cache::remember(self::CACHE_KEY, 900, static function (): array {
            $p = [];
            $add = static function (string $phrase, string $kind, $value) use (&$p): void {
                $key = self::phraseKey($phrase);

                if ($key === '' || ! self::isArabic($key)) {
                    return;
                }

                $p[$key] ??= ['b' => [], 'c' => [], 't' => []];

                if (! in_array($value, $p[$key][$kind], true)) {
                    $p[$key][$kind][] = $value;
                }
            };

            foreach (self::TERMS as $arabic => $english) {
                $add($arabic, 't', $english);
            }

            $ar = [];
            $bw = [];
            $cw = [];
            $names = DB::table('translations')
                ->where('locale', 'ar')
                ->where('status', 'published')
                ->where('field', 'name')
                ->whereIn('group', ['brands', 'categories'])
                ->get(['group', 'item_id', 'value']);
            $published = [];
            foreach ($names as $row) {
                $published[(string) $row->group][(int) $row->item_id] = (string) $row->value;
            }

            foreach (DB::table('brands')->get(['id', 'name']) as $b) {
                $id = (int) $b->id;
                $spellings = self::BRANDS[mb_strtolower(trim((string) $b->name))] ?? [];

                if (isset($published['brands'][$id])) {
                    $spellings[] = $published['brands'][$id];
                    $ar[mb_strtolower(trim((string) $b->name))] = $published['brands'][$id];
                } elseif ($spellings !== []) {
                    $ar[mb_strtolower(trim((string) $b->name))] = $spellings[0];
                }

                foreach ($spellings as $s) {
                    $add($s, 'b', $id);
                    $bw[$id] = array_values(array_unique(array_merge($bw[$id] ?? [], self::words($s))));
                }
            }

            foreach (DB::table('categories')->get(['id', 'name']) as $c) {
                $id = (int) $c->id;

                if (isset($published['categories'][$id])) {
                    $add($published['categories'][$id], 'c', $id);
                    $cw[$id] = self::words($published['categories'][$id]);
                    $ar[mb_strtolower(trim((string) $c->name))] = $published['categories'][$id];
                }
            }

            foreach ($ar as $k => $v) {
                $ar[str_replace(' ', '', $k)] ??= $v;
            }

            return ['p' => $p, 'cw' => $cw, 'bw' => $bw, 'ar' => $ar];
        });
    }

    public static function flush(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Called from model hooks during migrations; a cache that cannot be
            // reached must never take a write down with it.
        }
    }

    /**
     * The query as groups, each of which must match: the normalised word or
     * phrase (`w`), and the brands, categories and English terms it names.
     *
     * @return list<array{w: string, b: list<int>, c: list<int>, t: list<string>}>
     */
    public static function plan(string $q): array
    {
        $words = self::words($q);

        if ($words === []) {
            return [];
        }

        $map = self::map();
        $out = [];
        $n = count($words);

        for ($i = 0; $i < $n;) {
            $hit = null;

            // The longest known phrase starting here wins ("واقي شمس" before "واقي").
            for ($len = min(self::PHRASE, $n - $i); $len >= 1; $len--) {
                $key = implode(' ', array_slice($words, $i, $len));

                if (isset($map['p'][$key])) {
                    $hit = ['w' => $key] + $map['p'][$key];
                    $i += $len;
                    break;
                }
            }

            if ($hit === null) {
                $hit = ['w' => $words[$i], 'b' => [], 'c' => [], 't' => []];
                $i++;
            }

            // Every word of the group, three letters or more, is also the START
            // of one of a category's or a brand's words: "واقي شمس" → واقيات
            // الشمس, "ميديك" → ميديكيوب. All of the group's words, so a phrase
            // names only what it all fits.
            $ws = explode(' ', $hit['w']);

            if (min(array_map('mb_strlen', $ws)) >= 3) {
                foreach (['c' => $map['cw'], 'b' => $map['bw']] as $kind => $index) {
                    foreach ($index as $id => $owned) {
                        $fits = true;

                        foreach ($ws as $w) {
                            $one = false;
                            foreach ($owned as $o) {
                                if (str_starts_with($o, $w)) {
                                    $one = true;
                                    break;
                                }
                            }
                            if (! $one) {
                                $fits = false;
                                break;
                            }
                        }

                        if ($fits && ! in_array($id, $hit[$kind], true)) {
                            $hit[$kind][] = $id;
                        }
                    }
                }
            }

            $out[] = $hit;
        }

        return $out;
    }

    /** The one brand a query is nothing but, or null ("أنوا" → Anua's id). */
    public static function brandOnly(array $plan): ?int
    {
        if (count($plan) !== 1) {
            return null;
        }

        $g = $plan[0];

        return count($g['b']) === 1 && $g['c'] === [] && $g['t'] === [] ? (int) $g['b'][0] : null;
    }

    /**
     * Narrow a products query to the plan: every group matches, each in any of
     * its four ways. Sub-selects only -- no query of its own.
     */
    public static function apply($builder, array $plan, bool $withText = true): void
    {
        foreach ($plan as $g) {
            $builder->where(function ($w) use ($g, $withText) {
                if ($withText) {
                    // Every word of the group in the Arabic name, in any order.
                    $w->orWhereIn('products.id', function ($s) use ($g) {
                        $s->select('product_id')->from(self::TABLE);
                        foreach (explode(' ', $g['w']) as $word) {
                            SearchTerms::whereLike($s, self::TABLE . '.text', $word);
                        }
                    });
                }

                if ($g['b'] !== []) {
                    $w->orWhereIn('products.brand_id', $g['b']);
                }

                if ($g['c'] !== []) {
                    $w->orWhereIn('products.id', function ($s) use ($g) {
                        $s->select('product_id')->from('category_product')->whereIn('category_id', $g['c']);
                    });
                }

                foreach ($g['t'] as $term) {
                    foreach (SearchTerms::expand($term) as $e) {
                        SearchTerms::orWhereLike($w, 'products.name', $e);
                    }
                }

                // A group that can match nothing at all must say so, not vanish.
                if (! $withText && $g['b'] === [] && $g['c'] === [] && $g['t'] === []) {
                    $w->whereRaw('1 = 0');
                }
            });
        }
    }

    /**
     * Is TABLE there? Read once per 10 minutes, and only ever on an Arabic
     * query. False during an update, before its migration has run.
     */
    public static function ready(): bool
    {
        try {
            return (bool) Cache::remember('kbb.search.ar.ready', 600, static fn () => \Illuminate\Support\Facades\Schema::hasTable(self::TABLE) ? 1 : 0);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One product's Arabic name into TABLE, or out of it (null). Called by
     * Translation's saved/deleted hook; never throws.
     */
    public static function index(int $productId, ?string $arabicName): void
    {
        try {
            $text = $arabicName === null ? '' : self::normalise($arabicName);

            if ($text === '') {
                DB::table(self::TABLE)->where('product_id', $productId)->delete();

                return;
            }

            DB::table(self::TABLE)->updateOrInsert(['product_id' => $productId], ['text' => $text]);
        } catch (\Throwable) {
            // A missing table (mid-update) or a locked one must never stop a
            // translation from saving; the migration fills the table anyway.
        }
    }

    /**
     * An English search word as the Arabic shop shows it in the panel -- a
     * brand's or category's Arabic name, or a shop word from TERMS -- or null.
     * The Arabic it returns is always one plan() reads back.
     */
    public static function arabicFor(string $english): ?string
    {
        $key = mb_strtolower(trim($english));

        if ($key === '' || self::isArabic($key)) {
            return null;
        }

        $map = self::map();

        // "SKIN 1004" and "SKIN1004" are one brand: spaces do not decide it.
        foreach ([$key, str_replace(' ', '', $key)] as $k) {
            if (isset($map['ar'][$k])) {
                return $map['ar'][$k];
            }
        }

        static $terms = null;
        $terms ??= array_flip(array_reverse(self::TERMS, true));

        return $terms[$key] ?? self::DISPLAY[$key] ?? null;
    }

    /** Shipped trending words with no single-word term of their own (all read back by plan()). */
    private const DISPLAY = [
        'dark spots' => 'البقع الداكنة', 'capsule cream' => 'كريم كبسولات',
    ];
}
