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
use App\Support\WholeDirhams;
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
     * WHAT "REVENUE" MEANS ON THESE SCREENS, SHIPPED AS DATA — LANE DU.
     *
     * ── THE DEFECT THIS ANSWERS ────────────────────────────────────────────
     *
     * Every revenue figure on the dashboard and in Analytics is SUM(orders.total)
     * with refunds taken off. `orders.total` is what the customer was billed,
     * and on an EXCLUSIVE-tax order that includes VAT the shop charges on the
     * state's behalf and does not keep. So the headline number counts money
     * that is not the owner's, under a word — "Revenue" — that says it is.
     *
     * The number was not wrong yesterday and it is not wrong today: while the
     * shop sits in the shipped `display` mode, `orders.tax_total` is written 0
     * for every order this application places, so there is nothing in there to
     * take out. It becomes wrong the moment the owner turns `tax_mode` to
     * `live` on a country with an `exclusive` basis, at which point the tile
     * grows by the VAT and says nothing about why. Naming the basis now, while
     * the two readings are the same figure, is the one moment the disclosure
     * costs nobody anything.
     *
     * ── WHY THE FIGURE IS NOT SIMPLY NETTED HERE ───────────────────────────
     *
     * Because it cannot be done exactly, and an approximation carrying the word
     * "Revenue" is the defect again in the other direction. The screen prints
     * Net + Refunded = Gross and that identity has to hold; `refunds.amount` is
     * the money handed back, VAT and all, and NOTHING records the tax share of
     * a refund. Netting the tax out of gross while subtracting refunds whole
     * would understate the result by the VAT inside every refund, and inventing
     * a tax share for a refund is exactly the kind of arithmetic that must not
     * grow a second home (see App\Support\TaxRule).
     *
     * So the figure stays what it is, it SAYS what it is, and the component the
     * owner needs to read it is published beside it — `tax_collected_*_aed`,
     * SUM(orders.tax_total) over the identical rows. Where nothing in the
     * window was refunded, revenue minus that figure is the ex-VAT reading
     * exactly; where something was, it is the ex-VAT reading of what was
     * billed, which is the honest thing to say about it.
     *
     * Published rather than written into a Blade template for the same reason
     * `revenue_statuses` is: the screen prints the list it actually sums, and a
     * label living in the shell would drift from the query the first time
     * either changed.
     *
     * @return array{includes_tax:bool,label:string,note:string}
     */
    public static function revenueBasis(): array
    {
        return [
            // The one machine-readable fact. The shell may branch on it; the
            // two strings are for the owner to read.
            'includes_tax' => true,
            'label' => 'Revenue (incl. VAT)',
            'note' => 'What customers were billed, less refunds. Any VAT charged on these orders is'
                . ' inside this figure — it is collected for the tax authority and not kept. The VAT'
                . ' inside it is shown beside it.',
        ];
    }

    /**
     * What the "Title template" box on SEO & Meta actually governs — LANE EM.
     *
     * Same arrangement, and the same reason, as revenueBasis() above: the
     * screen's explanation of a control lives next to the behaviour it
     * describes, so the two cannot drift. A note written into the admin shell
     * would be wrong the first time either changed, and this particular control
     * has already been reported twice as "does nothing" by people reading it
     * literally.
     *
     * ── WHAT WAS MEASURED, NOT ASSUMED ──────────────────────────────────────
     *
     * The received report was "seo_title_template cannot change a product
     * title". That is NOT what the code does, and the difference matters
     * because it points at a different fix. Rendered against real pages:
     *
     *   TEMPLATE: "Buy {title} today"
     *     product   -> "Buy Anua Heartleaf Quercetinol Toner · K-Beauty Bliss today"
     *     shop      -> "Buy Shop all · K-Beauty Bliss today"
     *     brands    -> "Buy All brands today"
     *     home      -> unchanged
     *
     *   TEMPLATE: "{sitename} {sep} {title}"
     *     product   -> "Anua Heartleaf Quercetinol Toner · K-Beauty Bliss"  (unmoved)
     *     brands    -> "K-Beauty Bliss | All brands"                        (moved)
     *
     * So the template DOES drive product titles. What it cannot do on a product
     * page is REORDER the site name, and the reason is not the template engine:
     * store/product.blade.php ends its own title with the site name
     * (ProductTitle::head()), as do shop, collection, cart, checkout, wishlist,
     * orders, tracking and CMS pages. App\Support\Seo::tokens() then blanks
     * {sitename} so the brand is not printed twice, and TitleTemplate::tidy()
     * drops the separator that token was attached to. On the pages that do NOT
     * bake the site name into their own title — the brand directory, the
     * account area, the newsletter pages — both tokens work normally.
     *
     * The home page ignores the template outright and uses `seo_home_title`.
     *
     * ── WHY THIS IS A NOTE AND NOT A CODE CHANGE ────────────────────────────
     *
     * Making {sitename} live on product pages means taking the suffix out of
     * ProductTitle::head(), which moves every product's browser tab and every
     * product's Google result on a shop that has not asked for it. That is an
     * owner's decision, not a lane's, and ProductTitle::head() says in as many
     * words that it moved that string without changing what any page prints.
     * So the behaviour is left exactly as it is and the screen is made to state
     * it. tests/Feature/TitleTemplateReachTest.php pins every line of this.
     *
     * @return array{label:string,note:string,tokens_note:string}
     */
    public static function titleTemplateBasis(): array
    {
        return [
            'label' => 'Title template',
            'note' => 'Sets the browser tab and the Google result title for every page except the'
                . ' home page, which uses the Home title box below instead. Text you add around'
                . ' {title} appears on all of them.',
            'tokens_note' => '{sitename} and the separator only change pages that do not already end'
                . ' in your site name. Product, shop, category, cart, checkout, wishlist, order and'
                . ' content pages build their own title ending in the site name, so the engine drops'
                . ' {sitename} there rather than print your shop name twice — moving it in this box'
                . ' will not move it on those pages. The brand directory, the account pages and the'
                . ' newsletter pages have no such suffix, and there both tokens work as written.',
        ];
    }

    /**
     * What a per-product revenue figure is made of — LANE DU.
     *
     * `SUM(order_items.total)` is the line value of what was sold and nothing
     * else: no delivery, no gift wrapping, no cash-on-delivery surcharge, and
     * no refunds. It is therefore NOT the same quantity as the revenue tile
     * beside it, and two figures on one screen that are both called "revenue"
     * while summing different columns is the thing this method exists to stop.
     *
     * VAT is the awkward part and it is stated rather than smoothed over: an
     * EXCLUSIVE tax is added at order level and never reaches a line, so it is
     * outside these figures; an INCLUSIVE tax is a portion of the prices the
     * lines carry, so it is inside them. Splitting it per line would need a tax
     * column on `order_items`, which this schema does not have, and
     * apportioning one would be inventing a figure to make a label tidier.
     *
     * @return array{label:string,note:string}
     */
    public static function productRevenueBasis(): array
    {
        return [
            'label' => 'Product sales',
            'note' => 'The value of the lines sold. Delivery, fees and refunds are not in it, and'
                . ' VAT is only in it where the price already included VAT.',
        ];
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

        // The VAT inside the figure above, over the IDENTICAL rows — same
        // statuses, same demo exclusion, same window. See revenueBasis().
        $tax30 = (int) $paidReal()
            ->where('created_at', '>=', $since)
            ->sum('tax_total');

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
            'COUNT(*) as n, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(tax_total), 0) as tax',
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
            // The VAT inside the two figures above, and the words that say the
            // figures carry it. Zero on every order this shop places while
            // `tax_mode` is `display`. See revenueBasis().
            'tax_collected_30d_aed' => (int) round($tax30 / 100),
            'revenue_basis' => self::revenueBasis(),
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
                'tax_collected_aed' => (int) round(((int) ($todayRow->tax ?? 0)) / 100),
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

            /*
             * WHAT THE STOREFRONT WILL PRINT IF THE META DESCRIPTION BOX IS
             * LEFT EMPTY — the string, not a guess at it.
             *
             * The per-product snippet preview in the admin shell had no way to
             * ask, so it made one up: "Shop {name} by {brand} at {site} —
             * authentic Korean skincare, fast UAE delivery." That sentence has
             * never been emitted by this storefront. The real chain is the
             * product's `short_description`, then the sitewide
             * `seo_default_description`, then nothing at all — and the invented
             * one also promised a delivery speed for ONE country on behalf of a
             * shop that serves the Gulf on different terms per country, which
             * is the promise App\Support\DeliveryLine exists to stop being made
             * in one voice.
             *
             * Computed through App\Support\Seo::describe(), the same method
             * that builds the tag on the page, so the preview and the page
             * cannot drift. `ignoreOverride: true` because this is the FALLBACK:
             * it answers "what shows if you clear that box", which is the only
             * question the preview's `||` needs answered.
             *
             * An EMPTY STRING is a real answer and means the page publishes no
             * description tag. The preview should show that rather than hide it
             * — see ProductSeo::rawDescription() for how a product ends up
             * there.
             */
            'seo_fallback_description' => \App\Support\ProductSeo::metaDescription($p, true),
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
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(tax_total), 0) as tax')
            ->first();

        $grossRevenue = (int) ($totals->revenue ?? 0);       // fils
        $paidCount    = (int) ($totals->n ?? 0);
        // The VAT inside $grossRevenue, from the same aggregate over the same
        // rows so the two cannot be taken over different order sets. It is
        // reported, never subtracted — see revenueBasis().
        $taxCollected = (int) ($totals->tax ?? 0);

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
            // Then by the group key, so the "Top products" table is a stable
            // eight. Two products that sold one unit at the same price tie on
            // revenue exactly, and this is a LIMIT.
            // Then by the group key, so the "Top products" table is a stable
            // eight. Two products that sold one unit at the same price tie on
            // revenue exactly, and this is a LIMIT. `name` and `brand` are the
            // whole GROUP BY, so together they cannot tie.
            ->orderByDesc('revenue')
            ->orderBy('order_items.name')
            ->orderBy('order_items.brand')
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
            // What the three figures above are made of, and how much of them is
            // tax the shop collects and does not keep. Both are published
            // rather than written into the screen for the same reason
            // `revenue_statuses` below is. See revenueBasis().
            'tax_collected_aed' => (int) round($taxCollected / 100),
            'revenue_basis'     => self::revenueBasis(),
            'top_products_basis' => self::productRevenueBasis(),
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

    /**
     * GET /admin-api/users — list back-office accounts.
     *
     * AN EXPLICIT SELECT, for the same reason CustomersApiController carries
     * one: `admin_users` also holds `password` and `remember_token`, and this
     * endpoint is the staff list, so the row it reads is by definition the row
     * of somebody who can sign in to the shop. `AdminUser::$hidden` covers the
     * two of them on the way out, but $hidden is a serialisation rule — it
     * stops a hash being printed, it does not stop it being fetched, and it is
     * one `makeVisible()`, one `toArray()` on a related model or one future
     * edit to $hidden away from not applying. Naming the five columns this
     * screen actually shows means the hashes are never loaded at all, so no
     * later change to how this array is built can leak one.
     *
     * The response shape is unchanged.
     */
    public function users()
    {
        $me = \Illuminate\Support\Facades\Auth::guard('admin')->id();
        $rows = \App\Models\AdminUser::query()
            ->select(['id', 'name', 'email', 'role', 'created_at'])
            ->orderBy('id')
            ->get()
            ->map(fn ($u) => [
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

    /**
     * GET /admin-api/settings — flat { key: value } from store_settings.
     *
     * `ga` and `meta_pixel` are ALIASES, not rows.
     *
     * Store → SEO & Meta has a Google Analytics box and a Meta Pixel box.
     * Growth & Marketing → Marketing Pixels has a Google (GA4) box and a Meta
     * Pixel box. Those were four independent values for two IDs, saved in two
     * different tables, with nothing on either screen showing what the other
     * held — which is exactly how a shop ends up with both Google boxes filled
     * in, Google's tag loaded twice and every session counted twice.
     *
     * There is now ONE value per network, in the Marketing Pixels module's own
     * keys, and both screens are windows onto it: read back here, written
     * through in updateSettings(). Whichever box the owner types in, the other
     * shows the same thing, and there is no state in which two different IDs
     * exist for one network.
     */
    public function settings()
    {
        $map = \App\Models\Setting::map();
        $analytics = app(\App\Services\Analytics::class);

        foreach (\App\Services\Analytics::LEGACY_KEYS as $legacyKey => $network) {
            $map[$legacyKey] = $analytics->id($network);
        }

        /*
         * THE CLAIMS BOXES HAVE TO OPEN SHOWING WHAT THE PAGE SHOWS.
         *
         * Setting::map() is the settings TABLE, and a claim whose row has never
         * been written is simply not in it — while the storefront, reading
         * through App\Support\TrustClaims, is rendering that claim's shipped
         * default perfectly happily. Sent raw, the Claims tab would therefore
         * open with seven empty boxes on a shop whose pages all say "100%
         * original", and an owner who pressed Save without typing anything
         * would post seven blanks and silently strip every claim off his own
         * site. An empty box MEANS "remove this claim" on that screen, so an
         * empty box has to mean the owner emptied it.
         *
         * So the resolved value is sent, exactly as the legacy analytics keys
         * above are: row present, its value, blank included; row absent, the
         * default the page is already printing. `?? ''` and not the default
         * again, because text() answers null for a claim the owner really has
         * cleared, and that one must arrive as an empty box.
         */
        foreach (array_keys(\App\Support\TrustClaims::CLAIMS) as $claimKey) {
            $map[$claimKey] = \App\Support\TrustClaims::get($claimKey) ?? '';
        }

        /*
         * Sent BESIDE `settings`, not inside it, because it is not a setting:
         * it is the screen's own explanation of one, resolved from the code
         * that implements it. Same shape as the `revenue_basis` the dashboard
         * and the orders screen already take their tile captions from, and for
         * the same reason — see titleTemplateBasis().
         *
         * Additive: the shell reads `d.settings` and is unaffected by a second
         * top-level key until it chooses to render this one.
         */
        return response()->json([
            'settings' => $map,
            'title_template_basis' => self::titleTemplateBasis(),
        ]);
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
         * What the SITE calls itself, as opposed to what the BUSINESS is
         * called — Lane FW, from docs/FO-HOMEPAGE-INVENTORY.md §2.
         *
         * READ BY TWO STOREFRONT SURFACES AND WRITTEN BY NOTHING until this
         * line. store/home.blade.php prints it as the page's <h1> whenever the
         * hero slider is switched off or has no slides, and
         * store/review-wall.blade.php prints it as the wordmark at the top of
         * the shareable review page. Both had a literal fallback, so the key
         * looked configurable and was not: it was in no SETTING_RULES entry, no
         * module endpoint, no seeder, and nothing in the tree called ->set() on
         * it.
         *
         * NOT A SECOND `store_name`, and it is worth saying why rather than
         * leaving the next reader to wonder. `store_name` is the business: it
         * signs the emails, heads the invoices, carries the footer copyright
         * and is what the SEO layer falls back to. This is the line the shop
         * puts at the top of its own page, and the shipped defaults are
         * different strings for that reason — "K-Beauty Bliss" against
         * "K-Beauty Bliss — authentic Korean skincare in the UAE". Collapsing
         * them would put the tagline on every invoice or strip it off the
         * homepage, and neither is a decision a lane gets to make.
         *
         * BLANK IS ALLOWED AND MEANS "the shipped line". It has to be: a
         * cleared box stores '' rather than removing the row, and
         * SettingsService::get() answers its default only when the ROW is
         * absent — so a reader written as get('site_title', 'K-Beauty Bliss …')
         * would print an EMPTY <h1> the moment the owner cleared the field.
         * Both readers therefore use `?: ` against their own literal, and
         * tests/Feature/SiteTitleSettingTest.php posts a blank and an explicit
         * null through this endpoint and reads the storefront back.
         */
        'site_title' => ['text', 'Site title'],

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

        /*
         * ── WHERE THE SHOP PHYSICALLY IS — Lane S ───────────────────────────
         *
         * `org_type` on the SEO screen has offered `Store` and `LocalBusiness`
         * since that screen was built, and picking either bought NOTHING,
         * because the Organization node carried no address, no coordinates and
         * no hours. These eight keys are what make that choice mean something;
         * App\Support\BusinessAddress turns them into the node and carries the
         * argument for when it refuses to.
         *
         * ON BUSINESS DETAILS AND NOT ON THE SEO SCREEN. This is the same
         * judgement Lane DI made putting `support_phone` here rather than on
         * Mail: where a business trades is a fact about the business, like its
         * name, its currency and its time zone, and it belongs beside them. The
         * SEO screen is where you say how facts are PRESENTED to a search
         * engine; it is not where the shop's street lives. Filing an address
         * under "SEO & Meta" would also mean a shop that later wants it on a
         * contact page has to go to the SEO screen to change it.
         *
         * AND NOT A SECOND USE OF `invoice_address`, which is one free-text
         * blob printed verbatim on a document. It cannot be decomposed into
         * streetAddress / addressLocality / addressCountry without guessing,
         * and guessing is what publishes "Dubai" as a street. The two are also
         * genuinely allowed to differ: plenty of shops invoice from a trade
         * licence address and trade from a mall unit. Nothing about invoices
         * changes here.
         *
         * NO TELEPHONE KEY, deliberately — `support_phone`, eleven lines up, is
         * already the number this shop prints in its own header and footer. A
         * second "phone for search engines" box is a second place for one fact.
         *
         * EVERY ONE SHIPS BLANK, so a shop that never opens the section emits
         * byte-for-byte the JSON-LD it emitted before they existed.
         *
         * ── WHY THESE RULES ────────────────────────────────────────────────
         *
         * The four address lines are `text` for the reason this list's header
         * gives: a street is written a dozen defensible ways in this city
         * ("Shop 4, Al Wasl Road", "Unit 12 — Gold Souk Extension") and any
         * length or character rule would refuse a real one. `postalCode` is
         * `text` and NOT required anywhere, because the UAE does not use postal
         * codes for street addresses at all.
         *
         * `store_country` is `country` AND NOT `code` with 2, which is what it
         * was first written as. Two reasons, and the first is a bug that rule
         * would have shipped:
         *
         *   `code` HAS NO BLANK ESCAPE. It matches exactly N letters, so ''
         *   is refused -- correct for `currency`, which a shop must always
         *   have, and wrong here, where blank is the SHIPPED STATE and the only
         *   way to withdraw an address once one has been published. An owner
         *   who moved to an online-only model could clear the street and the
         *   city and would then be unable to save the screen at all, because
         *   the country box he had filled in once could never be emptied.
         *
         *   It is also read by Google as a claim about where the business is,
         *   and "AR" typed for "AE" is Argentina. `country` checks membership
         *   of App\Support\Countries::NAMES -- the same list checkRows() holds
         *   the per-country delivery lines to -- so the typo is refused at the
         *   box rather than published. BusinessAddress checks it again before
         *   emitting, which costs nothing and covers a row written before this
         *   rule existed.
         *
         * `geo` is a new rule and has to be: latitude and longitude are the
         * only settings in this console with a NUMERIC RANGE that is not a
         * money amount, and `int` cannot hold 25.2048. It refuses anything
         * outside ±90 / ±180 rather than clamping, because a clamped
         * coordinate is a confident wrong place on a map.
         *
         * `hours` is a new rule for the stronger reason: it is the only value
         * here with a SYNTAX, and App\Support\OpeningHours::parse() is the one
         * function that knows it — this rule and the JSON-LD emitter call the
         * same parser, so a line the box accepted is a line the emitter can
         * read. Without that, this screen would accept "monday-ish, 10ish" and
         * answer "Saved" while nothing ever appeared on a page.
         */
        'store_street' => ['text', 'Street address'],
        'store_locality' => ['text', 'City'],
        'store_region' => ['text', 'Emirate or region'],
        'store_postcode' => ['text', 'Postal code'],
        'store_country' => ['country', 'Country'],
        'store_latitude' => ['geo', 'Latitude', 90],
        'store_longitude' => ['geo', 'Longitude', 180],
        'store_hours' => ['hours', 'Opening hours'],

        // Currency display (Store -> Business Details -> Currency). Every one of
        // these has to be here or Save reports success and writes nothing.
        'currency' => ['code', 'Currency', 3],
        'currency_symbol' => ['text', 'Currency symbol'],
        'currency_position' => ['enum', 'Symbol position', \App\Support\Money::POSITIONS],
        /*
         * `optint` AND NOT `int`, AND THE DIFFERENCE IS A MONEY BUG — Lane DI.
         *
         * The box on Store → Business Details → Currency carries the
         * placeholder "blank — whole numbers", nothing seeds this key, and
         * App\Support\Money's own header documents "no `currency_decimals` row"
         * as a supported, shipped state. So blank is not an empty field the
         * owner forgot: it is the value this store runs on.
         *
         * Under `int` it was refused — and because updateSettings() validates
         * the whole tab and writes none of it when one value fails, a fresh
         * shop could not save its STORE NAME. The owner typed his name, pressed
         * Save and was told "Nothing was saved. “Currency decimals” must be a
         * whole number." about a box he had never touched.
         *
         * WHY BLANK IS STORED AS '' AND NOT COERCED TO '0'. Money::config()
         * maps both an absent row and an empty one to null, and null is NOT the
         * same as 0 there — see Money::displayDecimals() and ::minorExponent().
         * null means "print whole dirhams, but keep reading the stored integers
         * as hundredths"; 0 means "this currency HAS no minor unit", which
         * makes minorExponent() 0 and every amount in the database read back a
         * hundred times too large. AED 199, stored as 19900 fils, would print
         * as 19,900. Coercing a blank box to 0 is the money bug, not the
         * refusal it replaces.
         */
        'currency_decimals' => ['optint', 'Currency decimals', [0, 4]],
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

        /*
         * The rest of the Tax tab — Lane CU.
         *
         * These four used to live on Store -> Ecommerce -> Checkout and were
         * written by that screen's own endpoint. The owner asked for "a
         * seperate tab for 'Tax'" and went looking for it on Business Details,
         * so the whole feature is one screen there now and saves through this
         * endpoint with the rate and the per-country table it belongs beside.
         * A key missing from this list is dropped while the endpoint still
         * answers ok — the standing warning at the top — which is why all four
         * are here and not only the new one.
         */
        'tax_mode' => ['enum', 'Tax mode', \App\Support\VatDisplay::MODES],
        'vat_enabled' => ['flag', 'Show the VAT line'],
        'vat_basis' => ['enum', 'How the rate is applied', \App\Support\TaxRule::BASES],
        'vat_label' => ['text', 'VAT line text'],

        /*
         * The per-country BASIS — a JSON object of ISO code => inclusive |
         * exclusive | flat, the other column of the same table
         * vat_country_rates holds the rates for.
         *
         * Two keys rather than one because vat_country_rates already exists, is
         * already validated, and is already filled in on shops that have used
         * it; changing its shape would throw away rates the owner typed. The
         * RATE MAP IS THE TABLE and this is a column on it — VatDisplay drops a
         * basis for a country with no rate of its own, because a country that
         * is not in the table is not in the table.
         */
        'vat_country_bases' => ['basismap', 'Per-country VAT basis'],

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
        /*
         * Product images in the sitemap (Lane S). A flag, and it ships at '0'
         * so applying the package does not move /sitemap.xml -- see the long
         * note in Store\SeoFilesController::sitemap(). Listed here because a
         * key absent from this map is a key the SEO screen can post and the
         * server will silently drop, which is the "Saved on screen, no row in
         * the table" failure SeoVerificationTagsTest was written after.
         */
        'sitemap_images' => ['flag', 'Product images in sitemap'],
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
        /*
         * `optint` for the same reason, found by the same sweep — Lane DI.
         *
         * This is a plain number box on Store → Search appearance → Google
         * Merchant. Clearing it posts '' and, under `int`, refused the WHOLE
         * SEO tab: the owner's title template, his descriptions and his
         * verification tokens all discarded because he emptied a box whose own
         * help text says a zero there publishes no return policy.
         *
         * Blank is unambiguous here. App\Support\Seo reads it as
         * `(int) ($s['merchant_return_days'] ?? 0)`, and `(int) ''` is 0, so an
         * empty row, an absent row and a typed 0 are the same value to the only
         * reader this key has — all three mean "publish no return policy".
         * Nothing is guessed at by accepting the blank.
         */
        'merchant_return_days' => ['optint', 'Return window', [0, 3650]],

        /*
         * ── WHO IS ISSUING THE INVOICE — Lane DG ────────────────────────────
         *
         * Eight keys that App\Services\Invoices\InvoiceDocument::seller() and
         * ::docType() have read since the day they were written, and that
         * NOTHING in this application has ever been able to write. Not "were
         * validated loosely" — absent from this list entirely, which by the
         * standing warning at the top of this constant means every one of them
         * was REJECTED by updateSettings() as an unknown key while the screen
         * that never rendered them could not have posted them anyway.
         *
         * What that cost, in the owner's terms: his legal business name and his
         * trading address are not on the invoices he sends, and there was no
         * box anywhere in this console to put them in. `invoice_trn` is worse
         * than cosmetic — docType() prints "Tax Invoice" only when tax was
         * really charged or really contained AND a registration number is
         * recorded, so with no way to record one the heading read "Invoice" for
         * ever, whatever his accountant told him.
         *
         * They are written by the Invoice tab of Store → Business Details, and
         * InvoiceIdentitySettingsTest saves through this endpoint and reads
         * every value back OFF A RENDERED INVOICE rather than out of the
         * settings table — the round trip is the property, not the row.
         *
         * EVERY ONE SHIPS BLANK and every reader already falls back, so a shop
         * that never opens the tab prints exactly what it printed before.
         *
         * ── WHY THESE RULES AND NOT `text` FOR ALL EIGHT ────────────────────
         *
         * The note at the top of this list is emphatic that a bound has to come
         * from the consumer rather than from taste, and that a wrong limit
         * blocks a legitimate value. Measured against that, five of these eight
         * genuinely have no shape their reader depends on:
         *
         *   name / address / footer / doctype / phone → `text`
         *
         * A phone number is `text` and not a pattern because the seller block
         * prints it verbatim and a UAE shop writes one four defensible ways
         * ("+971 58 505 2611", "058 505 2611", a landline and a mobile with a
         * slash between them). There is nothing here to check it against and
         * refusing one of those forms would be inventing a convention.
         *
         * `invoice_doctype` is `text` for a stronger reason than "no shape":
         * it is printed VERBATIM as the document's heading and it overrides
         * docType()'s own rule. It is the owner's exact words, after he has
         * asked his accountant what a shop of his size in his emirate must call
         * the document — so it may not be normalised, case-folded or held to an
         * enum of phrases this project made up. Only `trim()` applies, which is
         * what every key here gets and what docType() itself already does on
         * read (its `!== ''` check depends on it).
         *
         * The other three are given a rule because their consumer has one:
         *
         *   `invoice_trn`  IS LOAD-BEARING, not decoration. It is the second
         *       half of the condition that lets the document call itself a tax
         *       document, so anything non-empty in this box changes the heading
         *       on every invoice the shop issues. `ident` is deliberately NOT
         *       "fifteen digits": the UAE TRN is 15 and so is the Saudi VAT
         *       number, but this shop delivers across the GCC and beyond and a
         *       15-digit rule would refuse a legitimate registration number
         *       from anywhere that numbers them differently. What it does
         *       refuse is the shape that is never a registration number and
         *       always a mistake — a sentence, a pasted address, a newline.
         *
         *   `invoice_email`  is an address a customer reads off a printed page
         *       and types into their mail client. A malformed one is not a
         *       cosmetic defect: it is a customer who cannot reach the shop.
         *       Checked with filter_var, which is what MailSettings::
         *       replyToAddress() and EmailBranding::support() already use, so
         *       the three agree about what an address is.
         *
         *   `invoice_website`  is checked for the shape of a web address and
         *       NOT required to carry a scheme, because "kbeautybliss.com" is
         *       what an owner types and exactly what belongs on an invoice —
         *       demanding "https://" would be the wrong limit this list's own
         *       header warns about. What `weburl` refuses is whitespace, a
         *       scheme other than http(s), and anything with no dot in it,
         *       which is to say prose typed into the wrong box.
         */
        'invoice_business_name' => ['text', 'Business name on invoices'],
        'invoice_address' => ['text', 'Business address on invoices'],
        'invoice_trn' => ['ident', 'Tax registration number (TRN)'],
        'invoice_email' => ['email', 'Email address on invoices'],
        'invoice_phone' => ['text', 'Phone number on invoices'],
        'invoice_website' => ['weburl', 'Website on invoices'],
        'invoice_footer' => ['text', 'Invoice footer'],
        'invoice_doctype' => ['text', 'What the invoice calls itself'],

        /*
         * ── HOW A CUSTOMER REACHES THIS SHOP — Lane DI ──────────────────────
         *
         * Three keys that are read in five places and were written in none.
         * `support_email` and `brand_whatsapp` are worse than unwritten: they
         * are SEEDED, with this shop's real production address and real
         * production number, so every install carries somebody's actual contact
         * details with no box anywhere in this console to change them.
         *
         * WHY THE BUSINESS TAB AND NOT THE INVOICE TAB. The Invoice tab holds
         * eight keys that are about one document. These three are not:
         *
         *   - `support_email` is InvoiceDocument::seller()'s fallback for
         *     `invoice_email` — so it is the value the invoice uses when the
         *     invoice's own box is blank. A fallback shown beside the thing it
         *     is a fallback for is two email boxes on one tab whose difference
         *     an owner has to be told; a fallback shown one tab up, under the
         *     shop's own details, is where the invoice goes looking.
         *   - `brand_whatsapp` is on EVERY PAGE OF THE STOREFRONT — the header
         *     chip, the footer button, the mobile menu — and in the support
         *     block of every customer email (EmailBranding::support()). Filing
         *     the number that runs the shop's chat button under "Invoice" would
         *     say it was an invoice field, which it is not.
         *   - `support_phone` is the number the header and footer PRINT.
         *
         * AND NOT STORE → MAIL, which is where the second candidate was. Mail
         * already has `mail_support_email` and `mail_support_whatsapp`, and
         * those are deliberately narrower: they are what the EMAIL FOOTER says,
         * overriding these. Putting the shop-wide values on the same screen as
         * their per-channel overrides makes Mail the home of a fact half its
         * readers are not emails. The shop-wide fact belongs with the shop's
         * name, timezone and currency; the mail-only override stays on Mail.
         *
         * `text` FOR BOTH NUMBERS, for the reason `invoice_phone` is text: the
         * seller block, the header and the footer print them verbatim and a UAE
         * shop writes a number four defensible ways. `email` for the address,
         * so the three places that decide what an address is — this rule,
         * MailSettings::replyToAddress() and EmailBranding::support() — keep
         * agreeing. All three accept blank, and blank means "go back to what
         * the shop shipped with" rather than "print an empty line": see
         * App\Support\SupportContact, which is the one place those shipped
         * values now live.
         */
        'support_email' => ['email', 'Support email address'],
        'support_phone' => ['text', 'Phone number shown on the site'],
        'brand_whatsapp' => ['text', 'WhatsApp number'],

        /*
         * THE ACCENT COLOUR, WHICH HAD A READER AND NO WRITER (Lane DN).
         *
         * App\View\Composers\StoreComposer has read `brand_accent` since the
         * baseline and SettingsSeeder has seeded it with '#E0567B'; nothing in
         * this console has ever written it. Every page on the storefront takes
         * its --pink and --pink-deep from the answer
         * (layouts/store.blade.php), so this was the shop's whole accent
         * colour, decided by a seeder and unreachable by its owner.
         *
         * WORSE THAN AN INERT KEY, because the console told the owner it was
         * unreachable AND told him why, in two places on the Theme screen:
         * "they are part of the theme's stylesheet rather than a setting" and
         * "there is no colour editor behind this console and nothing here
         * writes a palette". Both sentences were true of the console and false
         * of the application, and both are corrected in the same commit as
         * this line — a control that saves nothing and a note that says one
         * cannot exist are the same bug told from two ends.
         *
         * `hex`, not `text`: the reader validates and falls back, so free text
         * would save, report "Saved", and change nothing. See checkSetting().
         *
         * Only ONE colour is editable, and that is deliberate. --pink-deep is
         * DERIVED by Color::darken() rather than configured, so the two shades
         * cannot drift apart; the rest of the palette really is fixed in the
         * stylesheet, and the Theme screen still says so about those.
         */
        'brand_accent' => ['hex', 'Brand colour'],

        /*
         * THE SHOP'S TRUST CLAIMS — Lane DR.
         *
         * "100% original", "Direct from brands and trusted suppliers",
         * "24/7 support", "Korean brands, all sourced direct" and two spellings
         * of "100% authentic" were literals inside Blade templates: statements
         * about how this business buys stock and how many hours a day it
         * answers the phone, made to every visitor and, on the checkout, to
         * every shopper in the second before they pay. Whether any of them is
         * true is the owner's question; that he could neither see them nor
         * withdraw them was ours. On shared hosting with no shell, a claim in a
         * template is a claim only a signed package can retract.
         *
         * `reassure_auth_text` was already read from settings by
         * partials/checkout/reassurance.blade.php and had NO ENTRY HERE, which
         * on this endpoint is the same as not existing: updateSettings()
         * rejects every key that is not in this list, so the screen would have
         * reported success and written nothing. That is the failure the header
         * of this list warns about, caught in the wild.
         *
         * `text` for all six, and the empty string is ACCEPTED ON PURPOSE.
         * Clearing the box is how the owner says "do not print this", and
         * App\Support\TrustClaims turns a cleared value into a removed badge
         * rather than an empty one. A rule that refused blanks would take that
         * answer away. Defaults live in TrustClaims::CLAIMS, not here, so the
         * wording has one home.
         */
        'trust_authentic_title' => ['text', 'Home page: authenticity badge title'],
        'trust_authentic_text' => ['text', 'Home page: authenticity badge wording'],
        'trust_support_title' => ['text', 'Home page: support badge title'],
        'home_brands_note' => ['text', 'Home page: brands section note'],
        'checkout_authentic_text' => ['text', 'Checkout: authenticity chip'],
        'reassure_auth_text' => ['text', 'Checkout: reassurance line'],
        'anno_authentic_text' => ['text', 'Announcement bar: authenticity claim'],

        /*
         * The seventh claim, added by Lane DT — the product page's "100%
         * authentic" chip, which Lane DR could not reach because another lane
         * held store/product.blade.php.
         *
         * `text` and blank-accepted for exactly the reason above it: clearing
         * the box is how the claim is withdrawn. Its own key rather than a
         * second use of `checkout_authentic_text`, whose default is the same
         * string — TrustClaims::CLAIMS carries the full reasoning, but the
         * short of it is that a shared box would make clearing the product
         * page's chip also strip the one beside Place order.
         */
        'product_authentic_text' => ['text', 'Product page: authenticity chip'],
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
         * WHAT EACH KEY HOLDS RIGHT NOW, read once, straight off the table.
         *
         * Only the whole-dirham rule uses it, and it uses it for one thing: to
         * tell a value the owner has just TYPED apart from one that is merely
         * being posted back unchanged. This screen submits a whole tab at a
         * time, so without it a shop carrying a legacy COD fee of 1,250 fils
         * could not save its store name — the fee would be refused on every
         * save of that tab for ever, and updateSettings() writes none of a tab
         * when one value fails. That is the same trap the `optint` note on
         * `currency_decimals` below records, and it is worth paying one query
         * to stay out of it.
         *
         * Through the query builder rather than Setting::map() or
         * SettingsService::get(), because both of those apply defaults and
         * memoise — and what is wanted here is the raw row, or nothing.
         */
        $stored = \App\Models\Setting::query()
            ->whereIn('key', array_keys($incoming))
            ->pluck('value', 'key')
            ->all();

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

            $result = $this->checkSetting($type, $label, $value, $extra, $stored[$key] ?? null);

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
        $analytics = app(\App\Services\Analytics::class);

        foreach ($clean as $key => $value) {
            /*
             * The two alias keys are WRITTEN THROUGH to the one canonical ID
             * rather than stored here — see settings() above for why there is
             * only one. Analytics::setId() also drops the superseded
             * `settings` row, so a save from this screen cannot resurrect the
             * second copy, and switches the Marketing Pixels module on if a
             * non-blank ID arrives while it is off. That last part is what
             * keeps this screen's own promise ("saving an ID here loads
             * Google's tag on every storefront page") true now that the tag it
             * loads is the module's.
             */
            if (isset(\App\Services\Analytics::LEGACY_KEYS[$key])) {
                $analytics->setId(\App\Services\Analytics::LEGACY_KEYS[$key], (string) $value);
                $saved++;
                continue;
            }

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
    private function checkSetting(string $type, string $label, mixed $raw, mixed $extra, mixed $stored = null): array
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

            case 'hex':
                /*
                 * A CSS colour, checked with the reader's own test.
                 *
                 * NOT `text`, and the reason is the standing failure mode of
                 * this screen rather than tidiness. App\View\Composers\Store-
                 * Composer guards the value with Color::isValidHex() and falls
                 * back to the design default when it fails — correctly, because
                 * the value is interpolated into a <style> block. So a
                 * mistyped colour saved as free text would be accepted, stored,
                 * reported as "Saved", and then silently ignored by every page
                 * on the storefront. The owner would be looking at the old
                 * colour with the new one in the box. Refusing it says so.
                 *
                 * BLANK IS ALLOWED AND MEANS "the theme's own colour". It has
                 * to be: the reader treats anything it cannot parse as absent,
                 * so clearing the field is the only way back to the default,
                 * and SettingsService::get() returns its default only when the
                 * ROW is absent — a cleared box stores '', never nothing.
                 * Refusing '' would make the default unreachable once a colour
                 * had been set.
                 *
                 * NORMALISED to a leading # in lower case, so that the reader's
                 * own `strtolower($accent) !== '#e0567b'` comparison — which is
                 * what decides whether an override is emitted at all — cannot
                 * be defeated by typing the default back in a different case or
                 * without its hash.
                 */
                if ($value === '') {
                    return $ok('');
                }

                return \App\Support\Color::isValidHex($value)
                    ? $ok('#' . strtolower(ltrim($value, '#')))
                    : $no("“{$label}” must be a colour like #E0567B.");

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

            case 'geo':
                /*
                 * A latitude or a longitude. Blank is allowed and means "no
                 * coordinates", which is the only way back once a pair has been
                 * saved -- the same reason `hex` accepts blank.
                 *
                 * The shape test and the range test are App\Support\
                 * BusinessAddress::isCoordinate()'s, not a second copy, so the
                 * value this screen accepts is exactly the value the emitter
                 * will publish. A number refused here can never reach a page,
                 * and a number stored here can never be silently dropped by the
                 * emitter -- which is the failure mode a separate validator
                 * would reintroduce the first time one of the two was edited.
                 *
                 * REFUSED, NOT CLAMPED. A latitude of 200 is a typo or a
                 * longitude in the wrong box; clamping it to 90 would publish
                 * the North Pole as this shop's address and report "Saved".
                 */
                if ($value === '') {
                    return $ok('');
                }

                return \App\Support\BusinessAddress::isCoordinate($value, (int) $extra)
                    ? $ok($value)
                    : $no("“{$label}” must be a number between -{$extra} and {$extra}, like 25.2048.");

            case 'hours':
                /*
                 * Opening hours, checked by the parser that emits them.
                 *
                 * One function decides what a line means, and it is the one the
                 * JSON-LD layer calls -- see App\Support\OpeningHours for why
                 * that is the whole design. The message comes back naming the
                 * offending LINE, because the owner is looking at a textarea
                 * with several in it and "invalid" would not tell him which.
                 *
                 * Blank is allowed and means the shop publishes no hours.
                 */
                if ($value === '') {
                    return $ok('');
                }

                $hours = \App\Support\OpeningHours::parse($value);

                return $hours['ok'] ? $ok($value) : $no("“{$label}”: " . $hours['error']);

            case 'country':
                /*
                 * A two-letter country code that is really a country.
                 *
                 * BLANK IS ALLOWED and means "no country", which is how an
                 * address is withdrawn -- see the note beside `store_country`
                 * in SETTING_RULES for why `code` could not be reused.
                 *
                 * Checked against App\Support\Countries::NAMES rather than
                 * against a letter count, because the value is published to a
                 * search engine as a fact about where the business trades.
                 * Upper-cased on the way in, so the emitter's lookup and this
                 * one agree without either normalising a second time.
                 */
                if ($value === '') {
                    return $ok('');
                }

                $code = strtoupper($value);

                return array_key_exists($code, \App\Support\Countries::NAMES)
                    ? $ok($code)
                    : $no("“{$label}” must be a two-letter country code, like AE.");

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

                /*
                 * WHOLE DIRHAMS — Lane FA. "no decimals. if any decimals
                 * comes. adjust to the price."
                 *
                 * These four keys (free_ship, delivery_flat, cod_fee,
                 * gift_fee) are money the owner types, so this REFUSES rather
                 * than adjusts — see App\Support\WholeDirhams for the rule and
                 * why it falls that way. 1,250 fils is AED 12.50, and a COD
                 * surcharge of AED 12.50 on a shop whose totals are whole
                 * dirhams is the fil that reappears on every cash order.
                 *
                 * THE WIRE IS FILS HERE, not dirhams — the screen multiplies
                 * before posting — so the message is built in fils too. The
                 * whole-dirham helper talks in major units, which is the right
                 * vocabulary for the owner and the wrong one for the box he is
                 * actually looking at, and telling him "enter AED 12" beside a
                 * field that wants 1200 is how the hundredfold mistake the
                 * `fils` rule above exists to prevent gets made a second time.
                 *
                 * Unchanged values pass. See the $stored note in
                 * updateSettings() — without it, one legacy fee makes a whole
                 * settings tab unsaveable.
                 */
                if ((string) $stored !== $value && ! WholeDirhams::isWhole((int) $value)) {
                    $unit = WholeDirhams::unit();

                    return $no("“{$label}” is " . $value . ' fils, which is AED '
                        . \App\Support\Money::decimalString((int) $value)
                        . '. This shop prices in whole ' . WholeDirhams::plural()
                        . ', so enter ' . WholeDirhams::toward((int) $value)
                        . ' or ' . WholeDirhams::away((int) $value)
                        . ' (fils come in ' . $unit . 's).');
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

                /*
                 * WHOLE DIRHAMS on the two major-unit money keys —
                 * merchant_ship_cost and merchant_ship_free_over.
                 *
                 * These feed App\Support\Seo, which publishes them to Google
                 * Merchant as a shipping rate and a free-shipping threshold
                 * beside the product's own price. A shop that advertises
                 * "AED 12.50 delivery" in a product feed while charging whole
                 * dirhams at the till has published a price it does not honour
                 * — which is a worse failure than an untidy admin screen,
                 * because the crawler compares the two.
                 *
                 * Here the wire IS major units, so the ordinary message reads
                 * correctly and no fils translation is needed.
                 */
                if ((string) $stored !== $value
                    && ! WholeDirhams::isWhole($this->filsFromAedText($value))) {
                    return $no(WholeDirhams::message($label, $this->filsFromAedText($value)));
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

            case 'basismap':
                /*
                 * A JSON object of ISO country code => inclusive | exclusive |
                 * flat. The `ratemap` case above, entry by entry, for the
                 * column beside the rate.
                 *
                 * ONE BAD ENTRY REFUSES THE WHOLE MAP, for the reason that case
                 * gives at length: keeping the entries that parsed would save a
                 * table the owner did not type and report success over it — and
                 * here a dropped entry does not merely misprint a receipt, it
                 * decides whether a country's shoppers are charged the tax on
                 * top. The message names the country at fault.
                 *
                 * An unknown basis is refused rather than defaulted. VatDisplay
                 * defaults one to `inclusive` when it READS the column, because
                 * a row that arrives from an older build has to be survivable;
                 * a value arriving from the screen is a payload this endpoint
                 * has never emitted, and quietly storing something adjacent to
                 * it is how a shop ends up charging a rate nobody chose.
                 */
                if ($value === '' || $value === '{}' || $value === '[]') {
                    return $ok('{}');
                }

                $decoded = json_decode($value, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    return $no("“{$label}” could not be read.");
                }

                $map = [];

                foreach ($decoded as $code => $basis) {
                    $code = strtoupper(trim((string) $code));
                    $name = \App\Support\Countries::NAMES[$code] ?? null;

                    if ($name === null) {
                        return $no("“{$label}” names a country this shop does not deliver to: {$code}.");
                    }

                    if (is_array($basis) || is_object($basis)) {
                        return $no("“{$label}” must give {$name} a single basis.");
                    }

                    $basis = strtolower(trim((string) $basis));

                    if (! in_array($basis, \App\Support\TaxRule::BASES, true)) {
                        return $no("“{$label}” must give {$name} one of: "
                            . implode(', ', \App\Support\TaxRule::BASES) . '.');
                    }

                    $map[$code] = $basis;
                }

                ksort($map);

                return $ok((string) json_encode($map, JSON_FORCE_OBJECT));

            /*
             * ── THE THREE RULES THE INVOICE IDENTITY KEYS NEEDED — Lane DG ──
             *
             * Every one of them accepts a BLANK VALUE, and that is not an
             * oversight. All eight invoice keys ship empty, every reader in
             * InvoiceDocument falls back, and clearing a box has to be a way of
             * saying "take this off my invoice". A rule that refused '' would
             * make the TRN, the email and the website one-way doors.
             */

            case 'email':
                /*
                 * An address printed on a document a customer reads and types
                 * into their mail client.
                 *
                 * filter_var and not a regex of our own, and not Laravel's
                 * `email` validation rule either: CLAUDE.md records three open
                 * advisories against this framework version and one of them is
                 * CRLF injection in that rule. This value is never handed to a
                 * mail header — the seller block prints it as a text node — but
                 * filter_var refuses a newline anyway, which is the property
                 * that keeps it that way if somebody later makes it a Reply-To.
                 *
                 * The same check MailSettings::replyToAddress() and
                 * EmailBranding::support() already apply, so all three agree
                 * about what an address is rather than each having an opinion.
                 */
                if ($value === '') {
                    return $ok('');
                }

                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                    ? $ok($value)
                    : $no("“{$label}” must be an email address, like info@example.com.");

            case 'weburl':
                /*
                 * A web address as a human writes one on a letterhead.
                 *
                 * NO SCHEME IS REQUIRED, deliberately. "kbeautybliss.com" is
                 * what an owner types into this box and exactly what belongs
                 * printed under his name; demanding "https://" would refuse the
                 * commonest correct answer, which is the wrong-limit failure
                 * SETTING_RULES' own header warns about. A scheme IS accepted,
                 * because a pasted address carries one.
                 *
                 * What is refused: whitespace and control characters (a line of
                 * prose typed into the wrong box), a scheme that is not http or
                 * https (`javascript:` and `data:` are not websites — the
                 * seller block prints this as text today, and the check is here
                 * so that stays safe if it ever becomes an href), and a value
                 * with no dot in it, which is not a domain.
                 */
                if ($value === '') {
                    return $ok('');
                }

                if (preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
                    return $no("“{$label}” must be a web address with no spaces, like kbeautybliss.com.");
                }

                // A scheme is anything before the first ':' that looks like one.
                // Checked before the dot rule, so "javascript:alert(1)" is told
                // what is wrong with it rather than being told it needs a dot.
                if (preg_match('~^([A-Za-z][A-Za-z0-9+.-]*):~', $value, $m) === 1
                    && ! in_array(strtolower($m[1]), ['http', 'https'], true)) {
                    return $no("“{$label}” must be a web address — only http:// and https:// links can be printed.");
                }

                return str_contains($value, '.')
                    ? $ok($value)
                    : $no("“{$label}” must be a web address, like kbeautybliss.com.");

            case 'ident':
                /*
                 * A short registration identifier — today, `invoice_trn`.
                 *
                 * THIS ONE CHANGES A HEADING. InvoiceDocument::docType() prints
                 * "Tax Invoice" only when tax was really charged or contained
                 * AND this value is non-empty, so whatever lands here is a
                 * statement the shop makes about itself on every invoice it
                 * issues. That is why it is not `text`.
                 *
                 * NOT "FIFTEEN DIGITS". The UAE TRN is fifteen digits and so is
                 * the Saudi VAT number, but this shop delivers well beyond both
                 * and a jurisdiction that numbers its registrations differently
                 * would be locked out by a rule written for one country. Letters
                 * are allowed for the same reason. Spaces, hyphens and slashes
                 * are allowed because registrations are commonly written with
                 * them and reformatting the owner's own number would be the
                 * "helpful" normalisation this lane is not doing anywhere.
                 *
                 * 40 characters is not a guess at a format; it is the length
                 * past which this has stopped being an identifier and started
                 * being a sentence in the wrong box — which, unchecked, would
                 * silently re-head every invoice in the shop.
                 */
                if ($value === '') {
                    return $ok('');
                }

                return preg_match('/^[A-Za-z0-9][A-Za-z0-9 \/-]{0,39}$/', $value) === 1
                    ? $ok($value)
                    : $no("“{$label}” must be a registration number — letters, digits, "
                        . 'spaces and hyphens, up to 40 characters.');

            /*
             * ── A WHOLE NUMBER, OR NOTHING AT ALL — Lane DI ─────────────────
             *
             * `int` with one addition: an empty value is accepted and stored as
             * ''. Everything else about it — the digits-only shape, the bounds,
             * the messages — is `int`'s, deliberately, because the two must not
             * drift apart.
             *
             * A SEPARATE TYPE AND NOT A LOOSENING OF `int`. Whether blank is a
             * legitimate answer is a property of the KEY, not of "being a
             * number": `merchant_return_days` has a documented meaning for an
             * empty value and `currency_decimals` ships empty by design, while
             * a bound like `[0, 4]` on a key whose reader has no empty case
             * would be turned by a blanket loosening into a silent way to store
             * nothing and be told it saved. That is the exact failure the note
             * at the top of SETTING_RULES exists to warn about, and this repo
             * has recorded it more than once. Opting in per key keeps the
             * decision where it can be read.
             *
             * '' AND NOT '0', AND NOT A DELETED ROW. Both readers that use this
             * type already treat an empty value exactly as they treat an absent
             * one — Money::config() collapses null and '' to null before it
             * does anything else, and Seo's `(int)` cast makes '' and a missing
             * key both 0 — so storing the empty string needs nothing new from
             * either of them and needs no delete path in SettingsService, whose
             * whole job here is to flush two caches on write. What the row does
             * carry is the fact that the owner looked at the box and chose the
             * blank, which an absent row cannot say.
             */
            case 'optint':
                if ($value === '') {
                    return $ok('');
                }

                [$min, $max] = (array) $extra;

                if (preg_match('/^-?\d+$/', $value) !== 1) {
                    return $no("“{$label}” must be a whole number, or blank.");
                }

                $n = (int) $value;

                return ($n >= $min && $n <= $max)
                    ? $ok((string) $n)
                    : $no("“{$label}” must be between {$min} and {$max}.");
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

        /*
         * Through the funnel, which is the only thing that writes the column —
         * see App\Services\Orders\OrderStatus. What it adds here is what this
         * screen could never do on its own: the move is recorded in the order's
         * history with the operator's name against it, and a code that was
         * spent on an order this dropdown cancels is handed back.
         *
         * It refuses ONE thing, and silently: setting the status the order is
         * already in. There is nothing to tell the operator, because the answer
         * they get is the one they asked for.
         *
         * Nothing else is refused. An operator who has just marked the wrong
         * order completed can put it back to pending from this dropdown, which
         * on a host with no shell is the only way that mistake ever gets fixed.
         */
        /*
         * IT CAN NOW FAIL, and that is the substance of this guard.
         *
         * A move back out of `cancelled`, `failed` or `refunded` has to take
         * back the units the cancellation put on the shelf and the coupon use
         * it handed out. When the units have been sold since, or the code has
         * been redeemed to its limit since, the funnel refuses the whole
         * transition and nothing is written. That refusal has to reach the
         * operator: it is the one outcome where a cheerful `ok: true` and an
         * unchanged order is exactly the silent oversell this exists to stop.
         *
         * 422 rather than 409 or 500 — the request is well formed and the
         * server understood it, and the shop's state is what will not have it.
         * `status` in the body is the status the order still has, which is the
         * one the screen must go on showing.
         */
        try {
            app(\App\Services\Orders\OrderStatus::class)->moveTo(
                $o,
                $data['status'],
                by: auth('admin')->user()?->name ?: 'Admin',
                reason: 'Changed on the order screen.',
            );
        } catch (\App\Services\Orders\OrderReviveRefused $e) {
            return response()->json([
                'error' => 'revive_refused',
                'kind' => $e->kind,
                'message' => $e->getMessage(),
                'id' => $o->id,
                // Read off the row rather than the instance: nothing was
                // written, so this is what it was and still is.
                'status' => (string) $o->fresh()?->status,
            ], 422);
        }

        // The shelf is not this screen's business either: OrderTransitionStock
        // is called by the funnel, once, for every site that moves a status —
        // which is what that class's own header asked for.

        return response()->json(['ok' => true, 'id' => $o->id, 'status' => $o->status]);
    }
}
