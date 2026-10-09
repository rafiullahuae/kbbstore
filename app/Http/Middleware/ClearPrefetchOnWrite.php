<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\InstantNav;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page fetched ahead must not show the shop as it was before a change.
 *                                                                   (Lane SP)
 *
 * With "Open pages instantly" on (App\Support\InstantNav), Chrome may hold a
 * product page it fetched while the shopper's pointer rested on its link. If
 * she then adds something to the bag and opens that page, she would be shown
 * the copy fetched BEFORE the add -- measured in Chromium 141: the bag on the
 * page read 1, the page opened from the prefetch read 0. Removing and
 * re-adding the speculation rules did not discard it.
 *
 * `Clear-Site-Data: "prefetchCache"` on the response to anything that changes
 * something (every non-GET: add to bag, quantity, wishlist, login, logout,
 * review, newsletter ...) does: the same run then read 1, from a fresh fetch.
 * It clears ONLY the prefetches -- no cookie, no storage, no HTTP cache -- and
 * a browser that does not know the value ignores the header.
 */
final class ClearPrefetchOnWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && ! $response->headers->has('Clear-Site-Data')
            // The "Most viewed" beacon changes nothing a page shows (its
            // ranking is cached ten minutes): it must not throw away a page
            // the shopper is about to open. Lane RB's routes/buy-together.php.
            && ! $request->routeIs('product.view-beacon')
            // The page-view beacon every shop page sends after load (Lane AN)
            // changes nothing a page shows either -- unless it carries `id`,
            // the "Recently viewed" write, which does (the row on the next
            // product page). Without this, every page view would throw away
            // whatever had been fetched ahead while the page was loading.
            && ! ($request->routeIs('product.viewed-later') && ! $request->filled('id'))) {
            try {
                if (InstantNav::on()) {
                    $response->headers->set('Clear-Site-Data', '"prefetchCache"');
                }
            } catch (\Throwable) {
                // A settings read that fails is a response without the header,
                // never a failed add-to-bag.
            }
        }

        return $response;
    }
}
