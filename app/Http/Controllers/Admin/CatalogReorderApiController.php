<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\ScopeOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store → Catalog → Reorder.
 *
 * EVERY CATEGORY AND EVERY BRAND HAS ITS OWN ORDER. (Lane SO)
 *
 * This wrote one global `products.position`, the way WordPress's menu_order
 * did, so reordering Super Sale renumbered the Medicube products it shares with
 * the Medicube brand and the brand page moved. The owner: "NO ANY CATEGORY OR
 * BRAND should disturb the sorting of each other in any case." Each scope now
 * reads and writes only its own numbers -- `category_product.category_position`
 * for a category, `products.brand_position` for a brand -- and App\Support\
 * ScopeOrder says why there. `products.position` is no longer written here;
 * /shop/ and search keep reading it as they did.
 *
 * Two scope types share this one controller rather than duplicating it —
 * Category::products() is a many-to-many (a product can sit in several
 * categories), Brand::products() is a direct hasMany (one brand per
 * product) — different relationship shapes underneath, but "the set of
 * this scope's visible products, in its order" is the same question either
 * way, so scopeQuery() and ordered() are the one place that knows the
 * difference.
 *
 * THE ORDER THIS SCREEN SHOWS IS THE ORDER THE PAGE SHOWS. Both sort on the
 * scope's number, never-ordered products last, then the page's own tie-break:
 * featured, name, id for a category (ShopController::applyDefaultSort), name,
 * id for a brand (BrandController::show). And every save renumbers the whole
 * scope 0..n-1 in that order, writing only the rows whose number changed, so
 * products on pages nobody touched keep their places and no tie is left for a
 * page to break differently from this screen.
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

    /**
     * The base query for "this scope's visible products", each carrying its
     * number in THIS scope as `scope_pos` -- the one place the two
     * relationship shapes are reconciled. An unknown id is a 404.
     */
    private function scopeQuery(string $type, int $id)
    {
        if ($type === 'brand') {
            abort_unless(Brand::query()->whereKey($id)->exists(), 404);

            return Product::query()->visible()
                ->where('products.brand_id', $id)
                ->addSelect('products.'.ScopeOrder::BRAND_COLUMN.' as scope_pos');
        }

        abort_unless(Category::query()->whereKey($id)->exists(), 404);

        return ScopeOrder::inCategory(Product::query()->visible(), $id)
            ->addSelect(ScopeOrder::ALIAS.'.kso_pos as scope_pos');
    }

    /** The scope's order, exactly as its storefront page sorts it. */
    private function ordered(string $type, $query)
    {
        if ($type === 'brand') {
            return ScopeOrder::orderInBrand($query)
                ->orderBy('products.name')
                ->orderBy('products.id');
        }

        return ScopeOrder::orderInCategory($query)
            ->orderByDesc('products.featured')
            ->orderBy('products.name')
            ->orderBy('products.id');
    }

    /**
     * The scope's ids in order, with each one's current number.
     *
     * @return array<int, int|null>  product id => scope_pos, in order
     */
    private function currentOrder(string $type, int $id): array
    {
        $out = [];

        foreach ($this->ordered($type, $this->scopeQuery($type, $id)->addSelect('products.id'))->toBase()->get() as $row) {
            $out[(int) $row->id] = $row->scope_pos === null ? null : (int) $row->scope_pos;
        }

        return $out;
    }

    /**
     * Write $ids as the scope's order 0..n-1, touching only rows whose number
     * changes, and only rows of THIS scope: a category's pivot rows, or the
     * brand's products. No other scope's number can be reached from here.
     *
     * @param  list<int>  $ids
     * @param  array<int, int|null>  $current
     */
    private function writeOrder(string $type, int $id, array $ids, array $current): int
    {
        $written = 0;

        DB::transaction(function () use ($type, $id, $ids, $current, &$written) {
            foreach (array_values($ids) as $index => $productId) {
                if (array_key_exists($productId, $current) && $current[$productId] === $index) {
                    continue;
                }

                $written += $type === 'brand'
                    ? DB::table('products')->where('id', $productId)->where('brand_id', $id)
                        ->update(['brand_position' => $index])   // ScopeOrder::BRAND_COLUMN; a literal so the raw-write guard can read it
                    : DB::table('category_product')->where('category_id', $id)->where('product_id', $productId)
                        ->update(['category_position' => $index]);   // ScopeOrder::CATEGORY_COLUMN
            }
        });

        return $written;
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

        $products = $this->ordered($type, $query
            ->addSelect('products.id', 'products.name', 'products.brand_id', 'products.sku',
                'products.price', 'products.sale_price')
            ->with('brand:id,name'))
            // ordered() ends in `id`: names are not unique, so this paged
            // screen needs a partition of the list rather than a sample of it.
            ->forPage($page, $perPage)
            ->get();

        $orders = $this->ordersCountFor($products->pluck('id')->all());

        $products = $products
            ->map(fn ($p, $i) => [
                'id' => $p->id,
                'name' => $p->name,
                'brand' => $p->brand?->name,
                'sku' => $p->sku,
                'price' => \App\Support\Money::toAed($p->price),
                'sale_price' => $p->sale_price ? \App\Support\Money::toAed($p->sale_price) : null,
                'orders_count' => $orders[$p->id] ?? 0,
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
     * How many genuine orders each product on THIS PAGE has appeared in: one
     * grouped statement over the page's ids, never one per product.
     *
     * It was a correlated subquery in the select list, which MySQL evaluates
     * for every product in the scope before it sorts and cuts the page, each
     * one a search of `order_items` for that product. Where `order_items` has
     * no index on product_id -- a table the 2026_09_15 repair rebuilt column by
     * column has none -- every one of those searches reads the whole table.
     * Measured on MySQL 8.0 at 60,000 orders and 270,000 lines, a 205-product
     * category: 11.9 s at 50 a page and 12.3 s at 500, the same at any page
     * size because the cost was the scope, not the page. That is the "stuck on
     * Loading" this screen showed whenever the page size changed. This form is
     * one pass over the lines whatever the scope, and an index lookup per id
     * where the index exists.
     *
     * The definition is unchanged: COUNT(DISTINCT order_id) over lines whose
     * order is in Order::REAL_STATUSES and not trashed -- what the whereHas()
     * it replaces asked, with the soft-delete scope written out because a
     * DB::table() join does not know it.
     *
     * @param  list<int>  $ids
     * @return array<int, int>  product id => orders it appeared in
     */
    private function ordersCountFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // JOIN_ORDER: see CatalogProductsApiController::attachSalesTotals().
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', \App\Models\Order::REAL_STATUSES)
            ->whereNull('orders.deleted_at')
            ->whereIn('order_items.product_id', $ids)
            ->groupBy('order_items.product_id')
            ->selectRaw('/*+ JOIN_ORDER(order_items, orders) */ order_items.product_id as product_id,'
                .' COUNT(DISTINCT order_items.order_id) as orders_count')
            ->pluck('orders_count', 'product_id')
            ->map(fn ($n) => (int) $n)
            ->all();
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

        $current = $this->currentOrder($type, $id);
        $allIds = array_keys($current);

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

        // The page's new order spliced into the whole scope's; written as one
        // 0..n-1 run, so only what moved is touched. (Lane SO)
        array_splice($allIds, $offset, count($expectedSlice), array_map('intval', array_values($data['product_ids'])));
        $this->writeOrder($type, $id, $allIds, $current);

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

        $current = $this->currentOrder($type, $id);
        $ids = array_keys($current);

        $from = array_search($data['product_id'], $ids, true);

        if ($from === false) {
            return response()->json(['ok' => false, 'message' => 'That product is not in this scope.'], 422);
        }

        $to = min($data['to'], count($ids) - 1);

        [$moved] = array_splice($ids, $from, 1);
        array_splice($ids, $to, 0, [$moved]);

        $this->writeOrder($type, $id, $ids, $current);

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

        /*
         * `products.id` last on every arm, because this WRITES the order it
         * reads. The ids below are enumerated straight into `position`, so a
         * tie the database broke arbitrarily is not a momentary display
         * accident here — it is persisted as the shop's curated order and
         * then read back by ShopController's default sort. Two owners
         * pressing the same button on the same catalogue got different
         * answers, and neither could tell why.
         *
         * `created_at` ties for the whole imported catalogue, `total_sales`
         * across its tail and the coalesced price wherever two products cost
         * the same, so this is the common case rather than the edge.
         */
        match ($data['by']) {
            'name' => $query->orderBy('products.name')->orderBy('products.id'),
            'price' => $query->orderByRaw('COALESCE(products.sale_price, products.price) asc')
                ->orderBy('products.id'),
            'newest' => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
            'bestselling' => $query->orderByDesc('products.total_sales')->orderByDesc('products.id'),
        };

        $current = [];
        foreach ($query->addSelect('products.id')->toBase()->get() as $row) {
            $current[(int) $row->id] = $row->scope_pos === null ? null : (int) $row->scope_pos;
        }

        $this->writeOrder($type, $id, array_keys($current), $current);

        return response()->json(['ok' => true, 'sorted' => count($current)]);
    }
}
