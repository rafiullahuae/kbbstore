<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Catalog → Products.
 *
 * Was a complete mockup: a hardcoded 15-product array, and several columns
 * — SEO score, readability score, "synced to Meta catalog," internal link
 * counts, page views — with no real concept behind them at all, just a
 * rotating pattern keyed off array position. Those are left out here
 * entirely rather than faked against real data, since each one is
 * genuinely its own separate feature (an SEO scoring engine, a Meta
 * catalogue sync integration, page-view analytics) that doesn't exist yet.
 * Showing a plausible-looking number for something uncomputed would be
 * worse than not showing the column.
 *
 * The columns here are the ones with a real, existing concept behind
 * them: stock, price, categories, featured, date, brand, status, and
 * order count — the last built the same way as Catalog → Reorder's, off
 * Order::REAL_STATUSES, not reused count fields that turned out to mean
 * something else on inspection.
 */
class CatalogProductsApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MIN = 10;
    private const PER_PAGE_MAX = 500;

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $filter = $request->query('filter', 'all');
        $sort = $request->query('sort', 'newest');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = $this->clampPerPage((int) $request->query('per_page', self::PER_PAGE_DEFAULT));

        $query = Product::query()->whereNull('deleted_at');

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like)));
        }

        match ($filter) {
            'published' => $query->where('status', 'publish'),
            'draft' => $query->where('status', 'draft'),
            // Low stock mirrors the same threshold this screen's own
            // preview used — a real number, just not yet an admin-
            // configurable one.
            'low' => $query->where('manage_stock', true)->where('stock', '>', 0)->where('stock', '<=', 15),
            'out' => $query->where(fn ($q) => $q->where('stock_status', 'outofstock')
                ->orWhere(fn ($s) => $s->where('manage_stock', true)->where('stock', '<=', 0))),
            default => null,
        };

        match ($sort) {
            'name' => $query->orderBy('name'),
            'price_desc' => $query->orderByRaw('COALESCE(sale_price, price) desc'),
            'stock_asc' => $query->orderBy('stock'),
            default => $query->orderByDesc('created_at'),
        };

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $products = $query
            ->select('id', 'name', 'slug', 'sku', 'brand_id', 'price', 'sale_price', 'stock',
                'manage_stock', 'stock_status', 'featured', 'status', 'created_at', 'image')
            ->with(['brand:id,name', 'categories:id,name'])
            ->addSelect(['orders_count' => OrderItem::selectRaw('COUNT(DISTINCT order_id)')
                ->whereColumn('product_id', 'products.id')
                ->whereHas('order', fn ($o) => $o->whereIn('status', Order::REAL_STATUSES))])
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'brand' => $p->brand?->name,
                'image' => $p->image,
                'stock_status' => $p->stock_status,
                'stock' => $p->manage_stock ? $p->stock : null,
                'price' => Money::toAed($p->price),
                'sale_price' => $p->sale_price ? Money::toAed($p->sale_price) : null,
                'categories' => $p->categories->pluck('name')->values(),
                'featured' => (bool) $p->featured,
                'status' => $p->status,
                'date' => $p->created_at?->format('M j, Y'),
                'orders_count' => (int) $p->orders_count,
            ]);

        return response()->json([
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'counts' => [
                'all' => Product::whereNull('deleted_at')->count(),
                'published' => Product::whereNull('deleted_at')->where('status', 'publish')->count(),
                'draft' => Product::whereNull('deleted_at')->where('status', 'draft')->count(),
                'low' => Product::whereNull('deleted_at')->where('manage_stock', true)
                    ->where('stock', '>', 0)->where('stock', '<=', 15)->count(),
                'out' => Product::whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('stock_status', 'outofstock')
                        ->orWhere(fn ($s) => $s->where('manage_stock', true)->where('stock', '<=', 0)))
                    ->count(),
            ],
        ]);
    }

    public function toggleFeatured(Request $request, Product $product): JsonResponse
    {
        $product->update(['featured' => ! $product->featured]);

        return response()->json(['ok' => true, 'featured' => (bool) $product->featured]);
    }

    private function clampPerPage(int $requested): int
    {
        if ($requested <= 0) {
            return self::PER_PAGE_DEFAULT;
        }

        return max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $requested));
    }
}
