<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * A customer's orders, money and address, aggregated at query time — the ONE
 * definition Store → Customers and Growth & Marketing → Marketing Emails →
 * Customer groups both read. (Moved here from CustomersApiController::rowQuery()
 * by Lane MK; docs/EMAILS-PLAN.md §3: "That function is extracted into a shared
 * CustomerAggregates query builder (it moves and keeps its tests), not copied.")
 *
 * WHY ONE PLACE. A customer group that says "spent AED 500+" is a promise that
 * the same people are on Store → Customers when the owner filters it to AED
 * 500+. Two copies of this SQL is two definitions of "spent", and the first
 * time somebody fixes refunds in one of them the campaign goes to a different
 * list from the one he checked. tests/Feature/CustomerAggregatesTest.php pins
 * that the controller holds no copy of its own.
 *
 * WHERE THE NUMBERS COME FROM, AND WHY NOT FROM THE COLUMNS THAT LOOK RIGHT.
 * `customers` carries orders_count, total_spent and last_order_at. Nothing in
 * this application writes any of the three (Customer::UNMAINTAINED_COLUMNS), so
 * on a live install they read 0, 0 and NULL for every customer. Every figure
 * here is aggregated from `orders` instead.
 *
 * MONEY is an integer number of fils end to end.
 *
 * WHAT COUNTS AS AN ORDER: Order::REAL_STATUSES, net of counted refunds
 * (PaymentRefunder::COUNTED), demo orders excluded (DemoSeed). The all-statuses
 * count is kept beside it so a detail view can still list cancelled orders.
 */
final class CustomerAggregates
{
    /**
     * Sentinel for "no activity ever".
     *
     * MySQL's GREATEST() and SQLite's multi-argument max() both return NULL if
     * any argument is NULL, which would sort every never-active customer to the
     * same place as the most recent one. ISO-8601 sorts lexicographically the
     * same way it sorts chronologically, so this works as a string on SQLite
     * and as a datetime on MySQL.
     */
    public const NEVER = '1970-01-01 00:00:00';

    /**
     * `orders`, grouped per customer: the paid count, net spend, first and last
     * paid order, and the all-statuses count. One pass, conditional aggregates.
     */
    public static function ordersSubquery(): QueryBuilder
    {
        $real = Order::REAL_STATUSES;
        $marks = implode(',', array_fill(0, count($real), '?'));

        /*
         * SPEND IS NET OF REFUNDS.
         *
         * PaymentRefunder moves an order to 'refunded' only once the refunds
         * cover the whole captured amount, so a customer given AED 400 back on
         * a AED 1,000 order kept the whole AED 1,000 here until refunds were
         * netted out. That figure is what the owner uses to decide who his
         * best customers are, so being wrong in the generous direction is not
         * harmless: it promotes whoever returned the most.
         *
         * WHICH REFUND ROWS: PaymentRefunder::COUNTED — settled, or in flight
         * and reserved, and NOT the failed attempts. Same definition the order
         * screen, the dashboard and Analytics use.
         */
        $refunded = DB::table('refunds')
            ->whereIn('status', \App\Services\Payments\PaymentRefunder::COUNTED)
            ->groupBy('order_id')
            ->selectRaw('order_id, COALESCE(SUM(amount), 0) as refunded_fils');

        /*
         * Demo orders are not spend (Store -> Demo Content seeds eight of them
         * and logs each in `demo_seed_log`). Same definition of "demo" as the
         * Dashboard and Analytics use -- see App\Support\DemoSeed.
         */
        return DemoSeed::excludeQuery(DB::table('orders'), Order::class, 'orders.id')
            ->leftJoinSub($refunded, 'rf', 'rf.order_id', '=', 'orders.id')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.customer_id')
            ->groupBy('orders.customer_id')
            /*
             * The CASE around the subtraction floors a line at zero rather than
             * letting it go negative: an imported WooCommerce refund never
             * passed through PaymentRefunder's check, and one bad row must not
             * drag a customer's lifetime value below what they actually paid.
             * Written as CASE rather than MAX()/GREATEST(), which do not mean
             * the same thing on both engines.
             */
            ->selectRaw(
                "orders.customer_id as customer_id,
                 SUM(CASE WHEN orders.status IN ($marks) THEN 1 ELSE 0 END) as paid_orders,
                 COALESCE(SUM(CASE WHEN orders.status IN ($marks)
                     THEN (CASE WHEN orders.total - COALESCE(rf.refunded_fils, 0) > 0
                                THEN orders.total - COALESCE(rf.refunded_fils, 0) ELSE 0 END)
                     ELSE 0 END), 0) as spend_fils,
                 MAX(CASE WHEN orders.status IN ($marks) THEN orders.created_at END) as paid_last_at,
                 MIN(CASE WHEN orders.status IN ($marks) THEN orders.created_at END) as paid_first_at,
                 COUNT(*) as all_orders",
                array_merge($real, $real, $real, $real)
            );
    }

    /**
     * `customers` with every aggregate Store → Customers needs already joined
     * on (this was CustomersApiController::rowQuery()).
     *
     * Three grouped derived tables and one plain join, evaluated once for the
     * whole page rather than once per row:
     *
     *   oa  orders, conditionally aggregated (ordersSubquery()).
     *   ca  carts, for last-seen activity from someone who has never ordered.
     *   ap  addresses, reduced to one chosen address id per customer — the
     *       default if there is one, otherwise the oldest. COALESCE over two
     *       aggregates rather than a window function, because the production
     *       host is MySQL and ROW_NUMBER() is not safe to assume there.
     *
     * @param  Builder<Customer>|null  $from
     * @return Builder<Customer>
     */
    public static function query(?Builder $from = null): Builder
    {
        $carts = DB::table('carts')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(last_activity_at) as cart_last_at');

        $address = DB::table('addresses')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(MIN(CASE WHEN is_default = 1 THEN id END), MIN(id)) as addr_id');

        $never = self::NEVER;

        return ($from ?? Customer::query())
            ->leftJoinSub(self::ordersSubquery(), 'oa', 'oa.customer_id', '=', 'customers.id')
            ->leftJoinSub($carts, 'ca', 'ca.customer_id', '=', 'customers.id')
            ->leftJoinSub($address, 'ap', 'ap.customer_id', '=', 'customers.id')
            ->leftJoin('addresses as ad', 'ad.id', '=', 'ap.addr_id')
            ->select([
                // An explicit allowlist. `customers` also carries password,
                // legacy_password and remember_token; a screen that selected
                // the whole row would hand all three to anything that could
                // reach the endpoint.
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
                // Store -> Customers -> Send account invite (Lane PQ). The
                // dates and the count only; the token hash never leaves the row.
                'customers.invited_at',
                'customers.invite_count',
                'customers.invite_accepted_at',
                DB::raw('(CASE WHEN customers.password IS NOT NULL OR customers.legacy_password IS NOT NULL THEN 1 ELSE 0 END) as has_login'),
                DB::raw('COALESCE(oa.paid_orders, 0) as paid_orders'),
                DB::raw('COALESCE(oa.spend_fils, 0) as spend_fils'),
                DB::raw('COALESCE(oa.all_orders, 0) as all_orders'),
                DB::raw('oa.paid_last_at as paid_last_at'),
                DB::raw('ca.cart_last_at as cart_last_at'),
                DB::raw(self::lastActiveExpression() . ' as last_active_at'),
                DB::raw('ad.city as addr_city'),
                DB::raw('ad.state as addr_state'),
                DB::raw('ad.country as addr_country'),
            ])
            ->addBinding([$never, $never, $never, $never], 'select');
    }

    /** The later of "last paid order" and "last cart activity". Four `?`, each NEVER. */
    public static function lastActiveExpression(): string
    {
        return '(CASE WHEN COALESCE(ca.cart_last_at, ?) > COALESCE(oa.paid_last_at, ?)'
            . ' THEN COALESCE(ca.cart_last_at, ?) ELSE COALESCE(oa.paid_last_at, ?) END)';
    }
}
