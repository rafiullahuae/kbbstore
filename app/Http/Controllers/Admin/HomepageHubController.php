<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Services\GridSections;
use App\Services\HomepageHub;
use App\Support\ProductSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Homepage content → All sections (Lane HC). Three READS.
 *
 * Nothing here writes. Every save the editor makes goes to the endpoint that
 * already owns the value (see App\Services\HomepageHub), so this controller
 * cannot become a second writer of anything.
 *
 *   GET  /admin-api/homepage-hub            homepagehub.view
 *   GET  /admin-api/homepage-hub/products   homepagehub.search   the typeahead
 *   POST /admin-api/homepage-hub/preview    homepagehub.search   what a source draws
 *
 * Both catalogue reads return HomepageHub::card() — id, name, brand, image,
 * price — and never the model: `products` carries wc_id, sku and total_sales.
 */
final class HomepageHubController extends Controller
{
    /** The most rows the typeahead returns. */
    public const SEARCH_CAP = 20;

    public function show(HomepageHub $hub): JsonResponse
    {
        return response()->json($hub->payload());
    }

    /**
     * The manual picker's typeahead: name (contains), SKU (exact — the column
     * is matched but never returned) or brand name (contains). Two characters
     * at least, twenty rows at most, visible products only — the ones a rail
     * can draw.
     */
    public function products(Request $request): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));

        if (mb_strlen($q) < 2) {
            return response()->json(['ok' => true, 'products' => []]);
        }

        $like = '%'.addcslashes($q, '%_\\').'%';

        $rows = Product::query()
            ->select(['id', 'name', 'brand_id', 'image', 'price', 'sale_price'])
            ->visible()
            ->with('brand:id,name')
            ->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhere('sku', $q)
                ->orWhereIn('brand_id', Brand::query()->select('id')->where('name', 'like', $like)))
            ->orderByDesc('total_sales')->orderByDesc('id')
            ->limit(self::SEARCH_CAP)
            ->get();

        return response()->json([
            'ok' => true,
            'products' => $rows->map(fn (Product $p) => HomepageHub::card($p))->values()->all(),
        ]);
    }

    /**
     * What a selection the owner has NOT saved would draw — the editor's live
     * preview of the Products tab. The same GridSections::pool() the shop
     * reads, so the picture cannot drift from the page. Writes nothing.
     */
    public function preview(Request $request, GridSections $grids): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:20'],
            'brand' => ['sometimes', 'integer', 'min:0'],
            'cat' => ['sometimes', 'integer', 'min:0'],
            'picks' => ['sometimes', 'array', 'max:'.ProductSource::CAP_PICKS],
            'picks.*' => ['integer'],
            'query' => ['sometimes', 'array'],
            'children' => ['sometimes', 'boolean'],
            'max' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'limit' => ['required', 'integer', 'min:1', 'max:48'],
        ]);

        // "As shipped" previews as the query the shop runs for it is not a
        // pool; show best sellers there, which the editor labels as such.
        $source = $data['source'] === 'auto' ? 'bestsellers' : $data['source'];

        $rows = $grids->pool(
            $source,
            (int) ($data['brand'] ?? 0),
            (int) ($data['cat'] ?? 0),
            ProductSource::ids($data['picks'] ?? [], ProductSource::CAP_PICKS),
            (int) $data['limit'],
            ! empty($data['max']) ? (int) $data['max'] * 100 : null,
            (bool) ($data['children'] ?? false),
            ProductSource::clean($data['query'] ?? []),
        );

        return response()->json([
            'ok' => true,
            'products' => $rows->map(fn (Product $p) => HomepageHub::card($p))->values()->all(),
        ]);
    }
}
