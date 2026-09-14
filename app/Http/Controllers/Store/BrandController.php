<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Brand index.
 *
 * Three brand URLs existed in the app and none of them resolved. The homepage
 * linked to /brands/ twice -- "All brands" in the brand strip and "Shop all
 * brands" in a section button -- and MenuDemo built /korean-skincare-brands/
 * for the mega menu's Brands node with /brand/{slug}/ for each leaf. No route
 * matched any of them, and the fallback simply aborts 404, so every one of
 * those links was dead.
 *
 * What did work was Brand::url(), which returns /shop/?filter_brands={slug} --
 * the filtered listing. So individual brands were reachable, just never by the
 * URLs the site itself was publishing.
 *
 * This makes /brands/ the real page, since the homepage was already treating
 * it as one, and points the other two at it with 301s rather than serving the
 * same catalogue under three addresses. Each brand tile links to the filtered
 * listing, which keeps one canonical URL per brand instead of introducing a
 * second archive with the same products on it.
 */
class BrandController extends Controller
{
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
            'brands' => $brands,
            // Empty brands are listed but muted rather than hidden: a brand
            // with nothing in stock today is still a brand the shop carries,
            // and silently dropping it makes the A-Z look wrong.
            'stocked' => $brands->where('products_count', '>', 0)->count(),
        ]);
    }

    /** The WordPress-era index URL. */
    public function legacyIndex(): RedirectResponse
    {
        return redirect()->route('brands.index', [], 301);
    }

    /**
     * The WordPress-era per-brand URL. Redirects to the filtered listing that
     * Brand::url() already produces, so there is exactly one page per brand.
     * An unknown slug 404s rather than dumping the shopper on an unfiltered
     * shop page that looks like it worked.
     */
    public function legacyShow(string $slug): RedirectResponse
    {
        $brand = Brand::where('slug', $slug)->firstOrFail();

        return redirect($brand->url(), 301);
    }
}
