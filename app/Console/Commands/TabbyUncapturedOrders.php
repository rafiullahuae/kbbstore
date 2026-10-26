<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

/**
 * LIST the Tabby orders this shop authorised and never captured — READ ONLY.
 *
 *     php artisan payments:tabby-uncaptured
 *     php artisan payments:tabby-uncaptured --days=30
 *     php artisan payments:tabby-uncaptured --gateway=all
 *     php artisan payments:tabby-uncaptured --out=storage/app/reports/tabby.csv
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Tabby AUTHORISES at checkout and pays nothing until the order is CAPTURED, and
 * Tabby voids an authorisation nobody captures after its capture window (default
 * 30 days). On this shop Tabby has no automatic capture — Tamara has the hourly
 * TamaraCaptureSweep, Tabby was never given one — so a Tabby order that nobody
 * pressed "Capture" on is one Tabby never pays out and never counts.
 *
 * This command answers one question and changes NOTHING: which Tabby orders are
 * authorised (`paid_at` set) but not captured (`captured_at` null) and still
 * live (not cancelled / refunded / failed / draft). It prints them and writes a
 * CSV to storage/app/reports/ so the owner can open or download the list. It
 * does not call Tabby, does not capture, does not write a single order row.
 *
 * The "window" column is advisory: days since authorisation against --days (the
 * Tabby capture window; default 30). `CAPTURE NOW` means still inside it and
 * worth capturing today (Store -> Orders -> the order -> Capture); `likely
 * expired` means past it and almost certainly voided by Tabby already — the
 * Payments -> Reconcile screen confirms a payment's live state authoritatively.
 */
final class TabbyUncapturedOrders extends Command
{
    protected $signature = 'payments:tabby-uncaptured
        {--gateway=tabby : tabby, tamara, or all}
        {--days=30 : capture window in days, for the "likely expired" flag}
        {--limit=0 : cap the number of rows (0 = no cap)}
        {--out= : CSV path (default storage/app/reports/<gateway>-uncaptured-<time>.csv)}
        {--no-file : print only, do not write a CSV}';

    protected $description = 'List authorised-but-never-captured Tabby (or Tamara) orders. Read only.';

    /** Statuses that are not a live, payable order. */
    private const DEAD = ['cancelled', 'refunded', 'failed', 'draft'];

    public function handle(): int
    {
        $gateway = strtolower(trim((string) $this->option('gateway')));
        $gateways = $gateway === 'all' ? ['tabby', 'tamara'] : [$gateway];

        if ($gateway !== 'all' && ! in_array($gateway, ['tabby', 'tamara'], true)) {
            $this->error("Unknown --gateway '{$gateway}'. Use tabby, tamara, or all.");

            return self::INVALID;
        }

        $window = max(1, (int) $this->option('days'));
        $limit = max(0, (int) $this->option('limit'));

        // READ ONLY. `paid_at` set = authorised; `captured_at` null = never
        // captured; status still live. Ordered oldest-authorised first so the
        // ones closest to lapsing are read first.
        $query = Order::query()
            ->whereIn('payment_method', $gateways)
            ->whereNotNull('paid_at')
            ->whereNull('captured_at')
            ->whereNotIn('status', self::DEAD)
            ->orderBy('paid_at');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $orders = $query->get(['id', 'order_number', 'payment_method', 'status', 'total', 'currency', 'paid_at']);

        if ($orders->isEmpty()) {
            $this->info('No authorised-but-uncaptured ' . implode('/', $gateways) . ' orders. Nothing to show.');

            return self::SUCCESS;
        }

        $now = now();
        $rows = [];
        $capturableFils = 0;
        $expiredFils = 0;

        foreach ($orders as $o) {
            $days = $o->paid_at !== null ? (int) $o->paid_at->diffInDays($now) : 0;
            $expired = $days > $window;
            $amount = number_format(((int) $o->total) / 100, 2);
            $rows[] = [
                'order_number' => (string) $o->order_number,
                'gateway' => (string) $o->payment_method,
                'amount' => $amount,
                'currency' => (string) $o->currency,
                'status' => (string) $o->status,
                'authorised_at' => $o->paid_at?->format('Y-m-d H:i'),
                'days_since_auth' => $days,
                'flag' => $expired ? 'likely expired' : 'CAPTURE NOW',
            ];
            $expired ? $expiredFils += (int) $o->total : $capturableFils += (int) $o->total;
        }

        $this->table(
            ['Order', 'Gateway', 'Amount', 'Cur', 'Status', 'Authorised', 'Days', 'Window'],
            array_map(static fn (array $r): array => array_values($r), $rows)
        );

        $cur = (string) ($orders->first()->currency ?: 'AED');
        $this->newLine();
        $this->line('Total uncaptured: <options=bold>' . $orders->count() . '</> orders');
        $this->line('  Still capturable (<=' . $window . 'd): <fg=yellow;options=bold>' . number_format($capturableFils / 100, 2) . ' ' . $cur . '</> — capture these now (Store -> Orders -> order -> Capture)');
        $this->line('  Likely expired (>' . $window . 'd):  <fg=red>' . number_format($expiredFils / 100, 2) . ' ' . $cur . '</> — confirm on Payments -> Reconcile');

        if (! $this->option('no-file')) {
            $path = $this->writeCsv($rows, $gateways);
            if ($path !== null) {
                $this->newLine();
                $this->info('Saved: ' . $path);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Write the rows to a CSV and return its path, or null if it could not be
     * written (never throws — a report that cannot save must not fail the list).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $gateways
     */
    private function writeCsv(array $rows, array $gateways): ?string
    {
        try {
            $out = trim((string) $this->option('out'));
            if ($out === '') {
                $dir = storage_path('app/reports');
                if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                    $this->warn('Could not create ' . $dir . ' — printing only.');

                    return null;
                }
                $out = $dir . '/' . implode('-', $gateways) . '-uncaptured-' . now()->format('Ymd-His') . '.csv';
            } else {
                $parent = \dirname($out);
                if (! is_dir($parent) && ! @mkdir($parent, 0775, true) && ! is_dir($parent)) {
                    $this->warn('Could not create ' . $parent . ' — printing only.');

                    return null;
                }
            }

            $fh = @fopen($out, 'w');
            if ($fh === false) {
                $this->warn('Could not open ' . $out . ' for writing — printing only.');

                return null;
            }

            fputcsv($fh, ['order_number', 'gateway', 'amount', 'currency', 'status', 'authorised_at', 'days_since_auth', 'flag']);
            foreach ($rows as $r) {
                fputcsv($fh, array_values($r));
            }
            fclose($fh);

            return $out;
        } catch (\Throwable $e) {
            $this->warn('Could not write the CSV (' . $e->getMessage() . ') — printing only.');

            return null;
        }
    }
}
