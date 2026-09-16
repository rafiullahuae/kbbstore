<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\SettingsService;
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

    /** key => [title, intro, how to select] */
    private const COLLECTIONS = [
        'new-in' => [
            'New In',
            'The latest Korean skincare to land, newest first.',
            'newest',
        ],
        'best-sellers' => [
            'Best Sellers',
            'The products our customers keep coming back for.',
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

        [$title, $intro, $mode] = self::COLLECTIONS[$key];

        $query = Product::query()
            ->select(self::CARD_COLUMNS)
            ->visible()
            ->with('brand:id,name,slug');

        match ($mode) {
            // Newest by publication where it exists, falling back to id so a
            // catalogue imported without dates still orders sensibly.
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'popular' => $query->orderByDesc('total_sales')->orderByDesc('review_count'),
            'on_sale' => $query
                ->whereNotNull('sale_price')
                ->where('sale_price', '>', 0)
                ->whereColumn('sale_price', '<', 'price')
                ->orderByRaw('(price - sale_price) / price DESC'),
            'budget' => $query
                ->whereRaw('COALESCE(NULLIF(sale_price, 0), price) <= ?', [5400])
                ->orderByRaw('COALESCE(NULLIF(sale_price, 0), price) ASC'),
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
            'seoCtx' => $this->seoCtx($request, $title, $intro, $products->total(), $page),
        ]);
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
    private function seoCtx(Request $request, string $title, string $intro, int $total, int $page): array
    {
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
