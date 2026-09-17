<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\Reconciliation\CashOnDeliveryPosition;
use App\Services\Payments\Reconciliation\ReconcileWindow;
use App\Services\Payments\Reconciliation\Reconciler;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Check the payment providers' books against this database.
 *
 *     php artisan payments:reconcile --days=30
 *     php artisan payments:reconcile --from=2026-09-01 --to=2026-09-30
 *     php artisan payments:reconcile --days=7 --provider=stripe --restart
 *     php artisan payments:reconcile --days=30 --cod
 *
 * WHO THIS IS FOR, WHICH IS NOT THE OWNER. This host is shared hosting with no
 * shell access, so the person who most needs a reconciliation cannot run a
 * command. The screen behind Store → Payments → Reconcile is his handle, and it
 * drives the same Reconciler through the same phases; this command exists for
 * CI, for a developer with a copy of the database, and because a service that
 * can only be exercised through a browser is a service that is hard to test at
 * the seams.
 *
 * It exits 1 when anything unacknowledged was found, so it can be wired to
 * something that watches, and 0 when the two sides agree. A run that could not
 * READ a provider also exits 1 — "I could not check" is not "all clear", and
 * the one thing a money check must never do is report silence as agreement.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
                            {--days=30 : How many days back to check, ending today}
                            {--from= : Start date (YYYY-MM-DD). Overrides --days}
                            {--to= : End date (YYYY-MM-DD). Defaults to today}
                            {--provider=* : Limit to these gateways. Default: every one that can be asked}
                            {--restart : Throw away this window\'s previous findings and look again}
                            {--cod : Also print the cash-on-delivery position, which is a different report}';

    protected $description = "Check Stripe, Tabby and Tamara's books against this shop's payments and refunds";

    /**
     * A hard stop on the step loop.
     *
     * The phases are finite and each one advances or finishes, so this should
     * never be reached. It is here because the alternative to a wrong bound is
     * an unbounded loop against somebody else's rate-limited API, and that is
     * not a trade worth having either way.
     */
    private const MAX_STEPS = 2000;

    public function handle(Reconciler $reconciler, CashOnDeliveryPosition $cod): int
    {
        if (! Schema::hasTable(Reconciler::RUNS)) {
            $this->error('There are no reconciliation tables. Run the migrations first.');

            return self::FAILURE;
        }

        try {
            $window = $this->window();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        /** @var array<int, string> $providers */
        $providers = (array) $this->option('provider');

        $runId = $reconciler->open($window, $providers, 'console', (bool) $this->option('restart'));

        $this->line(sprintf(
            'Reconciling %s (%d day%s).',
            $window->key(),
            $window->days(),
            $window->days() === 1 ? '' : 's',
        ));

        $steps = 0;

        do {
            $state = $reconciler->step($runId);
            $steps++;

            if (($state['done'] ?? false) !== true && $this->output->isVerbose()) {
                $this->line(sprintf(
                    '  %s / %s — %d of %d phases done',
                    (string) ($state['provider'] ?? '?'),
                    (string) ($state['phase'] ?? '?'),
                    (int) ($state['phases_done'] ?? 0),
                    (int) ($state['phases_total'] ?? 0),
                ));
            }
        } while (($state['done'] ?? false) !== true && $steps < self::MAX_STEPS);

        $this->report($reconciler, $runId);

        if ($this->option('cod')) {
            $this->codReport($cod->forWindow($window));
        }

        return array_sum($reconciler->counts($runId)) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function window(): ReconcileWindow
    {
        $from = (string) ($this->option('from') ?? '');
        $to = (string) ($this->option('to') ?? '');

        if ($from !== '') {
            return ReconcileWindow::between($from, $to !== '' ? $to : now('UTC')->toDateString());
        }

        return ReconcileWindow::lastDays((int) $this->option('days'));
    }

    private function report(Reconciler $reconciler, int $runId): void
    {
        $status = $reconciler->status($runId);
        $run = $status['run'];

        $this->newLine();
        $this->line(sprintf(
            'Read %d transaction%s from %s; checked %d row%s here.',
            (int) $run['remote_seen'],
            (int) $run['remote_seen'] === 1 ? '' : 's',
            implode(', ', $run['providers']) ?: 'nobody',
            (int) $run['local_checked'],
            (int) $run['local_checked'] === 1 ? '' : 's',
        ));

        $counts = $reconciler->counts($runId);

        if ($counts === []) {
            $this->info('Both sides agree. Nothing outstanding.');

            return;
        }

        $this->newLine();

        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-28s %d', $kind, $n));
        }

        $this->newLine();

        $rows = DB::table(Reconciler::FINDINGS)
            ->where('run_id', $runId)
            ->whereNull('acknowledged_at')
            ->orderBy('id')
            ->limit(40)
            ->get();

        foreach ($rows as $f) {
            $this->line(sprintf(
                '  [%s] %s  order %s  %s',
                strtoupper((string) $f->severity),
                (string) $f->kind,
                (string) ($f->order_number ?: '—'),
                $this->amounts($f),
            ));
            $this->line('        ' . (string) $f->summary);
        }

        if (count($rows) < array_sum($counts)) {
            $this->line(sprintf('  … and %d more.', array_sum($counts) - count($rows)));
        }
    }

    private function amounts(object $f): string
    {
        $remote = $f->amount_remote !== null ? Money::amount((int) $f->amount_remote, 2) : null;
        $local = $f->amount_local !== null ? Money::amount((int) $f->amount_local, 2) : null;

        return match (true) {
            $remote !== null && $local !== null => sprintf('%s here / %s there', $local, $remote),
            $remote !== null => $remote . ' at the provider',
            $local !== null => $local . ' here',
            default => '',
        };
    }

    /** @param array<string, mixed> $position */
    private function codReport(array $position): void
    {
        $this->newLine();
        $this->line('Cash on delivery — a DIFFERENT report, not a reconciliation:');
        foreach ([
            'marked collected' => $position['collected'],
            'still owed to us' => $position['outstanding'],
            'closed, uncollected' => $position['closed_uncollected'],
        ] as $label => $figures) {
            $this->line(sprintf(
                '  %-20s %s over %d order%s',
                $label,
                Money::amount((int) $figures['fils'], 2),
                (int) $figures['orders'],
                (int) $figures['orders'] === 1 ? '' : 's',
            ));
        }
        $this->newLine();
        $this->line('  ' . (string) $position['note']);
    }
}
