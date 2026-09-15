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
        $payload = Cache::remember(
            'kbb.search.' . md5(mb_strtolower($q)) . '.' . $this->limitKey(),
            300,
            fn () => $this->build($q)
        );

        // Counted after the results are known, so a term nobody could find is
        // not offered back to the next visitor.
        $this->insights->record($q, (int) ($payload['total'] ?? 0));

        return response()->json($payload);
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
                });
            }

            $own = $ownQuery->orderByDesc('total_sales')->limit($n)->get();

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
                    })
                    ->whereNotIn('id', $products->pluck('id')->all() ?: [0])
                    ->orderByDesc('total_sales')
                    ->limit($n - $products->count())
                    ->get();

                $products = $products->concat($others);
            }

            if ($products->isNotEmpty()) {
                $groups[] = [
                    'key' => 'products',
                    'label' => 'Products',
                    'items' => $products->map(fn ($p) => [
                        'label' => $p->name,
                        'meta' => $p->brand?->name,
                        'price' => Money::plain($p->effectivePrice()),
                        'image' => $p->image,
                        'colour' => \App\Support\Gradient::for(($p->brand?->name ?? '') . $p->name),
                        'initials' => \App\Support\Gradient::initials($p->brand?->name ?: $p->name),
                        'url' => $p->url(),
                    ])->all(),
                ];
            }
        }

        // Categories, unaffected by which brand was recognised.
        if ($rest !== '' && ($n = (int) $this->header->get('search_limit_categories'))) {
            $cats = SearchTerms::whereLike(
                Category::query()->select('id', 'name', 'slug'), 'categories.name', $rest
            )
                ->withCount(['products' => fn ($w) => $w->visible()])
                ->groupBy('categories.id', 'categories.name', 'categories.slug')
                ->having('products_count', '>', 0)
                ->orderByDesc('products_count')
                ->limit($n)
                ->get();

            if ($cats->isNotEmpty()) {
                $groups[] = [
                    'key' => 'categories',
                    'label' => 'Categories',
                    'items' => $cats->map(fn ($c) => [
                        'label' => $c->name,
                        'meta' => $c->products_count . ' products',
                        'colour' => \App\Support\Gradient::for($c->name),
                        'initials' => \App\Support\Gradient::initials($c->name),
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
                ->where(function ($w) use ($terms) {
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
                ->limit($n)
                ->get();

            /*
             * Top the list up so the panel offers a full set.
             *
             * A search for a brand that stocks three products returned three
             * rows and left the panel looking half-built. Anything short is
             * filled with the best sellers from the brands already matched,
             * then from the catalogue at large — still relevant, and the panel
             * is a consistent size whatever was typed.
             */
            if ($products->count() < $n) {
                $have = $products->pluck('id')->all();
                $brandIds = $products->pluck('brand_id')->filter()->unique()->all();

                $filler = Product::query()
                    ->select(self::CARD_COLUMNS)
                    ->visible()
                    ->with('brand:id,name,slug')
                    ->whereNotIn('id', $have ?: [0])
                    ->when($brandIds !== [], fn ($w) => $w->orderByRaw(
                        'CASE WHEN brand_id IN (' . implode(',', array_fill(0, count($brandIds), '?')) . ') THEN 0 ELSE 1 END',
                        $brandIds
                    ))
                    ->orderByDesc('total_sales')
                    ->limit($n - $products->count())
                    ->get();

                $products = $products->concat($filler);
            }

            if ($products->isNotEmpty()) {
                $groups[] = [
                    'key' => 'products',
                    'label' => 'Products',
                    'items' => $products->map(fn ($p) => [
                        'label' => $p->name,
                        'meta' => $p->brand?->name,
                        'price' => Money::plain($p->effectivePrice()),
                        'image' => $p->image,
                        // The theme's .si swatch falls back to a gradient with
                        // initials when a product has no photo.
                        'colour' => \App\Support\Gradient::for(($p->brand?->name ?? '') . $p->name),
                        'initials' => \App\Support\Gradient::initials($p->brand?->name ?: $p->name),
                        'url' => $p->url(),
                    ])->all(),
                ];
            }
        }

        if ($n = (int) $this->header->get('search_limit_categories')) {
            $cats = SearchTerms::whereLike(
                Category::query()->select('id', 'name', 'slug'), 'categories.name', $q
            )
                ->withCount(['products' => fn ($w) => $w->visible()])
                ->groupBy('categories.id', 'categories.name', 'categories.slug')
                ->having('products_count', '>', 0)
                ->orderByDesc('products_count')
                ->limit($n)
                ->get();

            if ($cats->isNotEmpty()) {
                $groups[] = [
                    'key' => 'categories',
                    'label' => 'Categories',
                    'items' => $cats->map(fn ($c) => [
                        'label' => $c->name,
                        'meta' => $c->products_count . ' products',
                        'colour' => \App\Support\Gradient::for($c->name),
                        'initials' => \App\Support\Gradient::initials($c->name),
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
                ->limit($n)
                ->get();

            if ($brands->isNotEmpty()) {
                $groups[] = [
                    'key' => 'brands',
                    'label' => 'Brands',
                    'items' => $brands->map(fn ($b) => [
                        'label' => $b->name,
                        'meta' => $b->products_count . ' products',
                        'colour' => \App\Support\Gradient::for($b->name),
                        'initials' => \App\Support\Gradient::initials($b->name),
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
                ->select('id', 'name', 'slug', 'brand_id', 'price', 'sale_price', 'image')
                ->visible()
                ->with('brand:id,name')
                ->orderByDesc('total_sales')
                ->limit((int) $header->get('search_results_max'))
                ->get()
                ->map(fn ($p) => [
                    'name' => $p->name,
                    'brand' => $p->brand?->name,
                    'url' => Url::to('/product/' . $p->slug . '/'),
                    'image' => $p->image,
                    'price' => Money::format($p->effectivePrice()),
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
}
