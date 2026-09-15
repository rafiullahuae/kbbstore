<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * A gateway that receives server-to-server callbacks.
 *
 * Cash on delivery does not implement this. Everything else does, and the
 * webhook controller will not route to a gateway that does not — an endpoint
 * that accepted a body for a gateway with no verification is precisely the
 * hole this interface exists to make impossible.
 *
 * The contract for every implementation:
 *
 *   1. Verify the signature FIRST, before parsing, before touching the
 *      database, before logging the body. Use hash_equals, never ==.
 *   2. Never trust an amount, a currency or an order state from the body.
 *      Take the order from our own table and compare.
 *   3. Be idempotent. Confirmation goes through PaymentConfirmer, which
 *      applies at most once per order.
 */
interface HandlesWebhooks
{
    public function handleWebhook(Request $request): WebhookOutcome;
}
