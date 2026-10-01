<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The next batch of a listing, for "Load more on scroll".        (Lane PI-B)
 *
 * NOT A NEW ENDPOINT. It is the listing's own URL — /shop/?paged=3,
 * /collections/skincare-sets/?paged=3, /super-sale?page=3 — with
 * `kbbbatch=1` added, answered by the same controller after the same query,
 * the same filters, the same sort and the same SetPricing::prime(). So a batch
 * cannot disagree with the page it continues, and it costs exactly the queries
 * that page costs (StorefrontQueryBudgetTest's numbers, not new ones).
 *
 * ── WHAT IT RETURNS, AND ONLY THAT ──────────────────────────────────────────
 *
 * The route is public, so the response is an allowlist of five keys built
 * here, never a model:
 *
 *   html  the cards, rendered by the same <x-product-card> the page draws —
 *         escaped by Blade exactly as the page is, and nothing a shopper
 *         could not already read on ?paged=N
 *   page  this batch's page number
 *   last  the last page number
 *   next  the next batch's ordinary URL (no kbbbatch), or null at the end
 *   url   this batch's ordinary URL, for history.replaceState()
 *
 * No product id, no sku, no wc_id, no stock count leaves through it that the
 * card itself does not print. ListingLoadTest pins the key set.
 */
final class ListingBatch
{
    public const PARAM = 'kbbbatch';

    public static function wanted(Request $request): bool
    {
        return $request->query(self::PARAM) === '1';
    }

    /**
     * @param  iterable<\App\Models\Product>  $products
     */
    public static function respond(iterable $products, ?string $catLabel, int $page, int $lastPage, ?string $next, string $self): JsonResponse
    {
        $html = view('partials.listing-batch', [
            'products' => $products,
            'catLabel' => $catLabel,
        ])->render();

        return response()->json([
            'html' => $html,
            'page' => $page,
            'last' => $lastPage,
            'next' => $next,
            'url' => $self,
        ])->header('X-Robots-Tag', 'noindex');
    }
}
