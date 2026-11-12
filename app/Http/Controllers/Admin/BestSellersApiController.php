<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\RecentBestSellers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalog -> Best Sellers (2.60.661): the /best-sellers/ list as the shop
 * shows it, to reorder by drag and remove with the cross -- the New Arrivals
 * Display grid, over RecentBestSellers. Capability `catalog.best_sellers`
 * (owner, manager), fail-closed through AdminCapabilities.
 */
final class BestSellersApiController extends Controller
{
    /** The grid never holds more than the ranking (150); room for drafts. */
    private const MAX_LIST = 1000;

    public function grid(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['present', 'array', 'max:'.self::MAX_LIST],
            'order.*' => ['integer', 'min:1'],
            'excluded' => ['present', 'array', 'max:'.self::MAX_LIST],
            'excluded.*' => ['integer', 'min:1'],
        ]);

        RecentBestSellers::save($data['order'], $data['excluded']);

        return response()->json($this->state());
    }

    public function reset(): JsonResponse
    {
        RecentBestSellers::reset();

        return response()->json($this->state());
    }

    private function state(): array
    {
        return [
            'ok' => true,
            'items' => RecentBestSellers::grid(),
            'days' => RecentBestSellers::DAYS,
            'max' => RecentBestSellers::MAX,
            'url' => \App\Support\Url::to('/best-sellers/'),
        ];
    }
}
