<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Support\DemoSeed;
use App\Support\Money;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Store → Customers.
 *
 * WHAT WAS HERE BEFORE. AdminController::customers() loaded every customer row
 * with ->get(), looked up a grouped order aggregate it had already pulled into
 * memory, and printed $c->emirate — a column that does not exist on this table
 * and never has, so the "Emirate" column was blank for every customer on every
 * install. No pagination, no filters, no sorting, no search, no detail view.
 * That endpoint stays registered (routes/web.php is not this lane's to edit)
 * but nothing reads it any more; see routes/customers-admin.php.
 *
 * WHERE THE NUMBERS COME FROM, AND WHY NOT FROM THE COLUMNS THAT LOOK RIGHT.
 * `customers` carries orders_count, total_spent and last_order_at. Nothing in
 * this application writes any of the three, so on a live install they read 0, 0
 * and NULL for every customer including one with hundreds of dirhams of
 * history. Reading them would have produced a screen that looked finished and
 * was entirely wrong, which is the defect shape this codebase keeps paying for.
 * Every figure here is aggregated from `orders` at query time instead. If the
 * WooCommerce importer later populates those columns they become a second,
 * independent source of truth for the same fact; that is raised in the report
 * rather than quietly consumed here.
 *
 * MONEY. Every total is an integer number of fils (AED x 100) end to end: SUM()
 * in SQL, intdiv() for the average order value, and Money::plain() for display,
 * which formats out of the integer without ever constructing a float.
 *
 * ONE QUERY, NOT ONE PER ROW. /shop once ran 390 queries for four products. The
 * list here is a fixed number of statements whatever the customer count: three
 * grouped derived tables (orders, carts, addresses) are LEFT JOINed onto
 * `customers`, so order counts, lifetime spend, last order, last cart activity
 * and the customer's country/city all arrive with the page of rows.
 *
 * WHAT COUNTS AS AN ORDER. Order::REAL_STATUSES — the same definition Catalog →
 * Reorder and the dashboard's revenue figure use. A cancelled or failed order
 * is not spend. The row still knows its all-statuses count, so the detail view
 * can show those orders without the list's money being wrong.
 *
 * GUESTS. Checkout firstOrCreate()s a `customers` row for a shopper who does
 * not sign in, so a guest here is a customer row with no usable credential
 * (password and legacy_password both NULL) rather than a missing row. Imported
 * Woo guest orders that arrive with customer_id NULL belong to nobody and
 * cannot appear in a customer list at all; the list reports how many there are
 * rather than silently dropping their revenue on the floor.
 */
class CustomersApiController extends Controller
{
    use \App\Support\AggregatesQueries;

    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MIN = 10;
    private const PER_PAGE_MAX = 500;

    /** Hard ceiling on one CSV. A shared host is not a reporting server. */
    private const EXPORT_MAX = 50000;

    /** Rows per database round trip while streaming the CSV. */
    private const EXPORT_CHUNK = 500;

    /** Ceiling on one bulk action, so a stuck loop cannot empty the table. */
    private const BULK_MAX = 500;

    /**
     * Which build of THIS FILE is actually executing, reported with any error.
     *
     * 2.60.121 shipped the real fix for the MySQL 1140 failure and the live
     * server returned the identical error afterwards. That left two
     * indistinguishable explanations — the fix is wrong, or the fix is not
     * running — and no way to tell them apart from a screenshot. The package
     * was verified to contain the corrected file, so it was the second; but
     * proving that took a round trip that this constant removes.
     *
     * Bump it whenever this file changes. If an error reports a build older
     * than the package just applied, the server is serving cached bytecode and
     * the code is not the thing to go and look at.
     */
    private const BUILD = '2.60.130';

    /**
     * The chip filters, named once so the list, the chip counts and the export
     * cannot drift apart — a filter meaning one thing on screen and another in
     * the download is how somebody emails the wrong customer list.
     */
    private const SEGMENTS = ['all', 'ordered', 'never', 'repeat', 'account', 'guest', 'verified', 'unverified', 'trashed'];

    /**
     * Sentinel for "no activity ever".
     *
     * MySQL's GREATEST() and SQLite's multi-argument max() both return NULL if
     * any argument is NULL, which would sort every never-active customer to the
     * same place as the most recent one. ISO-8601 sorts lexicographically the
     * same way it sorts chronologically, so this works as a string on SQLite
     * and as a datetime on MySQL.
     */
    private const NEVER = '1970-01-01 00:00:00';

    /* ------------------------------------------------------------------ list */

    public function index(Request $request): JsonResponse
    {
        /*
         * A database error here is reported, not swallowed.
         *
         * This screen returned a bare 500 "Server Error" on the live server
         * while answering 200 in every test, and with APP_DEBUG off there was
         * nothing to go on — the reason took a round trip through the owner and
         * a diagnostic release to even name. The endpoint is inside the
         * auth:admin group, so the person reading this is already the store
         * owner: telling them "Unknown column 'customers.notes'" costs nothing
         * and saves that round trip.
         *
         * Only the driver's message is returned, never the SQL and never the
         * bindings — bindings carry the search term and the operator's filters.
         */
        try {
            return $this->listing($request);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'The customer list could not be read from the database.',
                // getPrevious() is the PDOException, whose message is the driver's
                // own text and nothing else. NEVER fall back to
                // $e->getMessage(): QueryException appends
                // "(Connection: mysql, SQL: ... where email = someone@example.com)"
                // — the whole statement with the BINDINGS INTERPOLATED, and the
                // bindings here carry the operator's search term. That would make
                // the promise three lines above a lie. Found by Lane Z.
                'db_error' => $e->getPrevious()?->getMessage()
                    ?? 'The database driver gave no further detail.',
                'build' => self::BUILD,
            ], 500);
        }
    }

    private function listing(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = $this->clampPerPage((int) $request->query('per_page', self::PER_PAGE_DEFAULT));
        $segment = $this->segment($request);
        $sort = (string) $request->query('sort', 'newest');

        // Everything except the chip, so the chip counts describe the list the
        // other filters have already narrowed to.
        $base = $this->baseQuery($request);

        $counts = $this->segmentCounts($base, $request);

        $query = $this->applySegment(clone $base, $segment);

        $total = (clone $query)->toBase()->getCountForPagination();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = $this->applySort($query, $sort)
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($c) => $this->rowToApi($c))
            ->values();

        return response()->json([
            'customers' => $rows,
            // Totals for the view the operator is actually looking at, chip
            // included — a summary that silently described a different set of
            // customers than the rows underneath it would be worse than none.
            'summary' => $this->summaryFor($query),
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'counts' => $counts,
            'countries' => $this->knownCountries(),
            // Orders belonging to no customer row. Always 0 on a store that has
            // only ever taken orders through this checkout; a WooCommerce
            // import that brings guest orders across without creating customers
            // is what makes it non-zero, and the screen says so out loud.
            'unlinked_orders' => (int) Order::query()
                ->whereNull('customer_id')
                ->whereIn('status', Order::REAL_STATUSES)
                ->count(),
            'currency' => Money::currency(),
        ]);
    }

    /* ---------------------------------------------------------------- detail */

    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $this->rowQuery(Customer::query()->withTrashed())
            ->where('customers.id', $id)
            ->first();

        if ($customer === null) {
            return response()->json(['ok' => false, 'error' => 'Customer not found.'], 404);
        }

        $orders = Order::query()
            ->where('customer_id', $id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get([
                'id', 'wc_order_id', 'order_number', 'status', 'total',
                'payment_method_title', 'payment_method', 'created_at', 'paid_at',
            ])
            ->map(fn (Order $o) => [
                'id' => (int) $o->id,
                // Surfaced, not hidden: the WooCommerce order id is how an
                // imported order is matched back to the source system.
                'wc_order_id' => $o->wc_order_id === null ? null : (int) $o->wc_order_id,
                'order_number' => (string) $o->order_number,
                'status' => (string) $o->status,
                'total_fils' => (int) $o->total,
                'total_display' => Money::plain((int) $o->total),
                'payment' => $o->paymentLabel(),
                'placed_at' => $this->iso($o->created_at),
                'paid_at' => $this->iso($o->paid_at),
                'counts_as_spend' => in_array((string) $o->status, Order::REAL_STATUSES, true),
            ])
            ->values();

        $addresses = DB::table('addresses')
            ->where('customer_id', $id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get([
                'id', 'type', 'is_default', 'first_name', 'last_name', 'company',
                'line1', 'line2', 'city', 'state', 'postcode', 'country', 'phone',
            ])
            ->map(fn ($a) => [
                'id' => (int) $a->id,
                'type' => (string) $a->type,
                'is_default' => (bool) $a->is_default,
                'name' => trim(((string) $a->first_name) . ' ' . ((string) $a->last_name)),
                'company' => $a->company,
                'line1' => $a->line1,
                'line2' => $a->line2,
                'city' => $a->city,
                'state' => $a->state,
                'postcode' => $a->postcode,
                'country' => $a->country,
                'phone' => $a->phone,
            ])
            ->values();

        return response()->json([
            'customer' => $this->rowToApi($customer) + [
                'notes' => (string) ($customer->notes ?? ''),
            ],
            'orders' => $orders,
            'addresses' => $addresses,
        ]);
    }

    /* ---------------------------------------------------------------- export */

    /**
     * CSV of the current filtered view — the same rows, in the same order, as
     * the screen the operator is looking at, not "all customers".
     *
     * Two things this is careful about. It streams in chunks, because a shared
     * host will not hold 5,000 customers and their aggregates in memory
     * alongside the request. And every cell goes through csvCell(), which
     * neutralises the formula-injection characters: a shopper whose name is
     * `=HYPERLINK(...)` must not become a live formula when the owner opens the
     * file, and the name field on this store is typed by the public at
     * checkout.
     */
    public function export(Request $request): StreamedResponse
    {
        $segment = $this->segment($request);
        $sort = (string) $request->query('sort', 'newest');

        /*
         * NO ->limit(self::EXPORT_MAX) HERE, DELIBERATELY. It used to be, and
         * it did nothing at all.
         *
         * chunk() walks the query with forPage(), and forPage() SETS limit and
         * offset — it does not intersect with a limit already on the builder.
         * So the 50,000 ceiling was overwritten by `limit 500 offset 0` on the
         * first round trip and the export streamed the entire filtered table.
         * Proved by the statements the endpoint issues: EXPORT_MAX never
         * appeared in any of them.
         *
         * The ceiling is enforced where it can actually be enforced — by
         * counting rows written and stopping the walk. Returning false from the
         * chunk callback is how chunk() is told to stop.
         */
        $query = $this->applySort(
            $this->applySegment($this->baseQuery($request), $segment),
            $sort
        );

        $filename = 'customers-' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM: without it Excel on Windows reads an Arabic or
            // accented name as mojibake, and this customer list has both.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'id', 'wp_user_id', 'name', 'first_name', 'last_name', 'email', 'phone',
                'account_type', 'email_verified', 'registered_at', 'orders', 'orders_all',
                'total_spent_fils', 'total_spent', 'aov_fils', 'aov',
                'last_order_at', 'last_active_at', 'city', 'state', 'country', 'currency',
            ]);

            $currency = Money::currency();

            $written = 0;

            $query->chunk(self::EXPORT_CHUNK, function ($chunk) use ($out, $currency, &$written) {
                foreach ($chunk as $c) {
                    if ($written >= self::EXPORT_MAX) {
                        return false;
                    }

                    $written++;

                    $row = $this->rowToApi($c);

                    fputcsv($out, array_map($this->csvCell(...), [
                        $row['id'],
                        $row['wp_user_id'] ?? '',
                        $row['name'] ?? '',
                        $row['first_name'] ?? '',
                        $row['last_name'] ?? '',
                        $row['email'],
                        $row['phone'] ?? '',
                        $row['account_type'],
                        $row['email_verified'] ? 'yes' : 'no',
                        $row['registered_at'] ?? '',
                        $row['orders'],
                        $row['orders_all'],
                        $row['spend_fils'],
                        $this->majorString($row['spend_fils']),
                        $row['aov_fils'],
                        $this->majorString($row['aov_fils']),
                        $row['last_order_at'] ?? '',
                        $row['last_active_at'] ?? '',
                        $row['city'] ?? '',
                        $row['state'] ?? '',
                        $row['country'] ?? '',
                        $currency,
                    ]));
                }
            });

            fclose($out);
        }, 200, $headers);
    }

    /* --------------------------------------------------------------- actions */

    /** The shop owner's private note about this customer. */
    public function saveNote(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        $customer = Customer::query()->withTrashed()->find($id);

        if ($customer === null) {
            return response()->json(['ok' => false, 'error' => 'Customer not found.'], 404);
        }

        $customer->forceFill(['notes' => (string) ($data['notes'] ?? '')])->save();

        return response()->json(['ok' => true, 'notes' => (string) $customer->notes]);
    }

    /**
     * Move a customer to the trash. Reversible, and it refuses by default.
     *
     * This is a soft delete, so the record and its orders survive — but a
     * customer with order history disappearing from the list is still a
     * surprise worth refusing once. Without force the response reports how many
     * orders and how much money would go with them and changes nothing; the
     * screen shows those numbers in a confirm dialog and only then sends force.
     * Same shape as the guarded deletes on the brands and categories screens.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $customer = Customer::query()->find($id);

        if ($customer === null) {
            return response()->json(['ok' => false, 'error' => 'Customer not found.'], 404);
        }

        $stats = $this->orderStatsFor([$id])[$id] ?? ['orders' => 0, 'spend' => 0];

        if ($stats['orders'] > 0 && ! $request->boolean('force')) {
            return response()->json([
                'ok' => false,
                'needs_confirmation' => true,
                'orders' => $stats['orders'],
                'spend_fils' => $stats['spend'],
                'spend_display' => Money::plain($stats['spend']),
                'message' => 'This customer has order history. Nothing has been deleted.',
            ], 409);
        }

        $customer->delete();

        return response()->json(['ok' => true, 'id' => $id, 'trashed' => true]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $customer = Customer::query()->onlyTrashed()->find($id);

        if ($customer === null) {
            return response()->json(['ok' => false, 'error' => 'No trashed customer with that id.'], 404);
        }

        $customer->restore();

        return response()->json(['ok' => true, 'id' => $id, 'trashed' => false]);
    }

    /**
     * Trash several at once, with the same refusal rule applied per customer.
     *
     * Without force, customers who have ordered are skipped and reported back
     * by name rather than the whole call failing — the operator asked for the
     * selection, and the safe half of it is still what they meant.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::BULK_MAX],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $force = $request->boolean('force');

        $customers = Customer::query()->whereIn('id', $ids)->get(['id', 'name', 'email']);
        $stats = $this->orderStatsFor($customers->pluck('id')->map(fn ($v) => (int) $v)->all());

        $deletable = [];
        $skipped = [];

        foreach ($customers as $customer) {
            $orders = $stats[(int) $customer->id]['orders'] ?? 0;

            if ($orders > 0 && ! $force) {
                $skipped[] = [
                    'id' => (int) $customer->id,
                    'label' => (string) ($customer->name ?: $customer->email),
                    'orders' => $orders,
                ];

                continue;
            }

            $deletable[] = (int) $customer->id;
        }

        if ($deletable !== []) {
            Customer::query()->whereIn('id', $deletable)->delete();
        }

        return response()->json([
            'ok' => true,
            'deleted' => count($deletable),
            'skipped' => $skipped,
        ]);
    }

    /* --------------------------------------------------------------- queries */

    /**
     * `customers` with every aggregate this screen needs already joined on.
     *
     * Three grouped derived tables and one plain join, evaluated once for the
     * whole page rather than once per row:
     *
     *   oa  orders, conditionally aggregated so the paid figures (spend, last
     *       order) and the all-statuses count come out of a single pass.
     *   ca  carts, for last-seen activity from someone who has never ordered.
     *   ap  addresses, reduced to one chosen address id per customer — the
     *       default if there is one, otherwise the oldest. COALESCE over two
     *       aggregates rather than a window function, because the production
     *       host is MySQL and ROW_NUMBER() is not safe to assume there.
     */
    private function rowQuery(?Builder $from = null): Builder
    {
        $real = Order::REAL_STATUSES;
        $marks = implode(',', array_fill(0, count($real), '?'));

        /*
         * SPEND IS NET OF REFUNDS.
         *
         * The comment above this method already explains why spend counts only
         * REAL_STATUSES: a cancelled order is money the store never took. A
         * partial refund is the same fact wearing a different hat, and it was
         * being missed. PaymentRefunder moves an order to 'refunded' only once
         * the refunds cover the whole captured amount — see the comment on its
         * status update, which says so in as many words — so a customer given
         * AED 400 back on a AED 1,000 order kept the whole AED 1,000 here, in
         * the sort-by-value order, in the summary strip and in the CSV export.
         *
         * That figure is what the owner uses to decide who their best customers
         * are, so being wrong in the generous direction is not harmless: it
         * promotes whoever returned the most.
         *
         * WHICH REFUND ROWS: PaymentRefunder::COUNTED — settled, or in flight
         * and reserved, and NOT the failed attempts, which exist precisely so
         * the merchant can see that no money moved. Same definition the order
         * screen's `refunded_total_aed` uses and the same one the dashboard and
         * Analytics use, so no two screens can describe one order differently.
         */
        $refunded = DB::table('refunds')
            ->whereIn('status', \App\Services\Payments\PaymentRefunder::COUNTED)
            ->groupBy('order_id')
            ->selectRaw('order_id, COALESCE(SUM(amount), 0) as refunded_fils');

        /*
         * Demo orders are not spend. Store -> Demo Content seeds eight of them
         * and logs each in `demo_seed_log`; without this they counted towards
         * a customer's lifetime value, the store-wide average order value and
         * every band filter on this screen, so turning demo content on moved
         * figures the owner reads as real money. Same definition of "demo" as
         * the Dashboard and Analytics use -- see App\Support\DemoSeed.
         */
        $orders = DemoSeed::excludeQuery(DB::table('orders'), Order::class, 'orders.id')
            ->leftJoinSub($refunded, 'rf', 'rf.order_id', '=', 'orders.id')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.customer_id')
            ->groupBy('orders.customer_id')
            /*
             * The CASE around the subtraction floors a line at zero rather than
             * letting it go negative. It cannot through this application —
             * PaymentRefunder refuses a refund beyond what was captured, and a
             * full one takes the order out of REAL_STATUSES — but an imported
             * WooCommerce refund never passed through that check, and one bad
             * row must not be able to drag a customer's lifetime value below
             * what they actually paid. Written as CASE rather than MAX()/
             * GREATEST(), which do not mean the same thing on both engines.
             */
            ->selectRaw(
                "orders.customer_id as customer_id,
                 SUM(CASE WHEN orders.status IN ($marks) THEN 1 ELSE 0 END) as paid_orders,
                 COALESCE(SUM(CASE WHEN orders.status IN ($marks)
                     THEN (CASE WHEN orders.total - COALESCE(rf.refunded_fils, 0) > 0
                                THEN orders.total - COALESCE(rf.refunded_fils, 0) ELSE 0 END)
                     ELSE 0 END), 0) as spend_fils,
                 MAX(CASE WHEN orders.status IN ($marks) THEN orders.created_at END) as paid_last_at,
                 COUNT(*) as all_orders",
                array_merge($real, $real, $real)
            );

        $carts = DB::table('carts')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(last_activity_at) as cart_last_at');

        $address = DB::table('addresses')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(MIN(CASE WHEN is_default = 1 THEN id END), MIN(id)) as addr_id');

        $never = self::NEVER;

        return ($from ?? Customer::query())
            ->leftJoinSub($orders, 'oa', 'oa.customer_id', '=', 'customers.id')
            ->leftJoinSub($carts, 'ca', 'ca.customer_id', '=', 'customers.id')
            ->leftJoinSub($address, 'ap', 'ap.customer_id', '=', 'customers.id')
            ->leftJoin('addresses as ad', 'ad.id', '=', 'ap.addr_id')
            ->select([
                // An explicit allowlist. `customers` also carries password,
                // legacy_password and remember_token; a screen that selected
                // the whole row would hand all three to anything that could
                // reach this endpoint.
                'customers.id',
                'customers.wp_user_id',
                'customers.name',
                'customers.first_name',
                'customers.last_name',
                'customers.email',
                'customers.phone',
                'customers.email_verified_at',
                'customers.whatsapp_optin',
                'customers.notes',
                'customers.created_at',
                'customers.deleted_at',
                DB::raw('(CASE WHEN customers.password IS NOT NULL OR customers.legacy_password IS NOT NULL THEN 1 ELSE 0 END) as has_login'),
                DB::raw('COALESCE(oa.paid_orders, 0) as paid_orders'),
                DB::raw('COALESCE(oa.spend_fils, 0) as spend_fils'),
                DB::raw('COALESCE(oa.all_orders, 0) as all_orders'),
                DB::raw('oa.paid_last_at as paid_last_at'),
                DB::raw('ca.cart_last_at as cart_last_at'),
                DB::raw($this->lastActiveExpression() . ' as last_active_at'),
                DB::raw('ad.city as addr_city'),
                DB::raw('ad.state as addr_state'),
                DB::raw('ad.country as addr_country'),
            ])
            ->addBinding([$never, $never, $never, $never], 'select');
    }

    /** The later of "last paid order" and "last cart activity". */
    private function lastActiveExpression(): string
    {
        return '(CASE WHEN COALESCE(ca.cart_last_at, ?) > COALESCE(oa.paid_last_at, ?)'
            . ' THEN COALESCE(ca.cart_last_at, ?) ELSE COALESCE(oa.paid_last_at, ?) END)';
    }

    /** Everything the operator typed, except the chip. */
    private function baseQuery(Request $request): Builder
    {
        $query = $this->rowQuery();

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            // Escaped the way Catalog → Products escapes it: somebody searching
            // for "100%" must not turn it into a wildcard.
            $like = '%' . $this->escapeLike($search) . '%';

            $query->where(function ($q) use ($like, $search) {
                foreach ([
                    'customers.name',
                    'customers.first_name',
                    'customers.last_name',
                    'customers.email',
                    'customers.phone',
                ] as $column) {
                    $q->orWhereRaw($column . ' like ? escape ' . self::LIKE_ESCAPE_SQL, [$like]);
                }

                // Typing an id finds that customer, and typing a WooCommerce
                // user id finds the row it was imported into — the only way to
                // answer "did this Woo user come across?".
                if (ctype_digit($search)) {
                    $q->orWhere('customers.id', '=', (int) $search)
                        ->orWhere('customers.wp_user_id', '=', (int) $search);
                }
            });
        }

        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        /*
         * An imported customer can have no registration date at all. A date
         * filter excludes them, which is correct; the unfiltered list still
         * shows them, with an em dash rather than a crash.
         *
         * A SHOP-LOCAL DAY AGAINST A UTC COLUMN. The owner types a date on the
         * shop's clock; `customers.created_at` holds a UTC instant. Comparing
         * the two as calendar days -- which is what the date-only builder
         * helper used here did -- filed a customer who registered at 01:30
         * Dubai under the previous day, while the row beside it showed the
         * Dubai date StoreTime::iso() renders into `registered_at`. The screen
         * disagreed with itself at both edges of every range, which is worse
         * than being uniformly wrong: neither number could be trusted because
         * they could not both be right.
         *
         * shopDayStartUtc() converts each shop-local boundary to the UTC
         * instant it actually falls at. The range is HALF-OPEN -- `to` becomes
         * the start of the day after the one typed -- so the operator's last
         * day is included whole at any column precision. A closing bound of
         * 23:59:59 silently drops the last second of it on MySQL's DATETIME(6).
         */
        $fromAt = $this->shopDayStartUtc($from);
        $toAt = $this->shopDayStartUtc($to, 1);

        if ($fromAt !== null) {
            $query->where('customers.created_at', '>=', $fromAt);
        }

        if ($toAt !== null) {
            $query->where('customers.created_at', '<', $toAt);
        }

        $spendMin = $request->query('spend_min');
        $spendMax = $request->query('spend_max');

        if ($spendMin !== null && trim((string) $spendMin) !== '') {
            $query->whereRaw('COALESCE(oa.spend_fils, 0) >= ?', [Money::fromMajor((float) $spendMin)]);
        }

        if ($spendMax !== null && trim((string) $spendMax) !== '') {
            $query->whereRaw('COALESCE(oa.spend_fils, 0) <= ?', [Money::fromMajor((float) $spendMax)]);
        }

        $country = strtoupper(trim((string) $request->query('country', '')));

        if ($country !== '') {
            $query->where('ad.country', '=', $country);
        }

        $city = trim((string) $request->query('city', ''));

        if ($city !== '') {
            $query->whereRaw('ad.city like ? escape ' . self::LIKE_ESCAPE_SQL, ['%' . $this->escapeLike($city) . '%']);
        }

        return $query;
    }

    /**
     * The UTC instant at which a shop-local calendar day begins, for a date the
     * operator typed into a filter box -- or null when they typed nothing, or
     * nothing usable.
     *
     * WHY THIS EXISTS. Storage is UTC and the owner reads and types Dubai; see
     * App\Support\StoreTime for the whole argument, including why moving
     * APP_TIMEZONE would reinterpret every stored instant instead of converting
     * it. This is the query-bound direction of that conversion.
     *
     * $plusDays lets a caller ask for the start of the day AFTER the one typed,
     * which is how a closing bound covers the whole of the operator's last day
     * without naming a last second that the column's precision might outrun.
     * The step is taken on the SHOP's clock and re-read as a date rather than
     * by adding 24 hours to a UTC instant, so a zone with a DST transition
     * (none in Dubai, but the zone is a setting and Europe/London is a legal
     * value) cannot make "the next day" 23 or 25 hours long.
     *
     * The result is a CarbonImmutable and is handed to the builder as one,
     * NEVER as a string: SQLite compares TEXT, and 'T' (0x54) sorts above
     * ' ' (0x20), so an ISO-8601 string bound drops the whole boundary day
     * there while MySQL coerces it and does not.
     *
     * A bound that is not a real Y-m-d is treated as absent rather than as a
     * bound matching nothing. The screen's inputs are `type="date"`, so they
     * can only ever send Y-m-d or empty and this path is unreachable from the
     * UI; a hand-made request that reached it used to return a silently empty
     * list, which is the same confidently-wrong screen this lane is here to
     * remove. Ignoring the malformed half shows the operator more rows than
     * they asked for, never fewer, and never 500s.
     */
    private function shopDayStartUtc(string $day, int $plusDays = 0): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        try {
            $local = CarbonImmutable::createFromFormat('!Y-m-d', $day, StoreTime::timezone());
        } catch (\Throwable) {
            return null;
        }

        if (! $local instanceof CarbonImmutable) {
            return null;
        }

        return StoreTime::startOfDayUtc($local->addDays($plusDays)->format('Y-m-d'));
    }

    private function segment(Request $request): string
    {
        $segment = (string) $request->query('filter', 'all');

        return in_array($segment, self::SEGMENTS, true) ? $segment : 'all';
    }

    private function applySegment(Builder $query, string $segment): Builder
    {
        return match ($segment) {
            'ordered' => $query->whereRaw('COALESCE(oa.paid_orders, 0) > 0'),
            'never' => $query->whereRaw('COALESCE(oa.paid_orders, 0) = 0'),
            'repeat' => $query->whereRaw('COALESCE(oa.paid_orders, 0) >= 2'),
            'account' => $query->where(fn ($q) => $q->whereNotNull('customers.password')
                ->orWhereNotNull('customers.legacy_password')),
            'guest' => $query->whereNull('customers.password')->whereNull('customers.legacy_password'),
            'verified' => $query->whereNotNull('customers.email_verified_at'),
            'unverified' => $query->whereNull('customers.email_verified_at'),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
    }

    /**
     * Every chip's count in one statement.
     *
     * Eight separate COUNT queries would also have been constant-time, but this
     * screen already runs five statements and each one on this host is a round
     * trip over a socket shared with the storefront.
     */

    private function segmentCounts(Builder $base, Request $request): array
    {
        $row = $this->aggregate(
            $base,
            'COUNT(*) as c_all,
             SUM(CASE WHEN COALESCE(oa.paid_orders, 0) > 0 THEN 1 ELSE 0 END) as c_ordered,
             SUM(CASE WHEN COALESCE(oa.paid_orders, 0) = 0 THEN 1 ELSE 0 END) as c_never,
             SUM(CASE WHEN COALESCE(oa.paid_orders, 0) >= 2 THEN 1 ELSE 0 END) as c_repeat,
             SUM(CASE WHEN customers.password IS NOT NULL OR customers.legacy_password IS NOT NULL THEN 1 ELSE 0 END) as c_account,
             SUM(CASE WHEN customers.password IS NULL AND customers.legacy_password IS NULL THEN 1 ELSE 0 END) as c_guest,
             SUM(CASE WHEN customers.email_verified_at IS NOT NULL THEN 1 ELSE 0 END) as c_verified,
             SUM(CASE WHEN customers.email_verified_at IS NULL THEN 1 ELSE 0 END) as c_unverified'
        );

        $trashed = $this->applySegment($this->baseQuery($request), 'trashed')
            ->toBase()
            ->getCountForPagination();

        return [
            'all' => (int) ($row->c_all ?? 0),
            'ordered' => (int) ($row->c_ordered ?? 0),
            'never' => (int) ($row->c_never ?? 0),
            'repeat' => (int) ($row->c_repeat ?? 0),
            'account' => (int) ($row->c_account ?? 0),
            'guest' => (int) ($row->c_guest ?? 0),
            'verified' => (int) ($row->c_verified ?? 0),
            'unverified' => (int) ($row->c_unverified ?? 0),
            'trashed' => (int) $trashed,
        ];
    }

    /**
     * The four figures across the top of the screen, for the filtered view.
     *
     * The store-wide average order value is total spend over total ORDERS, not
     * the mean of each customer's average — those are different numbers, and
     * the second one is the wrong one. Integer division again.
     */
    private function summaryFor(Builder $query): array
    {
        $row = $this->aggregate(
            $query,
            'COUNT(*) as customers,
             COALESCE(SUM(COALESCE(oa.paid_orders, 0)), 0) as orders,
             COALESCE(SUM(COALESCE(oa.spend_fils, 0)), 0) as spend'
        );

        $customers = (int) ($row->customers ?? 0);
        $orders = (int) ($row->orders ?? 0);
        $spend = (int) ($row->spend ?? 0);
        $aov = $orders > 0 ? intdiv($spend, $orders) : 0;

        return [
            'customers' => $customers,
            'orders' => $orders,
            'spend_fils' => $spend,
            'spend_display' => Money::plain($spend),
            'aov_fils' => $aov,
            'aov_display' => Money::plain($aov),
        ];
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        $never = self::NEVER;

        return match ($sort) {
            'oldest' => $query->orderBy('customers.created_at')->orderBy('customers.id'),
            // COALESCE/NULLIF because an imported customer can have no name at
            // all; sorting by name must not bury every one of them together
            // under a blank.
            'name' => $query->orderByRaw('COALESCE(NULLIF(customers.name, ?), customers.email) asc', [''])
                ->orderBy('customers.id'),
            'spend_desc' => $query->orderByRaw('COALESCE(oa.spend_fils, 0) desc')->orderByDesc('customers.id'),
            'spend_asc' => $query->orderByRaw('COALESCE(oa.spend_fils, 0) asc')->orderBy('customers.id'),
            'orders_desc' => $query->orderByRaw('COALESCE(oa.paid_orders, 0) desc')->orderByDesc('customers.id'),
            'aov_desc' => $query->orderByRaw(
                'CASE WHEN COALESCE(oa.paid_orders, 0) > 0'
                . ' THEN COALESCE(oa.spend_fils, 0) / COALESCE(oa.paid_orders, 1) ELSE 0 END desc'
            )->orderByDesc('customers.id'),
            'last_order_desc' => $query->orderByRaw('COALESCE(oa.paid_last_at, ?) desc', [$never])
                ->orderByDesc('customers.id'),
            'last_active_desc' => $query->orderByRaw(
                $this->lastActiveExpression() . ' desc',
                [$never, $never, $never, $never]
            )->orderByDesc('customers.id'),
            default => $query->orderByDesc('customers.created_at')->orderByDesc('customers.id'),
        };
    }

    /**
     * Order count and spend for a handful of ids, for the delete confirmations.
     *
     * One grouped query for the whole set rather than one per customer — the
     * bulk path would otherwise be 500 round trips behind a single click.
     *
     * @param  list<int>  $ids
     * @return array<int, array{orders:int, spend:int}>
     */
    private function orderStatsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // Net of refunds, for the same reason and by the same definition as
        // rowQuery() above: the "this customer has AED X of history" line on a
        // delete confirmation must be the figure the list showed, or the owner
        // is being asked to confirm against a number they have not seen.
        $refunded = DB::table('refunds')
            ->whereIn('status', \App\Services\Payments\PaymentRefunder::COUNTED)
            ->groupBy('order_id')
            ->selectRaw('order_id, COALESCE(SUM(amount), 0) as refunded_fils');

        return Order::query()
            ->leftJoinSub($refunded, 'rf', 'rf.order_id', '=', 'orders.id')
            ->whereIn('orders.customer_id', $ids)
            ->whereIn('orders.status', Order::REAL_STATUSES)
            ->groupBy('orders.customer_id')
            ->selectRaw('orders.customer_id as customer_id, COUNT(*) as n,'
                . ' COALESCE(SUM(CASE WHEN orders.total - COALESCE(rf.refunded_fils, 0) > 0'
                . ' THEN orders.total - COALESCE(rf.refunded_fils, 0) ELSE 0 END), 0) as s')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->customer_id => [
                'orders' => (int) $r->n,
                'spend' => (int) $r->s,
            ]])
            ->all();
    }

    /** Countries that actually appear in the address book, for the filter. */
    private function knownCountries(): array
    {
        return DB::table('addresses')
            ->whereNotNull('country')
            ->where('country', '!=', '')
            // `distinct()` makes `country` the unique key of this result, so
            // the order is already total; the LIMIT is a safety bound on a
            // column with at most a couple of hundred distinct values.
            ->distinct()
            ->orderBy('country')
            ->limit(300)
            ->pluck('country')
            ->map(fn ($c) => strtoupper((string) $c))
            ->unique()
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------ formatting */

    /**
     * The demo-seeded customer ids, read once per request.
     *
     * Memoised on the INSTANCE, not in a static: a controller is constructed
     * per request, so the memo lasts exactly as long as the answer can be
     * relied on. A process-level static would be the trap CLAUDE.md records
     * against Setting::map() -- a queue worker or a test process would hold
     * one answer across a seed or a removal.
     *
     * Null means "not asked yet", which is not the same as the empty array
     * that means "nothing has been seeded".
     *
     * @var array<int, true>|null
     */
    private ?array $demoCustomerIds = null;

    /** @return array<int, true> */
    private function demoCustomerIds(): array
    {
        return $this->demoCustomerIds ??= DemoSeed::idsFor(Customer::class);
    }

    /**
     * One row, as an explicit allowlist of fields.
     *
     * Nothing here is the model. `customers` holds a bcrypt hash, a WordPress
     * phpass hash and a remember token, and this endpoint returns personal data
     * — email, phone, home city — for every shopper the store has. The rule
     * behind Product::toApi() and SettingController::PUBLIC_KEYS applies with
     * more force on an admin screen, not less.
     */
    private function rowToApi(object $c): array
    {
        $orders = (int) $c->paid_orders;
        $spend = (int) $c->spend_fils;

        // Integer division. An average of 33.333 dirhams is 3333 fils, and a
        // float here would be the one place money drifts.
        $aov = $orders > 0 ? intdiv($spend, $orders) : 0;

        $name = trim((string) ($c->name ?? ''));

        if ($name === '') {
            $name = trim(((string) ($c->first_name ?? '')) . ' ' . ((string) ($c->last_name ?? '')));
        }

        $demo = $this->demoCustomerIds();

        return [
            'id' => (int) $c->id,
            // The import mapping, on the row, on the screen. Null means this
            // customer was created on this store rather than brought across.
            'wp_user_id' => $c->wp_user_id === null ? null : (int) $c->wp_user_id,
            'name' => $name === '' ? null : $name,
            'first_name' => $this->blankToNull($c->first_name),
            'last_name' => $this->blankToNull($c->last_name),
            'email' => (string) $c->email,
            'phone' => $this->blankToNull($c->phone),
            'account_type' => ((int) $c->has_login) === 1 ? 'account' : 'guest',
            'email_verified' => $c->email_verified_at !== null,
            'whatsapp_optin' => (bool) $c->whatsapp_optin,
            'registered_at' => $this->iso($c->created_at),
            'orders' => $orders,
            'orders_all' => (int) $c->all_orders,
            'spend_fils' => $spend,
            'spend_display' => Money::plain($spend),
            'aov_fils' => $aov,
            'aov_display' => Money::plain($aov),
            'last_order_at' => $this->iso($c->paid_last_at),
            'last_active_at' => $this->iso($c->last_active_at),
            'city' => $this->blankToNull($c->addr_city),
            'state' => $this->blankToNull($c->addr_state),
            'country' => $this->blankToNull($c->addr_country),
            'trashed' => $c->deleted_at !== null,
            /*
             * FIGURES EXCLUDE DEMO ROWS; LISTS SHOW THEM AND SAY SO.
             *
             * Store -> Demo Content seeds sample customers so the panel can be
             * explored before real data exists, and putting them on screens is
             * the whole point of the feature -- hiding them would break it. So
             * a demo customer stays on this list and carries the fact, and the
             * screen badges it.
             *
             * It matters here more than on most lists because the spend column
             * beside it is computed from REAL orders only (see rowQuery), so a
             * demo customer reads AED 0 lifetime spend. Without this flag that
             * row is indistinguishable from a real shopper who has never
             * bought anything, and the owner has no way to tell which of the
             * two they are looking at. Same definition of "demo" as the
             * Dashboard, Analytics and the Orders list use -- App\Support\DemoSeed,
             * which answers from `demo_seed_log` rather than a column.
             */
            'is_demo' => isset($demo[(int) $c->id]),
        ];
    }

    /**
     * ISO-8601, whether the value arrived as a Carbon instance or a raw string,
     * and NULL for the "never active" sentinel however the driver spells it.
     *
     * This used to test `$value === self::NEVER`, an exact 19-character string
     * compare, and that is driver-dependent. lastActiveExpression() is a CASE,
     * and a CASE takes the widest type of its branches: on MySQL 8 the branches
     * are DATETIME(6) columns, so the bound sentinel comes back widened to
     * '1970-01-01 00:00:00.000000' — 26 characters. The compare missed, the
     * sentinel was parsed as a real date, and every customer who had never
     * ordered and never had a cart showed a last-active date of 1 Jan 1970 on
     * the live screen. SQLite returns the string unchanged and so does MariaDB
     * 10.11, which is why nothing caught it: it needs the production engine.
     *
     * Compared as an instant rather than as text, so the fractional seconds,
     * the driver and the column precision all stop mattering. Anything at or
     * before the sentinel is "never" — this store has no activity from 1969.
     */
    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $when = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        if ($when->getTimestamp() <= Carbon::parse(self::NEVER)->getTimestamp()) {
            return null;
        }

        /*
         * Converted to the shop's clock on the way out -- every date this
         * screen shows funnels through here, so one conversion covers the
         * joined date, the last order and the last activity at once.
         *
         * CONVERTED, not reinterpreted: the instant is unchanged and the string
         * still carries an offset, exactly as toIso8601String() did, so the
         * console's positional slice() calls keep working. Without it these
         * were UTC days, and a customer who first ordered at 01:30 in Dubai
         * joined "yesterday". See App\Support\StoreTime.
         */
        return StoreTime::iso($when);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Back-ported from OrdersApiController, which found the trap.
     *
     * str_replace(['\\', '%', '_'], ...) with a plain ->where(…, 'like', …) is
     * dialect-dependent: MySQL treats a backslash as the default LIKE escape,
     * SQLite has NO default escape at all. The same pattern that finds
     * "KBB-100%-OFF" on production matches nothing under the suite — green
     * here, and a search box behaving differently where nobody is measuring.
     *
     * An explicit ESCAPE '!' removes the difference. Both engines accept it,
     * and '!' has no special meaning in a string literal on either, so there is
     * no second layer of escaping to get wrong. The escape character is doubled
     * first, or a term containing '!' would escape the character after it.
     */
    private const LIKE_ESCAPE = '!';

    /** The literal as written into SQL. No binding: it is a constant. */
    private const LIKE_ESCAPE_SQL = "'!'";

    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term
        );
    }

    /**
     * Neutralise a spreadsheet formula before it reaches a cell.
     *
     * Excel, LibreOffice and Sheets all execute a cell beginning =, +, - or @,
     * and a leading tab or carriage return sneaks past a naive check of the
     * first character. Every value in this export is supplied by the public:
     * name, email and phone are typed at checkout. Prefixing a single quote is
     * the mitigation those applications understand — the cell reads as text and
     * the original characters survive for anyone reading the raw file.
     */
    private function csvCell(mixed $value): string
    {
        $string = (string) $value;

        if ($string !== '' && str_contains("=+-@\t\r", $string[0])) {
            return "'" . $string;
        }

        return $string;
    }

    /**
     * Fils as a plain decimal string, for a spreadsheet column.
     *
     * Built by integer division, not by dividing a float, and without the
     * thousands separators Money::amount() adds — a grouped number stops being
     * a number once it is in a CSV.
     */
    private function majorString(int $fils): string
    {
        $exponent = Money::minorExponent();

        $sign = $fils < 0 ? '-' : '';
        $abs = abs($fils);

        if ($exponent <= 0) {
            return $sign . $abs;
        }

        $unit = 10 ** $exponent;

        return $sign . intdiv($abs, $unit) . '.' . str_pad((string) ($abs % $unit), $exponent, '0', STR_PAD_LEFT);
    }

    private function clampPerPage(int $requested): int
    {
        if ($requested <= 0) {
            return self::PER_PAGE_DEFAULT;
        }

        return max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $requested));
    }
}
