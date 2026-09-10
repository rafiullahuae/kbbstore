<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The wishlist.
 *
 * Held in a cookie rather than the database, so it works for guests without an
 * account and survives a session. The header badge already reads the same
 * cookie, which is why the count was showing before this page existed.
 */
class WishlistController extends Controller
{
    private const COOKIE = 'kbb_wishlist';
    private const MAX = 100;

    private const CARD_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image',
        'rating', 'review_count', 'featured', 'position', 'type', 'total_sales',
    ];

    public function __construct(private SettingsService $settings) {}

    public function index(Request $request)
    {
        $ids = $this->idList($request);

        // Order by the cookie, so the most recently added appears first.
        $products = $ids === []
            ? collect()
            : Product::query()->select(self::CARD_COLUMNS)->visible()
                ->with('brand:id,name,slug')
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn ($p) => array_search($p->id, $ids, true))
                ->values();

        return view('store.wishlist', [
            'products' => $products,
            'settings' => $this->settings,
        ]);
    }

    /**
     * The saved product IDs, for the heart buttons on any other page to mark
     * themselves filled on load. The cookie itself is httpOnly (Laravel's
     * default), so client-side JS cannot read it directly — the browser
     * still sends it automatically with this same-origin request, same as
     * any other cookie, which is what makes this endpoint work at all.
     */
    public function ids(Request $request): JsonResponse
    {
        return response()->json(['ids' => $this->idList($request)]);
    }

    /** Add or remove, returning the new state so the button can update. */
    public function toggle(Request $request): JsonResponse
    {
        $id = (int) $request->input('product_id');

        if ($id <= 0 || ! Product::query()->whereKey($id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Unknown product.'], 422);
        }

        $ids = $this->idList($request);
        $saved = in_array($id, $ids, true);

        $ids = $saved
            ? array_values(array_diff($ids, [$id]))
            : array_slice(array_merge([$id], $ids), 0, self::MAX);

        return response()
            ->json(['ok' => true, 'saved' => ! $saved, 'count' => count($ids)])
            // A year, so a wishlist is not lost between visits.
            ->cookie(self::COOKIE, implode(',', $ids), 60 * 24 * 365);
    }

    /** @return int[] */
    private function idList(Request $request): array
    {
        $raw = (string) $request->cookie(self::COOKIE, '');

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }
}
