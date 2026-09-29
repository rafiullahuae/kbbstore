<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\TamaraSweep;
use Illuminate\Console\Command;

/**
 * Settle Tamara orders whose approval notification never arrived.
 *
 *     php artisan payments:tamara-sweep
 *     php artisan payments:tamara-sweep --days=180 --limit=200
 *     php artisan payments:tamara-sweep --minutes=0 --dry
 *
 * App\Services\Payments\TamaraSweep carries the whole argument for why this has
 * to exist: an approval that reaches Tamara but whose callback never reaches
 * this shop leaves an order `pending` for ever, holding stock and a coupon use,
 * while the buyer has a live payment plan and believes they have bought
 * something. The shop never ships and never gets paid.
 *
 * WHO RUNS IT. Cloudways gives this project a shell, so the honest answer is
 * cron — hourly is plenty:
 *
 *     0 * * * * cd <app root> && php artisan payments:tamara-sweep >> storage/logs/tamara-sweep.log 2>&1
 *
 * The owner does not need a shell for it either: the same service is behind
 * POST /admin-api/payments/tamara/sweep, so "Run the sweep" on
 * Store -> Gateway webhooks does exactly what this does.
 *
 * ▲ THAT SENTENCE WAS FALSE FOR AS LONG AS THIS FILE EXISTED, and is worth
 *   keeping as a note. It said "the button on the payments screen" and there
 *   was no button anywhere: nothing in resources/views/admin/** called any of
 *   the five Tamara endpoints, so the only caller of this service was this
 *   command and a Playwright harness that POSTed to the endpoint directly. Lane
 *   TM built the screen the sentence describes. A comment asserting that
 *   somebody else's code exists is a claim like any other and this one went
 *   unchecked for a release.
 *
 * This command exists for the person who has a shell, for CI, and because a
 * service reachable only through a browser is one that is hard to exercise at
 * its seams — the same reasoning ReconcilePayments states at its head. Its two
 * siblings, payments:tamara-webhook and payments:tamara-limits, exist for the
 * same reason and were added with that screen.
 *
 * EXIT CODES. 0 when the sweep ran and nothing was left in an error state, 1
 * when any order could not be read from Tamara — "I could not check" is not
 * "all clear", which is the rule the reconciliation command already follows. A
 * sweep that found nothing to do exits 0, because that is the healthy case and
 * the one it will report almost every hour.
 */
class SweepTamaraOrders extends Command
{
    protected $signature = 'payments:tamara-sweep
                            {--minutes= : Leave orders newer than this alone (default 15; a shopper may still be on Tamara\'s page)}
                            {--days= : How far back to look (default 30)}
                            {--limit= : Most orders to examine in one run (default 50)}
                            {--dry : List the candidates and ask Tamara nothing}';

    protected $description = 'Find Tamara orders that were approved but never confirmed, and settle them';

    public function handle(TamaraSweep $sweep): int
    {
        $minutes = $this->intOption('minutes');
        $days = $this->intOption('days');
        $limit = $this->intOption('limit');

        if ((bool) $this->option('dry')) {
            /*
             * A DRY RUN LISTS AND DOES NOT ASK.
             *
             * It calls the SAME candidates() the real sweep loops over, rather
             * than a second copy of that query written for printing. Two copies
             * would drift, and the direction they drift in is a dry run that
             * reassures somebody about a list the real sweep does not use.
             *
             * There is deliberately no `--dry` branch inside TamaraSweep::run():
             * a flag that makes the money path skip its own work is a flag that
             * can be left on.
             */
            $candidates = $sweep->candidates($minutes, $days, $limit);

            if ($candidates->isEmpty()) {
                $this->info('No Tamara orders are waiting on a notification.');

                return self::SUCCESS;
            }

            $this->line(sprintf('%d order(s) would be checked with Tamara:', $candidates->count()));

            foreach ($candidates as $order) {
                $this->line(sprintf(
                    '  %-24s placed %s   tamara order: %s',
                    (string) $order->order_number,
                    (string) $order->created_at,
                    trim((string) $order->transaction_id) !== ''
                        ? (string) $order->transaction_id
                        : '(none — would be looked up by reference)',
                ));
            }

            return self::SUCCESS;
        }

        $report = $sweep->run($minutes, $days, $limit, 'scheduled check');

        if (! $report['ran']) {
            $this->warn((string) $report['reason']);

            // Not a failure: a shop with no Tamara keys has no stale Tamara
            // orders, and exiting 1 would make a cron entry cry wolf hourly.
            return self::SUCCESS;
        }

        if ($report['examined'] === 0) {
            $this->info('No Tamara orders are waiting on a notification.');

            return self::SUCCESS;
        }

        foreach ($report['orders'] as $row) {
            $this->line(sprintf('  %-24s %-8s %s', $row['order'], $row['outcome'], $row['message']));
        }

        $this->newLine();
        $this->line(sprintf(
            'Examined %d. Marked paid: %d. Closed as declined or expired: %d. Still waiting: %d. Could not check: %d.',
            $report['examined'],
            $report['paid'],
            $report['failed'],
            $report['untouched'],
            $report['errors'],
        ));

        if ($report['paid'] > 0) {
            $this->warn(sprintf(
                '%d order(s) had been approved at Tamara and were never confirmed here. '
                . 'They are paid now and ready to capture.',
                $report['paid'],
            ));
        }

        return $report['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** An option that is absent reads as null, so the service's own default wins. */
    private function intOption(string $key): ?int
    {
        $raw = $this->option($key);

        return $raw === null || $raw === '' || ! is_numeric($raw) ? null : (int) $raw;
    }
}
