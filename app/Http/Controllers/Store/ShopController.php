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

    /**
     * @param  ?Category  $category  the row, when the caller has already got it.
     *
     * CategoryArchiveController resolves the category to decide between 200,
     * 301 and 404, and then handed the slug down here for a second
     * `select * from categories where slug = ?` against the row it was holding.
     * Every category archive on the site paid for that — 10 queries on the
     * fixture, 9 with it passed. Still optional, and still resolved from the
     * slug when it is not supplied, so any other caller is unaffected.
     */
    public function index(Request $request, ?string $categorySlug = null, ?Category $category = null): View
    {
        // Facets::active() memoises in a process-level static. Under PHP-FPM
        // that is one request and harmless; in the test suite, a queue worker
        // or Octane it would hand this request the previous one's filters.
        Facets::reset();

        $active = Facets::active();
        $page = Facets::page();
        $perPage = (int) $this->settings->get('products_per_page', 24);

        /*
         * EVERY CARD COLUMN QUALIFIED WITH ITS TABLE.
         *
         * A search joins `brands` (applyFacets below), and `brands` carries a
         * column called id, one called slug, one called name, one called
         * position and one called created_at — five of the eighteen this list
         * asks for. Unqualified, MySQL answers "Column 'id' in field list is
         * ambiguous" and every search on the shop is a 500. SQLite is happy to
         * guess, which is exactly the MySQL-parity gap docs/MYSQL-PARITY.md
         * exists for, so this is qualified once here rather than discovered on
         * the live host.
         *
         * `products.name` and `name` select the same column when nothing is
         * joined, so the unsearched query is unchanged.
         */
        $query = Product::query()
            ->visible()
            ->select(array_map(static fn (string $c): string => 'products.' . $c, self::CARD_COLUMNS))
            ->with('brand:id,name,slug');

        $category ??= $categorySlug ? Category::where('slug', $categorySlug)->first() : null;

        if ($category) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id));
        }

        $this->applyFacets($query, $active, (string) $request->query('s', ''));
        $this->applySort($query, Facets::sort());

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        /*
         * A page number past the end is not a page.
         *
         * forPage(min($page, $lastPage)) below clamps out-of-range requests
         * back onto page one, so every one of /shop/?paged=2, ?paged=57 and
         * ?paged=4000 answered 200 with the page-one grid -- and the canonical
         * Facets::canonicalUrl() then emitted for it was that same out-of-range
         * URL, self-referencing. That is an unbounded supply of crawlable,
         * self-canonicalising duplicates of page one, on /shop/ and on every
         * category archive, and Google will happily walk it: each fetch is
         * crawl budget taken off a product page, and each is a duplicate of a
         * page that is already indexed.
         *
         * 404 rather than a redirect or a canonical pointing back at page one,
         * for the same reason CategoryArchiveController 404s an unknown
         * category: the URL does not identify anything, and a 301 from an
         * unbounded space onto one real page is a soft 404 wearing a 301. Only
         * page numbers above the last one are affected -- page one, and every
         * page that really exists, are untouched, so the self-referencing
         * canonical on a genuine /shop/?paged=2 still stands.
         */
        if ($page > 1 && $page > $lastPage) {
            abort(404);
        }

        $products = $query->forPage(min($page, $lastPage), $perPage)->get();

        [$title, $sub, $crumb] = $this->heading($category, (string) $request->query('s', ''));

        $banner = $this->banner($category, $active, (string) $request->query('s', ''), $title);

        /*
         * PER-CATEGORY SEO OVERRIDES — `categories.seo`, unread until now.
         *
         * The same column, the same shape and the same story as `brands.seo`;
         * Store\BrandController::seoCtx() carries the reasoning in full and
         * this is the other half of it. Store → Catalog → Categories has been
         * collecting an SEO title and description, saving them into this
         * column and publishing neither, so a category archive — the highest
         * intent page on the site, which is what "korean sunscreen uae" lands
         * on — took its title from the bare category name and its description
         * from seoDescription() below whatever the owner typed.
         *
         * Through ProductSeo::normalise() for the reason given there: the
         * admin writes `seo.description` and the published vocabulary spells
         * that key `desc`, and ProductSeo::RENAME is the existing map between
         * them. Reusing it is what makes this the product mechanism rather
         * than a second one beside it.
         *
         * NULL FOR /shop/ AND FOR A SEARCH, which have no category and
         * therefore no row to carry an override.
         */
        $catSeo = $category ? (\App\Support\ProductSeo::normalise($category->seo) ?? []) : [];

        /*
         * THE CANONICAL OVERRIDE REPLACES THE CLEAN BASE URL, NOT THE WHOLE
         * CANONICAL — and that distinction is the only safe way to do it here.
         *
         * Facets::canonicalUrl() is what makes /shop/?paged=2 canonicalise to
         * itself rather than to page one; its own docblock records why
         * collapsing later pages is worse than the crawl cost of indexing
         * them. Substituting the owner's URL for the whole canonical would
         * reintroduce exactly that: every page of a category, and every
         * filtered view of it, claiming to be one document. Substituting it
         * for the base the method builds FROM keeps pagination and facet
         * collapsing behaving as they do on every other listing.
         *
         * Url::absolute(), never Url::to(): the owner's path already carries
         * whatever language it meant, and to() would strip that and apply this
         * reader's. absolute() passes a full http(s) URL through untouched.
         */
        $canonicalBase = trim((string) ($catSeo['canonical'] ?? ''));
        $listingUrl = $canonicalBase !== ''
            ? Url::absolute($canonicalBase)
            : $this->absoluteListingUrl($category);

        return view('store.shop', [
            'banner' => $banner,
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'cols' => Facets::columns(),
            'sorts' => Facets::SORTS,
            'curorder' => Facets::sort(),
            'buckets' => Facets::BUCKETS,
            'title' => $title,
            'seoCtx' => array_filter([
                /*
                 * A title the owner typed is the WHOLE title — `title_is_final`
                 * stops Seo::render() appending the site name through
                 * `seo_title_template`, which is exactly how
                 * Store\ProductController::show() treats a per-product title.
                 * Both keys are null when the box is empty and array_filter
                 * drops them, so an un-overridden archive is byte-for-byte the
                 * page it was.
                 */
                'title' => trim((string) ($catSeo['title'] ?? '')) !== '' ? $catSeo['title'] : null,
                'title_is_final' => (trim((string) ($catSeo['title'] ?? '')) !== '') ?: null,
                'description' => trim((string) ($catSeo['desc'] ?? '')) !== ''
                    ? $catSeo['desc']
                    : $this->seoDescription($category, (string) $request->query('s', ''), $total),
                /*
                 * The share image is the hero this page actually draws.
                 *
                 * Every category archive published the store-wide default share
                 * image, so a category with its own banner photograph shared as
                 * the generic shop card. $banner is already resolved above for
                 * the view — this reads the same value rather than deciding a
                 * second time, so the picture in the share card and the picture
                 * at the top of the page cannot disagree.
                 *
                 * PageBanner::resolve() has already dropped the image for the
                 * styles that never draw one (tint, no-image), which is why
                 * this is the right key to read and `categories.banner` is not:
                 * a banner switched off still has a URL in the column, and
                 * publishing it would share a photograph the page does not show.
                 *
                 * Null — no banner, or a search/filter page that has none —
                 * falls through to og_default_image, unchanged.
                 */
                /*
                 * An explicit og_image override wins over the banner, exactly
                 * as it does on a product (`$override['og_image'] ?? $product->image`).
                 *
                 * `categories.image` is STILL not read, here or anywhere: it
                 * has no storefront consumer at all, so publishing it as an
                 * og:image would share a picture that appears nowhere on the
                 * page. The banner stays the fallback because the banner is
                 * the hero this page actually draws.
                 */
                'image' => trim((string) ($catSeo['og_image'] ?? '')) !== ''
                    ? $catSeo['og_image']
                    : (is_string($banner['image'] ?? null) && $banner['image'] !== '' ? $banner['image'] : null),
                'url' => Facets::canonicalUrl($listingUrl),
                /*
                 * A category the owner has marked noindex is noindexed on
                 * EVERY view of itself — page two, a filtered view, a sort —
                 * because this is read once for the archive and not per facet.
                 * Store\SeoFilesController drops the same categories from the
                 * sitemap, so the two documents cannot disagree.
                 */
                'noindex' => ! empty($catSeo['noindex']) ?: null,
                'breadcrumb' => $this->breadcrumbTrail($category),
            ], static fn ($v) => $v !== null),
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
                // And `categories.id`, because this is a LIMIT: a tie on the
                // count at the thirtieth place decides which category the
                // filter rail offers at all.
                ->orderBy('categories.id')
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
                // Same as the categories above; brand names are not unique.
                ->orderBy('brands.id')
                ->limit(40)
                ->get()),
        ]);
    }

    /**
     * The banner for whatever page this request actually is, or null.
     *
     * WHAT COUNTS AS A BRAND PAGE, given U-05.
     *
     * A brand has no path of its own. Brand::url() is
     * /shop/?filter_brands={slug} and the contract says so in as many words —
     * "this must not be 'improved' into a pretty URL". So the brand's listing
     * is this controller, reached through a query parameter, and the only
     * honest definition of "the Estée Lauder page" available here is: the
     * brand facet holds exactly one slug, and nothing else is narrowing the
     * results.
     *
     * The three exclusions are all cases where the banner would be a lie
     * rather than a decoration:
     *
     *   - two or more brands selected. /shop/?filter_brands=a,b is a
     *     comparison, not a brand page; showing one of the two brands' banners
     *     across the top of it would be picking a winner at random.
     *   - a category also selected. The heading already says "Cleansers", and
     *     a brand banner over a category-narrowed grid describes neither.
     *   - a search term. The page is a result set.
     *
     * Deliberately NOT excluded: price, sale, in-stock and paging. Those
     * narrow the grid without changing what the page is about, and dropping
     * the banner on page 2 of a brand would look like a bug.
     *
     * One extra query, only on the single-brand path, and only to fetch the
     * two columns this needs. The category needs none — it is already loaded.
     */
    private function banner(?Category $category, array $active, string $search, string $title): ?array
    {
        if ($category) {
            return \App\Support\PageBanner::forModel($category, $title);
        }

        $oneBrand = count($active['brand']) === 1 && ! $active['cat'] && $search === '';

        if (! $oneBrand) {
            return null;
        }

        $brand = Brand::query()
            ->select('id', 'name', 'banner')
            ->where('slug', $active['brand'][0])
            ->first();

        return \App\Support\PageBanner::forModel($brand, $brand?->name ?? $title);
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

            /*
             * ── ONE LEFT JOIN, NOT A CORRELATED EXISTS PER TERM PER ROW ─────
             *
             * orWhereHas('brand', …) compiles to
             *   exists (select * from brands
             *           where products.brand_id = brands.id and brands.name like ?)
             * which the planner evaluates once per candidate row, per search
             * term. On 3,025 products that is the dominant cost of the COUNT
             * that decides the pagination, and the COUNT runs on every search
             * before a single card is rendered.
             *
             * WHY THE JOIN IS BEHAVIOUR-IDENTICAL AND NOT MERELY EQUIVALENT-
             * LOOKING. products.brand_id is a single nullable foreign key onto
             * the brands primary key, so the join matches AT MOST ONE row per
             * product: it cannot multiply rows, which is the only way a join
             * ever changes a COUNT or a result set. LEFT, not INNER, so a
             * product with brand_id NULL — or pointing at a brand that has been
             * deleted — stays in the result exactly as it did under EXISTS, and
             * `brands.name like ?` is NULL for it, which is not true, which is
             * the same answer EXISTS gave. Product::brand() is a plain
             * belongsTo with no constraints and Brand has no soft deletes, so
             * there is nothing in the relation for the join to leave out.
             *
             * Measured rather than assumed, and the numbers are in the lane
             * report. tests/Feature/ShopSearchJoinTest.php pins the result set,
             * the ordering and the count against the EXISTS form it replaces,
             * on a search matching by product name only, by brand name only, by
             * both, and by neither.
             */
            $query->leftJoin('brands', 'products.brand_id', '=', 'brands.id');

            $query->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    \App\Support\SearchTerms::orWhereLike($q, 'products.name', $term);
                    \App\Support\SearchTerms::orWhereLike($q, 'products.sku', $term);
                    \App\Support\SearchTerms::orWhereLike($q, 'brands.name', $term);
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

            /*
             * Bucketed on the price the SHOPPER IS CHARGED, not on the `price`
             * column.
             *
             * `price` is the pre-sale figure. Reading it here meant a product
             * marked down from AED 200 to AED 50 printed AED 50 on its card and
             * was then filed under "AED 150 – 300", absent from "AED 54 – 150":
             * a shopper filtering by budget was shown everything except the
             * discounted stock, with a Sale badge on the cards that survived.
             *
             * App\Support\EffectivePrice is Product::effectivePrice() in SQL,
             * scheduling window included, so the filter and the card cannot
             * disagree. Bucket bounds are declared in whole AED.
             */
            \App\Support\EffectivePrice::whereRange(
                $query,
                $min === null ? null : $min * 100,
                $max === null ? null : $max * 100,
            );
        }

        if ($active['sale'] === '1') {
            // Product::isOnSale(), not the raw columns. `sale_price IS NOT NULL
            // AND sale_price < price` is true the moment a markdown is
            // scheduled, so a sale set up for next week was listed under "On
            // sale" today — at full price, with no Sale badge, because
            // ProductLabels draws that badge from isOnSale() and the two
            // disagreed.
            \App\Support\EffectivePrice::whereOnSale($query);
        }

        if ($active['instock'] === '1') {
            $query->where('stock_status', 'instock');
        }
    }

    private function applySort($query, string $orderby): void
    {
        /*
         * QUALIFIED, for the same reason the select list above is: a search
         * joins `brands`, and `name`, `position`, `id` and `created_at` all
         * exist on both tables. An unqualified ORDER BY across that join is
         * ambiguous on MySQL and a coin toss on SQLite. Nothing is joined on an
         * unsearched listing, where `products.name` and `name` are the same
         * column and the plan is identical.
         */
        match ($orderby) {
            'popularity' => $query->orderByDesc('products.total_sales'),
            // Same reasoning as the price bucket above: "Price: low to high"
            // has to mean the price on the card. Sorting on the `price` column
            // put an AED 50 markdown where AED 200 belongs, near the end of the
            // cheapest-first list the shopper opened to find it.
            'plow' => \App\Support\EffectivePrice::orderBy($query, 'asc'),
            'phigh' => \App\Support\EffectivePrice::orderBy($query, 'desc'),
            'rating' => $query->orderByDesc('products.rating')->orderByDesc('products.review_count'),
            'date' => $query->orderByDesc('products.created_at'),
            'name' => $query->orderBy('products.name'),
            // "Featured" is the curated order the Sorting module maintains.
            default => $this->applyDefaultSort($query),
        };

        /*
         * AND THEN `id`, WHATEVER THE SHOPPER PICKED.
         *
         * Every arm above leaves ties, and several leave nothing but ties.
         * `rating` and `review_count` are 0 for most of this catalogue, so
         * "Sort by: average rating" is one enormous tied block; `total_sales`
         * ties across the whole tail; `created_at` ties for every product the
         * importer wrote in the same second, which is all of them.
         *
         * This method's caller pages the result with forPage(), and LIMIT /
         * OFFSET only partitions a list when the order is total. Page 1 and
         * page 2 are two separate requests running two separate queries, and
         * where the order does not decide between two rows, nothing obliges
         * the second query to break the tie the way the first one did. The
         * shopper sees the same product on both pages and never sees the one
         * it displaced.
         *
         * The tie-break runs in the same direction as the sort it follows, so
         * "most popular" and "cheapest" both keep reading the way they look.
         * It CANNOT reorder a list that was already ordered -- a final key
         * only ever decides between rows every earlier key called equal.
         */
        if (in_array($orderby, ['popularity', 'rating', 'date'], true)) {
            $query->orderByDesc('products.id');
        } else {
            $query->orderBy('products.id');
        }
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
        $query->orderByDesc('products.featured');

        if ($this->settings->moduleEnabled('product_sorting', false)) {
            $query->orderBy('products.position');
        }

        return $query->orderBy('products.name');
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

    /**
     * NO DELIVERY PROMISE IN A META DESCRIPTION.
     *
     * All three of these ended in a transit time attached to one country, and
     * this is the description on the category archive, the search results and
     * /shop — so it went to every visitor of those pages AND into the search
     * result Google shows for them.
     *
     * It was the hardest delivery promise left anywhere in this application,
     * for two reasons rather than one. It named a country, like the checkout,
     * the home page, the order confirmation and the product page before their
     * repairs. And the window it named is one the shop does not record and does
     * not agree with: `delivery_default_text`, the owner's own wording, is
     * "1–3 days fast delivery all over UAE". A shopper in Dubai who chose this
     * shop from a search result offering a one-day window has been told two
     * different things by one shop before they have clicked anything.
     *
     * A META DESCRIPTION CANNOT BE PER-VISITOR, which is why the machinery the
     * rest of this lane uses is deliberately NOT applied here. There is one
     * description per URL, a crawler is one of the readers, and varying it by a
     * guessed geo header would mean serving search engines something different
     * from shoppers — for a promise, which is the worst thing to do it with.
     *
     * So the clause is removed rather than localised, and nothing replaces it:
     * everything these still say is a count of real rows.
     *
     * THE ONE THIS LANE DID NOT TOUCH is `seo_default_description`, the
     * site-wide fallback, which also carries a delivery claim. That one is the
     * owner's to write — it is a real field on Store → SEO (SETTING_RULES:
     * 'seo_default_description' => ['text', 'Default description']) — and
     * rewriting a setting he may already have edited is not this lane's to do.
     */
    private function seoDescription(?Category $category, string $search, int $total): string
    {
        if ($category) {
            $count = $total ? "{$total} authentic Korean skincare picks" : 'authentic Korean skincare';

            return "Shop {$category->name} at K-Beauty Bliss — {$count}.";
        }

        if ($search !== '') {
            $result = $total === 1 ? 'result' : 'results';

            return "\"{$search}\" — {$total} {$result} at K-Beauty Bliss, authentic Korean skincare.";
        }

        return "Browse every K-Beauty Bliss product — {$total} authentic Korean skincare picks, from serums to beauty devices.";
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
