<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ShopController;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Brands, for the merchandising screen — the two things Admin\BrandsApiController
 * does not provide.
 *
 * 1. AN HONEST PRODUCT COUNT.
 *
 *    BrandsApiController::index() counts with
 *
 *        leftJoin products ON products.brand_id = brands.id
 *                          AND products.deleted_at IS NULL
 *
 *    i.e. every product that is not soft-deleted, drafts and hidden rows
 *    included. The page that count labels is /shop/?filter_brands={slug}
 *    (Brand::url(), URL contract U-05), which ShopController renders from
 *    Product::visible() — status='publish' AND is_visible=1. A brand with two
 *    live products and six drafts is labelled "8" beside a page listing 2.
 *    Same bug as the category count, same cause, and it is pinned the same way
 *    in CategoryCountHonestyTest.
 *
 *    This endpoint returns `products_count` on the page's terms and
 *    `filed_count` for everything, so the owner can still see the drafts
 *    without the headline number lying.
 *
 * 2. REORDER.
 *
 *    `brands.position` has existed since the original schema and nothing in
 *    this admin has ever written it. There is no brand reorder endpoint at
 *    all — BrandsApiController registers index/store/update/destroy and stops
 *    — so the column sits at its 0 default for every row and the brand
 *    directory is ordered by name whatever the owner wants.
 *
 * WHY A SEPARATE CONTROLLER rather than adding methods to BrandsApiController:
 * that file and routes/brands-admin.php belong to another lane. Changing its
 * index() response shape under it would break the Brands tab that reads it.
 * This is additive and its own route file.
 *
 * NOTE ON THE COUNT QUERY. The aggregate is a correlated subquery, not the
 * leftJoin+groupBy BrandsApiController uses, so that `position` and `name` can
 * be ordered on without appearing in a GROUP BY. A bare column beside an
 * aggregate is MySQL 1140 under ONLY_FULL_GROUP_BY, which is the failure
 * CLAUDE.md records as having shipped to production twice.
 */
class BrandsTreeApiController extends Controller
{
    /** GET /admin-api/brands-tree */
    public function index(): JsonResponse
    {
        $brands = Brand::query()
            ->select('brands.id', 'brands.slug', 'brands.name', 'brands.logo',
                'brands.description', 'brands.position')
            ->selectSub(
                DB::table('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.brand_id', 'brands.id')
                    ->whereNull('products.deleted_at')
                    ->where('products.status', 'publish')
                    ->where('products.is_visible', true),
                'products_count'
            )
            ->selectSub(
                DB::table('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.brand_id', 'brands.id')
                    ->whereNull('products.deleted_at'),
                'filed_count'
            )
            ->orderBy('brands.position')
            ->orderBy('brands.name')
            ->get();

        return response()->json(['ok' => true, 'brands' => $brands]);
    }

    /**
     * POST /admin-api/brands-tree/reorder
     *
     * Same contract as the category reorder it sits beside: ids in their new
     * order, only ids that exist are written, a duplicate id is written once.
     * A stale page cannot renumber rows it was not showing.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1', 'max:500'],
            'order.*' => ['required', 'integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['order'])));

        $known = array_flip(array_map(
            'intval',
            Brand::query()->whereIn('id', $ids)->pluck('id')->all()
        ));

        DB::transaction(function () use ($ids, $known) {
            $position = 0;

            foreach ($ids as $id) {
                if (! isset($known[$id])) {
                    continue;
                }

                DB::table('brands')->where('id', $id)->update(['position' => $position++]);
            }
        });

        // The brand list in the shop sidebar is cached for 900 seconds under
        // kbb.shop.brands. Without this the owner reorders, reloads the shop
        // and sees no change for a quarter of an hour.
        ShopController::flushSidebarCache();
        Cache::forget('kbb.home.cats');

        return response()->json(['ok' => true, 'ordered' => count($ids)]);
    }
}
