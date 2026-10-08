<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\OldServerPictures;
use Illuminate\Console\Command;

/**
 * The SSH fallback for "Fetch missing pictures from the old server" (Lane PX):
 * the same service, the same ledger and the same guards as the admin button,
 * looping step after step until nothing is left. Safe to stop with Ctrl+C and
 * run again: it resumes onto the rows still pending.
 *
 *   php artisan kbb:fetch-missing-pictures --check
 *   php artisan kbb:fetch-missing-pictures --from-ip=177.202.242.149
 */
class FetchMissingPictures extends Command
{
    protected $signature = 'kbb:fetch-missing-pictures
        {--from-ip= : the OLD server\'s public address (default: Hostinger, 177.202.242.149)}
        {--check : count what is missing and who names it; no network}
        {--http : plain HTTP to the same address, when the old certificate no longer validates}
        {--retry : put earlier failures back in the queue first}
        {--retry-gone : also retry pictures the old server answered 404 for}
        {--files=20 : pictures per step}
        {--pause=300 : milliseconds between two requests to the old server}
        {--csv= : write every picture still missing to this file}';

    protected $description = 'Fetch pictures the shop names but does not have, from the old server by its IP, keeping the real hostname';

    public function handle(OldServerPictures $pictures): int
    {
        if ($this->option('check')) {
            $this->line('Checking (no network)...');
            $this->report($pictures->scan(), $pictures);

            return self::SUCCESS;
        }

        $ip = (string) ($this->option('from-ip') ?: $pictures->defaultIp());

        if (($refusal = $pictures->ipRefusal($ip)) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if ($this->option('retry') || $this->option('retry-gone')) {
            $this->line($pictures->retry((bool) $this->option('retry-gone')).' picture(s) put back in the queue.');
        }

        $this->line('Fetching from '.$ip.' as '.$pictures->fetchHost().($this->option('http') ? ' over plain HTTP' : ' over HTTPS, certificate verified').'...');

        $continue = false;

        do {
            $r = $pictures->batch([
                'ip' => $ip,
                'http' => (bool) $this->option('http'),
                'files' => (int) $this->option('files'),
                'seconds' => 60,
                'pause_ms' => (int) $this->option('pause'),
                'continue' => $continue,
            ]);
            $continue = true;

            foreach ((array) ($r['results'] ?? []) as $one) {
                $this->line(sprintf('  %-8s %s  %s', $one['state'], $one['path'], $one['state'] === OldServerPictures::FETCHED ? '' : $one['reason']));
            }

            $s = (array) $r['summary'];
            $this->line(sprintf('  -- fetched %d, failed %d, not on the old server %d, refused %d, remaining %d',
                $s['fetched'], $s['failed'], $s['gone'], $s['refused'], $s['remaining']));

            if (! ($r['ok'] ?? false) || ($r['tls_failed'] ?? false) || ($r['stopped'] ?? false)) {
                $this->warn((string) ($r['message'] ?? ''));

                if ($r['tls_failed'] ?? false) {
                    $this->warn('Run again with --http --retry to fetch over plain HTTP from the same address.');
                }

                break;
            }
        } while ($r['more'] ?? false);

        $this->report($pictures->summary(), $pictures);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $s */
    private function report(array $s, OldServerPictures $pictures): void
    {
        $this->newLine();
        $this->line('Named in the database: '.$s['referenced'].'   on this server: '.$s['present'].'   MISSING: '.$s['missing']);
        $this->line('Fetched so far: '.$s['fetched'].'   remaining: '.$s['remaining'].'   failed: '.$s['failed']
            .'   not on the old server either: '.$s['gone'].'   refused: '.$s['refused']);

        foreach ((array) $s['by_host'] as $host => $n) {
            $this->line('  missing, named as '.$host.': '.$n);
        }

        $list = $pictures->missingList(100000);

        foreach (array_slice($list, 0, 40) as $row) {
            $this->line('  '.$row['state'].'  '.$row['path'].($row['reason'] !== '' ? '  -- '.$row['reason'] : ''));
            $this->line('      named by: '.implode('; ', $row['owners']));
        }

        if (count($list) > 40) {
            $this->line('  ... and '.(count($list) - 40).' more (use --csv=FILE for all of them)');
        }

        if (is_string($csv = $this->option('csv')) && $csv !== '') {
            $fh = fopen($csv, 'w');
            fputcsv($fh, ['path', 'state', 'http_status', 'reason', 'named_by']);

            foreach ($list as $row) {
                fputcsv($fh, [$row['path'], $row['state'], (string) ($row['status'] ?? ''), $row['reason'], implode(' | ', $row['owners'])]);
            }

            fclose($fh);
            $this->line('Wrote '.count($list).' row(s) to '.$csv);
        }

        $this->newLine();
        $this->warn($s['missing'] === 0 ? 'Nothing is missing.' : $s['warning']);
    }
}
