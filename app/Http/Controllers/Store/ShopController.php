<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\Facets;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Shop, category, brand and search archive.
 *
 * Filtering is server-side so every filtered view has its own indexable URL —
 * the previous build filtered in the browser, which meant Google could not index
 * a single filtered page across 671 products and 93 brands.
 */
class ShopController extends Controller
{
    /** Only the columns the card renders. The row also carries description,
     *  seo, meta_feed and images — several KB each, never used here.
     *  `created_at` is in the list because ProductLabels reads it for the
     *  "New" badge — while it was missing that badge could never fire on a
     *  listing, only on the product page. */
    private const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'position', 'type', 'total_sales',
        'created_at',
    ];

    public function __construct(private SettingsService $settings) {}

    public function index(Request $request, ?string $categorySlug = null): View
    {
        // Facets::active() memoises in a process-level static. Under PHP-FPM
        // that is one request and harmless; in the test suite, a queue worker
        // or Octane it would hand this request the previous one's filters.
        Facets::reset();

        $active = Facets::active();
        $page = Facets::page();
        $perPage = (int) $this->settings->get('products_per_page', 24);

        $query = Product::query()
            ->visible()
            ->select(self::CARD_COLUMNS)
            ->with('brand:id,name,slug');

        $category = $categorySlug ? Category::where('slug', $categorySlug)->first() : null;

        if ($category) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id));
        }

        $this->applyFacets($query, $active, (string) $request->query('s', ''));
        $this->applySort($query, Facets::sort());

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $products = $query->forPage(min($page, $lastPage), $perPage)->get();

        [$title, $sub, $crumb] = $this->heading($category, (string) $request->query('s', ''));

        return view('store.shop', [
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'cols' => Facets::columns(),
            'sorts' => Facets::SORTS,
            'curorder' => Facets::sort(),
            'buckets' => Facets::BUCKETS,
            'title' => $title,
            'seoCtx' => [
                'description' => $this->seoDescription($category, (string) $request->query('s', ''), $total),
                'url' => Facets::canonicalUrl($this->absoluteListingUrl($category)),
                'breadcrumb' => $this->breadcrumbTrail($category),
            ],
            'sub' => $sub,
            'crumb' => $crumb,
            'clearUrl' => $category ? $category->url() : Facets::clearUrl(),
            'chips' => $this->chips($active),
            // The sidebar is identical for every visitor and only changes with
            // the catalogue, so it is cached rather than recounted per view.
            // Ordered by the curated `position` FIRST, then by size.
            //
            // It was ordered by size alone, which meant the category reorder in
            // Store → Catalog → Categories wrote `categories.position` and
            // nothing on the storefront ever read it: the owner could reorder
            // the tree all day and this list never moved. "Most products" is
            // not a merchandising decision, and it is not one the owner asked
            // for.
            //
            // Backward compatible by construction: `position` defaults to 0 on
            // every row and nothing has ever written it, so until the owner
            // actually reorders something, every row ties at 0 and the size
            // ordering below decides exactly as before.
            'cats' => Cache::remember('kbb.shop.cats', 900, fn () => Category::query()
                ->select('id', 'name', 'slug')
                ->withCount(['products' => fn ($q) => $q->visible()])
                ->groupBy('categories.id', 'categories.name', 'categories.slug', 'categories.position')
                ->having('products_count', '>', 0)
                ->orderBy('categories.position')
                ->orderByDesc('products_count')
                ->limit(30)
                ->get()),
            // Same reasoning as the categories above: `brands.position` has
            // existed since the original schema and nothing has ever read it,
            // so the brand reorder had nowhere to show up. Ties at 0 fall back
            // to alphabetical, which is what this did before.
            'brands' => Cache::remember('kbb.shop.brands', 900, fn () => Brand::query()
                ->select('id', 'name', 'slug')
                ->withCount(['products' => fn ($q) => $q->visible()])
                ->groupBy('brands.id', 'brands.name', 'brands.slug', 'brands.position')
                ->having('products_count', '>', 0)
                ->orderBy('brands.position')
                ->orderBy('name')
                ->limit(40)
                ->get()),
        ]);
    }

    private function applyFacets($query, array $active, string $search): void
    {
        if ($search !== '') {
            // Expanded before matching: "moisturiser" has to reach a product
            // labelled "Moisturizer", and "sun cream" has to reach "sunscreen".
            // Matching the raw string returns nothing and the shopper concludes
            // the shop does not stock it.
            //
            // Also note the escaping. The old code interpolated the term
            // straight into the pattern, so a shopper typing "50%" searched for
            // "anything, then 50, then anything" -- not a SQL injection, since
            // the value is still bound, but wrong results all the same.
            $terms = \App\Support\SearchTerms::expand($search);

            $query->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    \App\Support\SearchTerms::orWhereLike($q, 'products.name', $term);
                    \App\Support\SearchTerms::orWhereLike($q, 'products.sku', $term);
                    $q->orWhereHas('brand', fn ($b) => \App\Support\SearchTerms::whereLike($b, 'brands.name', $term));
                }
            });
        }

        if ($active['cat']) {
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.slug', $active['cat']));
        }

        if ($active['brand']) {
            $query->whereHas('brand', fn ($q) => $q->whereIn('slug', $active['brand']));
        }

        if ($active['price'] && isset(Facets::BUCKETS[$active['price']])) {
            [, $min, $max] = Facets::BUCKETS[$active['price']];
            // Stored in fils, declared in AED.
            if ($min !== null) { $query->where('price', '>=', $min * 100); }
            if ($max !== null) { $query->where('price', '<=', $max * 100); }
        }

        if ($active['sale'] === '1') {
            $query->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price');
        }

        if ($active['instock'] === '1') {
            $query->where('stock_status', 'instock');
        }
    }

    private function applySort($query, string $orderby): void
    {
        match ($orderby) {
            'popularity' => $query->orderByDesc('total_sales'),
            'plow' => $query->orderBy('price'),
            'phigh' => $query->orderByDesc('price'),
            'rating' => $query->orderByDesc('rating')->orderByDesc('review_count'),
            'date' => $query->orderByDesc('created_at'),
            'name' => $query->orderBy('name'),
            // "Featured" is the curated order the Sorting module maintains.
            default => $this->applyDefaultSort($query),
        };
    }

    /**
     * "Featured" — the shop's default sort, and the one surface the
     * `product_sorting` module actually governs.
     *
     * The curated order lives in `products.position`, written by Store →
     * Catalog → Reorder (CatalogReorderApiController). This is the plugin's
     * own idea: WordPress's `menu_order` baked into WooCommerce's "Default
     * sorting". Off, the column is simply not consulted and the default view
     * falls back to featured-first, then alphabetical — which is exactly what
     * this query did before a curated order existed at all.
     *
     * This gate is the whole module. Before it, `position` was in the ORDER BY
     * unconditionally and the switch on Store → Modules changed nothing
     * whatsoever, while the registry advertised it as `live`.
     *
     * Deliberately NOT gated: the `menu_order` option of the [kbb_products]
     * shortcode (App\Support\Shortcodes). That is an explicit, per-shortcode
     * request for the curated order, not the default sort the module is about,
     * and silently ignoring an author's explicit choice is a different bug.
     */
    private function applyDefaultSort($query)
    {
        $query->orderByDesc('featured');

        if ($this->settings->moduleEnabled('product_sorting', false)) {
            $query->orderBy('position');
        }

        return $query->orderBy('name');
    }

    /**
     * A real, page-specific meta description instead of falling back to one
     * sitewide default everywhere — every category, search, and the general
     * shop page gets its own, distinct text. Duplicate meta descriptions
     * across a catalogue's category pages is flagged as a real quality
     * signal problem, not just a missed opportunity, so this isn't
     * cosmetic. Kept deliberately short (under ~130 characters) rather than
     * padded out to the full 160-character budget — accurate and concise
     * reads better than stretched, and Google truncates hard past 155-160
     * on desktop and roughly 120 on mobile regardless.
     */
    /**
     * BreadcrumbList schema's own trail, not the single visual label
     * `heading()` returns — search-engine breadcrumb schema needs the
     * real ancestor chain with URLs (Home → Shop → Category), which
     * nothing in this controller was building until now; the schema
     * renderer itself (Seo::jsonLd()) has accepted a 'breadcrumb' context
     * key from the start, but no controller ever actually supplied one.
     */
    private function breadcrumbTrail(?Category $category): array
    {
        $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');
        $trail = [
            ['name' => 'Home', 'url' => $base . '/'],
            ['name' => 'Shop', 'url' => $base . '/shop/'],
        ];

        if ($category) {
            $trail[] = ['name' => $category->name, 'url' => $base . $category->url()];
        }

        return $trail;
    }

    /**
     * Category::url() (like Product::url()) is deliberately root-relative
     * for <a href> links — a canonical tag needs the real, absolute URL,
     * so site_url is prepended by hand here rather than reused as-is.
     */
    private function absoluteListingUrl(?Category $category): string
    {
        $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');

        return $base . ($category ? $category->url() : '/shop/');
    }

    private function seoDescription(?Category $category, string $search, int $total): string
    {
        if ($category) {
            $count = $total ? "{$total} authentic Korean skincare picks" : 'authentic Korean skincare';

            return "Shop {$category->name} at K-Beauty Bliss — {$count}, next-day UAE delivery.";
        }

        if ($search !== '') {
            $result = $total === 1 ? 'result' : 'results';

            return "\"{$search}\" — {$total} {$result} at K-Beauty Bliss, authentic Korean skincare with next-day UAE delivery.";
        }

        return "Browse every K-Beauty Bliss product — {$total} authentic Korean skincare picks, from serums to beauty devices, next-day UAE delivery.";
    }

    private function heading(?Category $category, string $search): array
    {        if ($category) {
            return [
                $category->name,
                $category->description ?: 'Authentic Korean skincare, curated for the UAE.',
                'Category',
            ];
        }

        if ($search !== '') {
            return ["Search: {$search}", 'Results across products and brands.', 'Search'];
        }

        return ['Shop all', 'Authentic Korean skincare, curated for the UAE.', 'Shop'];
    }

    /**
     * Removable chips for whatever is currently applied.
     *
     * The two lookups are batched. Selecting eight brands used to mean eight
     * separate `select name from brands where slug = ?` round trips just to
     * label the chips above the grid.
     */
    private function chips(array $active): array
    {
        $chips = [];

        $catNames = $active['cat']
            ? Category::whereIn('slug', $active['cat'])->pluck('name', 'slug')
            : collect();

        $brandNames = $active['brand']
            ? Brand::whereIn('slug', $active['brand'])->pluck('name', 'slug')
            : collect();

        foreach ($active['cat'] as $slug) {
            $name = $catNames[$slug] ?? null;
            if ($name) { $chips[] = ['key' => 'cat', 'value' => $slug, 'label' => $name]; }
        }

        foreach ($active['brand'] as $slug) {
            $name = $brandNames[$slug] ?? null;
            if ($name) { $chips[] = ['key' => 'brand', 'value' => $slug, 'label' => $name]; }
        }

        if ($active['price'] && isset(Facets::BUCKETS[$active['price']])) {
            $chips[] = ['key' => 'price', 'value' => $active['price'], 'label' => Facets::BUCKETS[$active['price']][0]];
        }

        if ($active['sale'] === '1') { $chips[] = ['key' => 'sale', 'value' => '1', 'label' => 'On sale']; }
        if ($active['instock'] === '1') { $chips[] = ['key' => 'instock', 'value' => '1', 'label' => 'In stock']; }

        return $chips;
    }

    /** Call after any catalogue write, or the sidebar counts go stale. */
    public static function flushSidebarCache(): void
    {
        Cache::forget('kbb.shop.cats');
        Cache::forget('kbb.shop.brands');
    }
}
