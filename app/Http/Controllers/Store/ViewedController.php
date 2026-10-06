<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Recently viewed", for a product page that was fetched ahead.   (Lane SP)
 *
 * A product page served to a speculative request (App\Support\InstantNav)
 * does not write the `kbb_viewed` cookie, because the shopper may never open
 * it. When she does, the page posts here -- once, from the page itself -- and
 * the cookie is written exactly as ProductController writes it for a page
 * opened the ordinary way. Nothing else: no table, no count.
 *
 * POST, CSRF-checked and throttled like every other storefront POST; a product
 * that is not visible is refused, so the cookie can only ever hold what the
 * product page itself would have put there.
 */
final class ViewedController extends Controller
{
    public function store(Request $request): Response
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0 || ! Product::query()->visible()->whereKey($id)->exists()) {
            return response('', 404);
        }

        ProductController::rememberViewed($request, $id);

        return response('', 204);
    }
}
