<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Refund;

/**
 * The result of one refund attempt.
 *
 * Four outcomes, and the difference between them decides both the HTTP status
 * the admin screen sees and whether the merchant should believe the money has
 * gone back:
 *
 *   applied    the provider took it. Money is on its way to the customer.
 *   duplicate  this exact refund was already made. Also a success — the
 *              caller asked for one refund and there is one refund — but no
 *              second call was made and no second row written.
 *   refused    we would not even try: over the captured amount, nothing
 *              captured, a zero amount. No provider call, no money moved.
 *   failed     we tried and the provider said no, or could not be reached.
 *              Recorded in `payment_events` with the provider's own code. The
 *              order is NOT marked refunded.
 *
 * `refused` and `failed` are deliberately distinct. One is our rule stopping a
 * mistake before it costs anything; the other is a call that really happened
 * and really did not work, which is the one that needs a human.
 */
final class RefundOutcome
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly ?Refund $refund,
        public readonly string $message,
        public readonly int $status,
    ) {}

    public static function applied(Refund $refund, SettlementResult $result): self
    {
        return new self(
            true,
            $result->code,
            $refund,
            $result->message ?? 'Refund sent to the payment provider.',
            200,
        );
    }

    public static function duplicate(Refund $refund): self
    {
        return new self(
            true,
            'duplicate',
            $refund,
            'That refund had already been made; nothing was charged back twice.',
            200,
        );
    }

    public static function refused(string $code, string $message): self
    {
        return new self(false, $code, null, $message, 422);
    }

    public static function failed(Refund $refund, SettlementResult $result): self
    {
        return new self(
            false,
            $result->code,
            $refund,
            $result->message ?? 'The payment provider refused that refund. Nothing was returned to the customer.',
            // 502, not 422: the request was fine, the provider was not. The
            // difference is what tells the admin whether to fix the figure or
            // to try again later.
            502,
        );
    }
}
