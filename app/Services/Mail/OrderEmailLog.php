<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The per-order record of the emails that may go out at most ONCE (Lane RL).
 *
 * claim() is the whole mechanism: an insert-or-ignore against
 * unique(order_id, kind). The process that inserts the row is the one that
 * sends; every other process — a second webhook delivery, the browser's own
 * confirmation racing the webhook, the scheduler and the page heartbeat both
 * sweeping in the same minute — inserts nothing and sends nothing. That is a
 * decision the database makes, which is the only place two PHP processes can
 * agree on anything.
 *
 * THE CLAIM COMES BEFORE THE SEND, deliberately. If the send then fails the
 * row stays, and the email is not retried automatically: a reminder sent twice
 * is worse than one lost, and a lost receipt has a button (Order actions →
 * Resend confirmation email). The failure itself is recorded in Sent mail by
 * OrderMailer::send(), so it is not silent.
 *
 * NOTHING HERE MAY THROW. It runs on the checkout's request and inside the
 * observer that a status change fires. A missing table (a package whose
 * migration has not run yet) answers "not claimed" — i.e. DO NOT SEND — which
 * is the safe direction for every caller: a receipt or reminder that could not
 * be recorded as sent is a receipt or reminder that could be sent twice.
 */
class OrderEmailLog
{
    /** The closed list of kinds. Anything else is refused rather than stored. */
    public const KINDS = ['confirmation', 'reminder_1', 'reminder_2', 'status_completed', 'feedback'];

    /** Claim (order, kind). True only for the one caller that inserted the row. */
    public function claim(Order|int $order, string $kind): bool
    {
        if (! in_array($kind, self::KINDS, true)) {
            return false;
        }

        $id = $order instanceof Order ? (int) $order->getKey() : $order;

        if ($id <= 0) {
            return false;
        }

        try {
            $now = now();

            return DB::table('order_emails')->insertOrIgnore([
                'order_id' => $id,
                'kind' => $kind,
                'sent_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;
        } catch (\Throwable $e) {
            Log::warning('order email claim failed', [
                'order_id' => $id,
                'kind' => $kind,
                'exception' => class_basename($e),
            ]);

            return false;
        }
    }

    /** Has this (order, kind) been claimed? */
    public function has(Order|int $order, string $kind): bool
    {
        $id = $order instanceof Order ? (int) $order->getKey() : $order;

        try {
            return DB::table('order_emails')->where('order_id', $id)->where('kind', $kind)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
