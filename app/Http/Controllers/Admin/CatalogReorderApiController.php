<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store → Catalog → Reorder.
 *
 * WordPress kept this in wp_rwpp_product_order and WooCommerce reads it as
 * menu_order — a single, global sort value per product, not one per
 * category or brand. The `position` column mirrors that on purpose (see
 * the comment on it in the schema migration): reordering a product while
 * looking at one category or brand also moves it everywhere else that
 * product appears, exactly like the real site.
 *
 * Two scope types share this one controller rather than duplicating it —
 * Category::products() is a many-to-many (a product can sit in several
 * categories), Brand::products() is a direct hasMany (one brand per
 * product) — different relationship shapes underneath, but "the set of
 * this scope's visible products, in position order" is the same question
 * either way, so scopeQuery() is the one place that knows the difference.
 *
 * Explicit save, not save-on-every-click: every other screen in this admin
 * has a real Save button, and reorder previously didn't — every drag,
 * arrow click, and typed rank hit the database immediately, with no
 * chance to review before it landed. Now, moving things within the loaded
 * page only changes an in-memory order client-side; save() is the one
 * place anything is actually written. Whole-scope actions (auto-sort)
 * stay immediate on purpose — they already have their own confirmation
 * step, and they are not a page-local edit to begin with, so a second
 * save on top would protect against nothing.
 */
class CatalogReorderApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MIN = 10;
    private const PER_PAGE_MAX = 500;

    /** Category tree or flat brand list, depending on ?type=. */
    public function scopes(Request $request): JsonResponse
    {
        $type = $request->query('type', 'category');

        if ($type === 'brand') {
            $brands = Brand::query()
                ->select('id', 'name')
                ->whereHas('products', fn ($q) => $q->visible())
                ->orderBy('name')
                ->get();

            return response()->json(['type' => 'brand', 'scopes' => $brands]);
        }

        $categories = Category::query()
            ->select('id', 'name', 'parent_id')
            ->whereNull('parent_id')
            ->where(fn ($q) => $q->whereHas('products', fn ($p) => $p->visible())
                ->orWhereHas('children.products', fn ($p) => $p->visible()))
            ->with(['children' => fn ($q) => $q->select('id', 'name', 'parent_id')
                ->whereHas('products', fn ($p) => $p->visible())
                ->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'children' => $c->children->map(fn ($k) => ['id' => $k->id, 'name' => $k->name])->values(),
            ]);

        return response()->json(['type' => 'category', 'scopes' => $categories]);
    }

    /** The base query for "this scope's visible products" — the one place the two relationship shapes are reconciled. */
    private function scopeQuery(string $type, int $id)
    {
        if ($type === 'brand') {
            $brand = Brand::findOrFail($id);

            return $brand->products()->visible();
        }

        $category = Category::findOrFail($id);

        return $category->products()->visible();
    }

    /**
     * A page of a scope's products, optionally filtered by a search term
     * across the whole scope. Includes each product's real price, sale
     * price, and how many genuine orders (processing/onhold/completed —
     * see Order::REAL_STATUSES) it has appeared in, so a sorting decision
     * can be made looking at real numbers, not just names.
     */
    public function products(Request $request, string $type, int $id): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = $this->clampPerPage((int) $request->query('per_page', self::PER_PAGE_DEFAULT));

        $query = $this->scopeQuery($type, $id);

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(fn ($q) => $q->where('products.name', 'like', $like)
                ->orWhere('products.sku', 'like', $like)
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like)));
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $products = $query
            ->select('products.id', 'products.name', 'products.brand_id', 'products.sku',
                'products.position', 'products.price', 'products.sale_price')
            ->with('brand:id,name')
            ->addSelect(['orders_count' => \App\Models\OrderItem::selectRaw('COUNT(DISTINCT order_id)')
                ->whereColumn('product_id', 'products.id')
                ->whereHas('order', fn ($o) => $o->whereIn('status', \App\Models\Order::REAL_STATUSES))])
            ->orderBy('products.position')
            ->orderBy('products.name')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($p, $i) => [
                'id' => $p->id,
                'name' => $p->name,
                'brand' => $p->brand?->name,
                'sku' => $p->sku,
                'price' => \App\Support\Money::toAed($p->price),
                'sale_price' => $p->sale_price ? \App\Support\Money::toAed($p->sale_price) : null,
                'orders_count' => (int) $p->orders_count,
                'rank' => ($page - 1) * $perPage + $i + 1,
            ]);

        return response()->json([
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Clamped to a sane range rather than trusted outright — a page size
     * of, say, 50,000 would defeat the entire point of paginating at all,
     * turning it back into the unusable flat list this was built to
     * replace. 500 is generous for genuinely wanting a big working set
     * while staying well short of that.
     */
    private function clampPerPage(int $requested): int
    {
        if ($requested <= 0) {
            return self::PER_PAGE_DEFAULT;
        }

        return max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $requested));
    }

    /**
     * Saves a new order for exactly the page that was loaded and edited —
     * the product ids sent must be exactly the ids currently occupying
     * that page's position range, just reordered among themselves.
     * Anything outside that range, on other pages, is left untouched.
     * Validated against the scope's actual current order rather than
     * trusted outright, so a stale or tampered payload can't silently
     * shuffle products it was never shown.
     */
    public function savePage(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'page' => ['required', 'integer', 'min:1'],
            'per_page' => ['required', 'integer', 'min:1'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $perPage = $this->clampPerPage($data['per_page']);

        $allIds = $this->scopeQuery($type, $id)
            ->orderBy('products.position')->orderBy('products.name')
            ->pluck('products.id')->values()->all();

        $offset = (int) (($data['page'] - 1) * $perPage);
        $expectedSlice = array_slice($allIds, $offset, $perPage);

        $sentSorted = $data['product_ids'];
        sort($sentSorted);
        $expectedSorted = $expectedSlice;
        sort($expectedSorted);

        if ($sentSorted !== $expectedSorted) {
            return response()->json([
                'ok' => false,
                'message' => 'This page changed since it was loaded — reload and try again.',
            ], 409);
        }

        DB::transaction(function () use ($offset, $data) {
            foreach ($data['product_ids'] as $i => $productId) {
                Product::where('id', $productId)->update(['position' => $offset + $i]);
            }
        });

        return response()->json(['ok' => true, 'updated' => count($data['product_ids'])]);
    }

    /**
     * Moves a single product to an absolute position across the whole
     * scope, beyond the loaded page's range — used only for the explicit
     * rank number when its target isn't on the currently loaded page,
     * since that case can't be represented as a local, in-page edit
     * without the product disappearing from view before it's saved.
     */
    public function moveAbsolute(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'to' => ['required', 'integer', 'min:0'],
        ]);

        $ids = $this->scopeQuery($type, $id)
            ->orderBy('products.position')->orderBy('products.name')
            ->pluck('products.id')->values()->all();

        $from = array_search($data['product_id'], $ids, true);

        if ($from === false) {
            return response()->json(['ok' => false, 'message' => 'That product is not in this scope.'], 422);
        }

        $to = min($data['to'], count($ids) - 1);

        [$moved] = array_splice($ids, $from, 1);
        array_splice($ids, $to, 0, [$moved]);

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $productId) {
                Product::where('id', $productId)->update(['position' => $index]);
            }
        });

        return response()->json(['ok' => true, 'from' => $from, 'to' => $to]);
    }

    /**
     * Re-derives the whole scope's order from an existing product field —
     * a starting point to fine-tune from rather than an arbitrary list.
     * Immediate, not staged — already gated by its own confirmation
     * dialog client-side, and a reset of the whole scope's order isn't a
     * page-local edit a Save button would meaningfully protect.
     */
    public function autoSort(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate(['by' => ['required', 'in:name,price,newest,bestselling']]);

        $query = $this->scopeQuery($type, $id);

        match ($data['by']) {
            'name' => $query->orderBy('products.name'),
            'price' => $query->orderByRaw('COALESCE(products.sale_price, products.price) asc'),
            'newest' => $query->orderByDesc('products.created_at'),
            'bestselling' => $query->orderByDesc('products.total_sales'),
        };

        $ids = $query->pluck('products.id')->values()->all();

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $productId) {
                Product::where('id', $productId)->update(['position' => $index]);
            }
        });

        return response()->json(['ok' => true, 'sorted' => count($ids)]);
    }
}
