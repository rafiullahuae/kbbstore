<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which orders are "this customer's", for the two places on the order screen
 * that answer it: the Customer history card on the right, and the "Order
 * history" popup beside the Customer field. (Lane PU)
 *
 * summary() is AdminOrderController::customerHistory() moved here verbatim --
 * same query, same figures, same keys -- so the card is byte-identical and the
 * popup's totals are the card's totals by construction rather than by care.
 *
 * A registered customer is matched by `customer_id`; a guest by the order's
 * email, exactly as the card always has. Trashed orders count, as they always
 * have on the card; the popup marks them rather than hiding them, or its count
 * and its list would disagree.
 */
final class OrderCustomerHistory
{
    public static function query(?int $customerId, string $email): Builder
    {
        return $customerId
            ? Order::withTrashed()->where('customer_id', $customerId)
            : Order::withTrashed()->where('email', $email);
    }

    /** @return array<string, mixed> */
    public static function summary(?int $customerId, string $email): array
    {
        $query = self::query($customerId, $email);

        $orders = $query->get(['id', 'total', 'tax_total', 'status']);
        $real = $orders->whereIn('status', Order::REAL_STATUSES);

        return [
            'total_orders' => $orders->count(),
            'total_revenue_aed' => Money::toAed((int) $real->sum('total')),
            'average_order_value_aed' => $real->count() > 0 ? Money::toAed((int) round($real->sum('total') / $real->count())) : 0,
            /*
             * WHAT THIS CUSTOMER WAS BILLED, VAT INCLUDED — Lane DU.
             *
             * `total` is the billed figure, so on an exclusive-tax order it
             * carries VAT the shop collects for the tax authority and does not
             * keep. This panel sits beside the order the operator is reading
             * and gets asked "how much has this customer spent with us" — two
             * different questions, and it should not answer the second with the
             * first and no note. The VAT inside the figure is published so the
             * screen can say so; the same disclosure the dashboard carries, in
             * AdminController::revenueBasis().
             */
            'tax_collected_aed' => Money::toAed((int) $real->sum('tax_total')),
            'revenue_basis' => \App\Http\Controllers\Admin\AdminController::revenueBasis(),
        ];
    }
}
