<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store back-office API. Session-guarded (auth:admin) and mounted in web.php,
 * NOT api.php — the stateless API guard can't see the admin web session.
 * Money is stored in fils; the admin UI works in whole AED, so we convert here.
 */
class AdminController extends Controller
{
    // Kept here as an alias so nothing else in this file needs touching —
    // the actual definition now lives on Order::REAL_STATUSES, shared with
    // Catalog → Reorder / Products' order-count. See that constant's own
    // comment for why.
    private const REVENUE_STATUSES = \App\Models\Order::REAL_STATUSES;

    /** GET /admin-api/stats — dashboard KPIs + recent orders. */
    public function stats()
    {
        $since = now()->subDays(30)->toISOString();

        $revenue30 = (int) Order::whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $since)
            ->sum('total');

        $ordersTotal   = Order::count();
        $customers     = Customer::count();
        $products      = Product::count();
        $lowStock      = Product::whereNotNull('stock')->where('stock', '>', 0)->where('stock', '<=', 15)->count();
        $outStock      = Product::whereNotNull('stock')->where('stock', '=', 0)->count();

        // simple conversion proxy: paid orders / total orders (only meaningful once traffic is tracked)
        $paid = Order::whereIn('status', self::REVENUE_STATUSES)->count();

        /*
         * Eager-loaded. `Customer::find($o->customer_id)` inside the map was one
         * extra SELECT per recent order — bounded at eight here, unbounded in
         * orders() below, which is the same mistake without the limit. Both are
         * now one query for the whole set.
         */
        $recent = Order::with('customer:id,name')->orderByDesc('id')->limit(8)->get()->map(function (Order $o) {
            $c = $o->customer;
            return [
                'id'         => $o->id,
                'customer'   => $c->name ?? 'Guest',
                'total_aed'  => (int) round(($o->total ?? 0) / 100),
                'status'     => $o->status,
                'created_at' => $o->created_at,
            ];
        });

        return response()->json([
            'revenue_30d_aed' => (int) round($revenue30 / 100),
            'orders'          => $ordersTotal,
            'customers'       => $customers,
            'products'        => $products,
            'paid_orders'     => $paid,
            'low_stock'       => $lowStock,
            'out_of_stock'    => $outStock,
            'recent'          => $recent,
        ]);
    }

    /** GET /admin-api/products — full catalog for the admin table. */
    public function products()
    {
        // Eager-loaded. `brand` and `category` are belongsTo relations, not
        // columns -- reading them per row without this fires two extra queries
        // each, which on 671 products is over 1,300 queries for one screen.
        $rows = Product::with(['brand:id,name', 'category:id,name'])
            ->orderBy('position')->orderBy('id')->get()->map(fn (Product $p) => [
            'id'         => $p->id,
            'name'       => $p->name,
            'brand'      => $p->brand?->name,
            'sku'        => $p->sku,
            'category'   => $p->category?->name,
            'price_aed'  => $p->price !== null ? (int) round($p->price / 100) : null,
            'sale_aed'   => $p->sale_price !== null ? (int) round($p->sale_price / 100) : null,
            'stock'      => $p->stock,
            'status'     => $p->status,
            'slug'       => $p->slug,
        ]);

        // Category and brand rollups for the Catalog tabs.
        //
        // These selected flat `category` and `brand` columns that have never
        // existed on this table -- the schema has always used brand_id and
        // category_id foreign keys -- so every request raised
        // SQLSTATE[42S22] and the whole endpoint 500'd. Counted through the
        // joins instead.
        //
        // Counts follow the primary category_id, which is what the `category`
        // value on each row above shows. The many-to-many in `category_product`
        // would give a larger number that does not reconcile with the table.
        $categories = DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id')
            ->whereNull('products.deleted_at')
            ->select('categories.name', DB::raw('count(*) as n'))
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n]);

        $brands = DB::table('brands')
            ->join('products', 'products.brand_id', '=', 'brands.id')
            ->whereNull('products.deleted_at')
            ->select('brands.name', DB::raw('count(*) as n'))
            ->groupBy('brands.id', 'brands.name')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n]);

        return response()->json([
            'products'   => $rows,
            'categories' => $categories,
            'brands'     => $brands,
        ]);
    }

    /** GET /admin-api/products/{id} — full editable record for the product editor. */
    public function getProduct(int $id)
    {
        $p = Product::find($id);
        if (!$p) return response()->json(['error' => 'not_found'], 404);
        return response()->json([
            'id'                => $p->id,
            'name'              => $p->name,
            'brand'             => $p->brand,
            'category'          => $p->category,
            'sku'               => $p->sku,
            'description'       => $p->description,
            'short_description' => $p->short_description,
            'price_aed'         => $p->price !== null ? (int) round($p->price / 100) : null,
            'sale_aed'          => $p->sale_price !== null ? (int) round($p->sale_price / 100) : null,
            'stock'             => $p->stock,
            'status'            => $p->status,
            'slug'              => $p->slug,
            'seo'               => $p->seo_json ? json_decode($p->seo_json, true) : null,
        ]);
    }

    /** PUT /admin-api/products/{id} — persist edits (stock, price, name, status). */
    public function updateProduct(Request $request, int $id)
    {
        $product = Product::find($id);
        if (!$product) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'name'              => 'sometimes|nullable|string|max:255',
            'brand'             => 'sometimes|nullable|string|max:255',
            'category'          => 'sometimes|nullable|string|max:255',
            'sku'               => 'sometimes|nullable|string|max:255',
            'description'       => 'sometimes|nullable|string|max:20000',
            'short_description' => 'sometimes|nullable|string|max:5000',
            'stock'             => 'sometimes|nullable|integer|min:0|max:1000000',
            'price_aed'         => 'sometimes|nullable|integer|min:0|max:10000000',
            'sale_aed'          => 'sometimes|nullable|integer|min:0|max:10000000',
            'status'            => 'sometimes|string|in:active,draft,archived',
            'seo'               => 'sometimes|array',
        ]);

        if (array_key_exists('name', $data))              $product->name = $data['name'];
        if (array_key_exists('brand', $data))             $product->brand = $data['brand'];
        if (array_key_exists('category', $data))          $product->category = $data['category'];
        if (array_key_exists('sku', $data))               $product->sku = $data['sku'];
        if (array_key_exists('description', $data))       $product->description = $data['description'];
        if (array_key_exists('short_description', $data)) $product->short_description = $data['short_description'];
        if (array_key_exists('stock', $data))             $product->stock = $data['stock'];
        if (array_key_exists('status', $data))            $product->status = $data['status'];
        if (array_key_exists('price_aed', $data))         $product->price = $data['price_aed'] === null ? null : $data['price_aed'] * 100;
        if (array_key_exists('sale_aed', $data))          $product->sale_price = $data['sale_aed'] === null ? null : $data['sale_aed'] * 100;
        if (array_key_exists('seo', $data))               $product->seo_json = json_encode($data['seo']);

        $product->save();

        return response()->json(['ok' => true, 'id' => $product->id]);
    }

    /** POST /admin-api/inventory — bulk stock save from the Inventory tab. */
    public function saveInventory(Request $request)
    {
        $data = $request->validate([
            'changes'            => 'required|array|min:1',
            'changes.*.id'       => 'required|integer',
            'changes.*.stock'    => 'required|integer|min:0|max:1000000',
        ]);

        $n = 0;
        DB::transaction(function () use ($data, &$n) {
            foreach ($data['changes'] as $c) {
                $p = Product::find($c['id']);
                if ($p) { $p->stock = $c['stock']; $p->save(); $n++; }
            }
        });

        return response()->json(['ok' => true, 'saved' => $n]);
    }

    /**
     * GET /admin-api/orders — orders list for the admin table.
     *
     * Three queries, not two per order.
     *
     * This method used to run `Customer::find()` AND an `OrderItem::where()
     * ->count()` inside the map, over EVERY row in `orders` with no pagination:
     * 2N + 1 queries, so 1,343 statements on the 671-order live table for one
     * screen. Both are now resolved in one query each — the customer through an
     * eager load, the line count through a single grouped query keyed by
     * order_id.
     *
     * `ship_method` was also a column that has never existed. Eloquent returns
     * null for a missing attribute instead of raising, so the shipping method
     * was blank on every row and nothing said why. The column is
     * `shipping_method`; AdminOrderController's header comment has flagged this
     * family of wrong names since it was written.
     */
    public function orders()
    {
        $orders = Order::with('customer:id,name,email')->orderByDesc('id')->get();

        $lineCounts = OrderItem::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->groupBy('order_id')
            ->selectRaw('order_id, COUNT(*) as n')
            ->pluck('n', 'order_id');

        $rows = $orders->map(function (Order $o) use ($lineCounts) {
            $c = $o->customer;
            return [
                'id'         => $o->id,
                'customer'   => $c->name ?? 'Guest',
                'email'      => $c->email ?? null,
                'items'      => (int) ($lineCounts[$o->id] ?? 0),
                'subtotal_aed' => (int) round(($o->subtotal ?? 0) / 100),
                'total_aed'  => (int) round(($o->total ?? 0) / 100),
                'status'     => $o->status,
                'ship_method'=> $o->shipping_method,
                'created_at' => $o->created_at,
            ];
        });

        return response()->json(['orders' => $rows]);
    }

    /**
     * GET /admin-api/orders/{id} — single order with items + customer.
     *
     * Four columns named here have never existed on these tables, and Eloquent
     * answers a missing attribute with null rather than raising, so this modal
     * showed a quantity of nothing, a line total of AED 0 on every row, no
     * delivery charge, no COD fee and a blank shipping method — on real orders
     * that had all five. AdminOrderController's class comment has recorded the
     * list since it was written and deliberately left it alone as out of scope.
     *
     *   $i->qty        -> order_items.quantity
     *   $o->delivery   -> orders.shipping_total
     *   $o->cod_fee    -> orders.fee_total
     *   $o->ship_method-> orders.shipping_method
     *
     * The response keys are unchanged; only the columns behind them are real
     * now. Money stays integer fils until the final conversion, as everywhere
     * else here.
     */
    public function order(int $id)
    {
        /*
         * The eager load names only columns `customers` actually has.
         *
         * `emirate` and `default_address` are read below and are NOT columns on
         * that table — they answer null, exactly as they did when this method
         * looked the customer up by hand. Listing them here instead would be a
         * far worse bug than the one being fixed: SQLite resolves a
         * double-quoted identifier that matches no column as a STRING LITERAL,
         * so `select "emirate" from customers` returns the word "emirate" and
         * the suite stays green, while MySQL quotes with backticks and raises
         * 1054 Unknown column. A 500 on the live Orders screen, invisible here.
         * tests/Feature/EagerLoadColumnsTest.php now fails on any such list.
         */
        $o = Order::with('customer:id,name,email,phone')->find($id);
        if (!$o) return response()->json(['error' => 'not_found'], 404);

        $c = $o->customer;
        $items = OrderItem::where('order_id', $o->id)->get()->map(fn (OrderItem $i) => [
            'name'       => $i->name,
            'brand'      => $i->brand,
            'qty'        => (int) ($i->quantity ?? 0),
            'unit_aed'   => (int) round(($i->unit_price ?? 0) / 100),
            'line_aed'   => (int) round((((int) ($i->unit_price ?? 0)) * ((int) ($i->quantity ?? 0))) / 100),
        ]);

        return response()->json([
            'id'           => $o->id,
            'status'       => $o->status,
            'customer'     => $c ? ['name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'emirate' => $c->emirate, 'address' => $c->default_address] : null,
            'items'        => $items,
            'subtotal_aed' => (int) round(($o->subtotal ?? 0) / 100),
            'delivery_aed' => (int) round(($o->shipping_total ?? 0) / 100),
            'cod_fee_aed'  => (int) round(($o->fee_total ?? 0) / 100),
            'total_aed'    => (int) round(($o->total ?? 0) / 100),
            'ship_method'  => $o->shipping_method,
            'created_at'   => $o->created_at,
        ]);
    }

    /**
     * GET /admin-api/analytics — sales metrics derived from real orders.
     *
     * TWO THINGS WERE WRONG HERE, and only one of them was visible.
     *
     * 1. `$it->qty`. `order_items` has `quantity`; there is no `qty` column and
     *    never has been. Eloquent answers a missing attribute with null, so
     *    every `(int) $it->qty` was 0 — which means `units_sold` on the
     *    Analytics screen has read ZERO since this endpoint shipped, every unit
     *    count in `top_products` read zero, and the revenue each product was
     *    RANKED by was `unit_price * 0` = 0. The ordering was therefore
     *    arbitrary too. The endpoint answered 200 throughout.
     *
     * 2. It read the whole of `orders` and the whole of `order_items` into PHP
     *    to add them up, and fed every paid order id into one `whereIn`. On the
     *    live table that is 671 orders and their lines through the application,
     *    and a list of ids long enough to be worth worrying about against
     *    max_allowed_packet. Every figure but the 14-day series is an aggregate
     *    the database can do in one statement, so it does.
     *
     * The daily series is still bucketed in PHP, deliberately: grouping by day
     * in SQL needs DATE()/strftime(), and strftime() is SQLite-only — one of
     * the exact statements Tests\Support\SqlShape rejects. Only 14 days of paid
     * orders are read for it, not the table.
     *
     * Money stays in integer fils until the one conversion at the end.
     */
    public function analytics()
    {
        $paidQuery = Order::query()->whereIn('status', self::REVENUE_STATUSES);

        $totals = (clone $paidQuery)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as revenue')
            ->first();

        $revenueTotal = (int) ($totals->revenue ?? 0);       // fils
        $paidCount    = (int) ($totals->n ?? 0);
        $aov          = $paidCount ? intdiv($revenueTotal, $paidCount) : 0;
        $ordersTotal  = (int) Order::query()->count();

        // Daily revenue for the last 14 days. `created_at` is compared as a
        // date rather than grouped by one, so no dialect-specific date function
        // is needed and only the window's rows are read.
        $days = [];
        for ($i = 13; $i >= 0; $i--) $days[now()->subDays($i)->format('Y-m-d')] = 0;

        $window = (clone $paidQuery)
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->get(['created_at', 'total']);

        foreach ($window as $o) {
            $d = substr((string) $o->created_at, 0, 10);
            if (array_key_exists($d, $days)) $days[$d] += (int) $o->total;
        }

        $daily = [];
        foreach ($days as $d => $v) $daily[] = ['date' => $d, 'revenue_aed' => (int) round($v / 100)];

        // Status breakdown (all statuses present), grouped by the database.
        $status = Order::query()
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        /*
         * Top products by revenue, from the paid orders' line items.
         *
         * Grouped by name and brand in SQL. Both are in the GROUP BY, not just
         * one — MySQL under ONLY_FULL_GROUP_BY rejects a bare `brand` beside an
         * aggregate, which is the same 1140 that took the Customers screen down.
         */
        $itemQuery = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::REVENUE_STATUSES);

        $unitsSold = (int) ((clone $itemQuery)
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->first()->units ?? 0);

        $top = (clone $itemQuery)
            ->groupBy('order_items.name', 'order_items.brand')
            ->selectRaw('order_items.name as name, order_items.brand as brand,'
                . ' COALESCE(SUM(order_items.quantity), 0) as units,'
                . ' COALESCE(SUM(order_items.unit_price * order_items.quantity), 0) as revenue')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'name'        => $r->name,
                'brand'       => $r->brand,
                'units'       => (int) $r->units,
                'revenue_aed' => (int) round(((int) $r->revenue) / 100),
            ])
            ->values()
            ->all();

        return response()->json([
            'revenue_total_aed' => (int) round($revenueTotal / 100),
            'orders_total'      => $ordersTotal,
            'paid_orders'       => $paidCount,
            'aov_aed'           => (int) round($aov / 100),
            'units_sold'        => $unitsSold,
            'daily'             => $daily,
            'status_breakdown'  => $status,
            'top_products'      => $top,
        ]);
    }

    /** GET /admin-api/users — list back-office accounts. */
    public function users()
    {
        $me = \Illuminate\Support\Facades\Auth::guard('admin')->id();
        $rows = \App\Models\AdminUser::orderBy('id')->get()->map(fn ($u) => [
            'id'         => $u->id,
            'name'       => $u->name,
            'email'      => $u->email,
            'role'       => $u->role,
            'is_self'    => $u->id === $me,
            'created_at' => $u->created_at,
        ]);
        return response()->json(['users' => $rows]);
    }

    /** POST /admin-api/users — create a back-office account. */
    public function createUser(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:admin_users,email',
            'password' => 'required|string|min:8|max:255',
            'role'     => 'required|string|in:owner,manager,support,editor',
        ]);
        $u = \App\Models\AdminUser::create($data);  // password hashed by model cast
        return response()->json(['ok' => true, 'id' => $u->id]);
    }

    /** PUT /admin-api/users/{id} — update name/role, optionally reset password. */
    public function updateUser(Request $request, int $id)
    {
        $u = \App\Models\AdminUser::find($id);
        if (!$u) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'role'     => 'sometimes|string|in:owner,manager,support,editor',
            'password' => 'sometimes|nullable|string|min:8|max:255',
        ]);

        // Don't allow removing the last owner (either by demotion here).
        if (isset($data['role']) && $u->role === 'owner' && $data['role'] !== 'owner'
            && \App\Models\AdminUser::where('role', 'owner')->count() <= 1) {
            return response()->json(['error' => 'last_owner', 'message' => 'You cannot demote the only owner.'], 422);
        }

        if (isset($data['name'])) $u->name = $data['name'];
        if (isset($data['role'])) $u->role = $data['role'];
        if (!empty($data['password'])) $u->password = $data['password'];  // hashed by cast
        $u->save();

        return response()->json(['ok' => true, 'id' => $u->id]);
    }

    /** DELETE /admin-api/users/{id} — remove an account (never self or the last owner). */
    public function deleteUser(int $id)
    {
        $me = \Illuminate\Support\Facades\Auth::guard('admin')->id();
        $u = \App\Models\AdminUser::find($id);
        if (!$u) return response()->json(['error' => 'not_found'], 404);
        if ($u->id === $me) return response()->json(['error' => 'self', 'message' => 'You cannot delete your own account.'], 422);
        if ($u->role === 'owner' && \App\Models\AdminUser::where('role', 'owner')->count() <= 1) {
            return response()->json(['error' => 'last_owner', 'message' => 'You cannot delete the only owner.'], 422);
        }
        $u->delete();
        return response()->json(['ok' => true]);
    }

    /** GET /admin-api/settings — flat { key: value } from store_settings. */
    public function settings()
    {
        return response()->json(['settings' => \App\Models\Setting::map()]);
    }

    /** PUT /admin-api/settings — upsert a whitelisted set of store settings. */
    public function updateSettings(Request $request)
    {
        // Only these keys are editable from the admin (values arrive as plain strings;
        // money keys — free_ship, delivery_flat, cod_fee — are already in fils, converted UI-side).
        $allowed = [
            'store_name', 'currency', 'vat_rate',
            // Currency display (Store -> Business Details -> Currency).
            // Every one of these has to be here or Save reports success and
            // writes nothing — the loop below skips unknown keys silently.
            'currency_symbol', 'currency_position', 'currency_decimals',
            'currency_symbol_render',
            'free_ship', 'delivery_flat', 'cod_fee',
            'meta_pixel', 'ga', 'google_site_verification', 'bing_site_verification', 'seo_json',
            // Search appearance
            'site_url', 'seo_site_name', 'seo_separator', 'seo_title_template',
            'seo_home_title', 'seo_home_description', 'seo_default_description',
            'robots_index', 'robots_follow',
            // Social
            'og_default_image', 'twitter_handle',
            // Organization / schema
            'org_name', 'org_logo', 'org_type',
            // Sitemap / robots
            'sitemap_enabled', 'robots_txt',
            // Gift wrapping (Store -> Delivery & Shipping -> Gift wrapping).
            // Absent from this list, Save reported success and wrote nothing:
            // the loop below skips unknown keys and returns ok regardless.
            'gift_enabled', 'gift_fee',
            // Social profiles, feeding schema.org sameAs. The SEO screen has
            // posted all six since it shipped and every one was rejected here,
            // so sameAs could never be populated from the admin.
            'social_facebook', 'social_instagram', 'social_tiktok',
            'social_pinterest', 'social_linkedin', 'social_youtube',
            // Remaining verification tokens. google_ and bing_ were listed;
            // these two were not, for no reason anyone recorded.
            'pinterest_site_verification', 'baidu_site_verification',
            // Crawling and discovery toggles.
            'indexnow_on', 'llms_enabled', 'crawl_clean',
            // Brand directory display mode: auto | logos | names. Read by
            // BrandController and rendered by store/brands.blade.php.
            'brands_display',
            // Google Merchant listing block.
            'enable_merchant', 'merchant_condition', 'merchant_ship_country',
            'merchant_ship_cost', 'merchant_ship_free_over', 'merchant_return_days',
        ];

        $incoming = $request->input('settings', []);
        if (!is_array($incoming)) return response()->json(['error' => 'invalid'], 422);

        $saved = 0;
        $rejected = [];

        // Through SettingsService::set() rather than Setting::updateOrCreate().
        // all() is a rememberForever cache and Setting::map() keeps a second
        // one; writing the row directly left both holding the old value, so
        // every setting on this screen -- COD fee, VAT rate, the free-shipping
        // threshold -- reached the database and was then ignored by the
        // storefront until something else happened to flush them. set() clears
        // both caches.
        $settings = app(\App\Services\SettingsService::class);

        foreach ($incoming as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                $rejected[] = $key;
                continue;
            }

            $settings->set($key, (string) $value);
            $saved++;
        }

        // Reported rather than swallowed: a silent skip is how an unlisted key
        // can look saved for days.
        return response()->json(array_filter([
            'ok' => true,
            'saved' => $saved,
            'rejected' => $rejected ?: null,
        ], static fn ($v) => $v !== null));
    }

    /** GET /admin-api/reviews?status= — moderation list (all, or by status). */
    public function reviews(Request $request)
    {
        $q = \App\Models\Review::query();
        if ($request->filled('status')) $q->where('status', $request->query('status'));

        // Four of the fields below were reading columns that do not exist on
        // `reviews`: product_slug, author, body and likes. Eloquent returns
        // null for a missing attribute rather than raising, so the screen did
        // not error -- it just showed a blank author, blank text and no
        // product name against every review. The real columns are product_id
        // (a relation), author_name, content and helpful.
        $rows = $q->with('product:id,name,slug')->orderByDesc('id')->get()->map(function ($r) {
            return [
                'id'           => $r->id,
                'product_slug' => $r->product?->slug,
                'product'      => $r->product?->name ?? '—',
                'author'       => $r->author_name,
                'rating'       => (int) $r->rating,
                'title'        => $r->title,
                'body'         => $r->content,
                'verified'     => (bool) $r->verified,
                'likes'        => (int) $r->helpful,
                'reply'        => $r->reply,
                'status'       => $r->status,
                'created_at'   => $r->created_at,
            ];
        });

        $counts = [
            'all'      => \App\Models\Review::count(),
            'pending'  => \App\Models\Review::where('status', 'pending')->count(),
            'approved' => \App\Models\Review::where('status', 'approved')->count(),
            'rejected' => \App\Models\Review::where('status', 'rejected')->count(),
        ];

        return response()->json(['reviews' => $rows, 'counts' => $counts]);
    }

    /** PUT /admin-api/reviews/{id} — moderate one review (status and/or reply). */
    public function updateReview(Request $request, int $id)
    {
        $r = \App\Models\Review::find($id);
        if (!$r) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'status' => 'sometimes|string|in:pending,approved,rejected',
            'reply'  => 'sometimes|nullable|string|max:5000',
        ]);

        if (array_key_exists('status', $data)) {
            $r->status = $data['status'];
        }
        if (array_key_exists('reply', $data)) $r->reply = $data['reply'];
        $r->save();

        return response()->json(['ok' => true, 'id' => $r->id, 'status' => $r->status]);
    }

    /** POST /admin-api/reviews/bulk — approve / reject / delete many at once. */
    public function bulkReviews(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|string|in:approve,reject,delete',
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer',
        ]);

        $n = 0;
        foreach (\App\Models\Review::whereIn('id', $data['ids'])->get() as $r) {
            if ($data['action'] === 'delete') { $r->delete(); $n++; continue; }
            $r->status = $data['action'] === 'approve' ? 'approved' : 'rejected';
            $r->save(); $n++;
        }

        return response()->json(['ok' => true, 'affected' => $n]);
    }

    /** GET /admin-api/customers — customer list with order count + lifetime spend. */
    public function customers()
    {
        $agg = Order::select('customer_id', DB::raw('count(*) as n'), DB::raw('sum(total) as s'))
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $rows = Customer::orderByDesc('id')->get()->map(function (Customer $c) use ($agg) {
            $a = $agg->get($c->id);
            return [
                'id'         => $c->id,
                'name'       => $c->name,
                'email'      => $c->email,
                'phone'      => $c->phone,
                'emirate'    => $c->emirate,
                'orders'     => $a ? (int) $a->n : 0,
                'spent_aed'  => $a ? (int) round(($a->s ?? 0) / 100) : 0,
                'created_at' => $c->created_at,
            ];
        });

        return response()->json(['customers' => $rows]);
    }

    /** GET /admin-api/quiz-leads — skin-quiz submissions / leads. */
    public function quizLeads()
    {
        $decode = function ($v) {
            if (is_array($v)) return $v;
            if (is_string($v) && $v !== '') {
                $d = json_decode($v, true);
                if (is_array($d)) return $d;
                return array_values(array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== ''));
            }
            return [];
        };

        $rows = \App\Models\QuizSubmission::orderByDesc('id')->get()->map(function ($q) use ($decode) {
            $concerns = $decode($q->concerns);
            $routines = $decode($q->recommended_routines);
            return [
                'id'          => $q->id,
                'name'        => $q->name,
                'email'       => $q->email,
                'phone'       => $q->phone,
                'skin_type'   => $q->skin_type,
                'concerns'    => $concerns,
                'recommended' => array_map(fn ($r) => is_array($r) ? ($r['name'] ?? ($r['title'] ?? '')) : $r, $routines),
                'expert'      => (bool) $q->expert_requested,
                'status'      => $q->status ?: 'new',
                'created_at'  => $q->created_at,
            ];
        });

        return response()->json(['leads' => $rows]);
    }

    /** PUT /admin-api/orders/{id}/status — change an order's status. */
    public function updateOrderStatus(Request $request, int $id)
    {
        $o = Order::find($id);
        if (!$o) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'status' => 'required|string|in:draft,pending,processing,onhold,shipped,completed,cancelled,refunded,failed',
        ]);

        $o->status = $data['status'];
        $o->updated_at = now()->toISOString();
        $o->save();

        return response()->json(['ok' => true, 'id' => $o->id, 'status' => $o->status]);
    }
}
