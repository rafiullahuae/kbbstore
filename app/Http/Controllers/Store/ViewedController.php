<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Analytics\Tracker;
use App\Support\ProductViews;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The shop's ONE beacon per opened page.                    (Lane SP, Lane AN)
 *
 * Every shop page sends one POST here after it has loaded (resources/js/kbb/
 * hit.js, in the bundle; never from a prefetch or a prerender, because those
 * run no script until the page is really shown). It carries up to three
 * things and answers 204:
 *
 *   p, t, r, us/um/uc, ck, l, n, ss, af/al   the page view for Analytics
 *        (App\Services\Analytics\Tracker: one INSERT, allowlisted and capped;
 *        bots, admins, prefetches and the owner's addresses are not counted)
 *   id   "Recently viewed" for a product page that was fetched ahead (Lane
 *        SP): the `kbb_viewed` cookie, written exactly as ProductController
 *        writes it for a page opened the ordinary way
 *   pv   one "Most viewed" count (App\Support\ProductViews), which used to
 *        be a second beacon of its own (/api/product-view, kept for pages
 *        cached before this shipped)
 *
 * One request, so a product page that used to send two (or three) sends one.
 * POST, CSRF-checked and throttled per address like every other storefront
 * POST; a product that is not visible is never written for either id. A
 * request with only an `id` that is not visible still answers 404, as before.
 */
final class ViewedController extends Controller
{
    public function store(Request $request): Response
    {
        $id = max(0, (int) $request->input('id', 0));
        $pv = max(0, (int) $request->input('pv', 0));
        $visible = ($id > 0 || $pv > 0)
            ? Product::query()->visible()->whereKey(array_values(array_unique(array_filter([$id, $pv]))))->pluck('id')->map(fn ($v): int => (int) $v)->all()
            : [];

        if ($id > 0 && in_array($id, $visible, true)) {
            ProductController::rememberViewed($request, $id);
        }

        if ($pv > 0 && in_array($pv, $visible, true)) {
            ProductViews::record($pv);
        }

        if ($request->has('p')) {
            Tracker::record($request);

            return response('', 204);
        }

        if ($id > 0 && ! in_array($id, $visible, true) || $id <= 0 && $pv <= 0) {
            return response('', 404);
        }

        return response('', 204);
    }
}
