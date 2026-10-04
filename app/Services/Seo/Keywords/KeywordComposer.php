<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

/**
 * Mix & match: one page's keywords, in the four layers the owner named.
 *
 *   own       the page's exact identity — "cosrx advanced snail 96 mucin power
 *             essence", "cosrx", "serums"
 *   product   what it is and what is in it — "snail mucin essence",
 *             "hydrating essence"
 *   industry  how the trade names it — "korean essence", "k-beauty essence"
 *   skincare  the shopper's intent — "essence for dry skin"
 *
 * Each layer is built from TEMPLATES (always available, this shop's own facts)
 * and from the BANK (phrases a real source returned that are about this page).
 * Every candidate is scored by its source — Search Console > Autocomplete >
 * site search > lexicon > template — and the page keeps the best of each layer
 * (3 + 3 + 2 + 2), never two phrases that are the same words reordered, never
 * more than ten in all.
 *
 * NO KEYWORD IS ABOUT ANOTHER PAGE. A bank phrase is only a candidate if it
 * contains this page's own anchors (its brand and name words, or its type and
 * ingredient), which is what keeps a serum's keywords off a sunscreen and keeps
 * every page's set different from every other's.
 *
 * Pure: no queries. The caller hands it the bank index and the map of
 * primaries already owned.
 */
final class KeywordComposer
{
    public const QUOTA = ['own' => 3, 'product' => 3, 'industry' => 2, 'skincare' => 2];

    public const MAX = 10;

    /** The page's exact identity. Always kept (PINNED), whatever outranks it. */
    private const T_OWN = 110;

    /** The size variant of a product's name — also pinned: it is what separates two sizes. */
    private const T_SIZE = 95;

    private const T_OWN2 = 100;

    private const T_OTHER = 60;

    private const INDUSTRY_WORDS = ['korean', 'korea', 'k-beauty', 'kbeauty', 'كوري', 'كورية', 'الكورية', 'كوريا'];

    private const INTENT_WORDS = ['for', 'acne', 'dry', 'oily', 'sensitive', 'combination', 'pores', 'wrinkles', 'aging', 'spots', 'للبشرة', 'لحب', 'للبقع', 'للمسام', 'لمكافحة'];

    /**
     * @param  array  $p  a profile from EntityCatalog
     * @param  array{terms: array, index: array, pages: array}  $bank
     * @param  array<string, string>  $owners  primary keyword => "type:id" of the page that owns it
     * @return array{layers: array<string, list<string>>, keywords: list<string>, primary: ?string, clash: ?string, suggest: array{title: string, desc: string}}
     */
    public static function compose(array $p, array $bank, array $owners, string $siteName): array
    {
        $ar = ($p['locale'] ?? 'en') === 'ar';
        $self = $p['type'].':'.$p['id'];

        $cands = [];
        $pinned = [];
        $add = static function (string $layer, ?string $phrase, int $score, bool $pin = false) use (&$cands, &$pinned): void {
            $k = KeywordText::clean($phrase);
            if ($k === null) {
                return;
            }
            if ($pin) {
                $pinned[$k] = true;
                $layer = 'own';
            }
            if (! isset($cands[$k]) || $cands[$k][1] < $score) {
                $cands[$k] = [$layer, $score];
            }
        };

        foreach (($ar ? self::templatesAr($p, $siteName) : self::templatesEn($p, $siteName)) as [$layer, $phrase, $score]) {
            // A template that a real source also returned scores as that source.
            $c = KeywordText::clean($phrase);
            $add($layer, $phrase, max($score, $c !== null ? ($bank['terms'][$c][0] ?? 0) : 0), $layer === 'own' && in_array($score, [self::T_OWN, self::T_SIZE], true));
        }

        $anchors = self::anchors($p);
        foreach (self::bankMatches($p, $bank, $anchors) as $term => $score) {
            $add(self::classify($term, $anchors), $term, $score);
        }

        // Search Console said Google already shows THIS page for these.
        foreach ($bank['pages'][rtrim((string) $p['path'], '/').'/'] ?? [] as $term) {
            $add(self::classify($term, $anchors, 'own'), $term, ($bank['terms'][$term][0] ?? 600) + 200);
        }

        // Select: per-layer quota, then fill, dedupe by word set.
        $byLayer = ['own' => [], 'product' => [], 'industry' => [], 'skincare' => []];
        foreach ($cands as $term => [$layer, $score]) {
            $byLayer[$layer][$term] = $score;
        }
        foreach ($byLayer as &$list) {
            uksort($list, static fn ($a, $b) => [isset($pinned[$b]), $list[$b], mb_strlen($b)] <=> [isset($pinned[$a]), $list[$a], mb_strlen($a)]);
        }
        unset($list);

        $picked = [];
        $seen = [];
        $layers = ['own' => [], 'product' => [], 'industry' => [], 'skincare' => []];
        $take = static function (string $layer, string $term) use (&$picked, &$seen, &$layers): bool {
            $sig = KeywordText::signature($term);
            if (isset($seen[$sig]) || count($picked) >= self::MAX) {
                return false;
            }
            $seen[$sig] = true;
            $picked[$term] = $layer;
            $layers[$layer][] = $term;

            return true;
        };

        foreach (self::QUOTA as $layer => $n) {
            foreach ($byLayer[$layer] as $term => $score) {
                if (count($layers[$layer]) >= $n) {
                    break;
                }
                $take($layer, $term);
            }
        }
        $rest = [];
        foreach ($byLayer as $layer => $list) {
            foreach ($list as $term => $score) {
                if (! isset($picked[$term])) {
                    $rest[] = [$score, $layer, $term];
                }
            }
        }
        usort($rest, static fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($rest as [, $layer, $term]) {
            if (count($picked) >= self::MAX) {
                break;
            }
            $take($layer, $term);
        }

        // Primary: the best own/product phrase nobody else owns.
        $primary = null;
        $clash = null;
        $pool = [];
        foreach (['own', 'product'] as $layer) {
            foreach ($layers[$layer] as $term) {
                $pool[$term] = $byLayer[$layer][$term] ?? 0;
            }
        }
        arsort($pool);
        foreach (array_keys($pool) as $term) {
            $owner = $owners[$term] ?? null;
            if ($owner === null || $owner === $self) {
                $primary = $term;
                break;
            }
            $clash ??= $term.' — already the primary of '.$owner;
        }

        $keywords = [];
        foreach (['own', 'product', 'industry', 'skincare'] as $layer) {
            foreach ($layers[$layer] as $t) {
                $keywords[] = $t;
            }
        }

        return [
            'layers' => $layers,
            'keywords' => $keywords,
            'primary' => $primary,
            'clash' => $clash,
            'suggest' => self::suggest($p, $layers, $siteName),
        ];
    }

    /* ------------------------------------------------------------ templates */

    /** @return list<array{0: string, 1: string, 2: int}> */
    private static function templatesEn(array $p, string $site): array
    {
        $n = (string) ($p['core'] ?? '');
        $b = KeywordText::norm((string) ($p['brand'] ?? ''));
        $k = $p['kind'] ?? null;
        $ings = $p['ingredients'] ?? [];
        $cons = $p['concerns'] ?? [];
        $skin = $p['skin'] ?? [];
        $o = [];

        switch ($p['type']) {
            case 'product':
                $o[] = ['own', trim($b.' '.$n), self::T_OWN];
                $o[] = ['own', $n, self::T_OWN2];
                // The size, when the name has one: two sizes of one product are
                // two pages, and this is the phrase that tells them apart.
                if (preg_match('/\b(\d+(?:\.\d+)?\s?(?:ml|g|oz))\b/u', KeywordText::norm((string) ($p['name'] ?? '')), $m)) {
                    $o[] = ['own', trim($b.' '.$n).' '.str_replace(' ', '', $m[1]), self::T_SIZE];
                }
                $o[] = ['own', trim($b.' '.$n).' price in uae', self::T_OTHER + 10];
                if ($k && $b) {
                    $o[] = ['own', $b.' '.$k, self::T_OTHER + 5];
                }
                foreach ($ings as $i) {
                    if ($k) {
                        $o[] = ['product', $i.' '.$k, self::T_OTHER + 5];
                    }
                }
                foreach ($cons as $c) {
                    // "sunscreen for sun protection" says one thing twice.
                    if ($k && ! ($c === 'sun' && $k === 'sunscreen')) {
                        $o[] = ['product', Lexicon::CONCERNS[$c][1].' '.$k, self::T_OTHER];
                        $o[] = ['skincare', $k.' for '.Lexicon::CONCERNS[$c][0], self::T_OTHER];
                    }
                }
                foreach ($skin as $s) {
                    if ($k) {
                        $o[] = ['skincare', $k.' for '.$s, self::T_OTHER];
                    }
                }
                if ($k) {
                    $o[] = ['industry', 'korean '.$k, self::T_OTHER];
                    $o[] = ['industry', 'k-beauty '.$k, self::T_OTHER - 5];
                }
                if ($b) {
                    $o[] = ['industry', $b.' korean skincare', self::T_OTHER - 5];
                }
                break;

            case 'category':
                $o[] = ['own', $n, self::T_OWN];
                $o[] = ['own', $n.' uae', self::T_OTHER + 10];
                $o[] = ['own', 'buy '.$n.' online', self::T_OTHER];
                $o[] = ['industry', 'korean '.$n, self::T_OTHER + 5];
                $o[] = ['industry', 'best korean '.$n, self::T_OTHER];
                if ($k) {
                    foreach (array_keys(Lexicon::SKIN_TYPES) as $s) {
                        $o[] = ['skincare', $k.' for '.$s, self::T_OTHER - 5];
                    }
                    foreach (array_slice(array_keys(Lexicon::CONCERNS), 0, 4) as $c) {
                        $o[] = ['product', Lexicon::CONCERNS[$c][1].' '.$k, self::T_OTHER - 5];
                    }
                }
                foreach ($cons as $c) {
                    $o[] = ['skincare', 'korean skincare for '.Lexicon::CONCERNS[$c][0], self::T_OTHER];
                }
                break;

            case 'brand':
                $o[] = ['own', $b, self::T_OWN];
                $o[] = ['own', $b.' uae', self::T_OTHER + 15];
                $o[] = ['own', $b.' price in uae', self::T_OTHER + 10];
                $o[] = ['own', $b.' products', self::T_OTHER + 5];
                foreach ($p['kinds'] ?? [] as $kind) {
                    $o[] = ['product', $b.' '.$kind, self::T_OTHER + 5];
                }
                $o[] = ['industry', $b.' korean skincare', self::T_OTHER + 5];
                $o[] = ['industry', $b.' k-beauty', self::T_OTHER];
                $o[] = ['skincare', $b.' skincare', self::T_OTHER];
                $o[] = ['skincare', $b.' dubai', self::T_OTHER - 5];
                break;

            case 'collection':
                $o[] = ['own', $n, self::T_OWN];
                $o[] = ['industry', $n.' korean skincare', self::T_OTHER + 5];
                foreach ($cons as $c) {
                    $o[] = ['skincare', 'korean skincare for '.Lexicon::CONCERNS[$c][0], self::T_OTHER + 5];
                    $o[] = ['product', Lexicon::CONCERNS[$c][1].' skincare', self::T_OTHER];
                }
                $o[] = ['own', $n.' uae', self::T_OTHER];
                break;

            case 'page':
                if ($p['id'] === 'home') {
                    $o[] = ['own', $site, self::T_OWN];
                    $o[] = ['own', $site.' uae', self::T_OTHER + 10];
                    foreach (Lexicon::INDUSTRY as [$en]) {
                        $o[] = ['industry', $en.' uae', self::T_OTHER];
                    }
                    $o[] = ['product', 'korean skincare online', self::T_OTHER];
                    $o[] = ['product', 'original korean skincare', self::T_OTHER];
                    $o[] = ['skincare', 'korean skincare dubai', self::T_OTHER];
                    $o[] = ['skincare', 'korean skincare abu dhabi', self::T_OTHER - 5];
                } else {
                    $o[] = ['own', $n, self::T_OWN];
                    $o[] = ['own', $site.' '.$n, self::T_OWN2];
                }
                break;

            case 'post':
                $o[] = ['own', $n, self::T_OWN];
                foreach ($ings as $i) {
                    $o[] = ['product', $k ? $i.' '.$k : $i.' skincare', self::T_OTHER];
                }
                foreach ($cons as $c) {
                    $o[] = ['skincare', 'korean skincare for '.Lexicon::CONCERNS[$c][0], self::T_OTHER];
                }
                if ($k) {
                    $o[] = ['industry', 'korean '.$k, self::T_OTHER];
                }
                break;
        }

        return $o;
    }

    /** @return list<array{0: string, 1: string, 2: int}> */
    private static function templatesAr(array $p, string $site): array
    {
        $n = KeywordText::norm((string) ($p['name'] ?? ''));
        $b = KeywordText::norm((string) ($p['brand'] ?? ''));
        $k = Lexicon::typeAr($p['kind'] ?? null);
        $o = [];

        switch ($p['type']) {
            case 'product':
                $core = EntityCatalog::core($n, $b);
                $o[] = ['own', trim($b.' '.$core), self::T_OWN];
                $o[] = ['own', $core, self::T_OWN2];
                $o[] = ['own', 'سعر '.trim($b.' '.$core).' في الامارات', self::T_OTHER + 10];
                foreach ($p['ingredients'] ?? [] as $i) {
                    if ($k) {
                        $o[] = ['product', $k.' '.Lexicon::INGREDIENTS[$i], self::T_OTHER + 5];
                    }
                }
                if ($k) {
                    $o[] = ['industry', $k.' كوري', self::T_OTHER];
                    foreach ($p['concerns'] ?? [] as $c) {
                        $o[] = ['skincare', $k.' '.Lexicon::CONCERNS[$c][2], self::T_OTHER];
                    }
                }
                if ($b) {
                    $o[] = ['industry', 'منتجات '.$b.' الكورية', self::T_OTHER - 5];
                }
                break;

            case 'brand':
                $o[] = ['own', $b, self::T_OWN];
                $o[] = ['own', $b.' الامارات', self::T_OTHER + 15];
                $o[] = ['own', 'سعر '.$b.' في الامارات', self::T_OTHER + 10];
                $o[] = ['industry', 'منتجات '.$b.' الكورية', self::T_OTHER + 5];
                foreach ($p['kinds'] ?? [] as $kind) {
                    $o[] = ['product', Lexicon::typeAr($kind).' '.$b, self::T_OTHER];
                }
                $o[] = ['skincare', $b.' دبي', self::T_OTHER - 5];
                break;

            case 'page':
                if ($p['id'] === 'home') {
                    $o[] = ['own', KeywordText::norm($site), self::T_OWN];
                    foreach (Lexicon::INDUSTRY as [, $arPhrase]) {
                        $o[] = ['industry', $arPhrase.' في الامارات', self::T_OTHER];
                    }
                    $o[] = ['product', 'منتجات كورية أصلية', self::T_OTHER];
                    $o[] = ['skincare', 'منتجات كورية دبي', self::T_OTHER];
                } else {
                    $o[] = ['own', $n, self::T_OWN];
                }
                break;

            default: // category, collection, post
                $o[] = ['own', $n, self::T_OWN];
                $o[] = ['own', $n.' الامارات', self::T_OTHER + 10];
                if ($k) {
                    $o[] = ['industry', $k.' كوري', self::T_OTHER + 5];
                    foreach (Lexicon::SKIN_TYPES as $arSkin) {
                        $o[] = ['skincare', $k.' '.$arSkin, self::T_OTHER - 5];
                    }
                }
                foreach ($p['concerns'] ?? [] as $c) {
                    $o[] = ['skincare', 'منتجات كورية '.Lexicon::CONCERNS[$c][2], self::T_OTHER];
                }
                break;
        }

        return $o;
    }

    /* ------------------------------------------------------------ the bank */

    /** @return array{brand: list<string>, core: list<string>, kind: list<string>, ing: list<list<string>>, name: list<string>} */
    private static function anchors(array $p): array
    {
        $stop = array_merge(Lexicon::STOP, ['korean', 'skincare', 'skin', 'care']);
        $kindWords = $p['kind'] ? KeywordText::tokens((string) $p['kind']) : [];
        $distinct = static fn (string $s): array => array_values(array_filter(
            KeywordText::tokens($s),
            static fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, $stop, true) && ! ctype_digit($t)
        ));

        return [
            'brand' => $distinct((string) ($p['brand'] ?? '')),
            'core' => array_values(array_diff($distinct((string) ($p['core'] ?? '')), $kindWords)),
            'kind' => $kindWords,
            'ing' => array_map(static fn ($i) => KeywordText::tokens($i), $p['ingredients'] ?? []),
            'name' => $distinct((string) ($p['name'] ?? '')),
        ];
    }

    /** @return array<string, int> bank terms about this page => score */
    private static function bankMatches(array $p, array $bank, array $a): array
    {
        if ($bank['terms'] === []) {
            return [];
        }

        $seeds = array_merge($a['brand'], $a['core'], $a['kind'], $a['name']);
        $pool = [];
        foreach (array_unique($seeds) as $tok) {
            foreach (array_slice($bank['index'][$tok] ?? [], 0, 400) as $term) {
                $pool[$term] = true;
            }
        }

        $out = [];
        $generic = $p['type'] === 'brand' ? self::generic() : [];
        foreach (array_keys($pool) as $term) {
            $tt = KeywordText::tokens($term);
            // A phrase naming ANOTHER brand is about that brand's pages, never
            // this one's — "anua heartleaf toner" stays off a COSRX page and
            // off the Toners category alike.
            foreach ($bank['brands'] ?? [] as $bt) {
                if ($bt !== $a['brand'] && $bt !== [] && array_diff($bt, $tt) === []) {
                    continue 2;
                }
            }
            $has = static fn (array $need): bool => $need !== [] && array_diff($need, $tt) === [];
            $coreHits = count(array_intersect($a['core'], $tt));

            $relevant = match ($p['type']) {
                'product' => ($has($a['brand']) && $coreHits >= 1)
                    || ($coreHits >= 2)
                    || ($has($a['kind']) && array_filter($a['ing'], $has) !== []),
                // A brand page targets the BRAND: "cosrx uae", "cosrx serum",
                // "cosrx korean skincare" — never "cosrx snail mucin essence",
                // which is that product's page's keyword.
                'brand' => $has($a['brand']) && array_diff($tt, $a['brand'], $generic) === [],
                default => $has($a['name']) || ($a['kind'] !== [] && $has($a['kind']) && count($tt) <= 4),
            };

            if ($relevant) {
                $out[$term] = $bank['terms'][$term][0];
            }
        }

        arsort($out);

        return array_slice($out, 0, 40, true);
    }

    /** Words that qualify a brand or a type without naming a product. */
    private static function generic(): array
    {
        $g = ['products', 'product', 'price', 'prices', 'buy', 'online', 'original', 'official', 'store', 'shop', 'skincare',
            'skin', 'care', 'sale', 'offers', 'best', 'korean', 'korea', 'k-beauty', 'beauty', 'cosmetics', 'set', 'review', 'reviews'];
        foreach (Lexicon::TYPES as $canon => [, $aliases]) {
            foreach (array_merge([$canon], $aliases) as $w) {
                array_push($g, ...KeywordText::tokens($w));
            }
        }
        foreach (Lexicon::UAE['en'] as $w) {
            array_push($g, ...KeywordText::tokens($w));
        }
        foreach (Lexicon::UAE['ar'] as $w) {
            array_push($g, ...KeywordText::tokens($w));
        }
        array_push($g, 'in', 'for', 'the', 'كوري', 'كورية', 'الكورية', 'منتجات');

        return array_values(array_unique($g));
    }

    private static function classify(string $term, array $a, string $fallback = 'product'): string
    {
        $tt = KeywordText::tokens($term);
        $coreHits = count(array_intersect($a['core'], $tt));

        if (($a['brand'] !== [] && array_diff($a['brand'], $tt) === []) || $coreHits >= 2
            || ($a['name'] !== [] && array_diff($a['name'], $tt) === [])) {
            return 'own';
        }
        if (array_intersect(self::INDUSTRY_WORDS, $tt) !== [] || str_contains($term, 'k-beauty') || str_contains($term, 'k beauty')) {
            return 'industry';
        }
        if (array_intersect(self::INTENT_WORDS, $tt) !== []) {
            return 'skincare';
        }

        return $fallback === 'own' ? 'own' : 'product';
    }

    /* ------------------------------------------------------------ suggestions */

    /** @return array{title: string, desc: string} */
    private static function suggest(array $p, array $layers, string $site): array
    {
        $ar = ($p['locale'] ?? 'en') === 'ar';
        $display = trim((string) ($p['display'] ?? $p['name'] ?? ''));
        $phrase = $layers['product'][0] ?? ($layers['industry'][0] ?? null);
        $more = array_values(array_filter(
            array_merge($layers['product'], $layers['skincare'], $layers['industry']),
            static fn ($t) => $t !== $phrase
        ));

        $title = $display.' | '.$site;
        if ($phrase !== null && ! $ar && $p['type'] !== 'page') {
            $long = $display.' – '.KeywordText::display($phrase).' | '.$site;
            if (mb_strlen($long) <= 60) {
                $title = $long;
            }
        }

        $list = array_slice(array_filter([$phrase, $more[0] ?? null]), 0, 2);
        if ($ar) {
            $desc = 'تسوق '.$display.' في الامارات'.($list !== [] ? ': '.implode('، ', $list) : '').'. منتجات كورية أصلية مع توصيل سريع.';
        } else {
            $desc = 'Shop '.$display.' in the UAE'.($list !== [] ? ': '.implode(', ', $list) : '').'. 100% authentic Korean skincare with fast delivery.';
        }

        if (mb_strlen($desc) > 158) {
            $desc = rtrim(mb_substr($desc, 0, 155), " ,.،").'…';
        }

        return ['title' => mb_substr($title, 0, 70), 'desc' => $desc];
    }
}
