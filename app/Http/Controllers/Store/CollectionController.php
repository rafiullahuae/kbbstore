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

        $products = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('store.collection', [
            'key' => $key,
            'title' => $title,
            'intro' => $intro,
            'products' => $products,
            'settings' => $this->settings,
        ]);
    }
}
