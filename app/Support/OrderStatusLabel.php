<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The word a shopper reads for an order's status.
 *
 * ── WHY A CLASS AND NOT __() AT THE CALL SITE ───────────────────────────────
 *
 * `orders.status` is a free varchar, written by the checkout, by the admin, by
 * the payment gateways and by the WooCommerce import, and this application has
 * never held a closed list of the values it can hold. Two storefront views
 * printed it as `ucfirst(str_replace('-', ' ', $status))` — which is not a
 * translation, it is a formatting rule that happens to produce English.
 *
 * Writing __('store.order_status.'.$status) at the call site would have turned
 * an unrecognised value into the literal key on the page ("store.order_status.
 * awaiting-pickup") the first time anyone added one. So the lookup is guarded
 * here, once: a status this shop has wording for is translated, and anything
 * else falls back to exactly the formatting that shipped. Nothing new can
 * appear on a page that did not appear on it before.
 *
 * The English wording of each key is deliberately identical to what that
 * formatting produced, including 'Onhold' for the unhyphenated spelling, so the
 * English page is byte-for-byte what it was.
 */
final class OrderStatusLabel
{
    /** The status as a shopper should read it. */
    public static function for(?string $status): string
    {
        $status = trim((string) $status);

        if ($status === '') {
            return '';
        }

        $key = 'store.order_status.' . mb_strtolower($status);

        if (app('translator')->has($key)) {
            return (string) __($key);
        }

        return ucfirst(str_replace('-', ' ', $status));
    }
}
