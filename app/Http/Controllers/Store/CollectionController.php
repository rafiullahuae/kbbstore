<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\ConcernCollections;
use Illuminate\Http\Request;

/**
 * Curated product listings that are not categories: New In, Best Sellers,
 * Super Sale and Under 54 AED.
 *
 * These are the header links that were dead. Each is a query over the same
 * catalogue rather than a stored collection, so nothing needs maintaining as
 * products come and go.
 */
class CollectionController extends Controller
{
    private const PER_PAGE = 24;

    /** Same narrow select the shop uses; ShopController keeps its copy private. */
    private const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'position', 'type', 'total_sales',
    ];

    /**
     * key => [title, intro, how to select]
     *
     * ── THE WORDING HERE IS THE ENGLISH SOURCE, NOT THE OUTPUT — Lane FB ────
     *
     * A const cannot call __(), and this row carries the SELECTION MODE beside
     * the wording — 'newest', 'popular', 'on_sale', 'budget' — which is what
     * show() switches on. So the constant stays exactly as it is and the
     * DISPLAY copy is looked up by key in wordingFor() below, under
     * `store.collection.*`. Nothing compares the title or the intro against
     * anything; the URL key and the mode are what the code reads, and both are
     * untouched.
     *
     * CollectionPhpLabelsAreKeyedTest holds the English here and the English in
     * InterfaceStrings to each other, because a second English source fails
     * silently and in only one direction.
     */
    private const COLLECTIONS = [
        'new-in' => [
            'New In',
            'The latest Korean skincare to land, newest first.',
            'newest',
        ],
        'best-sellers' => [
            'Best Sellers',
            // Resolved in show(): the sentence is only true if the order
            // history says so. App\Support\RepeatPurchase measures it, and
            // hands back a units-sold wording on a shop that has no repeat
            // purchases yet -- which is every shop before its first returning
            // customer, and was the state this page claimed loyalty in.
            '',
            'popular',
        ],
        'super-sale' => [
            'Super Sale',
            'Every product currently reduced.',
            'on_sale',
        ],
        'under-54' => [
            'Everything under AED 54',
            'Small joys, gently priced.',
            'budget',
        ],
    ];

    public function __construct(private SettingsService $settings) {}

    public function show(Request $request, string $key)
    {
        abort_unless(isset(self::COLLECTIONS[$key]), 404);

        [, , $mode] = self::COLLECTIONS[$key];

        [$title, $intro] = $this->wordingFor($key);

        if ($mode === 'popular') {
            $intro = \App\Support\RepeatPurchase::intro();
        }

        $query = Product::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->with('brand:id,name,slug');

        /*
         * EVERY ONE OF THESE ENDS IN `id`, BECAUSE ALL FOUR ARE PAGINATED.
         *
         * `paginate()` below is LIMIT/OFFSET over whatever order this match
         * arm left behind, and LIMIT/OFFSET is only a partition of the list
         * when the order is TOTAL. Where the sort key ties, the database is
         * free to break the tie differently between the request that built
         * page 1 and the request that built page 2 -- they are two separate
         * queries, minutes apart, and nothing carries the first one's tie
         * decision into the second. A product then appears on both pages, or
         * on neither.
         *
         * That is not theoretical here. `products_total_sales_index` (added by
         * 2026_10_11_000000_clear_caches_storefront_speed) means the planner
         * has two ways to answer `ORDER BY total_sales DESC`: walk the index
         * backwards, or sort. Both are correct, and over a tied group they
         * return the tied rows in OPPOSITE orders -- measured, not assumed.
         * Which one it picks is a costing decision that depends on the row
         * estimate, and the estimate is not the same for `LIMIT 24` as it is
         * for `LIMIT 24 OFFSET 24`.
         *
         * `review_count` was the tie-break on 'popular' and is not one: it is
         * 0 for most of this catalogue, so on the rows that actually tie on
         * total_sales it ties too. It is kept because where it does differ it
         * is the better signal; `id` goes after it as the key that cannot tie.
         *
         * THIS DOES NOT REORDER ANYTHING THAT WAS ALREADY ORDERED. A final key
         * only ever decides between rows the preceding keys called equal.
         */
        match ($mode) {
            // Newest by publication where it exists, falling back to id so a
            // catalogue imported without dates still orders sensibly.
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            /*
             * Repeat buyers first, then the ordering this page already had.
             * On a shop with no repeat purchases in its history applyTo()
             * emits byte-for-byte the SQL this line used to, tie-break
             * included -- pinned by comparing toSql().
             */
            'popular' => \App\Support\RepeatPurchase::applyTo($query),
            'on_sale' => $query
                ->whereNotNull('sale_price')
                ->where('sale_price', '>', 0)
                ->whereColumn('sale_price', '<', 'price')
                ->orderByRaw('(price - sale_price) / price DESC')
                ->orderByDesc('id'),
            'budget' => $query
                ->whereRaw('COALESCE(NULLIF(sale_price, 0), price) <= ?', [5400])
                ->orderByRaw('COALESCE(NULLIF(sale_price, 0), price) ASC')
                ->orderBy('id'),
        };

        /*
         * The page number is read off the request rather than left to
         * Paginator::resolveCurrentPage().
         *
         * That resolver is a closure the pagination service provider captured
         * around the application instance at boot, and this project replaces
         * that instance: warm_caches_2_60_4 runs config:cache and route:cache,
         * each of which constructs a fresh Application and repoints the
         * container at it (tests/Pest.php documents the same trap for
         * request() inside a view). The resolver goes on reading the discarded
         * app's request, so it answers page 1 for every URL -- which is
         * precisely the thing this method now has to be right about. Reading
         * $request, which is the request the router matched, cannot go stale.
         */
        $page = max(1, (int) $request->query('page', 1));

        $products = $query->paginate(self::PER_PAGE, ['*'], 'page', $page)->withQueryString();

        // Same rule as ShopController: a page number past the end is not a
        // page. Here it answers 200 with an empty grid rather than a copy of
        // page one, which is a thin page instead of a duplicate one, but the
        // supply of them is just as unbounded and each still publishes itself
        // as its own canonical.
        if ($page > 1 && $page > $products->lastPage()) {
            abort(404);
        }

        return view('store.collection', [
            'key' => $key,
            'title' => $title,
            'intro' => $intro,
            'products' => $products,
            'settings' => $this->settings,
            'seoCtx' => $this->seoCtx($request, $title, $intro, $products->total(), $page, $products),
        ]);
    }

    /**
     * A concern-led listing: /concern/acne/.
     *
     * ── WHY THIS IS ITS OWN ACTION AND NOT A FIFTH ROW IN COLLECTIONS ──────
     *
     * The four listings above are QUERIES OVER THE WHOLE CATALOGUE with a
     * hard-coded arm each -- newest, popular, on_sale, budget -- and every one
     * of them always has products, so every one of them always exists. A
     * concern listing is none of that: it selects on a column an operator
     * fills in, it may be empty, and whether it exists at all is a decision
     * (App\Support\ConcernCollections::isLive()) rather than a constant. Bolting
     * it onto show() would mean a match arm that can 404 on a key show() has
     * already promised is valid, and one route parameter meaning two different
     * kinds of thing.
     *
     * It is also ONE ACTION FOR ALL EIGHT CONCERNS, which is the whole point:
     * adding `dryness` later is copy plus a slug in ENABLED, and touches no
     * route file. routes/web.php is not this lane's to edit and should not need
     * editing again for the next seven.
     *
     * EVERYTHING BELOW THE SELECTION IS THE SAME CODE THE OTHER FOUR RUN --
     * the same narrow select, the same paginate-with-$request-page, the same
     * past-the-end 404, the same seoCtx. A concern page is a collection page;
     * it should not differ from one by accident.
     */
    public function concern(Request $request, string $concern)
    {
        /*
         * 404 UNTIL THE PAGE IS WORTH VISITING, and that is the feature.
         *
         * A concern with no copy, or with fewer than MIN_PRODUCTS live
         * products, has no page: not an empty grid, not a "coming soon". See
         * ConcernCollections' header for why a thin page here is worse than no
         * page -- the sitemap asks the same class the same question, so it
         * cannot advertise a URL this line refuses.
         */
        abort_unless(ConcernCollections::isLive($concern), 404);

        [$title, $intro] = $this->concernWording($concern);

        $query = ConcernCollections::query($concern, self::CARD_COLUMNS)
            ->with('brand:id,name,slug');

        /*
         * Featured first, then the shop's own default order, then id.
         *
         * ENDING IN `id` FOR THE REASON THE FOUR ABOVE DO, which show()'s
         * docblock sets out in full: paginate() is LIMIT/OFFSET over whatever
         * order this leaves behind, and LIMIT/OFFSET only partitions the list
         * when the order is TOTAL. `featured` is a boolean and `position` ties
         * across most of this catalogue, so without a key that cannot tie a
         * product can appear on two pages or on neither.
         */
        $query->orderByDesc('featured')->orderBy('position')->orderBy('id');

        $page = max(1, (int) $request->query('page', 1));

        $products = $query->paginate(self::PER_PAGE, ['*'], 'page', $page)->withQueryString();

        if ($page > 1 && $page > $products->lastPage()) {
            abort(404);
        }

        return view('store.collection', [
            'key' => 'concern-' . $concern,
            'title' => $title,
            'intro' => $intro,
            'products' => $products,
            'settings' => $this->settings,
            'seoCtx' => $this->seoCtx($request, $title, $intro, $products->total(), $page, $products),
        ]);
    }

    /**
     * A concern page's heading and its sentence, in the shopper's language.
     *
     * KEYED, exactly as wordingFor() is, and for the same reason: a const
     * cannot call __(), and an English string compared against anything is a
     * second English source that fails silently in one direction. The key is
     * built from the concern slug, which is the same slug RoutineConcerns
     * stores and the URL carries, so the page and its copy cannot be paired up
     * by eye and got wrong.
     *
     * A CLOSED LIST AND NOT AN INTERPOLATION would be the rule here as it is
     * there -- but concern() has already refused anything not in
     * ConcernCollections::ENABLED before this is reached, and ENABLED is a
     * constant in this application rather than anything a URL can reach. The
     * slug is `[a-z-]+` by construction of RoutineConcerns::LIST.
     *
     * @return array{0:string,1:string}  [title, intro]
     */
    private function concernWording(string $concern): array
    {
        return [
            __('store.concern.title_' . str_replace('-', '_', $concern)),
            __('store.concern.intro_' . str_replace('-', '_', $concern)),
        ];
    }

    /**
     * This listing's heading and the sentence under it, in the shopper's
     * language.
     *
     * KEYED BY THE URL KEY, with the dash that cannot appear in a translation
     * key replaced by an underscore, so the constant's row and the key are
     * paired by the same string rather than by eye. The match is a CLOSED LIST
     * rather than an interpolation: show() has already 404'd anything not in
     * COLLECTIONS, and a key composed from a URL segment is how a listing ends
     * up rendering its own slug at a shopper.
     *
     * /best-sellers/ returns '' for the intro exactly as the constant does —
     * its sentence is a measurement and show() asks RepeatPurchase for it.
     *
     * @return array{0:string,1:string}  [title, intro]
     */
    private function wordingFor(string $key): array
    {
        return match ($key) {
            'new-in' => [__('store.collection.title_new_in'), __('store.collection.intro_new_in')],
            'best-sellers' => [__('store.collection.title_best_sellers'), ''],
            'super-sale' => [__('store.collection.title_super_sale'), __('store.collection.intro_super_sale')],
            'under-54' => [__('store.collection.title_under_54'), __('store.collection.intro_under_54')],
        };
    }

    /**
     * What these four pages tell a search engine about themselves.
     *
     * They passed no seoCtx at all, so the layout's defaults applied and all
     * four published the same thing: the store-wide default meta description
     * (identical to the homepage's and to every other page without one), no
     * breadcrumb, and a canonical built from getPathInfo() — which drops the
     * query string, so /new-in?page=2 canonicalised to /new-in.
     *
     * That last one is the expensive half, and Facets already carries the
     * reasoning for the shop: collapsing page two onto page one tells Google
     * page two does not exist, and everything only reachable from page two
     * goes with it. On a "New In" listing that is specifically the products
     * that have just landed. The two listings on this site should not disagree
     * about it, and the fix is the same self-referencing canonical the shop
     * already emits.
     *
     * Only ?page is carried into the canonical, not the whole query string:
     * these listings have no facets, so any other parameter on the URL (a
     * campaign tag, a stray ?ref=) is not a different document and must not
     * become a different canonical.
     */
    private function seoCtx(
        Request $request,
        string $title,
        string $intro,
        int $total,
        int $page,
        \Illuminate\Contracts\Pagination\LengthAwarePaginator $products
    ): array {
        /*
         * NO DELIVERY PROMISE IN A META DESCRIPTION — the same removal, and the
         * same reasoning, as ShopController::seoDescription(), whose docblock
         * carries it in full. Both halves ended in a transit time attached to
         * one country, on every collection page and in the search result Google
         * shows for it, and the window they named is not the one the shop
         * records. Nothing replaces the clause: what is left is a count of real
         * rows.
         */
        $description = $total > 0
            ? "{$intro} {$total} authentic Korean skincare products at K-Beauty Bliss."
            : "{$intro} Authentic Korean skincare at K-Beauty Bliss.";

        // SeoSettings, not Setting::map(): the latter memoises in a
        // process-level static as well as the cache, and a page rendered
        // before that map was first filled produced a root-relative trail
        // inside an otherwise absolute document. Same reasoning, same fix, as
        // Store\ProductController's seoCtx closure.
        $base = rtrim(\App\Services\Seo\SeoSettings::get('site_url', ''), '/');
        $path = rtrim($request->getPathInfo(), '/') . '/';

        return [
            'description' => $description,
            /*
             * WHAT THESE FOUR PAGES ARE, AND WHAT IS ON THEM.
             *
             * All four are lists of products and none of them said so: the
             * document carried the sitewide Organization and WebSite nodes, a
             * BreadcrumbList, and no statement of type at all. `collection`
             * makes both halves machine-readable -- see App\Support\Seo's
             * CollectionPage branch for the shape, and
             * App\Support\CollectionSchema for why the price in it is a
             * decimal string built from integer fils and never the column.
             *
             * UNCONDITIONAL HERE, unlike the shop's. These listings have no
             * facets and no sort -- the docblock above says so, and says why
             * only ?page is carried into the canonical -- so the canonical is
             * always this page and the rows are always its own. There is no
             * filtered view whose contents could be attached to somebody
             * else's URL.
             *
             * firstItem() is the 1-based index of this page's first row within
             * the whole listing, so firstItem() - 1 is the count of rows before
             * it and positions on page two run 25..48. It is null on an empty
             * listing, which ?? 1 turns back into an offset of zero -- and an
             * empty listing has no rows to number anyway.
             */
            'type' => 'collection',
            'collection' => \App\Support\CollectionSchema::from(
                $products,
                $base,
                ($products->firstItem() ?? 1) - 1
            ) + ['name' => $title],
            // Page one canonicalises to the clean URL — ?page=1 and the bare
            // path are the same document, and only one of them should be it.
            'url' => $page > 1 ? $base . $path . '?page=' . $page : $base . $path,
            'breadcrumb' => [
                ['name' => 'Home', 'url' => $base . '/'],
                ['name' => 'Shop', 'url' => $base . '/shop/'],
                ['name' => $title, 'url' => $base . $path],
            ],
        ];
    }
}
