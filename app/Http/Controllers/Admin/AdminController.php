<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\DemoSeed;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store back-office API. Session-guarded (auth:admin) and mounted in web.php,
 * NOT api.php — the stateless API guard can't see the admin web session.
 *
 * MONEY IS INTEGER FILS. It is stored that way, it stays that way through every
 * calculation here, and it is converted once at the edge of the response. The
 * admin UI works in major units (AED), so a value arriving from a form is a
 * decimal STRING parsed by integer arithmetic — never `$aed * 100`, which is
 * 114 for 1.15 because binary floating point cannot hold 1.15. This file used
 * to say "the admin UI works in whole AED", and that was the bug rather than
 * the design: the schema has always stored fils and the importer has always
 * parsed AED 99.50 into 9950.
 */
class AdminController extends Controller
{
    /*
     * The one summary helper, not a fourth variant of it. `selectRaw()` APPENDS
     * to the select list rather than replacing it, which is the MySQL 1140 that
     * has reached production twice; see the trait's own comment for the three
     * distinct ways the hand-rolled form breaks.
     */
    use \App\Support\AggregatesQueries;

    // Kept here as an alias so nothing else in this file needs touching —
    // the actual definition now lives on Order::REAL_STATUSES, shared with
    // Catalog → Reorder / Products' order-count. See that constant's own
    // comment for why.
    private const REVENUE_STATUSES = \App\Models\Order::REAL_STATUSES;

    /**
     * `products.status`, read out of the schema rather than guessed.
     *
     * Declared in 0001_01_01_000000_create_kbb_schema.php as
     * `publish | draft | private`, and Product::scopeVisible() — which every
     * category page, the shop listing and the sitemap go through — is
     * `status = 'publish'`. This validator used to accept `active`, `draft`,
     * `archived`; two of those three are values nothing in this application has
     * ever recognised. CatalogProductsApiController::SETTABLE_STATUSES is the
     * same three, from the same reading.
     */
    private const PRODUCT_STATUSES = ['publish', 'draft', 'private'];

    /**
     * Columns the product editor may not write, whatever it sends.
     *
     * `slug` and `wc_id` are identity: /product/{slug}/ and `?add-to-cart={wc_id}`
     * are live URL contracts with links in the wild, so editing either breaks
     * addresses that already exist. `total_sales`, `rating` and `review_count`
     * are computed from orders and reviews — a figure that can be typed over is
     * a figure that means nothing.
     */
    private const PRODUCT_READONLY_FIELDS = ['slug', 'wc_id', 'total_sales', 'rating', 'review_count'];

    /**
     * A money field arriving from the admin: a decimal string in major units.
     *
     * A STRING, deliberately. `integer` was the old rule and it made AED 99.50
     * unenterable; `numeric` would let a float through and put the rounding
     * back. Up to four decimal places are accepted and truncated to the
     * currency's exponent in filsFromMajor(), so a paste from a spreadsheet
     * with trailing zeros is not a 422. Same rule as
     * CatalogProductsApiController::MONEY_RULE.
     */
    private const MONEY_RULE = 'regex:/^\d{1,9}(\.\d{1,4})?$/';

    /**
     * Fils handed back against orders that still count as revenue.
     *
     * WHY THIS EXISTS. PaymentRefunder moves an order to 'refunded' only once
     * the refunds cover the whole captured amount — see the comment above the
     * status update in PaymentRefunder::settle(), which says so in as many
     * words. A PARTIAL refund deliberately leaves the order 'completed', so it
     * stays inside Order::REAL_STATUSES and its FULL total kept counting as
     * revenue on the dashboard, in Analytics and in the average order value.
     * AED 400 handed back to a customer was still revenue for ever.
     *
     * WHICH REFUND ROWS. PaymentRefunder::COUNTED — settled, or in flight and
     * reserved. Not the failed attempts, which are recorded precisely so the
     * merchant can see that no money moved. This is the same definition the
     * order detail screen's `refunded_total_aed` uses, so the two screens
     * cannot tell the owner two different things about the same order.
     *
     * DELETED ORDERS. Built from the `refunds` table, so Order's SoftDeletes
     * global scope does not apply — whereNull('orders.deleted_at') is doing
     * real work here, exactly as it is on the top-products join below.
     *
     * DEMO ORDERS. Excluded on the same terms as the gross figure they are
     * netted against. Netting real refunds out of a gross that included
     * invented orders — or the reverse — would produce a number that is
     * neither, and the mismatch would be invisible.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private static function countedRefunds()
    {
        $q = \Illuminate\Support\Facades\DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->whereIn('refunds.status', \App\Services\Payments\PaymentRefunder::COUNTED);

        return DemoSeed::excludeQuery($q, Order::class, 'orders.id');
    }

    /**
     * GET /admin-api/stats — dashboard KPIs + recent orders.
     *
     * TWO MORE THINGS WERE WRONG HERE, on top of the refund netting above.
     *
     * 1. DEMO ORDERS COUNTED AS REAL MONEY. Store -> Demo Content seeds eight
     *    sample orders and logs each one in `demo_seed_log`; nothing here knew
     *    that log existed, so switching demo content on added invented revenue
     *    to every tile and switching it off took it away, with nothing on the
     *    screen saying so. Every count below now excludes them — and the
     *    response SAYS how many it left out, because an owner who seeded demo
     *    data on purpose is owed an explanation for why the dashboard did not
     *    move. See App\Support\DemoSeed.
     *
     * 2. "THE LAST 30 DAYS" WAS 30 UTC DAYS. The shop is in Dubai. The window
     *    now opens at MIDNIGHT on the shop's own clock 30 days ago, so its
     *    oldest day is a whole day and the figure does not creep between
     *    refreshes. There is a `today` block computed the same way, because the
     *    owner's "today" and a UTC "today" are four hours apart.
     *    See App\Support\StoreTime.
     *
     * The bound stays a Carbon rather than an ISO-8601 string, for the reason
     * the previous lane recorded here and found the hard way: SQLite compares
     * such a string as TEXT and drops the boundary day, MySQL warns 1292. Both
     * bounds below are CarbonImmutable in UTC, derived from shop-local
     * midnight — never a shop-local date string against a UTC column, which is
     * the exact mismatch the timezone work is about.
     */
    public function stats()
    {
        $paidReal = fn () => DemoSeed::exclude(
            Order::query()->whereIn('status', self::REVENUE_STATUSES),
            Order::class,
        );

        $since = StoreTime::windowStartUtc(30);

        $grossRevenue30 = (int) $paidReal()
            ->where('created_at', '>=', $since)
            ->sum('total');

        // Netted against the same window, so the dashboard and Analytics cannot
        // disagree about what the store actually took. See countedRefunds().
        $refunds30 = (int) self::countedRefunds()
            ->where('orders.created_at', '>=', $since)
            ->sum('refunds.amount');

        $revenue30 = max(0, $grossRevenue30 - $refunds30);

        /*
         * Today, on the shop's clock, as one bounded aggregate rather than two
         * round trips. Netted the same way as the 30-day figure beside it — a
         * tile that counted a refunded sale as today's revenue would be the
         * same defect the window above was just fixed for.
         */
        $dayStart = StoreTime::startOfDayUtc();
        $dayEnd   = $dayStart->addDay();

        $todayRow = $this->aggregate(
            $paidReal()->where('created_at', '>=', $dayStart)->where('created_at', '<', $dayEnd),
            'COUNT(*) as n, COALESCE(SUM(total), 0) as revenue',
        );

        $todayRefunds = (int) self::countedRefunds()
            ->where('orders.created_at', '>=', $dayStart)
            ->where('orders.created_at', '<', $dayEnd)
            ->sum('refunds.amount');

        $ordersTotal   = DemoSeed::exclude(Order::query(), Order::class)->count();
        $customers     = DemoSeed::exclude(Customer::query(), Customer::class)->count();
        $products      = DemoSeed::exclude(Product::query(), Product::class)->count();
        $lowStock      = DemoSeed::exclude(Product::query(), Product::class)->whereNotNull('stock')->where('stock', '>', 0)->where('stock', '<=', 15)->count();
        $outStock      = DemoSeed::exclude(Product::query(), Product::class)->whereNotNull('stock')->where('stock', '=', 0)->count();

        /*
         * NOT a conversion rate, and no longer labelled as one.
         *
         * This is paid orders / all orders — the share of orders that reached a
         * real status rather than being cancelled, failed or left as a draft.
         * Conversion is orders per SESSION and nothing in this application
         * tracks sessions, so a "Conversion 87%" tile was telling the owner
         * that 87% of visitors bought something. The screen now calls it what
         * it is: how many orders completed.
         */
        $paid = $paidReal()->count();

        /*
         * Eager-loaded. `Customer::find($o->customer_id)` inside the map was one
         * extra SELECT per recent order — bounded at eight here, unbounded in
         * orders() below, which is the same mistake without the limit. Both are
         * now one query for the whole set.
         *
         * `created_at` goes out as a shop-local ISO-8601 string WITH its offset
         * rather than as a bare Carbon. The feed does `.slice(0, 10)` on this
         * value, and Carbon's default serialisation is a UTC `...Z`, so an
         * order placed at 01:30 Dubai showed the previous day's date. Carrying
         * the offset makes both slicing it and `new Date()` correct.
         */
        $recent = DemoSeed::exclude(Order::query(), Order::class)
            ->with('customer:id,name')->orderByDesc('id')->limit(8)->get()->map(function (Order $o) {
                $c = $o->customer;
                return [
                    'id'         => $o->id,
                    'customer'   => $c->name ?? 'Guest',
                    'total_aed'  => (int) round(($o->total ?? 0) / 100),
                    'status'     => $o->status,
                    'created_at' => StoreTime::iso($o->created_at),
                ];
            });

        return response()->json([
            'revenue_30d_aed' => (int) round($revenue30 / 100),
            // Reported, not merely subtracted: a figure that silently shrank
            // would be as hard to trust as one that silently did not.
            'gross_revenue_30d_aed' => (int) round($grossRevenue30 / 100),
            'refunds_30d_aed' => (int) round($refunds30 / 100),
            'orders'          => $ordersTotal,
            'customers'       => $customers,
            'products'        => $products,
            'paid_orders'     => $paid,
            'low_stock'       => $lowStock,
            'out_of_stock'    => $outStock,
            'recent'          => $recent,
            'timezone'        => StoreTime::zone(),
            'today'           => [
                'date'        => StoreTime::today()->format('Y-m-d'),
                'orders'      => (int) ($todayRow->n ?? 0),
                'revenue_aed' => (int) round(max(0, ((int) ($todayRow->revenue ?? 0)) - $todayRefunds) / 100),
            ],
            'demo'            => DemoSeed::disclosure(),
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
            /*
             * Exact major units, plus the integer beside them.
             *
             * `(int) round($p->price / 100)` rounded a real price to the
             * nearest dirham on the way out of the database. The product
             * editor reads THIS list to fill its price input, so a product
             * stored at 9950 fils was shown as 100 and saved back as 10000 —
             * the row got 50 fils more expensive every time somebody opened it
             * and pressed Update without touching the price. Same pair of keys
             * /admin-api/catalog/products answers with.
             */
            'price_aed'  => $p->price === null ? null : Money::toMajor((int) $p->price),
            'price_fils' => $p->price === null ? null : (int) $p->price,
            'sale_aed'   => $p->sale_price === null ? null : Money::toMajor((int) $p->sale_price),
            'sale_fils'  => $p->sale_price === null ? null : (int) $p->sale_price,
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

    /**
     * GET /admin-api/products/{id} — full editable record for the product editor.
     *
     * `brand` and `category` were returned as `$p->brand` and `$p->category`.
     * Neither is a column: both are belongsTo RELATIONS (brand_id /
     * category_id), so Eloquent lazy-loaded them and serialised the whole
     * related model into the response. The editor, which wants a name to put in
     * a <select>, was handed
     * `{"id":9,"slug":"anua","name":"Anua","logo":null,...,"source_term_id":null}`
     * — every column of `brands`, including the importer's own bookkeeping —
     * and two extra queries per call to build it. The name is what this screen
     * reads, so the name is what it gets, with the id beside it for anything
     * that wants to write the field back.
     *
     * MONEY. `(int) round($p->price / 100)` rounded a real price to the nearest
     * dirham on the way OUT. A product stored at 9950 fils (AED 99.50) was
     * shown to the operator as 100, and saving the form back without touching
     * the price wrote 10000 — a silent 50-fil rise every time anybody opened
     * the record. `price_aed` is now the exact major-unit value and
     * `price_fils` the integer beside it, which is the pair
     * /admin-api/catalog/products already answers with.
     */
    public function getProduct(int $id)
    {
        // Eager-loaded, and only the two columns this response reads. Without
        // it `brand` and `category` are two more SELECTs per call.
        $p = Product::with(['brand:id,name', 'category:id,name'])->find($id);
        if (!$p) return response()->json(['error' => 'not_found'], 404);

        return response()->json([
            'id'                => $p->id,
            'name'              => $p->name,
            'brand'             => $p->brand?->name,
            'brand_id'          => $p->brand_id,
            'category'          => $p->category?->name,
            'category_id'       => $p->category_id,
            'sku'               => $p->sku,
            'description'       => $p->description,
            'short_description' => $p->short_description,
            'price_aed'         => $p->price === null ? null : Money::toMajor((int) $p->price),
            'price_fils'        => $p->price === null ? null : (int) $p->price,
            'sale_aed'          => $p->sale_price === null ? null : Money::toMajor((int) $p->sale_price),
            'sale_fils'         => $p->sale_price === null ? null : (int) $p->sale_price,
            'stock'             => $p->stock,
            'status'            => $p->status,
            'slug'              => $p->slug,
            /*
             * `seo`, the json column — NOT `seo_json`.
             *
             * This used to read `seo_json`, and the note here used to say that
             * was the column "this editor has always read and written" and that
             * a sibling lane believing it was `seo` would have written
             * "somewhere nothing reads". Half of that was right. The editor did
             * read and write `seo_json` — and nothing else in the application
             * ever did, in either direction. The readers are all on `seo`:
             *
             *   Store\ProductController::show()      builds the actual <head>
             *   Admin\SchemaInspectorApiController   the JSON-LD preview
             *   Admin\CatalogueAuditApiController    the SEO health report
             *
             * So `seo_json` was a closed loop between these two methods: the
             * operator typed a meta title, this endpoint handed it straight
             * back, the screen looked correct, and Google was never told. The
             * feature had never once worked in production.
             *
             * 2026_10_05_000001 moves the stored blobs across and renames the
             * two keys that also differed (seo_title -> title,
             * meta_description -> desc), so an operator's existing work follows
             * them to the column that publishes it.
             */
            'seo'               => is_array($p->seo) ? $p->seo : null,
        ]);
    }

    /**
     * PUT /admin-api/products/{id} — persist edits from the product editor.
     *
     * FOUR THINGS WERE WRONG HERE. Three of them answered 200.
     *
     * 1. THE STATUS VOCABULARY, and this one was live data loss.
     *    `products.status` is `publish | draft | private` — declared in the
     *    Phase 0 schema, mapped onto by the importer, and filtered on by
     *    Product::scopeVisible(), which is `status = 'publish'`. This validator
     *    accepted `active`, `draft`, `archived`. `active` was the only
     *    non-draft value it took, so the one thing an operator could do to
     *    publish a product wrote a value nothing in the application recognises:
     *    the row vanished from the storefront, from every category page and
     *    from the sitemap, and the endpoint answered {"ok":true}. `archived`
     *    does not exist either. The set is now read off the schema.
     *
     *    `active` is REFUSED rather than quietly mapped to `publish`. A caller
     *    sending it is working from the wrong vocabulary and should be told,
     *    and a 422 is strictly better than the silent delisting it used to get.
     *
     * 2. BRAND AND CATEGORY WERE WRITTEN AS COLUMNS. `$product->brand = 'Anua'`
     *    does not touch the relation — setAttribute() has no idea `brand` is
     *    one — it puts 'Anua' in the attribute bag, and save() then issues
     *    `UPDATE products SET brand = ?`. There is no such column, so this was
     *    SQLSTATE 42S22 and an HTTP 500. Not theoretical: the product editor in
     *    resources/views/admin/app.blade.php sends `brand` on every save the
     *    moment the Brands box is on screen, and its catch shows the operator
     *    "Save failed — check connection" while their edits are dropped.
     *
     *    THE FIX, and why this one rather than the other one: the rest of this
     *    admin writes brands and categories as foreign keys validated with
     *    `exists:` — CatalogProductsApiController::update() takes `brand_id`
     *    and `category_id` and nothing else. So that is the canonical form here
     *    too. The NAME form is still accepted, because the live editor sends it
     *    and its <select> is populated from this controller's own /admin-api/products
     *    rollup, i.e. from real rows — but it is RESOLVED against `brands` /
     *    `categories` and an unknown name is refused. It never creates a brand
     *    or a category: a product save is not the place to grow the catalogue's
     *    taxonomy, and a typo that silently mints "Anuaa" is how a brand list
     *    stops being a brand list.
     *
     * 3. MONEY COULD NOT BE ENTERED, AND WAS MULTIPLIED AS A FLOAT.
     *    `price_aed` was validated `integer`, so AED 99.50 was a 422 — the
     *    operator could not type it at all — and the value that did get through
     *    was multiplied by 100 in PHP. Money in this schema is integer fils and
     *    the importer has always parsed AED 99.50 into 9950 by integer
     *    arithmetic (App\Services\Import\Money documents why), so a
     *    whole-dirham admin was never the schema's restriction: it was this
     *    validator's. Prices now arrive as decimal STRINGS and are parsed
     *    digit-by-digit, never through a float. `(int) (1.15 * 100)` is 114.
     *
     * 4. A SALE PRICE AT OR ABOVE THE REGULAR PRICE was accepted. It is not a
     *    discount, Product::isOnSale() answers false, and the screen shows a
     *    struck-through price that never applies. Refused, on the integers.
     *
     * NOT WRITABLE, at any price: `slug`, `wc_id`, `total_sales`, `rating`,
     * `review_count`. The first two are live URL and contract surfaces —
     * /product/{slug}/ and `?add-to-cart={wc_id}` links exist in the wild — and
     * the last three are computed from orders and reviews. Sending one is a 422
     * rather than a silent drop, so a caller finds out.
     */
    public function updateProduct(Request $request, int $id)
    {
        $product = Product::find($id);
        if (!$product) return response()->json(['error' => 'not_found'], 404);

        /*
         * Refused loudly rather than ignored. `$request->validate()` drops
         * unlisted keys, so a caller sending `slug` or `total_sales` would get
         * {"ok":true} and no change — indistinguishable from a write that
         * worked, which is how a caller keeps sending it for months.
         */
        $forbidden = array_values(array_intersect(self::PRODUCT_READONLY_FIELDS, array_keys($request->all())));

        if ($forbidden !== []) {
            return response()->json([
                'error'   => 'read_only',
                'message' => 'These fields are identity or computed columns and cannot be edited here: ' . implode(', ', $forbidden) . '.',
                'errors'  => array_fill_keys($forbidden, ['Not editable.']),
            ], 422);
        }

        $data = $request->validate([
            'name'              => 'sometimes|nullable|string|max:255',
            // Either form. The id is canonical; the name is what the live
            // editor sends and is resolved below.
            'brand'             => 'sometimes|nullable|string|max:255',
            'brand_id'          => 'sometimes|nullable|integer|exists:brands,id',
            'category'          => 'sometimes|nullable|string|max:255',
            'category_id'       => 'sometimes|nullable|integer|exists:categories,id',
            'sku'               => 'sometimes|nullable|string|max:255',
            'description'       => 'sometimes|nullable|string|max:20000',
            'short_description' => 'sometimes|nullable|string|max:5000',
            'stock'             => 'sometimes|nullable|integer|min:0|max:1000000',
            // Decimal strings in major units, parsed by integer arithmetic.
            'price_aed'         => ['sometimes', 'nullable', self::MONEY_RULE],
            'sale_aed'          => ['sometimes', 'nullable', self::MONEY_RULE],
            'status'            => 'sometimes|string|in:' . implode(',', self::PRODUCT_STATUSES),
            'seo'               => 'sometimes|array',
        ]);

        $changes = [];

        foreach (['name', 'sku', 'description', 'short_description', 'status'] as $key) {
            if (array_key_exists($key, $data)) $changes[$key] = $data[$key];
        }

        if (array_key_exists('stock', $data)) {
            $changes['stock'] = $data['stock'] === null ? null : (int) $data['stock'];
        }

        // The id form wins where both are sent: it is unambiguous and the name
        // is only ever a lookup for it.
        if (array_key_exists('brand', $data)) {
            $resolved = $this->resolveTaxonomyId(\App\Models\Brand::class, $data['brand']);
            if ($resolved === false) {
                return $this->unknownTaxonomy('brand', $data['brand']);
            }
            $changes['brand_id'] = $resolved;
        }

        if (array_key_exists('brand_id', $data)) {
            $changes['brand_id'] = $data['brand_id'] === null ? null : (int) $data['brand_id'];
        }

        if (array_key_exists('category', $data)) {
            $resolved = $this->resolveTaxonomyId(\App\Models\Category::class, $data['category']);
            if ($resolved === false) {
                return $this->unknownTaxonomy('category', $data['category']);
            }
            $changes['category_id'] = $resolved;
        }

        if (array_key_exists('category_id', $data)) {
            $changes['category_id'] = $data['category_id'] === null ? null : (int) $data['category_id'];
        }

        foreach (['price_aed' => 'price', 'sale_aed' => 'sale_price'] as $field => $column) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $fils = $this->filsFromMajor($data[$field]);

            /*
             * The column's real ceiling, checked here rather than discovered
             * from the database.
             *
             * `products.price` and `sale_price` are `$t->integer(...)`, i.e.
             * signed 32-bit, so anything past 2,147,483,647 fils (AED
             * 21,474,836.47) does not fit. MySQL in strict mode raises on the
             * insert; SQLite stores it happily — so without this the suite
             * stays green and production 500s, which is the parity gap this
             * repo keeps paying for. The old `max:10000000` rule was on the AED
             * value and let AED 10,000,000 (1,000,000,000 fils) through, which
             * is already half the column again.
             */
            if ($fils !== null && $fils > \App\Services\Import\Money::MAX_FILS) {
                return response()->json([
                    'error'   => 'money_out_of_range',
                    'message' => 'That is more than a price column can hold (maximum '
                        . Money::plain(\App\Services\Import\Money::MAX_FILS) . ').',
                    'errors'  => [$field => ['Too large.']],
                ], 422);
            }

            $changes[$column] = $fils;
        }

        if (array_key_exists('seo', $data)) {
            /*
             * `seo`, not `seo_json` — see the long note in getProduct() for why
             * the previous arrangement round-tripped perfectly and published
             * nothing.
             *
             * The key names are normalised on the way in as well. This editor's
             * SEO panel collects Yoast-shaped names and the storefront reads
             * `title` and `desc`, so a payload pointed at the right column
             * would still have published nothing without the rename. The same
             * two renames are applied by the data migration, and
             * App\Support\ProductSeo owns the mapping so the two cannot drift.
             */
            $changes['seo'] = \App\Support\ProductSeo::normalise($data['seo']);
        }

        /*
         * Compared on the integers, against whatever the row will hold after
         * this write — so sending only a sale price is checked against the
         * stored regular price rather than against nothing.
         */
        $nextPrice = array_key_exists('price', $changes)
            ? $changes['price']
            : ($product->price === null ? null : (int) $product->price);

        $nextSale = array_key_exists('sale_price', $changes)
            ? $changes['sale_price']
            : ($product->sale_price === null ? null : (int) $product->sale_price);

        if ($nextSale !== null) {
            if ($nextPrice === null) {
                return response()->json([
                    'error'   => 'sale_without_price',
                    'message' => 'A sale price needs a regular price to be a discount from.',
                    'errors'  => ['sale_aed' => ['Set a regular price first.']],
                ], 422);
            }

            if ($nextSale >= $nextPrice) {
                return response()->json([
                    'error'   => 'sale_not_a_discount',
                    'message' => 'The sale price has to be below the regular price.',
                    'errors'  => ['sale_aed' => ['Regular price is ' . Money::plain($nextPrice) . '.']],
                ], 422);
            }
        }

        if ($changes !== []) {
            // Only the columns assembled above. Product::$guarded is empty, so
            // fill() with anything wider than this would be mass assignment
            // over the whole table.
            $product->fill($changes)->save();
        }

        return response()->json(['ok' => true, 'id' => $product->id]);
    }

    /**
     * A brand or category NAME -> its id.
     *
     * Returns null for an empty value (clearing the field), the id for a match,
     * and false for a name that is not in the table — which the caller turns
     * into a 422. Never creates the row: see the note in updateProduct().
     *
     * Matched case-insensitively on the name, then on the slug, because the
     * editor's <select> carries display names ("Beauty of Joseon") while some
     * callers have the slug.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function resolveTaxonomyId(string $model, ?string $name): int|false|null
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        // Lowered on both sides rather than relying on the column's collation:
        // MySQL's utf8mb4_unicode_ci matches case-insensitively and SQLite's
        // default BINARY collation does not, and a lookup that behaves
        // differently on the two engines is the parity gap this repo keeps
        // paying for.
        $id = $model::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');

        if ($id === null) {
            $id = $model::query()
                ->whereRaw('LOWER(slug) = ?', [mb_strtolower($name)])
                ->value('id');
        }

        return $id === null ? false : (int) $id;
    }

    /** The 422 for a brand or category name that is not in the table. */
    private function unknownTaxonomy(string $field, ?string $name)
    {
        return response()->json([
            'error'   => 'unknown_' . $field,
            'message' => 'No ' . $field . ' called "' . trim((string) $name) . '". Create it in Catalog → '
                . ($field === 'brand' ? 'Brands' : 'Categories') . ' first.',
            'errors'  => [$field => ['Not a known ' . $field . '.']],
        ], 422);
    }

    /**
     * A decimal string in major units -> exact fils, by integer arithmetic.
     *
     * Never `(int) round($major * 100)`. A binary float cannot hold 1.15, so
     * `(int) (1.15 * 100)` is 114, and App\Support\Money::fromMajor() — which
     * is that expression — is deliberately not used here. The string is split
     * on the decimal point and the two halves are combined with integer
     * multiplication and addition, so 99.50 is 99 * 100 + 50 by construction.
     *
     * Digits past the currency's exponent are truncated rather than rounded:
     * the operator typed more precision than the currency has, and rounding the
     * last digit up is a price they did not ask for. Same rule, and the same
     * reasoning, as CatalogProductsApiController::filsFromMajor().
     */
    private function filsFromMajor(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $parts = explode('.', $text, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = $parts[1] ?? '';

        $exponent = Money::minorExponent();

        $fraction = substr(str_pad($fraction, $exponent, '0'), 0, max(0, $exponent));

        return ((int) $whole) * (10 ** $exponent) + ($fraction === '' ? 0 : (int) $fraction);
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

        /*
         * A LIST, so demo orders stay on it — that is what Demo Content is for
         * — but each one says what it is. The money FIGURES on the Dashboard
         * and Analytics leave them out; see App\Support\DemoSeed for why the
         * answer comes from `demo_seed_log` rather than a column on `orders`.
         */
        $demoOrderIds = DemoSeed::idsFor(Order::class);

        $rows = $orders->map(function (Order $o) use ($lineCounts, $demoOrderIds) {
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
                'is_demo'    => isset($demoOrderIds[$o->id]),
                /*
                 * Shop-local ISO-8601, offset included. This was a bare Carbon,
                 * which serialises as '2026-09-15T21:30:00.000000Z' — and the
                 * screen slices the first ten characters off it, so an order
                 * placed at 01:30 in Dubai was dated the previous day on the
                 * Orders table, on its row in Recent activity, and nowhere did
                 * anything say the date was a UTC one.
                 */
                'created_at' => StoreTime::iso($o->created_at),
            ];
        });

        return response()->json([
            'orders'   => $rows,
            'timezone' => StoreTime::zone(),
            'demo'     => DemoSeed::disclosure(),
        ]);
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
            /*
             * The address comes off the ORDER, not off the customer.
             *
             * `$c->emirate` and `$c->default_address` were read here and there
             * are no such columns on `customers` — the emirate lives in
             * `addresses.state` and there is no default-address column at all.
             * Eloquent answers a missing attribute with null instead of
             * raising, so the order modal has shown a blank emirate and a blank
             * address on every order since it shipped, with nothing to say why.
             *
             * `orders.shipping_address` is the right source anyway: it is a
             * json snapshot of where THIS order went, so an order stays correct
             * after the customer edits their address book, and it costs no
             * extra query because the column is already on the row.
             */
            'customer'     => $c ? [
                'name'    => $c->name,
                'email'   => $c->email,
                'phone'   => $c->phone,
                'emirate' => $this->addressLine($o, 'state'),
                'address' => $this->formattedAddress($o),
            ] : null,
            'items'        => $items,
            'subtotal_aed' => (int) round(($o->subtotal ?? 0) / 100),
            'delivery_aed' => (int) round(($o->shipping_total ?? 0) / 100),
            'cod_fee_aed'  => (int) round(($o->fee_total ?? 0) / 100),
            'total_aed'    => (int) round(($o->total ?? 0) / 100),
            'ship_method'  => $o->shipping_method,
            // Shop-local, offset carried — the same treatment as the Orders
            // list this drawer opens from, so the two cannot show an order two
            // different dates.
            'created_at'   => StoreTime::iso($o->created_at),
        ]);
    }

    /**
     * One field out of an order's own address snapshot.
     *
     * `orders.shipping_address` and `billing_address` are json columns cast to
     * array on the model, holding the address as it was when the order was
     * placed. Shipping first, billing as the fallback: a digital or
     * collect-in-store order has only the latter.
     *
     * Note the key is `state`, which is what `addresses.state` is called and
     * which the Phase 0 schema comments as "// Emirate". There is no `emirate`
     * column anywhere in this database, which is exactly how the old read here
     * managed to be blank for years without erroring.
     */
    private function addressLine(Order $o, string $key): ?string
    {
        foreach ([$o->shipping_address, $o->billing_address] as $address) {
            if (! is_array($address)) {
                continue;
            }

            $value = trim((string) ($address[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** The order's delivery address on one line, or null when it has none. */
    private function formattedAddress(Order $o): ?string
    {
        $parts = array_filter(
            array_map(fn (string $key) => $this->addressLine($o, $key), ['line1', 'line2', 'city', 'state', 'postcode']),
            static fn (?string $v) => $v !== null && $v !== ''
        );

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * GET /admin-api/analytics — sales metrics derived from real orders, over a
     * date range the owner chooses.
     *
     * THE FILTER. `?period=all|today|week|month|year|custom` plus `from`/`to`
     * for a custom range. App\Support\AnalyticsRange owns every boundary, every
     * chart bucket and the single timezone hook; read its docblock before
     * changing anything about dates here. Nothing in this method works out a
     * date for itself, on purpose: seven figures on that screen have to agree
     * about what "this month" means, and the way they stop agreeing is one of
     * them computing its own window.
     *
     * EVERY BOX OBEYS IT. Net revenue, refunded, gross, average order, units
     * sold, orders, where the orders are, best sellers and the chart are all
     * narrowed by the same $range. A box that ignored the filter while its
     * neighbours honoured it would be worse than no filter, because the owner
     * would have no way to tell which of the two numbers they were reading.
     *
     * A REFUND COUNTS IN THE PERIOD OF ITS ORDER, not the period the money went
     * back in. The refund and the order carry different dates and only one of
     * them can be the answer. It is the order's, because:
     *
     *   - every other figure on the page is keyed on the order, so keying this
     *     one on the refund would make Net + Refunded stop equalling Gross, and
     *     make the average order value divide a revenue figure that does not
     *     belong to the orders it is divided by;
     *   - it cannot go negative. A quiet month holding a refund against a busy
     *     month's order would otherwise report revenue below zero and draw a bar
     *     under the axis;
     *   - it is what the chart has done since this screen was rebuilt, so
     *     nothing on the page has to change its mind.
     *
     * Read the Refunded box as "of what these orders brought in, this much went
     * back" — which is what the screen now says on it, in those words.
     *
     * TWO THINGS WERE WRONG HERE BEFORE ANY OF THAT, and only one was visible.
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
     * The series is no longer read row by row either. It is grouped in SQL by
     * the stored HOUR — `substr(created_at, 1, 13)`, which both engines
     * evaluate the same way, rather than DATE()/strftime(), and strftime() is
     * one of the exact statements Tests\Support\SqlShape rejects — and the
     * hours are then placed on the shop's own wall clock in PHP. That keeps the
     * statement count flat whatever the range, and leaves the timezone in one
     * place instead of inside a dialect-specific date expression.
     *
     * Money stays in integer fils until the one conversion at the end.
     */
    public function analytics(Request $request)
    {
        $filter = $request->validate([
            'period' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Support\AnalyticsRange::PERIODS)],
            // A custom range is refused rather than quietly answered as some
            // other period: a screen showing February under a heading that says
            // "3 - 5 Feb" is the failure this endpoint is trying to avoid.
            // The year bounds stop a pasted or fat-fingered date turning into a
            // chart with twenty thousand buckets in it.
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:2000-01-01', 'before_or_equal:2100-01-01'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:2000-01-01', 'before_or_equal:2100-01-01'],
        ]);

        $period = $filter['period'] ?? \App\Support\AnalyticsRange::DEFAULT_PERIOD;

        /*
         * "All time" has no boundaries, so the CHART still needs a span: the
         * first and last order the shop ever took. Asked for only when it is
         * needed, and as one aggregate rather than by reading any rows.
         */
        $earliest = null;
        $latest = null;

        if ($period === 'all') {
            /*
             * Demo-excluded like everything else below. Left in, the eight
             * orders Store -> Demo Content seeds would set the chart's span,
             * so "All time" would open on a date range invented by a preview
             * feature rather than on the shop's own trading history.
             */
            $edges = DemoSeed::exclude(
                Order::query()->whereIn('status', self::REVENUE_STATUSES),
                Order::class,
            )
                ->selectRaw('MIN(created_at) as first_at, MAX(created_at) as last_at')
                ->first();

            $earliest = $edges?->first_at ? (string) $edges->first_at : null;
            $latest = $edges?->last_at ? (string) $edges->last_at : null;
        }

        $range = \App\Support\AnalyticsRange::resolve(
            $period,
            $filter['from'] ?? null,
            $filter['to'] ?? null,
            $earliest,
            $latest,
        );

        /*
         * Every query below is built from one of these two, and every one of
         * them is narrowed by $range->apply(). Nothing here dates itself.
         *
         * DEMO ORDERS ARE NOT SALES, and the exclusion belongs here rather than
         * at each call site for the same reason the range does: a figure that
         * forgets it is not obviously wrong on screen, it is just quietly
         * larger. Store -> Demo Content seeds eight orders and logs each one in
         * `demo_seed_log`; every figure on this screen used to include them, so
         * switching demo content on inflated the revenue, the average order
         * value and the top-products table, and switching it off deflated them
         * again with nothing saying why. Excluded here and disclosed in the
         * payload below — see App\Support\DemoSeed.
         */
        $paidQuery = $range->apply(DemoSeed::exclude(
            Order::query()->whereIn('status', self::REVENUE_STATUSES),
            Order::class,
        ));
        $allOrders = $range->apply(DemoSeed::exclude(Order::query(), Order::class));

        $totals = (clone $paidQuery)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as revenue')
            ->first();

        $grossRevenue = (int) ($totals->revenue ?? 0);       // fils
        $paidCount    = (int) ($totals->n ?? 0);

        /*
         * Revenue is NET of refunds, and the average order value is computed
         * from the net figure. See countedRefunds() for why a partially
         * refunded order is still sitting in REAL_STATUSES with its full total.
         *
         * The range is applied to `orders.created_at`, NOT to the refund's own
         * created_at — the decision written out at the top of this method.
         *
         * max(0, ...) is belt and braces: PaymentRefunder refuses a refund that
         * would exceed what was captured, so the difference cannot go negative
         * through this application — but an imported Woo refund does not pass
         * through that check, and a negative "revenue" is a worse answer than a
         * clamped one.
         */
        $refundTotal  = (int) $range->apply(self::countedRefunds(), 'orders.created_at')->sum('refunds.amount');
        $revenueTotal = max(0, $grossRevenue - $refundTotal);
        // intdiv, not division: an empty period has no orders and dividing by
        // the count would be a 500 on a page that should read "nothing yet".
        $aov          = $paidCount ? intdiv($revenueTotal, $paidCount) : 0;
        $ordersTotal  = (int) (clone $allOrders)->selectRaw('COUNT(*) as n')->first()->n;

        /* ---------------------------------------------------------- the chart */

        /*
         * Grouped by the stored hour in SQL, then placed on the shop's wall
         * clock in PHP. Two statements whatever the range holds — a day, or
         * eight years — and no dialect-specific date function in either.
         *
         * Both the grouped expression and the aggregates are named in the
         * select, which is what MySQL's ONLY_FULL_GROUP_BY wants: the 1140 this
         * codebase has already paid for twice.
         */
        $hourExpr = 'substr(orders.created_at, 1, 13)';

        $grossByHour = (clone $paidQuery)
            ->from('orders')
            ->groupByRaw($hourExpr)
            ->selectRaw($hourExpr . ' as bucket_hour, COALESCE(SUM(orders.total), 0) as bucket_gross')
            ->get();

        /*
         * selectRaw()->get(), not pluck() with a raw value column: pluck() reads
         * the value off the result row by the STRING it was given, so a raw
         * aggregate expression becomes a property name and the query dies with
         * "Undefined property: stdClass::$amount), 0)".
         */
        $refundsByHour = $range->apply(self::countedRefunds(), 'orders.created_at')
            ->groupByRaw($hourExpr)
            ->selectRaw($hourExpr . ' as bucket_hour, COALESCE(SUM(refunds.amount), 0) as bucket_refunded')
            ->get();

        $buckets = $range->buckets();
        $fils = array_fill_keys(array_keys($buckets), 0);

        /*
         * array_key_exists rather than a bare `$fils[$key] +=`.
         *
         * A key the walk did not generate used to be a 500 — "Undefined array
         * key 2026-09-01" — which is how the month-overflow bug fixed in
         * AnalyticsRange::buckets() announced itself on a preview with four
         * years of orders in it. The bug is fixed at the source; this stops a
         * future one taking the whole screen down, and
         * AdminAnalyticsFilterTest's "the chart must add up to the KPI"
         * assertions are what would catch the money going missing instead.
         */
        foreach ($grossByHour as $row) {
            $key = $range->bucketKeyForStoredHour((string) $row->bucket_hour);
            if ($key !== null && array_key_exists($key, $fils)) $fils[$key] += (int) $row->bucket_gross;
        }

        foreach ($refundsByHour as $row) {
            $key = $range->bucketKeyForStoredHour((string) $row->bucket_hour);
            if ($key !== null && array_key_exists($key, $fils)) $fils[$key] -= (int) $row->bucket_refunded;
        }

        /*
         * A bar reads "what the store kept from the orders placed in this
         * bucket", which is the question an owner asks of a sales chart, and —
         * as a side effect — can never go below the axis.
         */
        $series = [];
        $seriesFils = 0;

        foreach ($buckets as $key => $bucket) {
            $net = max(0, $fils[$key]);
            $seriesFils += $net;
            $series[] = $bucket + ['revenue_aed' => (int) round($net / 100)];
        }

        /*
         * The chart's own scale, stated rather than implied.
         *
         * The bars were drawn against the tallest of them with no axis and no
         * number anywhere on the card, so fourteen days of AED 90 looked
         * exactly like fourteen days of AED 90,000 — the shape of a chart that
         * implies a trend that is not there. The screen prints the peak and the
         * total, and both of them name the period they belong to.
         */
        $peak = $series ? max(array_column($series, 'revenue_aed')) : 0;

        /*
         * Totalled in FILS and rounded once, NOT summed from the rounded bars.
         *
         * Rounding each bucket to whole AED and then adding them up drifts by a
         * dirham per bucket in the worst case: a year of weekly bars printed
         * "This year AED 148,177" beside a Net revenue KPI of AED 148,175, two
         * cards apart on the same screen. Two numbers that should be the same
         * number and are not is precisely the thing that makes an owner stop
         * trusting the page, and it is the reason this figure exists at all.
         */
        $seriesTotal = (int) round($seriesFils / 100);

        /* -------------------------------------------- where the orders are */

        // Every status present IN THE PERIOD, grouped by the database. All time
        // was the old behaviour and is still one of the options, but it is no
        // longer the only thing this table can say.
        $status = (clone $allOrders)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        /* -------------------------------------------------- best sellers */

        /*
         * Top products by revenue, from the paid orders' line items.
         *
         * Grouped by name and brand in SQL. Both are in the GROUP BY, not just
         * one — MySQL under ONLY_FULL_GROUP_BY rejects a bare `brand` beside an
         * aggregate, which is the same 1140 that took the Customers screen down.
         *
         * whereNull('orders.deleted_at') is not decoration. Order uses
         * SoftDeletes, so `Order::query()` carries a global scope that hides
         * trashed rows — but a raw join to `orders` from OrderItem does NOT,
         * because the scope belongs to the Order builder and this query is built
         * from OrderItem. Without it a trashed order's units and revenue would
         * reappear in these figures while the same order stayed out of
         * paid_orders and revenue_total above, and the two halves of the screen
         * would disagree with each other.
         *
         * The range is on `orders.created_at` for the same reason: a line is in
         * the period its ORDER is in.
         */
        /*
         * Both narrowings, and the order matters only in that neither may be
         * dropped: the range decides WHICH period this table covers, and the
         * demo exclusion decides whose units are real.
         *
         * DemoSeed::exclude() is told `orders` explicitly, not left to infer
         * the table from the model. This builder's model is OrderItem, so the
         * default would qualify the subquery against `order_items.id` and
         * suppress whichever LINE happened to share an id with a demo order —
         * quietly deleting real units from a real product's row. The demo
         * marker is on the ORDER, so the column compared has to be `orders.id`.
         * $range->apply() is told `orders.created_at` for the same reason: the
         * date being filtered is the order's, not the line's.
         */
        $itemQuery = $range->apply(
            DemoSeed::exclude(
                OrderItem::query()
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereNull('orders.deleted_at')
                    ->whereIn('orders.status', self::REVENUE_STATUSES),
                Order::class,
                'orders',
            ),
            'orders.created_at'
        );

        $unitsSold = (int) ((clone $itemQuery)
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->first()->units ?? 0);

        /*
         * Revenue per product is the LINE TOTAL, not the list price.
         *
         * SUM(unit_price * quantity) is what the line would have cost at full
         * price. `order_items.total` is what the line was actually charged at,
         * after any discount the pricing code applied — the column the invoice
         * and the order screen both read. A product only ever sold inside a
         * coupon or a bundle reported revenue the store never took, and the
         * Top products table could not be reconciled with the Revenue KPI at
         * the top of the same screen.
         */
        $top = (clone $itemQuery)
            ->groupBy('order_items.name', 'order_items.brand')
            ->selectRaw('order_items.name as name, order_items.brand as brand,'
                . ' COALESCE(SUM(order_items.quantity), 0) as units,'
                . ' COALESCE(SUM(order_items.total), 0) as revenue')
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
            // What the owner asked for, and what the screen prints on every
            // card: the period is part of the answer, not a setting beside it.
            'period'            => $range->toArray(),
            'bucket'            => $range->bucket(),
            'bucket_label'      => $range->bucketLabel(),

            'revenue_total_aed' => (int) round($revenueTotal / 100),
            'gross_revenue_aed' => (int) round($grossRevenue / 100),
            'refunds_total_aed' => (int) round($refundTotal / 100),
            'orders_total'      => $ordersTotal,
            'paid_orders'       => $paidCount,
            'aov_aed'           => (int) round($aov / 100),
            'units_sold'        => $unitsSold,
            'series'            => $series,
            'peak_aed'          => $peak,
            'period_total_aed'  => $seriesTotal,
            // Named rather than described in prose, so the screen can print the
            // list it actually sums and cannot drift out of step with it the
            // way the old "processing, on-hold and completed" copy had.
            'revenue_statuses'  => array_values(self::REVENUE_STATUSES),
            'status_breakdown'  => $status,
            'top_products'      => $top,
            'timezone'          => StoreTime::zone(),
            'demo'              => DemoSeed::disclosure(),
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

    /**
     * The editable settings, each with the rule its own consumer imposes.
     *
     * WHY PER-KEY AND NOT ONE BLANKET RULE. This used to be a flat list of key
     * names: if the key was on it, whatever string arrived was written. Nothing
     * downstream could recover from a bad one, because every consumer reads
     * these with a bare cast and `(int) 'abc'` is 0 — silently, and 0 is a
     * legal-looking value for all of them. A typo in the free-shipping
     * threshold therefore set it to zero, and `$subtotal >= 0` is true for
     * every basket that has ever existed, so the store shipped everything free
     * and said "Saved" while doing it. Same shape for the COD and gift fees,
     * which simply stopped being charged.
     *
     * The three money conventions here are genuinely different and one rule
     * could not have covered them:
     *
     *   fils  free_ship, delivery_flat, cod_fee, gift_fee — the screen
     *         multiplies by 100 before posting, so the wire value is an integer
     *         number of fils. A decimal is REFUSED rather than guessed at:
     *         "12.50" in a fils field is either AED 12.50 or 12 fils depending
     *         on who typed it, and the old `(int)` cast silently chose 12 —
     *         AED 0.12, a hundredfold under-charge that reads back as a
     *         plausible number on the screen afterwards.
     *   aed   merchant_ship_cost, merchant_ship_free_over — posted raw from a
     *         `step="0.01"` box and compared against price_aed by
     *         App\Support\Seo, so these ARE major units and a decimal is right.
     *   pct   vat_rate — a percentage, read as a float by VatDisplay.
     *
     * Every bound below comes from the consumer, not from taste: the money
     * ceiling is the 32-bit money column (Money::MAX_FILS), the enums are the
     * exact lists their readers match against, and keys whose column is
     * longText and whose reader imposes no shape are left as free text rather
     * than given a length limit nobody could justify.
     *
     * PUBLIC so that a test can ask what this endpoint accepts instead of
     * scraping the method body for quoted strings, which is how the currency
     * and SEO allowlist tests used to do it — they broke the moment the list
     * moved, while the behaviour they describe had not changed at all.
     *
     * @var array<string, array{0:string, 1:string, 2?:mixed}>
     */
    public const SETTING_RULES = [
        'store_name' => ['text', 'Store name'],

        /*
         * The shop's own wall clock, read by App\Support\StoreTime.
         *
         * A SETTING and not a constant, and deliberately NOT `APP_TIMEZONE`.
         * This value decides how stored instants are DISPLAYED — the
         * dashboard's "today", each bar of the 14-day chart, the date on an
         * invoice. It never changes what is stored, which stays UTC; flipping
         * `config('app.timezone')` instead would reinterpret every historical
         * timestamp in the store rather than converting it. StoreTime's header
         * has the full reasoning and a test pins the distinction.
         *
         * `tz` and not `enum`: a curated list would be one more place to be
         * out of date, and the tz database is the authoritative answer.
         */
        'store_timezone' => ['tz', 'Time zone'],

        // Currency display (Store -> Business Details -> Currency). Every one of
        // these has to be here or Save reports success and writes nothing.
        'currency' => ['code', 'Currency', 3],
        'currency_symbol' => ['text', 'Currency symbol'],
        'currency_position' => ['enum', 'Symbol position', \App\Support\Money::POSITIONS],
        'currency_decimals' => ['int', 'Currency decimals', [0, 4]],
        'currency_symbol_render' => ['enum', 'Symbol rendering', \App\Support\Money::RENDER_MODES],

        'vat_rate' => ['pct', 'VAT rate'],

        /*
         * The per-country VAT overrides — a JSON object of ISO code => per cent.
         *
         * A STRING, not an array, because checkSetting() refuses arrays and
         * objects outright: settings.value is text and a nested value is not a
         * setting. So the screen posts the map encoded, and 'ratemap' unpacks
         * and checks it key by key rather than trusting it wholesale.
         *
         * WITHOUT THIS LINE the key would be silently dropped from every
         * payload while updateSettings() still answered ok — the exact failure
         * the note at the top of this list describes, and the reason
         * tests/Feature/VatPerCountryTest.php asserts a reader sees the new
         * rate rather than asserting a 200 came back.
         *
         * vat_rate above is unchanged and remains the rate for every country
         * without an entry here: the owner's "all countries at once".
         */
        'vat_country_rates' => ['ratemap', 'Per-country VAT rates'],

        // Money, in fils. See the note above for why a decimal is refused.
        'free_ship' => ['fils', 'Free-shipping threshold'],
        'delivery_flat' => ['fils', 'Flat delivery charge'],
        'cod_fee' => ['fils', 'Cash-on-delivery fee'],

        'meta_pixel' => ['text', 'Meta Pixel ID'],
        'ga' => ['text', 'Google Analytics ID'],
        'google_site_verification' => ['text', 'Google verification token'],
        'bing_site_verification' => ['text', 'Bing verification token'],
        'seo_json' => ['text', 'Custom JSON-LD'],

        // Search appearance.
        'site_url' => ['text', 'Site URL'],
        'seo_site_name' => ['text', 'Site name'],
        'seo_separator' => ['text', 'Title separator'],
        'seo_title_template' => ['text', 'Title template'],
        'seo_home_title' => ['text', 'Home title'],
        'seo_home_description' => ['text', 'Home description'],
        'seo_default_description' => ['text', 'Default description'],

        // Emitted straight into <meta name="robots">, so the vocabulary is the
        // one the tag actually defines rather than a boolean.
        'robots_index' => ['enum', 'Search engines', ['index', 'noindex']],
        'robots_follow' => ['enum', 'Follow links', ['follow', 'nofollow']],

        // Social.
        'og_default_image' => ['text', 'Default share image'],
        'twitter_handle' => ['text', 'X / Twitter handle'],

        // Organization / schema.
        'org_name' => ['text', 'Organization name'],
        'org_logo' => ['text', 'Organization logo'],
        'org_type' => ['enum', 'Organization type', ['Organization', 'OnlineStore', 'Store', 'LocalBusiness']],

        // Sitemap / robots.
        'sitemap_enabled' => ['flag', 'XML sitemap'],
        'robots_txt' => ['text', 'robots.txt'],

        // Gift wrapping (Store -> Delivery & Shipping -> Gift wrapping).
        'gift_enabled' => ['flag', 'Gift wrapping'],
        'gift_fee' => ['fils', 'Gift-wrap fee'],

        /*
         * The per-country delivery line (Store -> Delivery & Shipping ->
         * Delivery lines), read by App\Support\DeliveryLine.
         *
         * `rows` and not `text`: this value is a list of
         * ['country' => 'XX', 'text' => '...'] and checkRows() is the only
         * thing in this file that will take one. Until this line existed the
         * key was not on this list at all, which — by the note at the top of
         * this constant — meant a Save that reported success and wrote
         * nothing. Nothing wrote it, so the escape hatch
         * CheckoutController::deliveryText() promised the owner for the Gulf
         * had no screen behind it.
         */
        'delivery_texts' => ['rows', 'Delivery lines by country'],

        // Social profiles, feeding schema.org sameAs.
        'social_facebook' => ['text', 'Facebook URL'],
        'social_instagram' => ['text', 'Instagram URL'],
        'social_tiktok' => ['text', 'TikTok URL'],
        'social_pinterest' => ['text', 'Pinterest URL'],
        'social_linkedin' => ['text', 'LinkedIn URL'],
        'social_youtube' => ['text', 'YouTube URL'],

        'pinterest_site_verification' => ['text', 'Pinterest verification token'],
        'baidu_site_verification' => ['text', 'Baidu verification token'],

        // Crawling and discovery toggles.
        'indexnow_on' => ['flag', 'Instant indexing'],
        'llms_enabled' => ['flag', 'Publish llms.txt'],
        'crawl_clean' => ['flag', 'Crawl-budget cleanup'],

        // Brand directory display mode, read by BrandController.
        'brands_display' => ['enum', 'Brand display', \App\Http\Controllers\Store\BrandController::DISPLAY_MODES],

        // Google Merchant listing block. The two money keys here are AED, not
        // fils — see the note above.
        'enable_merchant' => ['flag', 'Merchant listing'],
        'merchant_condition' => ['enum', 'Condition', ['NewCondition', 'UsedCondition', 'RefurbishedCondition']],
        'merchant_ship_country' => ['code', 'Ship-to country', 2],
        'merchant_ship_cost' => ['aed', 'Shipping cost'],
        'merchant_ship_free_over' => ['aed', 'Free shipping over'],
        'merchant_return_days' => ['int', 'Return window', [0, 3650]],
    ];

    /** PUT /admin-api/settings — upsert a whitelisted set of store settings. */
    public function updateSettings(Request $request)
    {
        $incoming = $request->input('settings', []);
        if (!is_array($incoming)) return response()->json(['error' => 'invalid'], 422);

        $rejected = [];
        $errors = [];
        $clean = [];

        /*
         * VALIDATE EVERYTHING FIRST, WRITE NOTHING UNTIL IT ALL PASSES.
         *
         * The screen posts a whole tab in one request. Writing as we went would
         * leave a rejected payload half-applied — some keys new, some old, and
         * a 422 on screen naming one field while the others landed anyway. That
         * is a worse state to be in than either outcome on its own.
         */
        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, self::SETTING_RULES)) {
                $rejected[] = $key;
                continue;
            }

            [$type, $label] = self::SETTING_RULES[$key];
            $extra = self::SETTING_RULES[$key][2] ?? null;

            $result = $this->checkSetting($type, $label, $value, $extra);

            if ($result['error'] !== null) {
                $errors[$key] = [$result['error']];
                continue;
            }

            $clean[$key] = $result['value'];
        }

        if ($errors !== []) {
            return response()->json([
                'ok' => false,
                'message' => 'Nothing was saved. ' . implode(' ', array_map(
                    static fn (array $m) => $m[0],
                    array_slice($errors, 0, 3)
                )),
                'errors' => $errors,
            ], 422);
        }

        $saved = 0;

        // Through SettingsService::set() rather than Setting::updateOrCreate().
        // all() is a rememberForever cache and Setting::map() keeps a second
        // one; writing the row directly left both holding the old value, so
        // every setting on this screen -- COD fee, VAT rate, the free-shipping
        // threshold -- reached the database and was then ignored by the
        // storefront until something else happened to flush them. set() clears
        // both caches.
        $settings = app(\App\Services\SettingsService::class);

        foreach ($clean as $key => $value) {
            $settings->set($key, $value);
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

    /**
     * Check one setting against its rule.
     *
     * Returns ['value' => string, 'error' => null] or ['value' => null,
     * 'error' => message]. A refusal is always better than a coerced value
     * here: the caller is a person looking at the field they just typed into,
     * and the alternative — the old behaviour — was to store something that
     * read back as zero and charge customers accordingly.
     *
     * The value is a string for every type but `rows`, which is the one shape
     * here that is genuinely a list — see checkRows().
     *
     * @return array{value: mixed, error: ?string}
     */
    private function checkSetting(string $type, string $label, mixed $raw, mixed $extra): array
    {
        $ok = static fn (string $v): array => ['value' => $v, 'error' => null];
        $no = static fn (string $m): array => ['value' => null, 'error' => $m];

        /*
         * `rows` is handled BEFORE the single-value guard below, because it is
         * the one type whose value legitimately is an array. Everything else
         * still falls through the guard: settings.value is text, and an array
         * arriving for a `text` or `fils` key is a malformed payload, not a
         * value to stringify.
         */
        if ($type === 'rows') {
            return $this->checkRows($label, $raw);
        }

        // Arrays and objects are not settings values; the column is text.
        if (is_array($raw) || is_object($raw)) {
            return $no("“{$label}” must be a single value.");
        }

        $value = trim((string) $raw);

        switch ($type) {
            case 'text':
                // settings.value is longText and these keys have no shape their
                // reader depends on, so no length rule is invented here. A wrong
                // limit blocks a legitimate value, which is worse than none.
                return $ok($value);

            case 'flag':
                return ($value === '0' || $value === '1')
                    ? $ok($value)
                    : $no("“{$label}” must be on or off.");

            case 'enum':
                return in_array($value, (array) $extra, true)
                    ? $ok($value)
                    : $no("“{$label}” must be one of: " . implode(', ', (array) $extra) . '.');

            case 'tz':
                /*
                 * An IANA timezone identifier, checked against the tz database
                 * itself rather than a list kept here. "Dubai" and "GMT+4" are
                 * both refused: the first is not an identifier, and the second
                 * is a fixed offset that would be wrong for half the year in
                 * any zone that observes DST. The message names the shape
                 * rather than listing four hundred identifiers at somebody who
                 * mistyped one.
                 */
                return StoreTime::isValidZone($value) && (str_contains($value, '/') || $value === 'UTC')
                    ? $ok($value)
                    : $no("“{$label}” must be a timezone name like Asia/Dubai.");

            case 'code':
                $len = (int) $extra;

                return preg_match('/^[A-Za-z]{' . $len . '}$/', $value) === 1
                    ? $ok(strtoupper($value))
                    : $no("“{$label}” must be {$len} letters.");

            case 'int':
                [$min, $max] = (array) $extra;

                if (preg_match('/^-?\d+$/', $value) !== 1) {
                    return $no("“{$label}” must be a whole number.");
                }

                $n = (int) $value;

                return ($n >= $min && $n <= $max)
                    ? $ok((string) $n)
                    : $no("“{$label}” must be between {$min} and {$max}.");

            case 'fils':
                /*
                 * A whole number of fils. A decimal is refused rather than
                 * truncated: the screen posts fils, so "12.50" here is somebody
                 * typing dirhams into a fils field, and the old `(int)` cast
                 * turned that into 12 fils — AED 0.12 instead of AED 12.50.
                 * The message says what to type instead rather than just "no".
                 */
                if (preg_match('/^\d+$/', $value) !== 1) {
                    if (preg_match('/^\d+\.\d{1,2}$/', $value) === 1) {
                        return $no("“{$label}” is in fils (AED x 100), so it must be a whole number — "
                            . 'for AED ' . $value . ' enter ' . $this->filsFromAedText($value) . '.');
                    }

                    return $no("“{$label}” must be a whole number of fils (AED x 100).");
                }

                // Length first, so the string cannot overflow a PHP int on the
                // way to the numeric comparison and wrap into a plausible value.
                if (strlen(ltrim($value, '0')) > 10
                    || (int) $value > \App\Services\Import\Money::MAX_FILS) {
                    return $no("“{$label}” is more than the money column can store "
                        . '(up to AED 21,474,836.47).');
                }

                return $ok((string) (int) $value);

            case 'aed':
                /*
                 * Major units, up to two decimals, parsed digit-by-digit so no
                 * float is ever constructed: 99.50 is 99 * 100 + 50 by
                 * construction. `(int) round($aed * 100)` would be a coin flip
                 * on values binary floating point cannot hold exactly.
                 */
                if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
                    return $no("“{$label}” must be an amount in AED, with at most two decimals.");
                }

                if (strlen(explode('.', $value)[0]) > 9
                    || $this->filsFromAedText($value) > \App\Services\Import\Money::MAX_FILS) {
                    return $no("“{$label}” is more than the money column can store "
                        . '(up to AED 21,474,836.47).');
                }

                return $ok($value);

            case 'pct':
                if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
                    return $no("“{$label}” must be a percentage, with at most two decimals.");
                }

                // Compared in hundredths, so the bound is exact integer work too.
                return $this->filsFromAedText($value) <= 10000
                    ? $ok($value)
                    : $no("“{$label}” must be between 0 and 100.");

            case 'ratemap':
                /*
                 * A JSON object of ISO country code => percentage.
                 *
                 * Checked ENTRY BY ENTRY, and one bad entry refuses the whole
                 * map. The alternative — keeping the entries that parsed —
                 * would save a table the owner did not type and show him
                 * "Saved" over it, which is how a wrong tax rate ends up on a
                 * receipt without anyone deciding to put it there.
                 *
                 * Each rate is held to the same shape as the 'pct' rule above,
                 * for the same reason and by the same integer comparison; a
                 * float would make the 100 bound a coin flip.
                 *
                 * The message names the country at fault, by name where the
                 * shop knows it, because the screen this comes back to shows a
                 * table and "invalid" alone would not say which row.
                 */
                if ($value === '' || $value === '{}' || $value === '[]') {
                    return $ok('{}');
                }

                $decoded = json_decode($value, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    return $no("“{$label}” could not be read.");
                }

                $map = [];

                foreach ($decoded as $code => $rate) {
                    $code = strtoupper(trim((string) $code));
                    $name = \App\Support\Countries::NAMES[$code] ?? null;

                    if ($name === null) {
                        return $no("“{$label}” names a country this shop does not deliver to: {$code}.");
                    }

                    if (is_array($rate) || is_object($rate)) {
                        return $no("“{$label}” must give {$name} a single percentage.");
                    }

                    $rate = trim((string) $rate);

                    if (preg_match('/^\d+(?:\.\d{1,2})?$/', $rate) !== 1) {
                        return $no("“{$label}” must give {$name} a percentage, with at most two decimals.");
                    }

                    if ($this->filsFromAedText($rate) > 10000) {
                        return $no("“{$label}” must be between 0 and 100 — {$name} is {$rate}.");
                    }

                    $map[$code] = $rate;
                }

                // Sorted so the stored row does not churn on every save just
                // because the screen happened to build the object in a
                // different order, and FORCE_OBJECT so an empty map is stored
                // as {} rather than [].
                ksort($map);

                return $ok((string) json_encode($map, JSON_FORCE_OBJECT));
        }

        return $no("“{$label}” could not be checked.");
    }

    /**
     * A list of `['country' => 'XX', 'text' => '…']` rows.
     *
     * THE ONE TYPE HERE WHOSE VALUE IS NOT A SINGLE STRING, and it exists
     * because `delivery_texts` — the per-country delivery line — is genuinely a
     * list and had no way through this endpoint at all. That is not a cosmetic
     * gap: the note on SETTING_RULES says a key missing from that list is
     * skipped while the endpoint still answers `ok`, so the screen would have
     * said "Saved" and written nothing, every time, forever. The setting had one
     * reader in the whole codebase and no writer anywhere.
     *
     * SettingsService::set() json-encodes a non-scalar and its decode() reads it
     * back as an array, so the round trip is the service's own and nothing here
     * hand-rolls it.
     *
     * EVERY ROW IS CHECKED AND A BAD ONE REFUSES THE WHOLE PAYLOAD, rather than
     * being dropped quietly. A dropped row is the silent-corruption shape this
     * whole method exists to end: the operator types a line, presses Save, is
     * told it saved, and the row is simply not there when the screen reloads.
     * The country code is checked against App\Support\Countries::NAMES and not
     * merely for being two letters, because the screen offers a picker built
     * from exactly that list — anything else did not come from the screen.
     *
     * AN EMPTY LIST IS A VALID VALUE and means "no country has a line of its
     * own". It has to be: removing the last row is the only way back to the
     * shipped behaviour, and treating [] as "nothing supplied" would leave the
     * old rows in place with the screen reporting otherwise.
     *
     * @return array{value: mixed, error: ?string}
     */
    private function checkRows(string $label, mixed $raw): array
    {
        if (! is_array($raw)) {
            return ['value' => null, 'error' => "“{$label}” must be a list of countries."];
        }

        $clean = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                return ['value' => null, 'error' => "“{$label}” has a row that is not a country and a line."];
            }

            // is_scalar before the cast, not after: `(string) []` is a PHP
            // warning and a literal "Array", which would then be refused for
            // the wrong reason and leave a line in the log that says nothing
            // about the payload that caused it.
            $rawCode = $row['country'] ?? '';
            $rawText = $row['text'] ?? '';

            if (! is_scalar($rawCode) || ! is_scalar($rawText)) {
                return ['value' => null, 'error' => "“{$label}” has a row whose country or line is not text."];
            }

            $code = strtoupper(trim((string) $rawCode));
            $text = trim((string) $rawText);

            if (! array_key_exists($code, \App\Support\Countries::NAMES)) {
                return ['value' => null, 'error' => "“{$label}” has a row for “{$code}”, which is not a country on the list."];
            }

            if (isset($clean[$code])) {
                return ['value' => null, 'error' => "“{$label}” has two rows for "
                    . \App\Support\Countries::NAMES[$code] . '. Each country can have one line.'];
            }

            // settings.value is longText, but this string is printed into a
            // delivery band and under a button — a thousand characters is far
            // past anything that reads as a delivery promise, and the bound is
            // what stops a paste accident becoming the storefront's layout.
            if (mb_strlen($text) > 1000) {
                return ['value' => null, 'error' => "“{$label}” has a line longer than 1,000 characters for "
                    . \App\Support\Countries::NAMES[$code] . '.'];
            }

            $clean[$code] = ['country' => $code, 'text' => $text];
        }

        return ['value' => array_values($clean), 'error' => null];
    }

    /**
     * An AED decimal string -> exact fils, by integer arithmetic only.
     *
     * The caller has already checked the shape, so this cannot fail. Split on
     * the point and combine the halves with an integer multiply and add: 99.50
     * is 9950, 1.15 is 115 and 0.29 is 29 — none of which survive a round trip
     * through a binary float intact.
     */
    private function filsFromAedText(string $value): int
    {
        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
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

        /*
         * TWO SPELLINGS OF "NOT APPROVED", and the chips only counted one.
         *
         * The schema declares `reviews.status` as `pending | approved | spam`.
         * This endpoint has only ever written `rejected`, which is a fourth
         * value nothing else in the application knows — Review::scopeApproved()
         * is `status = 'approved'`, so both spellings do hide the review, which
         * is why nobody noticed. What did not work: a review imported from
         * Sorina carrying the schema's own `spam` was counted in `all` and in
         * no chip, so it was unreachable from the moderation screen, and
         * PUT /admin-api/reviews/{id} answered 422 for it.
         *
         * Counted in one grouped query rather than four COUNT(*) round trips,
         * and `spam` is reported both on its own and folded into `rejected` so
         * the existing chip keeps meaning "not approved, not waiting".
         *
         * DELIBERATELY NOT NORMALISED HERE. Rewriting live `rejected` rows to
         * `spam` is a data migration, and this lane owns the write paths rather
         * than the reviews screen. Both spellings are accepted below until
         * whoever owns that screen picks one.
         */
        $byStatus = \App\Models\Review::query()
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        $counts = [
            'all'      => (int) $byStatus->sum(),
            'pending'  => (int) ($byStatus['pending'] ?? 0),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0) + (int) ($byStatus['spam'] ?? 0),
            'spam'     => (int) ($byStatus['spam'] ?? 0),
        ];

        return response()->json(['reviews' => $rows, 'counts' => $counts]);
    }

    /** PUT /admin-api/reviews/{id} — moderate one review (status and/or reply). */
    public function updateReview(Request $request, int $id)
    {
        $r = \App\Models\Review::find($id);
        if (!$r) return response()->json(['error' => 'not_found'], 404);

        // `spam` is the schema's own third value and was refused here, so a
        // review that arrived carrying it could not be moderated at all. See
        // the note on the counts in reviews() for why both spellings stand.
        $data = $request->validate([
            'status' => 'sometimes|string|in:pending,approved,rejected,spam',
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
        /*
         * SPEND IS REAL ORDERS ONLY; THE ORDER COUNT IS EVERY ORDER.
         *
         * `sum(total)` used to run over every status, cancelled and refunded
         * included, so this screen and the Customers screen — which has always
         * summed Order::REAL_STATUSES — showed two different lifetime totals
         * for the same person, and neither said which it meant. A customer who
         * ordered AED 125 and had a AED 999 order cancelled read as AED 1,124
         * here and AED 125 there.
         *
         * REAL_STATUSES is the right definition and it is not this lane's
         * opinion: the constant exists precisely so the dashboard's revenue
         * figure, Catalog → Reorder and the Customers screen cannot drift
         * apart, and a cancelled order is money the store never took. So this
         * endpoint is the one that changes.
         *
         * The COUNT deliberately stays at every order. "How many times has this
         * person ordered" and "how much have they actually paid us" are two
         * different questions, and the replacement screen keeps both for the
         * same reason (paid_orders beside all_orders).
         */
        $real = Order::REAL_STATUSES;
        $marks = implode(',', array_fill(0, count($real), '?'));

        /*
         * Demo orders are excluded here too, and it has to be here and not only
         * on the dashboard: a demo customer's lifetime spend is invented money
         * against an invented person, and the dashboard's revenue tile and this
         * screen's column are the two figures the owner is most likely to add
         * up by hand and expect to reconcile.
         */
        $agg = DemoSeed::exclude(Order::query(), Order::class)
            ->select('customer_id')
            ->selectRaw('count(*) as n')
            ->selectRaw("coalesce(sum(case when status in ($marks) then total else 0 end), 0) as s", $real)
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        /*
         * The emirate, from where it actually lives.
         *
         * `$c->emirate` was read below and `customers` has no such column —
         * the emirate is `addresses.state`, which the Phase 0 schema comments
         * as "// Emirate". Eloquent answers a missing attribute with null
         * rather than raising, so this column has been blank on every row of
         * the Customers table since it shipped and nothing said why.
         *
         * ONE query for the whole page, not one per customer: ordered so a
         * default shipping address wins, and keyBy() keeps the first of each
         * customer's rows. Sorted in PHP rather than with a window function,
         * which neither SQLite nor the older MySQL this may meet can be
         * assumed to have.
         */
        $emirates = \App\Models\Address::query()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['customer_id', 'type', 'is_default', 'state'])
            ->sortByDesc(fn ($a) => $a->type === 'shipping' ? 1 : 0)
            ->filter(fn ($a) => trim((string) $a->state) !== '')
            ->groupBy('customer_id')
            ->map(fn ($group) => trim((string) $group->first()->state));

        /*
         * FIGURES EXCLUDE DEMO ROWS; LISTS SHOW THEM AND SAY SO.
         *
         * This is a list, and the entire point of Demo Content is to put rows
         * on screens so the panel can be explored before real data exists —
         * hiding them would break the feature it is meant to serve. So the demo
         * customers stay, carrying `is_demo`, and the screen badges them.
         *
         * Their spend column is nevertheless computed from real orders only
         * (see $agg above), so a demo row reads AED 0 next to its badge rather
         * than contributing invented money to a column an owner might add up.
         * The dashboard's customer COUNT — a figure, not a list — leaves them
         * out entirely, and `demo` below is what lets this screen explain the
         * difference instead of the owner discovering it.
         */
        $demoCustomerIds = DemoSeed::idsFor(Customer::class);

        $rows = Customer::orderByDesc('id')->get()->map(function (Customer $c) use ($agg, $emirates, $demoCustomerIds) {
            $a = $agg->get($c->id);
            return [
                'id'         => $c->id,
                'name'       => $c->name,
                'email'      => $c->email,
                'phone'      => $c->phone,
                'emirate'    => $emirates[$c->id] ?? null,
                'orders'     => $a ? (int) $a->n : 0,
                'spent_aed'  => $a ? (int) round(($a->s ?? 0) / 100) : 0,
                'is_demo'    => isset($demoCustomerIds[$c->id]),
                // Shop-local and offset-carrying, for the same reason as
                // the dashboard's recent feed: the screen slices the first
                // ten characters off this string.
                'created_at' => StoreTime::iso($c->created_at),
            ];
        });

        return response()->json([
            'customers' => $rows,
            'timezone'  => StoreTime::zone(),
            'demo'      => DemoSeed::disclosure(),
        ]);
    }

    /**
     * The states a quiz lead can be moved through.
     *
     * `quiz_submissions.status` is a free-form string that defaults to 'new',
     * and nothing has ever written anything else to it — the screen had no way
     * to record that a lead had been rung. An allowlist rather than a free text
     * field so the chip counts on the screen have a fixed set to count.
     */
    public const QUIZ_LEAD_STATUSES = ['new', 'contacted', 'converted', 'closed'];

    /** GET /admin-api/quiz-leads — skin-quiz submissions / leads. */
    public function quizLeads(Request $request)
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

        $query = \App\Models\QuizSubmission::query();

        /*
         * Search, which this screen did not have.
         *
         * The owner opens Quiz Leads because somebody has just phoned, and the
         * three things they have to hand are a name, an email and a number.
         * Phone is matched on DIGITS ONLY on both sides: a lead captured as
         * '+971 50 123 4567' has to be findable by typing '501234567', which is
         * how the number appears on a phone screen. REPLACE is in both dialects
         * and takes no date function, so nothing here is SQLite-only.
         */
        $term = trim((string) $request->query('q', ''));

        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $digits = preg_replace('/\D+/', '', $term);

            $query->where(function ($w) use ($like, $digits) {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('skin_type', 'like', $like);

                if ($digits !== '') {
                    $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '(', ''), ')', '')";
                    $w->orWhereRaw("REPLACE($stripped, '+', '') LIKE ?", ['%' . $digits . '%']);
                }
            });
        }

        $status = (string) $request->query('status', '');
        if (in_array($status, self::QUIZ_LEAD_STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($request->boolean('expert')) {
            $query->where('expert_requested', true);
        }

        $rows = $query->orderByDesc('id')->get()->map(function ($q) use ($decode) {
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
                // The words the customer actually typed when asking for a
                // consultation. The screen flagged the request and hid the
                // request, which made the flag almost useless: the owner could
                // see that somebody wanted help and not what they wanted.
                'expert_message' => $q->expert_message,
                'status'      => $q->status ?: 'new',
                'created_at'  => $q->created_at,
            ];
        });

        return response()->json([
            'leads' => $rows,
            'statuses' => self::QUIZ_LEAD_STATUSES,
            'summary' => $this->quizLeadSummary(),
        ]);
    }

    /**
     * How the quiz is actually doing — over EVERY lead, not the filtered page.
     *
     * A lead counts as converted when an order in Order::REAL_STATUSES exists
     * on the same email address and was placed AFTER the quiz was filled in.
     * Both halves matter and both are the difference between a real figure and
     * a flattering one:
     *
     *   AFTER, because an existing customer who takes the skin quiz on Tuesday
     *   was not converted by it on the Monday before. Matching on email alone
     *   would have counted the store's best customers as quiz conversions and
     *   made the quiz look like its most effective channel.
     *
     *   REAL_STATUSES, because a cancelled order is not a conversion, for the
     *   same reason it is not revenue.
     *
     * This is attribution by the only identifier the two tables share and it is
     * not claimed to be more than that: a lead who orders from a different
     * address is missed, and the figure is a floor rather than an estimate. The
     * screen says so in one line rather than printing a bare percentage.
     *
     * Two queries whatever the lead count: the leads' emails, then the orders
     * on those emails.
     *
     * @return array{total:int, converted:int, converted_pct:int, expert:int, new:int}
     */
    private function quizLeadSummary(): array
    {
        $leads = \App\Models\QuizSubmission::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get(['id', 'email', 'created_at']);

        $total = (int) \App\Models\QuizSubmission::query()->count();

        $converted = 0;

        if ($leads->isNotEmpty()) {
            /*
             * Both spellings go into the IN list.
             *
             * The map below compares lowercased, but the IN list is matched by
             * the DATABASE, and SQLite's `=` on TEXT is case-sensitive while
             * MySQL's utf8mb4_unicode_ci is not. Sending only the lowercased
             * form would quietly drop a lead whose email was stored with a
             * capital on one engine and not the other — a conversion figure
             * that is right in tests and wrong in production.
             */
            $emails = $leads->pluck('email')
                ->flatMap(fn ($e) => [trim((string) $e), mb_strtolower(trim((string) $e))])
                ->filter(fn ($e) => $e !== '')
                ->unique()
                ->values();

            /*
             * The EARLIEST real order per email, so the comparison below is
             * "did they ever order after the quiz" rather than "was their most
             * recent order after the quiz". Grouped in SQL: both the grouped
             * column and the aggregate are named, which is what ONLY_FULL_GROUP_BY
             * wants and what the 1140 that took the Customers screen down twice
             * was about.
             */
            $firstOrder = Order::query()
                ->whereIn('status', self::REVENUE_STATUSES)
                // Bounded by the LEADS' addresses, not the whole orders table.
                // There are far fewer quiz submissions than orders on this shop,
                // so this is the small side of the join; without it the grouped
                // scan reads every order the store has ever taken to answer a
                // question about a few hundred of them.
                ->whereIn('email', $emails->all())
                ->groupBy('email')
                ->selectRaw('email, MIN(created_at) as first_at')
                ->get()
                ->mapWithKeys(fn ($r) => [mb_strtolower(trim((string) $r->email)) => (string) $r->first_at]);

            $seen = [];

            foreach ($leads as $lead) {
                $email = mb_strtolower(trim((string) $lead->email));
                if ($email === '' || isset($seen[$email])) continue;

                $orderedAt = $firstOrder[$email] ?? null;
                if ($orderedAt === null) continue;

                // Parsed rather than string-compared: `orders.created_at` and
                // `quiz_submissions.created_at` are not guaranteed to be
                // written in the same format — QuizController::store() writes
                // an ISO-8601 string into created_at, which SQLite keeps
                // verbatim and MySQL truncates.
                if (\Illuminate\Support\Carbon::parse($orderedAt)->greaterThanOrEqualTo($lead->created_at)) {
                    $seen[$email] = true;
                    $converted++;
                }
            }
        }

        return [
            'total' => $total,
            'converted' => $converted,
            'converted_pct' => $total > 0 ? (int) round($converted * 100 / $total) : 0,
            'expert' => (int) \App\Models\QuizSubmission::query()->where('expert_requested', true)->count(),
            'new' => (int) \App\Models\QuizSubmission::query()->where('status', 'new')->count(),
        ];
    }

    /** PUT /admin-api/quiz-leads/{id} — record that a lead has been worked. */
    public function updateQuizLead(Request $request, int $id)
    {
        $lead = \App\Models\QuizSubmission::find($id);
        if (!$lead) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'status' => 'required|string|in:' . implode(',', self::QUIZ_LEAD_STATUSES),
        ]);

        $lead->status = $data['status'];
        $lead->save();

        return response()->json(['ok' => true, 'id' => $lead->id, 'status' => $lead->status]);
    }

    /** PUT /admin-api/orders/{id}/status — change an order's status. */
    public function updateOrderStatus(Request $request, int $id)
    {
        $o = Order::find($id);
        if (!$o) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'status' => 'required|string|in:draft,pending,processing,onhold,shipped,completed,cancelled,refunded,failed',
            // "Email the customer about this change", as ticked on the order
            // screen. Absent means the operator expressed no view and the
            // standing per-status rule decides, which is what every caller
            // written before this field existed means.
            'notify' => 'sometimes|nullable|boolean',
        ]);

        /*
         * RECORDED BEFORE THE SAVE, because the save is what sends.
         *
         * OrderMailObserver fires on `updated` and it never sees a request, so
         * the operator's choice has to be waiting for it. OrderStatusMailPolicy
         * is bound scoped for exactly this: the instance this line writes to is
         * the instance OrderMailer reads a moment later. Nothing is persisted —
         * it governs this one save and is gone with the response.
         */
        app(\App\Services\Mail\OrderStatusMailPolicy::class)->decideFor(
            $o,
            $request->has('notify') ? $request->boolean('notify') : null,
        );

        $was = (string) $o->status;

        $o->status = $data['status'];
        $o->updated_at = now()->toISOString();
        $o->save();

        // What this costs or credits the shelf is decided in one place for all
        // four sites that write this column — see OrderTransitionStock. Called
        // after the save, and it cannot throw.
        app(\App\Services\Orders\OrderTransitionStock::class)
            ->applied((int) $o->id, $was, (string) $o->status);

        return response()->json(['ok' => true, 'id' => $o->id, 'status' => $o->status]);
    }
}
