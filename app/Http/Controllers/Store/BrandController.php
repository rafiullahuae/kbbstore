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

    public function __construct(private SettingsService $settings) {}

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
            ->orderBy('position')
            ->orderBy('name')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        return view('store.brands', [
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
