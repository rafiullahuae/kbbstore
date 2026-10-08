<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Ask the provider before the shop lets an unfinished order go. (Lane BK)
 *
 * The shop gives a basket back automatically when a shopper comes home from a
 * payment they did not finish (App\Services\Checkout\UnfinishedPayment). Before
 * it does, the gateway that took the order is asked what really happened, and
 * that answer is applied first: a payment the provider HAS taken is recorded
 * through PaymentConfirmer, so the order is paid and nothing is released — the
 * paid answer always wins.
 *
 * Every gateway whose journey() is not `placed` implements this. That is pinned
 * by UnfinishedPaymentEveryGatewayTest, so a gateway added later cannot reach
 * the shop without saying how its unfinished payments are settled.
 */
interface SettlesBeforeRelease
{
    /**
     * True when the provider says no money can move for this order any more
     * (cancelled, declined, expired, never opened) and the shop may release it.
     *
     * False when money moved (and has now been applied), or when the provider
     * could not be asked and $shopperCameBack is false. $shopperCameBack is true
     * when the provider ITSELF sent the shopper to our cancel or failure
     * address, which is its own statement that the payment is over.
     */
    public function settleBeforeRelease(Order $order, bool $shopperCameBack): bool;
}
