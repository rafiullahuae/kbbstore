<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupPoller;
use App\Services\Quiqup\ShipmentSync;
use Illuminate\Console\Command;

/**
 * kbb:quiqup-sync -- the minute cron's Quiqup tick (QUIQUP-PLAN §6.3).
 *
 * Scheduled everyMinute()->withoutOverlapping(5)->runInBackground() in
 * routes/console.php. There is NO queue worker on the live host, so this tick
 * (and the open admin page) is the only thing that does background work:
 *
 *   1. re-apply events whose apply failed (applied_at NULL, <= 50)
 *   2. finish creates a closed tab left queued (<= 5)
 *   3. poll shipments whose next_poll_at is due (30 calls/h ceiling)
 *
 * IDLE COST: one indexed query. Every piece of owed work sets
 * shipments.next_poll_at, so "is anything due?" is one EXISTS on that index,
 * and the tick returns when it is not.
 *
 *   --dry       say what is due, call nothing
 *   --order=ID  read that order's booking from Quiqup now
 */
final class QuiqupSync extends Command
{
    protected $signature = 'kbb:quiqup-sync {--dry : List what is due without calling Quiqup} {--order= : Refresh one order\'s booking now}';

    protected $description = 'Quiqup: re-apply missed events, finish queued bookings, poll due shipments';

    public function handle(ShipmentSync $sync, QuiqupPoller $poller): int
    {
        if ($this->option('order') !== null) {
            return $this->refreshOne($poller, (int) $this->option('order'));
        }

        $due = Shipment::query()
            ->whereNotNull('next_poll_at')
            ->where('next_poll_at', '<=', now()->format('Y-m-d H:i:s.u'))
            ->exists();

        if (! $due) {
            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $rows = Shipment::query()->whereNotNull('next_poll_at')->where('next_poll_at', '<=', now()->format('Y-m-d H:i:s.u'))
                ->orderBy('next_poll_at')->orderBy('id')->limit(100)->get(['order_id', 'qq_id', 'job', 'state', 'next_poll_at']);
            foreach ($rows as $r) {
                $this->line(sprintf('order %d  quiqup %s  %s/%s  due %s', $r->order_id, $r->qq_id ?? '-', $r->job, $r->state ?? '-', $r->next_poll_at));
            }
            $this->line('Calls used this hour: ' . QuiqupPoller::spentThisHour() . '/' . QuiqupPoller::HOURLY_CEILING);

            return self::SUCCESS;
        }

        $reapplied = $sync->reapplyPending(50);
        $created = $this->finishQueued();
        $polled = $poller->pollDue();

        if ($reapplied + $created + $polled['polled'] > 0) {
            $this->line(sprintf('quiqup: re-applied %d, booked %d, polled %d (%d changed, %d calls)', $reapplied, $created, $polled['polled'], $polled['changed'], $polled['calls']));
        }

        return self::SUCCESS;
    }

    /** Step 2: finish what a closed tab left queued, 5 a tick. */
    protected function finishQueued(): int
    {
        return app(\App\Services\Quiqup\ShipmentCreator::class)->runDue(5);
    }

    private function refreshOne(QuiqupPoller $poller, int $orderId): int
    {
        $s = Shipment::query()->where('order_id', $orderId)->orderByDesc('id')->first();

        if ($s === null || Order::withTrashed()->find($orderId) === null) {
            $this->error('No Quiqup booking for order ' . $orderId . '.');

            return self::FAILURE;
        }

        $r = $poller->refresh($s);
        $this->line($r->ok() ? 'Refreshed: ' . (Shipment::query()->find($s->getKey())?->state ?? '-') : $r->message());

        return $r->ok() ? self::SUCCESS : self::FAILURE;
    }
}
