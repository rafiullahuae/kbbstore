<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Complete your order" — the two reminders for an order nobody has paid for
 * (Lane RL).
 *
 * The owner, verbatim: "for any pending incomplete order, doesn't matter what
 * payment method, we need to send them proper completion email. 30 minutes
 * later and another email after 24 hours later with full automation."
 *
 * WHO IS REMINDED. An order still `pending` or `failed`, with no payment
 * recorded (`paid_at` null), not in the trash, not imported from WooCommerce,
 * not a sample, with an email address — and NOT one whose customer has placed
 * another order since (same email, a later id, anything but a draft). That last
 * rule is "never to an order the customer completed": a shopper whose Tabby was
 * declined and who then paid by card has a later order, and is left alone.
 * Cash on delivery is excluded by name: a COD order is placed, not unpaid —
 * the courier collects — and it reaches `processing` during checkout itself.
 *
 * WHEN. Reminder 1 is due from 30 minutes after the order was created until
 * 24 hours; reminder 2 from 24 hours until 72 hours. Each is sent at most once
 * (claimed in `order_emails`). A reminder whose window has passed is skipped
 * rather than sent late — on a quiet day with no cron, a 30-minute reminder
 * arriving at hour 23 would land an hour before the 24-hour one. The 72-hour
 * ceiling is also what keeps the first sweep after this package from writing
 * to every unpaid order in the shop's history.
 *
 * IT STOPS ITSELF. Every rule above is re-read on every sweep, so an order
 * that is paid, cancelled, or moved by anybody between the two reminders
 * simply stops matching. Nothing has to be cancelled.
 *
 * AND THE FEEDBACK REQUEST rides the same sweep: 3 hours after the Delivered
 * email went — see feedbackDue().
 *
 * TWO DRIVERS, ONE SWEEP: the scheduler (`kbb:order-reminders`, every minute,
 * when the cron line is installed) and OrderReminderTick (after an ordinary
 * page request, at most once a minute, when it is not). Both call sweep(); the
 * claim makes running both harmless.
 */
class OrderReminders
{
    public const FIRST_AFTER_MINUTES = 30;

    public const SECOND_AFTER_HOURS = 24;

    public const GIVE_UP_AFTER_HOURS = 72;

    public const FEEDBACK_AFTER_HOURS = 3;

    public const FEEDBACK_GIVE_UP_DAYS = 7;

    public function __construct(
        private OrderMailer $mailer,
        private OrderEmailLog $log,
    ) {}

    /**
     * Send what is due, up to $limit messages. Returns how many were sent.
     */
    public function sweep(int $limit = 10, ?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;

        try {
            if ($this->mailer->secondReminderEnabled()) {
                $sent += $this->stage(2, $limit - $sent, $now);
            }

            if ($sent < $limit && $this->mailer->firstReminderEnabled()) {
                $sent += $this->stage(1, $limit - $sent, $now);
            }

            if ($sent < $limit && $this->mailer->feedbackEnabled()) {
                $sent += $this->feedbackStage($limit - $sent, $now);
            }
        } catch (\Throwable $e) {
            Log::warning('order reminder sweep failed', ['exception' => class_basename($e), 'message' => $e->getMessage()]);
        }

        return $sent;
    }

    /** The orders due for $stage right now, oldest first. */
    public function due(int $stage, int $limit, ?Carbon $now = null): \Illuminate\Support\Collection
    {
        $now ??= now();

        [$from, $until] = $stage === 1
            ? [$now->copy()->subHours(self::SECOND_AFTER_HOURS), $now->copy()->subMinutes(self::FIRST_AFTER_MINUTES)]
            : [$now->copy()->subHours(self::GIVE_UP_AFTER_HOURS), $now->copy()->subHours(self::SECOND_AFTER_HOURS)];

        $kind = 'reminder_' . $stage;

        return $this->unfinished()
            ->where('orders.created_at', '>', $from)
            ->where('orders.created_at', '<=', $until)
            ->whereNotExists(function ($q) use ($kind) {
                $q->select(DB::raw(1))
                    ->from('order_emails')
                    ->whereColumn('order_emails.order_id', 'orders.id')
                    ->where('order_emails.kind', $kind);
            })
            ->orderBy('orders.created_at')
            ->orderBy('orders.id')
            ->limit(max(0, $limit))
            ->get();
    }

    /** Is this order, as it stands, one that may still be reminded? */
    public function stillUnfinished(Order $order): bool
    {
        return $this->unfinished()->whereKey($order->getKey())->exists();
    }

    /** Every order still waiting to be paid for, by the rules in the header. */
    private function unfinished(): Builder
    {
        return Order::query()
            ->select('orders.*')
            ->whereIn('orders.status', OrderMailer::UNPAID_STATUSES)
            ->whereNull('orders.paid_at')
            ->whereNull('orders.wc_order_id')
            ->where(fn ($q) => $q->whereNull('orders.payment_method')->orWhere('orders.payment_method', '!=', 'cod'))
            ->where('orders.email', '!=', '')
            ->where(fn ($q) => $q->whereNull('orders.origin')->orWhere('orders.origin', '!=', \App\Services\Orders\SampleOrder::ORIGIN))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('orders as later')
                    ->whereColumn('later.email', 'orders.email')
                    ->whereColumn('later.id', '>', 'orders.id')
                    ->where('later.status', '!=', 'draft')
                    ->whereNull('later.deleted_at');
            });
    }

    /**
     * Orders whose Delivered email went at least FEEDBACK_AFTER_HOURS ago and
     * have not been asked for feedback — Lane RL.
     *
     * The owner: "after 3 hours of the completed email sent, we will send them
     * auto feedback email." The clock is the `status_completed` row
     * OrderMailer writes only when that email really went, so an unticked
     * box or a switched-off Delivered email means no feedback request. Still
     * `completed` now, so an order refunded or cancelled in the meantime is
     * skipped; given up after FEEDBACK_GIVE_UP_DAYS so the first sweep after
     * a long quiet spell does not write to last month's customers.
     */
    public function feedbackDue(int $limit, ?Carbon $now = null): \Illuminate\Support\Collection
    {
        $now ??= now();

        return Order::query()
            ->select('orders.*')
            ->join('order_emails as delivered', function ($j) {
                $j->on('delivered.order_id', '=', 'orders.id')->where('delivered.kind', '=', 'status_completed');
            })
            ->where('orders.status', 'completed')
            ->whereNull('orders.wc_order_id')
            ->where('orders.email', '!=', '')
            ->where(fn ($q) => $q->whereNull('orders.origin')->orWhere('orders.origin', '!=', \App\Services\Orders\SampleOrder::ORIGIN))
            ->where('delivered.sent_at', '<=', $now->copy()->subHours(self::FEEDBACK_AFTER_HOURS))
            ->where('delivered.sent_at', '>', $now->copy()->subDays(self::FEEDBACK_GIVE_UP_DAYS))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('order_emails')
                    ->whereColumn('order_emails.order_id', 'orders.id')
                    ->where('order_emails.kind', 'feedback');
            })
            ->orderBy('delivered.sent_at')
            ->orderBy('orders.id')
            ->limit(max(0, $limit))
            ->get();
    }

    private function feedbackStage(int $limit, Carbon $now): int
    {
        $sent = 0;

        foreach ($this->feedbackDue($limit, $now) as $order) {
            if (! $this->log->claim($order, 'feedback')) {
                continue;
            }

            if ($this->mailer->feedback($order)) {
                $sent++;

                try {
                    $order->notes()->create([
                        'author' => 'system',
                        'is_customer_note' => false,
                        'content' => sprintf('Emailed the customer the feedback request ("How is your glow?") to %s.', (string) $order->email),
                    ]);
                } catch (\Throwable) {
                }
            }
        }

        return $sent;
    }

    private function stage(int $stage, int $limit, Carbon $now): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $sent = 0;

        foreach ($this->due($stage, $limit, $now) as $order) {
            if (! $this->log->claim($order, 'reminder_' . $stage)) {
                continue;
            }

            $this->mailer->reminder($order, $stage);

            try {
                $order->notes()->create([
                    'author' => 'system',
                    'is_customer_note' => false,
                    'content' => sprintf(
                        'Emailed the customer "Complete your order" (reminder %d of 2, %s) to %s.',
                        $stage,
                        $stage === 1 ? '30 minutes' : '24 hours',
                        (string) $order->email,
                    ),
                ]);
            } catch (\Throwable) {
                // The note is a courtesy for the order screen; the send stands.
            }

            $sent++;
        }

        return $sent;
    }
}
