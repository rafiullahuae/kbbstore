<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;

/**
 * A gateway whose authorisation can be RELEASED without taking the money.
 *
 * The third thing a held payment can do, and until this existed there was no
 * way to say it. SettlesPayments covers taking the money (capture) and giving
 * it back after it moved (refund). Neither describes the case the merchant
 * actually meets most often on a BNPL order: the order is cancelled before
 * anything is captured, and the shopper is left with a live authorisation —
 * against their Tabby credit limit, on their Tabby account, in their app —
 * for goods they are not getting.
 *
 * WHY IT MATTERS EVEN THOUGH THE PROVIDER EVENTUALLY VOIDS IT ANYWAY
 *
 * Tabby auto-voids an uncaptured authorisation, so "do nothing" is not
 * technically a loss of money. What it is, is up to a month of a customer's
 * credit limit consumed by a cancelled order, and a payment the reconciler
 * reports as AUTHORISED against an order that is `cancelled` — a discrepancy
 * with no action attached to it, every day, until the provider's own timer runs
 * out. Releasing it deliberately turns that into a closed pair.
 *
 * Three rules, which are SettlesPayments' rules and read the same way for the
 * same reasons:
 *
 *   1. **Re-read the provider's live status first.** Whether an authorisation
 *      can still be released is the provider's answer, never ours, and a void
 *      posted against a CAPTURED payment must be refused rather than sent — at
 *      best it is rejected, at worst a provider treats it as a full refund.
 *   2. **Already released is a success.** A retry after a dropped connection
 *      settles rather than alarms, exactly as `already_captured` does.
 *   3. **Never throw.** Return SettlementResult::failed() and let the caller
 *      write it down.
 */
interface VoidsAuthorisations
{
    /**
     * Release an authorisation nobody captured.
     *
     * Returns ok() with `voided` when this call released it, ok() with
     * `already_voided` when it was already released, and failed() otherwise —
     * including, and especially, when the payment has been captured and the
     * money the caller wants released has already moved.
     */
    public function voidAuthorisation(Order $order): SettlementResult;
}
