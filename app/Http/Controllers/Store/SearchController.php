<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\SearchSetChoices;
use App\Support\SearchTerms;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Search suggestions for the header box.
 *
 * The layout has been advertising this endpoint to the JavaScript, but no route
 * served it — every keystroke returned a 404.
 *
 * Results are grouped so a shopper can jump straight to a category or a brand
 * rather than scrolling a flat list of products.
 *
 * Extended Search Results (Store → Site Search): when a query is recognised
 * as starting or ending with an actual brand name, matching narrows to that
 * brand instead of the ordinary keyword search — "Medicube" alone shows only
 * Medicube's own products, never padded out with another brand's bestsellers
 * just to fill the panel. "Medicube Serum" splits into the brand and the
 * leftover word, matches that brand's serums first, and only widens to other
 * brands' serums if the setting for that is on. Off by default; every path
 * below falls straight back to the existing keyword search when it's off or
 * no brand is recognised in the query.
 */
class SearchController extends Controller
{
    private const CARD_COLUMNS = [
        'id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'image', 'stock_status', 'type',
        /*
         * ▲ AND THE THREE A SET'S PRICE CANNOT BE READ WITHOUT. (Lane SG)
         *
         * App\Support\SetPricing::COLUMNS carries the argument in full. In
         * short: SetPricing::mode() and ::basis() read these off getAttributes()
         * and fall back to "no rule, no anchor" for an absent column, so a
         * narrow select does not fail -- it prices the set at the number in
         * `products.price`, which is a stale snapshot for a rule-priced set and
         * the pre-reduction figure for an anchored one. This grid showed one
         * price and the set's own page showed another.
         */
        ...\App\Support\SetPricing::COLUMNS,
    ];

    public function __construct(
        private SettingsService $settings,
        private HeaderSettings $header,
        private \App\Services\SearchInsights $insights,
    ) {}

    public function suggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $min = max(1, (int) $this->header->get('search_min_chars'));

        if (mb_strlen($q) < $min) {
            return response()->json(['query' => $q, 'groups' => [], 'total' => 0]);
        }

        // The same query returns the same suggestions for everyone, and the
        // header fires one per keystroke. (Rule 27)
        //
        // The per-brand set choices are part of the key (a hash of the saved
        // map, no query), so choosing a set for Anua takes effect on the next
        // search rather than after the five minutes a cached payload lives.
        $payload = Cache::remember(
            'kbb.search.' . md5(mb_strtolower($q)) . '.' . $this->limitKey() . '.' . $this->choicesKey() . '.s3',
            300,
            fn () => $this->build($q) + $this->setCandidates($q)
        );

        // Picked per request, OUTSIDE the cache, so "a different one each
        // search" is true and the cached payload is still shared.
        $payload = $this->setFirst($payload);

        // Counted after the results are known, so a term nobody could find is
        // not offered back to the next visitor.
        //
        // ▲ ONLY WHEN THE SEARCH HAS SETTLED (1 October 2026). The box asks on
        //   every keystroke, so counting every request counted "me", "med",
        //   "medi" on the way to "medicube" -- and Growth -> Search Terms would
        //   have ranked fragments. search.js sends `log=1` once per term, when
        //   the shopper stops typing, presses Enter or picks a result. The
        //   answer is the same cached payload either way.
        if ($request->boolean('log')) {
            $this->insights->record($q, (int) ($payload['total'] ?? 0));
        }

        return response()->json($payload);
    }

    /**
     * The sets this search fits, best sellers first, as panel rows.
     *
     * THE DEFECT (1 October 2026): "the search is not showing set products at
     * all". Sets were never excluded -- they lost on the ordering. The panel
     * keeps the top few matches by total_sales, and a set, new or converted
     * from an imported product, has sold little here yet, so it was always
     * below the cut. The owner asked for a set at #1: "if i write Anua, any
     * set which has Anua in it should display #1".
     *
     * A search that names a brand (SearchController::detectBrand(), partial
     * names included) fits every visible set that IS that brand's, or holds
     * one of its products in the box. Otherwise a set fits when every word of
     * the search is in its name. One query, at most eight rows, cached with
     * the rest of the payload.
     *
     * ▲ AND THE BRAND'S CHOSEN SET IS ALWAYS AMONG THE EIGHT (Lane PL). The
     *   owner can pick one set per brand (Store -> Site Search -> Sets in
     *   search -> Set shown first, by brand). A set nobody has bought yet sorts
     *   last, so an ORDER BY that puts the chosen id first keeps it inside the
     *   limit -- still one query, and still only if it fits and is visible,
     *   which is what makes a deleted or unpublished choice fall back. The
     *   brand id travels with the rows so setFirst() can honour the choice
     *   outside the cache.
     *
     * @return array{sets: list<array<string, mixed>>, sets_brand: int|null}
     */
    private function setCandidates(string $q): array
    {
        $detected = $this->detectBrand($q, true);
        $brandId = $detected !== null ? (int) $detected['brand']->id : null;
        $chosen = $brandId !== null ? ($this->setChoices()[$brandId] ?? null) : null;

        $sets = Product::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->with('brand:id,name,slug')
            ->where('type', 'set')
            ->where(function ($w) use ($detected, $q) {
                if ($detected !== null) {
                    SearchSetChoices::whereFits($w, (int) $detected['brand']->id);

                    return;
                }

                $words = SearchTerms::words($q);

                if ($words === []) {
                    $w->whereRaw('1 = 0');

                    return;
                }

                $w->where(function ($all) use ($words) {
                    foreach ($words as $word) {
                        SearchTerms::whereLike($all, 'products.name', $word);
                    }
                });
            })
            ->when($chosen !== null, fn ($o) => $o->orderByRaw('CASE WHEN products.id = ? THEN 0 ELSE 1 END', [$chosen]))
            ->orderByDesc('total_sales')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        if ($sets->isEmpty()) {
            return ['sets' => [], 'sets_brand' => $brandId];
        }

        \App\Support\SetPricing::prime($sets);

        return [
            'sets' => $sets->map(fn ($p) => ['id' => (int) $p->id] + $this->productRow($p))->all(),
            'sets_brand' => $brandId,
        ];
    }

    /**
     * The saved brand => set map, read once per REQUEST.
     *
     * Memoised on the request, not on $this: Laravel keeps a controller
     * instance on its Route, so a property here outlives the request in a
     * test, a queue worker or Octane -- the Setting::map() trap CLAUDE.md
     * records -- and a choice saved after the first search would never be
     * seen. Measured: the first draft of this did exactly that.
     *
     * @return array<int, int>
     */
    private function setChoices(): array
    {
        $attrs = request()->attributes;

        if (! $attrs->has('kbb.search_set_choices')) {
            $attrs->set('kbb.search_set_choices', SearchSetChoices::map());
        }

        return $attrs->get('kbb.search_set_choices');
    }

    private function choicesKey(): string
    {
        $map = $this->setChoices();

        return $map === [] ? 'c0' : 'c' . substr(md5(json_encode($map)), 0, 10);
    }

    /**
     * Put one fitting set at the top of Products, when the owner has it on
     * (Store -> Site Search -> Sets in search). Never adds a row past the
     * panel's limit; a set already in the list moves up rather than twice.
     */
    private function setFirst(array $payload): array
    {
        $sets = $payload['sets'] ?? [];
        $brandId = $payload['sets_brand'] ?? null;
        unset($payload['sets'], $payload['sets_brand']);

        if ($sets === [] || ! $this->header->get('search_sets_first')) {
            return $payload;
        }

        $pick = null;

        // The owner's own choice for this brand wins over random / best -- but
        // only while it is still one of the visible sets this search found for
        // the brand. Anything else (deleted, unpublished, no longer holding the
        // brand's products) is simply not in $sets, and the rule below runs.
        $chosen = $brandId !== null ? ($this->setChoices()[(int) $brandId] ?? null) : null;

        if ($chosen !== null) {
            foreach ($sets as $row) {
                if (($row['id'] ?? null) === $chosen) {
                    $pick = $row;
                    break;
                }
            }
        }

        $pick ??= $this->header->get('search_sets_pick') === 'best'
            ? $sets[0]
            : $sets[array_rand($sets)];

        // The id was only ever for the match above; the panel row is the same
        // shape every other product row has.
        unset($pick['id']);

        $groups = $payload['groups'] ?? [];
        $at = null;

        foreach ($groups as $i => $group) {
            if (($group['key'] ?? '') === 'products') {
                $at = $i;
                break;
            }
        }

        if ($at === null) {
            array_unshift($groups, ['key' => 'products', 'label' => 'Products', 'items' => []]);
            $at = 0;
        }

        $items = array_values(array_filter($groups[$at]['items'], fn ($it) => ($it['url'] ?? null) !== $pick['url']));
        array_unshift($items, $pick);
        $groups[$at]['items'] = array_slice($items, 0, max(1, (int) $this->header->get('search_results_max')));

        $payload['groups'] = $groups;
        $payload['total'] = array_sum(array_map(fn ($g) => count($g['items']), $groups));

        return $payload;
    }

    /**
     * The search panel's thumbnail: the 200px img-cache copy, never the
     * original. The panel draws these at about 48-64px, and the original is
     * 70-470 KB where the copy is a few KB -- the owner, 8 October: "the search
     * box results uses original size of the product ... it's loading super
     * slow". variantUrl() returns the original unchanged when no copy exists,
     * so a picture is never lost. SearchThumbnailsAreSmallTest.
     */
    private function thumb(?string $image): ?string
    {
        return ($image === null || $image === '') ? $image : \App\Support\ImageVariants::variantUrl($image, 200);
    }

    /** One product as a panel row -- the shape both result builders print. */
    private function productRow(Product $p): array
    {
        return [
            'label' => $p->t('name'),
            'meta' => $p->brand?->t('name'),
            'price' => Money::plain($p->effectivePrice()),
            'image' => $this->thumb($p->image),
            'colour' => \App\Support\Gradient::for(($p->brand?->name ?? '') . $p->name),
            'initials' => \App\Support\Gradient::initials($p->brand?->t('name') ?: $p->t('name')),
            'url' => $p->url(),
        ];
    }

    private function limitKey(): string
    {
        // Extended search on/off and its own sub-settings change what a given
        // query returns, so they're part of the cache key too — otherwise
        // flipping the feature on would keep serving results cached from
        // before it was enabled until they happened to expire on their own.
        return implode('-', [
            (int) $this->header->get('search_results_max'),
            (int) $this->header->get('search_limit_categories'),
            (int) $this->header->get('search_limit_brands'),
            $this->header->get('search_extended_enabled') ? 'x1' : 'x0',
            $this->header->get('search_extended_strict_brand') ? 's1' : 's0',
            $this->header->get('search_extended_partial_brand_match') ? 'p1' : 'p0',
            $this->header->get('search_extended_broaden_others') ? 'b1' : 'b0',
        ]);
    }

    /**
     * Looks for a real brand name at the start or end of the query. Longest
     * brand names are tried first, so "Beauty of Joseon" is recognised
     * outright rather than stopping at some shorter brand that happens to be
     * a prefix of it. A match must land on a word boundary — "Medicubex"
     * must never match "Medicube" — and, when partial matching is on, a
     * query that is itself a left-anchored prefix of a brand name counts
     * too, for a brand still being typed.
     *
     * @return array{brand: Brand, rest: string}|null
     */
    private function detectBrand(string $q, bool $partial): ?array
    {
        $q = trim($q);

        if ($q === '') {
            return null;
        }

        $qLower = mb_strtolower($q);

        $brands = Cache::remember('kbb.search.brandnames', 900,
            fn () => Brand::query()->select('id', 'name', 'slug')->get());

        $sorted = $brands->sortByDesc(fn ($b) => mb_strlen($b->name));

        foreach ($sorted as $brand) {
            $nameLower = mb_strtolower($brand->name);
            $nameLen = mb_strlen($nameLower);

            if ($nameLen === 0) {
                continue;
            }

            // The full brand name opens the query.
            if (str_starts_with($qLower, $nameLower)) {
                $boundary = mb_substr($qLower, $nameLen, 1);

                if ($boundary === '' || $boundary === ' ') {
                    return ['brand' => $brand, 'rest' => trim(mb_substr($q, $nameLen))];
                }
            }

            // The full brand name closes the query — "Serum Medicube".
            if (mb_strlen($qLower) > $nameLen && str_ends_with($qLower, $nameLower)) {
                $boundary = mb_substr($qLower, -$nameLen - 1, 1);

                if ($boundary === ' ') {
                    return ['brand' => $brand, 'rest' => trim(mb_substr($q, 0, -$nameLen))];
                }
            }

            // Still being typed — the query so far is the start of the brand
            // name, with nothing left over yet ("medicub", "beauty of").
            if ($partial && mb_strlen($qLower) < $nameLen && str_starts_with($nameLower, $qLower)) {
                return ['brand' => $brand, 'rest' => ''];
            }
        }

        return null;
    }

    private function build(string $q): array
    {
        if ($this->header->get('search_extended_enabled')) {
            $partial = (bool) $this->header->get('search_extended_partial_brand_match');
            $detected = $this->detectBrand($q, $partial);

            if ($detected !== null) {
                return $this->buildForBrand($q, $detected['brand'], $detected['rest']);
            }
        }

        return $this->buildGeneral($q);
    }

    /**
     * A recognised brand, with or without a leftover word. Categories still
     * match the ordinary way — "Serum" as a category is a store-wide thing,
     * not specific to whichever brand was typed — but products and the
     * padding-to-fill-the-panel behaviour both change: nothing here is ever
     * backfilled with an unrelated brand's bestsellers the way the general
     * search pads a short result out.
     */
    private function buildForBrand(string $q, Brand $brand, string $rest): array
    {
        $groups = [];
        $n = (int) $this->header->get('search_results_max');
        $strict = (bool) $this->header->get('search_extended_strict_brand');
        $broaden = (bool) $this->header->get('search_extended_broaden_others');

        if ($n) {
            $ownQuery = Product::query()
                ->select(self::CARD_COLUMNS)
                ->visible()
                ->with('brand:id,name,slug')
                ->where('brand_id', $brand->id);

            if ($rest !== '') {
                $ownQuery->where(function ($w) use ($rest) {
                    SearchTerms::orWhereLike($w, 'products.name', $rest);
                    SearchTerms::orWhereLike($w, 'products.sku', $rest);
                    SearchTerms::orWhereEveryWord($w, $rest, ['products.name', 'products.sku']);
                });
            }

            $own = $ownQuery->orderByDesc('total_sales')->orderByDesc('id')->limit($n)->get();

            $products = $own;

            // Brand-only query, strict mode: never top up with this brand's
            // other products or anyone else's — a shorter, honest list.
            // Brand-only query, not strict: top up with more of this same
            // brand only, never a different one.
            if ($rest === '' && ! $strict && $own->count() < $n) {
                $more = Product::query()
                    ->select(self::CARD_COLUMNS)
                    ->visible()
                    ->with('brand:id,name,slug')
                    ->where('brand_id', $brand->id)
                    ->whereNotIn('id', $own->pluck('id')->all() ?: [0])
                    ->orderByDesc('total_sales')
                    ->orderByDesc('id')
                    ->limit($n - $own->count())
                    ->get();

                $products = $products->concat($more);
            }

            // Brand + word, widening on: the same word, matched against every
            // other brand too, filling whatever room is left.
            if ($rest !== '' && $broaden && $products->count() < $n) {
                $others = Product::query()
                    ->select(self::CARD_COLUMNS)
                    ->visible()
                    ->with('brand:id,name,slug')
                    ->where('brand_id', '!=', $brand->id)
                    ->where(function ($w) use ($rest) {
                        SearchTerms::orWhereLike($w, 'products.name', $rest);
                        SearchTerms::orWhereLike($w, 'products.sku', $rest);
                        SearchTerms::orWhereEveryWord($w, $rest, ['products.name', 'products.sku']);
                    })
                    ->whereNotIn('id', $products->pluck('id')->all() ?: [0])
                    ->orderByDesc('total_sales')
                    ->orderByDesc('id')
                    ->limit($n - $products->count())
                    ->get();

                $products = $products->concat($others);
            }

            if ($products->isNotEmpty()) {
            /*
             * ONE STATEMENT FOR EVERY SET IN THIS DROPDOWN, OR NONE. (Lane SG)
             *
             * The panel prints effectivePrice() just as a tile does, so it needs
             * the set columns selected above AND the parts total behind them.
             * SetPricing::prime() looks first: no set among these rows and it
             * runs nothing at all, which is every search on this shop today.
             */
            \App\Support\SetPricing::prime($products);

                $groups[] = [
                    'key' => 'products',
                    'label' => 'Products',
                    'items' => $products->map(fn ($p) => [
                        // t(), not the column: the suggest dropdown is read by
                        // a shopper. The `colour` seed below stays on the
                        // English so a product's swatch is the same in both
                        // languages — see components/product-card.blade.php.
                        'label' => $p->t('name'),
                        'meta' => $p->brand?->t('name'),
                        'price' => Money::plain($p->effectivePrice()),
                        'image' => $this->thumb($p->image),
                        'colour' => \App\Support\Gradient::for(($p->brand?->name ?? '') . $p->name),
                        'initials' => \App\Support\Gradient::initials($p->brand?->t('name') ?: $p->t('name')),
                        'url' => $p->url(),
                    ])->all(),
                ];
            }
        }

        // Categories, unaffected by which brand was recognised.
        if ($rest !== '' && ($n = (int) $this->header->get('search_limit_categories'))) {
            $cats = $this->suggestCategories(SearchTerms::whereLike(
                Category::query()->select('id', 'name', 'slug'), 'categories.name', $rest
            ), $n);

            if ($cats->isNotEmpty()) {
                $groups[] = [
                    'key' => 'categories',
                    'label' => 'Categories',
                    'items' => $cats->map(fn ($c) => [
                        'label' => $c->t('name'),
                        'meta' => $c->products_count . ' products',
                        'colour' => \App\Support\Gradient::for($c->name),
                        'initials' => \App\Support\Gradient::initials($c->t('name')),
                        'url' => $c->url(),
                    ])->all(),
                ];
            }
        }

        // No separate "Brands" group — the brand is already the context the
        // whole result set is built around, not a further match to list.

        return [
            'query' => $q,
            'groups' => $groups,
            'total' => array_sum(array_map(fn ($g) => count($g['items']), $groups)),
            'all_url' => Url::to('/shop/') . '?s=' . urlencode($q),
        ];
    }

    /**
     * `term%` — a PREFIX pattern with the shopper's wildcards made literal.
     *
     * SearchTerms::like() is the equivalent for the `%term%` patterns the WHERE
     * clauses use; this is the prefix form the relevance ranking needs, and it
     * escapes by exactly the same rules so the two halves of the query agree
     * about what was typed. The escape character is doubled first, or a term
     * containing it would escape the character after it.
     *
     * Kept here rather than added to SearchTerms because app/Support belongs to
     * another lane; if that lane wants it, it moves.
     */
    private static function likePrefix(string $term): string
    {
        $escape = SearchTerms::ESCAPE;

        return str_replace(
            [$escape, '%', '_'],
            [$escape . $escape, $escape . '%', $escape . '_'],
            $term
        ) . '%';
    }

    private function buildGeneral(string $q): array
    {
        $groups = [];

        // The results page has expanded synonyms since 2.60.77; the dropdown
        // did not, so typing "moisturiser" showed nothing here and then found
        // products the moment you pressed enter. Same SearchTerms, so the two
        // agree.
        $terms = \App\Support\SearchTerms::expand($q);

        if ($n = (int) $this->header->get('search_results_max')) {
            $products = Product::query()
                ->select(self::CARD_COLUMNS)
                ->visible()
                ->with('brand:id,name,slug')
                ->where(function ($w) use ($terms, $q) {
                    // Every word, in any order: "medicube booster x2" finds
                    // "medicube - AGE-R Booster Pro X2 Pink". See SearchTerms::words().
                    SearchTerms::orWhereEveryWord($w, $q, ['products.name', 'products.sku', 'brand.name']);

                    foreach ($terms as $term) {
                        SearchTerms::orWhereLike($w, 'products.name', $term);
                        SearchTerms::orWhereLike($w, 'products.sku', $term);
                        $w->orWhereHas('brand', fn ($b) => SearchTerms::whereLike($b, 'brands.name', $term));
                    }
                })
                /*
                 * A name match beats a brand or SKU match, so the obvious
                 * result is not buried under an incidental one.
                 *
                 * Escaped and given an explicit ESCAPE clause, exactly as the
                 * WHERE above is by SearchTerms::orWhereLike(). This clause was
                 * interpolating the raw term straight into a LIKE pattern while
                 * the matching half escaped it, so the two halves disagreed
                 * about what the shopper typed: a '%' or '_' in the box acted
                 * as a wildcard HERE and as literal text there, and a backslash
                 * meant one thing on MySQL (its default LIKE escape) and
                 * another on SQLite (no default escape at all) — the same
                 * search ranked differently in the suite and in production.
                 * Only ordering was ever wrong, never which rows came back,
                 * which is why it survived: the results were right and the
                 * best one was not always first.
                 *
                 * `name` is left unquoted deliberately: MySQL reads "name" as a
                 * STRING literal unless ANSI_QUOTES is set, which would make
                 * this compare the word "name" to the pattern and rank nothing
                 * at all. The column is unambiguous here — this query joins
                 * nothing — so the bare identifier is the portable spelling.
                 */
                ->orderByRaw(
                    "CASE WHEN name like ? escape '" . SearchTerms::ESCAPE . "' THEN 0 ELSE 1 END",
                    [self::likePrefix($q)]
                )
                ->orderByDesc('total_sales')
                ->orderByDesc('id')
                ->limit($n)
                ->get();

            /*
             * Top the list up so the panel offers a full set.
             *
             * A search for a brand that stocks three products returned three
             * rows and left the panel looking half-built. Anything short is
             * filled with the best sellers from the brands already matched.
             *
             * ▲ AND ONLY FROM THOSE BRANDS, AND NEVER FROM NOTHING (1 October
             *   2026). It used to go on to "the catalogue at large", so a
             *   search that matched NOTHING showed five unrelated best sellers
             *   and "5 found". The owner typed "medicube booster x2" and saw
             *   Anua and Dr.Althea -- which read exactly as the search having
             *   stopped and kept old results. A search that matches nothing
             *   now says so; one that matches a brand tops up from that brand.
             */
            $brandIds = $products->pluck('brand_id')->filter()->unique()->all();

            if ($products->count() < $n && $brandIds !== []) {
                $have = $products->pluck('id')->all();

                $filler = Product::query()
                    ->select(self::CARD_COLUMNS)
                    ->visible()
                    ->with('brand:id,name,slug')
                    ->whereNotIn('id', $have ?: [0])
                    ->whereIn('brand_id', $brandIds)
                    ->orderByDesc('total_sales')
                    ->orderByDesc('id')
                    ->limit($n - $products->count())
                    ->get();

                $products = $products->concat($filler);
            }

            if ($products->isNotEmpty()) {
            /*
             * ONE STATEMENT FOR EVERY SET IN THIS DROPDOWN, OR NONE. (Lane SG)
             *
             * The panel prints effectivePrice() just as a tile does, so it needs
             * the set columns selected above AND the parts total behind them.
             * SetPricing::prime() looks first: no set among these rows and it
             * runs nothing at all, which is every search on this shop today.
             */
            \App\Support\SetPricing::prime($products);

                $groups[] = [
                    'key' => 'products',
                    'label' => 'Products',
                    'items' => $products->map(fn ($p) => [
                        // t(), not the column: the suggest dropdown is read by
                        // a shopper. The `colour` seed below stays on the
                        // English so a product's swatch is the same in both
                        // languages — see components/product-card.blade.php.
                        'label' => $p->t('name'),
                        'meta' => $p->brand?->t('name'),
                        'price' => Money::plain($p->effectivePrice()),
                        'image' => $this->thumb($p->image),
                        // The theme's .si swatch falls back to a gradient with
                        // initials when a product has no photo.
                        'colour' => \App\Support\Gradient::for(($p->brand?->name ?? '') . $p->name),
                        'initials' => \App\Support\Gradient::initials($p->brand?->t('name') ?: $p->t('name')),
                        'url' => $p->url(),
                    ])->all(),
                ];
            }
        }

        if ($n = (int) $this->header->get('search_limit_categories')) {
            $cats = $this->suggestCategories(SearchTerms::whereLike(
                Category::query()->select('id', 'name', 'slug'), 'categories.name', $q
            ), $n);

            if ($cats->isNotEmpty()) {
                $groups[] = [
                    'key' => 'categories',
                    'label' => 'Categories',
                    'items' => $cats->map(fn ($c) => [
                        'label' => $c->t('name'),
                        'meta' => $c->products_count . ' products',
                        'colour' => \App\Support\Gradient::for($c->name),
                        'initials' => \App\Support\Gradient::initials($c->t('name')),
                        'url' => $c->url(),
                    ])->all(),
                ];
            }
        }

        if ($n = (int) $this->header->get('search_limit_brands')) {
            $brands = SearchTerms::whereLike(
                Brand::query()->select('id', 'name', 'slug'), 'brands.name', $q
            )
                ->withCount(['products' => fn ($w) => $w->visible()])
                ->groupBy('brands.id', 'brands.name', 'brands.slug')
                ->having('products_count', '>', 0)
                ->orderByDesc('products_count')
                ->orderByDesc('brands.id')
                ->limit($n)
                ->get();

            if ($brands->isNotEmpty()) {
                $groups[] = [
                    'key' => 'brands',
                    'label' => 'Brands',
                    'items' => $brands->map(fn ($b) => [
                        'label' => $b->t('name'),
                        'meta' => $b->products_count . ' products',
                        'colour' => \App\Support\Gradient::for($b->name),
                        'initials' => \App\Support\Gradient::initials($b->t('name')),
                        'url' => $b->url(),
                    ])->all(),
                ];
            }
        }

        return [
            'query' => $q,
            'groups' => $groups,
            'total' => array_sum(array_map(fn ($g) => count($g['items']), $groups)),
            'all_url' => Url::to('/shop/') . '?s=' . urlencode($q),
        ];
    }

    /**
     * What the panel shows before anything is typed.
     *
     * Trending words, the shop's most-searched terms of the last week, and a
     * few products worth looking at — enough to fill both columns so the panel
     * does not change shape once typing starts.
     */
    public function starter(Request $request): JsonResponse
    {
        $header = $this->header;
        $mobile = $request->boolean('mobile');

        $words = $header->trendingWords();
        $words = array_slice($words, 0, (int) $header->get($mobile ? 'trending_limit_mobile' : 'trending_limit'));

        $popular = Cache::remember('kbb.search.starter.products', 900,
            fn () => Product::query()
                /*
                 * `type` AND THE THREE SET COLUMNS. (Lane SG) This list had
                 * neither, so Product::isSet() failed closed here and the
                 * dropdown quoted `products.price` for a set -- the one figure
                 * that is not what the shop charges for one. isSet() is the
                 * only thing `type` changes on this path: a variable parent
                 * reaches VariantPricing through a NULL `price`, not through
                 * its type.
                 */
                ->select('id', 'name', 'slug', 'brand_id', 'price', 'sale_price', 'image', 'type',
                    ...\App\Support\SetPricing::COLUMNS)
                ->visible()
                ->with('brand:id,name')
                ->orderByDesc('total_sales')
                ->orderByDesc('id')
                ->limit((int) $header->get('search_results_max'))
                ->get()
                /*
                 * ONE STATEMENT FOR EVERY SET IN THE STARTER PANEL, OR NONE.
                 * (Lane SG) See the two dropdown handlers above.
                 */
                ->tap(fn ($rows) => \App\Support\SetPricing::prime($rows))
                ->map(fn ($p) => [
                    'name' => $p->t('name'),
                    'brand' => $p->brand?->t('name'),
                    'url' => Url::to('/product/' . $p->slug . '/'),
                    'image' => $this->thumb($p->image),
                    /*
                     * plain(), NOT format(). (Lane PI-A) format() is the
                     * WooCommerce-shaped <span> markup Blade prints with
                     * {!! !!}; this is JSON, and search.js escapes every field
                     * of it before it touches the DOM, as it must. So the
                     * owner's "Popular right now" read `Anua · <span
                     * class="woocommerce-Price-amount amount" dir="ltr">…AED
                     * </span>80</span>` as letters. The suggestion and result
                     * rows above were already plain(); this was the one row
                     * that was not. Text in the JSON, escaped once in the JS.
                     */
                    'price' => Money::plain($p->effectivePrice()),
                ])
                ->all());

        return response()->json([
            'trending' => array_values($words),
            'recent' => $this->insights->popular((int) $header->get('search_recent_count')),
            'popular' => $popular,
            'brands' => Cache::remember('kbb.search.starter.brands', 900,
                fn () => Brand::query()->orderByDesc('id')->limit(6)->pluck('name')->all()),
            'layout' => $header->get('search_panel'),
        ]);
    }

    /** Full results page — a real URL, so a search can be shared and indexed. */
    public function page(Request $request)
    {
        return app(ShopController::class)->index($request);
    }

    /**
     * The categories a suggestion lists, with "N products" beside each.
     *
     * Lane SC: while a parent lists its sub-categories' products, N is what
     * its page lists, each product once, and a parent whose products are all
     * in its children is suggested at all. The rolled-up counts are one cached
     * map (CategoryRollup::counts()), so this costs no query while warm; the
     * name match itself is the same one statement, without the HAVING, ORDER
     * and LIMIT, which are done on the rolled-up counts instead. Nothing
     * rolling up: the original statement, unchanged.
     */
    private function suggestCategories(\Illuminate\Database\Eloquent\Builder $base, int $n): \Illuminate\Support\Collection
    {
        $base->withCount(['products' => fn ($w) => $w->visible()]);

        if (\App\Support\CategoryRollup::hasRollups()) {
            return \App\Support\CategoryRollup::applyCounts(
                $base->get(),
                static fn ($a, $b) => [-(int) $a->products_count, -(int) $a->id] <=> [-(int) $b->products_count, -(int) $b->id],
                $n,
            );
        }

        return $base
            ->groupBy('categories.id', 'categories.name', 'categories.slug')
            ->having('products_count', '>', 0)
            ->orderByDesc('products_count')
            ->orderByDesc('categories.id')
            ->limit($n)
            ->get();
    }
}
