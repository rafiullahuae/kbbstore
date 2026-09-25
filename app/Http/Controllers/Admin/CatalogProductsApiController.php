<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Support\MajorUnits;
use App\Support\Money;
use App\Support\RichText;
use App\Support\WholeDirhams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catalog → Products.
 *
 * WHAT WAS HERE BEFORE. A list, and only a list. It paginated and it searched
 * on name/SKU/brand, and that was the whole screen: five chips, four sorts, no
 * export, no bulk action, and an Edit button whose entire implementation was
 * `toast("Product editing isn't built yet")`. The one write in the file was
 * toggleFeatured. This is the screen a shop owner spends their day on, and the
 * two things they do on it all day — change a price and change a stock number —
 * were the two things it could not do.
 *
 * It is now built the way Store → Customers and Store → Orders are built, from
 * the same parts and with the same rules, because a third arrangement of the
 * same screen is a third place for the same bug to live.
 *
 * WHICH STATUSES EXIST. Read out of the codebase, not guessed.
 * `products.status` is declared in the schema as `publish | draft | private`,
 * and Services\Import\Entities\ProductImporter::STATUS_MAP maps every
 * WooCommerce post status onto exactly those three, rejecting anything else
 * rather than inventing a fourth. So KNOWN_STATUSES is those three — and the
 * chips are still built from a DISTINCT over the column on top of them, because
 * the dead importer this repo replaced defaulted the column to 'active' (a
 * value the schema has no concept of) and a row written by it would otherwise
 * be invisible on every chip including "All". The same is done for
 * `stock_status`, whose vocabulary is `instock | outofstock | onbackorder`.
 *
 * MONEY IS INTEGER FILS, AND IS PARSED AS TEXT. `products.price` and
 * `sale_price` are integer fils and nothing here divides them. Prices arriving
 * from the screen are parsed out of their decimal STRING by integer arithmetic
 * (filsFromMajor below) rather than multiplied as a float: `(int) (1.15 * 100)`
 * is 114, and a store that is a fil light on every hundredth product is a store
 * whose books do not add up. The percentage in a bulk price adjustment is
 * carried as integer basis points for the same reason — `1 - 30/100` is
 * 0.69999999999999995559, which is the float bug this repo has already paid
 * for once in bundle pricing.
 *
 * ONE QUERY, NOT ONE PER ROW. /shop once ran 390 queries for four products.
 * Everything this list shows per row arrives with the page: two grouped derived
 * tables (order lines, category assignments) and one plain join (brands) are
 * LEFT JOINed onto `products`, and the category NAMES come from a single
 * constrained eager load over the page. The count does not move when the page
 * size does, which is the actual N+1 property and is asserted as an equality
 * rather than as a threshold.
 *
 * AND ONLY ON THE STATEMENTS THAT READ THEM. "One query, not one per row" says
 * nothing about how many of the six statements this screen issues have to carry
 * a grouped derived table, and for a long time the answer was "all of them" —
 * including the counts and the summary strip, which name no column of either
 * and were paying to materialise both. See rowQuery(), withSalesTotals() and
 * withCategoryCounts(): 209.0 ms of SQL to 59.9 ms at 3,025 products and 27,000
 * order lines, with the response byte-for-byte identical. docs/page-cost.md.
 *
 * SUMMARIES GO THROUGH App\Support\AggregatesQueries. selectRaw() appends
 * rather than replaces, and applySort()/forPage() mutate the builder they are
 * given, so a summary built from the page's own builder inherits its ORDER BY
 * (MySQL 1140) and its OFFSET (every tile reads zero from page two, on every
 * engine). That shipped twice. There is one helper and this screen uses it.
 *
 * IMPORTED DATA IS THE NORMAL CASE. A product arriving from WooCommerce has a
 * wc_id, and may have no image, no category, no brand, no SKU, a null price and
 * a status nobody planned for. None of that may throw, and none of it may be
 * silently dropped from a count — which is why "no image" and "no category" are
 * chips rather than accidents.
 *
 * WHAT IS DELIBERATELY NOT RETURNED. The list returns an allowlist of columns.
 * `products` also carries `seo`, `seo_json`, `meta_feed` and `custom_tabs` —
 * admin blobs nothing on this screen reads — and `description`, which is a page
 * of HTML per row. Those reach the detail endpoint only, which is a deliberate
 * act to open. The rule behind Product::toApi() and
 * SettingController::PUBLIC_KEYS applies here too.
 */
class CatalogProductsApiController extends Controller
{
    use \App\Support\AggregatesQueries;

    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MIN = 10;
    private const PER_PAGE_MAX = 500;

    /** Hard ceiling on one CSV. A shared host is not a reporting server. */
    private const EXPORT_MAX = 50000;

    /** Rows per database round trip while streaming the CSV. */
    private const EXPORT_CHUNK = 500;

    /** Ceiling on one bulk action, so a stuck loop cannot rewrite the catalogue. */
    private const BULK_MAX = 200;

    /**
     * "Low stock" means this many units or fewer, with stock management on.
     *
     * The same number the previous version of this screen used. Still not an
     * admin-configurable setting; naming it once is the most that can honestly
     * be said about it.
     */
    private const LOW_STOCK = 15;

    /**
     * The statuses this schema declares, offered as chips even at a count of
     * zero. Anything else found in the column is added at query time — see
     * chipCounts().
     */
    private const KNOWN_STATUSES = ['publish', 'draft', 'private'];

    /** Likewise for stock_status. */
    private const KNOWN_STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];

    /**
     * Statuses a write may SET.
     *
     * Identical to KNOWN_STATUSES on purpose: an operator may publish,
     * unpublish or hide a product, and may not invent a fourth value. A status
     * outside this list can exist in the column (an import put it there) and
     * can be filtered on, but nothing in this controller will ever write one.
     */
    private const SETTABLE_STATUSES = ['publish', 'draft', 'private'];

    private const SETTABLE_STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];

    /**
     * Chips that are not a status value. Kept as a constant so the screen, the
     * counts and applyFilter() cannot drift apart.
     */
    private const DERIVED_FILTERS = [
        'low', 'hidden', 'no_image', 'on_sale', 'no_category', 'no_price', 'featured', 'trashed',
    ];

    /**
     * Money on the wire, in major units, as a decimal string.
     *
     * A regex rather than `numeric` because the value is parsed as TEXT
     * afterwards: `numeric` accepts "1e3" and " 1.5 ", and neither survives
     * digit-by-digit parsing.
     *
     * This was 'regex:/^\d{1,9}(\.\d{1,4})?$/' — four decimal places and no
     * ceiling — which left two live defects on the inline price cell and the
     * bulk price actions. "0.145" passed and stored as 14 fils, half a fil
     * dropped without a word. And "999999999" is 99,999,999,900 fils into a
     * signed 32-bit column: MySQL strict mode raises 1264 and answers 500,
     * SQLite stores it happily, and the two engines part company on what the
     * catalogue holds. The order-line editor had the same pair and was fixed
     * in the package before this one; this is the same fix on the other screen.
     *
     * MajorUnits::shape() allows at most as many decimals as the currency
     * actually has, so excess precision is refused out loud rather than
     * truncated. It stays deliberately wider than the column so that a number
     * whose only problem is being too large is told that, rather than being
     * called a bad format — the ceiling itself is enforced by
     * MajorUnits::exceedsColumn() in moneyOrFail() below.
     */
    private static function moneyRule(): array
    {
        return [MajorUnits::shape(), function (string $attribute, $value, $fail) {
            if ($value === null || $value === '') {
                return;
            }

            if (MajorUnits::exceedsColumn(MajorUnits::fils($value))) {
                $fail('That amount is larger than this store can hold. The most is '
                    . MajorUnits::maxMajor() . '.');
            }
        }];
    }

    /* ------------------------------------------------------------------ list */

    public function index(Request $request): JsonResponse
    {
        /*
         * A database error is reported, not swallowed. Store → Customers spent
         * a diagnostic release just learning the name of a live 500. Only the
         * driver's message is returned — never the SQL and never the bindings,
         * which carry the operator's search term.
         */
        try {
            return $this->listing($request);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'The product list could not be read from the database.',
                'db_error' => $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ], 500);
        }
    }

    private function listing(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = $this->clampPerPage((int) $request->query('per_page', self::PER_PAGE_DEFAULT));
        $filter = $this->filter($request);
        $sort = (string) $request->query('sort', 'newest');

        // Everything the operator typed EXCEPT the chip, so every chip count
        // describes the list the other filters have already narrowed to.
        $base = $this->baseQuery($request);

        $chips = $this->chipCounts($base, $request);

        $query = $this->applyFilter(clone $base, $filter);

        $total = (clone $query)->toBase()->getCountForPagination();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        // The sales aggregate is added HERE, to the clone that fetches rows,
        // and not to $query — which is still needed, unaggregated, by
        // summaryFor() below. applySort() and forPage() mutate what they get.
        $rows = $this->applySort($this->withSalesTotals($this->withCategoryCounts(clone $query)), $sort)
            ->forPage($page, $perPage)
            // ONE query for every category name on the page, not one per row.
            // Both columns exist on `categories`; a constrained eager load
            // naming one that does not is a string literal on SQLite and a
            // 1054 on the server (tests/Feature/EagerLoadColumnsTest.php).
            ->with('categories:id,name')
            ->get()
            ->map(fn ($p) => $this->rowToApi($p))
            ->values();

        return response()->json([
            'products' => $rows,
            'summary' => $this->summaryFor($query),
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'counts' => $chips['counts'],
            // Chip order, so the screen renders the same sequence every time
            // rather than whatever order the database felt like.
            'statuses' => $chips['statuses'],
            'stock_statuses' => $chips['stock_statuses'],
            'settable_statuses' => self::SETTABLE_STATUSES,
            'settable_stock_statuses' => self::SETTABLE_STOCK_STATUSES,
            'low_stock_threshold' => self::LOW_STOCK,
            'currency' => Money::currency(),
            'minor_exponent' => Money::minorExponent(),
        ]);
    }

    /**
     * Brands and categories, for the filter menus and the bulk category picker.
     *
     * Two small lists rather than a join on every row: the screen needs the
     * whole vocabulary to offer it, and the list rows only need the names they
     * actually carry.
     */
    public function facets(): JsonResponse
    {
        $brands = DB::table('brands')->orderBy('name')->get(['id', 'name'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])
            ->values();

        $categories = DB::table('categories')
            ->orderBy('path')
            ->orderBy('name')
            ->get(['id', 'name', 'path', 'depth'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'path' => $c->path === null ? null : (string) $c->path,
                'depth' => (int) $c->depth,
            ])
            ->values();

        return response()->json(['brands' => $brands, 'categories' => $categories]);
    }

    /* ---------------------------------------------------------------- detail */

    /**
     * One product, with everything the edit panel needs.
     *
     * This is the only endpoint in the file that returns the long description
     * and the SEO blob, because it is the only screen that edits them. The list
     * carries neither: 50 rows of product HTML is a response nobody reads.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::withTrashed()
            ->with(['categories:id,name', 'brand:id,name'])
            ->find($id);

        if ($product === null) {
            return response()->json(['message' => 'No such product.'], 404);
        }

        $stats = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', Order::REAL_STATUSES)
            ->whereNull('orders.deleted_at')
            ->where('order_items.product_id', '=', $product->id)
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders_count,'
                .' COALESCE(SUM(order_items.quantity), 0) as units_sold,'
                .' COALESCE(SUM(order_items.total), 0) as revenue_fils')
            ->first();

        $price = $product->price === null ? null : (int) $product->price;
        $sale = $product->sale_price === null ? null : (int) $product->sale_price;
        $effective = $product->effectivePrice();

        return response()->json(['product' => [
            'id' => (int) $product->id,
            // The import mapping, on the record. Null means this product was
            // created here rather than brought across.
            'wc_id' => $product->wc_id === null ? null : (int) $product->wc_id,
            'name' => (string) $product->name,
            'slug' => (string) $product->slug,
            'sku' => $this->blankToNull($product->sku),
            'type' => (string) $product->type,
            'status' => (string) $product->status,
            'is_visible' => (bool) $product->is_visible,
            'featured' => (bool) $product->featured,
            'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
            'brand' => $product->brand?->name,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'categories' => $product->categories
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
                ->values(),
            'price_fils' => $price,
            'price_input' => $price === null ? '' : $this->majorString($price),
            'price_display' => $price === null ? null : Money::plain($price),
            'sale_price_fils' => $sale,
            'sale_price_input' => $sale === null ? '' : $this->majorString($sale),
            'sale_price_display' => $sale === null ? null : Money::plain($sale),
            'effective_price_fils' => $effective,
            'effective_price_display' => Money::plain($effective),
            'on_sale' => $product->isOnSale(),
            'discount_percent' => $product->discountPercent(),
            'sale_starts_at' => $this->iso($product->sale_starts_at),
            'sale_ends_at' => $this->iso($product->sale_ends_at),
            'manage_stock' => (bool) $product->manage_stock,
            'stock' => $product->stock === null ? null : (int) $product->stock,
            'stock_status' => (string) $product->stock_status,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'image' => $this->blankToNull($product->image),
            'images' => is_array($product->images) ? array_values($product->images) : [],
            'rating' => (float) $product->rating,
            'review_count' => (int) $product->review_count,
            'total_sales' => (int) $product->total_sales,
            'position' => (int) $product->position,
            'orders_count' => (int) ($stats->orders_count ?? 0),
            'units_sold' => (int) ($stats->units_sold ?? 0),
            'revenue_fils' => (int) ($stats->revenue_fils ?? 0),
            'revenue_display' => Money::plain((int) ($stats->revenue_fils ?? 0)),
            /*
             * WHAT THAT FIGURE IS MADE OF — Lane DU.
             *
             * SUM(order_items.total): the line value of this product across
             * paid orders. It is NOT the same quantity as the revenue tile on
             * the dashboard, which sums `orders.total` and therefore carries
             * delivery, fees and VAT. Two numbers called "revenue" on one
             * console, summing different columns, is the defect this names
             * rather than hides. See AdminController::productRevenueBasis().
             */
            'revenue_basis' => \App\Http\Controllers\Admin\AdminController::productRevenueBasis(),
            'trashed' => $product->trashed(),
            'created_at' => $this->iso($product->created_at),
            'updated_at' => $this->iso($product->updated_at),
            'url' => $product->url(),
            'currency' => Money::currency(),
        ]]);
    }

    /* ----------------------------------------------------------- inline edit */

    /**
     * Save one product. Backs both the inline cells and the detail panel.
     *
     * Every field is validated here and nowhere else — the screen's own checks
     * are a courtesy to the operator, not a control. `sometimes` throughout, so
     * an inline edit that sends one key changes one column and a detail save
     * that sends fifteen changes fifteen, through the same path.
     *
     * NOT WRITABLE, at any price: wc_id, slug, total_sales, rating,
     * review_count. The first two are identity — `?add-to-cart={wc_id}` links
     * and /product/{slug}/ URLs are live in the wild, so changing either breaks
     * addresses that already exist — and the last three are computed from
     * orders and reviews. A field that can be typed over is a field whose value
     * means nothing.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::withTrashed()->find($id);

        if ($product === null) {
            return response()->json(['message' => 'No such product.'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::SETTABLE_STATUSES)],
            'stock_status' => ['sometimes', 'string', 'in:'.implode(',', self::SETTABLE_STOCK_STATUSES)],
            'is_visible' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'manage_stock' => ['sometimes', 'boolean'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            // Decimal strings in major units. Parsed by integer arithmetic.
            'price' => ['sometimes', 'nullable', ...self::moneyRule()],
            'sale_price' => ['sometimes', 'nullable', ...self::moneyRule()],
            'sale_starts_at' => ['sometimes', 'nullable', 'date'],
            'sale_ends_at' => ['sometimes', 'nullable', 'date'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'category_ids' => ['sometimes', 'array', 'max:50'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200000'],
        ]);

        $changes = [];

        foreach (['name', 'status', 'stock_status'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $data[$key];
            }
        }

        /*
         * The two HTML columns go through the sanitiser, exactly as
         * ProductEditorApiController::applyRichText() does it.
         *
         * `string|max:200000` above is a LENGTH check. It says nothing about
         * what the string contains, and partials/product-tabs.blade.php prints
         * both of these with {!! !!} on the public product page -- twice, once
         * for the desktop panel and once for the mobile accordion. So whatever
         * lands in the column is what a visitor's browser executes.
         *
         * App\Support\RichText was written to be the ONLY door into these
         * columns and this screen was a second one standing open beside it.
         * Being behind auth:admin is not the answer: admin_users.role is
         * validated and stored but enforced nowhere, so every back-office
         * account has this reach, and a sanitiser that runs on one of two
         * write paths is a sanitiser that does not run.
         *
         * Blank-after-cleaning becomes NULL rather than '', so a description
         * emptied here reads the same to tabs() as one that was never set --
         * the editor's rule, kept identical on purpose.
         */
        foreach (['short_description', 'description'] as $key) {
            if (array_key_exists($key, $data)) {
                $clean = RichText::clean($data[$key]);

                $changes[$key] = RichText::isBlank($clean) ? null : $clean;
            }
        }

        if (array_key_exists('sku', $data)) {
            $changes['sku'] = $this->blankToNull($data['sku']);
        }

        foreach (['is_visible', 'featured', 'manage_stock'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = (bool) $data[$key];
            }
        }

        if (array_key_exists('stock', $data)) {
            $changes['stock'] = $data['stock'] === null ? null : (int) $data['stock'];
        }

        foreach (['brand_id', 'category_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }

        foreach (['price' => 'Price', 'sale_price' => 'Sale price'] as $key => $label) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $fils = $this->filsFromMajor($data[$key]);

            /*
             * WHOLE DIRHAMS, REFUSED ON A CHANGE ONLY — the same rule the
             * product editor applies, for the same reasons, written out in
             * full in App\Support\WholeDirhams and in
             * ProductEditorApiController::apply().
             *
             * It has to be here as well as there because this is a SECOND
             * write path onto the same two columns: the inline price cell on
             * the catalogue list posts here, and a rule that lived on only one
             * of the two screens would be a rule the shop does not have.
             *
             * The comparison against the stored value is what lets the inline
             * cell keep working on the products this catalogue already holds
             * at 9,980 fils — clicking into the cell and pressing Enter
             * without changing the digits saves, rather than refusing an edit
             * nobody made.
             */
            $stored = $product->{$key} === null ? null : (int) $product->{$key};

            if ($fils !== null && $fils !== $stored && ! WholeDirhams::isWhole($fils)) {
                return response()->json([
                    'message' => WholeDirhams::message($label, $fils),
                    'errors' => [$key => ['Whole ' . WholeDirhams::plural() . ' only.']],
                ], 422);
            }

            $changes[$key] = $fils;
        }

        foreach (['sale_starts_at', 'sale_ends_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $data[$key] === null || $data[$key] === ''
                    ? null
                    : Carbon::parse((string) $data[$key]);
            }
        }

        /*
         * A sale price at or above the regular price is not a sale, and
         * Product::isOnSale() would answer false while the screen showed a
         * struck-through price. Refused rather than quietly accepted, with the
         * comparison done on the integers.
         */
        $nextPrice = array_key_exists('price', $changes) ? $changes['price'] : ($product->price === null ? null : (int) $product->price);
        $nextSale = array_key_exists('sale_price', $changes) ? $changes['sale_price'] : ($product->sale_price === null ? null : (int) $product->sale_price);

        if ($nextSale !== null) {
            if ($nextPrice === null) {
                return response()->json([
                    'message' => 'A sale price needs a regular price to be a discount from.',
                    'errors' => ['sale_price' => ['Set a regular price first.']],
                ], 422);
            }

            if ($nextSale >= $nextPrice) {
                return response()->json([
                    'message' => 'The sale price has to be below the regular price.',
                    'errors' => ['sale_price' => [
                        'Regular price is '.Money::plain($nextPrice).'.',
                    ]],
                ], 422);
            }
        }

        if ($changes !== []) {
            $product->fill($changes)->save();
        }

        if (array_key_exists('category_ids', $data)) {
            $product->categories()->sync(array_map('intval', $data['category_ids']));
        }

        return response()->json([
            'ok' => true,
            'product' => $this->rowById($product->id),
        ]);
    }

    /**
     * The star column. Left as its own endpoint because routes/web.php already
     * registers it and this lane does not edit that file.
     */
    public function toggleFeatured(Request $request, Product $product): JsonResponse
    {
        $product->update(['featured' => ! $product->featured]);

        return response()->json(['ok' => true, 'featured' => (bool) $product->featured]);
    }

    /* ----------------------------------------------------------------- bulk */

    /**
     * Set a status on a selection.
     *
     * Taking a product OUT of `publish` removes it from the storefront, from
     * every category page and from the sitemap. That is the same shape of
     * consequence as taking an order out of revenue, and it gets the same
     * treatment: without `force` those products are skipped and named back,
     * rather than the whole call failing — the operator asked for the
     * selection, and the safe half of it is still what they meant.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX],
            'ids.*' => ['integer'],
            'status' => ['required', 'string', 'in:'.implode(',', self::SETTABLE_STATUSES)],
        ]);

        $ids = $this->uniqueIds($data['ids']);
        $status = (string) $data['status'];
        $force = $request->boolean('force');

        $products = Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'sku', 'status', 'price']);

        $changeable = [];
        $skipped = [];

        foreach ($products as $product) {
            $current = (string) $product->status;

            if ($current === $status) {
                continue;
            }

            $unpublishing = $current === 'publish' && $status !== 'publish';

            if ($unpublishing && ! $force) {
                $skipped[] = $this->skippedRow($product, 'Currently live on the storefront.');

                continue;
            }

            $changeable[] = (int) $product->id;
        }

        if ($changeable !== []) {
            Product::query()->whereIn('id', $changeable)->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'status' => $status,
            'changed' => count($changeable),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Add, remove or replace category assignments on a selection.
     *
     * `replace` is the destructive one — it drops every assignment a product
     * already has, which on an imported catalogue is the whole WooCommerce
     * taxonomy for that product — so it refuses without an explicit `confirm`.
     * add and remove are additive and reversible and do not.
     *
     * Writes go through the pivot directly rather than through sync() per
     * product: `category_product` has a composite primary key, so the work is
     * "insert the pairs that are not there" and "delete the pairs that are",
     * which is two statements for the whole selection instead of two per row.
     */
    public function bulkCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX],
            'ids.*' => ['integer'],
            'category_ids' => ['required', 'array', 'min:1', 'max:50'],
            'category_ids.*' => ['integer'],
            'mode' => ['required', 'string', 'in:add,remove,replace'],
        ]);

        $ids = $this->uniqueIds($data['ids']);
        $mode = (string) $data['mode'];

        // Only categories that exist. An id that does not is dropped rather
        // than inserted — the pivot has a foreign key and would refuse it, and
        // a 500 is a worse answer than "that category is gone".
        $categoryIds = array_values(array_intersect(
            $this->uniqueIds($data['category_ids']),
            Category::query()->whereIn('id', $this->uniqueIds($data['category_ids']))->pluck('id')->map('intval')->all()
        ));

        if ($categoryIds === []) {
            return response()->json([
                'message' => 'None of those categories exist any more.',
            ], 422);
        }

        if ($mode === 'replace' && ! $request->boolean('confirm')) {
            return response()->json([
                'message' => 'Replacing categories removes every category these products are already in. Confirm to go ahead.',
                'needs_confirmation' => true,
            ], 422);
        }

        // Products that really exist and are not trashed, so the pivot cannot
        // grow a row for a product the list never shows.
        $productIds = Product::query()->whereIn('id', $ids)->pluck('id')->map('intval')->values()->all();

        if ($productIds === []) {
            return response()->json(['ok' => true, 'changed' => 0, 'attached' => 0, 'detached' => 0]);
        }

        $attached = 0;
        $detached = 0;

        DB::transaction(function () use ($mode, $productIds, $categoryIds, &$attached, &$detached) {
            if ($mode === 'replace') {
                $detached += DB::table('category_product')->whereIn('product_id', $productIds)->delete();
            }

            if ($mode === 'remove') {
                $detached += DB::table('category_product')
                    ->whereIn('product_id', $productIds)
                    ->whereIn('category_id', $categoryIds)
                    ->delete();

                return;
            }

            // What is already there, in one read, so the insert below never
            // collides with the composite primary key.
            $existing = [];

            foreach (DB::table('category_product')
                ->whereIn('product_id', $productIds)
                ->whereIn('category_id', $categoryIds)
                ->get(['product_id', 'category_id']) as $row) {
                $existing[$row->product_id.':'.$row->category_id] = true;
            }

            $insert = [];

            foreach ($productIds as $productId) {
                foreach ($categoryIds as $categoryId) {
                    if (isset($existing[$productId.':'.$categoryId])) {
                        continue;
                    }

                    $insert[] = ['product_id' => $productId, 'category_id' => $categoryId];
                }
            }

            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table('category_product')->insert($chunk);
                $attached += count($chunk);
            }
        });

        // The primary category is a separate column and the storefront reads it
        // for breadcrumbs. A product left with no primary category but plenty
        // of assignments renders a breadcrumb to nowhere, so one of the
        // categories just attached fills it in where it is empty.
        if ($mode !== 'remove') {
            Product::query()
                ->whereIn('id', $productIds)
                ->whereNull('category_id')
                ->update(['category_id' => $categoryIds[0], 'updated_at' => now()]);
        }

        return response()->json([
            'ok' => true,
            'mode' => $mode,
            'changed' => count($productIds),
            'attached' => $attached,
            'detached' => $detached,
        ]);
    }

    /**
     * Adjust price or sale price across a selection.
     *
     * ALWAYS CONFIRMED. Every branch of this endpoint rewrites money on rows
     * the operator cannot all see at once, and there is no undo. It refuses
     * without an explicit `confirm`, and the screen's first click is a preview
     * that asks for it.
     *
     * ALWAYS INTEGER. A percentage arrives in whole or fractional percent and
     * is carried as integer BASIS POINTS: -30% is -3000, and the new price is
     *
     *     (fils * (10000 + bp)) / 10000, rounded half away from zero
     *
     * evaluated with intdiv on integers. The float spelling of the same thing,
     * `$fils * (1 - 30 / 100)`, is 0.69999999999999995559 per dirham and lands
     * a fil light — which is exactly the defect found in bundle pricing.
     *
     * Nothing is written below zero, and nothing is written that would leave a
     * sale price at or above its regular price. Rows that would are skipped and
     * reported by name; force is not offered for them, because there is no
     * sensible price to write instead.
     */
    public function bulkPrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX],
            'ids.*' => ['integer'],
            'target' => ['required', 'string', 'in:price,sale_price'],
            'mode' => ['required', 'string', 'in:percent,amount,set,clear'],
            // Up to two decimal places of percent: "12.5" is 1250 basis points.
            'percent' => ['required_if:mode,percent', 'nullable', 'regex:/^-?\d{1,3}(\.\d{1,2})?$/'],
            // A signed decimal string in major units, for mode=amount.
            'amount' => ['required_if:mode,amount', 'nullable', 'regex:/^-?\d{1,9}(\.\d{1,4})?$/'],
            'value' => ['required_if:mode,set', 'nullable', ...self::moneyRule()],
        ]);

        if (! $request->boolean('confirm')) {
            return response()->json([
                'message' => 'A bulk price change cannot be undone. Confirm to go ahead.',
                'needs_confirmation' => true,
            ], 422);
        }

        $ids = $this->uniqueIds($data['ids']);
        $target = (string) $data['target'];
        $mode = (string) $data['mode'];

        /*
         * WHOLE DIRHAMS ON THE ONE MODE WHERE A PRICE IS TYPED — Lane FA.
         *
         * `set` is an operator typing an amount that will become the price of
         * every selected product, so it is REFUSED here exactly as the single
         * price cell refuses one. The other three modes are DERIVED — a
         * percentage, a signed adjustment, a clear — and are adjusted per
         * product in the loop below with a count reported back; see the note
         * on $adjusted.
         *
         * No stored-value comparison on this branch, and there cannot be one:
         * one typed figure is being applied to many products, so there is no
         * "the value did not change" to exempt. That is the right answer here
         * anyway — a bulk set is unambiguously somebody entering a new price.
         */
        if ($mode === 'set') {
            $typed = $this->filsFromMajor($data['value']);

            if ($typed !== null && ! WholeDirhams::isWhole($typed)) {
                return response()->json([
                    'message' => WholeDirhams::message($target === 'price' ? 'Price' : 'Sale price', $typed),
                    'errors' => ['value' => ['Whole ' . WholeDirhams::plural() . ' only.']],
                ], 422);
            }
        }

        /*
         * How many products had their new price moved to a whole dirham.
         *
         * REPORTED, NEVER SILENT. A bulk price change is confirmed explicitly
         * ("A bulk price change cannot be undone"), so adjusting a derived
         * result here is the owner's own action rather than something done to
         * him — but he still has to be told how many of his prices are not the
         * arithmetic he asked for. A 10% cut across the catalogue that lands
         * 40 products on a rounded dirham is a fact about his prices.
         */
        $adjusted = 0;

        $products = Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'sku', 'status', 'price', 'sale_price']);

        $updates = [];
        $skipped = [];

        foreach ($products as $product) {
            $price = $product->price === null ? null : (int) $product->price;
            $sale = $product->sale_price === null ? null : (int) $product->sale_price;
            $current = $target === 'price' ? $price : $sale;

            if ($mode === 'clear') {
                if ($target === 'price') {
                    // Clearing the regular price would leave a sale price with
                    // nothing to be a discount from, and a product with no
                    // price at all on the shelf.
                    $skipped[] = $this->skippedRow($product, 'A product cannot have its regular price removed.');

                    continue;
                }

                if ($sale === null) {
                    continue;
                }

                $updates[(int) $product->id] = null;

                continue;
            }

            if ($mode === 'set') {
                $next = $this->filsFromMajor($data['value']);
            } elseif ($current === null) {
                // Nothing to adjust FROM. Adjusting null by a percentage is not
                // a number; saying so beats inventing one.
                $skipped[] = $this->skippedRow(
                    $product,
                    $target === 'price' ? 'No regular price set.' : 'Not on sale.'
                );

                continue;
            } elseif ($mode === 'percent') {
                $next = $this->applyBasisPoints($current, $this->basisPoints((string) $data['percent']));
            } else {
                $next = $current + $this->signedFilsFromMajor((string) $data['amount']);
            }

            if ($next === null || $next < 0) {
                $skipped[] = $this->skippedRow($product, 'That change would take the price below zero.');

                continue;
            }

            /*
             * THE DERIVED RESULT, ADJUSTED TO A WHOLE DIRHAM.
             *
             * 10% off AED 199 is AED 179.10 and nobody typed that figure —
             * which is exactly the case App\Support\WholeDirhams calls
             * "adjust" rather than "refuse". Refusing here would be refusing
             * the owner's own percentage because of arithmetic he did not
             * choose, and skipping the product would leave a catalogue where
             * the sale applied to some rows and not others.
             *
             * NEAREST, not up and not down. A bulk adjustment is not a promise
             * to a customer the way a coupon discount is (which rounds in the
             * shopper's favour) and not a discount off a shelf price the way a
             * bundle unit is (which rounds the same way): it is the owner
             * repricing his own stock, and the honest rounding of a figure
             * with no other claim on it is the closest one. Over a catalogue
             * it is also the only direction that does not drift the whole
             * price list one way.
             *
             * Counted, and reported in the response.
             */
            if (! WholeDirhams::isWhole($next)) {
                $next = WholeDirhams::nearest($next);
                $adjusted++;
            }

            $regular = $target === 'price' ? $next : $price;
            $discount = $target === 'sale_price' ? $next : $sale;

            if ($discount !== null && ($regular === null || $discount >= $regular)) {
                $skipped[] = $this->skippedRow(
                    $product,
                    'That would leave the sale price at or above the regular price.'
                );

                continue;
            }

            if ($next === $current) {
                continue;
            }

            $updates[(int) $product->id] = $next;
        }

        /*
         * One UPDATE per distinct resulting price, not one per product: a 10%
         * cut across 200 products usually lands on far fewer than 200 distinct
         * values, and a percentage applied to one price is one statement. The
         * ids are grouped by the value they are being set to.
         */
        $byValue = [];

        foreach ($updates as $productId => $value) {
            $byValue[$value === null ? 'null' : (string) $value][] = $productId;
        }

        DB::transaction(function () use ($byValue, $target) {
            foreach ($byValue as $value => $productIds) {
                Product::query()->whereIn('id', $productIds)->update([
                    $target => $value === 'null' ? null : (int) $value,
                    'updated_at' => now(),
                ]);
            }
        });

        /*
         * A MASS UPDATE FIRES NO MODEL EVENTS, AND THIS ONE WRITES PRICES.
         *
         * App\Services\VariantPricing holds a snapshot of every variable
         * parent's price range, dropped by Product::booted() and
         * ProductVariant::booted() on Eloquent's `saved` and `deleted`. A
         * query-builder update like the one above fires neither, so the
         * snapshot survives a write to the very columns it is derived from —
         * the boundary VariantPricing::invalidate() states in its own docblock
         * ("anything that starts to has to call this itself"). This endpoint is
         * the first thing in the application that does, so it says so here.
         *
         * It cannot go wrong through THIS request today: nothing on this path
         * asks for a derived price, and invalidate() builds nothing when the
         * container has never resolved the class — so on every call this is one
         * array lookup and no work at all. It is here so that the day this
         * response grows a price, or this method grows a caller, the snapshot
         * is already right rather than quietly a version behind.
         *
         * Pinned by tests/Feature/VariantPriceMemoRawWriteGuardTest.php, which
         * fails naming the file and line of any new query-builder write to
         * `products` or `product_variants` that does not do this.
         */
        \App\Services\VariantPricing::invalidate();

        return response()->json([
            'ok' => true,
            'target' => $target,
            'mode' => $mode,
            'changed' => count($updates),
            'statements' => count($byValue),
            'skipped' => $skipped,
            /*
             * Said out loud, on the screen, beside the count that changed.
             * A derived figure may be adjusted; it may never be adjusted
             * quietly. See the note where $adjusted is incremented.
             */
            'adjusted_to_whole' => $adjusted,
            'adjusted_note' => $adjusted === 0 ? null
                : $adjusted . ' ' . ($adjusted === 1 ? 'price was' : 'prices were')
                    . ' rounded to the nearest whole ' . rtrim(WholeDirhams::plural(), 's')
                    . ' — this shop prices in whole ' . WholeDirhams::plural() . '.',
        ]);
    }

    /* ---------------------------------------------------------------- export */

    /**
     * CSV of the CURRENT filtered view — the same rows, in the same order, as
     * the screen the operator is looking at.
     *
     * Streamed in chunks: a shared host will not hold 2,266 products and their
     * aggregates in memory alongside the request. Every cell goes through
     * csvCell(), because a product name is operator- and importer-supplied text
     * and `=HYPERLINK(...)` in a product name must not become a live formula
     * when the owner double-clicks the file.
     *
     * NO ->limit(self::EXPORT_MAX) HERE, DELIBERATELY. chunk() walks with
     * forPage(), and forPage() SETS limit and offset rather than intersecting
     * with a limit already on the builder, so a ceiling expressed that way is
     * overwritten on the first round trip and the export streams the whole
     * table. The ceiling is enforced by counting rows written and returning
     * false from the chunk callback, which is how chunk() is told to stop.
     */
    public function export(Request $request): StreamedResponse
    {
        $filter = $this->filter($request);
        $sort = (string) $request->query('sort', 'newest');

        $query = $this->applySort(
            $this->withSalesTotals(
                $this->withCategoryCounts(
                    $this->applyFilter($this->baseQuery($request), $filter)
                )
            ),
            $sort
        );

        $filename = 'products-'.now()->format('Y-m-d').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM: without it Excel on Windows reads an accented or
            // Korean product name as mojibake, and this catalogue has both.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'id', 'wc_id', 'name', 'sku', 'brand', 'categories', 'status', 'visible',
                'featured', 'stock_status', 'manage_stock', 'stock',
                'price_fils', 'price', 'sale_price_fils', 'sale_price',
                'effective_price_fils', 'effective_price', 'on_sale', 'discount_percent',
                'has_image', 'orders', 'units_sold', 'revenue_fils', 'revenue',
                'created_at', 'updated_at', 'trashed', 'currency',
            ]);

            $currency = Money::currency();
            $written = 0;

            $query->with('categories:id,name')->chunk(self::EXPORT_CHUNK, function ($chunk) use ($out, $currency, &$written) {
                foreach ($chunk as $product) {
                    if ($written >= self::EXPORT_MAX) {
                        return false;
                    }

                    $written++;

                    $row = $this->rowToApi($product);

                    fputcsv($out, array_map($this->csvCell(...), [
                        $row['id'],
                        $row['wc_id'] ?? '',
                        $row['name'],
                        $row['sku'] ?? '',
                        $row['brand'] ?? '',
                        implode(' | ', $row['categories']),
                        $row['status'],
                        $row['is_visible'] ? 'yes' : 'no',
                        $row['featured'] ? 'yes' : 'no',
                        $row['stock_status'],
                        $row['manage_stock'] ? 'yes' : 'no',
                        $row['stock'] ?? '',
                        $row['price_fils'] ?? '',
                        $row['price_fils'] === null ? '' : $this->majorString($row['price_fils']),
                        $row['sale_price_fils'] ?? '',
                        $row['sale_price_fils'] === null ? '' : $this->majorString($row['sale_price_fils']),
                        $row['effective_price_fils'] ?? '',
                        $row['effective_price_fils'] === null ? '' : $this->majorString($row['effective_price_fils']),
                        $row['on_sale'] ? 'yes' : 'no',
                        $row['discount_percent'],
                        $row['has_image'] ? 'yes' : 'no',
                        $row['orders_count'],
                        $row['units_sold'],
                        $row['revenue_fils'],
                        $this->majorString($row['revenue_fils']),
                        $row['created_at'] ?? '',
                        $row['updated_at'] ?? '',
                        $row['trashed'] ? 'yes' : 'no',
                        $currency,
                    ]));
                }
            });

            fclose($out);
        }, 200, $headers);
    }

    /* --------------------------------------------------------------- queries */

    /**
     * `products` with the join EVERY statement on this screen needs, and only
     * that one.
     *
     *   b   brands, for the name and for sorting by it. It stays here because
     *       the FILTERED SET is defined in terms of it — the search box matches
     *       b.name — so a statement that merely counts that set still has to
     *       carry it or it would count the wrong rows. It is an eq_ref join on
     *       a primary key.
     *
     * TWO GROUPED DERIVED TABLES USED TO BE HERE TOO, and putting them here put
     * them on all six statements this screen issues, five of which never named
     * a column of either. They are now added by the statements that read them:
     *
     *   withSalesTotals()      oa, the per-product sales aggregate
     *   withCategoryCounts()   ca, how many categories a product is in
     *
     * Each of those two methods carries its own measurement. Together they took
     * this endpoint from 209.0 ms of SQL to 59.9 ms against 3,025 products,
     * 6,000 orders and 27,000 order lines; docs/page-cost.md has the method and
     * `artisan kbb:page-cost` reproduces it.
     *
     * The category NAMES are not here on purpose: a GROUP_CONCAT would be a
     * dialect problem (separator syntax differs) and would have to be parsed
     * back out. They arrive through one constrained eager load over the page.
     */
    private function rowQuery(): Builder
    {
        return Product::query()
            ->leftJoin('brands as b', 'b.id', '=', 'products.brand_id')
            ->select([
                // An explicit allowlist. `products` also carries seo, seo_json,
                // meta_feed and custom_tabs — admin blobs nothing on this
                // screen reads — and `description`, which is a page of HTML per
                // row. A screen that selected the whole row would ship all of
                // it 50 times per request.
                'products.id',
                'products.wc_id',
                'products.name',
                'products.slug',
                'products.sku',
                'products.type',
                'products.status',
                'products.is_visible',
                'products.featured',
                'products.brand_id',
                'products.category_id',
                'products.price',
                'products.sale_price',
                'products.sale_starts_at',
                'products.sale_ends_at',
                'products.manage_stock',
                'products.stock',
                'products.stock_status',
                'products.image',
                'products.rating',
                'products.review_count',
                'products.total_sales',
                'products.position',
                'products.created_at',
                'products.updated_at',
                'products.deleted_at',
                DB::raw('b.name as brand_name'),
            ]);
    }

    /**
     * How many categories each product is in.
     *
     *   ca  category_product, grouped. Two things read it and nothing else
     *       does: the `category_count` printed on every row, and the
     *       "No category" chip's COUNT, which is a SUM(CASE ...) over the whole
     *       filtered set and therefore genuinely needs the number per row.
     *
     * It used to be in rowQuery() beside `b`, which put it on all six
     * statements. Measured on MySQL 8.0 at 3,025 products and 4,026 pivot rows,
     * the same statement with the join and without it, twice in each direction:
     *
     *     the pagination COUNT      6.7 - 7.2 ms  ->  3.3 - 3.7 ms
     *     the status chips          8.0 - 8.7 ms  ->  4.9 - 5.3 ms
     *     the summary strip         7.7 - 8.0 ms  ->  3.6 - 4.3 ms
     *
     * About 3.4 ms a statement, on five statements that never named it.
     *
     * `b` stays in rowQuery() and this does not, and the difference is which
     * statements READ them: the search box matches b.name, so a COUNT of the
     * filtered set has to carry `b` or it would count the wrong rows. Nothing
     * filters on a category COUNT any more — see applyFilter()'s `no_category`
     * arm, which asks the pivot directly.
     */
    private function withCategoryCounts(Builder $query): Builder
    {
        $categories = DB::table('category_product')
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as category_count');

        return $query
            ->leftJoinSub($categories, 'ca', 'ca.product_id', '=', 'products.id')
            ->addSelect([DB::raw('COALESCE(ca.category_count, 0) as category_count')]);
    }

    /**
     * The sales aggregate, added to a query that is about to FETCH ROWS — the
     * page of the list, one row re-read after a write, or the export.
     *
     *   oa  order_items joined to orders, for how many orders a product has
     *       appeared in, how many units have sold and what they were worth.
     *       Restricted to Order::REAL_STATUSES — the same definition the
     *       dashboard's revenue figure, Catalog → Reorder and Store → Orders
     *       all read — so this screen cannot disagree with them about what a
     *       sale is. Trashed orders are excluded: `orders` soft-deletes, and a
     *       raw DB::table() join does not know that.
     *
     * WHY IT IS NOT IN rowQuery() ANY MORE. It was, and so every statement this
     * screen issues carried it: the page of 50 rows, the pagination COUNT, the
     * three chip-count statements and the summary strip. A derived table is
     * materialised in full before it can be joined, and this one joins the
     * whole of `order_items` to the whole of `orders` and groups the result —
     * to answer a question about at most fifty products.
     *
     * Measured on MySQL 8.0 against 3,025 products, 6,000 orders and 27,000
     * order lines (docs/page-cost.md; `artisan kbb:page-cost` reproduces it):
     *
     *     GET /admin-api/catalog-products-list   11 statements, 209.0 ms of SQL
     *
     * and the split, in one such request:
     *
     *          the derived chip counts    40.4 ms   needs ca; never names oa
     *          the stock_status chips     40.2 ms   names neither
     *          the pagination COUNT       39.9 ms   names neither
     *          the status chips           39.6 ms   names neither
     *          the summary strip          38.5 ms   names neither
     *          the page of rows           34.7 ms   needs both
     *
     * Five of those six name no column of `oa` at all — aggregateQuery()
     * discards the select list so the aggregate is legal, and what it discards
     * is the only thing that referenced it. With `oa` and `ca` each scoped to
     * their readers the page is 59.9 ms.
     *
     * REMOVING IT CANNOT CHANGE A COUNT. The subquery groups by product_id, so
     * it yields at most one row per product, and a LEFT JOIN onto at most one
     * row neither multiplies nor drops rows. Asserted from the other end in
     * tests/Feature/PageCostBudgetTest.php against the figures computed
     * independently.
     *
     * `b` stays in rowQuery() because the FILTERED SET is defined in terms of
     * it: the search box matches b.name, so a statement that counts that set
     * has to carry it or it would count the wrong rows. It is an eq_ref join on
     * a primary key and costs a lookup per row returned. The category-count
     * join used to stay for the same reason and no longer has to — see
     * withCategoryCounts() below and applyFilter()'s `no_category` arm.
     *
     * addSelect(), not select(): the allowlist rowQuery() built is the point of
     * that method. Replacing it here would put `description` — a page of HTML
     * per row — back on the wire fifty times a request.
     */
    private function withSalesTotals(Builder $query): Builder
    {
        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', Order::REAL_STATUSES)
            ->whereNull('orders.deleted_at')
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id,'
                .' COUNT(DISTINCT order_items.order_id) as orders_count,'
                .' COALESCE(SUM(order_items.quantity), 0) as units_sold,'
                .' COALESCE(SUM(order_items.total), 0) as revenue_fils');

        return $query
            ->leftJoinSub($sales, 'oa', 'oa.product_id', '=', 'products.id')
            ->addSelect([
                DB::raw('COALESCE(oa.orders_count, 0) as orders_count'),
                DB::raw('COALESCE(oa.units_sold, 0) as units_sold'),
                DB::raw('COALESCE(oa.revenue_fils, 0) as revenue_fils'),
            ]);
    }

    /** Everything the operator typed, except the chip. */
    private function baseQuery(Request $request): Builder
    {
        $query = $this->rowQuery();

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            // Somebody searching for "50%" must not turn it into a wildcard.
            $like = '%'.$this->escapeLike($search).'%';

            $columns = ['products.name', 'products.sku', 'products.slug', 'b.name'];

            $query->where(function ($q) use ($columns, $like, $search) {
                foreach ($columns as $column) {
                    // The column names are literals from the list above, never
                    // anything the request supplies; only the pattern is bound.
                    $q->orWhereRaw($column.' like ? escape '.self::LIKE_ESCAPE_SQL, [$like]);
                }

                // Typing an id finds that product, and typing a WooCommerce
                // product id finds the row it was imported into — the only way
                // to answer "did Woo product 18422 come across?".
                if (ctype_digit($search)) {
                    $q->orWhere('products.id', '=', (int) $search)
                        ->orWhere('products.wc_id', '=', (int) $search);
                }
            });
        }

        $brandId = trim((string) $request->query('brand_id', ''));

        if ($brandId !== '' && ctype_digit($brandId)) {
            $query->where('products.brand_id', '=', (int) $brandId);
        }

        $categoryId = trim((string) $request->query('category_id', ''));

        if ($categoryId !== '' && ctype_digit($categoryId)) {
            // Through the pivot, so a product in a category it is not PRIMARILY
            // in is still found. whereExists rather than a join: a join would
            // multiply the row out and every count above it would be wrong.
            $query->whereExists(function ($q) use ($categoryId) {
                $q->select(DB::raw(1))
                    ->from('category_product')
                    ->whereColumn('category_product.product_id', 'products.id')
                    ->where('category_product.category_id', '=', (int) $categoryId);
            });
        }

        // Price band, in whole major units on the wire and fils in the
        // comparison. A real column, never a SELECT alias: MySQL will not have
        // an alias in a WHERE clause and SQLite accepting one is how that ships
        // unnoticed.
        foreach ([['price_min', '>='], ['price_max', '<=']] as [$key, $operator]) {
            $raw = trim((string) $request->query($key, ''));

            if ($raw !== '' && preg_match('/^\d{1,9}(\.\d{1,4})?$/', $raw) === 1) {
                $query->where('products.price', $operator, $this->filsFromMajor($raw));
            }
        }

        $type = trim((string) $request->query('type', ''));

        if ($type !== '') {
            $query->where('products.type', '=', $type);
        }

        return $query;
    }

    /** The chip. 'all', a derived filter, or one literal status out of a column. */
    private function filter(Request $request): string
    {
        $filter = trim((string) $request->query('filter', 'all')) ?: 'all';

        /*
         * The names the PREVIOUS version of this screen used, and the names
         * routes/web.php's still-registered GET /admin-api/catalog/products is
         * driven with by tests/Feature/SqlDialectGuardTest.php. Mapped rather
         * than dropped: a chip that silently showed every product when the
         * caller asked for one status is the worse answer.
         */
        return match ($filter) {
            'published' => 'publish',
            'out' => 'outofstock',
            default => $filter,
        };
    }

    private function applyFilter(Builder $query, string $filter): Builder
    {
        if ($filter === 'all' || $filter === '') {
            return $query;
        }

        if ($filter === 'trashed') {
            return $query->onlyTrashed();
        }

        if ($filter === 'low') {
            return $query->where('products.manage_stock', true)
                ->where('products.stock', '>', 0)
                ->where('products.stock', '<=', self::LOW_STOCK);
        }

        if ($filter === 'hidden') {
            return $query->where('products.is_visible', false);
        }

        if ($filter === 'featured') {
            return $query->where('products.featured', true);
        }

        if ($filter === 'no_image') {
            return $query->where(fn ($q) => $q->whereNull('products.image')->orWhere('products.image', '=', ''));
        }

        if ($filter === 'no_price') {
            return $query->whereNull('products.price');
        }

        if ($filter === 'no_category') {
            /*
             * The pivot, asked directly, rather than `COALESCE(ca.category_count,
             * 0) = 0` against the grouped derived table.
             *
             * Exactly the same set of rows — "no pivot row" and "a count of
             * zero over the pivot" are the same statement about the same table
             * — and it is the same shape baseQuery() already uses for
             * `category_id`, with the same reasoning: a whereExists asks a
             * question without changing what the query is over.
             *
             * What it buys is that the grouped join is no longer part of the
             * FILTERED SET's definition, so every statement that merely counts
             * that set can stop carrying it. See withCategoryCounts() for the
             * measurement.
             */
            return $query->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('category_product')
                    ->whereColumn('category_product.product_id', 'products.id');
            });
        }

        if ($filter === 'on_sale') {
            return $this->whereOnSale($query);
        }

        if (in_array($filter, self::KNOWN_STOCK_STATUSES, true)) {
            return $query->where('products.stock_status', '=', $filter);
        }

        // Anything else is a literal status. An unknown one is NOT rewritten to
        // 'all' — a chip that quietly showed the whole catalogue when the
        // operator asked for one status would be worse than an empty list.
        return $query->where('products.status', '=', $filter);
    }

    /**
     * The sale-window predicate, exactly as Product::effectivePrice() decides
     * it: a sale price below the regular price, inside its window if it has one.
     *
     * The dates are compared as instants against a bound value, never as text.
     * A CASE takes the widest type of its branches and MySQL 8 widens a
     * DATETIME to DATETIME(6), so a string comparison against a datetime is a
     * comparison that misses on the server and matches here — which is how
     * every customer who had never ordered came to show a last-active date of
     * 1 Jan 1970.
     */
    private function whereOnSale(Builder $query): Builder
    {
        $now = now()->toDateTimeString();

        return $query
            ->whereNotNull('products.sale_price')
            ->whereNotNull('products.price')
            ->whereColumn('products.sale_price', '<', 'products.price')
            ->where(fn ($q) => $q->whereNull('products.sale_starts_at')->orWhere('products.sale_starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('products.sale_ends_at')->orWhere('products.sale_ends_at', '>=', $now));
    }

    /** The same predicate as SQL, for a CASE inside an aggregate. */
    private function onSaleSql(): string
    {
        return 'products.sale_price IS NOT NULL'
            .' AND products.price IS NOT NULL'
            .' AND products.sale_price < products.price'
            .' AND (products.sale_starts_at IS NULL OR products.sale_starts_at <= ?)'
            .' AND (products.sale_ends_at IS NULL OR products.sale_ends_at >= ?)';
    }

    /**
     * Every chip's count, over the filtered set the other controls define.
     *
     * Four statements whatever the catalogue size: one grouped by status, one
     * grouped by stock_status, one row of SUM(CASE ...) for the derived chips,
     * and one for the trash — `products` soft-deletes, so trashed rows are
     * outside the default scope and cannot be counted in the same pass.
     *
     * The status list returned alongside is the union of what this schema
     * declares and what the column actually holds, so a row written by the dead
     * importer with status 'active' gets a chip rather than being invisible.
     *
     * @return array{counts: array<string,int>, statuses: list<string>, stock_statuses: list<string>}
     */
    private function chipCounts(Builder $base, Request $request): array
    {
        $counts = [];

        $statusRows = $this->aggregateQuery($base, 'products.status as s, COUNT(*) as c')
            ->groupBy('products.status')
            ->get();

        $all = 0;

        foreach ($statusRows as $row) {
            $status = (string) ($row->s ?? '');
            $counts[$status] = ($counts[$status] ?? 0) + (int) $row->c;
            $all += (int) $row->c;
        }

        $stockRows = $this->aggregateQuery($base, 'products.stock_status as s, COUNT(*) as c')
            ->groupBy('products.stock_status')
            ->get();

        foreach ($stockRows as $row) {
            $stock = (string) ($row->s ?? '');
            $counts[$stock] = ($counts[$stock] ?? 0) + (int) $row->c;
        }

        $now = now()->toDateTimeString();

        $derived = $this->aggregate(
            // `ca` and nothing else: this is the one aggregate that reads a
            // category count per row.
            $this->withCategoryCounts(clone $base),
            'SUM(CASE WHEN products.manage_stock = 1 AND products.stock > 0 AND products.stock <= '.self::LOW_STOCK.' THEN 1 ELSE 0 END) as low,'
            .' SUM(CASE WHEN products.is_visible = 0 THEN 1 ELSE 0 END) as hidden,'
            .' SUM(CASE WHEN products.featured = 1 THEN 1 ELSE 0 END) as featured,'
            ." SUM(CASE WHEN products.image IS NULL OR products.image = '' THEN 1 ELSE 0 END) as no_image,"
            .' SUM(CASE WHEN products.price IS NULL THEN 1 ELSE 0 END) as no_price,'
            .' SUM(CASE WHEN COALESCE(ca.category_count, 0) = 0 THEN 1 ELSE 0 END) as no_category,'
            .' SUM(CASE WHEN '.$this->onSaleSql().' THEN 1 ELSE 0 END) as on_sale',
            [$now, $now]
        );

        foreach (self::DERIVED_FILTERS as $key) {
            if ($key === 'trashed') {
                continue;
            }

            $counts[$key] = (int) ($derived?->{$key} ?? 0);
        }

        $counts['trashed'] = (int) $this->applyFilter($this->baseQuery($request), 'trashed')
            ->toBase()
            ->getCountForPagination();

        $statuses = array_values(array_unique(array_merge(
            self::KNOWN_STATUSES,
            array_filter(array_map(fn ($r) => (string) ($r->s ?? ''), $statusRows->all()), fn ($s) => $s !== '')
        )));

        $stockStatuses = array_values(array_unique(array_merge(
            self::KNOWN_STOCK_STATUSES,
            array_filter(array_map(fn ($r) => (string) ($r->s ?? ''), $stockRows->all()), fn ($s) => $s !== '')
        )));

        foreach (array_merge($statuses, $stockStatuses) as $key) {
            $counts[$key] = $counts[$key] ?? 0;
        }

        $counts['all'] = $all;

        // The names the previous screen's chips used, kept so the still-mounted
        // GET /admin-api/catalog/products answers the same shape it used to.
        $counts['published'] = $counts['publish'] ?? 0;
        $counts['out'] = $counts['outofstock'] ?? 0;

        return ['counts' => $counts, 'statuses' => $statuses, 'stock_statuses' => $stockStatuses];
    }

    /**
     * The figures across the top of the screen, for the filtered view.
     *
     * Inventory value is stock multiplied by the EFFECTIVE price — what the
     * shop would actually charge today, sale window and all — summed in SQL
     * over integers. The average price divides by the number of products that
     * HAVE a price, not by every row: an imported catalogue with 200 priceless
     * drafts in it would otherwise report an average that is simply wrong.
     * Integer division throughout.
     */
    private function summaryFor(Builder $query): array
    {
        $now = now()->toDateTimeString();
        $onSale = $this->onSaleSql();

        $row = $this->aggregate(
            $query,
            'COUNT(*) as products,'
            .' SUM(CASE WHEN products.price IS NOT NULL THEN 1 ELSE 0 END) as priced,'
            .' COALESCE(SUM(CASE WHEN products.price IS NOT NULL THEN products.price ELSE 0 END), 0) as price_total,'
            .' COALESCE(SUM(CASE WHEN products.manage_stock = 1 AND products.stock > 0 THEN products.stock ELSE 0 END), 0) as stock_units,'
            .' COALESCE(SUM(CASE WHEN products.manage_stock = 1 AND products.stock > 0'
            .'   THEN products.stock * (CASE WHEN '.$onSale.' THEN products.sale_price ELSE COALESCE(products.price, 0) END)'
            .'   ELSE 0 END), 0) as inventory_fils,'
            .' SUM(CASE WHEN '.$onSale.' THEN 1 ELSE 0 END) as on_sale,'
            ." SUM(CASE WHEN products.stock_status = 'outofstock' THEN 1 ELSE 0 END) as out_of_stock,"
            .' SUM(CASE WHEN products.status = \'publish\' AND products.is_visible = 1 THEN 1 ELSE 0 END) as live',
            [$now, $now, $now, $now]
        );

        $products = (int) ($row->products ?? 0);
        $priced = (int) ($row->priced ?? 0);
        $priceTotal = (int) ($row->price_total ?? 0);
        $inventory = (int) ($row->inventory_fils ?? 0);
        $average = $priced > 0 ? intdiv($priceTotal, $priced) : 0;

        return [
            'products' => $products,
            'live' => (int) ($row->live ?? 0),
            'priced' => $priced,
            'stock_units' => (int) ($row->stock_units ?? 0),
            'inventory_fils' => $inventory,
            'inventory_display' => Money::plain($inventory),
            'average_price_fils' => $average,
            'average_price_display' => Money::plain($average),
            'on_sale' => (int) ($row->on_sale ?? 0),
            'out_of_stock' => (int) ($row->out_of_stock ?? 0),
        ];
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'oldest' => $query->orderBy('products.created_at')->orderBy('products.id'),
            'updated' => $query->orderByDesc('products.updated_at')->orderByDesc('products.id'),
            'name' => $query->orderBy('products.name')->orderBy('products.id'),
            'name_desc' => $query->orderByDesc('products.name')->orderByDesc('products.id'),
            'sku' => $query->orderBy('products.sku')->orderBy('products.id'),
            // COALESCE, because a product on sale is worth its sale price and a
            // product with no price at all must not sort as if it were free
            // above everything — it sorts last on a descending price sort.
            'price_desc' => $query->orderByRaw('COALESCE(products.sale_price, products.price, 0) desc')->orderByDesc('products.id'),
            'price_asc' => $query->orderByRaw('COALESCE(products.sale_price, products.price, 0) asc')->orderBy('products.id'),
            'stock_asc' => $query->orderByRaw('COALESCE(products.stock, 0) asc')->orderBy('products.id'),
            'stock_desc' => $query->orderByRaw('COALESCE(products.stock, 0) desc')->orderByDesc('products.id'),
            'status' => $query->orderBy('products.status')->orderByDesc('products.id'),
            // A product with no brand sorts under the empty string rather than
            // being scattered by a NULL, which orders differently on the two
            // engines.
            'brand' => $query->orderByRaw("COALESCE(b.name, '') asc")->orderBy('products.id'),
            'orders_desc' => $query->orderByRaw('COALESCE(oa.orders_count, 0) desc')->orderByDesc('products.id'),
            'sales_desc' => $query->orderByRaw('COALESCE(oa.units_sold, 0) desc')->orderByDesc('products.id'),
            'position' => $query->orderBy('products.position')->orderBy('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };
    }

    /** One row, re-read through the same query the list uses. */
    private function rowById(int $id): ?array
    {
        $product = $this->withSalesTotals($this->withCategoryCounts($this->rowQuery()))
            ->withTrashed()
            ->with('categories:id,name')
            ->where('products.id', '=', $id)
            ->first();

        return $product === null ? null : $this->rowToApi($product);
    }

    /* ------------------------------------------------------------ formatting */

    /**
     * One row, as an explicit allowlist of fields. Never the model.
     *
     * `price` and `sale_price` are here in MAJOR units and are the only floats
     * in this file. They exist because the order detail screen's product picker
     * — another lane's region of the admin view — already reads them and has
     * done since before this screen was rewritten. Nothing here computes with
     * them: every figure this screen shows comes from the *_fils integers
     * beside them, and every price this screen writes is parsed from a string.
     */
    private function rowToApi(object $p): array
    {
        $price = $p->price === null ? null : (int) $p->price;
        $sale = $p->sale_price === null ? null : (int) $p->sale_price;
        $effective = $this->effectiveFils($price, $sale, $p->sale_starts_at, $p->sale_ends_at);
        $onSale = $price !== null && $effective < $price;
        $image = $this->blankToNull($p->image);

        return [
            'id' => (int) $p->id,
            'wc_id' => $p->wc_id === null ? null : (int) $p->wc_id,
            'name' => (string) $p->name,
            'slug' => (string) $p->slug,
            'sku' => $this->blankToNull($p->sku),
            'type' => (string) $p->type,
            'brand' => $this->blankToNull($p->brand_name ?? null),
            'brand_id' => $p->brand_id === null ? null : (int) $p->brand_id,
            'image' => $image,
            'has_image' => $image !== null,
            'status' => (string) $p->status,
            'is_visible' => (bool) $p->is_visible,
            'featured' => (bool) $p->featured,
            'stock_status' => (string) $p->stock_status,
            'manage_stock' => (bool) $p->manage_stock,
            // Null means "not tracked", which is a different statement from
            // zero and reads differently on the shelf.
            'stock' => $p->manage_stock ? ($p->stock === null ? 0 : (int) $p->stock) : null,
            'low_stock' => (bool) $p->manage_stock && $p->stock !== null && (int) $p->stock > 0 && (int) $p->stock <= self::LOW_STOCK,
            'price_fils' => $price,
            'price_display' => $price === null ? null : Money::plain($price),
            'price_input' => $price === null ? '' : $this->majorString($price),
            'sale_price_fils' => $sale,
            'sale_price_display' => $sale === null ? null : Money::plain($sale),
            'sale_price_input' => $sale === null ? '' : $this->majorString($sale),
            'effective_price_fils' => $effective,
            'effective_price_display' => Money::plain($effective),
            'on_sale' => $onSale,
            // Integer division, so the percentage on the badge is derived from
            // the fils and never from a float that is a ten-thousandth short.
            'discount_percent' => ($onSale && $price !== null && $price > 0)
                ? intdiv(($price - $effective) * 100, $price)
                : 0,
            'sale_starts_at' => $this->iso($p->sale_starts_at),
            'sale_ends_at' => $this->iso($p->sale_ends_at),
            // Back-compatible major-unit numbers. See the note above.
            'price' => $price === null ? null : Money::toMajor($price),
            'sale_price' => $sale === null ? null : Money::toMajor($sale),
            'categories' => $this->categoryNames($p),
            'category_ids' => $this->categoryIds($p),
            'category_count' => (int) ($p->category_count ?? 0),
            'orders_count' => (int) ($p->orders_count ?? 0),
            'units_sold' => (int) ($p->units_sold ?? 0),
            'revenue_fils' => (int) ($p->revenue_fils ?? 0),
            'revenue_display' => Money::plain((int) ($p->revenue_fils ?? 0)),
            'total_sales' => (int) $p->total_sales,
            'review_count' => (int) $p->review_count,
            'rating' => (float) $p->rating,
            'position' => (int) $p->position,
            'trashed' => $p->deleted_at !== null,
            'created_at' => $this->iso($p->created_at),
            'updated_at' => $this->iso($p->updated_at),
            // What the old screen printed. Kept because a shop owner reads
            // "12 Mar 2026" faster than an ISO timestamp, and because the
            // export and the list should agree about the date.
            'date' => $this->shortDate($p->created_at),
        ];
    }

    /** @return list<string> */
    private function categoryNames(object $p): array
    {
        if (! $p instanceof Product || ! $p->relationLoaded('categories')) {
            return [];
        }

        return $p->categories->map(fn ($c) => (string) $c->name)->values()->all();
    }

    /** @return list<int> */
    private function categoryIds(object $p): array
    {
        if (! $p instanceof Product || ! $p->relationLoaded('categories')) {
            return [];
        }

        return $p->categories->map(fn ($c) => (int) $c->id)->values()->all();
    }

    /**
     * The price the shop would charge today, in fils.
     *
     * The same three branches as Product::effectivePrice(), evaluated on a row
     * that came back from a query-builder select rather than as a hydrated
     * model — the dates arrive as strings there and as Carbon here, and both
     * have to work.
     */
    private function effectiveFils(?int $price, ?int $sale, mixed $startsAt, mixed $endsAt): int
    {
        if ($price === null) {
            return $sale ?? 0;
        }

        if ($sale === null || $sale >= $price) {
            return $price;
        }

        $now = now();
        $start = $this->carbon($startsAt);
        $end = $this->carbon($endsAt);

        if ($start !== null && $now->lt($start)) {
            return $price;
        }

        if ($end !== null && $now->gt($end)) {
            return $price;
        }

        return $sale;
    }

    private function carbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function iso(mixed $value): ?string
    {
        return $this->carbon($value)?->toIso8601String();
    }

    private function shortDate(mixed $value): ?string
    {
        return $this->carbon($value)?->format('M j, Y');
    }

    private function blankToNull(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function skippedRow(Product $product, string $why): array
    {
        return [
            'id' => (int) $product->id,
            'label' => (string) $product->name,
            'sku' => $this->blankToNull($product->sku),
            'status' => (string) $product->status,
            'reason' => $why,
        ];
    }

    /* ----------------------------------------------------------------- money */

    /**
     * A decimal string in major units -> exact fils, by integer arithmetic.
     *
     * Never `(int) round($major * 100)`. A binary float cannot hold 1.15, and
     * `(int) (1.15 * 100)` is 114 — a store that is a fil light on every
     * hundredth product is a store whose books do not add up. The same family
     * of defect was found in bundle pricing, where `1 - 30 / 100` is
     * 0.69999999999999995559.
     *
     * The integer and fractional halves are split on the decimal point, the
     * fraction is padded or truncated to the currency's exponent, and the two
     * are combined with multiplication and addition on integers only.
     *
     * THE PARSE ITSELF NOW LIVES IN App\Support\MajorUnits, unchanged, and
     * this method calls it. Catalog → Products' create form needs exactly this
     * arithmetic and a third hand-rolled copy of it is a third place for the
     * same defect to come back — the same reasoning that keeps image uploads on
     * one endpoint (routes/brands-admin.php, routes/catalog-admin.php). The
     * signature, the behaviour and every caller here are unchanged.
     */
    private function filsFromMajor(mixed $value): ?int
    {
        return MajorUnits::fils($value);
    }

    /** The same parse, for a signed value (a bulk amount adjustment). */
    private function signedFilsFromMajor(string $text): int
    {
        return MajorUnits::signedFils($text);
    }

    /**
     * A percentage string -> integer basis points. "12.5" is 1250, "-30" is
     * -3000. Two decimal places of percent, which is a hundredth of a percent.
     */
    /** PUBLIC for the same reason as applyBasisPoints() above — Lane FA. */
    public function basisPoints(string $percent): int
    {
        $percent = trim($percent);
        $negative = str_starts_with($percent, '-');

        if ($negative || str_starts_with($percent, '+')) {
            $percent = substr($percent, 1);
        }

        $parts = explode('.', $percent, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2);

        $bp = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$bp : $bp;
    }

    /**
     * fils * (10000 + bp) / 10000, rounded half away from zero, on integers.
     *
     * 10000 fils at -3000bp is exactly 7000, not 6999.999999999999. The
     * rounding is done by adding half the divisor before the integer division,
     * which is what a price rounds like.
     */
    /**
     * PUBLIC so the arithmetic can be tested where it is exact — Lane FA.
     *
     * bulkPrice() now rounds every derived result to a whole dirham, and a fil
     * of float drift is invisible once the answer has been rounded to a dirham:
     * 6,999 and 7,000 are both AED 70. AdminCatalogProductsTest was written
     * for exactly that fil (`1 - 30 / 100` is 0.69999999999999995559), so it
     * holds THIS method to the exact figure and the endpoint to the policy.
     * Folding the two together would leave that test passing over the defect
     * it exists for. Same arrangement as CouponService::exactDiscountFor().
     */
    public function applyBasisPoints(int $fils, int $bp): int
    {
        $numerator = $fils * (10000 + $bp);

        if ($numerator >= 0) {
            return intdiv($numerator + 5000, 10000);
        }

        return -intdiv(-$numerator + 5000, 10000);
    }

    /**
     * Fils as a plain decimal string, for a spreadsheet column or an input box.
     *
     * Built by integer division, and without the thousands separators
     * Money::amount() adds — a grouped number stops being a number once it is
     * in a CSV, and stops being editable once it is in a text box.
     */
    private function majorString(int $fils): string
    {
        $exponent = Money::minorExponent();

        $sign = $fils < 0 ? '-' : '';
        $abs = abs($fils);

        if ($exponent <= 0) {
            return $sign.$abs;
        }

        $unit = 10 ** $exponent;

        return $sign.intdiv($abs, $unit).'.'.str_pad((string) ($abs % $unit), $exponent, '0', STR_PAD_LEFT);
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * Make a search term literal inside a LIKE pattern, identically on both
     * dialects.
     *
     * The usual str_replace(['\\', '%', '_'], ...) with a plain ->where(…,
     * 'like', …) is a dialect trap: MySQL treats a backslash as the default
     * LIKE escape and SQLite has NO default escape at all, so the pattern that
     * finds "KBB-50%-OFF" on production matches nothing under the test suite.
     * An explicit ESCAPE '!' removes the difference; '!' has no special meaning
     * in a string literal on either engine, so there is no second layer of
     * escaping to get wrong. The escape character is doubled first, or a term
     * containing '!' would escape the character after it.
     */
    private const LIKE_ESCAPE = '!';

    /** The literal as it is written into SQL. No binding: it is a constant. */
    private const LIKE_ESCAPE_SQL = "'!'";

    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $term
        );
    }

    /**
     * Neutralise a spreadsheet formula before it reaches a cell.
     *
     * Excel, LibreOffice and Sheets all execute a cell beginning =, +, - or @,
     * and a leading tab or carriage return sneaks past a naive check of the
     * first character. Product names and SKUs in this catalogue are supplied by
     * the importer from a WooCommerce export, which is itself a file somebody
     * could have edited. Prefixing a single quote is the mitigation those
     * applications understand — the cell reads as text and the original
     * characters survive in the raw file.
     */
    private function csvCell(mixed $value): string
    {
        $string = (string) $value;

        if ($string !== '' && str_contains("=+-@\t\r", $string[0])) {
            return "'".$string;
        }

        return $string;
    }

    /** @return list<int> */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function clampPerPage(int $requested): int
    {
        if ($requested <= 0) {
            return self::PER_PAGE_DEFAULT;
        }

        return max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $requested));
    }
}
