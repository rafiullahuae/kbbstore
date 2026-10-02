<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AlsoLikePicks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /admin-api/product-editor-also-like?q=…&exclude=…            (Lane PS)
 *
 * The search behind Catalog → Products → (edit) → You may also like.
 *
 * ▲ IT KEEPS THE PRODUCT EDITOR'S CAPABILITY, BY ITS ADDRESS. It is registered
 *   in routes/product-editor-admin.php, under the `product-editor-` prefix,
 *   so App\Support\AdminCapabilities' existing rows decide it exactly as they
 *   decide the editor's own load: a GET under `admin-api/product-editor-*` is
 *   `catalog.view`, inside the guarded admin-api group. No second capability
 *   to keep in step, and nothing outside that group can reach it — it lists
 *   the catalogue by name.
 *
 * Saving the picks is NOT here: they travel with the product's own save
 * (`product-editor-save/{id}`, `catalog.manage`), so a product and its picks
 * can never be half-saved.
 */
class AlsoLikeApiController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'products' => AlsoLikePicks::search(
                (string) $request->query('q', ''),
                (int) $request->query('exclude', 0),
            ),
        ]);
    }
}
