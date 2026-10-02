<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ProductViews;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The beacon behind "Most viewed".                                  (Lane RB)
 *
 * POST /api/product-view {id} — sent by resources/js/kbb/fbt.js after the
 * product page has loaded, at most once per product per browser per day.
 * CSRF like every storefront POST, throttled in routes/buy-together.php, and a
 * product that is not visible is refused without a write, so the table cannot
 * be filled with ids nobody can open. It answers 204 and nothing else: there
 * is nothing about the count a shopper needs to read back.
 */
class ProductViewController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $id = (int) $data['id'];

        if (! Product::query()->visible()->whereKey($id)->exists()) {
            return response('', 404);
        }

        ProductViews::record($id);

        return response('', 204);
    }
}
