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
     *  seo, meta_feed and images — several KB each, never used here. */
    private const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'position', 'type', 'total_sales',
    ];

    public function __construct(private SettingsService $settings) {}

    public function index(Request $request, ?string $categorySlug = null): View
    {
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
            ],
            'sub' => $sub,
            'crumb' => $crumb,
            'clearUrl' => $category ? $category->url() : Facets::clearUrl(),
            'chips' => $this->chips($active),
            // The sidebar is identical for every visitor and only changes with
            // the catalogue, so it is cached rather than recounted per view.
            'cats' => Cache::remember('kbb.shop.cats', 900, fn () => Category::query()
                ->select('id', 'name', 'slug')
                ->withCount(['products' => fn ($q) => $q->visible()])
                ->groupBy('categories.id', 'categories.name', 'categories.slug')
                ->having('products_count', '>', 0)
                ->orderByDesc('products_count')
                ->limit(30)
                ->get()),
            'brands' => Cache::remember('kbb.shop.brands', 900, fn () => Brand::query()
                ->select('id', 'name', 'slug')
                ->withCount(['products' => fn ($q) => $q->visible()])
                ->groupBy('brands.id', 'brands.name', 'brands.slug')
                ->having('products_count', '>', 0)
                ->orderBy('name')
                ->limit(40)
                ->get()),
        ]);
    }

    private function applyFacets($query, array $active, string $search): void
    {
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$search}%"));
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
            default => $query->orderByDesc('featured')->orderBy('position')->orderBy('name'),
        };
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

    /** Removable chips for whatever is currently applied. */
    private function chips(array $active): array
    {
        $chips = [];

        foreach ($active['cat'] as $slug) {
            $name = Category::where('slug', $slug)->value('name');
            if ($name) { $chips[] = ['key' => 'cat', 'value' => $slug, 'label' => $name]; }
        }

        foreach ($active['brand'] as $slug) {
            $name = Brand::where('slug', $slug)->value('name');
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
