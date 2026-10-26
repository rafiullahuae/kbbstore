<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\TabbyCaptureSweep;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Take the money on authorised Tabby orders that were never captured.
 *
 *     php artisan payments:tabby-capture --dry
 *     php artisan payments:tabby-capture
 *     php artisan payments:tabby-capture --days=30 --limit=200 --minutes=0
 *
 * App\Services\Payments\TabbyCaptureSweep carries the whole argument, and it is
 * CaptureTamaraOrders' sibling with one difference: Tabby captures on
 * AUTHORISATION, not on fulfilment, because that is what the owner asked for
 * ("the tabby orders must be captured automatically ... before in wordpress
 * website the orders were auto capturing"). Tabby authorises at checkout and
 * pays nothing, `paid_at` says PAID on every screen while no fil has moved, and
 * Tabby voids an uncaptured authorisation after about 30 days — until the
 * capture the order does not count in the payout.
 *
 * ── RUN `--dry` FIRST ───────────────────────────────────────────────────────
 *
 * `--dry` lists the candidates and asks Tabby nothing, whether or not the
 * switch is on. There is no `--dry` branch inside the service's run(): a flag
 * that makes the money path skip its own work is a flag that can be left on.
 *
 * ── THE SWITCH SHIPS ON ─────────────────────────────────────────────────────
 *
 * Store → Payments → Tabby → Settings → "Capture automatically" is On as the
 * package ships, because the owner asked for it (migration
 * tabby_auto_capture_on_by_default). With it off this command prints why and
 * exits 0.
 *
 * ── WHO RUNS IT ─────────────────────────────────────────────────────────────
 *
 * The scheduler. routes/console.php runs this every few minutes so capture is
 * effectively immediate, which is the "immediate" half of what the owner asked
 * for, reached without a network call inside the checkout transaction. Two
 * overlapping runs are safe — PaymentCapturer claims `captured_at` in one
 * conditional UPDATE — but the schedule still carries withoutOverlapping().
 *
 * ── EXIT CODES ──────────────────────────────────────────────────────────────
 *
 * 0 when the sweep ran and every order it touched was captured or already
 * captured; 1 when any capture failed, because "I could not take the money" is
 * not "all clear". A run with nothing to do, or with the switch off, exits 0 —
 * the healthy case it reports almost every tick.
 */
class CaptureTabbyOrders extends Command
{
    protected $signature = 'payments:tabby-capture
                            {--minutes= : Leave orders authorised more recently than this alone (default 0; Tabby capture follows the shopper\'s own authorisation, not an operator action)}
                            {--days= : How far back to look, measured from the authorisation (default: the gateway\'s own capture window, 30)}
                            {--limit= : Most orders to capture in one run (default 50)}
                            {--dry : List the candidates and ask Tabby nothing}';

    protected $description = 'Capture authorised Tabby orders that were never captured';

    public function handle(TabbyCaptureSweep $sweep): int
    {
        $minutes = $this->intOption('minutes');
        $days = $this->intOption('days');
        $limit = $this->intOption('limit');

        if ((bool) $this->option('dry')) {
            $candidates = $sweep->candidates($minutes, $days, $limit);

            if ($candidates->isEmpty()) {
                $this->info('No authorised Tabby orders are waiting to be captured.');

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
            $this->line(sprintf('Total that would be captured: %s AED', Money::amount($total, 2)));

            return self::SUCCESS;
        }

        $report = $sweep->run($minutes, $days, $limit, 'scheduled capture');

        if (! $report['ran']) {
            $this->warn((string) $report['reason']);

            // Not a failure. The switch being off, or Tabby not being
            // configured, is a healthy state a few-minutely cron must not cry
            // wolf over.
            return self::SUCCESS;
        }

        if ($report['examined'] === 0) {
            $this->info('No authorised Tabby orders are waiting to be captured.');

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
            $this->warn(sprintf(
                '%d order(s) were already captured. If that was not the Capture button or an '
                . 'overlapping run, Tabby has a capture this shop had no record of — check '
                . 'Store → Payments → Reconcile, and the captured total on each.',
                $report['already'],
            ));
        }

        if ($report['failed'] > 0) {
            $this->warn(sprintf(
                '%d order(s) could not be captured. Each is an authorised Tabby order whose money '
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
