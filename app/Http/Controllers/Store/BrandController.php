<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\Url;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Brand directory and brand landing pages.
 *
 * Three brand URLs existed in the app and none of them resolved. The homepage
 * linked to /brands/ twice -- "All brands" in the brand strip and "Shop all
 * brands" in a section button -- and MenuDemo built /korean-skincare-brands/
 * for the mega menu's Brands node with /brand/{slug}/ for each leaf.
 *
 * An earlier pass picked /brands/ as the real page because the homepage was
 * already treating it as one. The owner has since settled the question the
 * other way round: /korean-skincare-brands/ is the live address. That is the
 * one served here; /brands/ and /brand/{slug}/ are 301s.
 *
 * URL Contract U-05 is untouched. A brand's filterable, sortable, paginated
 * *product listing* is still /shop/?filter_brands={slug} -- what Brand::url()
 * returns, and what the shop's filters actually run on. The per-brand page
 * added here is a landing page: the brand's name, logo and description, with a
 * preview of its catalogue and a link onward to that listing. It does not
 * reimplement the listing and Brand::url() has deliberately not been changed.
 */
class BrandController extends Controller
{
    /**
     * The same column list ShopController uses, because the same card
     * component renders both. Narrower would save a few bytes and break the
     * card the first time a skin reads a field this forgot.
     */
    private const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'position', 'type', 'total_sales',
    ];

    /** A landing page is a taster. The shop listing does the paging. */
    private const PREVIEW_LIMIT = 12;

    /**
     * How the directory draws a brand tile. Set in the admin under
     * Catalog → Brands; the key is `brands_display`.
     *
     *   auto  — logo when the brand has one, its initial otherwise, name
     *           underneath either way. What the directory has always done,
     *           and the default, so an existing site looks unchanged.
     *   logos — the logo alone. A brand with no logo still shows its name
     *           rather than an empty tile; on day one most brands have no
     *           logo, so that fallback is the common case, not the edge one.
     *   names — the name alone: no logo, no initial circle.
     */
    public const DISPLAY_MODES = ['auto', 'logos', 'names'];

    public const DISPLAY_DEFAULT = 'auto';

    /**
     * The `minmax()` floor the tile grid is built from, in px, chosen from how
     * many brands there are.
     *
     * The grid is `repeat(auto-fill, minmax(<this>, 1fr))`, so this number is
     * the only thing that decides the column count: the browser fits as many
     * tracks of at least this width as the row has room for. Two failure modes
     * it exists to avoid — a shop with four brands laying them out as four
     * postage stamps with a wide empty gutter, and a shop with ninety-three
     * laying them out as a single endless column.
     *
     * Deliberately CSS, not JavaScript: the count is known at render time and
     * the reflow between phone and desktop then costs nothing and needs no
     * script to run first.
     */
    public static function gridMinimum(int $count): int
    {
        return match (true) {
            $count <= 3 => 240,
            $count <= 6 => 210,
            $count <= 12 => 186,
            $count <= 30 => 166,
            default => 148,
        };
    }

    /**
     * The `brands` module's gate — Lane EH.
     *
     * Applied in the constructor so every action is covered, including the two
     * legacy 301s: a redirect that still answers when the module is off would
     * leave /brand/{slug}/ pointing at a page that 404s, which is worse than
     * either end being consistent. This is the shape AddressController already
     * uses, and for the same stated reason.
     *
     * ON by default in ModuleRegistry, unlike the plugin, and the accompanying
     * migration turns it on for stores that already have a row. Both are
     * necessary and the reasoning is seo_engine's, which this follows exactly:
     * the brand directory, the per-brand landing pages and the two redirects
     * have all been serving real pages, ungated, since 2.60.109. Shipping the
     * plugin's `false` with a real gate behind it would 404 three live,
     * indexed URL families the moment the package applied, with no visible
     * symptom anywhere in the admin.
     */
    public function __construct(private SettingsService $settings)
    {
        abort_unless($this->settings->moduleEnabled('brands', true), 404);
    }

    /** The configured mode, with anything unrecognised falling back to `auto`. */
    private function displayMode(): string
    {
        $mode = (string) $this->settings->get('brands_display', self::DISPLAY_DEFAULT);

        return in_array($mode, self::DISPLAY_MODES, true) ? $mode : self::DISPLAY_DEFAULT;
    }

    public function index(): View
    {
        // One grouped query for the counts rather than a count per brand.
        // Ninety-three brands would otherwise be ninety-three queries.
        $brands = Brand::query()
            ->select('brands.id', 'brands.name', 'brands.slug', 'brands.logo', 'brands.description')
            ->selectRaw('COUNT(products.id) as products_count')
            // The join condition goes through the shared predicate, so a brand's
            // product count on this index matches what its own page will
            // actually list. Without it a brand with three live products and
            // one scheduled for next month advertises four, and the customer
            // who clicks through counts three and finds the shop wrong about
            // its own catalogue. `is_visible` was not being checked here
            // either, which was the same defect for hidden products.
            ->leftJoin('products', function ($join) {
                $join->on('products.brand_id', '=', 'brands.id');

                \App\Support\ProductVisibility::raw($join);
            })
            ->groupBy('brands.id', 'brands.name', 'brands.slug', 'brands.logo', 'brands.description')
            ->orderBy('brands.name')
            ->get();

        return view('store.brands', [
            // The directory lists every brand; there is no one brand for a
            // banner to belong to. Passed explicitly so the shared view never
            // reads an undefined variable.
            'banner' => null,
            'brand' => null,
            'brands' => $brands,
            'products' => collect(),
            'display' => $this->displayMode(),
            'gridMin' => self::gridMinimum($brands->count()),
            // Empty brands are listed but muted rather than hidden: a brand
            // with nothing in stock today is still a brand the shop carries,
            // and silently dropping it makes the A-Z look wrong.
            'stocked' => $brands->where('products_count', '>', 0)->count(),
        ]);
    }

    /** One brand's landing page, at /korean-skincare-brands/{slug}/. */
    public function show(string $slug): View
    {
        $brand = Brand::query()->where('slug', $slug)->firstOrFail();

        $products = Product::query()
            ->visible()
            ->select(self::CARD_COLUMNS)
            /*
             * Both relations the card reads, eager-loaded.
             *
             * This page renders through <x-product-grid>, and that component --
             * unlike <x-product-card>, which the shop uses -- reads BOTH
             * `$p->brand?->name` AND `$p->categories->first()?->name`. Neither
             * was loaded here, so every tile fired two more queries and the
             * page cost grew with the brand's catalogue: measured at 12 queries
             * for a brand carrying three products and 30 for one carrying more
             * than the twelve this previews. With both loaded it is flat — the
             * same count whatever the brand carries — because the two lazy
             * loads per tile collapse into two batched ones for the page.
             * Pinned in tests/Feature/StorefrontQueryBudgetTest.php.
             */
            ->with(['brand:id,name,slug', 'categories:id,name'])
            ->where('brand_id', $brand->id)
            // `position` is 0 until the owner reorders anything and product
            // names are not unique, so `id` finishes an order this LIMIT
            // otherwise takes over a tie.
            ->orderBy('position')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        // The brand's own banner, when the owner has turned one on. Null for
        // every brand that has not, which is the default and is decided by the
        // column being NULL rather than by a stored flag.
        // The FALLBACK heading is the brand's own name and is therefore
        // catalogue: t(). The banner's own `heading` is a JSON sub-key of
        // `banner` and is not translatable yet — see the note in
        // docs/fn-translation-at-scale.md, which is a decision for the SEO lane.
        $banner = \App\Support\PageBanner::forModel($brand, $brand->t('name'));

        return view('store.brands', [
            'banner' => $banner,
            'seoCtx' => $this->seoCtx($brand, $banner, $products),
            'brand' => $brand,
            'brands' => collect(),
            'products' => $products,
            'stocked' => 0,
            // The landing page shows one brand's hero, which always wants the
            // logo and the name together; the directory's modes do not apply
            // to it. Passed anyway so the shared view never reads an undefined
            // variable.
            'display' => self::DISPLAY_DEFAULT,
            'gridMin' => self::gridMinimum(0),
        ]);
    }

    /**
     * What a brand page tells a share scraper about itself.
     *
     * IT SAID NOTHING, AND SO EVERY BRAND PAGE SAID THE SAME THING. show()
     * passed no $seoCtx at all, so layouts/store.blade.php applied its
     * defaults and all ninety-odd brand landing pages published one identical
     * Open Graph card: the store-wide default description ("Shop Korean
     * skincare in the UAE — serums, creams, moisturisers…", the same sentence
     * the homepage and the cart publish), the store-wide default share image,
     * and no breadcrumb. Pasting a brand page into WhatsApp produced a card
     * that did not name the brand anywhere except in the title. Verified by
     * fetching /korean-skincare-brands/round-lab/ against a running preview
     * before the change.
     *
     * Three things go in, and each is a value this page already has:
     *
     *  - THE DESCRIPTION is the brand's own `description` column when the
     *    owner has written one. There is no invented fallback sentence: a
     *    brand with an empty description keeps the store-wide default, which
     *    is true of it, rather than getting a generated claim about a
     *    catalogue this method has not counted. (It must not count one —
     *    show() fetches a capped preview, and the brand page is on
     *    StorefrontQueryBudgetTest's budget at 9 queries. This adds none.)
     *
     *  - THE IMAGE is the page's own hero: the banner photograph when the
     *    owner has turned a banner on, the brand logo otherwise. Both are
     *    stored the way `products.image` is — a URL or a root-relative path,
     *    rendered raw into <img src> by store/brands.blade.php — so both go
     *    through Seo::absolute() exactly as a product's image does. Null when
     *    the brand has neither, which correctly falls through to the store's
     *    default share image rather than publishing a broken og:image.
     *
     *  - THE CANONICAL is built from site_url + Url::to(...), the same shape
     *    ProductController uses with $product->url(). Url::to() is what honours
     *    KBB_BASE_PATH, and Seo::canonical() is what reconciles the two ends:
     *    Url::to() adds the /kbb-upgrade prefix and site_url already carries
     *    it, and canonical() collapses the one duplicate. Writing the path as a
     *    bare '/korean-skincare-brands/…' literal instead would publish a
     *    canonical that 404s on the live host — which is exactly the class of
     *    bug a test on the default base path cannot see.
     *
     *    Brand::url() is deliberately NOT used: U-05 keeps it pointing at the
     *    filterable shop listing (/shop/?filter_brands={slug}), and this page
     *    is the landing page, not that listing. Canonicalising one to the other
     *    would tell Google this page does not exist.
     *
     * SeoSettings::get() rather than Setting::map(), for the reason
     * CollectionController::seoCtx() carries in full: the latter memoises in a
     * process-level static and a page rendered before that map was first
     * filled produced a root-relative trail inside an absolute document.
     *
     * @param  array<string, mixed>|null  $banner
     * @return array<string, mixed>
     */
    private function seoCtx(Brand $brand, ?array $banner, \Illuminate\Support\Collection $products): array
    {
        $base = rtrim(\App\Services\Seo\SeoSettings::get('site_url', ''), '/');
        $url = $base . Url::to('/korean-skincare-brands/' . $brand->slug . '/');

        /*
         * PER-BRAND SEO OVERRIDES — `brands.seo`, which nothing read until now.
         *
         * The column has existed since 2026_10_05_add_category_seo_and_redirects,
         * whose own header says it is "deliberately identical to `products.seo`
         * so there is one shape in this app for the SEO overrides of a thing".
         * Store\ProductController::show() has read that shape on products for
         * months. Nothing read it here or on categories, so Catalog → Brands
         * collected a title and a description, saved them, reported success,
         * and published neither: an owner who filled the boxes in got the
         * store-wide default description on every brand page, exactly as if
         * they had left them empty. Reproduced against a running preview by
         * saving a brand SEO title and fetching the page.
         *
         * ProductSeo::normalise() ON THE READ, AND IT IS NOT DECORATION. The
         * admin screen for brands and categories writes `seo.description`,
         * while the published vocabulary — ProductSeo::PUBLISHED_KEYS, what
         * Store\ProductController reads — spells that key `desc`. Reading the
         * raw array would have found no `desc` and published nothing, which is
         * the SECOND of the two independent bugs ProductSeo's header records
         * against products ("the admin's panel collected Yoast-shaped names and
         * the storefront reads different ones"). ProductSeo::RENAME already
         * maps `description` to `desc`, so running the stored bag through the
         * product normaliser is what makes this the SAME mechanism rather than
         * a parallel one — and it is also what turns a hand-written or
         * imported `noindex` of "1" into a real bool, which `!empty()` below
         * relies on.
         */
        $override = \App\Support\ProductSeo::normalise($brand->seo) ?? [];

        // t(), so an Arabic brand page publishes an Arabic <meta description>.
        // `description` is one of TranslationStore::LONG_FIELDS — one brand,
        // one row, one query on the page that prints it.
        $description = trim((string) ($override['desc'] ?? $brand->t('description')));

        /*
         * THE SHARE IMAGE IS STILL THE PAGE'S OWN HERO unless the owner has
         * named one. Order: the explicit og_image override, then the banner
         * photograph this page draws, then the brand logo. Products resolve it
         * in exactly this order (`$override['og_image'] ?? $product->image`).
         *
         * Nothing new is published that the page does not show. `categories.image`
         * has no storefront consumer at all and is deliberately NOT reached for
         * anywhere in this class or in ShopController's equivalent — an og:image
         * for a picture that appears nowhere on the page is a share card that
         * misrepresents the page, which is the defect this whole lane is about.
         */
        $image = trim((string) ($override['og_image'] ?? ''));

        if ($image === '') {
            $image = is_string($banner['image'] ?? null) ? trim((string) $banner['image']) : '';
        }

        if ($image === '') {
            $image = trim((string) $brand->logo);
        }

        /*
         * A CANONICAL OVERRIDE REPLACES THE COMPUTED ONE, as it does on a
         * product. Url::absolute(), never Url::to(): the override is a path the
         * OWNER wrote, so it already carries whatever language and prefix they
         * meant, and Url::to() would strip the locale segment off it and apply
         * this reader's instead — the exact mistake Url::absolute()'s own
         * docblock records against hreflang alternates. absolute() also passes
         * a full http(s) URL through untouched, which is the form the product
         * editor's canonical box collects.
         */
        $canonical = trim((string) ($override['canonical'] ?? ''));

        if ($canonical !== '') {
            $url = Url::absolute($canonical);
        }

        /*
         * NOINDEX. Two halves, and the second is the one that is easy to miss.
         *
         * App\Support\Seo::render() turns this into `<meta name="robots"
         * content="noindex, nofollow">` — that is the page saying it. The
         * SITEMAP is a separate document that was still advertising the same
         * URL, and Google reports that pair as "Submitted URL marked noindex"
         * rather than quietly honouring it; Store\SeoFilesController already
         * skips noindexed PRODUCTS for that reason and now skips brands and
         * categories on the same test.
         *
         * The hreflang alternates are the third document that could contradict
         * it. They are emitted by layouts/store.blade.php from
         * Locale::alternatePaths(), which returns an empty array while Arabic
         * is off — so a noindexed brand publishes no alternate today. That is a
         * fact about the current configuration and not a guard, and it is
         * written up in this lane's report rather than patched from here: the
         * fix belongs in the layout, which this lane does not own.
         */
        $noindex = ! empty($override['noindex']);

        /*
         * WHAT THIS PAGE IS, AND WHICH PRODUCTS ARE ON IT.
         *
         * A brand landing page is a list of that brand's products and published
         * no type saying so. `collection` is the statement -- App\Support\Seo's
         * CollectionPage branch carries the shape, App\Support\CollectionSchema
         * carries why the price in it is a decimal string and not the fils
         * column.
         *
         * OFFSET ZERO, and that is not an assumption: show() draws ONE window
         * of at most PREVIEW_LIMIT products and this page has no pagination at
         * all. If it ever grows some, the offset has to grow with it, which is
         * why it is passed explicitly rather than defaulted.
         *
         * NOT WHEN THE OWNER HAS TYPED A CANONICAL. $canonical above replaces
         * the computed URL with a document this method did not render; these
         * rows are this page's and naming them as that page's contents would be
         * a claim about somebody else's page. Same rule, same reason, as
         * ShopController's.
         *
         * The list is the products this page DRAWS, capped at PREVIEW_LIMIT --
         * not the brand's whole catalogue. A list naming products the page does
         * not show is the listing-page equivalent of the invented reviews in
         * plan item 34: reachable, machine-readable, and not what the visitor
         * can see.
         */
        $collection = $canonical === ''
            ? \App\Support\CollectionSchema::from($products, $base, 0) + ['name' => $brand->name]
            : null;

        $ctx = array_filter([
            'type' => 'collection',
            'collection' => $collection,
            'description' => $description !== '' ? $description : null,
            'image' => $image !== '' ? $image : null,
            'url' => $url,
            'noindex' => $noindex ?: null,
            // The same keys the visible crumb uses, so the trail Google prints
            // and the trail a shopper reads say the same words. Their English
            // defaults are 'Home' and 'Brands', which is what these literals were.
            'breadcrumb' => [
                ['name' => __('store.breadcrumb.home'), 'url' => $base . Url::to('/')],
                ['name' => __('store.breadcrumb.brands'), 'url' => $base . Url::to('/korean-skincare-brands/')],
                ['name' => $brand->t('name'), 'url' => $url],
            ],
        ], static fn ($v) => $v !== null);

        /*
         * `title_is_final`, exactly as Store\ProductController::show() sets it:
         * a title the owner typed is the WHOLE title, with the site name not
         * appended. Set only when there is one, so an empty box still goes
         * through `seo_title_template` and reads " | K-Beauty Bliss" as every
         * other page does.
         */
        if (! empty($override['title'])) {
            $ctx['title'] = $override['title'];
            $ctx['title_is_final'] = true;
        }

        return $ctx;
    }

    /**
     * /brands/ -- the address the homepage publishes, and the one an earlier
     * pass had made the real page before the owner settled on the other.
     */
    public function legacyIndex(): RedirectResponse
    {
        // Url::redirect() rather than route(): Laravel strips the trailing
        // slash when it registers a URI, so route('brands.index') hands back
        // /korean-skincare-brands and the 301 would land on a URL that is not
        // the canonical one. U-01 keeps the slash, and Url::redirect() also
        // applies the staging base path exactly once.
        return redirect(Url::redirect('/korean-skincare-brands/'), 301);
    }

    /**
     * /brand/{slug}/ -- the mega menu's per-brand leaf.
     *
     * Now points at the brand's own landing page rather than straight at the
     * filtered shop listing: the landing page is the canonical brand URL, and
     * it is the thing that links on to the listing. An unknown slug 404s rather
     * than dumping the shopper on an unfiltered shop page that looks like it
     * worked.
     */
    public function legacyShow(string $slug): RedirectResponse
    {
        $brand = Brand::query()->where('slug', $slug)->firstOrFail();

        return redirect(Url::redirect('/korean-skincare-brands/' . $brand->slug . '/'), 301);
    }
}
