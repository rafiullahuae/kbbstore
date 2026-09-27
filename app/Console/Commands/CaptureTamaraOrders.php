<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\TamaraCaptureSweep;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Take the money on Tamara orders that have shipped and were never captured.
 *
 *     php artisan payments:tamara-capture --dry
 *     php artisan payments:tamara-capture
 *     php artisan payments:tamara-capture --days=180 --limit=200 --minutes=0
 *
 * App\Services\Payments\TamaraCaptureSweep carries the whole argument. In short:
 * Tamara authorises at checkout and pays nothing, `paid_at` says PAID on every
 * screen while no fil has moved, and Tamara voids an authorisation nobody
 * captured after about 180 days. An order that ships and is never captured is
 * one the shop gave the goods away for.
 *
 * ── RUN `--dry` FIRST, AND BEFORE TOUCHING THE SWITCH ───────────────────────
 *
 * `--dry` lists the candidates and asks Tamara nothing. It works WHETHER OR NOT
 * automatic capture is switched on, which is deliberate: this command's real
 * first job is answering "what would this do on my shop" for an owner who has
 * not decided yet. There is no `--dry` branch inside the service's run() — a
 * flag that makes the money path skip its own work is a flag that can be left
 * on.
 *
 * ── IT DOES NOTHING UNTIL THE OWNER TURNS IT ON ─────────────────────────────
 *
 * Store → Payments → Tamara → Settings → "Capture automatically when an order
 * ships" is Off on every install. With it off this command prints why and exits
 * 0. Applying the package that adds it therefore moves no money.
 *
 * ── WHO RUNS IT ─────────────────────────────────────────────────────────────
 *
 * Cron, once the owner has said yes. Cloudways gives this project a shell and
 * one crontab line already drives routes/console.php, so the honest home is a
 * Schedule entry there — NOT ADDED BY THIS LANE, because routes/console.php is
 * shared and because scheduling a money job is the same decision as switching it
 * on. The line to add, whichever way it is driven:
 *
 *     0 * * * * cd <app root> && php artisan payments:tamara-capture >> storage/logs/tamara-capture.log 2>&1
 *
 * Hourly is plenty against a 180-day window. Two overlapping runs are safe —
 * PaymentCapturer claims `captured_at` in one conditional UPDATE, so the loser
 * of a race is answered `already_captured` without a second call to Tamara —
 * but a Schedule entry should still carry `withoutOverlapping()` so a slow run
 * does not stack.
 *
 * ── EXIT CODES ──────────────────────────────────────────────────────────────
 *
 * 0 when the sweep ran and every order it touched was captured or was already
 * captured; 1 when any capture failed, because "I could not take the money" is
 * not "all clear" and is the one thing here worth waking somebody for. A run
 * with nothing to do exits 0, and so does a run with the switch off — that is
 * the healthy case and the one it reports almost every hour.
 */
class CaptureTamaraOrders extends Command
{
    protected $signature = 'payments:tamara-capture
                            {--minutes= : Leave orders touched more recently than this alone (default 30; an operator who marked the wrong order shipped has that long to undo it)}
                            {--days= : How far back to look, measured from the authorisation (default: the gateway\'s own capture window, 180)}
                            {--limit= : Most orders to capture in one run (default 50)}
                            {--dry : List the candidates and ask Tamara nothing}';

    protected $description = 'Capture Tamara orders that have shipped and were never captured';

    public function handle(TamaraCaptureSweep $sweep): int
    {
        $minutes = $this->intOption('minutes');
        $days = $this->intOption('days');
        $limit = $this->intOption('limit');

        if ((bool) $this->option('dry')) {
            $candidates = $sweep->candidates($minutes, $days, $limit);

            if ($candidates->isEmpty()) {
                $this->info('No shipped Tamara orders are waiting to be captured.');

                return self::SUCCESS;
            }

            $this->line(sprintf('%d order(s) would be captured:', $candidates->count()));

            $total = 0;

            foreach ($candidates as $order) {
                $total += (int) $order->total;

                $this->line(sprintf(
                    '  %-24s %-10s %12s %s   authorised %s',
                    (string) $order->order_number,
                    (string) $order->status,
                    Money::amount((int) $order->total, 2),
                    strtoupper((string) ($order->currency ?: 'AED')),
                    (string) $order->paid_at,
                ));
            }

            $this->newLine();
            // Summed in integer fils and converted once, at the edge, for
            // printing. The same rule the rest of this path follows.
            $this->line(sprintf('Total that would be captured: %s AED', Money::amount($total, 2)));

            return self::SUCCESS;
        }

        $report = $sweep->run($minutes, $days, $limit, 'scheduled capture');

        if (! $report['ran']) {
            $this->warn((string) $report['reason']);

            // Not a failure. The switch being off is the shipped default, and a
            // cron entry that exited 1 on it would cry wolf every hour for ever.
            return self::SUCCESS;
        }

        if ($report['examined'] === 0) {
            $this->info('No shipped Tamara orders are waiting to be captured.');

            return self::SUCCESS;
        }

        foreach ($report['orders'] as $row) {
            $this->line(sprintf('  %-24s %-9s %-22s %s', $row['order'], $row['outcome'], $row['code'], $row['message']));
        }

        $this->newLine();
        $this->line(sprintf(
            'Examined %d. Captured: %d (%s AED). Already captured: %d. Failed: %d.',
            $report['examined'],
            $report['captured'],
            Money::amount((int) $report['captured_fils'], 2),
            $report['already'],
            $report['failed'],
        ));

        if ($report['already'] > 0) {
            /*
             * WORTH SAYING OUT LOUD, because it is not simply "nothing to do".
             *
             * `already_captured` arrives two ways. The harmless one is a lost
             * claim: the Capture button, or an overlapping run, got there first,
             * and this line is the double-capture guard reporting that it worked.
             * The other one is Tamara reporting the order captured while this
             * shop's `captured_at` was null — a capture that landed at the
             * provider and whose record here did not, which is exactly what
             * Store → Payments → Reconcile exists to find.
             *
             * AND THE SECOND ONE HAS A KNOWN SHARP EDGE, named here rather than
             * left to be discovered: Tamara answers `already_captured` for
             * `partially_captured` too, and PaymentCapturer then writes
             * `captured_total` = the WHOLE order total, which is the ceiling
             * PaymentRefunder::capturedFils() measures a refund against. A
             * partial capture made outside this shop therefore reads as fully
             * refundable. Reported, not fixed here — the fix is a captured AMOUNT
             * on SettlementResult, which is an interface four gateways implement.
             */
            $this->warn(sprintf(
                '%d order(s) were already captured. If that was not the Capture button or an '
                . 'overlapping run, Tamara has a capture this shop had no record of — check '
                . 'Store → Payments → Reconcile, and check the captured total on each.',
                $report['already'],
            ));
        }

        if ($report['failed'] > 0) {
            $this->warn(sprintf(
                '%d order(s) could not be captured. Each one is goods that have shipped and money that '
                . 'has not moved — open the order and read the note the attempt left on it.',
                $report['failed'],
            ));
        }

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** An option that is absent reads as null, so the service's own default wins. */
    private function intOption(string $key): ?int
    {
        $raw = $this->option($key);

        return $raw === null || $raw === '' || ! is_numeric($raw) ? null : (int) $raw;
    }
}
