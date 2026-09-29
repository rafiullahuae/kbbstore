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
        /*
         * ▲ AND THE THREE A SET'S PRICE CANNOT BE READ WITHOUT. (Lane SG)
         *
         * App\Support\SetPricing::COLUMNS carries the argument in full. In
         * short: SetPricing::mode() and ::basis() read these off getAttributes()
         * and fall back to "no rule, no anchor" for an absent column, so a
         * narrow select does not fail -- it prices the set at the number in
         * `products.price`, which is a stale snapshot for a rule-priced set and
         * the pre-reduction figure for an anchored one. This grid showed one
         * price and the set's own page showed another.
         */
        ...\App\Support\SetPricing::COLUMNS,
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

        /*
         * ONE STATEMENT FOR EVERY SET ON THIS PAGE, OR NONE AT ALL. (Lane SG)
         *
         * The three columns on the list above let a set be priced by its RULE;
         * this is what makes reading that rule affordable. SetPricing::prime()
         * looks first and touches the database only when one of these rows is
         * actually a set -- so a shop with none pays nothing and
         * StorefrontQueryBudgetTest's ceilings do not move. With sets present it
         * is ONE grouped aggregate for all of them, flat in their number, in
         * place of the one-per-set tally() the card would otherwise run lazily.
         * prime()'s docblock carries the two alternatives and why not.
         */
        \App\Support\SetPricing::prime($products);

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
