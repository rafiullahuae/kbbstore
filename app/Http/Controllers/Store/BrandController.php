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

    public function __construct(private SettingsService $settings) {}

    public function index(): View
    {
        // One grouped query for the counts rather than a count per brand.
        // Ninety-three brands would otherwise be ninety-three queries.
        $brands = Brand::query()
            ->select('brands.id', 'brands.name', 'brands.slug', 'brands.logo', 'brands.description')
            ->selectRaw('COUNT(products.id) as products_count')
            ->leftJoin('products', function ($join) {
                $join->on('products.brand_id', '=', 'brands.id')
                    ->whereNull('products.deleted_at')
                    ->where('products.status', '=', 'publish');
            })
            ->groupBy('brands.id', 'brands.name', 'brands.slug', 'brands.logo', 'brands.description')
            ->orderBy('brands.name')
            ->get();

        return view('store.brands', [
            'brand' => null,
            'brands' => $brands,
            'products' => collect(),
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
