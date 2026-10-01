<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\MediaAudit;
use App\Services\Import\MediaSideloader;
use Illuminate\Console\Command;

/**
 * `kbb:import-media-fetch` — the picture pass, from a shell. Lane PX.
 *
 * Fetch every photograph the imported catalogue still names on the old site,
 * put it in this shop's web root, and re-point the rows at it — batch after
 * batch until there is nothing left to try — then name every picture that did
 * not come across, with the reason.
 *
 * IT IS THE SAME ENGINE AS THE LIVE PROGRESS PAGE, NOT A SECOND ONE. Each pass
 * of the loop is one `MediaSideloader::batch()`, exactly the call the page's
 * Fetch button makes, so every guard in that class — the host and address
 * checks, the sniffing, the caps, the atomic rename, the ledger — applies here
 * unchanged, and the page shows this run as RUNNING while it works. The page
 * exists because the owner used to have no shell; on Cloudways he has one, and
 * a console command has no PHP-FPM request ceiling to be killed by halfway.
 *
 * WHEN IT STOPS. When nothing remains, or when a batch made no progress at all:
 * it fetched nothing, refused nothing, re-pointed nothing, and there was no
 * untried picture left for it to try. The last condition is the one the page's
 * loop was missing (see media-progress.blade.php): ten broken pictures in a row
 * is a batch that fetched nothing, and stopping there leaves every untried
 * picture behind them untouched.
 *
 * IT IS SAFE TO RUN AGAIN, AND TO KILL. A picture already on disk is not
 * fetched again; a file is only ever moved into place whole; a run killed
 * mid-way resumes onto exactly what is not done, including rows whose file
 * landed before the kill and were not yet re-pointed.
 *
 *   php artisan kbb:import-media-fetch --host=kbeautybliss.com
 *   php artisan kbb:import-media-fetch --host=kbeautybliss.com --retry
 *   php artisan kbb:import-media-fetch --plan
 *
 * Exit code 0 when no picture remains on the old host; 1 when some do, each
 * named above it with the reason.
 */
class ImportMediaFetch extends Command
{
    protected $signature = 'kbb:import-media-fetch
        {--host=* : only fetch from this host (the old shop\'s); repeatable. Default: every host the catalogue names}
        {--files=25 : pictures per batch}
        {--seconds=60 : wall clock per batch}
        {--retry : forget earlier FAILURES first, so each is tried once more (refusals are never retried)}
        {--plan : say what is left and stop; fetch nothing}
        {--csv= : write every failure and refusal to this file}';

    protected $description = 'Fetch the imported catalogue\'s pictures off the old site into this shop, then name every one that did not come';

    public function handle(): int
    {
        $sideloader = new MediaSideloader;
        $requested = array_values(array_filter(array_map('strval', (array) $this->option('host'))));
        $resolved = $sideloader->resolveHosts($requested);

        foreach ($resolved['ignored'] as $host) {
            $this->warn('No picture in this catalogue is on '.$host.'; ignored.');
        }

        if ($requested !== [] && $resolved['hosts'] === []) {
            $this->error('None of the hosts named is one the catalogue\'s pictures are on. Nothing was fetched.');

            return self::FAILURE;
        }

        $hosts = $resolved['hosts'];
        $plan = $sideloader->plan($hosts);

        $this->line('Hosts: '.($hosts === [] ? '(none — no picture is on another site)' : implode(', ', $hosts)));
        $this->line(sprintf(
            'Pictures: %d named · %d already here · %d to fetch · %d failed before · %d refused · free on the volume: %s',
            $plan['total'], $plan['present'], $plan['remaining'], $plan['failed'], $plan['refused'],
            $plan['free_bytes'] === null ? 'unknown' : $sideloader->bytes((int) $plan['free_bytes']),
        ));

        if ($this->option('plan')) {
            return $this->finish($sideloader, $hosts);
        }

        if ($this->option('retry')) {
            $this->line('Forgot '.$sideloader->retry().' earlier failure(s); each will be tried once more.');
        }

        if (! $plan['enough_room']) {
            $this->error('Not enough free space for the estimate ('.$sideloader->bytes((int) $plan['estimated_bytes'])
                .') plus the reserve. Nothing was fetched.');

            return self::FAILURE;
        }

        $files = max(1, min(200, (int) $this->option('files')));
        $seconds = max(1, min(120, (int) $this->option('seconds')));
        $started = microtime(true);
        $batch = 0;

        while (true) {
            $batch++;
            $result = $sideloader->batch(['hosts' => $hosts, 'files' => $files, 'seconds' => $seconds, 'bytes' => 256 * 1024 * 1024, 'skip_failed' => true]);
            $after = $result['plan'];
            $repointed = (int) (($result['repointed']['rows'] ?? 0) + ($result['repointed']['documents'] ?? 0));

            $this->line(sprintf(
                'batch %3d  fetched %3d  failed %3d  refused %3d  re-pointed %3d  %8s  — %d left, %s elapsed',
                $batch, $result['fetched'], $result['failed'], $result['refused'], $repointed,
                $sideloader->bytes((int) $result['bytes']), (int) $after['remaining'],
                gmdate('H:i:s', (int) (microtime(true) - $started)),
            ));

            if (! $result['ok']) {
                $this->error('Stopped: '.$result['stopped']);

                return $this->finish($sideloader, $hosts, self::FAILURE);
            }

            if ((int) $after['remaining'] === 0) {
                break;
            }

            $progress = $result['fetched'] > 0 || $result['refused'] > 0 || $repointed > 0 || (int) ($after['untried'] ?? 0) > 0;

            if (! $progress) {
                break;
            }
        }

        return $this->finish($sideloader, $hosts);
    }

    /**
     * Name every picture that is not here, and say whether any row still
     * depends on the old site.
     *
     * @param  list<string>  $hosts
     */
    private function finish(MediaSideloader $sideloader, array $hosts, int $code = self::SUCCESS): int
    {
        $failures = $sideloader->failures(100000);
        $plan = $sideloader->plan($hosts);

        $this->newLine();

        if ($failures !== []) {
            $this->line('<comment>'.count($failures).' picture(s) did not come across:</comment>');

            foreach ($failures as $row) {
                $this->line('  '.strtoupper($row['state']).'  '.$row['url']);
                $this->line('      '.$row['reason']);
            }

            $this->writeCsv($failures);
        }

        $audit = new MediaAudit;
        $remote = array_values(array_filter($audit->audit(), static fn (array $r): bool => $r['verdict'] === MediaAudit::REMOTE));

        $this->newLine();
        $this->line(sprintf('Done. %d picture(s) on this shop\'s disk; %d row reference(s) still name another site.',
            (int) $plan['present'] + (int) $plan['repointed'], count($remote)));

        foreach (array_slice($remote, 0, 50) as $row) {
            $this->line('  '.$row['owner'].'  ['.$row['field'].']  '.$row['url']);
        }

        if (count($remote) > 50) {
            $this->line('  … and '.(count($remote) - 50).' more (pass --csv=<file>).');
        }

        if ($remote === [] && $code === self::SUCCESS) {
            $this->info('Every picture the catalogue names is served by this shop. The old site can be switched off as far as pictures are concerned.');

            return self::SUCCESS;
        }

        if ($remote !== []) {
            $this->error('Do NOT switch the old site off yet: the rows above still load their picture from it.');
        }

        return self::FAILURE;
    }

    /** @param  list<array<string, mixed>>  $failures */
    private function writeCsv(array $failures): void
    {
        $path = (string) ($this->option('csv') ?? '');

        if ($path === '') {
            return;
        }

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            $this->error('Could not write '.$path);

            return;
        }

        fputcsv($handle, ['state', 'url', 'reason', 'attempts', 'status_code', 'attempted_at'], ',', '"', '');

        foreach ($failures as $row) {
            fputcsv($handle, [$row['state'], $row['url'], $row['reason'], $row['attempts'], $row['status_code'], $row['attempted_at']], ',', '"', '');
        }

        fclose($handle);

        $this->line('Every failure and refusal written to '.$path.'.');
    }
}
