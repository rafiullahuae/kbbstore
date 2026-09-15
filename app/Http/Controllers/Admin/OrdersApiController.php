<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderNote;
use App\Services\Payments\PaymentRefunder;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Store → Orders.
 *
 * WHAT WAS HERE BEFORE. AdminController::orders() returned every order the
 * store has ever taken in one array, and the screen counted the chips by
 * filtering that array in the browser. No pagination, no search, no date or
 * value filter, no export, and the totals were whatever the client had already
 * been handed. Pointed at the 2,419 orders the WooCommerce import brings across
 * that is one enormous response per visit and a chip count that is only ever as
 * right as the page happens to be. That endpoint stays registered — routes/web.php
 * is not this lane's to edit — but nothing reads it any more; see
 * routes/orders-admin.php.
 *
 * WHAT THIS DOES NOT TOUCH. The order DETAIL screen already exists and is
 * already good: AdminOrderController::show backs it, PaymentSettlementController
 * backs the capture panel beside it, and AdminOrderController::refund moves real
 * money through PaymentRefunder. None of that is reimplemented here and no
 * route below overlaps theirs. This controller is the LIST — and the list's View
 * button opens the screen those controllers already serve.
 *
 * WHAT COUNTS AS REVENUE. Order::REAL_STATUSES — processing, onhold, shipped,
 * completed — the same definition the dashboard's revenue figure, Catalog →
 * Reorder and Store → Customers all read. A cancelled, failed, pending, draft or
 * refunded order is a row on this screen but it is not money, and the summary
 * says so. Every row carries counts_as_revenue so the screen never has to
 * re-derive that rule in JavaScript and get it subtly different.
 *
 * WHICH STATUSES EXIST. Not a hard-coded list. `orders.status` is a free-form
 * string precisely so imported Woo statuses survive — the schema's own comment
 * names wc-shipped and wc-tamara-p-failed — so the chips are built from a
 * DISTINCT over the column, with the statuses this application writes itself
 * always present even at a count of zero. A hard-coded vocabulary would quietly
 * hide every imported order whose status nobody thought of.
 *
 * MONEY. Integer fils (AED x 100) end to end. SUM() in SQL, intdiv() for the
 * average order value, Money::plain() for display. No float is constructed
 * anywhere on the path, including in the CSV, where majorString() builds the
 * decimal by integer division.
 *
 * ONE QUERY, NOT ONE PER ROW. /shop once ran 390 queries for four products in
 * this repo. The list is a fixed number of statements whatever the order count:
 * two grouped derived tables (line items, refunds) and one plain join
 * (customers) are LEFT JOINed onto `orders`, so unit counts, refunded totals and
 * the buyer's name all arrive with the page of rows.
 *
 * GUEST AND IMPORTED ORDERS. customer_id is nullable and on an import of Woo
 * guest orders it is null for a large share of the table. Such an order still
 * has to show a name, so the name falls back to billing_address, which is JSON
 * on the row itself — decoded in PHP, never with a dialect-specific JSON
 * function in SQL. Missing phone, missing city and historical created_at dates
 * are all expected rather than exceptional.
 *
 * WHAT IS DELIBERATELY NOT RETURNED. `orders` carries ip_address and two full
 * JSON address blobs. The list and the export expose neither: a name, a city and
 * a country are what a list needs, and the customer's IP and street address are
 * already on the detail screen where looking at them is a deliberate act. The
 * rule behind Product::toApi() and SettingController::PUBLIC_KEYS applies with
 * more force to an admin screen holding every buyer's contact details, not less.
 */
class OrdersApiController extends Controller
{
    use \App\Support\AggregatesQueries;

    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MIN = 10;
    private const PER_PAGE_MAX = 500;

    /** Hard ceiling on one CSV. A shared host is not a reporting server. */
    private const EXPORT_MAX = 50000;

    /** Rows per database round trip while streaming the CSV. */
    private const EXPORT_CHUNK = 500;

    /** Ceiling on one bulk action, so a stuck loop cannot rewrite the table. */
    private const BULK_MAX = 200;

    /**
     * Statuses this application itself writes, so they are offered as chips
     * even before any order has reached them. Imported statuses are added to
     * this from the column at query time — see statusCounts().
     *
     * Sourced from the vocabulary already in the codebase: AdminOrderController
     * writes 'cancelled' and 'draft', PaymentRefunder writes 'refunded',
     * checkout writes 'pending', and Order::REAL_STATUSES names the four that
     * count as revenue.
     */
    private const KNOWN_STATUSES = [
        'draft', 'pending', 'processing', 'onhold', 'shipped',
        'completed', 'cancelled', 'refunded', 'failed',
    ];

    /**
     * Statuses a bulk action may SET.
     *
     * 'refunded' is absent on purpose. That status is written by
     * PaymentRefunder when the refunded total reaches the captured total, and
     * it is a statement about money that actually moved. A bulk button that
     * could set it would be a way to make the books say a refund happened
     * without one having happened.
     */
    private const BULK_SETTABLE = ['pending', 'processing', 'onhold', 'shipped', 'completed', 'cancelled'];

    /* ------------------------------------------------------------------ list */

    public function index(Request $request): JsonResponse
    {
        /*
         * A database error here is reported, not swallowed — the same decision
         * Store → Customers made after a live 500 cost a diagnostic release to
         * even name. Only the driver's message is returned, never the SQL and
         * never the bindings; the bindings carry the operator's search term.
         */
        try {
            return $this->listing($request);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'The order list could not be read from the database.',
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

        // Everything the operator typed EXCEPT the chip, so the chip counts
        // describe the list the other filters have already narrowed to.
        $base = $this->baseQuery($request);

        $counts = $this->statusCounts($base, $request);

        $query = $this->applyFilter(clone $base, $filter);

        $total = (clone $query)->toBase()->getCountForPagination();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = $this->applySort($query, $sort)
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($o) => $this->rowToApi($o))
            ->values();

        return response()->json([
            'orders' => $rows,
            'summary' => $this->summaryFor($query),
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'counts' => $counts['counts'],
            // Chip order, so the screen renders the same sequence every time
            // instead of whatever order the database felt like returning.
            'statuses' => $counts['statuses'],
            'revenue_statuses' => Order::REAL_STATUSES,
            'currency' => Money::currency(),
        ]);
    }

    /* ---------------------------------------------------------------- export */

    /**
     * CSV of the CURRENT filtered view — the same rows, in the same order, as
     * the screen the operator is looking at.
     *
     * Streamed in chunks, because a shared host will not hold 2,419 orders and
     * their aggregates in memory alongside the request. Every cell goes through
     * csvCell(): a buyer whose billing name is `=HYPERLINK(...)` must not become
     * a live formula when the owner opens the file, and every name, email and
     * phone in this table was typed by the public at checkout.
     */
    public function export(Request $request): StreamedResponse
    {
        $filter = $this->filter($request);
        $sort = (string) $request->query('sort', 'newest');

        $query = $this->applySort(
            $this->applyFilter($this->baseQuery($request), $filter),
            $sort
        )->limit(self::EXPORT_MAX);

        $filename = 'orders-' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM: without it Excel on Windows reads an Arabic or accented
            // billing name as mojibake, and this order table has both.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'id', 'wc_order_id', 'order_number', 'status', 'counts_as_revenue',
                'customer_id', 'customer_name', 'email', 'phone', 'city', 'country',
                'units', 'lines', 'total_fils', 'total', 'refunded_fils', 'refunded',
                'net_fils', 'net', 'payment', 'placed_at', 'paid_at', 'completed_at',
                'trashed', 'currency',
            ]);

            $currency = Money::currency();

            $query->chunk(self::EXPORT_CHUNK, function ($chunk) use ($out, $currency) {
                foreach ($chunk as $order) {
                    $row = $this->rowToApi($order);

                    fputcsv($out, array_map($this->csvCell(...), [
                        $row['id'],
                        $row['wc_order_id'] ?? '',
                        $row['order_number'],
                        $row['status'],
                        $row['counts_as_revenue'] ? 'yes' : 'no',
                        $row['customer_id'] ?? '',
                        $row['customer_name'] ?? '',
                        $row['email'] ?? '',
                        $row['phone'] ?? '',
                        $row['city'] ?? '',
                        $row['country'] ?? '',
                        $row['units'],
                        $row['lines'],
                        $row['total_fils'],
                        $this->majorString($row['total_fils']),
                        $row['refunded_fils'],
                        $this->majorString($row['refunded_fils']),
                        $row['net_fils'],
                        $this->majorString($row['net_fils']),
                        $row['payment'] ?? '',
                        $row['placed_at'] ?? '',
                        $row['paid_at'] ?? '',
                        $row['completed_at'] ?? '',
                        $row['trashed'] ? 'yes' : 'no',
                        $currency,
                    ]));
                }
            });

            fclose($out);
        }, 200, $headers);
    }

    /* ----------------------------------------------------------------- bulk */

    /**
     * Set the status on a selection.
     *
     * Two refusals, both deliberate. 'refunded' cannot be set at all — see
     * BULK_SETTABLE. And moving a revenue-carrying order to a status that is
     * NOT revenue (cancelling four completed orders, say) takes the money off
     * the store's own figures, so without force those orders are skipped and
     * reported back by name rather than the whole call failing: the operator
     * asked for the selection, and the safe half of it is still what they meant.
     *
     * Every change writes an order note, in one insert for the whole batch, so
     * the detail screen's history shows who did it and when. A bulk edit with no
     * trace is how a store ends up unable to explain its own numbers.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::BULK_MAX],
            'ids.*' => ['integer'],
            'status' => ['required', 'string', 'in:' . implode(',', self::BULK_SETTABLE)],
        ]);

        $ids = $this->uniqueIds($data['ids']);
        $status = (string) $data['status'];
        $force = $request->boolean('force');

        $wasRevenue = in_array($status, Order::REAL_STATUSES, true);

        $orders = Order::query()
            ->whereIn('id', $ids)
            ->get(['id', 'order_number', 'status', 'total']);

        $changeable = [];
        $skipped = [];

        foreach ($orders as $order) {
            $current = (string) $order->status;

            if ($current === $status) {
                continue;
            }

            $losesRevenue = in_array($current, Order::REAL_STATUSES, true) && ! $wasRevenue;

            if ($losesRevenue && ! $force) {
                $skipped[] = [
                    'id' => (int) $order->id,
                    'label' => (string) $order->order_number,
                    'status' => $current,
                    'total_fils' => (int) $order->total,
                    'total_display' => Money::plain((int) $order->total),
                ];

                continue;
            }

            $changeable[] = (int) $order->id;
        }

        if ($changeable !== []) {
            Order::query()->whereIn('id', $changeable)->update([
                'status' => $status,
                'updated_at' => now(),
            ]);

            $this->noteAll($changeable, 'Status set to ' . $status . ' from the orders list.');
        }

        return response()->json([
            'ok' => true,
            'status' => $status,
            'changed' => count($changeable),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Move a selection to the trash. Reversible, and it refuses by default.
     *
     * Soft delete, so the rows and their line items survive and bulkRestore
     * brings them straight back. It is still money leaving the store's figures,
     * so an order that counts as revenue is skipped and reported unless force
     * says otherwise — the same shape as the guarded deletes on the customers,
     * brands and categories screens.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::BULK_MAX],
            'ids.*' => ['integer'],
        ]);

        $ids = $this->uniqueIds($data['ids']);
        $force = $request->boolean('force');

        $orders = Order::query()
            ->whereIn('id', $ids)
            ->get(['id', 'order_number', 'status', 'total']);

        $deletable = [];
        $skipped = [];

        foreach ($orders as $order) {
            if (in_array((string) $order->status, Order::REAL_STATUSES, true) && ! $force) {
                $skipped[] = [
                    'id' => (int) $order->id,
                    'label' => (string) $order->order_number,
                    'status' => (string) $order->status,
                    'total_fils' => (int) $order->total,
                    'total_display' => Money::plain((int) $order->total),
                ];

                continue;
            }

            $deletable[] = (int) $order->id;
        }

        if ($deletable !== []) {
            Order::query()->whereIn('id', $deletable)->delete();
        }

        return response()->json([
            'ok' => true,
            'deleted' => count($deletable),
            'skipped' => $skipped,
        ]);
    }

    /** Undo a trashing for a selection. Never destructive, so never guarded. */
    public function bulkRestore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::BULK_MAX],
            'ids.*' => ['integer'],
        ]);

        $ids = $this->uniqueIds($data['ids']);

        $restored = Order::query()->onlyTrashed()->whereIn('id', $ids)->restore();

        return response()->json(['ok' => true, 'restored' => (int) $restored]);
    }

    /* --------------------------------------------------------------- queries */

    /**
     * `orders` with every aggregate this screen needs already joined on.
     *
     * Two grouped derived tables and one plain join, evaluated once for the
     * whole page rather than once per row:
     *
     *   ia  order_items, for units and line count.
     *   ra  refunds, for the refunded total. Restricted to the same statuses
     *       PaymentRefunder::COUNTED sums, so this screen and the refund
     *       ceiling on the detail page can never disagree about how much of an
     *       order has been sent back.
     *   c   customers, for the account holder's name. A guest order joins to
     *       nothing here and falls back to billing_address in PHP.
     *
     * No select bindings are added. The revenue rule is applied in PHP from the
     * status string rather than as a CASE in the select list, which keeps the
     * binding slot empty and the aggregate() helper below simple.
     */
    private function rowQuery(?Builder $from = null): Builder
    {
        $items = DB::table('order_items')
            ->groupBy('order_id')
            ->selectRaw('order_id, COALESCE(SUM(quantity), 0) as units, COUNT(*) as lines_count');

        $refunds = DB::table('refunds')
            ->whereIn('status', PaymentRefunder::COUNTED)
            ->groupBy('order_id')
            ->selectRaw('order_id, COALESCE(SUM(amount), 0) as refunded_fils');

        return ($from ?? Order::query())
            ->leftJoinSub($items, 'ia', 'ia.order_id', '=', 'orders.id')
            ->leftJoinSub($refunds, 'ra', 'ra.order_id', '=', 'orders.id')
            ->leftJoin('customers as c', 'c.id', '=', 'orders.customer_id')
            ->select([
                // An explicit allowlist. `orders` also carries ip_address and
                // two full JSON address blobs; `customers` carries a bcrypt
                // hash, a WordPress phpass hash and a remember token. A screen
                // that selected either whole row would hand all of it to
                // anything that could reach this endpoint.
                'orders.id',
                'orders.wc_order_id',
                'orders.order_number',
                'orders.status',
                'orders.customer_id',
                'orders.email',
                'orders.phone',
                'orders.total',
                'orders.currency',
                'orders.payment_method',
                'orders.payment_method_title',
                'orders.shipping_method',
                'orders.coupon_code',
                // Selected for the name/city fallback on a guest order and
                // reduced to three fields in rowToApi(). Never returned raw.
                'orders.billing_address',
                'orders.shipping_address',
                'orders.captured_at',
                'orders.paid_at',
                'orders.completed_at',
                'orders.created_at',
                'orders.deleted_at',
                DB::raw('COALESCE(ia.units, 0) as units'),
                DB::raw('COALESCE(ia.lines_count, 0) as lines_count'),
                DB::raw('COALESCE(ra.refunded_fils, 0) as refunded_fils'),
                DB::raw('c.name as account_name'),
                DB::raw('c.first_name as account_first_name'),
                DB::raw('c.last_name as account_last_name'),
            ]);
    }

    /** Everything the operator typed, except the chip. */
    private function baseQuery(Request $request): Builder
    {
        $query = $this->rowQuery();

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            // Somebody searching for "100%" must not turn it into a wildcard.
            $like = '%' . $this->escapeLike($search) . '%';

            $columns = [
                'orders.order_number',
                'orders.email',
                'orders.phone',
                'c.name',
                'c.first_name',
                'c.last_name',
                // A GUEST order has no customers row, so the buyer's name
                // exists only inside the billing_address JSON. Matching the
                // stored text finds it on both MySQL and SQLite without a
                // dialect-specific JSON function; the cost is that a term which
                // happens to be a street or a city matches too. A name search
                // that silently cannot find half the orders on an imported
                // store would be the worse trade.
                'orders.billing_address',
            ];

            $query->where(function ($q) use ($columns, $like, $search) {
                foreach ($columns as $column) {
                    // The column names are literals from the list above, never
                    // anything the request supplies; only the pattern is bound.
                    $q->orWhereRaw($column . ' like ? escape ' . self::LIKE_ESCAPE_SQL, [$like]);
                }

                // Typing an id finds that order, and typing a WooCommerce order
                // id finds the row it was imported into — the only way to
                // answer "did Woo order 18422 come across?".
                if (ctype_digit($search)) {
                    $q->orWhere('orders.id', '=', (int) $search)
                        ->orWhere('orders.wc_order_id', '=', (int) $search);
                }
            });
        }

        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        // An imported order can have any date at all, including one years old.
        // whereDate rather than a string comparison so a date-only bound is
        // inclusive of the whole day on both dialects.
        if ($from !== '') {
            $query->whereDate('orders.created_at', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('orders.created_at', '<=', $to);
        }

        // Order value, in whole dirhams on the wire and fils in the comparison.
        // A real column, never a SELECT alias: MySQL will not have an alias in
        // a WHERE clause and SQLite accepting one is how that ships unnoticed.
        $min = $request->query('total_min');
        $max = $request->query('total_max');

        if ($min !== null && trim((string) $min) !== '') {
            $query->where('orders.total', '>=', Money::fromMajor((float) $min));
        }

        if ($max !== null && trim((string) $max) !== '') {
            $query->where('orders.total', '<=', Money::fromMajor((float) $max));
        }

        $payment = trim((string) $request->query('payment', ''));

        if ($payment !== '') {
            $query->where('orders.payment_method', '=', $payment);
        }

        return $query;
    }

    /**
     * The chip. 'all', 'paid' (every revenue status at once), 'trashed', or one
     * literal status out of the column.
     */
    private function filter(Request $request): string
    {
        return trim((string) $request->query('filter', 'all')) ?: 'all';
    }

    private function applyFilter(Builder $query, string $filter): Builder
    {
        if ($filter === 'all' || $filter === '') {
            return $query;
        }

        if ($filter === 'trashed') {
            return $query->onlyTrashed();
        }

        if ($filter === 'paid') {
            return $query->whereIn('orders.status', Order::REAL_STATUSES);
        }

        // Anything else is a literal status. An unknown one is not rewritten to
        // 'all': a chip that quietly showed every order when the operator asked
        // for one status would be a worse answer than an empty list.
        return $query->where('orders.status', '=', $filter);
    }

    /**
     * The same filtered set, but as an aggregate-only query.
     *
     * selectRaw() APPENDS to the select list, it does not replace it. A
     * "(clone $base)->toBase()->selectRaw('COUNT(*)')" therefore keeps all 24
     * allowlisted columns from rowQuery() and adds an aggregate after them with
     * no GROUP BY on the outer query. SQLite permits that and invents a row for
     * the bare columns; MySQL refuses it outright:
     *
     *   SQLSTATE[42000] 1140 Mixing of GROUP columns (MIN(),MAX(),COUNT(),...)
     *   with no GROUP columns is illegal if there is no GROUP BY clause
     *
     * which is exactly what the live Customers screen returned while every test
     * passed. The joins and the WHERE must survive — the figures describe the
     * filtered set — so only the columns are discarded, and the select bindings
     * go with them: leaving bindings behind for markers that no longer exist
     * sends the driver more values than the statement has placeholders.
     */

    /**
     * Every chip's count.
     *
     * One grouped statement over the whole filtered set for the per-status
     * counts, plus one for the trash — `orders` soft-deletes, so trashed rows
     * are outside the default scope and cannot be counted in the same pass.
     *
     * The status list returned alongside is the union of what this application
     * writes (KNOWN_STATUSES) and what the column actually holds, so an
     * imported `wc-tamara-p-failed` gets a chip rather than being invisible.
     *
     * @return array{counts: array<string,int>, statuses: list<string>}
     */
    private function statusCounts(Builder $base, Request $request): array
    {
        $rows = $this->aggregateQuery($base, 'orders.status as s, COUNT(*) as c')
            ->groupBy('orders.status')
            ->get();

        $counts = [];
        $all = 0;
        $paid = 0;

        foreach ($rows as $row) {
            $status = (string) ($row->s ?? '');
            $n = (int) $row->c;

            $counts[$status] = ($counts[$status] ?? 0) + $n;
            $all += $n;

            if (in_array($status, Order::REAL_STATUSES, true)) {
                $paid += $n;
            }
        }

        $trashed = (int) $this->applyFilter($this->baseQuery($request), 'trashed')
            ->toBase()
            ->getCountForPagination();

        $statuses = array_values(array_unique(array_merge(
            self::KNOWN_STATUSES,
            array_filter(array_keys($counts), fn ($s) => $s !== '')
        )));

        foreach ($statuses as $status) {
            $counts[$status] = $counts[$status] ?? 0;
        }

        $counts['all'] = $all;
        $counts['paid'] = $paid;
        $counts['trashed'] = $trashed;

        return ['counts' => $counts, 'statuses' => $statuses];
    }

    /**
     * The figures across the top of the screen, for the filtered view.
     *
     * Revenue is the total of orders in a REAL status only, refunds subtracted.
     * The average order value divides that revenue by the number of REVENUE
     * orders, not by every row — a screen filtered to "cancelled" has an
     * average of nothing, not a division by the wrong denominator. Integer
     * division throughout.
     */
    private function summaryFor(Builder $query): array
    {
        $marks = implode(',', array_fill(0, count(Order::REAL_STATUSES), '?'));

        $row = $this->aggregate(
            $query,
            "COUNT(*) as orders,
             COALESCE(SUM(orders.total), 0) as gross,
             COALESCE(SUM(COALESCE(ra.refunded_fils, 0)), 0) as refunded,
             SUM(CASE WHEN orders.status IN ($marks) THEN 1 ELSE 0 END) as paid_orders,
             COALESCE(SUM(CASE WHEN orders.status IN ($marks) THEN orders.total ELSE 0 END), 0) as paid_gross,
             COALESCE(SUM(CASE WHEN orders.status IN ($marks) THEN COALESCE(ra.refunded_fils, 0) ELSE 0 END), 0) as paid_refunded",
            array_merge(Order::REAL_STATUSES, Order::REAL_STATUSES, Order::REAL_STATUSES)
        );

        $orders = (int) ($row->orders ?? 0);
        $gross = (int) ($row->gross ?? 0);
        $refunded = (int) ($row->refunded ?? 0);
        $paidOrders = (int) ($row->paid_orders ?? 0);
        $revenue = (int) ($row->paid_gross ?? 0) - (int) ($row->paid_refunded ?? 0);
        $aov = $paidOrders > 0 ? intdiv($revenue, $paidOrders) : 0;

        return [
            'orders' => $orders,
            'paid_orders' => $paidOrders,
            'gross_fils' => $gross,
            'gross_display' => Money::plain($gross),
            'refunded_fils' => $refunded,
            'refunded_display' => Money::plain($refunded),
            'revenue_fils' => $revenue,
            'revenue_display' => Money::plain($revenue),
            'aov_fils' => $aov,
            'aov_display' => Money::plain($aov),
        ];
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'oldest' => $query->orderBy('orders.created_at')->orderBy('orders.id'),
            'number' => $query->orderBy('orders.order_number')->orderBy('orders.id'),
            'total_desc' => $query->orderByDesc('orders.total')->orderByDesc('orders.id'),
            'total_asc' => $query->orderBy('orders.total')->orderBy('orders.id'),
            'units_desc' => $query->orderByRaw('COALESCE(ia.units, 0) desc')->orderByDesc('orders.id'),
            'status' => $query->orderBy('orders.status')->orderByDesc('orders.id'),
            // COALESCE/NULLIF because a guest order has no customers row at
            // all; sorting by customer must not bury every guest together
            // under a blank. Falls back to the email, which is never null.
            'customer' => $query->orderByRaw('COALESCE(NULLIF(c.name, ?), orders.email) asc', [''])
                ->orderBy('orders.id'),
            default => $query->orderByDesc('orders.created_at')->orderByDesc('orders.id'),
        };
    }

    /**
     * One note against each of a batch of orders, in a single insert.
     *
     * @param  list<int>  $ids
     */
    private function noteAll(array $ids, string $content): void
    {
        if ($ids === []) {
            return;
        }

        $author = auth('admin')->user()?->name ?: 'Admin';
        $now = now();

        OrderNote::query()->insert(array_map(fn (int $id) => [
            'order_id' => $id,
            'author' => $author,
            'is_customer_note' => false,
            'content' => $content,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids));
    }

    /* ------------------------------------------------------------ formatting */

    /**
     * One row, as an explicit allowlist of fields.
     *
     * Nothing here is the model, and the two JSON address blobs selected for
     * the fallbacks are reduced to a name, a city and a country before they
     * leave. The customer's IP and street address are on the detail screen,
     * which is a deliberate act to open; a list of 50 rows is not.
     */
    private function rowToApi(object $o): array
    {
        $status = (string) $o->status;
        $total = (int) $o->total;
        $refunded = (int) $o->refunded_fils;

        $billing = $this->address($o->billing_address);
        $shipping = $this->address($o->shipping_address);

        $name = trim((string) ($o->account_name ?? ''));

        if ($name === '') {
            $name = trim(((string) ($o->account_first_name ?? '')) . ' ' . ((string) ($o->account_last_name ?? '')));
        }

        if ($name === '') {
            $name = trim(((string) ($billing['first_name'] ?? '')) . ' ' . ((string) ($billing['last_name'] ?? '')));
        }

        if ($name === '') {
            $name = trim((string) ($billing['name'] ?? ''));
        }

        return [
            'id' => (int) $o->id,
            // The import mapping, on the row, on the screen. Null means this
            // order was placed on this store rather than brought across.
            'wc_order_id' => $o->wc_order_id === null ? null : (int) $o->wc_order_id,
            'order_number' => (string) ($o->order_number ?? $o->id),
            'status' => $status,
            'counts_as_revenue' => in_array($status, Order::REAL_STATUSES, true),
            'customer_id' => $o->customer_id === null ? null : (int) $o->customer_id,
            'customer_name' => $name === '' ? null : $name,
            // A guest order has no customers row. Saying so on the row is how
            // the screen can show it without pretending the buyer has an
            // account it could link to.
            'guest' => $o->customer_id === null,
            'email' => $this->blankToNull($o->email),
            'phone' => $this->blankToNull($o->phone),
            'city' => $this->blankToNull($shipping['city'] ?? $billing['city'] ?? null),
            'country' => $this->blankToNull($shipping['country'] ?? $billing['country'] ?? null),
            'units' => (int) $o->units,
            'lines' => (int) $o->lines_count,
            'total_fils' => $total,
            'total_display' => Money::plain($total),
            'refunded_fils' => $refunded,
            'refunded_display' => Money::plain($refunded),
            // Never below zero: a refund recorded for more than the order total
            // is a data problem, not a negative amount the store owes itself.
            'net_fils' => max(0, $total - $refunded),
            'net_display' => Money::plain(max(0, $total - $refunded)),
            // The merchant's own wording snapshotted at checkout wins, exactly
            // as Order::paymentLabel() decides it. The gateway-registry lookup
            // that method falls back to is deliberately NOT done here: it is a
            // per-gateway config read, and a list of 50 rows is the wrong place
            // to do 50 of them. The detail screen still shows the full label.
            'payment' => $this->paymentLabel($o),
            'payment_method' => $this->blankToNull($o->payment_method),
            'shipping_method' => $this->blankToNull($o->shipping_method),
            'coupon_code' => $this->blankToNull($o->coupon_code),
            'captured' => $o->captured_at !== null,
            'placed_at' => $this->iso($o->created_at),
            'paid_at' => $this->iso($o->paid_at),
            'completed_at' => $this->iso($o->completed_at),
            'trashed' => $o->deleted_at !== null,
        ];
    }

    /** payment_method_title, else the humanised id, else nothing recorded. */
    private function paymentLabel(object $o): string
    {
        $title = trim((string) ($o->payment_method_title ?? ''));

        if ($title !== '') {
            return $title;
        }

        $code = trim((string) ($o->payment_method ?? ''));

        return $code === '' ? 'Not recorded' : ucfirst(str_replace(['_', '-'], ' ', $code));
    }

    /**
     * A stored address, whether it arrived already cast to an array or as the
     * raw JSON string a query-builder select hands back.
     *
     * Imported rows carry every shape there is: a well-formed object, an empty
     * string, the literal "null", and occasionally a JSON array. None of them
     * may throw — a malformed address on one order must not take out the page.
     *
     * @return array<string, mixed>
     */
    private function address(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** ISO-8601, whether the value arrived as a Carbon instance or a raw string. */
    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Make a search term literal inside a LIKE pattern, identically on both
     * dialects.
     *
     * The usual `str_replace(['\\', '%', '_'], ...)` and a plain ->where(…,
     * 'like', …) is a dialect trap. MySQL treats a backslash as the default
     * LIKE escape; SQLite has NO default escape at all, so the same pattern
     * that finds "KBB-100%-OFF" on production matches nothing under the test
     * suite — green here, and a search box that silently drops results only
     * where it is being measured.
     *
     * An explicit `ESCAPE '!'` removes the difference. Both engines accept the
     * clause, and '!' has no special meaning in a string literal on either, so
     * there is no second layer of escaping to get wrong. The escape character
     * itself is doubled first, or a term containing '!' would escape the
     * character after it.
     */
    private const LIKE_ESCAPE = '!';

    /** The literal as it is written into SQL. No binding: it is a constant. */
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
     * the billing name, the email and the phone are typed at checkout. Prefixing
     * a single quote is the mitigation those applications understand — the cell
     * reads as text and the original characters survive in the raw file.
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
